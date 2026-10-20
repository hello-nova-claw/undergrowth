<?php
/**
 * Tests for the compost and return actions on the Posts screen, and for
 * keeping composted posts out of the link suggestions screen.
 *
 * @package Undergrowth
 */

/**
 * Compost and return actions.
 */
class Test_Admin_Compost extends WP_UnitTestCase {

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		unset( $_GET['post'], $_GET['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['tab'], $_GET['undergrowth_stage'], $_GET['undergrowth_composted'], $_GET['undergrowth_returned'], $_GET['undergrowth_skipped'], $_SERVER['HTTP_REFERER'], $_REQUEST['_wp_http_referer'] );
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
	 * Follows a row action's link and returns where its handler redirects.
	 *
	 * @param string $action  'compost' or 'return'.
	 * @param int    $post_id Post ID.
	 * @return string Redirect location.
	 * @throws WPDieException When the handler refuses the request.
	 */
	private function follow( string $action, int $post_id ): string {
		$_GET['post']         = (string) $post_id;
		$nonce                = wp_create_nonce( 'undergrowth_' . $action . '_' . $post_id );
		$_GET['_wpnonce']     = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		try {
			call_user_func( 'undergrowth_handle_' . $action . '_row' );
		} catch ( WPDieException $e ) {
			throw $e;
		} catch ( Exception $e ) {
			return $e->getMessage();
		}

		return '';
	}

	/**
	 * Renders one tab of the link suggestions screen.
	 *
	 * @param string $tab 'stages', or an empty string for the suggestions.
	 * @return string HTML.
	 */
	private function render( string $tab = '' ): string {
		if ( '' !== $tab ) {
			$_GET['tab'] = $tab;
		}

		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();

		return (string) ob_get_clean();
	}

	/**
	 * Plants three related posts that propose links to each other.
	 *
	 * @return int[] Post IDs of Oca, Yacon and Mashua.
	 */
	private function plant_garden(): array {
		return array(
			$this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) ),
			$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) ),
			$this->plant( 'Mashua', array( 0.8, 0.3, 0.0 ) ),
		);
	}

	/**
	 * Returns the row actions the Posts screen shows for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string>
	 */
	private function row_actions( int $post_id ): array {
		$post = get_post( $post_id );

		return apply_filters( 'post_row_actions', array(), $post );
	}

	public function test_garden_post_offers_a_labelled_compost_link() {
		list( $oca ) = $this->plant_garden();

		$actions = $this->row_actions( $oca );

		$this->assertArrayHasKey( 'undergrowth_compost', $actions );
		$this->assertArrayNotHasKey( 'undergrowth_return', $actions );
		$this->assertStringContainsString( 'action=undergrowth_compost', $actions['undergrowth_compost'] );
		$this->assertStringContainsString( '_wpnonce=', $actions['undergrowth_compost'] );
		$this->assertStringContainsString( 'aria-label="Compost &#8220;Oca&#8221;"', $actions['undergrowth_compost'] );
		$this->assertStringContainsString( '>Compost<', $actions['undergrowth_compost'] );
	}

	public function test_composting_post_offers_only_a_return_link() {
		list( $oca ) = $this->plant_garden();
		undergrowth_compost_post( $oca );

		$actions = $this->row_actions( $oca );

		$this->assertArrayHasKey( 'undergrowth_return', $actions );
		$this->assertArrayNotHasKey( 'undergrowth_compost', $actions );
		$this->assertStringContainsString( 'aria-label="Return &#8220;Oca&#8221; to the garden"', $actions['undergrowth_return'] );
		$this->assertStringContainsString( '>Return to garden<', $actions['undergrowth_return'] );
	}

	public function test_posts_outside_the_garden_offer_no_stage_links() {
		$draft  = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$locked = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		$page   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$this->assertSame( array(), $this->row_actions( $draft ) );
		$this->assertSame( array(), $this->row_actions( $locked ) );
		$this->assertSame( array(), apply_filters( 'page_row_actions', array(), get_post( $page ) ) );
	}

	public function test_indexed_pages_offer_the_compost_link_too() {
		add_filter(
			'undergrowth_post_types',
			static function () {
				return array( 'post', 'page' );
			}
		);
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$this->assertArrayHasKey( 'undergrowth_compost', apply_filters( 'page_row_actions', array(), get_post( $page ) ) );
	}

	public function test_post_the_user_cannot_edit_offers_no_stage_links() {
		list( $oca ) = $this->plant_garden();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->assertSame( array(), $this->row_actions( $oca ) );
	}

	public function test_compost_link_composts_and_returns_to_the_posts_screen() {
		list( $oca )             = $this->plant_garden();
		$_SERVER['HTTP_REFERER'] = admin_url( 'edit.php?paged=2&undergrowth_returned=4' );

		$location = $this->follow( 'compost', $oca );

		$this->assertSame( 'composting', undergrowth_get_stage( $oca ) );
		$this->assertStringContainsString( 'edit.php', $location );
		$this->assertStringContainsString( 'paged=2', $location );
		$this->assertStringContainsString( 'undergrowth_composted=1', $location );
		$this->assertStringNotContainsString( 'undergrowth_returned', $location );
	}

	public function test_return_link_returns_the_post_to_the_garden() {
		list( $oca ) = $this->plant_garden();
		undergrowth_compost_post( $oca );

		$location = $this->follow( 'return', $oca );

		$this->assertSame( 'seedling', undergrowth_get_stage( $oca ) );
		$this->assertStringContainsString( admin_url( 'edit.php' ), $location );
		$this->assertStringContainsString( 'undergrowth_returned=1', $location );
	}

	public function test_row_action_from_another_screen_returns_to_the_posts_list() {
		list( $oca )             = $this->plant_garden();
		$_SERVER['HTTP_REFERER'] = admin_url( 'options-general.php' );

		$location = $this->follow( 'compost', $oca );

		$this->assertStringStartsWith( admin_url( 'edit.php?' ), $location );
	}

	public function test_row_action_without_a_valid_nonce_is_refused() {
		list( $oca )          = $this->plant_garden();
		$_GET['post']         = (string) $oca;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_compost_' . ( $oca + 1 ) );

		$this->expectException( WPDieException::class );

		try {
			undergrowth_handle_compost_row();
		} finally {
			$this->assertSame( 'seedling', undergrowth_get_stage( $oca ) );
		}
	}

	public function test_row_action_the_user_may_not_take_is_refused() {
		list( $oca ) = $this->plant_garden();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'You cannot change the growth stage of this post.' );

		try {
			$this->follow( 'compost', $oca );
		} finally {
			$this->assertSame( 'seedling', undergrowth_get_stage( $oca ) );
		}
	}

	public function test_row_action_on_a_post_already_composting_explains_why() {
		list( $oca ) = $this->plant_garden();
		undergrowth_compost_post( $oca );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'This post is already composting.' );

		$this->follow( 'compost', $oca );
	}

	public function test_bulk_actions_offer_compost_and_return() {
		$actions = undergrowth_compost_bulk_actions( array( 'trash' => 'Move to Trash' ) );

		$this->assertSame( 'Move to Trash', $actions['trash'] );
		$this->assertSame( 'Compost', $actions['undergrowth_compost'] );
		$this->assertSame( 'Return to garden', $actions['undergrowth_return'] );
	}

	public function test_bulk_actions_are_left_out_for_a_user_who_cannot_edit_posts() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( array(), undergrowth_compost_bulk_actions( array() ) );
	}

	public function test_bulk_actions_follow_the_capability_of_the_listed_type() {
		register_post_type(
			'undergrowth_crop',
			array(
				'public'          => true,
				'capability_type' => 'crop',
				'map_meta_cap'    => true,
			)
		);
		$grower = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $grower )->add_cap( 'edit_crops' );
		wp_set_current_user( $grower );

		$GLOBALS['typenow'] = 'undergrowth_crop';
		$this->assertArrayHasKey( 'undergrowth_compost', undergrowth_compost_bulk_actions( array() ) );

		$GLOBALS['typenow'] = 'post';
		$this->assertSame( array(), undergrowth_compost_bulk_actions( array() ) );

		unregister_post_type( 'undergrowth_crop' );
	}

	public function test_bulk_compost_counts_what_changed_and_what_was_left() {
		list( $oca, $yacon, $mashua ) = $this->plant_garden();
		undergrowth_compost_post( $mashua );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$location = undergrowth_handle_compost_bulk( admin_url( 'edit.php?undergrowth_skipped=9' ), 'undergrowth_compost', array( $oca, $yacon, $mashua, $draft ) );

		$this->assertSame( 'composting', undergrowth_get_stage( $oca ) );
		$this->assertSame( 'composting', undergrowth_get_stage( $yacon ) );
		$this->assertStringContainsString( 'undergrowth_composted=2', $location );
		$this->assertStringContainsString( 'undergrowth_skipped=2', $location );
	}

	public function test_bulk_return_returns_each_composting_post() {
		list( $oca, $yacon ) = $this->plant_garden();
		undergrowth_compost_post( $oca );
		undergrowth_compost_post( $yacon );

		$location = undergrowth_handle_compost_bulk( admin_url( 'edit.php' ), 'undergrowth_return', array( $oca, $yacon ) );

		$this->assertSame( 'seedling', undergrowth_get_stage( $oca ) );
		$this->assertSame( 'seedling', undergrowth_get_stage( $yacon ) );
		$this->assertStringContainsString( 'undergrowth_returned=2', $location );
		$this->assertStringNotContainsString( 'undergrowth_skipped', $location );
	}

	public function test_other_bulk_actions_are_left_alone() {
		list( $oca ) = $this->plant_garden();

		$this->assertSame( 'edit.php?x=1', undergrowth_handle_compost_bulk( 'edit.php?x=1', 'trash', array( $oca ) ) );
		$this->assertSame( 'seedling', undergrowth_get_stage( $oca ) );
	}

	public function test_posts_screen_hooks_are_added_for_indexed_types_only() {
		undergrowth_add_posts_list_hooks( 'post' );
		undergrowth_add_posts_list_hooks( 'page' );

		$this->assertNotFalse( has_filter( 'bulk_actions-edit-post', 'undergrowth_compost_bulk_actions' ) );
		$this->assertNotFalse( has_filter( 'handle_bulk_actions-edit-post', 'undergrowth_handle_compost_bulk' ) );
		$this->assertNotFalse( has_filter( 'views_edit-post', 'undergrowth_composting_view' ) );
		$this->assertNotFalse( has_action( 'admin_notices', 'undergrowth_render_compost_notice' ) );
		$this->assertFalse( has_filter( 'bulk_actions-edit-page', 'undergrowth_compost_bulk_actions' ) );
	}

	public function test_loading_the_posts_screen_adds_its_hooks() {
		$GLOBALS['typenow'] = 'post';

		undergrowth_load_posts_screen();

		$this->assertNotFalse( has_filter( 'bulk_actions-edit-post', 'undergrowth_compost_bulk_actions' ) );
	}

	public function test_row_action_on_a_page_returns_to_the_pages_list() {
		add_filter(
			'undergrowth_post_types',
			static function () {
				return array( 'post', 'page' );
			}
		);
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$location = $this->follow( 'compost', $page );

		$this->assertStringStartsWith( admin_url( 'edit.php?post_type=page' ), $location );
	}

	public function test_composting_post_is_marked_in_the_posts_list() {
		list( $oca, $yacon ) = $this->plant_garden();
		undergrowth_compost_post( $oca );

		$this->assertSame( array( 'undergrowth_composting' => 'Composting' ), apply_filters( 'display_post_states', array(), get_post( $oca ) ) );
		$this->assertSame( array(), apply_filters( 'display_post_states', array(), get_post( $yacon ) ) );
	}

	public function test_composting_view_appears_only_when_a_post_is_composting() {
		list( $oca )        = $this->plant_garden();
		$GLOBALS['typenow'] = 'post';

		$this->assertSame( array( 'all' => 'All' ), undergrowth_composting_view( array( 'all' => 'All' ) ) );

		undergrowth_compost_post( $oca );
		$views = undergrowth_composting_view( array( 'all' => 'All' ) );

		$this->assertArrayHasKey( 'undergrowth_composting', $views );
		$this->assertStringContainsString( 'undergrowth_stage=composting', $views['undergrowth_composting'] );
		$this->assertStringContainsString( 'Composting <span class="count">(1)</span>', $views['undergrowth_composting'] );
		$this->assertStringNotContainsString( 'current', $views['undergrowth_composting'] );
	}

	public function test_composting_view_is_marked_current_when_chosen() {
		list( $oca ) = $this->plant_garden();
		undergrowth_compost_post( $oca );
		$GLOBALS['typenow']        = 'post';
		$_GET['undergrowth_stage'] = 'composting';

		$views = undergrowth_composting_view( array() );

		$this->assertStringContainsString( 'class="current" aria-current="page"', $views['undergrowth_composting'] );
	}

	public function test_composting_view_lists_only_composting_posts() {
		list( $oca, $yacon ) = $this->plant_garden();
		undergrowth_compost_post( $oca );
		$GLOBALS['pagenow']        = 'edit.php';
		$_GET['undergrowth_stage'] = 'composting';
		set_current_screen( 'edit-post' );

		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$posts                   = $query->query(
			array(
				'post_type' => 'post',
				'fields'    => 'ids',
			)
		);

		$this->assertSame( array( $oca ), array_map( 'intval', $posts ) );
		$this->assertNotContains( $yacon, $posts );
	}

	public function test_other_queries_ignore_the_composting_view() {
		list( $oca, $yacon ) = $this->plant_garden();
		undergrowth_compost_post( $oca );
		$GLOBALS['pagenow']        = 'edit.php';
		$_GET['undergrowth_stage'] = 'composting';
		set_current_screen( 'edit-post' );

		$posts = get_posts( array( 'fields' => 'ids' ) );

		$this->assertContains( $yacon, $posts );
	}

	public function test_notices_report_counts_without_time_or_nudges() {
		set_current_screen( 'edit-post' );
		$_GET['undergrowth_composted'] = '2';
		$_GET['undergrowth_returned']  = '1';
		$_GET['undergrowth_skipped']   = '1';

		ob_start();
		undergrowth_render_compost_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( '2 posts moved to compost.', $html );
		$this->assertStringContainsString( '1 post returned to the garden.', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( '1 post was left as it was', $html );
		$this->assertDoesNotMatchRegularExpression( '/\b(days?|weeks?|months?|years?|since|ago|soon)\b/i', wp_strip_all_tags( $html ) );
	}

	public function test_no_notice_without_a_result() {
		set_current_screen( 'edit-post' );

		ob_start();
		undergrowth_render_compost_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_result_arguments_are_removed_from_the_address() {
		$args = wp_removable_query_args();

		$this->assertContains( 'undergrowth_composted', $args );
		$this->assertContains( 'undergrowth_returned', $args );
		$this->assertContains( 'undergrowth_skipped', $args );
	}

	public function test_stages_tab_leaves_out_composting_posts() {
		list( $oca, $yacon ) = $this->plant_garden();
		undergrowth_compost_post( $oca );

		$html = $this->render( 'stages' );

		$this->assertStringNotContainsString( '>Oca<', $html );
		$this->assertStringContainsString( '>Yacon<', $html );
		$this->assertStringNotContainsString( '>Composting<', $html );
	}

	public function test_stages_tab_explains_when_every_post_is_composting() {
		list( $oca, $yacon, $mashua ) = $this->plant_garden();
		undergrowth_compost_post( $oca );
		undergrowth_compost_post( $yacon );
		undergrowth_compost_post( $mashua );

		$html = $this->render( 'stages' );

		$this->assertStringNotContainsString( 'wp-list-table', $html );
		$this->assertStringNotContainsString( 'No published posts yet', $html );
		$this->assertStringContainsString( 'Every published post is composting', $html );
	}

	public function test_suggestions_tab_leaves_out_proposals_to_or_from_a_composting_post() {
		list( $oca, $yacon, $mashua ) = $this->plant_garden();
		$this->assertNotSame( array(), $this->proposed( $yacon ) );

		// Stored straight in meta, so the proposals compost_post() removes stay.
		update_post_meta( $oca, 'undergrowth_stage', 'composting' );

		$html = $this->render();

		$this->assertStringContainsString( 'undergrowth-links', $html );
		$this->assertStringNotContainsString( '>Oca<', $html );
		$this->assertStringContainsString( '>Yacon<', $html );
		$this->assertStringContainsString( '>Mashua<', $html );
	}
}
