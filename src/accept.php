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

// Elements wpautop() treats as blocks, and a few more that hold no prose.
const UNDERGROWTH_BLOCK_ELEMENTS = 'table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary|iframe|script|object|embed|video|audio|select|textarea|math|svg';

/**
 * Accepts a proposed link by linking the word the target is most about
 * where it appears in the proposal's sentence.
 *
 * The suggestion is claimed before the post is touched, so two requests
 * arriving together can never both link it. Any failure gives the claim back.
 *
 * @param int $source_id Post the link sits in.
 * @param int $target_id Post the link points to.
 * @return string 'accepted', or why nothing changed: 'gone', 'not_proposed',
 *                'edited' (the sentence is no longer in the post), 'no_word'
 *                (no word could be linked safely) or 'needs_admin' (saving
 *                would also strip content the user may not save).
 */
function undergrowth_accept_link( int $source_id, int $target_id ): string {
	global $wpdb;

	$target = get_post( $target_id );

	if ( ! get_post( $source_id ) instanceof WP_Post || ! $target instanceof WP_Post || ! undergrowth_is_indexable( $target ) ) {
		return 'gone';
	}

	if ( ! undergrowth_claim_link( $source_id, $target_id, 'proposed', 'accepted' ) ) {
		return 'not_proposed';
	}

	$result = undergrowth_link_accepted( $source_id, $target );

	if ( 'accepted' !== $result ) {
		undergrowth_claim_link( $source_id, $target_id, 'accepted', 'proposed' );
		return $result;
	}

	undergrowth_write_memory( $source_id );

	return 'accepted';
}

/**
 * Moves a link from one state to another, only if it is in the first.
 *
 * @param int    $source_id Post the link sits in.
 * @param int    $target_id Post the link points to.
 * @param string $from      State the link must be in.
 * @param string $to        State to move it to.
 * @return bool True when exactly this request moved it.
 */
function undergrowth_claim_link( int $source_id, int $target_id, string $from, string $to ): bool {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
	$moved = $wpdb->update(
		undergrowth_links_table(),
		array(
			'state'      => $to,
			'updated_at' => current_time( 'mysql', true ),
		),
		array(
			'source_id' => $source_id,
			'target_id' => $target_id,
			'state'     => $from,
		),
		array( '%s', '%s' ),
		array( '%d', '%d', '%s' )
	);

	return 1 === $moved;
}

/**
 * Links one word in the source post for a link already claimed as accepted.
 *
 * @param int     $source_id Post the link sits in.
 * @param WP_Post $target    Post the link points to.
 * @return string 'accepted', or why nothing changed.
 */
function undergrowth_link_accepted( int $source_id, WP_Post $target ): string {
	global $wpdb;

	$source = get_post( $source_id );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every refresh.
	$sentence = (string) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT sentence FROM %i WHERE source_id = %d AND target_id = %d',
			undergrowth_links_table(),
			$source_id,
			$target->ID
		)
	);

	if ( ! $source instanceof WP_Post || '' === $sentence || ! in_array( $sentence, array_column( undergrowth_split_sentences( $source->post_content ), 'text' ), true ) ) {
		return 'edited';
	}

	$choice = undergrowth_choose_link_word( $source, $target, $sentence );

	if ( null === $choice ) {
		return 'no_word';
	}

	$linked = undergrowth_wrap_link_word( $source->post_content, $choice, (string) get_permalink( $target ) );

	// Without unfiltered_html, WordPress runs kses over the whole post on save
	// and can strip content such as an embed. Accepting one link must never do
	// that, so the save is refused when kses would change anything.
	if ( ! current_user_can( 'unfiltered_html' ) && wp_kses_post( $linked ) !== $linked ) {
		return 'needs_admin';
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

	return is_wp_error( $updated ) || 0 === $updated ? 'no_word' : 'accepted';
}

/**
 * Tells which word Accept would link in a proposal's sentence, without
 * changing anything, so the suggestions screen can show it.
 *
 * @param int    $source_id Post the link would sit in.
 * @param int    $target_id Post the link would point to.
 * @param string $sentence  Sentence as stored with the proposal.
 * @return array{term: string, word: string, offset: int, position: int}|null
 *         The word as written, its character offset in the sentence and its
 *         byte position in the post; null when Accept would link nothing.
 */
