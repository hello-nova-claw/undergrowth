<?php
/**
 * Tests for the Orphans tab: posts no accepted link points to.
 *
 * @package Undergrowth
 */

/**
 * Orphans are indexed posts in the garden with no accepted inbound link,
 * listed newest first with the suggestions that would link to them.
 */
class Test_Orphans extends WP_UnitTestCase {

	use Plants_Posts;
	use Stops_Redirects;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		delete_option( 'undergrowth_suggest_links' );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		unset( $_GET['tab'], $_REQUEST['paged'], $_POST['source'], $_POST['target'], $_REQUEST['_wpnonce'], $_REQUEST['_wp_http_referer'] );
		undergrowth_delete_memory_folder();
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Stores a link between two posts in a given state, as Accept or a
	 * refresh would leave it.
	 *
	 * @param int    $source Post the link sits in.
	 * @param int    $target Post it points to.
	 * @param string $state  'proposed', 'accepted' or 'vetoed'.
	 * @param float  $score  Similarity.
	 * @param string $kind   'cross' for a cross-pollination link, or empty.
	 */
	private function link( int $source, int $target, string $state, float $score = 0.9, string $kind = '' ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->replace(
			undergrowth_links_table(),
			array(
				'source_id'  => $source,
				'target_id'  => $target,
				'state'      => $state,
				'score'      => $score,
				'kind'       => $kind,
				'sentence'   => 'A sentence from post ' . $source . '.',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
	}

	/**
	 * Renders the Orphans tab.
	 *
	 * @return string
	 */
	private function render(): string {
		$_GET['tab'] = 'orphans';

		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();

		return (string) ob_get_clean();
	}

	public function test_an_indexed_post_with_no_accepted_link_in_is_an_orphan() {
		$oca = $this->seed( 'Oca', array( 1.0, 0.0 ) );

		$this->assertSame( array( $oca ), undergrowth_orphan_post_ids( 20 ) );
		$this->assertSame( 1, undergrowth_count_orphans() );
	}

	public function test_a_post_leaves_the_list_once_an_accepted_link_points_to_it() {
		$oca   = $this->seed( 'Oca', array( 1.0, 0.0 ) );
		$yacon = $this->seed( 'Yacon', array( 0.9, 0.1 ) );

		$this->link( $yacon, $oca, 'proposed' );
		$this->assertContains( $oca, undergrowth_orphan_post_ids( 20 ) );

		$this->link( $yacon, $oca, 'accepted' );
		$this->assertNotContains( $oca, undergrowth_orphan_post_ids( 20 ) );
		$this->assertSame( array( $yacon ), undergrowth_orphan_post_ids( 20 ) );
	}

	public function test_a_vetoed_link_does_not_count() {
		$oca   = $this->seed( 'Oca', array( 1.0, 0.0 ) );
		$yacon = $this->seed( 'Yacon', array( 0.9, 0.1 ) );

		$this->link( $yacon, $oca, 'vetoed' );

		$this->assertContains( $oca, undergrowth_orphan_post_ids( 20 ) );
	}

	public function test_a_link_accepted_in_a_post_that_left_the_index_does_not_count() {
		$oca   = $this->seed( 'Oca', array( 1.0, 0.0 ) );
		$yacon = $this->seed( 'Yacon', array( 0.9, 0.1 ) );

		$this->link( $yacon, $oca, 'accepted' );
		wp_update_post(
			array(
				'ID'          => $yacon,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( array( $oca ), undergrowth_orphan_post_ids( 20 ) );
	}

	public function test_composting_posts_never_appear() {
		$oca = $this->seed( 'Oca', array( 1.0, 0.0 ) );
		update_post_meta( $oca, 'undergrowth_stage', 'composting' );

		$this->assertNotContains( $oca, undergrowth_orphan_post_ids( 20 ) );
	}

	public function test_unindexed_posts_never_appear() {
		$read   = $this->seed( 'Oca', array( 1.0, 0.0 ) );
		$unread = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		undergrowth_delete_vector( $unread );
		$draft = $this->seed( 'Yacon', array( 0.9, 0.1 ), array( 'post_status' => 'draft' ) );

		$this->assertSame( array( $read ), undergrowth_orphan_post_ids( 20 ) );
		$this->assertNotContains( $draft, undergrowth_orphan_post_ids( 20 ) );
	}

	public function test_orphans_come_newest_first_and_page() {
		$old    = $this->seed( 'Old', array( 1.0, 0.0 ), array( 'post_date' => '2020-01-01 00:00:00' ) );
		$newest = $this->seed( 'Newest', array( 0.9, 0.1 ), array( 'post_date' => '2024-01-01 00:00:00' ) );
		$middle = $this->seed( 'Middle', array( 0.8, 0.2 ), array( 'post_date' => '2022-01-01 00:00:00' ) );

		$this->assertSame( array( $newest, $middle, $old ), undergrowth_orphan_post_ids( 20 ) );
		$this->assertSame( array( $middle ), undergrowth_orphan_post_ids( 1, 1 ) );
		$this->assertSame( 3, undergrowth_count_orphans() );
	}

	public function test_suggestions_to_a_post_come_best_first_and_only_those_the_user_can_act_on() {
		$oca     = $this->seed( 'Oca', array( 1.0, 0.0 ) );
		$yacon   = $this->seed( 'Yacon', array( 0.9, 0.1 ) );
		$ulluco  = $this->seed( 'Ulluco', array( 0.8, 0.2 ) );
		$mashua  = $this->seed( 'Mashua', array( 0.7, 0.3 ) );
		$other   = self::factory()->user->create( array( 'role' => 'author' ) );
		$locked  = $this->seed( 'Locked', array( 0.6, 0.4 ) );
		$current = get_current_user_id();

		$this->link( $ulluco, $oca, 'proposed', 0.7 );
		$this->link( $yacon, $oca, 'proposed', 0.9 );
		$this->link( $mashua, $oca, 'proposed', 0.8 );
		$this->link( $locked, $oca, 'proposed', 0.95 );
		update_post_meta( $mashua, 'undergrowth_stage', 'composting' );

		// An author may edit only their own posts.
		wp_set_current_user( $other );
		$this->assertSame( array(), undergrowth_suggestions_to( $oca ) );

		wp_set_current_user( $current );
		$this->assertSame( array( $locked, $yacon, $ulluco ), array_column( undergrowth_suggestions_to( $oca ), 'source_id' ) );
	}

	public function test_a_suggestion_keeps_its_kind_so_a_cross_pollination_link_is_named() {
		$oca   = $this->seed( 'Oca', array( 1.0, 0.0 ) );
		$yacon = $this->seed( 'Yacon', array( 0.9, 0.1 ) );

		$this->link( $yacon, $oca, 'proposed', 0.9, 'cross' );

		$this->assertSame( array( 'cross' ), array_column( undergrowth_suggestions_to( $oca ), 'kind' ) );
	}

	public function test_the_tab_lists_each_orphan_with_accept_and_veto_for_its_best_suggestions() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca is a tuber from the Andes.</p>' ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon is sweet. I grow oca beside it.</p>' ) );

		$this->assertSame( 'proposed', undergrowth_link_state( $yacon, $oca ) );

		$html = $this->render();

		$this->assertStringContainsString( 'nav-tab-active" aria-current="page">Orphans', $html );
		$this->assertStringContainsString( 'Could link here', $html );
		$this->assertStringContainsString( 'I grow <mark>oca</mark> beside it.', $html );
		$this->assertStringContainsString( 'form="undergrowth-accept-' . $yacon . '-' . $oca . '"', $html );
		$this->assertStringContainsString( 'form="undergrowth-veto-' . $yacon . '-' . $oca . '"', $html );
		$this->assertStringContainsString( 'id="undergrowth-accept-' . $yacon . '-' . $oca . '"', $html );
	}

	public function test_more_suggestions_than_shown_link_to_the_narrowed_suggestions_view() {
		$oca = $this->seed( 'Oca', array( 1.0, 0.0 ) );

		foreach ( array( 'Yacon', 'Ulluco', 'Mashua', 'Achira' ) as $index => $title ) {
			$this->link( $this->seed( $title, array( 0.9, 0.1 ) ), $oca, 'proposed', 0.9 - $index / 10 );
		}

		$html = $this->render();

		$this->assertSame( UNDERGROWTH_ORPHAN_SUGGESTIONS, substr_count( $html, 'id="undergrowth-accept-' ) );
		$this->assertStringContainsString( esc_url( undergrowth_links_in_url( $oca ) ), $html );
		$this->assertStringContainsString( 'See all 4 suggestions', $html );
	}

	public function test_the_suggestions_column_is_left_out_while_suggestions_are_off() {
		$this->seed( 'Oca', array( 1.0, 0.0 ) );
		update_option( 'undergrowth_suggest_links', '0' );

		$html = $this->render();

		$this->assertStringContainsString( 'Oca', $html );
		$this->assertStringNotContainsString( 'Could link here', $html );
	}

	public function test_an_empty_list_says_there_are_no_orphans_among_the_posts_read() {
		// Published but not read yet, so not an orphan either.
		$unread = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		undergrowth_delete_vector( $unread );

		$html = $this->render();

		$this->assertStringContainsString( 'No orphans. Every post in your garden that Undergrowth has read has at least one accepted link pointing to it.', $html );
	}

	public function test_the_published_date_is_shown_in_the_site_format() {
		update_option( 'date_format', 'Y-m-d' );
		$this->seed( 'Oca', array( 1.0, 0.0 ), array( 'post_date' => '2023-04-05 10:00:00' ) );

		$this->assertStringContainsString( '2023-04-05', $this->render() );
	}

	public function test_no_post_is_an_orphan_when_no_post_type_is_indexed() {
		$this->seed( 'Oca', array( 1.0, 0.0 ) );
		add_filter( 'undergrowth_post_types', '__return_empty_array' );

		$this->assertSame( array(), undergrowth_orphan_post_ids( 20 ) );
		$this->assertSame( 0, undergrowth_count_orphans() );
	}

	public function test_a_page_of_no_posts_lists_none() {
		$this->seed( 'Oca', array( 1.0, 0.0 ) );

		$this->assertSame( array(), undergrowth_orphan_post_ids( 0 ) );
	}

	public function test_a_page_past_the_end_lists_the_last_page() {
		$this->seed( 'Oca', array( 1.0, 0.0 ) );
		// WP_List_Table reads the page from the request.
		$_REQUEST['paged'] = '3';

		$this->assertStringContainsString( '<bdi>Oca</bdi>', $this->render() );
	}

	public function test_accept_from_the_tab_returns_to_its_page_and_the_post_leaves_the_list() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca is a tuber from the Andes.</p>' ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon is sweet. I grow oca beside it.</p>' ) );

		$this->assertContains( $oca, undergrowth_orphan_post_ids( 20 ) );

		$_POST['source']              = (string) $yacon;
		$_POST['target']              = (string) $oca;
		$_REQUEST['_wpnonce']         = wp_create_nonce( 'undergrowth_accept_' . $yacon . '_' . $oca );
		$_REQUEST['_wp_http_referer'] = '/wp-admin/edit.php?page=undergrowth-links&tab=orphans&paged=2';
		$location                     = '';

		try {
			undergrowth_handle_accept();
		} catch ( Exception $redirect ) {
			$location = $redirect->getMessage();
		}

		$this->assertNotContains( $oca, undergrowth_orphan_post_ids( 20 ) );
		$this->assertStringContainsString( 'tab=orphans', $location );
		$this->assertStringContainsString( 'paged=2', $location );
		$this->assertStringContainsString( 'undergrowth_notice=accepted', $location );
	}
}
