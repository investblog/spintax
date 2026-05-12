<?php
/**
 * Bulk Apply runner for bindings.
 *
 * @package Spintax
 */

namespace Spintax\Bindings;

defined( 'ABSPATH' ) || exit;

use Spintax\Support\Logging;
use Spintax\Support\OptionKeys;
use WP_Error;
use WP_Query;

/**
 * Walks every matching post for a binding in Action Scheduler chunks
 * and applies the binding to each. Falls back to a clear admin notice
 * + WP-CLI hint when Action Scheduler isn't installed.
 *
 * Per spec §4.10 + §9 Phase 4 acceptance:
 *  - chunks size comes from binding.behavior.chunk_size (clamped by Repo).
 *  - per-post writes go through BindingApplier::apply so all four
 *    behavior flags (auto_seed_empty, regenerate_on_save,
 *    preserve_manual_edits, clear_on_empty) are honoured.
 *  - bumps `_spintax_binding_last_applied_v_<id>` to the current
 *    `_spintax_binding_cache_v_<id>` value on each successful chunk so
 *    the binding card can show a Stale badge when versions diverge.
 *  - progress lines logged to `Spintax\Support\Logging`.
 *
 * Walk lifecycle (added in 2.0.3, spec §4.10):
 *  - `enqueue` and `run_synchronously` acquire a per-binding lock at
 *    walk start. Concurrent walks (admin double-click, admin+cron
 *    overlap) return `WP_Error 'walk_in_progress'` instead of racing.
 *  - Any chunk that records a failed post sets a cumulative
 *    "had failures" flag in option `_spintax_binding_walk_failed_v_<id>`.
 *  - The final chunk gates `stamp_last_applied_version()` on the
 *    cumulative flag, not just the current chunk's count.
 *  - Both the lock and the cumulative flag are cleared when the walk
 *    finishes (clean or otherwise). Stale locks older than 1h are
 *    overwritten automatically by the next walk-start.
 */
class BulkApply {

	public const ACTION = 'spintax_bindings_bulk_apply';

	/**
	 * Stale-lock cutoff in seconds — older locks are treated as
	 * orphaned (crashed walk / PHP timeout) and overwritten.
	 *
	 * @var int
	 */
	private const LOCK_TTL_SECONDS = 3600;

	/**
	 * Bindings repository.
	 *
	 * @var BindingsRepo
	 */
	private BindingsRepo $repo;

	/**
	 * Decision-tree applier.
	 *
	 * @var BindingApplier
	 */
	private BindingApplier $applier;

	/**
	 * Constructor.
	 *
	 * @param BindingsRepo|null   $repo    Bindings repository.
	 * @param BindingApplier|null $applier Decision-tree applier.
	 */
	public function __construct( ?BindingsRepo $repo = null, ?BindingApplier $applier = null ) {
		$this->repo    = $repo ?? new BindingsRepo();
		$this->applier = $applier ?? new BindingApplier();
	}

	/**
	 * Register the Action Scheduler handler.
	 */
	public function init(): void {
		add_action( self::ACTION, array( $this, 'handle' ), 10, 3 );
	}

	/**
	 * Whether Action Scheduler is available in this environment.
	 */
	public static function action_scheduler_available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Enqueue a Bulk Apply walk for a binding.
	 *
	 * Returns WP_Error:
	 *  - `spintax_bindings_not_found` — unknown binding id.
	 *  - `no_action_scheduler` — AS unavailable; surface notice steering
	 *    the user at the WP-CLI fallback.
	 *  - `walk_in_progress` (2.0.3) — a walk is already running for this
	 *    binding. The lock auto-clears after `LOCK_TTL_SECONDS` so the
	 *    user only sees this for genuine overlap.
	 *
	 * @param string $binding_id Binding id.
	 * @return true|WP_Error
	 */
	public function enqueue( string $binding_id ) {
		$binding = $this->repo->find( $binding_id );
		if ( null === $binding ) {
			return new WP_Error( 'spintax_bindings_not_found', __( 'Binding not found.', 'spintax' ) );
		}
		if ( ! self::action_scheduler_available() ) {
			return new WP_Error(
				'no_action_scheduler',
				__( 'Action Scheduler is not installed. Run `wp spintax bindings apply --binding=<id> --all` from the CLI instead.', 'spintax' )
			);
		}
		if ( ! $this->acquire_lock( $binding_id ) ) {
			return new WP_Error(
				'walk_in_progress',
				__( 'A Bulk Apply walk for this binding is already running. Wait for it to finish (or up to one hour for a stale lock to expire) before retrying.', 'spintax' )
			);
		}

		$this->reset_walk_state( $binding_id );

		$chunk_size = $this->chunk_size( $binding );
		as_enqueue_async_action(
			self::ACTION,
			array(
				'binding_id' => $binding_id,
				'offset'     => 0,
				'chunk_size' => $chunk_size,
			),
			'spintax'
		);

		( new Logging() )->push(
			'info',
			'Bulk Apply enqueued for binding ' . $binding_id . ' (chunk_size=' . $chunk_size . ')'
		);

		return true;
	}

