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

	$corpus = undergrowth_build_corpus( array_unique( array_merge( array_keys( undergrowth_comparable_vectors( $source_id ) ), array( $source_id, $target->ID ) ) ) );
	$linked = null;

	foreach ( array_keys( undergrowth_distinctive_terms( $target->ID, $corpus ) ) as $term ) {
		$linked = undergrowth_link_word( $source->post_content, $sentence, (string) $term, (string) get_permalink( $target ) );

		if ( null !== $linked ) {
			break;
		}
	}

	if ( null === $linked ) {
		return 'no_word';
	}

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
	// A pair of characters from an unspaced script has no word boundaries.
	$boundary = preg_match( '/[' . UNDERGROWTH_UNSPACED_SCRIPTS . ']/u', $term ) ? '' : '(?<![\p{L}\p{N}])';
	$pattern  = '/' . $boundary . preg_quote( $term, '/' ) . ( '' === $boundary ? '' : '(?![\p{L}\p{N}])' ) . '/iu';

	foreach ( undergrowth_linkable_paragraphs( $content ) as $paragraph ) {
		list( $inner, $inner_start, $plain ) = $paragraph;

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

			if ( $depth > 0 ) {
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

	if ( preg_match_all( '#<p\b[^>]*>(.*?)</p>#is', $content, $paragraphs, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $paragraphs[1] as $paragraph ) {
			$found[ $paragraph[1] ] = $paragraph[0];
		}
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
 * markup, no shortcode, and do not sit inside a block-level element or a
 * link that spans the blank lines around them. The element names are the
 * ones wpautop() treats as blocks, and a few that hold no prose.
 *
 * @param string $content Post content.
 * @return array<int, string> Each paragraph's HTML, keyed by its byte offset in the content.
 */
function undergrowth_classic_paragraphs( string $content ): array {
	$blocks     = 'table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary|iframe|script|object|embed|video|audio|select|textarea|math|svg';
	$shortcodes = '/' . get_shortcode_regex() . '/';
	$found      = array();

	// Elements still open where each stretch starts, read once in order.
	preg_match_all( '#<(/?)(' . $blocks . '|a)\b[^>]*>#i', $content, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
	$tag   = 0;
	$depth = 0;

	foreach ( (array) preg_split( '/\n\s*\n/', $content, -1, PREG_SPLIT_OFFSET_CAPTURE ) as $chunk ) {
		list( $text, $offset ) = $chunk;

		while ( isset( $tags[ $tag ] ) && $tags[ $tag ][0][1] < $offset ) {
			// Void elements and self-closed tags never stay open.
			if ( ! preg_match( '#^(?:hr|col|area|embed)$#i', $tags[ $tag ][2][0] ) && '/>' !== substr( $tags[ $tag ][0][0], -2 ) ) {
				$depth = max( 0, $depth + ( '' === $tags[ $tag ][1][0] ? 1 : -1 ) );
			}
			++$tag;
		}

		$inner = trim( $text );

		if ( '' === $inner || $depth > 0 || preg_match( '#</?(?:' . $blocks . ')\b#i', $inner ) || preg_match( $shortcodes, $inner ) ) {
			continue;
		}

		$found[ $offset + strpos( $text, $inner ) ] = $inner;
	}

	return $found;
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
