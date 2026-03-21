<?php
/**
 * Object cache manager for rendered template output.
 *
 * @package Spintax
 */

namespace Spintax\Core\Cache;

defined( 'ABSPATH' ) || exit;

use Spintax\Core\Settings\SettingsRepository;
use Spintax\Support\OptionKeys;

/**
 * Manages cached template output via the WP Object Cache API.
 *
 * Cache key: {template_id}_{template_version}_{context_hash}
 * Group:     spintax_{global_salt}
 *
 * Invalidation is done by bumping version counters:
 *   - Per-template version (meta) → invalidates one template
 *   - Global salt (option) → invalidates all templates
 */
class CacheManager {

	/**
	 * Cache group prefix prepended to the global salt.
	 *
	 * @var string
	 */
	public const CACHE_GROUP_PREFIX = 'spintax_';

	/**
	 * Settings repository for reading TTL and cache salt.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository|null $settings Optional settings repository instance.
	 */
	public function __construct( ?SettingsRepository $settings = null ) {
		$this->settings = $settings ?? new SettingsRepository();
	}

	/**
	 * Get cached output for a template + context combination.
	 *
	 * @param int    $template_id  Template post ID.
	 * @param string $context_hash RenderContext::get_context_hash().
	 * @return string|null Cached HTML or null on miss.
	 */
	public function get( int $template_id, string $context_hash ): ?string {
		$ttl = $this->get_effective_ttl( $template_id );
		if ( 0 === $ttl ) {
			return null;
		}

		$key   = $this->build_key( $template_id, $context_hash );
		$group = $this->get_group();
		$value = wp_cache_get( $key, $group );

		if ( false === $value ) {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Store rendered output in cache.
	 *
	 * @param int    $template_id  Template post ID.
	 * @param string $context_hash RenderContext::get_context_hash().
	 * @param string $output       Rendered HTML.
	 */
	public function set( int $template_id, string $context_hash, string $output ): void {
		$ttl = $this->get_effective_ttl( $template_id );
		if ( 0 === $ttl ) {
			return;
		}

		$key   = $this->build_key( $template_id, $context_hash );
		$group = $this->get_group();

		wp_cache_set( $key, $output, $group, $ttl );
	}

	/**
	 * Invalidate all cached variants for a single template.
	 *
	 * Bumps the per-template cache version so existing keys become stale.
	 *
	 * @param int $template_id Template post ID.
	 */
	public function invalidate_template( int $template_id ): void {
		$version = (int) get_post_meta( $template_id, OptionKeys::META_CACHE_VERSION, true );
		update_post_meta( $template_id, OptionKeys::META_CACHE_VERSION, $version + 1 );
		update_post_meta( $template_id, OptionKeys::META_LAST_REGENERATED, time() );
	}

	/**
	 * Invalidate ALL cached template output.
	 *
	 * Uses wp_cache_flush_group (WP 6.1+) when available,
	 * otherwise bumps the global salt so all keys rotate.
	 */
	public function invalidate_all(): void {
		$group = $this->get_group();

		// Bump salt first — new renders will use the new group.
		$this->settings->bump_cache_salt();

		// Flush the old group if the backend supports it.
		if ( function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( $group );
		}
	}

	/**
	 * Get effective TTL for a template.
	 *
	 * @param int $template_id Template post ID.
	 * @return int TTL in seconds. 0 = caching disabled.
	 */
	public function get_effective_ttl( int $template_id ): int {
		$override = get_post_meta( $template_id, OptionKeys::META_CACHE_TTL, true );

		if ( '' !== $override && false !== $override ) {
			return max( 0, (int) $override );
		}

		$settings = $this->settings->get();
		return max( 0, (int) $settings['default_ttl'] );
	}

	/**
	 * Get the per-template cache version.
	 *
	 * @param int $template_id Template post ID.
	 * @return int
	 */
	public function get_template_version( int $template_id ): int {
		$version = get_post_meta( $template_id, OptionKeys::META_CACHE_VERSION, true );
		return '' === $version ? 1 : (int) $version;
	}

	/**
	 * Build the cache key for a template + context.
	 *
	 * @param int    $template_id  Template post ID.
	 * @param string $context_hash Context hash string.
	 * @return string Cache key.
	 */
	private function build_key( int $template_id, string $context_hash ): string {
		$version = $this->get_template_version( $template_id );
		return "{$template_id}_{$version}_{$context_hash}";
	}

	/**
	 * Get the cache group name (includes global salt for invalidation).
	 */
	private function get_group(): string {
		$salt = $this->settings->get_cache_salt();
		return self::CACHE_GROUP_PREFIX . $salt;
	}
}
