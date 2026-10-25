<?php
/**
 * The one rule for what readers may see of the garden.
 *
 * Every block that names posts to readers asks here first, so the stage
 * badge, "More like this", "Linked from" and the garden index can never
 * disagree about which posts are shown.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether readers may see a post on a garden surface.
 *
 * The post must be published, public, free of a password, in an indexed
 * post type and not composted. Public means anyone can view it, whoever is
 * logged in: these surfaces are for readers, and a page cache would show
 * one viewer's list to the next. Composting is the author's private choice
 * to rest a post, so naming it to readers would publish that choice.
 *
 * @param WP_Post|int|null $post Post or post ID.
 * @return bool True when readers may see the post.
 */
function undergrowth_is_garden_visible( $post ): bool {
	$post = get_post( $post );

	return $post instanceof WP_Post
		&& undergrowth_is_indexable( $post )
		&& is_post_publicly_viewable( $post )
		&& 'composting' !== undergrowth_get_stage( $post->ID );
}

/**
 * Keeps the posts readers may see from a list, in the list's order.
 *
 * Posts and their meta are loaded in two queries for the whole list, so a
 * block can check many posts without a query for each.
 *
 * @param int[] $post_ids Post IDs.
 * @return int[] The visible IDs, each once, in their first order.
 */
function undergrowth_filter_garden_visible( array $post_ids ): array {
	$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );

	if ( array() === $post_ids ) {
		return array();
	}

	_prime_post_caches( $post_ids, false, true );

	return array_values( array_filter( $post_ids, 'undergrowth_is_garden_visible' ) );
}
