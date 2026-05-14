<?php

namespace Spintax\Tests\Admin;

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
}
