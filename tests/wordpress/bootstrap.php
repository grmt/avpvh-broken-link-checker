<?php
/**
 * Bootstrap for the WordPress integration tests.
 *
 * WP_TESTS_DIR points at the WordPress test suite (tests/phpunit of wordpress-develop),
 * WP_TESTS_PHPUNIT_POLYFILLS_PATH at the PHPUnit Polyfills; the shared CI workflow and the
 * README set both.
 *
 * @package Avpvh_Broken_Link_Checker
 */

// phpcs:disable

$avpvh_brkn_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $avpvh_brkn_tests_dir || ! is_file( $avpvh_brkn_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Set WP_TESTS_DIR to the WordPress test suite (wordpress-develop/tests/phpunit).\n" );
	exit( 1 );
}

// The core of the plugin only loads in the admin and in cron; the tests drive it as an admin.
define( 'WP_ADMIN', true );

require_once $avpvh_brkn_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		// The module manager finds its modules with get_plugins(), which only looks in the
		// plugins directory: link the checkout in there, as an installed plugin would be.
		$source = dirname( __DIR__, 2 );
		$target = WP_PLUGIN_DIR . '/avpvh-broken-link-checker';
		if ( ! file_exists( $target ) ) {
			wp_mkdir_p( WP_PLUGIN_DIR );
			symlink( $source, $target );
		}
		wp_register_plugin_realpath( $target . '/avpvh-broken-link-checker.php' );

		require $source . '/avpvh-broken-link-checker.php';
	}
);

require $avpvh_brkn_tests_dir . '/includes/bootstrap.php';

require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once __DIR__ . '/class-test-case.php';
