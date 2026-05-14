<?php

namespace Spintax\Tests\Admin;

use Spintax\Admin\AdminNotice;

/**
 * Test fixture exposing the `AdminNotice` trait's private methods so
 * the trait contract can be exercised in isolation.
 *
 * `redirect_with_notice()` is split into `flash_notice()` (writes the
 * transient without redirecting) + `do_redirect()` (the side-effects)
 * so tests can assert flash state without halting execution via
 * `wp_safe_redirect()` + `exit`.
 */
class NoticeFixture {
	use AdminNotice {
		AdminNotice::redirect_with_notice as private trait_redirect_with_notice;
	}

	/**
	 * Public proxy that flashes the same payload the trait would, but
	 * skips the redirect + exit.
	 *
	 * @param string|array<string, mixed> $payload Notice payload.
	 * @param string                      $type    Notice type.
	 */
	public function flash( $payload, string $type = 'success' ): void {
		$key = 'spintax_admin_notice_' . get_current_user_id();
		$ref = new \ReflectionMethod( $this, 'normalize_notice_payload' );
		$ref->setAccessible( true );
		set_transient(
			$key,
			array(
				'payload' => $ref->invoke( $this, $payload ),
				'type'    => $type,
			),
			60
		);
	}

	/**
	 * Public proxy for render_notice() so tests can capture output.
	 */
	public function render(): string {
		ob_start();
		$ref = new \ReflectionMethod( $this, 'render_notice' );
		$ref->setAccessible( true );
		$ref->invoke( $this );
		return (string) ob_get_clean();
	}
}

/**
 * Exercises the AdminNotice trait — both legacy string payloads and
 * the 2.1.0 rich payload shape (text + action_url + action_label).
 */
class AdminNoticeTest extends \WP_UnitTestCase {

	private NoticeFixture $fixture;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
		delete_transient( 'spintax_admin_notice_' . $this->user_id );
		$this->fixture = new NoticeFixture();
	}

	public function tear_down(): void {
		delete_transient( 'spintax_admin_notice_' . $this->user_id );
		parent::tear_down();
	}

	public function test_string_payload_renders_as_plain_notice(): void {
		$this->fixture->flash( 'All good.' );
		$html = $this->fixture->render();

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'All good.', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	public function test_string_payload_escapes_html(): void {
		$this->fixture->flash( '<script>alert(1)</script>' );
		$html = $this->fixture->render();

		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_array_payload_renders_action_link(): void {
		$this->fixture->flash(
			array(
				'text'         => 'Bulk Apply enqueued.',
				'action_url'   => admin_url( 'edit.php?post_type=spintax_template&page=spintax-logs' ),
				'action_label' => 'View progress in Logs →',
			)
		);
		$html = $this->fixture->render();

		$this->assertStringContainsString( 'Bulk Apply enqueued.', $html );
		$this->assertStringContainsString( 'class="button button-small"', $html );
		$this->assertStringContainsString( 'page=spintax-logs', $html );
		$this->assertStringContainsString( 'View progress in Logs', $html );
	}

	public function test_array_payload_without_action_url_falls_back_to_text(): void {
		$this->fixture->flash(
			array(
				'text'         => 'Just text.',
				'action_label' => 'never shown',
			)
		);
		$html = $this->fixture->render();

		$this->assertStringContainsString( 'Just text.', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	public function test_array_payload_escapes_action_url(): void {
		$this->fixture->flash(
			array(
				'text'         => 'Click below.',
				'action_url'   => 'javascript:alert(1)',
				'action_label' => 'Bad link',
			)
		);
		$html = $this->fixture->render();

		// esc_url() strips javascript: schemes — the href should be empty
		// or fall back to a safe placeholder, never executable.
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_notice_clears_after_render(): void {
		$this->fixture->flash( 'Once.' );
		$this->fixture->render();
		$html = $this->fixture->render();

		$this->assertSame( '', $html, 'Notice transient must be consumed on first render.' );
	}

	public function test_invalid_type_falls_back_to_info(): void {
		$this->fixture->flash( 'Hello', 'bogus' );
		$html = $this->fixture->render();
		$this->assertStringContainsString( 'notice-info', $html );
	}

	public function test_legacy_message_key_still_renders(): void {
		// Older pre-2.1.0 sites may have a notice transient written by
		// the previous payload shape ({message, type}). Confirm
		// render_notice gracefully handles it via the fallback branch.
		$key = 'spintax_admin_notice_' . $this->user_id;
		set_transient(
			$key,
			array(
				'message' => 'Legacy text.',
				'type'    => 'success',
			),
			60
		);

		$html = $this->fixture->render();
		$this->assertStringContainsString( 'Legacy text.', $html );
		$this->assertStringContainsString( 'notice-success', $html );
	}
}
