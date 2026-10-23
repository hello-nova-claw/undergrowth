<?php
/**
 * Tests for filtering, sorting and bulk-pinning the Posts list by growth stage.
 *
 * @package Undergrowth
 */

/**
 * The Posts list filters, sorts and acts on many posts by stage, with core's
 * own filter, sortable column and bulk action hooks.
 */
class Test_Posts_Stage_Filters extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	public function tear_down() {
		unset( $_GET['undergrowth_stage'], $_GET['undergrowth_pinned'], $_GET['undergrowth_unpinned'], $_GET['undergrowth_composted'], $_GET['undergrowth_returned'], $_GET['undergrowth_skipped'] );
		$GLOBALS['pagenow'] = 'index.php';
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Publishes a post at a stage.
	 *
	 * @param string $stage A stage, or an empty string to store none.
	 * @return int Post ID.
	 */
	private function post_at( string $stage ): int {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		if ( '' !== $stage ) {
			undergrowth_set_stage( $post_id, $stage );
		}

		return $post_id;
	}

	/**
	 * Runs a query as the main query of the Posts screen.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return int[] Post IDs in order.
	 */
	private function list_posts( array $args = array() ): array {
		$GLOBALS['pagenow'] = 'edit.php';
		set_current_screen( 'edit-post' );

		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$posts                   = $query->query(
			array_merge(
				array(
					'post_type'      => 'post',
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1,
				),
				$args
			)
		);

		return array_map( 'intval', $posts );
	}

	/**
	 * Prints the filter dropdown above a list.
	 *
	 * @param string $post_type Post type of the list.
	 * @param string $which     'top' or 'bottom'.
	 * @return string HTML.
	 */
	private function filter_html( string $post_type = 'post', string $which = 'top' ): string {
		ob_start();
		undergrowth_render_stage_filter( $post_type, $which );

		return (string) ob_get_clean();
	}

	public function test_the_filter_offers_every_garden_stage_and_pinned() {
		$html = $this->filter_html();

		$this->assertStringContainsString( '<label for="undergrowth-stage-filter" class="screen-reader-text">Filter by growth stage</label>', $html );
		$this->assertStringContainsString( '<select name="undergrowth_stage" id="undergrowth-stage-filter">', $html );
		$this->assertMatchesRegularExpression( '#<option value=""[^>]*>All stages</option>#', $html );

		preg_match_all( '#<option value="([a-z]*)"#', $html, $values );
		$this->assertSame( array( '', 'seedling', 'sprout', 'budding', 'evergreen', 'pinned' ), $values[1] );
		$this->assertStringContainsString( '>Sprout</option>', $html );
		$this->assertStringContainsString( '>Pinned</option>', $html );
	}

	public function test_the_filter_does_not_offer_composting() {
		$this->assertStringNotContainsString( 'value="composting"', $this->filter_html() );
	}

	public function test_the_filter_marks_the_chosen_stage() {
		$_GET['undergrowth_stage'] = 'budding';

		$this->assertMatchesRegularExpression( '#<option value="budding" selected=\'selected\'>#', $this->filter_html() );
	}

	public function test_the_composting_view_keeps_itself_through_the_filter_form() {
		$_GET['undergrowth_stage'] = 'composting';

		$html = $this->filter_html();

		$this->assertStringNotContainsString( '<select', $html );
		$this->assertSame( '<input type="hidden" name="undergrowth_stage" value="composting" />', trim( $html ) );
	}

	public function test_the_filter_is_printed_once_above_the_list() {
		$this->assertSame( '', $this->filter_html( 'post', 'bottom' ) );
	}

	public function test_lists_outside_the_garden_have_no_filter() {
		$this->assertSame( '', $this->filter_html( 'attachment' ) );

		undergrowth_add_posts_list_hooks( 'attachment' );
		$this->assertFalse( has_action( 'restrict_manage_posts', 'undergrowth_render_stage_filter' ) );

		undergrowth_add_posts_list_hooks( 'post' );
		$this->assertNotFalse( has_action( 'restrict_manage_posts', 'undergrowth_render_stage_filter' ) );
	}

