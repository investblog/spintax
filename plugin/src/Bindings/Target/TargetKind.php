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
	 * Validate the target at APPLY time, and return a machine outcome the Planner can act on.
	 *
	 * Runs at stage 2 of the applier's gate order — after scope, before the source is resolved and
	 * before anything renders — so a target that cannot be written never pays for the work.
	 *
	 * The post id is part of the question, not decoration: a kind may need to confirm that *this
	 * post* is a thing it can write to. Returning null here is a promise that `write()` will
	 * actually write; a kind that discovers otherwise inside `write()` has already let the Planner
	 * report a write that never happened, and the signature meta will have been stamped on a lie.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id The post being applied to.
	 * @return string|null A PlanCode SKIP_* when the target is unusable, or null when it is ready.
	 */
	public function validate_runtime( array $binding, int $post_id ): ?string;

	/**
	 * Validate the target at SAVE time, in the admin, and explain the problem to a human.
	 *
	 * The counterpart to `validate_runtime()`, and the two are deliberately different: this one
	 * returns a translated sentence for the editor staring at the form, while `validate_runtime()`
	 * returns a machine `PlanCode` for the applier. A kind can accept a save it will later refuse to
	 * apply — an ACF binding saved while ACF is deactivated is the precedent, and it exists so the
	 * configuration survives a deactivation cycle instead of being silently unsavable.
	 *
	 * Called at ONE point in `BindingsPage::handle_save()` — after the kind-agnostic reserved-key
	 * guard and the empty-key check, at exactly the position the ACF field-key check used to occupy.
	 * That position is load-bearing: the first error the editor sees must not change per kind, which
	 * is why Phase 2 deferred this method rather than fold it in and reorder the messages.
	 *
	 * @param array<string, mixed> $binding The binding as the form submitted it (not yet persisted).
	 * @return string|null Translated error message, or null when the target is acceptable.
	 */
	public function validate_save( array $binding ): ?string;

	/**
	 * Sanitise this kind's `target` sub-array (key + any kind-specific fields).
	 *
	 * @param array<string, mixed> $target The raw `binding.target` array.
	 * @return array<string, mixed> Normalised `target` array.
	 */
	public function normalize_target( array $target ): array;
}
