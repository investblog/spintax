<?php
/**
 * Centralised option and meta key constants.
 *
 * @package Spintax
 */

namespace Spintax\Support;

defined( 'ABSPATH' ) || exit;

/**
 * All WordPress option and post-meta keys used by the plugin.
 */
final class OptionKeys {

	/**
	 * Plugin settings option key.
	 *
	 * @var string
	 */
	public const SETTINGS = 'spintax_settings';

	/**
	 * Global variables parsed key-value pairs.
	 *
	 * @var string
	 */
	public const GLOBAL_VARIABLES = 'spintax_global_variables';

	/**
	 * Global variables raw #set text (for the editor).
	 *
	 * @var string
	 */
	public const GLOBAL_VARIABLES_RAW = 'spintax_global_variables_raw';

	/**
	 * Global cache salt/version option key.
	 *
	 * @var string
	 */
	public const CACHE_SALT = 'spintax_cache_salt';

	/**
	 * Debug log entries option key.
	 *
	 * @var string
	 */
	public const LOGS = 'spintax_logs';

	/**
	 * Per-template cache TTL override in seconds.
	 *
	 * @var string
	 */
	public const META_CACHE_TTL = '_spintax_cache_ttl';

	/**
	 * Per-template cron schedule meta key.
	 *
	 * @var string
	 */
	public const META_CRON_SCHEDULE = '_spintax_cron_schedule';

	/**
	 * Per-template cache version counter meta key.
	 *
	 * @var string
	 */
	public const META_CACHE_VERSION = '_spintax_cache_version';

	/**
	 * Embedded template IDs for dependency tracking.
	 *
	 * @var string
	 */
	public const META_EMBEDS = '_spintax_embeds';

	/**
	 * Last default regeneration timestamp meta key.
	 *
	 * @var string
	 */
	public const META_LAST_REGENERATED = '_spintax_last_regenerated_at';
}