	/**
	 * Process a chunk and re-enqueue while more posts remain.
	 *
	 * Hooked as the Action Scheduler callback for `BulkApply::ACTION`.
	 *
	 * @param string $binding_id Binding id.
	 * @param int    $offset     Walk offset.
	 * @param int    $chunk_size Chunk size.
	 */
	public function handle( string $binding_id, int $offset, int $chunk_size ): void {
		$binding = $this->repo->find( $binding_id );
		if ( null === $binding ) {
			( new Logging() )->push( 'warning', 'Bulk Apply: binding ' . $binding_id . ' missing — aborting.' );
			$this->finalise_walk( $binding_id, /* stamp */ false );
			return;
		}

		// First chunk of the walk → wipe any stale cumulative-failure flag
		// left over from a crashed prior walk. `enqueue()` already does
		// this when it acquires the lock, but `handle()` is also called
		// directly (tests, custom dispatchers) so we duplicate the reset
		// here to guarantee a clean slate at offset 0.
		if ( 0 === $offset ) {
			$this->reset_walk_state( $binding_id );
		}

		$post_ids = $this->query_chunk( $binding, $offset, $chunk_size );
		if ( empty( $post_ids ) ) {
			// No more posts — either nothing matched at offset 0, or the
			// walk drained between chunks. Treat as walk-end either way:
			// stamp only when no chunk recorded a failure across the walk.
			$this->finalise_walk( $binding_id, ! $this->walk_had_failures( $binding_id ) );
			( new Logging() )->push( 'info', 'Bulk Apply completed for binding ' . $binding_id );
			return;
		}

		$counts = array(
			'wrote'   => 0,
			'skipped' => 0,
			'failed'  => 0,
			'cleared' => 0,
		);

		foreach ( $post_ids as $post_id ) {
			try {
				$result = $this->applier->apply( $binding, (int) $post_id );
			} catch ( \Throwable $e ) {
				++$counts['failed'];
				( new Logging() )->push( 'error', 'Bulk Apply post ' . $post_id . ' binding ' . $binding_id . ': ' . $e->getMessage() );
				continue;
			}

			if ( 0 === strpos( $result, 'wrote_' ) ) {
				++$counts['wrote'];
				if ( BindingApplier::WROTE_EMPTY === $result ) {
					++$counts['cleared'];
				}
			} else {
				++$counts['skipped'];
			}
		}

		$this->record_chunk_failures( $binding_id, $counts['failed'] );

		( new Logging() )->push(
			'info',
			sprintf(
				'Bulk Apply chunk binding=%s offset=%d size=%d wrote=%d skipped=%d failed=%d cleared=%d',
				$binding_id,
				$offset,
				count( $post_ids ),
				$counts['wrote'],
				$counts['skipped'],
				$counts['failed'],
				$counts['cleared']
			)
		);

		if ( count( $post_ids ) < $chunk_size ) {
			// Final chunk. Gate the stamp on the cumulative failure flag
			// (spec §4.10 revised in 2.0.3) — any earlier chunk with a
			// failed post leaves the Stale badge in place, even if this
			// final chunk was clean.
			$had_failures = $this->walk_had_failures( $binding_id );
			if ( ! $had_failures ) {
				$this->finalise_walk( $binding_id, /* stamp */ true );
				( new Logging() )->push( 'info', 'Bulk Apply completed for binding ' . $binding_id );
			} else {
				$this->finalise_walk( $binding_id, /* stamp */ false );
				( new Logging() )->push(
					'warning',
					sprintf(
						'Bulk Apply completed for binding %s with failures somewhere in the walk — Stale badge NOT cleared, retry the binding.',
						$binding_id
					)
				);
			}
			return;
		}

		// More posts remain — enqueue the next chunk.
		if ( self::action_scheduler_available() ) {
			as_enqueue_async_action(
				self::ACTION,
				array(
					'binding_id' => $binding_id,
					'offset'     => $offset + $chunk_size,
					'chunk_size' => $chunk_size,
				),
				'spintax'
			);
		}
	}

