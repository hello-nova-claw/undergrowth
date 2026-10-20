<?php
/**
 * Choosing the sentence in a source post where a link fits.
 *
 * Sentences are matched on the words the author already used, so no extra
 * embedding requests are made and the result is always the author's text.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_DISTINCTIVE_TERMS = 20;

// Hiragana, katakana and CJK ideographs. These scripts do not separate words
// with spaces, so they are matched as overlapping pairs of characters.
const UNDERGROWTH_UNSPACED_SCRIPTS = '\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}';

/**
 * Splits post content into sentences, in order.
 *
 * Block boundaries such as paragraphs, headings and list items always end a
 * sentence. Text inside a link is kept but the sentence is marked, because a
 * sentence that already holds a link is no place for another. Text inside a
 * quote or a Custom HTML block is marked too, because it is not the author's
 * own paragraph to link from.
 *
 * @param string $html Post content.
 * @return array<int, array{text: string, linked: bool, quoted: bool}> Sentences in reading order.
 */
function undergrowth_split_sentences( string $html ): array {
	$open    = "\u{E000}";
	$close   = "\u{E001}";
	$quoted  = "\u{E002}";
	$content = strip_shortcodes( $html );

	// Every block boundary ends a sentence, and so does the start of a quote
	// or of any block, so text either side of one never runs together. Text
	// that ends inside someone else's words has every line marked, because a
	// line break inside it also ends a sentence.
	$spans  = undergrowth_others_words_spans( $content );
	$span   = 0;
	$pieces = (array) preg_split( '#(</(?:p|h[1-6]|li|blockquote|pre|figcaption|td|th|dt|dd|div)\s*>|<br\s*/?>|<blockquote\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>|<!--\s+/?wp:.*?-->)#is', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE );
	$html   = '';

	for ( $i = 0, $count = count( $pieces ); $i < $count; $i += 2 ) {
		$piece_text = $pieces[ $i ][0];
		$ends_at    = $pieces[ $i + 1 ][1] ?? strlen( $content );

		// Pieces come in order, so stretches that end before this one are
		// passed for good.
		while ( isset( $spans[ $span ] ) && $spans[ $span ][1] < $ends_at ) {
			++$span;
		}

		if ( isset( $spans[ $span ] ) && $spans[ $span ][0] <= $ends_at && '' !== trim( wp_strip_all_tags( $piece_text ) ) ) {
			$piece_text = str_replace( "\n", $quoted . "\n", $piece_text ) . $quoted;
		}

		$html .= $piece_text . ( isset( $pieces[ $i + 1 ] ) ? "\n" : '' );
	}

	$html = (string) preg_replace( '#<a\b[^>]*>#i', $open, $html );
	$html = (string) preg_replace( '#</a\s*>#i', $close, $html );
	$text = html_entity_decode( wp_strip_all_tags( $html, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	$sentences = array();

	// Link text can run past a sentence end, and a link can wrap whole
	// paragraphs, so being inside a link carries over between parts and
	// between blocks.
	$inside = false;

	foreach ( preg_split( '/\n+/u', $text ) as $block ) {
		$in_quote = false !== strpos( $block, $quoted );
		$parts    = preg_split( '/(?<=[.!?…])\s+|(?<=[。！？])/u', str_replace( $quoted, '', $block ) );

		foreach ( (array) $parts as $part ) {
			$linked = false;

			// A sentence counts as linked when a link opens in it, even one
			// around an image with no text, or when some of its own text sits
			// inside a link. A closing tag at its start alone does not count.
			foreach ( (array) preg_split( "/({$open}|{$close})/u", $part, -1, PREG_SPLIT_DELIM_CAPTURE ) as $piece ) {
				if ( $open === $piece ) {
					$inside = true;
					$linked = true;
				} elseif ( $close === $piece ) {
					$inside = false;
				} elseif ( $inside && '' !== trim( (string) $piece ) ) {
					$linked = true;
				}
			}

			$part = trim( (string) preg_replace( '/\s+/u', ' ', str_replace( array( $open, $close ), '', $part ) ) );

			if ( '' !== $part ) {
				$sentences[] = array(
					'text'   => $part,
					'linked' => $linked,
					'quoted' => $in_quote,
				);
			}
		}
	}

	return $sentences;
}

/**
 * Finds the stretches of post content that are someone else's words.
 *
 * A quote holds someone else's words and a Custom HTML block holds markup
 * the author wrote by hand, so neither is the author's paragraph to change.
 * The content is read once, in order, so a long post costs one scan.
 *
 * @param string $html Post content.
 * @return array<int, array{0: int, 1: int}> Byte offsets where each stretch starts and ends, in order. A stretch still open at the end runs to PHP_INT_MAX.
 */
function undergrowth_others_words_spans( string $html ): array {
	// Only real tags and markers count, never tag-like text inside a comment
	// or a quoted attribute value. Comments and other tags are matched whole
	// and skipped, so the scan stops only on a quote's own tags or on the
	// markers of the blocks that matter. A self-closing marker such as
	// <!-- wp:html /--> opens nothing.
	$attributes = '(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>';
	$blocks     = '(?:html|quote|pullquote)';

	$scanned = preg_match_all(
		'#(?:<!--(?!\s+/?wp:' . $blocks . '[\s/]).*?-->|<(?!/?blockquote[\s/>])/?[a-z]' . $attributes . ')(*SKIP)(*FAIL)'
		. '|<(?<tag>/?)blockquote(?=[\s/>])' . $attributes
		. '|<!--\s+(?<marker>/?)wp:(?<block>' . $blocks . ')(?:\s(?:(?!-->).)*)?(?<!/)-->#is',
		$html,
		$found,
		PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL
	);

	// A scan that fails, such as at the backtrack limit, must never make
	// someone else's words look like the author's, so all of it counts.
	if ( false === $scanned ) {
		return array( array( 0, PHP_INT_MAX ) );
	}

	$depths = array();
	$spans  = array();
	$start  = 0;

	// In document order, so a stray closing tag never cancels a later quote.
	foreach ( $found as $match ) {
		$kind  = $match['block'][0] ?? 'blockquote';
		$slash = $match['tag'][0] ?? $match['marker'][0];
		$was   = array_sum( $depths ) > 0;

		$depths[ $kind ] = '/' === $slash ? max( 0, ( $depths[ $kind ] ?? 0 ) - 1 ) : ( $depths[ $kind ] ?? 0 ) + 1;

		if ( ! $was && array_sum( $depths ) > 0 ) {
			$start = $match[0][1] + strlen( $match[0][0] );
		} elseif ( $was && 0 === array_sum( $depths ) ) {
			$spans[] = array( $start, $match[0][1] );
		}
	}

	if ( array_sum( $depths ) > 0 ) {
		$spans[] = array( $start, PHP_INT_MAX );
	}

	return $spans;
}

/**
 * Tells whether a point in post content falls inside a quote or a Custom HTML block.
 *
 * @param string $before Content up to the point.
 * @return bool True when the point is inside one of those.
 */
function undergrowth_inside_others_words( string $before ): bool {
	$spans = undergrowth_others_words_spans( $before );

	return array() !== $spans && PHP_INT_MAX === $spans[ count( $spans ) - 1 ][1];
}

/**
 * Breaks text into lowercase words for matching.
 *
 * Latin and other spaced scripts give whole words of three letters or more;
 * unspaced scripts give overlapping pairs of characters.
 *
 * @param string $text Plain text.
 * @return string[] Tokens in order, with repeats.
 */
function undergrowth_tokens( string $text ): array {
	$unspaced = UNDERGROWTH_UNSPACED_SCRIPTS;

	if ( ! preg_match_all( "/[{$unspaced}]+|(?:(?![{$unspaced}])[\\p{L}\\p{N}])+/u", mb_strtolower( $text, 'UTF-8' ), $matches ) ) {
		return array();
	}

	$tokens = array();

	foreach ( $matches[0] as $run ) {
		if ( preg_match( "/^[{$unspaced}]/u", $run ) ) {
			$chars = mb_str_split( $run, 1, 'UTF-8' );
			$last  = count( $chars ) - 1;

			if ( 0 === $last ) {
				$tokens[] = $run;
			}

			for ( $i = 0; $i < $last; $i++ ) {
				$tokens[] = $chars[ $i ] . $chars[ $i + 1 ];
			}
		} elseif ( mb_strlen( $run, 'UTF-8' ) >= 3 ) {
			$tokens[] = $run;
		}
	}

	return $tokens;
}

/**
 * Counts, for each token, how many posts in a set use it.
 *
 * @param int[] $post_ids Posts to count across.
 * @return array{documents: int, frequency: array<string, int>, counts: array<int, array<string, int>>}
 */
function undergrowth_build_corpus( array $post_ids ): array {
	$frequency = array();
	$counts    = array();

	// One query for every post, rather than one per post inside the loop.
	_prime_post_caches( array_map( 'intval', $post_ids ), false, false );

	foreach ( $post_ids as $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			continue;
		}

		// Counts, not every occurrence, so a long archive fits in memory.
		$counts[ $post_id ] = array_count_values( undergrowth_tokens( undergrowth_post_text( $post ) ) );

		foreach ( array_keys( $counts[ $post_id ] ) as $token ) {
			$frequency[ $token ] = ( $frequency[ $token ] ?? 0 ) + 1;
		}
	}

	return array(
		'documents' => count( $counts ),
		'frequency' => $frequency,
		'counts'    => $counts,
	);
}

/**
 * Finds the words that most set a post apart from the rest of the site.
 *
 * Words the post uses often and other posts rarely use score highest. Every
 * word keeps a little weight, so two posts that only share common words can
 * still be matched.
 *
 * @param int   $post_id Post to describe.
 * @param array $corpus  Result of undergrowth_build_corpus().
 * @return array<string, float> Weight keyed by token, highest first.
 *
 * @phpstan-param array{documents: int, frequency: array<string, int>, counts: array<int, array<string, int>>} $corpus
 */
function undergrowth_distinctive_terms( int $post_id, array $corpus ): array {
	if ( empty( $corpus['counts'][ $post_id ] ) ) {
		return array();
	}

	$weights = array();

	// Repeats count for less and less, and rarity is squared, so a word in
	// every post keeps only a sliver of weight. A sentence full of "the" and
	// "with" never outweighs one holding a word the target is actually about.
	foreach ( $corpus['counts'][ $post_id ] as $token => $count ) {
		$documents                  = $corpus['frequency'][ $token ] ?? 1;
		$rarity                     = log( ( $corpus['documents'] + 1 ) / ( $documents + 0.5 ) );
		$weights[ (string) $token ] = ( 1 + log( $count ) ) * $rarity * $rarity;
	}

	uksort(
		$weights,
		static function ( $a, $b ) use ( $weights ) {
			$order = $weights[ $b ] <=> $weights[ $a ];

			return 0 !== $order ? $order : strcmp( (string) $a, (string) $b );
		}
	);

	return array_slice( $weights, 0, UNDERGROWTH_DISTINCTIVE_TERMS, true );
}

/**
 * Picks the sentence in the source post that best fits a link to the target.
 *
 * @param int   $source_id Post the link would sit in.
 * @param int   $target_id Post the link would point to.
 * @param array $corpus    Result of undergrowth_build_corpus().
 * @return array{text: string, index: int}|null The sentence and its position, or null when none fits.
 *
 * @phpstan-param array{documents: int, frequency: array<string, int>, counts: array<int, array<string, int>>} $corpus
 */
function undergrowth_choose_sentence( int $source_id, int $target_id, array $corpus ): ?array {
	$source = get_post( $source_id );
	$terms  = undergrowth_distinctive_terms( $target_id, $corpus );

	if ( ! $source instanceof WP_Post || array() === $terms ) {
		return null;
	}

	$best       = null;
	$best_score = 0.0;

	foreach ( undergrowth_split_sentences( $source->post_content ) as $index => $sentence ) {
		if ( $sentence['linked'] || $sentence['quoted'] ) {
			continue;
		}

		$score = 0.0;

		foreach ( array_unique( undergrowth_tokens( $sentence['text'] ) ) as $token ) {
			$score += $terms[ $token ] ?? 0.0;
		}

		if ( $score > $best_score ) {
			$best_score = $score;
			$best       = array(
				'text'  => $sentence['text'],
				'index' => $index,
			);
		}
	}

	return $best;
}
