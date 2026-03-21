<?php
/**
 * [spintax] shortcode handler.
 *
 * @package Spintax
 */

namespace Spintax\Core\Shortcode;

use Spintax\Core\Render\Renderer;

/**
 * Registers and handles the [spintax] shortcode for use in posts/pages.
 */
class ShortcodeController {

	private Renderer $renderer;

	public function __construct( ?Renderer $renderer = null ) {
		$this->renderer = $renderer ?? new Renderer();
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_shortcode( 'spintax', array( $this, 'handle' ) );
	}

	/**
	 * Handle the [spintax] shortcode.
	 *
	 * Supported forms:
	 *   [spintax id="123"]
	 *   [spintax slug="my-template"]
	 *   [spintax id="123" city="Moscow" name="John"]
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string|null  $content Enclosed content (unused).
	 * @return string Rendered template output.
	 */
	public function handle( $atts, ?string $content = null ): string {
		$raw_atts = is_array( $atts ) ? $atts : array();

		$defaults = array(
			'id'   => '',
			'slug' => '',
		);
		$parsed = array_merge( $defaults, $raw_atts );

		$id_or_slug = '' !== $parsed['id'] ? $parsed['id'] : $parsed['slug'];

		if ( '' === $id_or_slug ) {
			return '';
		}

		// Collect runtime variables: all attributes except id and slug.
		// WordPress lowercases all shortcode attribute names.
		$runtime_vars = $raw_atts;
		unset( $runtime_vars['id'], $runtime_vars['slug'] );

		return $this->renderer->render( $id_or_slug, $runtime_vars );
	}
}
