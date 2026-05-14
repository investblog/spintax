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
 *
 * Two payload shapes are accepted:
 *
 *  - string $message — legacy single-line notice text.
 *  - array{text: string, action_url?: string, action_label?: string}
 *      Rich payload: renders `text` plus an optional inline CTA button
 *      linking to `action_url`. Use when the notice should steer the
 *      user to a follow-up screen (e.g. "Bulk Apply enqueued" → Logs).
 *
 * Both shapes escape text and URLs at render time. Callers MUST NOT
 * pre-escape — passing pre-escaped HTML inside the array would be
 * double-escaped and surface as literal entities to the editor.
 */
trait AdminNotice {

	/**
	 * Redirect with a flash notice stored in a user transient.
	 *
	 * @param string                      $url     Redirect target.
	 * @param string|array<string, mixed> $payload Notice text, or rich payload (see trait docblock).
	 * @param string                      $type    Notice type: success|error|warning|info.
	 */
	private function redirect_with_notice( string $url, $payload, string $type = 'success' ): void {
		$key = 'spintax_admin_notice_' . get_current_user_id();
		set_transient(
			$key,
			array(
				'payload' => $this->normalize_notice_payload( $payload ),
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

		$type    = in_array( $notice['type'] ?? '', array( 'success', 'error', 'warning', 'info' ), true )
			? $notice['type']
			: 'info';
		$payload = isset( $notice['payload'] ) && is_array( $notice['payload'] )
			? $notice['payload']
			// Fallback for any pre-2.1.0 transient still in flight after upgrade.
			: $this->normalize_notice_payload( $notice['message'] ?? '' );

		$text         = (string) ( $payload['text'] ?? '' );
		$action_url   = (string) ( $payload['action_url'] ?? '' );
		$action_label = (string) ( $payload['action_label'] ?? '' );

		$action_html = '';
		if ( '' !== $action_url && '' !== $action_label ) {
			$action_html = sprintf(
				' <a class="button button-small" href="%s">%s</a>',
				esc_url( $action_url ),
				esc_html( $action_label )
			);
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s%s</p></div>',
			esc_attr( $type ),
			esc_html( $text ),
			// Action HTML is built from escaped fragments above; safe to echo.
			$action_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Normalise a notice payload into the canonical array shape.
	 *
	 * Accepts:
	 *  - string                — coerced to ['text' => $string]
	 *  - array{text, action_url?, action_label?} — pass-through with type coercion.
	 *
	 * @param string|array<string, mixed> $payload Raw payload.
	 * @return array{text: string, action_url: string, action_label: string}
	 */
	private function normalize_notice_payload( $payload ): array {
		if ( is_string( $payload ) ) {
			return array(
				'text'         => $payload,
				'action_url'   => '',
				'action_label' => '',
			);
		}
		if ( ! is_array( $payload ) ) {
			return array(
				'text'         => '',
				'action_url'   => '',
				'action_label' => '',
			);
		}
		return array(
			'text'         => (string) ( $payload['text'] ?? '' ),
			'action_url'   => (string) ( $payload['action_url'] ?? '' ),
			'action_label' => (string) ( $payload['action_label'] ?? '' ),
		);
	}
}
