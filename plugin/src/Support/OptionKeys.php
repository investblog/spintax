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

	/**
	 * Per-template plural locale meta key.
	 *
	 * Read by the renderer, the preview and the validator, each of which must resolve the
	 * same ladder — this key first, the site locale second. It was a bare string in one
	 * place until the validator became a second consumer.
	 *
	 * @var string
	 */
	public const META_LOCALE = '_spintax_locale';

	/**
	 * Bindings store option key (single autoloaded option, see spec §4.1).
	 *
	 * @var string
	 */
	public const BINDINGS = 'spintax_bindings';

	/**
	 * Per-post sibling meta prefix that holds the spintax source for
	 * `per_post`-mode bindings. Concatenated with `target.key`.
	 *
	 * @var string
	 */
	public const META_BINDING_SOURCE_PREFIX = '_spintax_source_';

	/**
	 * Per-post sibling meta prefix that holds the last-rendered signature
	 * hash for manual-edit detection. Concatenated with the binding id.
	 *
	 * @var string
	 */
	public const META_BINDING_RENDER_SIG_PREFIX = '_spintax_last_render_sig_';

	/**
	 * Option key prefix for per-binding cache version stamps used by the
	 * template-edit cascade (see spec §4.7a). Concatenated with the
	 * binding id; stored as an option, not post-meta.
	 *
	 * @var string
	 */
	public const OPTION_BINDING_CACHE_VERSION_PREFIX = '_spintax_binding_cache_v_';

	/**
	 * Option key prefix for the last cache-version a Bulk Apply / cron
	 * walk finished with. When `CACHE_VERSION_PREFIX` > this, the binding
	 * card surfaces a "Stale" badge until the next successful walk.
	 *
	 * @var string
	 */
	public const OPTION_BINDING_LAST_APPLIED_VERSION_PREFIX = '_spintax_binding_last_applied_v_';

	/**
	 * Option key prefix for the per-binding walk lock. Holds a unix
	 * timestamp set on `BulkApply::enqueue()` / `::run_synchronously()`
	 * and cleared on the final chunk. A new walk that finds a lock <1h
	 * old refuses to start (spec §4.10 added 2.0.3); locks older than
	 * that are treated as orphaned (crashed walk) and overwritten.
	 *
	 * @var string
	 */
	public const OPTION_BINDING_WALK_LOCK_PREFIX = '_spintax_binding_walk_lock_';

	/**
	 * Option key prefix for the per-binding cumulative-failure flag.
	 * Set to 1 by any walk chunk that records a failed post; checked on
	 * the final chunk to gate `OPTION_BINDING_LAST_APPLIED_VERSION_PREFIX`
	 * (spec §4.10 added 2.0.3). Cleared by `BulkApply::enqueue` /
	 * `run_synchronously` when a new walk starts and by the final chunk
	 * when it completes (or aborts).
	 *
	 * @var string
	 */
	public const OPTION_BINDING_WALK_FAILED_PREFIX = '_spintax_binding_walk_failed_v_';
}
