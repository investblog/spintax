<?php
/**
 * Template CPT editor customisation.
 *
 * @package Spintax
 */

namespace Spintax\Admin;

defined( 'ABSPATH' ) || exit;

use Spintax\Core\PostType\TemplatePostType;
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
