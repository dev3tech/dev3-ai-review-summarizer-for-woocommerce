<?php
/**
 * Normalises and sanitises a summary (AI output or a moderator's edit) into the stored shape.
 *
 * @package Dev3\AiReviewSummarizer
 */

namespace Dev3\AiReviewSummarizer\Summary;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions: no I/O.
 */
class Summary_Parser {

	const MAX_SUMMARY = 700;
	const MAX_BULLET  = 140;
	const MAX_BULLETS = 5;
	const MAX_TERM    = 40;
	const MAX_TERMS   = 8;

	/**
	 * Sanitises a decoded summary.
	 *
	 * @param mixed $data Decoded JSON / request body.
	 * @return array { summary, pros[], cons[], sentiment{positive,neutral,negative}, scores{quality,value,sizing}, keywords[], flagged[] }
	 */
	public static function sanitize( $data ) {
		$data = is_array( $data ) ? $data : array();
		return array(
			'summary'   => self::text( isset( $data['summary'] ) ? $data['summary'] : '', self::MAX_SUMMARY ),
			'pros'      => self::bullets( isset( $data['pros'] ) ? $data['pros'] : array() ),
			'cons'      => self::bullets( isset( $data['cons'] ) ? $data['cons'] : array() ),
			'sentiment' => self::sentiment( isset( $data['sentiment'] ) ? $data['sentiment'] : array() ),
			'scores'    => self::scores( isset( $data['scores'] ) ? $data['scores'] : array() ),
			'keywords'  => self::keywords( isset( $data['keywords'] ) ? $data['keywords'] : array() ),
			'flagged'   => self::flagged( isset( $data['flagged'] ) ? $data['flagged'] : array() ),
		);
	}

	/**
	 * Whether a sanitised summary has anything worth showing.
	 *
	 * @param array $summary Sanitised summary.
	 * @return bool
	 */
	public static function is_usable( array $summary ) {
		return '' !== ( isset( $summary['summary'] ) ? $summary['summary'] : '' ) || ! empty( $summary['pros'] ) || ! empty( $summary['cons'] );
	}

	/**
	 * Plain text: no tags, no links, single line, capped.
	 *
	 * @param mixed $value Raw.
	 * @param int   $max   Max chars.
	 * @return string
	 */
	public static function text( $value, $max ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = wp_strip_all_tags( (string) $value );
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		// Links never belong in a summary (defence in depth against injected reviews).
		$value = preg_replace( '#\b(?:https?://|www\.)\S+#i', '', $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $value );
		$value = trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
		$value = trim( $value, " \t-•*" );
		if ( mb_strlen( $value ) > $max ) {
			$cut   = mb_substr( $value, 0, $max - 1 );
			$space = mb_strrpos( $cut, ' ' );
			$value = rtrim( false !== $space && $space > $max * 0.6 ? mb_substr( $cut, 0, $space ) : $cut, ' ,;:' ) . '…';
		}
		return $value;
	}

