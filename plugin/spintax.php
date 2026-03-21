<?php
/**
 * Plugin Name:       Spintax
 * Plugin URI:        https://github.com/investblog/spintax
 * Description:       Spintax processing for WordPress.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            301.st
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
	}
);

/**
 * Deactivation hook — clear cron events but keep data.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'spintax_cron_regenerate' );
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

		// Load global helper function.
		require_once SPINTAX_PLUGIN_DIR . 'src/Core/Render/functions.php';

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
