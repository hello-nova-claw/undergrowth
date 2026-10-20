<?php
/**
 * Accepting a proposal: linking one word of the author's own sentence.
 *
 * The author's text is never rewritten. One existing word is wrapped in a
 * plain link, and anything that cannot be done safely is left untouched.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Accepts a proposed link by linking the target's most distinctive word
 * where it appears in the proposal's sentence.
 *
 * @param int $source_id Post the link sits in.
 * @param int $target_id Post the link points to.
 * @return string 'accepted', or why nothing changed: 'gone', 'not_proposed',
 *                'edited' (the sentence is no longer in the post) or
 *                'no_word' (no word could be linked safely).
 */
function undergrowth_accept_link( int $source_id, int $target_id ): string {
	global $wpdb;

	$source = get_post( $source_id );
	$target = get_post( $target_id );

	if ( ! $source instanceof WP_Post || ! $target instanceof WP_Post || ! undergrowth_is_indexable( $target ) ) {
		return 'gone';
	}

	if ( 'proposed' !== undergrowth_link_state( $source_id, $target_id ) ) {
		return 'not_proposed';
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every refresh.
	$sentence = (string) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT sentence FROM %i WHERE source_id = %d AND target_id = %d',
			undergrowth_links_table(),
			$source_id,
			$target_id
		)
	);

	if ( '' === $sentence || ! in_array( $sentence, array_column( undergrowth_split_sentences( $source->post_content ), 'text' ), true ) ) {
		return 'edited';
	}

	$corpus = undergrowth_build_corpus( array_unique( array_merge( array_keys( undergrowth_comparable_vectors( $source_id ) ), array( $source_id, $target_id ) ) ) );
	$linked = null;

	foreach ( array_keys( undergrowth_distinctive_terms( $target_id, $corpus ) ) as $term ) {
		$linked = undergrowth_link_word( $source->post_content, $sentence, (string) $term, (string) get_permalink( $target ) );

		if ( null !== $linked ) {
			break;
		}
	}

	if ( null === $linked ) {
		return 'no_word';
	}

	$updated = wp_update_post(
		wp_slash(
			array(
				'ID'           => $source_id,
				'post_content' => $linked,
			)
		),
		true
	);

	if ( is_wp_error( $updated ) || 0 === $updated ) {
		return 'no_word';
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
	$wpdb->update(
		undergrowth_links_table(),
		array(
			'state'      => 'accepted',
			'updated_at' => current_time( 'mysql', true ),
		),
		array(
			'source_id' => $source_id,
			'target_id' => $target_id,
		),
		array( '%s', '%s' ),
		array( '%d', '%d' )
	);

	undergrowth_write_memory( $source_id );

	return 'accepted';
}

/**
 * Wraps the first safe occurrence of a word inside a sentence in a link.
 *
 * Only paragraphs are searched, and only their plain text: never a tag, an
 * attribute or text already inside a link. The occurrence must fall within
 * the sentence, so the same word earlier in the paragraph is never linked.
 *
 * @param string $content  Post content.
 * @param string $sentence Sentence the link belongs in, as stored.
 * @param string $term     Lowercase word, or a pair of characters in unspaced scripts.
 * @param string $url      Address to link to.
 * @return string|null The content with one word linked, or null when no safe place exists.
 */
function undergrowth_link_word( string $content, string $sentence, string $term, string $url ): ?string {
	if ( ! preg_match_all( '#<p\b[^>]*>(.*?)</p>#is', $content, $paragraphs, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}

	// A pair of characters from an unspaced script has no word boundaries.
	$boundary = preg_match( '/[' . UNDERGROWTH_UNSPACED_SCRIPTS . ']/u', $term ) ? '' : '(?<![\p{L}\p{N}])';
	$pattern  = '/' . $boundary . preg_quote( $term, '/' ) . ( '' === $boundary ? '' : '(?![\p{L}\p{N}])' ) . '/iu';

	foreach ( $paragraphs[1] as $paragraph ) {
		list( $inner, $inner_start ) = $paragraph;

		$plain = ltrim( undergrowth_plain_text( $inner ) );
		$start = mb_strpos( $plain, $sentence );

		if ( false === $start ) {
			continue;
		}

		$end   = $start + mb_strlen( $sentence );
		$depth = 0;

		foreach ( (array) preg_split( '/(<[^>]*>)/', $inner, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE ) as $segment ) {
			list( $text, $offset ) = $segment;

			if ( '<' === substr( $text, 0, 1 ) ) {
				if ( preg_match( '#^<a\b#i', $text ) ) {
					++$depth;
				} elseif ( preg_match( '#^</a\s*>#i', $text ) ) {
					$depth = max( 0, $depth - 1 );
				}
				continue;
			}

			if ( $depth > 0 || ! preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[0] as $match ) {
				list( $word, $at ) = $match;

				$before = ltrim( undergrowth_plain_text( substr( $inner, 0, $offset + $at ) ) );
				$from   = mb_strlen( $before );

				if ( $from < $start || $from + mb_strlen( $word ) > $end ) {
					continue;
				}

				$position = $inner_start + $offset + $at;

				return substr( $content, 0, $position )
					. '<a href="' . esc_url( $url ) . '">' . $word . '</a>'
					. substr( $content, $position + strlen( $word ) );
			}
		}
	}

	return null;
}

/**
 * Reduces HTML to the text a reader sees, with whitespace collapsed.
 *
 * Unlike wp_strip_all_tags(), nothing is trimmed, so the text before a point
 * in the HTML lines up with the same point in the text of the whole.
 *
 * @param string $html HTML fragment.
 * @return string Plain text.
 */
function undergrowth_plain_text( string $html ): string {
	$text = html_entity_decode( (string) preg_replace( '/<[^>]*>/', '', $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	return (string) preg_replace( '/\s+/u', ' ', $text );
}
