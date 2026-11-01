<?php
/**
 * Summary sanitising (AI output and moderator edits).
 *
 * @package Dev3\AiReviewSummarizer
 */

use Dev3\AiReviewSummarizer\Summary\Summary_Parser;

class ParserTest extends WP_UnitTestCase {

	public function test_strips_markup_links_and_caps_lengths() {
		$out = Summary_Parser::sanitize(
			array(
				'summary' => '<script>alert(1)</script><b>Great</b> shirt. Buy at https://evil.example/x now ' . str_repeat( 'word ', 300 ),
				'pros'    => array( '<img src=x onerror=alert(1)>Soft', 'Soft', '  ', '- Durable', 'a', 'b', 'c', 'd' ),
				'cons'    => "Runs small\nRuns small\n• Thin",
			)
		);
		$this->assertStringNotContainsString( '<', $out['summary'] );
		$this->assertStringNotContainsString( 'evil.example', $out['summary'] );
		$this->assertStringStartsWith( 'Great shirt.', $out['summary'] );
		$this->assertLessThanOrEqual( Summary_Parser::MAX_SUMMARY, mb_strlen( $out['summary'] ) );
		$this->assertSame( array( 'Soft', 'Durable', 'a', 'b', 'c' ), $out['pros'], 'deduped, trimmed, capped at 5' );
		$this->assertSame( array( 'Runs small', 'Thin' ), $out['cons'] );
	}

	public function test_sentiment_is_normalised_to_100() {
		$this->assertSame( array( 'positive' => 70, 'neutral' => 20, 'negative' => 10 ), Summary_Parser::sentiment( array( 'positive' => 70, 'neutral' => 20, 'negative' => 10 ) ) );
		$this->assertSame( array( 'positive' => 50, 'neutral' => 30, 'negative' => 20 ), Summary_Parser::sentiment( array( 'positive' => 0.5, 'neutral' => 0.3, 'negative' => 0.2 ) ) );
		$s = Summary_Parser::sentiment( array( 'positive' => 1, 'neutral' => 1, 'negative' => 1 ) );
		$this->assertSame( 100, array_sum( $s ) );
		$this->assertSame( array( 'positive' => 0, 'neutral' => 0, 'negative' => 0 ), Summary_Parser::sentiment( array( 'positive' => -5 ) ) );
		$this->assertSame( 100, array_sum( Summary_Parser::sentiment( array( 'positive' => 90, 'neutral' => 30, 'negative' => 'x' ) ) ) );
	}

	public function test_scores_are_clamped_and_sizing_whitelisted() {
		$this->assertSame( array( 'quality' => 5.0, 'value' => 1.0, 'sizing' => 'runs_small' ), Summary_Parser::scores( array( 'quality' => 9, 'value' => 0.4, 'sizing' => 'Runs Small' ) ) );
		$this->assertSame( array( 'quality' => null, 'value' => 4.3, 'sizing' => 'not_applicable' ), Summary_Parser::scores( array( 'quality' => null, 'value' => '4.26', 'sizing' => 'huge' ) ) );
		$this->assertSame( 'true_to_size', Summary_Parser::scores( array( 'sizing' => 'true' ) )['sizing'] );
		$this->assertSame( 'not_applicable', Summary_Parser::scores( array( 'sizing' => 'n/a' ) )['sizing'] );
	}

	public function test_keywords_and_flags() {
		$k = Summary_Parser::keywords( array( array( 'term' => 'Fabric', 'sentiment' => 'POSITIVE', 'mentions' => 3 ), array( 'term' => 'fabric' ), 'fit', array( 'term' => 'x', 'sentiment' => 'angry' ), 5 ) );
		$this->assertSame( array( 'Fabric', 'fit', 'x' ), wp_list_pluck( $k, 'term' ) );
		$this->assertSame( 'positive', $k[0]['sentiment'] );
		$this->assertSame( 'neutral', $k[2]['sentiment'] );
		$this->assertSame( array( 2, 4 ), Summary_Parser::flagged( array( 4, '2', -1, 'x', 4 ) ) );
	}

	public function test_garbage_input_is_safe() {
		$out = Summary_Parser::sanitize( 'not an array' );
		$this->assertFalse( Summary_Parser::is_usable( $out ) );
		$this->assertSame( 'not_applicable', $out['scores']['sizing'] );
		$this->assertTrue( Summary_Parser::is_usable( Summary_Parser::sanitize( array( 'cons' => array( 'Thin' ) ) ) ) );
	}
}
