<?php
/**
 * Spintax conditional pre-pass — `{?VAR?then|else}` resolution.
 *
 * @package Spintax
 */

namespace Spintax\Core\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Resolve `{?VAR?then|else}` conditional expressions against a variable map.
 *
 * Supported forms:
 *   {?VAR?then}            show then if VAR is truthy
 *   {?VAR?then|else}       show then if truthy, else otherwise
 *   {?!VAR?then}           show then if VAR is falsy
 *   {?!VAR?then|else}      inverted with else
 *
 * Truthy = the value is set and contains at least one non-whitespace
 * character. Lookup is case-insensitive (consistent with `%var%`).
 *
 * Lenient by design — malformed forms are left literal so the next
 * pipeline stage can decide whether to consume them. Typically called
 * twice from the renderer: once before `expand_variables()` (for
 * conditionals authored directly in the template body) and once after
 * (for conditionals that were introduced via substituted variable
 * values).
 *
 * Mirrors `spintax-conditionals.ts` (146 LOC) in casino-platform; see
 * `docs/spintax-conditionals-spec.md` there for the v1 contract.
 */
class Conditionals {

	private const NAME_RE = '/^[A-Za-z_][A-Za-z0-9_]*/';

	/**
	 * Expand `{?...?...}` conditionals against the variables map.
	 *
	 * @param string                $template  Template body.
	 * @param array<string, string> $variables Variable map (name => raw value).
	 * @return string Template with conditionals resolved.
	 */
	public function apply( string $template, array $variables ): string {
		// Fast-path — most templates have no conditionals.
		if ( false === strpos( $template, '{?' ) ) {
			return $template;
		}

		$normalised = array();
		foreach ( $variables as $k => $v ) {
			$normalised[ strtolower( (string) $k ) ] = (string) $v;
		}

		return $this->apply_once( $template, $normalised );
	}

	/**
	 * Truthy = value is set and contains at least one non-whitespace char.
	 *
	 * @param string|null $value Value to test.
	 */
	private function is_truthy( ?string $value ): bool {
		return null !== $value && 1 === preg_match( '/\S/u', $value );
	}

	/**
	 * Apply conditionals once over the whole template. Recurses into the
	 * chosen branch so nested conditionals resolve in a single call.
	 *
	 * @param string                $template   Template body.
	 * @param array<string, string> $normalised Lowercased variable map.
	 */
	private function apply_once( string $template, array $normalised ): string {
		$out = '';
		$i   = 0;
		$len = strlen( $template );

		while ( $i < $len ) {
			$start = strpos( $template, '{?', $i );
			if ( false === $start ) {
				$out .= substr( $template, $i );
				break;
			}

			$out .= substr( $template, $i, $start - $i );

			$parsed = $this->parse_conditional( $template, $start );
			if ( null === $parsed ) {
				// Malformed at this position. Emit the leading `{` literal
				// and keep scanning — a real `{?...}` later in the string
				// still gets a chance.
				$out .= $template[ $start ];
				$i    = $start + 1;
				continue;
			}

			$lookup_key  = strtolower( $parsed['name'] );
			$value       = $normalised[ $lookup_key ] ?? null;
			$base_truthy = $this->is_truthy( $value );
			$truthy      = $parsed['inverted'] ? ! $base_truthy : $base_truthy;
			$chosen      = $truthy ? $parsed['then_branch'] : $parsed['else_branch'];

			$out .= $this->apply_once( $chosen, $normalised );
			$i    = $parsed['end'];
		}

		return $out;
	}

	/**
	 * Parse a single conditional starting at `$template[$start]`.
	 *
	 * Returns the parsed structure, or null if the form is malformed at
	 * any step (caller should leave the substring literal and keep
	 * scanning past the opening `{`).
	 *
	 * @param string $template Template body.
	 * @param int    $start    Index of the opening `{`.
	 * @return array{end:int, name:string, inverted:bool, then_branch:string, else_branch:string}|null
	 */
	private function parse_conditional( string $template, int $start ): ?array {
		if ( ! isset( $template[ $start ], $template[ $start + 1 ] ) ) {
			return null;
		}
		if ( '{' !== $template[ $start ] || '?' !== $template[ $start + 1 ] ) {
			return null;
		}

		$p = $start + 2;
		if ( ! isset( $template[ $p ] ) ) {
			return null;
		}

		$inverted = '!' === $template[ $p ];
		if ( $inverted ) {
			++$p;
		}

		// Variable name.
		$rest = substr( $template, $p );
		if ( 1 !== preg_match( self::NAME_RE, $rest, $m ) ) {
			return null;
		}
		$name = $m[0];
		$p   += strlen( $name );

		// Required `?` after the name.
		if ( ! isset( $template[ $p ] ) || '?' !== $template[ $p ] ) {
			return null;
		}
		++$p;

		// Walk to the matching `}` while tracking brace + bracket depth.
		// brace_depth starts at 1 because the opening `{` is already past.
		$body_start    = $p;
		$brace_depth   = 1;
		$bracket_depth = 0;
		$len           = strlen( $template );

		while ( $p < $len ) {
			$ch = $template[ $p ];
			if ( '{' === $ch ) {
				++$brace_depth;
			} elseif ( '}' === $ch ) {
				--$brace_depth;
				if ( 0 === $brace_depth ) {
					break;
				}
			} elseif ( '[' === $ch ) {
				++$bracket_depth;
			} elseif ( ']' === $ch ) {
				if ( $bracket_depth > 0 ) {
					--$bracket_depth;
				}
			}
			++$p;
		}
		if ( 0 !== $brace_depth ) {
			return null;
		}

		$body = substr( $template, $body_start, $p - $body_start );
		$end  = $p + 1;

		// Split the body on the first depth-0 `|`.
		$sep_pos    = -1;
		$body_depth = 0;
		$body_len   = strlen( $body );
		for ( $j = 0; $j < $body_len; $j++ ) {
			$ch = $body[ $j ];
			if ( '{' === $ch || '[' === $ch ) {
				++$body_depth;
			} elseif ( '}' === $ch || ']' === $ch ) {
				if ( $body_depth > 0 ) {
					--$body_depth;
				}
			} elseif ( '|' === $ch && 0 === $body_depth ) {
				$sep_pos = $j;
				break;
			}
		}

		if ( $sep_pos < 0 ) {
			$then_branch = $body;
			$else_branch = '';
		} else {
			$then_branch = substr( $body, 0, $sep_pos );
			$else_branch = substr( $body, $sep_pos + 1 );
		}

		return array(
			'end'         => $end,
			'name'        => $name,
			'inverted'    => $inverted,
			'then_branch' => $then_branch,
			'else_branch' => $else_branch,
		);
	}
}
