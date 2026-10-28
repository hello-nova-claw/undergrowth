<?php
/**
 * Tests for the link suggestions screen.
 *
 * @package Undergrowth
 */

/**
 * The Undergrowth screen, Accept and Veto.
 */
class Test_Admin extends WP_UnitTestCase {

	use Plants_Posts;
	use Stops_Redirects;

	/**
	 * Editor who can see the screen.
	 *
	 * @var int
	 */
	private $editor;

	public function set_up() {
		parent::set_up();

		// Menus registered by an earlier test would otherwise still be listed.
		$GLOBALS['submenu']       = array();
		$GLOBALS['_parent_pages'] = array();

		$this->start_planting();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );
		delete_option( 'undergrowth_suggest_links' );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		unset( $_POST['source'], $_POST['target'], $_POST['post'], $_POST['_wpnonce'], $_POST['undergrowth_suggest_links'], $_GET['source'], $_GET['target'], $_REQUEST['_wpnonce'] );
		undergrowth_delete_memory_folder();

		parent::tear_down();
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
	 * Calls a form handler and returns where it redirected.
	 *
	 * @param callable $handler Handler function.
	 * @return string Redirect location.
	 * @throws WPDieException When the handler refuses the request.
	 */
	private function submit( callable $handler ): string {
		try {
			$handler();
		} catch ( WPDieException $e ) {
			throw $e;
		} catch ( Exception $e ) {
			return $e->getMessage();
		}

		return '';
	}

	/**
	 * Fills the veto form fields.
	 *
	 * @param int $source Source post ID.
	 * @param int $target Target post ID.
	 */
	private function veto_form( int $source, int $target ): void {
		$_POST['source']      = (string) $source;
		$_POST['target']      = (string) $target;
		$nonce                = wp_create_nonce( 'undergrowth_veto_' . $source . '_' . $target );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
	}

	public function test_shown_notice_is_removed_from_the_address() {
		$this->assertContains( 'undergrowth_notice', wp_removable_query_args() );
	}

	public function test_revision_is_removed_from_the_address_only_on_this_screen() {
		set_current_screen( 'posts_page_undergrowth-links' );
		$this->assertContains( 'revision', wp_removable_query_args() );

		// Core's own revisions screen is addressed by it.
		set_current_screen( 'revision' );
		$this->assertNotContains( 'revision', wp_removable_query_args() );

		set_current_screen( 'front' );
	}

