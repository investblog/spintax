<?php

namespace Spintax\Tests\Core\Variables;

use Spintax\Core\Variables\WooCommerceProductContextSource;

class WooCommerceProductContextSourceTest extends \WP_UnitTestCase {

	/**
	 * Build a fake WC_Product-like double.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return object
	 */
	private function fake_product( array $overrides = array() ): object {
		$data = array_merge(
			array(
				'id'                => 5,
				'name'              => 'Blue Shirt',
				'slug'              => 'blue-shirt',
				'sku'               => 'SKU-1',
				'type'              => 'simple',
				'price'             => '19.99',
				'regular_price'     => '24.99',
				'sale_price'        => '',
				'stock_status'      => 'instock',
				'short_description' => '<p>Nice &amp; soft</p>',
				'attributes'        => array(),
				'attribute_values'  => array(),
				'status'            => 'publish',
			),
			$overrides
		);

		return new class( $data ) {
			/** @var array<string, mixed> */
			private array $data;

			public function __construct( array $data ) {
				$this->data = $data;
			}

			public function get_id() {
				return $this->data['id'];
			}

			public function get_name() {
				return $this->data['name'];
			}

			public function get_slug() {
				return $this->data['slug'];
			}

			public function get_sku() {
				return $this->data['sku'];
			}

			public function get_type() {
				return $this->data['type'];
			}

			public function get_price() {
				return $this->data['price'];
			}

			public function get_regular_price() {
				return $this->data['regular_price'];
			}

			public function get_sale_price() {
				return $this->data['sale_price'];
			}

			public function get_stock_status() {
				return $this->data['stock_status'];
			}

			public function get_short_description() {
				return $this->data['short_description'];
			}

			public function get_attributes() {
				return $this->data['attributes'];
			}

			public function get_attribute( $name ) {
				return $this->data['attribute_values'][ $name ] ?? '';
			}

			public function get_status() {
				return $this->data['status'];
			}
		};
	}

	public function test_build_returns_empty_when_woocommerce_inactive(): void {
		$source = new WooCommerceProductContextSource(
			static fn(): bool => false,
			static fn( int $id ) => null
		);

		$this->assertSame( array(), $source->build( 5 ) );
	}

	public function test_build_returns_empty_when_product_missing(): void {
		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			static fn( int $id ) => null
		);

