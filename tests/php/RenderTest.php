<?php
/**
 * Storefront: shortcode, block, auto-insert positions, escaping, no fake schema.
 *
 * @package Dev3\AiReviewSummarizer
 */

use Dev3\AiReviewSummarizer\Core3\Core\Settings_Store;
use Dev3\AiReviewSummarizer\Frontend\Display;
use Dev3\AiReviewSummarizer\Frontend\Renderer;
use Dev3\AiReviewSummarizer\Summary\Summarizer;

class RenderTest extends WP_UnitTestCase {
	use DEV3RS_Fixtures;

	public function set_up() {
		parent::set_up();
		$this->dev3rs_setup();
	}

	public function tear_down() {
		$this->dev3rs_teardown();
		parent::tear_down();
	}

	public function test_shortcode_renders_nothing_without_summary() {
		$pid = $this->product_with_reviews( 3 );
		$this->assertSame( '', do_shortcode( '[dev3rs_summary id="' . $pid . '"]' ) );
		$this->assertSame( '', do_shortcode( '[dev3rs_summary id="999999"]' ) );
		$this->assertSame( '', do_shortcode( '[dev3rs_summary]' ), 'no current product' );
	}

	public function test_shortcode_renders_accessible_escaped_widget() {
		$pid                         = $this->product_with_reviews( 3 );
		$this->ai_payload            = self::good_payload();
		$this->ai_payload['pros'][]  = 'Fits "great" & <i>comfy</i>';
		Summarizer::generate( $pid );
		$html = do_shortcode( '[dev3rs_summary id="' . $pid . '"]' );
		$this->assertStringContainsString( '<section class="dev3rs-rs dev3rs-rs--card"', $html );
		$this->assertStringContainsString( 'aria-labelledby=', $html );
		$this->assertStringContainsString( 'What buyers love', $html );
		$this->assertStringContainsString( 'Things to consider', $html );
		$this->assertStringContainsString( 'role="img" aria-label="Review sentiment: 70% positive, 20% neutral, 10% negative"', $html );
		$this->assertStringContainsString( 'Runs small', $html );
		$this->assertStringContainsString( 'Fits &quot;great&quot; &amp; comfy', $html );
		$this->assertStringNotContainsString( '<i>', $html );
		$this->assertStringNotContainsString( 'ld+json', $html );
		$this->assertStringNotContainsString( 'itemprop', $html );
		$this->assertStringNotContainsString( 'Gemini', $html );
		$this->assertTrue( wp_style_is( Renderer::STYLE, 'enqueued' ) );

		$compact = do_shortcode( '[dev3rs_summary id="' . $pid . '" layout="compact" show_scores="no" show_keywords="false"]' );
		$this->assertStringContainsString( 'dev3rs-rs--compact', $compact );
		$this->assertStringNotContainsString( 'dev3rs-rs__scores', $compact );
		$this->assertStringNotContainsString( 'dev3rs-rs__chips', $compact );
	}

	public function test_hidden_when_reviews_drop_below_threshold_unless_locked() {
		$pid = $this->product_with_reviews( 3 );
		Summarizer::generate( $pid );
		Settings_Store::update( array( 'min_reviews' => 5 ) );
		$this->assertSame( '', Renderer::product( $pid ) );
		\Dev3\AiReviewSummarizer\Summary\Summary_Store::set_locked( $pid, true );
		$this->assertNotSame( '', Renderer::product( $pid ) );
	}

	public function test_block_render_and_registration() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'dev3rs/review-summary' ) );
		$pid = $this->product_with_reviews( 3 );
		$this->assertSame( '', render_block( array( 'blockName' => 'dev3rs/review-summary', 'attrs' => array( 'productId' => $pid ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) ) );
		Summarizer::generate( $pid );
		$html = render_block( array( 'blockName' => 'dev3rs/review-summary', 'attrs' => array( 'productId' => $pid, 'layout' => 'compact', 'showSentiment' => 'hide' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
		$this->assertStringContainsString( 'wp-block-dev3rs-review-summary', $html );
		$this->assertStringContainsString( 'dev3rs-rs--compact', $html );
		$this->assertStringNotContainsString( 'dev3rs-rs__bar', $html );
	}

	public function test_auto_insert_positions() {
		$pid = $this->product_with_reviews( 3 );
		Summarizer::generate( $pid );
		$display = new Display();
		$this->go_to( get_permalink( $pid ) );
		$GLOBALS['product'] = wc_get_product( $pid ); // phpcs:ignore

		// Tab position adds our tab before Reviews only when a summary exists.
		$tabs = $display->add_tab( array( 'reviews' => array( 'title' => 'Reviews', 'priority' => 30, 'callback' => '__return_null' ) ) );
		$this->assertArrayHasKey( 'dev3rs_summary', $tabs );
		$this->assertSame( 'Review summary', $tabs['dev3rs_summary']['title'] );
		ob_start();
		call_user_func( $tabs['dev3rs_summary']['callback'] );
		$this->assertStringContainsString( 'dev3rs-rs', ob_get_clean() );

		// Above reviews wraps the Reviews tab callback and prints once.
		$display  = new Display();
		$wrapped  = $display->wrap_reviews_tab(
			array(
				'reviews' => array(
					'callback' => static function () {
						echo '<div id="reviews"></div>';
					},
				),
			)
		);
		ob_start();
		call_user_func( $wrapped['reviews']['callback'], 'reviews', array() );
		call_user_func( $wrapped['reviews']['callback'], 'reviews', array() );
		$out = ob_get_clean();
		$this->assertSame( 1, substr_count( $out, '<section class="dev3rs-rs' ), 'rendered once' );
		$this->assertLessThan( strpos( $out, 'id="reviews"' ), strpos( $out, 'dev3rs-rs' ), 'above the list' );

		// Near add to cart (block themes): appended after the add-to-cart block.
		$display = new Display();
		$this->assertStringStartsWith( '<form>', $display->append_block( '<form></form>', array() ) );
		$this->assertStringContainsString( 'dev3rs-rs', $display->append_block( '<form></form>', array() ) . ( new Display() )->append_block( '', array() ) );

		// No summary → no tab.
		$empty              = $this->product_with_reviews( 3 );
		$GLOBALS['product'] = wc_get_product( $empty ); // phpcs:ignore
		$this->assertArrayNotHasKey( 'dev3rs_summary', ( new Display() )->add_tab( array() ) );
	}

	public function test_position_hooks_follow_setting() {
		$pid = $this->product_with_reviews( 3 );
		Summarizer::generate( $pid );
		$this->go_to( get_permalink( $pid ) );
		Settings_Store::update( array( 'display' => array( 'position' => 'add_to_cart' ) ) );
		$display = new Display();
		$display->position_hooks();
		$this->assertNotFalse( has_action( 'woocommerce_after_add_to_cart_form', array( $display, 'echo_current' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_product_tabs', array( $display, 'add_tab' ) ) );

		Settings_Store::update( array( 'display' => array( 'position' => 'off' ) ) );
		$off = new Display();
		$off->position_hooks();
		$this->assertFalse( has_action( 'woocommerce_after_add_to_cart_form', array( $off, 'echo_current' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_product_tabs', array( $off, 'wrap_reviews_tab' ) ) );
	}
}
