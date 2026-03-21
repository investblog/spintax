<?php
/**
 * Default values for plugin settings and data structures.
 *
 * @package Spintax
 */

namespace Spintax\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Static factory methods for default configuration values.
 */
final class Defaults {

	/**
	 * Default plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		return array(
			'default_ttl'        => 3600,
			'editors_can_manage' => true,
			'debug'              => false,
			'logs_max'           => 200,
		);
	}

	/**
	 * Default global variables (empty).
	 *
	 * @return array<string, string>
	 */
	public static function global_variables(): array {
		return array();
	}

	/**
	 * Allowed cron schedule values.
	 *
	 * @return string[]
	 */
	public static function cron_schedules(): array {
		return array( 'disabled', 'hourly', 'twicedaily', 'daily' );
	}

	/**
	 * Allowed log levels.
	 *
	 * @return string[]
	 */
	public static function log_levels(): array {
		return array( 'info', 'warning', 'error', 'debug' );
	}
}
