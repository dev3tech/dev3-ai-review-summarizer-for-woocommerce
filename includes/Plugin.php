<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiReviewSummarizer
 */

namespace Dev3\AiReviewSummarizer;

use Dev3\AiReviewSummarizer\Core3\Core\Assets;
use Dev3\AiReviewSummarizer\Core3\Core\Schema;
use Dev3\AiReviewSummarizer\Jobs\Bulk_Job;
use Dev3\AiReviewSummarizer\Jobs\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3rs_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3RS_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		delete_transient( Scheduler::CHECK_KEY );
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( 'dev3rs_license_check' ); // Licence check from builds before licensing was removed.
		Scheduler::unschedule_all();
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		add_filter( 'dev3rs_settings_defaults', array( Settings::class, 'defaults' ) );
		add_filter( 'dev3rs_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
	}

	/**
	 * WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function woo_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		load_plugin_textdomain( 'dev3-ai-review-summarizer-for-woocommerce', false, dirname( plugin_basename( DEV3RS_FILE ) ) . '/languages' );
		self::register_schema();
		// Moderating product summaries is a shop-manager task (filterable; settings stay manage_options).
		add_filter( 'dev3rs_capability', static fn() => 'manage_woocommerce', 5 );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3RS_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action(
			'init',
			static fn() => Assets::register(
				array( 'admin', 'block' ),
				array(
					'wooActive'   => self::woo_active(),
					'adminUrl'    => admin_url(),
					'canModerate' => current_user_can( Core3\Core\Capabilities::generate() ),
				)
			)
		);
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
		if ( ! self::woo_active() ) {
			add_action( 'admin_notices', array( $this, 'woo_notice' ) );
			return;
		}
		( new Jobs\Scheduler() )->register();
		Bulk_Job::register();
		( new Frontend\Display() )->register();
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		if ( self::woo_active() ) {
			( new Rest\Summary_Controller() )->register_routes();
		}
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public function woo_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html( DEV3RS_NAME ),
			esc_html__( 'needs WooCommerce. Install and activate WooCommerce to summarise product reviews.', 'dev3-ai-review-summarizer-for-woocommerce' )
		);
	}
}
