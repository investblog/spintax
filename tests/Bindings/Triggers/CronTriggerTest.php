<?php

namespace Spintax\Tests\Bindings\Triggers;

use Spintax\Bindings\BindingsRepo;
use Spintax\Bindings\Triggers\CronTrigger;
use Spintax\Support\OptionKeys;

class CronTriggerTest extends \WP_UnitTestCase {

	private BindingsRepo $repo;
	private CronTrigger $trigger;

	public function set_up(): void {
		parent::set_up();
		delete_option( OptionKeys::BINDINGS );

		// Strip global hooks so factory fixtures don't trip cron scheduling.
		remove_all_actions( 'spintax_binding_saved' );
		remove_all_actions( 'spintax_binding_deleted' );

		$this->repo    = new BindingsRepo();
		$this->trigger = new CronTrigger( $this->repo );
	}

	private function hook_for( string $id ): string {
		return 'spintax_binding_cron_' . $id;
	}

	private function make_binding( string $cron = 'daily' ): array {
		return $this->repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'k', 'field_key' => '' ),
				'source'    => array( 'mode' => 'per_post' ),
				'triggers'  => array( 'save_post' => true, 'cron' => $cron ),
			)
		);
	}

	public function test_sync_schedule_registers_wp_cron_event(): void {
		$binding = $this->make_binding( 'daily' );
		$this->trigger->sync_schedule( $binding );

		$event = wp_get_scheduled_event( $this->hook_for( $binding['id'] ) );
		$this->assertNotFalse( $event );
		$this->assertSame( 'daily', $event->schedule );
	}

	public function test_sync_schedule_disabled_clears_existing_event(): void {
		$binding = $this->make_binding( 'daily' );
		$this->trigger->sync_schedule( $binding );
		$this->assertNotFalse( wp_get_scheduled_event( $this->hook_for( $binding['id'] ) ) );

		// Now flip to disabled and re-sync.
		$binding['triggers']['cron'] = 'disabled';
		$this->trigger->sync_schedule( $binding );

		$this->assertFalse( wp_get_scheduled_event( $this->hook_for( $binding['id'] ) ) );
	}

	public function test_sync_schedule_changes_cadence_without_duplicating(): void {
		$binding = $this->make_binding( 'hourly' );
		$this->trigger->sync_schedule( $binding );
		$this->assertSame( 'hourly', wp_get_scheduled_event( $this->hook_for( $binding['id'] ) )->schedule );

		$binding['triggers']['cron'] = 'twicedaily';
		$this->trigger->sync_schedule( $binding );

		$event = wp_get_scheduled_event( $this->hook_for( $binding['id'] ) );
		$this->assertSame( 'twicedaily', $event->schedule );

		// And confirm there's still exactly one event on that hook.
		$crons = _get_cron_array() ?: array();
		$count = 0;
		foreach ( $crons as $timestamp => $hooks ) {
			if ( isset( $hooks[ $this->hook_for( $binding['id'] ) ] ) ) {
				$count += count( $hooks[ $this->hook_for( $binding['id'] ) ] );
			}
		}
		$this->assertSame( 1, $count, 'sync_schedule must not duplicate cron events when changing cadence' );
	}

	public function test_unschedule_removes_event(): void {
		$binding = $this->make_binding( 'daily' );
		$this->trigger->sync_schedule( $binding );
		$this->assertNotFalse( wp_get_scheduled_event( $this->hook_for( $binding['id'] ) ) );

		$this->trigger->unschedule( $binding['id'] );

		$this->assertFalse( wp_get_scheduled_event( $this->hook_for( $binding['id'] ) ) );
	}

	public function test_init_subscribes_to_binding_lifecycle_actions(): void {
		$this->trigger->init();
		$this->assertNotFalse( has_action( 'spintax_binding_saved', array( $this->trigger, 'sync_schedule' ) ) );
		$this->assertNotFalse( has_action( 'spintax_binding_deleted', array( $this->trigger, 'unschedule' ) ) );
	}
}
