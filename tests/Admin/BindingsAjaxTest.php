<?php

namespace Spintax\Tests\Admin;

use Spintax\Admin\BindingsAjax;
use Spintax\Bindings\BindingsRepo;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Support\Capabilities;
use Spintax\Support\OptionKeys;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

/**
 * Drives BindingsAjax endpoints through the WP_Ajax_UnitTestCase plumbing.
 */
class BindingsAjaxTest extends \WP_Ajax_UnitTestCase {

	private BindingsAjax $ajax;
	private int $admin_id;
	private int $subscriber_id;

	public function set_up(): void {
		parent::set_up();
		delete_option( OptionKeys::BINDINGS );

		// Reset the in-process object cache so transient-style
		// 5-minute caches don't leak between cases.
		wp_cache_flush();

		$this->ajax          = new BindingsAjax( new BindingsRepo() );
		$this->ajax->init();

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Make sure the admin has our custom capability.
		Capabilities::register( true );
	}

	private function set_nonce( string $action = 'spintax_admin' ): void {
		$_REQUEST['nonce'] = wp_create_nonce( $action );
		$_GET['nonce']     = $_REQUEST['nonce'];
		$_POST['nonce']    = $_REQUEST['nonce'];
	}

	/**
	 * Fire an AJAX action and decode the JSON envelope.
	 *
	 * @return array{success: bool, data: mixed}
	 */
	private function dispatch( string $action ): array {
		try {
			$this->_handleAjax( $action );
			$this->fail( 'Expected wp_send_json_* to throw.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// success path.
		} catch ( WPAjaxDieStopException $e ) {
			// error path with status code.
		}
		return json_decode( $this->_last_response, true );
	}

	// ----- meta_keys -----

	public function test_meta_keys_returns_distinct_keys_for_post_type(): void {
		wp_set_current_user( $this->admin_id );

		$p1 = self::factory()->post->create();
		$p2 = self::factory()->post->create();
		update_post_meta( $p1, 'subtitle', 'A' );
		update_post_meta( $p1, 'subtitle', 'A duplicate' );
		update_post_meta( $p2, 'subtitle', 'B' );
		update_post_meta( $p1, 'description', 'X' );

		$this->set_nonce();
		$_REQUEST['post_type'] = 'post';
		$_GET['post_type']     = 'post';

		$resp = $this->dispatch( 'spintax_binding_meta_keys' );

		$this->assertTrue( $resp['success'] );
		$names = array_column( $resp['data'], 'name' );
		$this->assertContains( 'subtitle', $names );
		$this->assertContains( 'description', $names );
		// SELECT DISTINCT — subtitle appears once.
		$this->assertSame( 1, count( array_filter( $names, fn( $n ) => 'subtitle' === $n ) ) );
	}

	public function test_meta_keys_filters_reserved_keys(): void {
		wp_set_current_user( $this->admin_id );

		$p = self::factory()->post->create();
		update_post_meta( $p, '_thumbnail_id', '1' );
		update_post_meta( $p, '_edit_lock', '1' );
		update_post_meta( $p, '_spintax_source_hero', 'tpl' );
		update_post_meta( $p, 'public_field', 'ok' );

		$this->set_nonce();
		$_REQUEST['post_type'] = 'post';
		$_GET['post_type']     = 'post';

		$resp  = $this->dispatch( 'spintax_binding_meta_keys' );
		$names = array_column( $resp['data'], 'name' );

		$this->assertContains( 'public_field', $names );
		$this->assertNotContains( '_thumbnail_id', $names );
		$this->assertNotContains( '_edit_lock', $names );
		$this->assertNotContains( '_spintax_source_hero', $names );
	}

	public function test_meta_keys_empty_for_unknown_post_type(): void {
		wp_set_current_user( $this->admin_id );

		$this->set_nonce();
		$_REQUEST['post_type'] = 'nope';
		$_GET['post_type']     = 'nope';

		$resp = $this->dispatch( 'spintax_binding_meta_keys' );

		$this->assertTrue( $resp['success'] );
		$this->assertSame( array(), $resp['data'] );
	}

	public function test_meta_keys_rejects_anonymous_user(): void {
		wp_set_current_user( $this->subscriber_id );

		$this->set_nonce();
		$_REQUEST['post_type'] = 'post';
		$_GET['post_type']     = 'post';

		$resp = $this->dispatch( 'spintax_binding_meta_keys' );

		$this->assertFalse( $resp['success'] );
	}

	// ----- acf_fields -----

	public function test_acf_fields_returns_empty_when_acf_inactive(): void {
		// ACF is not loaded in the test suite by default.
		if ( function_exists( 'acf_get_field_groups' ) ) {
			$this->markTestSkipped( 'ACF active in test suite; this branch tests graceful absence.' );
		}

		wp_set_current_user( $this->admin_id );

		$this->set_nonce();
		$_REQUEST['post_type'] = 'post';
		$_GET['post_type']     = 'post';

		$resp = $this->dispatch( 'spintax_binding_acf_fields' );

		$this->assertTrue( $resp['success'] );
		$this->assertSame( array(), $resp['data'] );
	}

	// ----- template_list -----

	public function test_template_list_returns_published_templates(): void {
		wp_set_current_user( $this->admin_id );

		wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Alpha',
				'post_name'    => 'alpha',
				'post_content' => 'A',
			)
		);
		wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => 'Beta',
				'post_content' => 'B',
			)
		);

		$this->set_nonce();

		$resp = $this->dispatch( 'spintax_binding_template_list' );

		$this->assertTrue( $resp['success'] );
		$titles = array_column( $resp['data'], 'title' );
		$this->assertContains( 'Alpha', $titles );
		$this->assertNotContains( 'Beta', $titles, 'drafts must not be returned' );
	}

	public function test_template_list_rejects_no_capability(): void {
		wp_set_current_user( $this->subscriber_id );

		$this->set_nonce();

		$resp = $this->dispatch( 'spintax_binding_template_list' );

		$this->assertFalse( $resp['success'] );
	}

	// ----- test_binding scope-filter parity (added in 2.0.1) -----

	private function create_test_binding(): array {
		$tpl_id = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl for test_binding',
				'post_content' => 'Rendered output',
			)
		);
		return ( new BindingsRepo() )->create(
			array(
				'post_type' => 'post',
				'status'    => 'any',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'target_field', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl_id ),
				'triggers'  => array( 'save_post' => true, 'cron' => 'disabled' ),
				'variables' => array( 'expose_post_context' => false ),
			)
		);
	}

	public function test_test_binding_reports_out_of_scope_for_wrong_post_type(): void {
		wp_set_current_user( $this->admin_id );

		$binding = $this->create_test_binding();
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->set_nonce();
		$_REQUEST['binding_id'] = $binding['id'];
		$_POST['binding_id']    = $binding['id'];
		$_REQUEST['post_id']    = $page_id;
		$_POST['post_id']       = $page_id;

		$resp = $this->dispatch( 'spintax_test_binding' );

		$this->assertTrue( $resp['success'] );
		$this->assertSame( 'skip_out_of_scope_type', $resp['data']['result'] );
		$this->assertFalse( $resp['data']['would_write'] );
	}

	public function test_test_binding_reports_out_of_scope_for_draft_under_publish_filter(): void {
		wp_set_current_user( $this->admin_id );

		$binding   = $this->create_test_binding();
		$bindings  = new BindingsRepo();
		$bindings->update( $binding['id'], array( 'status' => 'publish' ) );

		$draft_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
			)
		);

		$this->set_nonce();
		$_REQUEST['binding_id'] = $binding['id'];
		$_POST['binding_id']    = $binding['id'];
		$_REQUEST['post_id']    = $draft_id;
		$_POST['post_id']       = $draft_id;

		$resp = $this->dispatch( 'spintax_test_binding' );

		$this->assertTrue( $resp['success'] );
		$this->assertSame( 'skip_out_of_scope_status', $resp['data']['result'] );
		$this->assertFalse( $resp['data']['would_write'] );
	}

	public function test_test_binding_writes_plan_for_in_scope_post(): void {
		wp_set_current_user( $this->admin_id );

		// Create the post BEFORE the binding so the plugin's save_post
		// trigger (registered globally by the bootstrapper) doesn't
		// auto-seed the target meta as a side effect of creation. The
		// Test panel is supposed to be a dry-run.
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$binding = $this->create_test_binding();

		$this->set_nonce();
		$_REQUEST['binding_id'] = $binding['id'];
		$_POST['binding_id']    = $binding['id'];
		$_REQUEST['post_id']    = $post_id;
		$_POST['post_id']       = $post_id;

		$resp = $this->dispatch( 'spintax_test_binding' );

		$this->assertTrue( $resp['success'] );
		$this->assertSame( 'wrote_seeded', $resp['data']['result'] );
		$this->assertTrue( $resp['data']['would_write'] );
		$this->assertSame( 'Rendered output', $resp['data']['rendered_preview'] );
		// Crucially — test panel is a dry-run; the target stays empty.
		$this->assertSame( '', get_post_meta( $post_id, 'target_field', true ) );
	}

	// ----- test_binding preview permission gate (2.4.0 security review) -----
	//
	// The dry-run reflects the binding's RENDER back to the caller, and a render can pull in the
	// target's own data (post fields, ACF siblings, product data). So a `manage_spintax_templates`
	// holder must not be able to preview a post they could not otherwise view — a stock Editor has
	// the plugin CAP but cannot see another user's draft, or a draft product's fields. `read_post`
	// is the exact "may this user see THIS post's content" gate.

	/**
	 * A low-privilege user who holds the plugin capability but not broad editing rights — the shape
	 * of the threat (CAP is grantable to any role by an admin).
	 */
	private function cap_only_user(): int {
		$uid  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user = new \WP_User( $uid );
		$user->add_cap( Capabilities::CAP );
		return $uid;
	}

	public function test_test_binding_refuses_preview_of_a_post_the_user_cannot_read(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$draft_id  = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_author' => $author_id,
			)
		);
		$binding = $this->create_test_binding();

		wp_set_current_user( $this->cap_only_user() );
		$this->set_nonce();
		$_REQUEST['binding_id'] = $binding['id'];
		$_POST['binding_id']    = $binding['id'];
		$_REQUEST['post_id']    = $draft_id;
		$_POST['post_id']       = $draft_id;

		$resp = $this->dispatch( 'spintax_test_binding' );

		$this->assertFalse( $resp['success'] );
		$this->assertStringContainsString( 'permission to preview', $resp['data']['message'] );
		$this->assertArrayNotHasKey( 'rendered_preview', (array) $resp['data'], 'no rendered content may leak on refusal' );
	}

	public function test_test_binding_allows_preview_of_a_published_post_for_a_cap_user(): void {
		// A published post is public on the front end, so previewing it discloses nothing new.
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		$binding = $this->create_test_binding();

		wp_set_current_user( $this->cap_only_user() );
		$this->set_nonce();
		$_REQUEST['binding_id'] = $binding['id'];
		$_POST['binding_id']    = $binding['id'];
		$_REQUEST['post_id']    = $post_id;
		$_POST['post_id']       = $post_id;

		$resp = $this->dispatch( 'spintax_test_binding' );

		$this->assertTrue( $resp['success'] );
		$this->assertArrayHasKey( 'rendered_preview', $resp['data'] );
	}

	public function test_test_binding_still_lets_an_editor_preview_a_draft(): void {
		// The gate must not break the legitimate workflow: an editor who CAN edit the draft (here,
		// their own) still gets the preview. Only content they cannot view is refused.
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$draft_id  = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_author' => $editor_id,
			)
		);
		$binding = $this->create_test_binding();

		wp_set_current_user( $editor_id );
		$this->set_nonce();
		$_REQUEST['binding_id'] = $binding['id'];
		$_POST['binding_id']    = $binding['id'];
		$_REQUEST['post_id']    = $draft_id;
		$_POST['post_id']       = $draft_id;

		$resp = $this->dispatch( 'spintax_test_binding' );

		$this->assertTrue( $resp['success'] );
		$this->assertArrayHasKey( 'rendered_preview', $resp['data'] );
	}

	// ----- dismiss_admin_notice (added in 2.1.0) -----

	public function test_dismiss_admin_notice_writes_user_meta(): void {
		wp_set_current_user( $this->admin_id );

		$this->set_nonce();
		$_REQUEST['notice_id'] = 'as-v210';
		$_POST['notice_id']    = 'as-v210';

		$resp = $this->dispatch( 'spintax_dismiss_admin_notice' );

		$this->assertTrue( $resp['success'] );
		$this->assertSame( 'as-v210', $resp['data']['dismissed'] );
		$this->assertSame(
			'1',
			(string) get_user_meta( $this->admin_id, BindingsAjax::DISMISSED_NOTICE_META_PREFIX . 'as-v210', true )
		);
		$this->assertTrue( BindingsAjax::is_notice_dismissed( 'as-v210' ) );
	}

	public function test_dismiss_admin_notice_rejects_unknown_id(): void {
		wp_set_current_user( $this->admin_id );

		$this->set_nonce();
		$_REQUEST['notice_id'] = 'random-id';
		$_POST['notice_id']    = 'random-id';

		$resp = $this->dispatch( 'spintax_dismiss_admin_notice' );

		$this->assertFalse( $resp['success'] );
		// No user_meta written for the rejected id.
		$this->assertSame(
			'',
			(string) get_user_meta( $this->admin_id, BindingsAjax::DISMISSED_NOTICE_META_PREFIX . 'random-id', true )
		);
	}

	public function test_dismiss_admin_notice_requires_capability(): void {
		wp_set_current_user( $this->subscriber_id );

		$this->set_nonce();
		$_REQUEST['notice_id'] = 'as-v210';
		$_POST['notice_id']    = 'as-v210';

		$resp = $this->dispatch( 'spintax_dismiss_admin_notice' );

		$this->assertFalse( $resp['success'] );
		// Subscriber's user_meta must remain untouched.
		$this->assertSame(
			'',
			(string) get_user_meta( $this->subscriber_id, BindingsAjax::DISMISSED_NOTICE_META_PREFIX . 'as-v210', true )
		);
	}

	public function test_is_notice_dismissed_returns_false_for_unauthenticated_user(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( BindingsAjax::is_notice_dismissed( 'as-v210' ) );
	}
}
