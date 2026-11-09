<?php
/**
 * Tests for the garden index block.
 *
 * @package Undergrowth
 */

/**
 * Garden index block: the author's posts by Map group, each with its stage.
 */
class Test_Garden_Index_Block extends WP_UnitTestCase {

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		undergrowth_register_stage_meta();
		delete_option( 'undergrowth_garden_index' );
	}

	/**
	 * Renders the block.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 * @return string Markup.
	 */
	private function render( array $attrs = array() ): string {
		$block = new WP_Block(
			array(
				'blockName'    => 'undergrowth/garden-index',
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		return $block->render();
	}

	/**
	 * Publishes a post, stores its vector and puts it in a cluster.
	 *
	 * @param string               $title  Post title.
	 * @param float[]              $vector Vector to store.
	 * @param int                  $leader Leader of the cluster, 0 for the post to lead, or -1 for no cluster.
	 * @param array<string, mixed> $args   Other post fields.
	 * @return int Post ID.
	 */
	private function member( string $title, array $vector, int $leader = 0, array $args = array() ): int {
		$post_id = $this->seed( $title, $vector, $args );

		if ( $leader >= 0 ) {
			update_post_meta( $post_id, 'undergrowth_cluster', 0 === $leader ? $post_id : $leader );
		}

		return $post_id;
	}

	/**
	 * Makes two clusters, around Oca and around Yacon.
	 *
	 * @return array<string, int> Post IDs keyed by title.
	 */
	private function plant_clusters(): array {
		$posts                 = array();
		$posts['Oca']          = $this->member( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$posts['Oca leaves']   = $this->member( 'Oca leaves', array( 0.99, 0.1, 0.0 ), $posts['Oca'] );
		$posts['Oca tubers']   = $this->member( 'Oca tubers', array( 0.99, 0.0, 0.1 ), $posts['Oca'] );
		$posts['Yacon']        = $this->member( 'Yacon', array( 0.0, 0.0, 1.0 ) );
		$posts['Yacon syrup']  = $this->member( 'Yacon syrup', array( 0.1, 0.0, 0.99 ), $posts['Yacon'] );
		$posts['Yacon leaves'] = $this->member( 'Yacon leaves', array( 0.0, 0.1, 0.99 ), $posts['Yacon'] );

		return $posts;
	}

	/**
	 * Reads the index back as group names and the titles under each.
	 *
	 * @param string $html Block markup.
	 * @return array<string, string[]> Titles keyed by group heading, in order.
	 */
	private function groups( string $html ): array {
		preg_match_all( '#<h2 class="wp-block-heading">([^<]*)</h2><ul class="wp-block-undergrowth-garden-index-list">(.*?)</ul>#s', $html, $sections, PREG_SET_ORDER );
		$groups = array();

		foreach ( $sections as $section ) {
			preg_match_all( '#<a href="[^"]*">(?:<span class="wp-block-undergrowth-garden-index-icon[^"]*">.*?</span>)?([^<]*)</a>#s', $section[2], $titles );
			$groups[ html_entity_decode( $section[1] ) ] = $titles[1];
		}

		return $groups;
	}

	public function test_block_is_registered_from_block_json() {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'undergrowth/garden-index' );

		$this->assertInstanceOf( WP_Block_Type::class, $type );
		$this->assertSame( 'Garden index', $type->title );
		$this->assertSame( 'undergrowth_render_garden_index_block', $type->render_callback );
		$this->assertSame( array( '', 'seedling', 'sprout', 'budding', 'evergreen' ), $type->attributes['stage']['enum'] );
		$this->assertSame( array( 'undergrowth-garden-index' ), $type->style_handles );
		$this->assertArrayHasKey( 'typography', $type->supports );
		$this->assertArrayHasKey( 'spacing', $type->supports );
		$this->assertFalse( $type->supports['html'] );
	}

	public function test_registers_again_without_a_build() {
		WP_Block_Type_Registry::get_instance()->unregister( 'undergrowth/garden-index' );

		undergrowth_register_garden_index_block();

		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'undergrowth/garden-index' ) );
	}

	public function test_the_lists_keep_the_blocks_edge_under_a_classic_themes_list_rule() {
		$css = (string) file_get_contents( dirname( UNDERGROWTH_FILE ) . '/assets/garden-index.css' );

		// A classic theme indents every list in the content with a rule
		// such as `.entry-content ul`, a class and an element printed after
		// the block's styles. Each group's list is reset by a rule naming
		// the block's class, the element and the list's class, and each
		// entry by one naming the block's class and the list's class, so
		// both outrank that rule.
		$this->assertMatchesRegularExpression( '#^\.wp-block-undergrowth-garden-index ul\.wp-block-undergrowth-garden-index-list \{[^}]*\bmargin: 0;[^}]*\bpadding: 0;[^}]*\blist-style: none;#ms', $css );
		$this->assertMatchesRegularExpression( '#^\.wp-block-undergrowth-garden-index \.wp-block-undergrowth-garden-index-list > li \{[^}]*\bmargin: 0;[^}]*\bpadding: 0\.2em 0;[^}]*\blist-style: none;#ms', $css );
		$this->assertDoesNotMatchRegularExpression( '#^\.wp-block-undergrowth-garden-index (ul|li) \{#m', $css );
		// The Specimen style's list rule names the list class too, so it
		// still applies over the reset.
		$this->assertMatchesRegularExpression( '#^\.is-style-specimen \.wp-block-undergrowth-garden-index-list \{#m', $css );
	}

	public function test_lists_the_maps_groups_in_map_order_named_as_on_the_map() {
		$this->plant_clusters();
		undergrowth_build_garden_index();

		$expected = array();

		foreach ( undergrowth_cluster_map() as $cluster ) {
			$titles                 = array_map( 'get_the_title', $cluster['members'] );
			$expected[ $titles[0] ] = $titles;
		}

		$this->assertSame( $expected, $this->groups( $this->render() ) );
	}

	public function test_posts_in_no_group_come_last() {
		$this->plant_clusters();
		$this->member( 'Mashua', array( 0.0, 1.0, 0.0 ), -1 );
		undergrowth_build_garden_index();

		$groups = $this->groups( $this->render() );

		$this->assertSame( 'Other posts', array_key_last( $groups ) );
		$this->assertSame( array( 'Mashua' ), end( $groups ) );
	}

	public function test_a_post_published_since_the_cycle_is_listed_with_the_other_posts() {
		$this->plant_clusters();
		undergrowth_build_garden_index();
		$this->member( 'Ulluco', array( 0.0, 1.0, 0.0 ), -1 );

		$this->assertSame( array( 'Ulluco' ), $this->groups( $this->render() )['Other posts'] );
	}

	public function test_each_post_shows_its_stage_with_a_label() {
		$posts = $this->plant_clusters();
		update_post_meta( $posts['Oca leaves'], 'undergrowth_stage', 'budding' );
		undergrowth_build_garden_index();

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#>Oca leaves</a> <span class="wp-block-undergrowth-garden-index-stage is-stage-budding"><span class="screen-reader-text">Growth stage: Budding</span><span aria-hidden="true">Budding</span></span>#', $html );
		$this->assertStringContainsString( '<span aria-hidden="true">Seedling</span>', $html );
	}

	public function test_each_post_shows_its_stage_icon_in_the_title_link() {
		$posts = $this->plant_clusters();
		update_post_meta( $posts['Oca leaves'], 'undergrowth_stage', 'budding' );
		undergrowth_build_garden_index();

		$html = $this->render();

		// The glyph takes the link's colour and is left out of its name, so
		// the link is named by the title alone.
		$this->assertMatchesRegularExpression( '#<a href="[^"]*"><span class="wp-block-undergrowth-garden-index-icon is-stage-budding"><svg [^>]*aria-hidden="true" focusable="false">.*?</svg></span>Oca leaves</a>#s', $html );
		$this->assertStringNotContainsString( 'role="img"', $html );
	}

	/**
	 * Leaves out a post readers may not see, from its group and from the rest.
	 *
	 * @dataProvider data_hidden_posts
	 *
	 * @param array<string, string> $args  Post fields.
	 * @param string                $stage Stage stored for the post.
	 */
	public function test_leaves_out_a_post_readers_may_not_see( array $args, string $stage ) {
		$posts  = $this->plant_clusters();
		$hidden = $this->member( 'Hidden', array( 0.99, 0.05, 0.05 ), $posts['Oca'] );
		$apart  = $this->member( 'Hidden apart', array( 0.0, 1.0, 0.0 ), -1 );
		undergrowth_build_garden_index();

		foreach ( array( $hidden, $apart ) as $post_id ) {
			wp_update_post( array_merge( array( 'ID' => $post_id ), $args ) );
			update_post_meta( $post_id, 'undergrowth_stage', $stage );
		}

		// Even a logged-in administrator sees what readers see.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->render();

		$this->assertStringNotContainsString( 'Hidden', $html );
		$this->assertStringContainsString( '>Oca leaves</a>', $html );
	}

	/**
	 * Posts readers may not see in the garden.
	 *
	 * @return array<string, array{0: array<string, string>, 1: string}>
	 */
	public function data_hidden_posts(): array {
		return array(
			'draft'              => array( array( 'post_status' => 'draft' ), 'sprout' ),
			'private'            => array( array( 'post_status' => 'private' ), 'sprout' ),
			'password-protected' => array( array( 'post_password' => 'oca' ), 'sprout' ),
			'composted'          => array( array(), 'composting' ),
		);
	}

	public function test_a_group_is_named_after_its_first_post_readers_may_see() {
		$posts = $this->plant_clusters();
		undergrowth_build_garden_index();
		$first = undergrowth_cluster_map()[0]['members'];
		wp_update_post(
			array(
				'ID'            => $first[0],
				'post_password' => 'oca',
			)
		);

		$groups = $this->groups( $this->render() );

		$this->assertArrayNotHasKey( get_the_title( $first[0] ), $groups );
		$this->assertArrayHasKey( get_the_title( $first[1] ), $groups );
		$this->assertNotEmpty( $posts );
	}

	public function test_a_group_with_no_post_readers_may_see_is_left_out() {
		$posts = $this->plant_clusters();
		undergrowth_build_garden_index();

		foreach ( array( 'Yacon', 'Yacon syrup', 'Yacon leaves' ) as $title ) {
			update_post_meta( $posts[ $title ], 'undergrowth_stage', 'composting' );
		}

		$html = $this->render();

		$this->assertStringNotContainsString( 'Yacon', $html );
		$this->assertCount( 1, $this->groups( $html ) );
	}

	public function test_filters_by_stage_and_drops_groups_left_empty() {
		$posts = $this->plant_clusters();
		update_post_meta( $posts['Yacon syrup'], 'undergrowth_stage', 'sprout' );
		$apart = $this->member( 'Mashua', array( 0.0, 1.0, 0.0 ), -1 );
		update_post_meta( $apart, 'undergrowth_stage', 'sprout' );
		undergrowth_build_garden_index();

		$groups = $this->groups( $this->render( array( 'stage' => 'sprout' ) ) );

		// The group keeps the name it has on the Map.
		$this->assertSame( array( 'Yacon syrup' ), $groups['Yacon'] );
		$this->assertSame( array( 'Mashua' ), $groups['Other posts'] );
		$this->assertCount( 2, $groups );
	}

	public function test_shows_nothing_when_no_post_is_visible() {
		$this->assertSame( '', $this->render() );
	}

	public function test_lists_every_post_as_other_posts_before_the_first_cycle() {
		$this->member( 'Oca', array( 1.0, 0.0, 0.0 ), -1 );
		$this->member( 'Yacon', array( 0.0, 1.0, 0.0 ), -1 );

		$this->assertSame( array( 'Other posts' => array( 'Yacon', 'Oca' ) ), $this->groups( $this->render() ) );
	}

	public function test_other_posts_are_newest_first_by_date_not_by_id() {
		$this->member( 'Old import', array( 1.0, 0.0, 0.0 ), -1, array( 'post_date' => '2001-05-01 00:00:00' ) );
		$this->member( 'Recent', array( 0.0, 1.0, 0.0 ), -1, array( 'post_date' => '2026-05-01 00:00:00' ) );
		$this->member( 'Redated', array( 0.0, 0.0, 1.0 ), -1, array( 'post_date' => '2010-05-01 00:00:00' ) );

		$this->assertSame( array( 'Other posts' => array( 'Recent', 'Redated', 'Old import' ) ), $this->groups( $this->render() ) );
	}

	public function test_reads_groups_from_the_cache_not_the_vectors() {
		$posts = $this->plant_clusters();
		undergrowth_build_garden_index();
		$before = $this->groups( $this->render() );

		// A cluster key changed outside the cycle is not read until the next one.
		delete_post_meta( $posts['Oca leaves'], 'undergrowth_cluster' );

		$this->assertSame( $before, $this->groups( $this->render() ) );
	}

	public function test_the_cluster_cycle_rebuilds_the_cache() {
		$posts = $this->plant_clusters();
		update_option( 'undergrowth_garden_index', array( 'groups' => array() ), false );

		undergrowth_run_cluster_cycle();

		$cached = get_option( 'undergrowth_garden_index' );
		$this->assertNotEmpty( $cached['groups'] );
		$this->assertContains( $posts['Oca leaves'], array_merge( ...array_column( $cached['groups'], 'members' ) ) );
	}

	public function test_titles_are_escaped() {
		$this->member( 'Roots & <b>tubers</b>', array( 1.0, 0.0, 0.0 ), -1 );

		$html = $this->render();

		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( 'tubers</a>', $html );
	}

	public function test_block_supports_reach_the_wrapper() {
		$this->member( 'Oca', array( 1.0, 0.0, 0.0 ), -1 );

		$html = $this->render( array( 'fontSize' => 'small' ) );

		$this->assertStringStartsWith( '<div class="wp-block-undergrowth-garden-index has-small-font-size"', $html );
		$this->assertStringNotContainsString( 'style=', $html );
	}

	/**
	 * Makes a group of a given size around Oca, its members stored nearest
	 * the centre first, as the cluster cycle stores them.
	 *
	 * @param int $size Posts in the group.
	 * @return int[] Post IDs, nearest the centre first.
	 */
	private function large_group( int $size ): array {
		$leader  = $this->member( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$members = array( $leader );

		for ( $i = 1; $i < $size; $i++ ) {
			$members[] = $this->member( "Oca {$i}", array( 1.0, 0.01 * $i, 0.0 ), $leader );
		}

		update_option(
			'undergrowth_garden_index',
			array(
				'groups' => array(
					array(
						'leader'  => $leader,
						'members' => $members,
					),
				),
			),
			false
		);

		return $members;
	}

	/**
	 * Publishes a page holding the Garden feed.
	 *
	 * @return int Page ID.
	 */
	private function feed_page(): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Garden',
				'post_content' => '<!-- wp:undergrowth/garden-feed /-->',
			)
		);
	}

	public function test_a_large_group_shows_the_twenty_nearest_its_centre_and_links_to_the_rest() {
		$members = $this->large_group( 23 );
		$page    = $this->feed_page();

		$html  = $this->render();
		$shown = $this->groups( $html )['Oca'];

		$this->assertSame( array_merge( array( 'Oca' ), array_map( static fn( $i ) => "Oca {$i}", range( 1, 19 ) ) ), $shown );
		$this->assertStringContainsString( 'href="' . esc_url( add_query_arg( 'group', $members[0], get_permalink( $page ) ) ) . '"', $html );
		$this->assertStringContainsString( 'See all 23 posts in this group', $html );
	}

	public function test_a_group_of_twenty_needs_no_link() {
		$this->large_group( 20 );
		$this->feed_page();

		$html = $this->render();

		$this->assertCount( 20, $this->groups( $html )['Oca'] );
		$this->assertStringNotContainsString( 'See all', $html );
	}

	public function test_without_a_feed_page_the_rest_are_counted_without_a_link() {
		$this->large_group( 23 );

		$html = $this->render();

		$this->assertCount( 20, $this->groups( $html )['Oca'] );
		$this->assertStringContainsString( 'And 3 more posts in this group.', $html );
		$this->assertStringNotContainsString( 'group=', $html );
	}

	public function test_other_posts_show_the_twenty_newest_and_link_to_the_rest() {
		for ( $i = 1; $i <= 22; $i++ ) {
			$this->member( "Loose {$i}", array( 0.0, 1.0, 0.0 ), -1, array( 'post_date' => sprintf( '2026-01-%02d 00:00:00', $i ) ) );
		}
		$page = $this->feed_page();

		$html = $this->render();

		$this->assertSame( array_map( static fn( $i ) => "Loose {$i}", range( 22, 3 ) ), $this->groups( $html )['Other posts'] );
		$this->assertStringContainsString( 'href="' . esc_url( add_query_arg( 'group', 'other', get_permalink( $page ) ) ) . '"', $html );
	}

	public function test_a_stage_filter_counts_and_caps_only_that_stage() {
		$members = $this->large_group( 25 );

		foreach ( array_slice( $members, 0, 22 ) as $post_id ) {
			update_post_meta( $post_id, 'undergrowth_stage', 'sprout' );
		}

		$html = $this->render( array( 'stage' => 'sprout' ) );

		$this->assertCount( 20, $this->groups( $html )['Oca'] );
		$this->assertStringContainsString( 'And 2 more posts in this group.', $html );
	}

	public function test_a_stage_filter_carries_into_the_see_all_link() {
		$members = $this->large_group( 25 );
		$page    = $this->feed_page();

		foreach ( array_slice( $members, 0, 22 ) as $post_id ) {
			update_post_meta( $post_id, 'undergrowth_stage', 'sprout' );
		}

		$html = $this->render( array( 'stage' => 'sprout' ) );

		$this->assertStringContainsString(
			'href="' . esc_url(
				add_query_arg(
					array(
						'group' => $members[0],
						'stage' => 'sprout',
					),
					get_permalink( $page )
				)
			) . '"',
			$html
		);
		$this->assertStringContainsString( 'See all 22 posts in this group', $html );
	}

	public function test_renders_without_loading_the_posts_it_lists() {
		$members = $this->large_group( 23 );
		wp_cache_flush();

		$this->render();

		foreach ( $members as $post_id ) {
			$this->assertFalse( wp_cache_get( $post_id, 'posts' ) );
		}
	}

	public function test_a_larger_group_takes_no_more_queries() {
		global $wpdb;

		// The feed's page is looked up once and remembered, so both renders
		// below read the same option.
		undergrowth_garden_feed_url();
		$this->large_group( 25 );
		wp_cache_flush();
		$before = $wpdb->num_queries;
		$this->render();
		$small = $wpdb->num_queries - $before;

		foreach ( get_option( 'undergrowth_garden_index' )['groups'][0]['members'] as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		$this->large_group( 30 );
		wp_cache_flush();
		$before = $wpdb->num_queries;
		$this->render();

		$this->assertLessThanOrEqual( $small, $wpdb->num_queries - $before );
	}

	public function test_the_link_follows_the_feed_to_another_page() {
		$this->large_group( 23 );
		$first = $this->feed_page();
		$this->assertStringContainsString( esc_url( add_query_arg( 'group', '', get_permalink( $first ) ) ), $this->render() );

		wp_trash_post( $first );
		$second = $this->feed_page();

		$this->assertStringContainsString( esc_url( get_permalink( $second ) ), $this->render() );

		wp_delete_post( $second, true );

		$this->assertStringContainsString( 'And 3 more posts in this group.', $this->render() );
	}
}
