<?php
/**
 * [spintax] shortcode handler.
 *
 * @package Spintax
 */

namespace Spintax\Core\Shortcode;

defined( 'ABSPATH' ) || exit;

use Spintax\Core\Render\Renderer;
use Spintax\Core\Variables\RuntimeContextBuilder;
use Spintax\Core\Variables\WooCommerceProductContextSource;

/**
 * Registers and handles the [spintax] shortcode for use in posts/pages.
 */
class ShortcodeController {

	/**
	 * Template renderer for processing spintax output.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * WooCommerce product-context variable source.
	 *
	 * @var WooCommerceProductContextSource
	 */
	private WooCommerceProductContextSource $product_context;

	/**
	 * Constructor.
	 *
	 * @param Renderer|null                        $renderer        Optional renderer instance.
	 * @param WooCommerceProductContextSource|null $product_context Optional product-context source.
	 */
	public function __construct( ?Renderer $renderer = null, ?WooCommerceProductContextSource $product_context = null ) {
		$this->renderer        = $renderer ?? new Renderer();
		$this->product_context = $product_context ?? new WooCommerceProductContextSource();
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
	 * @param string|null  $content Enclosed content (unused, required by WP shortcode API).
	 * @return string Rendered template output.
	 */
	public function handle( $atts, ?string $content = null ): string {
		unset( $content ); // Required by WP shortcode API but not used.
		$raw_atts = is_array( $atts ) ? $atts : array();

		$defaults = array(
			'id'   => '',
			'slug' => '',
		);
		$parsed   = array_merge( $defaults, $raw_atts );

		$id_or_slug = '' !== $parsed['id'] ? $parsed['id'] : $parsed['slug'];

		if ( '' === $id_or_slug ) {
			return '';
		}

		// Collect runtime variables: all attributes except id and slug.
		// WordPress lowercases all shortcode attribute names.
		$runtime_vars = $raw_atts;
		unset( $runtime_vars['id'], $runtime_vars['slug'] );

		// Layer WooCommerce product context beneath explicit attributes on
		// product pages (no-op off-product or when WooCommerce is inactive).
		$runtime_vars = RuntimeContextBuilder::merge( $this->product_context, $runtime_vars );

		return $this->renderer->render( $id_or_slug, $runtime_vars );
	}
}
