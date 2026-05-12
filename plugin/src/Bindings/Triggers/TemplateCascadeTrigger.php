<?php
/**
 * Template-edit cascade for bindings.
 *
 * @package Spintax
 */

namespace Spintax\Bindings\Triggers;

defined( 'ABSPATH' ) || exit;

use Spintax\Bindings\BindingsRepo;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Support\OptionKeys;
use WP_Post;

/**
 * Bumps the per-binding cache-version stamp whenever a Spintax template
 * is edited (spec §4.7a).
 *
 * Bumping the stamp invalidates the per-post-per-binding render cache
 * that Phase 4 will introduce (§4.12); without the bump, a cron-scheduled
 * regenerate could replay a cached stale render and silently no-op
 * after a template edit.
 *
 * **This trigger does NOT rewrite any target fields.** Per spec §4.7a:
 * editing a template only updates internal cache hygiene; visibility
 * propagation to the front-end requires an explicit Bulk Apply
 * (lands in Phase 4) or the next individual post save.
 */
class TemplateCascadeTrigger {

	/**
	 * Bindings repository.
	 *
	 * @var BindingsRepo
	 */
	private BindingsRepo $repo;

	/**
	 * Constructor.
	 *
	 * @param BindingsRepo|null $repo Bindings repository.
	 */
	public function __construct( ?BindingsRepo $repo = null ) {
		$this->repo = $repo ?? new BindingsRepo();
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'save_post_' . TemplatePostType::POST_TYPE, array( $this, 'on_template_save' ), 20, 3 );
	}

	/**
	 * Bump the cache-version option for every binding referencing this template.
	 *
	 * @param int          $template_id Template post id.
	 * @param WP_Post|null $post        Template post object.
	 * @param bool         $update      Whether this is an update.
	 */
	public function on_template_save( int $template_id, $post, bool $update ): void {
		unset( $update );

		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( TemplatePostType::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( 'revision' === $post->post_type ) {
			return;
		}

		$bindings = $this->repo->find_by_template_id( $template_id );
		if ( empty( $bindings ) ) {
			return;
		}

		foreach ( $bindings as $binding ) {
			$id = (string) ( $binding['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$this->bump_cache_version( $id );
		}
	}

	/**
	 * Increment the per-binding cache version option.
	 *
	 * @param string $binding_id Binding id.
	 */
	private function bump_cache_version( string $binding_id ): void {
		$key     = OptionKeys::OPTION_BINDING_CACHE_VERSION_PREFIX . $binding_id;
		$current = (int) get_option( $key, 0 );
		update_option( $key, $current + 1, false );
	}
}
