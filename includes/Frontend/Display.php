<?php
/**
 * Auto-insertion on single product pages (classic and block themes), the shortcode and the block.
 *
 * @package Dev3\AiReviewSummarizer
 */

namespace Dev3\AiReviewSummarizer\Frontend;

use Dev3\AiReviewSummarizer\Settings;
use Dev3\AiReviewSummarizer\Summary\Summary_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Storefront wiring.
 */
class Display {

	const SHORTCODE = 'dev3rs_summary';
	const BLOCK     = 'dev3rs/review-summary';

	/**
	 * Products already rendered by the auto-insert (avoids duplicates when a theme fires two hooks).
	 *
	 * @var array<int,bool>
	 */
	private $done = array();

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'init', array( Renderer::class, 'register_style' ), 5 );
		add_action( 'init', array( $this, 'register_block' ), 20 );
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp', array( $this, 'position_hooks' ) );
	}

	/**
	 * Stylesheet on product pages that have a summary (shortcode/block enqueue it themselves).
	 */
	public function enqueue() {
		if ( function_exists( 'is_product' ) && is_product() && Summary_Store::get( get_queried_object_id() ) ) {
			wp_enqueue_style( Renderer::STYLE );
		}
	}

	/**
	 * Adds the hooks for the chosen position.
	 */
	public function position_hooks() {
		if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$position = Settings::display()['position'];
		/**
		 * Filters the auto-insert position (above_reviews, add_to_cart, tab, off) for this request.
		 *
		 * @param string $position Position.
		 * @param int    $product_id Product ID.
		 */
		$position = (string) apply_filters( 'dev3rs_position', $position, get_queried_object_id() );
		if ( 'above_reviews' === $position ) {
			add_filter( 'woocommerce_product_tabs', array( $this, 'wrap_reviews_tab' ), 98 );
			add_filter( 'render_block_woocommerce/product-reviews', array( $this, 'prepend_block' ), 10, 2 );
			add_filter( 'render_block_woocommerce/product-reviews-title', array( $this, 'prepend_block' ), 10, 2 );
		} elseif ( 'add_to_cart' === $position ) {
			// Fires right after the add-to-cart form in classic templates and in the Add to Cart Form block.
			add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'echo_current' ) );
			if ( ! wp_is_block_theme() ) {
				// Fallback for products without a form (e.g. out of stock). Block themes map this hook elsewhere.
				add_action( 'woocommerce_single_product_summary', array( $this, 'echo_current' ), 31 );
			}
			add_filter( 'render_block_woocommerce/add-to-cart-form', array( $this, 'append_block' ), 10, 2 );
			add_filter( 'render_block_woocommerce/add-to-cart-with-options', array( $this, 'append_block' ), 10, 2 );
		} elseif ( 'tab' === $position ) {
			add_filter( 'woocommerce_product_tabs', array( $this, 'add_tab' ), 20 );
		}
	}

	/**
	 * Current single product ID.
	 *
	 * @return int
	 */
	private function current_id() {
		global $product;
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			return (int) $product->get_id();
		}
		return (int) get_queried_object_id();
	}

	/**
	 * Widget for the current product, once per request.
	 *
	 * @return string
	 */
	private function once() {
		$id = $this->current_id();
		if ( ! $id || isset( $this->done[ $id ] ) ) {
			return '';
		}
		$html = Renderer::product( $id );
		if ( '' !== $html ) {
			$this->done[ $id ] = true;
		}
		return $html;
	}

	/**
	 * Classic: next to the add-to-cart button.
	 */
	public function echo_current() {
		echo $this->once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Renderer.
	}

	/**
	 * Classic/product-details block: the summary at the top of the Reviews tab, above the list.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function wrap_reviews_tab( $tabs ) {
		if ( empty( $tabs['reviews']['callback'] ) ) {
			return $tabs;
		}
		$original                    = $tabs['reviews']['callback'];
		$tabs['reviews']['callback'] = function ( ...$args ) use ( $original ) {
			echo $this->once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Renderer.
			call_user_func_array( $original, $args );
		};
		return $tabs;
	}

	/**
	 * Block themes: before the Product Reviews block.
	 *
	 * @param string $content Block HTML.
	 * @param array  $block   Block.
	 * @return string
	 */
	public function prepend_block( $content, $block ) {
		unset( $block );
		return $this->once() . $content;
	}

	/**
	 * Block themes: after the add-to-cart block.
	 *
	 * @param string $content Block HTML.
	 * @param array  $block   Block.
	 * @return string
	 */
	public function append_block( $content, $block ) {
		unset( $block );
		return $content . $this->once();
	}

	/**
	 * Own "Review summary" tab (only when there is a summary).
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function add_tab( $tabs ) {
		$id = $this->current_id();
		if ( ! $id || ! Summary_Store::get( $id ) ) {
			return $tabs;
		}
		$tabs['dev3rs_summary'] = array(
			'title'    => Settings::label( Settings::display(), 'tab_title' ),
			'priority' => 25,
			'callback' => function () {
				echo $this->once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Renderer.
			},
		);
		return $tabs;
	}

	/**
	 * [dev3rs_summary id="123" layout="compact" show_scores="no" …].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'             => 0,
				'layout'         => '',
				'show_summary'   => '',
				'show_pros_cons' => '',
				'show_scores'    => '',
				'show_sentiment' => '',
				'show_keywords'  => '',
				'heading'        => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		$id   = (int) $atts['id'] ? (int) $atts['id'] : $this->current_id();
		unset( $atts['id'] );
		return Renderer::product( $id, array_filter( $atts, 'strlen' ) );
	}

	/**
	 * Dynamic block (server-rendered; the editor shows a ServerSideRender preview).
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			DEV3RS_PATH . 'assets/blocks/review-summary',
			array( 'render_callback' => array( $this, 'render_block' ) )
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array          $attributes Attributes.
	 * @param string         $content    Inner content.
	 * @param \WP_Block|null $block      Block.
	 * @return string
	 */
	public function render_block( $attributes, $content = '', $block = null ) {
		unset( $content );
		$id = isset( $attributes['productId'] ) ? (int) $attributes['productId'] : 0;
		if ( ! $id && $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ) {
			$id = (int) $block->context['postId'];
		}
		if ( ! $id ) {
			$id = $this->current_id();
		}
		$overrides = array();
		if ( ! empty( $attributes['layout'] ) ) {
			$overrides['layout'] = $attributes['layout'];
		}
		foreach ( array(
			'showScores'    => 'show_scores',
			'showSentiment' => 'show_sentiment',
			'showKeywords'  => 'show_keywords',
		) as $attr => $key ) {
			if ( isset( $attributes[ $attr ] ) && '' !== $attributes[ $attr ] ) {
				$overrides[ $key ] = 'show' === $attributes[ $attr ];
			}
		}
		$html = Renderer::product( $id, $overrides );
		if ( '' === $html ) {
			return '';
		}
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes( array( 'class' => 'dev3rs-block' ) ) : 'class="dev3rs-block"';
		return '<div ' . $wrapper . '>' . $html . '</div>';
	}
}
