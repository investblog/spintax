<?php

namespace Spintax\Tests\Admin;

use Spintax\Admin\BindingsAjax;
use Spintax\Admin\BindingsPage;
use Spintax\Bindings\BindingsRepo;
use Spintax\Bindings\BulkApply;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Support\Capabilities;
use Spintax\Support\OptionKeys;

/**
 * Tiny exception used by tests that need to short-circuit the trait's
 * `wp_safe_redirect() + exit;` sequence without killing the PHPUnit
 * worker. Wired up via the `wp_redirect` filter in individual cases.
 */
class BindingsPageRedirect extends \Exception {

	public string $redirect_url;

	public function __construct( string $url ) {
		parent::__construct( $url );
		$this->redirect_url = $url;
	}
}

/**
 * Exercises BindingsPage::handle_save() via reflection so we can assert
 * the validation surface introduced in 2.0.1:
 *  - ACF field_key required + name-vs-key match (Tier 5)
 *  - Cross-kind dedup blocks save (Tier 4 revised)
 *  - Validation errors flash form state instead of redirecting away
 */
class BindingsPageTest extends \WP_UnitTestCase {

	private BindingsPage $page;
	private int $admin_id;

	public function set_up(): void {
		parent::set_up();

		delete_option( OptionKeys::BINDINGS );
		Capabilities::register( true );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$_POST   = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Wipe any prior flash from earlier tests.
		delete_transient( 'spintax_binding_form_flash_' . $this->admin_id );

		$this->page = new BindingsPage();
	}

	public function tear_down(): void {
		delete_transient( 'spintax_binding_form_flash_' . $this->admin_id );
		$_POST = array();
		parent::tear_down();
	}

	private function call_handle_save(): array {
		$reflection = new \ReflectionMethod( BindingsPage::class, 'handle_save' );
		$reflection->setAccessible( true );
		return $reflection->invoke( $this->page );
	}

