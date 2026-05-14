<?php

namespace Spintax\Tests\Bindings;

use Spintax\Bindings\BindingsRepo;
use Spintax\Bindings\BulkApply;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Support\Logging;
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

		( new Logging() )->clear();
		$totals = ( new BulkApply( $this->repo ) )->run_synchronously( $binding['id'] );

		$this->assertIsArray( $totals );
		$this->assertSame( 7, $totals['wrote'], 'all empty targets seeded' );
		$this->assertSame( 0, $totals['skipped'] );
		$this->assertSame( 0, $totals['failed'] );

		foreach ( $ids as $id ) {
			$this->assertSame( 'Hello', get_post_meta( $id, 'target_field', true ) );
		}

		// Clean run must write the info log line (2.1.1 P2 #1). Without
		// this assertion, a future refactor of run_synchronously() could
		// silently delete the log call and the "View details in Logs →"
		// CTA on the Run-now success notice would land on a Logs page
		// missing the corresponding completion record.
		$messages = array_map(
			static fn( $entry ) => (string) ( $entry['msg'] ?? '' ),
			( new Logging() )->all()
		);
		$expected = sprintf(
			'Bulk Apply run_synchronously completed for binding %s — wrote=7 skipped=0 cleared=0.',
			$binding['id']
		);
		$this->assertContains( $expected, $messages, 'run_synchronously must log on clean completion' );
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

	public function test_run_synchronously_does_not_stamp_when_a_post_fails(): void {
		$binding = $this->create_binding();

		update_option( OptionKeys::OPTION_BINDING_CACHE_VERSION_PREFIX . $binding['id'], 5 );

		self::factory()->post->create_many( 3 );

		// Drop in an applier that throws for every post — simulates the
		// "some posts failed" branch. The walk should NOT stamp the
		// last-applied version (Stale badge stays on; spec §4.10).
		$throwing_applier = new class extends \Spintax\Bindings\BindingApplier {
			public function apply( array $binding, int $post_id ): string {
				throw new \RuntimeException( 'simulated render failure' );
			}
		};

		$totals = ( new BulkApply( $this->repo, $throwing_applier ) )->run_synchronously( $binding['id'] );

		$this->assertSame( 3, $totals['failed'] );
		$this->assertSame( 0, $totals['wrote'] );

		$stamp = (int) get_option( OptionKeys::OPTION_BINDING_LAST_APPLIED_VERSION_PREFIX . $binding['id'], 0 );
		$this->assertSame( 0, $stamp, 'last-applied stamp must stay at 0 when any post failed' );
	}

	public function test_handle_action_callback_does_not_stamp_when_failures_in_final_chunk(): void {
		$binding = $this->create_binding( array( 'behavior' => array( 'chunk_size' => 50 ) ) );

		update_option( OptionKeys::OPTION_BINDING_CACHE_VERSION_PREFIX . $binding['id'], 9 );

		self::factory()->post->create_many( 2 );

		$throwing_applier = new class extends \Spintax\Bindings\BindingApplier {
			public function apply( array $binding, int $post_id ): string {
				throw new \RuntimeException( 'boom' );
			}
		};

		// chunk_size (50) > posts (2) → single chunk, hits the
		// "final chunk" branch. Failures > 0 → must NOT stamp.
		( new BulkApply( $this->repo, $throwing_applier ) )->handle( $binding['id'], 0, 50 );

		$stamp = (int) get_option( OptionKeys::OPTION_BINDING_LAST_APPLIED_VERSION_PREFIX . $binding['id'], 0 );
		$this->assertSame( 0, $stamp );
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

	// ----- Multi-chunk cumulative failure tracking (added in 2.0.3) -----

	public function test_handle_does_not_stamp_when_failures_in_earlier_chunk(): void {
		$binding = $this->create_binding( array( 'behavior' => array( 'chunk_size' => 2 ) ) );

		update_option( OptionKeys::OPTION_BINDING_CACHE_VERSION_PREFIX . $binding['id'], 7 );

		// 3 posts so chunk 1 (size 2) fills, chunk 2 (size 1 < chunk_size)
		// is recognised as final.
		self::factory()->post->create_many( 3 );

		// Applier throws for the first two posts (chunk 1), succeeds for the third (chunk 2).
		$applier = new class() extends \Spintax\Bindings\BindingApplier {
			public int $calls = 0;
			public function apply( array $binding, int $post_id ): string {
				++$this->calls;
				if ( $this->calls <= 2 ) {
					throw new \RuntimeException( 'chunk-1 failure' );
				}
				return \Spintax\Bindings\BindingApplier::WROTE_SEEDED;
			}
		};

		$bulk = new BulkApply( $this->repo, $applier );

		// Chunk 1 — 2 failures recorded in the cumulative flag.
		$bulk->handle( $binding['id'], 0, 2 );
		$this->assertSame(
			1,
			(int) get_option( OptionKeys::OPTION_BINDING_WALK_FAILED_PREFIX . $binding['id'], 0 ),
			'chunk 1 failure must set the cumulative flag'
		);

		// Chunk 2 (final, only 1 post) — 0 failures locally, but cumulative flag still set.
		$bulk->handle( $binding['id'], 2, 2 );

		$stamp = (int) get_option( OptionKeys::OPTION_BINDING_LAST_APPLIED_VERSION_PREFIX . $binding['id'], 0 );
		$this->assertSame(
			0,
			$stamp,
			'last-applied stamp must NOT be set when an earlier chunk failed (spec §4.10, 2.0.3)'
		);

		// Final chunk cleans up: cumulative flag is gone.
		$this->assertSame( '', (string) get_option( OptionKeys::OPTION_BINDING_WALK_FAILED_PREFIX . $binding['id'], '' ) );
	}

	public function test_handle_clean_walk_clears_failure_flag_and_stamps(): void {
		$binding = $this->create_binding( array( 'behavior' => array( 'chunk_size' => 5 ) ) );

		update_option( OptionKeys::OPTION_BINDING_CACHE_VERSION_PREFIX . $binding['id'], 4 );
		// Pre-existing stale flag from a previous failed walk — must be cleared.
		update_option( OptionKeys::OPTION_BINDING_WALK_FAILED_PREFIX . $binding['id'], 1 );

		self::factory()->post->create_many( 3 );

		( new BulkApply( $this->repo ) )->handle( $binding['id'], 0, 5 );

		// Clean walk: stamps + clears the cumulative flag.
		$this->assertSame( 4, (int) get_option( OptionKeys::OPTION_BINDING_LAST_APPLIED_VERSION_PREFIX . $binding['id'], 0 ) );
		$this->assertSame( '', (string) get_option( OptionKeys::OPTION_BINDING_WALK_FAILED_PREFIX . $binding['id'], '' ) );
	}

	// ----- Walk lock (added in 2.0.3) -----

	public function test_enqueue_returns_walk_in_progress_when_lock_held(): void {
		$binding = $this->create_binding();

		if ( ! BulkApply::action_scheduler_available() ) {
			$this->markTestSkipped( 'Lock conflict only fires when AS is available; otherwise no_action_scheduler short-circuits earlier.' );
		}

		// Pretend a walk is in progress (recent lock).
		update_option(
			OptionKeys::OPTION_BINDING_WALK_LOCK_PREFIX . $binding['id'],
			time(),
			false
		);

		$result = ( new BulkApply( $this->repo ) )->enqueue( $binding['id'] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'walk_in_progress', $result->get_error_code() );
	}

	public function test_run_synchronously_returns_walk_in_progress_when_lock_held(): void {
		$binding = $this->create_binding();

		update_option(
			OptionKeys::OPTION_BINDING_WALK_LOCK_PREFIX . $binding['id'],
			time(),
			false
		);

		$result = ( new BulkApply( $this->repo ) )->run_synchronously( $binding['id'] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'walk_in_progress', $result->get_error_code() );
	}

	public function test_stale_lock_is_overwritten_after_one_hour(): void {
		$binding = $this->create_binding();

		// Stale lock more than 1 hour old — should be ignored.
		update_option(
			OptionKeys::OPTION_BINDING_WALK_LOCK_PREFIX . $binding['id'],
			time() - 7200,
			false
		);

		self::factory()->post->create_many( 2 );

		$result = ( new BulkApply( $this->repo ) )->run_synchronously( $binding['id'] );
		$this->assertIsArray( $result, 'stale lock must be overwritten' );
		$this->assertSame( 2, $result['wrote'] );
	}

	public function test_run_synchronously_releases_lock_after_completion(): void {
		$binding = $this->create_binding();
		self::factory()->post->create_many( 1 );

		( new BulkApply( $this->repo ) )->run_synchronously( $binding['id'] );

		// Lock cleared so a second walk can start.
		$this->assertSame( '', (string) get_option( OptionKeys::OPTION_BINDING_WALK_LOCK_PREFIX . $binding['id'], '' ) );

		// And actually start it to prove the option is gone, not just stale.
		$second = ( new BulkApply( $this->repo ) )->run_synchronously( $binding['id'] );
		$this->assertIsArray( $second );
	}
}
