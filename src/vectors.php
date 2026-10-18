<?php
/**
 * Reading and writing rows in the vectors table.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

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
 * }
 * @return bool True when the row was written.
 *
 * @phpstan-param array{provider: string, model: string, language: string, content_hash: string} $meta
 */
function undergrowth_save_vector( int $post_id, array $vector, array $meta ): bool {
	global $wpdb;

	if ( array() === $vector || '' === $meta['provider'] || '' === $meta['model'] || '' === $meta['language'] ) {
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
			'dimensions'   => count( $vector ),
			'embedding'    => wp_json_encode( $vector ),
			'content_hash' => $meta['content_hash'],
			'embedded_at'  => current_time( 'mysql', true ),
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
 * Removes the stored vector for a post, if it has one.
 *
 * @param int $post_id Post ID.
 */
function undergrowth_delete_vector( int $post_id ): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
	$wpdb->delete( undergrowth_vectors_table(), array( 'post_id' => $post_id ), array( '%d' ) );
}
