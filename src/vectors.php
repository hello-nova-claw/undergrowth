<?php
/**
 * Reading and writing rows in the vectors table, and comparing vectors.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_VECTOR_CHUNK = 100;

/**
 * Stores the vector for a post, replacing any earlier one.
 *
 * A vector without its provider, model or language is refused, because it
 * could later be compared against vectors from a different model or language.
 *
 * @param int     $post_id Post the vector was made from.
 * @param float[] $vector  The vector.
 * @param array   $meta {
 *     Where the vector came from.
 *
 *     @type string $provider     Provider name.
 *     @type string $model        Model name.
 *     @type string $language     Locale of the post, such as en_US.
 *     @type string $content_hash SHA-256 of the text that was embedded.
 *     @type string $embedded_at  Optional. When the text was read, as a UTC MySQL date. Default now.
 * }
 * @return bool True when the row was written.
 *
 * @phpstan-param array{provider: string, model: string, language: string, content_hash: string, embedded_at?: string} $meta
 */
function undergrowth_save_vector( int $post_id, array $vector, array $meta ): bool {
	global $wpdb;

	if ( '' === $meta['provider'] || '' === $meta['model'] || '' === $meta['language'] ) {
		return false;
	}

	// An invalid vector would encode to an empty string that later reads as
	// a stored, up-to-date vector, so it is refused rather than written. A
	// list of finite floats always encodes, so no further check is needed.
	$clean = undergrowth_sanitize_vector( $vector );

	if ( array() === $clean ) {
		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
	$written = $wpdb->replace(
		undergrowth_vectors_table(),
		array(
			'post_id'      => $post_id,
			'provider'     => $meta['provider'],
			'model'        => $meta['model'],
			'language'     => $meta['language'],
			'dimensions'   => count( $clean ),
			'embedding'    => wp_json_encode( $clean ),
			'content_hash' => $meta['content_hash'],
			'embedded_at'  => $meta['embedded_at'] ?? current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
	);

	return false !== $written;
}

/**
 * Reads the stored vector for a post.
 *
 * @param int $post_id Post ID.
 * @return array|null The row, with the vector decoded to floats, or null when the post has none.
 *
 * @phpstan-return array{post_id: int, provider: string, model: string, language: string, dimensions: int, vector: float[], content_hash: string, embedded_at: string}|null
 */
function undergrowth_get_vector( int $post_id ): ?array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every embed.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', undergrowth_vectors_table(), $post_id ), ARRAY_A );

	if ( ! is_array( $row ) ) {
		return null;
	}

	$vector = json_decode( (string) $row['embedding'], true );

	return array(
		'post_id'      => (int) $row['post_id'],
		'provider'     => (string) $row['provider'],
		'model'        => (string) $row['model'],
		'language'     => (string) $row['language'],
		'dimensions'   => (int) $row['dimensions'],
		'vector'       => is_array( $vector ) ? array_map( 'floatval', $vector ) : array(),
		'content_hash' => (string) $row['content_hash'],
		'embedded_at'  => (string) $row['embedded_at'],
	);
}

/**
 * Reads the provider, model and language of a post's stored vector, without
 * reading the vector itself.
 *
 * @param int $post_id Post ID.
 * @return array|null The vector's space, or null when the post has none.
 *
 * @phpstan-return array{provider: string, model: string, language: string}|null
 */
function undergrowth_get_vector_space( int $post_id ): ?array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every embed.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT provider, model, language FROM %i WHERE post_id = %d', undergrowth_vectors_table(), $post_id ), ARRAY_A );

	if ( ! is_array( $row ) ) {
		return null;
	}

	return array(
		'provider' => (string) $row['provider'],
		'model'    => (string) $row['model'],
		'language' => (string) $row['language'],
	);
}

/**
 * Removes the stored vector for a post, if it has one.
 *
 * @param int          $post_id Post ID.
 * @param WP_Post|null $post    The deleted post, when core's hook passes it.
 */
