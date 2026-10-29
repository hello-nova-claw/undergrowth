<?php
/**
 * Tests for the garden feed block.
 *
 * @package Undergrowth
 */

/**
 * Garden feed block: every post readers may see, newest tended first.
 */
class Test_Garden_Feed_Block extends WP_UnitTestCase {

	/**
	 * The request URI before the test, which page links are built from.
	 *
	 * @var string|null
	 */
	private $uri;

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
		$_GET      = array();
		$this->uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Put back as it was.
	}

	public function tear_down() {
		$_GET = array();

		if ( null === $this->uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->uri;
		}

		parent::tear_down();
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
				'blockName'    => 'undergrowth/garden-feed',
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		return $block->render();
	}

	/**
	 * Publishes a post last tended some days ago.
	 *
	 * @param string               $title   Post title.
	 * @param int                  $tended  Days since it was last modified.
	 * @param array<string, mixed> $args    Other post fields.
	 * @param int                  $planted Days since it was published.
	 * @return int Post ID.
	 */
	private function post( string $title, int $tended = 1, array $args = array(), int $planted = 400 ): int {
		global $wpdb;

		$post_id = self::factory()->post->create(
			array_merge(
				array(
					'post_title'  => $title,
					'post_status' => 'publish',
					'post_date'   => gmdate( 'Y-m-d H:i:s', time() - $planted * DAY_IN_SECONDS ),
				),
				$args
			)
		);

		// Core stamps the modified date on every save, so it is set after.
		$modified = gmdate( 'Y-m-d H:i:s', time() - $tended * DAY_IN_SECONDS );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $modified,
				'post_modified_gmt' => $modified,
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );

		return $post_id;
	}

	/**
	 * Reads the feed back as the titles it lists, in order.
	 *
	 * @param string $html Block markup.
	 * @return string[] Titles.
	 */
	private function titles( string $html ): array {
		preg_match_all( '#<a class="wp-block-undergrowth-garden-feed-title" href="[^"]*"><span class="wp-block-undergrowth-garden-feed-stage[^"]*">.*?</span><span>([^<]*)</span></a>#s', $html, $titles );

		return array_map( 'html_entity_decode', $titles[1] );
	}

	public function test_block_is_registered_from_block_json() {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'undergrowth/garden-feed' );

		$this->assertInstanceOf( WP_Block_Type::class, $type );
		$this->assertSame( 'Garden feed', $type->title );
		$this->assertSame( 'undergrowth_render_garden_feed_block', $type->render_callback );
		$this->assertSame( array( 'tended', 'newest', 'stage' ), $type->attributes['orderBy']['enum'] );
		$this->assertSame( 'post_format', $type->attributes['typeSource']['default'] );
		$this->assertTrue( $type->attributes['showImages']['default'] );
		$this->assertArrayNotHasKey( 'showFeatured', $type->attributes );
		$this->assertSame( array( 'undergrowth-garden-feed' ), $type->style_handles );
		$this->assertArrayHasKey( 'color', $type->supports );
		$this->assertArrayHasKey( 'typography', $type->supports );
		$this->assertArrayHasKey( 'spacing', $type->supports );
		$this->assertFalse( $type->supports['html'] );
	}

	public function test_registers_again_without_a_build() {
		WP_Block_Type_Registry::get_instance()->unregister( 'undergrowth/garden-feed' );

		undergrowth_register_garden_feed_block();

		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'undergrowth/garden-feed' ) );
	}

	public function test_lists_posts_newest_tended_first() {
		$this->post( 'Oca', 30 );
		$this->post( 'Yacon', 2 );
		$this->post( 'Mashua', 10 );

		$this->assertSame( array( 'Yacon', 'Mashua', 'Oca' ), $this->titles( $this->render() ) );
	}

	public function test_can_list_the_newest_planted_first() {
		$this->post( 'Oca', 30, array(), 3 );
		$this->post( 'Yacon', 2, array(), 300 );
		$this->post( 'Mashua', 10, array(), 30 );

		$this->assertSame( array( 'Oca', 'Mashua', 'Yacon' ), $this->titles( $this->render( array( 'orderBy' => 'newest' ) ) ) );
	}

	public function test_can_list_the_most_grown_first_then_newest_tended() {
		foreach ( array(
			array( 'Oca', 5, 'seedling' ),
			array( 'Yacon', 9, 'evergreen' ),
			array( 'Mashua', 1, 'sprout' ),
			array( 'Ulluco', 2, 'evergreen' ),
			array( 'Achira', 3, 'budding' ),
		) as list( $title, $tended, $stage ) ) {
			update_post_meta( $this->post( $title, $tended ), 'undergrowth_stage', $stage );
		}

		$this->assertSame( array( 'Ulluco', 'Yacon', 'Achira', 'Mashua', 'Oca' ), $this->titles( $this->render( array( 'orderBy' => 'stage' ) ) ) );
	}

	/**
	 * Leaves out a post readers may not see.
	 *
	 * @dataProvider data_hidden_posts
	 *
	 * @param array<string, string> $args  Post fields.
	 * @param string                $stage Stage stored for the post.
	 */
	public function test_leaves_out_a_post_readers_may_not_see( array $args, string $stage ) {
		$this->post( 'Oca' );
		update_post_meta( $this->post( 'Hidden', 1, $args ), 'undergrowth_stage', $stage );

		// Even a logged-in administrator sees what readers see.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( array( 'Oca' ), $this->titles( $this->render() ) );
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
			'not indexed'        => array( array( 'post_type' => 'page' ), 'sprout' ),
		);
	}

	public function test_shows_nothing_when_the_garden_is_empty() {
		$this->post( 'Draft', 1, array( 'post_status' => 'draft' ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_each_entry_shows_its_stage_icon_in_the_title_link_and_names_the_stage_after_it() {
		update_post_meta( $this->post( 'Oca' ), 'undergrowth_stage', 'budding' );

		$html = $this->render();

		// The glyph takes the link's colour and is left out of its name, so
		// the link is named by the title alone.
		$this->assertMatchesRegularExpression( '#<a class="wp-block-undergrowth-garden-feed-title" href="[^"]*"><span class="wp-block-undergrowth-garden-feed-stage is-stage-budding"><svg [^>]*aria-hidden="true" focusable="false">.*?</svg></span><span>Oca</span></a><span class="screen-reader-text">Growth stage: Budding</span>#s', $html );
		$this->assertStringNotContainsString( 'role="img"', $html );
	}

	public function test_each_entry_shows_when_it_was_last_tended() {
		$post_id = $this->post( 'Oca', 21 );

		$html = $this->render();

		$this->assertStringContainsString( '<time datetime="' . get_post_modified_time( 'c', true, $post_id ) . '">Tended 3 weeks ago</time>', $html );
	}

	public function test_shows_only_an_excerpt_the_author_wrote() {
		$this->post( 'Oca', 1, array( 'post_excerpt' => 'Small <b>tubers</b>.' ) );
		$this->post(
			'Yacon',
			2,
			array(
				'post_content' => 'A long body readers never see cut short here.',
				'post_excerpt' => '',
			)
		);

		$html = $this->render();

		$this->assertSame( 1, substr_count( $html, 'wp-block-undergrowth-garden-feed-excerpt' ) );
		$this->assertStringContainsString( '<p class="wp-block-undergrowth-garden-feed-excerpt">Small tubers.</p>', $html );
		$this->assertStringNotContainsString( 'cut short', $html );
		$this->assertStringNotContainsString( 'excerpt', $this->render( array( 'showExcerpt' => false ) ) );
	}

	public function test_shows_the_post_format_as_the_type_but_not_for_a_standard_post() {
		set_post_format( $this->post( 'Oca', 1 ), 'aside' );
		$this->post( 'Yacon', 2 );

		$html = $this->render();

		$this->assertSame( 1, substr_count( $html, 'wp-block-undergrowth-garden-feed-type' ) );
		$this->assertStringContainsString( '<span class="wp-block-undergrowth-garden-feed-type">Aside</span>', $html );
		$this->assertStringNotContainsString( 'feed-type', $this->render( array( 'showType' => false ) ) );
	}

	public function test_can_take_the_type_from_another_taxonomy() {
		$essay = self::factory()->category->create( array( 'name' => 'Essays & notes' ) );
		$this->post( 'Oca', 1, array( 'post_category' => array( $essay ) ) );

		$html = $this->render( array( 'typeSource' => 'category' ) );

		$this->assertStringContainsString( '<span class="wp-block-undergrowth-garden-feed-type">Essays &amp; notes</span>', $html );
	}

	public function test_an_unknown_type_taxonomy_falls_back_to_the_post_format() {
		set_post_format( $this->post( 'Oca' ), 'quote' );

		$this->assertStringContainsString( '>Quote</span>', $this->render( array( 'typeSource' => 'no_such_taxonomy' ) ) );
	}

	public function test_filters_by_stage_from_the_query_args() {
		update_post_meta( $this->post( 'Oca', 1 ), 'undergrowth_stage', 'sprout' );
		$this->post( 'Yacon', 2 );

		$_GET['stage'] = 'sprout';
		$this->assertSame( array( 'Oca' ), $this->titles( $this->render() ) );

		// A post with no stage stored is a Seedling.
		$_GET['stage'] = 'seedling';
		$this->assertSame( array( 'Yacon' ), $this->titles( $this->render() ) );

		// Composting is never a filter readers can use.
		$_GET['stage'] = 'composting';
		$this->assertSame( array( 'Oca', 'Yacon' ), $this->titles( $this->render() ) );
	}

	public function test_filters_by_post_format_from_the_query_args() {
		set_post_format( $this->post( 'Oca', 1 ), 'aside' );
		$this->post( 'Yacon', 2 );

		$_GET['type'] = 'aside';
		$this->assertSame( array( 'Oca' ), $this->titles( $this->render() ) );

		$_GET['type'] = 'standard';
		$this->assertSame( array( 'Yacon' ), $this->titles( $this->render() ) );
	}

	public function test_filters_by_another_type_taxonomy_from_the_query_args() {
		$essay = self::factory()->category->create( array( 'slug' => 'essay' ) );
		$this->post( 'Oca', 1, array( 'post_category' => array( $essay ) ) );
		$this->post( 'Yacon', 2 );

		$_GET['type'] = 'essay';

		$this->assertSame( array( 'Oca' ), $this->titles( $this->render( array( 'typeSource' => 'category' ) ) ) );
	}

	public function test_filters_by_a_tag_or_a_category_as_the_topic() {
		$tagged = $this->post( 'Oca', 1 );
		wp_set_post_tags( $tagged, array( 'roots' ) );
		$this->post( 'Yacon', 2, array( 'post_category' => array( self::factory()->category->create( array( 'slug' => 'roots' ) ) ) ) );
		$this->post( 'Mashua', 3 );

		$_GET['topic'] = 'roots';

		$this->assertSame( array( 'Oca', 'Yacon' ), $this->titles( $this->render() ) );
	}

	public function test_filters_by_a_topic_named_outside_latin_letters() {
		$post_id = $this->post( 'Oca', 1 );
		wp_set_post_tags( $post_id, array( 'שורשים' ) );
		$this->post( 'Yacon', 2 );

		// The browser sends the slug as UTF-8, and it is stored encoded.
		$_GET['topic'] = 'שורשים';

		$this->assertSame( array( 'Oca' ), $this->titles( $this->render() ) );
	}

	public function test_says_so_when_no_post_matches_the_filters() {
		$this->post( 'Oca' );

		$_GET['topic'] = 'nothing-here';

		$html = $this->render();

		$this->assertSame( array(), $this->titles( $html ) );
		$this->assertStringContainsString( 'Nothing in the garden matches.', $html );
	}

	/**
	 * Gives a post a featured image.
	 *
	 * @param int $post_id Post ID.
	 */
	private function picture( int $post_id ): void {
		set_post_thumbnail( $post_id, self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post_id ) );
	}

	public function test_shows_each_entrys_featured_image_above_its_title_on_every_page() {
		$this->picture( $this->post( 'Oca', 1 ) );
		$this->post( 'Yacon', 2 );
		$this->picture( $this->post( 'Mashua', 3 ) );

		$attrs = array( 'postsPerPage' => 2 );
		$html  = $this->render( $attrs );

		$this->assertSame( array( 'Oca', 'Yacon' ), $this->titles( $html ) );
		$this->assertSame( 1, substr_count( $html, 'has-image' ) );
		$this->assertSame( 1, substr_count( $html, '<img ' ) );
		// Decorative: the title names the entry, so the image says nothing more.
		$this->assertMatchesRegularExpression( '#<li class="wp-block-undergrowth-garden-feed-item has-image"><img [^>]*class="[^"]*wp-block-undergrowth-garden-feed-image[^"]*"[^>]*><a class="wp-block-undergrowth-garden-feed-title" href="[^"]*"><span class="wp-block-undergrowth-garden-feed-stage[^"]*">.*?</span><span>Oca</span></a>#s', $html );
		$this->assertMatchesRegularExpression( '#<img [^>]*alt=""#', $html );
		$this->assertStringNotContainsString( 'is-featured', $html );

		$_GET['garden-page'] = '2';
		$html                = $this->render( $attrs );

		$this->assertSame( array( 'Mashua' ), $this->titles( $html ) );
		$this->assertSame( 1, substr_count( $html, '<img ' ) );
	}

	public function test_can_leave_the_images_out() {
		$this->picture( $this->post( 'Oca' ) );

		$html = $this->render( array( 'showImages' => false ) );

		$this->assertSame( array( 'Oca' ), $this->titles( $html ) );
		$this->assertStringNotContainsString( '<img ', $html );
		$this->assertStringNotContainsString( 'has-image', $html );
	}

	public function test_reads_the_old_show_featured_setting_as_show_images() {
		$this->picture( $this->post( 'Oca' ) );

		// A block saved before the setting was renamed keeps working.
		$this->assertStringContainsString( '<img ', $this->render( array( 'showFeatured' => true ) ) );
		$this->assertStringNotContainsString( '<img ', $this->render( array( 'showFeatured' => false ) ) );
	}

	public function test_pages_through_the_garden_keeping_the_filters() {
		foreach ( array( 'Oca', 'Yacon', 'Mashua' ) as $days => $title ) {
			update_post_meta( $this->post( $title, $days + 1 ), 'undergrowth_stage', 'sprout' );
		}
		$this->post( 'Ulluco', 9 );

		$_SERVER['REQUEST_URI'] = '/garden/?stage=sprout';
		$_GET['stage']          = 'sprout';

		$html = $this->render( array( 'postsPerPage' => 2 ) );

		$this->assertSame( array( 'Oca', 'Yacon' ), $this->titles( $html ) );
		$this->assertStringContainsString( '<nav class="wp-block-undergrowth-garden-feed-pages" aria-label="Garden pages">', $html );
		$this->assertStringContainsString( 'href="/garden/?stage=sprout&#038;garden-page=2"', $html );

		$_GET['garden-page'] = '2';
		$this->assertSame( array( 'Mashua' ), $this->titles( $this->render( array( 'postsPerPage' => 2 ) ) ) );

		// A page past the end shows the last one.
		$_GET['garden-page'] = '9';
		$this->assertSame( array( 'Mashua' ), $this->titles( $this->render( array( 'postsPerPage' => 2 ) ) ) );
	}

	public function test_loads_only_the_posts_on_the_page_it_shows() {
		$post_ids = array();

		foreach ( array( 'Oca', 'Yacon', 'Mashua', 'Ulluco', 'Achira' ) as $days => $title ) {
			$post_ids[] = $this->post( $title, $days + 1 );
		}

		wp_cache_flush();
		$before = get_num_queries();
		$html   = $this->render( array( 'postsPerPage' => 2 ) );

		$this->assertSame( array( 'Oca', 'Yacon' ), $this->titles( $html ) );
		$this->assertSame(
			array_slice( $post_ids, 0, 2 ),
			array_values(
				array_filter(
					$post_ids,
					static function ( int $post_id ): bool {
						return false !== wp_cache_get( $post_id, 'posts' );
					}
				)
			)
		);
		$this->assertLessThanOrEqual( 15, get_num_queries() - $before );
	}

	/**
	 * Stores one Map group for the garden index.
	 *
	 * @param int[] $members Post IDs, the leader first.
	 */
	private function store_group( array $members ): void {
		update_option(
			'undergrowth_garden_index',
			array(
				'groups' => array(
					array(
						'leader'  => $members[0],
						'members' => $members,
					),
				),
			),
			false
		);
	}

	public function test_filters_to_one_group_from_the_query_args_in_the_feeds_order() {
		$oca    = $this->post( 'Oca', 5 );
		$ulluco = $this->post( 'Ulluco', 1 );
		$yacon  = $this->post( 'Yacon', 2 );
		$this->post( 'Mashua', 3 );
		$this->store_group( array( $oca, $yacon, $ulluco ) );

		$_GET['group'] = (string) $oca;

		$html = $this->render();

		$this->assertSame( array( 'Ulluco', 'Yacon', 'Oca' ), $this->titles( $html ) );
		$this->assertStringContainsString( 'In the group around “<bdi>Oca</bdi>”', $html );
	}

	public function test_filters_to_the_posts_in_no_group() {
		$oca = $this->post( 'Oca', 5 );
		$this->post( 'Mashua', 3 );
		$this->post( 'Achira', 4 );
		$this->store_group( array( $oca, $this->post( 'Yacon', 2 ) ) );

		$_GET['group'] = 'other';

		$this->assertSame( array( 'Mashua', 'Achira' ), $this->titles( $this->render() ) );
	}

	public function test_an_unknown_group_matches_nothing() {
		$this->post( 'Oca' );

		$_GET['group'] = '999999';

		$this->assertStringContainsString( 'Nothing in the garden matches.', $this->render() );
	}

	public function test_one_page_needs_no_page_links() {
		$this->post( 'Oca' );

		$this->assertStringNotContainsString( '<nav', $this->render() );
	}

	public function test_escapes_the_authors_words() {
		$this->post( 'Oca <script>', 1, array( 'post_excerpt' => '<img src=x onerror=alert(1)>Tubers' ) );

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
	}

	public function test_tells_the_editor_which_post_types_are_indexed() {
		add_filter(
			'undergrowth_post_types',
			static function () {
				return array( 'post', 'page' );
			}
		);

		$settings = get_block_editor_settings( array(), new WP_Block_Editor_Context() );

		$this->assertSame( array( 'post', 'page' ), $settings['undergrowthIndexedPostTypes'] );
	}

	public function test_every_stage_has_its_own_icon_with_an_accessible_name() {
		$icons = array();

		foreach ( array( 'seedling', 'sprout', 'budding', 'evergreen' ) as $stage ) {
			$icon = undergrowth_stage_icon( $stage, undergrowth_stage_label( $stage ) );

			$this->assertStringStartsWith( '<svg ', $icon );
			$this->assertStringContainsString( 'role="img" aria-label="' . undergrowth_stage_label( $stage ) . '"', $icon );
			$this->assertStringContainsString( 'stroke="currentColor"', $icon );
			$icons[] = preg_replace( '#aria-label="[^"]*"#', '', $icon );
		}

		$this->assertCount( 4, array_unique( $icons ) );
	}

	public function test_an_icon_without_a_name_is_hidden_from_screen_readers() {
		$icon = undergrowth_stage_icon( 'sprout' );

		$this->assertStringContainsString( 'aria-hidden="true" focusable="false"', $icon );
		$this->assertStringNotContainsString( 'role="img"', $icon );
	}

	public function test_composting_has_no_icon() {
		$this->assertSame( '', undergrowth_stage_icon( 'composting', 'Composting' ) );
	}
}
