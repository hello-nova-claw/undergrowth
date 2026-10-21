<?php
/**
 * The Discord interactions endpoint, where Discord sends button clicks.
 *
 * The address is public, so nothing reaches the handler unless Discord
 * signed it with the application's key.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

// Discord signs each request with the time it was sent. A signed request
// replayed after this many seconds is refused.
const UNDERGROWTH_DISCORD_MAX_AGE = 300;

const UNDERGROWTH_DISCORD_PING            = 1;
const UNDERGROWTH_DISCORD_PONG            = 1;
const UNDERGROWTH_DISCORD_COMPONENT       = 3;
const UNDERGROWTH_DISCORD_CHANNEL_MESSAGE = 4;
const UNDERGROWTH_DISCORD_FLAG_EPHEMERAL  = 64;

add_action( 'rest_api_init', 'undergrowth_register_discord_route' );
add_filter( 'rest_pre_dispatch', 'undergrowth_discord_check_first', 10, 3 );

/**
 * Registers the interactions endpoint.
 */
function undergrowth_register_discord_route(): void {
	register_rest_route(
		UNDERGROWTH_DISCORD_NAMESPACE,
		UNDERGROWTH_DISCORD_ROUTE,
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'undergrowth_discord_interaction',
			'permission_callback' => 'undergrowth_discord_verify_request',
		)
	);
}

/**
 * Refuses an unsigned request to the endpoint before WordPress reads it.
 *
 * WordPress checks a JSON body before it calls the permission callback, so
 * without this an unsigned request with a broken body would be told it was
 * malformed rather than refused. Routes match without regard to case, as
 * they do when WordPress dispatches them.
 *
 * @param mixed           $result  Response from an earlier filter, or null.
 * @param WP_REST_Server  $server  REST server.
 * @param WP_REST_Request $request Request.
 * @return mixed The earlier response, the refusal, or null to carry on.
 */
function undergrowth_discord_check_first( $result, $server, $request ) {
	if ( null !== $result || ! $request instanceof WP_REST_Request || 'POST' !== $request->get_method() || 0 !== strcasecmp( '/' . UNDERGROWTH_DISCORD_NAMESPACE . UNDERGROWTH_DISCORD_ROUTE, untrailingslashit( $request->get_route() ) ) ) {
		return $result;
	}

	$verified = undergrowth_discord_verify_request( $request );

	return is_wp_error( $verified ) ? $verified : null;
}

/**
 * Checks that Discord signed a request, over its raw body, recently.
 *
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error True when the signature is good.
 */
function undergrowth_discord_verify_request( WP_REST_Request $request ) {
	$refused = new WP_Error(
		'undergrowth_discord_signature',
		__( 'This request is not signed by Discord.', 'undergrowth' ),
		array( 'status' => 401 )
	);

	$key       = undergrowth_discord_setting( 'public_key' );
	$signature = (string) $request->get_header( 'x_signature_ed25519' );
	$timestamp = (string) $request->get_header( 'x_signature_timestamp' );

	if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $key ) || 1 !== preg_match( '/^[0-9a-f]{128}$/i', $signature ) || 1 !== preg_match( '/^[0-9]{1,12}$/', $timestamp ) ) {
		return $refused;
	}

	if ( abs( time() - (int) $timestamp ) > UNDERGROWTH_DISCORD_MAX_AGE ) {
		return $refused;
	}

	try {
		$valid = sodium_crypto_sign_verify_detached( (string) hex2bin( $signature ), $timestamp . $request->get_body(), (string) hex2bin( $key ) );
	} catch ( SodiumException $e ) {
		$valid = false;
	}

	return $valid ? true : $refused;
}

/**
 * Answers an interaction Discord has signed.
 *
 * Everything in the payload came from Discord's users, so it is read as
 * data: only the fields named here are used, and none is echoed back.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error The reply Discord shows.
 */
function undergrowth_discord_interaction( WP_REST_Request $request ) {
	$interaction = json_decode( $request->get_body(), true );

	if ( ! is_array( $interaction ) || ! isset( $interaction['type'] ) || ! is_int( $interaction['type'] ) ) {
		return new WP_Error( 'undergrowth_discord_payload', __( 'This interaction could not be read.', 'undergrowth' ), array( 'status' => 400 ) );
	}

	if ( UNDERGROWTH_DISCORD_PING === $interaction['type'] ) {
		return new WP_REST_Response( array( 'type' => UNDERGROWTH_DISCORD_PONG ) );
	}

	if ( UNDERGROWTH_DISCORD_COMPONENT === $interaction['type'] ) {
		return undergrowth_discord_click( $interaction );
	}

	return undergrowth_discord_ephemeral( __( 'Undergrowth only answers its own Accept and Veto buttons.', 'undergrowth' ) );
}

/**
 * Builds a reply only the person who clicked can see.
 *
 * @param string $message Text of the reply.
 * @return WP_REST_Response
 */
function undergrowth_discord_ephemeral( string $message ): WP_REST_Response {
	return new WP_REST_Response(
		array(
			'type' => UNDERGROWTH_DISCORD_CHANNEL_MESSAGE,
			'data' => array(
				'content'          => $message,
				'flags'            => UNDERGROWTH_DISCORD_FLAG_EPHEMERAL,
				'allowed_mentions' => array( 'parse' => array() ),
			),
		)
	);
}
