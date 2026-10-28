<?php
/**
 * Cross-pollination suggestions: a link offered between two posts of a
 * sweet-spot pair, on top of a post's own suggestions.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

// A new step on the surprise scale moves the band the cross-pollination
// suggestions come from.
add_action( 'add_option_undergrowth_surprise', 'undergrowth_refresh_cross_links' );
add_action( 'update_option_undergrowth_surprise', 'undergrowth_refresh_cross_links' );

/**
 * Lists, for each post in a sweet-spot pair at the author's step, the posts
 * at the other ends, as the cross-pollination links it may be offered one of.
 *
 * Kept for the request, since every refresh asks and building the pairs
 * measures every group. New groups, words, pairs, a new step or a
 * dismissal make a new list.
 *
 * @return array<int, array<int, array{0: int, 1: float, 2: string[]}>> For each source post ID,
 *         its targets, closest first, each with the two posts' similarity and the words both groups share.
 */
function undergrowth_cross_links(): array {
	static $cache = array();

	$key = md5( (string) wp_json_encode( array( get_option( 'undergrowth_cluster_labels', array() ), undergrowth_surprise_step(), undergrowth_surprise_band( undergrowth_surprise_step() ), get_option( 'undergrowth_pollination_dismissed', array() ), get_option( 'undergrowth_pollination', array() ) ) ) );

	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$links = array();

	foreach ( undergrowth_pollination_pairs() as $pair ) {
		if ( null === $pair['sweet'] ) {
			continue;
		}

		list( $a, $b, $score ) = $pair['sweet'];

		$links[ $a ][] = array( $b, $score, $pair['threads'] );
		$links[ $b ][] = array( $a, $score, $pair['threads'] );
	}

	foreach ( $links as $source => $targets ) {
		// Closest first; equally close targets in ID order.
		usort(
			$targets,
			static function ( $x, $y ) {
				$order = $y[1] <=> $x[1];

				return 0 !== $order ? $order : $x[0] <=> $y[0];
			}
		);

		$links[ $source ] = $targets;
	}

	ksort( $links );
	$cache = array( $key => $links );

	return $links;
}

/**
 * Returns the cross-pollination link a post may be offered on top of its
 * usual suggestions, if any: the closest of its targets that can take one.
 *
 * None for a composting post. None to a post it already links to, already
 * suggests, or whose link the author accepted or vetoed, and none to a post
 * that cannot receive a link or is outside the post's comparison space.
 *
 * @param int        $post_id    Source post ID.
 * @param int[]      $taken      Targets the post's usual suggestions already hold.
 * @param int[]|null $comparable Posts whose vectors compare with this post's, or null for any.
 * @return array{0: int, 1: float}|null The target and the two posts' similarity, or null.
 */
function undergrowth_cross_link_target( int $post_id, array $taken, ?array $comparable = null ): ?array {
	$post  = get_post( $post_id );
	$links = undergrowth_cross_links();

	if ( ! isset( $links[ $post_id ] ) || ! $post instanceof WP_Post || ! undergrowth_is_indexable( $post ) || 'composting' === undergrowth_get_stage( $post_id ) ) {
		return null;
	}

	$taken  = array_map( 'intval', $taken );
	$linked = undergrowth_linked_post_ids( $post );
	$space  = null === $comparable ? null : array_flip( array_map( 'intval', $comparable ) );

	foreach ( $links[ $post_id ] as $candidate ) {
		list( $target, $score ) = $candidate;

		if ( ( null !== $space && ! isset( $space[ $target ] ) ) || in_array( $target, $taken, true ) || in_array( $target, $linked, true ) ) {
			continue;
		}

		if ( in_array( undergrowth_link_state( $post_id, $target ), array( 'accepted', 'vetoed' ), true ) || ! isset( undergrowth_open_target_ids( array( $target ) )[ $target ] ) ) {
			continue;
		}

		return array( $target, $score );
	}

	return null;
}

/**
 * Refreshes the suggestions of every post that holds a cross-pollination
 * suggestion or could be offered one, from cron, after the pairs change.
 */
function undergrowth_refresh_cross_links(): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every refresh.
	$holding = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT source_id FROM %i WHERE state = %s AND kind = %s', undergrowth_links_table(), 'proposed', 'cross' ) );
	$posts   = array_values( array_unique( array_merge( array_map( 'intval', $holding ), array_keys( undergrowth_cross_links() ) ) ) );

	if ( array() !== $posts ) {
		sort( $posts );
		wp_schedule_single_event( time(), 'undergrowth_refresh_sources', array( $posts ) );
	}
}

/**
 * Says why a suggestion is a cross-pollination link, in the author's own
 * words where both groups share some.
 *
 * @param int $source_id Source post ID.
 * @param int $target_id Target post ID.
 * @return string Paragraph HTML, escaped.
 */
function undergrowth_cross_link_label( int $source_id, int $target_id ): string {
	$threads = array();

	foreach ( undergrowth_cross_links()[ $source_id ] ?? array() as $candidate ) {
		if ( $target_id === $candidate[0] ) {
			$threads = $candidate[2];
			break;
		}
	}

	$reason = array() === $threads
		? esc_html__( 'These posts are in groups that sit apart, but they are not far apart themselves.', 'undergrowth' )
		: sprintf(
			/* translators: %1$s: a list of words from the posts, such as "oca, frost, and ledger". */
			esc_html__( 'These posts are in groups that sit apart, and both groups use %1$s.', 'undergrowth' ),
			wp_sprintf_l(
				'%l',
				array_map(
					static function ( $term ) {
						return '<bdi>' . esc_html( $term ) . '</bdi>';
					},
					$threads
				)
			)
		);

	return '<p class="undergrowth-cross"><strong>' . esc_html__( 'Cross-pollination.', 'undergrowth' ) . '</strong> ' . $reason . '</p>';
}
