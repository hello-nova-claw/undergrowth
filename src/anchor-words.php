<?php
/**
 * Telling which words can carry a link.
 *
 * A link's one word should name something the target post is about, so it
 * is a noun far more often than a verb, an adverb or an adjective. There is
 * no language model here: the lists below and the way the target itself
 * uses a word are the whole test, and both are cheap.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Words that never carry a link, and words that carry one only when the
 * target's title or a heading holds them, for a post's language.
 *
 * Only English has lists. In any other language every word may be linked,
 * as before, unless a filter adds a list.
 *
 * @param string $locale Language of the target post, such as en_US.
 * @return array{never: string[], named: string[]} Lowercase words. "never" are
 *         function words; "named" are words too common or vague to say where a
 *         link goes unless the target is named after them.
 */
function undergrowth_anchor_stopwords( string $locale ): array {
	$lists = array(
		'never' => array(),
		'named' => array(),
	);

	if ( 0 === strpos( $locale, 'en' ) ) {
		$lists = array(
			// Pronouns, determiners, prepositions, conjunctions, auxiliaries.
			'never' => explode(
				' ',
				'about above across after again near nearer against all almost along also although always among amongst and another any anybody anyone anything are around because been before behind being below beneath beside besides between both but can cannot could did does doing done down during each either else enough ever every everybody everyone everything few for from further had has have having hence her here hers herself him himself his how however into its itself just least less lest many may might mine more moreover most much must myself neither never nobody none nor not nothing now off often once one ones only onto other others ought our ours ourselves out over own perhaps rather same shall she should since some somebody someone something such than that the thee their theirs them themselves then there therefore these they thine this those though thou through throughout thus thy till too toward towards under unless until upon very was were what whatever when whence whenever where whereas wherein whether which while whilst who whoever whole whom whose why will with within without would yet you your yours yourself yourselves'
			),
			// Common verbs, adverbs, adjectives and nouns that say little alone.
			'named' => explode(
				' ',
				'able already back became become becomes becoming began begin begins best better beyond bring brings brought call called calls came cannot case certain certainly come comes coming could day days deal dear definite does early effect else end ends equally even ever fact far feel feels felt find finds first found gave general generally get gets getting give given gives giving goes going gone good got great greater greatest half hand hands hardly held high hold holds indeed instance its keep keeps kept kind knew know known knows large last lay least leave leaves left let lets like likely little long look looked looking looks lot made make makes making man manner matter mean means meant men merely might mine moment more most mostly move moved moves nearly need needs new next nor old once ones order part parts place point put quite rather ready real really right said same saw say saying says see seeing seem seemed seems seen sense set shall show shown side simply small sometimes soon sort still such sure take taken takes taking tell tells than thing things think thinks thought three till time times told took true truly try trying turn turned two upon use used uses using usual usually want wanted wants way ways well went whole wish word words work works year years yes met meet meets strong weak deep free full poor rich wise open fine hard easy clear plain simple alone pure various several important entire especially result results question questions number present possible common chief particular different nothing four five six seven eight nine ten twenty hundred thousand second third'
			),
		);
	}

	/**
	 * Filters the words that cannot carry a link in a post's language.
	 *
	 * @param array{never: string[], named: string[]} $lists  Lowercase words. "never" never carry a link; "named" only when the target's title or a heading holds them.
	 * @param string                                  $locale Language of the target post.
	 */
	$lists = apply_filters( 'undergrowth_anchor_stopwords', $lists, $locale );

	return array(
		'never' => array_map( 'strval', (array) ( $lists['never'] ?? array() ) ),
		'named' => array_map( 'strval', (array) ( $lists['named'] ?? array() ) ),
	);
}

