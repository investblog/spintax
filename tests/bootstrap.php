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

require_once "{$_tests_dir}/includes/functions.php";

tests_add_filter(
	'mute_deprecations',
	function () {
		return true;
	}
);

tests_add_filter(
	'plugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/plugin/spintax.php';
	}
);

require "{$_tests_dir}/includes/bootstrap.php";
