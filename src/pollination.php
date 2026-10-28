<?php
/**
 * Cross-pollination: pairs of the author's groups that sit apart but could
 * spark something together.
 *
 * Three signals, all from the author's own writing and the stored vectors:
 *
 * - a sweet spot: two posts in different groups whose similarity falls in a
 *   moderate band, neither obvious nor unrelated;
 * - a bridge: a post near the centres of two groups that have no accepted
 *   link between them;
 * - a shared rare thread: a word or two-word phrase two such groups use and
 *   the rest of the site rarely does.
 *
 * The surprise scale moves the band and how strong a bridge must be. It is
 * read when the pairs are shown, so changing it needs no new pass: the pass
 * keeps what the widest step would show. No model is asked anything.
 *
 * Groups are known by their posts, as hubs are, so a dismissal outlives the
 * monthly change of leaders.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

// The step the surprise scale starts at: cautious, so early pairs make sense.
const UNDERGROWTH_SURPRISE_DEFAULT = 2;

// How far below the cluster threshold the sweet-spot band reaches at each
// step, as a share of the gap between the cluster and link thresholds.
const UNDERGROWTH_SURPRISE_REACH = array(
	1 => 0.25,
	2 => 0.5,
	3 => 0.75,
	4 => 1.0,
	5 => 1.5,
);

// How far below the cluster threshold a bridge post may sit from each of
// the two groups' centres, as a share of the same gap.
const UNDERGROWTH_SURPRISE_BRIDGE = array(
	1 => 0.0,
	2 => 0.25,
	3 => 0.5,
	4 => 0.75,
	5 => 1.0,
);

// Most candidates of each kind kept for a pair of groups, so one that later
// leaves the garden or the band has others behind it.
const UNDERGROWTH_POLLINATION_KEEP = 3;

// Most shared words kept for a pair of groups.
const UNDERGROWTH_THREAD_TERMS = 3;

// How many of each group's own most telling words a shared word must be
// among. Any two groups share some word by chance; a thread is a word both
// are known for.
const UNDERGROWTH_THREAD_DEPTH = 10;

add_action( 'undergrowth_pollination_cycle', 'undergrowth_run_pollination_cycle', 10, 2 );
add_action( 'undergrowth_pollination_start', 'undergrowth_start_pollination' );
// After the other sources: a cross-pollination question holds its own place
// in the weekly batch, so it never has to win one from them.
add_filter( 'undergrowth_prompt_sources', 'undergrowth_pollination_prompt_sources', 25 );

/**
 * Returns the names of the surprise scale's steps, from the closest to the
 * wildest.
 *
 * @return array<int, string> Step names, keyed 1 to 5.
 */
function undergrowth_surprise_steps(): array {
	return array(
		1 => __( 'Close cousins', 'undergrowth' ),
		2 => __( 'Cautious', 'undergrowth' ),
		3 => __( 'Curious', 'undergrowth' ),
		4 => __( 'Adventurous', 'undergrowth' ),
		5 => __( 'Wild', 'undergrowth' ),
	);
}

/**
 * Returns the step the author chose on the surprise scale.
 *
 * @return int Step from 1 to 5.
 */
function undergrowth_surprise_step(): int {
	return undergrowth_sanitize_surprise( get_option( 'undergrowth_surprise', UNDERGROWTH_SURPRISE_DEFAULT ) );
}

/**
 * Keeps a surprise step within the scale.
 *
 * @param mixed $value Submitted or stored value.
 * @return int Step from 1 to 5, or the default for anything else.
 */
function undergrowth_sanitize_surprise( $value ): int {
	$step = is_numeric( $value ) ? (int) $value : 0;

	return isset( UNDERGROWTH_SURPRISE_REACH[ $step ] ) ? $step : UNDERGROWTH_SURPRISE_DEFAULT;
}

/**
 * Returns the gap between the cluster and link thresholds, which the scale's
 * steps are measured in, so the scale follows the site's own scores.
 *
 * @return array{0: float, 1: float} The cluster threshold and the gap, at least 0.05.
 */
function undergrowth_surprise_measure(): array {
	$cluster = undergrowth_min_cluster_similarity();

	return array( $cluster, max( 0.05, $cluster - undergrowth_min_link_similarity() ) );
}

/**
 * Returns the sweet-spot band at a step of the surprise scale.
 *
 * Pairs in the band are close enough to make sense and too far apart to
 * have joined one group. A higher step reaches further down.
 *
 * @param int $step Step from 1 to 5.
 * @return array{0: float, 1: float} Lowest similarity in the band, and the similarity it stays below.
 */
