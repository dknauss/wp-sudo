<?php
/**
 * PHPUnit bootstrap for WP Sudo integration tests.
 *
 * Loads the real WordPress test environment (WP_UnitTestCase, real DB).
 * Brain\Monkey and Patchwork are NOT loaded here — integration tests
 * use real WordPress functions, not mocks.
 *
 * Prerequisites: run `bash bin/install-wp-tests.sh` once before using this suite.
 *
 * @package WP_Sudo\Tests\Integration
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "ERROR: WordPress test library not found at {$_tests_dir}/includes/functions.php\n";
	echo "Run: bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest\n";
	exit( 1 );
}

// Polyfills path: tell the WP bootstrap where to find yoast/phpunit-polyfills.
// The WP bootstrap defaults to dirname(__DIR__, 3)/vendor/... which resolves to
// /tmp/vendor/... (wrong). Point it to this plugin's vendor directory instead.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

// Give access to tests_add_filter() before WordPress boots.
require_once $_tests_dir . '/includes/functions.php';

// Load the plugin under test at muplugins_loaded — the earliest safe hook.
// muplugins_loaded fires after WP core functions exist but before plugins_loaded.
// wp-sudo.php calls add_action(), which requires WordPress functions.
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require_once dirname( __DIR__, 2 ) . '/wp-sudo.php';
	}
);

// Composer autoloader for WP_Sudo\Tests\Integration\* classes.
// Do NOT require tests/bootstrap.php — it defines ABSPATH and class stubs
// that conflict with the real WordPress environment loaded below.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Boot the real WordPress environment (connects to MySQL, fires init hooks).
require $_tests_dir . '/includes/bootstrap.php';
