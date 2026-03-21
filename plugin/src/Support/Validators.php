<?php
/**
 * Data normalisation and validation helpers.
 *
 * @package Spintax
 */

namespace Spintax\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Static validation and normalisation methods.
 */
final class Validators {

	/**
	 * Normalise raw settings array against defaults with type coercion.
	 *
	 * @param mixed $raw Raw value from get_option().
	 * @return array<string, mixed>
	 */
	public static function normalize_settings( $raw ): array {
		$defaults = Defaults::settings();

		if ( ! is_array( $raw ) ) {
			return $defaults;
		}

		$result = array_merge( $defaults, array_intersect_key( $raw, $defaults ) );

		$result['default_ttl']        = self::clamp_int( (int) $result['default_ttl'], 0, 604800 );
		$result['editors_can_manage'] = (bool) $result['editors_can_manage'];
		$result['debug']              = (bool) $result['debug'];
		$result['logs_max']           = self::clamp_int( (int) $result['logs_max'], 10, 5000 );

		return $result;
	}

	/**
	 * Normalise global variables array.
	 *
	 * @param mixed $raw Raw value from get_option().
	 * @return array<string, string>
	 */
	public static function normalize_global_variables( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return Defaults::global_variables();
		}

		$normalised = array();
		foreach ( $raw as $name => $value ) {
			$name = strtolower( trim( (string) $name ) );
			if ( '' === $name ) {
				continue;
			}
			// Strip % wrappers if present.
			$name                  = trim( $name, '%' );
			$normalised[ $name ] = (string) $value;
		}

		return $normalised;
	}

	/**
	 * Clamp an integer to a min/max range.
	 */
	public static function clamp_int( int $value, int $min, int $max ): int {
		return max( $min, min( $max, $value ) );
	}
}
