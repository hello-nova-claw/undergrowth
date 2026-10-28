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
const UNDERGROWTH_POST_CHUNK           = 100;
const UNDERGROWTH_REFRESH_BATCH        = 20;

// Most sentences tried for a link on two words or more before one word is kept.
const UNDERGROWTH_ANCHOR_SENTENCES = 10;

// Raised whenever the rules for choosing a link's words change, so stored
// suggestions are chosen again. 2: two words or more (#511). 3: never two
// suggestions on overlapping words of one sentence (#522).
const UNDERGROWTH_ANCHOR_RULES = 3;

// On init, once every plugin has added its filters for a post's language.
add_action( 'init', 'undergrowth_maybe_refresh_anchors' );
add_action( 'undergrowth_post_embedded', 'undergrowth_refresh_around' );
add_action( 'undergrowth_post_left_index', 'undergrowth_forget_post_links' );
add_action( 'deleted_post', 'undergrowth_forget_post_links', 10, 2 );
add_action( 'undergrowth_refresh_sources', 'undergrowth_refresh_sources' );
add_action( 'undergrowth_refresh_index', 'undergrowth_refresh_index' );

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
 * @param array  $avoid     Runs of words in the sentence that the post's other proposals hold.
 * @return array{anchor: string, offset: int} The words as the sentence shows
 *         them and their character offset in it; empty and -1 when no words
 *         can be linked safely.
 *
 * @phpstan-param array{documents: int, frequency: array<string, int>, posts: array<int, true>} $corpus
 * @phpstan-param list<array{0: int, 1: int}> $avoid
 */
function undergrowth_proposal_anchor( int $source_id, int $target_id, string $sentence, array $corpus, array $avoid = array() ): array {
	$source = get_post( $source_id );
	$target = get_post( $target_id );
	$choice = $source instanceof WP_Post && $target instanceof WP_Post ? undergrowth_choose_link_span( $source, $target, $sentence, $corpus, $avoid ) : null;

	return null === $choice ? array(
		'anchor' => '',
		'offset' => -1,
	) : array(
		'anchor' => undergrowth_collapse_spaces( $choice['word'] ),
		'offset' => $choice['offset'],
	);
}

/**
 * Picks the sentence and words for a proposed link.
 *
 * The best sentence wins when it gives a run of two words or more. A link
 * on one word reads like an accident, so the post's other sentences that
 * fit are tried next, best first, and the first that gives a longer run
 * wins. Only when none does is the best sentence kept, on one word, and
 * then the first one that gives any word at all. In a script written
 * without spaces, a run of characters counts as long enough. A sentence
 * the author chose words in is kept while it still fits.
 *
 * Two proposals never sit on overlapping words in one sentence. A sentence
 * that holds another proposal's words is used only with other words, and
 * skipped when it has none, even if the post gets fewer suggestions.
 *
 * @param int      $post_id   Post the link would sit in.
 * @param int      $target_id Post the link would point to.
 * @param array    $corpus    Word counts across the pool.
 * @param string[] $taken     Sentences already holding another of the post's proposals.
 * @param array    $claimed   Runs of words the post's other proposals hold, by sentence.
 * @return array{text: string, anchor: string, offset: int}|null The sentence
 *         and the words as undergrowth_proposal_anchor() gives them, or null
 *         when no sentence fits.
 *
 * @phpstan-param array{documents: int, frequency: array<string, int>, posts: array<int, true>} $corpus
 * @phpstan-param array<string, list<array{0: int, 1: int}>> $claimed
 */
