<?php
/**
 * Re-entrancy guard for targets whose write goes through a save that fires `save_post`.
 *
 * @package Spintax
 */

namespace Spintax\Bindings;

defined( 'ABSPATH' ) || exit;

/**
 * Marks a post as "Spintax is writing to it right now", so the save_post trigger stands down.
 *
 * Every target before WooCommerce wrote through `update_post_meta()` / `update_field()`, neither of
 * which re-enters WordPress's save cycle. A product field does: `$product->save()` is the canonical
 * WooCommerce writer, and it fires `save_post` — which is the very hook `SavePostTrigger` listens on
 * at priority 20. Unguarded, a regenerate-on-save product binding is an infinite loop:
 *
 *     save_post → applier → write() → $product->save() → save_post → applier → …
 *
 * It is deliberately generic rather than WooCommerce-specific: any future target that writes through
 * a host API which re-enters the save cycle (a term, an order, a custom CRUD object) reuses this.
 *
 * Request-scoped static state is the right shape here despite the usual objections. The hazard is a
 * synchronous re-entry inside one PHP process — a lock in the database would be both slower and
 * wrong, because it would outlive the request that owns it and could strand a post if the process
 * died mid-write.
 */
final class ReentrancyGuard {

	/**
	 * Post ids currently being written by a target.
	 *
	 * @var array<int, true>
	 */
	private static array $active = array();

	/**
	 * Mark a post as being written. Pair with `leave()` in a `finally`.
	 *
	 * @param int $post_id Post being written.
	 */
	public static function enter( int $post_id ): void {
		self::$active[ $post_id ] = true;
	}

	/**
	 * Release the mark. Must run even when the write throws, or the post stays deaf to save_post
	 * for the rest of the request.
	 *
	 * @param int $post_id Post that was being written.
	 */
	public static function leave( int $post_id ): void {
		unset( self::$active[ $post_id ] );
	}

	/**
	 * True while a target is mid-write on this post — the signal for a trigger to stand down.
	 *
	 * @param int $post_id Post to check.
	 * @return bool
	 */
	public static function is_active( int $post_id ): bool {
		return isset( self::$active[ $post_id ] );
	}

	/**
	 * Drop all marks. Tests only — production has no reason to clear a guard it did not set.
	 */
	public static function reset(): void {
		self::$active = array();
	}
}
