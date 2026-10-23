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
	 * must also hook undergrowth_embed_identity to name its model. Without it
	 * the vector is never stored, and while an OpenAI key is saved it is
	 * thrown away and OpenAI is asked instead.
	 *
	 * @param float[]|null $vector Null unless an earlier callback supplied a vector.
	 * @param string       $text   Text to embed.
	 */
	$vector   = undergrowth_sanitize_vector( apply_filters( 'undergrowth_embed_provider', null, $text ) );
	$identity = undergrowth_embed_identity();
	$built_in = 'openai' === $identity['provider'] && UNDERGROWTH_OPENAI_MODEL === $identity['model'];

	// A filter vector is used only when its provider has named its own model.
	// Otherwise it would be stored as an OpenAI vector and compared with them.
	if ( array() !== $vector && ! $built_in ) {
		return $vector;
	}

	// OpenAI is called only while it is the declared provider, so a site that
	// names its own provider never sends post text to OpenAI by accident.
	return $built_in ? undergrowth_openai_embed( $text ) : array();
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
 *     OpenAI when a key is saved, otherwise empty strings until a provider
 *     declares itself.
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

	if ( '' !== undergrowth_openai_key() ) {
		$identity = array(
			'provider' => 'openai',
			'model'    => UNDERGROWTH_OPENAI_MODEL,
		);
	}

	/**
	 * Filters the provider and model names stored with every vector.
	 *
	 * Vectors from different models are never compared, so a provider must
	 * say which model it uses. When the provider or model name changes, every
	 * indexed post is embedded again from cron, a batch at a time.
	 *
	 * @param array $identity {
	 *     OpenAI when a key is saved, otherwise empty strings.
	 *
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

	// Each field comes from the filter's answer alone. Filling a missing model
	// from the OpenAI default would label another provider's vectors as
	// OpenAI's, so a missing field stays empty and the vector is not stored.
	$declared = array();

	foreach ( $column_lengths as $key => $max_length ) {
		$declared[ $key ] = isset( $filtered[ $key ] ) && is_string( $filtered[ $key ] )
			? mb_substr( sanitize_text_field( $filtered[ $key ] ), 0, $max_length )
			: '';
	}

	return $declared;
}

/**
 * Tells whether an identity names both a provider and a model. A vector is
 * stored only under a complete identity, so it can never be compared with
 * one from another model.
 *
 * @param array $identity Provider and model names.
 * @return bool
 *
 * @phpstan-param array{provider: string, model: string} $identity
 */
function undergrowth_identity_is_complete( array $identity ): bool {
	return '' !== $identity['provider'] && '' !== $identity['model'];
}

/**
 * Tells whether Undergrowth has something to read posts with: a saved key or
 * a provider hooked in through the filters.
 *
 * @return bool
 */
function undergrowth_has_embed_provider(): bool {
	return undergrowth_identity_is_complete( undergrowth_embed_identity() );
}
