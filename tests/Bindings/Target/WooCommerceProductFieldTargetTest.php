<?php
/**
 * The WooCommerce product-field target — the first binding target that writes into a catalogue.
 *
 * WooCommerce is not installed in the suite, which is precisely why the target takes injectable
 * seams (the same shape `WooCommerceProductContextSource` uses): a class whose every interesting
 * path is unreachable in tests is a class whose every interesting path is unverified. The live
 * smoke matrix in docs/release-checklist.md covers real WooCommerce; these cover the logic.
 *
 * @package Spintax
 */

namespace Spintax\Tests\Bindings\Target;

use Spintax\Bindings\Plan\PlanCode;
use Spintax\Bindings\ReentrancyGuard;
use Spintax\Bindings\Target\WooCommerceProductFieldTarget;

class WooCommerceProductFieldTargetTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		ReentrancyGuard::reset();
		parent::tear_down();
	}

	/**
	 * A stand-in for WC_Product: records what was set and whether the re-entrancy guard was up at
	 * the moment `save()` ran — which is the only moment it matters.
	 */
	private function fake_product(): object {
		return new class() {
			/** @var string */
			public string $description = 'old description';

			/** @var string */
			public string $short_description = 'old short';

			/** @var int */
			public int $saves = 0;

			/** @var bool|null */
			public ?bool $guard_up_during_save = null;

			public function get_description(): string {
				return $this->description;
			}

			public function get_short_description(): string {
				return $this->short_description;
			}

			public function set_description( string $value ): void {
				$this->description = $value;
			}

			public function set_short_description( string $value ): void {
				$this->short_description = $value;
			}

			public function save(): void {
				++$this->saves;
				$this->guard_up_during_save = ReentrancyGuard::is_active( 42 );
			}
		};
	}

	/**
	 * @param object|null $product Product the resolver should return (null = not a product).
	 * @param bool        $wc_on   Whether WooCommerce is "loaded".
	 */
	private function target( ?object $product, bool $wc_on = true ): WooCommerceProductFieldTarget {
		return new WooCommerceProductFieldTarget(
			static fn(): bool => $wc_on,
			static fn( int $post_id ) => $product ?? false
		);
	}

	/**
	 * @param string $key       Target key.
	 * @param string $post_type Bound post type.
	 * @return array<string, mixed>
	 */
	private function binding( string $key, string $post_type = 'product' ): array {
		return array(
			'post_type' => $post_type,
			'target'    => array(
				'kind'      => 'woocommerce_product_field',
				'key'       => $key,
				'field_key' => '',
			),
		);
	}

	// ── the whitelist ────────────────────────────────────────────────────────

	public function test_only_two_fields_are_writable(): void {
		// The cap is the feature. Price, SKU and stock are commerce data, and a template that can
		// reach them is a template that can take a shop down.
		$this->assertSame( array( 'description', 'short_description' ), WooCommerceProductFieldTarget::FIELDS );
	}

	public function test_save_rejects_a_field_outside_the_whitelist(): void {
		$target = $this->target( $this->fake_product() );

		$error = $target->validate_save( $this->binding( 'regular_price' ) );

		$this->assertIsString( $error );
		$this->assertStringContainsString( 'Only these product fields', $error );
	}

	public function test_save_rejects_a_non_product_post_type(): void {
		$target = $this->target( $this->fake_product() );

		$error = $target->validate_save( $this->binding( 'description', 'post' ) );

		$this->assertIsString( $error );
		$this->assertStringContainsString( 'Product post type', $error );
	}

	public function test_save_accepts_a_whitelisted_field_on_products(): void {
		$target = $this->target( $this->fake_product() );

		$this->assertNull( $target->validate_save( $this->binding( 'description' ) ) );
		$this->assertNull( $target->validate_save( $this->binding( 'short_description' ) ) );
	}

	public function test_normalize_empties_a_key_outside_the_whitelist(): void {
		// Emptied rather than kept, so the existing empty-key guard rejects it — one rejection path,
		// and no way to persist a key the runtime would then have to refuse.
		$target = $this->target( null );

		$normalized = $target->normalize_target(
			array(
				'kind'      => 'woocommerce_product_field',
				'key'       => 'regular_price',
				'field_key' => 'field_abc',
			)
		);

		$this->assertSame( '', $normalized['key'] );
		$this->assertSame( '', $normalized['field_key'], 'field_key is an ACF concept and must not survive here' );
		$this->assertSame( 'woocommerce_product_field', $normalized['kind'] );
	}

	public function test_normalize_keeps_a_whitelisted_key(): void {
		$target = $this->target( null );

		$normalized = $target->normalize_target(
			array(
				'kind' => 'woocommerce_product_field',
				'key'  => 'short_description',
			)
		);

		$this->assertSame( 'short_description', $normalized['key'] );
	}

	// ── the runtime gate ─────────────────────────────────────────────────────

	public function test_runtime_skips_when_woocommerce_is_inactive(): void {
		// Mirrors the ACF precedent on purpose: the save layer accepts the binding while WooCommerce
		// is off, so a deactivation cycle cannot make the configuration unsavable, and the applier
		// short-circuits instead of writing through some fallback. Generated copy stays put.
		$target = $this->target( $this->fake_product(), false );

		$this->assertSame(
			PlanCode::SKIP_WC_NOT_LOADED,
			$target->validate_runtime( $this->binding( 'description' ), 42 )
		);
	}

	public function test_runtime_skips_a_key_outside_the_whitelist(): void {
		// Reachable only via `wp spintax bindings import`, which bypasses the admin form. The
		// runtime is the last guard before a write.
		$target = $this->target( $this->fake_product() );

		$this->assertSame(
			PlanCode::SKIP_INVALID_WC_FIELD,
			$target->validate_runtime( $this->binding( 'regular_price' ), 42 )
		);
	}

	public function test_runtime_skips_a_binding_pointed_at_a_non_product_type(): void {
		$target = $this->target( $this->fake_product() );

		$this->assertSame(
			PlanCode::SKIP_INVALID_WC_FIELD,
			$target->validate_runtime( $this->binding( 'description', 'post' ), 42 )
		);
	}

	public function test_runtime_skips_a_post_that_is_not_a_product(): void {
		// wc_get_product() returns false for anything it cannot build. Letting this through would be
		// worse than a skip: the Planner would report a write, the write would silently do nothing,
		// and the signature meta would be stamped on a lie.
		$target = $this->target( null );

		$this->assertSame(
			PlanCode::SKIP_INVALID_WC_FIELD,
			$target->validate_runtime( $this->binding( 'description' ), 42 )
		);
	}

	public function test_runtime_clears_a_valid_product_target(): void {
		$target = $this->target( $this->fake_product() );

		$this->assertNull( $target->validate_runtime( $this->binding( 'description' ), 42 ) );
		$this->assertNull( $target->validate_runtime( $this->binding( 'short_description' ), 42 ) );
	}

	// ── read / write ─────────────────────────────────────────────────────────

	public function test_read_returns_the_field_the_binding_points_at(): void {
		$product = $this->fake_product();
		$target  = $this->target( $product );

		$this->assertSame( 'old description', $target->read( $this->binding( 'description' ), 42 ) );
		$this->assertSame( 'old short', $target->read( $this->binding( 'short_description' ), 42 ) );
	}

	public function test_write_goes_through_woocommerce_crud(): void {
		$product = $this->fake_product();
		$target  = $this->target( $product );

		$target->write( $this->binding( 'description' ), 42, 'generated copy' );

		$this->assertSame( 'generated copy', $product->description );
		$this->assertSame( 'old short', $product->short_description, 'the other field must be untouched' );
		$this->assertSame( 1, $product->saves, 'the write is committed through save(), the canonical WC writer' );
	}

	public function test_write_targets_the_short_description_when_asked(): void {
		$product = $this->fake_product();
		$target  = $this->target( $product );

		$target->write( $this->binding( 'short_description' ), 42, 'generated blurb' );

		$this->assertSame( 'generated blurb', $product->short_description );
		$this->assertSame( 'old description', $product->description );
	}

	public function test_write_refuses_a_key_outside_the_whitelist_at_the_sink(): void {
		// Defense in depth: `write()` is only reached after validate_runtime clears the key, but that
		// is an invariant of the applier's gate order. If a future reorder — or a direct caller —
		// ever handed `write()` a bad key, the `else` branch would silently overwrite the description.
		// The sink refuses it itself, so the invariant does not depend on the caller.
		$product = $this->fake_product();
		$target  = $this->target( $product );

		$target->write( $this->binding( 'regular_price' ), 42, '9.99' );

		$this->assertSame( 'old description', $product->description, 'an unknown key must not fall through to set_description' );
		$this->assertSame( 0, $product->saves, 'and nothing is committed' );
	}

	// ── the save loop ────────────────────────────────────────────────────────

	public function test_the_reentrancy_guard_is_up_while_save_runs(): void {
		// `$product->save()` fires save_post, which is the hook SavePostTrigger listens on. The guard
		// has to be up AT THAT MOMENT — not before the call, not after it — or a regenerate-on-save
		// binding re-applies to the product it is mid-write on and loops forever.
		$product = $this->fake_product();
		$target  = $this->target( $product );

		$target->write( $this->binding( 'description' ), 42, 'copy' );

		$this->assertTrue( $product->guard_up_during_save, 'the guard must be active inside save()' );
	}

	public function test_the_guard_is_released_after_the_write(): void {
		$target = $this->target( $this->fake_product() );

		$target->write( $this->binding( 'description' ), 42, 'copy' );

		$this->assertFalse(
			ReentrancyGuard::is_active( 42 ),
			'a guard left up would make the post deaf to save_post for the rest of the request'
		);
	}

	public function test_the_guard_is_released_even_when_the_save_throws(): void {
		$exploding = new class() {
			public function get_description(): string {
				return '';
			}

			public function set_description( string $value ): void {
				unset( $value );
			}

			public function save(): void {
				throw new \RuntimeException( 'database went away' );
			}
		};

		$target = new WooCommerceProductFieldTarget(
			static fn(): bool => true,
			static fn( int $post_id ) => $exploding
		);

		try {
			$target->write( $this->binding( 'description' ), 42, 'copy' );
			$this->fail( 'the exception should propagate — the applier decides what a failure means' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'database went away', $e->getMessage() );
		}

		$this->assertFalse( ReentrancyGuard::is_active( 42 ), 'the finally block must release the guard' );
	}
}
