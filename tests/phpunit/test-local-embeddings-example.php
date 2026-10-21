<?php
/**
 * Tests for the local embedding model example in docs/examples.
 *
 * @package Undergrowth
 */

/**
 * Local embeddings example.
 */
class Test_Local_Embeddings_Example extends WP_UnitTestCase {

	/**
	 * Requests attempted during the test, as URL and decoded body.
	 *
	 * @var array<int, array{url: string, body: mixed}>
	 */
	private $requests = array();

	/**
	 * The response the fake server gives.
	 *
	 * @var array<string, mixed>|WP_Error
	 */
	private $response;

	public function set_up() {
		parent::set_up();

		// The example hooks its filters when it loads. WordPress restores the
		// hooks after each test, so they are hooked again for every test.
		require_once dirname( __DIR__, 2 ) . '/docs/examples/local-embeddings.php';
		add_filter( 'undergrowth_embed_provider', 'undergrowth_local_embeddings_vector', 10, 2 );
		add_filter( 'undergrowth_embed_identity', 'undergrowth_local_embeddings_identity' );
		add_filter( 'undergrowth_min_link_similarity', 'undergrowth_local_embeddings_link_similarity' );
		add_filter( 'undergrowth_min_cluster_similarity', 'undergrowth_local_embeddings_cluster_similarity' );
		add_filter( 'undergrowth_gap_similarity', 'undergrowth_local_embeddings_gap_similarity' );

		$this->requests = array();
		$this->respond( array( 'data' => array( array( 'embedding' => array( 0.25, -1, 0.5 ) ) ) ) );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	/**
	 * Sets the fake server's JSON response.
	 *
	 * @param mixed $body Decoded body to return with status 200.
	 */
	private function respond( $body ): void {
		$this->response = array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Records each request and returns the fake response.
	 *
	 * @param false|array<string, mixed>|WP_Error $preempt     Short-circuit value.
	 * @param array<string, mixed>                $parsed_args Request arguments.
	 * @param string                              $url         Request URL.
	 * @return array<string, mixed>|WP_Error
	 */
	public function fake_http( $preempt, $parsed_args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'body' => json_decode( is_string( $parsed_args['body'] ) ? $parsed_args['body'] : '', true ),
		);

		return $this->response;
	}

	public function test_identity_names_ollama_and_nomic_by_default() {
		$this->assertSame(
			array(
				'provider' => 'ollama',
				'model'    => 'nomic-embed-text',
			),
			undergrowth_embed_identity()
		);
	}

	public function test_returns_the_vector_from_the_local_server() {
		$this->assertSame( array( 0.25, -1.0, 0.5 ), undergrowth_embed( 'Oca keeps well over winter.' ) );
	}

	public function test_sends_the_text_and_model_to_the_local_server() {
		undergrowth_embed( 'Oca keeps well over winter.' );

		$this->assertSame( 'http://127.0.0.1:11434/v1/embeddings', $this->requests[0]['url'] );
		$this->assertSame(
			array(
				'model' => 'nomic-embed-text',
				'input' => 'Oca keeps well over winter.',
			),
			$this->requests[0]['body']
		);
	}

	public function test_long_text_is_cut_to_the_byte_limit() {
		undergrowth_embed( str_repeat( 'oca ', 5000 ) );

		$this->assertLessThanOrEqual( UNDERGROWTH_LOCAL_EMBED_MAX_BYTES, strlen( $this->requests[0]['body']['input'] ) );
	}

	public function test_cut_never_splits_a_character() {
		undergrowth_embed( 'a' . str_repeat( 'ü', 5000 ) );

		$this->assertTrue( mb_check_encoding( $this->requests[0]['body']['input'], 'UTF-8' ) );
	}

	public function test_error_status_with_a_vector_shaped_body_gives_no_vector() {
		$this->response['response'] = array(
			'code'    => 500,
			'message' => 'Internal Server Error',
		);

		$this->assertSame( array(), undergrowth_embed( 'Oca keeps well over winter.' ) );
	}

	public function test_any_success_status_is_accepted() {
		$this->response['response'] = array(
			'code'    => 203,
			'message' => 'Non-Authoritative Information',
		);

		$this->assertSame( array( 0.25, -1.0, 0.5 ), undergrowth_embed( 'Oca keeps well over winter.' ) );
	}

	public function test_failed_request_gives_no_vector() {
		$this->response = new WP_Error( 'http_request_failed', 'Connection refused' );

		$this->assertSame( array(), undergrowth_embed( 'Oca keeps well over winter.' ) );
	}

	public function test_malformed_response_gives_no_vector() {
		$this->respond( array( 'error' => 'model not found' ) );

		$this->assertSame( array(), undergrowth_embed( 'Oca keeps well over winter.' ) );
	}

	public function test_nothing_is_sent_to_openai_when_a_key_is_saved() {
		update_option( 'undergrowth_openai_api_key', 'sk-test' );
		$this->response = new WP_Error( 'http_request_failed', 'Connection refused' );

		undergrowth_embed( 'Oca keeps well over winter.' );

		$this->assertSame( array( 'http://127.0.0.1:11434/v1/embeddings' ), wp_list_pluck( $this->requests, 'url' ) );
	}

	public function test_nomic_thresholds_replace_the_openai_defaults() {
		$this->assertSame( 0.7, undergrowth_min_link_similarity() );
		$this->assertSame( 0.8, undergrowth_min_cluster_similarity() );
		$this->assertSame( 0.94, undergrowth_gap_similarity() );
	}

	public function test_other_models_keep_the_default_thresholds() {
		$this->assertSame( 0.4, undergrowth_local_embeddings_link_similarity( 0.4, 'all-minilm' ) );
		$this->assertSame( 0.5, undergrowth_local_embeddings_cluster_similarity( 0.5, 'all-minilm' ) );
		$this->assertSame( 0.8, undergrowth_local_embeddings_gap_similarity( 0.8, 'all-minilm' ) );
	}
}
