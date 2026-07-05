<?php
/**
 * Immutable fact bundle fed to the pure Planner.
 *
 * @package Spintax
 */

namespace Spintax\Bindings\Plan;

defined( 'ABSPATH' ) || exit;

/**
 * Every fact the binding decision needs — resolved by the caller
 * (`BindingApplier`), so `Planner` itself performs no I/O.
 *
 * Two tiers matching the resolution order:
 *  - "cheap" facts drive `Planner::scope_reject()` (evaluated before the
 *    expensive render);
 *  - "full" facts drive the render/write branches of `Planner::plan()`.
 *
 * Properties are public and treated as immutable by convention (PHP 8.0 has no
 * `readonly`). All default so a cheap-only instance can be built for the
 * scope-reject pass without knowing the render result yet.
 */
final class PlanInput {

	/**
	 * Constructor.
	 *
	 * @param bool        $post_exists           Post resolved (get_post !== null).
	 * @param bool        $post_type_matches     Binding type empty OR equals post type.
	 * @param bool        $status_in_scope       status!='publish' OR post is published.
	 * @param bool        $target_runtime_valid  Target kind's runtime validation passed.
	 * @param string|null $target_runtime_code   Pre-computed SKIP_* when not valid.
	 * @param bool        $source_found          Source resolved to a usable string.
	 * @param string      $rendered              Rendered output.
	 * @param string      $current_target        Current stored target value.
	 * @param string|null $stored_signature      Stored render signature, null on cold start.
	 * @param bool        $regenerate_on_save    behavior.regenerate_on_save.
	 * @param bool        $auto_seed_empty       behavior.auto_seed_empty.
	 * @param bool        $preserve_manual_edits behavior.preserve_manual_edits.
	 * @param bool        $clear_on_empty        behavior.clear_on_empty.
	 */
	public function __construct(
		public bool $post_exists = true,
		public bool $post_type_matches = true,
		public bool $status_in_scope = true,
		public bool $target_runtime_valid = true,
		public ?string $target_runtime_code = null,
		public bool $source_found = true,
		public string $rendered = '',
		public string $current_target = '',
		public ?string $stored_signature = null,
		public bool $regenerate_on_save = false,
		public bool $auto_seed_empty = false,
		public bool $preserve_manual_edits = false,
		public bool $clear_on_empty = false
	) {}
}
