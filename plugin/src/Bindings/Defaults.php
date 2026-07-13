<?php
/**
 * Default values for binding entities and binding-domain enums.
 *
 * @package Spintax
 */

namespace Spintax\Bindings;

defined( 'ABSPATH' ) || exit;

use Spintax\Bindings\Target\TargetRegistry;

/**
 * Static factory methods for binding defaults (see spec §4.1).
 *
 * Kept separate from `Spintax\Support\Defaults` (plugin-wide settings/cron)
 * so binding-domain helpers stay together with the rest of the Bindings
 * namespace and can evolve independently.
 */
final class Defaults {

	/**
	 * Hard cap on bindings per site (autoloaded option size budget).
	 *
	 * @var int
	 */
	public const MAX_BINDINGS = 200;

	/**
	 * Default chunk size for Bulk Apply / cron walks.
	 *
	 * @var int
	 */
	public const DEFAULT_CHUNK_SIZE = 20;

	/**
	 * Allowed chunk size range — per-binding override clamps to this.
	 *
	 * @var int
	 */
	public const MIN_CHUNK_SIZE = 1;

	/**
	 * Maximum allowed chunk size (per-binding override clamps to this).
	 *
	 * @var int
	 */
	public const MAX_CHUNK_SIZE = 200;

	/**
	 * Default binding shape (see spec §4.1).
	 *
	 * `id`, `created_at` and `updated_at` are stamped by `BindingsRepo`
	 * on create — they are absent here on purpose.
	 *
	 * @return array<string, mixed>
	 */
	public static function binding(): array {
		return array(
			'post_type' => '',
			'status'    => 'any',
			'target'    => array(
				'kind'      => 'acf_field',
				'key'       => '',
				'field_key' => '',
			),
			'source'    => array(
				'mode'        => 'template',
				'template_id' => 0,
			),
			'variables' => array(
				'expose_post_context'    => true,
				'expose_product_context' => false,
				'expose_acf_siblings'    => false,
				'overrides'              => '',
			),
			'triggers'  => array(
				'save_post'     => true,
				// Reserved for V2; ignored by the V1 trigger pipeline (spec §4.7).
				'acf_save_post' => false,
				'cron'          => 'disabled',
			),
			'behavior'  => array(
				'auto_seed_empty'       => true,
				'regenerate_on_save'    => false,
				'preserve_manual_edits' => true,
				'clear_on_empty'        => false,
				'chunk_size'            => self::DEFAULT_CHUNK_SIZE,
			),
		);
	}

	/**
	 * Allowed values for `binding.target.kind`.
	 *
	 * @return string[]
	 */
	public static function target_kinds(): array {
		return TargetRegistry::ids();
	}

	/**
	 * Allowed values for `binding.source.mode`.
	 *
	 * @return string[]
	 */
	public static function source_modes(): array {
		return array( 'template', 'per_post' );
	}

	/**
	 * Allowed values for `binding.status`.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array( 'any', 'publish' );
	}

	/**
	 * Allowed cron schedule values for `binding.triggers.cron`.
	 *
	 * Matches `Spintax\Support\Defaults::cron_schedules()` so existing
	 * `CronManager` machinery can be reused unchanged.
	 *
	 * @return string[]
	 */
	public static function cron_schedules(): array {
		return array( 'disabled', 'hourly', 'twicedaily', 'daily' );
	}
}
