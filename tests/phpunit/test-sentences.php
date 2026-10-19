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

		$sentence = $this->stored_sentence( $oca, $yacon )['sentence'];
		$this->assertContains( $sentence, $this->sentence_texts( get_post( $oca )->post_content ) );
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
