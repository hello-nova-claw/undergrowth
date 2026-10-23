<?php
/**
 * Tests for the views, sorting and bulk actions of the growth stages table.
 *
 * @package Undergrowth
 */

/**
 * The growth stages table filters, sorts and acts on many posts the way
 * core's list tables do, with the same rules as the Posts list.
 */
class Test_Admin_Stages_Table extends WP_UnitTestCase {

	use Stops_Redirects;

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		foreach ( array( 'tab', 'undergrowth_stage', 'orderby', 'order', 'action', 'action2', 'post', '_wpnonce', 'paged' ) as $arg ) {
			unset( $_GET[ $arg ], $_REQUEST[ $arg ] );
		}
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Publishes a post at a stage.
	 *
	 * @param string $title Post title.
	 * @param string $stage A stage, or an empty string to store none.
	 * @return int Post ID.
	 */
	private function post_at( string $title, string $stage = '' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		if ( '' !== $stage ) {
			undergrowth_set_stage( $post_id, $stage );
		}

		return $post_id;
	}

	/**
	 * Sets request arguments the way a link or form would.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	private function request( array $args ): void {
		foreach ( $args as $name => $value ) {
			$_GET[ $name ]     = $value;
			$_REQUEST[ $name ] = $value;
		}
	}

	/**
	 * Renders the stages tab.
	 *
	 * @return string HTML.
	 */
	private function render(): string {
		$_GET['tab'] = 'stages';
		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();

		return (string) ob_get_clean();
	}

	/**
	 * Lists the table's posts in order.
	 *
	 * @return int[] Post IDs.
	 */
	private function listed(): array {
		set_current_screen( 'posts_page_undergrowth-links' );
		undergrowth_load_stages_table();
		$table = new Undergrowth_Stages_List_Table();
		$table->prepare_items();

		return array_map(
			static function ( $post ) {
				return $post->ID;
			},
			$table->items
		);
	}

	/**
	 * Applies a bulk action from the table and returns where it redirects.
	 *
	 * @param string $action   Bulk action value.
	 * @param int[]  $post_ids Ticked posts.
	 * @param bool   $nonce    Whether to send a valid nonce.
	 * @return string Redirect location, or an empty string when nothing redirected.
	 * @throws WPDieException When the handler refuses the request.
	 */
	private function bulk( string $action, array $post_ids, bool $nonce = true ): string {
		$this->request(
			array(
				'tab'      => 'stages',
				'action'   => $action,
				'post'     => array_map( 'strval', $post_ids ),
				'_wpnonce' => $nonce ? wp_create_nonce( 'bulk-undergrowth-stages' ) : 'nope',
			)
		);
		set_current_screen( 'posts_page_undergrowth-links' );

		try {
			undergrowth_handle_stages_bulk();
		} catch ( WPDieException $e ) {
			throw $e;
		} catch ( Exception $e ) {
			return $e->getMessage();
		}

		return '';
	}

	public function test_the_posts_list_and_the_table_share_one_set_of_filters() {
		$this->assertSame(
			array( 'seedling', 'sprout', 'budding', 'evergreen', 'pinned', 'unpinned' ),
			array_keys( undergrowth_stage_filters() )
		);
		$this->assertSame( 'Not pinned', undergrowth_stage_filters()['unpinned'] );
		$this->assertSame( 'Sprout', undergrowth_stage_filters()['sprout'] );

		ob_start();
		undergrowth_render_stage_filter( 'post', 'top' );
		$this->assertStringContainsString( '<option value="unpinned">Not pinned</option>', (string) ob_get_clean() );
	}

