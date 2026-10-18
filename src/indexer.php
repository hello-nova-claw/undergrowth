<?php
/**
 * Keeps the vectors table in step with published posts.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_after_insert_post', 'undergrowth_queue_embed', 10, 2 );
add_action( 'undergrowth_embed_post', 'undergrowth_embed_post' );
add_action( 'deleted_post', 'undergrowth_delete_vector' );

/**
 * Lists the post types whose published posts are indexed.
 *
 * @return string[] Post type names.
 */
function undergrowth_indexed_post_types(): array {
	/**
	 * Filters which post types are indexed.
	 *
	 * @param string[] $post_types Post type names. Default only 'post'.
	 */
	$post_types = apply_filters( 'undergrowth_post_types', array( 'post' ) );

	return is_array( $post_types ) ? array_values( array_filter( $post_types, 'is_string' ) ) : array( 'post' );
}

/**
 * Decides whether a post belongs in the index.
 *
 * Password-protected posts are left out because their body is not public,
 * so it must neither reach a provider nor shape links readers can see.
 *
 * @param WP_Post $post Post to check.
 * @return bool True when the post should have a vector.
 */
function undergrowth_is_indexable( WP_Post $post ): bool {
	return 'publish' === $post->post_status
		&& '' === $post->post_password
		&& in_array( $post->post_type, undergrowth_indexed_post_types(), true );
}

/**
 * Schedules an embed when a post is saved, or removes its vector when it
 * leaves the index.
 *
 * The embed runs from cron because the provider may be slow or remote, and
 * the editor should not wait for it.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post that was saved.
 */
function undergrowth_queue_embed( int $post_id, WP_Post $post ): void {
	if ( ! undergrowth_is_indexable( $post ) ) {
		wp_clear_scheduled_hook( 'undergrowth_embed_post', array( $post_id ) );
		undergrowth_delete_vector( $post_id );
		return;
	}

	if ( false === wp_next_scheduled( 'undergrowth_embed_post', array( $post_id ) ) ) {
		wp_schedule_single_event( time(), 'undergrowth_embed_post', array( $post_id ) );
	}
}

/**
 * Embeds a post and stores its vector, unless nothing has changed.
 *
 * @param int $post_id Post ID.
 */
function undergrowth_embed_post( int $post_id ): void {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return;
	}

	if ( ! undergrowth_is_indexable( $post ) ) {
		undergrowth_delete_vector( $post_id );
		return;
	}

	$identity = undergrowth_embed_identity();

	if ( '' === $identity['provider'] || '' === $identity['model'] ) {
		return;
	}

	$text     = undergrowth_post_text( $post );
	$language = undergrowth_post_language( $post );
	$hash     = hash( 'sha256', $text );
	$existing = undergrowth_get_vector( $post_id );

	if (
		null !== $existing
		&& $existing['content_hash'] === $hash
		&& $existing['provider'] === $identity['provider']
		&& $existing['model'] === $identity['model']
		&& $existing['language'] === $language
	) {
		return;
	}

	$vector = undergrowth_embed( $text );

	if ( array() === $vector ) {
		return;
	}

	undergrowth_save_vector(
		$post_id,
		$vector,
		array(
			'provider'     => $identity['provider'],
			'model'        => $identity['model'],
			'language'     => $language,
			'content_hash' => $hash,
		)
	);
}

/**
 * Builds the text that represents a post: its title, then its body as plain text.
 *
 * @param WP_Post $post Post to read.
 * @return string Title and body, separated by a blank line.
 */
function undergrowth_post_text( WP_Post $post ): string {
	$body = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
	$body = trim( (string) preg_replace( '/\s+/u', ' ', $body ) );

	return trim( $post->post_title . "\n\n" . $body );
}

/**
 * Returns the language of a post, as a locale.
 *
 * @param WP_Post $post Post to check.
 * @return string Locale such as en_US.
 */
function undergrowth_post_language( WP_Post $post ): string {
	/**
	 * Filters the language stored with a post's vector.
	 *
	 * Multilingual plugins can return the post's own language. Vectors in
	 * different languages are never compared.
	 *
	 * @param string  $locale Site locale.
	 * @param WP_Post $post   Post being embedded.
	 */
	$locale = apply_filters( 'undergrowth_post_language', get_locale(), $post );

	return is_string( $locale ) && '' !== $locale ? sanitize_text_field( $locale ) : get_locale();
}
