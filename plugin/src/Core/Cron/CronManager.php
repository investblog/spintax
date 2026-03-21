<?php
/**
 * Per-template WP-Cron regeneration.
 *
 * @package Spintax
 */

namespace Spintax\Core\Cron;

defined( 'ABSPATH' ) || exit;

use Spintax\Core\Cache\CacheManager;
use Spintax\Core\Cache\DependencyInvalidator;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Core\Render\Renderer;
use Spintax\Support\Defaults;
use Spintax\Support\OptionKeys;

/**
 * Manages per-template WP-Cron schedules for cache regeneration.
 */
class CronManager {

	public const HOOK = 'spintax_cron_regenerate';

	/**
	 * Register the cron callback.
	 */
	public function init(): void {
		add_action( self::HOOK, array( $this, 'handle' ), 10, 1 );
	}

	/**
	 * Cron callback: invalidate cache and warm default context.
	 *
	 * @param int $template_id Template post ID.
	 */
	public function handle( int $template_id ): void {
		$post = get_post( $template_id );
		if ( ! $post || TemplatePostType::POST_TYPE !== $post->post_type ) {
			return;
		}

		$cache = new CacheManager();
		$cache->invalidate_template( $template_id );

		$deps = new DependencyInvalidator( $cache );
		$deps->invalidate_dependents( $template_id );

		// Warm the default context.
		$renderer = new Renderer();
		$renderer->render( $template_id );

		// Reschedule.
		$this->sync_schedule( $template_id );
	}

	/**
	 * Sync the cron schedule for a template based on its meta.
	 *
	 * Call after saving template meta to create/update/remove the event.
	 *
	 * @param int $template_id Template post ID.
	 */
	public function sync_schedule( int $template_id ): void {
		// Always clear existing schedule first.
		$this->unschedule( $template_id );

		$schedule = (string) get_post_meta( $template_id, OptionKeys::META_CRON_SCHEDULE, true );
		$allowed  = Defaults::cron_schedules();

		if ( '' === $schedule || 'disabled' === $schedule || ! in_array( $schedule, $allowed, true ) ) {
			return;
		}

		// Only schedule cron if caching is enabled (TTL > 0).
		$cache = new CacheManager();
		if ( 0 === $cache->get_effective_ttl( $template_id ) ) {
			return;
		}

		wp_schedule_event( time(), $schedule, self::HOOK, array( $template_id ) );
	}

	/**
	 * Remove the cron event for a template.
	 *
	 * @param int $template_id Template post ID.
	 */
	public function unschedule( int $template_id ): void {
		$timestamp = wp_next_scheduled( self::HOOK, array( $template_id ) );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK, array( $template_id ) );
		}
	}

	/**
	 * Clear all spintax cron events (called on deactivation).
	 */
	public static function clear_all(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