	public function test_only_allowed_values_are_read() {
		$_GET['undergrowth_stage'] = 'Sprout';
		$this->assertSame( 'sprout', undergrowth_stage_filter_value() );

		$_GET['undergrowth_stage'] = 'pinned';
		$this->assertSame( 'pinned', undergrowth_stage_filter_value() );

		foreach ( array( 'composting', 'tree', "sprout' OR 1=1", array( 'sprout' ) ) as $value ) {
			$_GET['undergrowth_stage'] = $value;
			$this->assertSame( '', undergrowth_stage_filter_value() );
		}

		unset( $_GET['undergrowth_stage'] );
		$this->assertSame( '', undergrowth_stage_filter_value() );
	}

	public function test_filtering_by_a_stage_lists_only_posts_at_it() {
		$sprout  = $this->post_at( 'sprout' );
		$budding = $this->post_at( 'budding' );
		$this->post_at( 'sprout' );

		$_GET['undergrowth_stage'] = 'budding';

		$this->assertSame( array( $budding ), $this->list_posts() );

		$_GET['undergrowth_stage'] = 'sprout';

		$this->assertContains( $sprout, $this->list_posts() );
		$this->assertCount( 2, $this->list_posts() );
	}

	public function test_seedling_includes_posts_with_no_stored_stage() {
		$stored  = $this->post_at( 'seedling' );
		$new     = $this->post_at( '' );
		$unknown = $this->post_at( '' );
		add_post_meta( $unknown, 'undergrowth_stage', 'tree' );
		$this->post_at( 'evergreen' );
		self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$_GET['undergrowth_stage'] = 'seedling';

		$posts = $this->list_posts();
		sort( $posts );

		$this->assertSame( array( $stored, $new, $unknown ), $posts );
	}

	public function test_a_stage_filter_lists_only_posts_in_the_garden() {
		$draft  = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$locked = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		update_post_meta( $draft, 'undergrowth_stage', 'sprout' );
		update_post_meta( $locked, 'undergrowth_stage', 'sprout' );
		$sprout = $this->post_at( 'sprout' );

		$_GET['undergrowth_stage'] = 'sprout';

		$this->assertSame( array( $sprout ), $this->list_posts() );
	}

	public function test_filtering_by_pinned_lists_only_pinned_posts() {
		$pinned = $this->post_at( 'sprout' );
		undergrowth_pin_stage( $pinned, 'evergreen' );
		$this->post_at( 'evergreen' );

		$_GET['undergrowth_stage'] = 'pinned';

		$this->assertSame( array( $pinned ), $this->list_posts() );
	}

	public function test_an_unknown_filter_value_lists_every_post() {
		$sprout = $this->post_at( 'sprout' );
		$draft  = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$_GET['undergrowth_stage'] = 'tree';

		$this->assertEqualSets( array( $sprout, $draft ), $this->list_posts() );
	}

	public function test_other_queries_ignore_the_filter() {
		$sprout  = $this->post_at( 'sprout' );
		$budding = $this->post_at( 'budding' );

		$_GET['undergrowth_stage'] = 'sprout';
		$GLOBALS['pagenow']        = 'edit.php';
		set_current_screen( 'edit-post' );

		$this->assertEqualSets( array( $sprout, $budding ), get_posts( array( 'fields' => 'ids' ) ) );
	}

	public function test_the_main_query_of_another_screen_ignores_the_filter() {
		$sprout  = $this->post_at( 'sprout' );
		$budding = $this->post_at( 'budding' );

		$_GET['undergrowth_stage'] = 'sprout';
		$GLOBALS['pagenow']        = 'upload.php';

		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$posts                   = $query->query(
			array(
				'post_type' => 'post',
				'fields'    => 'ids',
			)
		);

		$this->assertEqualSets( array( $sprout, $budding ), $posts );
	}

	public function test_lists_outside_the_garden_ignore_the_filter() {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$_GET['undergrowth_stage'] = 'sprout';

		$this->assertSame( array( $page ), $this->list_posts( array( 'post_type' => 'page' ) ) );
	}

	public function test_the_stage_column_is_sortable() {
		undergrowth_add_posts_list_hooks( 'post' );

		$columns = apply_filters( 'manage_edit-post_sortable_columns', array( 'title' => 'title' ) );

		$this->assertSame( array( 'undergrowth_stage', false ), $columns['undergrowth_stage'] );
		$this->assertSame( 'title', $columns['title'] );
	}

