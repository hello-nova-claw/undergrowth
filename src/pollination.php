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

add_action( 'undergrowth_pollination_cycle', 'undergrowth_run_pollination_cycle' );
add_action( 'admin_post_undergrowth_dismiss_pollination', 'undergrowth_handle_dismiss_pollination' );

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
 * The first batch runs at once, so a small site has its pairs with its
 * groups; a larger one carries on from cron.
 */
function undergrowth_start_pollination(): void {
	update_option(
		'undergrowth_pollination_work',
		array(
			'made'  => undergrowth_cluster_labels_made(),
			'pairs' => array(),
		),
		false
	);

	undergrowth_run_pollination_cycle( 0 );
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
 * @param int|string $after_id Look at posts with a higher ID than this.
 */
function undergrowth_run_pollination_cycle( $after_id = 0 ): void {
	global $wpdb;

	$after_id = (int) $after_id;
	$work     = get_option( 'undergrowth_pollination_work' );

	// A new cluster pass has started since: its own end starts again.
	if ( ! is_array( $work ) || ! isset( $work['made'] ) || undergrowth_cluster_labels_made() !== $work['made'] ) {
		return;
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

	$wpdb->flush();

	$fetched = count( $rows );
	$last_id = $after_id;
	$groups  = undergrowth_cluster_leader_map();
	$spaces  = array();
	$pairs   = (array) $work['pairs'];

	list( $low, $high, $weakest ) = undergrowth_pollination_reach();

	while ( array() !== $rows ) {
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

	if ( UNDERGROWTH_CLUSTER_BATCH === $fetched ) {
		$work['pairs'] = $pairs;
		update_option( 'undergrowth_pollination_work', $work, false );
		wp_schedule_single_event( time(), 'undergrowth_pollination_cycle', array( $last_id ) );
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
 * it, and its share of the two groups' posts is at least twice its share of
 * the other posts. A phrase takes the place of its own words, as on the Map.
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

				$scores[ undergrowth_pollination_key( $a, $b ) ][ $term ] = $used / $size * log( ( $rest_size + 1 ) / ( $rest + 0.5 ) );
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
 * Lists the pairs of groups with accepted links between them.
 *
 * @return array<string, true> Keyed by undergrowth_pollination_key().
 */
function undergrowth_linked_group_pairs(): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table joined with the groups; links change on every accept.
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT DISTINCT a.meta_value AS a, b.meta_value AS b FROM %i l INNER JOIN %i a ON a.post_id = l.source_id AND a.meta_key = %s INNER JOIN %i b ON b.post_id = l.target_id AND b.meta_key = %s WHERE l.state = %s',
			undergrowth_links_table(),
			$wpdb->postmeta,
			'undergrowth_cluster',
			$wpdb->postmeta,
			'undergrowth_cluster',
			'accepted'
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
 * @param int      $limit Most pairs to return, or 0 for all.
 * @param int|null $step  Step of the surprise scale, or null for the author's.
 * @return array<int, array{groups: array{0: int, 1: int}, members: array{0: int[], 1: int[]}, sweet: array{0: int, 1: int, 2: float}|null, bridge: array{0: int, 1: float}|null, threads: string[], linked: bool}>
 *         Each pair's two leaders, lowest first, and their posts as the Map shows them;
 *         the sweet-spot posts, lowest ID first, with their similarity; the bridge post
 *         and its strength; the shared words; and whether an accepted link joins the groups.
 */
function undergrowth_pollination_pairs( int $limit = 0, ?int $step = null ): array {
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

	foreach ( undergrowth_cluster_map() as $cluster ) {
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

		return update_option( 'undergrowth_pollination_dismissed', $dismissed, false );
	}

	return false;
}

/**
 * Dismisses the pair posted from the Map's Cross-pollination view, then
 * returns there.
 */
function undergrowth_handle_dismiss_pollination(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() runs before anything changes.
	$a = isset( $_POST['a'] ) ? absint( wp_unslash( $_POST['a'] ) ) : 0;
	$b = isset( $_POST['b'] ) ? absint( wp_unslash( $_POST['b'] ) ) : 0;
	// phpcs:enable

	check_admin_referer( 'undergrowth_dismiss_pollination_' . $a . '_' . $b );

	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'You cannot change the Map.', 'undergrowth' ), '', array( 'response' => 403 ) );
	}

	wp_safe_redirect( undergrowth_suggestions_url( undergrowth_dismiss_pollination( $a, $b ) ? 'pollination_dismissed' : 'pollination_gone', 'map' ) . '#undergrowth-pollination' );
	exit;
}

/**
 * Returns the notices the Cross-pollination view's actions come back with.
 *
 * @return array<string, string> Messages keyed by notice.
 */
function undergrowth_pollination_result_messages(): array {
	return array(
		'pollination_dismissed' => __( 'Dismissed. This pair will not be shown again.', 'undergrowth' ),
		'pollination_gone'      => __( 'That pair is no longer shown, so nothing was changed.', 'undergrowth' ),
	);
}

/**
 * Prints the Cross-pollination view on the Map tab: each pair of groups,
 * why it was found, in the author's own titles and words, and a way to
 * dismiss it.
 */
function undergrowth_render_pollination_view(): void {
	$pairs = undergrowth_pollination_pairs();
	$steps = undergrowth_surprise_steps();

	printf( '<h2 id="undergrowth-pollination">%s</h2>', esc_html__( 'Cross-pollination', 'undergrowth' ) );
	printf(
		'<p>%1$s %2$s</p>',
		esc_html__( 'Pairs of groups that sit apart but could spark something together, found from your own posts once a month.', 'undergrowth' ),
		wp_kses(
			sprintf(
				/* translators: 1: name of the step on the surprise scale, such as "Cautious", 2: address of Settings > Undergrowth. */
				__( 'The surprise scale is at %1$s. <a href="%2$s">Change it on Settings › Undergrowth</a>.', 'undergrowth' ),
				'<strong>' . esc_html( $steps[ undergrowth_surprise_step() ] ) . '</strong>',
				esc_url( undergrowth_settings_url() . '#undergrowth_surprise_1' )
			),
			array(
				'a'      => array( 'href' => array() ),
				'strong' => array(),
			)
		)
	);

	if ( array() === $pairs ) {
		echo '<p class="undergrowth-pollination-none">' . esc_html__( 'No pairs at this step yet. A higher step on the surprise scale reaches further.', 'undergrowth' ) . '</p>';

		return;
	}

	echo '<ul class="undergrowth-pollination" aria-labelledby="undergrowth-pollination">';

	foreach ( $pairs as $pair ) {
		undergrowth_render_pollination_pair( $pair );
	}

	echo '</ul>';
}

/**
 * Prints one pair of the Cross-pollination view.
 *
 * @param array $pair One entry of undergrowth_pollination_pairs().
 *
 * @phpstan-param array{groups: array{0: int, 1: int}, members: array{0: int[], 1: int[]}, sweet: array{0: int, 1: int, 2: float}|null, bridge: array{0: int, 1: float}|null, threads: string[], linked: bool} $pair
 */
function undergrowth_render_pollination_pair( array $pair ): void {
	$id      = 'undergrowth-pollination-' . $pair['groups'][0] . '-' . $pair['groups'][1];
	$allowed = array(
		'a'   => array( 'href' => array() ),
		'bdi' => array(),
	);
	$reasons = array();

	if ( null !== $pair['sweet'] ) {
		$reasons[] = sprintf(
			/* translators: 1: title of a post in one group, 2: title of a post in the other. */
			__( '%1$s and %2$s are about different things, but not far apart.', 'undergrowth' ),
			undergrowth_post_link( $pair['sweet'][0] ),
			undergrowth_post_link( $pair['sweet'][1] )
		);
	}

	if ( null !== $pair['bridge'] ) {
		$reasons[] = sprintf(
			/* translators: %1$s: title of a post. */
			__( '%1$s sits near both groups.', 'undergrowth' ),
			undergrowth_post_link( $pair['bridge'][0] )
		);
	}

	if ( array() !== $pair['threads'] ) {
		$reasons[] = sprintf(
			/* translators: %1$s: a list of words from the posts, such as "oca, frost, and ledger". */
			__( 'Both groups use words the rest of your site rarely does: %1$s.', 'undergrowth' ),
			wp_sprintf_l(
				'%l',
				array_map(
					static function ( $term ) {
						return '<bdi>' . esc_html( $term ) . '</bdi>';
					},
					$pair['threads']
				)
			)
		);
	}

	if ( ! $pair['linked'] ) {
		$reasons[] = esc_html__( 'A post connecting these two groups would link both halves of your garden.', 'undergrowth' );
	}

	printf(
		'<li class="undergrowth-pollination-pair"><h3 id="%1$s">%2$s</h3><ul>',
		esc_attr( $id ),
		wp_kses(
			sprintf(
				/* translators: 1: title the first group is named after, 2: title the second group is named after. */
				__( 'Around %1$s, and around %2$s', 'undergrowth' ),
				'“<bdi>' . esc_html( undergrowth_post_title( $pair['members'][0][0] ) ) . '</bdi>”',
				'“<bdi>' . esc_html( undergrowth_post_title( $pair['members'][1][0] ) ) . '</bdi>”'
			),
			$allowed
		)
	);

	foreach ( $reasons as $reason ) {
		echo '<li>' . wp_kses( $reason, $allowed ) . '</li>';
	}

	printf(
		'</ul><form method="post" action="%1$s"><input type="hidden" name="action" value="undergrowth_dismiss_pollination" /><input type="hidden" name="a" value="%2$d" /><input type="hidden" name="b" value="%3$d" />%4$s<button type="submit" class="button" aria-describedby="%5$s">%6$s</button></form></li>',
		esc_url( admin_url( 'admin-post.php' ) ),
		(int) $pair['groups'][0],
		(int) $pair['groups'][1],
		wp_nonce_field( 'undergrowth_dismiss_pollination_' . $pair['groups'][0] . '_' . $pair['groups'][1], '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core's own field markup.
		esc_attr( $id ),
		esc_html__( 'Dismiss', 'undergrowth' )
	);
}
