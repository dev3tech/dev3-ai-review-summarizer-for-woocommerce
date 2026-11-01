<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiReviewSummarizer
 */

namespace Dev3\AiReviewSummarizer;

use Dev3\AiReviewSummarizer\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3rs_settings_defaults / dev3rs_settings_sanitize.
 */
class Settings {

	const POSITIONS = array( 'above_reviews', 'add_to_cart', 'tab', 'off' );
	const LAYOUTS   = array( 'card', 'compact' );
	const LANGUAGES = array( 'store', 'reviews' );
	const COLORS    = array( 'accent', 'background', 'text', 'positive', 'neutral', 'negative', 'border' );
	const TOGGLES   = array( 'show_summary', 'show_pros_cons', 'show_scores', 'show_sentiment', 'show_keywords', 'show_count', 'show_disclaimer' );
	const LABELS    = array( 'heading', 'pros_title', 'cons_title', 'tab_title' );

	/**
	 * Display defaults (colours, layout, toggles, labels; empty labels use the translated default).
	 *
	 * @return array
	 */
	public static function display_defaults() {
		return array(
			'position'        => 'above_reviews',
			'layout'          => 'card',
			'accent'          => '#4f46e5',
			'background'      => '#ffffff',
			'text'            => '#1f2937',
			'positive'        => '#16a34a',
			'neutral'         => '#9ca3af',
			'negative'        => '#dc2626',
			'border'          => '#e5e7eb',
			'radius'          => 12,
			'show_summary'    => true,
			'show_pros_cons'  => true,
			'show_scores'     => true,
			'show_sentiment'  => true,
			'show_keywords'   => true,
			'show_count'      => true,
			'show_disclaimer' => true,
			'heading'         => '',
			'pros_title'      => '',
			'cons_title'      => '',
			'tab_title'       => '',
		);
	}

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'min_reviews'      => 3,
				'max_reviews'      => 80,
				'auto_generate'    => true,
				'debounce_minutes' => 10,
				'nightly_refresh'  => true,
				'language'         => 'store',
				'display'          => self::display_defaults(),
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		$ints = array(
			'min_reviews'      => array( 1, 500 ),
			'max_reviews'      => array( 5, 300 ),
			'debounce_minutes' => array( 0, 1440 ),
		);
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = min( $range[1], max( $range[0], (int) $input[ $key ] ) );
			}
		}
		foreach ( array( 'auto_generate', 'nightly_refresh' ) as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = self::to_bool( $input[ $flag ] );
			}
		}
		if ( isset( $input['language'] ) && in_array( $input['language'], self::LANGUAGES, true ) ) {
			$out['language'] = $input['language'];
		}
		if ( isset( $input['display'] ) && is_array( $input['display'] ) ) {
			$base           = isset( $current['display'] ) && is_array( $current['display'] ) ? $current['display'] : array();
			$out['display'] = self::sanitize_display( $input['display'], array_merge( self::display_defaults(), $base ) );
		}
		return $out;
	}

	/**
	 * Sanitizes display options (also used for previews and shortcode/block overrides).
	 *
	 * @param array $input Raw.
	 * @param array $base  Values to start from.
	 * @return array
	 */
	public static function sanitize_display( array $input, array $base ) {
		$out = array_intersect_key( array_merge( self::display_defaults(), $base ), self::display_defaults() );
		if ( isset( $input['position'] ) && in_array( $input['position'], self::POSITIONS, true ) ) {
			$out['position'] = $input['position'];
		}
		if ( isset( $input['layout'] ) && in_array( $input['layout'], self::LAYOUTS, true ) ) {
			$out['layout'] = $input['layout'];
		}
		foreach ( self::COLORS as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$color = sanitize_hex_color( (string) $input[ $key ] );
				if ( $color ) {
					$out[ $key ] = $color;
				}
			}
		}
		if ( isset( $input['radius'] ) && is_numeric( $input['radius'] ) ) {
			$out['radius'] = min( 32, max( 0, (int) $input['radius'] ) );
		}
		foreach ( self::TOGGLES as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = self::to_bool( $input[ $key ] );
			}
		}
		foreach ( self::LABELS as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = mb_substr( sanitize_text_field( (string) $input[ $key ] ), 0, 80 );
			}
		}
		return $out;
	}

	/**
	 * Current display options.
	 *
	 * @return array
	 */
	public static function display() {
		$stored = Settings_Store::get( 'display', array() );
		return array_merge( self::display_defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Display label with its translated default.
	 *
	 * @param array  $display Display options.
	 * @param string $key     Label key.
	 * @return string
	 */
	public static function label( array $display, $key ) {
		if ( ! empty( $display[ $key ] ) ) {
			return (string) $display[ $key ];
		}
		$defaults = array(
			'heading'    => __( 'AI review summary', 'dev3-ai-review-summarizer-for-woocommerce' ),
			'pros_title' => __( 'What buyers love', 'dev3-ai-review-summarizer-for-woocommerce' ),
			'cons_title' => __( 'Things to consider', 'dev3-ai-review-summarizer-for-woocommerce' ),
			'tab_title'  => __( 'Review summary', 'dev3-ai-review-summarizer-for-woocommerce' ),
		);
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}

	/**
	 * Loose boolean ("false", "0", "no", "off" are false).
	 *
	 * @param mixed $value Raw.
	 * @return bool
	 */
	public static function to_bool( $value ) {
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
		}
		return (bool) $value;
	}
}
