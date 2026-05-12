<?php
/**
 * Per-post metabox for `per_post`-mode bindings.
 *
 * @package Spintax
 */

namespace Spintax\Admin;

defined( 'ABSPATH' ) || exit;

use Spintax\Bindings\BindingsRepo;
use Spintax\Support\Capabilities;
use Spintax\Support\OptionKeys;
use Spintax\Support\Validators;
use WP_Post;

/**
 * Inline source authoring for `per_post`-mode bindings.
 *
 * The metabox appears on the edit screen for any post whose post type
 * has at least one `per_post`-mode binding. For each matching binding
 * it shows a textarea holding the per-post source content from
 * `_spintax_source_<target.key>` and writes back on `save_post`.
 *
 * Phase 2 boundary: this is purely the authoring surface for the
 * `per_post` source. The actual write into the target field happens in
 * `BindingApplier` via `SavePostTrigger`.
 */
class BindingsMetaBox {

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
		add_action( 'add_meta_boxes', array( $this, 'register_for_post_type' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Conditionally register the metabox for post types that have
	 * at least one `per_post`-mode binding.
	 *
	 * @param string $post_type Current post type being edited.
	 */
	public function register_for_post_type( string $post_type ): void {
		$bindings = $this->per_post_bindings_for( $post_type );
		if ( empty( $bindings ) ) {
			return;
		}

		add_meta_box(
			'spintax-bindings-per-post',
			__( 'Spintax bindings (per-post source)', 'spintax' ),
			array( $this, 'render' ),
			$post_type,
			'normal',
			'default'
		);
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render( WP_Post $post ): void {
		$bindings = $this->per_post_bindings_for( $post->post_type );
		if ( empty( $bindings ) ) {
			return;
		}

		wp_nonce_field( 'spintax_binding_per_post', 'spintax_binding_per_post_nonce' );

		foreach ( $bindings as $binding ) {
			$id          = (string) ( $binding['id'] ?? '' );
			$target_key  = (string) ( $binding['target']['key'] ?? '' );
			$target_kind = (string) ( $binding['target']['kind'] ?? '' );
			if ( '' === $target_key ) {
				continue;
			}
			$meta_key = OptionKeys::META_BINDING_SOURCE_PREFIX . $target_key;
			$value    = (string) get_post_meta( $post->ID, $meta_key, true );

			$label = 'acf_field' === $target_kind
				/* translators: %s: ACF field name */
				? sprintf( __( 'ACF field: %s', 'spintax' ), $target_key )
				/* translators: %s: post-meta key */
				: sprintf( __( 'Post meta: %s', 'spintax' ), $target_key );

			$field_id = 'spintax-binding-source-' . sanitize_html_class( $id );
			?>
			<div style="margin-bottom:18px;padding:12px;background:#f6f7f7;border-radius:4px;">
				<p style="margin-top:0;">
					<strong><?php echo esc_html( $label ); ?></strong>
					<code style="margin-left:8px;font-size:11px;color:#646970;"><?php echo esc_html( $id ); ?></code>
				</p>
				<textarea
					name="spintax_binding_source[<?php echo esc_attr( $target_key ); ?>]"
					id="<?php echo esc_attr( $field_id ); ?>"
					rows="6"
					class="large-text code"
				><?php echo esc_textarea( $value ); ?></textarea>
				<p class="description">
					<?php
					printf(
						/* translators: %s: sibling meta key */
						esc_html__( 'Spintax source for this field on this post. Stored as %s.', 'spintax' ),
						'<code>' . esc_html( $meta_key ) . '</code>'
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Persist per-post source content on save.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post object.
	 */
	public function save( int $post_id, $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		if ( ! isset( $_POST['spintax_binding_per_post_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['spintax_binding_per_post_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'spintax_binding_per_post' ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::CAP ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
		if ( ! isset( $_POST['spintax_binding_source'] ) || ! is_array( $_POST['spintax_binding_source'] ) ) {
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			return;
		}

		$bindings = $this->per_post_bindings_for( $post->post_type );
		if ( empty( $bindings ) ) {
			return;
		}

		// Whitelist keys against current per_post bindings to avoid
		// drive-by writes to arbitrary `_spintax_source_*` meta.
		$allowed_keys = array();
		foreach ( $bindings as $binding ) {
			$key = (string) ( $binding['target']['key'] ?? '' );
			if ( '' !== $key ) {
				$allowed_keys[ $key ] = true;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; each value runs through Validators::sanitize_spintax() in the loop, which is the spintax-aware sanitiser.
		$raw_payload = wp_unslash( $_POST['spintax_binding_source'] );
		if ( ! is_array( $raw_payload ) ) {
			return;
		}

		foreach ( $raw_payload as $target_key => $raw_source ) {
			$target_key = (string) $target_key;
			if ( ! isset( $allowed_keys[ $target_key ] ) ) {
				continue;
			}
			$cleaned  = Validators::sanitize_spintax( (string) $raw_source );
			$meta_key = OptionKeys::META_BINDING_SOURCE_PREFIX . $target_key;
			if ( '' === $cleaned ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, $cleaned );
			}
		}
	}

	/**
	 * Bindings on a given post type that are in `per_post` mode.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<int, array<string, mixed>>
	 */
	private function per_post_bindings_for( string $post_type ): array {
		$all    = $this->repo->find_for_post_type( $post_type );
		$result = array();
		foreach ( $all as $binding ) {
			if ( 'per_post' === (string) ( $binding['source']['mode'] ?? '' ) ) {
				$result[] = $binding;
			}
		}
		return $result;
	}
}
