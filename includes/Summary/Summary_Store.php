<?php
/**
 * Summary cache in product post meta.
 *
 * @package Dev3\AiReviewSummarizer
 */

namespace Dev3\AiReviewSummarizer\Summary;

use Dev3\AiReviewSummarizer\Core3\Core\Settings_Store;
use Dev3\AiReviewSummarizer\Reviews\Review_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * `_dev3rs_summary` holds the summary plus { hash, model, generated_at, review_count, average, source, edited_* };
 * `_dev3rs_locked` = '1' when a moderator locked it (never auto-overwritten).
 */
class Summary_Store {

	const META   = '_dev3rs_summary';
	const LOCKED = '_dev3rs_locked';

	const STATUS_NONE   = 'none';
	const STATUS_FRESH  = 'fresh';
	const STATUS_STALE  = 'stale';
	const STATUS_LOCKED = 'locked';

	/**
	 * Stored record or null.
	 *
	 * @param int $product_id Product ID.
	 * @return array|null
	 */
	public static function get( $product_id ) {
		$record = get_post_meta( (int) $product_id, self::META, true );
		return is_array( $record ) && ! empty( $record ) ? $record : null;
	}

	/**
	 * Whether a moderator locked it.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function is_locked( $product_id ) {
		return '1' === (string) get_post_meta( (int) $product_id, self::LOCKED, true );
	}

	/**
	 * Saves a summary.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $summary    Sanitised summary.
	 * @param array $meta       Extra record keys (hash, model, source…).
	 * @return array Record.
	 */
	public static function save( $product_id, array $summary, array $meta ) {
		$record = array_merge( $summary, $meta );
		update_post_meta( (int) $product_id, self::META, wp_slash( $record ) );
		/**
		 * Fires after a summary is saved.
		 *
		 * @param int   $product_id Product ID.
		 * @param array $record     Stored record.
		 */
		do_action( 'dev3rs_summary_saved', (int) $product_id, $record );
		return $record;
	}

	/**
	 * Locks or unlocks.
	 *
	 * @param int  $product_id Product ID.
	 * @param bool $locked     Locked.
	 */
	public static function set_locked( $product_id, $locked ) {
		if ( $locked ) {
			update_post_meta( (int) $product_id, self::LOCKED, '1' );
		} else {
			delete_post_meta( (int) $product_id, self::LOCKED );
		}
	}

	/**
	 * Deletes the summary (and lock).
	 *
	 * @param int $product_id Product ID.
	 */
	public static function delete( $product_id ) {
		delete_post_meta( (int) $product_id, self::META );
		delete_post_meta( (int) $product_id, self::LOCKED );
	}

	/**
	 * Hash of the review set plus what changes the output (language, prompt version).
	 *
	 * @param string $review_hash Review_Repository::signature() hash.
	 * @return string
	 */
	public static function fingerprint( $review_hash ) {
		return md5( $review_hash . '|' . Settings_Store::get( 'language', 'store' ) . '|' . get_locale() . '|' . Prompt::VERSION );
	}

	/**
	 * Status for a product: none | fresh | stale | locked.
	 *
	 * @param int        $product_id Product ID.
	 * @param array|null $signature  Precomputed Review_Repository::signature().
	 * @return string
	 */
	public static function status( $product_id, $signature = null ) {
		$record = self::get( $product_id );
		if ( ! $record ) {
			return self::STATUS_NONE;
		}
		if ( self::is_locked( $product_id ) ) {
			return self::STATUS_LOCKED;
		}
		$signature = is_array( $signature ) ? $signature : Review_Repository::signature( $product_id );
		return ( isset( $record['hash'] ) && self::fingerprint( $signature['hash'] ) === $record['hash'] ) ? self::STATUS_FRESH : self::STATUS_STALE;
	}

	/**
	 * Statuses for many products (one review query, primed meta cache).
	 *
	 * @param int[] $product_ids IDs.
	 * @return array<int,string>
	 */
	public static function statuses( array $product_ids ) {
		$out = array();
		foreach ( array_chunk( array_map( 'intval', $product_ids ), 200 ) as $chunk ) {
			update_meta_cache( 'post', $chunk );
			$signatures = Review_Repository::signatures( $chunk );
			foreach ( $chunk as $id ) {
				$out[ $id ] = self::status( $id, $signatures[ $id ] );
			}
		}
		return $out;
	}

	/**
	 * Admin view of a product's summary state.
	 *
	 * @param int        $product_id Product ID.
	 * @param array|null $signature  Precomputed signature.
	 * @return array
	 */
	public static function describe( $product_id, $signature = null ) {
		$signature = $signature ? $signature : Review_Repository::signature( $product_id );
		$record    = self::get( $product_id );
		$product   = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		return array(
			'id'           => (int) $product_id,
			'name'         => $product ? wp_strip_all_tags( $product->get_name() ) : get_the_title( $product_id ),
			'permalink'    => get_permalink( $product_id ),
			'edit_link'    => get_edit_post_link( $product_id, 'raw' ),
			'thumbnail'    => (string) get_the_post_thumbnail_url( $product_id, 'thumbnail' ),
			'review_count' => $signature['count'],
			'average'      => $signature['average'],
			'eligible'     => $signature['count'] >= (int) Settings_Store::get( 'min_reviews', 3 ),
			'status'       => self::status( $product_id, $signature ),
			'locked'       => self::is_locked( $product_id ),
			'changed'      => $record && ( ! isset( $record['hash'] ) || self::fingerprint( $signature['hash'] ) !== $record['hash'] ),
			'summary'      => $record ? self::public_view( $record ) : null,
			'meta'         => $record ? array(
				'model'        => isset( $record['model'] ) ? $record['model'] : '',
				'generated_at' => isset( $record['generated_at'] ) ? (int) $record['generated_at'] : 0,
				'edited_at'    => isset( $record['edited_at'] ) ? (int) $record['edited_at'] : 0,
				'source'       => isset( $record['source'] ) ? $record['source'] : 'ai',
				'review_count' => isset( $record['review_count'] ) ? (int) $record['review_count'] : 0,
				'flagged'      => self::flagged_reviews( $record ),
			) : null,
		);
	}

	/**
	 * Reviews the AI flagged (instructions/spam), with moderation links.
	 *
	 * @param array $record Record.
	 * @return array[] { id, excerpt, link }
	 */
	private static function flagged_reviews( array $record ) {
		$out = array();
		foreach ( isset( $record['flagged_ids'] ) ? (array) $record['flagged_ids'] : array() as $comment_id ) {
			$comment = get_comment( (int) $comment_id );
			if ( ! $comment ) {
				continue;
			}
			$out[] = array(
				'id'      => (int) $comment_id,
				'excerpt' => wp_html_excerpt( wp_strip_all_tags( $comment->comment_content ), 120, '…' ),
				'link'    => admin_url( 'comment.php?action=editcomment&c=' . (int) $comment_id ),
			);
		}
		return $out;
	}

	/**
	 * The fields a shopper may see.
	 *
	 * @param array $record Stored record.
	 * @return array
	 */
	public static function public_view( array $record ) {
		$clean = Summary_Parser::sanitize( $record );
		unset( $clean['flagged'] );
		$clean['review_count'] = isset( $record['review_count'] ) ? (int) $record['review_count'] : 0;
		$clean['generated_at'] = isset( $record['generated_at'] ) ? (int) $record['generated_at'] : 0;
		return $clean;
	}
}