	public function test_result_notices_give_focus_to_the_first() {
		ob_start();
		undergrowth_print_result_notices(
			array(
				'notice-success' => '<p>Composted.</p>',
				'notice-warning' => '<p>One was skipped.<script>x</script></p>',
			)
		);
		$html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $html, 'id="undergrowth-notice"' ) );
		$this->assertMatchesRegularExpression( '#<div id="undergrowth-notice" class="notice notice-success is-dismissible" role="status" tabindex="-1"><p>Composted.</p></div>#', $html );
		// Newer versions of wp_kses() drop the element's text with its tags.
		$this->assertMatchesRegularExpression( '#<div class="notice notice-warning is-dismissible" role="status"><p>One was skipped\.x?</p></div>#', $html );
		$this->assertStringNotContainsString( '<script>x', $html );
		$this->assertSame( 1, substr_count( $html, '.focus()' ) );
	}

	public function test_post_link_can_carry_a_class() {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Oca' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertMatchesRegularExpression( '#^<a class="row-title" href="[^"]+post=' . $post_id . '[^"]*"><bdi>Oca</bdi></a>$#', undergrowth_post_link( $post_id, 'row-title' ) );
		$this->assertMatchesRegularExpression( '#^<a href="[^"]+"><bdi>Oca</bdi></a>$#', undergrowth_post_link( $post_id ) );
	}

	public function test_post_link_is_plain_text_when_the_edit_address_is_empty() {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Oca' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'get_edit_post_link', '__return_empty_string' );

		$this->assertSame( '<bdi>Oca</bdi>', undergrowth_post_link( $post_id, 'row-title' ) );
	}

	public function test_suggestions_url_opens_each_tab_with_its_notice() {
		$this->assertSame( admin_url( 'edit.php?page=undergrowth-links' ), undergrowth_suggestions_url() );
		$this->assertSame( admin_url( 'edit.php?page=undergrowth-links&tab=map&undergrowth_notice=resurfacing_dismissed' ), undergrowth_suggestions_url( 'resurfacing_dismissed', 'map' ) );
		$this->assertSame( admin_url( 'edit.php?page=undergrowth-links&tab=stages' ), undergrowth_suggestions_url( '', 'stages' ) );
	}

	public function test_settings_url_opens_settings_undergrowth() {
		$this->assertSame( admin_url( 'options-general.php?page=undergrowth' ), undergrowth_settings_url() );
	}

	public function test_screen_is_listed_under_posts_for_editors() {
		do_action( 'admin_menu' );

		$this->assertStringContainsString( 'edit.php?page=undergrowth-links', menu_page_url( 'undergrowth-links', false ) );
	}

	public function test_screen_is_named_undergrowth_in_the_menu_and_its_heading() {
		global $submenu;

		do_action( 'admin_menu' );

		$names = wp_list_pluck( $submenu['edit.php'], 0, 2 );

		$this->assertSame( 'Undergrowth', $names['undergrowth-links'] );
		$this->assertStringContainsString( 'aria-label="Undergrowth screen"', $this->render() );
	}

	public function test_screen_is_not_offered_to_authors() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		do_action( 'admin_menu' );

		$this->assertSame( '', menu_page_url( 'undergrowth-links', false ) );
	}

	public function test_suggestions_are_on_by_default() {
		$this->assertTrue( undergrowth_suggestions_enabled() );
	}

	public function test_turned_off_suggestions_create_no_proposals() {
		update_option( 'undergrowth_suggest_links', '0' );

		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$this->assertSame( array(), $this->proposed( $oca ) );
		$this->assertNotNull( undergrowth_get_vector( $oca ) );
	}

	public function test_turned_off_suggestions_skip_the_refresh_work_entirely() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		update_option( 'undergrowth_suggest_links', '0' );

		$queries = array();
		$record  = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record );
		undergrowth_refresh_around( $oca );
		remove_filter( 'query', $record );

		$this->assertSame( array(), preg_grep( '/undergrowth_(vectors|links)/', $queries ) );
	}

	public function test_vetoing_marks_the_row_and_rewrites_the_file() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$this->assertTrue( undergrowth_veto_link( $oca, $yacon ) );
		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
		$this->assertStringContainsString( "## vetoes\n- " . get_permalink( $yacon ), (string) file_get_contents( undergrowth_memory_path( $oca ) ) );
	}

	public function test_veto_of_a_missing_post_is_refused() {
		$oca = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertFalse( undergrowth_veto_link( $oca, PHP_INT_MAX ) );
	}

	public function test_veto_is_recorded_even_after_the_suggestion_was_withdrawn() {
		global $wpdb;

		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$wpdb->delete(
			undergrowth_links_table(),
			array(
				'source_id' => $oca,
				'target_id' => $yacon,
			)
		);

		$this->assertTrue( undergrowth_veto_link( $oca, $yacon ) );
		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );

		undergrowth_refresh_proposals( $oca );

		$this->assertNotContains( $yacon, $this->proposed( $oca ) );
	}

	public function test_accepted_link_is_not_turned_into_a_veto() {
		global $wpdb;

		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$wpdb->update(
			undergrowth_links_table(),
			array( 'state' => 'accepted' ),
			array(
				'source_id' => $oca,
				'target_id' => $yacon,
			)
		);

		$this->assertFalse( undergrowth_veto_link( $oca, $yacon ) );
		$this->assertSame( 'accepted', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_veto_form_for_a_deleted_post_says_it_is_gone() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		wp_delete_post( $yacon, true );
		$this->veto_form( $oca, $yacon );

		$this->assertStringContainsString( 'undergrowth_notice=gone', $this->submit( 'undergrowth_handle_veto' ) );

		$_GET['undergrowth_notice'] = 'gone';
		$html                       = $this->render();
		unset( $_GET['undergrowth_notice'] );

		$this->assertStringContainsString( 'no longer exists', $html );
	}

	public function test_veto_form_for_an_accepted_link_says_it_was_left_alone() {
		global $wpdb;

		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$wpdb->update(
			undergrowth_links_table(),
			array( 'state' => 'accepted' ),
			array(
				'source_id' => $oca,
				'target_id' => $yacon,
			)
		);
		$this->veto_form( $oca, $yacon );

		$this->assertStringContainsString( 'undergrowth_notice=unchanged', $this->submit( 'undergrowth_handle_veto' ) );

		$_GET['undergrowth_notice'] = 'unchanged';
		$html                       = $this->render();
		unset( $_GET['undergrowth_notice'] );

		$this->assertStringContainsString( 'already accepted', $html );
	}

	public function test_veto_form_vetoes_and_returns_to_the_list_with_a_notice() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->veto_form( $oca, $yacon );

		$location = $this->submit( 'undergrowth_handle_veto' );

		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
		$this->assertStringStartsWith( admin_url( 'edit.php?page=undergrowth-links' ), $location );
		$this->assertStringContainsString( 'undergrowth_notice=vetoed', $location );
	}

	public function test_veto_form_without_a_valid_nonce_is_refused() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->veto_form( $oca, $yacon );
		$_POST['_wpnonce']    = 'forged';
		$_REQUEST['_wpnonce'] = 'forged';

		$this->expectException( WPDieException::class );
		$this->submit( 'undergrowth_handle_veto' );
	}

	public function test_veto_form_for_a_post_the_user_cannot_edit_is_refused() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->veto_form( $oca, $yacon );

		try {
			$this->submit( 'undergrowth_handle_veto' );
			$this->fail( 'The veto was not refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
		}
	}

	public function test_screen_lists_each_proposal_with_its_sentence_and_a_labelled_veto() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$html = $this->render();

		$this->assertStringContainsString( 'Notes about Oca.', wp_strip_all_tags( $html ) );
		$this->assertStringContainsString( esc_url( (string) get_edit_post_link( $oca, 'raw' ) ), $html );
		$this->assertMatchesRegularExpression( '/<button[^>]*aria-label="[^"]*Oca[^"]*Yacon[^"]*"/', $html );
	}

	/**
	 * Plants two posts whose proposals each quote a sentence with a word
	 * from the other post.
	 *
	 * @return int[] Oca's ID, then Yacon's.
	 */
	private function sharing_pair(): array {
		return array(
			$this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca grows here. I keep yacon nearby.</p>' ) ),
			$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon syrup is sweet. Yacon roots store well beside oca.</p>' ) ),
		);
	}

	public function test_the_quote_is_the_primary_column_as_on_the_comments_screen() {
		$this->sharing_pair();

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<th scope="col" id=.sentence. class=.manage-column column-sentence column-primary./', $html );
		$this->assertMatchesRegularExpression( '/<t[hd] class=.sentence column-sentence has-row-actions column-primary. data-colname="[^"]+"[^>]*><q dir="auto">/', $html );
		$this->assertStringNotContainsString( 'column-actions', $html );
	}

	public function test_link_to_sits_under_the_quote_and_both_titles_open_the_editor() {
		list( $oca, $yacon ) = $this->sharing_pair();

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#</q>\s*<p>Link to <a class="row-title" href="' . preg_quote( esc_url( (string) get_edit_post_link( $yacon, 'raw' ) ), '#' ) . '"><bdi>Yacon</bdi></a></p>#', $html );
		$this->assertMatchesRegularExpression( '#<td class=.source column-source. data-colname="In this post"><a href="' . preg_quote( esc_url( (string) get_edit_post_link( $oca, 'raw' ) ), '#' ) . '"><bdi>Oca</bdi></a>#', $html );
		$this->assertStringNotContainsString( 'href="' . esc_url( (string) get_permalink( $yacon ) ) . '"', $html );
	}

	public function test_accept_and_veto_are_row_actions_with_one_primary_action_per_screen() {
		$this->sharing_pair();

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<div class="row-actions visible"><span class=\'accept\'><button type="submit" form="undergrowth-accept-\d+-\d+" class="button-link" aria-label="Accept the link from Oca to Yacon">Accept</button> \| </span><span class=\'veto\'><button type="submit" form="undergrowth-veto-\d+-\d+" class="button-link" aria-label="Veto the link from Oca to Yacon">Veto</button> \| </span><span class=\'choose\'><a href="[^"]+" aria-label="Choose words for the link from Oca to Yacon">Choose words</a></span></div>#', $html );
		$this->assertStringNotContainsString( 'button-primary', $html );
	}

	public function test_an_untitled_post_is_named_as_core_names_it() {
		list( , $yacon ) = $this->sharing_pair();
		global $wpdb;

		// The title is cleared behind the plugin's back, so the post is not
		// read again and its suggestion stays.
		$wpdb->update( $wpdb->posts, array( 'post_title' => '' ), array( 'ID' => $yacon ) );
		clean_post_cache( $yacon );

		$html = $this->render();

		$this->assertStringContainsString( '<p>Link to <a class="row-title" href="', $html );
		$this->assertStringContainsString( '"><bdi>(no title)</bdi></a></p>', $html );
		$this->assertStringContainsString( 'aria-label="Accept the link from Oca to (no title)"', $html );
	}

	public function test_a_target_the_user_cannot_edit_is_named_without_a_link() {
		list( , $yacon ) = $this->sharing_pair();
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) use ( $yacon ) {
				return 'edit_post' === $cap && isset( $args[0] ) && $yacon === (int) $args[0] ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);

		$html = $this->render();

		$this->assertStringContainsString( '<p>Link to <strong><bdi>Yacon</bdi></strong></p>', $html );
		$this->assertStringNotContainsString( 'href=""', $html );
	}

	public function test_the_word_accept_will_link_is_marked_in_the_quote() {
		$this->sharing_pair();

		$html = $this->render();

		$this->assertStringContainsString( '<q dir="auto">I keep <mark>yacon</mark> nearby.</q>', $html );
	}

	public function test_every_word_accept_will_link_is_inside_one_mark() {
		$this->plant( 'Shed', array( 0.0, 0.0, 1.0 ), array( 'post_content' => '<p>Frost again.</p>' ) );
		$this->plant( 'Spring', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Every spring I copy the seed ledger out again.</p>' ) );
		$this->plant( 'The Seed Ledger', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>The ledger lists every seed. A ledger needs care.</p>' ) );

		$this->assertStringContainsString( '<q dir="auto">Every spring I copy the <mark>seed ledger</mark> out again.</q>', $this->render() );
	}

	public function test_a_quote_with_no_word_to_link_is_shown_without_a_mark() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Frost came early. I keep yacon nearby.</p>' ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon syrup is sweet. Yacon roots store well.</p>' ) );
		global $wpdb;
		$wpdb->update(
			undergrowth_links_table(),
			array( 'sentence' => 'Frost came early.' ),
			array(
				'source_id' => $oca,
				'target_id' => $yacon,
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( '<q dir="auto">Frost came early.</q>', $html );
	}

	public function test_the_mark_escapes_the_authors_words_around_it() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca grows here. I keep &lt;b&gt; yacon &amp; more.</p>' ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon syrup is sweet. Yacon roots store well.</p>' ) );

		$html = $this->render();

		$this->assertStringContainsString( '&lt;b&gt; <mark>yacon</mark> &amp; more.</q>', $html );
		$this->assertNotSame( 0, $oca );
	}

	public function test_quoted_sentence_takes_its_direction_from_its_own_text() {
		$this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$this->assertMatchesRegularExpression( '#<q dir="auto">(<mark>)?Notes(</mark>)? about Oca\.</q>#', $this->render() );
	}

	public function test_screen_buttons_submit_their_own_forms_without_inline_styles() {
		$this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$html = $this->render();
		$this->assertSame( 1, preg_match( '/<table.*?<\/table>/s', $html, $table ) );

		$this->assertStringNotContainsString( 'style=', $table[0] );

		// Accept and Veto on each of the two proposals.
		$this->assertSame( 4, preg_match_all( '/<button[^>]*\bform="([^"]+)"/', $table[0], $buttons ) );

		foreach ( $buttons[1] as $form ) {
			$this->assertMatchesRegularExpression( '/<form[^>]*\bid="' . preg_quote( $form, '/' ) . '"/', $html );
		}
	}

	public function test_screen_only_lists_proposals_the_user_can_edit() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_author' => $author ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		wp_set_current_user( $author );

		set_current_screen( 'posts_page_undergrowth-links' );
		undergrowth_load_list_table();
		$table = new Undergrowth_Links_List_Table();
		$table->prepare_items();

		$this->assertCount( 1, $table->items );
		$this->assertSame( 'Oca', get_the_title( $table->items[0]['source_id'] ) );
	}

	/**
	 * Stores proposals straight in the links table, newest first by ID.
	 *
	 * @param array<int, int[]> $pairs Source and target ID of each proposal.
	 */
	private function propose_rows( array $pairs ): void {
		global $wpdb;

		foreach ( $pairs as $i => $pair ) {
			$time = gmdate( 'Y-m-d H:i:s', 1700000000 + $i );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixtures in the plugin's own table.
			$wpdb->insert(
				undergrowth_links_table(),
				array(
					'source_id'  => $pair[0],
					'target_id'  => $pair[1],
					'state'      => 'proposed',
					'score'      => 0.5,
					'sentence'   => 'A sentence from post ' . $pair[0] . '.',
					'created_at' => $time,
					'updated_at' => $time,
				)
			);
		}
	}

	/**
	 * Prepares the suggestions table on one page.
	 *
	 * @param int $page Page number.
	 * @param int $to   Post the listed suggestions would link to, or 0.
	 * @return Undergrowth_Links_List_Table
	 */
	private function suggestions_table( int $page = 1, int $to = 0 ): Undergrowth_Links_List_Table {
		set_current_screen( 'posts_page_undergrowth-links' );
		undergrowth_load_list_table();
		$_REQUEST['paged'] = (string) $page;
		$table             = new Undergrowth_Links_List_Table( $to );
		$table->prepare_items();
		unset( $_REQUEST['paged'] );

		return $table;
	}

	public function test_an_editor_s_page_loads_only_the_posts_it_shows() {
		$posts = self::factory()->post->create_many( 30 );
		$pairs = array();

		foreach ( $posts as $i => $post ) {
			$pairs[] = array( $post, $posts[ ( $i + 1 ) % 30 ] );
		}

		$this->propose_rows( $pairs );
		wp_cache_flush();

		$table = $this->suggestions_table();
		$shown = array_unique( array_merge( array_column( $table->items, 'source_id' ), array_column( $table->items, 'target_id' ) ) );

		$this->assertCount( Undergrowth_Links_List_Table::PER_PAGE, $table->items );
		$this->assertSame( 30, $table->get_pagination_arg( 'total_items' ) );

		foreach ( $posts as $post ) {
			$this->assertSame( in_array( $post, $shown, true ), false !== wp_cache_get( $post, 'posts' ), (string) $post );
		}
	}

	public function test_pages_keep_the_newest_first_order_and_the_count() {
		$posts = self::factory()->post->create_many( 25 );
		$pairs = array();

		foreach ( $posts as $i => $post ) {
			$pairs[] = array( $post, $posts[ ( $i + 1 ) % 25 ] );
		}

		$this->propose_rows( $pairs );
		$sources = array_reverse( $posts );

		$first  = $this->suggestions_table( 1 );
		$second = $this->suggestions_table( 2 );

		$this->assertSame( array_slice( $sources, 0, 20 ), array_column( $first->items, 'source_id' ) );
		$this->assertSame( array_slice( $sources, 20 ), array_column( $second->items, 'source_id' ) );
		$this->assertSame( 25, $second->get_pagination_arg( 'total_items' ) );
	}

	public function test_composting_posts_and_other_targets_are_left_out_of_the_count() {
		list( $oca, $yacon, $mashua, $ulluco ) = self::factory()->post->create_many( 4 );
		$this->propose_rows(
			array(
				array( $oca, $yacon ),
				array( $yacon, $oca ),
				array( $mashua, $oca ),
				array( $ulluco, $oca ),
				array( $oca, $ulluco ),
			)
		);
		update_post_meta( $mashua, 'undergrowth_stage', 'composting' );
		update_post_meta( $ulluco, 'undergrowth_stage', 'evergreen' );

		$every = $this->suggestions_table();
		$to    = $this->suggestions_table( 1, $oca );

		$this->assertSame( 4, $every->get_pagination_arg( 'total_items' ) );
		$this->assertSame( array( $oca, $ulluco, $yacon, $oca ), array_column( $every->items, 'source_id' ) );
		$this->assertSame( 2, $to->get_pagination_arg( 'total_items' ) );
		$this->assertSame( array( $ulluco, $yacon ), array_column( $to->items, 'source_id' ) );
	}

	public function test_an_author_s_count_covers_only_their_own_posts() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$mine   = self::factory()->post->create_many( 22, array( 'post_author' => $author ) );
		$others = self::factory()->post->create_many( 5 );
		$pairs  = array();

		foreach ( $mine as $i => $post ) {
			$pairs[] = array( $post, $others[ $i % 5 ] );
		}

		foreach ( $others as $post ) {
			$pairs[] = array( $post, $mine[0] );
		}

		$this->propose_rows( $pairs );
		wp_set_current_user( $author );

		$first  = $this->suggestions_table( 1 );
		$second = $this->suggestions_table( 2 );

		$this->assertSame( 22, $first->get_pagination_arg( 'total_items' ) );
		$this->assertSame( array_reverse( $mine ), array_merge( array_column( $first->items, 'source_id' ), array_column( $second->items, 'source_id' ) ) );
	}

	public function test_a_source_an_editor_cannot_edit_is_still_left_out() {
		list( $oca, $yacon ) = self::factory()->post->create_many( 2 );
		$this->propose_rows( array( array( $oca, $yacon ), array( $yacon, $oca ) ) );
		$refuse = static function ( $caps, $cap, $user_id, $args ) use ( $oca ) {
			return 'edit_post' === $cap && isset( $args[0] ) && $oca === (int) $args[0] ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $refuse, 10, 4 );

		$table = $this->suggestions_table();

		remove_filter( 'map_meta_cap', $refuse, 10 );
		$this->assertSame( array( $yacon ), array_column( $table->items, 'source_id' ) );
		$this->assertSame( 1, $table->get_pagination_arg( 'total_items' ) );
	}

	public function test_a_capability_filter_refusing_one_post_keeps_the_count_right_on_every_page() {
		$posts = self::factory()->post->create_many( 25 );
		$pairs = array();

		foreach ( $posts as $i => $post ) {
			$pairs[] = array( $post, $posts[ ( $i + 1 ) % 25 ] );
		}

		$this->propose_rows( $pairs );
		$refused = $posts[0];
		$refuse  = static function ( $allcaps, $caps, $args ) use ( $refused ) {
			if ( 'edit_post' === ( $args[0] ?? '' ) && (int) ( $args[2] ?? 0 ) === $refused ) {
				return array();
			}

			return $allcaps;
		};
		add_filter( 'user_has_cap', $refuse, 10, 3 );

		$first  = $this->suggestions_table( 1 );
		$second = $this->suggestions_table( 2 );

		remove_filter( 'user_has_cap', $refuse, 10 );
		$this->assertSame( 24, $first->get_pagination_arg( 'total_items' ) );
		$this->assertCount( 20, $first->items );
		$this->assertSame( array_slice( array_reverse( $posts ), 20, 4 ), array_column( $second->items, 'source_id' ) );
	}

	public function test_a_type_without_mapped_capabilities_is_checked_row_by_row() {
		register_post_type(
			'undergrowth_plain',
			array(
				'public'          => true,
				'capability_type' => 'post',
				'map_meta_cap'    => false,
			)
		);
		list( $oca, $yacon ) = self::factory()->post->create_many( 2 );
		$plain               = self::factory()->post->create( array( 'post_type' => 'undergrowth_plain' ) );
		$this->propose_rows( array( array( $oca, $yacon ), array( $plain, $oca ) ) );

		// Without mapping, edit_post is a capability of its own, which an
		// editor does not have, though they may edit others' posts.
		$table = $this->suggestions_table();

		unregister_post_type( 'undergrowth_plain' );
		$this->assertSame( array( $oca ), array_column( $table->items, 'source_id' ) );
		$this->assertSame( 1, $table->get_pagination_arg( 'total_items' ) );
	}

	public function test_suggestions_tied_on_time_source_and_score_page_in_target_order() {
		list( $oca, $yacon, $mashua ) = self::factory()->post->create_many( 3 );
		$this->propose_rows( array( array( $oca, $mashua ), array( $oca, $yacon ) ) );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET updated_at = %s', undergrowth_links_table(), '2026-01-01 00:00:00' ) );

		$this->assertSame( array( min( $yacon, $mashua ), max( $yacon, $mashua ) ), array_column( $this->suggestions_table()->items, 'target_id' ) );
	}

	public function test_screen_explains_when_no_provider_is_set() {
		remove_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'options-general.php?page=undergrowth', $html );
		$this->assertStringContainsString( 'need an OpenAI API key', $html );
		$this->assertStringNotContainsString( 'embedding provider', $html );
		$this->assertStringNotContainsString( '<table', $html );
	}

	public function test_screen_has_no_settings_form() {
		$html = $this->render();

		$this->assertStringNotContainsString( 'name="undergrowth_suggest_links"', $html );
		$this->assertStringNotContainsString( 'undergrowth_toggle', $html );
		$this->assertFalse( has_action( 'admin_post_undergrowth_toggle' ) );
	}

	public function test_screen_says_when_suggestions_are_off_and_where_to_turn_them_on() {
		update_option( 'undergrowth_suggest_links', '0' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->render();

		$this->assertStringContainsString( 'Suggestions are off', $html );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'options-general.php?page=undergrowth' ) ) . '"', $html );
	}

	public function test_editors_are_told_suggestions_are_off_without_a_link_they_cannot_use() {
		update_option( 'undergrowth_suggest_links', '0' );

		$html = $this->render();

		$this->assertStringContainsString( 'Suggestions are off', $html );
		$this->assertStringNotContainsString( 'options-general.php?page=undergrowth', $html );
	}

	public function test_screen_explains_when_there_are_no_proposals_yet() {
		$this->assertStringContainsString( 'No link suggestions yet', $this->render() );
	}

	public function test_screen_shows_the_notice_after_a_veto() {
		$_GET['undergrowth_notice'] = 'vetoed';

		$html = $this->render();
		unset( $_GET['undergrowth_notice'] );

		$this->assertStringContainsString( 'won’t be suggested again', $html );
		$this->assertStringContainsString( 'id="undergrowth-notice"', $html );
	}

	public function test_the_notice_takes_focus_once_core_has_moved_it() {
		$_GET['undergrowth_notice'] = 'vetoed';

		$html = $this->render();
		unset( $_GET['undergrowth_notice'] );

		// Core moves notices under the heading when the page is ready, which
		// takes focus away from one focused earlier, so focus waits for load.
		$this->assertStringContainsString( "window.addEventListener( 'load'", $html );
		$this->assertStringContainsString( "document.getElementById( 'undergrowth-notice' )", $html );
	}

	public function test_result_notices_can_be_dismissed_as_cores_are() {
		foreach ( array( 'vetoed', 'gone' ) as $notice ) {
			$_GET['undergrowth_notice'] = $notice;

			$this->assertMatchesRegularExpression( '/<div id="undergrowth-notice" class="notice notice-(success|warning) is-dismissible"/', $this->render() );
		}

		unset( $_GET['undergrowth_notice'] );
	}

	public function test_screen_is_empty_for_users_without_access() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_uninstall_removes_the_toggle() {
		update_option( 'undergrowth_suggest_links', '0' );

		undergrowth_uninstall_site();

		$this->assertFalse( get_option( 'undergrowth_suggest_links' ) );
	}

	public function test_a_row_action_button_points_at_its_form_by_id() {
		$this->assertSame(
			'<button type="submit" form="undergrowth-veto-4-5" class="button-link" aria-label="Veto &quot;Oca&quot;">Veto</button>',
			undergrowth_row_action_button(
				'veto',
				array(
					'source' => 4,
					'target' => 5,
				),
				'Veto "Oca"',
				'Veto'
			)
		);
		$this->assertSame( 'undergrowth-pin-7', undergrowth_row_action_form_id( 'pin', array( 'post' => 7 ) ) );
	}

	public function test_a_row_action_form_holds_its_fields_in_order_and_a_nonce_for_them() {
		$form = undergrowth_row_action_form(
			'accept',
			array(
				'source' => 4,
				'target' => 5,
			)
		);

		$this->assertStringStartsWith( '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="undergrowth-accept-4-5"><input type="hidden" name="action" value="undergrowth_accept" /><input type="hidden" name="source" value="4" /><input type="hidden" name="target" value="5" />', $form );
		$this->assertStringContainsString( 'value="' . wp_create_nonce( 'undergrowth_accept_4_5' ) . '"', $form );
		$this->assertStringEndsWith( '</form>', $form );

		$this->assertStringContainsString( '<input type="hidden" name="post" value="7" />', undergrowth_row_action_form( 'pin', array( 'post' => 7 ) ) );
		$this->assertStringContainsString( wp_create_nonce( 'undergrowth_pin_7' ), undergrowth_row_action_form( 'pin', array( 'post' => 7 ) ) );
	}

	public function test_neither_list_table_builds_its_own_row_action_markup() {
		foreach ( array( 'class-undergrowth-links-list-table.php', 'class-undergrowth-stages-list-table.php' ) as $file ) {
			$this->assertStringNotContainsString( '<button type="submit" form=', (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/' . $file ), $file );
		}
	}

	public function test_the_link_gate_returns_the_posted_pair_once_the_nonce_and_capability_pass() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->veto_form( $oca, $yacon );

		$this->assertSame( array( $oca, $yacon ), undergrowth_posted_link_pair( 'veto' ) );
	}

	public function test_the_link_gate_reads_an_undo_link_from_the_address() {
		$oca                  = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon                = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$_GET['source']       = (string) $oca;
		$_GET['target']       = (string) $yacon;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_unveto_' . $oca . '_' . $yacon );

		$this->assertSame( array( $oca, $yacon ), undergrowth_posted_link_pair( 'unveto', true ) );
	}

	public function test_the_link_gate_checks_the_nonce_for_its_own_action() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->veto_form( $oca, $yacon );

		$this->expectException( WPDieException::class );
		undergrowth_posted_link_pair( 'accept' );
	}

	public function test_the_link_gate_refuses_a_user_who_cannot_edit_the_source() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->veto_form( $oca, $yacon );

		try {
			undergrowth_posted_link_pair( 'veto' );
			$this->fail( 'The gate let the request through.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'You cannot change links in this post.', $e->getMessage() );
		}
	}

	public function test_every_link_handler_is_refused_with_one_message() {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/admin.php' );

		// One gate holds the refusal, so no handler can drift from the others.
		$this->assertSame( 1, substr_count( $source, "'You cannot change links in this post.'" ) );
		$this->assertSame( 0, preg_match_all( "/check_admin_referer\\( 'undergrowth_[a-z_]+_' \\./", $source ) );
	}

	public function test_the_post_gate_returns_the_posted_post_once_the_nonce_passes() {
		$oca                  = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$_POST['post']        = (string) $oca;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_pin_' . $oca );

		$this->assertSame( $oca, undergrowth_posted_post_id( 'pin' ) );

		$this->expectException( WPDieException::class );
		undergrowth_posted_post_id( 'unpin' );
	}
}
