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

// A member this close to its cluster's centre counts as a post there. This
// fixed number applies until the site's own clusters are measured; see
// src/thresholds.php.
const UNDERGROWTH_GAP_SIMILARITY = 0.8;

add_action( 'undergrowth_cluster_cycle', 'undergrowth_run_cluster_cycle', 10, 2 );
add_action( 'undergrowth_post_left_index', 'undergrowth_forget_cluster' );
add_action( 'undergrowth_stage_changed', 'undergrowth_forget_composted_cluster' );

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
	 * @param float $similarity Cosine similarity. Default 0.5, or the site's
	 *                          90th percentile of pairwise scores once it is
	 *                          measured. See undergrowth_derived_threshold().
	 */
	$default    = undergrowth_derived_threshold( 'cluster' ) ?? UNDERGROWTH_MIN_CLUSTER_SIMILARITY;
	$similarity = apply_filters( 'undergrowth_min_cluster_similarity', $default );

	return is_numeric( $similarity ) ? max( -1.0, min( 1.0, (float) $similarity ) ) : $default;
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
	$leader = undergrowth_cluster_leader( $post_id );

	if ( 0 === $leader ) {
		return array();
	}

	$members = undergrowth_cluster_posts( $leader );

	return count( $members ) >= UNDERGROWTH_MIN_CLUSTER_SIZE ? $members : array();
}

/**
 * Returns the similarity a member needs to its cluster's centre for the
 * centre to count as written about.
 *
 * @return float Cosine similarity between -1 and 1.
 */
function undergrowth_gap_similarity(): float {
	/**
	 * Filters how close a post must be to its cluster's centre for the
	 * cluster to have no gap.
	 *
	 * @param float $similarity Cosine similarity. Default 0.8, or, once the
	 *                          site's clusters are measured, the score below
	 *                          which a cluster stands out from the rest. See
	 *                          undergrowth_outlier_floor().
	 */
	$default    = undergrowth_derived_threshold( 'gap' ) ?? UNDERGROWTH_GAP_SIMILARITY;
	$similarity = apply_filters( 'undergrowth_gap_similarity', $default );

	return is_numeric( $similarity ) ? max( -1.0, min( 1.0, (float) $similarity ) ) : $default;
}

/**
 * Lists the leaders of the clusters made by the last pass.
 *
 * @param int $limit Most leaders to return.
 * @return int[] Leader IDs, lowest first.
 */
function undergrowth_cluster_leaders( int $limit = UNDERGROWTH_MAX_CLUSTERS ): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One grouped query instead of loading every clustered post.
	$leaders = (array) $wpdb->get_col(
		$wpdb->prepare(
			'SELECT meta_value FROM %i WHERE meta_key = %s GROUP BY meta_value HAVING COUNT(*) >= %d',
			$wpdb->postmeta,
			'undergrowth_cluster',
			UNDERGROWTH_MIN_CLUSTER_SIZE
		)
	);

	// Grouped rows come back in no defined order, so the leaders are sorted
	// by number before the cap, and every run reads the same clusters.
	$leaders = array_map( 'intval', $leaders );
	sort( $leaders );

	return array_slice( $leaders, 0, $limit );
}

/**
 * Lists every post labelled with a cluster's leader.
 *
 * @param int $leader Leader ID.
 * @return int[] Post IDs, lowest first.
 */
function undergrowth_cluster_posts( int $leader ): array {
	global $wpdb;

	return array_map(
		'intval',
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Only the IDs are needed.
		$wpdb->get_col(
			$wpdb->prepare(
				'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s ORDER BY post_id ASC',
				$wpdb->postmeta,
				'undergrowth_cluster',
				(string) $leader
			)
		)
	);
}

/**
 * Tells whether the last pass made at least one cluster.
 *
 * @return bool True when there is a cluster to show.
 */
function undergrowth_has_clusters(): bool {
	return array() !== undergrowth_cluster_leaders( 1 );
}

/**
 * Lists every cluster with its posts, for the author to see.
 *
 * Posts come nearest the centre first, as measured for gaps, and any that
 * could not be measured after them, in ID order. Measuring is capped at
 * UNDERGROWTH_CLUSTER_BATCH posts, as for gaps, so a very large cluster lists
 * the rest after the measured ones. Composting and unpublished posts are
 * left out, and never counted towards the centre, even if their cluster key
 * is still there. The Map names each cluster after its first post; see
 * src/map.php.
 *
 * @return array<int, array{leader: int, members: int[], gap: bool}> Clusters with posts to show, lowest leader first.
 */
