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

	// --- wp_options -----------------------------------------------------------

	/** @var string Plugin settings (array). */
	public const SETTINGS = 'spintax_settings';

	/** @var string Global variables (array of name => value). */
	public const GLOBAL_VARIABLES = 'spintax_global_variables';

	/** @var string Global cache salt/version (int). */
	public const CACHE_SALT = 'spintax_cache_salt';

	/** @var string Debug log entries (array). */
	public const LOGS = 'spintax_logs';

	// --- wp_postmeta (template CPT) ------------------------------------------

	/** @var string Per-template cache TTL override in seconds (int, 0 = no cache). */
	public const META_CACHE_TTL = '_spintax_cache_ttl';

	/** @var string Per-template cron schedule (string: disabled|hourly|twicedaily|daily). */
	public const META_CRON_SCHEDULE = '_spintax_cron_schedule';

	/** @var string Per-template cache version counter (int). */
	public const META_CACHE_VERSION = '_spintax_cache_version';

	/** @var string Embedded template IDs for dependency tracking (array of int). */
	public const META_EMBEDS = '_spintax_embeds';

	/** @var string Last default regeneration timestamp (int). */
	public const META_LAST_REGENERATED = '_spintax_last_regenerated_at';
}
