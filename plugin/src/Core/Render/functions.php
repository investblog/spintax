<?php
/**
 * Global helper functions for theme developers.
 *
 * @package Spintax
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'spintax_render' ) ) {
	/**
	 * Render a spintax template.
	 *
	 * Usage:
	 *   echo spintax_render( 'my-template' );
	 *   echo spintax_render( 123, [ 'city' => 'Moscow' ] );
	 *
	 * @param int|string            $id_or_slug Template post ID or slug.
	 * @param array<string, string> $vars       Runtime variables.
	 * @return string Rendered HTML.
	 */
	function spintax_render( $id_or_slug, array $vars = array() ): string {
		static $renderer        = null;
		static $product_context = null;

		if ( null === $renderer ) {
			$renderer        = new Spintax\Core\Render\Renderer();
			$product_context = new Spintax\Core\Variables\WooCommerceProductContextSource();
		}

		// Layer WooCommerce product context beneath the caller's variables on
		// product pages (no-op off-product or when WooCommerce is inactive).
		$vars = Spintax\Core\Variables\RuntimeContextBuilder::merge( $product_context, $vars );

		return $renderer->render( $id_or_slug, $vars );
	}
}