		$this->assertSame( array(), $source->build( 5 ) );
	}

	public function test_build_returns_empty_off_product_context(): void {
		// No queried product object in a plain test request → auto-detect yields 0.
		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			fn( int $id ) => $this->fake_product()
		);

		$this->assertSame( array(), $source->build( 0 ) );
	}

	public function test_explicit_product_id_blocks_non_published_product(): void {
		// An explicit product_id must not expose a draft/private product's
		// context (it bypasses the main-query gate the auto path relies on).
		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			fn( int $id ) => $this->fake_product( array( 'id' => $id, 'status' => 'draft' ) )
		);

		$this->assertSame( array(), $source->build( 7 ) );
	}

	public function test_the_binding_path_has_no_publish_gate_and_that_is_the_point(): void {
		// The gate on `build()` protects the front end, where `[spintax product_id="123"]` would let
		// an author read a draft product they were never served. A binding is the opposite case: it
		// writes the product's OWN data into the product's OWN description, so nothing crosses a
		// boundary — and pre-generation exists precisely so the copy is ready BEFORE publication. A
		// gate here would refuse to seed exactly the products that need seeding.
		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			fn( int $id ) => $this->fake_product( array( 'id' => $id, 'status' => 'draft' ) )
		);

		$map = $source->build_for_binding( 7 );

		$this->assertNotSame( array(), $map, 'a draft product must still expose its context to its own binding' );
		$this->assertSame( '7', $map['product_id'] );
	}

	public function test_the_binding_path_is_empty_without_woocommerce(): void {
		$source = new WooCommerceProductContextSource(
			static fn(): bool => false,
			fn( int $id ) => $this->fake_product( array( 'id' => $id ) )
		);

		$this->assertSame( array(), $source->build_for_binding( 7 ) );
	}

	public function test_the_binding_path_is_empty_when_the_post_is_not_a_product(): void {
		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			static fn( int $id ) => false
		);

		$this->assertSame( array(), $source->build_for_binding( 7 ) );
	}

	public function test_explicit_product_id_allows_published_product(): void {
		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			fn( int $id ) => $this->fake_product( array( 'id' => $id, 'status' => 'publish' ) )
		);

		$vars = $source->build( 7 );
		$this->assertSame( '7', $vars['product_id'] );
	}

	public function test_explicit_gate_not_bypassed_by_auto_detect_memo(): void {
		global $wp_query;

		// Simulate an admin preview whose queried object is a draft product.
		$draft_id = self::factory()->post->create(
			array( 'post_type' => 'product', 'post_status' => 'draft' )
		);
		$wp_query->queried_object    = get_post( $draft_id );
		$wp_query->queried_object_id = $draft_id;

		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			fn( int $id ) => $this->fake_product(
				array( 'id' => $id, 'status' => 'draft', 'name' => 'Secret Draft' )
			)
		);

		// Auto-detect on the draft caches its map (ungated path)...
		$auto = $source->build( 0 );
		$this->assertSame( 'Secret Draft', $auto['product_name'] );

		// ...and an explicit lookup for the SAME id must still be gated,
		// not served the auto-cached entry.
		$this->assertSame( array(), $source->build( $draft_id ) );
	}

	public function test_product_values_are_shielded_from_spintax_interpretation(): void {
		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			fn( int $id ) => $this->fake_product(
				array( 'id' => $id, 'name' => 'Deal {50|60}% [x] %n%' )
			)
		);

		$vars = $source->build( 5 );

		// Structural characters are entity-encoded → rendered literally, never
		// parsed as enumeration / permutation / variable / nested shortcode.
		$this->assertSame( 'Deal &#123;50|60&#125;&#37; &#91;x&#93; &#37;n&#37;', $vars['product_name'] );
		$this->assertStringNotContainsString( '{', $vars['product_name'] );
		$this->assertStringNotContainsString( '[', $vars['product_name'] );
	}

	public function test_hash_is_shielded_to_block_include_injection(): void {
		// A product value with an embedded newline + `#include` must not reach
		// line-start as a live directive after substitution.
		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			fn( int $id ) => $this->fake_product(
				array( 'id' => $id, 'name' => "Buy now\n#include \"evil-slug\"" )
			)
		);

		$vars = $source->build( 5 );

		$this->assertStringNotContainsString( '#include', $vars['product_name'] );
		$this->assertStringContainsString( '&#35;include', $vars['product_name'] );
	}

	public function test_build_maps_core_product_fields(): void {
		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			fn( int $id ) => $this->fake_product( array( 'id' => $id ) )
		);

		$vars = $source->build( 5 );

		$this->assertSame( '5', $vars['product_id'] );
		$this->assertSame( 'Blue Shirt', $vars['product_name'] );
		$this->assertSame( 'blue-shirt', $vars['product_slug'] );
		$this->assertSame( 'SKU-1', $vars['product_sku'] );
		$this->assertSame( 'simple', $vars['product_type'] );
		$this->assertSame( 'instock', $vars['product_stock_status'] );
		// Short description reduced to entity-decoded plain text.
		$this->assertSame( 'Nice & soft', $vars['product_short_description'] );
	}

	public function test_price_fields_are_never_exposed(): void {
		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			fn( int $id ) => $this->fake_product( array( 'id' => $id ) )
		);

		$vars = $source->build( 5 );

		// Volatile commerce data is intentionally out of scope.
		$this->assertArrayNotHasKey( 'product_price', $vars );
		$this->assertArrayNotHasKey( 'product_regular_price', $vars );
		$this->assertArrayNotHasKey( 'product_sale_price', $vars );
	}

	public function test_product_id_always_present_in_non_empty_map(): void {
		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			fn( int $id ) => $this->fake_product( array( 'id' => $id ) )
		);

		$vars = $source->build( 42 );

		$this->assertArrayHasKey( 'product_id', $vars );
		$this->assertSame( '42', $vars['product_id'] );
	}

	public function test_missing_optional_fields_are_empty_string(): void {
		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			fn( int $id ) => $this->fake_product( array( 'sku' => '' ) )
		);

		$vars = $source->build( 5 );

		$this->assertSame( '', $vars['product_sku'] );
	}

	public function test_attribute_keys_are_parser_safe_and_pa_stripped(): void {
		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			fn( int $id ) => $this->fake_product(
				array(
					'attributes'       => array(
						'pa_color' => null,
						'size-eu'  => null,
					),
					'attribute_values' => array(
						'pa_color' => 'Blue',
						'size-eu'  => '42',
					),
				)
			)
		);

		$vars = $source->build( 5 );

		// pa_ stripped for the ergonomic alias; dash normalised to underscore.
		$this->assertSame( 'Blue', $vars['product_attribute_color'] );
		$this->assertSame( '42', $vars['product_attribute_size_eu'] );

		foreach ( array_keys( $vars ) as $key ) {
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_]+$/', $key );
		}
	}

	public function test_alias_collision_keeps_fully_qualified_key(): void {
		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			fn( int $id ) => $this->fake_product(
				array(
					'attributes'       => array(
						'color'    => null,
						'pa_color' => null,
					),
					'attribute_values' => array(
						'color'    => 'Red',
						'pa_color' => 'Blue',
					),
				)
			)
		);

		$vars = $source->build( 5 );

		// First wins the alias; the colliding one keeps its fully-qualified key.
		$this->assertSame( 'Red', $vars['product_attribute_color'] );
		$this->assertSame( 'Blue', $vars['product_attribute_pa_color'] );
	}

	public function test_map_is_memoised_per_product_for_the_request(): void {
		$calls  = 0;
		$source = new WooCommerceProductContextSource(
			static fn(): bool => true,
			function ( int $id ) use ( &$calls ) {
				++$calls;
				return $this->fake_product( array( 'id' => $id ) );
			}
		);

		$source->build( 5 );
		$source->build( 5 );

		$this->assertSame( 1, $calls );
	}
}
