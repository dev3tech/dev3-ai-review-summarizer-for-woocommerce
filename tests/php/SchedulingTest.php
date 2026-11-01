<?php
/**
 * Triggers: debounced Action Scheduler jobs (WP-Cron fallback), nightly sweep.
 *
 * @package Dev3\AiReviewSummarizer
 */

use Dev3\AiReviewSummarizer\Core3\Core\Settings_Store;
use Dev3\AiReviewSummarizer\Jobs\Scheduler;
use Dev3\AiReviewSummarizer\Summary\Summarizer;
use Dev3\AiReviewSummarizer\Summary\Summary_Store;

class SchedulingTest extends WP_UnitTestCase {
	use DEV3RS_Fixtures;

	public function set_up() {
		parent::set_up();
		$this->dev3rs_setup();
		Scheduler::unschedule_all();
	}

	public function tear_down() {
		Scheduler::unschedule_all();
		remove_all_filters( 'dev3rs_use_action_scheduler' );
		$this->dev3rs_teardown();
		parent::tear_down();
	}

	private function new_review( $pid, $approved = 1 ) {
		$id = $this->review( $pid, 4, 'Arrived quickly.', $approved );
		do_action( 'comment_post', $id, $approved, array() );
		return $id;
	}

	public function test_action_scheduler_is_used() {
		$this->assertTrue( Scheduler::has_action_scheduler(), 'WooCommerce bundles Action Scheduler' );
	}

	public function test_new_approved_review_queues_one_debounced_job() {
		$pid = $this->product_with_reviews( 3 );
		$this->new_review( $pid );
		$this->new_review( $pid );
		$this->assertTrue( as_has_scheduled_action( Scheduler::PRODUCT_HOOK, array( $pid ), Scheduler::GROUP ) );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => Scheduler::PRODUCT_HOOK, 'args' => array( $pid ), 'status' => 'pending' ), 'ids' ), 'debounced' );
		$next = Scheduler::pending( $pid );
		$this->assertGreaterThanOrEqual( time() + 9 * MINUTE_IN_SECONDS, $next, 'waits the debounce window' );
		$this->assertCount( 0, $this->ai_requests, 'nothing runs synchronously' );
	}

	public function test_pending_reviews_do_not_queue_until_approved() {
		$pid = $this->product_with_reviews( 3 );
		$cid = $this->new_review( $pid, 0 );
		$this->assertSame( 0, Scheduler::pending( $pid ) );
		wp_set_comment_status( $cid, 'approve' );
		$this->assertGreaterThan( 0, Scheduler::pending( $pid ), 'transition_comment_status queues it' );
	}

	public function test_locked_or_disabled_products_are_not_queued() {
		$pid = $this->product_with_reviews( 3 );
		Summarizer::generate( $pid );
		Summary_Store::set_locked( $pid, true );
		$this->new_review( $pid );
		$this->assertSame( 0, Scheduler::pending( $pid ) );

		$other = $this->product_with_reviews( 3 );
		Settings_Store::update( array( 'auto_generate' => false ) );
		$this->new_review( $other );
		$this->assertSame( 0, Scheduler::pending( $other ) );
	}

	public function test_job_handler_generates_then_skips() {
		$pid = $this->product_with_reviews( 3 );
		do_action( Scheduler::PRODUCT_HOOK, $pid );
		$this->assertNotNull( Summary_Store::get( $pid ) );
		$this->assertCount( 1, $this->ai_requests );
		$res = Scheduler::run_product( $pid );
		$this->assertSame( 'unchanged', $res['reason'] );
		$this->assertCount( 1, $this->ai_requests );
	}

	public function test_wp_cron_fallback() {
		add_filter( 'dev3rs_use_action_scheduler', '__return_false' );
		$pid = $this->product_with_reviews( 3 );
		$this->new_review( $pid );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::PRODUCT_HOOK, array( $pid ) ) );
		$this->assertFalse( as_has_scheduled_action( Scheduler::PRODUCT_HOOK, array( $pid ), Scheduler::GROUP ) );
	}

	public function test_nightly_sweep_queues_stale_and_missing_only() {
		$fresh  = $this->product_with_reviews( 3, 'Fresh' );
		$stale  = $this->product_with_reviews( 3, 'Stale' );
		$none   = $this->product_with_reviews( 3, 'None' );
		$locked = $this->product_with_reviews( 3, 'Locked' );
		$few    = $this->product_with_reviews( 1, 'Few' );
		foreach ( array( $fresh, $stale, $locked ) as $pid ) {
			Summarizer::generate( $pid );
		}
		Summary_Store::set_locked( $locked, true );
		$this->review( $stale, 2, 'New complaint.' );
		$this->review( $locked, 2, 'New complaint.' );

		$this->assertSame( 2, Scheduler::sweep() );
		$this->assertGreaterThan( 0, Scheduler::pending( $stale ) );
		$this->assertGreaterThan( 0, Scheduler::pending( $none ) );
		$this->assertSame( 0, Scheduler::pending( $fresh ) );
		$this->assertSame( 0, Scheduler::pending( $locked ) );
		$this->assertSame( 0, Scheduler::pending( $few ) );

		Settings_Store::update( array( 'nightly_refresh' => false ) );
		$this->assertSame( 0, Scheduler::sweep() );
	}

	public function test_sweep_is_scheduled_daily() {
		delete_transient( Scheduler::CHECK_KEY );
		Scheduler::ensure_sweep();
		$this->assertTrue( as_has_scheduled_action( Scheduler::SWEEP_HOOK, array(), Scheduler::GROUP ) );
	}
}
