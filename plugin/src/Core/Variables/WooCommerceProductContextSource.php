<?php
/**
 * WooCommerce product-context variable source for front-end renders.
 *
 * @package Spintax
 */

namespace Spintax\Core\Variables;

use Spintax\Support\SpintaxShield;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the current WooCommerce product as `%product_*%` references inside
 * `[spintax]` / `spintax_render()` output on product pages.
 *
 * Read-only: this source never writes to a product. WooCommerce is optional —
 * when it is inactive (or no product context resolves) `build()` returns an
 * empty array so callers can merge unconditionally into the runtime layer.
 *
 * Product values MUST land in the runtime-variable layer: the render cache key
 * is derived from that map (see RenderContext::get_context_hash), so
 * `product_id` — always present in a non-empty result — is what keeps product
 * A's cached output from leaking to product B.
 *
 * Mapped variables (see docs/spec-woocommerce.md §2.2):
 *  - `%product_id%`               (always present — cache discriminator)
 *  - `%product_name%`
 *  - `%product_slug%`
 *  - `%product_sku%`
 *  - `%product_type%`
 *  - `%product_stock_status%`
 *  - `%product_categories%`       (comma-joined plain-text names)
 *  - `%product_tags%`             (comma-joined plain-text names)
 *  - `%product_short_description%` (plain text)
 *  - `%product_attribute_<slug>%` (one per attribute)
 *
 * Performance: the full map is memoised per product id for the request, so the
 * term lookups behind `%product_categories%` / `%product_tags%` run at most
 * once per product regardless of how many `[spintax]` blocks reference it.
 */
class WooCommerceProductContextSource {

	/**
	 * Availability probe. Returns true when WooCommerce is loaded.
	 *
	 * @var callable():bool
	 */
	private $is_available;

	/**
	 * Product resolver. Maps a product id to a WC_Product-like object or null.
	 *
	 * @var callable(int):?object
	 */
	private $resolve_product;

	/**
	 * Per-request memo of built variable maps, keyed by "<path>:<product_id>"
	 * where <path> is `explicit` or `auto`. Scoping by path prevents an
	 * ungated auto-detected entry from being served to an explicit lookup.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $memo = array();

	/**
	 * Constructor.
	 *
	 * Both collaborators default to the real WooCommerce runtime; they exist as
	 * an injectable seam so the populated map can be unit-tested without
	 * WooCommerce loaded (the test environment ships no WC).
	 *
	 * @param callable|null $is_available    Optional availability probe.
	 * @param callable|null $resolve_product Optional product resolver.
	 */
	public function __construct( ?callable $is_available = null, ?callable $resolve_product = null ) {
		$this->is_available    = $is_available ?? static function (): bool {
			return function_exists( 'wc_get_product' );
		};
		$this->resolve_product = $resolve_product ?? static function ( int $id ) {
			return wc_get_product( $id );
		};
	}

	/**
	 * Build the product-context variable map.
	 *
	 * @param int $product_id Explicit product id, or 0 to auto-detect from the main query.
	 *                        An explicit id resolves only published products (auto-detect
	 *                        is already limited to the served product).
	 * @return array<string, string> Empty when WooCommerce is inactive or no product resolves.
	 */
	public function build( int $product_id = 0 ): array {
		if ( ! ( $this->is_available )() ) {
			return array();
		}

		// An explicit id bypasses the main-query gate, so it is status-checked
		// below. Auto-detection is already limited to the published product
		// WordPress served for this request.
		$explicit = $product_id > 0;
		if ( ! $explicit ) {
			$product_id = $this->detect_current_product_id();
		}
		if ( $product_id <= 0 ) {
			return array();
		}

		// Scope the memo by path: an explicit lookup must never be served an
		// auto-detected (ungated) cache entry for the same id, or the publish
		// gate below could be bypassed within a single request.
		$memo_key = ( $explicit ? 'explicit:' : 'auto:' ) . $product_id;
		if ( isset( $this->memo[ $memo_key ] ) ) {
			return $this->memo[ $memo_key ];
		}

		$product = ( $this->resolve_product )( $product_id );

		// Never expose a non-published product's context via an explicit
		// `product_id`: it would let an author read draft / private products
		// they were not served. The auto-detect path can't reach those.
		if ( $product && $explicit && 'publish' !== $this->product_status( $product ) ) {
			$product = null;
		}

		$map = $product ? $this->map( $product ) : array();

		$this->memo[ $memo_key ] = $map;

		return $map;
	}

