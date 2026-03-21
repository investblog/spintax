<?php
/**
 * Spintax template validator — static analysis without execution.
 *
 * @package Spintax
 */

namespace Spintax\Core\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Validates spintax template syntax.
 */
class Validator {

	/**
	 * Validate a template and return errors/warnings.
	 *
	 * @param string   $template       Raw template source.
	 * @param string[] $known_slugs    Known template slugs for #include validation (optional).
	 * @param string[] $global_var_names Global variable names (without %) for undefined-var warnings.
	 * @return array{errors: array, warnings: array}
	 */
	public function validate( string $template, array $known_slugs = array(), array $global_var_names = array() ): array {
		$errors   = array();
		$warnings = array();

		// Strip comments before analysis.
		$parser = new Parser();
		$text   = $parser->strip_comments( $template );

		$errors = array_merge( $errors, $this->check_brackets( $text ) );
		$errors = array_merge( $errors, $this->check_set_directives( $text ) );
		$errors = array_merge( $errors, $this->check_permutation_configs( $text ) );

		$var_result = $this->check_variable_references( $text, $global_var_names );
		$errors     = array_merge( $errors, $var_result['errors'] );
		$warnings   = array_merge( $warnings, $var_result['warnings'] );

		if ( ! empty( $known_slugs ) ) {
			$errors = array_merge( $errors, $this->check_include_targets( $text, $known_slugs ) );
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Check that all { } and [ ] are balanced and properly nested.
	 *
	 * @param string $text Template body to check.
	 * @return array<array{message: string, line: int, column: int}>
	 */
	private function check_brackets( string $text ): array {
		$errors = array();
		$stack  = array();
		$line   = 1;
		$col    = 1;
		$len    = strlen( $text );
		$pairs  = array(
			'{' => '}',
			'[' => ']',
		);

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $text[ $i ];

			if ( "\n" === $ch ) {
				++$line;
				$col = 1;
				continue;
			}

			if ( isset( $pairs[ $ch ] ) ) {
				$stack[] = array(
					'char'   => $ch,
					'expect' => $pairs[ $ch ],
					'line'   => $line,
					'column' => $col,
				);
			} elseif ( '}' === $ch || ']' === $ch ) {
				if ( empty( $stack ) ) {
					$errors[] = array(
						'message' => sprintf( 'Unexpected closing \'%s\' without matching opening bracket.', $ch ),
						'line'    => $line,
						'column'  => $col,
					);
				} else {
					$top = array_pop( $stack );
					if ( $top['expect'] !== $ch ) {
						$errors[] = array(
							'message' => sprintf(
								'Mismatched brackets: \'%s\' at line %d, col %d closed by \'%s\'.',
								$top['char'],
								$top['line'],
								$top['column'],
								$ch
							),
							'line'    => $line,
							'column'  => $col,
						);
					}
				}
			}

			++$col;
		}

		// Report unclosed brackets.
		foreach ( $stack as $unclosed ) {
			$errors[] = array(
				'message' => sprintf(
					'Unclosed \'%s\' — expected \'%s\'.',
					$unclosed['char'],
					$unclosed['expect']
				),
				'line'    => $unclosed['line'],
				'column'  => $unclosed['column'],
			);
		}

		return $errors;
	}

	/**
	 * Check #set directives for correct syntax.
	 *
	 * @param string $text Template body to check.
	 * @return array<array{message: string, line: int, column: int}>
	 */
	private function check_set_directives( string $text ): array {
		$errors = array();
		$lines  = explode( "\n", $text );

		foreach ( $lines as $line_num => $line_text ) {
			$trimmed = ltrim( $line_text );

			if ( ! str_starts_with( $trimmed, '#set ' ) && ! str_starts_with( $trimmed, '#set	' ) ) {
				continue;
			}

			// Must match: #set %name% = value.
			if ( ! preg_match( '/^#set\s+%(\w+)%\s*=\s*(.+)$/u', $trimmed ) ) {
				$errors[] = array(
					'message' => 'Malformed #set directive. Expected: #set %name% = value',
					'line'    => $line_num + 1,
					'column'  => 1,
				);
			}
		}

		return $errors;
	}

	/**
	 * Check permutation <config> blocks for valid syntax.
	 *
	 * @param string $text Template body to check.
	 * @return array<array{message: string, line: int, column: int}>
	 */
	private function check_permutation_configs( string $text ): array {
		$errors = array();

		// Find all [<...> patterns.
		if ( ! preg_match_all( '/\[<([^>]*?)>/u', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $errors;
		}

		foreach ( $matches[1] as $match ) {
			$config_str = $match[0];
			$offset     = $match[1];
			$line       = substr_count( $text, "\n", 0, $offset ) + 1;

			// If it looks like a full config (has key=), validate known keys.
			if ( preg_match( '/\w+\s*=/', $config_str ) ) {
				// Check for unknown config keys.
				$known    = array( 'minsize', 'maxsize', 'sep', 'lastsep' );
				$all_keys = array();
				preg_match_all( '/(\w+)\s*=/', $config_str, $key_matches );
				if ( ! empty( $key_matches[1] ) ) {
					$all_keys = $key_matches[1];
				}

				foreach ( $all_keys as $key ) {
					if ( ! in_array( strtolower( $key ), $known, true ) ) {
						$errors[] = array(
							'message' => sprintf( 'Unknown permutation config key: \'%s\'.', $key ),
							'line'    => $line,
							'column'  => 1,
						);
					}
				}

				// Check minsize/maxsize are positive integers.
				if ( preg_match( '/minsize\s*=\s*([^;>\s]+)/i', $config_str, $m ) && ! ctype_digit( $m[1] ) ) {
					$errors[] = array(
						'message' => sprintf( 'minsize must be a positive integer, got \'%s\'.', $m[1] ),
						'line'    => $line,
						'column'  => 1,
					);
				}
				if ( preg_match( '/maxsize\s*=\s*([^;>\s]+)/i', $config_str, $m ) && ! ctype_digit( $m[1] ) ) {
					$errors[] = array(
						'message' => sprintf( 'maxsize must be a positive integer, got \'%s\'.', $m[1] ),
						'line'    => $line,
						'column'  => 1,
					);
				}
			}
		}

		return $errors;
	}

	/**
	 * Check variable references for circular definitions and undefined warnings.
	 *
	 * @param string   $text             Template body (after comment stripping).
	 * @param string[] $global_var_names  Global variable names (without %).
	 * @return array{errors: array, warnings: array}
	 */
	private function check_variable_references( string $text, array $global_var_names = array() ): array {
		$errors   = array();
		$warnings = array();

		// Extract #set definitions.
		$definitions = array();
		preg_match_all( '/^[ \t]*#set\s+%(\w+)%\s*=\s*(.*?)$/mu', $text, $matches );
		if ( ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $i => $name ) {
				$definitions[ strtolower( $name ) ] = $matches[2][ $i ];
			}
		}

		// Check for self-referencing variables.
		foreach ( $definitions as $name => $value ) {
			if ( preg_match( '/%' . preg_quote( $name, '/' ) . '%/iu', $value ) ) {
				$errors[] = array(
					'message' => sprintf( 'Variable \'%s\' references itself.', $name ),
					'line'    => 0,
					'column'  => 0,
				);
			}
		}

		// Check for circular references (A→B→A).
		foreach ( $definitions as $name => $value ) {
			$visited = array( $name );
			$this->detect_cycle( $name, $definitions, $visited, $errors );
		}

		// Find all variable references in the body (outside #set lines).
		$body = preg_replace( '/^[ \t]*#set\s+%\w+%\s*=\s*.*?$/mu', '', $text );
		preg_match_all( '/%(\w+)%/u', $body, $ref_matches );

		if ( ! empty( $ref_matches[1] ) ) {
			$defined_names = array_keys( $definitions );
			$global_lower  = array_map( 'strtolower', $global_var_names );
			$all_known     = array_merge( $defined_names, $global_lower );

			foreach ( array_unique( $ref_matches[1] ) as $ref ) {
				$ref_lower = strtolower( $ref );
				if ( ! in_array( $ref_lower, $all_known, true ) ) {
					$warnings[] = array(
						'message' => sprintf(
							'Variable \'%s\' is not defined locally or globally — may be a runtime variable.',
							$ref
						),
						'line'    => 0,
						'column'  => 0,
					);
				}
			}
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Detect circular variable references via DFS.
	 *
	 * @param string   $current     Current variable being traced.
	 * @param array    $definitions All variable definitions.
	 * @param string[] $visited     Variables visited in current path.
	 * @param array    $errors      Error collector (by reference).
	 */
	private function detect_cycle( string $current, array $definitions, array $visited, array &$errors ): void {
		$value = $definitions[ $current ] ?? '';

		preg_match_all( '/%(\w+)%/u', $value, $refs );
		if ( empty( $refs[1] ) ) {
			return;
		}

		foreach ( $refs[1] as $ref ) {
			$ref_lower = strtolower( $ref );

			if ( $ref_lower === $current ) {
				continue; // Self-reference already reported.
			}

			if ( in_array( $ref_lower, $visited, true ) ) {
				$errors[] = array(
					'message' => sprintf(
						'Circular variable reference detected: %s.',
						implode( ' → ', array_merge( $visited, array( $ref_lower ) ) )
					),
					'line'    => 0,
					'column'  => 0,
				);
				return;
			}

			if ( isset( $definitions[ $ref_lower ] ) ) {
				$this->detect_cycle( $ref_lower, $definitions, array_merge( $visited, array( $ref_lower ) ), $errors );
			}
		}
	}

	/**
	 * Check that #include targets reference existing templates.
	 *
	 * @param string   $text        Template body.
	 * @param string[] $known_slugs Available template slugs/IDs.
	 * @return array<array{message: string, line: int, column: int}>
	 */
	private function check_include_targets( string $text, array $known_slugs ): array {
		$errors   = array();
		$parser   = new Parser();
		$includes = $parser->find_include_directives( $text );

		foreach ( $includes as $inc ) {
			if ( ! in_array( $inc['slug'], $known_slugs, true ) ) {
				$errors[] = array(
					'message' => sprintf( '#include target \'%s\' does not match any known template.', $inc['slug'] ),
					'line'    => $inc['line'],
					'column'  => 1,
				);
			}
		}

		return $errors;
	}
}
