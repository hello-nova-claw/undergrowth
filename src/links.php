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
const UNDERGROWTH_VECTOR_CHUNK         = 100;
const UNDERGROWTH_POST_CHUNK           = 100;
const UNDERGROWTH_REFRESH_BATCH        = 20;

add_action( 'undergrowth_post_embedded', 'undergrowth_refresh_around' );
add_action( 'undergrowth_post_left_index', 'undergrowth_forget_post_links' );
add_action( 'deleted_post', 'undergrowth_forget_post_links', 10, 2 );
add_action( 'undergrowth_refresh_sources', 'undergrowth_refresh_sources' );
add_action( 'undergrowth_refresh_index', 'undergrowth_refresh_index' );

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
 * Packs a vector into four bytes a dimension.
 *
 * Embedding models produce single-precision floats, so packing keeps what
 * they sent in about a tenth of the memory a PHP array of floats takes. A
 * provider sending doubles loses precision beyond about seven digits, which
 * moves a score by around 1e-7 at most. Two targets closer than that may
 * swap places. Packing doubles instead would take twice the memory.
 *
 * @param float[] $vector The vector.
 * @return string Packed floats, or an empty string when a value is too large
 *                for single precision and would turn into infinity.
 */
function undergrowth_pack_vector( array $vector ): string {
	foreach ( $vector as $value ) {
		if ( abs( $value ) > 3.4028234663852886E+38 ) {
			return '';
		}
	}

	return pack( 'g*', ...$vector );
}

/**
 * Unpacks a vector packed by undergrowth_pack_vector().
 *
 * @param string $packed Packed floats.
 * @return float[] The vector.
 */
function undergrowth_unpack_vector( string $packed ): array {
	$vector = '' === $packed ? false : unpack( 'g*', $packed );

	return is_array( $vector ) ? array_values( $vector ) : array();
}

/**
 * Splits a list into chunks, building each one only when it is asked for.
 *
 * Unlike array_chunk(), which builds every chunk at once, this holds one
 * chunk at a time, so a small chunk size never costs more memory.
 *
 * @param iterable<int|string, mixed> $items         Items to split.
 * @param int                         $size          Items per chunk, at least one.
 * @param bool                        $preserve_keys Whether each chunk keeps the items' keys.
 * @return Generator<int, array<int|string, mixed>> Chunks, in order.
 */
function undergrowth_chunks( iterable $items, int $size, bool $preserve_keys = false ): Generator {
	$size  = max( 1, $size );
	$chunk = array();

	foreach ( $items as $key => $item ) {
		if ( $preserve_keys ) {
			$chunk[ $key ] = $item;
		} else {
			$chunk[] = $item;
		}

		if ( count( $chunk ) === $size ) {
			yield $chunk;
			$chunk = array();
		}
	}

	if ( array() !== $chunk ) {
		yield $chunk;
	}
}

/**
 * Returns how many posts are read in one query when posts are read in chunks.
 *
 * @return int Posts per chunk, at least one.
 */
function undergrowth_post_chunk_size(): int {
	/**
	 * Filters how many posts are read in one query.
	 *
	 * A smaller chunk holds less in memory at once and sends more queries.
	 *
	 * @param int $size Posts per chunk. Default 100.
	 */
	return max( 1, (int) apply_filters( 'undergrowth_post_chunk_size', UNDERGROWTH_POST_CHUNK ) );
}

/**
 * Returns how many vectors are read in one query.
 *
 * @return int Vectors per chunk, at least one.
 */
function undergrowth_vector_chunk_size(): int {
	/**
	 * Filters how many stored vectors are read in one query.
	 *
	 * A smaller chunk holds less in memory at once and sends more queries.
	 *
	 * @param int $size Vectors per chunk. Default 100.
	 */
	return max( 1, (int) apply_filters( 'undergrowth_vector_chunk_size', UNDERGROWTH_VECTOR_CHUNK ) );
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
	 * @param float $similarity Cosine similarity. Default 0.4, or the site's
	 *                          40th percentile of pairwise scores once it is
	 *                          measured. See undergrowth_derived_threshold().
	 */
	$default    = undergrowth_derived_threshold( 'link' ) ?? UNDERGROWTH_MIN_LINK_SIMILARITY;
	$similarity = apply_filters( 'undergrowth_min_link_similarity', $default );

	return is_numeric( $similarity ) ? max( -1.0, min( 1.0, (float) $similarity ) ) : $default;
}

