<?php
/**
 * Thresholds that follow how a site's own posts score against each other.
 *
 * Embedding models spread their scores differently, so a fixed cosine score
 * that suits one model links everything, or nothing, with another. Once a
 * site has enough posts, the link and cluster thresholds come from its own
 * pairwise scores and the gap threshold from its own clusters, measured in
 * the monthly cluster cycle. The fixed numbers stay for small sites and for
 * providers whose spread has not been measured on real posts yet.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_DERIVED_MIN_POSTS    = 20;
const UNDERGROWTH_SCORE_SAMPLE         = 20000;
const UNDERGROWTH_SCORE_VECTORS        = 1000;
const UNDERGROWTH_LINK_PERCENTILE      = 0.4;
const UNDERGROWTH_CLUSTER_PERCENTILE   = 0.9;
const UNDERGROWTH_MIN_GAP_CLUSTERS     = 4;
const UNDERGROWTH_RECORDED_PERCENTILES = array( 10, 25, 40, 50, 75, 90, 95 );

// OpenAI's spread is not measured on real posts yet. docs/testing-v1.md asks
// for its percentiles; take it off this list once they are in.
const UNDERGROWTH_FIXED_THRESHOLD_PROVIDERS = array( 'openai' );

add_action( 'undergrowth_post_left_index', 'undergrowth_forget_scores_below_minimum' );
// After the deleted post's vector is gone.
add_action( 'deleted_post', 'undergrowth_forget_scores_below_minimum', 20, 2 );

/**
 * Lists the providers that keep the fixed thresholds whatever their scores.
 *
 * @return string[] Provider names.
 */
function undergrowth_fixed_threshold_providers(): array {
	/**
	 * Filters the providers that keep the fixed thresholds.
	 *
	 * Their scores are still measured, so the numbers can be checked before
	 * a provider is taken off this list.
	 *
	 * @param string[] $providers Provider names. Default array( 'openai' ).
	 */
	$providers = apply_filters( 'undergrowth_fixed_threshold_providers', UNDERGROWTH_FIXED_THRESHOLD_PROVIDERS );

	return is_array( $providers ) ? array_map( 'strval', $providers ) : UNDERGROWTH_FIXED_THRESHOLD_PROVIDERS;
}

/**
 * Returns a threshold measured from the site's own scores, when one applies.
 *
 * @param string $name 'link', 'cluster' or 'gap'.
 * @return float|null The measured threshold, or null when the fixed one applies.
 */
function undergrowth_derived_threshold( string $name ): ?float {
	$scores   = get_option( 'undergrowth_scores' );
	$identity = undergrowth_embed_identity();

	if (
		! is_array( $scores )
		|| ( $scores['provider'] ?? '' ) !== $identity['provider']
		|| ( $scores['model'] ?? '' ) !== $identity['model']
		|| (int) ( $scores['posts'] ?? 0 ) < UNDERGROWTH_DERIVED_MIN_POSTS
		|| in_array( $identity['provider'], undergrowth_fixed_threshold_providers(), true )
		|| ! is_numeric( $scores[ $name ] ?? null )
	) {
		return null;
	}

	return (float) $scores[ $name ];
}

/**
 * Measures how the site's posts score against each other, and stores the
 * percentiles the link and cluster thresholds come from.
 *
 * Only vectors from the current provider and model count, and only pairs in
 * one language and of one length are scored. A large site is sampled, both
 * the vectors kept and the pairs scored, so the cost stays the same however
 * many posts it has.
 */
function undergrowth_measure_scores(): void {
	global $wpdb;

	$identity = undergrowth_embed_identity();

	if ( ! undergrowth_identity_is_complete( $identity ) ) {
		return;
	}

	$groups = array();
	$seen   = array();
	$after  = 0;

	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; vectors change on every embed.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT post_id, language, embedding FROM %i WHERE provider = %s AND model = %s AND post_id > %d ORDER BY post_id LIMIT %d',
				undergrowth_vectors_table(),
				$identity['provider'],
				$identity['model'],
				$after,
				UNDERGROWTH_VECTOR_CHUNK
			),
			ARRAY_A
		);

		$fetched = count( $rows );

		foreach ( $rows as $row ) {
			$after  = (int) $row['post_id'];
			$packed = undergrowth_unit_vector( undergrowth_decode_embedding( (string) $row['embedding'] ) );

			// Vectors of another length, from a change of model settings,
			// cannot be compared either.
			if ( '' !== $packed ) {
				$group            = $row['language'] . "\n" . strlen( $packed );
				$seen[ $group ]   = ( $seen[ $group ] ?? 0 ) + 1;
				$groups[ $group ] = $groups[ $group ] ?? array();

				undergrowth_keep_in_reservoir( $groups[ $group ], $packed, $seen[ $group ], UNDERGROWTH_SCORE_VECTORS );
			}
		}

		unset( $rows );
		$wpdb->flush();
	} while ( UNDERGROWTH_VECTOR_CHUNK === $fetched );

	$weights = array();

	foreach ( $seen as $count ) {
		$weights[] = $count * ( $count - 1 ) / 2;
	}

	$scores      = undergrowth_sample_scores( array_values( $groups ), UNDERGROWTH_SCORE_SAMPLE, $weights );
	$percentiles = array();

	foreach ( UNDERGROWTH_RECORDED_PERCENTILES as $percent ) {
		$percentiles[ (string) $percent ] = array() === $scores ? null : undergrowth_score_percentile( $scores, $percent / 100 );
	}

	update_option(
		'undergrowth_scores',
		array(
			'provider'    => $identity['provider'],
			'model'       => $identity['model'],
			'posts'       => array_sum( $seen ),
			'pairs'       => count( $scores ),
			'percentiles' => $percentiles,
			'link'        => array() === $scores ? null : undergrowth_score_percentile( $scores, UNDERGROWTH_LINK_PERCENTILE ),
			'cluster'     => array() === $scores ? null : undergrowth_score_percentile( $scores, UNDERGROWTH_CLUSTER_PERCENTILE ),
			'gap'         => null,
		)
	);
}

