<?php
/**
 * The built-in OpenAI embedding provider.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_OPENAI_MODEL = 'text-embedding-3-small';
const UNDERGROWTH_OPENAI_URL   = 'https://api.openai.com/v1/embeddings'; // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- The owner's own key, as the handover decided; the core AI Client needs WordPress 7.0 (#119).

// The model takes 8,192 tokens. A token is never shorter than a byte, so the
// retry limit is always safe; the first limit fits most posts in one request.
const UNDERGROWTH_OPENAI_MAX_BYTES   = 24000;
const UNDERGROWTH_OPENAI_RETRY_BYTES = 8000;

// After a 429 or 5xx, nothing is sent until Retry-After has passed, or five
// minutes without one. A longer Retry-After is cut to an hour, the backfill
// check's own interval.
const UNDERGROWTH_OPENAI_WAIT     = 300;
const UNDERGROWTH_OPENAI_MAX_WAIT = 3600;

/**
 * Returns the OpenAI API key: the UNDERGROWTH_OPENAI_API_KEY constant when
 * wp-config.php defines it, otherwise the saved option.
 *
 * @return string The key, or an empty string when there is none.
 */
function undergrowth_openai_key(): string {
	$key = undergrowth_key_from_config( 'UNDERGROWTH_OPENAI_API_KEY' );

	return '' !== $key ? $key : undergrowth_saved_key( 'undergrowth_openai_api_key' );
}

/**
 * Asks OpenAI for the vector of a piece of text.
 *
 * Text the model rejects as too long is sent once more, cut to a length
 * that always fits, unless undergrowth_openai_deadline() has passed. Nothing is sent while OpenAI has asked Undergrowth to
 * wait, see undergrowth_openai_retry_time().
 *
 * @param string $text Text to embed.
 * @return float[] The vector, or an empty array when the request failed.
 */
function undergrowth_openai_embed( string $text ): array {
	$key = undergrowth_openai_key();

	if ( '' === $key || 0 !== undergrowth_openai_retry_time() ) {
		return array();
	}

	$result = undergrowth_openai_request( $key, undergrowth_openai_truncate( $text, UNDERGROWTH_OPENAI_MAX_BYTES ) );

	// A batch with a time budget sends the shorter text only while it has
	// time left, so one slow post cannot hold it for two timeouts. The post is
	// tried again on the next pass.
	if ( 400 === $result['status'] && microtime( true ) < undergrowth_openai_deadline() ) {
		$result = undergrowth_openai_request( $key, undergrowth_openai_truncate( $text, UNDERGROWTH_OPENAI_RETRY_BYTES ) );
	}

	$vector = undergrowth_sanitize_vector( $result['vector'] );

	if ( array() === $vector ) {
		undergrowth_record_failure( 'undergrowth_last_embed_error', '' !== $result['error'] ? $result['error'] : 'invalid_response', $result['status'] );

		// Too many requests, or trouble on OpenAI's side: sending the next
		// post at once would only be turned away too.
		if ( 429 === $result['status'] || $result['status'] >= 500 ) {
			// A request in flight meanwhile may have asked for a longer wait.
			update_option( 'undergrowth_embed_retry_at', max( time() + undergrowth_openai_wait( $result['retry_after'] ), undergrowth_openai_retry_time() ), false );
		}

		return array();
	}

	delete_option( 'undergrowth_last_embed_error' );

	return $vector;
}

/**
 * Sets or reads the time after which no text too long for the model is sent
 * again shorter.
 *
 * @param float|null $deadline Unix time with microseconds, INF for none, or null to read it.
 * @return float The deadline, INF when none is set.
 */
function undergrowth_openai_deadline( ?float $deadline = null ): float {
	static $current = INF;

	if ( null !== $deadline ) {
		$current = $deadline;
	}

	return $current;
}

/**
 * Returns when OpenAI may be asked again, after it turned a request away
 * with a 429 or 5xx.
 *
 * @return int Unix time, or 0 when requests may be sent now.
 */
function undergrowth_openai_retry_time(): int {
	// Its own option, so a request that succeeds or fails otherwise at the
	// same time never clears the wait.
	$retry = (int) get_option( 'undergrowth_embed_retry_at', 0 );

	return $retry > time() ? $retry : 0;
}

/**
 * Turns a Retry-After header into seconds to wait.
 *
 * @param string $retry_after Seconds, or an HTTP date, or empty when there was none.
 * @return int Seconds, five minutes when the header is missing or unreadable, an hour at most.
 */
function undergrowth_openai_wait( string $retry_after ): int {
	$retry_after = trim( $retry_after );

	if ( ctype_digit( $retry_after ) ) {
		$seconds = (int) $retry_after;
	} else {
		$date    = '' !== $retry_after ? strtotime( $retry_after ) : false;
		$seconds = false !== $date ? $date - time() : 0;
	}

	return $seconds > 0 ? min( $seconds, UNDERGROWTH_OPENAI_MAX_WAIT ) : UNDERGROWTH_OPENAI_WAIT;
}

/**
 * Sends one embeddings request.
 *
 * @param string $key  API key.
 * @param string $text Text to embed, already cut to length.
 * @return array {
 *     @type int    $status      HTTP status, or 0 when the request did not complete.
 *     @type string $error       Error code, or an empty string.
 *     @type mixed  $vector      The embedding as returned, before validation.
 *     @type string $retry_after The Retry-After header, or an empty string.
 * }
 *
 * @phpstan-return array{status: int, error: string, vector: mixed, retry_after: string}
 */
function undergrowth_openai_request( string $key, string $text ): array {
	$response = wp_remote_post(
		UNDERGROWTH_OPENAI_URL,
		array(
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			),
			'body'        => (string) wp_json_encode(
				array(
					'model' => UNDERGROWTH_OPENAI_MODEL,
					'input' => $text,
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'status'      => 0,
			'error'       => sanitize_key( $response->get_error_code() ),
			'vector'      => null,
			'retry_after' => '',
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( wp_remote_retrieve_body( $response ), true );
	$retry  = wp_remote_retrieve_header( $response, 'retry-after' );

	return array(
		'status'      => $status,
		'error'       => 200 === $status ? '' : 'http_status',
		'vector'      => 200 === $status && is_array( $body ) ? ( $body['data'][0]['embedding'] ?? null ) : null,
		'retry_after' => is_string( $retry ) ? $retry : '',
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
