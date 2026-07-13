<?php

namespace Spintax\Tests\Bindings\Plan;

use Spintax\Bindings\Plan\PlanCode;
use Spintax\Bindings\Plan\PlanInput;
use Spintax\Bindings\Plan\Planner;

/**
 * Table-driven lock on the pure decision. Every one of the 13 outcome codes is
 * reproduced from a crafted PlanInput — this is the safety net that guarantees
 * the 2.3.0 extraction preserved the historical decision tree exactly.
 */
class PlannerTest extends \WP_UnitTestCase {

	/**
	 * @return array<string, array{0: PlanInput, 1: string}>
	 */
	public static function planner_cases(): array {
		return array(
			// Scope-reject tier.
			'out_of_scope_type (no post)'                   => array( new PlanInput( post_exists: false ), PlanCode::SKIP_OUT_OF_SCOPE_TYPE ),
			'out_of_scope_type (type mismatch)'             => array( new PlanInput( post_type_matches: false ), PlanCode::SKIP_OUT_OF_SCOPE_TYPE ),
			'out_of_scope_status'                           => array( new PlanInput( status_in_scope: false ), PlanCode::SKIP_OUT_OF_SCOPE_STATUS ),
			'acf_not_loaded'                                => array( new PlanInput( target_runtime_valid: false, target_runtime_code: PlanCode::SKIP_ACF_NOT_LOADED ), PlanCode::SKIP_ACF_NOT_LOADED ),
			'invalid_acf_field'                             => array( new PlanInput( target_runtime_valid: false, target_runtime_code: PlanCode::SKIP_INVALID_ACF_FIELD ), PlanCode::SKIP_INVALID_ACF_FIELD ),
			'wc_not_loaded'                                 => array( new PlanInput( target_runtime_valid: false, target_runtime_code: PlanCode::SKIP_WC_NOT_LOADED ), PlanCode::SKIP_WC_NOT_LOADED ),
			'invalid_wc_field'                              => array( new PlanInput( target_runtime_valid: false, target_runtime_code: PlanCode::SKIP_INVALID_WC_FIELD ), PlanCode::SKIP_INVALID_WC_FIELD ),
			'source_not_found'                              => array( new PlanInput( source_found: false ), PlanCode::SKIP_SOURCE_NOT_FOUND ),
			// Path 1 (regenerate_on_save).
			'regen cold-start empty target → seeded'        => array( new PlanInput( regenerate_on_save: true, preserve_manual_edits: true, stored_signature: null, current_target: '', rendered: 'x' ), PlanCode::WROTE_SEEDED ),
			'regen cold-start nonempty → cold_start_manual' => array( new PlanInput( regenerate_on_save: true, preserve_manual_edits: true, stored_signature: null, current_target: 'existing', rendered: 'x' ), PlanCode::SKIP_COLD_START_MANUAL ),
			'regen manual edit detected'                    => array( new PlanInput( regenerate_on_save: true, preserve_manual_edits: true, stored_signature: 'staleHASH', current_target: 'edited', rendered: 'x' ), PlanCode::SKIP_MANUAL_EDIT_DETECTED ),
			'regen signature matches → regenerated'         => array( new PlanInput( regenerate_on_save: true, preserve_manual_edits: true, stored_signature: sha1( 'current' ), current_target: 'current', rendered: 'x' ), PlanCode::WROTE_REGENERATED ),
			'regen empty render + clear → wrote_empty'      => array( new PlanInput( regenerate_on_save: true, preserve_manual_edits: false, rendered: '', clear_on_empty: true, current_target: 'old' ), PlanCode::WROTE_EMPTY ),
			'regen empty render no clear → empty_render'    => array( new PlanInput( regenerate_on_save: true, preserve_manual_edits: false, rendered: '', clear_on_empty: false ), PlanCode::SKIP_EMPTY_RENDER ),
			'regen nonempty render → regenerated'           => array( new PlanInput( regenerate_on_save: true, preserve_manual_edits: false, rendered: 'x' ), PlanCode::WROTE_REGENERATED ),
			// Path 2 (auto_seed_empty).
			'auto-seed target nonempty → target_nonempty'   => array( new PlanInput( auto_seed_empty: true, current_target: 'existing', rendered: 'x' ), PlanCode::SKIP_TARGET_NONEMPTY ),
			'auto-seed empty target empty render'           => array( new PlanInput( auto_seed_empty: true, current_target: '', rendered: '' ), PlanCode::SKIP_EMPTY_RENDER ),
			'auto-seed empty target → seeded'               => array( new PlanInput( auto_seed_empty: true, current_target: '', rendered: 'x' ), PlanCode::WROTE_SEEDED ),
			// Path 3.
			'no write trigger'                              => array( new PlanInput( regenerate_on_save: false, auto_seed_empty: false ), PlanCode::SKIP_NO_WRITE_TRIGGER ),
		);
	}

	/**
	 * @dataProvider planner_cases
	 *
	 * @param PlanInput $input    Crafted facts.
	 * @param string    $expected Expected PlanCode.
	 */
	public function test_plan_returns_expected_code( PlanInput $input, string $expected ): void {
		$this->assertSame( $expected, ( new Planner() )->plan( $input ) );
	}

	public function test_scope_reject_null_when_all_pass(): void {
		$this->assertNull( ( new Planner() )->scope_reject( new PlanInput() ) );
	}

	public function test_scope_reject_prefers_type_over_later_gates(): void {
		// First failing gate wins: type reject beats a would-be status reject.
		$input = new PlanInput( post_exists: false, status_in_scope: false, source_found: false );
		$this->assertSame( PlanCode::SKIP_OUT_OF_SCOPE_TYPE, ( new Planner() )->scope_reject( $input ) );
	}

	public function test_every_outcome_code_is_covered_by_the_table(): void {
		$covered = array();
		foreach ( self::planner_cases() as $case ) {
			$covered[ $case[1] ] = true;
		}
		foreach ( PlanCode::all() as $code ) {
			$this->assertArrayHasKey( $code, $covered, "PlanCode {$code} is not exercised by PlannerTest." );
		}
	}
}
