<?php
/**
 * Tests for accepting a proposed link.
 *
 * @package Undergrowth
 */

/**
 * Accepting a proposal by linking one word in its sentence.
 */
class Test_Accept extends WP_UnitTestCase {

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		unset( $_POST['source'], $_POST['target'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['undergrowth_notice'], $_GET['revision'] );
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Stops a redirect so the handler's exit never runs.
	 *
	 * @param string $location Redirect target.
	 * @throws Exception Always, carrying the location.
	 */
	public function stop_redirect( $location ) {
		throw new Exception( esc_url_raw( $location ) );
	}

	/**
	 * Publishes the oca and yacon pair used by most tests.
	 *
	 * @param string $oca_content Content of the post the link would sit in.
	 * @return int[] Oca's ID, then Yacon's.
	 */
	private function pair( string $oca_content ): array {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => $oca_content ) );
		$yacon = $this->plant(
			'Yacon',
			array( 0.9, 0.1, 0.0 ),
			array( 'post_content' => '<p>Yacon syrup is a gentle sweetener. The yacon root stores well, and yacon leaves make tea.</p>' )
		);

		return array( $oca, $yacon );
	}

	/**
	 * Removes every link to a post from some content.
	 *
	 * @param string $content Post content.
	 * @param int    $target  Post the links point to.
	 * @return string
	 */
	private function without_links_to( string $content, int $target ): string {
		return (string) preg_replace( '#<a href="' . preg_quote( (string) get_permalink( $target ), '#' ) . '">(.*?)</a>#', '$1', $content );
	}

	/**
	 * Pins a proposal to a sentence, so a test controls which one is used.
	 *
	 * @param int    $source   Source post ID.
	 * @param int    $target   Target post ID.
	 * @param string $sentence Sentence as stored.
	 */
	private function set_sentence( int $source, int $target, string $sentence ): void {
		global $wpdb;

		$wpdb->update(
			undergrowth_links_table(),
			array( 'sentence' => $sentence ),
			array(
				'source_id' => $source,
				'target_id' => $target,
			)
		);
	}

	/**
	 * Contents where Accept links different words, each with the sentence
	 * the proposal is pinned to.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function choice_contents(): array {
		return array(
			'plain'          => array( '<p>Oca grows in the Andes. I keep a few yacon plants nearby for their syrup.</p>', 'I keep a few yacon plants nearby for their syrup.' ),
			'own case'       => array( '<p>Oca grows here. Yacon grows tall beside it.</p>', 'Yacon grows tall beside it.' ),
			'inside markup'  => array( '<p>Oca grows here. Oca &amp; <em>yacon</em> grow together.</p>', 'Oca & yacon grow together.' ),
			'later sentence' => array( '<p>Yacon is not grown here. Oca grows well. I keep yacon plants for their syrup.</p>', 'I keep yacon plants for their syrup.' ),
		);
	}

	/**
	 * Checks the word chosen beforehand against the word Accept links.
	 *
	 * @dataProvider choice_contents
	 *
	 * @param string $content  Content of the post the link would sit in.
	 * @param string $sentence Sentence the proposal is pinned to.
	 */
	public function test_the_word_chosen_before_accepting_is_the_word_accept_links( string $content, string $sentence ) {
		list( $oca, $yacon ) = $this->pair( $content );
		$this->set_sentence( $oca, $yacon, $sentence );

		$choice = undergrowth_link_word_choice( $oca, $yacon, $sentence );

		$this->assertIsArray( $choice );
		$this->assertSame( $choice['word'], mb_substr( $sentence, $choice['offset'], mb_strlen( $choice['word'] ) ) );

		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $yacon ) );
		$this->assertStringContainsString( '<a href="' . get_permalink( $yacon ) . '">' . $choice['word'] . '</a>', get_post( $oca )->post_content );
	}

	public function test_no_word_is_chosen_once_the_sentence_has_gone() {
		list( $oca, $yacon ) = $this->pair( '<p>Oca grows here. I keep yacon plants.</p>' );

		$this->assertNull( undergrowth_link_word_choice( $oca, $yacon, 'A sentence that is not there.' ) );
	}

	public function test_no_word_is_chosen_for_a_target_that_left_the_garden() {
		list( $oca, $yacon ) = $this->pair( '<p>Oca grows here. I keep yacon plants.</p>' );
		wp_update_post(
			array(
				'ID'          => $yacon,
				'post_status' => 'draft',
			)
		);

		$this->assertNull( undergrowth_link_word_choice( $oca, $yacon, 'I keep yacon plants.' ) );
	}

	public function test_no_word_is_chosen_for_posts_that_are_gone() {
		list( $oca ) = $this->pair( '<p>Oca grows here. I keep yacon plants.</p>' );

		$this->assertNull( undergrowth_link_word_choice( $oca, 999999, 'I keep yacon plants.' ) );
	}

	public function test_the_posts_compared_for_a_word_are_those_with_comparable_vectors() {
		list( $oca, $yacon ) = $this->pair( '<p>Oca grows here. I keep yacon plants.</p>' );

		$ids = undergrowth_comparable_post_ids( $oca );
		sort( $ids );

		$this->assertSame( array_map( 'intval', array_keys( undergrowth_comparable_vectors( $oca ) ) ), $ids );
		$this->assertContains( $yacon, $ids );
	}

	public function test_a_post_without_a_vector_has_nothing_to_compare() {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->assertSame( array(), undergrowth_comparable_post_ids( $draft ) );
	}

	public function test_most_distinctive_word_in_the_sentence_is_linked() {
		list( $oca, $yacon ) = $this->pair( '<p>Oca grows in the Andes. I keep a few yacon plants nearby for their syrup.</p>' );

		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $yacon ) );
		$this->assertStringContainsString(
			'I keep a few <a href="' . get_permalink( $yacon ) . '">yacon</a> plants',
			get_post( $oca )->post_content
		);
	}

	public function test_a_word_from_the_targets_title_is_linked_before_a_rarer_incidental_one() {
		foreach ( array( 'Shed', 'Path', 'Gate' ) as $title ) {
			$this->plant( $title, array( 0.0, 0.0, 1.0 ), array( 'post_content' => '<p>Frost again.</p>' ) );
		}
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>I keep yacon plants in the vicinity of frost.</p>' ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>The vicinity of water helps. The vicinity of frost hurts.</p>' ) );

		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $yacon ) );
		$this->assertStringContainsString( 'I keep <a href="' . get_permalink( $yacon ) . '">yacon</a> plants', get_post( $oca )->post_content );
	}

	public function test_nothing_but_one_word_changes() {
		$before              = '<!-- wp:paragraph --><p>Oca grows in the Andes. I keep a few yacon plants nearby for their syrup.</p><!-- /wp:paragraph -->';
		list( $oca, $yacon ) = $this->pair( $before );

		undergrowth_accept_link( $oca, $yacon );
		$after = get_post( $oca )->post_content;

		$this->assertSame( 1, substr_count( $after, '<a ' ) );
		$this->assertSame( $before, $this->without_links_to( $after, $yacon ) );
	}

	public function test_the_words_own_case_is_kept() {
		list( $oca, $yacon ) = $this->pair( '<p>Oca grows here. Yacon grows tall beside it.</p>' );

		undergrowth_accept_link( $oca, $yacon );

		$this->assertStringContainsString( '<a href="' . get_permalink( $yacon ) . '">Yacon</a> grows tall', get_post( $oca )->post_content );
	}

	public function test_a_word_inside_inline_markup_or_beside_entities_is_found() {
		list( $oca, $yacon ) = $this->pair( '<p>Oca grows here. Oca &amp; <em>yacon</em> grow together.</p>' );

		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $yacon ) );
		$this->assertStringContainsString( '<em><a href="' . get_permalink( $yacon ) . '">yacon</a></em>', get_post( $oca )->post_content );
	}

	public function test_the_word_is_linked_inside_the_chosen_sentence_not_an_earlier_one() {
		list( $oca, $yacon ) = $this->pair( '<p>Yacon is not grown here. Oca grows well. I keep yacon plants for their syrup.</p>' );
		global $wpdb;
		$wpdb->update(
			undergrowth_links_table(),
			array(
				'sentence'       => 'I keep yacon plants for their syrup.',
				'sentence_index' => 2,
			),
			array(
				'source_id' => $oca,
				'target_id' => $yacon,
			)
		);

		undergrowth_accept_link( $oca, $yacon );
		$content = get_post( $oca )->post_content;

		$this->assertStringStartsWith( '<p>Yacon is not grown here.', $content );
		$this->assertStringContainsString( 'I keep <a href="' . get_permalink( $yacon ) . '">yacon</a> plants', $content );
	}

	public function test_paragraphs_without_the_sentence_are_skipped() {
		list( $oca, $yacon ) = $this->pair( '<p>Yacon is a cousin of the sunflower.</p><p>Oca grows here. I keep yacon plants nearby for their syrup.</p>' );
		$this->set_sentence( $oca, $yacon, 'I keep yacon plants nearby for their syrup.' );

		undergrowth_accept_link( $oca, $yacon );
		$content = get_post( $oca )->post_content;

		$this->assertStringStartsWith( '<p>Yacon is a cousin', $content );
		$this->assertStringContainsString( 'I keep <a href="' . get_permalink( $yacon ) . '">yacon</a> plants', $content );
	}

	public function test_text_inside_an_existing_link_is_never_linked_again() {
		$elsewhere           = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		list( $oca, $yacon ) = $this->pair( '<p>See <a href="' . get_permalink( $elsewhere ) . '">my yacon notes</a> first. Oca grows here. I keep yacon plants for syrup.</p>' );
		$this->set_sentence( $oca, $yacon, 'I keep yacon plants for syrup.' );

		undergrowth_accept_link( $oca, $yacon );
		$content = get_post( $oca )->post_content;

		$this->assertStringContainsString( '>my yacon notes</a>', $content );
		$this->assertStringContainsString( 'I keep <a href="' . get_permalink( $yacon ) . '">yacon</a> plants', $content );
	}

	public function test_a_revision_is_kept() {
		list( $oca, $yacon ) = $this->pair( '<p>Oca grows here. I keep yacon plants nearby for their syrup.</p>' );
		$before              = count( wp_get_post_revisions( $oca ) );

		undergrowth_accept_link( $oca, $yacon );

		$this->assertGreaterThan( $before, count( wp_get_post_revisions( $oca ) ) );
	}

	public function test_accepted_link_is_recorded_and_listed_in_the_file() {
		list( $oca, $yacon ) = $this->pair( '<p>Oca grows here. I keep yacon plants nearby for their syrup.</p>' );

		undergrowth_accept_link( $oca, $yacon );

		$this->assertSame( 'accepted', undergrowth_link_state( $oca, $yacon ) );
		$this->assertStringContainsString( "## accepted links\n- " . get_permalink( $yacon ), (string) file_get_contents( undergrowth_memory_path( $oca ) ) );
	}

	public function test_accepting_twice_never_links_twice() {
		list( $oca, $yacon ) = $this->pair( '<p>Oca grows here. I keep yacon plants nearby for their syrup.</p>' );
		undergrowth_accept_link( $oca, $yacon );
		$once = get_post( $oca )->post_content;

		$this->assertSame( 'not_proposed', undergrowth_accept_link( $oca, $yacon ) );
		$this->assertSame( $once, get_post( $oca )->post_content );
	}

	public function test_edited_sentence_fails_safely() {
		list( $oca, $yacon ) = $this->pair( '<p>Oca grows here. I keep yacon plants nearby for their syrup.</p>' );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => '<p>Oca grows here. I have stopped growing anything else.</p>' ), array( 'ID' => $oca ) );
		clean_post_cache( $oca );

		$this->assertSame( 'edited', undergrowth_accept_link( $oca, $yacon ) );
		$this->assertSame( '<p>Oca grows here. I have stopped growing anything else.</p>', get_post( $oca )->post_content );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_sentence_stored_across_a_closing_quote_is_reported_as_edited() {
		list( $oca, $yacon ) = $this->pair( '<p>He said, “I keep yacon.” Oca grows here.</p>' );
		$before              = get_post( $oca )->post_content;
		$this->set_sentence( $oca, $yacon, 'He said, “I keep yacon.” Oca grows here.' );

		$this->assertSame( 'edited', undergrowth_accept_link( $oca, $yacon ) );
		$this->assertSame( $before, get_post( $oca )->post_content );

		undergrowth_refresh_proposals( $oca );

		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $yacon ) );
	}

	public function test_sentence_outside_a_paragraph_fails_safely() {
		list( $oca, $yacon ) = $this->pair( '<ul><li>I keep yacon plants nearby for their syrup.</li></ul><p>Yacon grows here.</p>' );
		$before              = get_post( $oca )->post_content;

		// A proposal stored before list items were skipped can still point there.
		$this->set_sentence( $oca, $yacon, 'I keep yacon plants nearby for their syrup.' );

		$this->assertSame( 'no_word', undergrowth_accept_link( $oca, $yacon ) );
		$this->assertSame( $before, get_post( $oca )->post_content );
	}

	public function test_a_failed_accept_leaves_the_suggestion_proposed() {
		list( $oca, $yacon ) = $this->pair( '<ul><li>I keep yacon plants nearby for their syrup.</li></ul><p>Yacon grows here.</p>' );
		$this->set_sentence( $oca, $yacon, 'I keep yacon plants nearby for their syrup.' );

		undergrowth_accept_link( $oca, $yacon );

		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_accept_is_refused_when_saving_would_strip_other_content() {
		$before              = '<p>Oca grows here. I keep yacon plants nearby for their syrup.</p><p><iframe src="https://example.test/video"></iframe></p>';
		list( $oca, $yacon ) = $this->pair( $before );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $before ), array( 'ID' => $oca ) );
		clean_post_cache( $oca );
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap ) {
				return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
			},
			10,
			2
		);

		$this->assertSame( 'needs_admin', undergrowth_accept_link( $oca, $yacon ) );
		$this->assertSame( $before, get_post( $oca )->post_content );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_a_word_inside_a_character_reference_is_never_linked() {
		$linked = undergrowth_link_word( '<p>It costs &pound;3 a pound.</p>', 'It costs £3 a pound.', 'pound', 'https://example.test/' );

		$this->assertSame( '<p>It costs &pound;3 a <a href="https://example.test/">pound</a>.</p>', $linked );
	}

	public function test_a_word_inside_a_hyphenated_compound_is_never_linked() {
		$this->assertNull( undergrowth_link_word( '<p>Self-reliance is its aversion.</p>', 'Self-reliance is its aversion.', 'reliance', 'https://example.test/' ) );
		$this->assertNull( undergrowth_link_word( '<p>We ate home-made bread.</p>', 'We ate home-made bread.', 'home', 'https://example.test/' ) );
	}

	public function test_the_word_on_its_own_is_linked_after_its_hyphenated_use() {
		$linked = undergrowth_link_word( '<p>Self-reliance is rare, and reliance on others common.</p>', 'Self-reliance is rare, and reliance on others common.', 'reliance', 'https://example.test/' );

		$this->assertSame( '<p>Self-reliance is rare, and <a href="https://example.test/">reliance</a> on others common.</p>', $linked );
	}

	public function test_a_word_beside_a_dash_used_as_punctuation_is_linked() {
		$linked = undergrowth_link_word( '<p>That is genius—the rest is noise.</p>', 'That is genius—the rest is noise.', 'genius', 'https://example.test/' );

		$this->assertSame( '<p>That is <a href="https://example.test/">genius</a>—the rest is noise.</p>', $linked );
	}

	/**
	 * Links the visible sentence, never its copy inside an attribute.
	 *
	 * @dataProvider data_attributes_holding_a_closing_bracket
	 *
	 * @param string $content  Paragraph whose markup holds the sentence in an attribute.
	 * @param string $expected The same paragraph with the visible word linked.
	 */
	public function test_text_inside_an_attribute_is_never_linked( string $content, string $expected ) {
		$this->assertSame( $expected, undergrowth_link_word( $content, 'I keep yacon plants nearby.', 'yacon', 'https://example.test/' ) );
	}

	/**
	 * Paragraphs whose attribute values hold a closing bracket and the sentence.
	 *
	 * @return array<string, string[]>
	 */
	public function data_attributes_holding_a_closing_bracket(): array {
		$link = '<a href="https://example.test/">yacon</a>';

		return array(
			'double quotes'        => array(
				'<p><span title=">I keep yacon plants nearby.">visible</span> I keep yacon plants nearby.</p>',
				'<p><span title=">I keep yacon plants nearby.">visible</span> I keep ' . $link . ' plants nearby.</p>',
			),
			'single quotes'        => array(
				"<p><span title='>I keep yacon plants nearby.'>visible</span> I keep yacon plants nearby.</p>",
				"<p><span title='>I keep yacon plants nearby.'>visible</span> I keep " . $link . ' plants nearby.</p>',
			),
			'the paragraph itself' => array(
				'<p title=">I keep yacon plants nearby.">I keep yacon plants nearby.</p>',
				'<p title=">I keep yacon plants nearby.">I keep ' . $link . ' plants nearby.</p>',
			),
			'a classic paragraph'  => array(
				"Intro.\n\n<span title=\">I keep yacon plants nearby.\">visible</span> I keep yacon plants nearby.",
				"Intro.\n\n<span title=\">I keep yacon plants nearby.\">visible</span> I keep " . $link . ' plants nearby.',
			),
		);
	}

	public function test_a_paragraph_inside_an_attribute_is_never_linked() {
		$this->assertSame(
			'<div data-copy="<p>I keep yacon plants nearby.</p>"><p>I keep <a href="https://example.test/">yacon</a> plants nearby.</p></div>',
			undergrowth_link_word(
				'<div data-copy="<p>I keep yacon plants nearby.</p>"><p>I keep yacon plants nearby.</p></div>',
				'I keep yacon plants nearby.',
				'yacon',
				'https://example.test/'
			)
		);
	}

	public function test_a_paragraph_opened_inside_another_ends_the_first() {
		$this->assertSame(
			'<p>Intro.<p>I keep <a href="https://example.test/">yacon</a> plants nearby.</p>',
			undergrowth_link_word( '<p>Intro.<p>I keep yacon plants nearby.</p>', 'I keep yacon plants nearby.', 'yacon', 'https://example.test/' )
		);
	}

	public function test_a_paragraph_never_closed_is_never_linked() {
		$this->assertNull( undergrowth_link_word( '<!-- wp:paragraph --><p>I keep yacon plants nearby.', 'I keep yacon plants nearby.', 'yacon', 'https://example.test/' ) );
	}

	public function test_a_closing_bracket_inside_a_links_attribute_still_marks_the_link() {
		$this->assertNull(
			undergrowth_link_word(
				'<p>Intro. <a title="a > b" href="https://example.test/other">I keep yacon plants nearby.</a></p>',
				'I keep yacon plants nearby.',
				'yacon',
				'https://example.test/'
			)
		);
	}

	public function test_plain_text_never_includes_attribute_values() {
		$this->assertSame( 'visible and more', undergrowth_plain_text( '<span title=">hidden" data-x=\'>also\'>visible</span> and <em>more</em>' ) );
	}

	public function test_sentence_inside_a_quote_is_never_linked() {
		list( $oca, $yacon ) = $this->pair( '<!-- wp:quote --><blockquote class="wp-block-quote"><p>I keep yacon plants nearby for their syrup.</p></blockquote><!-- /wp:quote --><p>Yacon grows here.</p>' );
		$before              = get_post( $oca )->post_content;

		// A proposal stored before quotes were skipped can still point there.
		$this->set_sentence( $oca, $yacon, 'I keep yacon plants nearby for their syrup.' );

		$this->assertSame( 'no_word', undergrowth_accept_link( $oca, $yacon ) );
		$this->assertSame( $before, get_post( $oca )->post_content );
	}

	public function test_sentence_inside_a_custom_html_block_is_never_linked() {
		$this->assertNull(
			undergrowth_link_word(
				'<!-- wp:html --><p>I keep yacon plants nearby for their syrup.</p><!-- /wp:html -->',
				'I keep yacon plants nearby for their syrup.',
				'yacon',
				'https://example.test/'
			)
		);
	}

	public function test_a_closing_quote_inside_a_comment_does_not_end_the_quote() {
		$this->assertNull(
			undergrowth_link_word(
				'<blockquote><p>Intro.</p><!-- </blockquote> --><p>I keep yacon plants nearby.</p></blockquote>',
				'I keep yacon plants nearby.',
				'yacon',
				'https://example.test/'
			)
		);
	}

	public function test_paragraph_inside_a_group_is_still_linked() {
		$linked = undergrowth_link_word(
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>I keep yacon plants nearby.</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
			'I keep yacon plants nearby.',
			'yacon',
			'https://example.test/'
		);

		$this->assertStringContainsString( 'I keep <a href="https://example.test/">yacon</a> plants', (string) $linked );
	}

	public function test_classic_paragraph_is_linked_in_place() {
		$before              = "Oca grows in the Andes.\n\nI keep a few yacon plants nearby for their syrup.\n\nHarvest comes after frost.";
		list( $oca, $yacon ) = $this->pair( $before );
		$this->set_sentence( $oca, $yacon, 'I keep a few yacon plants nearby for their syrup.' );

		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $yacon ) );
		$after = get_post( $oca )->post_content;
		$this->assertStringContainsString( 'I keep a few <a href="' . get_permalink( $yacon ) . '">yacon</a> plants', $after );
		$this->assertSame( $before, $this->without_links_to( $after, $yacon ) );
	}

	public function test_classic_paragraph_with_line_breaks_and_inline_markup_is_linked() {
		$linked = undergrowth_link_word(
			"Oca grows here.\nOca &amp; <em>yacon</em> grow together.\n\nFrost comes late.",
			'Oca & yacon grow together.',
			'yacon',
			'https://example.test/'
		);

		$this->assertSame( "Oca grows here.\nOca &amp; <em><a href=\"https://example.test/\">yacon</a></em> grow together.\n\nFrost comes late.", $linked );
	}

	public function test_classic_paragraph_beside_a_more_tag_is_linked() {
		$linked = undergrowth_link_word( "Oca grows here.<!--more-->\n\nI keep yacon plants nearby.", 'I keep yacon plants nearby.', 'yacon', 'https://example.test/' );

		$this->assertSame( "Oca grows here.<!--more-->\n\nI keep <a href=\"https://example.test/\">yacon</a> plants nearby.", $linked );
	}

	public function test_classic_paragraph_holding_a_whole_comment_is_linked() {
		$linked = undergrowth_link_word( "Oca grows here. I keep yacon plants nearby.<!--more-->\n\nFrost comes late.", 'I keep yacon plants nearby.', 'yacon', 'https://example.test/' );

		$this->assertSame( "Oca grows here. I keep <a href=\"https://example.test/\">yacon</a> plants nearby.<!--more-->\n\nFrost comes late.", $linked );
	}

	/**
	 * Classic posts whose only copy of the sentence sits where no link may go.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_classic_unlinkable() {
		return array(
			'heading'                      => array( "<h2>I keep yacon plants nearby.</h2>\n\nFrost comes late." ),
			'list'                         => array( "<ul>\n<li>I keep yacon plants nearby.</li>\n</ul>" ),
			'quote'                        => array( '<blockquote>I keep yacon plants nearby.</blockquote>' ),
			'quote over paragraphs'        => array( "<blockquote>\nIntro.\n\nI keep yacon plants nearby.\n\n</blockquote>" ),
			'preformatted'                 => array( '<pre>I keep yacon plants nearby.</pre>' ),
			'table'                        => array( "<table>\n<tr><td>I keep yacon plants nearby.</td></tr>\n</table>" ),
			'shortcode'                    => array( '[caption id="attachment_1"]<img src="yacon.jpg" alt="" /> I keep yacon plants nearby.[/caption]' ),
			'preformatted over paragraphs' => array( "<pre>\nIntro.\n\nI keep yacon plants nearby.\n\n</pre>" ),
			'preformatted with a comment'  => array( "<pre>Intro.<!-- </pre> -->\n\nI keep yacon plants nearby.\n\n</pre>" ),
			'comment over paragraphs'      => array( "<!-- Draft:\n\nI keep yacon plants nearby.\n\n-->\n\nFrost comes late." ),
			'shortcode over paragraphs'    => array( "[caption id=\"attachment_1\"]Intro.\n\nI keep yacon plants nearby.\n\n[/caption]" ),
			'list over paragraphs'         => array( "<ul>\n<li>Intro.\n\nI keep yacon plants nearby.</li>\n</ul>" ),
			'link over paragraphs'         => array( "<a href=\"https://example.test/notes/\">Intro.\n\nI keep yacon plants nearby.</a>" ),
			'hgroup'                       => array( '<hgroup>I keep yacon plants nearby.</hgroup>' ),
			'menu'                         => array( '<menu>I keep yacon plants nearby.</menu>' ),
			'map'                          => array( '<map name="m">I keep yacon plants nearby.</map>' ),
			'legend'                       => array( '<legend>I keep yacon plants nearby.</legend>' ),
		);
	}

	/**
	 * Checks nothing is linked outside a Classic post's own paragraphs.
	 *
	 * @dataProvider data_classic_unlinkable
	 *
	 * @param string $content Post content.
	 */
	public function test_classic_post_is_never_linked_outside_its_paragraphs( string $content ) {
		$this->assertNull( undergrowth_link_word( $content, 'I keep yacon plants nearby.', 'yacon', 'https://example.test/' ) );
	}

	public function test_text_outside_paragraphs_in_a_block_post_is_never_linked() {
		$this->assertNull(
			undergrowth_link_word(
				"<!-- wp:heading --><h2>Oca</h2><!-- /wp:heading -->\n\nI keep yacon plants nearby.",
				'I keep yacon plants nearby.',
				'yacon',
				'https://example.test/'
			)
		);
	}

	/**
	 * Publishes Oca and a Yacon post whose words rank "syrup" below its top 20.
	 *
	 * Yacon has twenty words of its own, each used three times. Oca shares
	 * "tuber" and "syrup" with it, so those two are the least distinctive, and
	 * "syrup", used once, comes last. A proposal stored on an Oca sentence
	 * holding only "syrup" is what a proposal made against fewer posts, before
	 * the rest of the site was indexed, looks like now.
	 *
	 * @param string $sentence Oca sentence the proposal is stored on.
	 * @return int[] Oca's ID, then Yacon's.
	 */
	private function stale_pair( string $sentence ): array {
		$own   = array( 'andes', 'leaves', 'sunflower', 'cousin', 'crisp', 'juicy', 'pale', 'brown', 'skin', 'peel', 'grate', 'salad', 'cellar', 'sprout', 'raw', 'fried', 'chips', 'flour', 'tall', 'roots' );
		$words = array();
		foreach ( array_merge( $own, array( 'tuber' ) ) as $word ) {
			$words[] = str_repeat( $word . ' ', 3 );
		}
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>I keep a tuber here. ' . $sentence . '</p>' ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>' . implode( '', $words ) . 'syrup.</p>' ) );

		$corpus = undergrowth_build_corpus( array( $oca, $yacon ) );
		$this->assertArrayNotHasKey( 'syrup', undergrowth_distinctive_terms( $yacon, $corpus ) );

		global $wpdb;
		$wpdb->replace(
			undergrowth_links_table(),
			array(
				'source_id'      => $oca,
				'target_id'      => $yacon,
				'state'          => 'proposed',
				'score'          => 0.9,
				'sentence'       => $sentence,
				'sentence_index' => 1,
				'created_at'     => current_time( 'mysql', true ),
				'updated_at'     => current_time( 'mysql', true ),
			)
		);

		return array( $oca, $yacon );
	}

	public function test_word_ranked_below_the_top_twenty_is_still_linked() {
		list( $oca, $yacon ) = $this->stale_pair( 'I like syrup on pancakes.' );

		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $yacon ) );
		$this->assertStringContainsString( 'I like <a href="' . get_permalink( $yacon ) . '">syrup</a> on pancakes.', get_post( $oca )->post_content );
	}

	public function test_sentence_sharing_no_word_with_the_target_still_fails() {
		list( $oca, $yacon ) = $this->stale_pair( 'I like honey on pancakes.' );

		$this->assertSame( 'no_word', undergrowth_accept_link( $oca, $yacon ) );
	}

	public function test_missing_post_fails_safely() {
		list( $oca, $yacon ) = $this->pair( '<p>Oca grows here. I keep yacon plants nearby for their syrup.</p>' );
		wp_delete_post( $yacon, true );

		$this->assertSame( 'gone', undergrowth_accept_link( $oca, $yacon ) );
	}

	public function test_accept_form_links_and_returns_with_the_revision() {
		list( $oca, $yacon )  = $this->pair( '<p>Oca grows here. I keep yacon plants nearby for their syrup.</p>' );
		$_POST['source']      = (string) $oca;
		$_POST['target']      = (string) $yacon;
		$nonce                = wp_create_nonce( 'undergrowth_accept_' . $oca . '_' . $yacon );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		try {
			undergrowth_handle_accept();
			$this->fail( 'The handler did not redirect.' );
		} catch ( WPDieException $e ) {
			$this->fail( 'The accept was refused.' );
		} catch ( Exception $e ) {
			$location = $e->getMessage();
		}

		$revisions = wp_get_post_revisions( $oca );
		$this->assertStringContainsString( 'undergrowth_notice=accepted', $location );
		$this->assertStringContainsString( 'revision=' . key( $revisions ), $location );
	}

	public function test_accept_form_is_refused_for_a_post_the_user_cannot_edit() {
		list( $oca, $yacon ) = $this->pair( '<p>Oca grows here. I keep yacon plants nearby for their syrup.</p>' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$_POST['source']      = (string) $oca;
		$_POST['target']      = (string) $yacon;
		$nonce                = wp_create_nonce( 'undergrowth_accept_' . $oca . '_' . $yacon );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$this->expectException( WPDieException::class );
		undergrowth_handle_accept();
	}

	public function test_screen_offers_a_labelled_accept_button() {
		$this->pair( '<p>Oca grows here. I keep yacon plants nearby for their syrup.</p>' );
		set_current_screen( 'posts_page_undergrowth-links' );

		ob_start();
		undergrowth_render_suggestions_page();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<button[^>]*aria-label="Accept the link from Oca to Yacon"/', $html );
	}

	public function test_screen_links_to_the_revision_after_accepting() {
		$_GET['undergrowth_notice'] = 'accepted';
		$_GET['revision']           = '42';
		set_current_screen( 'posts_page_undergrowth-links' );

		ob_start();
		undergrowth_render_suggestions_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'revision.php?revision=42', $html );
	}
}
