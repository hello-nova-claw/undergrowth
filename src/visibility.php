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

add_action( 'clean_post_cache', 'undergrowth_forget_garden_row' );
add_action( 'switch_blog', 'undergrowth_forget_garden_rows' );

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
	// get_post() would fall back to the global post for a missing ID.
	if ( ! $post ) {
		return false;
	}

	$post = get_post( $post );

	// A type can be queryable without being public, which readers are not
	// meant to browse, so both are checked.
	return $post instanceof WP_Post
		&& undergrowth_is_indexable( $post )
		&& is_post_publicly_viewable( $post )
		&& ( get_post_type_object( $post->post_type )->public ?? false )
		&& 'composting' !== undergrowth_get_stage( $post->ID );
}

/**
 * Keeps the posts readers may see from a list, in the list's order.
 *
 * Posts and their meta are loaded in two queries for the whole list, so a
 * block can check many posts without a query for each. A caller that only
 * counts or sorts the list can leave the posts unloaded: then only the
 * columns the rule reads are fetched, and nothing but the meta is kept, so
 * a long archive's text never fills the request's memory.
 *
 * @param int[] $post_ids   Post IDs.
 * @param bool  $load_posts Whether to load the posts, for a caller about to show them. Default true.
 * @return int[] The visible IDs, each once, in their first order.
 */
function undergrowth_filter_garden_visible( array $post_ids, bool $load_posts = true ): array {
	$post_ids = array_map( 'intval', $post_ids );
	$post_ids = array_values(
		array_unique(
			array_filter(
				$post_ids,
				static function ( int $id ): bool {
					return $id > 0;
				}
			)
		)
	);

	if ( array() === $post_ids ) {
		return array();
	}

	if ( ! $load_posts ) {
		return undergrowth_filter_garden_visible_unloaded( $post_ids );
	}

	_prime_post_caches( $post_ids, false, true );

	// An ID priming did not find has no post, and asking for it again would
	// cost a query each.
	$found = array_filter(
		$post_ids,
		static function ( int $id ): bool {
			return false !== wp_cache_get( $id, 'posts' );
		}
	);

	return array_values( array_filter( $found, 'undergrowth_is_garden_visible' ) );
}

/**
 * Keeps the posts readers may see from a list without loading the posts.
 *
 * Posts already loaded are checked as they are. The rest are read, past
 * the post cache, with only the columns undergrowth_is_garden_visible()
 * needs, and their meta is primed so the stage costs no query each. The
 * columns are kept for the rest of the request, since a garden page asks
 * for the same list once for each block on it.
 *
 * @param int[] $post_ids Positive post IDs, each once.
 * @return int[] The visible IDs, in their first order.
 */
function undergrowth_filter_garden_visible_unloaded( array $post_ids ): array {
	global $wpdb, $undergrowth_garden_rows;

	$undergrowth_garden_rows = (array) $undergrowth_garden_rows;
	$posts                   = array();
	$missing                 = array();

	foreach ( $post_ids as $id ) {
		$cached = wp_cache_get( $id, 'posts' );

		if ( false !== $cached ) {
			$posts[ $id ] = $cached;
		} elseif ( isset( $undergrowth_garden_rows[ $id ] ) ) {
			$posts[ $id ] = $undergrowth_garden_rows[ $id ];
		} else {
			$missing[] = $id;
		}
	}

	if ( array() !== $missing ) {
		$placeholders = implode( ', ', array_fill( 0, count( $missing ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Caching every post checked is what this avoids; there is one placeholder for each ID.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_status, post_password, post_type, post_parent FROM %i WHERE ID IN (' . $placeholders . ')', $wpdb->posts, ...$missing ) );
		$wpdb->flush();

		foreach ( $rows as $row ) {
			// Marked raw, as the cache's own are, or get_post() would load
			// the whole post to sanitize it.
			$row->filter                               = 'raw';
			$posts[ (int) $row->ID ]                   = $row;
			$undergrowth_garden_rows[ (int) $row->ID ] = $row;
		}
	}

	update_meta_cache( 'post', array_keys( $posts ) );

	$visible = array();

	foreach ( $post_ids as $id ) {
		if ( isset( $posts[ $id ] ) && undergrowth_is_garden_visible( new WP_Post( $posts[ $id ] ) ) ) {
			$visible[] = $id;
		}
	}

	return $visible;
}

/**
 * Drops a post's kept columns once its cache is cleaned.
 *
 * @param int $post_id Post ID.
 */
function undergrowth_forget_garden_row( $post_id ): void {
	global $undergrowth_garden_rows;

	unset( $undergrowth_garden_rows[ (int) $post_id ] );
}

/**
 * Drops every kept column when another site's posts are read.
 */
function undergrowth_forget_garden_rows(): void {
	global $undergrowth_garden_rows;

	$undergrowth_garden_rows = array();
}