function undergrowth_cluster_map(): array {
	$threshold = undergrowth_gap_similarity();
	$map       = array();

	foreach ( undergrowth_cluster_leaders() as $leader ) {
		$shown    = undergrowth_clusterable_posts( undergrowth_cluster_posts( $leader ) );
		$measured = undergrowth_measure_cluster( undergrowth_centre_members( $leader ) );
		$shown    = null === $measured ? $shown : array_merge( $measured['members'], array_diff( $shown, $measured['members'] ) );

		if ( array() === $shown ) {
			continue;
		}

		$map[] = array(
			'leader'  => $leader,
			'members' => $shown,
			'gap'     => null !== $measured && $measured['centre'] < $threshold,
		);
	}

	return $map;
}

/**
 * Finds clusters whose centre has no post: a gap found in the author's own
 * writing, with no model call.
 *
 * A cluster's centre is the mean of its members' unit vectors. When no
 * member reaches undergrowth_gap_similarity() to it, the posts circle
 * something none of them is about. Only posts still in the garden are
 * measured, so a composted post whose key was left behind fills no gap.
 *
 * @return array<int, array{leader: int, members: int[], cluster: int[], centre: float}> One entry per gap,
 *         by leader ID: the members measured, nearest the centre first; every member of the cluster,
 *         lowest first; and the nearest one's similarity to the centre.
 */
function undergrowth_cluster_gaps(): array {
	$threshold = undergrowth_gap_similarity();
	$gaps      = array();

	foreach ( undergrowth_cluster_leaders() as $leader ) {
		$members = undergrowth_cluster_posts( $leader );
		$gap     = undergrowth_cluster_gap( $leader, undergrowth_centre_members( $leader ), $threshold );

		if ( null !== $gap ) {
			$gaps[] = $gap + array( 'cluster' => $members );
		}
	}

	usort(
		$gaps,
		static function ( $a, $b ) {
			return $a['leader'] <=> $b['leader'];
		}
	);

	return $gaps;
}

/**
 * Lists the members a cluster's centre is measured on.
 *
 * Composting posts have left the garden, and a very large cluster is
 * measured on its first batch of members, so measuring stays cheap. The
 * map, the gaps and the measured gap threshold all use this one selection.
 *
 * @param int $leader Leader ID labelling the cluster.
 * @return int[] Member post IDs, lowest first.
 */
function undergrowth_centre_members( int $leader ): array {
	return array_slice( undergrowth_clusterable_posts( undergrowth_cluster_posts( $leader ) ), 0, UNDERGROWTH_CLUSTER_BATCH );
}

/**
 * Measures how close one cluster's members come to its centre, and returns
 * it as a gap when none comes close enough.
 *
 * @param int   $leader    Leader ID labelling the cluster.
 * @param int[] $members   Member post IDs, lowest first.
 * @param float $threshold Similarity at which the centre counts as written about.
 * @return array{leader: int, members: int[], centre: float}|null The gap, or null when there is none.
 */
function undergrowth_cluster_gap( int $leader, array $members, float $threshold ): ?array {
	$measured = undergrowth_measure_cluster( $members );

	if ( null === $measured || $measured['centre'] >= $threshold ) {
		return null;
	}

	return array( 'leader' => $leader ) + $measured;
}

/**
 * Measures how close one cluster's members come to its centre.
 *
 * Vectors are only ever compared within one provider, model, language and
 * length. A member embedded again with other settings keeps its cluster
 * until the next pass, so the largest such group is the one measured; on a
 * tie, the group of the lowest member ID.
 *
 * @param int[] $members Member post IDs, lowest first.
 * @return array{members: int[], centre: float}|null The members measured, nearest the centre first,
 *         and the nearest one's similarity to it; or null when too few can be measured.
 */
