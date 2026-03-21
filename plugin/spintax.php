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
		// Activation logic.
	}
);

/**
 * Deactivation hook.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		// Deactivation logic.
	}
);

/**
 * Initialize plugin.
 */
add_action(
	'plugins_loaded',
	function () {
		// Plugin initialization.
	}
);
