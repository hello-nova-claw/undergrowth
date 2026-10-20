<?php
/**
 * Self-trade: proposing links between an author's own posts.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_MAX_PROPOSALS        = 3;
const UNDERGROWTH_MIN_LINK_SIMILARITY  = 0.4;
const UNDERGROWTH_NEIGHBOURS_TO_UPDATE = 10;

add_action( 'undergrowth_post_embedded', 'undergrowth_refresh_around' );
add_action( 'undergrowth_post_left_index', 'undergrowth_forget_post_links' );
add_action( 'deleted_post', 'undergrowth_forget_post_links' );

/**
 * Tells whether two vectors can be compared at all.
 *
 * Vectors of different lengths, or with no magnitude, have no meaningful
 * similarity. Checking this apart from the score keeps them out even when
 * the minimum similarity is set at or below zero.
 *
 * @param float[] $a First vector.
 * @param float[] $b Second vector.
 * @return bool True when both have the same length and some magnitude.
 */
function undergrowth_vectors_comparable( array $a, array $b ): bool {
	if ( array() === $a || count( $a ) !== count( $b ) ) {
		return false;
	}

	return array_filter( $a ) !== array() && array_filter( $b ) !== array();
}

/**
 * Measures how alike two vectors are.
 *
 * @param float[] $a First vector.
 * @param float[] $b Second vector.
 * @return float Cosine similarity, or 0 when the vectors cannot be compared.
 */
function undergrowth_cosine( array $a, array $b ): float {
	$count = count( $a );

	if ( 0 === $count || count( $b ) !== $count ) {
		return 0.0;
	}

	$dot    = 0.0;
	$norm_a = 0.0;
	$norm_b = 0.0;

	for ( $i = 0; $i < $count; $i++ ) {
		$dot    += $a[ $i ] * $b[ $i ];
		$norm_a += $a[ $i ] * $a[ $i ];
		$norm_b += $b[ $i ] * $b[ $i ];
	}

	if ( 0.0 === $norm_a || 0.0 === $norm_b ) {
		return 0.0;
	}

	return $dot / sqrt( $norm_a * $norm_b );
}

/**
 * Returns the lowest similarity worth proposing a link for.
 *
 * @return float Cosine similarity between -1 and 1.
 */
function undergrowth_min_link_similarity(): float {
	/**
	 * Filters the lowest similarity worth proposing a link for.
	 *
	 * Different embedding models spread their scores differently, so a site
	 * using its own provider may need to tune this.
	 *
	 * @param float $similarity Cosine similarity. Default 0.4.
	 */
	$similarity = apply_filters( 'undergrowth_min_link_similarity', UNDERGROWTH_MIN_LINK_SIMILARITY );

	return is_numeric( $similarity ) ? max( -1.0, min( 1.0, (float) $similarity ) ) : UNDERGROWTH_MIN_LINK_SIMILARITY;
}

/**
 * Loads every stored vector that can be compared with a post's vector.
 *
 * Only vectors from the same provider, model and language qualify, because
 * scores across models or languages mean nothing.
 *
 * @param int $post_id Post whose vector sets the provider, model and language.
 * @return array<int, float[]> Vectors keyed by post ID, including the post's own.
 */
function undergrowth_comparable_vectors( int $post_id ): array {
	global $wpdb;

	$source = undergrowth_get_vector( $post_id );

	if ( null === $source ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; vectors change on every embed.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT post_id, embedding FROM %i WHERE provider = %s AND model = %s AND language = %s',
			undergrowth_vectors_table(),
			$source['provider'],
			$source['model'],
			$source['language']
		),
		ARRAY_A
	);

	$vectors = array();

	foreach ( (array) $rows as $row ) {
		$vector = undergrowth_sanitize_vector( json_decode( (string) $row['embedding'], true ) );

		if ( array() !== $vector ) {
			$vectors[ (int) $row['post_id'] ] = $vector;
		}
	}

	return $vectors;
}

/**
 * Lists the posts a post already links to.
 *
 * @param WP_Post $post Post to read.
 * @return int[] Post IDs of internal link targets.
 */