	public function test_sorting_by_stage_follows_its_rank_with_posts_without_a_stage_last() {
		$draft      = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$evergreen  = $this->post_at( 'evergreen' );
		$seedling   = $this->post_at( '' );
		$composting = $this->post_at( 'composting' );
		$sprout     = $this->post_at( 'sprout' );
		$budding    = $this->post_at( 'budding' );

		$this->assertSame(
			array( $seedling, $sprout, $budding, $evergreen, $composting, $draft ),
			$this->list_posts(
				array(
					'orderby' => 'undergrowth_stage',
					'order'   => 'asc',
				)
			)
		);

		$this->assertSame(
			array( $composting, $evergreen, $budding, $sprout, $seedling, $draft ),
			$this->list_posts(
				array(
					'orderby' => 'undergrowth_stage',
					'order'   => 'desc',
				)
			)
		);
	}

	public function test_sorting_by_stage_lists_each_post_once() {
		$sprout = $this->post_at( 'sprout' );
		add_post_meta( $sprout, 'undergrowth_stage', 'evergreen' );
		$this->post_at( 'budding' );

		$posts = $this->list_posts( array( 'orderby' => 'undergrowth_stage' ) );

		$this->assertCount( 2, $posts );
		$this->assertSame( $sprout, $posts[0] );
	}

	public function test_sorting_and_filtering_work_together() {
		$older = $this->post_at( 'sprout' );
		$this->post_at( 'budding' );
		$newer = $this->post_at( 'sprout' );
		wp_update_post(
			array(
				'ID'        => $older,
				'post_date' => '2020-01-01 00:00:00',
			)
		);

		$_GET['undergrowth_stage'] = 'sprout';

		$this->assertSame( array( $newer, $older ), $this->list_posts( array( 'orderby' => 'undergrowth_stage' ) ) );
	}

