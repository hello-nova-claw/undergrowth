<?php
/**
 * Tests for the notices that report a failed request to OpenAI, Anthropic
 * or Discord in plain words.
 *
 * @package Undergrowth
 */

/**
 * Each notice names the cause, then keeps the date and the status code.
 */
class Test_Failure_Notices extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		delete_option( 'undergrowth_last_embed_error' );
		delete_option( 'undergrowth_embed_retry_at' );
		delete_option( 'undergrowth_last_prompt_error' );
		delete_option( 'undergrowth_last_discord_error' );

		parent::tear_down();
	}

	/**
	 * Status codes and the words each should give.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public function causes(): array {
		return array(
			'unauthorised' => array( 401, 'did not accept the key' ),
			'forbidden'    => array( 403, 'did not accept the key' ),
			'too many'     => array( 429, 'too fast, or the account is out of credit' ),
			'no response'  => array( 0, 'could not be reached' ),
			'server'       => array( 500, 'had a problem on its side' ),
		);
	}

	/**
	 * Returns the saved time as the notices write it.
	 *
	 * @return string
	 */
	private function when(): string {
		return wp_date( get_option( 'date_format' ), 1767225600 ) . ' at ' . wp_date( get_option( 'time_format' ), 1767225600 );
	}

	/**
	 * Renders the notice for one service's saved error.
	 *
	 * @param string $option Option holding the error.
	 * @param int    $status HTTP status, or 0.
	 * @param string $code   Saved error code.
	 * @return string Notice text.
	 */
	private function notice( string $option, int $status, string $code = 'http_status' ): string {
		update_option(
			$option,
			array(
				'code'   => $code,
				'status' => $status,
				'time'   => 1767225600,
			)
		);

		ob_start();

		if ( 'undergrowth_last_discord_error' === $option ) {
			undergrowth_render_discord_section();
		} else {
			undergrowth_render_key_field(
				array(
					'label_for' => 'undergrowth_last_embed_error' === $option ? 'undergrowth_openai_api_key' : 'undergrowth_anthropic_api_key',
					'provider'  => 'undergrowth_last_embed_error' === $option ? 'openai' : 'anthropic',
				)
			);
		}

		$html = (string) ob_get_clean();

		$this->assertSame( 1, preg_match( '#<div class="notice notice-warning inline" id="undergrowth-(?:openai|anthropic|discord)-error">\s*<p>(.*?)</p>#s', $html, $match ) );

		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $match[1] ) ) );
	}

	/**
	 * Checks the OpenAI notice for one status code.
	 *
	 * @dataProvider causes
	 *
	 * @param int    $status HTTP status.
	 * @param string $words  Words the notice must hold.
	 */
	public function test_openai_notice_names_the_cause( int $status, string $words ) {
		$text = $this->notice( 'undergrowth_last_embed_error', $status );

		$this->assertStringContainsString( 'OpenAI', $text );
		$this->assertStringContainsString( $words, $text );
		$this->assertStringContainsString( '(status ' . $status . ')', $text );
		$this->assertStringContainsString( $this->when(), $text );
		$this->assertStringContainsString( 'tried again within the hour', $text );
	}

	/**
	 * Checks the Anthropic notice for one status code.
	 *
	 * @dataProvider causes
	 *
	 * @param int    $status HTTP status.
	 * @param string $words  Words the notice must hold.
	 */
	public function test_anthropic_notice_names_the_cause( int $status, string $words ) {
		$text = $this->notice( 'undergrowth_last_prompt_error', $status );

		$this->assertStringContainsString( 'Anthropic', $text );
		$this->assertStringContainsString( $words, $text );
		$this->assertStringContainsString( '(status ' . $status . ')', $text );
		$this->assertStringContainsString( $this->when(), $text );
		$this->assertStringContainsString( 'next monthly cycle', $text );
	}

	/**
	 * A source Claude answered without a question is skipped, not retried,
	 * so the notice says so and blames no one.
	 *
	 * @dataProvider answers
	 *
	 * @param string $code  Saved error code.
	 * @param string $words Words the notice must hold.
	 */
	public function test_anthropic_notice_says_an_answered_source_is_skipped( string $code, string $words ) {
		$text = $this->notice( 'undergrowth_last_prompt_error', 200, $code );

		$this->assertStringContainsString( $words, $text );
		$this->assertStringContainsString( 'skipped', $text );
		$this->assertStringContainsString( $this->when(), $text );
		$this->assertStringNotContainsString( 'problem on its side', $text );
		$this->assertStringNotContainsString( 'next monthly cycle', $text );
		$this->assertStringNotContainsString( 'status', $text );
	}

	/**
	 * Codes for a reply that came back but gave no question.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function answers(): array {
		return array(
			'refusal'    => array( 'refusal', 'declined' ),
			'max tokens' => array( 'max_tokens', 'cut off' ),
		);
	}

	/**
	 * Checks the Discord notice for one status code.
	 *
	 * @dataProvider causes
	 *
	 * @param int    $status HTTP status.
	 * @param string $words  Words the notice must hold.
	 */
	public function test_discord_notice_names_the_cause( int $status, string $words ) {
		$text = $this->notice( 'undergrowth_last_discord_error', $status );

		// Discord has a bot token, not a key, and no credit to run out of.
		$words = array(
			'did not accept the key'                    => 'did not accept the bot token',
			'too fast, or the account is out of credit' => 'came too fast',
		)[ $words ] ?? $words;

		$this->assertStringContainsString( 'Discord', $text );
		$this->assertStringContainsString( $words, $text );
		$this->assertStringContainsString( '(status ' . $status . ')', $text );
		$this->assertStringContainsString( $this->when(), $text );
		$this->assertStringContainsString( 'tried again the next time', $text );
	}

	public function test_a_status_code_alone_never_stands_for_the_cause() {
		$this->assertDoesNotMatchRegularExpression( '/failed on [^(]+\(status \d+\)\./', $this->notice( 'undergrowth_last_embed_error', 429 ) );
	}

	public function test_failure_record_keeps_only_the_code_status_and_time() {
		undergrowth_record_failure( 'undergrowth_last_embed_error', 'HTTP Status', 429 );

		$error = get_option( 'undergrowth_last_embed_error' );

		$this->assertSame( array( 'code', 'status', 'time' ), array_keys( $error ) );
		$this->assertSame( 'httpstatus', $error['code'] );
		$this->assertSame( 429, $error['status'] );
		$this->assertEqualsWithDelta( time(), $error['time'], 5 );
	}

	public function test_discord_pending_lists_the_suggestions_that_failed() {
		$this->assertSame( array(), undergrowth_discord_pending() );

		update_option(
			'undergrowth_last_discord_error',
			array(
				'time'    => 1767225600,
				'status'  => 500,
				'pending' => array(
					'1:2' => true,
					'3:4' => true,
				),
			)
		);

		$this->assertSame( array( '1:2', '3:4' ), undergrowth_discord_pending() );
	}

	public function test_openai_notice_says_when_posts_are_sent_again_after_a_wait() {
		$retry = time() + 120;
		update_option(
			'undergrowth_last_embed_error',
			array(
				'code'   => 'http_status',
				'status' => 429,
				'time'   => time(),
			)
		);
		update_option( 'undergrowth_embed_retry_at', $retry );

		ob_start();
		undergrowth_render_key_field(
			array(
				'label_for' => 'undergrowth_openai_api_key',
				'provider'  => 'openai',
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Undergrowth waits until ' . wp_date( get_option( 'date_format' ), $retry ) . ' at ' . wp_date( get_option( 'time_format' ), $retry ) . ', then sends the posts that were not read.', $html );
		$this->assertStringNotContainsString( 'within the hour', $html );
	}

	public function test_failure_notice_is_an_inline_warning_with_escaped_text() {
		ob_start();
		undergrowth_print_failure_notice( 'Salt & <b>pepper</b>' );
		undergrowth_print_failure_notice( 'Files.', 'undergrowth-file-error' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="notice notice-warning inline"><p>Salt &amp; &lt;b&gt;pepper&lt;/b&gt;</p></div>', $html );
		$this->assertStringContainsString( '<div class="notice notice-warning inline" id="undergrowth-file-error"><p>Files.</p></div>', $html );
	}
}