function undergrowth_linked_post_ids( WP_Post $post ): array {
	if ( ! preg_match_all( '/href\s*=\s*["\']([^"\']+)["\']/i', $post->post_content, $matches ) ) {
		return array();
	}

	$ids = array();

	foreach ( array_unique( $matches[1] ) as $url ) {
		$id = url_to_postid( html_entity_decode( $url ) );

		if ( $id > 0 ) {
			$ids[] = $id;
		}
	}

	return $ids;
}

/**
 * Tells whether the author wants links between posts suggested.
 *
 * @return bool True unless the author turned suggestions off.
 */
function undergrowth_suggestions_enabled(): bool {
	return '0' !== get_option( 'undergrowth_suggest_links', '1' );
}

/**
 * Records that the author never wants a link suggested again.
 *
 * The veto is stored even when the suggestion has meanwhile been withdrawn
 * by a refresh, because the author's choice outlives any one suggestion. An
 * accepted link is left alone: undoing it is a separate act.
 *
 * @param int $source_id Post the link would sit in.
 * @param int $target_id Post the link would point to.
 * @return bool True when the pair is vetoed afterwards.
 */
function undergrowth_veto_link( int $source_id, int $target_id ): bool {
	global $wpdb;

	if ( ! get_post( $source_id ) instanceof WP_Post || ! get_post( $target_id ) instanceof WP_Post ) {
		return false;
	}

	$state = undergrowth_link_state( $source_id, $target_id );
	$now   = current_time( 'mysql', true );

	if ( 'accepted' === $state ) {
		return false;
	}

	if ( 'proposed' === $state ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
		$wpdb->update(
			undergrowth_links_table(),
			array(
				'state'      => 'vetoed',
				'updated_at' => $now,
			),
			array(
				'source_id' => $source_id,
				'target_id' => $target_id,
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);
	} elseif ( '' === $state ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table has no core API.
		$wpdb->insert(
			undergrowth_links_table(),
			array(
				'source_id'  => $source_id,
				'target_id'  => $target_id,
				'state'      => 'vetoed',
				'score'      => 0,
				'sentence'   => '',
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%f', '%s', '%s', '%s' )
		);
	}

	undergrowth_write_memory( $source_id );

	return 'vetoed' === undergrowth_link_state( $source_id, $target_id );
}

/**
 * Returns the stored state of a link between two posts.
 *
 * @param int $source_id Post the link would sit in.
 * @param int $target_id Post the link would point to.
 * @return string 'proposed', 'accepted', 'vetoed', or an empty string when there is no row.
 */
function undergrowth_link_state( int $source_id, int $target_id ): string {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; states change on every refresh.
	$state = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT state FROM %i WHERE source_id = %d AND target_id = %d',
			undergrowth_links_table(),
			$source_id,
			$target_id
		)
	);

	return is_string( $state ) ? $state : '';
}

/**
 * Picks the posts a post should link to.
 *
 * Leaves out the post itself, posts it already links to, composting posts,
 * and any pair the author has accepted or vetoed. A composting post gets
 * no targets at all.
 *
 * @param int                      $post_id Source post ID.
 * @param array<int, float[]>|null $pool    Comparable vectors, when already loaded.
 * @param int                      $limit   Most targets to return, or 0 for every target above the minimum.
 * @return array<int, float> Similarity keyed by target post ID, best first.
 */
