<?php
/**
 * Plugin settings and global variables repository.
 *
 * @package Spintax
 */

namespace Spintax\Core\Settings;

defined( 'ABSPATH' ) || exit;

use Spintax\Support\Defaults;
use Spintax\Support\OptionKeys;
use Spintax\Support\Validators;

/**
 * CRUD for plugin settings and global variables.
 */
class SettingsRepository {

	/**
	 * Get normalised plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$raw = get_option( OptionKeys::SETTINGS, array() );
		return Validators::normalize_settings( $raw );
	}

	/**
	 * Patch-update plugin settings.
	 *
	 * @param array<string, mixed> $patch Key-value pairs to update.
	 */
	public function update( array $patch ): void {
		$current = $this->get();

		$allowed = array_keys( Defaults::settings() );
		$patch   = array_intersect_key( $patch, array_flip( $allowed ) );
		$merged  = Validators::normalize_settings( array_merge( $current, $patch ) );

		update_option( OptionKeys::SETTINGS, $merged, false );
	}

	/**
	 * Reset settings to defaults.
	 */
	public function reset(): void {
		delete_option( OptionKeys::SETTINGS );
	}

	// =========================================================================
	// Global variables
	// =========================================================================

	/**
	 * Get normalised global variables.
	 *
	 * @return array<string, string>
	 */
	public function get_global_variables(): array {
		$raw = get_option( OptionKeys::GLOBAL_VARIABLES, array() );
		return Validators::normalize_global_variables( $raw );
	}

	/**
	 * Replace all global variables.
	 *
	 * @param array<string, string> $vars name => value (names without %).
	 */
	public function set_global_variables( array $vars ): void {
		$normalised = Validators::normalize_global_variables( $vars );
		update_option( OptionKeys::GLOBAL_VARIABLES, $normalised, false );

		// Bump global cache salt so all cached renders are invalidated.
		$this->bump_cache_salt();
	}

	// =========================================================================
	// Cache salt
	// =========================================================================

	/**
	 * Get current global cache salt.
	 */
	public function get_cache_salt(): int {
		return (int) get_option( OptionKeys::CACHE_SALT, 1 );
	}

	/**
	 * Bump global cache salt — invalidates ALL cached template output.
	 */
	public function bump_cache_salt(): void {
		$current = $this->get_cache_salt();
		update_option( OptionKeys::CACHE_SALT, $current + 1, false );
	}

	/**
	 * Initialise cache salt if not set (called on activation).
	 */
	public function init_cache_salt(): void {
		if ( false === get_option( OptionKeys::CACHE_SALT ) ) {
			add_option( OptionKeys::CACHE_SALT, 1, '', false );
		}
	}
}
