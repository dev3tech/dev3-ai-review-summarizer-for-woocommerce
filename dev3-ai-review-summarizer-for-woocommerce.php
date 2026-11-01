<?php
/**
 * Plugin Name:       DEV3 AI Review Summarizer & Sentiment Analyzer for WooCommerce
 * Plugin URI:        https://dev3.tech/item/dev3-ai-review-summarizer-for-woocommerce
 * Description:       AI review summaries with pros & cons, sentiment and quality, value and sizing scores on WooCommerce product pages.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   11.2
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-review-summarizer-for-woocommerce
 * Domain Path:       /languages
 *
 * @package Dev3\AiReviewSummarizer
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3RS_VERSION', '1.0.0' );
define( 'DEV3RS_FILE', __FILE__ );
define( 'DEV3RS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3RS_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3RS_SLUG', 'dev3-ai-review-summarizer-for-woocommerce' );
define( 'DEV3RS_NAME', 'DEV3 AI Review Summarizer & Sentiment Analyzer for WooCommerce' );
define( 'DEV3RS_MIN_PHP', '7.4' );
define( 'DEV3RS_MIN_WP', '6.0' );
define( 'DEV3RS_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3RS_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\\AiReviewSummarizer\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3RS_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// WooCommerce feature compatibility: HPOS (we never touch orders) and the block-based cart/checkout.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEV3RS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEV3RS_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiReviewSummarizer\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiReviewSummarizer\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiReviewSummarizer\Plugin', 'instance' ) );
