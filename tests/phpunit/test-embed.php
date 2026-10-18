<?php
/**
 * Tests for the embed function and its provider filters.
 *
 * @package Undergrowth
 */

/**
 * Embedding.
 */
class Test_Embed extends WP_UnitTestCase {

	/**
	 * URLs of HTTP requests attempted during the test.
	 *
	 * @var string[]
	 */
	private $requests = array();

	public function set_up() {
		parent::set_up();

		$this->requests = array();
		add_filter( 'pre_http_request', array( $this, 'block_http' ), 10, 3 );
	}

	/**
	 * Records and blocks every outbound request.
	 *
	 * @param false|array<string, mixed>|WP_Error $response    Short-circuit value.
	 * @param array<string, mixed>                $parsed_args Request arguments.
	 * @param string                              $url         Request URL.
	 * @return WP_Error
	 */
	public function block_http( $response, $parsed_args, $url ) {
		$this->requests[] = $url;

		return new WP_Error( 'blocked', 'Tests make no HTTP requests.' );
	}

	/**
	 * Hooks a provider that returns the given value.
	 *
	 * @param mixed $value What the provider returns.
	 */
	private function provide( $value ): void {
		add_filter(
			'undergrowth_embed_provider',
			static function () use ( $value ) {
				return $value;
			}
		);
	}

	public function test_without_provider_returns_empty_vector() {
		$this->assertSame( array(), undergrowth_embed( 'Oca keeps well over winter.' ) );
	}

	public function test_without_provider_sends_nothing_off_site() {
		undergrowth_embed( 'Oca keeps well over winter.' );

		$this->assertSame( array(), $this->requests );
	}

	public function test_provider_vector_is_returned_as_floats() {
		$this->provide( array( 0.5, 2, -3.25 ) );

		$this->assertSame( array( 0.5, 2.0, -3.25 ), undergrowth_embed( 'Oca keeps well over winter.' ) );
	}

	public function test_provider_receives_the_text() {
		$received = null;
		add_filter(
			'undergrowth_embed_provider',
			static function ( $vector, $text ) use ( &$received ) {
				$received = $text;
				return array( 1.0 );
			},
			10,
			2
		);

		undergrowth_embed( 'Oca keeps well over winter.' );

		$this->assertSame( 'Oca keeps well over winter.', $received );
	}

	public function test_blank_text_is_not_sent_to_provider() {
		$called = false;
		add_filter(
			'undergrowth_embed_provider',
			static function () use ( &$called ) {
				$called = true;
				return array( 1.0 );
			}
		);

		$this->assertSame( array(), undergrowth_embed( "  \n\t " ) );
		$this->assertFalse( $called );
	}

	/**
	 * Return values from a provider that are not usable vectors.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function data_malformed_vectors(): array {
		return array(
			'string'         => array( '0.1,0.2' ),
			'empty array'    => array( array() ),
			'keyed array'    => array( array( 'a' => 1.0 ) ),
			'gap in keys'    => array(
				array(
					0 => 1.0,
					2 => 2.0,
				),
			),
			'numeric string' => array( array( 1.0, '0.5' ) ),
			'null member'    => array( array( 1.0, null ) ),
			'nested array'   => array( array( array( 1.0 ) ) ),
			'not a number'   => array( array( 1.0, NAN ) ),
			'infinity'       => array( array( INF, 1.0 ) ),
			'boolean'        => array( array( true ) ),
		);
	}

	/**
	 * Values that are not a flat list of finite numbers are rejected.
	 *
	 * @dataProvider data_malformed_vectors
	 *
	 * @param mixed $value What the provider returns.
	 */
	public function test_malformed_provider_vector_returns_empty_vector( $value ) {
		$this->provide( $value );

		$this->assertSame( array(), undergrowth_embed( 'Oca keeps well over winter.' ) );
	}

	public function test_identity_is_empty_without_provider() {
		$this->assertSame(
			array(
				'provider' => '',
				'model'    => '',
			),
			undergrowth_embed_identity()
		);
	}

	public function test_identity_comes_from_filter() {
		add_filter(
			'undergrowth_embed_identity',
			static function () {
				return array(
					'provider' => 'ollama',
					'model'    => 'nomic-embed-text',
				);
			}
		);

		$this->assertSame(
			array(
				'provider' => 'ollama',
				'model'    => 'nomic-embed-text',
			),
			undergrowth_embed_identity()
		);
	}

	public function test_identity_is_sanitised() {
		add_filter(
			'undergrowth_embed_identity',
			static function () {
				return array(
					'provider' => " <b>ollama</b>\n",
					'model'    => 42,
				);
			}
		);

		$this->assertSame(
			array(
				'provider' => 'ollama',
				'model'    => '',
			),
			undergrowth_embed_identity()
		);
	}

	public function test_identity_that_is_not_an_array_is_ignored() {
		add_filter(
			'undergrowth_embed_identity',
			static function () {
				return 'ollama';
			}
		);

		$this->assertSame(
			array(
				'provider' => '',
				'model'    => '',
			),
			undergrowth_embed_identity()
		);
	}
}
