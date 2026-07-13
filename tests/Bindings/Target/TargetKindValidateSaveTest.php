<?php
/**
 * `TargetKind::validate_save()` — the save-time half of the target contract.
 *
 * Added in Phase 3 (WooCommerce write targets), which is what finally forced it: a WC product field
 * has save-time rules of its own, and expressing them as another `if ( 'woocommerce' === $kind )`
 * branch in the admin is exactly the shape `TargetRegistry` exists to delete.
 *
 * Phase 2 deliberately deferred this method because folding it in risked reordering the first error
 * an editor sees. The precedence is locked in BindingsPageTest; these tests cover the per-kind
 * behaviour in isolation.
 *
 * @package Spintax
 */

namespace Spintax\Tests\Bindings\Target;

use Spintax\Bindings\Target\AcfFieldTarget;
use Spintax\Bindings\Target\PostMetaTarget;
use Spintax\Bindings\Target\TargetRegistry;

class TargetKindValidateSaveTest extends \WP_UnitTestCase {

	/**
	 * Build a binding payload shaped like the admin form submits it.
	 *
	 * @param string $kind      Target kind.
	 * @param string $key       Target key.
	 * @param string $field_key ACF field key.
	 * @param string $post_type Bound post type.
	 * @return array<string, mixed>
	 */
	private function binding( string $kind, string $key, string $field_key = '', string $post_type = 'post' ): array {
		return array(
			'post_type' => $post_type,
			'target'    => array(
				'kind'      => $kind,
				'key'       => $key,
				'field_key' => $field_key,
			),
		);
	}

	public function test_every_registered_kind_implements_validate_save(): void {
		foreach ( TargetRegistry::all() as $id => $target ) {
			$this->assertTrue(
				method_exists( $target, 'validate_save' ),
				"target kind {$id} must implement validate_save()"
			);
		}
	}

	public function test_post_meta_accepts_any_key_that_got_past_the_reserved_guard(): void {
		// Its wp_posts-column guard runs earlier, in the kind-agnostic tier — see the docblock on
		// PostMetaTarget::validate_save() for why moving it here would be a regression.
		$target = new PostMetaTarget();

		$this->assertNull( $target->validate_save( $this->binding( 'post_meta', 'my_field' ) ) );
		$this->assertNull( $target->validate_save( $this->binding( 'post_meta', 'anything_at_all' ) ) );
	}

	public function test_acf_target_requires_a_field_key(): void {
		$target = new AcfFieldTarget();

		$error = $target->validate_save( $this->binding( 'acf_field', 'hero_subtitle', '' ) );

		$this->assertIsString( $error );
		$this->assertStringContainsString( 'ACF field key is required', $error );
	}

	public function test_acf_target_with_a_field_key_is_accepted_while_acf_is_inactive(): void {
		// The suite runs without ACF, which is the point: a binding must survive an ACF deactivation
		// cycle, so the save layer accepts it and the applier re-checks at write time (and returns
		// SKIP_ACF_NOT_LOADED). Refusing the save here would make the configuration unrecoverable.
		$this->assertFalse( function_exists( 'acf_get_field' ), 'this test asserts behaviour with ACF absent' );

		$target = new AcfFieldTarget();

		$this->assertNull( $target->validate_save( $this->binding( 'acf_field', 'hero_subtitle', 'field_abc123' ) ) );
	}
}
