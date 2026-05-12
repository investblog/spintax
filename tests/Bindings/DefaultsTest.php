<?php

namespace Spintax\Tests\Bindings;

use Spintax\Bindings\Defaults;

class DefaultsTest extends \WP_UnitTestCase {

	public function test_binding_has_expected_top_level_keys(): void {
		$d = Defaults::binding();
		$this->assertSame(
			array( 'post_type', 'status', 'target', 'source', 'variables', 'triggers', 'behavior' ),
			array_keys( $d )
		);
		$this->assertArrayNotHasKey( 'id', $d, 'id is stamped by the repo, not by Defaults' );
		$this->assertArrayNotHasKey( 'created_at', $d );
		$this->assertArrayNotHasKey( 'updated_at', $d );
	}

	public function test_safe_defaults_for_behavior(): void {
		$d = Defaults::binding();
		$this->assertTrue( $d['behavior']['auto_seed_empty'], 'auto_seed_empty must default ON to avoid surprise overwrites' );
		$this->assertFalse( $d['behavior']['regenerate_on_save'], 'regenerate_on_save must default OFF' );
		$this->assertTrue( $d['behavior']['preserve_manual_edits'], 'preserve_manual_edits must default ON' );
		$this->assertFalse( $d['behavior']['clear_on_empty'] );
	}

	public function test_triggers_pin_acf_save_post_off_for_v1(): void {
		$d = Defaults::binding();
		$this->assertTrue( $d['triggers']['save_post'] );
		$this->assertFalse( $d['triggers']['acf_save_post'], 'V1 ignores acf_save_post; field is reserved for V2' );
		$this->assertSame( 'disabled', $d['triggers']['cron'] );
	}

	public function test_target_starts_as_acf_field_with_blank_field_key(): void {
		$d = Defaults::binding();
		$this->assertSame( 'acf_field', $d['target']['kind'] );
		$this->assertSame( '', $d['target']['key'] );
		$this->assertSame( '', $d['target']['field_key'] );
	}

	public function test_source_starts_as_template_mode(): void {
		$d = Defaults::binding();
		$this->assertSame( 'template', $d['source']['mode'] );
		$this->assertSame( 0, $d['source']['template_id'] );
	}

	public function test_enum_helpers_return_known_values(): void {
		$this->assertSame( array( 'acf_field', 'post_meta' ), Defaults::target_kinds() );
		$this->assertSame( array( 'template', 'per_post' ), Defaults::source_modes() );
		$this->assertSame( array( 'any', 'publish' ), Defaults::statuses() );
		$this->assertSame( array( 'disabled', 'hourly', 'twicedaily', 'daily' ), Defaults::cron_schedules() );
	}

	public function test_max_bindings_cap_is_two_hundred(): void {
		$this->assertSame( 200, Defaults::MAX_BINDINGS );
	}
}
