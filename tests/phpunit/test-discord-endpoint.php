<?php
/**
 * Tests for the Discord interactions endpoint.
 *
 * @package Undergrowth
 */

/**
 * Signature checks and PING on the interactions endpoint.
 */
class Test_Discord_Endpoint extends WP_UnitTestCase {

	use Signs_Discord_Interactions;

	const PING = '{"type":1,"id":"1","application_id":"112233445566778899","token":"fake","version":1}';

	public function set_up() {
		parent::set_up();

		$this->start_signing();
	}

	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;
		delete_option( 'undergrowth_discord_public_key' );

		parent::tear_down();
	}

	/**
	 * Tells whether a response came from the handler rather than the
	 * permission check.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return bool
	 */
	private function reached_handler( WP_REST_Response $response ): bool {
		return ! $response->is_error();
	}

	public function test_route_is_registered_for_post_only() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/undergrowth/v1/discord', $routes );
		$this->assertSame( array( 'POST' => true ), $routes['/undergrowth/v1/discord'][0]['methods'] );
	}

	public function test_signed_ping_gets_pong() {
		$response = rest_get_server()->dispatch( $this->signed_request( self::PING ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'type' => 1 ), $response->get_data() );
	}

	public function test_unsigned_request_is_refused() {
		$response = rest_get_server()->dispatch( $this->unsigned_request( self::PING ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertFalse( $this->reached_handler( $response ) );
	}

	public function test_tampered_body_is_refused() {
		$request = $this->signed_request( self::PING );
		$request->set_body( str_replace( '"type":1', '"type":3', self::PING ) );

		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_signature_from_another_key_is_refused() {
		update_option( 'undergrowth_discord_public_key', bin2hex( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) ) );

		$this->assertSame( 401, rest_get_server()->dispatch( $this->signed_request( self::PING ) )->get_status() );
	}

	public function test_malformed_signature_is_refused() {
		$request = $this->signed_request( self::PING );
		$request->set_header( 'X-Signature-Ed25519', 'not-hex' );

		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_short_signature_is_refused() {
		$request = $this->signed_request( self::PING );
		$request->set_header( 'X-Signature-Ed25519', str_repeat( 'ab', 32 ) );

		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_changed_timestamp_is_refused() {
		$now     = time();
		$request = $this->signed_request( self::PING, $now );
		$request->set_header( 'X-Signature-Timestamp', (string) ( $now - 10 ) );

		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_stale_timestamp_is_refused_even_when_signed() {
		$this->assertSame( 401, rest_get_server()->dispatch( $this->signed_request( self::PING, time() - 400 ) )->get_status() );
	}

	public function test_timestamp_from_the_future_is_refused() {
		$this->assertSame( 401, rest_get_server()->dispatch( $this->signed_request( self::PING, time() + 400 ) )->get_status() );
	}

	public function test_slightly_old_timestamp_is_accepted() {
		$this->assertSame( 200, rest_get_server()->dispatch( $this->signed_request( self::PING, time() - 60 ) )->get_status() );
	}

	public function test_every_request_is_refused_without_a_public_key() {
		delete_option( 'undergrowth_discord_public_key' );

		$this->assertSame( 401, rest_get_server()->dispatch( $this->signed_request( self::PING ) )->get_status() );
	}

	public function test_get_is_not_allowed() {
		$request = new WP_REST_Request( 'GET', '/undergrowth/v1/discord' );

		$this->assertSame( 404, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_unknown_interaction_gets_an_ephemeral_reply() {
		$response = $this->send( array( 'type' => 2 ) );
		$data     = $response->get_data();

		$this->assertSame( 4, $data['type'] );
		$this->assertSame( 64, $data['data']['flags'] );
		$this->assertSame( array(), $data['data']['allowed_mentions']['parse'] );
	}

	/**
	 * Signed bodies that are JSON but not an interaction.
	 *
	 * @return array<string, string[]>
	 */
	public function data_bodies_that_are_not_interactions() {
		return array(
			'list'          => array( '[]' ),
			'string'        => array( '"x"' ),
			'no type'       => array( '{"id":"1"}' ),
			'type as text'  => array( '{"type":"1"}' ),
			'type as float' => array( '{"type":1.5}' ),
		);
	}

	/**
	 * Runs for each body.
	 *
	 * @dataProvider data_bodies_that_are_not_interactions
	 *
	 * @param string $body Raw request body.
	 */
	public function test_signed_body_that_is_not_an_interaction_gets_a_bad_request( $body ) {
		$response = rest_get_server()->dispatch( $this->signed_request( $body ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'undergrowth_discord_payload', $response->get_data()['code'] );
	}

	public function test_signature_is_checked_against_the_raw_body() {
		$request = $this->signed_request( self::PING );
		$request->set_param( 'type', 3 );
		$request->set_body_params( array( 'type' => 3 ) );

		$this->assertTrue( undergrowth_discord_verify_request( $request ) );

		$request->set_body( str_replace( '"version":1', '"version":2', self::PING ) );
		$this->assertWPError( undergrowth_discord_verify_request( $request ) );
	}
}
