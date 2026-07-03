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