function undergrowth_surprise_band( int $step ): array {
	$step                  = undergrowth_sanitize_surprise( $step );
	list( $cluster, $gap ) = undergrowth_surprise_measure();
	$default               = array( $cluster - UNDERGROWTH_SURPRISE_REACH[ $step ] * $gap, $cluster );

	/**
	 * Filters the sweet-spot band at a step of the surprise scale.
	 *
	 * @param array{0: float, 1: float} $band Lowest similarity in the band, and the similarity it
	 *                                        stays below. Default from the cluster threshold down,
	 *                                        by a share of its gap to the link threshold: a quarter
	 *                                        at step 1, then a half, three quarters, all of it and
	 *                                        one and a half.
	 * @param int                       $step Step from 1 (close cousins) to 5 (wild).
	 */
	$band = apply_filters( 'undergrowth_surprise_band', $default, $step );

	if ( ! is_array( $band ) || ! isset( $band[0], $band[1] ) || ! is_numeric( $band[0] ) || ! is_numeric( $band[1] ) || (float) $band[0] > (float) $band[1] ) {
		return $default;
	}

	return array( (float) $band[0], (float) $band[1] );
}

/**
 * Returns how close a bridge post must be to each of two groups' centres at
 * a step of the surprise scale.
 *
 * @param int $step Step from 1 to 5.
 * @return float Lowest similarity to each centre.
 */
function undergrowth_bridge_strength( int $step ): float {
	$step                  = undergrowth_sanitize_surprise( $step );
	list( $cluster, $gap ) = undergrowth_surprise_measure();
	$default               = $cluster - UNDERGROWTH_SURPRISE_BRIDGE[ $step ] * $gap;

	/**
	 * Filters how close a bridge post must be to each of two groups' centres.
	 *
	 * @param float $strength Lowest similarity to each centre. Default the cluster threshold at
	 *                        step 1, then down by a quarter of its gap to the link threshold for
	 *                        each step, to the link threshold at step 5.
	 * @param int   $step     Step from 1 (close cousins) to 5 (wild).
	 */
	$strength = apply_filters( 'undergrowth_bridge_strength', $default, $step );

	return is_numeric( $strength ) ? (float) $strength : $default;
}

/**
 * Returns the widest band and the weakest bridge any step allows, which is
 * what a pass keeps.
 *
 * @return array{0: float, 1: float, 2: float} Lowest similarity, the similarity pairs stay below, and the weakest bridge.
 */
function undergrowth_pollination_reach(): array {
	$low    = INF;
	$high   = -INF;
	$bridge = INF;

	foreach ( array_keys( UNDERGROWTH_SURPRISE_REACH ) as $step ) {
		$band   = undergrowth_surprise_band( $step );
		$low    = min( $low, $band[0] );
		$high   = max( $high, $band[1] );
		$bridge = min( $bridge, undergrowth_bridge_strength( $step ) );
	}

	return array( $low, $high, $bridge );
}

/**
 * Names a pair of groups by their leaders, lowest first.
 *
 * @param int $a Leader ID.
 * @param int $b Leader ID.
 * @return string Key such as "12-40".
 */
function undergrowth_pollination_key( int $a, int $b ): string {
	return min( $a, $b ) . '-' . max( $a, $b );
}

/**
 * Keeps a candidate among the strongest few for a pair of groups.
 *
 * @param array<int, array<int, int|float>> $kept      Candidates so far, strongest first, the score last.
 * @param array<int, int|float>             $candidate A new candidate, its score last.
 * @return array<int, array<int, int|float>> Candidates, strongest first; equal scores in post ID order.
 */
function undergrowth_keep_strongest( array $kept, array $candidate ): array {
	$kept[] = $candidate;

	usort(
		$kept,
		static function ( $x, $y ) {
			$order = end( $y ) <=> end( $x );

			return 0 !== $order ? $order : array_slice( $x, 0, -1 ) <=> array_slice( $y, 0, -1 );
		}
	);

	return array_slice( $kept, 0, UNDERGROWTH_POLLINATION_KEEP );
}

/**
 * Starts finding pairs, at the end of a cluster pass.
 *
 * The first batch is queued for cron, so the request that ends the cluster
 * pass does no more work. Each batch is recorded like the cluster and stage
 * passes', so the hourly check runs one that died again.
 *
 * A start and a batch never interleave: both take one lock, so a batch of
 * the old pass can never store its pairs over the new pass's work. A start
 * that finds the lock taken tries again in a minute.
 */
