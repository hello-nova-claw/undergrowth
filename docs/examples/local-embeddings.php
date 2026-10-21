<?php
/**
 * Plugin Name: Undergrowth local embeddings
 * Description: Embeds posts with a model running on this machine instead of OpenAI. An example for developers.
 * Version:     1.0.0
 * License:     GPL-2.0-or-later
 *
 * Copy this file to wp-content/mu-plugins/. It sends each post's text to an
 * OpenAI-compatible /v1/embeddings endpoint, such as Ollama, LM Studio,
 * llama.cpp's server or text-embeddings-inference, and tells Undergrowth
 * which model made the vectors. No OpenAI key is needed or used.
 *
 * With Ollama:
 *
 *     ollama pull nomic-embed-text
 *     ollama serve
 *
 * To use another server or model, define these in wp-config.php:
 *
 *     define( 'UNDERGROWTH_LOCAL_EMBED_URL', 'http://127.0.0.1:8080/v1/embeddings' );
 *     define( 'UNDERGROWTH_LOCAL_EMBED_PROVIDER', 'tei' );
 *     define( 'UNDERGROWTH_LOCAL_EMBED_MODEL', 'all-MiniLM-L6-v2' );
 *
 * Changing the provider or model name makes Undergrowth embed every post
 * again from cron, so name the model you actually run.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

defined( 'UNDERGROWTH_LOCAL_EMBED_URL' ) || define( 'UNDERGROWTH_LOCAL_EMBED_URL', 'http://127.0.0.1:11434/v1/embeddings' );
defined( 'UNDERGROWTH_LOCAL_EMBED_PROVIDER' ) || define( 'UNDERGROWTH_LOCAL_EMBED_PROVIDER', 'ollama' );
defined( 'UNDERGROWTH_LOCAL_EMBED_MODEL' ) || define( 'UNDERGROWTH_LOCAL_EMBED_MODEL', 'nomic-embed-text' );

// Some models want a task prefix, such as "clustering: " for nomic-embed-text.
defined( 'UNDERGROWTH_LOCAL_EMBED_PREFIX' ) || define( 'UNDERGROWTH_LOCAL_EMBED_PREFIX', '' );

// Small models often read only the first 2,048 tokens or fewer, and some
// servers refuse longer input instead of cutting it.
defined( 'UNDERGROWTH_LOCAL_EMBED_MAX_BYTES' ) || define( 'UNDERGROWTH_LOCAL_EMBED_MAX_BYTES', 8000 );

/**
 * Asks the local server for the vector of a post's text.
 *
 * @param float[]|null $vector Null unless an earlier callback supplied a vector.
 * @param string       $text   Text to embed.
 * @return float[]|null The vector, or null when the server gave none.
 */
function undergrowth_local_embeddings_vector( $vector, $text ) {
	if ( null !== $vector ) {
		return $vector;
	}

	$input    = mb_strcut( UNDERGROWTH_LOCAL_EMBED_PREFIX . $text, 0, UNDERGROWTH_LOCAL_EMBED_MAX_BYTES, 'UTF-8' );
	$response = wp_remote_post(
		UNDERGROWTH_LOCAL_EMBED_URL,
		array(
			// A model on a laptop CPU can take several seconds for a long post.
			'timeout' => 60,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode(
				array(
					'model' => UNDERGROWTH_LOCAL_EMBED_MODEL,
					'input' => $input,
				)
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	// Undergrowth checks the numbers itself and ignores anything else.
	return isset( $body['data'][0]['embedding'] ) && is_array( $body['data'][0]['embedding'] )
		? $body['data'][0]['embedding']
		: null;
}
add_filter( 'undergrowth_embed_provider', 'undergrowth_local_embeddings_vector', 10, 2 );

/**
 * Names the local model, so its vectors are never compared with another model's.
 *
 * @return array{provider: string, model: string}
 */
function undergrowth_local_embeddings_identity() {
	return array(
		'provider' => UNDERGROWTH_LOCAL_EMBED_PROVIDER,
		'model'    => UNDERGROWTH_LOCAL_EMBED_MODEL,
	);
}
add_filter( 'undergrowth_embed_identity', 'undergrowth_local_embeddings_identity' );
