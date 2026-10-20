<?php
/**
 * Map: grouping posts into clusters from their stored vectors.
 *
 * Clustering is arithmetic on the vectors already stored, with no model call.
 * It runs once a month, before the stage cycle, so every stage is measured
 * against a complete set of clusters.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_MIN_CLUSTER_SIMILARITY = 0.5;
const UNDERGROWTH_MIN_CLUSTER_SIZE       = 3;
const UNDERGROWTH_CLUSTER_BATCH          = 50;
const UNDERGROWTH_MAX_CLUSTERS           = 100;

add_action( 'undergrowth_cluster_cycle', 'undergrowth_run_cluster_cycle' );
add_action( 'undergrowth_post_left_index', 'undergrowth_forget_cluster' );
add_action( 'undergrowth_stage_changed', 'undergrowth_forget_composted_cluster' );
add_filter( 'is_protected_meta', 'undergrowth_protect_cluster_meta', 10, 2 );

/**
 * Returns the lowest similarity at which a post joins a cluster.
 *
 * @return float Cosine similarity between -1 and 1.
 */
function undergrowth_min_cluster_similarity(): float {
	/**
	 * Filters the lowest similarity at which a post joins a cluster.
	 *
	 * Different embedding models spread their scores differently, so a site
	 * using its own provider may need to tune this.
	 *
	 * @param float $similarity Cosine similarity. Default 0.5.
	 */
	$similarity = apply_filters( 'undergrowth_min_cluster_similarity', UNDERGROWTH_MIN_CLUSTER_SIMILARITY );

	return is_numeric( $similarity ) ? max( -1.0, min( 1.0, (float) $similarity ) ) : UNDERGROWTH_MIN_CLUSTER_SIMILARITY;
}

/**
 * Returns the leader of a post's cluster.
 *
 * The leader's ID labels the cluster until the next pass, even after the
 * leader itself leaves it, for example by being composted. The other posts
 * stay together under that label.
 *
 * @param int $post_id Post ID.
 * @return int The leader's post ID, the post's own when it leads, or 0 when it was not clustered.
 */
function undergrowth_cluster_leader( int $post_id ): int {
	return (int) get_post_meta( $post_id, 'undergrowth_cluster', true );
}

/**
 * Lists the posts in a post's cluster.
 *
 * Fewer than UNDERGROWTH_MIN_CLUSTER_SIZE posts are not a cluster, matching
 * the stage rules' "cluster of 3+ posts".
 *
 * @param int $post_id Post ID.
 * @return int[] Post IDs in the cluster, lowest first and including the post,
 *               or an empty array when the post is not in a cluster.
 */
function undergrowth_cluster_members( int $post_id ): array {
	global $wpdb;

	$leader = undergrowth_cluster_leader( $post_id );

	if ( 0 === $leader ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- WP_Query would load every member post just to count them.
	$members = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s ORDER BY post_id ASC',
			$wpdb->postmeta,
			'undergrowth_cluster',
			(string) $leader
		)
	);

	return count( $members ) >= UNDERGROWTH_MIN_CLUSTER_SIZE ? array_map( 'intval', $members ) : array();
}

/**
 * Clusters one batch of stored vectors.
 *
 * Posts are visited in ID order. Each joins the cluster whose leader is most
 * similar to it, the older leader on a tie, or leads a new cluster when no
 * leader reaches the minimum similarity. A leader stands for its cluster by
 * its own vector rather than an average, so the same vectors always give
 * the same clusters. Only vectors from one provider, model and language are
 * compared, so each combination is clustered on its own.
 *
 * Each combination has at most UNDERGROWTH_MAX_CLUSTERS leaders. Once that
 * many exist, a post that matches none of them stays out of every cluster
 * until the next pass, so no batch compares more than its size times the
 * cap. Each batch that fills up schedules the next; the last starts the
 * stage cycle.
 *
 * @param int|string $after_id Cluster posts with a higher ID than this. Cron
 *                            passes an empty string when an event has no arguments.
 */
