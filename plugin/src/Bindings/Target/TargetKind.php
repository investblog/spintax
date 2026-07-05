<?php
/**
 * Binding target-kind descriptor contract.
 *
 * @package Spintax
 */

namespace Spintax\Bindings\Target;

defined( 'ABSPATH' ) || exit;

/**
 * A target kind knows how to read, write, runtime-validate and normalise one
 * class of binding target (ACF field, post meta, …). It lets `BindingApplier`
 * and `BindingsRepo` dispatch polymorphically instead of branching on the kind
 * string. Phase 2 scope: the runtime write path + normalisation only — admin
 * form UI, discovery endpoints, list badge and migration classify remain
 * per-kind branches by design.
 */
interface TargetKind {

	/**
	 * The kind identifier stored in `binding.target.kind`.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Read the current value of the target field.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 * @return string
	 */
	public function read( array $binding, int $post_id ): string;

	/**
	 * Write a value to the target field.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 * @param string               $value   Value to write.
	 */
	public function write( array $binding, int $post_id, string $value ): void;

	/**
	 * Verify the target is usable at apply time.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @return string|null A PlanCode SKIP_* when unusable, or null when valid.
	 */
	public function validate_runtime( array $binding ): ?string;

	/**
	 * Sanitise this kind's `target` sub-array (key + any kind-specific fields).
	 *
	 * @param array<string, mixed> $target The raw `binding.target` array.
	 * @return array<string, mixed> Normalised `target` array.
	 */
	public function normalize_target( array $target ): array;
}
