<?php
/**
 * REST: permissions + nonce, read/edit/lock, regenerate, bulk with progress, preview.
 *
 * @package Dev3\AiReviewSummarizer
 */

use Dev3\AiReviewSummarizer\Summary\Summarizer;
use Dev3\AiReviewSummarizer\Summary\Summary_Store;

class RestTest extends WP_UnitTestCase {
	use DEV3RS_Fixtures;

	private $admin;
	private $manager;
	private $editor;

	public function set_up() {
		parent::set_up();
		$this->dev3rs_setup();
		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$this->editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		$this->dev3rs_teardown();
		parent::tear_down();
	}

	private function call( $method, $route, $body = array(), $nonce = true ) {
		$query = array();
		if ( false !== strpos( $route, '?' ) ) {
			list( $route, $qs ) = explode( '?', $route, 2 );
			parse_str( $qs, $query );
		}
		$req = new WP_REST_Request( $method, '/dev3rs/v1/' . $route );
		$req->set_query_params( $query );
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		if ( $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $req );
	}

	public function test_permissions_and_nonce() {
		$pid = $this->product_with_reviews( 3 );
		wp_set_current_user( $this->editor );
		$this->assertSame( 403, $this->call( 'GET', 'products' )->get_status(), 'editors cannot moderate' );
		$this->assertSame( 403, $this->call( 'POST', "regenerate/$pid" )->get_status() );
		$this->assertSame( 403, $this->call( 'GET', "summary/$pid?context=edit" )->get_status() );
		wp_set_current_user( $this->manager );
		$this->assertSame( 200, $this->call( 'GET', 'products' )->get_status(), 'shop managers moderate' );
		$this->assertSame( 403, $this->call( 'GET', 'settings' )->get_status(), 'but cannot change settings' );
		$this->assertSame( 403, $this->call( 'POST', "regenerate/$pid", array(), false )->get_status(), 'nonce required' );
		$this->assertSame( 403, $this->call( 'POST', 'bulk', array( 'mode' => 'missing' ), false )->get_status() );
		$this->assertCount( 0, $this->ai_requests );
	}

