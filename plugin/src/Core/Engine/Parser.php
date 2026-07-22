<?php
/**
 * Spintax parser — recursive-descent, framework-agnostic.
 *
 * Handles GTW-original syntax:
 *   {a|b|c}        — enumeration (pick one)
 *   [<config>a|b|c] — permutation (pick N, shuffle, join)
 *   %var%           — variable reference
 *   #set %var% = v  — variable definition
 *   /#...#/         — comments
 *   #include "slug" — template include directive
 *
 * @package Spintax
 */

namespace Spintax\Core\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Spintax template parser.
 */
class Parser {

	/**
	 * Random number generator callable.
	 *
	 * @var callable(int,int):int
	 */
	private $random_fn;

	/**
	 * The one grammar for `#set` and `#def`, shared by the parser and the validator.
	 *
	 * Whitespace classes are restricted to spaces and tabs on purpose. `\s` would
	 * let the engine consume the newlines around `=` and capture the next
	 * directive as the value of an empty one — see
	 * `test_extract_set_directives_empty_value_does_not_swallow_next`.
	 *
	 * The value group is `(.*?)`, not `(.+)`: an empty value is legal. The
	 * validator used to disagree on both counts and reported `#set %x% =` as
	 * malformed while the parser accepted it; anything checking these directives
	 * must build on this constant rather than write its own pattern.
	 */
	public const DIRECTIVE_PATTERN = '/^[ \t]*#(set|def)[ \t]+%(\w+)%[ \t]*=[ \t]*(.*?)[ \t]*$/mu';

	/**
	 * The same grammar narrowed to `#set`, for `extract_set_directives()` alone.
	 *
	 * It cannot be expressed as a filter over `DIRECTIVE_PATTERN`, because that method must leave
	 * `#def` lines **in the body** rather than strip lines it will not resolve. Kept adjacent to
	 * the constant it mirrors so the two cannot drift apart unnoticed — which is exactly what
	 * happened when four near-copies of this were spread across the parser and the validator.
	 */
	public const SET_DIRECTIVE_PATTERN = '/^[ \t]*#set[ \t]+%(\w+)%[ \t]*=[ \t]*(.*?)[ \t]*$/mu';

	/**
	 * A `%var%` reference. Shared by expansion and by `#def` dependency discovery, so the two
	 * cannot disagree about what counts as a reference.
	 */
	public const VARIABLE_PATTERN = '/%(\w+)%/u';

	/**
	 * Maximum iterations for enumeration/permutation resolution.
	 *
	 * @var int
	 */
	private const MAX_ITERATIONS = 10000;

	/**
	 * Maximum recursion depth for variable expansion.
	 *
	 * @var int
	 */
	private const MAX_VARIABLE_DEPTH = 50;

	/**
	 * Constructor.
	 *
	 * @param callable|null $random_fn Custom RNG for deterministic testing. Signature: fn(int $min, int $max): int.
	 */
	public function __construct( ?callable $random_fn = null ) {
		$this->random_fn = $random_fn ?? static function ( int $min, int $max ): int {
			return random_int( $min, $max );
		};
	}

	/**
	 * Process a spintax template through all stages.
	 *
	 * This is a convenience method for standalone use. The Renderer calls
	 * individual stage methods for finer control (e.g. inserting #include
	 * resolution between permutations and post-processing).
	 *
	 * @param string $template Raw spintax markup.
	 * @param array  $variables Merged variable context (name => raw value, without % delimiters).
	 * @return string Processed text.
	 *
	 * @throws \RuntimeException If template processing encounters unresolvable syntax.
	 */
	public function process( string $template, array $variables = array() ): string {
		$text      = $this->strip_comments( $template );
		$extracted = $this->extract_directives( $text );
		$text      = $extracted['body'];
		$all_vars  = array_merge( $extracted['set'], $variables );

		// Caller keys are compared lowercased: `%var%` references are case-insensitive everywhere
		// else, so `['X' => …]` has to outrank `#def %x%` exactly as `['x' => …]` does.
		$caller = array_change_key_case( $variables, CASE_LOWER );

		// Roll `#def` values before expansion, in dependency order — following aliases through
		// everything visible here, caller variables included, not just the local `#set` map. This
		// is the same contract the full pipeline implements, minus the two passes this method does
		// not run at all (conditionals and plurals): a value carrying those is frozen with them
		// unresolved. Callers needing the whole language want `Spintax\Core\Render\Renderer`.
		// Excluded are the definitions that will actually be rolled; one the caller overrides is
		// left in, because the caller's value is what gets substituted and the graph must follow it.
		$aliases = array_diff_key(
			array_change_key_case( $all_vars, CASE_LOWER ),
			array_diff_key( $extracted['def'], $caller )
		);

		foreach ( $this->order_definitions( $extracted['def'], $aliases ) as $name ) {
			if ( array_key_exists( $name, $caller ) ) {
				continue;
			}

			$rolled            = $this->expand_variables( $extracted['def'][ $name ], $all_vars );
			$rolled            = $this->resolve_enumerations( $rolled );
			$all_vars[ $name ] = $this->resolve_permutations( $rolled );
		}

		$text = $this->expand_variables( $text, $all_vars );
		$text = $this->resolve_enumerations( $text );
		$text = $this->resolve_permutations( $text );
		$text = $this->post_process( $text );

		return $text;
	}

