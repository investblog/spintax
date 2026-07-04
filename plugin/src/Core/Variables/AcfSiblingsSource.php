<?php
/**
 * ACF sibling-field variable source for binding renders.
 *
 * @package Spintax
 */

namespace Spintax\Core\Variables;

use Spintax\Support\SpintaxShield;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the binding's ACF sibling fields as `%acf_<name>%` references.
 *
 * "Sibling" = every other text/textarea/wysiwyg field in the same ACF
 * field group as the binding's target field. Returns an empty map if:
 *  - ACF is inactive
 *  - the binding's target is not `acf_field`
 *  - the target field key resolves to no group
 *
 * Repeaters / groups / flexible_content layouts are intentionally NOT
 * traversed (spec NG1). A sibling field of those types is skipped.
 */
class AcfSiblingsSource {

	/**
	 * Build the ACF-siblings variable map for a single post.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 * @return array<string, string>
	 */
	public function build( array $binding, int $post_id ): array {
		if ( $post_id <= 0 ) {
			return array();
		}
		if ( 'acf_field' !== ( $binding['target']['kind'] ?? '' ) ) {
			return array();
		}
		if ( ! function_exists( 'acf_get_field' ) || ! function_exists( 'acf_get_fields' ) || ! function_exists( 'get_field' ) ) {
			return array();
		}

		$target_field_key = (string) ( $binding['target']['field_key'] ?? '' );
		$target_field     = '' !== $target_field_key ? acf_get_field( $target_field_key ) : null;
		if ( ! is_array( $target_field ) ) {
			return array();
		}

		$group_key = (string) ( $target_field['parent'] ?? '' );
		if ( '' === $group_key ) {
			return array();
		}

		$siblings = acf_get_fields( $group_key );
		if ( ! is_array( $siblings ) ) {
			return array();
		}

		$result = array();
		foreach ( $siblings as $field ) {
			$name = (string) ( $field['name'] ?? '' );
			$type = (string) ( $field['type'] ?? '' );
			$key  = (string) ( $field['key'] ?? '' );

			if ( '' === $name || $key === $target_field_key ) {
				continue;
			}
			if ( ! in_array( $type, array( 'text', 'textarea', 'wysiwyg' ), true ) ) {
				continue;
			}

			$value = get_field( $key, $post_id );
			if ( null === $value ) {
				$value = '';
			}
			if ( ! is_scalar( $value ) ) {
				continue; // arrays (repeater rows / flexible_content) not supported in V1.
			}

			$result[ 'acf_' . $name ] = (string) $value;
		}

		// ACF field values are content, not markup — shield so the render
		// pipeline can't re-interpret them as spintax (ADR-0001, T2).
		return SpintaxShield::neutralize_map( $result );
	}
}
