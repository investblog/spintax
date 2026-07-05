<?php
/**
 * Pure binding decision function.
 *
 * @package Spintax
 */

namespace Spintax\Bindings\Plan;

defined( 'ABSPATH' ) || exit;

/**
 * Decides the outcome of a binding apply from a fully-resolved `PlanInput`.
 *
 * Pure: no WP calls, no I/O, no state — every fact arrives via the DTO, so the
 * live `apply()` path and the Test-panel dry-run share one decision and can
 * never disagree. This is a 1:1 transcription of the historical
 * `BindingApplier::plan()` decision tree (spec §4.4); the ordering and every
 * branch are load-bearing contract — see `tests/Bindings/Plan/PlannerTest`.
 */
final class Planner {

	/**
	 * Cheap pre-render scope/validity gate.
	 *
	 * Reproduces the exact historical order so the first failing gate wins:
	 * post existence + type, then status, then target runtime validity, then
	 * source resolution. Returns a SKIP_* code, or null when nothing rejects.
	 *
	 * @param PlanInput $in Resolved facts (only the cheap tier is read).
	 * @return string|null PlanCode SKIP_* or null.
	 */
	public function scope_reject( PlanInput $in ): ?string {
		if ( ! $in->post_exists ) {
			return PlanCode::SKIP_OUT_OF_SCOPE_TYPE;
		}
		if ( ! $in->post_type_matches ) {
			return PlanCode::SKIP_OUT_OF_SCOPE_TYPE;
		}
		if ( ! $in->status_in_scope ) {
			return PlanCode::SKIP_OUT_OF_SCOPE_STATUS;
		}
		if ( ! $in->target_runtime_valid ) {
			return $in->target_runtime_code ?? PlanCode::SKIP_INVALID_ACF_FIELD;
		}
		if ( ! $in->source_found ) {
			return PlanCode::SKIP_SOURCE_NOT_FOUND;
		}
		return null;
	}

	/**
	 * Full decision. Returns exactly one PlanCode.
	 *
	 * @param PlanInput $in Fully-resolved facts.
	 * @return string One PlanCode value.
	 */
	public function plan( PlanInput $in ): string {
		$reject = $this->scope_reject( $in );
		if ( null !== $reject ) {
			return $reject;
		}

		$rendered_empty = ( '' === $in->rendered );
		$target_empty   = ( '' === $in->current_target );
		$has_signature  = ( null !== $in->stored_signature );

		// Path 1: regenerate-on-save supersedes auto-seed.
		if ( $in->regenerate_on_save ) {
			if ( $in->preserve_manual_edits ) {
				if ( ! $has_signature ) {
					// Cold start: no signature yet.
					return $target_empty ? PlanCode::WROTE_SEEDED : PlanCode::SKIP_COLD_START_MANUAL;
				}
				if ( sha1( $in->current_target ) !== $in->stored_signature ) {
					return PlanCode::SKIP_MANUAL_EDIT_DETECTED;
				}
			}

			if ( $rendered_empty ) {
				return $in->clear_on_empty ? PlanCode::WROTE_EMPTY : PlanCode::SKIP_EMPTY_RENDER;
			}
			return PlanCode::WROTE_REGENERATED;
		}

		// Path 2: auto-seed only writes when the target is empty.
		if ( $in->auto_seed_empty ) {
			if ( ! $target_empty ) {
				return PlanCode::SKIP_TARGET_NONEMPTY;
			}
			if ( $rendered_empty ) {
				return PlanCode::SKIP_EMPTY_RENDER;
			}
			return PlanCode::WROTE_SEEDED;
		}

		// Path 3: neither trigger flag — form-save validation should have warned.
		return PlanCode::SKIP_NO_WRITE_TRIGGER;
	}
}
