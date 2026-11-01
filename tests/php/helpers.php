<?php
/**
 * Shared fixtures: products, reviews and a fake Gemini endpoint.
 *
 * @package Dev3\AiReviewSummarizer
 */

use Dev3\AiReviewSummarizer\Core3\Core\Settings_Store;

/**
 * Test helpers.
 */
trait DEV3RS_Fixtures {

	/**
	 * Requests sent to the fake Gemini.
	 *
	 * @var array[]
	 */
	protected $ai_requests = array();

	/**
	 * Next fake response payload (decoded JSON object returned as text).
	 *
	 * @var array|string
	 */
	protected $ai_payload = null;

	/**
	 * HTTP status the fake returns.
	 *
	 * @var int
	 */
	protected $ai_status = 200;

	protected function dev3rs_setup() {
		Settings_Store::update(
			array(
				'api_key'       => 'test-key',
				'min_reviews'   => 3,
				'auto_generate' => true,
				'display'       => array( 'position' => 'above_reviews' ),
			)
		);
		$this->ai_requests = array();
		$this->ai_payload  = self::good_payload();
		$this->ai_status   = 200;
		add_filter( 'pre_http_request', array( $this, 'fake_gemini' ), 10, 3 );
	}

	protected function dev3rs_teardown() {
		remove_filter( 'pre_http_request', array( $this, 'fake_gemini' ), 10 );
		delete_option( 'dev3rs_bulk_job' );
		delete_option( 'dev3rs_bulk_lock' );
		Settings_Store::flush();
	}

	public function fake_gemini( $pre, $args, $url ) {
		if ( false === strpos( $url, 'generativelanguage' ) ) {
			return $pre;
		}
		$this->ai_requests[] = json_decode( $args['body'], true );
		if ( 200 !== $this->ai_status ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'error' => array( 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Rate limit' ) ) ),
				'response' => array( 'code' => $this->ai_status ),
				'cookies'  => array(),
			);
		}
		$text = is_string( $this->ai_payload ) ? $this->ai_payload : wp_json_encode( $this->ai_payload );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'candidates'    => array(
						array(
							'content'      => array( 'parts' => array( array( 'text' => $text ) ) ),
							'finishReason' => 'STOP',
						),
					),
					'usageMetadata' => array( 'totalTokenCount' => 321 ),
					'modelVersion'  => 'gemini-2.5-flash',
				)
			),
			'response' => array( 'code' => 200 ),
			'cookies'  => array(),
		);
	}

	protected static function good_payload() {
		return array(
			'summary'   => 'Buyers love the soft fabric but many say it runs small.',
			'pros'      => array( 'Soft fabric', 'Colours last' ),
			'cons'      => array( 'Runs small' ),
			'sentiment' => array( 'positive' => 70, 'neutral' => 20, 'negative' => 10 ),
			'scores'    => array( 'quality' => 4.3, 'value' => 4, 'sizing' => 'runs_small' ),
			'keywords'  => array( array( 'term' => 'fabric', 'sentiment' => 'positive', 'mentions' => 4 ) ),
			'flagged'   => array(),
		);
	}

	protected function product( $name = 'Test Tee' ) {
		return self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);
	}

	protected function review( $product_id, $rating = 5, $text = 'Great product, soft fabric.', $approved = 1, $author = 'Jane Reviewer' ) {
		$id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => $author,
				'comment_author_email' => 'jane.secret@example.com',
				'comment_author_IP'    => '203.0.113.9',
				'comment_content'      => $text,
				'comment_type'         => 'review',
				'comment_approved'     => $approved,
			)
		);
		update_comment_meta( $id, 'rating', $rating );
		return $id;
	}

	protected function product_with_reviews( $n = 4, $name = 'Test Tee' ) {
		$pid = $this->product( $name );
		for ( $i = 0; $i < $n; $i++ ) {
			$this->review( $pid, 3 + ( $i % 3 ), "Review number $i: soft fabric but runs small." );
		}
		return $pid;
	}
}