function undergrowth_rank_targets( int $post_id, ?array $pool = null, int $limit = UNDERGROWTH_MAX_PROPOSALS ): array {
	global $wpdb;

	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || ! undergrowth_is_indexable( $post ) ) {
		return array();
	}

	// A composting post rests outside the active garden: no links out of it.
	if ( 'composting' === undergrowth_get_stage( $post_id ) ) {
		return array();
	}

	$pool = $pool ?? undergrowth_comparable_vectors( $post_id );

	if ( ! isset( $pool[ $post_id ] ) ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; states change on every refresh.
	$decided = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT target_id FROM %i WHERE source_id = %d AND state IN (%s, %s)',
			undergrowth_links_table(),
			$post_id,
			'accepted',
			'vetoed'
		)
	);

	$excluded = array_flip( array_merge( array( $post_id ), undergrowth_linked_post_ids( $post ), array_map( 'intval', $decided ) ) );
	$minimum  = undergrowth_min_link_similarity();
	$scores   = array();

	foreach ( $pool as $target_id => $vector ) {
		if ( isset( $excluded[ $target_id ] ) || ! undergrowth_vectors_comparable( $pool[ $post_id ], $vector ) ) {
			continue;
		}

		$score = undergrowth_cosine( $pool[ $post_id ], $vector );

		if ( $score < $minimum ) {
			continue;
		}

		// A vector can outlive its post's publication when the status changes
		// without a save, so the target itself is checked as well.
		$target = get_post( $target_id );

		// No links into a composting post either.
		if ( $target instanceof WP_Post && undergrowth_is_indexable( $target ) && 'composting' !== undergrowth_get_stage( $target_id ) ) {
			$scores[ $target_id ] = $score;
		}
	}

	arsort( $scores );

	return $limit > 0 ? array_slice( $scores, 0, $limit, true ) : $scores;
}

/**
 * Rewrites a post's proposed links to match its current best targets.
 *
 * A target only counts when the source post has a sentence where the link
 * fits, so the next best target takes the place of one without. Accepted
 * and vetoed rows are never touched.
 *
 * @param int                      $post_id Source post ID.
 * @param array<int, float[]>|null $pool    Comparable vectors, when already loaded.
 * @param array|null               $corpus  Word counts across the pool, when already built.
 *
 * @phpstan-param array{documents: int, frequency: array<string, int>, counts: array<int, array<string, int>>}|null $corpus
 */
function undergrowth_refresh_proposals( int $post_id, ?array $pool = null, ?array $corpus = null ): void {
	global $wpdb;

	// Turned off, nothing new is proposed. Rows already stored are kept, so
	// turning suggestions back on does not lose any veto.
	if ( ! undergrowth_suggestions_enabled() ) {
		return;
	}

	// A pool built for another post lacks this post's vector when the two
	// differ in language or model. Ranking against it would find nothing and
	// wipe this post's valid proposals, so it ranks against its own instead,
	// with word counts to match.
	if ( null !== $pool && ! isset( $pool[ $post_id ] ) ) {
		$pool   = null;
		$corpus = null;
	}

	$pool    = $pool ?? undergrowth_comparable_vectors( $post_id );
	$corpus  = $corpus ?? undergrowth_build_corpus( array_keys( $pool ) );
	$table   = undergrowth_links_table();
	$now     = current_time( 'mysql', true );
	$targets = array();

	foreach ( undergrowth_rank_targets( $post_id, $pool, 0 ) as $target_id => $score ) {
		$sentence = undergrowth_choose_sentence( $post_id, $target_id, $corpus );

		if ( null === $sentence ) {
			continue;
		}

		$targets[ $target_id ] = $score;
		$state                 = undergrowth_link_state( $post_id, $target_id );
		$fields                = array(
			'score'          => $score,
			'sentence'       => $sentence['text'],
			'sentence_index' => $sentence['index'],
			'updated_at'     => $now,
		);

		if ( 'proposed' === $state ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
			$wpdb->update(
				$table,
				$fields,
				array(
					'source_id' => $post_id,
					'target_id' => $target_id,
				),
				array( '%f', '%s', '%d', '%s' ),
				array( '%d', '%d' )
			);
		} elseif ( '' === $state ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table has no core API.
			$wpdb->insert(
				$table,
				array_merge(
					array(
						'source_id'  => $post_id,
						'target_id'  => $target_id,
						'state'      => 'proposed',
						'created_at' => $now,
					),
					$fields
				),
				array( '%d', '%d', '%s', '%s', '%f', '%s', '%d', '%s' )
			);
		}

		if ( count( $targets ) >= UNDERGROWTH_MAX_PROPOSALS ) {
			break;
		}
	}

	$keep = array_map( 'intval', array_keys( $targets ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; states change on every refresh.
	$current = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT target_id FROM %i WHERE source_id = %d AND state = %s',
			$table,
			$post_id,
			'proposed'
		)
	);

	foreach ( array_diff( array_map( 'intval', $current ), $keep ) as $stale_target ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
		$wpdb->delete(
			$table,
			array(
				'source_id' => $post_id,
				'target_id' => $stale_target,
				'state'     => 'proposed',
			),
			array( '%d', '%d', '%s' )
		);
	}

	/**
	 * Fires after a post's proposed links have been rewritten.
	 *
	 * @param int $post_id Source post ID.
	 */
	do_action( 'undergrowth_proposals_refreshed', $post_id );
}

