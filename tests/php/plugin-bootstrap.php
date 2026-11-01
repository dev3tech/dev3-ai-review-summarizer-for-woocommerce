<?php
/**
 * Loads WooCommerce (from wp-env) before the plugin, then the shared bootstrap.
 *
 * @package Dev3\AiReviewSummarizer
 */

$dev3rs_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/wordpress-phpunit';
require_once $dev3rs_tests_dir . '/includes/functions.php';

tests_add_filter(
	'dev3rs_test_dependencies',
	static function ( $deps ) {
		foreach ( array( 'woocommerce/woocommerce.php', 'woocommerce.latest-stable/woocommerce.php' ) as $dep ) {
			if ( defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/' . $dep ) ) {
				$deps[] = $dep;
				break;
			}
		}
		return $deps;
	}
);

// WooCommerce tables, roles (shop_manager) and Action Scheduler tables.
tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
			$GLOBALS['wp_roles'] = null; // phpcs:ignore
			wp_roles();
		}
	}
);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/helpers.php';