function undergrowth_link_word_choice( int $source_id, int $target_id, string $sentence ): ?array {
	$source = get_post( $source_id );
	$target = get_post( $target_id );

	// As in undergrowth_accept_link(), a target that has left the garden
	// cannot be linked, so no word would be.
	if ( ! $source instanceof WP_Post || ! $target instanceof WP_Post || ! undergrowth_is_indexable( $target ) || '' === $sentence || ! in_array( $sentence, array_column( undergrowth_split_sentences( $source->post_content ), 'text' ), true ) ) {
		return null;
	}

	return undergrowth_choose_link_word( $source, $target, $sentence );
}

/**
 * Chooses the word to link in a sentence: the first that can be linked
 * safely, among every word the sentence shares with the target, the one
 * the target is most about first.
 *
 * The sentence was chosen for a word among the top few, but which words
 * make the top few shifts as posts are added, most of all while a new site
 * is still being indexed, so every shared word is tried.
 *
 * @param WP_Post $source   Post the link would sit in.
 * @param WP_Post $target   Post the link would point to.
 * @param string  $sentence Sentence the link belongs in, as stored.
 * @return array{term: string, word: string, offset: int, position: int}|null
 */
function undergrowth_choose_link_word( WP_Post $source, WP_Post $target, string $sentence ): ?array {
	$corpus = undergrowth_reusable_corpus( array_merge( undergrowth_comparable_post_ids( $source->ID ), array( $source->ID, $target->ID ) ) );

	foreach ( array_keys( undergrowth_anchor_terms( $target->ID, $sentence, $corpus, 0 ) ) as $term ) {
		$found = undergrowth_find_link_word( $source->post_content, $sentence, (string) $term );

		if ( null !== $found ) {
			return array( 'term' => (string) $term ) + $found;
		}
	}

	return null;
}

/**
 * Wraps a chosen word in a link.
 *
 * @param string $content Post content.
 * @param array  $found   Result of undergrowth_find_link_word().
 * @param string $url     Address to link to.
 * @return string
 *
 * @phpstan-param array{word: string, offset: int, position: int} $found
 */
function undergrowth_wrap_link_word( string $content, array $found, string $url ): string {
	return substr( $content, 0, $found['position'] )
		. '<a href="' . esc_url( $url ) . '">' . $found['word'] . '</a>'
		. substr( $content, $found['position'] + strlen( $found['word'] ) );
}

/**
 * Wraps the first safe occurrence of a word inside a sentence in a link.
 *
 * Only paragraphs are searched, and only their plain text: never a tag, an
 * attribute or text already inside a link. See undergrowth_linkable_paragraphs(). The occurrence must fall within
 * the sentence, so the same word earlier in the paragraph is never linked.
 *
 * @param string $content  Post content.
 * @param string $sentence Sentence the link belongs in, as stored.
 * @param string $term     Lowercase word, or a pair of characters in unspaced scripts.
 * @param string $url      Address to link to.
 * @return string|null The content with one word linked, or null when no safe place exists.
 */
function undergrowth_link_word( string $content, string $sentence, string $term, string $url ): ?string {
	$found = undergrowth_find_link_word( $content, $sentence, $term );

	return null === $found ? null : undergrowth_wrap_link_word( $content, $found, $url );
}

/**
 * Finds the first safe occurrence of a word inside a sentence.
 *
 * See undergrowth_link_word() for where a word may be linked.
 *
 * @param string $content  Post content.
 * @param string $sentence Sentence the link belongs in, as stored.
 * @param string $term     Lowercase word, or a pair of characters in unspaced scripts.
 * @return array{word: string, offset: int, position: int}|null The word as
 *         written, its character offset in the sentence and its byte
 *         position in the content, or null when no safe place exists.
 */
