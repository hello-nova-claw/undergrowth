<?php
/**
 * Orphans: posts that no accepted link points to.
 *
 * A reader following links, and a search engine, reaches a post through the
 * posts that link to it. Undergrowth only knows the links it suggested and
 * the author accepted, so those are the links counted here.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

// How many suggestions to link in are shown beside each orphan. The rest
// are on the Suggestions tab, narrowed to the post.
const UNDERGROWTH_ORPHAN_SUGGESTIONS = 3;

/**
 * Builds the SQL that picks orphans: indexed posts in the garden that no
 * accepted link from a published post points to.
 *
 * A post is indexed once it has a vector. A composting post rests outside
 * the garden, so it is never an orphan. A link accepted in a post that has
 * since left the index no longer reaches readers, so it is not counted.
 *
 * @param string $select Columns to select: 'p.ID' or 'COUNT(*)'.
 * @return array{0: string, 1: array<int, string>}|null The query without its order or limit, and its values in order, or null when no post type is indexed or the select list is another.
 */
function undergrowth_orphans_sql( string $select ): ?array {
	global $wpdb;

	if ( ! in_array( $select, array( 'p.ID', 'COUNT(*)' ), true ) ) {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Only p.ID or COUNT(*) can be selected.', 'undergrowth' ), '0.1.0' );
		return null;
	}

	$post   = undergrowth_indexable_sql( 'p.' );
	$source = undergrowth_indexable_sql( 's.' );

	if ( null === $post || null === $source ) {
		return null;
	}

	return array(
		// The select list is one of the two above, and the conditions are
		// built with placeholders, whose values follow in order.
		"SELECT {$select} FROM %i p"
		. ' INNER JOIN %i v ON v.post_id = p.ID'
		. " WHERE {$post[0]}"
		. ' AND NOT EXISTS ( SELECT 1 FROM %i m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s )'
		. " AND NOT EXISTS ( SELECT 1 FROM %i l INNER JOIN %i s ON s.ID = l.source_id WHERE l.target_id = p.ID AND l.state = %s AND {$source[0]} )",
		array_merge(
			array( $wpdb->posts, undergrowth_vectors_table() ),
			$post[1],
			array( $wpdb->postmeta, 'undergrowth_stage', 'composting', undergrowth_links_table(), $wpdb->posts, 'accepted' ),
			$source[1]
		),
	);
}

/**
 * Lists a page of orphans, newest first.
 *
 * @param int $limit  Most posts to list.
 * @param int $offset Posts to skip.
 * @return int[] Post IDs.
 */
function undergrowth_orphan_post_ids( int $limit, int $offset = 0 ): array {
	global $wpdb;

	$sql = undergrowth_orphans_sql( 'p.ID' );

	if ( null === $sql || $limit < 1 ) {
		return array();
	}

	$query  = $sql[0] . ' ORDER BY p.post_date DESC, p.ID DESC LIMIT %d OFFSET %d';
	$values = array_merge( $sql[1], array( $limit, max( 0, $offset ) ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Built with placeholders in undergrowth_orphans_sql(); links change on every accept.
	$ids = $wpdb->get_col( $wpdb->prepare( $query, $values ) );

	return array_map( 'intval', (array) $ids );
}

/**
 * Counts the orphans.
 *
 * @return int
 */
function undergrowth_count_orphans(): int {
	global $wpdb;

	$sql = undergrowth_orphans_sql( 'COUNT(*)' );

	if ( null === $sql ) {
		return 0;
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Built with placeholders in undergrowth_orphans_sql(); links change on every accept.
	return (int) $wpdb->get_var( $wpdb->prepare( $sql[0], $sql[1] ) );
}

/**
 * Lists the suggestions that would link to a post, best first, that the
 * current user can act on, as the Suggestions tab lists them.
 *
 * A suggestion in a post the user cannot edit, or in a composting post, is
 * left out.
 *
 * @param int $post_id Post the suggestions would link to.
 * @return array<int, array{source_id: int, target_id: int, sentence: string, anchor: string, offset: int, kind: string}> Suggestions, best first.
 */
function undergrowth_suggestions_to( int $post_id ): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every refresh.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT source_id, target_id, sentence, anchor, anchor_offset, kind FROM %i WHERE target_id = %d AND state = %s ORDER BY score DESC, source_id ASC',
			undergrowth_links_table(),
			$post_id,
			'proposed'
		),
		ARRAY_A
	);

	$rows = (array) $rows;

	_prime_post_caches( array_map( 'intval', array_column( $rows, 'source_id' ) ), false, true );

	$suggestions = array();

	foreach ( $rows as $row ) {
		$source_id = (int) $row['source_id'];

		if ( ! current_user_can( 'edit_post', $source_id ) || 'composting' === undergrowth_get_stage( $source_id ) ) {
			continue;
		}

		$suggestions[] = array(
			'source_id' => $source_id,
			'target_id' => (int) $row['target_id'],
			'sentence'  => (string) $row['sentence'],
			'anchor'    => (string) $row['anchor'],
			'offset'    => (int) $row['anchor_offset'],
			'kind'      => (string) $row['kind'],
		);
	}

	return $suggestions;
}
