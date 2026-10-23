<?php
/**
 * Tests for choosing the words a suggested link sits on.
 *
 * @package Undergrowth
 */

/**
 * The author chooses which of their own words become the link.
 */
class Test_Choose_Words extends WP_UnitTestCase {

	use Plants_Posts;

	const SENTENCE = 'Every spring I copy the seed ledger out again.';

	/**
	 * Post the link sits in.
	 *
	 * @var int
	 */
	private $source;

	/**
	 * Post the link points to.
	 *
	 * @var int
	 */
	private $target;

	public function set_up() {
		parent::set_up();

		$GLOBALS['submenu']       = array();
		$GLOBALS['_parent_pages'] = array();

		$this->start_planting();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );

		$this->plant( 'Shed', array( 0.0, 0.0, 1.0 ), array( 'post_content' => '<p>Frost again.</p>' ) );
		$this->source = $this->plant( 'Spring', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<!-- wp:paragraph --><p>' . self::SENTENCE . '</p><!-- /wp:paragraph -->' ) );
		$this->target = $this->plant( 'The Seed Ledger', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>The ledger lists every seed. A ledger needs care.</p>' ) );
	}

	public function tear_down() {
		unset( $_POST['source'], $_POST['target'], $_POST['first'], $_POST['last'], $_POST['shown'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['choose'] );
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
	 * Reads the stored words of the proposal.
	 *
	 * @return array{anchor: string, anchor_offset: string, anchor_chosen: string}
	 */
	private function stored(): array {
		global $wpdb;

		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT anchor, anchor_offset, anchor_chosen FROM %i WHERE source_id = %d AND target_id = %d', undergrowth_links_table(), $this->source, $this->target ), ARRAY_A );
	}

	/**
	 * Chooses words by position, as the form does.
	 *
	 * @param int         $first Position of the first word.
	 * @param int         $last  Position of the last word.
	 * @param string|null $shown Version of the sentence the form showed.
	 * @return string Result.
	 */
	private function choose( int $first, int $last, ?string $shown = null ): string {
		return undergrowth_choose_words( $this->source, $this->target, $first, $last, $shown ?? undergrowth_sentence_version( self::SENTENCE ) );
	}

	/**
	 * Renders the screen and returns its HTML.
	 *
	 * @return string
	 */
	private function render(): string {
		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();

		return (string) ob_get_clean();
	}

	/**
	 * Fills in the choose form's fields.
	 *
	 * @param int $first Position of the first word.
	 * @param int $last  Position of the last word.
	 */
	private function form( int $first, int $last ): void {
		$_POST['source']      = (string) $this->source;
		$_POST['target']      = (string) $this->target;
		$_POST['first']       = (string) $first;
		$_POST['last']        = (string) $last;
		$_POST['shown']       = undergrowth_sentence_version( self::SENTENCE );
		$nonce                = wp_create_nonce( 'undergrowth_choose_words_' . $this->source . '_' . $this->target );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
	}

	public function test_chosen_words_are_stored_as_the_authors_choice() {
		$this->assertSame( 'words_saved', $this->choose( 3, 5 ) );

		$this->assertSame(
			array(
				'anchor'        => 'copy the seed',
				'anchor_offset' => '15',
				'anchor_chosen' => '1',
			),
			$this->stored()
		);
	}

	public function test_accept_links_exactly_the_chosen_words() {
		$this->choose( 0, 1 );

		$this->assertSame( 'accepted', undergrowth_accept_link( $this->source, $this->target ) );
		$this->assertStringContainsString( '<p><a href="' . get_permalink( $this->target ) . '">Every spring</a> I copy', get_post( $this->source )->post_content );
	}

	public function test_one_word_can_be_chosen() {
		$this->assertSame( 'words_saved', $this->choose( 6, 6 ) );
		$this->assertSame( 'ledger', $this->stored()['anchor'] );
	}

	/**
	 * Checks positions that are not a run of words in the sentence are refused.
	 *
	 * @dataProvider bad_runs
	 *
	 * @param int $first Position of the first word.
	 * @param int $last  Position of the last word.
	 */
	public function test_a_run_outside_the_sentence_is_refused( int $first, int $last ) {
		$before = $this->stored();

		$this->assertSame( 'words_invalid', $this->choose( $first, $last ) );
		$this->assertSame( $before, $this->stored() );
	}

	/**
	 * Positions that are not a run of words in the sentence.
	 *
	 * @return array<string, array{0: int, 1: int}>
	 */
	public function bad_runs(): array {
		return array(
			'last before first' => array( 5, 3 ),
			'before the start'  => array( -1, 2 ),
			'past the end'      => array( 7, 9 ),
		);
	}

	public function test_a_choice_made_on_a_sentence_that_has_since_changed_is_refused() {
		$before = $this->stored();

		$this->assertSame( 'edited', $this->choose( 3, 5, undergrowth_sentence_version( 'Another sentence.' ) ) );
		$this->assertSame( $before, $this->stored() );
	}

	public function test_a_run_that_cannot_be_linked_safely_is_refused() {
		wp_update_post(
			array(
				'ID'           => $this->source,
				'post_content' => '<!-- wp:paragraph --><p>Every spring I copy the <em>seed</em> ledger out again.</p><!-- /wp:paragraph -->',
			)
		);
		$before = $this->stored();

		$this->assertSame( 'words_unsafe', $this->choose( 4, 6 ) );
		$this->assertSame( $before, $this->stored() );
	}

	public function test_a_vetoed_suggestion_cannot_have_words_chosen() {
		undergrowth_veto_link( $this->source, $this->target );

		$this->assertSame( 'not_proposed', $this->choose( 3, 5 ) );
	}

	public function test_a_refresh_keeps_the_authors_choice_while_the_sentence_stays() {
		$this->choose( 3, 5 );

		undergrowth_refresh_proposals( $this->source );

		$this->assertSame( 'copy the seed', $this->stored()['anchor'] );
		$this->assertSame( '1', $this->stored()['anchor_chosen'] );
	}

	public function test_a_refresh_drops_the_authors_choice_once_the_sentence_changes() {
		global $wpdb;

		$this->choose( 3, 5 );
		$wpdb->update(
			undergrowth_links_table(),
			array( 'sentence' => 'An older sentence.' ),
			array(
				'source_id' => $this->source,
				'target_id' => $this->target,
			)
		);

		undergrowth_refresh_proposals( $this->source );

		$this->assertSame( 'seed ledger', $this->stored()['anchor'] );
		$this->assertSame( '0', $this->stored()['anchor_chosen'] );
	}

	public function test_the_form_saves_and_returns_with_a_notice() {
		$this->form( 3, 5 );

		try {
			undergrowth_handle_choose_words();
			$this->fail( 'The handler did not redirect.' );
		} catch ( WPDieException $e ) {
			$this->fail( $e->getMessage() );
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'undergrowth_notice=words_saved', $e->getMessage() );
		}

		$this->assertSame( 'copy the seed', $this->stored()['anchor'] );
	}

	public function test_the_form_without_a_valid_nonce_is_refused() {
		$this->form( 3, 5 );
		$_POST['_wpnonce']    = 'forged';
		$_REQUEST['_wpnonce'] = 'forged';

		$this->expectException( WPDieException::class );
		undergrowth_handle_choose_words();
	}

	public function test_the_form_for_a_post_the_user_cannot_edit_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->form( 3, 5 );
		$before = $this->stored();

		try {
			undergrowth_handle_choose_words();
			$this->fail( 'The choice was not refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( $before, $this->stored() );
		}
	}

	public function test_each_suggestion_offers_choose_words_named_with_both_titles() {
		$this->assertMatchesRegularExpression( '#<span class=\'choose\'><a href="[^"]*choose=' . $this->source . '-' . $this->target . '[^"]*\#undergrowth-choose-' . $this->source . '-' . $this->target . '" aria-label="Choose words for the link from Spring to The Seed Ledger">Choose words</a></span>#', $this->render() );
	}

	public function test_the_form_opens_only_on_the_chosen_suggestion() {
		$this->assertStringNotContainsString( 'undergrowth-choose-words', $this->render() );

		$_GET['choose'] = $this->source . '-' . $this->target;
		$html           = $this->render();

		$this->assertSame( 1, substr_count( $html, 'class="undergrowth-choose-words"' ) );
	}

	public function test_the_form_lists_every_word_with_the_current_choice_selected() {
		$_GET['choose'] = $this->source . '-' . $this->target;
		$html           = $this->render();

		$this->assertMatchesRegularExpression( '#<label for="undergrowth-first-' . $this->source . '-' . $this->target . '">[^<]+</label>#', $html );
		$this->assertMatchesRegularExpression( '#<label for="undergrowth-last-' . $this->source . '-' . $this->target . '">[^<]+</label>#', $html );
		$this->assertSame( 2, substr_count( $html, '<option value="8">again (9 of 9)</option>' ) );
		$this->assertStringContainsString( '<option value="5" selected=\'selected\'>seed (6 of 9)</option>', $html );
		$this->assertStringContainsString( '<option value="6" selected=\'selected\'>ledger (7 of 9)</option>', $html );
	}

	public function test_the_form_never_offers_a_text_field() {
		$_GET['choose'] = $this->source . '-' . $this->target;
		$html           = $this->render();
		$form           = substr( $html, (int) strpos( $html, 'class="undergrowth-choose-words"' ) );
		$form           = substr( $form, 0, (int) strpos( $form, '</form>' ) );

		$this->assertDoesNotMatchRegularExpression( '#<textarea|<input(?![^>]*type="hidden")#', $form );
		$this->assertStringContainsString( '<legend>', $form );
	}

	public function test_saved_words_are_reported_and_marked() {
		$this->choose( 3, 5 );
		$_GET['undergrowth_notice'] = 'words_saved';

		$html = $this->render();
		unset( $_GET['undergrowth_notice'] );

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'I <mark>copy the seed</mark> ledger', $html );
	}
}
