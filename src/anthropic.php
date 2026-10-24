<?php
/**
 * The Anthropic Messages API client, which asks Claude to write prompts to
 * the author.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_ANTHROPIC_URL     = 'https://api.anthropic.com/v1/messages'; // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- The owner's own key, as the handover decided; the core AI Client needs WordPress 7.0 (#119).
const UNDERGROWTH_ANTHROPIC_VERSION = '2023-06-01';
const UNDERGROWTH_ANTHROPIC_MODEL   = 'claude-sonnet-5-5';

// A prompt is one sentence, but the model thinks before it answers and that
// counts against the same limit. A reply cut off by it is thrown away.
const UNDERGROWTH_ANTHROPIC_MAX_TOKENS = 4000;

/**
 * Returns the Anthropic API key: the UNDERGROWTH_ANTHROPIC_API_KEY constant
 * when wp-config.php defines it, otherwise the saved option.
 *
 * @return string The key, or an empty string when there is none.
 */
function undergrowth_anthropic_key(): string {
	$key = undergrowth_key_from_config( 'UNDERGROWTH_ANTHROPIC_API_KEY' );

	return '' !== $key ? $key : undergrowth_saved_key( 'undergrowth_anthropic_api_key' );
}

/**
 * Tells whether writing prompts is switched on, which it is only while there
 * is an Anthropic key.
 *
 * @return bool True when there is a key.
 */
function undergrowth_prompts_enabled(): bool {
	return '' !== undergrowth_anthropic_key();
}

/**
 * Returns the Claude model that writes prompts.
 *
 * @return string Model ID.
 */
function undergrowth_prompt_model(): string {
	/**
	 * Filters the Claude model that writes prompts.
	 *
	 * The request asks for low effort, so the model must accept the effort
	 * setting.
	 *
	 * @param string $model Model ID.
	 */
	$model = apply_filters( 'undergrowth_prompt_model', UNDERGROWTH_ANTHROPIC_MODEL );

	return is_string( $model ) && '' !== trim( $model ) ? trim( $model ) : UNDERGROWTH_ANTHROPIC_MODEL;
}

/**
 * Sends one message to Claude and returns its reply.
 *
 * Only a reply the model finished is returned. A cut-off reply or a refusal
 * is an error, so half a sentence never reaches the author.
 *
 * @param string $system The rules the reply must follow.
 * @param string $user   The data the reply is about.
 * @return string|WP_Error The reply's text, or the reason there is none.
 */
function undergrowth_anthropic_message( string $system, string $user ) {
	$key = undergrowth_anthropic_key();

	if ( '' === $key ) {
		return new WP_Error( 'no_key' );
	}

	$response = wp_remote_post(
		UNDERGROWTH_ANTHROPIC_URL,
		array(
			'timeout' => 60,
			'headers' => array(
				'x-api-key'         => $key,
				'anthropic-version' => UNDERGROWTH_ANTHROPIC_VERSION,
				'content-type'      => 'application/json',
			),
			'body'    => (string) wp_json_encode(
				array(
					'model'         => undergrowth_prompt_model(),
					'max_tokens'    => UNDERGROWTH_ANTHROPIC_MAX_TOKENS,
					'output_config' => array( 'effort' => 'low' ),
					'system'        => $system,
					'messages'      => array(
						array(
							'role'    => 'user',
							'content' => $user,
						),
					),
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return undergrowth_prompt_request_failed( $response->get_error_code(), 0 );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $status ) {
		return undergrowth_prompt_request_failed( 'http_status', $status );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $body ) || ! isset( $body['content'] ) || ! is_array( $body['content'] ) ) {
		return undergrowth_prompt_request_failed( 'invalid_response', $status );
	}

	$stop_reason = is_string( $body['stop_reason'] ?? null ) ? $body['stop_reason'] : '';

	if ( 'end_turn' !== $stop_reason ) {
		return undergrowth_prompt_request_failed( '' !== $stop_reason ? $stop_reason : 'invalid_response', $status );
	}

	$text = '';

	foreach ( $body['content'] as $block ) {
		if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) && is_string( $block['text'] ?? null ) ) {
			$text .= $block['text'];
		}
	}

	$text = trim( $text );

	if ( '' === $text ) {
		return undergrowth_prompt_request_failed( 'no_text', $status );
	}

	delete_option( 'undergrowth_last_prompt_error' );

	return $text;
}

/**
 * Records a failed request so the settings page can show it, and returns
 * the failure.
 *
 * Only the error code, HTTP status and time are kept. The key and the text
 * sent never reach this record.
 *
 * @param string $code   Error code.
 * @param int    $status HTTP status, or 0 when the request did not complete.
 * @return WP_Error The failure.
 */
function undergrowth_prompt_request_failed( string $code, int $status ): WP_Error {
	undergrowth_record_failure( 'undergrowth_last_prompt_error', $code, $status );

	return new WP_Error( sanitize_key( $code ) );
}
