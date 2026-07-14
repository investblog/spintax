<?php

namespace Spintax\Tests\Bindings\Plan;

use Spintax\Bindings\BindingApplier;
use Spintax\Bindings\Plan\PlanCode;

class PlanCodeTest extends \WP_UnitTestCase {

	public function test_all_returns_the_full_set_of_unique_codes(): void {
		$all = PlanCode::all();
		// 13 through 2.3.x; 15 since 2.4.0 added the two WooCommerce guards.
		$this->assertCount( 15, $all );
		$this->assertCount( 15, array_unique( $all ) );
	}

	public function test_is_write_only_for_write_codes(): void {
		$this->assertTrue( PlanCode::is_write( PlanCode::WROTE_SEEDED ) );
		$this->assertTrue( PlanCode::is_write( PlanCode::WROTE_REGENERATED ) );
		$this->assertTrue( PlanCode::is_write( PlanCode::WROTE_EMPTY ) );
		$this->assertFalse( PlanCode::is_write( PlanCode::SKIP_EMPTY_RENDER ) );
		$this->assertFalse( PlanCode::is_write( PlanCode::SKIP_SOURCE_NOT_FOUND ) );
		$this->assertFalse( PlanCode::is_write( PlanCode::SKIP_NO_WRITE_TRIGGER ) );
	}

	public function test_category_buckets(): void {
		$this->assertSame( 'write', PlanCode::category( PlanCode::WROTE_SEEDED ) );
		$this->assertSame( 'write', PlanCode::category( PlanCode::WROTE_EMPTY ) );
		$this->assertSame( 'blocked', PlanCode::category( PlanCode::SKIP_SOURCE_NOT_FOUND ) );
		$this->assertSame( 'blocked', PlanCode::category( PlanCode::SKIP_INVALID_ACF_FIELD ) );
		$this->assertSame( 'blocked', PlanCode::category( PlanCode::SKIP_ACF_NOT_LOADED ) );
		// The 2.4.0 WooCommerce guards. The spec makes this a contract clause — it drives the Bulk
		// Apply "N failed" telemetry and the stale-badge gate — so it is pinned, not just coded.
		$this->assertSame( 'blocked', PlanCode::category( PlanCode::SKIP_WC_NOT_LOADED ) );
		$this->assertSame( 'blocked', PlanCode::category( PlanCode::SKIP_INVALID_WC_FIELD ) );
		$this->assertSame( 'skip', PlanCode::category( PlanCode::SKIP_TARGET_NONEMPTY ) );
		$this->assertSame( 'skip', PlanCode::category( PlanCode::SKIP_MANUAL_EDIT_DETECTED ) );
		$this->assertSame( 'skip', PlanCode::category( PlanCode::SKIP_OUT_OF_SCOPE_TYPE ) );
	}

	/**
	 * The wire contract: BindingApplier's aliases must equal the PlanCode
	 * values byte-for-byte (logs / WP-CLI / telemetry depend on these strings).
	 */
	public function test_binding_applier_constants_alias_plan_codes(): void {
		$this->assertSame( PlanCode::WROTE_SEEDED, BindingApplier::WROTE_SEEDED );
		$this->assertSame( PlanCode::WROTE_REGENERATED, BindingApplier::WROTE_REGENERATED );
		$this->assertSame( PlanCode::WROTE_EMPTY, BindingApplier::WROTE_EMPTY );
		$this->assertSame( PlanCode::SKIP_MANUAL_EDIT_DETECTED, BindingApplier::SKIP_MANUAL_EDIT_DETECTED );
		$this->assertSame( PlanCode::SKIP_TARGET_NONEMPTY, BindingApplier::SKIP_TARGET_NONEMPTY );
		$this->assertSame( PlanCode::SKIP_EMPTY_RENDER, BindingApplier::SKIP_EMPTY_RENDER );
		$this->assertSame( PlanCode::SKIP_NO_WRITE_TRIGGER, BindingApplier::SKIP_NO_WRITE_TRIGGER );
		$this->assertSame( PlanCode::SKIP_SOURCE_NOT_FOUND, BindingApplier::SKIP_SOURCE_NOT_FOUND );
		$this->assertSame( PlanCode::SKIP_COLD_START_MANUAL, BindingApplier::SKIP_COLD_START_MANUAL );
		$this->assertSame( PlanCode::SKIP_OUT_OF_SCOPE_TYPE, BindingApplier::SKIP_OUT_OF_SCOPE_TYPE );
		$this->assertSame( PlanCode::SKIP_OUT_OF_SCOPE_STATUS, BindingApplier::SKIP_OUT_OF_SCOPE_STATUS );
		$this->assertSame( PlanCode::SKIP_ACF_NOT_LOADED, BindingApplier::SKIP_ACF_NOT_LOADED );
		$this->assertSame( PlanCode::SKIP_INVALID_ACF_FIELD, BindingApplier::SKIP_INVALID_ACF_FIELD );
		$this->assertSame( PlanCode::SKIP_WC_NOT_LOADED, BindingApplier::SKIP_WC_NOT_LOADED );
		$this->assertSame( PlanCode::SKIP_INVALID_WC_FIELD, BindingApplier::SKIP_INVALID_WC_FIELD );
	}
}