/**
 * Loads every stored vector that can be compared with a post's vector.
 *
 * Only vectors from the same provider, model and language qualify, because
 * scores across models or languages mean nothing.
 *
 * @param int $post_id Post whose vector sets the provider, model and language.
 * @return array<int, string> Packed vectors keyed by post ID, including the post's own.
 */
function undergrowth_comparable_vectors( int $post_id ): array {
	$source = undergrowth_get_vector( $post_id );

	return null === $source ? array() : undergrowth_vectors_in_space( $source['provider'], $source['model'], $source['language'] );
}

/**
 * Loads every stored vector from one provider, model and language.
 *
 * Rows are read a chunk at a time and kept packed, so a large archive never
 * sits in memory as JSON or as PHP arrays of floats.
 *
 * @param string $provider Provider name.
 * @param string $model    Model name.
 * @param string $language Locale, such as en_US.
 * @return array<int, string> Packed vectors keyed by post ID.
 */
function undergrowth_vectors_in_space( string $provider, string $model, string $language ): array {
	global $wpdb;

	$vectors = array();
	$after   = 0;
	$size    = undergrowth_vector_chunk_size();

	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; vectors change on every embed.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT post_id, embedding FROM %i WHERE provider = %s AND model = %s AND language = %s AND post_id > %d ORDER BY post_id LIMIT %d',
				undergrowth_vectors_table(),
				$provider,
				$model,
				$language,
				$after,
				$size
			),
			ARRAY_A
		);

		$fetched = count( $rows );

		foreach ( $rows as $row ) {
			$after  = (int) $row['post_id'];
			$packed = undergrowth_pack_vector( undergrowth_decode_embedding( (string) $row['embedding'] ) );

			if ( '' !== $packed ) {
				$vectors[ $after ] = $packed;
			}
		}

		// wpdb keeps the last result set until the next query, which would
		// leave a chunk of JSON behind for the whole refresh.
		unset( $rows );
		$wpdb->flush();
	} while ( $size === $fetched );

	return $vectors;
}

/**
 * Lists the posts whose vectors can be compared with a post's vector,
 * without reading the vectors.
 *
 * @param int $post_id Post whose vector sets the provider, model and language.
 * @return int[] Post IDs, in order, including the post's own.
 */
function undergrowth_comparable_post_ids( int $post_id ): array {
	global $wpdb;

	$source = undergrowth_get_vector_space( $post_id );

	if ( null === $source ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; vectors change on every embed.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT post_id FROM %i WHERE provider = %s AND model = %s AND language = %s ORDER BY post_id',
			undergrowth_vectors_table(),
			$source['provider'],
			$source['model'],
			$source['language']
		)
	);

	return array_map( 'intval', (array) $ids );
}

/**
 * Lists the posts a post already links to.
 *
 * The tag processor reads links as a browser does: an address without
 * quotes counts, and one inside a comment or another attribute does not.
 *
 * @param WP_Post $post Post to read.
 * @return int[] Post IDs of internal link targets.
 */
