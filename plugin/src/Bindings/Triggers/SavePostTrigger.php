<?php
/**
 * Save-post trigger for bindings.
 *
 * @package Spintax
 */

namespace Spintax\Bindings\Triggers;

defined( 'ABSPATH' ) || exit;

use Spintax\Bindings\BindingApplier;
use Spintax\Bindings\BindingsRepo;
use Spintax\Bindings\ReentrancyGuard;
use Spintax\Core\PostType\TemplatePostType;
use WP_Post;

/**
 * Hooks `save_post` at priority 20 — runs AFTER ACF's default
 * `save_post` handler (priority 10) so `expose_acf_siblings` reads
 * see freshly persisted ACF values without a second hook.
 *
 * Skip conditions (spec §4.7):
 *  - autosave (`DOING_AUTOSAVE`)
 *  - WordPress bulk edit (`$_REQUEST['bulk_edit']`)
 *  - REST batch import of auto-drafts
 *  - the spintax_template CPT itself (that path is handled by
 *    `TemplateCascadeTrigger`)
 */
class SavePostTrigger {

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
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 3 );
	}

	/**
	 * Fire all bindings that match this post.
	 *
	 * @param int          $post_id Post id.
	 * @param WP_Post|null $post    Post object.
	 * @param bool         $update  Whether this is an update.
	 */
	public function on_save_post( int $post_id, $post, bool $update ): void {
		unset( $update );

		if ( $this->should_skip( $post_id, $post ) ) {
			return;
		}

		$bindings = $this->repo->find_for_post_type( $post->post_type );
		if ( empty( $bindings ) ) {
			return;
		}

		foreach ( $bindings as $binding ) {
			if ( ! $this->binding_matches_status( $binding, $post ) ) {
				continue;
			}
			if ( empty( $binding['triggers']['save_post'] ) ) {
				continue;
			}
			$this->applier->apply( $binding, $post_id );
		}
	}

	/**
	 * Filter out save events that bindings should not respond to.
	 *
	 * @param int          $post_id Post id.
	 * @param WP_Post|null $post    Post object.
	 */
	private function should_skip( int $post_id, $post ): bool {
		if ( ! $post instanceof WP_Post ) {
			return true;
		}
		if ( $post_id <= 0 ) {
			return true;
		}

		// A target is mid-write on this post, and its write goes through a host API that re-enters
		// the save cycle — `$product->save()` in WooCommerce's case, which fires the very hook we
		// are standing in. Without this, a regenerate-on-save product binding would apply, save,
		// re-enter, apply, save, forever. The guard is set and released by the target itself, so
		// this stays true only for the duration of one write.
		if ( ReentrancyGuard::is_active( $post_id ) ) {
			return true;
		}

		// Skip the Spintax template CPT — that path is handled by
		// `TemplateCascadeTrigger`, not by binding application.
		if ( TemplatePostType::POST_TYPE === $post->post_type ) {
			return true;
		}

		// Skip revisions — bindings target the parent post.
		if ( 'revision' === $post->post_type ) {
			return true;
		}

		// Skip autosaves.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return true;
		}

		// Skip bulk edit (WordPress quick-bulk-edit submits via $_REQUEST['bulk_edit']).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter against a request marker; no state change here.
		if ( isset( $_REQUEST['bulk_edit'] ) ) {
			return true;
		}

		// Skip REST batch imports of auto-drafts (importers / drafts pipelines).
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && 'auto-draft' === $post->post_status ) {
			return true;
		}

		// Skip trashed / auto-draft saves regardless of context — nothing to render against.
		if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Honor the binding's status filter.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param WP_Post              $post    Post object.
	 */
	private function binding_matches_status( array $binding, WP_Post $post ): bool {
		$filter = (string) ( $binding['status'] ?? 'any' );
		if ( 'publish' === $filter ) {
			return 'publish' === $post->post_status;
		}
		return true; // 'any'
	}
}
