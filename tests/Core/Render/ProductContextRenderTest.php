<?php

namespace Spintax\Tests\Core\Render;

use Spintax\Core\Engine\Parser;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Core\Render\Renderer;
use Spintax\Core\Shortcode\ShortcodeController;
use Spintax\Core\Variables\WooCommerceProductContextSource;
use Spintax\Support\OptionKeys;

/**
 * Integration: WooCommerce product context flowing through the render path
 * and into the cache key (product A must never serve product B's output).
 */
class ProductContextRenderTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( OptionKeys::SETTINGS );
		delete_option( OptionKeys::GLOBAL_VARIABLES );
		wp_cache_flush();
	}

	/**
	 * Create a published template and return its ID.
	 */
	private function make_template( string $title, string $content ): int {
		return wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'publish',
			)
		);
	}

	/**
	 * Parser whose RNG returns $min but counts invocations (to prove cache reuse).
	 *
	 * @param int $calls Receives the RNG invocation count.
	 */
	private function counting_parser( int &$calls ): Parser {
		$calls = 0;
		return new Parser(
			static function ( int $min, int $max ) use ( &$calls ): int {
				++$calls;
				return $min;
			}
		);
	}

	/**
	 * A minimal WC_Product-like double named "Product <id>".
	 *
	 * @param int $id Product id.
	 */
	private function fake_product( int $id ): object {
		return new class( $id ) {
			private int $id;

			public function __construct( int $id ) {
				$this->id = $id;
			}

			public function get_id() {
				return $this->id; }
			public function get_name() {
				return 'Product ' . $this->id; }
			public function get_slug() {
				return 'product-' . $this->id; }
			public function get_sku() {
				return ''; }
			public function get_type() {
				return 'simple'; }
			public function get_price() {
				return ''; }
			public function get_regular_price() {
				return ''; }
			public function get_sale_price() {
				return ''; }
			public function get_stock_status() {
				return 'instock'; }
			public function get_short_description() {
				return ''; }
			public function get_attributes() {
				return array(); }
			public function get_attribute( $name ) {
				return ''; }
			public function get_status() {
				return 'publish'; }
		};
	}

	/**
	 * Source that reports WooCommerce active and resolves a per-id fake product.
	 */
	private function active_source(): WooCommerceProductContextSource {
		return new WooCommerceProductContextSource(
			static fn(): bool => true,
			fn( int $id ) => $this->fake_product( $id )
		);
	}

	/**
	 * Register a [spintax] controller wired with the given renderer + source.
	 */
	private function register_controller( Renderer $renderer, WooCommerceProductContextSource $source ): void {
		( new ShortcodeController( $renderer, $source ) )->init();
	}

	public function test_two_products_render_distinct_output(): void {
		$this->make_template( 'item', 'Item %product_name%' );
		$this->register_controller(
			new Renderer( new Parser( static fn( int $min, int $max ): int => $min ) ),
			$this->active_source()
		);

		$one = do_shortcode( '[spintax slug="item" product_id="1"]' );
		$two = do_shortcode( '[spintax slug="item" product_id="2"]' );

		$this->assertStringContainsString( 'Product 1', $one );
		$this->assertStringContainsString( 'Product 2', $two );
		$this->assertNotSame( $one, $two );
	}

	public function test_distinct_products_get_distinct_cache_entries(): void {
		$this->make_template( 'rnd', "#set %g% = {a|b}\n%g% for %product_name%" );
		$calls = 0;
		$this->register_controller( new Renderer( $this->counting_parser( $calls ) ), $this->active_source() );

		$first  = do_shortcode( '[spintax slug="rnd" product_id="1"]' );
		$cached = do_shortcode( '[spintax slug="rnd" product_id="1"]' );
		$other  = do_shortcode( '[spintax slug="rnd" product_id="2"]' );

		// Product 1's second render is served from cache (RNG not re-run).
		$this->assertSame( $first, $cached );
		// Product 2 has a different runtime map → different cache key → a miss.
		$this->assertNotSame( $first, $other );
		$this->assertStringContainsString( 'Product 1', $first );
		$this->assertStringContainsString( 'Product 2', $other );
		// One RNG call for product 1, one more for product 2 (cache hit spends none).
		$this->assertSame( 2, $calls );
	}

	public function test_non_product_context_is_unchanged(): void {
		$this->make_template( 'greet', 'Hello %who%!' );
		// WooCommerce inactive → source contributes nothing.
		$inactive = new WooCommerceProductContextSource( static fn(): bool => false );
		$this->register_controller(
			new Renderer( new Parser( static fn( int $min, int $max ): int => $min ) ),
			$inactive
		);

		$result = do_shortcode( '[spintax slug="greet" who="World"]' );

		$this->assertSame( 'Hello World!', $result );
		$this->assertStringNotContainsString( 'product_', $result );
	}

	public function test_explicit_var_overrides_auto_product_var(): void {
		$this->make_template( 'name', 'Name: %product_name%' );
		$this->register_controller(
			new Renderer( new Parser( static fn( int $min, int $max ): int => $min ) ),
			$this->active_source()
		);

		$result = do_shortcode( '[spintax slug="name" product_id="1" product_name="Override"]' );

		$this->assertStringContainsString( 'Override', $result );
		$this->assertStringNotContainsString( 'Product 1', $result );
	}
}
