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
	 * The post id is irrelevant here: an ACF field key is global, so a key that resolves for one
	 * post resolves for all of them.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id (unused).
	 * @return string|null PlanCode SKIP_* when unusable, or null when valid.
	 */
	public function validate_runtime( array $binding, int $post_id ): ?string {
		unset( $post_id );

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
	 * Validate the ACF target at save time — Tier 5 of the reserved-key guard (spec §4.6, 2.0.1).
	 *
	 * Lifted verbatim from `BindingsPage::validate_acf_field_key()` when Phase 3 added
	 * `validate_save` to the interface; the messages and their order are unchanged, because the
	 * first error an editor sees is part of the contract.
	 *
	 * - `field_key` must be present. ACF's `update_field()` needs the stable key, not the name.
	 * - When ACF is loaded, the key must resolve to a field whose `name` matches `target.key`.
	 *   A mismatched pair would route writes to whatever field the key actually belongs to.
	 * - When ACF is NOT loaded, the save is allowed: the applier re-checks at write time and skips.
	 *   That is deliberate — a binding must survive an ACF deactivation cycle.
	 *
	 * @param array<string, mixed> $binding Binding as submitted.
	 * @return string|null Error message, or null when valid.
	 */
	public function validate_save( array $binding ): ?string {
		$key       = (string) ( $binding['target']['key'] ?? '' );
		$field_key = (string) ( $binding['target']['field_key'] ?? '' );

		if ( '' === $field_key ) {
			return __( 'ACF field key is required for ACF targets. Pick a field from the dropdown or paste the field key (e.g. field_5f8a1234abcd).', 'spintax' );
		}

		if ( ! function_exists( 'acf_get_field' ) ) {
			return null;
		}

		$field = acf_get_field( $field_key );
		if ( ! is_array( $field ) || empty( $field['name'] ) ) {
			return sprintf(
				/* translators: %s: ACF field key entered by the user */
				__( 'ACF field key "%s" was not found. Confirm the field exists in an ACF field group.', 'spintax' ),
				$field_key
			);
		}

		$resolved_name = (string) $field['name'];
		if ( $resolved_name !== $key ) {
			return sprintf(
				/* translators: 1: ACF field key, 2: actual field name behind that key, 3: field name the user typed */
				__( 'ACF field key "%1$s" points to field "%2$s", not "%3$s". The field name and field key must match.', 'spintax' ),
				$field_key,
				$resolved_name,
				$key
			);
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
