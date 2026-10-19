<?php
/**
 * Tests for the built-in OpenAI embedding provider.
 *
 * @package Undergrowth
 */

/**
 * OpenAI provider.
 */
class Test_OpenAI extends WP_UnitTestCase {

	const KEY = 'sk-test-0123456789abcd';

	/**
	 * Requests the provider attempted, as [ url, args ].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private $requests = array();

	/**
	 * Responses to return, in order. The last one repeats.
	 *
	 * @var array<int, array<string, mixed>|WP_Error>
	 */
	private $responses = array();

	public function set_up() {
		parent::set_up();

		$this->requests  = array();
		$this->responses = array( $this->ok( array( 0.25, -0.5, 1 ) ) );
		update_option( 'undergrowth_openai_api_key', self::KEY );
		delete_option( 'undergrowth_last_embed_error' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	/**
	 * Records a request and returns the next queued response.
	 *
	 * @param false|array<string, mixed>|WP_Error $preempt Short-circuit value.
	 * @param array<string, mixed>                $args    Request arguments.
	 * @param string                              $url     Request URL.
	 * @return array<string, mixed>|WP_Error
	 */
	public function fake_http( $preempt, $args, $url ) {
		$this->requests[] = array( $url, $args );

		return count( $this->responses ) > 1 ? array_shift( $this->responses ) : $this->responses[0];
	}

	/**
	 * Builds a successful embeddings response.
	 *
	 * @param array<int, float|int> $vector Vector to return.
	 * @return array<string, mixed>
	 */
	private function ok( array $vector ): array {
		return $this->response( 200, wp_json_encode( array( 'data' => array( array( 'embedding' => $vector ) ) ) ) );
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
	 * Decodes the JSON body of a recorded request.
	 *
	 * @param int $index Request number.
	 * @return array<string, mixed>
	 */
	private function sent_body( int $index ): array {
		return json_decode( $this->requests[ $index ][1]['body'], true );
	}

	public function test_identity_is_openai_when_a_key_is_saved() {
		$this->assertSame(
			array(
				'provider' => 'openai',
				'model'    => 'text-embedding-3-small',
			),
			undergrowth_embed_identity()
		);
	}

	public function test_identity_is_empty_without_a_key() {
		delete_option( 'undergrowth_openai_api_key' );

		$this->assertSame( '', undergrowth_embed_identity()['provider'] );
	}

	public function test_without_a_key_nothing_is_sent() {
		delete_option( 'undergrowth_openai_api_key' );

		$this->assertSame( array(), undergrowth_embed( 'Oca keeps well.' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_request_goes_to_openai_with_key_and_model() {
		undergrowth_embed( 'Oca keeps well.' );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://api.openai.com/v1/embeddings', $this->requests[0][0] );
		$this->assertSame( 'POST', $this->requests[0][1]['method'] );
		$this->assertSame( 'Bearer ' . self::KEY, $this->requests[0][1]['headers']['Authorization'] );
		$this->assertSame( 'text-embedding-3-small', $this->sent_body( 0 )['model'] );
		$this->assertSame( 'Oca keeps well.', $this->sent_body( 0 )['input'] );
	}

	public function test_vector_is_read_from_the_response() {
		$this->assertSame( array( 0.25, -0.5, 1.0 ), undergrowth_embed( 'Oca keeps well.' ) );
	}

	public function test_filter_provider_wins_over_openai() {
		add_filter(
			'undergrowth_embed_provider',
			static function () {
				return array( 9.0 );
			}
		);

		$this->assertSame( array( 9.0 ), undergrowth_embed( 'Oca keeps well.' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_another_declared_provider_stops_openai_calls() {
		add_filter(
			'undergrowth_embed_identity',
			static function () {
				return array(
					'provider' => 'ollama',
					'model'    => 'nomic-embed-text',
				);
			}
		);

		$this->assertSame( array(), undergrowth_embed( 'Oca keeps well.' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_failed_request_returns_empty_vector_and_records_error_without_secrets() {
		$this->responses = array( $this->response( 500, '{"error":{"message":"Server error"}}' ) );

		$this->assertSame( array(), undergrowth_embed( 'Private draft about oca.' ) );

		$error = get_option( 'undergrowth_last_embed_error' );
		$this->assertSame( 500, $error['status'] );
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( $error ) );
		$this->assertStringNotContainsString( 'oca', wp_json_encode( $error ) );
	}

	public function test_transport_error_returns_empty_vector_and_records_error() {
		$this->responses = array( new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );

		$this->assertSame( array(), undergrowth_embed( 'Oca keeps well.' ) );
		$this->assertSame( 'http_request_failed', get_option( 'undergrowth_last_embed_error' )['code'] );
	}

	public function test_malformed_body_returns_empty_vector() {
		$this->responses = array( $this->response( 200, '{"data":[{"embedding":"nope"}]}' ) );

		$this->assertSame( array(), undergrowth_embed( 'Oca keeps well.' ) );
		$this->assertNotFalse( get_option( 'undergrowth_last_embed_error' ) );
	}

	public function test_success_clears_a_previous_error() {
		update_option( 'undergrowth_last_embed_error', array( 'code' => 'x' ) );

		undergrowth_embed( 'Oca keeps well.' );

		$this->assertFalse( get_option( 'undergrowth_last_embed_error' ) );
	}

	public function test_long_text_is_cut_before_sending() {
		undergrowth_embed( str_repeat( 'Oca keeps well. ', 10000 ) );

		$this->assertLessThanOrEqual( UNDERGROWTH_OPENAI_MAX_BYTES, strlen( $this->sent_body( 0 )['input'] ) );
	}

	public function test_too_long_for_the_model_is_retried_once_shorter() {
		$this->responses = array(
			$this->response( 400, '{"error":{"code":"context_length_exceeded"}}' ),
			$this->ok( array( 1.0 ) ),
		);

		$vector = undergrowth_embed( str_repeat( 'Oca keeps well. ', 10000 ) );

		$this->assertSame( array( 1.0 ), $vector );
		$this->assertCount( 2, $this->requests );
		$this->assertLessThan( strlen( $this->sent_body( 0 )['input'] ), strlen( $this->sent_body( 1 )['input'] ) );
	}

	public function test_cutting_text_keeps_valid_utf8() {
		$cut = undergrowth_openai_truncate( str_repeat( 'é', 10 ), 5 );

		$this->assertTrue( mb_check_encoding( $cut, 'UTF-8' ) );
		$this->assertLessThanOrEqual( 5, strlen( $cut ) );
	}

	public function test_cutting_text_ends_on_a_word_boundary_when_it_can() {
		$this->assertSame( 'Oca keeps', undergrowth_openai_truncate( 'Oca keeps well', 12 ) );
	}
}
