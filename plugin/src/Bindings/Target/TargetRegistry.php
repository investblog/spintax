<?php
/**
 * Registry of binding target kinds.
 *
 * @package Spintax
 */

namespace Spintax\Bindings\Target;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the available `binding.target.kind` values and
 * their behaviour. Static (mirroring `Defaults`' static nature); consumed by
 * `BindingApplier` (read/write/validate) and `BindingsRepo::normalize`
 * (allow-list + per-kind normalisation).
 *
 * The WooCommerce product-field target (2.4.0) is one entry here — which was the bet Phase 2 made,
 * and it paid: the write path needed a descriptor, not a new branch in five call sites.
 */
final class TargetRegistry {

	/**
	 * Lazily-built map of id → descriptor.
	 *
	 * @var array<string, TargetKind>|null
	 */
	private static ?array $kinds = null;

	/**
	 * Build the default descriptor set. Order is the shipping allow-list order.
	 *
	 * @return array<string, TargetKind>
	 */
	private static function build(): array {
		return array(
			'acf_field'                 => new AcfFieldTarget(),
			'post_meta'                 => new PostMetaTarget(),
			'woocommerce_product_field' => new WooCommerceProductFieldTarget(),
		);
	}

	/**
	 * Resolve a descriptor by id, or null when the kind is unknown.
	 *
	 * @param string $id Target kind id.
	 * @return TargetKind|null
	 */
	public static function get( string $id ): ?TargetKind {
		if ( null === self::$kinds ) {
			self::$kinds = self::build();
		}
		return self::$kinds[ $id ] ?? null;
	}

	/**
	 * Allowed target-kind ids (replaces `Defaults::target_kinds()`).
	 *
	 * @return string[]
	 */
	public static function ids(): array {
		if ( null === self::$kinds ) {
			self::$kinds = self::build();
		}
		return array_keys( self::$kinds );
	}

	/**
	 * All registered descriptors.
	 *
	 * @return array<string, TargetKind>
	 */
	public static function all(): array {
		if ( null === self::$kinds ) {
			self::$kinds = self::build();
		}
		return self::$kinds;
	}
}