/**
 * Keeps an even random sample of the items seen so far, at most a limit.
 *
 * Each item, once seen, has the same chance of being kept, however many
 * come after it.
 *
 * @param string[] $kept  Items kept so far. Changed in place.
 * @param string   $item  The item just seen.
 * @param int      $seen  How many items have been seen, counting this one.
 * @param int      $limit Most items to keep.
 */
function undergrowth_keep_in_reservoir( array &$kept, string $item, int $seen, int $limit ): void {
	if ( count( $kept ) < $limit ) {
		$kept[] = $item;
		return;
	}

	$slot = wp_rand( 0, $seen - 1 );

	if ( $slot < $limit ) {
		$kept[ $slot ] = $item;
	}
}

/**
 * Forgets the measured scores once the site has too few posts to trust them.
 *
 * A site with more posts left keeps them until the next cycle measures again.
 *
 * @param int          $post_id Post that left the index or was deleted. Unused.
 * @param WP_Post|null $post    The deleted post, when core's hook passes it.
 */
function undergrowth_forget_scores_below_minimum( $post_id = 0, ?WP_Post $post = null ): void {
	global $wpdb;

	if ( undergrowth_ignores_post( $post ) ) {
		return;
	}

	$scores = get_option( 'undergrowth_scores' );

	if ( ! is_array( $scores ) ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; vectors change on every embed.
	$posts = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE provider = %s AND model = %s',
			undergrowth_vectors_table(),
			(string) ( $scores['provider'] ?? '' ),
			(string) ( $scores['model'] ?? '' )
		)
	);

	if ( $posts < UNDERGROWTH_DERIVED_MIN_POSTS ) {
		delete_option( 'undergrowth_scores' );
	}
}

/**
 * Scores pairs of vectors, every pair when there are few and a random
 * sample when there are many.
 *
 * @param array<int, string[]> $groups  Packed unit vectors, grouped so that only vectors in one group are compared.
 * @param int                  $limit   Most pairs to score.
 * @param array<int, float>    $weights Pairs each group stands for, keyed like $groups, when the groups
 *                                      are themselves samples. Default: the pairs they hold.
 * @return float[] Scores, lowest first.
 */
function undergrowth_sample_scores( array $groups, int $limit, array $weights = array() ): array {
	$sizes = array();

	foreach ( $groups as $index => $group ) {
		$count = count( $group );

		if ( $count > 1 ) {
			$sizes[ $index ] = $count * ( $count - 1 ) / 2;
		}
	}

	$total  = array_sum( $sizes );
	$scores = array();

	if ( $total <= $limit ) {
		foreach ( array_keys( $sizes ) as $index ) {
			$group = array_values( $groups[ $index ] );

			for ( $i = 0, $count = count( $group ); $i < $count; $i++ ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$scores[] = undergrowth_packed_dot( $group[ $i ], $group[ $j ] );
				}
			}
		}
	} else {
		// A group sampled down to its reservoir still counts for all its pairs.
		$odds = array();

		foreach ( $sizes as $index => $pairs ) {
			$odds[ $index ] = (float) ( $weights[ $index ] ?? $pairs );
		}

		$total = array_sum( $odds ) >= 1 ? array_sum( $odds ) : $total;
		$odds  = array_sum( $odds ) >= 1 ? $odds : $sizes;

		for ( $n = 0; $n < $limit; $n++ ) {
			$group = array_values( $groups[ undergrowth_pick_group( $odds, wp_rand( 1, (int) $total ) ) ] );
			$i     = wp_rand( 0, count( $group ) - 1 );
			$j     = wp_rand( 0, count( $group ) - 2 );
			$j    += $j >= $i ? 1 : 0;

			$scores[] = undergrowth_packed_dot( $group[ $i ], $group[ $j ] );
		}
	}

	sort( $scores );

	return $scores;
}

