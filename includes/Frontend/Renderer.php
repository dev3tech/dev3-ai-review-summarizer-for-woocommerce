<?php
/**
 * Server-rendered summary widget (shared by hooks, shortcode, block and the admin preview).
 *
 * Accessible, JS-free markup. No schema.org/JSON-LD is printed: the summary is not a rating.
 *
 * @package Dev3\AiReviewSummarizer
 */

namespace Dev3\AiReviewSummarizer\Frontend;

use Dev3\AiReviewSummarizer\Core3\Core\Settings_Store;
use Dev3\AiReviewSummarizer\Reviews\Review_Repository;
use Dev3\AiReviewSummarizer\Settings;
use Dev3\AiReviewSummarizer\Summary\Summary_Parser;
use Dev3\AiReviewSummarizer\Summary\Summary_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Widget markup.
 */
class Renderer {

	const STYLE = 'dev3rs-summary';

	/**
	 * Instance counter for unique ids.
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Registers the stylesheet.
	 */
	public static function register_style() {
		wp_register_style( self::STYLE, DEV3RS_URL . 'assets/frontend/summary.css', array(), DEV3RS_VERSION );
	}

	/**
	 * Widget for a product, or '' when it has no summary.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $overrides  Display overrides (layout, show_* …).
	 * @return string
	 */
	public static function product( $product_id, array $overrides = array() ) {
		$product_id = (int) $product_id;
		if ( ! $product_id || 'product' !== get_post_type( $product_id ) || ( 'publish' !== get_post_status( $product_id ) && ! current_user_can( 'edit_post', $product_id ) ) ) {
			return '';
		}
		$record = Summary_Store::get( $product_id );
		if ( ! $record ) {
			return '';
		}
		// Reviews removed below the minimum: hide the outdated summary (a locked one is the moderator's call).
		$counts = Review_Repository::counts( array( $product_id ) );
		if ( ! Summary_Store::is_locked( $product_id ) && $counts[ $product_id ] < (int) Settings_Store::get( 'min_reviews', 3 ) ) {
			return '';
		}
		$display = Settings::sanitize_display( $overrides, Settings::display() );
		/**
		 * Filters display options for one product's widget.
		 *
		 * @param array $display    Display options.
		 * @param int   $product_id Product ID.
		 */
		$display = (array) apply_filters( 'dev3rs_display_options', $display, $product_id );
		$html    = self::record( $record, $display, $product_id );
		/**
		 * Filters the widget HTML.
		 *
		 * @param string $html       HTML ('' = nothing).
		 * @param int    $product_id Product ID.
		 * @param array  $record     Stored summary.
		 */
		return (string) apply_filters( 'dev3rs_summary_html', $html, $product_id, $record );
	}

