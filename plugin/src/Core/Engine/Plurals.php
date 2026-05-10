<?php
/**
 * Spintax plural-agreement pass — `{plural <count>: form1|form2|form3}` resolution.
 *
 * @package Spintax
 */

namespace Spintax\Core\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Resolve `{plural ...}` constructs by the active locale's plural rule.
 *
 * Syntax: `{plural <count>: form1|form2|form3}`
 *   <count> — literal integer string OR `%Var%` reference (already
 *             substituted by `expand_variables` upstream)
 *   forms   — pipe-separated, arity must match the locale family
 *             (3 for ru/uk/be, 2 for en/es/pt/de/...)
 *
 * Locale rules (V1):
 *   - East Slavic (ru/uk/be): one (1, 21, 31… not 11), few (2-4, 22-24…
 *                              not 12-14), many (everything else, 0).
 *   - EN-style (default):     one (n=1), many (everything else).
 *
 * Errors:
 *   - PluralFormError  — form slot contains nested spintax brackets
 *                        (`{` `}` `[` `]`). Extract via `#set` first.
 *   - PluralArityError — wrong number of forms for the locale family.
 *
 * Empty / missing / non-numeric count → entire construct → empty string.
 * Authors who need sentence-erase must gate with `{?CasinoHasFoo?…|}`.
 *
 * Lenient mode (production): catches both errors per-block, emits the
 * verbatim block text with fullwidth braces (U+FF5B / U+FF5D) so the
 * construct survives subsequent pipeline stages without being mistaken
 * for a synonym by the enumeration resolver.
 *
 * Mirrors `spintax-plurals.ts` in casino-platform; see
 * `docs/spintax-plurals-engine-plan.md` there for the v5 contract.
 */
class Plurals {

	/**
	 * Literal "plural" + one space — the unambiguous discriminator from
	 * synonym `{a|b|c}`. Must be followed by a count slot, `:`, and forms.
	 */
	private const PREFIX = '{plural ';

	/**
	 * Apply pluralisation. Finds all `{plural ...}` blocks via a brace-
	 * aware forward scan; replaces each with its resolved form.
	 *
	 * Options bag accepts:
	 *   - `lenient` (bool): catch errors per-block, emit verbatim with
	 *     fullwidth braces instead of throwing. Default false.
	 *   - `on_error` (callable): called with each caught \RuntimeException
	 *     when in lenient mode. Default null.
	 *
	 * @param string $text    Template body.
	 * @param string $lang    Render locale (raw — normalised to base).
	 * @param array  $options Options bag (see method body).
	 * @return string Template with plural constructs resolved.
	 *
	 * @throws PluralArityError When strict mode and a block has wrong arity.
	 * @throws PluralFormError  When strict mode and a form contains nested brackets.
	 */
	public function apply( string $text, string $lang, array $options = array() ): string {
		// Fast-path — most templates have no plural constructs.
		if ( false === strpos( $text, self::PREFIX ) ) {
			return $text;
		}

		$blocks = $this->find_plural_blocks( $text );
		if ( empty( $blocks ) ) {
			return $text;
		}

		$base_lang = $this->normalize_base_lang( $lang );
		$lenient   = ! empty( $options['lenient'] );
		$on_error  = $options['on_error'] ?? null;

		$out    = '';
		$cursor = 0;

		foreach ( $blocks as $block ) {
			$out .= substr( $text, $cursor, $block['start'] - $cursor );

			try {
				$out .= $this->resolve_plural_block( $block, $base_lang );
			} catch ( PluralArityError | PluralFormError $err ) {
				if ( ! $lenient ) {
					throw $err;
				}
				if ( null !== $on_error && is_callable( $on_error ) ) {
					$on_error( $err );
				}
				// Emit verbatim with fullwidth braces (U+FF5B / U+FF5D)
				// so the construct survives subsequent pipeline stages —
				// resolve_enumerations would otherwise see ASCII
				// "{plural N: a|b}" as a synonym and randomly pick.
				$verbatim = substr( $text, $block['start'], $block['end'] - $block['start'] );
				$verbatim = strtr(
					$verbatim,
					array(
						'{' => "\u{FF5B}",
						'}' => "\u{FF5D}",
					)
				);
				$out     .= $verbatim;
			}

			$cursor = $block['end'];
		}

		$out .= substr( $text, $cursor );
		return $out;
	}

	/**
	 * Brace-aware forward scanner for `{plural ...}` blocks.
	 *
	 * Walks linearly; for each `{plural ` occurrence, depth-counts braces
	 * to find the matching close. Skips unmatched openings (parser-error
	 * territory) and constructs without the required `:` delimiter
	 * (treated as not a plural block — could be literal copy).
	 *
	 * @param string $text Template body.
	 * @return array<int, array{start:int, end:int, count_slot:string, forms_raw:string}>
	 */
	public function find_plural_blocks( string $text ): array {
		$blocks     = array();
		$prefix_len = strlen( self::PREFIX );
		$len        = strlen( $text );
		$i          = 0;

		while ( $i < $len ) {
			$start = strpos( $text, self::PREFIX, $i );
			if ( false === $start ) {
				break;
			}

			// Walk forward from after the prefix, counting brace depth.
			$depth = 1;
			$j     = $start + $prefix_len;

			while ( $j < $len ) {
				$ch = $text[ $j ];
				if ( '{' === $ch ) {
					++$depth;
				} elseif ( '}' === $ch ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				}
				++$j;
			}

			if ( 0 !== $depth ) {
				// Unmatched opening — skip past the prefix to avoid infinite loop.
				$i = $start + $prefix_len;
				continue;
			}

			// text[start..j] is "{plural ... }". Extract inner.
			$inner     = substr( $text, $start + $prefix_len, $j - $start - $prefix_len );
			$colon_idx = strpos( $inner, ':' );

			if ( false === $colon_idx ) {
				// No colon — not a plural construct. Could be literal copy
				// like "{plural noun stuff}". Leave alone, keep scanning.
				$i = $j + 1;
				continue;
			}

			$blocks[] = array(
				'start'      => $start,
				'end'        => $j + 1,
				'count_slot' => substr( $inner, 0, $colon_idx ),
				'forms_raw'  => substr( $inner, $colon_idx + 1 ),
			);

			$i = $j + 1;
		}

		return $blocks;
	}

