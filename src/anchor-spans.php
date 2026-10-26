<?php
/**
 * Choosing the run of words a link sits on.
 *
 * A link on one word reads like an accident. A link on the few words that
 * name the target, such as "seed ledger" or the target's own title, reads
 * like one the author chose. The words are always the author's own, taken
 * from the sentence as written; nothing is added or changed.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

// Longest run of words a link may sit on.
const UNDERGROWTH_MAX_ANCHOR_WORDS = 4;

// A word, with any hyphens or apostrophes inside it, so "self-reliance" and
// "neighbor’s" are each one word.
const UNDERGROWTH_ANCHOR_WORD = '[\p{L}\p{N}]+(?:[\'’\-\x{2010}\x{2011}][\p{L}\p{N}]+)*';

/**
 * Splits text into words, each with the clause it belongs to.
 *
 * Anything between two words other than spaces, such as a comma, a dash, a
 * bracket or a quotation mark, starts a new clause, so a link never runs
 * across it.
 *
 * @param string $text Plain text.
 * @return array<int, array{text: string, lower: string, offset: int, length: int, clause: int}>
 *         Words in order, with character offsets.
 */
function undergrowth_anchor_words( string $text ): array {
	if ( ! preg_match_all( '/' . UNDERGROWTH_ANCHOR_WORD . '/u', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
		return array();
	}

	$words  = array();
	$clause = 0;
	$end    = 0;

	foreach ( $matches[0] as $match ) {
		list( $word, $at ) = $match;

		if ( array() !== $words && '' !== trim( substr( $text, $end, $at - $end ) ) ) {
			++$clause;
		}

		$end     = $at + strlen( $word );
		$words[] = array(
			'text'   => $word,
			'lower'  => mb_strtolower( $word, 'UTF-8' ),
			'offset' => mb_strlen( substr( $text, 0, $at ), 'UTF-8' ),
			'length' => mb_strlen( $word, 'UTF-8' ),
			'clause' => $clause,
		);
	}

	return $words;
}

/**
 * Lists the runs of words in a sentence a link to a post could sit on,
 * best first.
 *
 * A run of the target's title comes first, the longest first, because it
 * says most plainly where the link goes. Then, for each word the target is
 * about in turn, the phrase that word ends or names, such as "seed ledger"
 * or "Fair Haven Hill", or the words around it that read as one unit. A
 * word alone comes last, after every longer run. A run never crosses
 * punctuation, never starts or ends on a function word or a word under three
 * letters, and never holds more than four words.
 *
 * Phrases are only guessed in English. In another language a run of the
 * title is still found, and otherwise the word stands alone.
 *
 * @param string               $sentence Sentence the link would sit in.
 * @param array<string, float> $terms    Result of undergrowth_anchor_terms() for the sentence.
 * @param string               $title    Title of the post the link would point to.
 * @param string               $locale   Language of that post, such as en_US.
 * @return array<int, array{offset: int, length: int}> Character offset and length of each run in the sentence.
 */
function undergrowth_anchor_spans( string $sentence, array $terms, string $title, string $locale ): array {
	// $locale is the target's language, but the sentence is the source's.
	// Vectors are only compared within one language, so they are the same.
	$words   = undergrowth_anchor_words( $sentence );
	$never   = array_flip( undergrowth_anchor_stopwords( $locale )['never'] );
	$english = 0 === strpos( $locale, 'en' );
	$count   = count( $words );
	$spans   = array();

	$edge = static function ( array $word ) use ( $never ): bool {
		return undergrowth_is_long_enough( $word['lower'] ) && ! isset( $never[ $word['lower'] ] );
	};

	$add = static function ( int $first, int $last ) use ( &$spans, $words ): void {
		$offset = $words[ $first ]['offset'];

		$spans[ $offset . ':' . $last ] = array(
			'offset' => $offset,
			'length' => $words[ $last ]['offset'] + $words[ $last ]['length'] - $offset,
		);
	};

	// Runs of the title, longest first. Each must hold a word the target is
	// about, so a title word too common to name anything never pulls in a run.
	$title_runs = array();

	foreach ( undergrowth_anchor_words( $title ) as $word ) {
		$title_runs[ $word['clause'] ][] = undergrowth_word_stem( $word['lower'] );
	}

	$title_text = array_map(
		static function ( $stems ) {
			return ' ' . implode( ' ', $stems ) . ' ';
		},
		$title_runs
	);

	for ( $size = min( UNDERGROWTH_MAX_ANCHOR_WORDS, $count ); $size >= 2; $size-- ) {
		for ( $first = 0; $first + $size <= $count; $first++ ) {
			$last = $first + $size - 1;

			if ( $words[ $first ]['clause'] !== $words[ $last ]['clause'] || ! $edge( $words[ $first ] ) || ! $edge( $words[ $last ] ) ) {
				continue;
			}

			$run   = array_slice( $words, $first, $size );
			$stems = ' ' . implode( ' ', array_map( 'undergrowth_word_stem', array_column( $run, 'lower' ) ) ) . ' ';
			$about = array_intersect_key( $terms, array_flip( array_column( $run, 'lower' ) ) );

			foreach ( $title_text as $text ) {
				if ( array() !== $about && false !== strpos( $text, $stems ) ) {
					$add( $first, $last );
					break;
				}
			}
		}
	}

	// Phrases and close runs for each word in turn, then two things named
	// together, then a word alone, so a link sits on one word only when the
	// sentence offers nothing longer. A word of the target's title alone
	// still comes before runs around words the target is less about, which
	// would not name it at all.
	$later = array(
		'now'    => array(),
		'joined' => array(),
		'alone'  => array(),
	);
	$named = array_flip( array_merge( array(), ...array_values( $title_runs ) ) );

	foreach ( array_keys( $terms ) as $term ) {
		foreach ( $words as $index => $word ) {
			if ( (string) $term !== $word['lower'] ) {
				continue;
			}

			$titled = isset( $named[ undergrowth_word_stem( $word['lower'] ) ] );

			if ( $english ) {
				$phrase = undergrowth_anchor_phrase( $words, $index, $terms, $never );
				$runs   = undergrowth_anchor_neighbours( $words, $index, $never );

				$later['now'] = array_merge( $later['now'], null === $phrase ? array() : array( $phrase ), $runs['close'], $titled ? $runs['joined'] : array() );

				if ( ! $titled ) {
					$later['joined'] = array_merge( $later['joined'], $runs['joined'] );
				}
			}

			$later[ $titled ? 'now' : 'alone' ][] = array( $index, $index );
		}
	}

	foreach ( array_merge( $later['now'], $later['joined'], $later['alone'] ) as $run ) {
		if ( $run[0] === $run[1] || ( $edge( $words[ $run[0] ] ) && $edge( $words[ $run[1] ] ) ) ) {
			$add( $run[0], $run[1] );
		}
	}

	return array_values( $spans );
}

/**
 * Finds the runs of two to four words around a word that read as one
 * unit, when it heads no phrase of its own.
 *
 * Close runs come first: a word joined to another by "of", as in "the
 * composition of friendship" or "the impression of your character", and a
 * word that describes the one after it, as in "my honest opinion". Then
 * two things named together, as in "sounds and sights", which read less
 * surely as one unit.
 *
 * Only English is read this way.
 *
 * @param array<int, array{text: string, lower: string, offset: int, length: int, clause: int}> $words          Result of undergrowth_anchor_words().
 * @param int                                                                                   $index          Position of the word.
 * @param array<string, int>                                                                    $function_words Function words, as keys.
 * @return array{close: array<int, array{0: int, 1: int}>, joined: array<int, array{0: int, 1: int}>}
 *         First and last position of each run, best first.
 */
function undergrowth_anchor_neighbours( array $words, int $index, array $function_words ): array {
	$pointers = array_flip( explode( ' ', 'a an the this that these those his her its our their my your thy' ) );
	$openers  = $pointers + array_flip( explode( ' ', 'of in on at by for from with into upon about' ) );
	$subjects = array_flip( explode( ' ', 'i we they he she you who to will would can could shall should may might must did does' ) );
	$weak     = array_flip( undergrowth_anchor_stopwords( 'en_US' )['named'] );
	$clause   = $words[ $index ]['clause'];
	$lower    = static function ( int $at ) use ( $words, $clause ): ?string {
		return isset( $words[ $at ] ) && $clause === $words[ $at ]['clause'] ? $words[ $at ]['lower'] : null;
	};
	$content  = static function ( int $at ) use ( $lower, $function_words ): bool {
		$word = $lower( $at );

		return null !== $word && undergrowth_is_long_enough( $word ) && ! isset( $function_words[ $word ] ) && ! preg_match( '/ly$/u', $word );
	};
	// A word ends a run unless it describes the word after it, as
	// "haughty" does in "the ranks of haughty men", or belongs to it, as
	// "earth’s" does.
	$ends = static function ( int $at ) use ( $lower, $content, $weak ): bool {
		$after = $lower( $at + 1 );

		return $content( $at ) && ! preg_match( '/[\'’]s$/u', (string) $lower( $at ) ) && ! ( $content( $at + 1 ) && ! isset( $weak[ $after ] ) );
	};
	// Endings only a describing word has. After "of", as in "haughty",
	// -y counts too, but not before a verb, as in "the country offers".
	$describes = static function ( int $at, string $more = '' ) use ( $lower ): bool {
		return (bool) preg_match( '/(?:ous|ful|al|ic|ive|able|ible|ant|ent|ish|less|ary|est|ern' . $more . ')$/u', (string) $lower( $at ) );
	};
	$verb      = static function ( int $at ) use ( $lower ): bool {
		return (bool) preg_match( '/(?:ing|[^e]ed)$/u', (string) $lower( $at ) );
	};
	$runs      = array(
		'close'  => array(),
		'joined' => array(),
	);

	// "X of Y", with the word as Y, then as X. Y may follow a word such as
	// "the" or "your", as in "the heart of this matter". X after "we" or
	// "to" is a verb, as in "we eat of the bread".
	$first = null !== $lower( $index - 1 ) && isset( $pointers[ $lower( $index - 1 ) ] ) ? $index - 3 : $index - 2;

	if ( 'of' === $lower( $first + 1 ) && $content( $first ) && ! $verb( $first ) && ! isset( $subjects[ (string) $lower( $first - 1 ) ] ) ) {
		$runs['close'][] = array( $first, $index );
	}

	// Y may run on to the word it describes, as in "the ranks of haughty
	// men", within four words.
	if ( 'of' === $lower( $index + 1 ) ) {
		$at = null !== $lower( $index + 2 ) && isset( $pointers[ $lower( $index + 2 ) ] ) ? $index + 3 : $index + 2;

		if ( $ends( $at ) ) {
			$runs['close'][] = array( $index, $at );
		} elseif ( $at - $index + 1 < UNDERGROWTH_MAX_ANCHOR_WORDS && $describes( $at, '|y' ) && $ends( $at + 1 ) ) {
			$runs['close'][] = array( $index, $at + 1 );
		}
	}

	// The word describing the next one, after a word that opens a phrase.
	// It must have an ending a describing word has, as in "my honest
	// opinion" or "the actual horizon", so a noun is never joined to the
	// verb after it, as in "the heart bound". Nouns end in -al, -ant, -ent,
	// -est or -ish too, so after those a word ending in -s, as in "the plant
	// grows", is read as a verb. After an ending only describing words have,
	// such as -ous or -ful, it is a plural, as in "the curious habits".
	$next = $lower( $index + 1 );

	if ( null !== $lower( $index - 1 ) && isset( $openers[ $lower( $index - 1 ) ] ) && $describes( $index )
		&& $content( $index + 1 ) && ! isset( $weak[ $next ] ) && ! $verb( $index + 1 )
		&& ( ! preg_match( '/[^su\'’]s$/u', (string) $next ) || preg_match( '/(?:ous|ful|ive|able|ible|less|ic|ary|ern)$/u', $words[ $index ]['lower'] ) ) ) {
		$runs['close'][] = array( $index, $index + 1 );
	}

	// Two things named together, as in "sounds and sights" or "our
	// predecessors and our contemporaries". A pair after "I", "to", "will"
	// or the like, or before "the", "it" or "out", is a pair of verbs, as
	// in "to stand or walk upon" or "rot and destroy the crop". A pair of
	// describing words, as in "honest and natural", names nothing.
	$objects = $pointers + array_flip( explode( ' ', 'it them him me us out off upon' ) );
	$pair    = static function ( int $first, int $last ) use ( $lower, $content, $ends, $verb, $describes, $objects, $subjects ): bool {
		return $content( $first ) && $ends( $last ) && ! $verb( $first ) && ! $verb( $last ) && ! ( $describes( $first ) && $describes( $last ) )
			&& ! isset( $subjects[ (string) $lower( $first - 1 ) ] ) && ! isset( $objects[ (string) $lower( $last + 1 ) ] );
	};

	foreach ( array( 'and', 'or' ) as $joint ) {
		$other = $index + 2 + ( null !== $lower( $index + 2 ) && isset( $pointers[ $lower( $index + 2 ) ] ) ? 1 : 0 );

		if ( $joint === $lower( $index + 1 ) && $pair( $index, $other ) ) {
			$runs['joined'][] = array( $index, $other );
		}

		$other = $index - 2 - ( null !== $lower( $index - 1 ) && isset( $pointers[ $lower( $index - 1 ) ] ) ? 1 : 0 );

		if ( $joint === $lower( $other + 1 ) && $pair( $other, $index ) ) {
			$runs['joined'][] = array( $other, $index );
		}
	}

	return $runs;
}

/**
 * Finds the English phrase a word is the heart of.
 *
 * Words before it that describe it are taken, as in "the seed ledger", up
 * to the article or preposition that opens the phrase. A run that a pronoun
 * or an auxiliary opens begins with a verb, as in "I love yacon", so its
 * first word is left out. A word after it joins only when the target is
 * about that word too, as in "yacon syrup". A capitalised name runs on
 * either side, as in "Fair Haven Hill".
 *
 * @param array<int, array{text: string, lower: string, offset: int, length: int, clause: int}> $words Result of undergrowth_anchor_words().
 * @param int                                                                                   $index Position of the word.
 * @param array<string, float>                                                                  $terms Words the target is about.
 * @param array<string, int>                                                                    $function_words Function words, as keys.
 * @return array{0: int, 1: int}|null First and last position of a phrase longer than the word, or null.
 */
function undergrowth_anchor_phrase( array $words, int $index, array $terms, array $function_words ): ?array {
	$openers = array_flip( explode( ' ', 'a an the this these those his her its our their my your thy every each another such some any no of in on at by for from with into onto upon about over under through after before between among amongst without within near beside across along around behind beyond during toward towards' ) );
	$weak    = array_flip( undergrowth_anchor_stopwords( 'en_US' )['named'] );
	$clause  = $words[ $index ]['clause'];
	$named   = static function ( int $at ) use ( $words, $clause, $index ): bool {
		return isset( $words[ $at ] ) && $clause === $words[ $at ]['clause'] && preg_match( '/^\p{Lu}/u', $words[ $at ]['text'] )
			&& ( $at > 0 || $index > 0 );
	};
	$first   = $index;
	$last    = $index;

	if ( $named( $index ) ) {
		while ( $last - $first + 1 < UNDERGROWTH_MAX_ANCHOR_WORDS && $named( $first - 1 ) && ! isset( $function_words[ $words[ $first - 1 ]['lower'] ] ) ) {
			--$first;
		}

		while ( $last - $first + 1 < UNDERGROWTH_MAX_ANCHOR_WORDS && $named( $last + 1 ) && ! isset( $function_words[ $words[ $last + 1 ]['lower'] ] ) ) {
			++$last;
		}
	}

	if ( $first === $index ) {
		// Describing words before it. A function word, an adverb, or a word
		// ending in -ing, which is far more often a verb, as in "a passage
		// betraying affection", ends the phrase. So does a word ending in -s
		// before one that does not, as in "the walker enjoys comparative
		// freedom", unless it is "curious" or "farmer’s". A vague common word such as "true", or one ending in
		// -ed, describes only straight after an article, as in "the true
		// doctrine" or "the frozen middle", but not in "dashed water". Words that count or point, such as "little", "certain"
		// or "former", describe nothing.
		$at = $first - 1;

		while ( $last - $first + 1 < UNDERGROWTH_MAX_ANCHOR_WORDS && isset( $words[ $at ] ) && $clause === $words[ $at ]['clause'] ) {
			$word   = $words[ $at ]['lower'];
			$about  = isset( $terms[ $word ] );
			$opened = isset( $words[ $at - 1 ], $openers[ $words[ $at - 1 ]['lower'] ] ) && $clause === $words[ $at - 1 ]['clause'];

			if ( ! undergrowth_is_long_enough( $word ) || isset( $function_words[ $word ] ) || in_array( $word, array( 'former', 'latter', 'certain', 'little', 'several', 'various', 'first', 'second', 'third', 'last', 'next', 'still' ), true )
				|| ( ! $about && preg_match( '/(?:ly|ing)$/u', $word ) )
				|| ( ! $about && preg_match( '/[^su\'’]s$/u', $word ) && ! preg_match( '/s$/u', $words[ $index ]['lower'] ) )
				|| ( ! $about && ! $opened && ( isset( $weak[ $word ] ) || preg_match( '/[^e]ed$/u', $word ) ) ) ) {
				break;
			}

			$first = $at--;
		}

		$before = $words[ $first - 1 ] ?? null;

		// A run opened by a pronoun or an auxiliary starts with a verb.
		if ( $first < $index && null !== $before && $clause === $before['clause'] && ! isset( $openers[ $before['lower'] ] ) && ( isset( $function_words[ $before['lower'] ] ) || ! undergrowth_is_long_enough( $before['lower'] ) ) ) {
			++$first;
		}

		if ( $last - $first + 1 < UNDERGROWTH_MAX_ANCHOR_WORDS && isset( $words[ $last + 1 ], $terms[ $words[ $last + 1 ]['lower'] ] ) && $clause === $words[ $last + 1 ]['clause'] ) {
			++$last;
		}
	}

	return $first === $last ? null : array( $first, $last );
}