	/**
	 * Widget for a stored (or sample) record.
	 *
	 * @param array $record     Record.
	 * @param array $display    Display options.
	 * @param int   $product_id Product ID (0 for previews).
	 * @return string
	 */
	public static function record( array $record, array $display, $product_id = 0 ) {
		$data = Summary_Parser::sanitize( $record );
		if ( ! Summary_Parser::is_usable( $data ) ) {
			return '';
		}
		wp_enqueue_style( self::STYLE );
		++self::$count;
		$uid     = 'dev3rs-rs-' . (int) $product_id . '-' . self::$count;
		$compact = 'compact' === $display['layout'];
		$count   = isset( $record['review_count'] ) ? (int) $record['review_count'] : 0;

		$style = sprintf(
			'--dev3rs-accent:%s;--dev3rs-bg:%s;--dev3rs-text:%s;--dev3rs-pos:%s;--dev3rs-neu:%s;--dev3rs-neg:%s;--dev3rs-border:%s;--dev3rs-radius:%dpx',
			$display['accent'],
			$display['background'],
			$display['text'],
			$display['positive'],
			$display['neutral'],
			$display['negative'],
			$display['border'],
			(int) $display['radius']
		);

		$out  = '<section class="dev3rs-rs dev3rs-rs--' . ( $compact ? 'compact' : 'card' ) . '" aria-labelledby="' . esc_attr( $uid ) . '-h" style="' . esc_attr( $style ) . '" data-product="' . (int) $product_id . '">';
		$out .= '<header class="dev3rs-rs__head">';
		$out .= '<h2 class="dev3rs-rs__title" id="' . esc_attr( $uid ) . '-h">' . self::sparkle() . esc_html( Settings::label( $display, 'heading' ) ) . '</h2>';
		if ( $display['show_count'] && $count > 0 ) {
			/* translators: %s: number of reviews */
			$out .= '<p class="dev3rs-rs__meta">' . esc_html( sprintf( _n( 'Based on %s customer review', 'Based on %s customer reviews', $count, 'dev3-ai-review-summarizer-for-woocommerce' ), number_format_i18n( $count ) ) ) . '</p>';
		}
		$out .= '</header>';

		if ( $display['show_summary'] && '' !== $data['summary'] ) {
			$out .= '<p class="dev3rs-rs__text">' . esc_html( $data['summary'] ) . '</p>';
		}
		if ( $display['show_sentiment'] ) {
			$out .= self::sentiment( $data['sentiment'] );
		}
		if ( $display['show_pros_cons'] && ( $data['pros'] || $data['cons'] ) ) {
			$out .= '<div class="dev3rs-rs__lists">';
			$out .= self::bullets( $data['pros'], Settings::label( $display, 'pros_title' ), 'pros', $uid );
			$out .= self::bullets( $data['cons'], Settings::label( $display, 'cons_title' ), 'cons', $uid );
			$out .= '</div>';
		}
		if ( $display['show_scores'] ) {
			$out .= self::scores( $data['scores'] );
		}
		if ( $display['show_keywords'] && $data['keywords'] ) {
			$out .= self::keywords( $data['keywords'], $uid );
		}
		if ( $display['show_disclaimer'] ) {
			$out .= '<p class="dev3rs-rs__note">' . esc_html__( 'Generated by AI from customer reviews. Individual experiences may vary.', 'dev3-ai-review-summarizer-for-woocommerce' ) . '</p>';
		}
		$out .= '</section>';
		return $out;
	}

	/**
	 * Stacked sentiment bar with a text legend.
	 *
	 * @param array $s Sentiment.
	 * @return string
	 */
	private static function sentiment( array $s ) {
		if ( array_sum( $s ) <= 0 ) {
			return '';
		}
		$labels = array(
			'positive' => __( 'Positive', 'dev3-ai-review-summarizer-for-woocommerce' ),
			'neutral'  => __( 'Neutral', 'dev3-ai-review-summarizer-for-woocommerce' ),
			'negative' => __( 'Negative', 'dev3-ai-review-summarizer-for-woocommerce' ),
		);
		/* translators: 1: positive %, 2: neutral %, 3: negative % */
		$aria = sprintf( __( 'Review sentiment: %1$d%% positive, %2$d%% neutral, %3$d%% negative', 'dev3-ai-review-summarizer-for-woocommerce' ), $s['positive'], $s['neutral'], $s['negative'] );
		$bar  = '';
		$leg  = '';
		foreach ( $labels as $key => $label ) {
			if ( $s[ $key ] > 0 ) {
				$bar .= '<span class="dev3rs-rs__seg dev3rs-rs__seg--' . esc_attr( $key ) . '" style="width:' . (int) $s[ $key ] . '%"></span>';
			}
			$leg .= '<li><span class="dev3rs-rs__dot dev3rs-rs__dot--' . esc_attr( $key ) . '" aria-hidden="true"></span>' . esc_html( $label ) . ' <strong>' . (int) $s[ $key ] . '%</strong></li>';
		}
		return '<div class="dev3rs-rs__sentiment"><div class="dev3rs-rs__bar" role="img" aria-label="' . esc_attr( $aria ) . '">' . $bar . '</div><ul class="dev3rs-rs__legend" aria-hidden="true">' . $leg . '</ul></div>';
	}