	/**
	 * Apply the binding to every matching post in this process, no AS.
	 *
	 * Used by `wp spintax bindings apply --binding=<id> --all` and by
	 * test code that wants synchronous behaviour. Honours the same
	 * decision-tree flags but does not log per-chunk telemetry — the
	 * caller is responsible for that surface.
	 *
	 * Like `enqueue()`, holds the walk-lock for the duration of the walk
	 * and refuses to start if another walk is already in flight on the
	 * same binding (spec §4.10, 2.0.3).
	 *
	 * @param string $binding_id Binding id.
	 * @return array{wrote:int, skipped:int, failed:int, cleared:int}|WP_Error
	 * @throws \Throwable Re-thrown from `BindingApplier::apply()` or `query_chunk()` after walk state is finalised so the lock does not dangle on an unexpected exception.
	 */
	public function run_synchronously( string $binding_id ) {
		$binding = $this->repo->find( $binding_id );
		if ( null === $binding ) {
			return new WP_Error( 'spintax_bindings_not_found', __( 'Binding not found.', 'spintax' ) );
		}
		if ( ! $this->acquire_lock( $binding_id ) ) {
			return new WP_Error(
				'walk_in_progress',
				__( 'A Bulk Apply walk for this binding is already running. Wait for it to finish (or up to one hour for a stale lock to expire) before retrying.', 'spintax' )
			);
		}

		$this->reset_walk_state( $binding_id );

		$totals = array(
			'wrote'   => 0,
			'skipped' => 0,
			'failed'  => 0,
			'cleared' => 0,
		);

		$chunk_size = $this->chunk_size( $binding );
		$offset     = 0;

		try {
			while ( true ) {
				$post_ids = $this->query_chunk( $binding, $offset, $chunk_size );
				if ( empty( $post_ids ) ) {
					break;
				}
				foreach ( $post_ids as $post_id ) {
					try {
						$result = $this->applier->apply( $binding, (int) $post_id );
					} catch ( \Throwable $e ) {
						++$totals['failed'];
						continue;
					}
					if ( 0 === strpos( $result, 'wrote_' ) ) {
						++$totals['wrote'];
						if ( BindingApplier::WROTE_EMPTY === $result ) {
							++$totals['cleared'];
						}
					} else {
						++$totals['skipped'];
					}
				}
				if ( count( $post_ids ) < $chunk_size ) {
					break;
				}
				$offset += $chunk_size;
			}

			// Same gating policy as `handle()` (spec §4.10): only clear
			// the Stale badge when the entire walk was clean. The
			// cumulative flag is unused here because `$totals` is
			// in-process — but we set it anyway when failures occur so
			// observers (test code, external monitors) reading the
			// option see consistent state with the AS path.
			if ( $totals['failed'] > 0 ) {
				$this->record_chunk_failures( $binding_id, $totals['failed'] );
				( new Logging() )->push(
					'warning',
					sprintf(
						'Bulk Apply run_synchronously: binding %s had %d failures — Stale badge NOT cleared.',
						$binding_id,
						$totals['failed']
					)
				);
				$this->finalise_walk( $binding_id, /* stamp */ false );
			} else {
				$this->finalise_walk( $binding_id, /* stamp */ true );
			}
		} catch ( \Throwable $e ) {
			// Defensive: never leave a lock dangling on an unexpected
			// throw from query_chunk or instrumentation. The walk is
			// flagged as failed so the Stale badge persists.
			$this->record_chunk_failures( $binding_id, 1 );
			$this->finalise_walk( $binding_id, /* stamp */ false );
			throw $e;
		}

		return $totals;
	}

	// --- Walk state helpers (added in 2.0.3, spec §4.10) ---