	public function test_views_count_each_stage_and_leave_out_empty_ones() {
		$this->post_at( 'Oca', 'sprout' );
		$this->post_at( 'Yacon', 'sprout' );
		$pinned = $this->post_at( 'Mashua', 'evergreen' );
		undergrowth_pin_stage( $pinned, 'evergreen' );
		$this->post_at( 'Ulluco', 'composting' );

		$html = $this->render();

		$this->assertStringContainsString( '<ul class=\'subsubsub\'>', $html );
		$this->assertMatchesRegularExpression( '#<li class=\'all\'><a href="[^"]*" class="current" aria-current="page">All <span class="count">\(3\)</span></a>#', $html );
		$this->assertMatchesRegularExpression( '#<li class=\'sprout\'><a href="[^"]*undergrowth_stage=sprout[^"]*">Sprout <span class="count">\(2\)</span></a>#', $html );
		$this->assertMatchesRegularExpression( '#<li class=\'evergreen\'><a href="[^"]*">Evergreen <span class="count">\(1\)</span></a>#', $html );
		$this->assertMatchesRegularExpression( '#<li class=\'pinned\'><a href="[^"]*undergrowth_stage=pinned[^"]*">Pinned <span class="count">\(1\)</span></a>#', $html );
		$this->assertMatchesRegularExpression( '#<li class=\'unpinned\'><a href="[^"]*undergrowth_stage=unpinned[^"]*">Not pinned <span class="count">\(2\)</span></a>#', $html );
		$this->assertStringNotContainsString( "<li class='seedling'>", $html );
		$this->assertStringNotContainsString( "<li class='budding'>", $html );
		$this->assertStringNotContainsString( "<li class='composting'>", $html );
	}

