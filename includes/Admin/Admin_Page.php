<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiReviewSummarizer
 */

namespace Dev3\AiReviewSummarizer\Admin;

use Dev3\AiReviewSummarizer\Core3\Core\Assets;
use Dev3\AiReviewSummarizer\Core3\Core\Capabilities;
use Dev3\AiReviewSummarizer\Frontend\Renderer;
use Dev3\AiReviewSummarizer\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → DEV3 AI Review Summaries (top-level menu when WooCommerce is inactive).
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3RS_FILE ), array( $this, 'links' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$title = __( 'DEV3 AI Review Summaries', 'dev3-ai-review-summarizer-for-woocommerce' );
		$cap   = Capabilities::generate();
		if ( Plugin::woo_active() ) {
			$this->hook = (string) add_submenu_page( 'woocommerce', $title, $title, $cap, DEV3RS_SLUG, array( $this, 'render' ) );
		} else {
			$this->hook = (string) add_menu_page( $title, $title, Capabilities::manage(), DEV3RS_SLUG, array( $this, 'render' ), 'dashicons-star-half', 58 );
		}
	}

	/**
	 * Mount point.
	 */
	public function render() {
		echo '<div class="wrap"><div id="dev3rs-admin-root" class="dev3rs-root"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Assets::enqueue( 'admin' );
			wp_enqueue_style( Renderer::STYLE );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3RS_SLUG ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-review-summarizer-for-woocommerce' ) . '</a>' );
		return $links;
	}
}