function undergrowth_linked_post_ids( WP_Post $post ): array {
	$html = new WP_HTML_Tag_Processor( $post->post_content );
	$urls = array();

	while ( $html->next_tag( array( 'tag_name' => 'A' ) ) ) {
		$href = $html->get_attribute( 'href' );

		if ( is_string( $href ) && '' !== $href ) {
			$urls[] = $href;
		}
	}

	$ids = array();

	foreach ( array_unique( $urls ) as $url ) {
		$id = url_to_postid( $url );

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

	// Every caller checks this too, but a new one must not be able to forget.
	if ( ! get_post( $source_id ) instanceof WP_Post || ! get_post( $target_id ) instanceof WP_Post || ! current_user_can( 'edit_post', $source_id ) ) {
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
 * Says whether the current user can take back a veto: the pair is vetoed,
 * the user may edit the post the link would sit in, and both posts are
 * still read, so the suggestion it would bring back can still be accepted.
 *
 * @param int $source_id Post the link would sit in.
 * @param int $target_id Post the link would point to.
 * @return bool True when the veto can be undone.
 */
function undergrowth_can_unveto_link( int $source_id, int $target_id ): bool {
	$source = get_post( $source_id );
	$target = get_post( $target_id );

	// Every caller checks the capability too, but a new one must not be able to forget.
	return $source instanceof WP_Post
		&& $target instanceof WP_Post
		&& undergrowth_is_indexable( $source )
		&& undergrowth_is_indexable( $target )
		&& current_user_can( 'edit_post', $source_id )
		&& 'vetoed' === undergrowth_link_state( $source_id, $target_id );
}

/**
 * Takes back a veto made by mistake, from the Undo in the veto's notice.
 *
 * A vetoed suggestion keeps its sentence, so it is suggested again as it
 * was. A veto of a pair that was no longer suggested is forgotten, and the
 * next refresh around the post may suggest the pair again.
 *
 * @param int $source_id Post the link would sit in.
 * @param int $target_id Post the link would point to.
 * @return bool True when the pair was vetoed and no longer is. False when
 *              undergrowth_can_unveto_link() says no.
 */
function undergrowth_unveto_link( int $source_id, int $target_id ): bool {
	global $wpdb;

	if ( ! undergrowth_can_unveto_link( $source_id, $target_id ) ) {
		return false;
	}

	// Only a row still vetoed is changed, so an Accept or a refresh that got
	// there first is never undone.
	$where = array(
		'source_id' => $source_id,
		'target_id' => $target_id,
		'state'     => 'vetoed',
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
	$sentence = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT sentence FROM %i WHERE source_id = %d AND target_id = %d', undergrowth_links_table(), $source_id, $target_id ) );

	if ( '' === $sentence ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
		$done = $wpdb->delete( undergrowth_links_table(), $where, array( '%d', '%d', '%s' ) );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
		$done = $wpdb->update(
			undergrowth_links_table(),
			array(
				'state'      => 'proposed',
				'updated_at' => current_time( 'mysql', true ),
			),
			$where,
			array( '%s', '%s' ),
			array( '%d', '%d', '%s' )
		);
	}

	undergrowth_write_memory( $source_id );

	return is_int( $done ) && $done > 0;
}

/**
 * Vetoes a link and names what happened, as every place that offers a veto
 * reports it.
 *
 * @param int $source_id Post the link would sit in.
 * @param int $target_id Post the link would point to.
 * @return string 'vetoed', 'unchanged' when the link was already accepted,
 *                'forbidden' when the user cannot edit the source post, or 'gone'.
 */
function undergrowth_veto_result( int $source_id, int $target_id ): string {
	if ( undergrowth_veto_link( $source_id, $target_id ) ) {
		return 'vetoed';
	}

	if ( get_post( $source_id ) instanceof WP_Post && get_post( $target_id ) instanceof WP_Post && ! current_user_can( 'edit_post', $source_id ) ) {
		return 'forbidden';
	}

	return 'accepted' === undergrowth_link_state( $source_id, $target_id ) ? 'unchanged' : 'gone';
}

/**
 * Words the result of accepting or vetoing a link, so the author reads the
 * same reason on the screen, through an ability and in Discord.
 *
 * @return array<string, string> Message keyed by result: 'accepted', 'vetoed', 'unchanged', 'gone', 'not_proposed', 'edited', 'no_word', 'not_saved', 'words_saved', 'words_invalid', 'words_unsafe', 'needs_admin' or 'forbidden'.
 */
function undergrowth_link_result_messages(): array {
	return array(
		'accepted'      => __( 'Linked. The highlighted words in your sentence now link to the suggested post.', 'undergrowth' ),
		'vetoed'        => __( 'Done. This link won’t be suggested again.', 'undergrowth' ),
		'unchanged'     => __( 'That link was already accepted, so it was left as it is.', 'undergrowth' ),
		'gone'          => __( 'One of those posts no longer exists or is no longer published, so nothing was changed.', 'undergrowth' ),
		'not_proposed'  => __( 'That suggestion is no longer on the list, so nothing was changed.', 'undergrowth' ),
		'edited'        => __( 'That sentence has changed since the link was suggested, so nothing was changed. You can add the link by hand.', 'undergrowth' ),
		'no_word'       => __( 'No words in that sentence could be linked safely, so nothing was changed. You can add the link by hand.', 'undergrowth' ),
		'not_saved'     => __( 'The post could not be saved, so nothing was changed. Try again in a moment.', 'undergrowth' ),
		'words_saved'   => __( 'Saved. Accept will link the words now highlighted.', 'undergrowth' ),
		'words_invalid' => __( 'Those are not words of that sentence, so nothing was changed. Choose a first word and a last word at or after it.', 'undergrowth' ),
		'words_unsafe'  => __( 'Those words can’t be linked safely, for example because formatting or another link runs through them, so nothing was changed. Choose other words.', 'undergrowth' ),
		'forbidden'     => __( 'You cannot edit that post, so nothing was changed.', 'undergrowth' ),
		'needs_admin'   => __( 'Saving this post would also remove content that only someone allowed to post unfiltered HTML can save, such as an embed, so nothing was changed. Someone with that permission can accept this link.', 'undergrowth' ),
	);
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
 * Scores every post a post could link to, before the target posts are read.
 *
 * Leaves out the post itself, posts it already links to, and any pair the
 * author has accepted or vetoed. A composting post gets no targets at all.
 * Each target post still has to pass undergrowth_open_targets(), which
 * callers walk in rank order and only as far as they need, so a refresh
 * never loads every post in a large archive.
 *
 * @param int                     $post_id Source post ID.
 * @param array<int, string>|null $pool    Packed comparable vectors, when already loaded.
 * @return array<int, float> Similarity keyed by target post ID, best first.
 */
function undergrowth_score_targets( int $post_id, ?array $pool = null ): array {
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
	$source   = undergrowth_unpack_vector( $pool[ $post_id ] );
	$scores   = array();

	foreach ( $pool as $target_id => $packed ) {
		if ( isset( $excluded[ $target_id ] ) ) {
			continue;
		}

		// One vector at a time, so only two are ever unpacked at once.
		$vector = undergrowth_unpack_vector( $packed );

		if ( ! undergrowth_vectors_comparable( $source, $vector ) ) {
			continue;
		}

		$score = undergrowth_cosine( $source, $vector );

		if ( $score < $minimum ) {
			continue;
		}

		$scores[ $target_id ] = $score;
	}

	arsort( $scores );

	return $scores;
}

/**
 * Lists which of a set of posts can still receive a link.
 *
 * A vector can outlive its post's publication when the status changes
 * without a save, so the target posts themselves are checked. No links go
 * into a composting post either. Only the columns the check needs are read,
 * past the post cache, so a refresh never holds the archive's content.
 *
 * @param int[] $post_ids Posts to check.
 * @return array<int, true> The open posts, keyed by ID.
 */
function undergrowth_open_target_ids( array $post_ids ): array {
	global $wpdb;

	if ( array() === $post_ids ) {
		return array();
	}

	$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Caching every post checked is what this avoids; there is one placeholder for each ID.
	$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_status, post_password, post_type FROM %i WHERE ID IN (' . $placeholders . ')', $wpdb->posts, ...array_map( 'intval', $post_ids ) ) );
	$wpdb->flush();

	$indexable = array();

	foreach ( $rows as $row ) {
		if ( undergrowth_is_indexable( new WP_Post( $row ) ) ) {
			$indexable[] = (int) $row->ID;
		}
	}

	$open = array_fill_keys( $indexable, true );

	if ( array() === $indexable ) {
		return $open;
	}

	// One query for the stage key alone. Priming every meta key would hold
	// unrelated, possibly large, meta for the rest of the refresh.
	$placeholders = implode( ', ', array_fill( 0, count( $indexable ), '%d' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Priming every meta key is what this avoids; there is one placeholder for each ID.
	$composting = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s AND post_id IN (' . $placeholders . ')', $wpdb->postmeta, 'undergrowth_stage', 'composting', ...$indexable ) );
	$wpdb->flush();

	foreach ( array_map( 'intval', $composting ) as $id ) {
		unset( $open[ $id ] );
	}

	return $open;
}

/**
 * Walks scored targets in rank order, keeping only posts that can still
 * receive a link.
 *
 * Target posts are checked a chunk at a time, so a caller that stops after a
 * few targets reads only the first chunk.
 *
 * @param array<int, float> $scores Result of undergrowth_score_targets().
 * @return Generator<int, float> Similarity keyed by target post ID, best first.
 */
function undergrowth_open_targets( array $scores ): Generator {
	foreach ( undergrowth_chunks( $scores, undergrowth_post_chunk_size(), true ) as $chunk ) {
		$open = undergrowth_open_target_ids( array_keys( $chunk ) );

		foreach ( $chunk as $target_id => $score ) {
			if ( isset( $open[ $target_id ] ) ) {
				yield $target_id => $score;
			}
		}
	}
}

/**
 * Chooses the words a proposed link sits on, so the suggestion shows them
 * and Accept links exactly those.
 *
 * @param int    $source_id Post the link would sit in.
 * @param int    $target_id Post the link would point to.
 * @param string $sentence  Sentence chosen for the link.
 * @param array  $corpus    Word counts across the pool.
 * @return array{anchor: string, offset: int} The words as the sentence shows
 *         them and their character offset in it; empty and -1 when no words
 *         can be linked safely.
 *
 * @phpstan-param array{documents: int, frequency: array<string, int>, posts: array<int, true>} $corpus
 */
function undergrowth_proposal_anchor( int $source_id, int $target_id, string $sentence, array $corpus ): array {
	$source = get_post( $source_id );
	$target = get_post( $target_id );
	$choice = $source instanceof WP_Post && $target instanceof WP_Post ? undergrowth_choose_link_span( $source, $target, $sentence, $corpus ) : null;

	return null === $choice ? array(
		'anchor' => '',
		'offset' => -1,
	) : array(
		'anchor' => undergrowth_collapse_spaces( $choice['word'] ),
		'offset' => $choice['offset'],
	);
}

/**
 * Stores one proposal, or updates the one already stored for the pair.
 *
 * Accepted and vetoed rows are never touched. Words the author chose stay
 * theirs while the sentence they chose them in is still the one suggested.
 *
 * @param int    $post_id   Source post ID.
 * @param int    $target_id Target post ID.
 * @param float  $score     Similarity of the two posts.
 * @param string $sentence  Sentence the link would sit in.
 * @param array  $corpus    Word counts across the pool.
 * @param string $kind      'cross' for a cross-pollination link, or an empty string.
 *
 * @phpstan-param array{documents: int, frequency: array<string, int>, posts: array<int, true>} $corpus
 */
function undergrowth_store_proposal( int $post_id, int $target_id, float $score, string $sentence, array $corpus, string $kind ): void {
	global $wpdb;

	$table   = undergrowth_links_table();
	$now     = current_time( 'mysql', true );
	$state   = undergrowth_link_state( $post_id, $target_id );
	$anchor  = undergrowth_proposal_anchor( $post_id, $target_id, $sentence, $corpus );
	$fields  = array(
		'score'         => $score,
		'sentence'      => $sentence,
		'kind'          => $kind,
		'anchor'        => $anchor['anchor'],
		'anchor_offset' => $anchor['offset'],
		'anchor_chosen' => 0,
		'updated_at'    => $now,
	);
	$formats = array( '%f', '%s', '%s', '%s', '%d', '%d', '%s' );

	if ( 'proposed' === $state ) {
		// Words the author chose stay theirs while the sentence they
		// chose them in is still the one suggested.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every refresh.
		$chosen = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT sentence FROM %i WHERE source_id = %d AND target_id = %d AND anchor_chosen = 1', $table, $post_id, $target_id ) );

		if ( $sentence === $chosen ) {
			unset( $fields['anchor'], $fields['anchor_offset'], $fields['anchor_chosen'] );
			$formats = array( '%f', '%s', '%s', '%s' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
		$wpdb->update(
			$table,
			$fields,
			array(
				'source_id' => $post_id,
				'target_id' => $target_id,
			),
			$formats,
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
			array_merge( array( '%d', '%d', '%s', '%s' ), $formats )
		);
	}
}

/**
 * Rewrites a post's proposed links to match its current best targets.
 *
 * A target only counts when the source post has a sentence where the link
 * fits, so the next best target takes the place of one without. Accepted
 * and vetoed rows are never touched.
 *
 * @param int                     $post_id Source post ID.
 * @param array<int, string>|null $pool    Packed comparable vectors, when already loaded.
 * @param array|null              $corpus  Word counts across the pool, when already built.
 *
 * @phpstan-param array{documents: int, frequency: array<string, int>, posts: array<int, true>}|null $corpus
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
	$targets = array();
	$taken   = array();

	// Nearest targets choose first, so the strongest link gets its best sentence.
	foreach ( undergrowth_open_targets( undergrowth_score_targets( $post_id, $pool ) ) as $target_id => $score ) {
		$sentence = undergrowth_choose_sentence( $post_id, $target_id, $corpus, $taken );

		if ( null === $sentence ) {
			continue;
		}

		$targets[ $target_id ] = $score;
		$taken[]               = $sentence['text'];

		undergrowth_store_proposal( $post_id, $target_id, $score, $sentence['text'], $corpus, '' );

		if ( count( $targets ) >= UNDERGROWTH_MAX_PROPOSALS ) {
			break;
		}
	}

	// At most one cross-pollination link on top, to a post in a group that
	// sits apart, so the usual suggestions stay the majority.
	// Both ends must still be in this post's provider, model and language,
	// which the pairs, found once a month, cannot promise.
	$cross = isset( $pool[ $post_id ] ) ? undergrowth_cross_link_target( $post_id, array_keys( $targets ), array_keys( $pool ) ) : null;

	if ( null !== $cross ) {
		$sentence = undergrowth_choose_sentence( $post_id, $cross[0], $corpus, $taken );

		if ( null !== $sentence ) {
			$targets[ $cross[0] ] = $cross[1];

			undergrowth_store_proposal( $post_id, $cross[0], $cross[1], $sentence['text'], $corpus, 'cross' );
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

	$source  = undergrowth_unpack_vector( $pool[ $post_id ] );
	$nearest = array();

	foreach ( $pool as $other_id => $packed ) {
		if ( $other_id === $post_id ) {
			continue;
		}

		$vector = undergrowth_unpack_vector( $packed );

		if ( undergrowth_vectors_comparable( $source, $vector ) ) {
			$nearest[ $other_id ] = undergrowth_cosine( $source, $vector );
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
 * Removes proposals to and from a post that has left the index, then queues
 * the posts that pointed at it to pick a replacement in cron.
 *
 * Choosing replacements reads every comparable post, which would hold up
 * the request that trashed, unpublished, deleted or composted the post.
 *
 * Vetoes and accepted links are kept, so a republished post never brings a
 * vetoed link back.
 *
 * @param int          $post_id Post that was unpublished or deleted.
 * @param WP_Post|null $post    The deleted post, when core's hook passes it.
 */
function undergrowth_forget_post_links( int $post_id, ?WP_Post $post = null ): void {
	global $wpdb;

	if ( undergrowth_ignores_post( $post ) ) {
		return;
	}

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

	$sources = array_values( array_unique( array_map( 'intval', $sources ) ) );

	if ( array() !== $sources ) {
		sort( $sources );
		wp_schedule_single_event( time(), 'undergrowth_refresh_sources', array( $sources ) );
	}
}

/**
 * Lets the posts that proposed a post that left the index pick a
 * replacement, a batch at a time, and queues the rest.
 *
 * A source that left the index or was composted meanwhile is skipped.
 *
 * @param int[] $source_ids Posts that proposed the post that left.
 */
function undergrowth_refresh_sources( $source_ids ): void {
	$source_ids = array_values( array_map( 'intval', (array) $source_ids ) );
	$rest       = array_slice( $source_ids, UNDERGROWTH_REFRESH_BATCH );

	if ( array() !== $rest ) {
		wp_schedule_single_event( time(), 'undergrowth_refresh_sources', array( $rest ) );
	}

	undergrowth_refresh_posts( array_slice( $source_ids, 0, UNDERGROWTH_REFRESH_BATCH ) );
}

/**
 * Refreshes the proposals of some posts, in order, until the time is spent.
 *
 * Posts sharing a provider, model and language share one pool and one set
 * of word counts, so neither is built again for each post. A post that
 * left the index or is composting is skipped.
 *
 * @param int[] $post_ids Posts to refresh.
 * @param float $budget   Seconds after which no further post is started. One post is always refreshed.
 * @return int[] The posts that were reached, refreshed or skipped.
 */
function undergrowth_refresh_posts( array $post_ids, float $budget = INF ): array {
	$started = microtime( true );
	$pools   = array();
	$reached = array();

	foreach ( $post_ids as $source_id ) {
		if ( array() !== $reached && microtime( true ) - $started >= $budget ) {
			break;
		}

		$reached[] = $source_id;
		$source    = get_post( $source_id );

		if ( ! $source instanceof WP_Post || ! undergrowth_is_indexable( $source ) || 'composting' === undergrowth_get_stage( $source_id ) ) {
			continue;
		}

		$vector = undergrowth_get_vector( $source_id );

		if ( null === $vector ) {
			undergrowth_refresh_proposals( $source_id );
			continue;
		}

		$key = undergrowth_vector_space( $vector );

		if ( ! isset( $pools[ $key ] ) ) {
			$pool          = undergrowth_comparable_vectors( $source_id );
			$pools[ $key ] = array( $pool, undergrowth_reusable_corpus( array_keys( $pool ) ) );
		}

		undergrowth_refresh_proposals( $source_id, $pools[ $key ][0], $pools[ $key ][1] );
	}

	return $reached;
}

/**
 * Refreshes every indexed post's proposals once the backfill has read the
 * whole site, a batch at a time.
 *
 * Proposals made while the backfill was running were chosen with the word
 * counts of part of the site, so their sentence may no longer hold a word
 * that can carry the link. Each run takes the backfill's batch size and
 * time budget, then queues the next after the last post it reached.
 *
 * @param int $after Refresh posts with a higher ID than this.
 */
function undergrowth_refresh_index( $after = 0 ): void {
	$identity = undergrowth_index_target();

	// A switch of model or language since starts another backfill, which
	// queues its own refresh once it has read the site.
	if ( get_option( 'undergrowth_index_identity' ) !== $identity ) {
		return;
	}

	$size     = undergrowth_backfill_batch_size();
	$post_ids = undergrowth_indexed_post_ids( $identity, (int) $after, $size );
	$reached  = undergrowth_refresh_posts( $post_ids, undergrowth_backfill_time_budget() );

	if ( array() !== $reached && ( count( $post_ids ) === $size || count( $reached ) < count( $post_ids ) ) ) {
		wp_schedule_single_event( time(), 'undergrowth_refresh_index', array( end( $reached ) ) );
	}
}

/**
 * Lists posts that have a vector for an identity, by ID.
 *
 * @param array $identity {
 *     The provider and model, and the site language when posts take theirs from the site.
 *
 *     @type string $provider Provider name.
 *     @type string $model    Model name.
 *     @type string $language Site locale, or empty when each post sets its own language.
 * }
 * @param int   $after    Only posts with a higher ID than this.
 * @param int   $limit    Most posts to list.
 * @return int[] Post IDs in ascending order.
 *
 * @phpstan-param array{provider: string, model: string, language: string} $identity
 */
function undergrowth_indexed_post_ids( array $identity, int $after, int $limit ): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; vectors change on every embed.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_id FROM %i WHERE provider = %s AND model = %s AND ( %s = '' OR language = %s ) AND post_id > %d ORDER BY post_id LIMIT %d",
			undergrowth_vectors_table(),
			$identity['provider'],
			$identity['model'],
			$identity['language'],
			$identity['language'],
			$after,
			$limit
		)
	);

	return array_map( 'intval', (array) $ids );
}
