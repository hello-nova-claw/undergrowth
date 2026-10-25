<?php
/**
 * Letting the author choose which of their own words a link sits on.
 *
 * The author picks a first and a last word among the words already in the
 * suggestion's sentence. Nothing can be typed, so the link only ever goes
 * around the author's own words.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Names a version of a sentence, so a choice made on one sentence is never
 * saved against another that has replaced it.
 *
 * @param string $sentence Sentence as stored with the proposal.
 * @return string
 */
function undergrowth_sentence_version( string $sentence ): string {
	return md5( $sentence );
}

/**
 * Tells whether the author can choose words in a sentence.
 *
 * Unspaced scripts have no word boundaries to choose between.
 *
 * @param string $sentence Sentence as stored with the proposal.
 * @return bool
 */
function undergrowth_can_choose_words( string $sentence ): bool {
	return ! preg_match( '/[' . UNDERGROWTH_UNSPACED_SCRIPTS . ']/u', $sentence ) && array() !== undergrowth_anchor_words( $sentence );
}

/**
 * Saves the run of words the author chose for a proposed link.
 *
 * Only positions come in. The sentence is read from the proposal, and the
 * run must be a stretch of its words that Accept can link safely.
 *
 * @param int    $source_id Post the link would sit in.
 * @param int    $target_id Post the link would point to.
 * @param int    $first     Position of the first word, from 0.
 * @param int    $last      Position of the last word.
 * @param string $shown     undergrowth_sentence_version() of the sentence the author chose from.
 * @return string 'words_saved', or why nothing changed: 'not_proposed',
 *                'edited' (the sentence has changed), 'words_invalid' (not a
 *                run of words in it) or 'words_unsafe' (cannot be linked safely).
 */
function undergrowth_choose_words( int $source_id, int $target_id, int $first, int $last, string $shown ): string {
	global $wpdb;

	$table = undergrowth_links_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every refresh.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT state, sentence FROM %i WHERE source_id = %d AND target_id = %d', $table, $source_id, $target_id ), ARRAY_A );

	if ( ! is_array( $row ) || 'proposed' !== $row['state'] ) {
		return 'not_proposed';
	}

	$sentence = (string) $row['sentence'];
	$source   = get_post( $source_id );

	if ( ! hash_equals( undergrowth_sentence_version( $sentence ), $shown ) || ! $source instanceof WP_Post || ! in_array( $sentence, array_column( undergrowth_split_sentences( $source->post_content ), 'text' ), true ) ) {
		return 'edited';
	}

	$words = undergrowth_can_choose_words( $sentence ) ? undergrowth_anchor_words( $sentence ) : array();

	if ( $first < 0 || $last < $first || ! isset( $words[ $last ] ) ) {
		return 'words_invalid';
	}

	$offset = $words[ $first ]['offset'];
	$found  = undergrowth_find_link_span( $source->post_content, $sentence, $offset, $words[ $last ]['offset'] + $words[ $last ]['length'] - $offset );

	if ( null === $found ) {
		return 'words_unsafe';
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
	$wpdb->update(
		$table,
		array(
			'anchor'        => undergrowth_collapse_spaces( $found['word'] ),
			'anchor_offset' => $offset,
			'anchor_chosen' => 1,
		),
		array(
			'source_id' => $source_id,
			'target_id' => $target_id,
			'state'     => 'proposed',
			'sentence'  => $sentence,
		),
		array( '%s', '%d', '%d' ),
		array( '%d', '%d', '%s', '%s' )
	);

	return 'words_saved';
}