	/**
	 * Pros or cons list.
	 *
	 * @param string[] $items Items.
	 * @param string   $title Title.
	 * @param string   $kind  pros|cons.
	 * @param string   $uid   Unique id.
	 * @return string
	 */
	private static function bullets( array $items, $title, $kind, $uid ) {
		if ( ! $items ) {
			return '';
		}
		$id   = $uid . '-' . $kind;
		$icon = 'pros' === $kind
			? '<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8.2 13.6 4.6 10l-1.2 1.2 4.8 4.8 9-9-1.2-1.2z"/></svg>'
			: '<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16zm-.9 4h1.8v5H9.1zm0 6.5h1.8v1.8H9.1z"/></svg>';
		$out  = '<div class="dev3rs-rs__col dev3rs-rs__col--' . esc_attr( $kind ) . '"><h3 class="dev3rs-rs__subtitle" id="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</h3><ul aria-labelledby="' . esc_attr( $id ) . '">';
		foreach ( $items as $item ) {
			$out .= '<li><span class="dev3rs-rs__icon">' . $icon . '</span><span>' . esc_html( $item ) . '</span></li>';
		}
		return $out . '</ul></div>';
	}

	/**
	 * Quality/value meters and the sizing scale.
	 *
	 * @param array $scores Scores.
	 * @return string
	 */
	private static function scores( array $scores ) {
		$rows   = '';
		$labels = array(
			'quality' => __( 'Quality', 'dev3-ai-review-summarizer-for-woocommerce' ),
			'value'   => __( 'Value for money', 'dev3-ai-review-summarizer-for-woocommerce' ),
		);
		foreach ( $labels as $key => $label ) {
			if ( null === $scores[ $key ] ) {
				continue;
			}
			$val = (float) $scores[ $key ];
			/* translators: %s: score out of 5 */
			$txt   = sprintf( __( '%s out of 5', 'dev3-ai-review-summarizer-for-woocommerce' ), number_format_i18n( $val, 1 ) );
			$rows .= '<div class="dev3rs-rs__score"><dt>' . esc_html( $label ) . '</dt><dd><span class="dev3rs-rs__meter" aria-hidden="true"><span style="width:' . esc_attr( (string) round( $val / 5 * 100 ) ) . '%"></span></span><span class="dev3rs-rs__num">' . esc_html( number_format_i18n( $val, 1 ) ) . '<span class="dev3rs-rs__of">/5</span></span><span class="screen-reader-text dev3rs-rs__sr">' . esc_html( $txt ) . '</span></dd></div>';
		}
		$sizing = self::sizing_labels();
		if ( 'not_applicable' !== $scores['sizing'] && isset( $sizing[ $scores['sizing'] ] ) ) {
			$steps = array_keys( $sizing );
			$pos   = array_search( $scores['sizing'], $steps, true );
			$left  = round( $pos / ( count( $steps ) - 1 ), 2 );
			$rows .= '<div class="dev3rs-rs__score dev3rs-rs__score--fit"><dt>' . esc_html__( 'Fit', 'dev3-ai-review-summarizer-for-woocommerce' ) . '</dt><dd><span class="dev3rs-rs__fit" aria-hidden="true"><span class="dev3rs-rs__fit-track"></span><span class="dev3rs-rs__fit-mark" style="left:calc(7px + (100% - 14px) * ' . esc_attr( (string) $left ) . ')"></span><span class="dev3rs-rs__fit-ends"><span>' . esc_html__( 'Small', 'dev3-ai-review-summarizer-for-woocommerce' ) . '</span><span>' . esc_html__( 'True to size', 'dev3-ai-review-summarizer-for-woocommerce' ) . '</span><span>' . esc_html__( 'Large', 'dev3-ai-review-summarizer-for-woocommerce' ) . '</span></span></span><span class="dev3rs-rs__num dev3rs-rs__num--fit">' . esc_html( $sizing[ $scores['sizing'] ] ) . '</span></dd></div>';
		}
		return '' === $rows ? '' : '<dl class="dev3rs-rs__scores">' . $rows . '</dl>';
	}

	/**
	 * Sizing labels in scale order.
	 *
	 * @return array
	 */
	public static function sizing_labels() {
		return array(
			'runs_small'     => __( 'Runs small', 'dev3-ai-review-summarizer-for-woocommerce' ),
			'slightly_small' => __( 'Slightly small', 'dev3-ai-review-summarizer-for-woocommerce' ),
			'true_to_size'   => __( 'True to size', 'dev3-ai-review-summarizer-for-woocommerce' ),
			'slightly_large' => __( 'Slightly large', 'dev3-ai-review-summarizer-for-woocommerce' ),
			'runs_large'     => __( 'Runs large', 'dev3-ai-review-summarizer-for-woocommerce' ),
		);
	}