function undergrowth_measure_cluster( array $members ): ?array {
	global $wpdb;

	$groups = array();
	$rows   = array();

	if ( array() !== $members ) {
		$placeholders = implode( ', ', array_fill( 0, count( $members ), '%d' ) );

		// One query for the members, however many there are.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The plugin's own table; there is one placeholder for each ID.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, provider, model, language, embedding FROM %i WHERE post_id IN (' . $placeholders . ')', undergrowth_vectors_table(), ...array_map( 'intval', $members ) ), ARRAY_A ) as $row ) {
			$rows[ (int) $row['post_id'] ] = $row;
		}

		$wpdb->flush();
	}

	foreach ( $members as $post_id ) {
		$row    = $rows[ $post_id ] ?? null;
		$packed = null === $row ? '' : undergrowth_unit_vector( undergrowth_decode_embedding( (string) $row['embedding'] ) );

		unset( $rows[ $post_id ] );

		if ( '' === $packed ) {
			continue;
		}

		$space                        = undergrowth_vector_space( $row ) . "\n" . strlen( $packed );
		$groups[ $space ][ $post_id ] = undergrowth_unpack_vector( $packed );
	}

	$vectors = array();

	foreach ( $groups as $group ) {
		if ( count( $group ) > count( $vectors ) ) {
			$vectors = $group;
		}
	}

	if ( count( $vectors ) < UNDERGROWTH_MIN_CLUSTER_SIZE ) {
		return null;
	}

	$sum = array_fill( 0, count( reset( $vectors ) ), 0.0 );

	foreach ( $vectors as $vector ) {
		foreach ( $vector as $i => $value ) {
			$sum[ $i ] += $value;
		}
	}

	$centre = undergrowth_unit_vector( $sum );

	if ( '' === $centre ) {
		return null;
	}

	$centre = undergrowth_unpack_vector( $centre );
	$near   = array();

	foreach ( $vectors as $post_id => $vector ) {
		$near[ $post_id ] = undergrowth_dot_product( $vector, $centre );
	}

	// Nearest first; equally near posts stay in ID order.
	uksort(
		$near,
		static function ( $a, $b ) use ( $near ) {
			$order = $near[ $b ] <=> $near[ $a ];

			return 0 !== $order ? $order : $a <=> $b;
		}
	);

	return array(
		'members' => array_map( 'intval', array_keys( $near ) ),
		'centre'  => (float) reset( $near ),
	);
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
 * stage cycle, looks for composting posts near the new clusters, and then
 * stores the words that name each cluster on the Map. A batch that dies is
 * run again by the hourly check; see undergrowth_resume_stalled_passes().
 * Every batch clusters with the threshold read when the pass started.
 *
 * The first batch gives the pass an ID, which every later batch carries.
 * A pass started while another is under way replaces it: a batch of the
 * older pass stops before it changes anything. Batches run one at a time,
 * under a lock, so a new pass waits for the batch that is running.
 *
 * @param int|string $after_id Cluster posts with a higher ID than this. Cron
 *                            passes an empty string when an event has no arguments.
 * @param string     $pass     ID of the pass the batch belongs to. Empty for a new
 *                            pass, and for a batch queued before passes had IDs.
 */
function undergrowth_run_cluster_cycle( $after_id = 0, $pass = '' ): void {
	undergrowth_cluster_batch_under_lock( (int) $after_id, (string) $pass );
}

/**
 * Runs one clustering batch once it holds the clustering lock.
 *
 * @param int    $after_id Cluster posts with a higher ID than this.
 * @param string $pass     ID of the pass the batch belongs to, or an empty string.
 * @return bool False when another batch held the lock and this one was queued
 *              for a minute later, true otherwise.
 */
function undergrowth_cluster_batch_under_lock( int $after_id, string $pass ): bool {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	// The lock is taken atomically, so checking the pass, resetting the
	// clusters and storing a batch's own never interleave with another batch.
	// One left by a batch that died expires when the hourly check would run
	// that batch again. A batch that finds it taken tries again in a minute.
	if ( ! WP_Upgrader::create_lock( 'undergrowth_cluster_cycle', UNDERGROWTH_PASS_STALL ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'undergrowth_cluster_cycle', undergrowth_pass_args( $after_id, $pass ) );
		return false;
	}

	try {
		undergrowth_run_cluster_batch( $after_id, $pass );
	} finally {
		WP_Upgrader::release_lock( 'undergrowth_cluster_cycle' );
	}

	return true;
}

/**
 * Clusters one batch, while holding the clustering lock.
 *
 * @param int    $after_id Cluster posts with a higher ID than this.
 * @param string $pass     ID of the pass the batch belongs to, or an empty string.
 */