function undergrowth_find_link_word( string $content, string $sentence, string $term ): ?array {
	// A pair of characters from an unspaced script has no word boundaries.
	$boundary = preg_match( '/[' . UNDERGROWTH_UNSPACED_SCRIPTS . ']/u', $term ) ? '' : '(?<![\p{L}\p{N}])';
	$pattern  = '/' . $boundary . preg_quote( $term, '/' ) . ( '' === $boundary ? '' : '(?![\p{L}\p{N}])' ) . '/iu';

	foreach ( undergrowth_linkable_paragraphs( $content ) as $paragraph ) {
		list( $inner, $inner_start, $plain ) = $paragraph;

		$start = mb_strpos( $plain, $sentence );

		if ( false === $start ) {
			continue;
		}

		$end = $start + mb_strlen( $sentence );

		foreach ( ( new Undergrowth_HTML_Text( $inner ) )->text_nodes() as $node ) {
			list( $text, $offset, $in_link ) = $node;

			if ( $in_link ) {
				continue;
			}

			// A word that is also a character reference's name, as in &pound;,
			// must never be linked inside the reference.
			$matches = array();

			foreach ( (array) preg_split( '/(&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]*);)/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE ) as $piece ) {
				list( $piece_text, $piece_at ) = $piece;

				if ( '&' === substr( $piece_text, 0, 1 ) || ! preg_match_all( $pattern, $piece_text, $found, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}

				foreach ( $found[0] as $hit ) {
					$matches[] = array( $hit[0], $piece_at + $hit[1] );
				}
			}

			foreach ( $matches as $match ) {
				list( $word, $at ) = $match;

				$before = ltrim( undergrowth_plain_text( substr( $inner, 0, $offset + $at ) ) );
				$from   = mb_strlen( $before );

				if ( $from < $start || $from + mb_strlen( $word ) > $end ) {
					continue;
				}

				return array(
					'word'     => $word,
					'offset'   => $from - $start,
					'position' => $inner_start + $offset + $at,
				);
			}
		}
	}

	return null;
}

/**
 * Lists the paragraphs a link may be added to.
 *
 * In a block post these are its <p> elements. A post without blocks, as the
 * Classic editor writes it, keeps most paragraphs as text between blank
 * lines and gets its <p> tags only when shown, so those stretches count too,
 * unless they hold block-level markup or a shortcode. A paragraph inside
 * someone else's words never counts.
 *
 * @param string $content Post content.
 * @return array<int, array{0: string, 1: int, 2: string}> Each paragraph's inner HTML, its byte offset in the content, and its plain text, in order.
 */
function undergrowth_linkable_paragraphs( string $content ): array {
	$found = has_blocks( $content ) ? array() : undergrowth_classic_paragraphs( $content );

	foreach ( ( new Undergrowth_HTML_Text( $content ) )->paragraphs() as $paragraph ) {
		$found[ $paragraph[1] ] = $paragraph[0];
	}

	ksort( $found );

	$linkable = array();

	foreach ( $found as $inner_start => $inner ) {
		if ( ! undergrowth_inside_others_words( substr( $content, 0, $inner_start ) ) ) {
			$linkable[] = array( $inner, $inner_start, ltrim( undergrowth_plain_text( $inner ) ) );
		}
	}

	return $linkable;
}

/**
 * Finds the paragraphs wpautop() would make from a post without blocks.
 *
 * They are the stretches between blank lines that hold no block-level
 * markup and sit outside every block-level element, link and shortcode,
 * including ones that span the blank lines around them.
 *
 * @param string $content Post content.
 * @return array<int, string> Each paragraph's HTML, keyed by its byte offset in the content.
 */
function undergrowth_classic_paragraphs( string $content ): array {
	// Tags inside comments are not markup. Blanking them keeps every offset,
	// and a stretch that a comment runs into or out of is skipped.
	$comments = array();
	$scan     = (string) preg_replace_callback(
		'/<!--.*?(?:-->|$)/s',
		static function ( $comment ) {
			return str_repeat( ' ', strlen( $comment[0] ) );
		},
		$content
	);

	if ( preg_match_all( '/<!--.*?(?:-->|$)/s', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches[0] as $match ) {
			$comments[] = array( $match[1], $match[1] + strlen( $match[0] ) );
		}
	}

	$skip  = array_merge( undergrowth_element_ranges( $scan ), undergrowth_shortcode_ranges( $scan ) );
	$found = array();

	foreach ( (array) preg_split( '/\n\s*\n/', $content, -1, PREG_SPLIT_OFFSET_CAPTURE ) as $chunk ) {
		list( $text, $offset ) = $chunk;

		$inner = trim( $text );
		$start = $offset + (int) strpos( $text, $inner );

		$end = $start + strlen( $inner );

		if ( '' !== $inner && ! preg_match( '#</?(?:' . UNDERGROWTH_BLOCK_ELEMENTS . ')\b#i', $inner ) && ! undergrowth_overlaps_any( $start, $end, $skip ) && ! undergrowth_crosses_any( $start, $end, $comments ) ) {
			$found[ $start ] = $inner;
		}
	}

	return $found;
}

/**
 * Finds the stretches of HTML inside block-level elements and links.
 *
 * @param string $html HTML with comments blanked out.
 * @return array<int, array{0: int, 1: int}> Byte offsets where each outermost element starts and ends.
 */
function undergrowth_element_ranges( string $html ): array {
	preg_match_all( '#<(/?)(' . UNDERGROWTH_BLOCK_ELEMENTS . '|a)\b[^>]*>#i', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

	$ranges = array();
	$depth  = 0;
	$opened = 0;

	foreach ( $tags as $tag ) {
		// Void elements and self-closed tags never stay open.
		if ( preg_match( '#^(?:hr|col|area|embed)$#i', $tag[2][0] ) || '/>' === substr( $tag[0][0], -2 ) ) {
			continue;
		}

		if ( '' === $tag[1][0] ) {
			$opened = 0 === $depth ? $tag[0][1] : $opened;
			++$depth;
		} elseif ( $depth > 0 && 0 === --$depth ) {
			$ranges[] = array( $opened, $tag[0][1] + strlen( $tag[0][0] ) );
		}
	}

	if ( $depth > 0 ) {
		$ranges[] = array( $opened, strlen( $html ) );
	}

	return $ranges;
}

/**
 * Finds the stretches of HTML taken up by registered shortcodes.
 *
 * @param string $html HTML with comments blanked out.
 * @return array<int, array{0: int, 1: int}> Byte offsets where each shortcode starts and ends.
 */
function undergrowth_shortcode_ranges( string $html ): array {
	if ( ! preg_match_all( '/' . get_shortcode_regex() . '/', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
		return array();
	}

	$ranges = array();

	foreach ( $matches[0] as $match ) {
		$ranges[] = array( $match[1], $match[1] + strlen( $match[0] ) );
	}

	return $ranges;
}

/**
 * Tells whether any of several stretches runs across a stretch's edge.
 *
 * A stretch lying wholly inside the given one does not count.
 *
 * @param int                               $start  First byte.
 * @param int                               $end    Byte after the last.
 * @param array<int, array{0: int, 1: int}> $ranges Stretches as start and end offsets.
 * @return bool True when one of them crosses the start or the end.
 */
function undergrowth_crosses_any( int $start, int $end, array $ranges ): bool {
	foreach ( $ranges as $range ) {
		if ( $range[0] < $end && $start < $range[1] && ( $range[0] < $start || $range[1] > $end ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Tells whether a stretch of bytes overlaps any of several others.
 *
 * @param int                               $start  First byte.
 * @param int                               $end    Byte after the last.
 * @param array<int, array{0: int, 1: int}> $ranges Stretches as start and end offsets.
 * @return bool True when one of them overlaps.
 */
function undergrowth_overlaps_any( int $start, int $end, array $ranges ): bool {
	foreach ( $ranges as $range ) {
		if ( $range[0] < $end && $start < $range[1] ) {
			return true;
		}
	}

	return false;
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
	$text = html_entity_decode( implode( '', array_column( ( new Undergrowth_HTML_Text( $html ) )->text_nodes(), 0 ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	return (string) preg_replace( '/\s+/u', ' ', $text );
}