	private function fill_post_with_valid_meta_binding(): void {
		$tpl_id = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'X',
			)
		);
		$_POST = array(
			'binding_id'             => '',
			'spintax_post_type'      => 'post',
			'status'                 => 'any',
			'target_kind'            => 'post_meta',
			'target_key'             => 'my_field',
			'target_field_key'       => '',
			'source_mode'            => 'template',
			'source_template_id'     => (string) $tpl_id,
			'trigger_save_post'      => '1',
			'trigger_cron'           => 'disabled',
			'behavior_auto_seed_empty' => '1',
		);
	}

	public function test_create_succeeds_with_valid_post_meta_binding(): void {
		$this->fill_post_with_valid_meta_binding();
		$result = $this->call_handle_save();
		$this->assertSame( 'success', $result['type'], $result['message'] );
		$this->assertSame( 1, ( new BindingsRepo() )->count() );
	}

	public function test_missing_post_type_flashes_form_state(): void {
		$this->fill_post_with_valid_meta_binding();
		$_POST['spintax_post_type'] = '';

		$result = $this->call_handle_save();

		$this->assertSame( 'error', $result['type'] );
		$this->assertSame( 'new', $result['form_redirect_action'] );

		$flash = get_transient( 'spintax_binding_form_flash_' . $this->admin_id );
		$this->assertIsArray( $flash );
		$this->assertSame( 'my_field', $flash['data']['target']['key'] );
		$this->assertSame( 'post_meta', $flash['data']['target']['kind'] );
	}

	public function test_acf_target_without_field_key_is_rejected(): void {
		$this->fill_post_with_valid_meta_binding();
		$_POST['target_kind']      = 'acf_field';
		$_POST['target_field_key'] = '';

		$result = $this->call_handle_save();

		$this->assertSame( 'error', $result['type'] );
		$this->assertStringContainsString( 'ACF field key is required', $result['message'] );

		// Form state preserved.
		$flash = get_transient( 'spintax_binding_form_flash_' . $this->admin_id );
		$this->assertSame( 'acf_field', $flash['data']['target']['kind'] );
		$this->assertSame( 'my_field', $flash['data']['target']['key'] );
	}

	public function test_save_validation_error_redirects_back_to_form_not_list(): void {
		$this->fill_post_with_valid_meta_binding();
		$_POST['target_key'] = ''; // missing required field.

		$result = $this->call_handle_save();

		$this->assertSame( 'error', $result['type'] );
		$this->assertArrayHasKey( 'form_redirect_action', $result, 'error result must signal form-redirect (spec §4.8.1)' );
		$this->assertSame( 'new', $result['form_redirect_action'] );
	}

	public function test_update_validation_error_redirects_back_to_edit_form(): void {
		// Seed a saved binding.
		$repo    = new BindingsRepo();
		$tpl_id  = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'Y',
			)
		);
		$binding = $repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'k1', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
				'triggers'  => array( 'save_post' => true ),
			)
		);

		$this->fill_post_with_valid_meta_binding();
		$_POST['binding_id']         = $binding['id'];
		$_POST['source_template_id'] = '0';
		$_POST['source_mode']        = 'template';

		$result = $this->call_handle_save();

		$this->assertSame( 'error', $result['type'] );
		$this->assertSame( 'edit', $result['form_redirect_action'] );
		$this->assertSame( $binding['id'], $result['existing_id'] );
	}

	public function test_create_blocks_cross_kind_duplicate_at_save_layer(): void {
		// Seed an existing acf_field binding for ('post', 'hero_text').
		$repo    = new BindingsRepo();
		$tpl_id  = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'Z',
			)
		);
		$repo->create(
			array(
				'post_type' => 'post',
				'target'    => array(
					'kind'      => 'acf_field',
					'key'       => 'hero_text',
					'field_key' => 'field_xxx',
				),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
				'triggers'  => array( 'save_post' => true ),
			)
		);

		$this->fill_post_with_valid_meta_binding();
		$_POST['target_kind']      = 'post_meta';
		$_POST['target_key']       = 'hero_text';
		$_POST['target_field_key'] = '';

		$result = $this->call_handle_save();

		$this->assertSame( 'error', $result['type'], 'cross-kind duplicate must be rejected (Tier 4 revised)' );
		$this->assertStringContainsString( 'Another binding already targets this field', $result['message'] );
	}

	public function test_bulk_apply_notice_links_to_logs_page(): void {
		$repo   = new BindingsRepo();
		$tpl_id = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'X',
			)
		);
		$binding = $repo->create(
			array(
				'post_type' => 'post',
				'target'    => array(
					'kind'      => 'post_meta',
					'key'       => 'my_field',
					'field_key' => '',
				),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
				'triggers'  => array( 'save_post' => true ),
			)
		);

		// Stub BulkApply so the bulk-apply branch in handle_actions
		// short-circuits to success WITHOUT touching Action Scheduler
		// (the tests-cli container doesn't ship AS).
		$stub_bulk = new class( $repo ) extends BulkApply {
			public function enqueue( string $binding_id ) {
				return true;
			}
		};
		$page_under_test = new BindingsPage( $repo, $stub_bulk );

		$nonce = wp_create_nonce( 'spintax_bulk_apply_' . $binding['id'] );
		$_POST = array(
			'binding_id'         => $binding['id'],
			'spintax_bulk_apply' => '1',
			'_wpnonce'           => $nonce,
		);
		// `check_admin_referer()` reads `$_REQUEST['_wpnonce']`, not
		// `$_POST['_wpnonce']`; PHP's `request_order` doesn't always
		// auto-populate $_REQUEST in the CLI test runner.
		$_REQUEST['_wpnonce'] = $nonce;

		// Intercept the wp_safe_redirect → wp_redirect filter so the
		// trait's `exit;` after wp_safe_redirect doesn't kill PHPUnit.
		$redirect_filter = static function ( $location ) {
			throw new BindingsPageRedirect( (string) $location );
		};
		add_filter( 'wp_redirect', $redirect_filter, 1, 1 );

		try {
			$page_under_test->handle_actions();
			$this->fail( 'handle_actions() must trigger a redirect for Bulk Apply.' );
		} catch ( BindingsPageRedirect $e ) {
			// Expected.
		} finally {
			remove_filter( 'wp_redirect', $redirect_filter, 1 );
		}

		$flash = get_transient( 'spintax_admin_notice_' . $this->admin_id );
		$this->assertIsArray( $flash );
		$this->assertSame( 'success', $flash['type'] );
		$this->assertIsArray( $flash['payload'] );
		$this->assertSame( 'Bulk Apply enqueued.', $flash['payload']['text'] );
		$this->assertStringContainsString( 'page=spintax-logs', (string) $flash['payload']['action_url'] );
		$this->assertSame( 'View progress in Logs →', $flash['payload']['action_label'] );

		delete_transient( 'spintax_admin_notice_' . $this->admin_id );
	}

	public function test_run_now_button_visible_when_debug_or_no_action_scheduler(): void {
		$repo    = new BindingsRepo();
		$tpl_id  = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'X',
			)
		);
		$repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'k', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
				'triggers'  => array( 'save_post' => true ),
			)
		);

		// AS isn't loaded in tests-cli → run_now_available() returns true
		// regardless of debug flag.
		$_GET = array();

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="spintax_bulk_apply_now"', $html );
		$this->assertStringContainsString( 'Run now', $html );
	}

	public function test_walk_status_badge_renders_when_lock_held(): void {
		$repo    = new BindingsRepo();
		$tpl_id  = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'X',
			)
		);
		$binding = $repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'k', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
				'triggers'  => array( 'save_post' => true ),
			)
		);

		// Fresh lock — 30 seconds ago.
		update_option(
			OptionKeys::OPTION_BINDING_WALK_LOCK_PREFIX . $binding['id'],
			time() - 30,
			false
		);

		$_GET = array();

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'spintax-binding-walk-badge', $html );
		$this->assertMatchesRegularExpression( '/Running \(started \d+s ago\)/', $html );
	}

	public function test_walk_status_badge_hidden_when_lock_orphaned(): void {
		$repo    = new BindingsRepo();
		$tpl_id  = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'X',
			)
		);
		$binding = $repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'k', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
				'triggers'  => array( 'save_post' => true ),
			)
		);

		// Orphaned lock — older than 1 hour. Card must NOT show "Running".
		update_option(
			OptionKeys::OPTION_BINDING_WALK_LOCK_PREFIX . $binding['id'],
			time() - 7200,
			false
		);

		$_GET = array();

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'spintax-binding-walk-badge', $html );
	}

	public function test_run_now_handler_rejects_non_admin(): void {
		$repo    = new BindingsRepo();
		$tpl_id  = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'X',
			)
		);
		$binding = $repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'k', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
				'triggers'  => array( 'save_post' => true ),
			)
		);

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$nonce                = wp_create_nonce( 'spintax_bulk_apply_now_' . $binding['id'] );
		$_POST                = array(
			'binding_id'             => $binding['id'],
			'spintax_bulk_apply_now' => '1',
			'_wpnonce'               => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		$captured        = '';
		$redirect_filter = static function ( $location ) use ( &$captured ) {
			$captured = (string) $location;
			throw new BindingsPageRedirect( (string) $location );
		};
		add_filter( 'wp_redirect', $redirect_filter, 1, 1 );

		try {
			$this->page->handle_actions();
		} catch ( BindingsPageRedirect $e ) {
			// expected
		} finally {
			remove_filter( 'wp_redirect', $redirect_filter, 1 );
		}

		// The editor never reaches `run_synchronously()`, so target meta
		// must be untouched and no per-binding lock should be in place.
		// These hold regardless of which capability gate (CPT-level vs.
		// manage_options) short-circuits first.
		$this->assertSame(
			0,
			(int) get_option( \Spintax\Support\OptionKeys::OPTION_BINDING_WALK_LOCK_PREFIX . $binding['id'], 0 ),
			'editor must not have acquired the per-binding walk lock'
		);

		$flash = get_transient( 'spintax_admin_notice_' . $editor_id );
		if ( false !== $flash ) {
			$payload = isset( $flash['payload'] ) ? (array) $flash['payload'] : array();
			$this->assertStringNotContainsString(
				'Wrote',
				(string) ( $payload['text'] ?? '' ),
				'editor must NOT see a "Wrote N skipped M failed K" success flash'
			);
		}

		delete_transient( 'spintax_admin_notice_' . $editor_id );
	}

	public function test_stale_banner_renders_when_persisted_binding_is_stale(): void {
		$repo    = new BindingsRepo();
		$tpl_id  = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'X',
			)
		);
		$binding = $repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'k', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
				'triggers'  => array( 'save_post' => true ),
			)
		);

		// Bump cache_version > last_applied_version → is_stale = true.
		update_option( OptionKeys::OPTION_BINDING_CACHE_VERSION_PREFIX . $binding['id'], 5 );
		update_option( OptionKeys::OPTION_BINDING_LAST_APPLIED_VERSION_PREFIX . $binding['id'], 2 );

		$_GET = array( 'action' => 'edit', 'binding_id' => $binding['id'] );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'spintax-binding-stale-banner', $html );
		$this->assertStringContainsString( 'Source template edited since the last walk', $html );
		// Inline Bulk Apply form points at the same binding.
		$this->assertMatchesRegularExpression(
			'/name="binding_id" value="' . preg_quote( $binding['id'], '/' ) . '"/',
			$html
		);
	}

	public function test_stale_banner_offers_runnow_fallback_when_action_scheduler_missing(): void {
		// Reviewer P2 (2.1.1): without AS, the stale banner's primary
		// CTA was driving editors into the no_action_scheduler error
		// path. After the fix, Bulk Apply is disabled with the same
		// tooltip surfaced on the list view, and Run-now becomes the
		// primary action (caps gate already verified upstream).
		// The wp-env tests-cli container ships without Action Scheduler,
		// so action_scheduler_available() returns false naturally.
		$repo    = new BindingsRepo();
		$tpl_id  = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'X',
			)
		);
		$binding = $repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'k', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
				'triggers'  => array( 'save_post' => true ),
			)
		);
		update_option( OptionKeys::OPTION_BINDING_CACHE_VERSION_PREFIX . $binding['id'], 5 );
		update_option( OptionKeys::OPTION_BINDING_LAST_APPLIED_VERSION_PREFIX . $binding['id'], 2 );

		$_GET = array( 'action' => 'edit', 'binding_id' => $binding['id'] );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		// Isolate just the stale-banner block so the list-view's button
		// markup (which already pairs Bulk Apply with Run-now) can't
		// false-positive these assertions.
		$start = strpos( $html, 'spintax-binding-stale-banner' );
		$this->assertNotFalse( $start, 'stale banner must render' );
		$banner_html = substr( $html, $start, 2000 );

		// Bulk Apply present but disabled with the AS-missing tooltip.
		$this->assertMatchesRegularExpression(
			'/name="spintax_bulk_apply"[^>]*\bdisabled\b/',
			$banner_html,
			'Bulk Apply must be disabled when AS is missing'
		);
		$this->assertStringContainsString( 'Action Scheduler is not installed', $banner_html );

		// Run-now present and primary (admin in set_up has manage_options
		// and run_now_available() returns true when AS is missing).
		$this->assertMatchesRegularExpression(
			'/name="spintax_bulk_apply_now"[^>]*class="button button-primary"/',
			$banner_html,
			'Run-now must be the primary CTA when AS is missing'
		);
	}

	public function test_stale_banner_uses_persisted_source_mode_not_flash_draft(): void {
		// Reviewer P2: render_form merges flash over persisted; the stale
		// banner must still reflect the persisted source.mode, not the
		// draft. Build a stale template-mode binding, then flash a
		// per_post-mode draft. is_stale() reads option keys keyed on the
		// PERSISTED template-mode setup, so the banner must still render.
		$repo    = new BindingsRepo();
		$tpl_id  = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'X',
			)
		);
		$binding = $repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'k', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
				'triggers'  => array( 'save_post' => true ),
			)
		);
		update_option( OptionKeys::OPTION_BINDING_CACHE_VERSION_PREFIX . $binding['id'], 5 );
		update_option( OptionKeys::OPTION_BINDING_LAST_APPLIED_VERSION_PREFIX . $binding['id'], 2 );

		// Trip a validation error so the flash carries a per_post draft.
		$this->fill_post_with_valid_meta_binding();
		$_POST['binding_id']         = $binding['id'];
		$_POST['source_mode']        = 'per_post';
		$_POST['source_template_id'] = '0'; // forces "per_post" path validation only.
		$_POST['target_key']         = ''; // trigger an error so flash is set.
		$this->call_handle_save();

		$_GET = array( 'action' => 'edit', 'binding_id' => $binding['id'] );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		// Banner must STILL render based on persisted template-mode state.
		$this->assertStringContainsString( 'spintax-binding-stale-banner', $html );
	}

	public function test_stale_banner_hidden_when_binding_is_fresh(): void {
		$repo    = new BindingsRepo();
		$tpl_id  = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'X',
			)
		);
		$binding = $repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'k', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
				'triggers'  => array( 'save_post' => true ),
			)
		);

		$_GET = array( 'action' => 'edit', 'binding_id' => $binding['id'] );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'spintax-binding-stale-banner', $html );
	}

	public function test_trigger_warning_visible_when_no_triggers(): void {
		$repo    = new BindingsRepo();
		$tpl_id  = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'X',
			)
		);
		// Persist via raw option update — the repo's validators would
		// normally reject a no-trigger binding, but the form needs to
		// surface the warning if one somehow ends up in that state.
		// Binding id must match Validators::is_valid_binding_id() pattern
		// `bind_[a-z0-9]{6}` — render() skips loading otherwise.
		update_option(
			OptionKeys::BINDINGS,
			array(
				'bind_no0run' => array(
					'id'        => 'bind_no0run',
					'post_type' => 'post',
					'status'    => 'any',
					'target'    => array( 'kind' => 'post_meta', 'key' => 'k', 'field_key' => '' ),
					'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
					'triggers'  => array( 'save_post' => false, 'cron' => 'disabled' ),
					'variables' => array( 'expose_post_context' => false, 'expose_acf_siblings' => false, 'overrides' => '' ),
					'behavior'  => array(
						'auto_seed_empty'       => true,
						'regenerate_on_save'    => false,
						'preserve_manual_edits' => true,
						'clear_on_empty'        => false,
						'chunk_size'            => 20,
					),
				),
			),
			false
		);

		$_GET = array( 'action' => 'edit', 'binding_id' => 'bind_no0run' );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		// Warning element rendered without the `hidden` attribute.
		$this->assertMatchesRegularExpression(
			'/<div\s+class="spintax-trigger-warning[^"]*"(?![^>]*hidden)/',
			$html
		);
		$this->assertStringContainsString( 'This binding will never run', $html );
	}

	public function test_trigger_warning_copy_matches_server_rejection(): void {
		// Reviewer P2: the inline warning must not promise that the save
		// will succeed (handle_save() rejects the no-triggers case). The
		// copy needs to align with the server-side rejection.
		update_option(
			OptionKeys::BINDINGS,
			array(
				'bind_no0run' => array(
					'id'        => 'bind_no0run',
					'post_type' => 'post',
					'status'    => 'any',
					'target'    => array( 'kind' => 'post_meta', 'key' => 'k', 'field_key' => '' ),
					'source'    => array( 'mode' => 'template', 'template_id' => 0 ),
					'triggers'  => array( 'save_post' => false, 'cron' => 'disabled' ),
					'variables' => array( 'expose_post_context' => false, 'expose_acf_siblings' => false, 'overrides' => '' ),
					'behavior'  => array(
						'auto_seed_empty'       => true,
						'regenerate_on_save'    => false,
						'preserve_manual_edits' => true,
						'clear_on_empty'        => false,
						'chunk_size'            => 20,
					),
				),
			),
			false
		);

		$_GET = array( 'action' => 'edit', 'binding_id' => 'bind_no0run' );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Save will be rejected', $html );
		// Old contradictory copy is gone.
		$this->assertStringNotContainsString( 'saves will still create the binding', $html );
	}

	public function test_action_scheduler_notice_mentions_run_now_alongside_cli(): void {
		if ( BulkApply::action_scheduler_available() ) {
			$this->markTestSkipped( 'AS notice only renders when Action Scheduler is missing.' );
		}

		$_GET = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		// Reviewer P2: the notice was steering users at the CLI fallback
		// while 2.1.0 exposes a `Run now` button in the admin UI. Notice
		// copy must now surface both paths.
		$this->assertStringContainsString( 'Run now', $html );
		$this->assertStringContainsString( 'wp spintax bindings apply', $html );
	}

	public function test_trigger_warning_hidden_when_save_post_on(): void {
		$_GET = array( 'action' => 'new' );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		// Defaults::binding() ships save_post=true → warning hidden.
		$this->assertMatchesRegularExpression(
			'/<div\s+class="spintax-trigger-warning[^"]*"[^>]*hidden/',
			$html
		);
	}

	public function test_form_renders_acf_combobox_when_kind_is_acf_field(): void {
		$repo    = new BindingsRepo();
		$tpl_id  = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'X',
			)
		);
		$binding = $repo->create(
			array(
				'post_type' => 'post',
				'target'    => array(
					'kind'      => 'acf_field',
					'key'       => 'hero_text',
					'field_key' => 'field_abc',
				),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
				'triggers'  => array( 'save_post' => true ),
			)
		);

		$_GET = array( 'action' => 'edit', 'binding_id' => $binding['id'] );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		// Combobox container visible; plain text input hidden.
		$this->assertMatchesRegularExpression(
			'/<div class="spintax-acf-combobox"[^>]*>(?![^<]*hidden)/',
			$html
		);
		$this->assertStringContainsString( 'id="spintax-acf-combobox-input"', $html );
		$this->assertStringContainsString( 'role="combobox"', $html );
		$this->assertStringContainsString( 'aria-autocomplete="list"', $html );

		// Plain target_key input gets hidden when kind=acf_field.
		$this->assertMatchesRegularExpression(
			'/<input[^>]*id="spintax-target-key"[^>]*hidden/',
			$html
		);

		// ACF field key row is visible — combobox autofills it.
		$this->assertMatchesRegularExpression(
			'/<tr class="spintax-target-field-key-row"(?![^>]*hidden)/',
			$html
		);

		// Display value seeded with "name (field_key)" for the picked field.
		$this->assertMatchesRegularExpression(
			'/id="spintax-acf-combobox-input"[^>]*value="hero_text \(field_abc\)"/',
			$html
		);
	}

	public function test_form_hides_combobox_and_field_key_row_when_kind_is_post_meta(): void {
		$repo    = new BindingsRepo();
		$tpl_id  = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'X',
			)
		);
		$binding = $repo->create(
			array(
				'post_type' => 'post',
				'target'    => array(
					'kind'      => 'post_meta',
					'key'       => 'my_field',
					'field_key' => '',
				),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
				'triggers'  => array( 'save_post' => true ),
			)
		);

		$_GET = array( 'action' => 'edit', 'binding_id' => $binding['id'] );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		// Combobox container must be hidden when persisted kind=post_meta —
		// this is server-side B5 (no flash before JS toggles).
		$this->assertMatchesRegularExpression(
			'/<div class="spintax-acf-combobox"[^>]*hidden/',
			$html
		);
		// ACF field key row hidden (no field-key concept for post_meta).
		$this->assertMatchesRegularExpression(
			'/<tr class="spintax-target-field-key-row"[^>]*hidden/',
			$html
		);
		// Plain target_key text input remains visible.
		$this->assertMatchesRegularExpression(
			'/<input[^>]*id="spintax-target-key"(?![^>]*hidden)/',
			$html
		);
	}

	public function test_form_renders_tabs_with_aria_attributes(): void {
		$_GET = array( 'action' => 'new' );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="spintax-binding-tabs"', $html );
		$this->assertStringContainsString( 'role="tablist"', $html );
		$this->assertStringContainsString( 'id="spintax-tab-source-target"', $html );
		$this->assertStringContainsString( 'id="spintax-tab-behavior"', $html );
		// Test tab only appears in the edit view; new-binding form omits it.
		$this->assertStringNotContainsString( 'id="spintax-tab-test"', $html );

		// Source & Target is default-active; Behavior must be hidden.
		$this->assertMatchesRegularExpression(
			'/id="spintax-tab-source-target"[^>]*aria-selected="true"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/id="spintax-tab-behavior"[^>]*aria-selected="false"/',
			$html
		);

		// Hidden input carries the active_tab for POST round-trip.
		$this->assertStringContainsString( 'name="active_tab"', $html );
	}

	public function test_form_renders_test_tab_when_editing(): void {
		$repo    = new BindingsRepo();
		$tpl_id  = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'X',
			)
		);
		$binding = $repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'k', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
				'triggers'  => array( 'save_post' => true ),
			)
		);

		$_GET = array( 'action' => 'edit', 'binding_id' => $binding['id'] );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="spintax-tab-test"', $html );
		$this->assertStringContainsString( 'id="spintax-panel-test"', $html );
	}

	public function test_form_activates_tab_from_query_arg(): void {
		$_GET = array( 'action' => 'new', 'active_tab' => 'behavior' );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/id="spintax-tab-behavior"[^>]*aria-selected="true"/',
			$html
		);
		// Source & Target panel must be hidden when Behavior is active.
		$this->assertMatchesRegularExpression(
			'/id="spintax-panel-source-target"[^>]*hidden/',
			$html
		);
	}

	public function test_form_falls_back_to_default_tab_when_query_invalid(): void {
		$_GET = array( 'action' => 'new', 'active_tab' => 'malicious-anywhere' );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/id="spintax-tab-source-target"[^>]*aria-selected="true"/',
			$html
		);
	}

	public function test_trigger_validation_error_routes_to_behavior_tab(): void {
		$this->fill_post_with_valid_meta_binding();
		// Both triggers off → "binding will never run" error.
		$_POST['trigger_save_post'] = '';
		$_POST['trigger_cron']      = 'disabled';

		$result = $this->call_handle_save();

		$this->assertSame( 'error', $result['type'] );
		$this->assertSame( BindingsPage::TAB_BEHAVIOR, $result['active_tab'] );
	}

	public function test_target_validation_error_routes_to_source_target_tab(): void {
		$this->fill_post_with_valid_meta_binding();
		$_POST['target_key'] = '';

		$result = $this->call_handle_save();

		$this->assertSame( 'error', $result['type'] );
		$this->assertSame( BindingsPage::TAB_SOURCE_TARGET, $result['active_tab'] );
	}

	public function test_flashed_active_tab_round_trips_into_form(): void {
		$this->fill_post_with_valid_meta_binding();
		// Trigger a Behavior-tab validation error so the flash carries
		// active_tab = 'behavior'.
		$_POST['trigger_save_post'] = '';
		$_POST['trigger_cron']      = 'disabled';

		$this->call_handle_save();

		$flash = get_transient( 'spintax_binding_form_flash_' . $this->admin_id );
		$this->assertSame( BindingsPage::TAB_BEHAVIOR, $flash['active_tab'] );

		// Render the form WITHOUT an `active_tab` query param — the flash
		// should be the source of truth.
		$_GET = array( 'action' => 'new' );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/id="spintax-tab-behavior"[^>]*aria-selected="true"/',
			$html
		);
	}

	public function test_form_drops_stale_phase3_copy(): void {
		$_GET = array( 'action' => 'new' );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		// Stale 2.0-era hint about a deferred feature — Phase 3 has
		// shipped, so the text was misleading.
		$this->assertStringNotContainsString( 'Phase 3 will add', $html );
		$this->assertStringNotContainsString( 'Phase 3 will autofill', $html );
	}

	public function test_form_replaces_dry_jargon_with_plain_copy(): void {
		$_GET = array( 'action' => 'new' );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'DRY across posts', $html );
		$this->assertStringContainsString( 'Shared template', $html );
	}

	public function test_action_scheduler_notice_hidden_when_dismissed(): void {
		if ( BulkApply::action_scheduler_available() ) {
			$this->markTestSkipped( 'AS notice only renders when Action Scheduler is missing.' );
		}

		update_user_meta(
			$this->admin_id,
			BindingsAjax::DISMISSED_NOTICE_META_PREFIX . 'as-v210',
			1
		);

		$_GET = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Action Scheduler is not installed', $html );
	}

	public function test_action_scheduler_notice_shows_when_not_dismissed(): void {
		if ( BulkApply::action_scheduler_available() ) {
			$this->markTestSkipped( 'AS notice only renders when Action Scheduler is missing.' );
		}

		// Fresh user_meta — notice should appear with the dismiss data attribute.
		$_GET = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Action Scheduler is not installed', $html );
		$this->assertStringContainsString( 'data-spintax-dismiss-notice="as-v210"', $html );
		$this->assertStringContainsString( 'is-dismissible', $html );
	}

	public function test_flashed_values_round_trip_back_into_form(): void {
		$this->fill_post_with_valid_meta_binding();
		$_POST['target_key'] = ''; // trip validation.
		$_POST['variables_overrides'] = "#set %tone% = bold\n";

		$this->call_handle_save();

		$flash = get_transient( 'spintax_binding_form_flash_' . $this->admin_id );
		$this->assertIsArray( $flash );
		$this->assertStringContainsString( '#set %tone% = bold', $flash['data']['variables']['overrides'] );

		// Now simulate consume_form_flash via reflection to verify it
		// reads and clears the same transient render_form() relies on.
		$reflection = new \ReflectionMethod( BindingsPage::class, 'consume_form_flash' );
		$reflection->setAccessible( true );

		$consumed = $reflection->invoke( $this->page );
		$this->assertIsArray( $consumed );
		$this->assertSame( $flash['data'], $consumed['data'] );

		// Second invocation must return null — transient was deleted.
		$this->assertNull( $reflection->invoke( $this->page ) );
	}

	// =========================================================================
	// First-error precedence — the reason Phase 2 deferred `TargetKind::validate_save`
	// =========================================================================
	//
	// Save validation runs in a fixed order: kind-agnostic reserved-key guard (Tiers 1-3) →
	// post type → empty key → the kind's own `validate_save()`. Phase 3 moved the ACF field-key
	// check out of the page and behind the registry, and the whole risk of doing so was that the
	// FIRST message an editor sees would silently change. These lock the order, per kind.

	public function test_reserved_key_guard_outranks_the_missing_post_type_error(): void {
		$this->fill_post_with_valid_meta_binding();
		$_POST['target_key']        = '_wp_page_template'; // Tier 1: WordPress-internal.
		$_POST['spintax_post_type'] = '';                  // Also invalid, but it is checked later.

		$result = $this->call_handle_save();

		$this->assertSame( 'error', $result['type'] );
		$this->assertStringContainsString( 'WordPress-internal meta key', $result['message'] );
	}

	public function test_wp_posts_column_guard_stays_ahead_of_the_kind_check(): void {
		// Tier 3 lives in the kind-agnostic guard, NOT in PostMetaTarget::validate_save. Moving it
		// into the target would push it behind the empty-key check and change what the editor sees
		// first. Proven by leaving the post type empty: the column error must still win.
		$this->fill_post_with_valid_meta_binding();
		$_POST['target_key']        = 'post_title';
		$_POST['spintax_post_type'] = '';

		$result = $this->call_handle_save();

		$this->assertSame( 'error', $result['type'] );
		$this->assertStringContainsString( 'wp_posts column', $result['message'] );
	}

	public function test_empty_key_error_outranks_the_acf_field_key_error(): void {
		$this->fill_post_with_valid_meta_binding();
		$_POST['target_kind']      = 'acf_field';
		$_POST['target_key']       = '';
		$_POST['target_field_key'] = ''; // Both are wrong; the generic message must come first.

		$result = $this->call_handle_save();

		$this->assertSame( 'error', $result['type'] );
		$this->assertStringContainsString( 'Target field key is required', $result['message'] );
		$this->assertStringNotContainsString( 'ACF field key', $result['message'] );
	}

	public function test_post_meta_target_raises_no_kind_specific_save_error(): void {
		// The registry now dispatches a `validate_save()` for every kind. post_meta's returns null,
		// and this proves the dispatch did not invent an error where there was none.
		$this->fill_post_with_valid_meta_binding();

		$result = $this->call_handle_save();

		$this->assertSame( 'success', $result['type'], $result['message'] );
	}
}
