<?php
/**
 * Hubs: proposing a page that ties a dense group together.
 *
 * Where a group on the Map is large and its posts already link to each
 * other, a hub page helps readers find their way through it. Undergrowth
 * only proposes one. The author writes it. Starting a hub makes a draft
 * page holding the group's post titles as links and nothing else: no title,
 * no heading, no text. Undergrowth never publishes it.
 *
 * Group leaders change every month, so a group is known by its posts. A
 * veto is kept for the posts the group had, forever, and a group counts as
 * the same one while more than half of its posts are shared.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

// Fewest posts in a group before a hub is proposed for it.
const UNDERGROWTH_HUB_MIN_SIZE = 5;

// Smallest share of a group's posts that must have an accepted link to or
// from another post in the group.
const UNDERGROWTH_HUB_MIN_LINKED_SHARE = 0.5;

add_action( 'save_post', 'undergrowth_store_hub_links', 10, 2 );

/**
 * Returns the fewest posts a group needs before a hub is proposed for it.
 *
 * @return int Number of posts, at least UNDERGROWTH_MIN_CLUSTER_SIZE.
 */
function undergrowth_hub_min_size(): int {
	/**
	 * Filters the fewest posts a group needs before a hub is proposed for it.
	 *
	 * @param int $size Number of posts. Default 5. Never below the smallest group, 3.
	 */
	$size = apply_filters( 'undergrowth_hub_min_size', UNDERGROWTH_HUB_MIN_SIZE );

	return is_numeric( $size ) ? max( UNDERGROWTH_MIN_CLUSTER_SIZE, (int) $size ) : UNDERGROWTH_HUB_MIN_SIZE;
}

/**
 * Returns the smallest share of a group's posts that must already be linked
 * to another post in the group before a hub is proposed for it.
 *
 * @return float Share between 0 and 1.
 */
function undergrowth_hub_min_linked_share(): float {
	/**
	 * Filters the smallest share of a group's posts that must already be
	 * linked to another post in the group before a hub is proposed for it.
	 *
	 * Only links accepted through Undergrowth count, in either direction.
	 *
	 * @param float $share Share between 0 and 1. Default 0.5.
	 */
	$share = apply_filters( 'undergrowth_hub_min_linked_share', UNDERGROWTH_HUB_MIN_LINKED_SHARE );

	return is_numeric( $share ) ? max( 0.0, min( 1.0, (float) $share ) ) : UNDERGROWTH_HUB_MIN_LINKED_SHARE;
}

/**
 * Counts a group's posts that have an accepted link to or from another post
 * in the group.
 *
 * @param int[] $members Post IDs in the group.
 * @return int Number of linked posts.
 */
function undergrowth_hub_linked_members( array $members ): int {
	global $wpdb;

	$members = array_values( array_unique( array_map( 'intval', $members ) ) );

	if ( count( $members ) < 2 ) {
		return 0;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $members ), '%d' ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The plugin's own table; one placeholder for each ID, twice.
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT source_id, target_id FROM %i WHERE state = %s AND source_id IN (' . $placeholders . ') AND target_id IN (' . $placeholders . ')',
			undergrowth_links_table(),
			'accepted',
			...array_merge( $members, $members )
		),
		ARRAY_A
	);
	// phpcs:enable

	$linked = array();

	foreach ( $rows as $row ) {
		if ( (int) $row['source_id'] !== (int) $row['target_id'] ) {
			$linked[ (int) $row['source_id'] ] = true;
			$linked[ (int) $row['target_id'] ] = true;
		}
	}

	return count( $linked );
}

/**
 * Returns the vetoed groups, each as the posts it had when it was vetoed.
 *
 * @return int[][] Post IDs, lowest first, one list per veto.
 */
function undergrowth_hub_vetoes(): array {
	$vetoes = get_option( 'undergrowth_hub_vetoes', array() );

	if ( ! is_array( $vetoes ) ) {
		return array();
	}

	$sets = array();

	foreach ( $vetoes as $set ) {
		$set = is_array( $set ) ? array_values( array_filter( array_map( 'intval', $set ) ) ) : array();

		if ( array() !== $set ) {
			$sets[] = $set;
		}
	}

	return $sets;
}

/**
 * Tells whether the author vetoed a hub for this group, or for a group
 * sharing more than half of its posts.
 *
 * @param int[] $members Post IDs in the group.
 * @return bool True when it was vetoed.
 */
function undergrowth_hub_vetoed( array $members ): bool {
	foreach ( undergrowth_hub_vetoes() as $vetoed ) {
		if ( undergrowth_same_group( $members, $vetoed ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Lists the hub pages Undergrowth started, wherever the author has taken
 * them since, except the trash.
 *
 * @return array<int, int[]> The posts each hub was started for, keyed by the hub's ID.
 */
function undergrowth_hubs(): array {
	$ids = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => array( 'draft', 'pending', 'future', 'private', 'publish' ),
			'meta_key'       => 'undergrowth_hub', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only hub pages have the key.
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			// One hub at most for each group on the Map.
			'posts_per_page' => -1,
		)
	);

	$hubs = array();

	foreach ( array_map( 'intval', $ids ) as $hub_id ) {
		$hubs[ $hub_id ] = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $hub_id, 'undergrowth_hub', true ) ) ) );
	}

	return $hubs;
}

/**
 * Finds the hub started for this group, or for a group sharing more than
 * half of its posts.
 *
 * @param int[] $members Post IDs in the group.
 * @return int The hub's ID, or 0 when the group has none.
 */