function undergrowth_run_cluster_batch( int $after_id, string $pass ): void {
	global $wpdb;

	if ( 0 === $after_id && '' === $pass ) {
		$pass = wp_generate_uuid4();
	} elseif ( 0 === $after_id ) {
		// A first batch the hourly check queued again stops when a newer
		// pass has started since.
		if ( undergrowth_current_pass( 'undergrowth_cluster_cycle' ) !== $pass ) {
			return;
		}
	} else {
		$current = undergrowth_current_pass( 'undergrowth_cluster_cycle' );

		if ( '' !== $pass && $pass !== $current ) {
			return;
		}

		$pass = $current;
	}

	$run = undergrowth_mark_pass( 'undergrowth_cluster_cycle', $after_id, $pass );

	// The first batch measures the site's scores, so the whole pass clusters
	// with the threshold they give.
	if ( 0 === $after_id ) {
		undergrowth_measure_scores();
		delete_post_meta_by_key( 'undergrowth_cluster' );
		update_option( 'undergrowth_cluster_leaders', array(), false );
		// Kept for the whole pass: the scores can be forgotten between two
		// batches when a post leaves the index, and clusters made with two
		// thresholds would not compare.
		update_option( 'undergrowth_cluster_similarity', undergrowth_min_cluster_similarity(), false );
		undergrowth_forget_resurfacing_signals();
		undergrowth_start_cluster_labels();
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
	$open    = undergrowth_open_target_ids( array_map( 'intval', array_column( $rows, 'post_id' ) ) );
	$leaders = (array) get_option( 'undergrowth_cluster_leaders', array() );
	$pools   = array();
	$minimum = get_option( 'undergrowth_cluster_similarity' );
	$minimum = is_numeric( $minimum ) ? (float) $minimum : undergrowth_min_cluster_similarity();
	$last_id = $after_id;
	$joined  = array();

	while ( array() !== $rows ) {
		$row     = array_shift( $rows );
		$last_id = (int) $row['post_id'];
		$vector  = undergrowth_unit_vector( undergrowth_decode_embedding( (string) $row['embedding'] ) );

		if ( '' === $vector || ! isset( $open[ $last_id ] ) ) {
			continue;
		}

		$group = undergrowth_vector_space( $row );

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
			$joined[ $last_id ] = $leader;
		}
	}

	// Another run took the pass over while this one compared posts: the
	// hourly check queued this batch again, or a new pass started. Nothing is
	// stored, so the clusters are all that run's.
	if ( ! undergrowth_owns_pass( 'undergrowth_cluster_cycle', $run ) ) {
		return;
	}

	foreach ( $joined as $post_id => $leader ) {
		update_post_meta( $post_id, 'undergrowth_cluster', $leader );
	}

	if ( UNDERGROWTH_CLUSTER_BATCH === $fetched ) {
		update_option( 'undergrowth_cluster_leaders', $leaders, false );
		undergrowth_queue_pass_batch( 'undergrowth_cluster_cycle', $last_id, $pass );
		return;
	}

	undergrowth_measure_gap_threshold();
	undergrowth_queue_pass_batch( 'undergrowth_stage_cycle', 0 );
	undergrowth_start_resurfacing();
	// Only now is the pass complete. Until here, a batch that died is run
	// again by the hourly check, with the leaders it started with.
	delete_option( 'undergrowth_cluster_leaders' );
	delete_option( 'undergrowth_cluster_similarity' );
	undergrowth_end_pass( 'undergrowth_cluster_cycle' );
	undergrowth_build_garden_index();
	// Last, because it reads every post: the words only label the Map, so
	// running out of time here must not hold up stages or resurfacing.
	undergrowth_label_clusters();
	// After the labels, whose pass it is marked with. It only queues the
	// first batch, since that reads every vector.
	undergrowth_start_pollination();
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
 * Keeps the posts of a list that can be clustered, in the list's order.
 *
 * The rule of undergrowth_can_cluster(), checked for the whole list in a
 * fixed number of queries and without loading any post, so a cluster of
 * hundreds of posts costs no more than one of three.
 *
 * @param int[] $post_ids Post IDs.
 * @return int[] The posts that can be clustered.
 */
function undergrowth_clusterable_posts( array $post_ids ): array {
	$open = undergrowth_open_target_ids( $post_ids );

	return array_values(
		array_filter(
			$post_ids,
			static function ( int $post_id ) use ( $open ): bool {
				return isset( $open[ $post_id ] );
			}
		)
	);
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

	foreach ( undergrowth_chunks( $leader_ids, undergrowth_vector_chunk_size() ) as $chunk ) {
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
			$vector = undergrowth_unit_vector( undergrowth_decode_embedding( (string) $row['embedding'] ) );

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

		$dot = undergrowth_dot_product( $values, undergrowth_unpack_vector( $packed ) );

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
