<?php
/**
 * Neutralises spintax structural characters in data-derived values.
 *
 * @package Spintax
 */

namespace Spintax\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Shared shield for T2 (data-derived) runtime-variable sources.
 *
 * The render engine treats every variable value as potential spintax (it is
 * resolved after `%var%` expansion). Values that come from records rather than
 * from a template author — WooCommerce product fields, post fields, ACF field
 * values — are DATA, not markup, and must not be re-interpreted by the engine.
 *
 * `neutralize()` entity-encodes the engine's structural characters so the value
 * renders literally: it can no longer form an enumeration / permutation /
 * conditional / plural, expand as a `%var%`, execute a nested `[spintax]`, or
 * inject a `#include` / `#set` line directive. The numeric entities survive the
 * final `wp_kses_post` and render as the original glyph in the browser.
 *
 * See `docs/adr-0001-runtime-var-trust-levels.md` for the T1/T2 contract. T1
 * (markup-authoring) sources — template body, `#set`, globals, `spintax_render`
 * arguments, shortcode attributes — must NOT be passed through this.
 */
final class SpintaxShield {

	/**
	 * Structural characters → HTML numeric entities.
	 *
	 * @var array<string, string>
	 */
	private const MAP = array(
		'{' => '&#123;',
		'}' => '&#125;',
		'[' => '&#91;',
		']' => '&#93;',
		'%' => '&#37;',
		'#' => '&#35;',
	);

	/**
	 * Neutralise spintax structural characters in a single value.
	 *
	 * @param string $value Data-derived value.
	 * @return string Value with structural characters entity-encoded.
	 */
	public static function neutralize( string $value ): string {
		return strtr( $value, self::MAP );
	}

	/**
	 * Neutralise every value in a variable map (keys are left untouched).
	 *
	 * @param array<string, string> $map Variable name => data-derived value.
	 * @return array<string, string>
	 */
	public static function neutralize_map( array $map ): array {
		return array_map( array( self::class, 'neutralize' ), $map );
	}
}
