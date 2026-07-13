<?php
/**
 * Binding decision-tree outcome codes.
 *
 * @package Spintax
 */

namespace Spintax\Bindings\Plan;

defined( 'ABSPATH' ) || exit;

/**
 * The flat set of outcome codes a binding apply can produce (spec §4.4).
 *
 * Single source of truth for the 13 string values. `BindingApplier` exposes
 * these same values as class constants (aliased to here) for back-compat with
 * existing consumers, logs, WP-CLI output and telemetry — do not change the
 * strings.
 */
final class PlanCode {

	public const WROTE_SEEDED              = 'wrote_seeded';
	public const WROTE_REGENERATED         = 'wrote_regenerated';
	public const WROTE_EMPTY               = 'wrote_empty';
	public const SKIP_MANUAL_EDIT_DETECTED = 'skip_manual_edit_detected';
	public const SKIP_TARGET_NONEMPTY      = 'skip_target_nonempty';
	public const SKIP_EMPTY_RENDER         = 'skip_empty_render';
	public const SKIP_NO_WRITE_TRIGGER     = 'skip_no_write_trigger';
	public const SKIP_SOURCE_NOT_FOUND     = 'skip_source_not_found';
	public const SKIP_COLD_START_MANUAL    = 'skip_cold_start_manual';
	public const SKIP_OUT_OF_SCOPE_TYPE    = 'skip_out_of_scope_type';
	public const SKIP_OUT_OF_SCOPE_STATUS  = 'skip_out_of_scope_status';
	public const SKIP_ACF_NOT_LOADED       = 'skip_acf_not_loaded';
	public const SKIP_INVALID_ACF_FIELD    = 'skip_invalid_acf_field';
	public const SKIP_WC_NOT_LOADED        = 'skip_wc_not_loaded';
	public const SKIP_INVALID_WC_FIELD     = 'skip_invalid_wc_field';

	/**
	 * The three codes that mean a write happened / would happen.
	 *
	 * @var string[]
	 */
	private const WRITES = array(
		self::WROTE_SEEDED,
		self::WROTE_REGENERATED,
		self::WROTE_EMPTY,
	);

	/**
	 * Skip codes surfaced as "blocked" (a guard/error, not a benign no-op).
	 *
	 * @var string[]
	 */
	private const BLOCKED = array(
		self::SKIP_SOURCE_NOT_FOUND,
		self::SKIP_INVALID_ACF_FIELD,
		self::SKIP_ACF_NOT_LOADED,
		self::SKIP_INVALID_WC_FIELD,
		self::SKIP_WC_NOT_LOADED,
	);

	/**
	 * Every code (writes + skips).
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::WROTE_SEEDED,
			self::WROTE_REGENERATED,
			self::WROTE_EMPTY,
			self::SKIP_MANUAL_EDIT_DETECTED,
			self::SKIP_TARGET_NONEMPTY,
			self::SKIP_EMPTY_RENDER,
			self::SKIP_NO_WRITE_TRIGGER,
			self::SKIP_SOURCE_NOT_FOUND,
			self::SKIP_COLD_START_MANUAL,
			self::SKIP_OUT_OF_SCOPE_TYPE,
			self::SKIP_OUT_OF_SCOPE_STATUS,
			self::SKIP_ACF_NOT_LOADED,
			self::SKIP_INVALID_ACF_FIELD,
			self::SKIP_WC_NOT_LOADED,
			self::SKIP_INVALID_WC_FIELD,
		);
	}

	/**
	 * True when the code represents a write (seeded / regenerated / emptied).
	 *
	 * @param string $code Outcome code.
	 * @return bool
	 */
	public static function is_write( string $code ): bool {
		return in_array( $code, self::WRITES, true );
	}

	/**
	 * Bucket a code as 'write' | 'blocked' | 'skip' for summaries/telemetry.
	 *
	 * @param string $code Outcome code.
	 * @return string
	 */
	public static function category( string $code ): string {
		if ( self::is_write( $code ) ) {
			return 'write';
		}
		if ( in_array( $code, self::BLOCKED, true ) ) {
			return 'blocked';
		}
		return 'skip';
	}
}
