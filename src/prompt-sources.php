<?php
/**
 * Where a prompt can start: reader questions in comments, gaps in the
 * author's own clusters, and composted posts, plus the sentences of the
 * author's own that each one quotes.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'undergrowth_prompt_sources', 'undergrowth_comment_prompt_sources' );
add_filter( 'undergrowth_prompt_sources', 'undergrowth_gap_prompt_sources', 15 );
add_filter( 'undergrowth_prompt_sources', 'undergrowth_compost_prompt_sources', 20 );

/**
 * Collects the source records a prompt may be written from, leaving out
 * any that has been sent before.
 *
 * @return array<int, array{source: string, key: string, post_id: int, posts: int[], title: string, quotes: string[], data: string, exact?: bool, rest?: bool}> Records, best first.
 */
function undergrowth_prompt_candidates(): array {
	/**
	 * Filters the sources a prompt may be written from, best first.
	 *
	 * Each record names its source, a key that is the same every time the
	 * same thing is found, the post it is about, any other posts its
	 * sentences come from, exact sentences from those posts, and any text
	 * from someone other than the author, which is sent only as data.
	 *
	 * @param array<int, array<string, mixed>> $records Source records.
	 */
	$records = apply_filters( 'undergrowth_prompt_sources', array() );
	$clean   = array();
	$seen    = undergrowth_sent_prompt_keys();

	foreach ( (array) $records as $record ) {
		$record = undergrowth_clean_prompt_record( $record );

		if ( null === $record || isset( $seen[ $record['key'] ] ) ) {
			continue;
		}

		$seen[ $record['key'] ] = true;
		$clean[]                = $record;
	}

	return $clean;
}

/**
 * Returns the key of every source already sent, on any post.
 *
 * A key is kept on the post its prompt was about, but a gap's nearest post
 * can change while the gap stays the same, so keys are looked up across
 * all posts.
 *
 * @return array<string, true> Keys, as array keys.
 */
function undergrowth_sent_prompt_keys(): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One query for every key; a few are added each month.
	$keys = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT meta_value FROM %i WHERE meta_key = %s', $wpdb->postmeta, 'undergrowth_prompt_keys' ) );

	return array_fill_keys( array_map( 'strval', $keys ), true );
}

/**
 * Checks the shape of a source record from the filter.
 *
 * @param mixed $record Record from the filter.
 * @return array{source: string, key: string, post_id: int, posts: int[], title: string, quotes: string[], data: string, exact?: bool, rest?: bool}|null The record, or null when it is unusable.
 */
function undergrowth_clean_prompt_record( $record ): ?array {
	if ( ! is_array( $record ) || ! is_string( $record['source'] ?? null ) || ! is_string( $record['key'] ?? null ) || '' === $record['key'] ) {
		return null;
	}

	$post = get_post( (int) ( $record['post_id'] ?? 0 ) );

	if ( ! $post instanceof WP_Post || ! undergrowth_is_indexable( $post ) ) {
		return null;
	}

	$quotes = array_values( array_filter( (array) ( $record['quotes'] ?? array() ), 'is_string' ) );
	$posts  = array_values( array_unique( array_merge( array( $post->ID ), array_filter( array_map( 'intval', (array) ( $record['posts'] ?? array() ) ) ) ) ) );

	return array(
		'source'  => sanitize_key( $record['source'] ),
		'key'     => $record['key'],
		'post_id' => $post->ID,
		'posts'   => $posts,
		'title'   => $post->post_title,
		'quotes'  => $quotes,
		'data'    => is_string( $record['data'] ?? null ) ? mb_substr( $record['data'], 0, UNDERGROWTH_PROMPT_DATA_CHARS, 'UTF-8' ) : '',
		'exact'   => ! empty( $record['exact'] ),
		'rest'    => ! empty( $record['rest'] ),
	);
}

/**
 * Finds reader questions in recent approved comments.
 *
 * @param mixed $records Records found so far.
 * @return array<int, array<string, mixed>> Records with reader questions added.
 */
function undergrowth_comment_prompt_sources( $records ): array {
	$records  = (array) $records;
	$comments = get_comments(
		array(
			'status'      => 'approve',
			'type'        => 'comment',
			'post_status' => 'publish',
			'post_type'   => undergrowth_indexed_post_types(),
			'number'      => UNDERGROWTH_PROMPT_COMMENTS,
			'orderby'     => 'comment_date_gmt',
			'order'       => 'DESC',
		)
	);

	foreach ( $comments as $comment ) {
		if ( ! $comment instanceof WP_Comment ) {
			continue;
		}

		$post = get_post( (int) $comment->comment_post_ID );
		$text = undergrowth_plain_line( $comment->comment_content );

		if ( ! $post instanceof WP_Post || ! undergrowth_is_indexable( $post ) || undergrowth_is_post_author_comment( $comment, $post ) || ! undergrowth_has_question_mark( $text, undergrowth_prompt_language( $post->ID ) ) ) {
			continue;
		}

		$quote     = undergrowth_closest_sentence( $post, $text );
		$records[] = array(
			'source'  => 'comment',
			'key'     => 'comment:' . $comment->comment_ID,
			'post_id' => $post->ID,
			'quotes'  => null === $quote ? array() : array( $quote ),
			'data'    => $text,
		);
	}

	return $records;
}

/**
 * Offers gaps in the author's own clusters as sources.
 *
 * Each record is about the post nearest the gap and quotes one sentence
 * from each of the posts nearest it, all the author's own. Its key names
 * the cluster's members, so the same gap is asked about once, and a
 * cluster that changes can be asked about again.
 *
 * @param mixed $records Records found so far.
 * @return array<int, array<string, mixed>> Records with gaps added.
 */
