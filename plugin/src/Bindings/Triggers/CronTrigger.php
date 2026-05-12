<?php
/**
 * Per-binding cron trigger.
 *
 * @package Spintax
 */

namespace Spintax\Bindings\Triggers;

defined( 'ABSPATH' ) || exit;

use Spintax\Bindings\BindingsRepo;
use Spintax\Bindings\BulkApply;
use Spintax\Bindings\Defaults;

/**
 * Registers a per-binding WP-Cron schedule and dispatches it through
 * Bulk Apply when the schedule fires.
 *
 * Hook layout (spec §4.7):
 *  - cron schedule slug comes from `binding.triggers.cron`
 *    (off | hourly | twicedaily | daily — alias `disabled` accepted)
 *  - each binding gets its own hook `spintax_binding_cron_<id>`
 *    so individual bindings can be scheduled / unscheduled without
 *    touching the others
 *  - the callback enqueues a Bulk Apply walk via Action Scheduler
 *    when available, or runs the walk inline as a fallback
 *
 * Call `sync_schedule( $binding )` after any binding insert / update /
 * delete to bring the WP-Cron state in line with the binding config.
 */
class CronTrigger {

	/**
	 * Bindings repository.
	 *
	 * @var BindingsRepo
	 */
	private BindingsRepo $repo;

	/**
	 * Bulk Apply runner.
	 *
	 * @var BulkApply
	 */
	private BulkApply $bulk;

	/**
	 * Constructor.
	 *
	 * @param BindingsRepo|null $repo Bindings repository.
	 * @param BulkApply|null    $bulk Bulk Apply runner.
	 */
	public function __construct( ?BindingsRepo $repo = null, ?BulkApply $bulk = null ) {
		$this->repo = $repo ?? new BindingsRepo();
		$this->bulk = $bulk ?? new BulkApply( $this->repo );
	}

	/**
	 * Register a fire-hook for every binding currently in the store.
	 *
	 * Each binding listens on its own hook so unscheduling one does not
	 * affect the others.
	 */
	public function init(): void {
		foreach ( $this->repo->all() as $binding ) {
			$id = (string) ( $binding['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			add_action(
				$this->hook_name( $id ),
				function () use ( $id ): void {
					$this->fire( $id );
				}
			);
		}

		// Keep WP-Cron in sync with binding mutations.
		add_action( 'spintax_binding_saved', array( $this, 'sync_schedule' ) );
		add_action( 'spintax_binding_deleted', array( $this, 'unschedule' ) );
	}

	/**
	 * Bring the WP-Cron event for a binding in line with its config.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 */
	public function sync_schedule( array $binding ): void {
		$id = (string) ( $binding['id'] ?? '' );
		if ( '' === $id ) {
			return;
		}

		$schedule = $this->normalise_schedule( (string) ( $binding['triggers']['cron'] ?? 'disabled' ) );
		$hook     = $this->hook_name( $id );

		if ( null === $schedule ) {
			wp_clear_scheduled_hook( $hook );
			return;
		}

		$existing = wp_get_scheduled_event( $hook );
		if ( $existing && $existing->schedule === $schedule ) {
			return; // already on the right cadence.
		}

		wp_clear_scheduled_hook( $hook );
		wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, $hook );
	}

	/**
	 * Unschedule the WP-Cron event for a binding being deleted.
	 *
	 * @param string $binding_id Binding id.
	 */
	public function unschedule( string $binding_id ): void {
		if ( '' === $binding_id ) {
			return;
		}
		wp_clear_scheduled_hook( $this->hook_name( $binding_id ) );
	}

	/**
	 * Run the binding's cron payload — enqueue a Bulk Apply walk when
	 * Action Scheduler is available, otherwise run synchronously.
	 *
	 * @param string $binding_id Binding id.
	 */
	public function fire( string $binding_id ): void {
		if ( BulkApply::action_scheduler_available() ) {
			$this->bulk->enqueue( $binding_id );
			return;
		}
		$this->bulk->run_synchronously( $binding_id );
	}

	/**
	 * WP-Cron hook name for this binding.
	 *
	 * @param string $binding_id Binding id.
	 */
	private function hook_name( string $binding_id ): string {
		return 'spintax_binding_cron_' . $binding_id;
	}

	/**
	 * Translate the form-level cron value into a WP-Cron recurrence slug
	 * (or null when scheduling should be off).
	 *
	 * @param string $value Form value.
	 */
	private function normalise_schedule( string $value ): ?string {
		if ( in_array( $value, array( '', 'off', 'disabled' ), true ) ) {
			return null;
		}
		$allowed = array_filter(
			Defaults::cron_schedules(),
			static fn( string $v ): bool => 'disabled' !== $v
		);
		return in_array( $value, $allowed, true ) ? $value : null;
	}
}
