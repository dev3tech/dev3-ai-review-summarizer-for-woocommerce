<?php
/**
 * Review aggregation and privacy.
 *
 * @package Dev3\AiReviewSummarizer
 */

use Dev3\AiReviewSummarizer\Reviews\Review_Repository;

class ReviewsTest extends WP_UnitTestCase {
	use DEV3RS_Fixtures;

	public function set_up() {
		parent::set_up();
		$this->dev3rs_setup();
	}

	public function tear_down() {
		$this->dev3rs_teardown();
		parent::tear_down();
	}

	public function test_only_approved_reviews_without_personal_data() {
		$pid = $this->product();
		$this->review( $pid, 5, 'Lovely. Mail me at jane@example.com or call +1 555 123 4567, see https://spam.example' );
		$this->review( $pid, 1, 'Pending review', 0 );
		$this->review( $pid, 2, 'Spam review', 'spam' );
		self::factory()->comment->create( array( 'comment_post_ID' => $pid, 'comment_content' => 'Plain comment', 'comment_type' => 'comment' ) );
		$this->review( $pid, 4, 'Size 38 40 42 all fit <b>true</b>' );

		$reviews = Review_Repository::for_product( $pid );
		$this->assertCount( 2, $reviews );
		$json = wp_json_encode( $reviews );
		foreach ( array( 'Jane Reviewer', 'jane.secret@example.com', '203.0.113.9', 'jane@example.com', '555 123', 'spam.example', '<b>' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
		$this->assertStringContainsString( '[email]', $json );
		$this->assertStringContainsString( '38 40 42', $json, 'size runs are not redacted as phone numbers' );
		$this->assertSame( array( 'id', 'rating', 'text', 'date', 'verified' ), array_keys( $reviews[0] ) );
	}

	public function test_signature_changes_with_the_review_set() {
		$pid = $this->product_with_reviews( 3 );
		$a   = Review_Repository::signature( $pid );
		$this->assertSame( 3, $a['count'] );
		$this->assertSame( $a, Review_Repository::signatures( array( $pid ) )[ $pid ], 'batch == single' );
		$this->assertSame( $a['hash'], Review_Repository::signature( $pid )['hash'], 'stable' );

		$cid = $this->review( $pid, 1, 'Broke after a week.' );
		$b   = Review_Repository::signature( $pid );
		$this->assertNotSame( $a['hash'], $b['hash'] );

		wp_update_comment( array( 'comment_ID' => $cid, 'comment_content' => 'Broke after two weeks.' ) );
		$this->assertNotSame( $b['hash'], Review_Repository::signature( $pid )['hash'], 'edits change the hash' );

		update_comment_meta( $cid, 'rating', 2 );
		$c = Review_Repository::signature( $pid );
		wp_set_comment_status( $cid, 'hold' );
		$this->assertSame( $a['hash'], Review_Repository::signature( $pid )['hash'], 'unapproving restores the old set' );
		$this->assertNotSame( $c['hash'], $a['hash'] );
	}

	public function test_store_wide_products_and_threshold() {
		$many = $this->product_with_reviews( 4, 'Many' );
		$few  = $this->product_with_reviews( 1, 'Few' );
		$ids  = Review_Repository::products_with_reviews( 3 );
		$this->assertContains( $many, $ids );
		$this->assertNotContains( $few, $ids );
		$this->assertContains( $few, Review_Repository::products_with_reviews( 1 ) );
		$this->assertSame( array( $many => 4, $few => 1 ), Review_Repository::counts( array( $many, $few ) ) );
	}
}
