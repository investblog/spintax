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
	 * @param int|string           $id_or_slug Template post ID or slug.
	 * @param array<string, string> $vars       Runtime variables.
	 * @return string Rendered HTML.
	 */
	function spintax_render( $id_or_slug, array $vars = array() ): string {
		static $renderer = null;

		if ( null === $renderer ) {
			$renderer = new Spintax\Core\Render\Renderer();
		}

		return $renderer->render( $id_or_slug, $vars );
	}
}
