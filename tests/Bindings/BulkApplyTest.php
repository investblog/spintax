<?php

namespace Spintax\Tests\Bindings;

use Spintax\Bindings\BindingsRepo;
use Spintax\Bindings\BulkApply;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Support\OptionKeys;

class BulkApplyTest extends \WP_UnitTestCase {

	private BindingsRepo $repo;
	private int $template_id;

	public function set_up(): void {
		parent::set_up();
		delete_option( OptionKeys::BINDINGS );

		// Strip global save_post listeners so fixture creation doesn't
		// rewrite the target meta out from under us.
		remove_all_actions( 'save_post' );
		remove_all_actions( 'save_post_' . TemplatePostType::POST_TYPE );

		$this->repo        = new BindingsRepo();
		$this->template_id = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'Hello',
			)
		);
	}

	private function create_binding( array $overrides = array() ): array {
		$base = array_replace_recursive(
			array(
				'post_type' => 'post',
				'status'    => 'any',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'target_field', 'field_key' => '' ),
				'source'   => array( 'mode' => 'template', 'template_id' => $this->template_id ),
				'triggers' => array( 'save_post' => true, 'cron' => 'disabled' ),
				'variables' => array( 'expose_post_context' => false ),
				'behavior' => array(
					'auto_seed_empty'       => true,
					'regenerate_on_save'    => false,
					'preserve_manual_edits' => true,
					'clear_on_empty'        => false,
					'chunk_size'            => 5,
				),
			),
			$overrides
		);
		return $this->repo->create( $base );
	}

	public function test_run_synchronously_processes_all_matching_posts(): void {
		$binding = $this->create_binding();

		// Create 7 posts (more than chunk_size=5 to verify chunking).
		$ids = self::factory()->post->create_many( 7 );

		$totals = ( new BulkApply( $this->repo ) )->run_synchronously( $binding['id'] );

		$this->assertIsArray( $totals );
		$this->assertSame( 7, $totals['wrote'], 'all empty targets seeded' );
		$this->assertSame( 0, $totals['skipped'] );
		$this->assertSame( 0, $totals['failed'] );

		foreach ( $ids as $id ) {
			$this->assertSame( 'Hello', get_post_meta( $id, 'target_field', true ) );
		}
	}

	public function test_run_synchronously_skips_when_target_filled(): void {
		$binding = $this->create_binding();

		$id = self::factory()->post->create();
		update_post_meta( $id, 'target_field', 'pre-existing' );

		$totals = ( new BulkApply( $this->repo ) )->run_synchronously( $binding['id'] );

		$this->assertSame( 0, $totals['wrote'] );
		$this->assertSame( 1, $totals['skipped'] );
		$this->assertSame( 'pre-existing', get_post_meta( $id, 'target_field', true ) );
	}

	public function test_run_synchronously_respects_status_filter(): void {
		$binding = $this->create_binding( array( 'status' => 'publish' ) );

		$pub  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		( new BulkApply( $this->repo ) )->run_synchronously( $binding['id'] );

		$this->assertSame( 'Hello', get_post_meta( $pub, 'target_field', true ) );
		$this->assertSame( '', get_post_meta( $draft, 'target_field', true ) );
	}

	public function test_run_synchronously_stamps_last_applied_version(): void {
		$binding = $this->create_binding();

		// Pre-bump the cache version (simulates a template edit).
		update_option( OptionKeys::OPTION_BINDING_CACHE_VERSION_PREFIX . $binding['id'], 3 );

		self::factory()->post->create_many( 3 );

		( new BulkApply( $this->repo ) )->run_synchronously( $binding['id'] );

		$stamp = (int) get_option( OptionKeys::OPTION_BINDING_LAST_APPLIED_VERSION_PREFIX . $binding['id'], 0 );
		$this->assertSame( 3, $stamp );
	}

	public function test_enqueue_returns_wp_error_when_action_scheduler_unavailable(): void {
		$binding = $this->create_binding();

		if ( BulkApply::action_scheduler_available() ) {
			$this->markTestSkipped( 'Action Scheduler is loaded; cannot exercise the fallback branch.' );
		}

		$result = ( new BulkApply( $this->repo ) )->enqueue( $binding['id'] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_action_scheduler', $result->get_error_code() );
	}

	public function test_enqueue_returns_wp_error_for_unknown_binding(): void {
		$result = ( new BulkApply( $this->repo ) )->enqueue( 'bind_zzzzzz' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'spintax_bindings_not_found', $result->get_error_code() );
	}
}
