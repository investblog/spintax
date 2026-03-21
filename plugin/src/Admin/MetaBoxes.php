<?php
/**
 * Meta boxes for the template edit screen.
 *
 * @package Spintax
 */

namespace Spintax\Admin;

defined( 'ABSPATH' ) || exit;

use Spintax\Core\Cache\CacheManager;
use Spintax\Core\Cache\DependencyInvalidator;
use Spintax\Core\Cron\CronManager;
use Spintax\Core\Engine\Parser;
use Spintax\Core\Engine\Validator;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Core\Render\Renderer;
use Spintax\Core\Settings\SettingsRepository;
use Spintax\Support\Defaults;
use Spintax\Support\OptionKeys;

/**
 * Registers and handles three meta boxes:
 *   - Cache Settings (TTL override, cron schedule, regenerate button)
 *   - Preview (rendered output, regenerate preview, validation)
 *   - Usage (shortcode and PHP examples)
 */
class MetaBoxes {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'add_meta_boxes_' . TemplatePostType::POST_TYPE, array( $this, 'register' ) );
		add_action( 'save_post_' . TemplatePostType::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'show_validation_notices' ) );
		add_action( 'wp_ajax_spintax_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_spintax_regenerate', array( $this, 'ajax_regenerate' ) );
	}

	/**
	 * Register meta boxes.
	 */
	public function register(): void {
		add_meta_box(
			'spintax-cache-settings',
			__( 'Cache Settings', 'spintax' ),
			array( $this, 'render_cache_settings' ),
			TemplatePostType::POST_TYPE,
			'side',
			'default'
		);

		add_meta_box(
			'spintax-preview',
			__( 'Preview', 'spintax' ),
			array( $this, 'render_preview' ),
			TemplatePostType::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'spintax-usage',
			__( 'Usage', 'spintax' ),
			array( $this, 'render_usage' ),
			TemplatePostType::POST_TYPE,
			'side',
			'low'
		);
	}

	/**
	 * Render Cache Settings meta box.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_cache_settings( \WP_Post $post ): void {
		wp_nonce_field( 'spintax_meta_save', 'spintax_meta_nonce' );

		$ttl      = get_post_meta( $post->ID, OptionKeys::META_CACHE_TTL, true );
		$schedule = get_post_meta( $post->ID, OptionKeys::META_CRON_SCHEDULE, true );

		if ( '' === $schedule ) {
			$schedule = 'disabled';
		}
		?>
		<p>
			<label for="spintax-cache-ttl"><?php esc_html_e( 'Cache TTL (seconds)', 'spintax' ); ?></label><br>
			<input type="number" id="spintax-cache-ttl" name="spintax_cache_ttl"
				value="<?php echo esc_attr( $ttl ); ?>"
				min="0" step="1" class="widefat"
				placeholder="<?php esc_attr_e( 'Use global default', 'spintax' ); ?>">
			<span class="description"><?php esc_html_e( '0 = no caching. Empty = global default.', 'spintax' ); ?></span>
		</p>

		<p>
			<label for="spintax-cron-schedule"><?php esc_html_e( 'Cron Schedule', 'spintax' ); ?></label><br>
			<select id="spintax-cron-schedule" name="spintax_cron_schedule" class="widefat">
				<?php foreach ( Defaults::cron_schedules() as $value ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $schedule, $value ); ?>>
						<?php echo esc_html( ucfirst( $value ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<button type="button" class="button spintax-regenerate-public"
				data-post-id="<?php echo esc_attr( $post->ID ); ?>">
				<?php esc_html_e( 'Regenerate Public Cache', 'spintax' ); ?>
			</button>
		</p>
		<?php
	}

	/**
	 * Render Preview meta box.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_preview( \WP_Post $post ): void {
		?>
		<div class="spintax-preview-actions">
			<button type="button" class="button spintax-regenerate-preview"
				data-post-id="<?php echo esc_attr( $post->ID ); ?>">
				<?php esc_html_e( 'Regenerate Preview', 'spintax' ); ?>
			</button>
			<span class="spinner"></span>
		</div>

		<div class="spintax-preview-validation"></div>

		<div class="spintax-preview-output">
			<em><?php esc_html_e( 'Click "Regenerate Preview" to see rendered output.', 'spintax' ); ?></em>
		</div>
		<?php
	}

	/**
	 * Render Usage meta box.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_usage( \WP_Post $post ): void {
		$slug = $post->post_name;
		$id   = $post->ID;
		?>
		<p><strong><?php esc_html_e( 'Shortcode (by slug)', 'spintax' ); ?></strong></p>
		<code class="spintax-copy-shortcode widefat">[spintax slug="<?php echo esc_attr( $slug ); ?>"]</code>

		<p><strong><?php esc_html_e( 'Shortcode (by ID)', 'spintax' ); ?></strong></p>
		<code class="spintax-copy-shortcode widefat">[spintax id="<?php echo esc_attr( $id ); ?>"]</code>

		<p><strong><?php esc_html_e( 'PHP', 'spintax' ); ?></strong></p>
		<code class="widefat">&lt;?php echo spintax_render( '<?php echo esc_html( $slug ); ?>' ); ?&gt;</code>
		<?php
	}

	/**
	 * Save meta box data.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['spintax_meta_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['spintax_meta_nonce'] ) ), 'spintax_meta_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Cache TTL.
		if ( isset( $_POST['spintax_cache_ttl'] ) ) {
			$ttl = sanitize_text_field( wp_unslash( $_POST['spintax_cache_ttl'] ) );
			if ( '' === $ttl ) {
				delete_post_meta( $post_id, OptionKeys::META_CACHE_TTL );
			} else {
				update_post_meta( $post_id, OptionKeys::META_CACHE_TTL, max( 0, (int) $ttl ) );
			}
		}

		// Cron schedule.
		if ( isset( $_POST['spintax_cron_schedule'] ) ) {
			$schedule = sanitize_text_field( wp_unslash( $_POST['spintax_cron_schedule'] ) );
			if ( in_array( $schedule, Defaults::cron_schedules(), true ) ) {
				update_post_meta( $post_id, OptionKeys::META_CRON_SCHEDULE, $schedule );
			}
		}

		// Validate template content with full context.
		$result = $this->run_validation( $post->post_content );

		if ( ! empty( $result['errors'] ) || ! empty( $result['warnings'] ) ) {
			set_transient(
				'spintax_validation_' . $post_id,
				$result,
				120
			);
		}

		// Sync cron schedule.
		$cron = new CronManager();
		$cron->sync_schedule( $post_id );
	}

	/**
	 * Display validation notices on the template edit screen.
	 */
	public function show_validation_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || TemplatePostType::POST_TYPE !== $screen->post_type ) {
			return;
		}

		global $post;
		if ( ! $post ) {
			return;
		}

		$key    = 'spintax_validation_' . $post->ID;
		$result = get_transient( $key );
		if ( ! $result || ! is_array( $result ) ) {
			return;
		}
		delete_transient( $key );

		foreach ( $result['errors'] ?? array() as $error ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Spintax Error:', 'spintax' ),
				esc_html( $error['message'] )
			);
		}
		foreach ( $result['warnings'] ?? array() as $warning ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Spintax Warning:', 'spintax' ),
				esc_html( $warning['message'] )
			);
		}
	}

	/**
	 * AJAX: render a fresh preview from editor content (not DB).
	 */
	public function ajax_preview(): void {
		check_ajax_referer( 'spintax_admin', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Permission denied.', 'spintax' ) );
		}

		// Use editor content if sent, otherwise fall back to saved content.
		$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : null;
		if ( null === $content ) {
			$post = get_post( $post_id );
			if ( ! $post || TemplatePostType::POST_TYPE !== $post->post_type ) {
				wp_send_json_error( __( 'Template not found.', 'spintax' ) );
			}
			$content = $post->post_content;
		}

		// Validate with full context.
		$validation = $this->run_validation( $content );

		// Render preview (does NOT touch public cache).
		$renderer = new Renderer();
		$output   = $renderer->process_template( $content );

		wp_send_json_success( array(
			'html'       => $output,
			'validation' => $validation,
		) );
	}

	/**
	 * AJAX: regenerate public cache.
	 */
	public function ajax_regenerate(): void {
		check_ajax_referer( 'spintax_admin', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Permission denied.', 'spintax' ) );
		}

		$cache = new CacheManager();
		$cache->invalidate_template( $post_id );

		$deps = new DependencyInvalidator( $cache );
		$deps->invalidate_dependents( $post_id );

		// Warm with a full fresh subtree render (bypasses child caches too).
		$renderer = new Renderer();
		$renderer->render_fresh( $post_id );

		wp_send_json_success( array(
			'message' => __( 'Cache regenerated.', 'spintax' ),
		) );
	}

	/**
	 * Run validation with known template slugs and global variables.
	 *
	 * @param string $content Template content to validate.
	 * @return array{errors: array, warnings: array}
	 */
	private function run_validation( string $content ): array {
		$known_slugs = get_posts( array(
			'post_type'      => TemplatePostType::POST_TYPE,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post_status'    => array( 'publish', 'draft', 'private' ),
		) );

		// Collect slugs from post names.
		$slugs = array();
		foreach ( $known_slugs as $pid ) {
			$p = get_post( $pid );
			if ( $p ) {
				$slugs[] = $p->post_name;
				$slugs[] = (string) $p->ID;
			}
		}

		$settings    = new SettingsRepository();
		$global_vars = array_keys( $settings->get_global_variables() );

		$validator = new Validator();
		return $validator->validate( $content, $slugs, $global_vars );
	}
}
