<?php
/**
 * WooCommerce product-field binding target kind.
 *
 * @package Spintax
 */

namespace Spintax\Bindings\Target;

defined( 'ABSPATH' ) || exit;

use Spintax\Bindings\Plan\PlanCode;
use Spintax\Bindings\ReentrancyGuard;

/**
 * `kind=woocommerce_product_field` — writes a rendered template into a product's description or
 * short description.
 *
 * This is the first binding target that writes into a **catalogue**, and the first whose write goes
 * through a host API rather than a meta row. Both facts shape the class:
 *
 * **The field set is a hard whitelist of two.** `description` and `short_description`, nothing else.
 * Not because more is hard, but because everything else about a product — price, SKU, stock, sale
 * dates — is commerce data rather than generated copy, and a template that can reach it is a
 * template that can take a shop down. The whitelist is enforced at save time *and* re-checked at
 * apply time, because a WP-CLI import can create a binding the admin form would have refused.
 *
 * **Writes go through WooCommerce CRUD, never `wp_update_post()` or `$wpdb`.** The two fields map to
 * `post_content` / `post_excerpt`, so a direct write would appear to work — and would quietly leave
 * WooCommerce's product cache and its `wc_product_meta_lookup` table describing the old copy, while
 * skipping every `woocommerce_*` save hook a shop's other plugins rely on. (A note for reviewers,
 * because the earlier spec got this wrong: HPOS is *not* the reason. HPOS is order storage; product
 * descriptions live in `wp_posts` either way. The reason is lookup-table and hook consistency.)
 *
 * **`$product->save()` fires `save_post`,** which is the hook the binding trigger listens on — so
 * the write is wrapped in `ReentrancyGuard`, or a regenerate-on-save binding loops forever.
 */
final class WooCommerceProductFieldTarget implements TargetKind {

	/**
	 * The only product fields a binding may write. Hard-capped on purpose — see the class docblock.
	 *
	 * @var string[]
	 */
	public const FIELDS = array( 'description', 'short_description' );

	/**
	 * The post type these targets are meaningful on.
	 */
	public const POST_TYPE = 'product';

	/**
	 * Is WooCommerce there? Injectable so the class is testable without it.
	 *
	 * @var callable(): bool
	 */
	private $is_available;

	/**
	 * Resolve a post id to a product object, or something falsy.
	 *
	 * @var callable(int): mixed
	 */
	private $resolve_product;

	/**
	 * The seams exist for the same reason `WooCommerceProductContextSource` has them: the test suite
	 * runs without WooCommerce, and a target whose every path is unreachable in tests is a target
	 * whose every path is unverified. Production passes nothing and gets the real functions.
	 *
	 * @param callable|null $is_available    Override the WooCommerce presence check — fn(): bool.
	 * @param callable|null $resolve_product Override the product lookup — fn(int $post_id): mixed.
	 */
	public function __construct( ?callable $is_available = null, ?callable $resolve_product = null ) {
		$this->is_available    = $is_available ?? static fn(): bool => function_exists( 'wc_get_product' );
		$this->resolve_product = $resolve_product ?? static fn( int $post_id ) => wc_get_product( $post_id );
	}

	/**
	 * The kind identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'woocommerce_product_field';
	}

	/**
	 * Read the product's current description / short description.
	 *
	 * Only ever called after `validate_runtime()` has cleared WooCommerce and the field, so the
	 * `null` branch is a belt-and-braces guard rather than an expected path.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Product id.
	 * @return string
	 */
	public function read( array $binding, int $post_id ): string {
		$product = $this->product( $post_id );
		if ( null === $product ) {
			return '';
		}

		return 'short_description' === $this->field( $binding )
			? (string) $product->get_short_description()
			: (string) $product->get_description();
	}

	/**
	 * Write through WooCommerce CRUD, guarded against the save loop it would otherwise create.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Product id.
	 * @param string               $value   Rendered value to write.
	 */
	public function write( array $binding, int $post_id, string $value ): void {
		// Enforce the whitelist at the sink, not only upstream. Today `write()` is only ever reached
		// after `validate_runtime()` has cleared the key — but that is an invariant held by the
		// applier's gate order, and a future reorder, or a direct caller, must not be able to turn an
		// unknown key into a description overwrite (the `else` branch below would do exactly that).
		if ( ! in_array( $this->field( $binding ), self::FIELDS, true ) ) {
			return;
		}

		$product = $this->product( $post_id );
		if ( null === $product ) {
			return;
		}

		if ( 'short_description' === $this->field( $binding ) ) {
			$product->set_short_description( $value );
		} else {
			$product->set_description( $value );
		}

		// `save()` fires save_post, which is where SavePostTrigger lives. Without the guard this
		// binding would re-apply to the product it is mid-write on, and save again, forever. The
		// `finally` matters: a throwing save must not leave the product deaf to its own trigger.
		ReentrancyGuard::enter( $post_id );
		try {
			$product->save();
		} finally {
			ReentrancyGuard::leave( $post_id );
		}
	}

