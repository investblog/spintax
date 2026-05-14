<?php

namespace Spintax\Tests\Admin;

use Spintax\Admin\LogsPage;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Core\Settings\SettingsRepository;
use Spintax\Support\Capabilities;
use Spintax\Support\Logging;
use Spintax\Support\OptionKeys;

/**
 * Exercises the Spintax → Logs admin page (added in 2.1.0):
 *  - submenu registration under the spintax CPT
 *  - level filter + substring search
 *  - newest-first order
 *  - pagination clamped by settings.logs_max
 *  - clear-logs handler (admin can, editor cannot)
 *  - empty-state placeholder
 */
class LogsPageTest extends \WP_UnitTestCase {

	private LogsPage $page;
	private int $admin_id;
	private int $editor_id;

	public function set_up(): void {
		parent::set_up();

		delete_option( OptionKeys::LOGS );
		delete_option( OptionKeys::SETTINGS );
		Capabilities::register( true );

		$this->admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Default to admin context unless a specific test switches.
		wp_set_current_user( $this->admin_id );

		$_GET    = array();
		$_POST   = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->page = new LogsPage();
	}

	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();
		delete_option( OptionKeys::LOGS );
		delete_option( OptionKeys::SETTINGS );
		parent::tear_down();
	}

	private function seed_logs( array $entries ): void {
		$logger = new Logging();
		foreach ( $entries as $entry ) {
			$logger->push(
				$entry['lvl'] ?? 'info',
				$entry['msg'] ?? '',
				$entry['ctx'] ?? array()
			);
		}
	}

	private function render_html(): string {
		ob_start();
		$this->page->render();
		return (string) ob_get_clean();
	}

	public function test_submenu_registered_under_spintax_cpt(): void {
		global $submenu;
		$submenu = array();

		set_current_screen( 'dashboard' );
		$this->page->register_menu();

		$cpt_parent = 'edit.php?post_type=' . TemplatePostType::POST_TYPE;
		$this->assertArrayHasKey( $cpt_parent, $submenu );
		$slugs = array_column( $submenu[ $cpt_parent ], 2 );
		$this->assertContains( 'spintax-logs', $slugs );
	}

	public function test_render_shows_empty_state_when_no_logs(): void {
		$html = $this->render_html();
		$this->assertStringContainsString( 'No log entries yet', $html );
	}

	public function test_render_shows_filter_empty_state_when_filter_excludes_everything(): void {
		$this->seed_logs( array( array( 'lvl' => 'info', 'msg' => 'hello' ) ) );
		$_GET = array( 'level' => 'error' );

		$html = $this->render_html();
		$this->assertStringContainsString( 'No log entries match the current filter', $html );
	}

	public function test_render_lists_entries_newest_first(): void {
		$this->seed_logs(
			array(
				array( 'lvl' => 'info', 'msg' => 'oldest entry' ),
				array( 'lvl' => 'info', 'msg' => 'middle entry' ),
				array( 'lvl' => 'info', 'msg' => 'newest entry' ),
			)
		);

		$html = $this->render_html();
		$first_pos  = strpos( $html, 'newest entry' );
		$middle_pos = strpos( $html, 'middle entry' );
		$last_pos   = strpos( $html, 'oldest entry' );

		$this->assertNotFalse( $first_pos );
		$this->assertNotFalse( $middle_pos );
		$this->assertNotFalse( $last_pos );
		$this->assertLessThan( $middle_pos, $first_pos, 'Newest entry must appear before middle.' );
		$this->assertLessThan( $last_pos, $middle_pos, 'Middle entry must appear before oldest.' );
	}

	public function test_level_filter_narrows_to_matching_entries(): void {
		$this->seed_logs(
			array(
				array( 'lvl' => 'info', 'msg' => 'an info line' ),
				array( 'lvl' => 'warning', 'msg' => 'a warning line' ),
				array( 'lvl' => 'error', 'msg' => 'an error line' ),
			)
		);

		$_GET = array( 'level' => 'warning' );

		$html = $this->render_html();
		$this->assertStringContainsString( 'a warning line', $html );
		$this->assertStringNotContainsString( 'an info line', $html );
		$this->assertStringNotContainsString( 'an error line', $html );
	}

	public function test_unknown_level_filter_falls_back_to_all(): void {
		$this->seed_logs(
			array(
				array( 'lvl' => 'info', 'msg' => 'kept entry' ),
			)
		);

		$_GET = array( 'level' => 'gibberish' );
		$html = $this->render_html();

		$this->assertStringContainsString( 'kept entry', $html );
	}

	public function test_search_matches_message_substring_case_insensitive(): void {
		$this->seed_logs(
			array(
				array( 'lvl' => 'info', 'msg' => 'Bulk Apply enqueued for binding bind_abc123' ),
				array( 'lvl' => 'info', 'msg' => 'Unrelated event' ),
			)
		);

		$_GET = array( 'q' => 'BIND_ABC' );
		$html = $this->render_html();

		$this->assertStringContainsString( 'Bulk Apply enqueued', $html );
		$this->assertStringNotContainsString( 'Unrelated event', $html );
	}

	public function test_search_matches_context_values(): void {
		$this->seed_logs(
			array(
				array(
					'lvl' => 'info',
					'msg' => 'generic-message',
					'ctx' => array( 'binding_id' => 'bind_xyz' ),
				),
				array( 'lvl' => 'info', 'msg' => 'another-message' ),
			)
		);

		$_GET = array( 'q' => 'bind_xyz' );
		$html = $this->render_html();

		$this->assertStringContainsString( 'generic-message', $html );
		$this->assertStringNotContainsString( 'another-message', $html );
	}

	public function test_pagination_respects_logs_max_cap(): void {
		// Drop logs_max to 10 (the Validators::normalize_settings minimum;
		// anything lower is clamped). Page size effective_per_page() then
		// clamps to min( PER_PAGE=50, logs_max=10 ) = 10.
		( new SettingsRepository() )->update( array( 'logs_max' => 10 ) );

		$entries = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$entries[] = array( 'lvl' => 'info', 'msg' => 'entry ' . $i );
		}
		$this->seed_logs( $entries );

		$html = $this->render_html();

		// Ring buffer keeps last 10 entries (2-11); 0+1 are trimmed.
		$this->assertStringContainsString( 'entry 11', $html );
		$this->assertStringContainsString( 'entry 2', $html );
		$this->assertStringNotContainsString( 'entry 0', $html );
		$this->assertStringNotContainsString( 'entry 1<', $html );

		// All 10 retained entries fit on one page — no pagination links.
		$this->assertStringNotContainsString( 'tablenav-pages', $html );
	}

	public function test_clear_logs_requires_admin_capability(): void {
		// Switch to editor — editor lacks manage_options and must be
		// blocked from clearing even though they can VIEW logs.
		wp_set_current_user( $this->editor_id );
		$this->seed_logs( array( array( 'lvl' => 'info', 'msg' => 'sticky entry' ) ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                    = array(
			'spintax_logs_clear' => '1',
			'_wpnonce'           => wp_create_nonce( 'spintax_logs_clear' ),
		);

		$this->expectException( \WPDieException::class );
		$this->page->handle_actions();

		// Sanity: logs must remain after the wp_die().
		$this->assertNotEmpty( ( new Logging() )->all() );
	}

	public function test_editor_can_view_logs(): void {
		wp_set_current_user( $this->editor_id );
		$this->seed_logs( array( array( 'lvl' => 'info', 'msg' => 'editor-visible entry' ) ) );

		$html = $this->render_html();
		$this->assertStringContainsString( 'editor-visible entry', $html );
	}

	public function test_clear_button_hidden_for_non_admin(): void {
		wp_set_current_user( $this->editor_id );

		$html = $this->render_html();
		$this->assertStringNotContainsString( 'name="spintax_logs_clear"', $html );
	}

	public function test_clear_button_visible_for_admin(): void {
		$html = $this->render_html();
		$this->assertStringContainsString( 'name="spintax_logs_clear"', $html );
	}

	public function test_clear_handler_wipes_log_buffer(): void {
		$this->seed_logs( array( array( 'lvl' => 'info', 'msg' => 'will be cleared' ) ) );

		$nonce = wp_create_nonce( 'spintax_logs_clear' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                    = array(
			'spintax_logs_clear' => '1',
			'_wpnonce'           => $nonce,
		);
		$_REQUEST['_wpnonce']     = $nonce;

		// `redirect_with_notice` ends with `wp_safe_redirect + exit`.
		// Intercept the redirect so we can assert without killing the
		// test process.
		$captured        = '';
		$redirect_filter = static function ( $location ) use ( &$captured ) {
			$captured = (string) $location;
			throw new \RuntimeException( 'redirect captured' );
		};
		add_filter( 'wp_redirect', $redirect_filter, 1, 1 );

		try {
			$this->page->handle_actions();
			$this->fail( 'Expected a redirect after clearing logs.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect captured', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $redirect_filter, 1 );
		}

		$this->assertStringContainsString( 'spintax-logs', $captured );
		$this->assertEmpty( ( new Logging() )->all() );
	}

	public function test_page_url_points_under_cpt_submenu(): void {
		$url = LogsPage::page_url();
		$this->assertStringContainsString( 'edit.php', $url );
		$this->assertStringContainsString( 'post_type=' . TemplatePostType::POST_TYPE, $url );
		$this->assertStringContainsString( 'page=spintax-logs', $url );
	}
}