	/**
	 * Bullet list (array or newline-separated string), unique, capped.
	 *
	 * @param mixed $raw_list Raw.
	 * @return string[]
	 */
	public static function bullets( $raw_list ) {
		if ( is_string( $raw_list ) ) {
			$raw_list = preg_split( '/\r\n|\r|\n/', $raw_list );
		}
		$out  = array();
		$seen = array();
		foreach ( (array) $raw_list as $item ) {
			$item = self::text( $item, self::MAX_BULLET );
			$key  = mb_strtolower( $item );
			if ( '' === $item || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $item;
			if ( count( $out ) >= self::MAX_BULLETS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Sentiment shares as integers that add up to 100 (largest-remainder rounding).
	 *
	 * @param mixed $raw Raw.
	 * @return array{positive:int,neutral:int,negative:int}
	 */
	public static function sentiment( $raw ) {
		$raw  = is_array( $raw ) ? $raw : array();
		$keys = array( 'positive', 'neutral', 'negative' );
		$vals = array();
		foreach ( $keys as $key ) {
			$vals[ $key ] = isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ? max( 0.0, (float) $raw[ $key ] ) : 0.0;
		}
		$total = array_sum( $vals );
		if ( $total <= 0 ) {
			return array(
				'positive' => 0,
				'neutral'  => 0,
				'negative' => 0,
			);
		}
		// Scale to 100 (models sometimes answer in 0-1 fractions or with totals that are off by a few).
		$scale = 100 / $total;
		$out   = array();
		$rems  = array();
		foreach ( $vals as $key => $val ) {
			$exact        = $val * $scale;
			$out[ $key ]  = (int) floor( $exact );
			$rems[ $key ] = $exact - floor( $exact );
		}
		$left = 100 - array_sum( $out );
		arsort( $rems );
		foreach ( array_keys( $rems ) as $key ) {
			if ( $left <= 0 ) {
				break;
			}
			++$out[ $key ];
			--$left;
		}
		return array(
			'positive' => $out['positive'],
			'neutral'  => $out['neutral'],
			'negative' => $out['negative'],
		);
	}

	/**
	 * Quality/value 1-5 (one decimal, or null) and sizing from the allowed scale.
	 *
	 * @param mixed $raw Raw.
	 * @return array{quality:float|null,value:float|null,sizing:string}
	 */
	public static function scores( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( array( 'quality', 'value' ) as $key ) {
			$val         = isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ? (float) $raw[ $key ] : null;
			$out[ $key ] = ( null === $val || $val <= 0 ) ? null : round( min( 5.0, max( 1.0, $val ) ), 1 );
		}
		$sizing        = isset( $raw['sizing'] ) ? strtolower( str_replace( array( ' ', '-' ), '_', trim( (string) $raw['sizing'] ) ) ) : '';
		$aliases       = array(
			'small'  => 'runs_small',
			'true'   => 'true_to_size',
			'large'  => 'runs_large',
			'n/a'    => 'not_applicable',
			'na'     => 'not_applicable',
			'none'   => 'not_applicable',
			''       => 'not_applicable',
			'fits'   => 'true_to_size',
			'normal' => 'true_to_size',
		);
		$sizing        = isset( $aliases[ $sizing ] ) ? $aliases[ $sizing ] : $sizing;
		$out['sizing'] = in_array( $sizing, Prompt::SIZING, true ) ? $sizing : 'not_applicable';
		return $out;
	}

	/**
	 * Keywords with a sentiment tag.
	 *
	 * @param mixed $raw_list Raw.
	 * @return array[] { term, sentiment, mentions }
	 */
	public static function keywords( $raw_list ) {
		$out  = array();
		$seen = array();
		foreach ( (array) $raw_list as $item ) {
			if ( is_string( $item ) ) {
				$item = array( 'term' => $item );
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$term = self::text( isset( $item['term'] ) ? $item['term'] : '', self::MAX_TERM );
			$key  = mb_strtolower( $term );
			if ( '' === $term || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$tag          = isset( $item['sentiment'] ) ? strtolower( (string) $item['sentiment'] ) : 'neutral';
			$out[]        = array(
				'term'      => $term,
				'sentiment' => in_array( $tag, Prompt::TAGS, true ) ? $tag : 'neutral',
				'mentions'  => isset( $item['mentions'] ) && is_numeric( $item['mentions'] ) ? max( 1, min( 9999, (int) $item['mentions'] ) ) : 1,
			);
			if ( count( $out ) >= self::MAX_TERMS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Flagged review numbers.
	 *
	 * @param mixed $raw_list Raw.
	 * @return int[]
	 */
	public static function flagged( $raw_list ) {
		$out = array();
		foreach ( (array) $raw_list as $n ) {
			if ( is_numeric( $n ) && (int) $n > 0 ) {
				$out[] = (int) $n;
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return array_slice( $out, 0, 50 );
	}
}
