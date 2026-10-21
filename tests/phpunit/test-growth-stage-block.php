<?php
/**
 * Tests for the growth-stage badge block.
 *
 * @package Undergrowth
 */

/**
 * Growth-stage badge block.
 */
class Test_Growth_Stage_Block extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
	}

	/**
	 * Renders the block as it would be rendered for a post.
	 *
	 * @param int                  $post_id Post ID given as block context, or 0 for none.
	 * @param array<string, mixed> $attrs   Block attributes.
	 * @return string Markup.
	 */
	private function render( int $post_id, array $attrs = array() ): string {
		$context = $post_id ? array(
			'postId'   => $post_id,
			'postType' => get_post_type( $post_id ),
		) : array();

		$block = new WP_Block(
			array(
				'blockName'    => 'undergrowth/growth-stage',
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			$context
		);

		return $block->render();
	}

	/**
	 * Creates a published post at a stage.
	 *
	 * @param string               $stage One of UNDERGROWTH_STAGES.
	 * @param array<string, mixed> $args  Post fields.
	 * @return int Post ID.
	 */
	private function post_at( string $stage, array $args = array() ): int {
		$post_id = self::factory()->post->create( $args + array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, 'undergrowth_stage', $stage );

		return $post_id;
	}

	public function test_block_is_registered_from_block_json() {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'undergrowth/growth-stage' );

		$this->assertInstanceOf( WP_Block_Type::class, $type );
		$this->assertSame( 'Growth stage', $type->title );
		$this->assertSame( array( 'postId', 'postType' ), $type->uses_context );
		$this->assertSame( 'undergrowth_render_growth_stage_block', $type->render_callback );
		$this->assertArrayHasKey( 'color', $type->supports );
		$this->assertArrayHasKey( 'typography', $type->supports );
		$this->assertArrayHasKey( 'spacing', $type->supports );
		$this->assertFalse( $type->supports['html'] );
	}

	/**
	 * Shows the stage's name, and a label saying what the name is.
	 *
	 * @dataProvider data_shown_stages
	 *
	 * @param string $stage Stage stored for the post.
	 * @param string $name  Name readers see.
	 */
	public function test_shows_the_stage_name_with_an_accessible_label( string $stage, string $name ) {
		$html = $this->render( $this->post_at( $stage ) );

		$this->assertStringContainsString( 'class="is-stage-' . $stage . ' wp-block-undergrowth-growth-stage"', $html );
		$this->assertStringContainsString( '<span class="screen-reader-text">Growth stage: ' . $name . '</span>', $html );
		$this->assertStringContainsString( '<span aria-hidden="true">' . $name . '</span>', $html );
		$this->assertStringStartsWith( '<p ', $html );
	}

	/**
	 * Every stage a reader can see.
	 *
	 * @return array<string, string[]>
	 */
	public function data_shown_stages(): array {
		return array(
			'seedling'  => array( 'seedling', 'Seedling' ),
			'sprout'    => array( 'sprout', 'Sprout' ),
			'budding'   => array( 'budding', 'Budding' ),
			'evergreen' => array( 'evergreen', 'Evergreen' ),
		);
	}

	public function test_a_new_post_shows_seedling() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertStringContainsString( 'Growth stage: Seedling', $this->render( $post_id ) );
	}

	public function test_a_composted_post_shows_nothing() {
		$this->assertSame( '', $this->render( $this->post_at( 'composting' ) ) );
	}

	public function test_a_pinned_stage_shows_no_marker_to_readers() {
		$post_id = $this->post_at( 'sprout' );
		update_post_meta( $post_id, 'undergrowth_stage_pinned', true );

		$this->assertSame( $this->render( $this->post_at( 'sprout' ) ), $this->render( $post_id ) );
	}

	public function test_a_draft_shows_nothing_to_a_reader_but_shows_to_its_author() {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = $this->post_at(
			'sprout',
			array(
				'post_status' => 'draft',
				'post_author' => $author,
			)
		);

		wp_set_current_user( 0 );
		$this->assertSame( '', $this->render( $post_id ) );

		wp_set_current_user( $author );
		$this->assertStringContainsString( 'Growth stage: Sprout', $this->render( $post_id ) );
	}

	public function test_a_private_post_shows_nothing_to_a_reader() {
		wp_set_current_user( 0 );

		$this->assertSame( '', $this->render( $this->post_at( 'sprout', array( 'post_status' => 'private' ) ) ) );
	}

	public function test_a_post_type_that_is_not_indexed_shows_nothing() {
		$this->assertSame( '', $this->render( $this->post_at( 'sprout', array( 'post_type' => 'page' ) ) ) );
	}

	public function test_a_post_type_added_to_the_index_shows_its_stage() {
		add_filter(
			'undergrowth_post_types',
			static function () {
				return array( 'post', 'page' );
			}
		);

		$this->assertStringContainsString( 'Growth stage: Sprout', $this->render( $this->post_at( 'sprout', array( 'post_type' => 'page' ) ) ) );
	}

	public function test_no_post_in_context_shows_nothing() {
		$this->assertSame( '', $this->render( 0 ) );
		$this->assertSame( '', $this->render( PHP_INT_MAX ) );
	}

	public function test_translations_are_escaped() {
		add_filter(
			'gettext_with_context_undergrowth',
			static function ( $translation, $text ) {
				return 'Sprout' === $text ? '<b>Sprout</b>' : $translation;
			},
			10,
			2
		);
		add_filter(
			'gettext_undergrowth',
			static function ( $translation, $text ) {
				return 'Growth stage: %s' === $text ? '<i>Stage</i>: %s' : $translation;
			},
			10,
			2
		);

		$html = $this->render( $this->post_at( 'sprout' ) );

		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( '<i>', $html );
		$this->assertStringContainsString( '&lt;i&gt;Stage&lt;/i&gt;: &lt;b&gt;Sprout&lt;/b&gt;', $html );
		$this->assertStringContainsString( '<span aria-hidden="true">&lt;b&gt;Sprout&lt;/b&gt;</span>', $html );
	}

	public function test_theme_styles_reach_the_wrapper() {
		$html = $this->render(
			$this->post_at( 'budding' ),
			array(
				'backgroundColor' => 'accent',
				'textColor'       => 'contrast',
				'fontSize'        => 'small',
				'style'           => array(
					'spacing' => array(
						'padding' => array( 'top' => 'var:preset|spacing|20' ),
					),
				),
			)
		);

		$this->assertMatchesRegularExpression( '/class="[^"]*has-accent-background-color[^"]*"/', $html );
		$this->assertMatchesRegularExpression( '/class="[^"]*has-contrast-color[^"]*"/', $html );
		$this->assertMatchesRegularExpression( '/class="[^"]*has-small-font-size[^"]*"/', $html );
		$this->assertStringContainsString( 'padding-top:var(--wp--preset--spacing--20)', $html );
	}

	public function test_each_post_in_a_query_loop_shows_its_own_stage() {
		$this->post_at( 'sprout', array( 'post_date' => '2026-01-02 00:00:00' ) );
		$this->post_at( 'evergreen', array( 'post_date' => '2026-01-01 00:00:00' ) );
		$this->post_at( 'composting', array( 'post_date' => '2026-01-03 00:00:00' ) );

		$html = do_blocks(
			'<!-- wp:query {"queryId":1,"query":{"perPage":10,"postType":"post","order":"desc","orderBy":"date","inherit":false}} -->'
			. '<div class="wp-block-query"><!-- wp:post-template -->'
			. '<!-- wp:post-title /--><!-- wp:undergrowth/growth-stage /-->'
			. '<!-- /wp:post-template --></div><!-- /wp:query -->'
		);

		$this->assertSame( 1, substr_count( $html, 'Growth stage: Sprout' ) );
		$this->assertSame( 1, substr_count( $html, 'Growth stage: Evergreen' ) );
		$this->assertStringNotContainsString( 'Composting', $html );
		$this->assertLessThan(
			strpos( $html, 'Growth stage: Evergreen' ),
			strpos( $html, 'Growth stage: Sprout' )
		);
	}

	public function test_post_content_shows_the_stage_of_the_post_being_read() {
		$post_id = $this->post_at( 'budding', array( 'post_content' => '<!-- wp:undergrowth/growth-stage /-->' ) );

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$this->assertStringContainsString( 'Growth stage: Budding', apply_filters( 'the_content', get_the_content() ) );
	}
}
