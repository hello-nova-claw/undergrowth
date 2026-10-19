<?php
/**
 * The built-in OpenAI embedding provider.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_OPENAI_MODEL = 'text-embedding-3-small';
const UNDERGROWTH_OPENAI_URL   = 'https://api.openai.com/v1/embeddings';

// The model takes 8,192 tokens. A token is never shorter than a byte, so the
// retry limit is always safe; the first limit fits most posts in one request.
const UNDERGROWTH_OPENAI_MAX_BYTES   = 24000;
const UNDERGROWTH_OPENAI_RETRY_BYTES = 8000;

/**
 * Returns the saved OpenAI API key.
 *
 * @return string The key, or an empty string when none is saved.
 */
function undergrowth_openai_key(): string {
	$key = get_option( 'undergrowth_openai_api_key', '' );

	return is_string( $key ) ? $key : '';
}

/**
 * Asks OpenAI for the vector of a piece of text.
 *
 * Text the model rejects as too long is sent once more, cut to a length
 * that always fits.
 *
 * @param string $text Text to embed.
 * @return float[] The vector, or an empty array when the request failed.
 */
function undergrowth_openai_embed( string $text ): array {
	$key = undergrowth_openai_key();

	if ( '' === $key ) {
		return array();
	}

	$result = undergrowth_openai_request( $key, undergrowth_openai_truncate( $text, UNDERGROWTH_OPENAI_MAX_BYTES ) );

	if ( 400 === $result['status'] ) {
		$result = undergrowth_openai_request( $key, undergrowth_openai_truncate( $text, UNDERGROWTH_OPENAI_RETRY_BYTES ) );
	}

	$vector = undergrowth_sanitize_vector( $result['vector'] );

	if ( array() === $vector ) {
		undergrowth_record_embed_error( '' !== $result['error'] ? $result['error'] : 'invalid_response', $result['status'] );
		return array();
	}

	delete_option( 'undergrowth_last_embed_error' );

	return $vector;
}

/**
 * Sends one embeddings request.
 *
 * @param string $key  API key.
 * @param string $text Text to embed, already cut to length.
 * @return array {
 *     @type int    $status HTTP status, or 0 when the request did not complete.
 *     @type string $error  Error code, or an empty string.
 *     @type mixed  $vector The embedding as returned, before validation.
 * }
 *
 * @phpstan-return array{status: int, error: string, vector: mixed}
 */
function undergrowth_openai_request( string $key, string $text ): array {
	$response = wp_remote_post(
		UNDERGROWTH_OPENAI_URL,
		array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body'    => (string) wp_json_encode(
				array(
					'model' => UNDERGROWTH_OPENAI_MODEL,
					'input' => $text,
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'status' => 0,
			'error'  => sanitize_key( $response->get_error_code() ),
			'vector' => null,
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( wp_remote_retrieve_body( $response ), true );

	return array(
		'status' => $status,
		'error'  => 200 === $status ? '' : 'http_status',
		'vector' => 200 === $status && is_array( $body ) ? ( $body['data'][0]['embedding'] ?? null ) : null,
	);
}

/**
 * Cuts text to a number of bytes without splitting a character, at the last
 * space when there is one.
 *
 * @param string $text      Text to cut.
 * @param int    $max_bytes Longest result allowed, in bytes.
 * @return string The text, unchanged when it already fits.
 */
function undergrowth_openai_truncate( string $text, int $max_bytes ): string {
	if ( strlen( $text ) <= $max_bytes ) {
		return $text;
	}

	$cut   = mb_strcut( $text, 0, $max_bytes, 'UTF-8' );
	$space = strrpos( $cut, ' ' );

	return false !== $space && $space > 0 ? substr( $cut, 0, $space ) : $cut;
}

/**
 * Records the last failed request so the settings page can show it.
 *
 * Only the error code, HTTP status and time are kept. The key and the post
 * text never reach this record.
 *
 * @param string $code   Error code.
 * @param int    $status HTTP status, or 0.
 */
function undergrowth_record_embed_error( string $code, int $status ): void {
	update_option(
		'undergrowth_last_embed_error',
		array(
			'code'   => sanitize_key( $code ),
			'status' => $status,
			'time'   => time(),
		),
		false
	);
}
