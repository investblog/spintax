<?php
/**
 * Plugin Name:       Spintax
 * Plugin URI:        https://spintax.net
 * Description:       Template-based dynamic content generation using spintax markup. Create reusable templates with randomised text variants, variable substitution, and permutation logic.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            301st
 * Author URI:        https://301.st
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       spintax
 * Domain Path:       /languages
 *
 * @package Spintax
 */

namespace Spintax;

defined( 'ABSPATH' ) || exit;

define( 'SPINTAX_VERSION', '1.0.0' );
define( 'SPINTAX_PLUGIN_FILE', __FILE__ );
define( 'SPINTAX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPINTAX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPINTAX_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4 Autoloader.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'Spintax\\';
		$len    = strlen( $prefix );

		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative = substr( $class, $len );
		$file     = SPINTAX_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * Activation hook.
 */
register_activation_hook(
	__FILE__,
	function () {
		$settings = new Core\Settings\SettingsRepository();
		$settings->init_cache_salt();

		$s = $settings->get();
		Support\Capabilities::register( $s['editors_can_manage'] );

		// CPT must be registered before flushing rewrite rules.
		$cpt = new Core\PostType\TemplatePostType();
		$cpt->register();
		flush_rewrite_rules( false );

		// Seed a demo template on first activation.
		spintax_maybe_seed_demo();
	}
);

/**
 * Create a demo template if none exist yet.
 */
function spintax_maybe_seed_demo(): void {
	$existing = get_posts(
		array(
			'post_type'      => Core\PostType\TemplatePostType::POST_TYPE,
			'posts_per_page' => 1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		)
	);

	if ( ! empty( $existing ) ) {
		return;
	}

	$demo = <<<'SPINTAX'
/#
  Spintax Demo Template
  This template showcases the core syntax features.
  Edit it or create your own templates under Spintax > Add New.
#/

#set %product% = {Widget|Gadget|Tool}
#set %company% = {Acme|Nova|Apex} {Corp|Inc|Labs}
#set %features% = [<minsize=2;maxsize=3;sep=", ";lastsep=" and "> lightweight|durable|affordable|eco-friendly|portable|customizable]

<h2>{Introducing|Meet|Discover} the %product% by %company%</h2>

<p>{Our|The} {latest|newest|brand-new} %product% is {designed|built|crafted} for {professionals|creators|everyday use}. It is %features%.</p>

<p>{Key|Main|Top} {benefits|advantages|highlights}:</p>
<ul>
[<minsize=3;maxsize=4;> <li>{Fast|Quick|Rapid} {setup|installation} — {ready|up and running} in {minutes|under 5 minutes}.</li>
|<li>{Works|Compatible} with [<minsize=2;maxsize=3;sep=", ";lastsep=" and "> Windows|macOS|Linux|Android|iOS].</li>
|<li>{Free|Complimentary} {updates|upgrades} for {a year|12 months|the first year}.</li>
|<li>{24/7|Round-the-clock} {support|customer service} via [< and > email|live chat].</li>
|<li>{Trusted|Used} by [<minsize=2;maxsize=3;sep=", ";lastsep=" and "> startups|enterprises|freelancers|agencies|universities].</li>]
</ul>

<p>{Get started|Try it|Learn more} {today|now} at <strong>%company%</strong>.</p>
SPINTAX;

	wp_insert_post(
		array(
			'post_type'    => Core\PostType\TemplatePostType::POST_TYPE,
			'post_title'   => 'Demo Template',
			'post_name'    => 'demo',
			'post_content' => $demo,
			'post_status'  => 'publish',
		)
	);
}

/**
 * Deactivation hook — clear cron events but keep data.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		Core\Cron\CronManager::clear_all();
	}
);

/**
 * Initialize plugin on plugins_loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		// Register CPT.
		$cpt = new Core\PostType\TemplatePostType();
		$cpt->init();

		// Register [spintax] shortcode.
		$shortcode = new Core\Shortcode\ShortcodeController();
		$shortcode->init();

		// Register cron handler.
		$cron = new Core\Cron\CronManager();
		$cron->init();

		// Load global helper function.
		require_once SPINTAX_PLUGIN_DIR . 'src/Core/Render/functions.php';

		// Admin UI.
		if ( is_admin() ) {
			$admin_menu = new Admin\AdminMenu();
			$admin_menu->init();
		}

		// Invalidate cache when a template is saved.
		add_action(
			'save_post_' . Core\PostType\TemplatePostType::POST_TYPE,
			function ( int $post_id ): void {
				if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
					return;
				}
				$cache = new Core\Cache\CacheManager();
				$cache->invalidate_template( $post_id );

				$deps = new Core\Cache\DependencyInvalidator( $cache );
				$deps->invalidate_dependents( $post_id );
			}
		);

		// Sync capabilities with current settings.
		if ( is_admin() ) {
			$settings = new Core\Settings\SettingsRepository();
			$s        = $settings->get();
			Support\Capabilities::sync( $s['editors_can_manage'] );
		}
	}
);

/**
 * Add "Settings" link on the plugins page.
 */
add_filter(
	'plugin_action_links_' . SPINTAX_PLUGIN_BASENAME,
	function ( array $links ): array {
		$url  = admin_url( 'options-general.php?page=spintax-settings' );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'spintax' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}
);