function undergrowth_group_hub( array $members ): int {
	foreach ( undergrowth_hubs() as $hub_id => $started_for ) {
		if ( undergrowth_same_group( $members, $started_for ) ) {
			return $hub_id;
		}
	}

	return 0;
}

/**
 * Tells whether a hub should be proposed for a group: it is large enough,
 * enough of its posts already link to each other, it has no hub yet, and
 * the author never vetoed it.
 *
 * @param int[] $members Post IDs in the group.
 * @return bool True when the Map should propose a hub.
 */
function undergrowth_is_hub_candidate( array $members ): bool {
	$size = count( $members );

	return $size >= undergrowth_hub_min_size()
		&& undergrowth_hub_linked_members( $members ) >= $size * undergrowth_hub_min_linked_share()
		&& 0 === undergrowth_group_hub( $members )
		&& ! undergrowth_hub_vetoed( $members );
}

/**
 * Finds the group on the Map that a leader's ID labels.
 *
 * @param int $leader Leader ID.
 * @return int[] Post IDs in the group as the Map shows them, or none when the Map has no such group.
 */
function undergrowth_map_group( int $leader ): array {
	foreach ( undergrowth_cluster_map() as $cluster ) {
		if ( $cluster['leader'] === $leader ) {
			return $cluster['members'];
		}
	}

	return array();
}

/**
 * Builds the content of a hub draft: one core List block of the group's
 * post titles, each linked to its post, in Map order.
 *
 * Nothing else goes in it. Every word is a title the author wrote, read
 * raw so no other plugin's title filter adds to it. A post with no title is
 * named by its address, since Undergrowth has no words of its own to put.
 *
 * @param int[] $members Post IDs in the group.
 * @return string Block markup.
 */
function undergrowth_hub_draft_content( array $members ): string {
	$items = '';

	foreach ( $members as $post_id ) {
		$url   = (string) get_permalink( $post_id );
		$title = undergrowth_plain_line( (string) get_post_field( 'post_title', $post_id, 'raw' ) );

		$items .= sprintf(
			"<!-- wp:list-item -->\n<li><a href=\"%1\$s\">%2\$s</a></li>\n<!-- /wp:list-item -->\n\n",
			esc_url( $url ),
			esc_html( '' === $title ? $url : $title )
		);
	}

	return "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . $items . "</ul>\n<!-- /wp:list -->";
}

/**
 * Starts a hub for a group on the Map: a draft page holding the group's
 * post titles as links.
 *
 * The page has no title and no other content, and stays a draft until the
 * author publishes it. A group that already has a hub gets no second one.
 *
 * @param int $leader Leader ID labelling the group.
 * @return int|WP_Error The draft's ID, or why none was started: 'hub_gone' when the
 *                      Map has no such group or no hub is proposed for it, or core's error.
 */
function undergrowth_start_hub( int $leader ) {
	$members = undergrowth_map_group( $leader );

	if ( array() === $members || ! undergrowth_is_hub_candidate( $members ) ) {
		return new WP_Error( 'hub_gone' );
	}

	$sorted = $members;
	sort( $sorted );

	// Slashed, as wp_insert_post() expects, so a backslash in a title stays.
	return wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => '',
				'post_content' => undergrowth_hub_draft_content( $members ),
				'meta_input'   => array( 'undergrowth_hub' => $sorted ),
			)
		),
		true
	);
}

/**
 * Vetoes a hub for a group on the Map, for good.
 *
 * The group's posts are kept, so the same group is never proposed again,
 * even after its leader changes.
 *
 * @param int $leader Leader ID labelling the group.
 * @return bool True when the veto was stored, false when the Map proposes no hub for that group.
 */
function undergrowth_veto_hub( int $leader ): bool {
	$members = undergrowth_map_group( $leader );

	if ( array() === $members || ! undergrowth_is_hub_candidate( $members ) ) {
		return false;
	}

	sort( $members );

	$vetoes   = undergrowth_hub_vetoes();
	$vetoes[] = $members;

	return update_option( 'undergrowth_hub_vetoes', $vetoes, false );
}

/**
 * Records which posts a hub links to, each time it is saved, so a stage can
 * count the hub without reading it.
 *
 * Only pages Undergrowth started as hubs are read, and every link in them
 * counts, whether Undergrowth put it there or the author did.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post saved.
 */
function undergrowth_store_hub_links( $post_id, $post ): void {
	if ( ! $post instanceof WP_Post || undergrowth_ignores_post( $post ) || ! metadata_exists( 'post', (int) $post_id, 'undergrowth_hub' ) ) {
		return;
	}

	delete_post_meta( (int) $post_id, 'undergrowth_hub_link' );

	foreach ( array_unique( undergrowth_linked_post_ids( $post ) ) as $linked ) {
		add_post_meta( (int) $post_id, 'undergrowth_hub_link', (int) $linked );
	}
}

/**
 * Tells whether a published, public hub links to a post.
 *
 * @param int $post_id Post ID.
 * @return bool True when a hub readers can see links to it.
 */
function undergrowth_has_hub_link( int $post_id ): bool {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One row is enough; the stage cycle asks once per post.
	$found = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT 1 FROM %i m INNER JOIN %i p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_status = %s AND p.post_password = %s LIMIT 1',
			$wpdb->postmeta,
			$wpdb->posts,
			'undergrowth_hub_link',
			(string) $post_id,
			'publish',
			''
		)
	);

	return null !== $found;
}