function undergrowth_start_pollination(): void {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	if ( ! WP_Upgrader::create_lock( 'undergrowth_pollination_cycle', UNDERGROWTH_PASS_STALL ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'undergrowth_pollination_start' );
		return;
	}

	try {
		undergrowth_begin_pollination_pass();
	} finally {
		WP_Upgrader::release_lock( 'undergrowth_pollination_cycle' );
	}
}

/**
 * Records a new pass and queues its first batch, while holding the lock.
 */
function undergrowth_begin_pollination_pass(): void {
	$pass = wp_generate_uuid4();

	update_option(
		'undergrowth_pollination_work',
		array(
			'made'  => undergrowth_cluster_labels_made(),
			'pass'  => $pass,
			'pairs' => array(),
		),
		false
	);

	undergrowth_queue_pass_batch( 'undergrowth_pollination_cycle', 0, $pass );
}

/**
 * Returns when the current groups were found, which marks the work on them.
 *
 * @return string UTC MySQL date, or an empty string before any pass.
 */
function undergrowth_cluster_labels_made(): string {
	$labels = get_option( 'undergrowth_cluster_labels', array() );

	return is_array( $labels ) && isset( $labels['made'] ) ? (string) $labels['made'] : '';
}

/**
 * Reads each grouped post's leader in one query.
 *
 * @return array<int, int> Leader ID, keyed by post ID.
 */
function undergrowth_cluster_leader_map(): array {
	global $wpdb;

	$leaders = array_flip( undergrowth_cluster_leaders() );
	$map     = array();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One query instead of one per post.
	$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, meta_value FROM %i WHERE meta_key = %s', $wpdb->postmeta, 'undergrowth_cluster' ), ARRAY_A );

	foreach ( $rows as $row ) {
		$leader = (int) $row['meta_value'];

		// Only groups the Map shows, of three or more posts.
		if ( isset( $leaders[ $leader ] ) ) {
			$map[ (int) $row['post_id'] ] = $leader;
		}
	}

	return $map;
}

/**
 * Compares one batch of posts with every post and group centre in the same
 * provider, model and language, and keeps the strongest candidates for each
 * pair of groups. The last batch stores them.
 *
 * Every indexed post, composting ones among them, can bridge two groups.
 * Only posts in a group make sweet-spot pairs, so a composting post is never
 * proposed as one end of a link.
 *
 * Batches run one at a time, under the lock a start also takes. A batch
 * that finds it taken tries again in a minute.
 *
 * @param int|string $after_id Look at posts with a higher ID than this.
 * @param string     $pass     ID of the pass the batch belongs to.
 */
function undergrowth_run_pollination_cycle( $after_id = 0, $pass = '' ): void {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	if ( ! WP_Upgrader::create_lock( 'undergrowth_pollination_cycle', UNDERGROWTH_PASS_STALL ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'undergrowth_pollination_cycle', array( (int) $after_id, (string) $pass ) );
		return;
	}

	try {
		undergrowth_run_pollination_batch( (int) $after_id, (string) $pass );
	} finally {
		WP_Upgrader::release_lock( 'undergrowth_pollination_cycle' );
	}
}

/**
 * Runs one batch of a pollination pass, while holding the lock.
 *
 * @param int    $after_id Look at posts with a higher ID than this.
 * @param string $pass     ID of the pass the batch belongs to.
 */