function undergrowth_gap_prompt_sources( $records ): array {
	$records = (array) $records;

	foreach ( undergrowth_cluster_gaps() as $gap ) {
		$quotes = array();
		$posts  = array();

		// The nearest posts that have a sentence of their own, so a post made
		// only of quotes never leaves the gap with fewer of the author's words.
		foreach ( $gap['members'] as $post_id ) {
			$post  = get_post( $post_id );
			$quote = $post instanceof WP_Post ? undergrowth_longest_sentence( $post ) : null;

			if ( null !== $quote ) {
				$quotes[] = $quote;
				$posts[]  = $post_id;
			}

			if ( count( $quotes ) >= UNDERGROWTH_PROMPT_GAP_POSTS ) {
				break;
			}
		}

		if ( array() === $quotes ) {
			continue;
		}

		$records[] = array(
			'source'  => 'gap',
			'key'     => 'gap:' . hash( 'sha256', implode( ',', $gap['cluster'] ) ),
			'post_id' => $gap['members'][0],
			'posts'   => $posts,
			'quotes'  => $quotes,
		);
	}

	return $records;
}

/**
 * Offers composted posts as sources, oldest first.
 *
 * A composted post rests, but its words stay the author's own. The record
 * holds one sentence from it and nothing else: no date, and nothing that
 * would turn the question into a nudge to bring the post back.
 *
 * @param mixed $records Records found so far.
 * @return array<int, array<string, mixed>> Records with composted posts added.
 */
function undergrowth_compost_prompt_sources( $records ): array {
	$records = (array) $records;
	$found   = 0;

	// Posts already sent stay in the query, so it pages past them until it
	// has enough new ones, within a fixed number of pages.
	for ( $page = 1; $page <= UNDERGROWTH_PROMPT_PAGES; $page++ ) {
		$posts = get_posts(
			undergrowth_garden_query_args(
				array(
					// Run once a month, for a handful of posts.
					'meta_query'             => undergrowth_composting_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'orderby'                => array(
						'date' => 'ASC',
						'ID'   => 'ASC',
					),
					'numberposts'            => UNDERGROWTH_PROMPT_COMPOSTED,
					'paged'                  => $page,
					'fields'                 => 'all',
					'update_post_term_cache' => true,
					'suppress_filters'       => false,
				)
			)
		);

		foreach ( $posts as $post ) {
			$key   = 'compost:' . $post->ID;
			$quote = in_array( $key, get_post_meta( $post->ID, 'undergrowth_prompt_keys', false ), true ) ? null : undergrowth_longest_sentence( $post );

			// The question must rest on the author's own words, so a post
			// with none of its own is no source.
			if ( null === $quote ) {
				continue;
			}

			$records[] = array(
				'source'  => 'compost',
				'key'     => $key,
				'post_id' => $post->ID,
				'quotes'  => array( $quote ),
				'exact'   => true,
				'rest'    => true,
			);

			if ( ++$found >= UNDERGROWTH_PROMPT_COMPOSTED ) {
				return $records;
			}
		}

		if ( count( $posts ) < UNDERGROWTH_PROMPT_COMPOSTED ) {
			break;
		}
	}

	return $records;
}

/**
 * Picks the longest of the author's own sentences near the start of a post.
 *
 * @param WP_Post $post Post to choose from.
 * @return string|null The sentence, or null when the post has none of its own.
 */
function undergrowth_longest_sentence( WP_Post $post ): ?string {
	$best = null;
	$most = 0;

	$own = array_filter(
		undergrowth_split_sentences( $post->post_content ),
		static function ( $sentence ) {
			return ! $sentence['quoted'];
		}
	);

	foreach ( array_slice( $own, 0, UNDERGROWTH_PROMPT_SENTENCES ) as $sentence ) {
		$words = count( undergrowth_tokens( $sentence['text'] ) );

		if ( null === $best || $words > $most ) {
			$most = $words;
			$best = $sentence['text'];
		}
	}

	return $best;
}

/**
 * Tells whether a comment was left by the author of the post it is on.
 *
 * @param WP_Comment $comment Comment.
 * @param WP_Post    $post    Post the comment is on.
 * @return bool True when the post's author wrote it.
 */
function undergrowth_is_post_author_comment( WP_Comment $comment, WP_Post $post ): bool {
	if ( (int) $comment->user_id > 0 && (int) $comment->user_id === (int) $post->post_author ) {
		return true;
	}

	$author = get_userdata( (int) $post->post_author );

	return $author instanceof WP_User && '' !== $comment->comment_author_email && 0 === strcasecmp( $comment->comment_author_email, $author->user_email );
}

/**
 * Picks the author's own sentence that shares the most words with a text.
 *
 * Sentences inside a quote or a Custom HTML block are someone else's words
 * and are never chosen.
 *
 * @param WP_Post $post Post to choose from.
 * @param string  $text Text to compare with.
 * @return string|null The sentence, or null when none shares a word.
 */
function undergrowth_closest_sentence( WP_Post $post, string $text ): ?string {
	$words = array_flip( undergrowth_tokens( $text ) );
	$best  = null;
	$most  = 0;

	foreach ( undergrowth_split_sentences( $post->post_content ) as $sentence ) {
		if ( $sentence['quoted'] ) {
			continue;
		}

		$shared = count( array_intersect_key( array_flip( undergrowth_tokens( $sentence['text'] ) ), $words ) );

		if ( $shared > $most ) {
			$most = $shared;
			$best = $sentence['text'];
		}
	}

	return $best;
}
