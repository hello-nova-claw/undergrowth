<?php
/**
 * Tests for choosing the sentence where a link fits.
 *
 * @package Undergrowth
 */

/**
 * Sentence choice for link proposals.
 */
class Test_Sentences extends WP_UnitTestCase {

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
	}

	/**
	 * Returns the stored sentence and index for a link.
	 *
	 * @param int $source_id Source post ID.
	 * @param int $target_id Target post ID.
	 * @return array{sentence: string, sentence_index: string}|null
	 */
	private function stored_sentence( int $source_id, int $target_id ): ?array {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT sentence, sentence_index FROM %i WHERE source_id = %d AND target_id = %d',
				undergrowth_links_table(),
				$source_id,
				$target_id
			),
			ARRAY_A
		);
	}

	/**
	 * Returns only the text of each sentence.
	 *
	 * @param string $html Post content.
	 * @return string[]
	 */
	private function sentence_texts( string $html ): array {
		return array_column( undergrowth_split_sentences( $html ), 'text' );
	}

	public function test_english_text_splits_at_sentence_ends_and_blocks() {
		$this->assertSame(
			array( 'Oca is a tuber.', 'It keeps well!', 'Does it store?', 'A second paragraph' ),
			$this->sentence_texts( '<p>Oca is a tuber. It keeps well! Does it store?</p><p>A second paragraph</p>' )
		);
	}

	public function test_japanese_text_splits_without_spaces() {
		$this->assertSame(
			array( 'オカは塊茎です。', '冬に保存します。' ),
			$this->sentence_texts( '<p>オカは塊茎です。冬に保存します。</p>' )
		);
	}

	public function test_list_items_and_headings_are_separate_sentences() {
		$this->assertSame(
			array( 'Storing', 'Sand', 'Sawdust' ),
			$this->sentence_texts( '<h2>Storing</h2><ul><li>Sand</li><li>Sawdust</li></ul>' )
		);
	}

	public function test_sentence_with_a_link_is_marked() {
		$sentences = undergrowth_split_sentences( '<p>Oca keeps in sand. See <a href="https://example.test/">this</a> for more.</p>' );

		$this->assertFalse( $sentences[0]['linked'] );
		$this->assertTrue( $sentences[1]['linked'] );
	}

	public function test_link_text_running_past_a_full_stop_marks_both_sentences() {
		$sentences = undergrowth_split_sentences( '<p>See <a href="https://example.test/">my notes. Yacon</a> grows tall. Yacon is sweet.</p>' );

		$this->assertSame( array( 'See my notes.', 'Yacon grows tall.', 'Yacon is sweet.' ), array_column( $sentences, 'text' ) );
		$this->assertSame( array( true, true, false ), array_column( $sentences, 'linked' ) );
	}

	public function test_link_wrapping_several_paragraphs_marks_every_one() {
		$sentences = undergrowth_split_sentences( '<a href="https://example.test/"><p>Linked one.</p><p>Linked two.</p></a><p>Free.</p>' );

		$this->assertSame( array( 'Linked one.', 'Linked two.', 'Free.' ), array_column( $sentences, 'text' ) );
		$this->assertSame( array( true, true, false ), array_column( $sentences, 'linked' ) );
	}

	public function test_sentence_holding_an_image_only_link_is_marked() {
		$sentences = undergrowth_split_sentences( '<p>Yacon <a href="https://example.test/"><img src="https://example.test/yacon.jpg" alt=""></a> grows tall. Oca is sweet.</p>' );

		$this->assertSame( array( 'Yacon grows tall.', 'Oca is sweet.' ), array_column( $sentences, 'text' ) );
		$this->assertSame( array( true, false ), array_column( $sentences, 'linked' ) );
	}

	public function test_sentences_inside_someone_elses_words_are_marked_quoted() {
		$sentences = undergrowth_split_sentences(
			'<p>Mine before.</p>'
			. '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Their words.</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->'
			. 'Mine between. <blockquote>Classic words.</blockquote>'
			. '<!-- wp:html --><span>Hand-written markup.</span><!-- /wp:html -->'
			. '<!-- wp:pullquote --><figure class="wp-block-pullquote"><blockquote><p>Pulled words.</p><cite>Someone</cite></blockquote></figure><!-- /wp:pullquote -->'
			. '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Mine in a group.</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
		);

		$this->assertSame(
			array(
				'Mine before.'         => false,
				'Their words.'         => true,
				'Mine between.'        => false,
				'Classic words.'       => true,
				'Hand-written markup.' => true,
				'Pulled words.'        => true,
				'Someone'              => true,
				'Mine in a group.'     => false,
			),
			array_column( $sentences, 'quoted', 'text' )
		);
	}

	public function test_quote_left_open_at_the_end_marks_its_last_sentence() {
		$sentences = undergrowth_split_sentences( '<p>Mine.</p><blockquote>Their words, never closed.' );

		$this->assertSame( array( false, true ), array_column( $sentences, 'quoted' ) );
	}

	public function test_empty_custom_html_block_marks_nothing_after_it() {
		$sentences = undergrowth_split_sentences( '<!-- wp:html /--><!-- wp:paragraph --><p>Mine after.</p><!-- /wp:paragraph -->' );

		$this->assertSame( array( false ), array_column( $sentences, 'quoted' ) );
	}

	public function test_blockquote_mentioned_in_an_ordinary_comment_marks_nothing() {
		$sentences = undergrowth_split_sentences( '<!-- <blockquote> --><p>Mine after a comment.</p>' );

		$this->assertSame( array( false ), array_column( $sentences, 'quoted' ) );
	}

	public function test_shortcodes_never_become_sentences() {
		$this->assertSame( array( 'Before.', 'After.' ), $this->sentence_texts( '<p>Before. [gallery ids="1"] After.</p>' ) );
	}

	public function test_words_are_lowercased_and_keep_accents() {
		$this->assertSame( array( 'süßkartoffeln', 'und', 'yacón' ), undergrowth_tokens( 'Süßkartoffeln und Yacón' ) );
	}

	public function test_japanese_words_become_character_pairs() {
		$this->assertSame( array( 'ヤー', 'ーコ', 'コン' ), undergrowth_tokens( 'ヤーコン' ) );
	}

	public function test_text_without_letters_gives_no_tokens() {
		$this->assertSame( array(), undergrowth_tokens( '— ! ?' ) );
	}

	public function test_single_ideograph_is_kept_as_a_token() {
		$this->assertSame( array( '塊' ), undergrowth_tokens( '塊' ) );
	}

	public function test_corpus_skips_posts_that_no_longer_exist() {
		$corpus = undergrowth_build_corpus( array( PHP_INT_MAX ) );

		$this->assertSame( 0, $corpus['documents'] );
	}

	public function test_post_outside_the_corpus_has_no_distinctive_terms() {
		$this->assertSame( array(), undergrowth_distinctive_terms( 1, undergrowth_build_corpus( array() ) ) );
	}

	public function test_missing_source_post_gives_no_sentence() {
		$yacon  = self::factory()->post->create(
			array(
				'post_title'  => 'Yacon',
				'post_status' => 'publish',
			)
		);
		$corpus = undergrowth_build_corpus( array( $yacon ) );

		$this->assertNull( undergrowth_choose_sentence( PHP_INT_MAX, $yacon, $corpus ) );
	}

	public function test_short_latin_words_are_ignored() {
		$this->assertSame( array( 'oca' ), undergrowth_tokens( 'an oca is' ) );
	}

	public function test_sentence_sharing_the_targets_distinctive_words_is_chosen() {
		$oca   = $this->plant(
			'Oca',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => '<p>Oca grows in the Andes. I keep a few yacon plants nearby for their syrup. Harvest comes after frost.</p>' )
		);
		$yacon = $this->plant(
			'Yacon',
			array( 0.9, 0.1, 0.0 ),
			array( 'post_content' => '<p>Yacon syrup is a gentle sweetener. The yacon root stores well.</p>' )
		);

		$this->assertSame( 'I keep a few yacon plants nearby for their syrup.', $this->stored_sentence( $oca, $yacon )['sentence'] );
		$this->assertSame( '1', $this->stored_sentence( $oca, $yacon )['sentence_index'] );
	}

	public function test_german_sentence_is_chosen() {
		$kartoffel = $this->plant(
			'Kartoffeln',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => '<p>Kartoffeln brauchen lockeren Boden. Neben ihnen wachsen meine Süßkartoffeln besonders gut.</p>' )
		);
		$suess     = $this->plant(
			'Süßkartoffeln',
			array( 0.9, 0.1, 0.0 ),
			array( 'post_content' => '<p>Süßkartoffeln mögen Wärme. Süßkartoffeln lagert man trocken.</p>' )
		);

		$this->assertSame( 'Neben ihnen wachsen meine Süßkartoffeln besonders gut.', $this->stored_sentence( $kartoffel, $suess )['sentence'] );
	}

	public function test_japanese_sentence_is_chosen() {
		$oca   = $this->plant(
			'オカ',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => '<p>オカはアンデスの塊茎です。隣でヤーコンも育てています。</p>' )
		);
		$yacon = $this->plant(
			'ヤーコン',
			array( 0.9, 0.1, 0.0 ),
			array( 'post_content' => '<p>ヤーコンのシロップは甘いです。ヤーコンは保存できます。</p>' )
		);

		$this->assertSame( '隣でヤーコンも育てています。', $this->stored_sentence( $oca, $yacon )['sentence'] );
	}

	public function test_sentence_already_holding_a_link_is_never_chosen() {
		$elsewhere = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$oca       = $this->plant(
			'Oca',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => '<p>My <a href="' . esc_url( get_permalink( $elsewhere ) ) . '">yacon syrup notes</a> are here. Yacon grows tall beside the oca.</p>' )
		);
		$yacon     = $this->plant(
			'Yacon',
			array( 0.9, 0.1, 0.0 ),
			array( 'post_content' => '<p>Yacon syrup is a gentle sweetener.</p>' )
		);

		$this->assertSame( 'Yacon grows tall beside the oca.', $this->stored_sentence( $oca, $yacon )['sentence'] );
	}

	/**
	 * Source posts whose best sentence for a link to Yacon is in someone else's words.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_others_words() {
		return array(
			'quote block'       => array( '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Yacon syrup is a gentle sweetener.</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->' ),
			'classic quote'     => array( '<blockquote>Yacon syrup is a gentle sweetener.</blockquote>' ),
			'custom html block' => array( '<!-- wp:html --><div class="note">Yacon syrup is a gentle sweetener.</div><!-- /wp:html -->' ),
			'pullquote'         => array( '<!-- wp:pullquote --><figure class="wp-block-pullquote"><blockquote><p>Yacon syrup is a gentle sweetener.</p><cite>Yacon syrup grower</cite></blockquote></figure><!-- /wp:pullquote -->' ),
		);
	}

	/**
	 * Checks the next best sentence is chosen over one in someone else's words.
	 *
	 * @dataProvider data_others_words
	 *
	 * @param string $others_words Markup holding the best sentence.
	 */
	public function test_sentence_in_someone_elses_words_is_never_chosen( string $others_words ) {
		$oca   = $this->plant(
			'Oca',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => $others_words . '<!-- wp:paragraph --><p>I grow yacon beside the oca.</p><!-- /wp:paragraph -->' )
		);
		$yacon = $this->plant(
			'Yacon',
			array( 0.9, 0.1, 0.0 ),
			array( 'post_content' => '<p>Yacon syrup is a gentle sweetener.</p>' )
		);

		$this->assertSame( 'I grow yacon beside the oca.', $this->stored_sentence( $oca, $yacon )['sentence'] );
	}

	public function test_proposal_with_only_quoted_sentences_is_dropped() {
		$oca   = $this->plant(
			'Oca',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => '<blockquote>Yacon syrup is a gentle sweetener.</blockquote><p>Tubers like cool weather.</p>' )
		);
		$yacon = $this->plant(
			'Yacon',
			array( 0.9, 0.1, 0.0 ),
			array( 'post_content' => '<p>Yacon syrup is a gentle sweetener.</p>' )
		);

		$this->assertNotContains( $yacon, $this->proposed( $oca ) );
	}

	public function test_paragraph_inside_a_group_is_still_chosen() {
		$oca   = $this->plant(
			'Oca',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Yacon syrup is a gentle sweetener.</p><!-- /wp:paragraph --></div><!-- /wp:group --><!-- wp:paragraph --><p>I grow yacon beside the oca.</p><!-- /wp:paragraph -->' )
		);
		$yacon = $this->plant(
			'Yacon',
			array( 0.9, 0.1, 0.0 ),
			array( 'post_content' => '<p>Yacon syrup is a gentle sweetener.</p>' )
		);

		$this->assertSame( 'Yacon syrup is a gentle sweetener.', $this->stored_sentence( $oca, $yacon )['sentence'] );
	}

	public function test_repeated_common_words_never_outweigh_a_targets_own_words() {
		$filler = str_repeat( 'The garden and the shed with that path. ', 25 );
		foreach ( array( 'Shed', 'Path', 'Gate' ) as $i => $title ) {
			$this->plant( $title, array( 0.0, 0.0, 1.0 - 0.1 * $i ), array( 'post_content' => '<p>' . $filler . '</p>' ) );
		}
		$oca   = $this->plant(
			'Oca',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => '<p>The garden and the shed with that path. Yacon grows here.</p>' )
		);
		$yacon = $this->plant(
			'Yacon',
			array( 0.9, 0.1, 0.0 ),
			array( 'post_content' => '<p>' . $filler . str_repeat( 'Yacon is sweet. ', 5 ) . '</p>' )
		);

		$this->assertSame( 'Yacon grows here.', $this->stored_sentence( $oca, $yacon )['sentence'] );
	}

	public function test_corpus_keeps_word_counts_not_every_occurrence() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Oca',
				'post_content' => 'Oca oca oca.',
				'post_status'  => 'publish',
			)
		);

		$corpus = undergrowth_build_corpus( array( $post_id ) );

		$this->assertSame( array( 'oca' => 4 ), $corpus['counts'][ $post_id ] );
	}

	public function test_proposal_without_any_fitting_sentence_is_dropped() {
		$oca   = $this->plant(
			'Oca',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => '<p>Tubers like cool weather.</p>' )
		);
		$yacon = $this->plant(
			'Yacon',
			array( 0.9, 0.1, 0.0 ),
			array( 'post_content' => '<p>Syrup sweetener.</p>' )
		);

		$this->assertNotContains( $yacon, $this->proposed( $oca ) );
	}

	public function test_chosen_sentence_occurs_exactly_in_the_source_post() {
		$oca   = $this->plant(
			'Oca',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => '<p>Oca grows in the Andes. I keep <em>yacon</em> plants nearby for syrup.</p>' )
		);
		$yacon = $this->plant(
			'Yacon',
			array( 0.9, 0.1, 0.0 ),
			array( 'post_content' => '<p>Yacon syrup is a gentle sweetener.</p>' )
		);

		$stored = $this->stored_sentence( $oca, $yacon );
		$plain  = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( get_post( $oca )->post_content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

		$this->assertSame( 'I keep yacon plants nearby for syrup.', $stored['sentence'] );
		$this->assertStringContainsString( $stored['sentence'], $plain );
		$this->assertSame( '1', $stored['sentence_index'] );
	}

	public function test_same_posts_always_give_the_same_sentence() {
		$oca   = $this->plant(
			'Oca',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => '<p>Yacon grows here. Yacon grows there.</p>' )
		);
		$yacon = $this->plant(
			'Yacon',
			array( 0.9, 0.1, 0.0 ),
			array( 'post_content' => '<p>Yacon syrup.</p>' )
		);
		$first = $this->stored_sentence( $oca, $yacon );

		undergrowth_refresh_proposals( $oca );

		$this->assertSame( $first, $this->stored_sentence( $oca, $yacon ) );
		$this->assertSame( 'Yacon grows here.', $first['sentence'] );
	}
}
