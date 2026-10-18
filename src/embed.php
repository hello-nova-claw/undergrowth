<?php
/**
 * The single embed function and its provider filters.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns text into a vector of floats.
 *
 * Nothing else in the plugin knows which model made the vector. Callers that
 * store it record undergrowth_embed_identity() beside it.
 *
 * @param string $text Text to embed.
 * @return float[] The vector, or an empty array when no provider gave one.
 */
function undergrowth_embed( string $text ): array {
	if ( '' === trim( $text ) ) {
		return array();
	}

	/**
	 * Filters the vector for a piece of text before any built-in provider runs.
	 *
	 * Return a list of numbers to supply the vector yourself, for example from
	 * an embedding service running on the same server. A provider hooked here
	 * must also hook undergrowth_embed_identity, or its vectors are not stored.
	 *
	 * @param float[]|null $vector Null unless an earlier callback supplied a vector.
	 * @param string       $text   Text to embed.
	 */
	$vector = apply_filters( 'undergrowth_embed_provider', null, $text );

	// No hosted provider ships until the default is chosen, so without a
	// filter no post content leaves the site.
	return undergrowth_sanitize_vector( $vector );
}

/**
 * Accepts only a non-empty flat list of finite numbers.
 *
 * @param mixed $vector Value returned by a provider.
 * @return float[] The vector as floats, or an empty array when it is unusable.
 */
function undergrowth_sanitize_vector( $vector ): array {
	if ( ! is_array( $vector ) || array() === $vector || array_keys( $vector ) !== range( 0, count( $vector ) - 1 ) ) {
		return array();
	}

	$floats = array();

	foreach ( $vector as $value ) {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return array();
		}

		$value = (float) $value;

		if ( ! is_finite( $value ) ) {
			return array();
		}

		$floats[] = $value;
	}

	return $floats;
}

/**
 * Names the provider and model that undergrowth_embed() currently uses.
 *
 * @return array {
 *     Empty strings when no provider has declared itself.
 *
 *     @type string $provider Provider name.
 *     @type string $model    Model name.
 * }
 *
 * @phpstan-return array{provider: string, model: string}
 */
function undergrowth_embed_identity(): array {
	$identity = array(
		'provider' => '',
		'model'    => '',
	);

	/**
	 * Filters the provider and model names stored with every vector.
	 *
	 * Vectors from different models are never compared, so a provider must
	 * say which model it uses. Change the model name whenever the model
	 * changes, and posts are embedded again on their next save.
	 *
	 * @param array $identity {
	 *     @type string $provider Provider name, such as "ollama".
	 *     @type string $model    Model name, such as "nomic-embed-text".
	 * }
	 */
	$filtered = apply_filters( 'undergrowth_embed_identity', $identity );

	if ( ! is_array( $filtered ) ) {
		return $identity;
	}

	$column_lengths = array(
		'provider' => 100,
		'model'    => 191,
	);

	foreach ( $column_lengths as $key => $max_length ) {
		if ( isset( $filtered[ $key ] ) && is_string( $filtered[ $key ] ) ) {
			$identity[ $key ] = mb_substr( sanitize_text_field( $filtered[ $key ] ), 0, $max_length );
		}
	}

	return $identity;
}
