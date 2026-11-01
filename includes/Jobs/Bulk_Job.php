<?php
/**
 * Bulk generation ("Generate missing", "Refresh stale", selected products) with progress.
 *
 * The queue lives in one option. It is processed by a background runner (Action Scheduler / WP-Cron)
 * and, while the admin screen is open, by its progress polling (POST /bulk/step), so it also completes
 * on sites where cron does not run. An atomic row lock keeps the two from processing the same item.
 *
 * @package Dev3\AiReviewSummarizer
 */

namespace Dev3\AiReviewSummarizer\Jobs;

use Dev3\AiReviewSummarizer\Core3\Api\Api_Error;
use Dev3\AiReviewSummarizer\Core3\Core\Settings_Store;
use Dev3\AiReviewSummarizer\Reviews\Review_Repository;
use Dev3\AiReviewSummarizer\Summary\Summarizer;
use Dev3\AiReviewSummarizer\Summary\Summary_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk queue.
 */
class Bulk_Job {

	const OPTION    = 'dev3rs_bulk_job';
	const LOCK      = 'dev3rs_bulk_lock';
	const TICK_HOOK = 'dev3rs_bulk_tick';
	const MODES     = array( 'missing', 'stale', 'selected' );

	/**
	 * Registers the background runner.
	 */
	public static function register() {
		add_action( self::TICK_HOOK, array( __CLASS__, 'tick' ) );
	}

	/**
	 * Products a mode would process.
	 *
	 * @param string $mode missing|stale.
	 * @return int[]
	 */
	public static function candidates( $mode ) {
		$out = array();
		foreach ( Summary_Store::statuses( Review_Repository::products_with_reviews( (int) Settings_Store::get( 'min_reviews', 3 ) ) ) as $product_id => $status ) {
			if ( ( 'missing' === $mode && Summary_Store::STATUS_NONE === $status ) || ( 'stale' === $mode && Summary_Store::STATUS_STALE === $status ) ) {
				$out[] = $product_id;
			}
		}
		return $out;
	}

