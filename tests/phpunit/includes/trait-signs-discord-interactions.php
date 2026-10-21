<?php
/**
 * Test helper: signs fake Discord interactions with a throwaway key.
 *
 * @package Undergrowth
 */

/**
 * Sends signed interactions to the Discord endpoint, as Discord would.
 *
 * A test class using this calls start_signing() from set_up(). The keypair
 * is made fresh for each test and never leaves it.
 */
trait Signs_Discord_Interactions {

	/**
	 * Ed25519 secret key the fake Discord signs with.
	 *
	 * @var string
	 */
	private $secret_key = '';

	/**
	 * Makes a throwaway keypair and saves its public half as the site's key.
	 */
	private function start_signing(): void {
		global $wp_rest_server;

		$keypair          = sodium_crypto_sign_keypair();
		$this->secret_key = sodium_crypto_sign_secretkey( $keypair );

		update_option( 'undergrowth_discord_public_key', bin2hex( sodium_crypto_sign_publickey( $keypair ) ) );

		$wp_rest_server = null;
		rest_get_server();
	}

	/**
	 * Builds a request to the endpoint, signed over the timestamp and body.
	 *
	 * @param string   $body      Raw request body.
	 * @param int|null $timestamp Signing time; now when null.
	 * @return WP_REST_Request
	 */
	private function signed_request( string $body, ?int $timestamp = null ): WP_REST_Request {
		$timestamp = (string) ( $timestamp ?? time() );
		$request   = $this->unsigned_request( $body );

		$request->set_header( 'X-Signature-Ed25519', bin2hex( sodium_crypto_sign_detached( $timestamp . $body, $this->secret_key ) ) );
		$request->set_header( 'X-Signature-Timestamp', $timestamp );

		return $request;
	}

	/**
	 * Builds a request to the endpoint with no signature.
	 *
	 * @param string $body Raw request body.
	 * @return WP_REST_Request
	 */
	private function unsigned_request( string $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/undergrowth/v1/discord' );

		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );

		return $request;
	}

	/**
	 * Signs an interaction and sends it to the endpoint.
	 *
	 * @param array $interaction Interaction payload.
	 * @return WP_REST_Response
	 */
	private function send( array $interaction ): WP_REST_Response {
		return rest_get_server()->dispatch( $this->signed_request( (string) wp_json_encode( $interaction ) ) );
	}
}
