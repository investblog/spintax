<?php
/**
 * AJAX endpoints for the Bindings admin page.
 *
 * Phase 2 ships only `test_binding` (dogfood / dry-run path). Field
 * discovery (`ajax_acf_fields`, `ajax_meta_keys`, `ajax_template_list`)
 * lands in Phase 3 alongside the form's JS field picker.
 *
 * @package Spintax
 */

namespace Spintax\Admin;

defined( 'ABSPATH' ) || exit;

use Spintax\Bindings\BindingApplier;
use Spintax\Bindings\BindingsRepo;
use Spintax\Support\Capabilities;
use Spintax\Support\Validators;

/**
 * Wires AJAX actions for the Bindings admin surface.
 */
class BindingsAjax {

	/**
	 * Bindings repository.
	 *
	 * @var BindingsRepo
	 */
	private BindingsRepo $repo;

	/**
	 * Decision-tree applier.
	 *
	 * @var BindingApplier
	 */
	private BindingApplier $applier;

	/**
	 * Constructor.
	 *
	 * @param BindingsRepo|null   $repo    Bindings repository.
	 * @param BindingApplier|null $applier Decision-tree applier.
	 */
	public function __construct( ?BindingsRepo $repo = null, ?BindingApplier $applier = null ) {
		$this->repo    = $repo ?? new BindingsRepo();
		$this->applier = $applier ?? new BindingApplier();
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'wp_ajax_spintax_test_binding', array( $this, 'test_binding' ) );
	}

	/**
	 * Dry-run a binding against a specific post.
	 *
	 * Returns the planned action without side effects. Same logic
	 * path as `BindingApplier::apply()` but returns the plan instead
	 * of executing. No JS UI consumes this in Phase 2 — it is a
	 * dogfooding endpoint usable from curl or browser devtools.
	 */
	public function test_binding(): void {
		check_ajax_referer( 'spintax_admin', 'nonce' );

		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'spintax' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$binding_id = isset( $_POST['binding_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['binding_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		if ( ! Validators::is_valid_binding_id( $binding_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid binding id.', 'spintax' ) ), 400 );
		}
		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Provide a valid post id.', 'spintax' ) ), 400 );
		}

		$binding = $this->repo->find( $binding_id );
		if ( null === $binding ) {
			wp_send_json_error( array( 'message' => __( 'Binding not found.', 'spintax' ) ), 404 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found.', 'spintax' ) ), 404 );
		}

		$plan = $this->applier->plan( $binding, $post_id );

		wp_send_json_success(
			array(
				'binding_id'        => $binding_id,
				'post_id'           => $post_id,
				'post_title'        => $post->post_title,
				'post_type'         => $post->post_type,
				'result'            => $plan['result'],
				'would_write'       => $plan['would_write'],
				'rendered_preview'  => $plan['rendered'],
				'rendered_hash'     => $plan['rendered_hash'],
				'current_target'    => $plan['current'],
				'rendered_is_empty' => '' === $plan['rendered'],
			)
		);
	}
}