function undergrowth_run_pollination_batch( int $after_id, string $pass ): void {
	global $wpdb;

	// Another request may have started a pass since this one read them.
	wp_cache_delete( 'undergrowth_pollination_work', 'options' );
	wp_cache_delete( 'undergrowth_cluster_labels', 'options' );

	$work = get_option( 'undergrowth_pollination_work' );

	// A new cluster pass, or a new run of this one, has started since: it
	// carries on in its own batches.
	if ( ! is_array( $work ) || ! isset( $work['made'], $work['pass'] ) || undergrowth_cluster_labels_made() !== $work['made'] || $pass !== $work['pass'] ) {
		return;
	}

	$run = undergrowth_mark_pass( 'undergrowth_pollination_cycle', $after_id, $pass );

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

	$wpdb->flush();

	$fetched = count( $rows );
	$last_id = $after_id;
	$groups  = undergrowth_cluster_leader_map();
	$spaces  = array();
	$pairs   = (array) $work['pairs'];

	list( $low, $high, $weakest ) = undergrowth_pollination_reach();

	// Each post is compared with every grouped post after it, so a batch
	// takes longer as the site grows. Like the backfill, it starts no post
	// once its time is spent, and the next batch carries on from there.
	$started = microtime( true );
	$budget  = undergrowth_backfill_time_budget();

	while ( array() !== $rows ) {
		if ( $last_id !== $after_id && microtime( true ) - $started >= $budget ) {
			break;
		}

		$row     = array_shift( $rows );
		$last_id = (int) $row['post_id'];
		$post    = get_post( $last_id );
		$source  = undergrowth_unit_vector( undergrowth_decode_embedding( (string) $row['embedding'] ) );

		if ( '' === $source || ! $post instanceof WP_Post || ! undergrowth_is_indexable( $post ) ) {
			continue;
		}

		$space = undergrowth_vector_space( $row );

		if ( ! isset( $spaces[ $space ] ) ) {
			$spaces[ $space ] = undergrowth_pollination_space( $row, $groups );
		}

		$source = undergrowth_unpack_vector( $source );
		$near   = array();

		foreach ( $spaces[ $space ]['centres'] as $leader => $centre ) {
			// A vector of another length has no similarity to compare.
			if ( ! undergrowth_vectors_comparable( $source, $centre ) ) {
				continue;
			}

			$similarity = undergrowth_dot_product( $source, $centre );

			if ( $similarity >= $weakest ) {
				$near[ $leader ] = $similarity;
			}
		}

		ksort( $near );

		foreach ( $near as $a => $to_a ) {
			foreach ( $near as $b => $to_b ) {
				if ( $b > $a ) {
					$key                     = undergrowth_pollination_key( $a, $b );
					$pairs[ $key ]['bridge'] = undergrowth_keep_strongest( $pairs[ $key ]['bridge'] ?? array(), array( $last_id, min( $to_a, $to_b ) ) );
				}
			}
		}

		$group = $groups[ $last_id ] ?? 0;

		if ( 0 === $group ) {
			continue;
		}

		// Each pair is measured once, from its lower ID.
		foreach ( $spaces[ $space ]['vectors'] as $other_id => $packed ) {
			$other = $groups[ $other_id ] ?? 0;

			if ( $other_id <= $last_id || 0 === $other || $other === $group ) {
				continue;
			}

			$vector = undergrowth_unpack_vector( $packed );

			if ( ! undergrowth_vectors_comparable( $source, $vector ) ) {
				continue;
			}

			$similarity = undergrowth_dot_product( $source, $vector );

			if ( $similarity >= $low && $similarity < $high ) {
				$key                    = undergrowth_pollination_key( $group, $other );
				$pairs[ $key ]['sweet'] = undergrowth_keep_strongest( $pairs[ $key ]['sweet'] ?? array(), array( $last_id, $other_id, $similarity ) );
			}
		}
	}

	// The hourly check ran this batch again while this run was still
	// comparing: that run's results stand, and this one's are dropped.
	if ( ! undergrowth_owns_pass( 'undergrowth_pollination_cycle', $run ) ) {
		return;
	}

	if ( UNDERGROWTH_CLUSTER_BATCH === $fetched || array() !== $rows ) {
		$work['pairs'] = $pairs;
		update_option( 'undergrowth_pollination_work', $work, false );
		undergrowth_queue_pass_batch( 'undergrowth_pollination_cycle', $last_id, $pass );
		return;
	}

	update_option(
		'undergrowth_pollination',
		array(
			'made'  => $work['made'],
			'pairs' => $pairs,
		),
		false
	);
	delete_option( 'undergrowth_pollination_work' );
	undergrowth_end_pass( 'undergrowth_pollination_cycle' );
	undergrowth_refresh_cross_links();
}

/**
 * Loads the grouped vectors of one provider, model and language as unit
 * vectors, and the centre of each group in it.
 *
 * @param array<string, mixed> $identity Row giving the provider, model and language.
 * @param array<int, int>      $groups   Leader ID, keyed by post ID.
 * @return array{vectors: array<int, string>, centres: array<int, float[]>} Packed unit
 *         vectors of grouped posts, by post ID, and each group's centre, by leader ID.
 */
