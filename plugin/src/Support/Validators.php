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
			$name                = trim( $name, '%' );
			$normalised[ $name ] = (string) $value;
		}

		return $normalised;
	}

	/**
	 * Sanitize raw spintax markup from user input.
	 *
	 * Standard sanitize_textarea_field() cannot be used because it calls
	 * wp_strip_all_tags(), which destroys angle-bracket expressions that are
	 * valid spintax permutation syntax (e.g. <minsize=2;sep=", ">).
	 *
	 * This method addresses the real security concerns — invalid UTF-8,
	 * null bytes, and control characters — without breaking the markup.
	 *
	 * @param string $raw Raw spintax text from $_POST.
	 * @return string Sanitized text safe for storage.
	 */
	public static function sanitize_spintax( string $raw ): string {
		// Remove invalid UTF-8 sequences.
		$raw = wp_check_invalid_utf8( $raw, true );

		// Remove null bytes and other control characters except \n and \t.
		$raw = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw );

		// Normalize line endings.
		$raw = str_replace( "\r\n", "\n", $raw );
		$raw = str_replace( "\r", "\n", $raw );

		return $raw;
	}

	/**
	 * Clamp an integer to a min/max range.
	 *
	 * @param int $value Value to clamp.
	 * @param int $min   Minimum allowed value.
	 * @param int $max   Maximum allowed value.
	 * @return int Clamped value.
	 */
	public static function clamp_int( int $value, int $min, int $max ): int {
		return max( $min, min( $max, $value ) );
	}
}
