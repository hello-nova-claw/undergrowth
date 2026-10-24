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

// How many of a target's words count as what it is about, when choosing
// the word a link to it sits on.
const UNDERGROWTH_CENTRAL_TERMS = 25;

add_action( 'clean_post_cache', 'undergrowth_forget_reusable_corpus' );
// Another site's posts table can hold the same IDs.
add_action( 'switch_blog', 'undergrowth_forget_reusable_corpus' );

// Thai, Lao, Tibetan, Burmese, Khmer, hiragana, katakana and CJK ideographs.
// These scripts do not separate words with spaces, so they are matched as
// overlapping pairs of characters.
const UNDERGROWTH_UNSPACED_SCRIPTS = '\x{0E00}-\x{0EFF}\x{0F00}-\x{0FFF}\x{1000}-\x{109F}\x{1780}-\x{17FF}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}';

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
		// A closing quotation mark or bracket can sit between the stop and the
		// space. Besides Latin stops, these are the danda, Urdu, Armenian and
		// Ethiopic full stops and the Greek and Arabic question marks. Unicode
		// normalisation turns the Greek one into a semicolon, which ends a
		// sentence only after a Greek letter. CJK and Burmese stops need no
		// space after them.
		$in_quote = false !== strpos( $block, $quoted );
		$parts    = preg_split( '/(?:[.!?…।॥۔։።؟\x{037E}]|(?<=\p{Greek});)[”’"\'»)\]]*\K\s+|(?<=[。！？။])/u', str_replace( $quoted, '', $block ) );

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

	// Only letters, marks and digits of an unspaced script count, so a stop
	// such as the Burmese one is never part of a pair.
	if ( ! preg_match_all( "/(?:(?=[\\p{L}\\p{M}\\p{N}])[{$unspaced}])+|(?:(?![{$unspaced}])[\\p{L}\\p{N}])+/u", mb_strtolower( $text, 'UTF-8' ), $matches ) ) {
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
 * Counts how often a post uses each of its words.
 *
 * @param WP_Post $post Post to count.
 * @return array<string, int> Number of uses keyed by token.
 */
function undergrowth_post_token_counts( WP_Post $post ): array {
	// Counts, not every occurrence, so a long post stays small.
	return array_count_values( undergrowth_tokens( undergrowth_post_text( $post ) ) );
}

/**
 * Counts, for each token, how many posts in a set use it.
 *
 * Only these totals are kept. A post's own word counts are worked out again
 * when a sentence is chosen for a link to it, which happens for a handful of
 * posts, so a long archive never sits in memory word by word.
 *
 * @param int[] $post_ids Posts to count across.
 * @return array{documents: int, frequency: array<string, int>, posts: array<int, true>}
 */
function undergrowth_build_corpus( array $post_ids ): array {
	global $wpdb;

	$frequency = array();
	$posts     = array();
	$ids       = array_values( array_unique( array_map( 'intval', $post_ids ) ) );

	foreach ( array_chunk( $ids, UNDERGROWTH_POST_CHUNK ) as $chunk ) {
		// Read straight from the table, a chunk at a time, so the content of
		// every post never fills the post cache for the rest of the request.
		$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Caching every post is what this avoids; there is one placeholder for each ID in the chunk.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_title, post_content FROM %i WHERE ID IN (' . $placeholders . ')', $wpdb->posts, ...$chunk ) );

		foreach ( $rows as $row ) {
			$posts[ (int) $row->ID ] = true;

			foreach ( array_keys( undergrowth_post_token_counts( new WP_Post( $row ) ) ) as $token ) {
				$frequency[ $token ] = ( $frequency[ $token ] ?? 0 ) + 1;
			}
		}

		// wpdb keeps the last result set until the next query, which would
		// leave a chunk of posts behind for the whole refresh.
		unset( $rows );
		$wpdb->flush();
	}

	return array(
		'documents' => count( $posts ),
		'frequency' => $frequency,
		'posts'     => $posts,
	);
}

/**
 * Counts words across a set of posts, reusing the counts last made in this
 * request when the set is the same.
 *
 * Writing the files of a post and its neighbours after one embed asks for
 * the same counts again and again, and each count reads every post.
 *
 * @param int[] $post_ids Posts to count across.
 * @return array{documents: int, frequency: array<string, int>, posts: array<int, true>}
 */
function undergrowth_reusable_corpus( array $post_ids ): array {
	global $undergrowth_reusable_corpus;

	$ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
	sort( $ids );
	$key = implode( ',', $ids );

	if ( ! is_array( $undergrowth_reusable_corpus ) || $key !== $undergrowth_reusable_corpus['key'] ) {
		$undergrowth_reusable_corpus = array(
			'key'    => $key,
			'corpus' => undergrowth_build_corpus( $ids ),
		);
	}

	return $undergrowth_reusable_corpus['corpus'];
}

/**
 * Drops the reused word counts once any post changes, or another site's
 * posts are read.
 */
function undergrowth_forget_reusable_corpus(): void {
	global $undergrowth_reusable_corpus;

	$undergrowth_reusable_corpus = null;
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
 * @param int   $limit   Most words to return, or 0 for every word the post uses.
 * @return array<string, float> Weight keyed by token, highest first.
 *
 * @phpstan-param array{documents: int, frequency: array<string, int>, posts: array<int, true>} $corpus
 */
function undergrowth_distinctive_terms( int $post_id, array $corpus, int $limit = UNDERGROWTH_DISTINCTIVE_TERMS ): array {
	if ( ! isset( $corpus['posts'][ $post_id ] ) ) {
		return array();
	}

	$post = undergrowth_uncached_post( $post_id );

	if ( null === $post ) {
		return array();
	}

	$weights = array();

	// Repeats count for less and less, and rarity is squared, so a word in
	// every post keeps only a sliver of weight. A sentence full of "the" and
	// "with" never outweighs one holding a word the target is actually about.
	foreach ( undergrowth_post_token_counts( $post ) as $token => $count ) {
		$documents                  = $corpus['frequency'][ $token ] ?? 1;
		$rarity                     = log( ( $corpus['documents'] + 1 ) / ( $documents + 0.5 ) );
		$weights[ (string) $token ] = ( 1 + log( $count ) ) * $rarity * $rarity;
	}

	return undergrowth_heaviest_terms( $weights, $limit );
}

/**
 * Ranks the words of some text a link to a post could sit on, by how much
 * the post is about each.
 *
 * Only words in the text can be linked, so only those are ranked. Rarity
 * alone favours words the target happens to use once or twice, so it is
 * weighed against how often the target uses the word, and a word the target
 * uses only once counts for a quarter. A word in the target's title or one
 * of its headings says most plainly where the link goes, so it ranks above
 * every other word, unless half the site's posts use it.
 *
 * Any other word must be among the few the target is most about, and must
 * name something: function words, vague common words and words the target
 * uses as verbs or adverbs never carry a link (see anchor-words.php). When
 * no word in the text passes, nothing is returned, so no weak link is made.
 *
 * @param int    $target_id Post the link would point to.
 * @param string $text      Plain text the link would sit in.
 * @param array  $corpus    Result of undergrowth_build_corpus().
 * @param int    $limit     Most words to return, or 0 for every word that may carry the link.
 * @return array<string, float> Weight keyed by token, highest first.
 *
 * @phpstan-param array{documents: int, frequency: array<string, int>, posts: array<int, true>} $corpus
 */
function undergrowth_anchor_terms( int $target_id, string $text, array $corpus, int $limit = UNDERGROWTH_DISTINCTIVE_TERMS ): array {
	$target = isset( $corpus['posts'][ $target_id ] ) ? undergrowth_uncached_post( $target_id ) : null;

	if ( null === $target ) {
		return array();
	}

	$locale = undergrowth_post_language( $target );
	$stop   = undergrowth_anchor_stopwords( $locale );
	$never  = array_flip( $stop['never'] );
	$common = array_flip( $stop['named'] );
	$exact  = array();
	$names  = array();

	// A title word in half the site's posts, such as "part" in "Economy,
	// part 2", names nothing, and neither do its other forms.
	foreach ( array_unique( undergrowth_tokens( $target->post_title . ' ' . implode( ' ', undergrowth_headings( $target->post_content ) ) ) ) as $name ) {
		if ( 2 * ( $corpus['frequency'][ $name ] ?? 1 ) < $corpus['documents'] ) {
			$exact[ (string) $name ]                          = true;
			$names[ undergrowth_word_stem( (string) $name ) ] = true;
		}
	}

	$weights = array();
	$tfidf   = array();

	foreach ( undergrowth_post_token_counts( $target ) as $token => $count ) {
		$token = (string) $token;
		$named = isset( $names[ undergrowth_word_stem( $token ) ] );

		if ( isset( $never[ $token ] ) || ( isset( $common[ $token ] ) && ! $named ) ) {
			continue;
		}

		$rarity            = log( ( $corpus['documents'] + 1 ) / ( ( $corpus['frequency'][ $token ] ?? 1 ) + 0.5 ) );
		$weights[ $token ] = ( $count > 1 ? $count : 0.25 ) * $rarity * $rarity;
		$tfidf[ $token ]   = ( $count > 1 ? $count : 0.25 ) * $rarity;
	}

	if ( array() === $weights ) {
		return array();
	}

	// The target's central words are found among all of its words, before
	// the text narrows them, so a word the source happens to share is never
	// central only because nothing better is shared.
	$central = undergrowth_heaviest_terms( $tfidf, UNDERGROWTH_CENTRAL_TERMS );
	$unnamed = null;
	$top     = max( $weights );
	$ranked  = array();

	foreach ( array_unique( undergrowth_tokens( $text ) ) as $token ) {
		$token = (string) $token;

		if ( ! isset( $weights[ $token ] ) ) {
			continue;
		}

		if ( isset( $names[ undergrowth_word_stem( $token ) ] ) && 2 * ( $corpus['frequency'][ $token ] ?? 1 ) < $corpus['documents'] ) {
			// The very word of the title or heading comes before another
			// form of it.
			$ranked[ $token ] = $weights[ $token ] + ( isset( $exact[ $token ] ) ? 2 : 1 ) * $top;
		} elseif ( isset( $central[ $token ] ) ) {
			// The target is read word by word only once a central word needs it.
			$unnamed = $unnamed ?? undergrowth_unnamed_words( undergrowth_post_text( $target ), $locale );

			if ( ! isset( $unnamed[ $token ] ) ) {
				$ranked[ $token ] = $weights[ $token ];
			}
		}
	}

	return undergrowth_heaviest_terms( $ranked, $limit );
}

/**
 * Cuts a common English ending off a word, so "walked" and "walking" both
 * match a post titled "Walking".
 *
 * @param string $token Lowercase word.
 * @return string The word without -s, -es, -ed, -ing or -er, when at least four letters are left.
 */
function undergrowth_word_stem( string $token ): string {
	return (string) preg_replace( '/^(\p{L}{4,}?)(?:ing|ers|er|ed|es|s)$/u', '$1', $token );
}

/**
 * Lists the plain text of a post's headings.
 *
 * @param string $content Post content.
 * @return string[] Each heading's text, in order.
 */
function undergrowth_headings( string $content ): array {
	if ( ! preg_match_all( '#<h[1-6]\b[^>]*>(.*?)</h[1-6]\s*>#is', $content, $matches ) ) {
		return array();
	}

	return array_map( 'wp_strip_all_tags', $matches[1] );
}

/**
 * Sorts words by weight, heaviest first, and keeps the first few.
 *
 * Ties go in alphabetical order, so the same posts always give the same words.
 *
 * @param array<string, float> $weights Weight keyed by token.
 * @param int                  $limit   Most words to keep, or 0 for all.
 * @return array<string, float>
 */
function undergrowth_heaviest_terms( array $weights, int $limit ): array {
	uksort(
		$weights,
		static function ( $a, $b ) use ( $weights ) {
			$order = $weights[ $b ] <=> $weights[ $a ];

			return 0 !== $order ? $order : strcmp( (string) $a, (string) $b );
		}
	);

	return $limit > 0 ? array_slice( $weights, 0, $limit, true ) : $weights;
}

/**
 * Reads a post's title and content without adding it to the post cache.
 *
 * A refresh can try many targets before one fits, and none of them should
 * stay in memory afterwards.
 *
 * @param int $post_id Post to read.
 * @return WP_Post|null The post, or null when it no longer exists.
 */
function undergrowth_uncached_post( int $post_id ): ?WP_Post {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching every target tried is what this avoids.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ID, post_title, post_content FROM %i WHERE ID = %d', $wpdb->posts, $post_id ) );
	$wpdb->flush();

	return is_object( $row ) ? new WP_Post( $row ) : null;
}

/**
 * Picks the sentence in the source post that best fits a link to the target.
 *
 * A sentence already holding another proposal is passed over for the next
 * best one that fits, so accepting several links never crowds them into one
 * sentence. It is still chosen when nothing else fits.
 *
 * @param int      $source_id Post the link would sit in.
 * @param int      $target_id Post the link would point to.
 * @param array    $corpus    Result of undergrowth_build_corpus().
 * @param string[] $taken     Sentences already holding another of the post's proposals.
 * @return array{text: string, index: int}|null The sentence and its position, or null when none fits.
 *
 * @phpstan-param array{documents: int, frequency: array<string, int>, posts: array<int, true>} $corpus
 */
function undergrowth_choose_sentence( int $source_id, int $target_id, array $corpus, array $taken = array() ): ?array {
	$source = get_post( $source_id );

	return $source instanceof WP_Post ? undergrowth_choose_sentence_in( $source, $target_id, $corpus, $taken ) : null;
}

/**
 * Picks the sentence in a post's content that best fits a link to the
 * target, as undergrowth_choose_sentence() does.
 *
 * The post need not be saved, so a draft scan can choose from the content
 * the editor holds.
 *
 * @param WP_Post  $source    Post the link would sit in.
 * @param int      $target_id Post the link would point to.
 * @param array    $corpus    Result of undergrowth_build_corpus().
 * @param string[] $taken     Sentences already holding another of the post's proposals.
 * @return array{text: string, index: int}|null The sentence and its position, or null when none fits.
 *
 * @phpstan-param array{documents: int, frequency: array<string, int>, posts: array<int, true>} $corpus
 */
function undergrowth_choose_sentence_in( WP_Post $source, int $target_id, array $corpus, array $taken = array() ): ?array {
	// Only sentences from paragraphs a link may go in are scored, each with
	// its own paragraph's flags, so a heading or list item is never chosen,
	// even when it shares a paragraph's words or text. The target's words
	// are ranked among the ones those sentences hold.
	$sentences = array_filter(
		undergrowth_linkable_sentences( $source->post_content ),
		static function ( $sentence ) {
			return ! $sentence['linked'];
		}
	);

	$terms = undergrowth_anchor_terms( $target_id, implode( ' ', array_column( $sentences, 'text' ) ), $corpus );
	$taken = array_flip( $taken );
	$best  = array(
		'free'  => null,
		'taken' => null,
	);
	$top   = array(
		'free'  => 0.0,
		'taken' => 0.0,
	);

	foreach ( $sentences as $sentence ) {
		$score = 0.0;
		$kind  = isset( $taken[ $sentence['text'] ] ) ? 'taken' : 'free';

		foreach ( array_unique( undergrowth_tokens( $sentence['text'] ) ) as $token ) {
			$score += $terms[ $token ] ?? 0.0;
		}

		if ( $score > $top[ $kind ] ) {
			$top[ $kind ]  = $score;
			$best[ $kind ] = $sentence['text'];
		}
	}

	$best = $best['free'] ?? $best['taken'];

	if ( null === $best ) {
		return null;
	}

	// The index is where the sentence sits among all of the post's
	// sentences: the first unlinked one with the same text.
	foreach ( undergrowth_split_sentences( $source->post_content ) as $index => $sentence ) {
		if ( $best === $sentence['text'] && ! $sentence['linked'] && ! $sentence['quoted'] ) {
			return array(
				'text'  => $best,
				'index' => $index,
			);
		}
	}

	return null;
}

/**
 * Lists the sentences of the paragraphs a link may be added to.
 *
 * @param string $content Post content.
 * @return array<int, array{text: string, linked: bool, quoted: bool}> Sentences in reading order.
 */
function undergrowth_linkable_sentences( string $content ): array {
	$sentences = array();

	// Comments hold no links, and blanking them keeps every offset.
	$scan = (string) preg_replace_callback(
		'/<!--.*?(?:-->|$)/s',
		static function ( $comment ) {
			return str_repeat( ' ', strlen( $comment[0] ) );
		},
		$content
	);

	foreach ( undergrowth_linkable_paragraphs( $content ) as $paragraph ) {
		// A link can wrap a whole paragraph, so one still open where the
		// paragraph starts marks every sentence in it as linked.
		$before  = substr( $scan, 0, $paragraph[1] );
		$wrapped = preg_match_all( '#<a\b#i', $before ) > preg_match_all( '#</a\s*>#i', $before );

		foreach ( undergrowth_split_sentences( '<p>' . $paragraph[0] . '</p>' ) as $sentence ) {
			$sentence['linked'] = $sentence['linked'] || $wrapped;
			$sentences[]        = $sentence;
		}
	}

	return $sentences;
}