	/**
	 * Read a resolved product's post status.
	 *
	 * @param object $product WC_Product-like object.
	 * @return string Status slug, or '' when unavailable (treated as non-published).
	 */
	private function product_status( object $product ): string {
		return method_exists( $product, 'get_status' ) ? (string) $product->get_status() : '';
	}

	/**
	 * Resolve the current product id from the main query.
	 *
	 * Phase 1 supports singular product pages only; product loops/cards are a
	 * later slice localised to this method.
	 *
	 * @return int Product id, or 0 when the current query is not a single product.
	 */
	private function detect_current_product_id(): int {
		$object = get_queried_object();
		if ( $object instanceof \WP_Post && 'product' === $object->post_type ) {
			return (int) $object->ID;
		}

		return 0;
	}

	/**
	 * Map a resolved product to its `%product_*%` variables.
	 *
	 * @param object $product WC_Product-like object.
	 * @return array<string, string>
	 */
	private function map( object $product ): array {
		$product_id = (int) $product->get_id();

		// Price / stock are volatile commerce data, deliberately excluded — this
		// source is for generated copy, not live pricing (see spec §2.6).
		$map = array(
			'product_id'                => (string) $product_id,
			'product_name'              => (string) $product->get_name(),
			'product_slug'              => (string) $product->get_slug(),
			'product_sku'               => (string) $product->get_sku(),
			'product_type'              => (string) $product->get_type(),
			'product_stock_status'      => (string) $product->get_stock_status(),
			'product_categories'        => $this->term_names( $product_id, 'product_cat' ),
			'product_tags'              => $this->term_names( $product_id, 'product_tag' ),
			'product_short_description' => $this->plain_text( (string) $product->get_short_description() ),
		);

		$map = array_merge( $map, $this->attributes( $product ) );

		// Product data is content, not markup — shield it so the render pipeline
		// can't re-interpret a product field as spintax (see ADR-0001, T2).
		return SpintaxShield::neutralize_map( $map );
	}

	/**
	 * Comma-joined plain-text term names for a product taxonomy.
	 *
	 * Names only — no linked HTML enters the runtime layer.
	 *
	 * @param int    $product_id Product id.
	 * @param string $taxonomy   Taxonomy slug.
	 * @return string
	 */
	private function term_names( int $product_id, string $taxonomy ): string {
		$names = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );
		if ( is_wp_error( $names ) || ! is_array( $names ) ) {
			return '';
		}

		return implode( ', ', array_map( 'strval', $names ) );
	}

	/**
	 * Build the `%product_attribute_<slug>%` variable map.
	 *
	 * The primary key uses the ergonomic alias (leading `pa_` stripped). If the
	 * alias collides with another attribute, the fully-qualified key is kept as
	 * a fallback so no value is lost.
	 *
	 * @param object $product WC_Product-like object.
	 * @return array<string, string>
	 */
	private function attributes( object $product ): array {
		if ( ! method_exists( $product, 'get_attributes' ) ) {
			return array();
		}

		$result = array();
		foreach ( array_keys( (array) $product->get_attributes() ) as $raw_name ) {
			$raw_name = (string) $raw_name;
			$value    = (string) $product->get_attribute( $raw_name );

			$alias_key = 'product_attribute_' . $this->normalize_key( preg_replace( '/^pa_/', '', $raw_name ) );
			if ( ! isset( $result[ $alias_key ] ) ) {
				$result[ $alias_key ] = $value;
				continue;
			}

			// Alias already taken by another attribute — keep the fully-qualified key.
			$result[ 'product_attribute_' . $this->normalize_key( $raw_name ) ] = $value;
		}

		return $result;
	}

	/**
	 * Normalise a raw slug into a parser-safe variable-name segment.
	 *
	 * The engine expands only `%(\w+)%`, so the result is restricted to
	 * `[A-Za-z0-9_]` (dashes and other characters become underscores).
	 *
	 * @param string $slug Raw slug.
	 * @return string
	 */
	private function normalize_key( string $slug ): string {
		return (string) preg_replace( '/[^a-z0-9_]/', '_', sanitize_key( $slug ) );
	}

	/**
	 * Reduce an HTML fragment to trimmed, entity-decoded plain text.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private function plain_text( string $html ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
	}
}
