<?php
/**
 * Runtime-variable assembly shared across front-end render entry points.
 *
 * @package Spintax
 */

namespace Spintax\Core\Variables;

defined( 'ABSPATH' ) || exit;

/**
 * Merges auto-detected WooCommerce product context beneath a caller's explicit
 * runtime variables.
 *
 * A single merge path keeps the entry points (`ShortcodeController::handle`,
 * `spintax_render()`) from drifting on precedence or on how the explicit
 * `product_id` override is read.
 *
 * Precedence: explicit caller vars always win over auto-detected product vars.
 */
final class RuntimeContextBuilder {

	/**
	 * Layer auto-detected product context under explicit runtime variables.
	 *
	 * When the caller supplies a `product_id`, that value both selects the
	 * product context and is preserved as the `%product_id%` variable.
	 *
	 * @param WooCommerceProductContextSource $product_context Product context source.
	 * @param array<string, string>           $explicit        Explicit caller variables.
	 * @return array<string, string> Merged runtime variables.
	 */
	public static function merge( WooCommerceProductContextSource $product_context, array $explicit ): array {
		$product_id = isset( $explicit['product_id'] ) ? (int) $explicit['product_id'] : 0;

		$auto = $product_context->build( $product_id );
		if ( empty( $auto ) ) {
			return $explicit;
		}

		return array_merge( $auto, $explicit );
	}
}