	/**
	 * Theme chips.
	 *
	 * @param array  $keywords Keywords.
	 * @param string $uid      Unique id.
	 * @return string
	 */
	private static function keywords( array $keywords, $uid ) {
		$tags = array(
			'positive' => __( 'positive', 'dev3-ai-review-summarizer-for-woocommerce' ),
			'neutral'  => __( 'neutral', 'dev3-ai-review-summarizer-for-woocommerce' ),
			'negative' => __( 'negative', 'dev3-ai-review-summarizer-for-woocommerce' ),
			'mixed'    => __( 'mixed', 'dev3-ai-review-summarizer-for-woocommerce' ),
		);
		$out  = '<div class="dev3rs-rs__themes"><h3 class="dev3rs-rs__subtitle" id="' . esc_attr( $uid ) . '-k">' . esc_html__( 'Mentioned in reviews', 'dev3-ai-review-summarizer-for-woocommerce' ) . '</h3><ul class="dev3rs-rs__chips" aria-labelledby="' . esc_attr( $uid ) . '-k">';
		foreach ( $keywords as $kw ) {
			$out .= '<li class="dev3rs-rs__chip dev3rs-rs__chip--' . esc_attr( $kw['sentiment'] ) . '"><span class="dev3rs-rs__dot dev3rs-rs__dot--' . esc_attr( $kw['sentiment'] ) . '" aria-hidden="true"></span>' . esc_html( $kw['term'] ) . '<span class="screen-reader-text dev3rs-rs__sr"> (' . esc_html( $tags[ $kw['sentiment'] ] ) . ')</span></li>';
		}
		return $out . '</ul></div>';
	}

	/**
	 * Decorative sparkle icon.
	 *
	 * @return string
	 */
	private static function sparkle() {
		return '<svg class="dev3rs-rs__spark" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2l1.9 5.1L19 9l-5.1 1.9L12 16l-1.9-5.1L5 9l5.1-1.9L12 2zm7 11l.95 2.05L22 16l-2.05.95L19 19l-.95-2.05L16 16l2.05-.95L19 13zM6 15l.7 1.3L8 17l-1.3.7L6 19l-.7-1.3L4 17l1.3-.7L6 15z"/></svg>';
	}

	/**
	 * Sample record for previews when no product is chosen.
	 *
	 * @return array
	 */
	public static function sample() {
		return array(
			'summary'      => __( 'Shoppers praise the soft, breathable fabric and say the colours stay vivid after many washes. Most find it great value, though several mention that it runs about half a size small.', 'dev3-ai-review-summarizer-for-woocommerce' ),
			'pros'         => array(
				__( 'Soft, breathable fabric', 'dev3-ai-review-summarizer-for-woocommerce' ),
				__( 'Colours hold up after washing', 'dev3-ai-review-summarizer-for-woocommerce' ),
				__( 'Fast delivery and neat packaging', 'dev3-ai-review-summarizer-for-woocommerce' ),
			),
			'cons'         => array(
				__( 'Runs about half a size small', 'dev3-ai-review-summarizer-for-woocommerce' ),
				__( 'Sleeves are a little long', 'dev3-ai-review-summarizer-for-woocommerce' ),
			),
			'sentiment'    => array(
				'positive' => 72,
				'neutral'  => 18,
				'negative' => 10,
			),
			'scores'       => array(
				'quality' => 4.4,
				'value'   => 4.1,
				'sizing'  => 'slightly_small',
			),
			'keywords'     => array(
				array(
					'term'      => __( 'fabric', 'dev3-ai-review-summarizer-for-woocommerce' ),
					'sentiment' => 'positive',
					'mentions'  => 9,
				),
				array(
					'term'      => __( 'fit', 'dev3-ai-review-summarizer-for-woocommerce' ),
					'sentiment' => 'mixed',
					'mentions'  => 7,
				),
				array(
					'term'      => __( 'price', 'dev3-ai-review-summarizer-for-woocommerce' ),
					'sentiment' => 'positive',
					'mentions'  => 5,
				),
				array(
					'term'      => __( 'sleeves', 'dev3-ai-review-summarizer-for-woocommerce' ),
					'sentiment' => 'negative',
					'mentions'  => 3,
				),
			),
			'review_count' => 24,
		);
	}
}
