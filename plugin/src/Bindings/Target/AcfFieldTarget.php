<?php
/**
 * ACF-field binding target kind.
 *
 * @package Spintax
 */

namespace Spintax\Bindings\Target;

defined( 'ABSPATH' ) || exit;

use Spintax\Bindings\Plan\PlanCode;

/**
 * `kind=acf_field` — reads/writes via ACF's `get_field`/`update_field` on the
 * stable `field_key`, and re-verifies that key at apply time (spec §4.4.1).
 *
 * Logic lifted verbatim from `BindingApplier`'s former inline branches +
 * `validate_acf_target_runtime()`.
 */
final class AcfFieldTarget implements TargetKind {

	/**
	 * The kind identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'acf_field';
	}

	/**
	 * Read the current ACF field value.
	 *
	 * Only ever called after `validate_runtime()` has cleared the field_key —
	 * no silent fallback to post-meta (that would dilute the runtime guard).
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 * @return string
	 */
	public function read( array $binding, int $post_id ): string {
		$value = get_field( (string) $binding['target']['field_key'], $post_id );
		return is_string( $value ) ? $value : (string) ( null === $value ? '' : $value );
	}

	/**
	 * Write via `update_field( $field_key, ... )`.
	 *
	 * The field KEY (not name) lets ACF establish the reference meta on first
	 * write (spec §4.5).
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 * @param string               $value   Value to write.
	 */
	public function write( array $binding, int $post_id, string $value ): void {
		update_field( (string) $binding['target']['field_key'], $value, $post_id );
	}

	/**
	 * Re-verify the ACF target at apply time.
	 *
	 * ACF inactive → SKIP_ACF_NOT_LOADED (the save layer accepts ACF bindings
	 * while ACF is off, so they survive a deactivation cycle; we short-circuit
	 * rather than fall back to raw post-meta). Field deleted / key reassigned /
	 * name mismatch → SKIP_INVALID_ACF_FIELD.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @return string|null PlanCode SKIP_* when unusable, or null when valid.
	 */
	public function validate_runtime( array $binding ): ?string {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return PlanCode::SKIP_ACF_NOT_LOADED;
		}
		$field_key = (string) ( $binding['target']['field_key'] ?? '' );
		$key       = (string) ( $binding['target']['key'] ?? '' );
		if ( '' === $field_key ) {
			return PlanCode::SKIP_INVALID_ACF_FIELD;
		}
		$field = acf_get_field( $field_key );
		if ( ! is_array( $field ) || empty( $field['name'] ) ) {
			return PlanCode::SKIP_INVALID_ACF_FIELD;
		}
		if ( (string) $field['name'] !== $key ) {
			return PlanCode::SKIP_INVALID_ACF_FIELD;
		}
		return null;
	}

	/**
	 * Sanitise the ACF target sub-array (keeps `field_key`).
	 *
	 * @param array<string, mixed> $target The raw `binding.target` array.
	 * @return array<string, mixed> Normalised `target` array.
	 */
	public function normalize_target( array $target ): array {
		return array(
			'kind'      => 'acf_field',
			'key'       => sanitize_text_field( (string) ( $target['key'] ?? '' ) ),
			'field_key' => sanitize_text_field( (string) ( $target['field_key'] ?? '' ) ),
		);
	}
}