	/**
	 * Strip block comments delimited by /# ... #/.
	 *
	 * @param string $text Text containing block comments.
	 * @return string Text with comments removed.
	 */
	public function strip_comments( string $text ): string {
		return preg_replace( '~/\#.*?\#/~su', '', $text );
	}

	/**
	 * Extract #set directives and remove them from the body.
	 *
	 * @param string $text Text containing #set directives.
	 * @return array{body: string, variables: array<string, string>}
	 */
	public function extract_set_directives( string $text ): array {
		// Deliberately `#set` only, matching this method's name and its pre-`#def` contract. Making
		// it strip `#def` too would remove those lines from the body while returning no value for
		// them, so `%x%` would survive into the output as literal text — a directive silently
		// eaten. Callers that want both use `extract_directives()`.
		$variables = array();

		$body = (string) preg_replace_callback(
			self::SET_DIRECTIVE_PATTERN,
			static function ( array $m ) use ( &$variables ): string {
				$variables[ strtolower( $m[1] ) ] = $m[2];
				return '';
			},
			$text
		);

		$body = (string) preg_replace( "/\n{3,}/u", "\n\n", $body );

		return array(
			'body'      => $body,
			'variables' => $variables,
		);
	}

	/**
	 * Order `#def` names so a definition is rendered after everything it depends on.
	 *
	 * Dependencies are followed **through `#set` values**. A `#def` can reach another `#def` by way
	 * of an alias — `#def %b% = %s%` where `#set %s% = %a%` — and because a `#set` is expanded at
	 * reference time, that dependency is invisible in `%b%`'s own text. Discovering only direct
	 * references would roll `%b%` while `%a%` is still raw, and the frozen value would carry an
	 * unexpanded `%a%`.
	 *
	 * A cycle cannot be ordered, so its members are emitted last in declaration order; rendering
	 * them then relies on `expand_variables()`'s own depth guard rather than looping here.
	 *
	 * @param array<string, string> $definitions `#def` values, name => raw value.
	 * @param array<string, string> $set_values  `#set` values, name => raw value, for alias hops.
	 * @return list<string> Definition names, dependencies first.
	 */
	public function order_definitions( array $definitions, array $set_values = array() ): array {
		$names   = array_keys( $definitions );
		$blocked = array();

		foreach ( $definitions as $name => $value ) {
			$blocked[ $name ] = array_intersect(
				$this->referenced_names( $value, $set_values ),
				$names
			);
		}

		$ordered = array();
		$pending = $names;

		while ( ! empty( $pending ) ) {
			$progressed = false;

			foreach ( $pending as $index => $name ) {
				foreach ( $blocked[ $name ] as $dependency ) {
					if ( $dependency !== $name && in_array( $dependency, $pending, true ) ) {
						continue 2;
					}
				}

				$ordered[] = $name;
				unset( $pending[ $index ] );
				$progressed = true;
			}

			$pending = array_values( $pending );

			if ( ! $progressed ) {
				return array_merge( $ordered, $pending );
			}
		}

		return $ordered;
	}

