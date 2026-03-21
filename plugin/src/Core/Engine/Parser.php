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

	private const MAX_ITERATIONS    = 10000;
	private const MAX_VARIABLE_DEPTH = 50;

	/**
	 * @param callable|null $random_fn Custom RNG for deterministic testing. Signature: fn(int $min, int $max): int.
	 */
	public function __construct( ?callable $random_fn = null ) {
		$this->random_fn = $random_fn ?? static function ( int $min, int $max ): int {
			return random_int( $min, $max );
		};
	}

	// =========================================================================
	// Public API
	// =========================================================================

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
	 */
	public function process( string $template, array $variables = array() ): string {
		$text      = $this->strip_comments( $template );
		$extracted = $this->extract_set_directives( $text );
		$text      = $extracted['body'];
		$all_vars  = array_merge( $extracted['variables'], $variables );
		$text      = $this->expand_variables( $text, $all_vars );
		$text      = $this->resolve_enumerations( $text );
		$text      = $this->resolve_permutations( $text );
		$text      = $this->post_process( $text );

		return $text;
	}

	/**
	 * Strip block comments delimited by /# ... #/.
	 */
	public function strip_comments( string $text ): string {
		return preg_replace( '~/\#.*?\#/~su', '', $text );
	}

	/**
	 * Extract #set directives and remove them from the body.
	 *
	 * @return array{body: string, variables: array<string, string>}
	 */
	public function extract_set_directives( string $text ): array {
		$variables = array();

		$body = preg_replace_callback(
			'/^[ \t]*#set\s+%(\w+)%\s*=\s*(.*?)$/mu',
			static function ( array $m ) use ( &$variables ): string {
				$name              = strtolower( $m[1] );
				$variables[ $name ] = trim( $m[2] );
				return '';
			},
			$text
		);

		// Collapse blank lines left by stripped directives.
		$body = preg_replace( "/\n{3,}/u", "\n\n", $body );

		return array(
			'body'      => $body,
			'variables' => $variables,
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
				'/%(\w+)%/u',
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

		throw new \RuntimeException(
			'Variable expansion exceeded maximum depth (' . self::MAX_VARIABLE_DEPTH . '). Possible circular reference.'
		);
	}

	/**
	 * Resolve all enumerations {a|b|c} from innermost outward.
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
	 */
	public function post_process( string $text ): string {
		$placeholders = array();
		$counter      = 0;
		$domain_part  = '(?:(?:(?:xn--)?[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*)\.)+(?:xn--[a-z0-9\-]{2,59}|[\p{L}][\p{L}\p{N}-]{1,62})';
		$store_placeholder = static function ( string $value, string $prefix ) use ( &$placeholders, &$counter ): string {
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

		// --- 1. Shield: full URLs (with protocol) --------------------------
		$text = preg_replace_callback(
			'~(?:https?|ftp)://[^\s<>"\')\]]+~iu',
			static function ( array $m ) use ( $store_with_trailing_punctuation ): string {
				return $store_with_trailing_punctuation( $m[0], 'URL' );
			},
			$text
		);

		// --- 2. Shield: email addresses ------------------------------------
		$text = preg_replace_callback(
			'~[a-z0-9._%+\-]+@' . $domain_part . '\b~iu',
			static function ( array $m ) use ( $store_placeholder ): string {
				return $store_placeholder( $m[0], 'EMAIL' );
			},
			$text
		);

		// --- 3. Shield: bare domains (ASCII + punycode + IDN) --------------
		// Matches: example.com, sub.domain.co.uk, xn--e1afmapc.xn--p1ai, домен.рф
		// Requires dot-separated labels ending with a 2-63 char TLD that
		// contains at least one letter (excludes pure numbers like 3.14).
		$text = preg_replace_callback(
			'~\b' . $domain_part . '\b~iu',
			static function ( array $m ) use ( $store_placeholder ): string {
				return $store_placeholder( $m[0], 'DOM' );
			},
			$text
		);

		// --- 4. Shield: decimal numbers (3.14, 100.5) ----------------------
		$text = preg_replace_callback(
			'/\b\d+\.\d+\b/',
			static function ( array $m ) use ( &$placeholders, &$counter ): string {
				$key                    = "\x00NUM_{$counter}\x00";
				$placeholders[ $key ]   = $m[0];
				++$counter;
				return $key;
			},
			$text
		);

		// --- 5. Shield: abbreviations (т.д., и т.п., etc.) ----------------
		// Requires at least two dotted groups, so plain "A." still ends a sentence.
		$text = preg_replace_callback(
			'/\b(?:\p{L}{1,2}\.\s*){2,}/u',
			static function ( array $m ) use ( $store_placeholder ): string {
				return $store_placeholder( $m[0], 'ABBR' );
			},
			$text
		);

		// --- 6. Whitespace cleanup -----------------------------------------
		$text = preg_replace( '/[ \t]{2,}/u', ' ', $text );

		// --- 7. Punctuation spacing ----------------------------------------
		// Remove whitespace BEFORE punctuation:  "word ," → "word,"
		$text = preg_replace( '/\s+([,;:!?.])/u', '$1', $text );
		// Ensure space AFTER comma/semicolon/colon unless followed by
		// digit, whitespace, end, or tag. Placeholders (\x00) are allowed
		// — they will be restored later and need the space before them.
		$text = preg_replace( '/([,;:])(?!\d)(?!\s|$|<)/u', '$1 ', $text );
		// Ensure space AFTER sentence-ending punctuation (.!?) same rules.
		$text = preg_replace( '/([.!?])(?!\d)(?!\s|$|<)/u', '$1 ', $text );

		// --- 8. Capitalise first letter (skip leading HTML tags) -----------
		$text = preg_replace_callback(
			'/^(\s*(?:<[^>]+>\s*)*)(\p{Ll})/u',
			static fn( array $m ): string => $m[1] . mb_strtoupper( $m[2], 'UTF-8' ),
			$text
		);

		// --- 9. Capitalise after sentence-ending punctuation ---------------
		// Handles punctuation followed by optional HTML tags before the letter:
		//   "text. Next"  and  "text.</p><p>next"
		$text = preg_replace_callback(
			'/([.!?…])(\s*(?:<\/?[^>]+>\s*)*)(\p{Ll})/u',
			static fn( array $m ): string => $m[1] . $m[2] . mb_strtoupper( $m[3], 'UTF-8' ),
			$text
		);

		// --- 10. Capitalise after block-level HTML tags --------------------
		// After <p>, </p><p>, <h1>–<h6>, <li>, <blockquote>, <div>, <td>, <th>
		$text = preg_replace_callback(
			'/(<\/?(?:p|h[1-6]|li|blockquote|div|td|th)[^>]*>\s*)(\p{Ll})/ui',
			static fn( array $m ): string => $m[1] . mb_strtoupper( $m[2], 'UTF-8' ),
			$text
		);

		// --- 11. Capitalise after line breaks ------------------------------
		$text = preg_replace_callback(
			'/(\n\s*)(\p{Ll})/u',
			static fn( array $m ): string => $m[1] . mb_strtoupper( $m[2], 'UTF-8' ),
			$text
		);

		// --- 9. Restore placeholders (reverse order for safety) ------------
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

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Split text by | respecting {} and [] nesting.
	 *
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
	 */
	private function process_permutation( string $content ): string {
		$extracted = $this->extract_permutation_config( $content );
		$config    = $extracted['config'];
		$body      = $extracted['content'];

		$elements = $this->split_top_level( $body );
		$elements = array_map( 'trim', $elements );
		$elements = array_values( array_filter( $elements, static fn( string $e ): bool => '' !== $e ) );

		if ( empty( $elements ) ) {
			return '';
		}

		$total   = count( $elements );
		$minsize = $config['minsize'] ?? $total;
		$maxsize = $config['maxsize'] ?? $total;
		$sep     = $config['sep'];
		$lastsep = $config['lastsep'] ?? $sep;

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

		return array(
			'config'  => $this->parse_config_string( $config_str ),
			'content' => $remaining,
		);
	}

	/**
	 * Find closing > of a config block, respecting quoted strings.
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
	 * Fisher-Yates shuffle using the custom RNG.
	 */
	private function shuffle_array( array &$arr ): void {
		$n = count( $arr );
		for ( $i = $n - 1; $i > 0; $i-- ) {
			$j          = $this->random_int( 0, $i );
			$tmp        = $arr[ $i ];
			$arr[ $i ]  = $arr[ $j ];
			$arr[ $j ]  = $tmp;
		}
	}

	/**
	 * Join elements with sep between non-final items and lastsep before the last.
	 */
	private function join_with_separators( array $elements, string $sep, string $lastsep ): string {
		$count = count( $elements );

		if ( 0 === $count ) {
			return '';
		}
		if ( 1 === $count ) {
			return $elements[0];
		}

		$last = array_pop( $elements );
		return implode( $sep, $elements ) . $lastsep . $last;
	}

	/**
	 * Generate a random integer using the configured RNG.
	 */
	private function random_int( int $min, int $max ): int {
		if ( $min === $max ) {
			return $min;
		}
		return ( $this->random_fn )( $min, $max );
	}
}