	/**
	 * Re-verify the target at apply time.
	 *
	 * WooCommerce inactive → `SKIP_WC_NOT_LOADED`. This mirrors the ACF precedent deliberately: the
	 * save layer accepts a WooCommerce binding while WooCommerce is switched off, so a deactivation
	 * cycle does not make the configuration unsavable — and the applier short-circuits instead of
	 * writing through some fallback path. Already-generated copy stays exactly where it is; it is
	 * the product's real description by then, and nothing here reverts it.
	 *
	 * Everything else → `SKIP_INVALID_WC_FIELD`: a key outside the whitelist, a binding pointed at a
	 * post type that is not `product`, or a post that WooCommerce refuses to resolve as a product.
	 * The admin form rejects all three, so reaching them means the binding arrived another way —
	 * `wp spintax bindings import` is the honest example — and the runtime is the last guard before
	 * a write.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Product id.
	 * @return string|null PlanCode SKIP_* when unusable, or null when ready to write.
	 */
	public function validate_runtime( array $binding, int $post_id ): ?string {
		if ( ! ( $this->is_available )() ) {
			return PlanCode::SKIP_WC_NOT_LOADED;
		}

		if ( ! in_array( $this->field( $binding ), self::FIELDS, true ) ) {
			return PlanCode::SKIP_INVALID_WC_FIELD;
		}

		if ( self::POST_TYPE !== (string) ( $binding['post_type'] ?? '' ) ) {
			return PlanCode::SKIP_INVALID_WC_FIELD;
		}

		if ( null === $this->product( $post_id ) ) {
			return PlanCode::SKIP_INVALID_WC_FIELD;
		}

		return null;
	}

	/**
	 * Validate the target at save time, for a human.
	 *
	 * @param array<string, mixed> $binding Binding as submitted.
	 * @return string|null Error message, or null when valid.
	 */
	public function validate_save( array $binding ): ?string {
		if ( ! in_array( $this->field( $binding ), self::FIELDS, true ) ) {
			return sprintf(
				/* translators: %s: comma-separated list of writable product fields */
				__( 'Only these product fields can be generated: %s. Price, SKU, stock and the rest are commerce data, not copy — Spintax will not write to them.', 'spintax' ),
				implode( ', ', self::FIELDS )
			);
		}

		if ( self::POST_TYPE !== (string) ( $binding['post_type'] ?? '' ) ) {
			return __( 'A product-field target only works on the Product post type. Switch the post type to Product, or pick a different target kind.', 'spintax' );
		}

		return null;
	}

	/**
	 * Sanitise the target sub-array.
	 *
	 * A key outside the whitelist is emptied rather than kept, which hands it to the existing
	 * empty-key guard — one rejection path instead of two, and no way to persist a key that the
	 * runtime would then have to refuse.
	 *
	 * @param array<string, mixed> $target The raw `binding.target` array.
	 * @return array<string, mixed> Normalised `target` array.
	 */
	public function normalize_target( array $target ): array {
		$key = sanitize_key( (string) ( $target['key'] ?? '' ) );

		return array(
			'kind'      => 'woocommerce_product_field',
			'key'       => in_array( $key, self::FIELDS, true ) ? $key : '',
			'field_key' => '',
		);
	}

	/**
	 * The requested product field, unsanitised beyond a string cast.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @return string
	 */
	private function field( array $binding ): string {
		return (string) ( $binding['target']['key'] ?? '' );
	}

	/**
	 * Resolve a post id to a product object, or null.
	 *
	 * `wc_get_product()` returns `false` for anything that is not a product — a plain post, a
	 * missing id, a product WooCommerce cannot build — which is exactly the signal this needs.
	 *
	 * @param int $post_id Post id.
	 * @return object|null The product object, or null when this post is not one.
	 */
	private function product( int $post_id ): ?object {
		if ( ! ( $this->is_available )() ) {
			return null;
		}

		$product = ( $this->resolve_product )( $post_id );

		return is_object( $product ) ? $product : null;
	}
}