function undergrowth_choose_proposal( int $post_id, int $target_id, array $corpus, array $taken = array(), array $claimed = array() ): ?array {
	global $wpdb;

	$source = get_post( $post_id );
	$ranked = $source instanceof WP_Post ? undergrowth_rank_sentences_in( $source, $target_id, $corpus, $taken ) : array();
	$chosen = null;

	// Words the author chose stay theirs while their sentence still fits.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every refresh.
	$own = $wpdb->get_row( $wpdb->prepare( 'SELECT sentence, anchor, anchor_offset FROM %i WHERE source_id = %d AND target_id = %d AND state = %s AND anchor_chosen = 1', undergrowth_links_table(), $post_id, $target_id, 'proposed' ), ARRAY_A );

	if ( is_array( $own ) && in_array( $own['sentence'], $ranked, true ) ) {
		$mine = array(
			'word'     => (string) $own['anchor'],
			'offset'   => (int) $own['anchor_offset'],
			'position' => 0,
		);

		// Another proposal now holds those words, so the sentence is left
		// to it rather than suggested again with words the author did not
		// choose.
		if ( undergrowth_span_overlaps( $mine, $claimed[ $own['sentence'] ] ?? array() ) ) {
			$ranked = array_values( array_diff( $ranked, array( $own['sentence'] ) ) );
		} else {
			return array(
				'text'   => (string) $own['sentence'],
				'anchor' => (string) $own['anchor'],
				'offset' => (int) $own['anchor_offset'],
			);
		}
	}

	foreach ( array_slice( $ranked, 0, UNDERGROWTH_ANCHOR_SENTENCES ) as $sentence ) {
		$anchor = undergrowth_proposal_anchor( $post_id, $target_id, $sentence, $corpus, $claimed[ $sentence ] ?? array() );

		if ( '' === $anchor['anchor'] && isset( $claimed[ $sentence ] ) ) {
			continue;
		}

		$choice = array(
			'text'   => $sentence,
			'anchor' => $anchor['anchor'],
			'offset' => $anchor['offset'],
		);

		if ( preg_match( '/\s|[' . UNDERGROWTH_UNSPACED_SCRIPTS . ']/u', $anchor['anchor'] ) ) {
			return $choice;
		}

		if ( null === $chosen || ( '' === $chosen['anchor'] && '' !== $anchor['anchor'] ) ) {
			$chosen = $choice;
		}
	}

	return $chosen;
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
 * @param array  $anchor    Result of undergrowth_proposal_anchor() for the sentence, when already chosen.
 *
 * @phpstan-param array{documents: int, frequency: array<string, int>, posts: array<int, true>} $corpus
 * @phpstan-param array{anchor: string, offset: int}|null $anchor
 */
function undergrowth_store_proposal( int $post_id, int $target_id, float $score, string $sentence, array $corpus, string $kind, ?array $anchor = null ): void {
	global $wpdb;

	$table   = undergrowth_links_table();
	$now     = current_time( 'mysql', true );
	$state   = undergrowth_link_state( $post_id, $target_id );
	$anchor  = $anchor ?? undergrowth_proposal_anchor( $post_id, $target_id, $sentence, $corpus );
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
	$claimed = array();

	// Nearest targets choose first, so the strongest link gets its best sentence.
	foreach ( undergrowth_open_targets( undergrowth_score_targets( $post_id, $pool ) ) as $target_id => $score ) {
		$sentence = undergrowth_choose_proposal( $post_id, $target_id, $corpus, $taken, $claimed );

		if ( null === $sentence ) {
			continue;
		}

		$targets[ $target_id ] = $score;
		$taken[]               = $sentence['text'];

		if ( '' !== $sentence['anchor'] ) {
			$claimed[ $sentence['text'] ][] = array( $sentence['offset'], mb_strlen( $sentence['anchor'] ) );
		}

		undergrowth_store_proposal( $post_id, $target_id, $score, $sentence['text'], $corpus, '', $sentence );

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
		$sentence = undergrowth_choose_proposal( $post_id, $cross[0], $corpus, $taken, $claimed );

		if ( null !== $sentence ) {
			$targets[ $cross[0] ] = $cross[1];

			undergrowth_store_proposal( $post_id, $cross[0], $cross[1], $sentence['text'], $corpus, 'cross', $sentence );
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
 * Chooses every stored suggestion's sentence and words again, once, after
 * an update changes the rules for choosing them.
 *
 * Only proposed links change. Accepted and vetoed ones stay as they are,
 * and so do words the author chose. A site still being read queues the
 * same refresh when its backfill ends, so nothing is queued for it here.
 */
function undergrowth_maybe_refresh_anchors(): void {
	if ( UNDERGROWTH_ANCHOR_RULES === (int) get_option( 'undergrowth_anchor_rules' ) ) {
		return;
	}

	// Read on every request, so it loads with the other options.
	update_option( 'undergrowth_anchor_rules', UNDERGROWTH_ANCHOR_RULES, true );

	if ( get_option( 'undergrowth_index_identity' ) === undergrowth_index_target() && false === wp_next_scheduled( 'undergrowth_refresh_index', array( 0 ) ) ) {
		wp_schedule_single_event( time(), 'undergrowth_refresh_index', array( 0 ) );
	}
}

/**
 * Counts the suggestions whose words are a single word, so the rate can be
 * checked. Words the author chose and scripts written without spaces are
 * left out.
 *
 * @return array{one: int, all: int} Suggestions on one word, and all suggestions with words.
 */
function undergrowth_one_word_anchor_counts(): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every refresh.
	$anchors = (array) $wpdb->get_col( $wpdb->prepare( "SELECT anchor FROM %i WHERE state = %s AND anchor_chosen = 0 AND anchor <> ''", undergrowth_links_table(), 'proposed' ) );
	$counts  = array(
		'one' => 0,
		'all' => 0,
	);

	foreach ( $anchors as $anchor ) {
		if ( preg_match( '/[' . UNDERGROWTH_UNSPACED_SCRIPTS . ']/u', (string) $anchor ) ) {
			continue;
		}

		++$counts['all'];

		if ( ! preg_match( '/\s/u', (string) $anchor ) ) {
			++$counts['one'];
		}
	}

	return $counts;
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
