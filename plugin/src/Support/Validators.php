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

	// --- Bindings: id helpers + reserved-key guard (spec §4.6) ---

	/**
	 * Check whether a string is a syntactically-valid binding id.
	 *
	 * @param mixed $id Candidate id.
	 * @return bool
	 */
	public static function is_valid_binding_id( $id ): bool {
		return is_string( $id ) && (bool) preg_match( '/^bind_[a-z0-9]{6}$/', $id );
	}

	/**
	 * Generate a fresh binding id.
	 *
	 * @return string
	 */
	public static function generate_binding_id(): string {
		// 6 hex chars (24 bits) is plenty for collision avoidance up to the
		// 200-binding hard cap; `bin2hex(random_bytes(3))` is cryptographically
		// sound where available, otherwise wp_generate_password() (without
		// special chars) gives an equivalent alphanumeric.
		if ( function_exists( 'random_bytes' ) ) {
			return 'bind_' . bin2hex( random_bytes( 3 ) );
		}
		return 'bind_' . strtolower( wp_generate_password( 6, false, false ) );
	}

	/**
	 * Tier 1: WordPress-internal post-meta keys.
	 *
	 * Mirrors `wpci\MappingsPage::is_reserved_meta_key`.
	 *
	 * @param string $key Candidate meta key.
	 * @return bool
	 */
	public static function is_reserved_meta_key( string $key ): bool {
		$prefixes = array( '_wp_', '_edit_', '_oembed_' );
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
		return in_array( $key, array( '_pingme', '_encloseme', '_thumbnail_id' ), true );
	}

	/**
	 * Tier 2: plugin-internal meta keys.
	 *
	 * Prevents a binding from writing to another binding's source,
	 * signature, or cache-version stamp.
	 *
	 * @param string $key Candidate meta key.
	 * @return bool
	 */
	public static function is_plugin_internal_meta_key( string $key ): bool {
		$prefixes = array(
			'_spintax_source_',
			'_spintax_last_render_sig_',
			'_spintax_binding_cache_v_',
			'_spintax_',
		);
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tier 3: wp_posts column names.
	 *
	 * `update_post_meta()` against a post-column key creates a shadow
	 * meta row that the column does not read — silently confusing.
	 * Reject at form time.
	 *
	 * @param string $key Candidate meta key.
	 * @return bool
	 */
	public static function is_post_column( string $key ): bool {
		$columns = array(
			'ID',
			'post_author',
			'post_date',
			'post_date_gmt',
			'post_content',
			'post_content_filtered',
			'post_title',
			'post_excerpt',
			'post_status',
			'comment_status',
			'ping_status',
			'post_password',
			'post_name',
			'to_ping',
			'pinged',
			'post_modified',
			'post_modified_gmt',
			'post_parent',
			'guid',
			'menu_order',
			'post_type',
			'post_mime_type',
			'comment_count',
		);
		return in_array( $key, $columns, true );
	}
}