function undergrowth_run_cluster_cycle( $after_id = 0 ): void {
	global $wpdb;

	$after_id = (int) $after_id;

	if ( 0 === $after_id ) {
		delete_post_meta_by_key( 'undergrowth_cluster' );
		update_option( 'undergrowth_cluster_leaders', array(), false );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; vectors change on every embed.
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT post_id, provider, model, language, embedding FROM %i WHERE post_id > %d ORDER BY post_id ASC LIMIT %d',
			undergrowth_vectors_table(),
			$after_id,
			UNDERGROWTH_CLUSTER_BATCH
		),
		ARRAY_A
	);

	// wpdb keeps the last result set until the next query, which would leave
	// a batch of JSON behind while the batch is compared.
	$wpdb->flush();

	$fetched = count( $rows );
	$leaders = (array) get_option( 'undergrowth_cluster_leaders', array() );
	$pools   = array();
	$minimum = undergrowth_min_cluster_similarity();
	$last_id = $after_id;

	while ( array() !== $rows ) {
		$row     = array_shift( $rows );
		$last_id = (int) $row['post_id'];
		$vector  = undergrowth_unit_vector( undergrowth_sanitize_vector( json_decode( (string) $row['embedding'], true ) ) );

		if ( '' === $vector || ! undergrowth_can_cluster( $last_id ) ) {
			continue;
		}

		$group = $row['provider'] . "\n" . $row['model'] . "\n" . $row['language'];

		if ( ! isset( $pools[ $group ] ) ) {
			$leaders[ $group ] = $leaders[ $group ] ?? array();
			$pools[ $group ]   = undergrowth_leader_vectors( $leaders[ $group ], $row );
		}

		$leader = undergrowth_nearest_leader( $vector, $pools[ $group ], $minimum );

		if ( 0 === $leader && count( $leaders[ $group ] ) < UNDERGROWTH_MAX_CLUSTERS ) {
			$leader                      = $last_id;
			$leaders[ $group ][]         = $last_id;
			$pools[ $group ][ $last_id ] = $vector;
		}

		if ( 0 !== $leader ) {
			update_post_meta( $last_id, 'undergrowth_cluster', $leader );
		}
	}

	if ( UNDERGROWTH_CLUSTER_BATCH === $fetched ) {
		update_option( 'undergrowth_cluster_leaders', $leaders, false );
		wp_schedule_single_event( time(), 'undergrowth_cluster_cycle', array( $last_id ) );
		return;
	}

	delete_option( 'undergrowth_cluster_leaders' );
	wp_schedule_single_event( time(), 'undergrowth_stage_cycle', array( 0 ) );
}

/**
 * Tells whether a post belongs in the clusters of the active garden.
 *
 * Composted posts keep their vectors but have left the garden.
 *
 * @param int $post_id Post ID.
 * @return bool True for an indexed post that is not composting.
 */
function undergrowth_can_cluster( int $post_id ): bool {
	$post = get_post( $post_id );

	return $post instanceof WP_Post && undergrowth_is_indexable( $post ) && 'composting' !== undergrowth_get_stage( $post_id );
}

/**
 * Scales a vector to unit length and packs it.
 *
 * Leaders are compared with every post in a batch, so their lengths are
 * divided out once and each comparison is a single dot product.
 *
 * @param float[] $vector The vector.
 * @return string Packed unit vector, or an empty string when the vector has no magnitude.
 */
function undergrowth_unit_vector( array $vector ): string {
	$norm = 0.0;

	foreach ( $vector as $value ) {
		$norm += $value * $value;
	}

	if ( 0.0 === $norm ) {
		return '';
	}

	$norm = sqrt( $norm );

	foreach ( $vector as $i => $value ) {
		$vector[ $i ] = $value / $norm;
	}

	return undergrowth_pack_vector( $vector );
}