	/**
	 * Try to acquire the per-binding walk lock.
	 *
	 * Returns false if a lock newer than `LOCK_TTL_SECONDS` is in place;
	 * stale locks (presumably orphaned from a crashed walk) get
	 * overwritten and the call succeeds.
	 *
	 * @param string $binding_id Binding id.
	 */
	private function acquire_lock( string $binding_id ): bool {
		$key  = OptionKeys::OPTION_BINDING_WALK_LOCK_PREFIX . $binding_id;
		$prev = (int) get_option( $key, 0 );
		if ( $prev > 0 && ( time() - $prev ) < self::LOCK_TTL_SECONDS ) {
			return false;
		}
		update_option( $key, time(), false );
		return true;
	}

	/**
	 * Release the per-binding walk lock and clear the cumulative-failure
	 * flag. Called from `finalise_walk`.
	 *
	 * @param string $binding_id Binding id.
	 */
	private function release_lock( string $binding_id ): void {
		delete_option( OptionKeys::OPTION_BINDING_WALK_LOCK_PREFIX . $binding_id );
	}

	/**
	 * Persist a cumulative-failure flag for the in-progress walk.
	 *
	 * @param string $binding_id Binding id.
	 * @param int    $failed     Number of failed posts in this chunk.
	 */
	private function record_chunk_failures( string $binding_id, int $failed ): void {
		if ( $failed > 0 ) {
			update_option(
				OptionKeys::OPTION_BINDING_WALK_FAILED_PREFIX . $binding_id,
				1,
				false
			);
		}
	}

	/**
	 * Did any chunk in the current walk record a failure?
	 *
	 * @param string $binding_id Binding id.
	 */
	private function walk_had_failures( string $binding_id ): bool {
		return 1 === (int) get_option( OptionKeys::OPTION_BINDING_WALK_FAILED_PREFIX . $binding_id, 0 );
	}

	/**
	 * Wipe the cumulative-failure flag (called at walk start and end).
	 *
	 * @param string $binding_id Binding id.
	 */
	private function reset_walk_state( string $binding_id ): void {
		delete_option( OptionKeys::OPTION_BINDING_WALK_FAILED_PREFIX . $binding_id );
	}

	/**
	 * Close out a walk: optionally stamp the last-applied-version
	 * (clears Stale badge), then release the lock and clear the
	 * cumulative-failure flag.
	 *
	 * @param string $binding_id Binding id.
	 * @param bool   $stamp      Stamp the last-applied-version option.
	 */
	private function finalise_walk( string $binding_id, bool $stamp ): void {
		if ( $stamp ) {
			$this->stamp_last_applied_version( $binding_id );
		}
		$this->release_lock( $binding_id );
		$this->reset_walk_state( $binding_id );
	}

	/**
	 * Resolve the effective chunk size for a binding.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 */
	private function chunk_size( array $binding ): int {
		$size = (int) ( $binding['behavior']['chunk_size'] ?? Defaults::DEFAULT_CHUNK_SIZE );
		if ( $size < Defaults::MIN_CHUNK_SIZE ) {
			$size = Defaults::DEFAULT_CHUNK_SIZE;
		}
		return min( Defaults::MAX_CHUNK_SIZE, $size );
	}

	/**
	 * Query a chunk of post ids matching the binding's scope.
	 *
	 * @param array<string, mixed> $binding    Binding payload.
	 * @param int                  $offset     Walk offset.
	 * @param int                  $chunk_size Chunk size.
	 * @return int[]
	 */
	private function query_chunk( array $binding, int $offset, int $chunk_size ): array {
		$args = array(
			'post_type'      => (string) ( $binding['post_type'] ?? '' ),
			'posts_per_page' => $chunk_size,
			'offset'         => $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		$status = (string) ( $binding['status'] ?? 'any' );
		if ( 'publish' === $status ) {
			$args['post_status'] = 'publish';
		} else {
			$args['post_status'] = array( 'publish', 'pending', 'draft', 'future', 'private' );
		}

		$query = new WP_Query( $args );
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Stamp `_spintax_binding_last_applied_v_<id>` to the current
	 * cache-version value so the Stale badge clears after a successful run.
	 *
	 * @param string $binding_id Binding id.
	 */
	private function stamp_last_applied_version( string $binding_id ): void {
		$current = (int) get_option( OptionKeys::OPTION_BINDING_CACHE_VERSION_PREFIX . $binding_id, 0 );
		update_option(
			OptionKeys::OPTION_BINDING_LAST_APPLIED_VERSION_PREFIX . $binding_id,
			$current,
			false
		);
	}
}
