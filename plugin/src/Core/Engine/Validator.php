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
		$plural_result = $this->check_plurals( $text, $locale, $global_var_names );
		$errors        = array_merge( $errors, $plural_result['errors'] );
		$warnings      = array_merge( $warnings, $plural_result['warnings'] );

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
			// Spaces and tabs ONLY — the reference trims /^[ \t]+/. Bare ltrim() also eats
			// NUL/VT/CR, which turns e.g. NUL + `#set broken` into a malformed-directive
			// error the reference does not report (corpus: validate/directive-check-*).
			$trimmed = ltrim( $line_text, " \t" );

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

			// ASCII lookahead, not `\b`: under /u PCRE's `\b` is a Unicode boundary, so
			// `#includeя` had no boundary here and the check never fired — the reference's
			// `\b` is ASCII and it does (#56).
			if ( ! preg_match( '/#include(?![A-Za-z0-9_])/u', $occurrence['value'] ) ) {
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

		// Ascending offsets — resume the line count from the previous match.
		$cursor_offset = 0;
		$cursor_line   = 1;
		foreach ( $matches[1] as $match ) {
			$config_str = $match[0];
			$offset     = $match[1];
			if ( $offset > $cursor_offset ) {
				$cursor_line  += substr_count( $text, "\n", $cursor_offset, $offset - $cursor_offset );
				$cursor_offset = $offset;
			}
			$line = $cursor_line;

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

		// Same closure the old fixed-point sweep computed, via reverse edges — the sweep
		// re-read every macro once per newly tainted name, O(n²) on a macro chain.
		$queue = array_map( 'strval', array_keys( $tainted ) );

		$dependents = array();
		foreach ( $macros as $name => $value ) {
			if ( preg_match_all( Parser::VARIABLE_PATTERN, (string) $value, $references ) ) {
				foreach ( $references[1] as $reference ) {
					$dependents[ strtolower( $reference ) ][] = (string) $name;
				}
			}
		}
		while ( ! empty( $queue ) ) {
			$source = array_pop( $queue );
			foreach ( $dependents[ $source ] ?? array() as $name ) {
				if ( ! isset( $tainted[ $name ] ) ) {
					$tainted[ $name ] = true;
					$queue[]          = $name;
				}
			}
		}

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
	/**
	 * Follow `#set` references out of a form list and say what arrives verbatim.
	 *
	 * Returns `brackets` when the path reaches macro text carrying any bracket (which the
	 * plural stage then rejects), `opaque` when it reaches a conditional — where the
	 * engines themselves disagree, both PHP renderers resolving one that expansion
	 * introduces inside a form list while JS and Python do not, so this declines to judge
	 * rather than pick a side — and `clean` otherwise.
	 *
	 * @param string                $source     Text to scan.
	 * @param array<string, string> $defs       `#def` values.
	 * @param array<string, string> $macros     `#set` values.
	 * @param array<string, bool>   $host_names Names the host will supply.
	 * @param array<string, bool>   $seen_macro Visited macros, by reference (cycle guard).
	 * @return string `brackets` | `opaque` | `clean`
	 */
	private function walk_macro_path( string $source, array $defs, array $macros, array $host_names, array &$seen_macro ): string {
		if ( ! preg_match_all( '/%(\w+)%/', $source, $matches ) ) {
			return 'clean';
		}

		foreach ( $matches[1] as $reference ) {
			$name = strtolower( $reference );

			// A #def rolls it and a host value replaces it — either way the macro text
			// does not arrive verbatim, so this path says nothing.
			if ( isset( $defs[ $name ] ) || isset( $host_names[ $name ] ) ) {
				continue;
			}
			if ( ! isset( $macros[ $name ] ) || isset( $seen_macro[ $name ] ) ) {
				continue;
			}
			$seen_macro[ $name ] = true;
			$value               = $macros[ $name ];

			if ( 1 === preg_match( '/\{\?/', $value ) ) {
				return 'opaque';
			}
			if ( 1 === preg_match( '/[\[\]{}]/', $value ) ) {
				return 'brackets';
			}
			$deeper = $this->walk_macro_path( $value, $defs, $macros, $host_names, $seen_macro );
			if ( 'clean' !== $deeper ) {
				return $deeper;
			}
		}

		return 'clean';
	}

	/**
	 * Passes, not occurrences — each one substitutes EVERY reference, as the renderer's
	 * expansion does. Counting occurrences instead let a form list with 51 references
	 * exhaust the budget and go unjudged. Deliberately NOT claimed to match a renderer's
	 * own limit; it only has to terminate, and a chain deeper than this is suppressed
	 * rather than judged, which is the safe direction.
	 */
	private const FORM_EXPANSION_PASSES = 51;

	/**
	 * How many forms the plural stage will receive, or an admission that it is unknowable.
	 *
	 * Rendering expands `%variables%` and only THEN splits the form list, while this
	 * validator used to split the raw source — so any reference inside a form list was
	 * judged on the wrong number, in both directions (spintax-js#66).
	 *
	 * The rule is deliberately narrow, and the narrowness IS the correction. A first
	 * version tried to predict the roll, counting pipes at bracket depth 0 on the theory
	 * that a construct always collapses to one form. It does not:
	 *
	 *     #set %flag% =
	 *     #def %x% = {?flag?a|b|c}     the false branch freezes as `b|c`: TWO forms
	 *     {plural 1: one|%x%}          renders fine under ru; the guess said arity error
	 *
	 * So a value is counted only when its form count is the same WHATEVER the roll does —
	 * when it carries no construct at all. Note the bracket class below includes `{?`
	 * conditionals, unlike the unresolved-at-plural-time test: a conditional resolves
	 * before plurals, but its branches can differ in top-level pipes, and counting is
	 * about invariance rather than stage order.
	 *
	 * `macro_spintax` is the one prediction that survives, because it is not one: a `#set`
	 * named DIRECTLY in the form slot is substituted verbatim and is still spintax when
	 * the plural is decided. Reached through a `#def` it is rolled first.
	 *
	 * @param string                $forms_raw  Raw forms slot.
	 * @param array<string, string> $defs       `#def` values.
	 * @param array<string, string> $macros     `#set` values.
	 * @param array<string, bool>   $host_names Names the host will supply, lower-cased keys.
	 * @return array{forms: int, unresolved: bool, macro_spintax: bool}
	 */
	private function expand_forms_for_counting( string $forms_raw, array $defs, array $macros, array $host_names ): array {
		// Which brackets reach the form slot VERBATIM: follow the #set chain out of the raw
		// slot. "Direct" is a property of the PATH, not of one hop — `#set %a% = %b%` with
		// `#set %b% = {a|b}` never crosses a #def, so the macro text arrives whole.
		$seen_macro = array();
		$verbatim   = $this->walk_macro_path( $forms_raw, $defs, $macros, $host_names, $seen_macro );
		if ( 'brackets' === $verbatim ) {
			return array(
				'forms' => 0,
				'unresolved' => true,
				'macro_spintax' => true,
			);
		}
		if ( 'opaque' === $verbatim ) {
			return array(
				'forms' => 0,
				'unresolved' => true,
				'macro_spintax' => false,
			);
		}

		$text = $forms_raw;

		for ( $pass = 0; $pass < self::FORM_EXPANSION_PASSES; $pass++ ) {
			$bailed         = false;
			$saw_reference  = false;

			// EVERY reference per pass, as the renderer's expansion does.
			$text = preg_replace_callback(
				'/%(\w+)%/',
				static function ( array $m ) use ( $defs, $macros, $host_names, &$bailed, &$saw_reference ): string {
					$saw_reference = true;
					$name          = strtolower( $m[1] );

					// Runtime context outranks a definition of the same name, so a
					// host-declared name makes the count unknowable regardless.
					if ( isset( $host_names[ $name ] ) ) {
						$bailed = true;
						return $m[0];
					}

					$value = $defs[ $name ] ?? ( $macros[ $name ] ?? null );
					if ( null === $value ) {
						$bailed = true;
						return $m[0];
					}

					// A construct in the value: what it rolls to may or may not carry a
					// top-level pipe, so no single count is true of every render.
					if ( 1 === preg_match( '/[\[\]{}]/', $value ) ) {
						$bailed = true;
						return $m[0];
					}

					return $value;
				},
				$text
			);

			if ( $bailed ) {
				return array(
					'forms' => 0,
					'unresolved' => true,
					'macro_spintax' => false,
				);
			}
			if ( ! $saw_reference ) {
				// No construct can be left, so the plain split is what rendering does too.
				return array(
					'forms'         => count( explode( '|', $text ) ),
					'unresolved'    => false,
					'macro_spintax' => false,
				);
			}
		}

		// A cycle, or a chain deeper than this bothers to follow.
		return array(
			'forms' => 0,
			'unresolved' => true,
			'macro_spintax' => false,
		);
	}

	/**
	 * Check `{plural <count>: form|…}` blocks for structural and arity issues.
	 *
	 * Structural check (always on): the forms slot must not contain nested spintax
	 * brackets `{` `}` `[` `]` — including brackets a `#set` named in the slot would
	 * put back, since a macro is substituted verbatim and is still spintax when the
	 * plural is decided.
	 *
	 * Arity check (only when a locale is given): the form count must match the locale
	 * family (3 for ru/uk/be + sr/hr/bs, 2 for en/es/pt/de/…). Forms are counted the way
	 * rendering counts them — after definition values are substituted — and only when the
	 * count is provably invariant; a value carrying any bracket suppresses the verdict
	 * rather than guessing at a roll (spintax-js#66).
	 *
	 * An empty locale skips arity and may instead raise the locale-missing warning, which
	 * is why this returns two buckets rather than one list.
	 *
	 * @param string        $text             Template body (after comment stripping).
	 * @param string        $locale           Render locale (raw); empty disables the arity check.
	 * @param array<string> $global_var_names Names the host will supply at render time.
	 * @return array{errors: array<array{message: string, line: int, column: int}>, warnings: array<array{message: string, line: int, column: int}>}
	 */
	private function check_plurals( string $text, string $locale, array $global_var_names = array() ): array {
		$errors   = array();
		$warnings = array();
		$plurals  = new Plurals();
		$blocks  = $plurals->find_plural_blocks( $text );

		if ( empty( $blocks ) ) {
			return array(
				'errors' => $errors,
				'warnings' => $warnings,
			);
		}

		$macro_counts = $this->macro_tainted_names( $text );
		$directives   = $this->parser()->extract_directives( $text );
		$form_defs    = $directives['def'];
		$form_macros  = $directives['set'];
		// Names the host says it will supply: runtime context outranks a definition of
		// the same name, so one of these makes a form count unknowable however the
		// template defines it.
		$host_names   = array();
		foreach ( $global_var_names as $global_name ) {
			$host_names[ strtolower( (string) $global_name ) ] = true;
		}

		$base_lang = '' !== $locale ? $plurals->normalize_base_lang( $locale ) : '';
		$arity     = '' !== $base_lang ? $plurals->plural_arity( $base_lang ) : 0;

		// Blocks come in source order — resume the line count from the previous one.
		$cursor_offset = 0;
		$cursor_line   = 1;
		foreach ( $blocks as $block ) {
			if ( $block['start'] > $cursor_offset ) {
				$cursor_line  += substr_count( $text, "\n", $cursor_offset, $block['start'] - $cursor_offset );
				$cursor_offset = $block['start'];
			}
			$line = $cursor_line;

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

			// The form list AS THE RENDERER WILL SEE IT (spintax-js#66).
			$expanded = $this->expand_forms_for_counting( $block['forms_raw'], $form_defs, $form_macros, $host_names );

			// A `#set` whose value carries spintax lands in the form list VERBATIM and is
			// still unresolved when the plural is decided — the same fact the count-macro
			// check states for the count slot, and what this message already describes.
			if ( $expanded['macro_spintax'] ) {
				$errors[] = array(
					'message' => '{plural ...}: forms must not contain nested spintax brackets ({}, []). Extract synonym / conditional / permutation via #def first — a #set is substituted verbatim and would put the brackets straight back.',
					'line'    => $line,
					'column'  => 1,
				);
				continue;
			}

			// A reference the template does not define has no static form count. Judging it
			// would repeat the mistake #65 fixed: a verdict on a fact nobody claimed.
			if ( $expanded['unresolved'] ) {
				continue;
			}

			$form_count = $expanded['forms'];

			// Arity (only if locale provided).
			if ( $arity > 0 ) {
				if ( $form_count !== $arity ) {
					$errors[] = array(
						'message' => sprintf(
							'{plural ...}: expected %1$d forms for "%2$s", got %3$d.',
							$arity,
							$base_lang,
							$form_count
						),
						'line'    => $line,
						'column'  => 1,
					);
				}
			} elseif ( $form_count !== Plurals::DEFAULT_ARITY ) {
				// No locale means no arity VERDICT: the template may well be correct for the
				// locale it will be rendered with, and calling it invalid here would fail a
				// good template for a fact the caller never claimed. The warning covers the
				// other half — a form count the render locale may not take (spintax-js#65).
				//
				// The wording is deliberately weaker HERE than in the sibling engines, and
				// the reason is this plugin's own render path: `Renderer::process_template()`
				// replaces an empty locale with `get_locale()`, so "no locale" does NOT mean
				// the engine's two-form default on a WordPress site. Claiming it did would
				// report a false problem on every ru_RU install — a three-form template that
				// renders perfectly. So the message states the condition and names the
				// dependency instead of predicting the outcome.
				$warnings[] = array(
					'message' => sprintf(
						'{plural ...}: %1$d forms, and no locale was passed to the validator. Rendering agrees the plural with the locale it is given — the site locale when the caller supplies none — so this block resolves only where that locale takes %1$d forms. Pass the locale you will render with to get a definite verdict.',
						$form_count
					),
					'line'    => $line,
					'column'  => 1,
				);
			}
		}

		return array(
			'errors' => $errors,
			'warnings' => $warnings,
		);
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

		// Check for circular references (A→B→A). References are parsed once and the walk
		// is pruned to names that can actually reach a cycle — the naive restart-per-root
		// DFS re-parsed every value at every visit and re-explored shared subtrees, which
		// hung validate() on ~1.5 KB of converging definitions (see spintax-js#59; the
		// TS engine took the same fix in 0.3.3). Emission is unchanged: order, count and
		// messages are the recursive walk's, duplicated edges and all.
		$refs_of = $this->references_of( $definitions );
		$reaches = $this->names_that_reach_a_cycle( $definitions, $refs_of );
		foreach ( $definitions as $name => $value ) {
			$this->walk_cycles_from( (string) $name, $definitions, $refs_of, $reaches, $errors );
		}

		// Find all variable references in the body (outside #set lines).
		// `%var%` references and `{?VAR?...}` / `{?!VAR?...}` conditional
		// references are merged — both are warned about when the name is
		// neither defined locally nor declared globally.
		$body = $extracted['body'];
		preg_match_all( Parser::VARIABLE_PATTERN, $body, $percent_matches );
		// ASCII name tail, mirroring Conditionals::NAME_RE — `\w` under /u widened it to
		// Unicode, so `{?xя?y}` produced a phantom ref no renderer in the family reads (#56).
		preg_match_all( '/\{\?!?([A-Za-z_][A-Za-z0-9_]*)\?/u', $body, $cond_matches );
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
	 * Each definition's references, lowercased, parsed once — the walk used to run
	 * `preg_match_all` again at every visit of every root.
	 *
	 * @param array $definitions All variable definitions.
	 * @return array<string, string[]> Name → references in value order.
	 */
	private function references_of( array $definitions ): array {
		$refs_of = array();
		foreach ( $definitions as $name => $value ) {
			$list = array();
			if ( preg_match_all( Parser::VARIABLE_PATTERN, (string) $value, $matches ) ) {
				foreach ( $matches[1] as $ref ) {
					$list[] = strtolower( $ref );
				}
			}
			$refs_of[ (string) $name ] = $list;
		}
		return $refs_of;
	}

	/**
	 * Names from which a cycle of length ≥ 2 is reachable, over name → defined refs with
	 * self-edges excluded — exactly the edges the reporting walk can traverse (it skips
	 * `ref === current`; a pure self-loop is the self-reference check's).
	 *
	 * This is the prune that makes the walk affordable, and it is output-neutral by
	 * construction: a report fires only when the walk meets a name already on its path,
	 * which is a cycle that name lies on — so a subtree in which no name reaches any
	 * cycle cannot emit, and skipping it changes nothing. One iterative colour walk.
	 *
	 * @param array $definitions All variable definitions.
	 * @param array $refs_of     From `references_of()`.
	 * @return array<string, true> Lookup of names that reach a cycle.
	 */
	private function names_that_reach_a_cycle( array $definitions, array $refs_of ): array {
		$grey    = array();
		$black   = array();
		$reaches = array();

		foreach ( $definitions as $root => $unused ) {
			$root = (string) $root;
			if ( isset( $grey[ $root ] ) || isset( $black[ $root ] ) ) {
				continue;
			}
			$stack         = array( array( $root, 0 ) );
			$grey[ $root ] = true;
			while ( ! empty( $stack ) ) {
				$top  = count( $stack ) - 1;
				$name = $stack[ $top ][0];
				$i    = $stack[ $top ][1];
				$refs = $refs_of[ $name ] ?? array();
				if ( $i >= count( $refs ) ) {
					array_pop( $stack );
					unset( $grey[ $name ] );
					$black[ $name ] = true;
					if ( ! empty( $stack ) && isset( $reaches[ $name ] ) ) {
						$reaches[ $stack[ count( $stack ) - 1 ][0] ] = true;
					}
					continue;
				}
				$stack[ $top ][1] = $i + 1;
				$ref              = $refs[ $i ];
				if ( $ref === $name || ! isset( $definitions[ $ref ] ) ) {
					continue;
				}
				if ( isset( $grey[ $ref ] ) ) {
					$reaches[ $name ] = true; // Back edge — $name sits on a cycle.
				} elseif ( isset( $black[ $ref ] ) ) {
					if ( isset( $reaches[ $ref ] ) ) {
						$reaches[ $name ] = true;
					}
				} else {
					$stack[]      = array( $ref, 0 );
					$grey[ $ref ] = true;
				}
			}
		}
		return $reaches;
	}

	/**
	 * The reporting walk, exactly the recursive DFS it replaces: depth-first over a
	 * value's references in order, one report per frame that meets a name already on
	 * the path (that frame then abandons its remaining references; siblings continue
	 * from the parent). Iterative, with the path as a hash set plus a shared push/pop
	 * array — `in_array` over the path and an `array_merge` copy per step made one
	 * 1600-definition cycle cost tens of seconds.
	 *
	 * @param string $root        Definition to start from.
	 * @param array  $definitions All variable definitions.
	 * @param array  $refs_of     From `references_of()`.
	 * @param array  $reaches     From `names_that_reach_a_cycle()`.
	 * @param array  $errors      Error collector (by reference).
	 */
	private function walk_cycles_from( string $root, array $definitions, array $refs_of, array $reaches, array &$errors ): void {
		if ( ! isset( $reaches[ $root ] ) ) {
			return; // The root frame could only report a self-edge, which the walk skips.
		}

		$path    = array( $root );
		$on_path = array( $root => true );
		$stack   = array( array( $root, 0 ) );

		while ( ! empty( $stack ) ) {
			$top  = count( $stack ) - 1;
			$name = $stack[ $top ][0];
			$i    = $stack[ $top ][1];
			$refs = $refs_of[ $name ] ?? array();

			if ( $i >= count( $refs ) ) {
				array_pop( $stack );
				unset( $on_path[ $name ] );
				array_pop( $path );
				continue;
			}
			$stack[ $top ][1] = $i + 1;
			$ref              = $refs[ $i ];

			if ( $ref === $name ) {
				continue; // Self-reference already reported.
			}
			if ( isset( $on_path[ $ref ] ) ) {
				$errors[] = array(
					'message' => sprintf(
						'Circular variable reference detected: %s.',
						implode( ' → ', array_merge( $path, array( $ref ) ) )
					),
					'line'    => 0,
					'column'  => 0,
				);
				// The recursive walk returned from the whole frame here.
				array_pop( $stack );
				unset( $on_path[ $name ] );
				array_pop( $path );
				continue;
			}
			if ( isset( $definitions[ $ref ] ) && isset( $reaches[ $ref ] ) ) {
				$stack[]         = array( $ref, 0 );
				$on_path[ $ref ] = true;
				$path[]          = $ref;
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
