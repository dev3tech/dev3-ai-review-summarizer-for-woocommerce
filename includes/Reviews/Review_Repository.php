<?php
/**
 * Reads approved WooCommerce product reviews (comment_type = review) and their ratings.
 *
 * Reviewer names, emails, IPs and user agents are never read here, so they can never reach the AI.
 *
 * @package Dev3\AiReviewSummarizer
 */

namespace Dev3\AiReviewSummarizer\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * Review aggregation.
 */
class Review_Repository {

	/**
	 * Longest review text (characters) sent for one review.
	 */
	const MAX_REVIEW_CHARS = 1200;

	/**
	 * Approved reviews for a product, newest first, privacy-safe.
	 *
	 * @param int $product_id Product ID.
	 * @param int $limit      Max reviews (0 = all).
	 * @return array[] List of { id, rating, text, date, verified }.
	 */
	public static function for_product( $product_id, $limit = 0 ) {
		$args = array(
			'post_id' => (int) $product_id,
			'status'  => 'approve',
			'type'    => 'review',
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
			'fields'  => '',
		);
		if ( $limit > 0 ) {
			$args['number'] = (int) $limit;
		}
		$out = array();
		foreach ( get_comments( $args ) as $comment ) {
			$text = self::clean_text( $comment->comment_content );
			if ( '' === $text ) {
				continue;
			}
			$out[] = array(
				'id'       => (int) $comment->comment_ID,
				'rating'   => (int) get_comment_meta( $comment->comment_ID, 'rating', true ),
				'text'     => $text,
				'date'     => mysql2date( 'Y-m', $comment->comment_date_gmt ),
				'verified' => (bool) get_comment_meta( $comment->comment_ID, 'verified', true ),
			);
		}
		return $out;
	}

	/**
	 * Strips markup, redacts contact details/links and trims a review.
	 *
	 * @param string $text Raw review.
	 * @return string
	 */
	public static function clean_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text );
		$text = preg_replace( '#\b(?:https?://|www\.)\S+#i', '[link]', $text );
		$text = preg_replace_callback(
			'/\+?\d[\d\s().-]{7,}\d/',
			static function ( $m ) {
				// Phone-like only when it has 9+ digits and few spaces (so size runs such as "38 40 42" survive).
				$digits = preg_match_all( '/\d/', $m[0] );
				return $digits >= 9 && substr_count( $m[0], ' ' ) <= 3 ? '[phone]' : $m[0];
			},
			$text
		);
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		if ( mb_strlen( $text ) > self::MAX_REVIEW_CHARS ) {
			$text = rtrim( mb_substr( $text, 0, self::MAX_REVIEW_CHARS ) ) . '…';
		}
		return $text;
	}

	/**
	 * Fingerprint of a product's approved review set (ids, text and rating), used to skip unchanged products.
	 *
	 * @param int $product_id Product ID.
	 * @return array { hash, count, average }
	 */
	public static function signature( $product_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fingerprint must be live.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.comment_ID AS id, MD5(c.comment_content) AS h, COALESCE(m.meta_value, '0') AS r
				FROM {$wpdb->comments} c
				LEFT JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = 'rating'
				WHERE c.comment_post_ID = %d AND c.comment_approved = '1' AND c.comment_type = 'review'
				ORDER BY c.comment_ID ASC",
				(int) $product_id
			),
			ARRAY_A
		);
		$parts = array();
		$sum   = 0;
		$rated = 0;
		foreach ( (array) $rows as $row ) {
			$parts[] = $row['id'] . ':' . $row['h'] . ':' . (int) $row['r'];
			if ( (int) $row['r'] > 0 ) {
				$sum += (int) $row['r'];
				++$rated;
			}
		}
		return array(
			'hash'    => $parts ? md5( implode( '|', $parts ) ) : '',
			'count'   => count( $parts ),
			'average' => $rated ? round( $sum / $rated, 2 ) : 0.0,
		);
	}

	/**
	 * Signatures for many products in one query (same result as signature() per product).
	 *
	 * @param int[] $product_ids IDs.
	 * @return array<int,array> id => { hash, count, average }
	 */
	public static function signatures( array $product_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $product_ids ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = array(
				'hash'    => '',
				'count'   => 0,
				'average' => 0.0,
			);
		}
		if ( ! $ids ) {
			return $out;
		}
		$in = implode( ',', $ids );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
		$rows = $wpdb->get_results(
			"SELECT c.comment_post_ID AS pid, c.comment_ID AS id, MD5(c.comment_content) AS h, COALESCE(m.meta_value, '0') AS r
			FROM {$wpdb->comments} c
			LEFT JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = 'rating'
			WHERE c.comment_post_ID IN ($in) AND c.comment_approved = '1' AND c.comment_type = 'review'
			ORDER BY c.comment_post_ID ASC, c.comment_ID ASC",
			ARRAY_A
		);
		// phpcs:enable
		$parts = array();
		$sums  = array();
		foreach ( (array) $rows as $row ) {
			$pid             = (int) $row['pid'];
			$parts[ $pid ][] = $row['id'] . ':' . $row['h'] . ':' . (int) $row['r'];
			if ( (int) $row['r'] > 0 ) {
				$sums[ $pid ][0] = ( isset( $sums[ $pid ][0] ) ? $sums[ $pid ][0] : 0 ) + (int) $row['r'];
				$sums[ $pid ][1] = ( isset( $sums[ $pid ][1] ) ? $sums[ $pid ][1] : 0 ) + 1;
			}
		}
		foreach ( $parts as $pid => $list ) {
			$out[ $pid ] = array(
				'hash'    => md5( implode( '|', $list ) ),
				'count'   => count( $list ),
				'average' => isset( $sums[ $pid ] ) ? round( $sums[ $pid ][0] / $sums[ $pid ][1], 2 ) : 0.0,
			);
		}
		return $out;
	}

	/**
	 * Approved review counts for many products.
	 *
	 * @param int[] $product_ids IDs.
	 * @return array<int,int>
	 */
	public static function counts( array $product_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $product_ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
		$rows = $wpdb->get_results( "SELECT comment_post_ID AS id, COUNT(*) AS n FROM {$wpdb->comments} WHERE comment_post_ID IN ($in) AND comment_approved = '1' AND comment_type = 'review' GROUP BY comment_post_ID", ARRAY_A );
		$out  = array_fill_keys( $ids, 0 );
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Store-wide: published products with at least $min approved reviews.
	 *
	 * @param int $min   Minimum approved reviews.
	 * @param int $limit Max products (0 = all).
	 * @return int[]
	 */
	public static function products_with_reviews( $min = 1, $limit = 0 ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT c.comment_post_ID FROM {$wpdb->comments} c
			INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID AND p.post_type = 'product' AND p.post_status = 'publish'
			WHERE c.comment_approved = '1' AND c.comment_type = 'review'
			GROUP BY c.comment_post_ID HAVING COUNT(*) >= %d ORDER BY c.comment_post_ID ASC",
			max( 1, (int) $min )
		);
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', (int) $limit );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Whether a comment is an approved-or-not product review.
	 *
	 * @param \WP_Comment|int $comment Comment.
	 * @return int Product ID or 0.
	 */
	public static function review_product( $comment ) {
		$comment = get_comment( $comment );
		if ( ! $comment || 'review' !== $comment->comment_type ) {
			return 0;
		}
		return 'product' === get_post_type( (int) $comment->comment_post_ID ) ? (int) $comment->comment_post_ID : 0;
	}
}
