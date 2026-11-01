<?php
/**
 * Background work: debounced regeneration after review changes, the nightly stale sweep.
 *
 * Uses Action Scheduler (bundled with WooCommerce) and falls back to WP-Cron.
 *
 * @package Dev3\AiReviewSummarizer
 */

namespace Dev3\AiReviewSummarizer\Jobs;

use Dev3\AiReviewSummarizer\Core3\Core\Settings_Store;
use Dev3\AiReviewSummarizer\Reviews\Review_Repository;
use Dev3\AiReviewSummarizer\Summary\Summarizer;
use Dev3\AiReviewSummarizer\Summary\Summary_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks and job handlers.
 */
class Scheduler {

	const GROUP        = 'dev3rs';
	const PRODUCT_HOOK = 'dev3rs_generate_product';
	const SWEEP_HOOK   = 'dev3rs_nightly_sweep';
	const CHECK_KEY    = 'dev3rs_sweep_check';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'comment_post', array( $this, 'on_comment_post' ), 20, 2 );
		add_action( 'transition_comment_status', array( $this, 'on_transition' ), 20, 3 );
		add_action( 'edit_comment', array( $this, 'on_comment_changed' ), 20 );
		add_action( 'deleted_comment', array( $this, 'on_comment_changed' ), 20 );
		add_action( self::PRODUCT_HOOK, array( __CLASS__, 'run_product' ) );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_sweep' ) );
	}

	/**
	 * New review (approved immediately).
	 *
	 * @param int        $comment_id Comment ID.
	 * @param int|string $approved   1, 0 or 'spam'.
	 */
	public function on_comment_post( $comment_id, $approved ) {
		if ( 1 === (int) $approved && 'spam' !== $approved ) {
			$this->queue_for_comment( $comment_id );
		}
	}

	/**
	 * Approved / unapproved / spam / trash transitions.
	 *
	 * @param string      $new_status New.
	 * @param string      $old_status Old.
	 * @param \WP_Comment $comment    Comment.
	 */
	public function on_transition( $new_status, $old_status, $comment ) {
		if ( $new_status !== $old_status && ( 'approved' === $new_status || 'approved' === $old_status ) ) {
			$this->queue_for_comment( $comment );
		}
	}

	/**
	 * Edited or deleted review.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public function on_comment_changed( $comment_id ) {
		$this->queue_for_comment( $comment_id );
	}

	/**
	 * Queues the review's product.
	 *
	 * @param \WP_Comment|int $comment Comment.
	 */
	private function queue_for_comment( $comment ) {
		$product_id = Review_Repository::review_product( $comment );
		if ( $product_id ) {
			self::queue( $product_id );
		}
	}

	/**
	 * Debounced: one pending job per product; it reads the latest reviews when it runs.
	 *
	 * @param int $product_id Product ID.
	 * @param int $delay      Seconds (null = debounce setting).
	 * @return bool Whether a job is (now) pending.
	 */
	public static function queue( $product_id, $delay = null ) {
		if ( ! Settings_Store::get( 'auto_generate', true ) || Summary_Store::is_locked( $product_id ) ) {
			return false;
		}
		$delay = null === $delay ? (int) Settings_Store::get( 'debounce_minutes', 10 ) * MINUTE_IN_SECONDS : (int) $delay;
		$args  = array( (int) $product_id );
		if ( self::has_action_scheduler() ) {
			if ( as_has_scheduled_action( self::PRODUCT_HOOK, $args, self::GROUP ) ) {
				return true;
			}
			return (bool) as_schedule_single_action( time() + $delay, self::PRODUCT_HOOK, $args, self::GROUP );
		}
		if ( wp_next_scheduled( self::PRODUCT_HOOK, $args ) ) {
			return true;
		}
		return true === wp_schedule_single_event( time() + $delay, self::PRODUCT_HOOK, $args );
	}

	/**
	 * Pending job timestamp for a product (0 = none).
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	public static function pending( $product_id ) {
		$args = array( (int) $product_id );
		if ( self::has_action_scheduler() ) {
			$next = as_next_scheduled_action( self::PRODUCT_HOOK, $args, self::GROUP );
			return is_int( $next ) ? $next : ( true === $next ? time() : 0 );
		}
		return (int) wp_next_scheduled( self::PRODUCT_HOOK, $args );
	}

	/**
	 * Job: regenerate one product unless locked/unchanged/below threshold.
	 *
	 * @param int $product_id Product ID.
	 * @return array|\WP_Error
	 */
	public static function run_product( $product_id ) {
		if ( ! Settings_Store::get( 'auto_generate', true ) ) {
			return array(
				'result' => 'skipped',
				'reason' => 'disabled',
			);
		}
		$result = Summarizer::generate( (int) $product_id, array( 'context' => 'cron' ) );
		if ( is_wp_error( $result ) && 'dev3rs_not_enough_reviews' !== $result->get_error_code() ) {
			// Logged in Usage Logs by Ai::run; surface in the scheduler log too.
			error_log( 'DEV3 AI Review Summarizer: product ' . (int) $product_id . ': ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return $result;
	}

	/**
	 * Nightly: queue stale (unlocked) summaries and eligible products without one.
	 *
	 * @return int Products queued.
	 */
	public static function sweep() {
		if ( ! Settings_Store::get( 'nightly_refresh', true ) || ! Settings_Store::get( 'auto_generate', true ) ) {
			return 0;
		}
		/**
		 * Max products queued per nightly sweep.
		 *
		 * @param int $limit Limit.
		 */
		$limit  = (int) apply_filters( 'dev3rs_sweep_limit', 200 );
		$queued = 0;
		foreach ( Summary_Store::statuses( Review_Repository::products_with_reviews( (int) Settings_Store::get( 'min_reviews', 3 ) ) ) as $product_id => $status ) {
			if ( Summary_Store::STATUS_STALE !== $status && Summary_Store::STATUS_NONE !== $status ) {
				continue;
			}
			// Spread the calls out (20 s apart) to stay well inside API rate limits.
			if ( self::queue( $product_id, 60 + $queued * 20 ) ) {
				++$queued;
			}
			if ( $queued >= $limit ) {
				break;
			}
		}
		return $queued;
	}

	/**
	 * Makes sure the nightly sweep is scheduled (checked at most once a day from wp-admin).
	 */
	public static function ensure_sweep() {
		if ( get_transient( self::CHECK_KEY ) ) {
			return;
		}
		set_transient( self::CHECK_KEY, 1, DAY_IN_SECONDS );
		$first = ( new \DateTimeImmutable( 'tomorrow 03:00', wp_timezone() ) )->getTimestamp();
		if ( self::has_action_scheduler() ) {
			if ( ! as_has_scheduled_action( self::SWEEP_HOOK, array(), self::GROUP ) ) {
				as_schedule_recurring_action( $first, DAY_IN_SECONDS, self::SWEEP_HOOK, array(), self::GROUP );
			}
			wp_clear_scheduled_hook( self::SWEEP_HOOK );
			return;
		}
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( $first, 'daily', self::SWEEP_HOOK );
		}
	}

	/**
	 * Removes every scheduled job (deactivation / uninstall).
	 */
	public static function unschedule_all() {
		if ( self::has_action_scheduler() ) {
			as_unschedule_all_actions( self::PRODUCT_HOOK, null, self::GROUP );
			as_unschedule_all_actions( self::SWEEP_HOOK, array(), self::GROUP );
			as_unschedule_all_actions( Bulk_Job::TICK_HOOK, array(), self::GROUP );
		}
		wp_unschedule_hook( self::PRODUCT_HOOK );
		wp_clear_scheduled_hook( self::SWEEP_HOOK );
		wp_clear_scheduled_hook( Bulk_Job::TICK_HOOK );
		delete_transient( self::CHECK_KEY );
	}

	/**
	 * Action Scheduler is loaded (WooCommerce bundles it).
	 *
	 * @return bool
	 */
	public static function has_action_scheduler() {
		/**
		 * Force WP-Cron instead of Action Scheduler.
		 *
		 * @param bool $use Use Action Scheduler when available.
		 */
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ) && apply_filters( 'dev3rs_use_action_scheduler', true );
	}
}