	/**
	 * Starts a job.
	 *
	 * @param string $mode missing|stale|selected.
	 * @param int[]  $ids  Products for "selected".
	 * @return array|\WP_Error Progress.
	 */
	public static function start( $mode, array $ids = array() ) {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return Api_Error::make( 'dev3rs_bad_mode', __( 'Unknown bulk action.', 'dev3-ai-review-summarizer-for-woocommerce' ), '', 400 );
		}
		$job = self::get();
		if ( $job && 'running' === $job['status'] && time() - (int) $job['updated_at'] < 15 * MINUTE_IN_SECONDS ) {
			return Api_Error::make( 'dev3rs_bulk_running', __( 'A bulk job is already running.', 'dev3-ai-review-summarizer-for-woocommerce' ), __( 'Wait for it to finish or cancel it.', 'dev3-ai-review-summarizer-for-woocommerce' ), 409 );
		}
		$queue = 'selected' === $mode
			? array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) )
			: self::candidates( $mode );
		$queue = array_slice( $queue, 0, 2000 );
		$job   = array(
			'id'         => wp_generate_password( 8, false ),
			'mode'       => $mode,
			'status'     => $queue ? 'running' : 'done',
			'queue'      => $queue,
			'total'      => count( $queue ),
			'done'       => 0,
			'generated'  => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'current'    => 0,
			'errors'     => array(),
			'started_at' => time(),
			'updated_at' => time(),
			'user_id'    => get_current_user_id(),
		);
		self::put( $job );
		if ( $queue ) {
			self::schedule_tick( 5 );
		}
		return self::progress();
	}

	/**
	 * Processes the next product.
	 *
	 * @param string $context Usage-log context.
	 * @return array Progress.
	 */
	public static function step( $context = 'bulk' ) {
		if ( ! self::lock() ) {
			return self::progress() + array( 'busy' => true );
		}
		try {
			$job = self::get( true );
			if ( ! $job || 'running' !== $job['status'] ) {
				return self::progress();
			}
			if ( ! $job['queue'] ) {
				$job['status'] = 'done';
				self::put( $job );
				return self::progress();
			}
			$product_id     = (int) array_shift( $job['queue'] );
			$job['current'] = $product_id;
			self::put( $job );

			$opts = array( 'context' => $context );
			if ( 'selected' === $job['mode'] ) {
				$opts['force'] = ! Summary_Store::is_locked( $product_id );
			}
			$result = Summary_Store::is_locked( $product_id )
				? array(
					'result' => 'skipped',
					'reason' => 'locked',
				)
				: Summarizer::generate( $product_id, $opts );

			$job = self::get( true );
			if ( ! $job || 'running' !== $job['status'] ) {
				return self::progress();
			}
			$retries = isset( $job['retries'] ) ? (array) $job['retries'] : array();
			if ( is_wp_error( $result ) && 'dev3rs_quota' === $result->get_error_code() && empty( $retries[ $product_id ] ) ) {
				// Rate limited: retry this product once at the end of the queue.
				$retries[ $product_id ] = 1;
				$job['retries']         = $retries;
				$job['queue'][]         = $product_id;
				$job['current']         = 0;
				self::put( $job );
				return self::progress();
			}
			++$job['done'];
			if ( is_wp_error( $result ) ) {
				++$job['failed'];
				if ( count( $job['errors'] ) < 25 ) {
					$job['errors'][] = array(
						'id'      => $product_id,
						'name'    => get_the_title( $product_id ),
						'message' => $result->get_error_message(),
					);
				}
				if ( in_array( $result->get_error_code(), array( 'dev3rs_no_api_key', 'dev3rs_invalid_key', 'dev3rs_forbidden', 'dev3rs_model_not_found', 'dev3rs_region' ), true ) ) {
					$job['status'] = 'failed'; // Every other item would fail the same way.
				}
			} elseif ( 'generated' === $result['result'] ) {
				++$job['generated'];
			} else {
				++$job['skipped'];
			}
			$job['current'] = 0;
			if ( ! $job['queue'] && 'running' === $job['status'] ) {
				$job['status'] = 'done';
			}
			self::put( $job );
			return self::progress();
		} finally {
			self::unlock();
		}
	}

	/**
	 * Background runner: works for ~20 s, then reschedules itself.
	 */
	public static function tick() {
		$started = time();
		do {
			$progress = self::step( 'bulk' );
			if ( ! empty( $progress['busy'] ) ) {
				break;
			}
		} while ( 'running' === $progress['status'] && time() - $started < 20 );
		if ( 'running' === $progress['status'] ) {
			self::schedule_tick( 10 );
		}
	}

	/**
	 * Cancels the job.
	 *
	 * @return array Progress.
	 */
	public static function cancel() {
		$job = self::get( true );
		if ( $job && 'running' === $job['status'] ) {
			$job['status'] = 'cancelled';
			$job['queue']  = array();
			self::put( $job );
		}
		return self::progress();
	}

	/**
	 * Progress for the UI.
	 *
	 * @return array
	 */
	public static function progress() {
		$job = self::get( true );
		if ( ! $job ) {
			return array(
				'status' => 'idle',
				'total'  => 0,
				'done'   => 0,
			);
		}
		$out              = $job;
		$out['remaining'] = count( $job['queue'] );
		$out['percent']   = $job['total'] ? (int) floor( 100 * $job['done'] / $job['total'] ) : 100;
		$out['current']   = $job['current'] ? array(
			'id'   => (int) $job['current'],
			'name' => get_the_title( (int) $job['current'] ),
		) : null;
		unset( $out['queue'] );
		return $out;
	}

	/**
	 * Stored job.
	 *
	 * @param bool $fresh Bypass the object cache (another request may have changed it).
	 * @return array|null
	 */
	public static function get( $fresh = false ) {
		if ( $fresh ) {
			wp_cache_delete( self::OPTION, 'options' );
		}
		$job = get_option( self::OPTION );
		return is_array( $job ) && isset( $job['status'] ) ? $job : null;
	}

	/**
	 * Saves the job.
	 *
	 * @param array $job Job.
	 */
	private static function put( array $job ) {
		$job['updated_at'] = time();
		update_option( self::OPTION, $job, false );
	}

	/**
	 * Schedules the background runner.
	 *
	 * @param int $delay Seconds.
	 */
	private static function schedule_tick( $delay ) {
		if ( Scheduler::has_action_scheduler() ) {
			if ( ! as_has_scheduled_action( self::TICK_HOOK, array(), Scheduler::GROUP ) ) {
				as_schedule_single_action( time() + $delay, self::TICK_HOOK, array(), Scheduler::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::TICK_HOOK );
		}
	}

	/**
	 * Atomic lock (INSERT IGNORE on the options primary key); expires after 3 minutes.
	 *
	 * @return bool
	 */
	private static function lock() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic lock cannot be cached.
		$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, (string) time() ) );
		if ( 1 === (int) $taken ) {
			// phpcs:enable
			return true;
		}
		$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
		if ( $since && time() - $since > 3 * MINUTE_IN_SECONDS ) {
			$swap = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) time(), self::LOCK, (string) $since ) );
			return 1 === (int) $swap;
		}
		// phpcs:enable
		return false;
	}

	/**
	 * Releases the lock.
	 */
	private static function unlock() {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_delete( self::LOCK, 'options' );
	}
}