function undergrowth_delete_vector( int $post_id, ?WP_Post $post = null ): void {
	global $wpdb;

	if ( undergrowth_ignores_post( $post ) ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
	$wpdb->delete( undergrowth_vectors_table(), array( 'post_id' => $post_id ), array( '%d' ) );
}

/**
 * Decodes an embedding as stored in the vectors table.
 *
 * @param string $json The stored embedding.
 * @return float[] The vector, or an empty array when the stored value is not one.
 */
function undergrowth_decode_embedding( string $json ): array {
	return undergrowth_sanitize_vector( json_decode( $json, true ) );
}

/**
 * Names the space a vector belongs to. Vectors are only ever compared with
 * vectors of the same provider, model and language.
 *
 * @param array $row Vector row, or a vector read with undergrowth_get_vector().
 * @return string A key that two vectors share only when they may be compared.
 *
 * @phpstan-param array{provider: string, model: string, language: string} $row
 */
function undergrowth_vector_space( array $row ): string {
	return $row['provider'] . "\n" . $row['model'] . "\n" . $row['language'];
}

/**
 * Tells whether two vectors can be compared at all.
 *
 * Vectors of different lengths, or with no magnitude, have no meaningful
 * similarity. Checking this apart from the score keeps them out even when
 * the minimum similarity is set at or below zero.
 *
 * @param float[] $a First vector.
 * @param float[] $b Second vector.
 * @return bool True when both have the same length and some magnitude.
 */
function undergrowth_vectors_comparable( array $a, array $b ): bool {
	if ( array() === $a || count( $a ) !== count( $b ) ) {
		return false;
	}

	return array_filter( $a ) !== array() && array_filter( $b ) !== array();
}

/**
 * Measures how alike two vectors are.
 *
 * @param float[] $a First vector.
 * @param float[] $b Second vector.
 * @return float Cosine similarity, or 0 when the vectors cannot be compared.
 */
function undergrowth_cosine( array $a, array $b ): float {
	$count = count( $a );

	if ( 0 === $count || count( $b ) !== $count ) {
		return 0.0;
	}

	$dot    = 0.0;
	$norm_a = 0.0;
	$norm_b = 0.0;

	for ( $i = 0; $i < $count; $i++ ) {
		$dot    += $a[ $i ] * $b[ $i ];
		$norm_a += $a[ $i ] * $a[ $i ];
		$norm_b += $b[ $i ] * $b[ $i ];
	}

	if ( 0.0 === $norm_a || 0.0 === $norm_b ) {
		return 0.0;
	}

	return $dot / sqrt( $norm_a * $norm_b );
}

/**
 * Packs a vector into four bytes a dimension.
 *
 * Embedding models produce single-precision floats, so packing keeps what
 * they sent in about a tenth of the memory a PHP array of floats takes. A
 * provider sending doubles loses precision beyond about seven digits, which
 * moves a score by around 1e-7 at most. Two targets closer than that may
 * swap places. Packing doubles instead would take twice the memory.
 *
 * @param float[] $vector The vector.
 * @return string Packed floats, or an empty string when a value is too large
 *                for single precision and would turn into infinity.
 */
function undergrowth_pack_vector( array $vector ): string {
	foreach ( $vector as $value ) {
		if ( abs( $value ) > 3.4028234663852886E+38 ) {
			return '';
		}
	}

	return pack( 'g*', ...$vector );
}

/**
 * Unpacks a vector packed by undergrowth_pack_vector().
 *
 * @param string $packed Packed floats.
 * @return float[] The vector.
 */
function undergrowth_unpack_vector( string $packed ): array {
	$vector = '' === $packed ? false : unpack( 'g*', $packed );

	return is_array( $vector ) ? array_values( $vector ) : array();
}

/**
 * Returns how many vectors are read in one query.
 *
 * @return int Vectors per chunk, at least one.
 */
function undergrowth_vector_chunk_size(): int {
	/**
	 * Filters how many stored vectors are read in one query.
	 *
	 * A smaller chunk holds less in memory at once and sends more queries.
	 *
	 * @param int $size Vectors per chunk. Default 100.
	 */
	return max( 1, (int) apply_filters( 'undergrowth_vector_chunk_size', UNDERGROWTH_VECTOR_CHUNK ) );
}

/**
 * Loads every stored vector that can be compared with a post's vector.
 *
 * Only vectors from the same provider, model and language qualify, because
 * scores across models or languages mean nothing.
 *
 * @param int $post_id Post whose vector sets the provider, model and language.
 * @return array<int, string> Packed vectors keyed by post ID, including the post's own.
 */
function undergrowth_comparable_vectors( int $post_id ): array {
	$source = undergrowth_get_vector( $post_id );

	return null === $source ? array() : undergrowth_vectors_in_space( $source['provider'], $source['model'], $source['language'] );
}

/**
 * Loads every stored vector from one provider, model and language.
 *
 * Rows are read a chunk at a time and kept packed, so a large archive never
 * sits in memory as JSON or as PHP arrays of floats.
 *
 * @param string $provider Provider name.
 * @param string $model    Model name.
 * @param string $language Locale, such as en_US.
 * @return array<int, string> Packed vectors keyed by post ID.
 */
function undergrowth_vectors_in_space( string $provider, string $model, string $language ): array {
	global $wpdb;

	$vectors = array();
	$after   = 0;
	$size    = undergrowth_vector_chunk_size();

	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; vectors change on every embed.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT post_id, embedding FROM %i WHERE provider = %s AND model = %s AND language = %s AND post_id > %d ORDER BY post_id LIMIT %d',
				undergrowth_vectors_table(),
				$provider,
				$model,
				$language,
				$after,
				$size
			),
			ARRAY_A
		);

		$fetched = count( $rows );

		foreach ( $rows as $row ) {
			$after  = (int) $row['post_id'];
			$packed = undergrowth_pack_vector( undergrowth_decode_embedding( (string) $row['embedding'] ) );

			if ( '' !== $packed ) {
				$vectors[ $after ] = $packed;
			}
		}

		// wpdb keeps the last result set until the next query, which would
		// leave a chunk of JSON behind for the whole refresh.
		unset( $rows );
		$wpdb->flush();
	} while ( $size === $fetched );

	return $vectors;
}

/**
 * Lists the posts whose vectors can be compared with a post's vector,
 * without reading the vectors.
 *
 * @param int $post_id Post whose vector sets the provider, model and language.
 * @return int[] Post IDs, in order, including the post's own.
 */
function undergrowth_comparable_post_ids( int $post_id ): array {
	global $wpdb;

	$source = undergrowth_get_vector_space( $post_id );

	if ( null === $source ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; vectors change on every embed.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT post_id FROM %i WHERE provider = %s AND model = %s AND language = %s ORDER BY post_id',
			undergrowth_vectors_table(),
			$source['provider'],
			$source['model'],
			$source['language']
		)
	);

	return array_map( 'intval', (array) $ids );
}
