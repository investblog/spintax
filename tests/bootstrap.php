<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Spintax
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php\n";
	exit( 1 );
}

// Load PHPUnit Polyfills (required by WP test suite).
$_polyfills_candidates = array(
	dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php',
	'/tmp/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php',
);
foreach ( $_polyfills_candidates as $_pf ) {
	if ( file_exists( $_pf ) ) {
		require_once $_pf;
		break;
	}
}

require_once "{$_tests_dir}/includes/functions.php";

tests_add_filter(
	'mute_deprecations',
	function () {
		return true;
	}
);

tests_add_filter(
	'muplugins_loaded',
	function () {
		// wp-env maps ./plugin to the plugin dir, so spintax.php is at the root.
		$candidates = array(
			dirname( __DIR__ ) . '/spintax.php',
			dirname( __DIR__ ) . '/plugin/spintax.php',
		);
		foreach ( $candidates as $path ) {
			if ( file_exists( $path ) ) {
				require $path;
				return;
			}
		}
		echo "Could not find spintax.php bootstrap.\n";
		exit( 1 );
	}
);

require "{$_tests_dir}/includes/bootstrap.php";