	/**
	 * Resolve a single plural block.
	 *
	 * @param array  $block     Block data with start, end, count_slot, forms_raw keys.
	 * @param string $base_lang Normalised base language tag.
	 * @return string Resolved form, or empty string on missing/invalid count.
	 *
	 * @throws PluralArityError When form count mismatches locale arity.
	 * @throws PluralFormError  When form slot contains nested spintax brackets.
	 */
	private function resolve_plural_block( array $block, string $base_lang ): string {
		$construct_text = '{plural ' . $block['count_slot'] . ':' . $block['forms_raw'] . '}';

		// Form-slot bracket validation. Reject any spintax-structural
		// bracket inside forms: `{` `}` close the synonym/conditional leak
		// gap; `[` `]` close the permutation leak gap. `<` `>` `%` remain
		// allowed — HTML tags and unresolved %Var% survive harmlessly as
		// form text.
		if ( 1 === preg_match( '/[{}\[\]]/', $block['forms_raw'] ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- exception message, not rendered output.
			throw new PluralFormError(
				sprintf(
					'{plural ...}: forms must not contain nested spintax brackets ({}, []). Extract synonym / conditional / permutation via #set first, then reference the resulting variable in plain form text. Construct: %s',
					$construct_text
				),
				$block['start'],
				$construct_text
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Strict numeric parse of count slot. Trim, then full-string
		// integer match. Rejects "1,200", "12abc", "08h", "%CasinoFoo%"
		// that didn't substitute, etc.
		$trimmed = trim( $block['count_slot'] );
		if ( 1 !== preg_match( '/^-?\d+$/', $trimmed ) ) {
			// Missing / empty / non-numeric / unsubstituted %Var%.
			return '';
		}
		$n = (int) $trimmed;

		// Forms split + arity validation. Each form is trimmed so the
		// "{plural N: a|b|c}" convention (whitespace after colon, around
		// pipes for readability) doesn't leak structural whitespace into
		// rendered output. Internal whitespace inside a form is preserved
		// (only leading/trailing trimmed).
		$forms    = array_map( 'trim', explode( '|', $block['forms_raw'] ) );
		$expected = $this->plural_arity( $base_lang );
		if ( count( $forms ) !== $expected ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- exception message, not rendered output.
			throw new PluralArityError(
				sprintf(
					'{plural ...}: expected %1$d forms for "%2$s", got %3$d. Construct: %4$s',
					$expected,
					$base_lang,
					count( $forms ),
					$construct_text
				),
				$block['start'],
				$construct_text
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Pick form per locale's plural rule.
		return $this->plural_for( $base_lang, $n, $forms );
	}

	/**
	 * Pick the matching plural form by the locale's grammar rule.
	 *
	 * - East Slavic (ru/uk/be): one (1, 21, 31… but not 11), few (2-4,
	 *   22-24… but not 12-14), many (everything else, including 0).
	 * - EN-style (default): one (n=1), many (everything else).
	 *
	 * @param string   $base_lang Normalised base language tag.
	 * @param int      $n         Count value (negative supported via abs).
	 * @param string[] $forms     Form options matching arity.
	 * @return string Selected form.
	 */
	public function plural_for( string $base_lang, int $n, array $forms ): string {
		$abs    = abs( $n );
		$mod10  = $abs % 10;
		$mod100 = $abs % 100;

		switch ( $base_lang ) {
			case 'ru':
			case 'uk':
			case 'be':
				if ( 1 === $mod10 && 11 !== $mod100 ) {
					return $forms[0];
				}
				if ( $mod10 >= 2 && $mod10 <= 4 && ( $mod100 < 12 || $mod100 > 14 ) ) {
					return $forms[1];
				}
				return $forms[2];

			default:
				return 1 === $abs ? $forms[0] : $forms[1];
		}
	}

	/**
	 * Expected number of plural forms for the locale.
	 *
	 * @param string $base_lang Normalised base language tag.
	 * @return int 3 for East Slavic, 2 for EN-style default.
	 */
	public function plural_arity( string $base_lang ): int {
		switch ( $base_lang ) {
			case 'ru':
			case 'uk':
			case 'be':
				return 3;
			default:
				// EN-style 2-form locales: en, es, pt, de, it, fr, nl, sv,
				// no, da, fi, etc. bg/pl/cs/sk/sl explicitly out of v1 —
				// they need separate rules.
				return 2;
		}
	}

	/**
	 * Normalise locale string to its base language tag.
	 *
	 * Examples: `pt-BR` → `pt`, `uk_UA` → `uk`, `es-419` → `es`,
	 * `RU` → `ru`.
	 *
	 * @param string $lang Raw locale string.
	 * @return string Normalised base language.
	 */
	public function normalize_base_lang( string $lang ): string {
		$parts = preg_split( '/[-_]/', strtolower( $lang ), 2 );
		return $parts[0] ?? '';
	}
}
