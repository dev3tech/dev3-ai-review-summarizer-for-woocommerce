<?php
/**
 * Shared core smoke tests (keep these in every plugin).
 *
 * @package Dev3\AiReviewSummarizer
 */

use Dev3\AiReviewSummarizer\Core3\Api\Gemini_Client;
use Dev3\AiReviewSummarizer\Core3\Core\Encryption;
use Dev3\AiReviewSummarizer\Core3\Core\Rate_Limiter;
use Dev3\AiReviewSummarizer\Core3\Core\Settings_Store;

class CoreTest extends WP_UnitTestCase {

	public function test_encryption_and_key_masking() {
		$cipher = Encryption::encrypt( 'AIza-secret' );
		$this->assertSame( 'AIza-secret', Encryption::decrypt( $cipher ) );
		$public = Settings_Store::update( array( 'api_key' => 'AIzaABCD1234' ) );
		$this->assertSame( '••••••••1234', $public['api_key_masked'] );
		$this->assertArrayNotHasKey( 'api_key', $public );
		Settings_Store::update( array( 'api_key' => '••••••••1234' ) );
		$this->assertSame( 'AIzaABCD1234', Settings_Store::api_key() );
	}

	public function test_plugin_settings_are_merged_and_sanitized() {
		Settings_Store::update( array( 'temperature' => 9, 'min_reviews' => -4, 'auto_generate' => 'false' ) );
		$this->assertSame( 1.0, Settings_Store::get( 'temperature' ) );
		$this->assertSame( 1, Settings_Store::get( 'min_reviews' ) );
		$this->assertFalse( Settings_Store::get( 'auto_generate' ) );
	}

	public function test_decode_json_and_rate_limit() {
		$this->assertSame( array( 'a' => 1 ), Gemini_Client::decode_json( "```json\n{\"a\":1}\n```" ) );
		$this->assertNull( Gemini_Client::decode_json( '{"a":' ) );
		$this->assertTrue( Rate_Limiter::hit( 'test', '1.2.3.4', 2 ) );
		$this->assertTrue( Rate_Limiter::hit( 'test', '1.2.3.4', 2 ) );
		$this->assertFalse( Rate_Limiter::hit( 'test', '1.2.3.4', 2 ) );
	}

	public function test_core_routes_require_admin() {
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$req = new WP_REST_Request( 'GET', '/dev3rs/v1/settings' );
		$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 403, rest_do_request( $req )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 200, rest_do_request( $req )->get_status() );
	}
}
