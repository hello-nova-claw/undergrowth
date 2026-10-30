<?php
/**
 * Tests for the garden filters block.
 *
 * @package Undergrowth
 */

/**
 * Garden filters block: topic, stage and type, as links and a GET form.
 */
class Test_Garden_Filters_Block extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
		$_GET                   = array();
		$_SERVER['REQUEST_URI'] = '/garden/';
	}

	public function tear_down() {
		$_GET = array();
		unset( $GLOBALS['post'] );

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
				'blockName'    => 'undergrowth/garden-filters',
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		return $block->render();
	}

	/**
	 * Publishes a post.
	 *
	 * @param string               $title Post title.
	 * @param array<string, mixed> $args  Other post fields.
	 * @return int Post ID.
	 */
	private function post( string $title, array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_title'  => $title,
					'post_status' => 'publish',
				),
				$args
			)
		);
	}

	/**
	 * Reads a select's options back as value => label.
	 *
	 * @param string $html Block markup.
	 * @param string $name The select's name.
	 * @return array<string, string> Labels keyed by value.
	 */
	private function options( string $html, string $name ): array {
		if ( ! preg_match( '#<select [^>]*name="' . $name . '"[^>]*>(.*?)</select>#s', $html, $select ) ) {
			return array();
		}

		preg_match_all( '#<option value="([^"]*)"[^>]*>([^<]*)</option>#', $select[1], $options );

		return array_combine( $options[1], array_map( 'html_entity_decode', $options[2] ) );
	}

	/**
	 * Reads the topic links back as label => href.
	 *
	 * @param string $html Block markup.
	 * @return array<string, string> Links keyed by label.
	 */
	private function topics( string $html ): array {
		preg_match_all( '#<a href="([^"]*)"[^>]*>([^<]*)</a>#', $html, $links );

		return array_combine( array_map( 'html_entity_decode', $links[2] ), array_map( 'html_entity_decode', $links[1] ) );
	}

	public function test_block_is_registered_from_block_json() {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'undergrowth/garden-filters' );

		$this->assertInstanceOf( WP_Block_Type::class, $type );
		$this->assertSame( 'Garden filters', $type->title );
		$this->assertSame( 'undergrowth_render_garden_filters_block', $type->render_callback );
		$this->assertSame( array( 'post_tag', 'category' ), $type->attributes['topicSource']['enum'] );
		$this->assertSame( array( 'undergrowth-garden-filters' ), $type->style_handles );
		$this->assertFalse( $type->attributes['showCount']['default'] );
		$this->assertTrue( $type->supports['interactivity'] );
		$this->assertFalse( $type->supports['html'] );
	}

	public function test_registers_again_without_a_build() {
		WP_Block_Type_Registry::get_instance()->unregister( 'undergrowth/garden-filters' );

		undergrowth_register_garden_filters_block();

		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'undergrowth/garden-filters' ) );
	}

	public function test_the_topic_list_keeps_the_blocks_edge_under_a_classic_themes_list_rule() {
		$css = (string) file_get_contents( dirname( UNDERGROWTH_FILE ) . '/assets/garden-filters.css' );

		// A classic theme indents every list in the content with a rule
		// such as `.entry-content ul`, a class and an element printed after
		// the block's styles. The topic list's rule names the block's class
		// as well, in both writing directions, so it outranks that rule.
		$this->assertMatchesRegularExpression( '#^\.wp-block-undergrowth-garden-filters \.wp-block-undergrowth-garden-filters-topics ul \{[^}]*\bmargin: -4px 0 -4px -4px;[^}]*\bpadding: 4px 3em 4px 4px;#ms', $css );
		$this->assertMatchesRegularExpression( '#^\[dir="rtl"\] \.wp-block-undergrowth-garden-filters \.wp-block-undergrowth-garden-filters-topics ul \{[^}]*\bmargin: -4px -4px -4px 0;[^}]*\bpadding: 4px 4px 4px 3em;#ms', $css );
		$this->assertDoesNotMatchRegularExpression( '#^(\[dir="rtl"\] )?\.wp-block-undergrowth-garden-filters-topics ul \{#m', $css );
	}

	public function test_shows_nothing_when_the_garden_is_empty() {
		$this->post( 'Draft', array( 'post_status' => 'draft' ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_lists_its_choices_without_loading_the_posts() {
		$post_ids = array( $this->post( 'Oca' ), $this->post( 'Yacon' ) );
		update_post_meta( $post_ids[0], 'undergrowth_stage', 'sprout' );
		wp_cache_flush();

		$this->assertStringContainsString( 'Sprout', $this->render() );
		$this->assertFalse( wp_cache_get( $post_ids[0], 'posts' ) );
		$this->assertFalse( wp_cache_get( $post_ids[1], 'posts' ) );
	}

	public function test_lists_only_the_stages_the_visible_garden_holds() {
		update_post_meta( $this->post( 'Oca' ), 'undergrowth_stage', 'sprout' );
		$this->post( 'Yacon' );
		update_post_meta( $this->post( 'Hidden', array( 'post_status' => 'private' ) ), 'undergrowth_stage', 'evergreen' );
		update_post_meta( $this->post( 'Resting' ), 'undergrowth_stage', 'composting' );

		$this->assertSame(
			array(
				''         => 'All stages',
				'seedling' => 'Seedling',
				'sprout'   => 'Sprout',
			),
			$this->options( $this->render(), 'stage' )
		);
	}

	public function test_lists_only_the_post_formats_the_visible_garden_holds() {
		set_post_format( $this->post( 'Oca' ), 'aside' );
		$this->post( 'Yacon' );
		set_post_format( $this->post( 'Hidden', array( 'post_status' => 'draft' ) ), 'quote' );

		$this->assertSame(
			array(
				''         => 'All types',
				'aside'    => 'Aside',
				'standard' => 'Standard',
			),
			$this->options( $this->render(), 'type' )
		);
	}

	public function test_leaves_out_the_type_choice_when_every_post_is_standard() {
		$this->post( 'Oca' );

		$this->assertSame( array(), $this->options( $this->render(), 'type' ) );
	}

	/**
	 * Puts the filters on a page beside a garden feed, as the post being shown.
	 *
	 * @param string $feed The feed's block markup.
	 */
	private function on_page_with( string $feed ): void {
		$GLOBALS['post'] = get_post(
			self::factory()->post->create(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:undergrowth/garden-filters /-->' . $feed . '</div><!-- /wp:group -->',
				)
			)
		);
	}

	public function test_takes_the_types_from_the_feed_on_the_same_page() {
		$essay = self::factory()->category->create(
			array(
				'name' => 'Essay',
				'slug' => 'essay',
			)
		);
		set_post_format( $this->post( 'Oca', array( 'post_category' => array( $essay ) ) ), 'aside' );
		$this->post( 'Yacon' );

		$this->on_page_with( '<!-- wp:undergrowth/garden-feed {"typeSource":"category"} /-->' );

		$this->assertSame( array( 'essay' => 'Essay' ), array_intersect_key( $this->options( $this->render(), 'type' ), array( 'essay' => true ) ) );
		$this->assertArrayNotHasKey( 'aside', $this->options( $this->render(), 'type' ) );
	}

	public function test_takes_the_types_from_a_feed_inside_a_pattern() {
		register_block_pattern(
			'undergrowth-test/feed',
			array(
				'title'   => 'Feed',
				'content' => '<!-- wp:undergrowth/garden-feed {"typeSource":"category"} /-->',
			)
		);
		$essay = self::factory()->category->create( array( 'slug' => 'essay' ) );
		$this->post( 'Oca', array( 'post_category' => array( $essay ) ) );

		$this->on_page_with( '<!-- wp:pattern {"slug":"undergrowth-test/feed"} /-->' );
		$html = $this->render();
		unregister_block_pattern( 'undergrowth-test/feed' );

		$this->assertArrayHasKey( 'essay', $this->options( $html, 'type' ) );
	}

	public function test_takes_the_types_from_a_feed_inside_a_synced_pattern() {
		$synced = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:undergrowth/garden-feed {"typeSource":"category"} /-->',
			)
		);
		$essay  = self::factory()->category->create( array( 'slug' => 'essay' ) );
		$this->post( 'Oca', array( 'post_category' => array( $essay ) ) );

		$this->on_page_with( '<!-- wp:block {"ref":' . $synced . '} /-->' );

		$this->assertArrayHasKey( 'essay', $this->options( $this->render(), 'type' ) );
	}

	/**
	 * Skips a synced pattern WordPress would not render.
	 *
	 * @dataProvider data_unrendered_synced_patterns
	 *
	 * @param array<string, string> $args Fields of the synced pattern.
	 */
	public function test_skips_a_synced_pattern_wordpress_would_not_render( array $args ) {
		$hidden = self::factory()->post->create(
			$args + array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:undergrowth/garden-feed {"typeSource":"category"} /-->',
			)
		);
		set_post_format( $this->post( 'Oca' ), 'aside' );
		$this->post( 'Yacon' );

		$this->on_page_with( '<!-- wp:block {"ref":' . $hidden . '} /--><!-- wp:undergrowth/garden-feed /-->' );

		$this->assertArrayHasKey( 'aside', $this->options( $this->render(), 'type' ) );
	}

	/**
	 * Synced patterns core's block does not render.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public function data_unrendered_synced_patterns(): array {
		return array(
			'draft'              => array( array( 'post_status' => 'draft' ) ),
			'password-protected' => array( array( 'post_password' => 'oca' ) ),
		);
	}

	public function test_a_pattern_that_holds_itself_is_read_once() {
		register_block_pattern(
			'undergrowth-test/loop',
			array(
				'title'   => 'Loop',
				'content' => '<!-- wp:pattern {"slug":"undergrowth-test/loop"} /-->',
			)
		);
		set_post_format( $this->post( 'Oca' ), 'aside' );
		$this->post( 'Yacon' );

		$this->on_page_with( '<!-- wp:pattern {"slug":"undergrowth-test/loop"} /-->' );
		$html = $this->render();
		unregister_block_pattern( 'undergrowth-test/loop' );

		$this->assertArrayHasKey( 'aside', $this->options( $html, 'type' ) );
	}

	public function test_uses_post_formats_with_no_feed_on_the_page() {
		set_post_format( $this->post( 'Oca' ), 'aside' );
		$this->post( 'Yacon' );

		$this->on_page_with( '' );

		$this->assertArrayHasKey( 'aside', $this->options( $this->render(), 'type' ) );
	}

	public function test_links_each_tag_the_visible_garden_holds() {
		wp_set_post_tags( $this->post( 'Oca' ), array( 'Roots', 'Andes' ) );
		wp_set_post_tags( $this->post( 'Hidden', array( 'post_status' => 'draft' ) ), array( 'Secret' ) );

		$this->assertSame(
			array(
				'All topics' => '/garden/',
				'Andes'      => '/garden/?topic=andes',
				'Roots'      => '/garden/?topic=roots',
			),
			$this->topics( $this->render() )
		);
	}

	public function test_can_take_the_topics_from_categories() {
		$roots = self::factory()->category->create(
			array(
				'name' => 'Roots',
				'slug' => 'roots',
			)
		);
		$this->post( 'Oca', array( 'post_category' => array( $roots ) ) );

		$this->assertArrayHasKey( 'Roots', $this->topics( $this->render( array( 'topicSource' => 'category' ) ) ) );
		$this->assertArrayNotHasKey( 'Uncategorized', $this->topics( $this->render( array( 'topicSource' => 'category' ) ) ) );
	}

	public function test_marks_the_chosen_topic_and_keeps_the_other_filters_in_its_links() {
		wp_set_post_tags( $this->post( 'Oca' ), array( 'Roots', 'Andes' ) );

		$_SERVER['REQUEST_URI'] = '/garden/?topic=roots&stage=seedling&garden-page=2';
		$_GET                   = array(
			'topic'       => 'roots',
			'stage'       => 'seedling',
			'garden-page' => '2',
		);

		$html = $this->render();

		$this->assertStringContainsString( 'href="/garden/?stage=seedling&#038;topic=roots" aria-current="true"', $html );
		$this->assertSame( '/garden/?stage=seedling&topic=andes', $this->topics( $html )['Andes'] );
		$this->assertSame( '/garden/?stage=seedling', $this->topics( $html )['All topics'] );
	}

	public function test_the_form_sends_the_filters_by_get_to_the_same_page() {
		$this->post( 'Oca' );

		$_SERVER['REQUEST_URI'] = '/?page_id=7&topic=roots&stage=seedling';
		$_GET                   = array(
			'page_id' => '7',
			'topic'   => 'roots',
			'stage'   => 'seedling',
		);

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<form [^>]*method="get" action="/"#', $html );
		// The page's own query args and the chosen topic go with the form.
		$this->assertStringContainsString( '<input type="hidden" name="page_id" value="7">', $html );
		$this->assertStringContainsString( '<input type="hidden" name="topic" value="roots">', $html );
		$this->assertStringContainsString( '<option value="seedling" selected>', $html );
		// The button stays for a reader without JavaScript.
		$this->assertMatchesRegularExpression( '#<p class="wp-block-undergrowth-garden-filters-submit"><button type="submit"[^>]*>#', $html );
		// The class that hides it is only added once the script runs.
		$this->assertMatchesRegularExpression( '#<form class="wp-block-undergrowth-garden-filters-form" #', $html );
	}

	public function test_each_select_has_a_label_a_screen_reader_hears_and_an_all_choice_readers_see() {
		set_post_format( $this->post( 'Oca' ), 'aside' );
		$this->post( 'Yacon' );

		$html = $this->render();

		preg_match_all( '#<select id="([^"]+)"#', $html, $ids );
		$this->assertCount( 2, $ids[1] );

		foreach ( $ids[1] as $id ) {
			$this->assertStringContainsString( '<label class="screen-reader-text" for="' . $id . '">', $html );
		}

		$this->assertStringContainsString( '<option value="">All stages</option>', $html );
		$this->assertStringContainsString( '<option value="">All types</option>', $html );
	}

	public function test_puts_the_topics_and_the_selects_on_one_row_under_a_visible_label() {
		wp_set_post_tags( $this->post( 'Oca' ), array( 'Roots' ) );
		set_post_format( $this->post( 'Yacon' ), 'aside' );

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<div class="wp-block-undergrowth-garden-filters-row"><nav class="wp-block-undergrowth-garden-filters-topics" aria-labelledby="([^"]+)"><span class="wp-block-undergrowth-garden-filters-label" id="\1">Topics</span><ul>#', $html );
		$this->assertMatchesRegularExpression( '#</nav><form class="wp-block-undergrowth-garden-filters-form"#', $html );
		$this->assertMatchesRegularExpression( '#</form></div><p class="wp-block-undergrowth-garden-filters-count screen-reader-text" role="status">[^<]+</p></div>$#', $html );
		// Plain links: the chosen one is marked, none is wrapped in anything else.
		$this->assertMatchesRegularExpression( '#<li><a href="[^"]*" aria-current="true"[^>]*>All topics</a></li><li><a href="[^"]*"[^>]*>Roots</a></li></ul></nav>#', $html );
	}

	public function test_counts_the_matching_posts_and_shows_the_count_only_when_asked() {
		wp_set_post_tags( $this->post( 'Oca' ), array( 'Roots' ) );
		$this->post( 'Yacon' );

		// The status is read out either way; by default only a screen reader gets it.
		$this->assertMatchesRegularExpression( '#</div><p class="wp-block-undergrowth-garden-filters-count screen-reader-text" role="status">2 posts</p></div>$#', $this->render() );

		$html = $this->render( array( 'showCount' => true ) );

		$this->assertMatchesRegularExpression( '#</div><p class="wp-block-undergrowth-garden-filters-count" role="status">2 posts</p></div>$#', $html );

		$_GET['topic'] = 'roots';
		$this->assertStringContainsString( 'screen-reader-text" role="status">1 post</p>', $this->render() );
		$this->assertStringContainsString( 'filters-count" role="status">1 post</p>', $this->render( array( 'showCount' => true ) ) );
	}

	public function test_is_a_router_region_that_updates_in_place_and_applies_a_select_on_change() {
		$this->post( 'Oca' );

		$html = $this->render();

		$this->assertStringContainsString( 'data-wp-interactive="undergrowth/garden"', $html );
		$this->assertStringContainsString( 'data-wp-router-region="undergrowth/garden-filters"', $html );
		$this->assertStringContainsString( 'data-wp-on--submit="actions.filter"', $html );
		$this->assertStringContainsString( 'data-wp-on--change="actions.filter"', $html );
		// With the script, the form takes the class that hides the button.
		$this->assertMatchesRegularExpression( '#<form class="wp-block-undergrowth-garden-filters-form"[^>]* data-wp-class--is-live="state.live"#', $html );
	}

	public function test_can_leave_out_each_filter() {
		wp_set_post_tags( $this->post( 'Oca' ), array( 'Roots' ) );
		set_post_format( $this->post( 'Yacon' ), 'aside' );

		$html = $this->render(
			array(
				'showTopics' => false,
				'showStage'  => false,
				'showType'   => false,
			)
		);

		$this->assertStringNotContainsString( 'Roots', $html );
		$this->assertStringNotContainsString( '<select', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'wp-block-undergrowth-garden-filters-row', $html );
	}

	public function test_escapes_term_names() {
		wp_set_post_tags( $this->post( 'Oca' ), array( '<b>Roots</b>' ) );

		$this->assertStringNotContainsString( '<b>', $this->render() );
	}

	/**
	 * Renders the garden feed, which the filters must agree with.
	 *
	 * @return string Markup.
	 */
	private function feed(): string {
		$block = new WP_Block(
			array(
				'blockName'    => 'undergrowth/garden-feed',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		return $block->render();
	}

	public function test_the_feed_is_a_router_region_too() {
		$this->post( 'Oca' );

		$html = $this->feed();

		$this->assertStringContainsString( 'data-wp-interactive="undergrowth/garden"', $html );
		$this->assertStringContainsString( 'data-wp-router-region="undergrowth/garden-feed"', $html );
	}

	public function test_counts_a_filter_of_zero_as_the_feed_does() {
		$this->post( 'Oca' );
		$this->post( 'Yacon' );

		// A reader can write any value into the address. Nothing has this
		// slug, so the feed filters down to nothing and the count must too.
		$_GET['topic'] = '0';

		$this->assertStringContainsString( 'role="status">0 posts</p>', $this->render() );
		$this->assertStringContainsString( 'Nothing in the garden matches.', $this->feed() );
	}

	public function test_counts_a_topic_whose_slug_is_zero_as_the_feed_does() {
		// Core keeps "0" as a slug: a term named "0" takes it when no other
		// term in its taxonomy is in the way, and sanitize_title() keeps it
		// when it is read back from the address.
		$zero = self::factory()->tag->create( array( 'name' => '0' ) );
		$this->assertSame( '0', get_term( $zero )->slug );

		$this->post( 'Oca', array( 'tags_input' => array( $zero ) ) );
		$this->post( 'Yacon' );

		$_GET['topic'] = '0';

		$html = $this->render();
		$feed = $this->feed();

		$this->assertMatchesRegularExpression( '#<a href="[^"]*" aria-current="true"[^>]*>0</a>#', $html );
		$this->assertSame( 1, substr_count( $feed, 'wp-block-undergrowth-garden-feed-item' ) );
		$this->assertStringContainsString( 'role="status">1 post</p>', $html );
	}
}
