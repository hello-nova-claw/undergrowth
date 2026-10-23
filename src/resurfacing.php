<?php
/**
 * Resurfacing: signals that a composting post sits close to what the author
 * is writing now, and their record in the per-post file.
 *
 * After each monthly clustering pass, every composting post is compared
 * with the leaders of the clusters that are new that cycle. A post close
 * enough to one gets a signal, which the author can dismiss. A signal lasts
 * until the next pass, so a post gets at most one each cycle.
 *
 * A cluster is new when no cluster of the previous pass held more than half
 * of its posts. Leader IDs are not compared: a cluster whose leader is
 * composted is led by another of its posts the next time, and is still the
 * same cluster. A cluster that more than doubled is mostly new writing, so
 * it counts as new too. The first pass has nothing to compare with, so no
 * cluster is new on it.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'undergrowth_resurfacing_cycle', 'undergrowth_run_resurfacing_cycle' );
add_action( 'undergrowth_post_left_index', 'undergrowth_forget_resurfacing_signal' );
add_action( 'undergrowth_stage_changed', 'undergrowth_forget_returned_signal' );
add_filter( 'is_protected_meta', 'undergrowth_protect_resurfacing_meta', 10, 2 );

// How each event reads in the per-post file. The file is a plain record in
// one fixed format, so these are not translated.
const UNDERGROWTH_RESURFACING_EVENTS = array(
	'shown'     => 'signal shown',
	'dismissed' => 'signal dismissed by the author',
);

/**
 * Records that a resurfacing signal was shown for a post, or dismissed.
 *
 * Only a composting post can resurface. Nothing here limits how often a
 * signal is shown; that is the signal's own rule.
 *
 * @param int    $post_id Post ID.
 * @param string $event   A key of UNDERGROWTH_RESURFACING_EVENTS.
 * @return bool True when the event was recorded.
 */
function undergrowth_log_resurfacing( int $post_id, string $event ): bool {
	if ( ! isset( UNDERGROWTH_RESURFACING_EVENTS[ $event ] ) || ! get_post( $post_id ) instanceof WP_Post || 'composting' !== undergrowth_get_stage( $post_id ) ) {
		return false;
	}

	$logged = add_post_meta(
		$post_id,
		'undergrowth_resurfacing_log',
		array(
			'time'  => time(),
			'event' => $event,
		)
	);

	if ( false === $logged ) {
		return false;
	}

	/**
	 * Fires after a resurfacing signal is shown or dismissed.
	 *
	 * @param int $post_id Post ID.
	 */
	do_action( 'undergrowth_resurfacing_logged', $post_id );

	return true;
}

/**
 * Returns the recorded resurfacing events for a post, oldest first.
 *
 * @param int $post_id Post ID.
 * @return array<int, array{time: int, event: string}> Events.
 */
function undergrowth_resurfacing_log( int $post_id ): array {
	$entries = array();

	foreach ( get_post_meta( $post_id, 'undergrowth_resurfacing_log', false ) as $entry ) {
		if (
			! is_array( $entry )
			|| ! isset( $entry['time'], $entry['event'] )
			|| ! is_int( $entry['time'] )
			|| $entry['time'] <= 0
			|| ! is_string( $entry['event'] )
			|| ! isset( UNDERGROWTH_RESURFACING_EVENTS[ $entry['event'] ] )
		) {
			continue;
		}

		$entries[] = array(
			'time'  => $entry['time'],
			'event' => $entry['event'],
		);
	}

	// An import can add older rows after newer ones, so rows are put in time
	// order. The index keeps rows from the same second in the order they were
	// added, since usort() is not stable before PHP 8.
	$order = array_keys( $entries );
	array_multisort( array_column( $entries, 'time' ), SORT_ASC, SORT_NUMERIC, $order, SORT_ASC, SORT_NUMERIC, $entries );

	return $entries;
}

/**
 * Returns the lowest similarity at which a composting post resurfaces near
 * a new cluster.
 *
 * @return float Cosine similarity between -1 and 1.
 */