	/**
	 * Every variable name a value reaches, hopping through `#set` aliases.
	 *
	 * @param string                $value      Raw value.
	 * @param array<string, string> $set_values `#set` values to follow through.
	 * @return list<string> Referenced names, lowercased.
	 */
	private function referenced_names( string $value, array $set_values ): array {
		$queue = $this->direct_references( $value );
		$seen  = array();

		while ( ! empty( $queue ) ) {
			$name = array_shift( $queue );

			if ( isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;

			if ( array_key_exists( $name, $set_values ) ) {
				foreach ( $this->direct_references( $set_values[ $name ] ) as $next ) {
					$queue[] = $next;
				}
			}
		}

		return array_keys( $seen );
	}

	/**
	 * The `%var%` names written literally in a string.
	 *
	 * @param string $text Text to scan.
	 * @return list<string> Names, lowercased.
	 */
	private function direct_references( string $text ): array {
		if ( ! preg_match_all( self::VARIABLE_PATTERN, $text, $matches ) ) {
			return array();
		}

		return array_map( 'strtolower', $matches[1] );
	}

	/**
	 * Extract `#set` and `#def` directives and remove them from the body.
	 *
	 * `#set` is a macro: the value is substituted at every `%var%` reference and
	 * whatever brackets it holds resolve independently each time. `#def` is
	 * roll-once: the value is rendered a single time and the result is held for
	 * every reference. Both share one grammar, deliberately — see
	 * `DIRECTIVE_PATTERN`.
	 *
	 * `occurrences` preserves every directive line in source order, including
	 * duplicates that the `set` / `def` maps flatten away. A validator cannot
	 * report a collision it can no longer see, so the raw list is the contract.
	 *
	 * @param string $text Text containing directives.
	 * @return array{body: string, set: array<string, string>, def: array<string, string>, occurrences: list<array{kind: string, name: string, value: string, line: int}>}
	 */
	public function extract_directives( string $text ): array {
		$occurrences = array();

		if ( preg_match_all( self::DIRECTIVE_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$offset = (int) $match[0][1];

				$occurrences[] = array(
					'kind'  => strtolower( $match[1][0] ),
					'name'  => strtolower( $match[2][0] ),
					'value' => $match[3][0],
					'line'  => substr_count( $text, "\n", 0, $offset ) + 1,
				);
			}
		}

		$set = array();
		$def = array();

		foreach ( $occurrences as $occurrence ) {
			if ( 'def' === $occurrence['kind'] ) {
				$def[ $occurrence['name'] ] = $occurrence['value'];
			} else {
				$set[ $occurrence['name'] ] = $occurrence['value'];
			}
		}

		$body = (string) preg_replace( self::DIRECTIVE_PATTERN, '', $text );

		// Collapse blank lines left by stripped directives.
		$body = (string) preg_replace( "/\n{3,}/u", "\n\n", $body );

		return array(
			'body'        => $body,
			'set'         => $set,
			'def'         => $def,
			'occurrences' => $occurrences,
		);
	}

	/**
	 * Expand %var% references iteratively until none remain.
	 *
	 * @param string $text     Text with %var% references.
	 * @param array  $variables name => raw value (names without %).
	 * @return string Text with variables expanded.
	 *
	 * @throws \RuntimeException If circular/deep variable expansion detected.
	 */
	public function expand_variables( string $text, array $variables ): string {
		// Normalise variable keys to lowercase.
		$normalised = array();
		foreach ( $variables as $k => $v ) {
			$normalised[ strtolower( $k ) ] = $v;
		}

		for ( $i = 0; $i < self::MAX_VARIABLE_DEPTH; $i++ ) {
			$changed = false;
			$text    = preg_replace_callback(
				self::VARIABLE_PATTERN,
				static function ( array $m ) use ( $normalised, &$changed ): string {
					$name = strtolower( $m[1] );
					if ( isset( $normalised[ $name ] ) ) {
						$changed = true;
						return $normalised[ $name ];
					}
					return $m[0];
				},
				$text
			);

			if ( ! $changed ) {
				return $text;
			}
		}

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- exception message, not rendered output.
		throw new \RuntimeException(
			'Variable expansion exceeded maximum depth (' . self::MAX_VARIABLE_DEPTH . '). Possible circular reference.'
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Resolve all enumerations {a|b|c} from innermost outward.
	 *
	 * @param string $text Text containing enumeration syntax.
	 * @return string Text with enumerations resolved.
	 *
	 * @throws \RuntimeException If resolution exceeds maximum iterations.
	 */
	public function resolve_enumerations( string $text ): string {
		$iteration = 0;

		do {
			$text = preg_replace_callback(
				'/\{([^{}]*)\}/u',
				function ( array $m ): string {
					$options = $this->split_top_level( $m[1] );
					if ( empty( $options ) ) {
						return '';
					}
					return $options[ $this->random_int( 0, count( $options ) - 1 ) ];
				},
				$text,
				-1,
				$count
			);

			if ( ++$iteration >= self::MAX_ITERATIONS ) {
				throw new \RuntimeException( 'Enumeration resolution exceeded maximum iterations.' );
			}
		} while ( $count > 0 );

		return $text;
	}

	/**
	 * Resolve all permutations [<config>a|b|c] from innermost outward.
	 *
	 * @param string $text Text containing permutation syntax.
	 * @return string Text with permutations resolved.
	 *
	 * @throws \RuntimeException If resolution exceeds maximum iterations.
	 */
	public function resolve_permutations( string $text ): string {
		$iteration = 0;

		do {
			$text = preg_replace_callback(
				'/\[([^\[\]]*)\]/u',
				function ( array $m ): string {
					return $this->process_permutation( $m[1] );
				},
				$text,
				-1,
				$count
			);

			if ( ++$iteration >= self::MAX_ITERATIONS ) {
				throw new \RuntimeException( 'Permutation resolution exceeded maximum iterations.' );
			}
		} while ( $count > 0 );

		return $text;
	}

	/**
	 * Lightweight sentence and whitespace correction.
	 *
	 * Processing order matters — URLs, emails and domains are shielded
	 * from punctuation/capitalisation rules via placeholders.
	 *
	 * Pipeline:
	 *   1. Shield URLs, emails, domains → placeholders
	 *   2. Collapse duplicate whitespace
	 *   3. Fix punctuation spacing
	 *   4. Capitalise sentences
	 *   5. Restore placeholders
	 *
	 * @param string $text Text to post-process.
	 * @return string Cleaned-up text.
	 */
	public function post_process( string $text ): string {
		$placeholders                    = array();
		$counter                         = 0;
		$domain_part                     = '(?:(?:(?:xn--)?[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*)\.)+(?:xn--[a-z0-9\-]{2,59}|[\p{L}][\p{L}\p{N}-]{1,62})';
		$store_placeholder               = static function ( string $value, string $prefix ) use ( &$placeholders, &$counter ): string {
			$key                  = "\x00{$prefix}_{$counter}\x00";
			$placeholders[ $key ] = $value;
			++$counter;
			return $key;
		};
		$store_with_trailing_punctuation = static function ( string $value, string $prefix ) use ( $store_placeholder ): string {
			if ( preg_match( '/([.,;:!]+)$/u', $value, $m ) ) {
				$suffix = $m[1];
				$value  = substr( $value, 0, -strlen( $suffix ) );

				if ( '' === $value ) {
					return $suffix;
				}

				return $store_placeholder( $value, $prefix ) . $suffix;
			}

			return $store_placeholder( $value, $prefix );
		};

		// 1. Shield: URIs — full URLs (with protocol) and mailto:/tel: (no //
		// authority) — in ONE pass. Must run before the email/domain passes,
		// otherwise the address is carved out from under the prefix, leaving a
		// bare 'mailto:'/'tel:' whose colon then gets a space injected, producing
		// a malformed link.
		//
		// One pass, not two: a URI body runs to the first delimiter, so the two
		// match sets overlap whenever one URI carries the other's scheme, and the
		// second pass then runs into a placeholder the first had minted —
		// 'mailto:sales@x.com?body=see%20https://shop.x.com/cart' stored a value
		// containing URL_0's key, the restore was already past that key, and the
		// engine emitted a raw U+0000. Either pass order damages whichever URI
		// runs second; a single alternation has no second pass. U+0000 is excluded
		// from the body class for the caller-supplied case (spintax-js#53).
		$text = preg_replace_callback(
			'~(?:(?:https?|ftp)://|(?:mailto|tel):)[^\x00\s<>"\')\]]+~iu',
			static function ( array $m ) use ( $store_with_trailing_punctuation ): string {
				$prefix = preg_match( '~^(?:mailto|tel):~i', $m[0] ) ? 'URI' : 'URL';

				return $store_with_trailing_punctuation( $m[0], $prefix );
			},
			$text
		);

		// 2. Shield: email addresses.
		$text = preg_replace_callback(
			'~[a-z0-9._%+\-]+@' . $domain_part . '\b~iu',
			static function ( array $m ) use ( $store_placeholder ): string {
				return $store_placeholder( $m[0], 'EMAIL' );
			},
			$text
		);

		// 3. Shield: bare domains (ASCII + punycode + IDN).
		// Matches: example.com, sub.domain.co.uk, xn--e1afmapc.xn--p1ai, etc.
		// Requires dot-separated labels ending with a 2-63 char TLD that
		// contains at least one letter (excludes pure numbers like 3.14).
		$text = preg_replace_callback(
			'~\b' . $domain_part . '\b~iu',
			static function ( array $m ) use ( $store_placeholder ): string {
				return $store_placeholder( $m[0], 'DOM' );
			},
			$text
		);

		// 4. Shield: decimal numbers (3.14, 100.5).
		$text = preg_replace_callback(
			'/\b\d+\.\d+\b/',
			static function ( array $m ) use ( &$placeholders, &$counter ): string {
				$key                  = "\x00NUM_{$counter}\x00";
				$placeholders[ $key ] = $m[0];
				++$counter;
				return $key;
			},
			$text
		);

		// 5a. Shield: abbreviations (e.g. etc.).
		// Requires at least two dotted groups, so plain "A." still ends a sentence.
		$text = preg_replace_callback(
			'/\b(?:\p{L}{1,2}\.\s*){2,}/u',
			static function ( array $m ) use ( $store_placeholder ): string {
				return $store_placeholder( $m[0], 'ABBR' );
			},
			$text
		);

		// 5b. Shield: single-token abbreviations from a curated whitelist.
		// Step 5a needs 2+ dotted groups, so single-word abbreviations like
		// "соц.", "эл.", "г.", "Mr." look like sentence ends and step 9
		// would capitalise the next word ("соц. Сети", "эл. Почты"). The
		// whitelist below enumerates forms common enough in editorial copy
		// to be worth shielding by name. Case-insensitive — covers both
		// sentence-initial "Соц." and inline "соц.". A real sentence end
		// (like "ОК.", "А.") is intentionally NOT in the list — the parser
		// can't tell those from a real period.
		$single_abbrevs = array(
			// Russian — common editorial / address / unit shorthands.
			'соц',
			'эл',
			'см',
			'ср',
			'ст',
			'ул',
			'пр',
			'пер',
			'г',
			'р',
			'руб',
			'коп',
			'тыс',
			'млн',
			'млрд',
			'трлн',
			'доп',
			'напр',
			'прим',
			'изд',
			'обл',
			'респ',
			'стр',
			'табл',
			'рис',
			'мин',
			'макс',
			'тел',
			'факс',
			// English — titles, business suffixes, common editorial.
			'etc',
			'vs',
			'Mr',
			'Mrs',
			'Ms',
			'Dr',
			'Prof',
			'Sr',
			'Jr',
			'Inc',
			'Ltd',
			'Co',
			'Corp',
			'No',
			'St',
			'Ave',
			'Blvd',
		);
		$single_abbr_re = '/(?<![\p{L}\p{N}])(?:' . implode( '|', $single_abbrevs ) . ')\.(?=\s|$|<)/iu';
		$text           = preg_replace_callback(
			$single_abbr_re,
			static function ( array $m ) use ( $store_placeholder ): string {
				return $store_placeholder( $m[0], 'ABBR' );
			},
			$text
		);

		// 6. Whitespace cleanup.
		$text = preg_replace( '/[ \t]{2,}/u', ' ', $text );

		// 7. Punctuation spacing.
		// Remove whitespace BEFORE punctuation: "word ," becomes "word,".
		$text = preg_replace( '/\s+([,;:!?.])/u', '$1', $text );
		// Ensure space AFTER comma/semicolon/colon unless followed by
		// digit, whitespace, end, or tag. Placeholders (\x00) are allowed;
		// they will be restored later and need the space before them.
		$text = preg_replace( '/([,;:])(?!\d)(?!\s|$|<)/u', '$1 ', $text );
		// Ensure space AFTER sentence-ending punctuation (.!?) with same rules. The class matches a
		// RUN of marks, and the run must be complete (`(?![.!?])`): "..." and "?!" are ONE sentence
		// end, not several, so the space belongs after the run and never inside it. The guard is what
		// forces that — a greedy `+` on its own still backtracks INTO the run to satisfy the
		// lookaheads, which turns "Wow!!!" into "Wow!! !" and "Wait... what" into "Wait.. . what".
		$text = preg_replace( '/([.!?]+)(?![.!?])(?!\d)(?!\s|$|<)/u', '$1 ', $text );

		// 7a. Sentence openers: Spanish OPENS a question/exclamation with an inverted mark, so the
		// mark binds to the word it opens ("¿ qué" becomes "¿qué"). Runs BEFORE capitalisation, so
		// the passes below see the real first letter instead of a space.
		$text = preg_replace( '/([¿¡])\s+/u', '$1', $text );

		// 8-11. Capitalisation.
		// Between a sentence boundary and the first letter there can be a RUN of HTML tags, SENTENCE
		// OPENERS (¿ ¡ — Spanish is the only European language whose punctuation OPENS a sentence)
		// and whitespace, in any order and any number. Two shapes the earlier rule missed:
		// "¡¿Qué haces?!" (RAE's question-exclamation) opens with TWO marks, and
		// "<p>¿<a href="/ayuda">Necesitas ayuda</a>?</p>" puts a tag AFTER the opener.
		// The capitalisers upper-case the first CHARACTER after the boundary, and an inverted mark
		// has no uppercase form, so whatever $lead fails to cover silently keeps a lowercase first
		// letter. The opener set stays deliberately narrow: quotes and brackets both open AND close,
		// and capitalising after them would mangle list markers ("Elige una. (a) primero").
		$lead = '(?:<[^>]+>|[¿¡]|\s)*';

		// 8. Capitalise first letter (skip leading HTML tags and sentence openers).
		$text = preg_replace_callback(
			'/^(' . $lead . ')(\p{Ll})/u',
			static fn( array $m ): string => $m[1] . mb_strtoupper( $m[2], 'UTF-8' ),
			$text
		);

		// 9. Capitalise after sentence-ending punctuation.
		// For example: "text. Next", "text.</p><p>next" and "Hola. ¿<em>Qué</em> tal?".
		$text = preg_replace_callback(
			'/([.!?…])(' . $lead . ')(\p{Ll})/u',
			static fn( array $m ): string => $m[1] . $m[2] . mb_strtoupper( $m[3], 'UTF-8' ),
			$text
		);

		// 10. Capitalise after block-level HTML tags.
		// Covers p, h1-h6, li, blockquote, div, td, th.
		$text = preg_replace_callback(
			'/(<\/?(?:p|h[1-6]|li|blockquote|div|td|th)[^>]*>' . $lead . ')(\p{Ll})/ui',
			static fn( array $m ): string => $m[1] . mb_strtoupper( $m[2], 'UTF-8' ),
			$text
		);

		// 11. Capitalise after line breaks.
		$text = preg_replace_callback(
			'/(\n' . $lead . ')(\p{Ll})/u',
			static fn( array $m ): string => $m[1] . mb_strtoupper( $m[2], 'UTF-8' ),
			$text
		);

		// 12. Restore placeholders (reverse order for safety).
		if ( ! empty( $placeholders ) ) {
			$text = str_replace(
				array_keys( $placeholders ),
				array_values( $placeholders ),
				$text
			);
		}

		return trim( $text );
	}

	/**
	 * Find #include directives in text.
	 *
	 * @param string $text Text to search for #include directives.
	 * @return array<array{slug: string, line: int, start: int, length: int}>
	 */
	public function find_include_directives( string $text ): array {
		$includes = array();

		if ( preg_match_all( '/^[ \t]*#include\s+"([^"]+)"\s*$/mu', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $i => $full_match ) {
				$offset = $full_match[1];
				$line   = substr_count( $text, "\n", 0, $offset ) + 1;

				$includes[] = array(
					'slug'   => $matches[1][ $i ][0],
					'line'   => $line,
					'start'  => $offset,
					'length' => strlen( $full_match[0] ),
				);
			}
		}

		return $includes;
	}

	/**
	 * Replace #include directives in text using a resolver callback.
	 *
	 * @param string   $text     Text that may contain #include directives.
	 * @param callable $resolver fn(string $slug_or_id): string — returns rendered content.
	 * @return string Text with includes resolved.
	 */
	public function resolve_includes( string $text, callable $resolver ): string {
		return preg_replace_callback(
			'/^[ \t]*#include\s+"([^"]+)"\s*$/mu',
			static fn( array $m ): string => $resolver( $m[1] ),
			$text
		);
	}

	/**
	 * Split text by | respecting {} and [] nesting.
	 *
	 * @param string $text Text to split by top-level pipe characters.
	 * @return string[]
	 */
	private function split_top_level( string $text ): array {
		$parts         = array();
		$current       = '';
		$brace_depth   = 0;
		$bracket_depth = 0;
		$len           = strlen( $text );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $text[ $i ];

			if ( '{' === $ch ) {
				++$brace_depth;
				$current .= $ch;
			} elseif ( '}' === $ch ) {
				--$brace_depth;
				$current .= $ch;
			} elseif ( '[' === $ch ) {
				++$bracket_depth;
				$current .= $ch;
			} elseif ( ']' === $ch ) {
				--$bracket_depth;
				$current .= $ch;
			} elseif ( '|' === $ch && 0 === $brace_depth && 0 === $bracket_depth ) {
				$parts[] = $current;
				$current = '';
			} else {
				$current .= $ch;
			}
		}

		$parts[] = $current;
		return $parts;
	}

	/**
	 * Process a single permutation expression (content between [ and ]).
	 *
	 * @param string $content Permutation content without outer brackets.
	 * @return string Resolved permutation result.
	 */
	private function process_permutation( string $content ): string {
		$extracted = $this->extract_permutation_config( $content );
		$config    = $extracted['config'];
		$body      = $extracted['content'];

		$raw_parts = $this->split_top_level( $body );
		$elements  = $this->extract_per_element_separators( $raw_parts );

		if ( empty( $elements ) ) {
			return '';
		}

		$total   = count( $elements );
		$sep     = $config['sep'];
		$lastsep = $config['lastsep'] ?? $sep;

		// When only maxsize is set, minsize defaults to 1 (not total).
		// When only minsize is set, maxsize defaults to total.
		// When neither is set, both default to total (use all elements).
		$has_min = null !== $config['minsize'];
		$has_max = null !== $config['maxsize'];

		if ( $has_min && $has_max ) {
			$minsize = $config['minsize'];
			$maxsize = $config['maxsize'];
		} elseif ( $has_min ) {
			$minsize = $config['minsize'];
			$maxsize = $total;
		} elseif ( $has_max ) {
			$minsize = 1;
			$maxsize = $config['maxsize'];
		} else {
			$minsize = $total;
			$maxsize = $total;
		}

		$minsize = max( 1, min( $minsize, $total ) );
		$maxsize = max( $minsize, min( $maxsize, $total ) );

		$pick = $this->random_int( $minsize, $maxsize );

		$this->shuffle_array( $elements );
		$selected = array_slice( $elements, 0, $pick );

		return $this->join_with_separators( $selected, $sep, $lastsep );
	}

	/**
	 * Extract optional <config> prefix from permutation content.
	 *
	 * @param string $content Raw permutation content.
	 * @return array{config: array, content: string}
	 */
	private function extract_permutation_config( string $content ): array {
		$trimmed = ltrim( $content );

		if ( '' === $trimmed || '<' !== $trimmed[0] ) {
			return array(
				'config'  => $this->default_permutation_config(),
				'content' => $content,
			);
		}

		$end = $this->find_config_end( $trimmed, 0 );
		if ( -1 === $end ) {
			return array(
				'config'  => $this->default_permutation_config(),
				'content' => $content,
			);
		}

		$config_str = substr( $trimmed, 1, $end - 1 );
		$remaining  = substr( $trimmed, $end + 1 );

		if ( $this->looks_like_html_start_tag( $config_str, $remaining ) ) {
			return array(
				'config'  => $this->default_permutation_config(),
				'content' => $content,
			);
		}

		return array(
			'config'  => $this->parse_config_string( $config_str ),
			'content' => $remaining,
		);
	}

	/**
	 * Find closing > of a config block, respecting quoted strings.
	 *
	 * @param string $text  Text to search.
	 * @param int    $start Start position (opening <).
	 * @return int Position of closing > or -1 if not found.
	 */
	private function find_config_end( string $text, int $start ): int {
		$in_quote = false;
		$len      = strlen( $text );

		for ( $i = $start + 1; $i < $len; $i++ ) {
			if ( '"' === $text[ $i ] ) {
				$in_quote = ! $in_quote;
			}
			if ( '>' === $text[ $i ] && ! $in_quote ) {
				return $i;
			}
		}

		return -1;
	}

	/**
	 * Parse a config string into parameters.
	 *
	 * Supports two forms:
	 *   - Full config:  minsize=2;maxsize=3;sep=", ";lastsep=" and "
	 *   - Single separator: , (literal separator string)
	 *
	 * @param string $str Config string to parse.
	 * @return array Parsed configuration parameters.
	 */
	private function parse_config_string( string $str ): array {
		$config = $this->default_permutation_config();

		// Detect full config by presence of known key names with =.
		if ( preg_match( '/\b(?:minsize|maxsize|sep|lastsep)\s*=/i', $str ) ) {
			if ( preg_match( '/minsize\s*=\s*(\d+)/i', $str, $m ) ) {
				$config['minsize'] = (int) $m[1];
			}
			if ( preg_match( '/maxsize\s*=\s*(\d+)/i', $str, $m ) ) {
				$config['maxsize'] = (int) $m[1];
			}
			// sep="value" — negative lookbehind excludes "lastsep".
			if ( preg_match( '/(?<!last)sep\s*=\s*"([^"]*)"/i', $str, $m ) ) {
				$config['sep'] = $m[1];
			}
			if ( preg_match( '/lastsep\s*=\s*"([^"]*)"/i', $str, $m ) ) {
				$config['lastsep'] = $m[1];
			}
		} else {
			// Single separator string.
			$config['sep']     = $str;
			$config['lastsep'] = $str;
		}

		return $config;
	}

	/**
	 * Detect whether a leading <...> block is actually an HTML start tag.
	 *
	 * This avoids mis-parsing permutations like [<li>item</li>|...] as if
	 * `<li>` were the permutation config.
	 *
	 * @param string $tag_text  Inner contents of the leading <...> block.
	 * @param string $remaining Text that follows the closing >.
	 * @return bool True when the block should be treated as HTML, not config.
	 */
	private function looks_like_html_start_tag( string $tag_text, string $remaining ): bool {
		$trimmed = trim( $tag_text );
		if ( '' === $trimmed ) {
			return false;
		}

		if ( ! preg_match( '/^([a-zA-Z][a-zA-Z0-9-]*)(?:\s+[^>]*)?\/?$/', $trimmed, $m ) ) {
			return false;
		}

		$tag_name = strtolower( $m[1] );

		if ( str_ends_with( $trimmed, '/' ) ) {
			return true;
		}

		return 1 === preg_match(
			'/<\/' . preg_quote( $tag_name, '/' ) . '\s*>/i',
			$remaining
		);
	}

	/**
	 * Default permutation configuration.
	 */
	private function default_permutation_config(): array {
		return array(
			'minsize' => null,
			'maxsize' => null,
			'sep'     => ' ',
			'lastsep' => null,
		);
	}

	/**
	 * Extract per-element separators from raw split parts.
	 *
	 * A trailing `< sep >` in part[i] becomes the custom_sep of the element from part[i+1].
	 *
	 * @param array $raw_parts Raw string array from split_top_level.
	 * @return array<array{text: string, custom_sep: string|null}> Elements with optional per-element separators.
	 */
	private function extract_per_element_separators( array $raw_parts ): array {
		$elements    = array();
		$pending_sep = null;

		foreach ( $raw_parts as $i => $part ) {
			$trailing_sep = null;

			// Check for trailing <sep> (only on non-last parts).
			if ( $i < count( $raw_parts ) - 1 ) {
				$extracted = $this->extract_trailing_sep( $part );
				if ( null !== $extracted ) {
					$part         = $extracted['text'];
					$trailing_sep = $extracted['sep'];
				}
			}

			$text = trim( $part );
			if ( '' !== $text ) {
				$elements[] = array(
					'text'       => $text,
					'custom_sep' => $pending_sep,
				);
			}

			$pending_sep = $trailing_sep;
		}

		return $elements;
	}

	/**
	 * Detect a per-element separator `< sep >` at the end of a raw part string.
	 *
	 * @param string $part Raw part text.
	 * @return array{text: string, sep: string}|null Extracted text and separator, or null.
	 */
	private function extract_trailing_sep( string $part ): ?array {
		$trimmed = rtrim( $part );
		$len     = strlen( $trimmed );

		if ( 0 === $len || '>' !== $trimmed[ $len - 1 ] ) {
			return null;
		}

		// Find the matching '<' scanning backward.
		$open_pos = -1;
		for ( $i = $len - 2; $i >= 0; $i-- ) {
			if ( '<' === $trimmed[ $i ] ) {
				$open_pos = $i;
				break;
			}
			// Another '>' before finding '<' — nested/complex, bail.
			if ( '>' === $trimmed[ $i ] ) {
				return null;
			}
		}

		if ( -1 === $open_pos ) {
			return null;
		}

		$inner         = substr( $trimmed, $open_pos + 1, $len - $open_pos - 2 );
		$inner_trimmed = trim( $inner );

		// HTML tag detection: closing tag </x>, self-closing <x/>, or tag with attributes <x ...>.
		if (
			str_starts_with( $inner_trimmed, '/' )
			|| str_ends_with( $inner_trimmed, '/' )
			|| preg_match( '/^[a-zA-Z][a-zA-Z0-9]*\s/', $inner_trimmed )
		) {
			return null;
		}

		return array(
			'text' => substr( $trimmed, 0, $open_pos ),
			'sep'  => $inner,
		);
	}

	/**
	 * Auto-pad purely alphabetic separators with spaces.
	 *
	 * @param string $sep Separator string.
	 * @return string Padded separator if purely alphabetic, otherwise unchanged.
	 */
	private function pad_separator_if_needed( string $sep ): string {
		$trimmed = trim( $sep );
		if ( '' === $trimmed ) {
			return $sep;
		}
		if ( preg_match( '/^\p{L}+$/u', $trimmed ) ) {
			return ' ' . $trimmed . ' ';
		}
		return $sep;
	}

	/**
	 * Fisher-Yates shuffle using the custom RNG.
	 *
	 * @param array $arr Array of element structs to shuffle in place.
	 */
	private function shuffle_array( array &$arr ): void {
		$n = count( $arr );
		for ( $i = $n - 1; $i > 0; $i-- ) {
			$j         = $this->random_int( 0, $i );
			$tmp       = $arr[ $i ];
			$arr[ $i ] = $arr[ $j ];
			$arr[ $j ] = $tmp;
		}
	}

	/**
	 * Join elements with separators. Per-element custom_sep overrides globals.
	 *
	 * @param array  $elements   Array of element structs with text and custom_sep.
	 * @param string $global_sep Separator between non-final items.
	 * @param string $global_lastsep Separator before the last item.
	 * @return string Joined string.
	 */
	private function join_with_separators( array $elements, string $global_sep, string $global_lastsep ): string {
		$count = count( $elements );

		if ( 0 === $count ) {
			return '';
		}
		if ( 1 === $count ) {
			return $elements[0]['text'];
		}

		$parts = array();

		for ( $i = 0; $i < $count; $i++ ) {
			if ( 0 === $i ) {
				$parts[] = $elements[ $i ]['text'];
			} else {
				if ( null !== $elements[ $i ]['custom_sep'] ) {
					$sep = $elements[ $i ]['custom_sep'];
				} elseif ( $i === $count - 1 ) {
					$sep = $global_lastsep;
				} else {
					$sep = $global_sep;
				}
				$parts[] = $this->pad_separator_if_needed( $sep );
				$parts[] = $elements[ $i ]['text'];
			}
		}

		return implode( '', $parts );
	}

	/**
	 * Generate a random integer using the configured RNG.
	 *
	 * @param int $min Minimum value (inclusive).
	 * @param int $max Maximum value (inclusive).
	 * @return int Random integer between min and max.
	 */
	private function random_int( int $min, int $max ): int {
		if ( $min === $max ) {
			return $min;
		}
		return ( $this->random_fn )( $min, $max );
	}
}