function undergrowth_pollination_space( array $identity, array $groups ): array {
	$vectors = array();
	$sums    = array();

	foreach ( undergrowth_vectors_in_space( (string) $identity['provider'], (string) $identity['model'], (string) $identity['language'] ) as $post_id => $packed ) {
		$leader = $groups[ $post_id ] ?? 0;
		$unit   = 0 === $leader ? '' : undergrowth_unit_vector( undergrowth_unpack_vector( $packed ) );

		if ( '' === $unit ) {
			continue;
		}

		$vectors[ $post_id ] = $unit;
		$vector              = undergrowth_unpack_vector( $unit );

		if ( ! isset( $sums[ $leader ] ) ) {
			$sums[ $leader ] = $vector;
			continue;
		}

		// A vector of another length cannot share a centre.
		if ( count( $vector ) !== count( $sums[ $leader ] ) ) {
			unset( $vectors[ $post_id ] );
			continue;
		}

		foreach ( $vector as $i => $value ) {
			$sums[ $leader ][ $i ] += $value;
		}
	}

	$centres = array();

	foreach ( $sums as $leader => $sum ) {
		$centre = undergrowth_unit_vector( $sum );

		if ( '' !== $centre ) {
			$centres[ $leader ] = undergrowth_unpack_vector( $centre );
		}
	}

	return array(
		'vectors' => $vectors,
		'centres' => $centres,
	);
}

/**
 * Finds the words two groups share that the rest of the site rarely uses,
 * from the counts the Map's labels are made from.
 *
 * A word or two-word phrase counts when at least two posts in each group use
 * it, its share of the two groups' posts is at least twice its share of the
 * other posts, and it is among the UNDERGROWTH_THREAD_DEPTH words that most
 * set each group apart from the posts outside the pair. A phrase takes the
 * place of its own words, as on the Map.
 *
 * @param array<int, array{size: int, counts: array<string, int>, spelled: array<string, array<string, int>>}> $groups    Each group's size, the number of its posts using each word, and how they spell it, by leader ID.
 * @param array<string, int>                                                                                   $site      Number of posts on the site using each word.
 * @param int                                                                                                  $site_size Number of posts on the site.
 * @return array<string, string[]> Up to UNDERGROWTH_THREAD_TERMS words, most telling first,
 *         as the author spelled them, keyed by undergrowth_pollination_key().
 */
function undergrowth_find_threads( array $groups, array $site, int $site_size ): array {
	$by_term = array();

	foreach ( $groups as $leader => $group ) {
		foreach ( $group['counts'] as $term => $count ) {
			if ( $count >= 2 ) {
				$by_term[ (string) $term ][] = (int) $leader;
			}
		}
	}

	$scores = array();
	$known  = array();

	foreach ( $by_term as $term => $leaders ) {
		sort( $leaders );

		foreach ( $leaders as $i => $a ) {
			foreach ( array_slice( $leaders, $i + 1 ) as $b ) {
				$used      = $groups[ $a ]['counts'][ $term ] + $groups[ $b ]['counts'][ $term ];
				$size      = $groups[ $a ]['size'] + $groups[ $b ]['size'];
				$rest      = max( 0, ( $site[ $term ] ?? $used ) - $used );
				$rest_size = max( 0, $site_size - $size );

				if ( $size < 1 || ( $rest_size > 0 && $used / $size < 2 * $rest / $rest_size ) ) {
					continue;
				}

				$key           = undergrowth_pollination_key( $a, $b );
				$known[ $key ] = $known[ $key ] ?? array(
					undergrowth_group_known_terms( $groups[ $a ], $groups[ $b ], $site, $site_size ),
					undergrowth_group_known_terms( $groups[ $b ], $groups[ $a ], $site, $site_size ),
				);

				if ( ! isset( $known[ $key ][0][ $term ], $known[ $key ][1][ $term ] ) ) {
					continue;
				}

				$scores[ $key ][ $term ] = $used / $size * log( ( $rest_size + 1 ) / ( $rest + 0.5 ) );
			}
		}
	}

	$threads = array();

	foreach ( $scores as $key => $terms ) {
		list( $a, $b ) = array_map( 'intval', explode( '-', $key ) );

		// A phrase takes the place of a word it holds.
		foreach ( array_keys( $terms ) as $term ) {
			if ( false !== strpos( (string) $term, ' ' ) ) {
				foreach ( explode( ' ', (string) $term ) as $part ) {
					unset( $terms[ $part ] );
				}
			}
		}

		foreach ( array_keys( undergrowth_heaviest_terms( $terms, UNDERGROWTH_THREAD_TERMS ) ) as $term ) {
			$spellings = array();

			foreach ( array( $a, $b ) as $leader ) {
				foreach ( $groups[ $leader ]['spelled'][ $term ] ?? array() as $spelling => $count ) {
					$spellings[ $spelling ] = ( $spellings[ $spelling ] ?? 0 ) + $count;
				}
			}

			arsort( $spellings );
			$threads[ $key ][] = (string) ( array_key_first( $spellings ) ?? $term );
		}
	}

	ksort( $threads );

	return $threads;
}

