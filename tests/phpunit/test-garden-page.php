<?php
/**
 * Tests for the garden count block and the Garden page pattern.
 *
 * @package Undergrowth
 */

/**
 * Garden count block and the Garden page pattern.
 */
class Test_Garden_Page extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
		$_GET = array();
	}

	/**
	 * Renders the count block.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 * @return string Markup.
	 */
	private function render( array $attrs = array() ): string {
		$block = new WP_Block(
			array(
				'blockName'    => 'undergrowth/garden-count',
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		return $block->render();
	}

	/**
	 * Publishes posts.
	 *
	 * @param int                  $count How many.
	 * @param array<string, mixed> $args  Post fields.
	 * @return int[] Post IDs.
	 */
	private function posts( int $count, array $args = array() ): array {
		return self::factory()->post->create_many( $count, $args + array( 'post_status' => 'publish' ) );
	}

	public function test_block_is_registered_from_block_json() {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'undergrowth/garden-count' );

		$this->assertInstanceOf( WP_Block_Type::class, $type );
		$this->assertSame( 'Garden count', $type->title );
		$this->assertSame( 'undergrowth_render_garden_count_block', $type->render_callback );
		$this->assertTrue( $type->attributes['showLabel']['default'] );
		$this->assertArrayHasKey( 'typography', $type->supports );
		$this->assertFalse( $type->supports['html'] );
	}

	public function test_registers_again_without_a_build() {
		WP_Block_Type_Registry::get_instance()->unregister( 'undergrowth/garden-count' );

		undergrowth_register_garden_count_block();

		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'undergrowth/garden-count' ) );
	}

	public function test_counts_only_the_posts_readers_may_see() {
		$this->posts( 3 );
		$this->posts( 1, array( 'post_status' => 'draft' ) );
		$this->posts( 1, array( 'post_password' => 'oca' ) );
		update_post_meta( $this->posts( 1 )[0], 'undergrowth_stage', 'composting' );

		$this->assertMatchesRegularExpression( '#^<p [^>]*class="wp-block-undergrowth-garden-count"[^>]*><span class="wp-block-undergrowth-garden-count-number">3</span> <span class="wp-block-undergrowth-garden-count-label">posts in the garden</span></p>$#', $this->render() );
	}

	public function test_names_a_single_post_in_the_singular() {
		$this->posts( 1 );

		$this->assertStringContainsString( '>1</span> <span class="wp-block-undergrowth-garden-count-label">post in the garden</span>', $this->render() );
	}

	public function test_can_show_the_number_alone() {
		$this->posts( 2 );

		$html = $this->render( array( 'showLabel' => false ) );

		$this->assertStringContainsString( '<span class="wp-block-undergrowth-garden-count-number">2</span>', $html );
		$this->assertStringNotContainsString( 'in the garden', $html );
	}

	public function test_counts_the_whole_garden_whatever_the_filters() {
		$this->posts( 2 );

		$_GET['stage'] = 'evergreen';

		$this->assertStringContainsString( '>2</span>', $this->render() );
	}

	public function test_shows_nothing_for_an_empty_garden() {
		$this->assertSame( '', $this->render() );
	}

	public function test_registers_the_garden_page_pattern_in_its_own_category() {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( 'undergrowth/garden-page' );

		$this->assertIsArray( $pattern );
		$this->assertSame( 'Garden page', $pattern['title'] );
		$this->assertSame( array( 'undergrowth' ), $pattern['categories'] );
		$this->assertTrue( WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( 'undergrowth' ) );
	}

	public function test_the_pattern_holds_the_garden_blocks_in_order() {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( 'undergrowth/garden-page' );
		$names   = array();
		$walk    = static function ( array $blocks ) use ( &$walk, &$names ): void {
			foreach ( $blocks as $block ) {
				if ( null !== $block['blockName'] ) {
					$names[] = $block['blockName'];
				}
				$walk( $block['innerBlocks'] );
			}
		};
		$walk( parse_blocks( $pattern['content'] ) );

		$this->assertSame(
			array( 'core/heading', 'core/paragraph', 'undergrowth/garden-count', 'undergrowth/garden-filters', 'undergrowth/garden-feed' ),
			array_values( array_intersect( $names, array( 'core/heading', 'core/paragraph', 'undergrowth/garden-count', 'undergrowth/garden-filters', 'undergrowth/garden-feed' ) ) )
		);
		$this->assertContains( 'core/group', $names );
		$this->assertContains( 'core/columns', $names );
		$this->assertStringContainsString( '"showFeatured":true', $pattern['content'] );
	}

	public function test_the_pattern_sets_no_colours_and_leaves_the_intro_to_the_author() {
		$content = WP_Block_Patterns_Registry::get_instance()->get_registered( 'undergrowth/garden-page' )['content'];

		$this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,6}\b|rgb\(|"(text|background)Color"/i', $content );
		$this->assertStringNotContainsString( 'style="', $content );
		// The intro is a placeholder the editor shows, so readers see none
		// of it until the author writes their own.
		$this->assertMatchesRegularExpression( '#<!-- wp:paragraph \{"placeholder":"[^"]+"\} -->\s*<p></p>#', $content );
	}

	public function test_the_pattern_renders_the_visible_garden() {
		$this->posts( 2 );

		$html = do_blocks( WP_Block_Patterns_Registry::get_instance()->get_registered( 'undergrowth/garden-page' )['content'] );

		$this->assertStringContainsString( 'The Garden', $html );
		$this->assertStringContainsString( 'wp-block-undergrowth-garden-count', $html );
		$this->assertStringContainsString( 'wp-block-undergrowth-garden-filters', $html );
		$this->assertSame( 2, substr_count( $html, 'wp-block-undergrowth-garden-feed-item' ) );
	}
}
