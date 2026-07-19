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
	 * Spintax that is still unresolved when plural agreement runs.
	 *
	 * Stage order decides this, not bracket type. Conditionals resolve at 6c, *before* plurals at
	 * 6d, so a `{?…}` in a count value is already a literal by the time the count is read —
	 * flagging it would be a false positive on a template that renders correctly. Enumerations
	 * (stage 7) and permutations (stage 8) run *after* plurals and are the real hazard.
	 *
	 * A conditional is therefore the only exemption. A nested `{plural …}` is **not**: it resolves
	 * in the same pass as the outer block, not before it, so `#set %n% = {plural 1:1|2}` used as a
	 * count leaves the outer construct holding unresolved spintax and it degrades to fullwidth
	 * braces. Exempting it here was a real regression — introduced by narrowing this rule for the
	 * conditional case and caught in review.
	 *
	 * The lookahead still catches an enumeration nested *inside* a conditional —
	 * `{?flag?{1|4}|2}` — where the inner `{1|` matches and the block genuinely does render empty.
	 */
	private const UNRESOLVED_AT_PLURAL_TIME = '/\[|\{(?!\?)/u';

	/**
	 * Shared parser, used for its grammar rather than for rendering.
	 *
	 * Every directive-shaped question — what is a directive, what does it define, where — is
	 * answered by the parser, so the validator cannot drift from the engine it validates for. It
	 * did drift, three separate ways, before this field existed.
	 *
	 * @var Parser|null
	 */
	private ?Parser $parser = null;

	/**
	 * Lazily build the shared parser.
	 *
	 * @return Parser
	 */
	private function parser(): Parser {
		if ( null === $this->parser ) {
			$this->parser = new Parser();
		}

		return $this->parser;
	}

	/**
	 * Validate a template and return errors/warnings.
	 *
	 * @param string   $template         Raw template source.
	 * @param string[] $known_slugs      Known template slugs for #include validation (optional).
	 * @param string[] $global_var_names Global variable names (without %) for undefined-var warnings.
	 * @param string   $locale           Render locale for plural arity check (raw, e.g. "ru" / "ru_RU"). Empty skips arity check (structural-only validation of `{plural ...}` blocks).
	 * @return array{errors: array, warnings: array}
	 */
	public function validate( string $template, array $known_slugs = array(), array $global_var_names = array(), string $locale = '' ): array {
		$errors   = array();
		$warnings = array();

		// Strip comments before analysis.
		$text = $this->parser()->strip_comments( $template );

		$errors = array_merge( $errors, $this->check_brackets( $text ) );
		$errors = array_merge( $errors, $this->check_directives( $text ) );
		$errors = array_merge( $errors, $this->check_permutation_configs( $text ) );
		$errors = array_merge( $errors, $this->check_plurals( $text, $locale ) );

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
	 * Check `#set` / `#def` directives for correct syntax and for duplicate names.
	 *
	 * Both the shape test and the duplicate test build on `Parser::DIRECTIVE_PATTERN`. They used to
	 * use a private copy that differed from the parser's in two ways — `\s` for whitespace and
	 * `(.+)` for the value — and the second was a live defect: an empty value is legal and the
	 * parser accepts it, but this reported `#set %x% =` as malformed unless the author happened to
	 * leave a trailing space.
	 *
	 * @param string $text Template body to check.
	 * @return array<array{message: string, line: int, column: int}>
	 */
	private function check_directives( string $text ): array {
		$errors = array();
		$lines  = explode( "\n", $text );

		foreach ( $lines as $line_num => $line_text ) {
			$trimmed = ltrim( $line_text );

			$kind = null;
			foreach ( array( '#set', '#def' ) as $candidate ) {
				if ( str_starts_with( $trimmed, $candidate . ' ' ) || str_starts_with( $trimmed, $candidate . "\t" ) ) {
					$kind = $candidate;
					break;
				}
			}

			if ( null === $kind ) {
				continue;
			}

			if ( ! preg_match( Parser::DIRECTIVE_PATTERN, $trimmed ) ) {
				$errors[] = array(
					'message' => sprintf( 'Malformed %1$s directive. Expected: %1$s %%name%% = value', $kind ),
					'line'    => $line_num + 1,
					'column'  => 1,
				);
			}
		}

		return array_merge(
			$errors,
			$this->check_duplicate_definitions( $text ),
			$this->check_includes_in_definitions( $text )
		);
	}

	/**
	 * Report `#include` inside a `#def` value.
	 *
	 * Includes resolve at the last stage, after everything a definition is rolled through, so one
	 * cannot be frozen into a value — it would survive as literal text. A `#set` is fine: it is
	 * substituted verbatim and its `#include` reaches the include stage in the body, where it works.
	 *
	 * @param string $text Template body to check.
	 * @return array<array{message: string, line: int, column: int}>
	 */
	private function check_includes_in_definitions( string $text ): array {
		$errors = array();

		foreach ( $this->parser()->extract_directives( $text )['occurrences'] as $occurrence ) {
			if ( 'def' !== $occurrence['kind'] ) {
				continue;
			}

			if ( ! preg_match( '/#include\b/u', $occurrence['value'] ) ) {
				continue;
			}

			$errors[] = array(
				'message' => sprintf(
					'#include cannot appear in a #def value (\'%s\'): includes resolve after the value is frozen. Use #set, or put the #include in the body.',
					$occurrence['name']
				),
				'line'    => $occurrence['line'],
				'column'  => 1,
			);
		}

		return $errors;
	}

	/**
	 * Report a name defined more than once, by either directive.
	 *
	 * The `set` / `def` maps flatten a collision to last-wins before anyone can see it, which is
	 * why `extract_directives()` also returns every occurrence with its line. Two `#set` lines
	 * sharing a name were silently last-wins before this check existed; a `#set` and a `#def`
	 * sharing one would be worse, since the two carry opposite semantics.
	 *
	 * @param string $text Template body to check.
	 * @return array<array{message: string, line: int, column: int}>
	 */
	private function check_duplicate_definitions( string $text ): array {
		$errors = array();
		$seen   = array();

		foreach ( $this->parser()->extract_directives( $text )['occurrences'] as $occurrence ) {
			$name = $occurrence['name'];

			if ( isset( $seen[ $name ] ) ) {
				$errors[] = array(
					'message' => sprintf(
						'Variable \'%s\' is defined more than once (first on line %d). A name belongs to one directive, once.',
						$name,
						$seen[ $name ]
					),
					'line'    => $occurrence['line'],
					'column'  => 1,
				);
				continue;
			}

			$seen[ $name ] = $occurrence['line'];
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
	 * Names whose value still carries unresolved spintax when the plural pass runs.
	 *
	 * A `#set` is a macro: its value is substituted verbatim, so brackets in it survive until the
	 * enumeration and permutation stages, which run *after* plurals. A name is tainted if its own
	 * value holds `{` or `[`, or if it references a tainted name — computed to a fixed point,
	 * because the chain can be arbitrarily long:
	 *
	 *     #set %m% = {1|4|9}
	 *     #set %n% = %m%          ← no bracket in sight, still tainted
	 *     {plural %n%: …}
	 *
	 * `#def` names are never tainted: they are frozen to literal text before the body is processed.
	 * That asymmetry is the whole point of the diagnostic.
	 *
	 * Boundary worth knowing: the validator is given global variable *names*, never their values,
	 * so taint cannot propagate through a global. A count referencing one is not flagged. Static
	 * analysis cannot see that far, which is why the runtime consequence is pinned by a test rather
	 * than trusted to this check.
	 *
	 * @param string $text Template body (after comment stripping).
	 * @return array<string, true> Tainted names, lowercased, as a lookup.
	 */
	private function macro_tainted_names( string $text ): array {
		$macros  = $this->parser()->extract_directives( $text )['set'];
		$tainted = array();

		foreach ( $macros as $name => $value ) {
			if ( 1 === preg_match( self::UNRESOLVED_AT_PLURAL_TIME, $value ) ) {
				$tainted[ $name ] = true;
			}
		}

		do {
			$grew = false;

			foreach ( $macros as $name => $value ) {
				if ( isset( $tainted[ $name ] ) ) {
					continue;
				}

				if ( ! preg_match_all( Parser::VARIABLE_PATTERN, $value, $references ) ) {
					continue;
				}

				foreach ( $references[1] as $reference ) {
					if ( isset( $tainted[ strtolower( $reference ) ] ) ) {
						$tainted[ $name ] = true;
						$grew             = true;
						break;
					}
				}
			}
		} while ( $grew );

		return $tainted;
	}

	/**
	 * Check `{plural <count>: form|…}` blocks for structural and arity issues.
	 *
	 * Structural check (always on): forms slot must not contain nested
	 * spintax brackets `{` `}` `[` `]`.
	 *
	 * Arity check (only when locale provided): form count must match the
	 * locale family (3 for ru/uk/be + sr/hr/bs, 2 for en/es/pt/de/...). Empty locale
	 * skips arity — useful when the validator runs without locale context
	 * and wants to surface only structural issues.
	 *
	 * @param string $text   Template body (after comment stripping).
	 * @param string $locale Render locale (raw); empty disables arity check.
	 * @return array<array{message: string, line: int, column: int}>
	 */
	private function check_plurals( string $text, string $locale ): array {
		$errors  = array();
		$plurals = new Plurals();
		$blocks  = $plurals->find_plural_blocks( $text );

		if ( empty( $blocks ) ) {
			return $errors;
		}

		$macro_counts = $this->macro_tainted_names( $text );

		$base_lang = '' !== $locale ? $plurals->normalize_base_lang( $locale ) : '';
		$arity     = '' !== $base_lang ? $plurals->plural_arity( $base_lang ) : 0;

		foreach ( $blocks as $block ) {
			$line = substr_count( $text, "\n", 0, $block['start'] ) + 1;

			// A macro in the count slot: the count is still unresolved spintax when the plural pass
			// runs, so the block resolves to nothing. `#def` is the fix — it freezes to a literal
			// before the body is processed — which is why this points at the directive rather than
			// at the plural block.
			if ( preg_match_all( Parser::VARIABLE_PATTERN, $block['count_slot'], $count_refs ) ) {
				foreach ( $count_refs[1] as $reference ) {
					if ( ! isset( $macro_counts[ strtolower( $reference ) ] ) ) {
						continue;
					}

					$errors[] = array(
						'message' => sprintf(
							'{plural ...}: the count \'%s\' is a #set macro, so it is still unresolved spintax when the plural is decided and the block renders empty. Define it with #def instead.',
							$reference
						),
						'line'    => $line,
						'column'  => 1,
					);
				}
			}

			// Structural: no nested spintax brackets in forms.
			if ( 1 === preg_match( '/[{}\[\]]/', $block['forms_raw'] ) ) {
				$errors[] = array(
					'message' => '{plural ...}: forms must not contain nested spintax brackets ({}, []). Extract synonym / conditional / permutation via #def first — a #set is substituted verbatim and would put the brackets straight back.',
					'line'    => $line,
					'column'  => 1,
				);
				continue;
			}

			// Arity (only if locale provided).
			if ( $arity > 0 ) {
				$forms = explode( '|', $block['forms_raw'] );
				if ( count( $forms ) !== $arity ) {
					$errors[] = array(
						'message' => sprintf(
							'{plural ...}: expected %1$d forms for "%2$s", got %3$d.',
							$arity,
							$base_lang,
							count( $forms )
						),
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

		// Both directives define names, so both feed the self-reference, cycle and unknown-name
		// checks. Parsing only `#set` here meant a `#def`-defined name was reported as possibly a
		// runtime variable at every reference, and its cycles went undetected.
		$extracted   = $this->parser()->extract_directives( $text );
		$definitions = array_merge( $extracted['set'], $extracted['def'] );

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
		// `%var%` references and `{?VAR?...}` / `{?!VAR?...}` conditional
		// references are merged — both are warned about when the name is
		// neither defined locally nor declared globally.
		$body = $extracted['body'];
		preg_match_all( Parser::VARIABLE_PATTERN, $body, $percent_matches );
		preg_match_all( '/\{\?!?([A-Za-z_]\w*)\?/u', $body, $cond_matches );
		$all_refs = array_merge( $percent_matches[1] ?? array(), $cond_matches[1] ?? array() );

		if ( ! empty( $all_refs ) ) {
			$defined_names = array_keys( $definitions );
			$global_lower  = array_map( 'strtolower', $global_var_names );
			$all_known     = array_merge( $defined_names, $global_lower );

			foreach ( array_unique( $all_refs ) as $ref ) {
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
