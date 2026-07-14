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
use Spintax\Core\PostType\TemplatePostType;
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
	 * User-meta key prefix for dismissed admin notices.
	 *
	 * The full key is `<prefix><notice_id>`, where `notice_id` is a
	 * version-scoped slug (e.g. `as-v210`) so a future release that
	 * needs the same notice category surfaced again can bump the id
	 * without manually clearing per-user meta.
	 */
	public const DISMISSED_NOTICE_META_PREFIX = '_spintax_dismissed_notice_';

	/**
	 * Whitelist of notice ids the dismiss endpoint will accept. Keeps
	 * the surface auditable — random ids posted by malicious or buggy
	 * clients can't fill `wp_usermeta` with junk rows.
	 *
	 * @return string[]
	 */
	public static function dismissible_notice_ids(): array {
		return array( 'as-v210' );
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'wp_ajax_spintax_test_binding', array( $this, 'test_binding' ) );
		add_action( 'wp_ajax_spintax_binding_meta_keys', array( $this, 'meta_keys' ) );
		add_action( 'wp_ajax_spintax_binding_acf_fields', array( $this, 'acf_fields' ) );
		add_action( 'wp_ajax_spintax_binding_template_list', array( $this, 'template_list' ) );
		add_action( 'wp_ajax_spintax_dismiss_admin_notice', array( $this, 'dismiss_admin_notice' ) );
	}

	/**
	 * Persist that the current user has dismissed a specific notice.
	 *
	 * Expected POST: `notice_id` (whitelist), `nonce` (spintax_admin).
	 * Stores `1` in `wp_usermeta` under
	 * `_spintax_dismissed_notice_<notice_id>` so `render_*` helpers
	 * can short-circuit on subsequent page loads.
	 */
	public function dismiss_admin_notice(): void {
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by guard() above.
		$notice_id = isset( $_POST['notice_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['notice_id'] ) ) : '';

		if ( ! in_array( $notice_id, self::dismissible_notice_ids(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown notice id.', 'spintax' ) ), 400 );
		}

		update_user_meta(
			get_current_user_id(),
			self::DISMISSED_NOTICE_META_PREFIX . $notice_id,
			1
		);

		wp_send_json_success( array( 'dismissed' => $notice_id ) );
	}

	/**
	 * Check whether the current user has dismissed a given notice id.
	 *
	 * @param string $notice_id Notice slug from `dismissible_notice_ids()`.
	 */
	public static function is_notice_dismissed( string $notice_id ): bool {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		return (bool) get_user_meta( $user_id, self::DISMISSED_NOTICE_META_PREFIX . $notice_id, true );
	}

	/**
	 * Distinct postmeta keys for a given post type, filtered by the
	 * reserved-key guard. Used by the form-side autocomplete on
	 * target.kind = post_meta.
	 */
	public function meta_keys(): void {
		$this->guard();

		$post_type = $this->read_post_type();
		if ( '' === $post_type ) {
			wp_send_json_success( array() );
		}

		$cache_key = 'meta_keys_' . $post_type;
		$cached    = wp_cache_get( $cache_key, 'spintax' );
		if ( is_array( $cached ) ) {
			wp_send_json_success( $cached );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin AJAX lookup wrapped in wp_cache below; full-table scans are bounded by the LIMIT and run only on rare form-time suggestions.
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT pm.meta_key
				FROM %i pm
				INNER JOIN %i p ON p.ID = pm.post_id
				WHERE p.post_type = %s
				ORDER BY pm.meta_key
				LIMIT 200',
				$wpdb->postmeta,
				$wpdb->posts,
				$post_type
			)
		);

		$items = array();
		foreach ( (array) $keys as $key ) {
			$key = (string) $key;
			if ( Validators::is_reserved_meta_key( $key ) ) {
				continue;
			}
			if ( Validators::is_plugin_internal_meta_key( $key ) ) {
				continue;
			}
			$items[] = array(
				'name'  => $key,
				'label' => $key,
			);
		}

		wp_cache_set( $cache_key, $items, 'spintax', 5 * MINUTE_IN_SECONDS );
		wp_send_json_success( $items );
	}

	/**
	 * Top-level ACF text/textarea/wysiwyg fields for a given post type.
	 *
	 * Does NOT recurse into `sub_fields` (repeaters, groups) or
	 * `flexible_content` layouts — V1 non-goal NG1 excludes nested
	 * rendering, so exposing nested fields would invite users to
	 * configure bindings the applier cannot write.
	 *
	 * Returns an empty list when ACF is not active (graceful degradation).
	 */
	public function acf_fields(): void {
		$this->guard();

		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			wp_send_json_success( array() );
		}

		$post_type = $this->read_post_type();
		if ( '' === $post_type ) {
			wp_send_json_success( array() );
		}

		$cache_key = 'acf_fields_' . $post_type;
		$cached    = wp_cache_get( $cache_key, 'spintax' );
		if ( is_array( $cached ) ) {
			wp_send_json_success( $cached );
		}

		$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
		$result = array();

		foreach ( (array) $groups as $group ) {
			$fields = acf_get_fields( $group['key'] );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			$group_title = (string) ( $group['title'] ?? '' );
			foreach ( $fields as $field ) {
				$type = (string) ( $field['type'] ?? '' );
				if ( ! in_array( $type, array( 'text', 'textarea', 'wysiwyg' ), true ) ) {
					continue;
				}
				$name = (string) ( $field['name'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$result[] = array(
					'name'      => $name,
					'label'     => '' !== ( $field['label'] ?? '' ) ? (string) $field['label'] : $name,
					'group'     => $group_title,
					'field_key' => (string) ( $field['key'] ?? '' ),
				);
			}
		}

		wp_cache_set( $cache_key, $result, 'spintax', 5 * MINUTE_IN_SECONDS );
		wp_send_json_success( $result );
	}

	/**
	 * List of published `spintax_template` CPT entries for the
	 * source-template dropdown.
	 */
	public function template_list(): void {
		$this->guard();

		$ids = get_posts(
			array(
				'post_type'     => TemplatePostType::POST_TYPE,
				'numberposts'   => -1,
				'post_status'   => 'publish',
				'orderby'       => 'title',
				'order'         => 'ASC',
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);

		$result = array();
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post ) {
				continue;
			}
			$result[] = array(
				'id'    => (int) $post->ID,
				'title' => (string) $post->post_title,
				'slug'  => (string) $post->post_name,
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Capability + nonce check shared by all endpoints.
	 *
	 * Calls `wp_send_json_error()` (which exits) on failure.
	 */
	private function guard(): void {
		check_ajax_referer( 'spintax_admin', 'nonce' );
		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'spintax' ) ), 403 );
		}
	}

	/**
	 * Read the `post_type` parameter, normalising and validating it
	 * against registered post types.
	 */
	private function read_post_type(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by guard() above.
		$post_type = isset( $_REQUEST['post_type'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['post_type'] ) ) : '';
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return '';
		}
		return $post_type;
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

		// The preview reflects the binding's RENDER back to the caller, and a render can pull in a
		// target's own data — post fields, ACF siblings, or (2.4.0) WooCommerce product data. So the
		// dry-run must not become a way to read content the caller could not otherwise see: a
		// `manage_spintax_templates` holder is a stock Editor by default, who cannot view a draft
		// product's SKU / short description. `read_post` is exactly "may this user see THIS post's
		// content" — public posts pass for everyone; drafts/private/products map through their own
		// edit capabilities. The write path needs no such gate (it never echoes the data back, and
		// runs in userless cron / Bulk Apply contexts); this is only about the reflection.
		if ( ! current_user_can( 'read_post', $post_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to preview this post.', 'spintax' ) ),
				403
			);
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