function undergrowth_min_resurfacing_similarity(): float {
	// As close as a post has to be to a leader to join its cluster, so a
	// signal means the composting post would have joined the new cluster.
	$default = undergrowth_min_cluster_similarity();

	/**
	 * Filters the lowest similarity at which a composting post resurfaces
	 * near a new cluster.
	 *
	 * Compared with the cluster's leader, as a post joining it would be.
	 *
	 * @param float $similarity Cosine similarity. Default the lowest
	 *                          similarity at which a post joins a cluster.
	 *                          See undergrowth_min_cluster_similarity().
	 */
	$similarity = apply_filters( 'undergrowth_min_resurfacing_similarity', $default );

	return is_numeric( $similarity ) ? max( -1.0, min( 1.0, (float) $similarity ) ) : $default;
}

/**
 * Returns the leader of the new cluster a composting post was found close to.
 *
 * @param int $post_id Post ID.
 * @return int The leader's post ID, or 0 when the post has no signal this cycle.
 */
function undergrowth_resurfacing_signal( int $post_id ): int {
	return (int) get_post_meta( $post_id, 'undergrowth_resurfacing', true );
}

/**
 * Lists every post in a cluster of UNDERGROWTH_MIN_CLUSTER_SIZE or more,
 * with its cluster's leader.
 *
 * @return array<int, int> Leader IDs keyed by post ID, lowest post ID first.
 */
function undergrowth_cluster_snapshot(): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Once a month; WP_Query would load every clustered post.
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT post_id, meta_value FROM %i WHERE meta_key = %s ORDER BY post_id ASC',
			$wpdb->postmeta,
			'undergrowth_cluster'
		),
		ARRAY_A
	);

	$labels = array();

	foreach ( $rows as $row ) {
		$labels[ (int) $row['post_id'] ] = (int) $row['meta_value'];
	}

	$sizes = array_count_values( $labels );

	return array_filter(
		$labels,
		static function ( $leader ) use ( $sizes ) {
			return $sizes[ $leader ] >= UNDERGROWTH_MIN_CLUSTER_SIZE;
		}
	);
}

/**
 * Finds the clusters that are new since the previous pass.
 *
 * @param array<int, int> $current  Leader IDs keyed by post ID, from this pass.
 * @param array<int, int> $previous Leader IDs keyed by post ID, from the previous pass.
 * @return int[] Leaders of the clusters that no previous cluster held more than half of, lowest first.
 */
function undergrowth_new_cluster_leaders( array $current, array $previous ): array {
	$clusters = array();

	foreach ( $current as $post_id => $leader ) {
		$clusters[ $leader ][] = $post_id;
	}

	$new = array();

	foreach ( $clusters as $leader => $members ) {
		$held = array_count_values( array_values( array_intersect_key( $previous, array_flip( $members ) ) ) );

		if ( array() === $held || 2 * max( $held ) <= count( $members ) ) {
			$new[] = $leader;
		}
	}

	sort( $new );

	return $new;
}

/**
 * Compares this pass's clusters with the previous pass's, keeps them for
 * the next, and starts looking for composting posts near the new ones.
 *
 * Called by the last batch of a clustering pass.
 */
function undergrowth_start_resurfacing(): void {
	$current  = undergrowth_cluster_snapshot();
	$previous = get_option( 'undergrowth_previous_clusters' );

	update_option( 'undergrowth_previous_clusters', $current, false );

	if ( ! is_array( $previous ) ) {
		return;
	}

	$new = undergrowth_new_cluster_leaders( $current, array_map( 'intval', $previous ) );

	if ( array() === $new ) {
		return;
	}

	update_option( 'undergrowth_new_clusters', $new, false );
	undergrowth_run_resurfacing_cycle( 0 );
}

/**
 * Compares one batch of composting posts with the new clusters' leaders,
 * and gives each post close enough to one a signal.
 *
 * Only a leader with the same provider, model and language as the post is
 * compared. A post that already has its signal this cycle is left as it is,
 * so it is shown once. Each batch that fills up schedules the next.
 *
 * @param int|string $after_id Look at posts with a higher ID than this.
 */
