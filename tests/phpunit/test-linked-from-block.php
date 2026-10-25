<?php
/**
 * Tests for the "Linked from" block.
 *
 * @package Undergrowth
 */

/**
 * "Linked from" block: the author's posts with an accepted link to this one.
 */
class Test_Linked_From_Block extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
	}

	public function tear_down() {
		unset( $GLOBALS['post'] );

		parent::tear_down();
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
				'blockName'    => 'undergrowth/linked-from',
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
	 * Stores a link row between two posts.
	 *
	 * @param int    $source_id Post the link sits in.
	 * @param int    $target_id Post the link points to.
	 * @param string $state     Row state.
	 */
	private function link( int $source_id, int $target_id, string $state = 'accepted' ): void {
		global $wpdb;

		$wpdb->insert(
			undergrowth_links_table(),
			array(
				'source_id'  => $source_id,
				'target_id'  => $target_id,
				'state'      => $state,
				'score'      => 0.5,
				'sentence'   => '',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Publishes a post.
	 *
	 * @param string               $title Post title.
	 * @param array<string, mixed> $args  Other post fields.
	 * @return int Post ID.
	 */
	private function publish( string $title, array $args = array() ): int {
		return self::factory()->post->create(
			$args + array(
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Titles of the posts the block lists, in order.
	 *
	 * @param string $html Block markup.
	 * @return string[] Link texts.
	 */
	private function listed( string $html ): array {
		preg_match_all( '#<li><a href="[^"]*">([^<]*)</a></li>#', $html, $matches );

		return $matches[1];
	}

	public function test_block_is_registered_from_block_json() {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'undergrowth/linked-from' );

		$this->assertInstanceOf( WP_Block_Type::class, $type );
		$this->assertSame( 'Linked from', $type->title );
		$this->assertSame( array( 'postId', 'postType' ), $type->uses_context );
		$this->assertSame( 'undergrowth_render_linked_from_block', $type->render_callback );
		$this->assertSame( 'string', $type->attributes['heading']['type'] );
		$this->assertSame( 5, $type->attributes['maxItems']['default'] );
		$this->assertArrayHasKey( 'color', $type->supports );
		$this->assertArrayHasKey( 'typography', $type->supports );
		$this->assertArrayHasKey( 'spacing', $type->supports );
		$this->assertFalse( $type->supports['html'] );
	}

	public function test_registers_again_without_a_build() {
		WP_Block_Type_Registry::get_instance()->unregister( 'undergrowth/linked-from' );

		undergrowth_register_linked_from_block();

		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'undergrowth/linked-from' ) );
	}

	public function test_lists_the_posts_with_an_accepted_link_here_newest_first() {
		$target = $this->publish( 'Oca' );
		$older  = $this->publish( 'Yacon', array( 'post_date' => '2026-01-01 10:00:00' ) );
		$newer  = $this->publish( 'Mashua', array( 'post_date' => '2026-06-01 10:00:00' ) );
		$this->link( $older, $target );
		$this->link( $newer, $target );

		$html = $this->render( $target );

		$this->assertSame( array( 'Mashua', 'Yacon' ), $this->listed( $html ) );
		$this->assertStringContainsString( '<a href="' . esc_url( get_permalink( $newer ) ) . '">Mashua</a>', $html );
		$this->assertStringContainsString( 'class="wp-block-undergrowth-linked-from"', $html );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Linked from</h2>', $html );
		$this->assertStringStartsWith( '<div ', $html );
	}

	public function test_lists_only_accepted_links_that_point_here() {
		$target    = $this->publish( 'Oca' );
		$proposed  = $this->publish( 'Yacon' );
		$vetoed    = $this->publish( 'Mashua' );
		$outgoing  = $this->publish( 'Ulluco' );
		$elsewhere = $this->publish( 'Achira' );
		$this->link( $proposed, $target, 'proposed' );
		$this->link( $vetoed, $target, 'vetoed' );
		$this->link( $target, $outgoing );
		$this->link( $elsewhere, $outgoing );

		$this->assertSame( '', $this->render( $target ) );
	}

	/**
	 * Leaves out a linking post readers may not see.
	 *
	 * @dataProvider data_hidden_posts
	 *
	 * @param array<string, string> $args  Post fields.
	 * @param string                $stage Stage stored for the post.
	 */
	public function test_leaves_out_a_linking_post_readers_may_not_see( array $args, string $stage ) {
		$target = $this->publish( 'Oca' );
		$shown  = $this->publish( 'Yacon' );
		$hidden = $this->publish( 'Mashua', $args );
		update_post_meta( $hidden, 'undergrowth_stage', $stage );
		$this->link( $shown, $target );
		$this->link( $hidden, $target );

		// Even its author, logged in, sees what readers see.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( array( 'Yacon' ), $this->listed( $this->render( $target ) ) );
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
			'not indexed'        => array( array( 'post_type' => 'page' ), 'sprout' ),
			'composted'          => array( array(), 'composting' ),
		);
	}

	public function test_a_trashed_or_deleted_linking_post_is_left_out() {
		$target  = $this->publish( 'Oca' );
		$trashed = $this->publish( 'Yacon' );
		$deleted = $this->publish( 'Mashua' );
		$this->link( $trashed, $target );
		$this->link( $deleted, $target );
		wp_trash_post( $trashed );
		wp_delete_post( $deleted, true );

		$this->assertSame( '', $this->render( $target ) );
	}

	/**
	 * Shows nothing on a post readers may not see in the garden.
	 *
	 * @dataProvider data_hidden_posts
	 *
	 * @param array<string, string> $args  Post fields.
	 * @param string                $stage Stage stored for the post.
	 */
	public function test_shows_nothing_on_a_post_readers_may_not_see( array $args, string $stage ) {
		$target = $this->publish( 'Oca', $args );
		update_post_meta( $target, 'undergrowth_stage', $stage );
		$this->link( $this->publish( 'Yacon' ), $target );

		$this->assertSame( '', $this->render( $target ) );
	}

	public function test_shows_nothing_outside_a_post() {
		$target = $this->publish( 'Oca' );
		$this->link( $this->publish( 'Yacon' ), $target );

		// Such as on an archive, where the global post is its first post.
		$GLOBALS['post'] = get_post( $target );

		$this->assertSame( '', $this->render( 0 ) );
	}

	public function test_lists_at_most_the_chosen_number() {
		$target = $this->publish( 'Oca' );

		foreach ( range( 1, 4 ) as $day ) {
			$this->link( $this->publish( 'Post ' . $day, array( 'post_date' => "2026-03-0{$day} 10:00:00" ) ), $target );
		}

		$this->assertSame( array( 'Post 4', 'Post 3' ), $this->listed( $this->render( $target, array( 'maxItems' => 2 ) ) ) );
	}

	public function test_lists_five_by_default() {
		$target = $this->publish( 'Oca' );

		foreach ( range( 1, 7 ) as $day ) {
			$this->link( $this->publish( 'Post ' . $day, array( 'post_date' => "2026-03-0{$day} 10:00:00" ) ), $target );
		}

		$this->assertCount( 5, $this->listed( $this->render( $target ) ) );
	}

	/**
	 * Falls back to the default for a number outside what the block offers.
	 *
	 * @dataProvider data_out_of_range_counts
	 *
	 * @param mixed $max      Stored maximum.
	 * @param int   $expected Items listed out of 8.
	 */
	public function test_a_number_out_of_range_lists_the_default( $max, int $expected ) {
		$target = $this->publish( 'Oca' );

		foreach ( range( 1, 8 ) as $n ) {
			$this->link( $this->publish( 'Post ' . $n ), $target );
		}

		$this->assertCount( $expected, $this->listed( $this->render( $target, array( 'maxItems' => $max ) ) ) );
	}

	/**
	 * Maximums outside the range, as a hand-edited post could store them.
	 *
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public function data_out_of_range_counts(): array {
		return array(
			'zero'     => array( 0, 5 ),
			'negative' => array( -3, 5 ),
			'too many' => array( 500, 5 ),
			'text'     => array( 'many', 5 ),
		);
	}

	public function test_the_heading_is_the_authors_own_text_escaped() {
		$target = $this->publish( 'Oca' );
		$this->link( $this->publish( 'Yacon' ), $target );

		$html = $this->render( $target, array( 'heading' => 'Paths <em>here</em>' ) );

		$this->assertStringContainsString( '<h2 class="wp-block-heading">Paths &lt;em&gt;here&lt;/em&gt;</h2>', $html );
	}

	public function test_an_empty_heading_falls_back_to_the_default() {
		$target = $this->publish( 'Oca' );
		$this->link( $this->publish( 'Yacon' ), $target );

		$this->assertStringContainsString( '>Linked from</h2>', $this->render( $target, array( 'heading' => '   ' ) ) );
	}

	public function test_titles_are_escaped_and_an_untitled_post_is_still_named() {
		$target = $this->publish( 'Oca' );
		$this->link( $this->publish( 'Roots & <b>tubers</b>', array( 'post_date' => '2026-02-01 10:00:00' ) ), $target );
		$this->link( $this->publish( '', array( 'post_date' => '2026-01-01 10:00:00' ) ), $target );

		$html = $this->render( $target );

		$this->assertMatchesRegularExpression( '#>Roots &(amp|\#038); tubers</a>#', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '>(no title)</a>', $html );
	}

	public function test_a_post_linking_to_itself_is_left_out() {
		$target = $this->publish( 'Oca' );
		$this->link( $target, $target );

		$this->assertSame( '', $this->render( $target ) );
	}

	public function test_block_supports_reach_the_wrapper() {
		$target = $this->publish( 'Oca' );
		$this->link( $this->publish( 'Yacon' ), $target );

		$html = $this->render( $target, array( 'fontSize' => 'small' ) );

		$this->assertStringContainsString( 'has-small-font-size', $html );
		$this->assertStringNotContainsString( 'style=', $html );
	}
}
