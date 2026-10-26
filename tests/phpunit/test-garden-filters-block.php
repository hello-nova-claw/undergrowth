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
		$this->assertTrue( $type->supports['interactivity'] );
		$this->assertFalse( $type->supports['html'] );
	}

	public function test_registers_again_without_a_build() {
		WP_Block_Type_Registry::get_instance()->unregister( 'undergrowth/garden-filters' );

		undergrowth_register_garden_filters_block();

		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'undergrowth/garden-filters' ) );
	}

	public function test_shows_nothing_when_the_garden_is_empty() {
		$this->post( 'Draft', array( 'post_status' => 'draft' ) );

		$this->assertSame( '', $this->render() );
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

	public function test_can_take_the_types_from_another_taxonomy() {
		$essay = self::factory()->category->create(
			array(
				'name' => 'Essay',
				'slug' => 'essay',
			)
		);
		$this->post( 'Oca', array( 'post_category' => array( $essay ) ) );

		$this->assertSame( array( 'essay' => 'Essay' ), array_slice( $this->options( $this->render( array( 'typeSource' => 'category' ) ), 'type' ), 1 ) );
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

		$this->assertStringContainsString( 'href="/garden/?topic=roots&#038;stage=seedling" aria-current="true"', $html );
		$this->assertSame( '/garden/?topic=andes&stage=seedling', $this->topics( $html )['Andes'] );
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
		$this->assertMatchesRegularExpression( '#<button type="submit"[^>]*>#', $html );
	}

	public function test_each_select_has_a_label() {
		set_post_format( $this->post( 'Oca' ), 'aside' );
		$this->post( 'Yacon' );

		$html = $this->render();

		preg_match_all( '#<select id="([^"]+)"#', $html, $ids );
		$this->assertCount( 2, $ids[1] );

		foreach ( $ids[1] as $id ) {
			$this->assertStringContainsString( '<label for="' . $id . '">', $html );
		}
	}

	public function test_announces_how_many_posts_match() {
		wp_set_post_tags( $this->post( 'Oca' ), array( 'Roots' ) );
		$this->post( 'Yacon' );

		$this->assertMatchesRegularExpression( '#<p class="wp-block-undergrowth-garden-filters-count" role="status">2 posts</p>#', $this->render() );

		$_GET['topic'] = 'roots';
		$this->assertStringContainsString( 'role="status">1 post</p>', $this->render() );
	}

	public function test_is_a_router_region_that_updates_in_place() {
		$this->post( 'Oca' );

		$html = $this->render();

		$this->assertStringContainsString( 'data-wp-interactive="undergrowth/garden"', $html );
		$this->assertStringContainsString( 'data-wp-router-region="undergrowth/garden-filters"', $html );
		$this->assertStringContainsString( 'data-wp-on--submit="actions.filter"', $html );
		$this->assertStringContainsString( 'data-wp-on--change="actions.filter"', $html );
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
		$this->assertStringContainsString( 'role="status"', $html );
	}

	public function test_escapes_term_names() {
		wp_set_post_tags( $this->post( 'Oca' ), array( '<b>Roots</b>' ) );

		$this->assertStringNotContainsString( '<b>', $this->render() );
	}

	public function test_the_feed_is_a_router_region_too() {
		$this->post( 'Oca' );

		$block = new WP_Block(
			array(
				'blockName'    => 'undergrowth/garden-feed',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$html  = $block->render();

		$this->assertStringContainsString( 'data-wp-interactive="undergrowth/garden"', $html );
		$this->assertStringContainsString( 'data-wp-router-region="undergrowth/garden-feed"', $html );
	}
}
