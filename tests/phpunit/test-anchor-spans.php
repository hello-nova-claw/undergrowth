<?php
/**
 * Tests for choosing the words a link sits on.
 *
 * @package Undergrowth
 */

/**
 * A link sits on a short phrase of the author's own words.
 */
class Test_Anchor_Spans extends WP_UnitTestCase {

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
	}

	public function tear_down() {
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Lists the spans a link could sit on, as text, best first.
	 *
	 * @param string   $sentence Sentence the link sits in.
	 * @param string[] $terms    Words the target is about, best first.
	 * @param string   $title    Target's title.
	 * @param string   $locale   Target's language.
	 * @return string[]
	 */
	private function spans( string $sentence, array $terms, string $title = 'Notes', string $locale = 'en_US' ): array {
		return array_map(
			static function ( $span ) use ( $sentence ) {
				return mb_substr( $sentence, $span['offset'], $span['length'] );
			},
			undergrowth_anchor_spans( $sentence, array_fill_keys( $terms, 1.0 ), $title, $locale )
		);
	}

	public function test_a_run_of_the_targets_title_comes_first() {
		$this->assertSame( 'seed ledger', $this->spans( 'Every spring I copy the seed ledger out again.', array( 'spring', 'ledger' ), 'The Seed Ledger' )[0] );
	}

	public function test_the_title_matches_whatever_its_case_and_number() {
		$this->assertSame( 'Seed Ledgers', $this->spans( 'I keep Seed Ledgers for every bed.', array( 'ledgers' ), 'The seed ledger' )[0] );
	}

	public function test_the_longest_run_of_the_title_comes_first() {
		$this->assertSame( 'old seed ledger', $this->spans( 'We found the old seed ledger in the shed.', array( 'ledger' ), 'An old seed ledger, mended' )[0] );
	}

	public function test_a_noun_phrase_is_linked_without_its_article() {
		$this->assertSame( 'seed ledger', $this->spans( 'The seed ledger keeps every name.', array( 'ledger' ) )[0] );
	}

	public function test_a_verb_before_the_word_is_left_out() {
		$this->assertSame( 'yacon', $this->spans( 'I love yacon.', array( 'yacon' ) )[0] );
	}

	public function test_a_participle_before_the_word_is_left_out() {
		$this->assertSame( 'affection', $this->spans( 'Any passage betraying affection moves us.', array( 'affection' ) )[0] );
	}

	public function test_a_common_describing_word_joins_after_an_article() {
		$this->assertSame( 'true doctrine', $this->spans( 'The true doctrine of omnipresence is old.', array( 'doctrine' ) )[0] );
	}

	public function test_a_word_that_counts_or_points_is_left_out() {
		$this->assertSame( 'ponds', $this->spans( 'The opening of the former ponds is late.', array( 'ponds' ) )[0] );
		$this->assertSame( 'sunny valley', $this->spans( 'They met in a little sunny valley.', array( 'valley' ) )[0] );
	}

	public function test_a_phrase_after_a_verb_starts_after_it() {
		$this->assertSame( 'seed ledgers', $this->spans( 'I keep seed ledgers for every bed.', array( 'ledgers' ) )[0] );
	}

	public function test_a_name_of_several_words_is_kept_whole() {
		$this->assertSame( 'Fair Haven Hill', $this->spans( 'We walked up Fair Haven Hill at dusk.', array( 'haven' ) )[0] );
	}

	public function test_a_following_word_the_target_is_about_joins_the_phrase() {
		$this->assertSame( 'yacon syrup', $this->spans( 'A spoon of yacon syrup sweetens it.', array( 'yacon', 'syrup' ) )[0] );
	}

	/**
	 * Checks a run of words never spans two clauses.
	 *
	 * @dataProvider clause_breaks
	 *
	 * @param string $sentence Sentence with punctuation between the words.
	 */
	public function test_no_span_crosses_punctuation_that_ends_a_clause( string $sentence ) {
		foreach ( $this->spans( $sentence, array( 'ledger', 'seed' ), 'Seed ledger' ) as $span ) {
			$this->assertDoesNotMatchRegularExpression( '/[,;:—–()"“”]/u', $span );
		}
	}

	/**
	 * Sentences with the title's words on either side of a clause break.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function clause_breaks(): array {
		return array(
			'comma'     => array( 'I sort the seed, ledger in hand.' ),
			'semicolon' => array( 'I sort the seed; ledger work waits.' ),
			'colon'     => array( 'One thing to sort: seed ledger work.' ),
			'dash'      => array( 'I sort the seed—ledger work waits.' ),
			'bracket'   => array( 'I sort the seed (ledger work waits).' ),
			'quote'     => array( 'I sort the “seed” ledger.' ),
		);
	}

	public function test_no_span_starts_or_ends_on_a_stop_word() {
		foreach ( $this->spans( 'A ledger of the seed for the year.', array( 'ledger', 'seed' ), 'Ledger of the seed' ) as $span ) {
			$this->assertDoesNotMatchRegularExpression( '/^(?:a|of|the|for)\b|\b(?:a|of|the|for)$/i', $span );
		}
	}

	public function test_no_span_runs_past_four_words() {
		foreach ( $this->spans( 'We found the big old red seed ledger box.', array( 'ledger' ), 'Big old red seed ledger box' ) as $span ) {
			$this->assertLessThanOrEqual( 4, count( explode( ' ', $span ) ) );
		}
	}

	public function test_the_single_word_is_still_offered() {
		$this->assertContains( 'ledger', $this->spans( 'The seed ledger keeps every name.', array( 'ledger' ) ) );
	}

	public function test_a_hyphenated_compound_is_never_split() {
		$this->assertNotContains( 'reliance', $this->spans( 'Self-reliance is rare.', array( 'reliance' ) ) );
	}

	public function test_another_language_gets_runs_of_the_title_but_no_guessed_phrase() {
		$this->assertSame( array( 'jardín' ), $this->spans( 'Mi pequeño jardín crece.', array( 'jardín' ), 'Notas', 'es_ES' ) );
		$this->assertSame( 'pequeño jardín', $this->spans( 'Mi pequeño jardín crece.', array( 'jardín' ), 'Un pequeño jardín', 'es_ES' )[0] );
	}

	public function test_accept_links_the_whole_phrase() {
		$this->plant( 'Shed', array( 0.0, 0.0, 1.0 ), array( 'post_content' => '<p>Frost again.</p>' ) );
		$source = $this->plant( 'Spring', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<!-- wp:paragraph --><p>Every spring I copy the seed ledger out again.</p><!-- /wp:paragraph -->' ) );
		$target = $this->plant( 'The Seed Ledger', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>The ledger lists every seed. A ledger needs care.</p>' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 'accepted', undergrowth_accept_link( $source, $target ) );
		$this->assertStringContainsString( 'copy the <a href="' . get_permalink( $target ) . '">seed ledger</a> out', get_post( $source )->post_content );
	}

	public function test_a_phrase_across_an_inline_tag_falls_back_to_one_word() {
		$this->plant( 'Shed', array( 0.0, 0.0, 1.0 ), array( 'post_content' => '<p>Frost again.</p>' ) );
		$source = $this->plant( 'Spring', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Every spring I copy the <em>seed</em> ledger out again.</p>' ) );
		$target = $this->plant( 'The Seed Ledger', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>The ledger lists every seed. A ledger needs care.</p>' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 'accepted', undergrowth_accept_link( $source, $target ) );
		$this->assertStringContainsString( '<em>seed</em> <a href="' . get_permalink( $target ) . '">ledger</a> out', get_post( $source )->post_content );
	}

	public function test_a_pair_of_characters_stored_from_an_unspaced_script_is_what_accept_links() {
		global $wpdb;

		$this->plant( 'Shed', array( 0.0, 0.0, 1.0 ), array( 'post_content' => '<p>Frost again.</p>' ) );
		$source = $this->plant( 'Trip', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>明日は東京へ行く。</p>' ) );
		$target = $this->plant( '東京', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>東京は大きい。東京の夜は長い。</p>' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$anchor = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT anchor FROM %i WHERE source_id = %d AND target_id = %d', undergrowth_links_table(), $source, $target ) );

		$this->assertNotSame( '', $anchor );
		$this->assertSame( 'accepted', undergrowth_accept_link( $source, $target ) );
		$this->assertStringContainsString( '">' . $anchor . '</a>', get_post( $source )->post_content );
	}

	public function test_text_without_words_has_no_spans() {
		$this->assertSame( array(), undergrowth_anchor_words( '— … !' ) );
		$this->assertSame( array(), $this->spans( '— … !', array( 'ledger' ) ) );
	}

	public function test_an_empty_or_misplaced_span_is_never_found() {
		$content = '<p>I copy the seed ledger out.</p>';

		$this->assertNull( undergrowth_find_link_span( $content, 'I copy the seed ledger out.', 11, 0 ) );
		$this->assertNull( undergrowth_find_link_span( $content, 'I copy the seed ledger out.', -1, 4 ) );
		$this->assertNull( undergrowth_find_link_span( $content, 'I copy the seed ledger out.', 25, 9 ) );
		$this->assertNull( undergrowth_find_link_span( $content, 'I copy the seed ledger out.', 6, 1 ) );
	}

	public function test_a_pair_of_characters_inside_a_link_is_never_chosen() {
		$this->plant( 'Shed', array( 0.0, 0.0, 1.0 ), array( 'post_content' => '<p>Frost again.</p>' ) );
		$source = $this->plant( 'Trip', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>明日は<a href="https://example.test/">東京</a>へ行く。</p>' ) );
		$target = $this->plant( '東京', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>東京は大きい。東京の夜は長い。</p>' ) );

		$this->assertNull( undergrowth_choose_link_span( get_post( $source ), get_post( $target ), '明日は東京へ行く。' ) );
	}

	public function test_a_phrase_across_a_character_reference_is_never_linked() {
		$this->assertNull( undergrowth_find_link_span( '<p>Salt &amp; pepper mills.</p>', 'Salt & pepper mills.', 0, 13 ) );
	}

	public function test_a_span_is_found_only_where_the_sentence_puts_it() {
		$content = '<p>The seed ledger is old. I copy the seed ledger out.</p>';
		$found   = undergrowth_find_link_span( $content, 'I copy the seed ledger out.', 11, 11 );

		$this->assertSame( 'seed ledger', $found['word'] );
		$this->assertSame( strrpos( $content, 'seed ledger' ), $found['position'] );
	}

	public function test_a_span_that_cuts_a_word_is_never_found() {
		$this->assertNull( undergrowth_find_link_span( '<p>I copy the seed ledgers out.</p>', 'I copy the seed ledgers out.', 11, 11 ) );
	}

	public function test_a_span_over_a_line_break_in_the_source_is_found() {
		$found = undergrowth_find_link_span( "<p>I copy the seed\nledger out.</p>", 'I copy the seed ledger out.', 11, 11 );

		$this->assertSame( "seed\nledger", $found['word'] );
	}

	public function test_the_proposal_stores_the_span_the_table_marks() {
		global $wpdb;

		$this->plant( 'Shed', array( 0.0, 0.0, 1.0 ), array( 'post_content' => '<p>Frost again.</p>' ) );
		$source = $this->plant( 'Spring', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Every spring I copy the seed ledger out again.</p>' ) );
		$target = $this->plant( 'The Seed Ledger', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>The ledger lists every seed. A ledger needs care.</p>' ) );

		undergrowth_refresh_proposals( $source );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT sentence, anchor, anchor_offset FROM %i WHERE source_id = %d AND target_id = %d', undergrowth_links_table(), $source, $target ), ARRAY_A );

		$this->assertSame( 'seed ledger', $row['anchor'] );
		$this->assertSame( 'seed ledger', mb_substr( $row['sentence'], (int) $row['anchor_offset'], 11 ) );
	}

	public function test_a_proposal_with_no_words_safe_to_link_stores_none() {
		global $wpdb;

		$this->plant( 'Shed', array( 0.0, 0.0, 1.0 ), array( 'post_content' => '<p>Frost again.</p>' ) );
		$source = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>I keep sweet-yacon plants.</p>' ) );
		$target = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon syrup is sweet. Yacon roots store well.</p>' ) );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT sentence, anchor, anchor_offset FROM %i WHERE source_id = %d AND target_id = %d', undergrowth_links_table(), $source, $target ), ARRAY_A );

		$this->assertSame( 'I keep sweet-yacon plants.', $row['sentence'] );
		$this->assertSame( '', $row['anchor'] );
		$this->assertSame( '-1', $row['anchor_offset'] );
	}

	public function test_a_stored_span_is_what_accept_links() {
		global $wpdb;

		$this->plant( 'Shed', array( 0.0, 0.0, 1.0 ), array( 'post_content' => '<p>Frost again.</p>' ) );
		$source = $this->plant( 'Spring', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Every spring I copy the seed ledger out again.</p>' ) );
		$target = $this->plant( 'The Seed Ledger', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>The ledger lists every seed. A ledger needs care.</p>' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$wpdb->update(
			undergrowth_links_table(),
			array(
				'anchor'        => 'copy the seed',
				'anchor_offset' => 15,
			),
			array(
				'source_id' => $source,
				'target_id' => $target,
			)
		);

		$this->assertSame( 'copy the seed', undergrowth_link_word_choice( $source, $target, 'Every spring I copy the seed ledger out again.', 'copy the seed', 15 )['word'] );
		$this->assertSame( 'accepted', undergrowth_accept_link( $source, $target ) );
		$this->assertStringContainsString( 'I <a href="' . get_permalink( $target ) . '">copy the seed</a> ledger', get_post( $source )->post_content );
	}

	public function test_a_stored_span_that_no_longer_fits_the_sentence_is_worked_out_again() {
		$this->plant( 'Shed', array( 0.0, 0.0, 1.0 ), array( 'post_content' => '<p>Frost again.</p>' ) );
		$source = $this->plant( 'Spring', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Every spring I copy the seed ledger out again.</p>' ) );
		$target = $this->plant( 'The Seed Ledger', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>The ledger lists every seed. A ledger needs care.</p>' ) );

		$this->assertSame( 'seed ledger', undergrowth_link_word_choice( $source, $target, 'Every spring I copy the seed ledger out again.', 'frost', 3 )['word'] );
	}

	public function test_words_joined_by_of_make_a_run_either_way() {
		$this->assertSame( 'composition of friendship', $this->spans( 'There are two elements in the composition of friendship today.', array( 'friendship' ) )[0] );
		$this->assertSame( 'impression of your character', $this->spans( 'It blurs the impression of your character.', array( 'impression' ) )[0] );
		$this->assertSame( 'heart of this matter', $this->spans( 'Little is written to the heart of this matter in books.', array( 'heart' ) )[0] );
	}

	public function test_a_verb_before_of_is_left_out() {
		$this->assertNotContains( 'eat of the bread', $this->spans( 'We eat of the bread which grows here.', array( 'bread' ) ) );
	}

	public function test_a_word_after_of_runs_on_to_the_word_it_describes() {
		$this->assertSame( 'ranks of haughty weeds', $this->spans( 'I hoed the ranks of haughty weeds at dawn.', array( 'ranks' ) )[0] );
	}

	public function test_a_describing_word_joins_the_word_it_describes() {
		$this->assertSame( 'honest opinion', $this->spans( 'Let me record my honest opinion about it.', array( 'honest' ) )[0] );
	}

	public function test_a_noun_never_joins_the_verb_after_it() {
		$this->assertSame( array( 'passion' ), $this->spans( 'The passion rebuilds the world.', array( 'passion' ) ) );
		$this->assertSame( array( 'plant' ), $this->spans( 'The plant grows fast.', array( 'plant' ) ) );
		$this->assertSame( array( 'fish' ), $this->spans( 'The fish swims.', array( 'fish' ) ) );
		$this->assertSame( 'actual horizon', $this->spans( 'We saw the actual horizon.', array( 'actual' ) )[0] );
	}

	public function test_a_plural_joins_a_word_only_a_describing_word_ends_like() {
		$this->assertSame( 'curious habits', $this->spans( 'I noted the curious habits of wrens.', array( 'curious' ) )[0] );
		$this->assertSame( 'useful tools', $this->spans( 'He kept the useful tools for weeding.', array( 'useful' ) )[0] );
	}

	public function test_two_things_named_together_make_a_run() {
		$this->assertSame( 'sounds and sights', $this->spans( 'These sounds and sights I heard anywhere.', array( 'sounds' ) )[0] );
		$this->assertSame( 'predecessors and our contemporaries', $this->spans( 'Both our predecessors and our contemporaries knew it.', array( 'contemporaries' ) )[0] );
	}

	public function test_two_verbs_or_two_describing_words_are_never_joined() {
		$this->assertNotContains( 'stand or walk', $this->spans( 'The legs were made to stand or walk upon.', array( 'walk' ) ) );
		$this->assertNotContains( 'ground and destroy', $this->spans( 'The seeds rot in the ground and destroy the crop.', array( 'ground' ) ) );
		$this->assertNotContains( 'honest and natural', $this->spans( 'They were each honest and natural then.', array( 'natural' ) ) );
	}

	public function test_a_verb_ending_in_s_ends_the_describing_words() {
		$this->assertSame( 'comparative freedom', $this->spans( 'The walker enjoys comparative freedom.', array( 'freedom' ) )[0] );
	}

	public function test_a_word_ending_in_ed_describes_only_after_an_article() {
		$this->assertSame( 'frozen middle', $this->spans( 'A canal forms about the frozen middle.', array( 'middle' ) )[0] );
		$this->assertNotContains( 'dashed water', $this->spans( 'I rose early, dashed water on the floor.', array( 'water' ) ) );
	}

	public function test_a_word_alone_comes_after_every_longer_run() {
		$spans = $this->spans( 'Plants know the composition of friendship.', array( 'plants', 'friendship' ) );

		$this->assertSame( 'composition of friendship', $spans[0] );
		$this->assertSame( array( 'plants', 'friendship' ), array_slice( array_map( 'strtolower', $spans ), -2 ) );
	}

	public function test_a_sentence_that_gives_a_phrase_wins_over_a_better_one_that_gives_one_word() {
		global $wpdb;

		$this->plant( 'Shed', array( 0.0, 0.0, 1.0 ), array( 'post_content' => '<p>Frost again.</p>' ) );
		$source = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>I love yacon. The harvest of yacon came early.</p>' ) );
		$target = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon syrup is sweet. Yacon roots store well.</p>' ) );

		undergrowth_refresh_proposals( $source );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT sentence, anchor FROM %i WHERE source_id = %d AND target_id = %d', undergrowth_links_table(), $source, $target ), ARRAY_A );

		$this->assertSame( 'The harvest of yacon came early.', $row['sentence'] );
		$this->assertSame( 'harvest of yacon', $row['anchor'] );
	}

	public function test_one_word_is_kept_only_when_no_sentence_offers_more() {
		global $wpdb;

		$this->plant( 'Shed', array( 0.0, 0.0, 1.0 ), array( 'post_content' => '<p>Frost again.</p>' ) );
		$source = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>I love yacon. Frost took the yacon.</p>' ) );
		$target = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon syrup is sweet. Yacon roots store well.</p>' ) );

		undergrowth_refresh_proposals( $source );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT sentence, anchor FROM %i WHERE source_id = %d AND target_id = %d', undergrowth_links_table(), $source, $target ), ARRAY_A );

		$this->assertSame( 'I love yacon.', $row['sentence'] );
		$this->assertSame( 'yacon', $row['anchor'] );

		// The target's suggestion back to the source is on one word too.
		$this->assertSame(
			array(
				'one' => 2,
				'all' => 2,
			),
			undergrowth_one_word_anchor_counts()
		);
		$this->assertContains( 'Suggestions linking one word: 2 of 2', undergrowth_scores_report() );

		// Words in a script without spaces are counted in neither.
		$wpdb->update(
			undergrowth_links_table(),
			array( 'anchor' => '菊芋' ),
			array(
				'source_id' => $source,
				'target_id' => $target,
			)
		);

		$this->assertSame(
			array(
				'one' => 1,
				'all' => 1,
			),
			undergrowth_one_word_anchor_counts()
		);
	}

	public function test_words_the_author_chose_keep_their_sentence() {
		global $wpdb;

		$this->plant( 'Shed', array( 0.0, 0.0, 1.0 ), array( 'post_content' => '<p>Frost again.</p>' ) );
		$source = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>I love yacon. The harvest of yacon came early.</p>' ) );
		$target = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon syrup is sweet. Yacon roots store well.</p>' ) );
		$where  = array(
			'source_id' => $source,
			'target_id' => $target,
		);

		$wpdb->update(
			undergrowth_links_table(),
			array(
				'sentence'      => 'I love yacon.',
				'anchor'        => 'yacon',
				'anchor_offset' => 7,
				'anchor_chosen' => 1,
			),
			$where
		);

		undergrowth_refresh_proposals( $source );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT sentence, anchor, anchor_chosen FROM %i WHERE source_id = %d AND target_id = %d', undergrowth_links_table(), $source, $target ), ARRAY_A );

		$this->assertSame( 'I love yacon.', $row['sentence'] );
		$this->assertSame( 'yacon', $row['anchor'] );
		$this->assertSame( '1', $row['anchor_chosen'] );
	}

	public function test_an_update_chooses_pending_suggestions_words_again() {
		global $wpdb;

		$this->plant( 'Shed', array( 0.0, 0.0, 1.0 ), array( 'post_content' => '<p>Frost again.</p>' ) );
		$source = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>I love yacon. The harvest of yacon came early.</p>' ) );
		$target = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon syrup is sweet. Yacon roots store well.</p>' ) );
		$table  = undergrowth_links_table();

		// A suggestion stored under the old rules, and an accepted link.
		$wpdb->update(
			$table,
			array(
				'sentence'      => 'I love yacon.',
				'anchor'        => 'yacon',
				'anchor_offset' => 7,
			),
			array(
				'source_id' => $source,
				'target_id' => $target,
			)
		);
		$wpdb->update(
			$table,
			array(
				'state'  => 'accepted',
				'anchor' => 'syrup',
			),
			array(
				'source_id' => $target,
				'target_id' => $source,
			)
		);
		update_option( 'undergrowth_index_identity', undergrowth_index_target(), false );
		delete_option( 'undergrowth_anchor_rules' );
		wp_clear_scheduled_hook( 'undergrowth_refresh_index', array( 0 ) );

		undergrowth_maybe_refresh_anchors();

		$this->assertSame( UNDERGROWTH_ANCHOR_RULES, (int) get_option( 'undergrowth_anchor_rules' ) );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_refresh_index', array( 0 ) ) );

		undergrowth_refresh_index( 0 );

		$this->assertSame( 'harvest of yacon', $wpdb->get_var( $wpdb->prepare( 'SELECT anchor FROM %i WHERE source_id = %d AND target_id = %d', $table, $source, $target ) ) );
		$this->assertSame( 'syrup', $wpdb->get_var( $wpdb->prepare( 'SELECT anchor FROM %i WHERE source_id = %d AND target_id = %d', $table, $target, $source ) ) );
	}

	public function test_the_refresh_is_queued_once_and_not_while_the_site_is_still_read() {
		delete_option( 'undergrowth_index_identity' );
		delete_option( 'undergrowth_anchor_rules' );
		wp_clear_scheduled_hook( 'undergrowth_refresh_index', array( 0 ) );

		undergrowth_maybe_refresh_anchors();

		$this->assertFalse( wp_next_scheduled( 'undergrowth_refresh_index', array( 0 ) ) );

		// Once the rules are recorded, nothing is queued again.
		update_option( 'undergrowth_index_identity', undergrowth_index_target(), false );
		undergrowth_maybe_refresh_anchors();

		$this->assertFalse( wp_next_scheduled( 'undergrowth_refresh_index', array( 0 ) ) );

		// A refresh already waiting is not queued twice.
		delete_option( 'undergrowth_anchor_rules' );
		undergrowth_maybe_refresh_anchors();
		delete_option( 'undergrowth_anchor_rules' );
		undergrowth_maybe_refresh_anchors();

		$events = array_filter(
			_get_cron_array(),
			static function ( $hooks ) {
				return isset( $hooks['undergrowth_refresh_index'] );
			}
		);

		$this->assertCount( 1, $events );
		$this->assertCount( 1, current( $events )['undergrowth_refresh_index'] );
	}
}
