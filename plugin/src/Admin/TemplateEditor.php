<?php
/**
 * Template CPT editor customisation.
 *
 * @package Spintax
 */

namespace Spintax\Admin;

defined( 'ABSPATH' ) || exit;

use Spintax\Core\PostType\TemplatePostType;
use Spintax\Support\Links;
use Spintax\Support\OptionKeys;

/**
 * Customises the template edit screen: list columns, code editor mode.
 */
class TemplateEditor {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		$cpt = TemplatePostType::POST_TYPE;

		add_filter( "manage_{$cpt}_posts_columns", array( $this, 'register_columns' ) );
		add_action( "manage_{$cpt}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_filter( 'wp_editor_settings', array( $this, 'force_text_editor' ), 10, 2 );
		add_action( 'edit_form_after_title', array( $this, 'render_help_links' ) );
	}

	/**
	 * Render a small toolbar of doc/playground links above the editor.
	 *
	 * Shown only on the template CPT edit screen. Locale-aware: WP sites
	 * running in Russian get the `/ru/` mirror of long-form pages, others
	 * get the EN root.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_help_links( \WP_Post $post ): void {
		if ( TemplatePostType::POST_TYPE !== $post->post_type ) {
			return;
		}
		?>
		<div class="spintax-help-links" style="margin:1em 0;padding:.6em .9em;background:#f0f6fc;border-left:4px solid #2271b1;font-size:13px;">
			<strong><?php esc_html_e( 'Spintax help:', 'spintax' ); ?></strong>
			<a href="<?php echo esc_url( Links::docs_syntax() ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Syntax reference', 'spintax' ); ?>
			</a>
			·
			<a href="<?php echo esc_url( Links::docs_plural() ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Plural guide', 'spintax' ); ?>
			</a>
			·
			<a href="<?php echo esc_url( Links::docs_conditional() ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Conditional guide', 'spintax' ); ?>
			</a>
			·
			<a href="<?php echo esc_url( Links::playground() ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Live playground', 'spintax' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Register custom list table columns.
	 *
	 * @param array $columns Default columns.
	 * @return array Modified columns.
	 */
	public function register_columns( array $columns ): array {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['spintax_slug']      = __( 'Slug', 'spintax' );
				$new['spintax_shortcode'] = __( 'Shortcode', 'spintax' );
				$new['spintax_ttl']       = __( 'Cache TTL', 'spintax' );
				$new['spintax_cron']      = __( 'Cron', 'spintax' );
				$new['spintax_regen']     = __( 'Regenerated', 'spintax' );
			}
		}

		// Remove default date — we have our own regen column.
		unset( $new['date'] );

		return $new;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'spintax_slug':
				$post = get_post( $post_id );
				echo '<code>' . esc_html( $post->post_name ) . '</code>';
				break;

			case 'spintax_shortcode':
				$post = get_post( $post_id );
				$sc   = '[spintax slug="' . esc_attr( $post->post_name ) . '"]';
				printf(
					'<code class="spintax-copy-shortcode" title="%s">%s</code>',
					esc_attr__( 'Click to copy', 'spintax' ),
					esc_html( $sc )
				);
				break;

			case 'spintax_ttl':
				$override = get_post_meta( $post_id, OptionKeys::META_CACHE_TTL, true );
				if ( '' !== $override && false !== $override ) {
					$ttl = (int) $override;
					echo 0 === $ttl
						? esc_html__( 'Disabled', 'spintax' )
						: esc_html( $this->format_duration( $ttl ) );
				} else {
					echo '<em>' . esc_html__( 'Default', 'spintax' ) . '</em>';
				}
				break;

			case 'spintax_cron':
				$schedule = get_post_meta( $post_id, OptionKeys::META_CRON_SCHEDULE, true );
				echo esc_html( $schedule && 'disabled' !== $schedule ? $schedule : '—' );
				break;

			case 'spintax_regen':
				$ts = (int) get_post_meta( $post_id, OptionKeys::META_LAST_REGENERATED, true );
				echo $ts > 0
					? esc_html( human_time_diff( $ts ) . ' ' . __( 'ago', 'spintax' ) )
					: '—';
				break;
		}
	}

	/**
	 * Force plain text editor (no TinyMCE) for template CPT.
	 *
	 * @param array  $settings Editor settings.
	 * @param string $editor_id Editor ID.
	 * @return array Modified settings.
	 */
	public function force_text_editor( array $settings, string $editor_id ): array {
		if ( 'content' !== $editor_id ) {
			return $settings;
		}

		$screen = get_current_screen();
		if ( ! $screen || TemplatePostType::POST_TYPE !== $screen->post_type ) {
			return $settings;
		}

		$settings['tinymce']       = false;
		$settings['quicktags']     = true;
		$settings['media_buttons'] = false;
		$settings['wpautop']       = false;

		return $settings;
	}

	/**
	 * Format seconds as a human-readable duration.
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string Human-readable duration string.
	 */
	private function format_duration( int $seconds ): string {
		if ( $seconds >= 86400 ) {
			return round( $seconds / 86400, 1 ) . 'd';
		}
		if ( $seconds >= 3600 ) {
			return round( $seconds / 3600, 1 ) . 'h';
		}
		if ( $seconds >= 60 ) {
			return round( $seconds / 60 ) . 'm';
		}
		return $seconds . 's';
	}
}
