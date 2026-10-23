<?php
/**
 * Tests for telling which words can carry a link.
 *
 * @package Undergrowth
 */

/**
 * A link sits on a word that names what the target is about.
 */
class Test_Anchor_Words extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'undergrowth_anchor_stopwords' );
		remove_all_filters( 'undergrowth_post_language' );

		parent::tear_down();
	}

	/**
	 * Ranks the words of some text for a link to a target, among a few
	 * posts that use none of them.
	 *
	 * @param array<string, string> $target Target post fields.
	 * @param string                $text   Text the link would sit in.
	 * @return string[] Tokens, best first.
	 */
	private function anchors( array $target, string $text ): array {
		$target = self::factory()->post->create( $target );
		$others = self::factory()->post->create_many( 3, array( 'post_content' => 'Snow.' ) );

		return array_map( 'strval', array_keys( undergrowth_anchor_terms( $target, $text, undergrowth_build_corpus( array_merge( array( $target ), $others ) ), 0 ) ) );
	}

	public function test_function_words_never_carry_a_link() {
		$this->assertSame(
			array( 'pond' ),
			$this->anchors( array( 'post_content' => 'Notes about the pond, and about the pond again, with the pond.' ), 'About the pond, with them.' )
		);
	}

	public function test_common_word_carries_a_link_only_when_the_target_is_named_after_it() {
		$content = 'Time passes slowly by the pond. Time heals the pond.';

		$this->assertSame( array( 'pond' ), $this->anchors( array( 'post_content' => $content ), 'Time and the pond.' ) );
		$this->assertSame(
			array( 'time', 'pond' ),
			$this->anchors(
				array(
					'post_title'   => 'Time',
					'post_content' => $content,
				),
				'Time and the pond.'
			)
		);
	}

	public function test_word_the_target_is_not_about_carries_no_link() {
		// Twenty-five words used three times each fill what the target is
		// about. A word used once is incidental, however rare.
		$words   = explode( ' ', 'alfa bravo charlie delta echo foxtrot golf hotel india juliett kilo lima mike november oscar papa quebec romeo sierra tango uniform victor whiskey xray yankee' );
		$content = str_repeat( implode( '. ', $words ) . '. ', 3 ) . 'The vicinity.';

		$this->assertSame( array(), $this->anchors( array( 'post_content' => $content ), 'The vicinity of the hut.' ) );
		$this->assertSame( array( 'alfa' ), $this->anchors( array( 'post_content' => $content ), 'The vicinity of alfa.' ) );
	}

	public function test_word_the_target_uses_as_a_verb_carries_no_link() {
		$this->assertSame(
			array( 'heron' ),
			$this->anchors( array( 'post_content' => 'I waded in. We waded out, and they waded far. The heron, the heron.' ), 'The heron waded.' )
		);
	}

	public function test_heading_word_ranks_like_a_title_word() {
		$this->assertSame(
			array( 'yacon', 'frost' ),
			$this->anchors( array( 'post_content' => '<h2>Yacon</h2><p>The frost, the frost, the frost. One yacon.</p>' ), 'Yacon and frost.' )
		);
	}

	public function test_title_word_ranks_before_another_form_of_it() {
		$target = array(
			'post_title'   => 'Walking',
			'post_content' => 'I walked to the woods. Walking is the woods, the woods, the woods.',
		);

		$this->assertSame( array( 'walked', 'woods' ), $this->anchors( $target, 'I walked in the woods.' ) );
		$this->assertSame( array( 'walking', 'walked', 'woods' ), $this->anchors( $target, 'Walking, I walked in the woods.' ) );
	}

	public function test_other_form_of_a_title_word_most_posts_use_carries_no_link() {
		$parts  = self::factory()->post->create_many( 3, array( 'post_title' => 'Economy, part 1' ) );
		$target = self::factory()->post->create(
			array(
				'post_title'   => 'Economy, part 2',
				'post_content' => 'The economy has parts, many parts. The farm, the farm, the farm.',
			)
		);
		$corpus = undergrowth_build_corpus( array_merge( $parts, array( $target ) ) );

		$this->assertSame( array( 'farm' ), array_keys( undergrowth_anchor_terms( $target, 'The parts of the farm.', $corpus, 0 ) ) );
	}

	public function test_other_languages_keep_every_word_unless_a_filter_lists_some() {
		add_filter(
			'undergrowth_post_language',
			static function () {
				return 'fr_FR';
			}
		);

		$target = array( 'post_content' => 'Avec les tubercules, avec le gel.' );

		$this->assertSame( array( 'avec', 'gel', 'les', 'tubercules' ), $this->anchors( $target, 'Avec les tubercules et le gel.' ) );

		add_filter(
			'undergrowth_anchor_stopwords',
			static function ( $lists, $locale ) {
				return 'fr_FR' === $locale ? array( 'never' => array( 'avec', 'les' ) ) : $lists;
			},
			10,
			2
		);

		$this->assertSame( array( 'gel', 'tubercules' ), $this->anchors( $target, 'Avec les tubercules et le gel.' ) );
	}

	public function test_only_english_has_word_lists() {
		$this->assertSame(
			array(
				'never' => array(),
				'named' => array(),
			),
			undergrowth_anchor_stopwords( 'de_DE' )
		);
		$this->assertContains( 'the', undergrowth_anchor_stopwords( 'en_GB' )['never'] );
		$this->assertContains( 'thing', undergrowth_anchor_stopwords( 'en_US' )['named'] );
	}

	public function test_uses_of_a_word_tell_a_noun_from_a_verb_or_description() {
		$unnamed = undergrowth_unnamed_words( 'The heron waits by the shore. It slowly waded in, as herons will grow old in such desperate haste, the haste of a heron.', 'en_US' );

		foreach ( array( 'slowly', 'waded', 'grow', 'desperate' ) as $word ) {
			$this->assertArrayHasKey( $word, $unnamed, $word );
		}

		foreach ( array( 'heron', 'shore', 'haste', 'herons' ) as $word ) {
			$this->assertArrayNotHasKey( $word, $unnamed, $word );
		}
	}

	public function test_noun_before_a_verb_stays_a_noun() {
		$unnamed = undergrowth_unnamed_words( 'Nature bestowed the impulse, and the balls rolled. It runs through all the balls of the system.', 'en_US' );

		$this->assertArrayNotHasKey( 'balls', $unnamed );
		$this->assertArrayNotHasKey( 'impulse', $unnamed );
	}

	public function test_word_right_after_a_noun_is_a_verb() {
		$unnamed = undergrowth_unnamed_words( 'The identity of it, and identity runs through the world.', 'en_US' );

		$this->assertArrayHasKey( 'runs', $unnamed );
		$this->assertArrayNotHasKey( 'identity', $unnamed );
	}

	public function test_capitalised_word_mid_sentence_is_a_name() {
		$unnamed = undergrowth_unnamed_words( 'We walked to Concord. They will Concord.', 'en_US' );

		$this->assertArrayNotHasKey( 'concord', $unnamed );
	}

	public function test_possessive_ends_a_noun_phrase() {
		$unnamed = undergrowth_unnamed_words( 'I will neighbor. My neighbor’s field, and the neighbor’s house.', 'en_US' );

		$this->assertArrayNotHasKey( 'neighbor', $unnamed );
	}

	public function test_words_are_read_only_in_english() {
		$this->assertSame( array(), undergrowth_unnamed_words( 'Il marche lentement, très lentement.', 'fr_FR' ) );
		$this->assertSame( array(), undergrowth_unnamed_words( '', 'en_US' ) );
	}

	/**
	 * Checks a common ending is cut off a word.
	 *
	 * @dataProvider data_word_stem
	 *
	 * @param string $word Lowercase word.
	 * @param string $stem Expected stem.
	 */
	public function test_word_stem( string $word, string $stem ) {
		$this->assertSame( $stem, undergrowth_word_stem( $word ) );
	}

	/**
	 * Words and their stems.
	 *
	 * @return array<string, string[]>
	 */
	public function data_word_stem(): array {
		return array(
			'-ed'        => array( 'walked', 'walk' ),
			'-ing'       => array( 'walking', 'walk' ),
			'-s'         => array( 'ponds', 'pond' ),
			'-er'        => array( 'reader', 'read' ),
			'no ending'  => array( 'read', 'read' ),
			'short stem' => array( 'spring', 'spring' ),
			'three left' => array( 'used', 'used' ),
		);
	}

	public function test_headings_are_read_as_plain_text() {
		$this->assertSame( array( 'Yacon roots', 'Oca' ), undergrowth_headings( '<h2 class="x">Yacon <em>roots</em></h2><p>Text.</p><h3>Oca</h3>' ) );
		$this->assertSame( array(), undergrowth_headings( '<p>No headings.</p>' ) );
	}
}