/**
 * Loads the vectors of a combination's leaders, a chunk at a time.
 *
 * A leader that left the index, or was embedded again with another model or
 * language, since it was chosen is left out.
 *
 * @param int[]                $leader_ids Leader post IDs, oldest first.
 * @param array<string, mixed> $identity   Row giving the provider, model and language.
 * @return array<int, string> Packed unit vectors keyed by leader ID, oldest first.
 */
function undergrowth_leader_vectors( array $leader_ids, array $identity ): array {
	global $wpdb;

	$vectors = array();

	foreach ( array_chunk( $leader_ids, UNDERGROWTH_VECTOR_CHUNK ) as $chunk ) {
		$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The placeholders are built above, one per leader.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; vectors change on every embed.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, embedding FROM %i WHERE provider = %s AND model = %s AND language = %s AND post_id IN ({$placeholders}) ORDER BY post_id ASC",
				array_merge( array( undergrowth_vectors_table(), $identity['provider'], $identity['model'], $identity['language'] ), $chunk )
			),
			ARRAY_A
		);
		// phpcs:enable

		$wpdb->flush();

		foreach ( $rows as $row ) {
			$vector = undergrowth_unit_vector( undergrowth_sanitize_vector( json_decode( (string) $row['embedding'], true ) ) );

			if ( '' !== $vector ) {
				$vectors[ (int) $row['post_id'] ] = $vector;
			}
		}

		unset( $rows );
	}

	return $vectors;
}

/**
 * Finds the leader most similar to a vector.
 *
 * @param string             $vector  Packed unit vector.
 * @param array<int, string> $leaders Packed unit vectors keyed by leader ID, oldest first.
 * @param float              $minimum Lowest similarity that counts.
 * @return int The leader's post ID, or 0 when none reaches the minimum.
 */
function undergrowth_nearest_leader( string $vector, array $leaders, float $minimum ): int {
	$values  = undergrowth_unpack_vector( $vector );
	$nearest = 0;
	$best    = $minimum;

	foreach ( $leaders as $leader_id => $packed ) {
		// Vectors of another length, from a change of model settings, cannot be compared.
		if ( strlen( $packed ) !== strlen( $vector ) ) {
			continue;
		}

		$dot = 0.0;
		$i   = 0;

		foreach ( undergrowth_unpack_vector( $packed ) as $value ) {
			$dot += $value * $values[ $i++ ];
		}

		// A later leader has to be strictly closer, so a tie stays with the
		// older one. The first match may sit exactly on the minimum.
		if ( $dot > $best || ( 0 === $nearest && $dot >= $best ) ) {
			$nearest = $leader_id;
			$best    = $dot;
		}
	}

	return $nearest;
}

/**
 * Removes a post from its cluster when it leaves the index.
 *
 * @param int $post_id Post ID.
 */
function undergrowth_forget_cluster( int $post_id ): void {
	delete_post_meta( $post_id, 'undergrowth_cluster' );
}

/**
 * Removes a post from its cluster when the author composts it.
 *
 * A composted post leaves the active garden at once, so it does not wait
 * for the next pass to leave its cluster either.
 *
 * @param int $post_id Post whose stage changed.
 */
function undergrowth_forget_composted_cluster( int $post_id ): void {
	if ( 'composting' === undergrowth_get_stage( $post_id ) ) {
		undergrowth_forget_cluster( $post_id );
	}
}

/**
 * Keeps the cluster key out of the Custom Fields box, where it could be
 * edited by hand.
 *
 * @param bool   $is_protected Whether the key is protected.
 * @param string $meta_key     Meta key.
 * @return bool True for the cluster key, otherwise unchanged.
 */
function undergrowth_protect_cluster_meta( $is_protected, $meta_key ) {
	return 'undergrowth_cluster' === $meta_key ? true : $is_protected;
}
