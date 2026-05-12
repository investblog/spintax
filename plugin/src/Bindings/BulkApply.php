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
 */
class BulkApply {

	public const ACTION = 'spintax_bindings_bulk_apply';

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
	 * Returns WP_Error 'no_action_scheduler' if AS isn't installed —
	 * callers should surface this as an admin notice pointing the user
	 * at the WP-CLI fallback (`wp spintax bindings apply --binding=<id> --all`).
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
			return;
		}

		$post_ids = $this->query_chunk( $binding, $offset, $chunk_size );
		if ( empty( $post_ids ) ) {
			$this->stamp_last_applied_version( $binding_id );
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
			$this->stamp_last_applied_version( $binding_id );
			( new Logging() )->push( 'info', 'Bulk Apply completed for binding ' . $binding_id );
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
	 * @param string $binding_id Binding id.
	 * @return array{wrote:int, skipped:int, failed:int, cleared:int}|WP_Error
	 */
	public function run_synchronously( string $binding_id ) {
		$binding = $this->repo->find( $binding_id );
		if ( null === $binding ) {
			return new WP_Error( 'spintax_bindings_not_found', __( 'Binding not found.', 'spintax' ) );
		}

		$totals = array(
			'wrote'   => 0,
			'skipped' => 0,
			'failed'  => 0,
			'cleared' => 0,
		);

		$chunk_size = $this->chunk_size( $binding );
		$offset     = 0;

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

		$this->stamp_last_applied_version( $binding_id );

		return $totals;
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
