<?php
/**
 * Tests for the Anthropic Messages API client that writes prompts.
 *
 * @package Undergrowth
 */

/**
 * Anthropic client.
 */
class Test_Anthropic extends WP_UnitTestCase {

	const KEY = 'sk-ant-test-0123456789abcd';

	/**
	 * Requests the client attempted, as [ url, args ].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private $requests = array();

	/**
	 * Response to return.
	 *
	 * @var array<string, mixed>|WP_Error
	 */
	private $response;

	public function set_up() {
		parent::set_up();

		$this->requests = array();
		$this->response = $this->reply( 'What do you actually do with oca in winter?' );
		update_option( 'undergrowth_anthropic_api_key', self::KEY );
		delete_option( 'undergrowth_last_prompt_error' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	/**
	 * Records a request and returns the queued response.
	 *
	 * @param false|array<string, mixed>|WP_Error $preempt Short-circuit value.
	 * @param array<string, mixed>                $args    Request arguments.
	 * @param string                              $url     Request URL.
	 * @return array<string, mixed>|WP_Error
	 */
	public function fake_http( $preempt, $args, $url ) {
		$this->requests[] = array( $url, $args );

		return $this->response;
	}

	/**
	 * Builds a Messages API reply.
	 *
	 * @param string $text        Text of the reply.
	 * @param string $stop_reason Why the model stopped.
	 * @return array<string, mixed>
	 */
	private function reply( string $text, string $stop_reason = 'end_turn' ): array {
		return $this->response(
			200,
			(string) wp_json_encode(
				array(
					'type'        => 'message',
					'role'        => 'assistant',
					'content'     => array(
						array(
							'type'     => 'thinking',
							'thinking' => '',
						),
						array(
							'type' => 'text',
							'text' => $text,
						),
					),
					'stop_reason' => $stop_reason,
				)
			)
		);
	}

	/**
	 * Builds an HTTP response array.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $body   Response body.
	 * @return array<string, mixed>
	 */
	private function response( int $status, string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Decodes the JSON body of the first recorded request.
	 *
	 * @return array<string, mixed>
	 */
	private function sent_body(): array {
		return json_decode( $this->requests[0][1]['body'], true );
	}

	public function test_prompts_are_off_without_a_key() {
		delete_option( 'undergrowth_anthropic_api_key' );

		$this->assertFalse( undergrowth_prompts_enabled() );
	}

	public function test_prompts_are_on_with_a_key() {
		$this->assertTrue( undergrowth_prompts_enabled() );
	}

	public function test_without_a_key_nothing_is_sent() {
		delete_option( 'undergrowth_anthropic_api_key' );

		$result = undergrowth_anthropic_message( 'Rules.', 'Data.' );

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->requests );
	}

	public function test_request_goes_to_the_messages_api_with_key_and_version() {
		undergrowth_anthropic_message( 'Rules.', 'Data.' );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://api.anthropic.com/v1/messages', $this->requests[0][0] );
		$this->assertSame( 'POST', $this->requests[0][1]['method'] );
		$this->assertSame( self::KEY, $this->requests[0][1]['headers']['x-api-key'] );
		$this->assertSame( '2023-06-01', $this->requests[0][1]['headers']['anthropic-version'] );
	}

	public function test_request_carries_the_system_text_apart_from_the_user_text() {
		undergrowth_anthropic_message( 'Rules.', 'Data.' );

		$body = $this->sent_body();
		$this->assertSame( 'Rules.', $body['system'] );
		$this->assertSame(
			array(
				array(
					'role'    => 'user',
					'content' => 'Data.',
				),
			),
			$body['messages']
		);
	}

	public function test_default_model_is_a_current_claude_model() {
		undergrowth_anthropic_message( 'Rules.', 'Data.' );

		$this->assertSame( 'claude-opus-5-5', $this->sent_body()['model'] );
	}

	public function test_model_can_be_changed_with_a_filter() {
		add_filter(
			'undergrowth_prompt_model',
			static function () {
				return 'claude-sonnet-5-5';
			}
		);

		undergrowth_anthropic_message( 'Rules.', 'Data.' );

		$this->assertSame( 'claude-sonnet-5-5', $this->sent_body()['model'] );
	}

	public function test_filtered_model_that_is_not_a_name_falls_back_to_the_default() {
		add_filter(
			'undergrowth_prompt_model',
			static function () {
				return array( 'claude' );
			}
		);

		$this->assertSame( 'claude-opus-5-5', undergrowth_prompt_model() );
	}

	public function test_reply_text_is_returned_without_thinking_blocks() {
		$this->assertSame( 'What do you actually do with oca in winter?', undergrowth_anthropic_message( 'Rules.', 'Data.' ) );
	}

	public function test_success_clears_the_last_error() {
		update_option( 'undergrowth_last_prompt_error', array( 'code' => 'http_status' ) );

		undergrowth_anthropic_message( 'Rules.', 'Data.' );

		$this->assertFalse( get_option( 'undergrowth_last_prompt_error' ) );
	}

	/**
	 * A reply cut off or refused is never used.
	 *
	 * @dataProvider data_unusable_stop_reasons
	 *
	 * @param string $stop_reason Why the model stopped.
	 */
	public function test_reply_that_did_not_finish_is_refused( string $stop_reason ) {
		$this->response = $this->reply( 'What do you', $stop_reason );

		$result = undergrowth_anthropic_message( 'Rules.', 'Data.' );

		$this->assertWPError( $result );
		$this->assertSame( $stop_reason, get_option( 'undergrowth_last_prompt_error' )['code'] );
	}

	/**
	 * Stop reasons that leave a reply unusable.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function data_unusable_stop_reasons(): array {
		return array(
			'cut off' => array( 'max_tokens' ),
			'refusal' => array( 'refusal' ),
		);
	}

	public function test_reply_without_text_is_refused() {
		$this->response = $this->reply( '   ' );

		$this->assertWPError( undergrowth_anthropic_message( 'Rules.', 'Data.' ) );
		$this->assertSame( 'no_text', get_option( 'undergrowth_last_prompt_error' )['code'] );
	}

	public function test_http_error_is_recorded_without_the_key_or_any_text() {
		$this->response = $this->response( 401, '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}' );

		$result = undergrowth_anthropic_message( 'Rules about oca.', 'Data about oca.' );

		$this->assertWPError( $result );

		$error = get_option( 'undergrowth_last_prompt_error' );
		$this->assertSame( 'http_status', $error['code'] );
		$this->assertSame( 401, $error['status'] );
		$this->assertSame( array( 'code', 'status', 'time' ), array_keys( $error ) );
		$this->assertStringNotContainsString( self::KEY, (string) wp_json_encode( $error ) );
	}

	public function test_transport_error_is_recorded() {
		$this->response = new WP_Error( 'http_request_failed', 'cURL error 28' );

		$this->assertWPError( undergrowth_anthropic_message( 'Rules.', 'Data.' ) );

		$error = get_option( 'undergrowth_last_prompt_error' );
		$this->assertSame( 'http_request_failed', $error['code'] );
		$this->assertSame( 0, $error['status'] );
	}

	public function test_body_that_is_not_json_is_refused() {
		$this->response = $this->response( 200, 'not json' );

		$this->assertWPError( undergrowth_anthropic_message( 'Rules.', 'Data.' ) );
	}
}