	public function test_the_chosen_view_is_marked_current() {
		$this->post_at( 'Oca', 'sprout' );
		$this->post_at( 'Yacon', 'budding' );
		$this->request( array( 'undergrowth_stage' => 'sprout' ) );

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<li class=\'sprout\'><a href="[^"]*" class="current" aria-current="page">Sprout#', $html );
		$this->assertDoesNotMatchRegularExpression( '#<li class=\'all\'><a href="[^"]*" class="current"#', $html );
	}

	public function test_a_view_lists_only_its_posts() {
		$oca    = $this->post_at( 'Oca', 'sprout' );
		$yacon  = $this->post_at( 'Yacon', 'budding' );
		$mashua = $this->post_at( 'Mashua' );
		undergrowth_pin_stage( $yacon, 'budding' );
		$this->post_at( 'Ulluco', 'composting' );

		$this->request( array( 'undergrowth_stage' => 'sprout' ) );
		$this->assertSame( array( $oca ), $this->listed() );

		$this->request( array( 'undergrowth_stage' => 'seedling' ) );
		$this->assertSame( array( $mashua ), $this->listed() );

		$this->request( array( 'undergrowth_stage' => 'pinned' ) );
		$this->assertSame( array( $yacon ), $this->listed() );

		// Composting posts are never pinned, and stay off this table.
		$this->request( array( 'undergrowth_stage' => 'unpinned' ) );
		$this->assertSame( array( $mashua, $oca ), $this->listed() );

		$this->request( array( 'undergrowth_stage' => 'composting' ) );
		$this->assertSame( array( $mashua, $oca, $yacon ), $this->listed() );
	}

	public function test_not_pinned_on_the_posts_list_leaves_out_composting_posts() {
		$oca = $this->post_at( 'Oca', 'sprout' );
		$this->post_at( 'Yacon', 'composting' );
		$this->request( array( 'undergrowth_stage' => 'unpinned' ) );
		$GLOBALS['pagenow'] = 'edit.php';
		set_current_screen( 'edit-post' );

		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$posts                   = $query->query(
			array(
				'post_type' => 'post',
				'fields'    => 'ids',
			)
		);
		$GLOBALS['pagenow']      = 'index.php';

		$this->assertSame( array( $oca ), array_map( 'intval', $posts ) );
	}

	public function test_an_empty_view_shows_the_table_with_no_posts() {
		$this->post_at( 'Oca', 'sprout' );
		$this->request( array( 'undergrowth_stage' => 'evergreen' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'wp-list-table', $html );
		$this->assertStringContainsString( '<td class="colspanchange" colspan="4">No posts in this view.</td>', $html );
		$this->assertStringNotContainsString( 'No published posts yet', $html );
	}

	public function test_a_page_past_the_end_shows_the_last_page() {
		$oca = $this->post_at( 'Oca', 'sprout' );
		$this->request( array( 'paged' => '2' ) );

		$this->assertSame( array( $oca ), $this->listed() );

		$html = $this->render();

		$this->assertStringContainsString( '>Oca<', $html );
		$this->assertStringNotContainsString( 'No posts in this view.', $html );
		$this->assertStringNotContainsString( 'No published posts yet', $html );
	}

	public function test_a_page_past_the_end_of_several_shows_the_last_one() {
		$posts = array();

		for ( $i = 1; $i <= Undergrowth_Stages_List_Table::PER_PAGE + 1; $i++ ) {
			$posts[] = $this->post_at( sprintf( 'Post %02d', $i ) );
		}

		$this->request( array( 'paged' => '5' ) );

		$this->assertSame( array( end( $posts ) ), $this->listed() );
	}

	public function test_the_stage_and_pin_have_their_own_columns() {
		$oca = $this->post_at( 'Oca', 'sprout' );
		undergrowth_pin_stage( $oca, 'sprout' );
		$yacon = $this->post_at( 'Yacon', 'budding' );

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<td class=\'stage column-stage\' data-colname="Growth stage"\s*>Sprout</td>#', $html );
		$this->assertMatchesRegularExpression( '#<td class=\'pinned column-pinned\' data-colname="Pinned"\s*><span class="dashicons dashicons-sticky undergrowth-pinned" aria-hidden="true" title="Pinned"></span><span class="screen-reader-text">Pinned</span></td>#', $html );
		$this->assertStringContainsString( '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">Not pinned</span>', $html );
		$this->assertStringContainsString( '<label class="screen-reader-text" for="cb-select-' . $yacon . '">Select Yacon</label>', $html );
		$this->assertStringContainsString( '<input id="cb-select-' . $yacon . '" type="checkbox" name="post[]" value="' . $yacon . '" />', $html );
	}

	public function test_the_row_forms_sit_outside_the_bulk_form() {
		$oca = $this->post_at( 'Oca' );

		$html = $this->render();

		$bulk_start = strpos( $html, '<form method="get" id="undergrowth-stages-form"' );
		$bulk_end   = strpos( $html, '</form>', (int) $bulk_start );
		$row_form   = strpos( $html, 'id="undergrowth-pin-' . $oca . '"' );

		$this->assertNotFalse( $bulk_start );
		$this->assertGreaterThan( $bulk_end, $row_form );
		$this->assertStringContainsString( 'form="undergrowth-pin-' . $oca . '"', substr( $html, (int) $bulk_start, (int) $bulk_end - (int) $bulk_start ) );
	}

	public function test_the_bulk_form_keeps_the_view_and_sort() {
		$this->post_at( 'Oca', 'sprout' );
		$this->request(
			array(
				'undergrowth_stage' => 'sprout',
				'orderby'           => 'stage',
				'order'             => 'desc',
			)
		);

		$html = $this->render();

		foreach ( array(
			'page'              => 'undergrowth-links',
			'tab'               => 'stages',
			'undergrowth_stage' => 'sprout',
			'orderby'           => 'stage',
			'order'             => 'desc',
		) as $name => $value ) {
			$this->assertStringContainsString( '<input type="hidden" name="' . $name . '" value="' . $value . '" />', $html );
		}
	}

	public function test_columns_sort_by_title_stage_rank_and_pin() {
		undergrowth_load_stages_table();
		$table = new Undergrowth_Stages_List_Table();

		$this->assertSame(
			array(
				'post'   => array( 'title', false ),
				'stage'  => array( 'stage', false ),
				'pinned' => array( 'pinned', true ),
			),
			$table->get_sortable_columns()
		);
	}

	public function test_posts_are_listed_by_title_by_default() {
		$yacon = $this->post_at( 'Yacon' );
		$oca   = $this->post_at( 'Oca' );

		$this->assertSame( array( $oca, $yacon ), $this->listed() );

		$this->request( array( 'order' => 'desc' ) );
		$this->assertSame( array( $oca, $yacon ), $this->listed() );

		$this->request(
			array(
				'orderby' => 'title',
				'order'   => 'desc',
			)
		);
		$this->assertSame( array( $yacon, $oca ), $this->listed() );
	}

	public function test_sorting_by_stage_follows_its_rank() {
		$evergreen = $this->post_at( 'Apio', 'evergreen' );
		$seedling  = $this->post_at( 'Yacon' );
		$sprout    = $this->post_at( 'Oca', 'sprout' );
		$budding   = $this->post_at( 'Mashua', 'budding' );

		$this->request( array( 'orderby' => 'stage' ) );
		$this->assertSame( array( $seedling, $sprout, $budding, $evergreen ), $this->listed() );

		$this->request(
			array(
				'orderby' => 'stage',
				'order'   => 'desc',
			)
		);
		$this->assertSame( array( $evergreen, $budding, $sprout, $seedling ), $this->listed() );
	}

	public function test_sorting_by_stage_breaks_ties_by_title() {
		$yacon = $this->post_at( 'Yacon', 'sprout' );
		$oca   = $this->post_at( 'Oca', 'sprout' );

		$this->request( array( 'orderby' => 'stage' ) );

		$this->assertSame( array( $oca, $yacon ), $this->listed() );
	}

	public function test_sorting_by_pin_puts_pinned_posts_first() {
		$apio  = $this->post_at( 'Apio' );
		$yacon = $this->post_at( 'Yacon' );
		$oca   = $this->post_at( 'Oca' );
		undergrowth_pin_stage( $yacon, 'sprout' );

		$this->request(
			array(
				'orderby' => 'pinned',
				'order'   => 'desc',
			)
		);
		$this->assertSame( array( $yacon, $apio, $oca ), $this->listed() );

		$this->request(
			array(
				'orderby' => 'pinned',
				'order'   => 'asc',
			)
		);
		$this->assertSame( array( $apio, $oca, $yacon ), $this->listed() );
	}

	public function test_unknown_sort_values_fall_back_to_title() {
		$yacon = $this->post_at( 'Yacon' );
		$oca   = $this->post_at( 'Oca' );

		$this->request(
			array(
				'orderby' => 'post_author',
				'order'   => 'sideways',
			)
		);

		$this->assertSame( array( $oca, $yacon ), $this->listed() );
		$this->assertSame( array( 'title', 'ASC' ), undergrowth_stages_table_order() );
	}

	public function test_bulk_actions_offer_pin_unpin_and_compost() {
		undergrowth_load_stages_table();
		$table = new Undergrowth_Stages_List_Table();

		$this->assertSame(
			array(
				'pin'     => 'Pin stage',
				'unpin'   => 'Unpin stage',
				'compost' => 'Compost',
			),
			$table->get_bulk_actions()
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( array(), $table->get_bulk_actions() );
	}

	public function test_bulk_pin_pins_each_post_and_returns_with_counts() {
		$oca   = $this->post_at( 'Oca', 'sprout' );
		$yacon = $this->post_at( 'Yacon', 'budding' );
		undergrowth_pin_stage( $yacon, 'budding' );
		$_GET['undergrowth_stage'] = 'sprout';
		$_GET['orderby']           = 'stage';

		$location = $this->bulk( 'pin', array( $oca, $yacon ) );

		$this->assertTrue( undergrowth_is_stage_pinned( $oca ) );
		$this->assertSame( 'sprout', undergrowth_get_stage( $oca ) );
		$this->assertStringContainsString( 'page=undergrowth-links', $location );
		$this->assertStringContainsString( 'tab=stages', $location );
		$this->assertStringContainsString( 'undergrowth_stage=sprout', $location );
		$this->assertStringContainsString( 'orderby=stage', $location );
		$this->assertStringContainsString( 'undergrowth_pinned=1', $location );
		$this->assertStringContainsString( 'undergrowth_skipped=1', $location );
		$this->assertStringNotContainsString( '_wpnonce', $location );
		$this->assertStringNotContainsString( 'action=', $location );
	}

	public function test_bulk_unpin_and_compost_reuse_the_posts_list_rules() {
		$oca   = $this->post_at( 'Oca', 'sprout' );
		$yacon = $this->post_at( 'Yacon', 'sprout' );
		undergrowth_pin_stage( $oca, 'evergreen' );

		$this->assertStringContainsString( 'undergrowth_unpinned=1', $this->bulk( 'unpin', array( $oca, $yacon ) ) );
		$this->assertFalse( undergrowth_is_stage_pinned( $oca ) );

		$location = $this->bulk( 'compost', array( $oca, $yacon ) );

		$this->assertStringContainsString( 'undergrowth_composted=2', $location );
		$this->assertSame( 'composting', undergrowth_get_stage( $yacon ) );
	}

	public function test_bulk_actions_skip_posts_the_user_cannot_edit() {
		$oca    = $this->post_at( 'Oca', 'sprout' );
		$theirs = $this->post_at( 'Yacon', 'sprout' );
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) use ( $theirs ) {
				return 'edit_post' === $cap && isset( $args[0] ) && $theirs === (int) $args[0] ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);

		$location = $this->bulk( 'compost', array( $oca, $theirs ) );

		$this->assertStringContainsString( 'undergrowth_composted=1', $location );
		$this->assertStringContainsString( 'undergrowth_skipped=1', $location );
		$this->assertSame( 'sprout', undergrowth_get_stage( $theirs ) );
	}

	public function test_a_bulk_action_without_a_valid_nonce_is_refused() {
		$oca = $this->post_at( 'Oca', 'sprout' );

		$this->expectException( WPDieException::class );

		try {
			$this->bulk( 'compost', array( $oca ), false );
		} finally {
			$this->assertSame( 'sprout', undergrowth_get_stage( $oca ) );
		}
	}

	public function test_no_bulk_action_leaves_the_page_alone() {
		$oca = $this->post_at( 'Oca', 'sprout' );

		$this->assertSame( '', $this->bulk( '-1', array( $oca ) ) );
		$this->assertSame( '', $this->bulk( 'trash', array( $oca ) ) );
		$this->assertSame( 'sprout', undergrowth_get_stage( $oca ) );
	}

	public function test_a_form_sent_without_an_action_returns_to_a_clean_address() {
		$this->request(
			array(
				'tab'               => 'stages',
				'action'            => '-1',
				'paged'             => '2',
				'undergrowth_stage' => 'sprout',
				'_wpnonce'          => 'abc',
				'_wp_http_referer'  => '/wp-admin/edit.php',
			)
		);
		set_current_screen( 'posts_page_undergrowth-links' );

		try {
			undergrowth_handle_stages_bulk();
			$location = '';
		} catch ( Exception $e ) {
			$location = $e->getMessage();
		}
		unset( $_GET['_wp_http_referer'], $_REQUEST['_wp_http_referer'] );

		$this->assertStringContainsString( 'undergrowth_stage=sprout', $location );
		$this->assertStringContainsString( 'paged=2', $location );
		$this->assertStringNotContainsString( '_wpnonce', $location );
		$this->assertStringNotContainsString( '_wp_http_referer', $location );
	}

	public function test_bulk_actions_on_another_tab_are_ignored() {
		$oca = $this->post_at( 'Oca', 'sprout' );
		$this->request(
			array(
				'action'   => 'compost',
				'post'     => array( (string) $oca ),
				'_wpnonce' => wp_create_nonce( 'bulk-undergrowth-stages' ),
			)
		);

		undergrowth_handle_stages_bulk();

		$this->assertSame( 'sprout', undergrowth_get_stage( $oca ) );
	}

	public function test_sort_links_do_not_carry_a_result_already_shown() {
		$uri                    = $_SERVER['REQUEST_URI'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Put back as it was.
		$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php?page=undergrowth-links&tab=stages&undergrowth_pinned=2&undergrowth_skipped=1&undergrowth_notice=pinned&orderby=stage';

		undergrowth_forget_shown_results();
		$cleaned                = $_SERVER['REQUEST_URI']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compared as it is.
		$_SERVER['REQUEST_URI'] = $uri;

		$this->assertSame( '/wp-admin/edit.php?page=undergrowth-links&tab=stages&orderby=stage', $cleaned );
		$this->assertNotFalse( has_action( 'load-posts_page_undergrowth-links', 'undergrowth_forget_shown_results' ) );
	}

	public function test_the_bulk_result_shows_on_the_stages_tab() {
		$this->post_at( 'Oca', 'sprout' );
		$_GET['undergrowth_pinned'] = '2';

		$html = $this->render();
		unset( $_GET['undergrowth_pinned'] );

		$this->assertStringContainsString( '2 posts pinned at their stages.', $html );
		$this->assertStringContainsString( 'id="undergrowth-notice"', $html );
	}
}
