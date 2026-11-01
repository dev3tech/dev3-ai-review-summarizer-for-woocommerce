<?php
/**
 * Review set → AI → sanitised summary in post meta, skipping unchanged and locked products.
 *
 * @package Dev3\AiReviewSummarizer
 */

namespace Dev3\AiReviewSummarizer\Summary;

use Dev3\AiReviewSummarizer\Core3\Api\Ai;
use Dev3\AiReviewSummarizer\Core3\Api\Api_Error;
use Dev3\AiReviewSummarizer\Core3\Core\Settings_Store;
use Dev3\AiReviewSummarizer\Reviews\Review_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Generation pipeline.
 */
class Summarizer {

	const TASK = 'review_summary';

	/**
	 * Generates (or skips) one product.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $opts {
	 *     Options.
	 *     @type bool   $force   Regenerate even when the review set is unchanged or the summary is locked.
	 *     @type bool   $unlock  Remove the lock after a forced regeneration.
	 *     @type string $context Usage-log context (admin, bulk, cron).
	 * }
	 * @return array|\WP_Error { result: generated|skipped, reason?, record? }
	 */
	public static function generate( $product_id, array $opts = array() ) {
		$opts       = array_merge(
			array(
				'force'   => false,
				'unlock'  => false,
				'context' => 'admin',
			),
			$opts
		);
		$product_id = (int) $product_id;
		if ( 'product' !== get_post_type( $product_id ) ) {
			return Api_Error::make( 'dev3rs_not_product', __( 'Product not found.', 'dev3-ai-review-summarizer-for-woocommerce' ), '', 404 );
		}
		$locked = Summary_Store::is_locked( $product_id );
		if ( $locked && ! $opts['force'] ) {
			return self::skipped( 'locked' );
		}

		$signature = Review_Repository::signature( $product_id );
		$min       = (int) Settings_Store::get( 'min_reviews', 3 );
		if ( $signature['count'] < $min ) {
			return Api_Error::make(
				'dev3rs_not_enough_reviews',
				/* translators: 1: approved reviews, 2: minimum */
				sprintf( __( 'This product has %1$d approved reviews; at least %2$d are needed for a summary.', 'dev3-ai-review-summarizer-for-woocommerce' ), $signature['count'], $min ),
				__( 'Lower the minimum in General settings or wait for more reviews.', 'dev3-ai-review-summarizer-for-woocommerce' ),
				422
			);
		}

		$fingerprint = Summary_Store::fingerprint( $signature['hash'] );
		$record      = Summary_Store::get( $product_id );
		if ( ! $opts['force'] && $record && isset( $record['hash'] ) && $record['hash'] === $fingerprint ) {
			return self::skipped( 'unchanged' );
		}

		$reviews = Review_Repository::for_product( $product_id, (int) Settings_Store::get( 'max_reviews', 80 ) );
		if ( ! $reviews ) {
			return Api_Error::make( 'dev3rs_no_review_text', __( 'The approved reviews have no text to summarise.', 'dev3-ai-review-summarizer-for-woocommerce' ), '', 422 );
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$name    = $product ? $product->get_name() : get_the_title( $product_id );
		$terms   = get_the_terms( $product_id, 'product_cat' );
		$cats    = is_array( $terms ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';

		$args = array(
			'model'      => 'default',
			'system'     => Prompt::system( Prompt::language( (string) Settings_Store::get( 'language', 'store' ), get_locale() ) ),
			'prompt'     => Prompt::user( $name, $cats, $reviews, $signature ),
			'schema'     => Prompt::schema(),
			'max_tokens' => max( 3072, (int) Settings_Store::get( 'max_tokens', 4096 ) ),
			'timeout'    => 120,
		);
		/**
		 * Filters the AI request for a product summary.
		 *
		 * @param array $args       Ai::run args.
		 * @param int   $product_id Product ID.
		 * @param array $reviews    Privacy-safe reviews being sent.
		 */
		$args = apply_filters( 'dev3rs_summary_request', $args, $product_id, $reviews );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 180 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long AI call.
		}
		$result = Ai::run( self::TASK, $args, (string) $opts['context'] );
		if ( is_wp_error( $result ) ) {
			return self::brand_error( $result );
		}

		$summary = Summary_Parser::sanitize( isset( $result['data'] ) ? $result['data'] : array() );
		$summary = self::drop_out_of_range_flags( $summary, count( $reviews ) );
		if ( ! Summary_Parser::is_usable( $summary ) ) {
			return Api_Error::make( 'dev3rs_empty_summary', __( 'The AI returned an empty summary.', 'dev3-ai-review-summarizer-for-woocommerce' ), __( 'Try again in a moment.', 'dev3-ai-review-summarizer-for-woocommerce' ), 502, true );
		}

		$saved = Summary_Store::save(
			$product_id,
			$summary,
			array(
				'hash'         => $fingerprint,
				'model'        => isset( $result['model'] ) ? sanitize_text_field( $result['model'] ) : '',
				'generated_at' => time(),
				'review_count' => $signature['count'],
				'analysed'     => count( $reviews ),
				'average'      => $signature['average'],
				'source'       => 'ai',
				'language'     => (string) Settings_Store::get( 'language', 'store' ),
				'edited_at'    => 0,
				'edited_by'    => 0,
				'flagged_ids'  => self::flagged_ids( $summary['flagged'], $reviews ),
				'tokens'       => isset( $result['usage']['total'] ) ? (int) $result['usage']['total'] : 0,
			)
		);
		if ( $locked && $opts['unlock'] ) {
			Summary_Store::set_locked( $product_id, false );
		}
		return array(
			'result' => 'generated',
			'record' => $saved,
		);
	}

	/**
	 * Saves a moderator's edit (sanitised) and locks it by default.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $input      Edited fields.
	 * @param bool  $lock       Lock against automatic regeneration.
	 * @return array|\WP_Error Record.
	 */
	public static function save_edit( $product_id, array $input, $lock = true ) {
		$product_id = (int) $product_id;
		if ( 'product' !== get_post_type( $product_id ) ) {
			return Api_Error::make( 'dev3rs_not_product', __( 'Product not found.', 'dev3-ai-review-summarizer-for-woocommerce' ), '', 404 );
		}
		$current = Summary_Store::get( $product_id );
		$current = $current ? $current : array();
		$merged  = array_merge( Summary_Parser::sanitize( $current ), $input );
		$summary = Summary_Parser::sanitize( $merged );
		if ( ! Summary_Parser::is_usable( $summary ) ) {
			return Api_Error::make( 'dev3rs_empty_summary', __( 'Add a summary paragraph or at least one pro or con.', 'dev3-ai-review-summarizer-for-woocommerce' ), '', 422 );
		}
		$signature = Review_Repository::signature( $product_id );
		$record    = Summary_Store::save(
			$product_id,
			$summary,
			array_merge(
				array_diff_key( $current, $summary ),
				array(
					'hash'         => Summary_Store::fingerprint( $signature['hash'] ),
					'review_count' => $signature['count'],
					'average'      => $signature['average'],
					'generated_at' => isset( $current['generated_at'] ) ? (int) $current['generated_at'] : time(),
					'model'        => isset( $current['model'] ) ? $current['model'] : '',
					'source'       => 'edited',
					'edited_at'    => time(),
					'edited_by'    => get_current_user_id(),
				)
			)
		);
		Summary_Store::set_locked( $product_id, (bool) $lock );
		return $record;
	}

	/**
	 * Skip result.
	 *
	 * @param string $reason Reason.
	 * @return array
	 */
	private static function skipped( $reason ) {
		return array(
			'result' => 'skipped',
			'reason' => $reason,
		);
	}

	/**
	 * Keeps only flags that point at real review numbers.
	 *
	 * @param array $summary Summary.
	 * @param int   $count   Reviews sent.
	 * @return array
	 */
	private static function drop_out_of_range_flags( array $summary, $count ) {
		$summary['flagged'] = array_values(
			array_filter(
				$summary['flagged'],
				static function ( $n ) use ( $count ) {
					return $n >= 1 && $n <= $count;
				}
			)
		);
		return $summary;
	}

	/**
	 * Maps flagged review numbers (1-based, as sent) to comment IDs.
	 *
	 * @param int[] $flagged Review numbers.
	 * @param array $reviews Reviews sent.
	 * @return int[]
	 */
	private static function flagged_ids( array $flagged, array $reviews ) {
		$ids = array();
		foreach ( $flagged as $n ) {
			if ( isset( $reviews[ $n - 1 ]['id'] ) ) {
				$ids[] = (int) $reviews[ $n - 1 ]['id'];
			}
		}
		return $ids;
	}

	/**
	 * Core error messages name the engine; storefront-facing admin UI says "AI" (API-key messages are kept).
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_Error
	 */
	public static function brand_error( \WP_Error $error ) {
		$message = $error->get_error_message();
		if ( false !== stripos( $message, 'API key' ) ) {
			return $error;
		}
		$message = preg_replace( '/^Gemini\b/', 'The AI', $message );
		$message = preg_replace( '/\bGemini\b/', 'the AI', $message );
		return new \WP_Error( $error->get_error_code(), $message, $error->get_error_data() );
	}
}
