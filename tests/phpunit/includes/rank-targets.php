<?php
/**
 * The ranking the tests compare proposals against.
 *
 * @package Undergrowth
 */

/**
 * Picks the posts a post should link to, as proposals rank them.
 *
 * Leaves out the post itself, posts it already links to, composting posts,
 * and any pair the author has accepted or vetoed. A composting post gets
 * no targets at all.
 *
 * @param int                     $post_id Source post ID.
 * @param array<int, string>|null $pool    Packed comparable vectors, when already loaded.
 * @param int                     $limit   Most targets to return, or 0 for every target above the minimum.
 * @return array<int, float> Similarity keyed by target post ID, best first.
 */
function undergrowth_test_rank_targets( int $post_id, ?array $pool = null, int $limit = UNDERGROWTH_MAX_PROPOSALS ): array {
	$targets = array();

	foreach ( undergrowth_open_targets( undergrowth_score_targets( $post_id, $pool ) ) as $target_id => $score ) {
		$targets[ $target_id ] = $score;

		if ( $limit > 0 && count( $targets ) >= $limit ) {
			break;
		}
	}

	return $targets;
}