/**
 * Lists the words a group is best known for, set against the posts outside
 * it and one other group, so two groups that share a word are each judged
 * without the other.
 *
 * @param array{size: int, counts: array<string, int>} $group     The group.
 * @param array{size: int, counts: array<string, int>} $other     The group it is paired with.
 * @param array<string, int>                           $site      Number of posts on the site using each word.
 * @param int                                          $site_size Number of posts on the site.
 * @return array<string, true> Up to UNDERGROWTH_THREAD_DEPTH words, as keys.
 */
function undergrowth_group_known_terms( array $group, array $other, array $site, int $site_size ): array {
	$without = array();

	foreach ( $group['counts'] as $term => $count ) {
		$without[ $term ] = max( $count, ( $site[ $term ] ?? $count ) - ( $other['counts'][ $term ] ?? 0 ) );
	}

	return array_fill_keys( undergrowth_choose_label_terms( $group['counts'], $group['size'], $without, $site_size - $other['size'], UNDERGROWTH_THREAD_DEPTH ), true );
}

/**
 * Lists the pairs of groups with accepted links between them.
 *
 * @return array<string, true> Keyed by undergrowth_pollination_key().
 */
function undergrowth_linked_group_pairs(): array {
	global $wpdb;

	// CROSS JOIN keeps the order written, so SQLite reads the accepted links
	// first and looks up each end's group. With inner joins it paired every
	// grouped post with every other before looking for a link, 3 seconds at
	// 5,000 posts. MySQL plans both the same.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table joined with the groups; links change on every accept.
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT DISTINCT a.meta_value AS a, b.meta_value AS b FROM %i l CROSS JOIN %i a CROSS JOIN %i b WHERE l.state = %s AND a.post_id = l.source_id AND a.meta_key = %s AND b.post_id = l.target_id AND b.meta_key = %s',
			undergrowth_links_table(),
			$wpdb->postmeta,
			$wpdb->postmeta,
			'accepted',
			'undergrowth_cluster',
			'undergrowth_cluster'
		),
		ARRAY_A
	);

	$linked = array();

	foreach ( $rows as $row ) {
		if ( $row['a'] !== $row['b'] ) {
			$linked[ undergrowth_pollination_key( (int) $row['a'], (int) $row['b'] ) ] = true;
		}
	}

	return $linked;
}

/**
 * Returns the pairs the author dismissed, each as the posts its two groups
 * had then.
 *
 * @return array<int, array{0: int[], 1: int[]}> Two lists of post IDs per dismissal.
 */
function undergrowth_dismissed_pollination(): array {
	$stored = get_option( 'undergrowth_pollination_dismissed', array() );
	$pairs  = array();

	foreach ( is_array( $stored ) ? $stored : array() as $pair ) {
		if ( ! is_array( $pair ) || ! isset( $pair[0], $pair[1] ) || ! is_array( $pair[0] ) || ! is_array( $pair[1] ) ) {
			continue;
		}

		$a = array_values( array_filter( array_map( 'intval', $pair[0] ) ) );
		$b = array_values( array_filter( array_map( 'intval', $pair[1] ) ) );

		if ( array() !== $a && array() !== $b ) {
			$pairs[] = array( $a, $b );
		}
	}

	return $pairs;
}

/**
 * Tells whether the author dismissed this pair of groups, or a pair sharing
 * more than half of each group's posts.
 *
 * @param int[] $a Post IDs in one group.
 * @param int[] $b Post IDs in the other.
 * @return bool True when it was dismissed.
 */