	public function test_other_orders_are_left_alone() {
		$first  = $this->post_at( 'evergreen' );
		$second = $this->post_at( 'seedling' );

		$this->assertSame(
			array( $first, $second ),
			$this->list_posts(
				array(
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			)
		);
	}

	public function test_bulk_actions_offer_pin_and_unpin_before_compost() {
		$this->assertSame(
			array( 'trash', 'undergrowth_pin', 'undergrowth_unpin', 'undergrowth_compost', 'undergrowth_return' ),
			array_keys( undergrowth_compost_bulk_actions( array( 'trash' => 'Move to Trash' ) ) )
		);
		$this->assertSame( 'Pin stage', undergrowth_compost_bulk_actions( array() )['undergrowth_pin'] );
		$this->assertSame( 'Unpin stage', undergrowth_compost_bulk_actions( array() )['undergrowth_unpin'] );
	}

	public function test_bulk_pin_pins_each_post_at_its_current_stage() {
		$sprout    = $this->post_at( 'sprout' );
		$evergreen = $this->post_at( 'evergreen' );

		$location = undergrowth_handle_compost_bulk( admin_url( 'edit.php' ), 'undergrowth_pin', array( $sprout, $evergreen ) );

		$this->assertTrue( undergrowth_is_stage_pinned( $sprout ) );
		$this->assertSame( 'sprout', undergrowth_get_stage( $sprout ) );
		$this->assertTrue( undergrowth_is_stage_pinned( $evergreen ) );
		$this->assertSame( 'evergreen', undergrowth_get_stage( $evergreen ) );
		$this->assertStringContainsString( 'undergrowth_pinned=2', $location );
		$this->assertStringNotContainsString( 'undergrowth_skipped', $location );
	}

	public function test_bulk_pin_skips_posts_it_cannot_pin() {
		$sprout     = $this->post_at( 'sprout' );
		$pinned     = $this->post_at( 'budding' );
		$composting = $this->post_at( 'composting' );
		$draft      = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		undergrowth_pin_stage( $pinned, 'budding' );
		$log = count( undergrowth_stage_log( $pinned ) );

		$location = undergrowth_handle_compost_bulk( admin_url( 'edit.php' ), 'undergrowth_pin', array( $sprout, $pinned, $composting, $draft ) );

		$this->assertStringContainsString( 'undergrowth_pinned=1', $location );
		$this->assertStringContainsString( 'undergrowth_skipped=3', $location );
		$this->assertSame( 'composting', undergrowth_get_stage( $composting ) );
		$this->assertFalse( undergrowth_is_stage_pinned( $composting ) );
		$this->assertFalse( undergrowth_is_stage_pinned( $draft ) );
		// An already pinned post is left as it was, not pinned again.
		$this->assertCount( $log, undergrowth_stage_log( $pinned ) );
	}

	public function test_bulk_unpin_unpins_and_measures_each_pinned_post() {
		$pinned   = $this->post_at( 'seedling' );
		$unpinned = $this->post_at( 'sprout' );
		undergrowth_pin_stage( $pinned, 'evergreen' );

		$location = undergrowth_handle_compost_bulk( admin_url( 'edit.php' ), 'undergrowth_unpin', array( $pinned, $unpinned ) );

		$this->assertFalse( undergrowth_is_stage_pinned( $pinned ) );
		$this->assertSame( undergrowth_measure_stage( $pinned ), undergrowth_get_stage( $pinned ) );
		$this->assertSame( 'sprout', undergrowth_get_stage( $unpinned ) );
		$this->assertStringContainsString( 'undergrowth_unpinned=1', $location );
		$this->assertStringContainsString( 'undergrowth_skipped=1', $location );
	}

	public function test_bulk_actions_skip_posts_the_user_cannot_edit() {
		$theirs = $this->post_at( 'sprout' );
		$mine   = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ),
			)
		);
		wp_set_current_user( (int) get_post_field( 'post_author', $mine ) );

		$pin = undergrowth_handle_compost_bulk( admin_url( 'edit.php' ), 'undergrowth_pin', array( $theirs, $mine ) );

		$this->assertStringContainsString( 'undergrowth_pinned=1', $pin );
		$this->assertStringContainsString( 'undergrowth_skipped=1', $pin );
		$this->assertFalse( undergrowth_is_stage_pinned( $theirs ) );
		$this->assertTrue( undergrowth_is_stage_pinned( $mine ) );

		$compost = undergrowth_handle_compost_bulk( admin_url( 'edit.php' ), 'undergrowth_compost', array( $theirs, $mine ) );

		$this->assertStringContainsString( 'undergrowth_composted=1', $compost );
		$this->assertStringContainsString( 'undergrowth_skipped=1', $compost );
		$this->assertSame( 'sprout', undergrowth_get_stage( $theirs ) );
	}

	public function test_a_new_result_replaces_the_one_in_the_address() {
		$sprout = $this->post_at( 'sprout' );

		$location = undergrowth_handle_compost_bulk( admin_url( 'edit.php?undergrowth_stage=sprout&undergrowth_composted=4&undergrowth_skipped=2' ), 'undergrowth_pin', array( $sprout ) );

		$this->assertStringContainsString( 'undergrowth_stage=sprout', $location );
		$this->assertStringContainsString( 'undergrowth_pinned=1', $location );
		$this->assertStringNotContainsString( 'undergrowth_composted', $location );
		$this->assertStringNotContainsString( 'undergrowth_skipped', $location );
	}

	public function test_notices_count_pinned_and_unpinned_posts() {
		set_current_screen( 'edit-post' );
		$_GET['undergrowth_pinned']   = '3';
		$_GET['undergrowth_unpinned'] = '1';
		$_GET['undergrowth_skipped']  = '1';

		ob_start();
		undergrowth_render_compost_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '3 posts pinned at their stages.', $html );
		$this->assertStringContainsString( '1 post unpinned.', $html );
		$this->assertStringContainsString( '1 post was left as it was', $html );
		$this->assertDoesNotMatchRegularExpression( '/\b(days?|weeks?|months?|years?|since|ago|soon)\b/i', wp_strip_all_tags( $html ) );
	}

	public function test_pin_results_are_removed_from_the_address() {
		$args = wp_removable_query_args();

		$this->assertContains( 'undergrowth_pinned', $args );
		$this->assertContains( 'undergrowth_unpinned', $args );
	}
}
