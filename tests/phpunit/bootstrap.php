<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * @package Undergrowth
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php. Run the tests through wp-env: npm run test:php" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

require_once "{$_tests_dir}/includes/functions.php";

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/undergrowth.php';
	}
);

require "{$_tests_dir}/includes/bootstrap.php";

// The SQLite driver keeps its list of temporary tables in a table it makes
// with the first one. Until then, DROP TEMPORARY TABLE IF EXISTS fails and
// rolls back the test's transaction, so whatever the test writes next is
// kept for every later test. Uninstall drops tables that the suite turns
// into DROP TEMPORARY, so one temporary table is made up front.
if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
	$GLOBALS['wpdb']->query( 'CREATE TEMPORARY TABLE undergrowth_sqlite_temporary_tables (id int)' );
}

require __DIR__ . '/includes/rank-targets.php';
require __DIR__ . '/includes/trait-plants-posts.php';
require __DIR__ . '/includes/trait-signs-discord-interactions.php';
require __DIR__ . '/includes/trait-stops-redirects.php';

// The threshold meter reads Jetpack Stats. Without Jetpack, stand-ins answer
// with whatever a test sets.
if ( ! class_exists( 'Automattic\Jetpack\Modules' ) ) {
	require __DIR__ . '/includes/class-modules.php';
}

if ( ! class_exists( 'Automattic\Jetpack\Connection\Manager' ) ) {
	require __DIR__ . '/includes/class-manager.php';
}

if ( ! class_exists( 'Automattic\Jetpack\Stats\WPCOM_Stats' ) ) {
	require __DIR__ . '/includes/class-wpcom-stats.php';
}

if ( ! function_exists( 'pll_get_post_language' ) ) {
	require __DIR__ . '/includes/polylang.php';
}

// Composer loads WP-CLI's class with the i18n command, but not WP-CLI
// itself. Its helper functions and the commands file are loaded here, so the
// commands can be run with a logger that keeps what they print.
if ( class_exists( 'WP_CLI' ) ) {
	require_once dirname( __DIR__, 2 ) . '/vendor/wp-cli/wp-cli/php/utils.php';
	require __DIR__ . '/includes/class-undergrowth-test-cli-logger.php';
	require dirname( __DIR__, 2 ) . '/src/cli.php';
}