function undergrowth_pollination_dismissed( array $a, array $b ): bool {
	foreach ( undergrowth_dismissed_pollination() as $pair ) {
		if ( ( undergrowth_same_group( $a, $pair[0] ) && undergrowth_same_group( $b, $pair[1] ) ) || ( undergrowth_same_group( $a, $pair[1] ) && undergrowth_same_group( $b, $pair[0] ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Returns the best current pairs of groups at the author's step of the
 * surprise scale, for the Map and for other features such as the Prompt
 * studio.
 *
 * A pair shows when at least one signal holds at the step:
 *
 * - a sweet-spot pair of posts, both still in their groups, in the band;
 * - for groups with no accepted link between them, a bridge post at least
 *   as strong as the step asks, still in the index (it may be composting),
 *   and the words they share.
 *
 * Pairs the author dismissed never show. Pairs with more signals come
 * first, then the closer sweet spot, then the stronger bridge.
 *
 * @param int                                                            $limit Most pairs to return, or 0 for all.
 * @param int|null                                                       $step  Step of the surprise scale, or null for the author's.
 * @param array<int, array{leader: int, members: int[], gap: bool}>|null $map   Result of undergrowth_cluster_map(), when already built.
 * @return array<int, array{groups: array{0: int, 1: int}, members: array{0: int[], 1: int[]}, sweet: array{0: int, 1: int, 2: float}|null, bridge: array{0: int, 1: float}|null, threads: string[], linked: bool}>
 *         Each pair's two leaders, lowest first, and their posts as the Map shows them;
 *         the sweet-spot posts, lowest ID first, with their similarity; the bridge post
 *         and its strength; the shared words; and whether an accepted link joins the groups.
 */
function undergrowth_pollination_pairs( int $limit = 0, ?int $step = null, ?array $map = null ): array {
	$stored = get_option( 'undergrowth_pollination', array() );
	$labels = get_option( 'undergrowth_cluster_labels', array() );
	$made   = undergrowth_cluster_labels_made();

	// Pairs from an older pass name leaders that no longer stand for anything.
	if ( ! is_array( $stored ) || ! isset( $stored['made'], $stored['pairs'] ) || $made !== $stored['made'] ) {
		$stored = array( 'pairs' => array() );
	}

	$threads = is_array( $labels ) && isset( $labels['threads'] ) && is_array( $labels['threads'] ) ? $labels['threads'] : array();
	$keys    = array_unique( array_merge( array_keys( (array) $stored['pairs'] ), array_keys( $threads ) ) );

	if ( array() === $keys ) {
		return array();
	}

	$step     = undergrowth_sanitize_surprise( $step ?? undergrowth_surprise_step() );
	$band     = undergrowth_surprise_band( $step );
	$strength = undergrowth_bridge_strength( $step );
	$members  = array();

	foreach ( $map ?? undergrowth_cluster_map() as $cluster ) {
		$members[ $cluster['leader'] ] = $cluster['members'];
	}

	$linked = undergrowth_linked_group_pairs();
	$pairs  = array();

	foreach ( $keys as $key ) {
		list( $a, $b ) = array_map( 'intval', explode( '-', (string) $key ) );

		if ( ! isset( $members[ $a ], $members[ $b ] ) || undergrowth_pollination_dismissed( $members[ $a ], $members[ $b ] ) ) {
			continue;
		}

		$found  = (array) ( $stored['pairs'][ $key ] ?? array() );
		$joined = isset( $linked[ $key ] );
		$pair   = array(
			'groups'  => array( $a, $b ),
			'members' => array( $members[ $a ], $members[ $b ] ),
			'sweet'   => null,
			'bridge'  => null,
			'threads' => $joined ? array() : array_values( array_map( 'strval', (array) ( $threads[ $key ] ?? array() ) ) ),
			'linked'  => $joined,
		);

		foreach ( (array) ( $found['sweet'] ?? array() ) as $candidate ) {
			list( $source, $target, $score ) = array_values( (array) $candidate ) + array( 0, 0, 0.0 );

			$sides = array( in_array( (int) $source, $members[ $a ], true ), in_array( (int) $target, $members[ $b ], true ), in_array( (int) $source, $members[ $b ], true ), in_array( (int) $target, $members[ $a ], true ) );

			if ( (float) $score >= $band[0] && (float) $score < $band[1] && ( ( $sides[0] && $sides[1] ) || ( $sides[2] && $sides[3] ) ) ) {
				$pair['sweet'] = array( (int) $source, (int) $target, (float) $score );
				break;
			}
		}

		foreach ( $joined ? array() : (array) ( $found['bridge'] ?? array() ) as $candidate ) {
			list( $post_id, $score ) = array_values( (array) $candidate ) + array( 0, 0.0 );
			$post                    = get_post( (int) $post_id );

			if ( (float) $score >= $strength && $post instanceof WP_Post && undergrowth_is_indexable( $post ) ) {
				$pair['bridge'] = array( (int) $post_id, (float) $score );
				break;
			}
		}

		if ( null !== $pair['sweet'] || null !== $pair['bridge'] || array() !== $pair['threads'] ) {
			$pairs[] = $pair;
		}
	}

	usort(
		$pairs,
		static function ( $x, $y ) {
			$signals = static function ( $pair ) {
				return ( null === $pair['sweet'] ? 0 : 1 ) + ( null === $pair['bridge'] ? 0 : 1 ) + ( array() === $pair['threads'] ? 0 : 1 );
			};

			$order = array( $signals( $y ), $y['sweet'][2] ?? -INF, $y['bridge'][1] ?? -INF ) <=> array( $signals( $x ), $x['sweet'][2] ?? -INF, $x['bridge'][1] ?? -INF );

			return 0 !== $order ? $order : $x['groups'] <=> $y['groups'];
		}
	);

	return $limit > 0 ? array_slice( $pairs, 0, $limit ) : $pairs;
}

/**
 * Dismisses a pair of groups for good, keeping both groups' posts so the
 * pair stays dismissed after the leaders change.
 *
 * @param int $a Leader ID of one group.
 * @param int $b Leader ID of the other.
 * @return bool True when stored, false when no such pair is shown.
 */
function undergrowth_dismiss_pollination( int $a, int $b ): bool {
	foreach ( undergrowth_pollination_pairs() as $pair ) {
		if ( undergrowth_pollination_key( $a, $b ) !== undergrowth_pollination_key( ...$pair['groups'] ) ) {
			continue;
		}

		$dismissed   = get_option( 'undergrowth_pollination_dismissed', array() );
		$dismissed   = is_array( $dismissed ) ? $dismissed : array();
		$sets        = $pair['members'];
		$dismissed[] = array_map(
			static function ( $set ) {
				sort( $set );

				return $set;
			},
			$sets
		);

		if ( ! update_option( 'undergrowth_pollination_dismissed', $dismissed, false ) ) {
			return false;
		}

		// Its cross-pollination suggestions go with it.
		undergrowth_refresh_cross_links();

		return true;
	}

	return false;
}

/**
 * Offers the cross-pollination pairs as sources for questions, best first.
 *
 * Each record quotes one sentence from each of two posts, the author's own
 * words only: the sweet-spot posts when the pair has them, otherwise the
 * post each group is named after. Its key names the two posts, so each is
 * asked about once. A pair with a post that has no sentence of its own is
 * no source.
 *
 * @param mixed $records Records found so far.
 * @return array<int, array<string, mixed>> Records with the pairs added.
 */
function undergrowth_pollination_prompt_sources( $records ): array {
	$records = (array) $records;

	foreach ( undergrowth_pollination_pairs() as $pair ) {
		$ends   = null === $pair['sweet'] ? array( $pair['members'][0][0], $pair['members'][1][0] ) : array( $pair['sweet'][0], $pair['sweet'][1] );
		$quotes = array();

		foreach ( $ends as $post_id ) {
			$post  = get_post( $post_id );
			$quote = $post instanceof WP_Post ? undergrowth_longest_sentence( $post ) : null;

			if ( null === $quote ) {
				continue 2;
			}

			$quotes[] = $quote;
		}

		$records[] = array(
			'source'  => 'cross',
			'key'     => 'cross:' . min( $ends ) . '-' . max( $ends ),
			'post_id' => $ends[0],
			'posts'   => $ends,
			'quotes'  => $quotes,
		);
	}

	return $records;
}

/**
 * Tells whether a source record is a cross-pollination pair.
 *
 * @param array<string, mixed> $record Source record.
 * @return bool True for a pair.
 */
function undergrowth_is_cross_prompt_record( array $record ): bool {
	return 'cross' === ( $record['source'] ?? '' );
}

/**
 * Writes the weekly batch's cross-pollination question, when the batch has
 * room, holds none yet, and a pair can give one.
 *
 * @param int $calls Requests sent, counted up here.
 * @return int Questions written: 1 or 0.
 */
function undergrowth_write_cross_prompt( int &$calls = 0 ): int {
	foreach ( undergrowth_prompts( 'new' ) as $prompt ) {
		if ( 'cross' === $prompt['source'] ) {
			return 0;
		}
	}

	return undergrowth_write_prompts( 1, UNDERGROWTH_PROMPT_MAX_CALLS, 'undergrowth_is_cross_prompt_record', $calls );
}
