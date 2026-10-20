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

	public function test_most_distinctive_word_in_the_sentence_is_linked() {
		list( $oca, $yacon ) = $this->pair( '<p>Oca grows in the Andes. I keep a few yacon plants nearby for their syrup.</p>' );

		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $yacon ) );
		$this->assertStringContainsString(
			'I keep a few <a href="' . get_permalink( $yacon ) . '">yacon</a> plants',
			get_post( $oca )->post_content
		);
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

	public function test_sentence_outside_a_paragraph_fails_safely() {
		list( $oca, $yacon ) = $this->pair( '<ul><li>I keep yacon plants nearby for their syrup.</li></ul>' );
		$before              = get_post( $oca )->post_content;

		$this->assertSame( 'no_word', undergrowth_accept_link( $oca, $yacon ) );
		$this->assertSame( $before, get_post( $oca )->post_content );
	}

	public function test_a_failed_accept_leaves_the_suggestion_proposed() {
		list( $oca, $yacon ) = $this->pair( '<ul><li>I keep yacon plants nearby for their syrup.</li></ul>' );

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

	public function test_sentence_inside_a_quote_is_never_linked() {
		list( $oca, $yacon ) = $this->pair( '<!-- wp:quote --><blockquote class="wp-block-quote"><p>I keep yacon plants nearby for their syrup.</p></blockquote><!-- /wp:quote -->' );
		$before              = get_post( $oca )->post_content;

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