	public function test_public_summary_hides_private_fields() {
		$pid = $this->product_with_reviews( 3 );
		wp_set_current_user( 0 );
		$this->assertSame( 404, $this->call( 'GET', "summary/$pid", array(), false )->get_status() );
		Summarizer::generate( $pid );
		$res = $this->call( 'GET', "summary/$pid", array(), false );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 'Runs small', $data['summary']['cons'][0] );
		$this->assertArrayNotHasKey( 'hash', $data['summary'] );
		$this->assertArrayNotHasKey( 'flagged', $data['summary'] );
		wp_update_post( array( 'ID' => $pid, 'post_status' => 'draft' ) );
		$this->assertSame( 404, $this->call( 'GET', "summary/$pid", array(), false )->get_status(), 'drafts stay private' );
	}

	public function test_products_list_with_statuses() {
		$fresh = $this->product_with_reviews( 3, 'A fresh' );
		$none  = $this->product_with_reviews( 3, 'B none' );
		$few   = $this->product_with_reviews( 1, 'C few' );
		Summarizer::generate( $fresh );
		wp_set_current_user( $this->admin );
		$data = $this->call( 'GET', 'products' )->get_data();
		$this->assertSame( 3, $data['stats']['reviewed'] );
		$this->assertSame( 1, $data['stats']['fresh'] );
		$this->assertSame( 1, $data['stats']['none'], 'only eligible products count as missing' );
		$rows = array_column( $data['items'], null, 'id' );
		$this->assertSame( 'fresh', $rows[ $fresh ]['status'] );
		$this->assertSame( 'none', $rows[ $none ]['status'] );
		$this->assertFalse( $rows[ $few ]['eligible'] );
		$filtered = $this->call( 'GET', 'products?status=none' )->get_data();
		$this->assertSame( array( $none ), wp_list_pluck( $filtered['items'], 'id' ) );
	}

	public function test_edit_lock_and_regenerate() {
		$pid = $this->product_with_reviews( 3 );
		wp_set_current_user( $this->manager );
		$res = $this->call( 'POST', "regenerate/$pid" );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'fresh', $res->get_data()['status'] );

		$res = $this->call(
			'PUT',
			"summary/$pid",
			array(
				'summary'   => '<em>Edited</em> by hand',
				'pros'      => array( 'Hand-picked pro' ),
				'sentiment' => array( 'positive' => 1, 'neutral' => 1, 'negative' => 2 ),
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 'locked', $data['status'] );
		$this->assertSame( 'Edited by hand', $data['summary']['summary'] );
		$this->assertSame( 100, array_sum( $data['summary']['sentiment'] ) );
		$this->assertSame( 'edited', $data['meta']['source'] );

		$locked = $this->call( 'POST', "regenerate/$pid" );
		$this->assertSame( 409, $locked->get_status(), 'locked needs explicit unlock' );
		$this->assertSame( 'dev3rs_locked', $locked->get_data()['code'] );
		$this->assertSame( 'Edited by hand', Summary_Store::get( $pid )['summary'] );

		$ok = $this->call( 'POST', "regenerate/$pid", array( 'unlock' => true ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertFalse( $ok->get_data()['locked'] );

		$this->assertSame( 'locked', $this->call( 'PUT', "summary/$pid", array( 'locked' => true ) )->get_data()['status'], 'lock only' );
		$this->assertSame( 'fresh', $this->call( 'PUT', "summary/$pid", array( 'locked' => false ) )->get_data()['status'], 'unlock only' );
		$this->assertSame( 422, $this->call( 'PUT', "summary/$pid", array( 'summary' => '', 'pros' => array(), 'cons' => array() ) )->get_status() );
		$this->assertSame( 'none', $this->call( 'DELETE', "summary/$pid" )->get_data()['status'] );
	}

	public function test_bulk_generate_missing_with_progress() {
		$a      = $this->product_with_reviews( 3, 'A' );
		$b      = $this->product_with_reviews( 3, 'B' );
		$locked = $this->product_with_reviews( 3, 'L' );
		Summarizer::generate( $locked );
		Summary_Store::set_locked( $locked, true );
		$this->ai_requests = array();
		wp_set_current_user( $this->manager );

		$start = $this->call( 'POST', 'bulk', array( 'mode' => 'missing' ) )->get_data();
		$this->assertSame( 'running', $start['status'] );
		$this->assertSame( 2, $start['total'] );
		$this->assertSame( 409, $this->call( 'POST', 'bulk', array( 'mode' => 'stale' ) )->get_status(), 'one job at a time' );

		$p1 = $this->call( 'POST', 'bulk/step' )->get_data();
		$this->assertSame( 1, $p1['done'] );
		$this->assertSame( 50, $p1['percent'] );
		$p2 = $this->call( 'POST', 'bulk/step' )->get_data();
		$this->assertSame( 'done', $p2['status'] );
		$this->assertSame( 2, $p2['generated'] );
		$this->assertSame( 100, $this->call( 'GET', 'bulk' )->get_data()['percent'] );
		$this->assertCount( 2, $this->ai_requests );
		$this->assertNotNull( Summary_Store::get( $a ) );
		$this->assertNotNull( Summary_Store::get( $b ) );

		// Refresh stale: only the unlocked stale product.
		$this->review( $a, 1, 'Changed my mind.' );
		$this->review( $locked, 1, 'Changed my mind.' );
		$stale = $this->call( 'POST', 'bulk', array( 'mode' => 'stale' ) )->get_data();
		$this->assertSame( 1, $stale['total'] );
		$this->call( 'POST', 'bulk/step' );
		$this->assertSame( 'fresh', Summary_Store::status( $a ) );
		$this->assertSame( 'locked', Summary_Store::status( $locked ) );

		// Cancel.
		$this->call( 'POST', 'bulk', array( 'mode' => 'selected', 'ids' => array( $a, $b ) ) );
		$this->assertSame( 'cancelled', $this->call( 'DELETE', 'bulk' )->get_data()['status'] );
		$this->assertSame( 400, $this->call( 'POST', 'bulk', array( 'mode' => 'nope' ) )->get_status() );
	}

	public function test_bulk_stops_on_fatal_key_errors_and_retries_rate_limits_once() {
		$a = $this->product_with_reviews( 3, 'A' );
		$b = $this->product_with_reviews( 3, 'B' );
		wp_set_current_user( $this->manager );
		$this->call( 'POST', 'bulk', array( 'mode' => 'missing' ) );
		$this->ai_status = 429;
		$p               = $this->call( 'POST', 'bulk/step' )->get_data();
		$this->assertSame( 0, $p['done'], 'rate-limited item is re-queued' );
		$this->assertSame( 2, $p['remaining'] );
		\Dev3\AiReviewSummarizer\Core3\Core\Settings_Store::update( array( 'api_key' => '' ) );
		$p = $this->call( 'POST', 'bulk/step' )->get_data();
		$this->assertSame( 'failed', $p['status'] );
		$this->assertSame( 1, $p['failed'] );
		unset( $a, $b );
	}

	public function test_preview_renders_unsaved_display_options() {
		wp_set_current_user( $this->manager );
		$res = $this->call( 'POST', 'preview', array( 'display' => array( 'layout' => 'compact', 'accent' => '#ff0000', 'show_keywords' => false, 'heading' => 'Shoppers say' ) ) );
		$html = $res->get_data()['html'];
		$this->assertTrue( $res->get_data()['sample'] );
		$this->assertStringContainsString( 'dev3rs-rs--compact', $html );
		$this->assertStringContainsString( '--dev3rs-accent:#ff0000', $html );
		$this->assertStringContainsString( 'Shoppers say', $html );
		$this->assertStringNotContainsString( 'dev3rs-rs__chips', $html );
		$this->assertSame( 'card', \Dev3\AiReviewSummarizer\Settings::display()['layout'], 'not saved' );
	}
}