/**
 * Finds the words a post uses more as something else than as a noun.
 *
 * Each use is read from the words around it. A word that ends a phrase
 * opened by a determiner, as in "the woods," or "her own instinct for", or
 * that is capitalised mid-sentence, is used as a noun. A word after "I",
 * "will", "not", "is" or "very" is a verb or describes something. So is one
 * between a determiner and a word used as a noun, as in "such desperate
 * haste", and one right after a word used as a noun, as in "identity runs
 * through". A word with an ending such as -ly, -ed or -ous that is never
 * used as a noun is an adverb, a participle or an adjective.
 *
 * Only English is read this way. In any other language no word is found.
 *
 * @param string $text   Plain text of the post.
 * @param string $locale Language of the post, such as en_US.
 * @return array<string, true> Lowercase tokens that should not carry a link.
 */
function undergrowth_unnamed_words( string $text, string $locale ): array {
	// Words and single marks. An apostrophe is a mark, so "neighbor’s" is
	// read as "neighbor" closing its phrase.
	if ( 0 !== strpos( $locale, 'en' ) || ! preg_match_all( '/[\p{L}\p{N}]+|[^\s\p{L}\p{N}]/u', $text, $matches ) ) {
		return array();
	}

	$determiners = array_flip( explode( ' ', 'a an the this these those his her its our their my your thy every each another such' ) );
	$before_verb = array_flip( explode( ' ', 'i you he she it we they will would shall should can could may might must not never did does do to very so too quite more most less how well always am is are was were be been being seems seemed' ) );
	// Function words end a phrase. Words under three letters are never
	// tokens, so they are not in the lists, but they end phrases too.
	$closing     = array_flip( array_merge( undergrowth_anchor_stopwords( $locale )['never'], explode( ' ', 'a i am an as at be by do he if in is it me my no of on or so to up us we' ) ) );
	$words       = $matches[0];
	$lower       = array_map( 'mb_strtolower', $words );
	$is_word     = array_map(
		static function ( $word ) {
			return 1 === preg_match( '/^[\p{L}\p{N}]/u', $word );
		},
		$words
	);
	$count       = count( $words );
	$noun        = array();
	$attributive = array();
	$after       = array();
	$other       = array();

	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! $is_word[ $i ] || isset( $closing[ $lower[ $i ] ] ) ) {
			continue;
		}

		$prev   = $i > 0 ? $lower[ $i - 1 ] : '.';
		$opened = isset( $determiners[ $prev ] ) || ( $i > 1 && $is_word[ $i - 1 ] && isset( $determiners[ $lower[ $i - 2 ] ] ) );
		$closes = $i + 1 >= $count || ! $is_word[ $i + 1 ] || isset( $closing[ $lower[ $i + 1 ] ] );
		$named  = preg_match( '/^\p{Lu}/u', $words[ $i ] ) && $i > 0 && ( $is_word[ $i - 1 ] || ',' === $prev );
		$token  = $lower[ $i ];

		if ( preg_match( '/.{3}(?:ly|est|ed|ous|ful|ive|ish|able|ible)$/u', $token ) ) {
			$other[ $token ] = $other[ $token ] ?? 0;
		}

		if ( ( $opened && $closes ) || $named ) {
			$noun[ $token ] = ( $noun[ $token ] ?? 0 ) + 1;
		} elseif ( isset( $before_verb[ $prev ] ) ) {
			$other[ $token ] = ( $other[ $token ] ?? 0 ) + 1;
		} elseif ( isset( $determiners[ $prev ] ) ) {
			// Settled once every noun is known: "the balls rolled" is a noun.
			$attributive[] = array( $token, $lower[ $i + 1 ] );
		} elseif ( $i > 0 && $is_word[ $i - 1 ] && $closes ) {
			// "this guiding identity runs through", if "identity" is a noun.
			$after[] = array( $token, $prev );
		}
	}

	foreach ( array_merge( $attributive, $after ) as $pair ) {
		list( $token, $beside ) = $pair;

		if ( isset( $noun[ $beside ] ) && $beside !== $token ) {
			$other[ $token ] = ( $other[ $token ] ?? 0 ) + 1;
		}
	}

	$unnamed = array();

	foreach ( $other as $token => $uses ) {
		if ( ( $noun[ $token ] ?? 0 ) < max( 1, $uses ) ) {
			$unnamed[ (string) $token ] = true;
		}
	}

	return $unnamed;
}
