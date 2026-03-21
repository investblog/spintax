<?php
/**
 * Flash-message trait for admin pages (PRG pattern).
 *
 * @package Spintax
 */

namespace Spintax\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Provides redirect-with-notice and render-notice helpers.
 */
trait AdminNotice {

	/**
	 * Redirect with a flash notice stored in a user transient.
	 *
	 * @param string $url     Redirect target.
	 * @param string $message Notice text.
	 * @param string $type    Notice type: success|error|warning|info.
	 */
	private function redirect_with_notice( string $url, string $message, string $type = 'success' ): void {
		$key = 'spintax_admin_notice_' . get_current_user_id();
		set_transient(
			$key,
			array(
				'message' => $message,
				'type'    => $type,
			),
			60
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render and clear a pending flash notice.
	 */
	private function render_notice(): void {
		$key    = 'spintax_admin_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! $notice || ! is_array( $notice ) ) {
			return;
		}

		delete_transient( $key );

		$type    = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true )
			? $notice['type']
			: 'info';
		$message = esc_html( $notice['message'] );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $message is already escaped with esc_html() above.
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			$message
		);
	}
}