/**
 * Refreshes proposals for a newly embedded post, for the posts already
 * proposing it, and for its nearest posts.
 *
 * Without the neighbours, an older post would only learn about a new one
 * the next time it was saved. Without the posts already proposing it, a post
 * that changed subject would keep links that no longer fit.
 *
 * @param int $post_id Post that was just embedded.
 */
function undergrowth_refresh_around( int $post_id ): void {
	global $wpdb;

	// Every refresh below would return at once, so skip loading the vectors.
	if ( ! undergrowth_suggestions_enabled() ) {
		return;
	}

	$pool = undergrowth_comparable_vectors( $post_id );

	if ( ! isset( $pool[ $post_id ] ) ) {
		return;
	}

	$corpus = undergrowth_build_corpus( array_keys( $pool ) );

	undergrowth_refresh_proposals( $post_id, $pool, $corpus );

	$nearest = array();

	foreach ( $pool as $other_id => $vector ) {
		if ( $other_id !== $post_id && undergrowth_vectors_comparable( $pool[ $post_id ], $vector ) ) {
			$nearest[ $other_id ] = undergrowth_cosine( $pool[ $post_id ], $vector );
		}
	}

	arsort( $nearest );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; states change on every refresh.
	$proposing = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT source_id FROM %i WHERE target_id = %d AND state = %s',
			undergrowth_links_table(),
			$post_id,
			'proposed'
		)
	);

	// Walk in rank order and stop at the limit, so posts only checked as far
	// as needed. A stale vector for an unpublished post would otherwise take
	// a place that a published neighbour needs.
	$neighbours = array();

	foreach ( array_keys( $nearest ) as $other_id ) {
		$other = get_post( $other_id );

		if ( $other instanceof WP_Post && undergrowth_is_indexable( $other ) ) {
			$neighbours[] = (int) $other_id;
		}

		if ( count( $neighbours ) >= UNDERGROWTH_NEIGHBOURS_TO_UPDATE ) {
			break;
		}
	}

	$to_refresh = array_unique( array_merge( array_map( 'intval', $proposing ), $neighbours ) );

	foreach ( $to_refresh as $neighbour_id ) {
		undergrowth_refresh_proposals( (int) $neighbour_id, $pool, $corpus );
	}
}

/**
 * Removes proposals to and from a post that has left the index, then lets
 * the posts that pointed at it pick a replacement.
 *
 * Vetoes and accepted links are kept, so a republished post never brings a
 * vetoed link back.
 *
 * @param int $post_id Post that was unpublished or deleted.
 */
function undergrowth_forget_post_links( int $post_id ): void {
	global $wpdb;

	$table = undergrowth_links_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; states change on every refresh.
	$sources = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT source_id FROM %i WHERE target_id = %d AND state = %s',
			$table,
			$post_id,
			'proposed'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE state = %s AND ( source_id = %d OR target_id = %d )',
			$table,
			'proposed',
			$post_id,
			$post_id
		)
	);

	// Sources sharing a provider, model and language share one pool, so the
	// same vectors are not loaded and decoded once per source.
	$pools = array();

	foreach ( array_map( 'intval', $sources ) as $source_id ) {
		$vector = undergrowth_get_vector( $source_id );

		if ( null === $vector ) {
			undergrowth_refresh_proposals( $source_id );
			continue;
		}

		$key = $vector['provider'] . '|' . $vector['model'] . '|' . $vector['language'];

		if ( ! isset( $pools[ $key ] ) ) {
			$pools[ $key ] = undergrowth_comparable_vectors( $source_id );
		}

		undergrowth_refresh_proposals( $source_id, $pools[ $key ] );
	}
}