/**
 * Picks a group in proportion to the pairs it holds.
 *
 * @param array<int, float> $sizes Pairs in each group, keyed by group. At least one.
 * @param int               $pick  A number from 1 to the total of pairs.
 * @return int The group's key.
 */
function undergrowth_pick_group( array $sizes, int $pick ): int {
	foreach ( $sizes as $index => $pairs ) {
		$pick -= (int) $pairs;

		if ( $pick <= 0 ) {
			return $index;
		}
	}

	return (int) array_key_last( $sizes );
}

/**
 * Scores two packed unit vectors the way clustering compares them.
 *
 * @param string $a Packed unit vector.
 * @param string $b Packed unit vector.
 * @return float Cosine similarity.
 */
function undergrowth_packed_dot( string $a, string $b ): float {
	return undergrowth_dot_product( undergrowth_unpack_vector( $a ), undergrowth_unpack_vector( $b ) );
}

/**
 * Multiplies two unpacked vectors of the same length, value by value, and
 * adds up the products.
 *
 * Callers that compare one vector with many unpack it once and pass it here.
 *
 * @param float[] $a Vector values, in order.
 * @param float[] $b Vector values, in order.
 * @return float The dot product, which is the cosine similarity for unit vectors.
 */
function undergrowth_dot_product( array $a, array $b ): float {
	$dot = 0.0;

	foreach ( $b as $i => $value ) {
		$dot += $value * $a[ $i ];
	}

	return $dot;
}

/**
 * Reads the score at a share of the way up a sorted list.
 *
 * @param float[] $scores   Scores, lowest first. At least one.
 * @param float   $fraction Share of the way up, from 0 to 1.
 * @return float
 */
function undergrowth_score_percentile( array $scores, float $fraction ): float {
	return (float) $scores[ (int) floor( $fraction * ( count( $scores ) - 1 ) ) ];
}

/**
 * Finds the score below which a cluster's centre stands out from the others.
 *
 * A centroid sits closer to its members than any two members to each other,
 * so centre scores have a scale of their own. A gap is a cluster whose
 * nearest member is unusually far from its centre for this site: more than
 * one and a half times the spread of the middle half below its lower quarter.
 *
 * @param float[] $centres Each cluster's nearest member's similarity to its centre.
 * @return float|null The floor, or null when there are too few clusters to compare.
 */
function undergrowth_outlier_floor( array $centres ): ?float {
	if ( count( $centres ) < UNDERGROWTH_MIN_GAP_CLUSTERS ) {
		return null;
	}

	sort( $centres );

	$lower = undergrowth_score_percentile( $centres, 0.25 );
	$upper = undergrowth_score_percentile( $centres, 0.75 );

	return $lower - 1.5 * ( $upper - $lower );
}

/**
 * Stores the gap threshold measured from the clusters the cycle just made.
 */
function undergrowth_measure_gap_threshold(): void {
	$centres = array();

	foreach ( undergrowth_cluster_leaders() as $leader ) {
		$measured = undergrowth_measure_cluster( undergrowth_centre_members( $leader ) );

		if ( null !== $measured ) {
			$centres[] = $measured['centre'];
		}
	}

	// Read only now: the scores may have been forgotten while the clusters
	// were measured, and must not come back.
	$scores = get_option( 'undergrowth_scores' );

	if ( is_array( $scores ) ) {
		$scores['gap'] = undergrowth_outlier_floor( $centres );
		update_option( 'undergrowth_scores', $scores );
	}
}

/**
 * Describes the site's measured scores and the thresholds in use, line by line.
 *
 * The real-posts test reads these to compare a provider's spread with the
 * fixed numbers. See the `wp undergrowth scores` command.
 *
 * @return string[] Lines of plain text.
 */
function undergrowth_scores_report(): array {
	$scores = get_option( 'undergrowth_scores' );
	$lines  = array();

	if ( is_array( $scores ) ) {
		$lines[] = 'Provider: ' . $scores['provider'];
		$lines[] = 'Model: ' . $scores['model'];
		$lines[] = 'Posts: ' . $scores['posts'];
		$lines[] = 'Pairs scored: ' . $scores['pairs'];

		foreach ( (array) $scores['percentiles'] as $percent => $score ) {
			$lines[] = 'p' . $percent . ': ' . ( null === $score ? '-' : number_format( (float) $score, 3 ) );
		}
	} else {
		$lines[] = 'No scores measured yet. Run the cluster cycle: wp cron event run undergrowth_cluster_cycle';
	}

	$thresholds = array(
		'Link'    => array( 'link', undergrowth_min_link_similarity() ),
		'Cluster' => array( 'cluster', undergrowth_min_cluster_similarity() ),
		'Gap'     => array( 'gap', undergrowth_gap_similarity() ),
	);

	foreach ( $thresholds as $label => $threshold ) {
		$source  = null === undergrowth_derived_threshold( $threshold[0] ) ? 'fixed' : 'measured';
		$lines[] = $label . ' threshold in use: ' . number_format( $threshold[1], 3 ) . ' (' . $source . ')';
	}

	return $lines;
}