function undergrowth_run_resurfacing_cycle( $after_id = 0 ): void {
	global $wpdb;

	$after_id = (int) $after_id;
	$leaders  = array_map( 'intval', (array) get_option( 'undergrowth_new_clusters', array() ) );

	if ( array() === $leaders ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; vectors change on every embed.
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT v.post_id, v.provider, v.model, v.language, v.embedding FROM %i v INNER JOIN %i m ON m.post_id = v.post_id WHERE m.meta_key = %s AND m.meta_value = %s AND v.post_id > %d ORDER BY v.post_id ASC LIMIT %d',
			undergrowth_vectors_table(),
			$wpdb->postmeta,
			'undergrowth_stage',
			'composting',
			$after_id,
			UNDERGROWTH_CLUSTER_BATCH
		),
		ARRAY_A
	);

	$wpdb->flush();

	$fetched = count( $rows );
	$pools   = array();
	$minimum = undergrowth_min_resurfacing_similarity();
	$last_id = $after_id;

	while ( array() !== $rows ) {
		$row     = array_shift( $rows );
		$last_id = (int) $row['post_id'];
		$post    = get_post( $last_id );

		if ( ! $post instanceof WP_Post || ! undergrowth_is_indexable( $post ) || 0 !== undergrowth_resurfacing_signal( $last_id ) ) {
			continue;
		}

		$vector = undergrowth_unit_vector( undergrowth_sanitize_vector( json_decode( (string) $row['embedding'], true ) ) );

		if ( '' === $vector ) {
			continue;
		}

		$group = $row['provider'] . "\n" . $row['model'] . "\n" . $row['language'];

		if ( ! isset( $pools[ $group ] ) ) {
			$pools[ $group ] = undergrowth_leader_vectors( $leaders, $row );
		}

		$leader = undergrowth_nearest_leader( $vector, $pools[ $group ], $minimum );

		if ( 0 !== $leader && false !== add_post_meta( $last_id, 'undergrowth_resurfacing', $leader, true ) ) {
			undergrowth_log_resurfacing( $last_id, 'shown' );
		}
	}

	if ( UNDERGROWTH_CLUSTER_BATCH === $fetched ) {
		wp_schedule_single_event( time(), 'undergrowth_resurfacing_cycle', array( $last_id ) );
		return;
	}

	delete_option( 'undergrowth_new_clusters' );
}

/**
 * Forgets every signal and the pass that was looking for them.
 *
 * Called when a new clustering pass starts, since a signal lasts one cycle.
 */
function undergrowth_forget_resurfacing_signals(): void {
	delete_option( 'undergrowth_new_clusters' );
	delete_post_meta_by_key( 'undergrowth_resurfacing' );
}

/**
 * Dismisses a post's signal for this cycle.
 *
 * @param int $post_id Post ID.
 * @return bool True when there was a signal to dismiss.
 */
function undergrowth_dismiss_resurfacing( int $post_id ): bool {
	if ( 0 === undergrowth_resurfacing_signal( $post_id ) || ! delete_post_meta( $post_id, 'undergrowth_resurfacing' ) ) {
		return false;
	}

	undergrowth_log_resurfacing( $post_id, 'dismissed' );

	return true;
}

/**
 * Removes a post's signal when it leaves the index.
 *
 * @param int $post_id Post ID.
 */
function undergrowth_forget_resurfacing_signal( int $post_id ): void {
	delete_post_meta( $post_id, 'undergrowth_resurfacing' );
}

/**
 * Removes a post's signal when the author returns it to the garden.
 *
 * @param int $post_id Post whose stage changed.
 */
function undergrowth_forget_returned_signal( int $post_id ): void {
	if ( 'composting' !== undergrowth_get_stage( $post_id ) ) {
		undergrowth_forget_resurfacing_signal( $post_id );
	}
}

/**
 * Keeps the signal key out of the Custom Fields box, where it could be
 * edited by hand.
 *
 * @param bool   $is_protected Whether the key is protected.
 * @param string $meta_key     Meta key.
 * @return bool True for the signal key, otherwise unchanged.
 */
function undergrowth_protect_resurfacing_meta( $is_protected, $meta_key ) {
	return 'undergrowth_resurfacing' === $meta_key ? true : $is_protected;
}
