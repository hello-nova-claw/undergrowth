<?php
/**
 * Tests for the Growth stage column on the Posts list.
 *
 * @package Undergrowth
 */

/**
 * A post's stage sits with the post, beside Compost and the Composting view.
 */
class Test_Posts_Stage_Column extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * Prints one cell of the column.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function cell( int $post_id ): string {
		ob_start();
		undergrowth_render_stage_column( 'undergrowth_stage', $post_id );

		return (string) ob_get_clean();
	}

	public function test_the_column_is_added_to_lists_in_the_garden() {
		undergrowth_add_posts_list_hooks( 'post' );

		$columns = apply_filters( 'manage_post_posts_columns', array( 'title' => 'Title' ) );

		$this->assertSame( 'Growth stage', $columns['undergrowth_stage'] );
		$this->assertNotFalse( has_action( 'manage_post_posts_custom_column', 'undergrowth_render_stage_column' ) );
	}

	public function test_the_column_comes_after_the_title() {
		$columns = undergrowth_add_stage_column(
			array(
				'cb'     => '',
				'title'  => 'Title',
				'author' => 'Author',
			)
		);

		$this->assertSame( array( 'cb', 'title', 'undergrowth_stage', 'author' ), array_keys( $columns ) );
	}

	public function test_a_list_without_a_title_column_gets_it_last() {
		$this->assertSame(
			array( 'cb', 'author', 'undergrowth_stage' ),
			array_keys(
				undergrowth_add_stage_column(
					array(
						'cb'     => '',
						'author' => 'Author',
					)
				)
			)
		);
	}

	public function test_lists_outside_the_garden_have_no_column() {
		undergrowth_add_posts_list_hooks( 'attachment' );

		$this->assertFalse( has_filter( 'manage_attachment_posts_columns', 'undergrowth_add_stage_column' ) );
	}

	public function test_a_published_post_shows_its_stage() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		undergrowth_set_stage( $post_id, 'sprout' );

		$this->assertSame( 'Sprout', trim( $this->cell( $post_id ) ) );
	}

	public function test_a_pinned_stage_is_marked() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		undergrowth_pin_stage( $post_id, 'evergreen' );

		$html = $this->cell( $post_id );

		$this->assertStringContainsString( 'Evergreen', $html );
		$this->assertStringContainsString( 'undergrowth-pinned', $html );
		$this->assertStringContainsString( '<span class="screen-reader-text">Pinned</span>', $html );
	}

	public function test_a_composting_post_says_so() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		undergrowth_compost_post( $post_id );

		$this->assertStringContainsString( 'Composting', $this->cell( $post_id ) );
		$this->assertStringNotContainsString( 'undergrowth-pinned', $this->cell( $post_id ) );
	}

	public function test_a_draft_has_no_stage_yet() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->assertStringContainsString( '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">No growth stage</span>', $this->cell( $post_id ) );
	}

	public function test_other_columns_are_left_alone() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		ob_start();
		undergrowth_render_stage_column( 'author', $post_id );

		$this->assertSame( '', ob_get_clean() );
	}
}
