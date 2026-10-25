<?php
/**
 * Tests for "More like this": ranking, hiding and the block.
 *
 * @package Undergrowth
 */

/**
 * "More like this".
 */
class Test_More_Like_This extends WP_UnitTestCase {

	use Plants_Posts;

	/**
	 * The post every list here is for.
	 *
	 * @var int
	 */
	private $source;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->source = $this->old( 'Oca', array( 1, 0, 0 ) );
	}

	/**
	 * Stores a post last changed long ago, so freshness lifts it by nothing
	 * that matters.
	 *
	 * @param string               $title  Post title.
	 * @param float[]              $vector Vector to store.
	 * @param array<string, mixed> $args   Other post fields.
	 * @param array<string, mixed> $meta   Vector identity to store instead.
	 * @return int Post ID.
	 */
	private function old( string $title, array $vector, array $args = array(), array $meta = array() ): int {
		return $this->seed( $title, $vector, $args + array( 'post_date' => '2001-01-01 00:00:00' ), $meta );
	}

	/**
	 * Sets when a post was last changed.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $when    GMT date and time, or any strtotime() phrase.
	 */
	private function changed( int $post_id, string $when ): void {
		global $wpdb;

		$date = gmdate( 'Y-m-d H:i:s', strtotime( $when ) );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $date,
				'post_modified_gmt' => $date,
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );
	}

	/**
	 * Records accepted links pointing to a post.
	 *
	 * @param int $target_id Post linked to.
	 * @param int $links     How many.
	 */
	private function linked( int $target_id, int $links ): void {
		global $wpdb;

		for ( $i = 0; $i < $links; $i++ ) {
			$wpdb->insert(
				undergrowth_links_table(),
				array(
					'source_id'  => self::factory()->post->create(),
					'target_id'  => $target_id,
					'state'      => 'accepted',
					'score'      => 0.5,
					'sentence'   => '',
					'created_at' => '2026-01-01 00:00:00',
					'updated_at' => '2026-01-01 00:00:00',
				)
			);
		}
	}

	/**
	 * Renders the block for a post.
	 *
	 * @param int                  $post_id Post ID given as block context.
	 * @param array<string, mixed> $attrs   Block attributes.
	 * @return string Markup.
	 */
	private function render( int $post_id, array $attrs = array() ): string {
		$block = new WP_Block(
			array(
				'blockName'    => 'undergrowth/more-like-this',
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array(
				'postId'   => $post_id,
				'postType' => 'post',
			)
		);

		return $block->render();
	}

	public function test_ranks_by_meaning_and_leaves_out_distant_posts() {
		$far   = $this->old( 'Ledger', array( 0, 1, 0 ) );
		$mid   = $this->old( 'Syrup', array( 1, 1, 0 ) );
		$close = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$near  = $this->old( 'Mashua', array( 1, 0.5, 0 ) );

		$this->assertSame( array( $close, $near, $mid ), undergrowth_more_like_this( $this->source, 6 ) );
		$this->assertNotContains( $far, undergrowth_more_like_this( $this->source, 6 ) );
	}

	public function test_only_vectors_from_the_same_model_and_language_are_compared() {
		$other = $this->old( 'Yacon', array( 1, 0, 0 ), array(), array( 'model' => 'another-model' ) );
		$fr    = $this->old( 'Mashua', array( 1, 0, 0 ), array(), array( 'language' => 'fr_FR' ) );
		$same  = $this->old( 'Syrup', array( 1, 0.2, 0 ) );

		$this->assertSame( array( $same ), undergrowth_more_like_this( $this->source, 6 ) );
		$this->assertNotContains( $other, undergrowth_more_like_this( $this->source, 6 ) );
		$this->assertNotContains( $fr, undergrowth_more_like_this( $this->source, 6 ) );
	}

	public function test_equal_scores_keep_a_fixed_order() {
		$first  = $this->old( 'Yacon', array( 1, 0.2, 0 ) );
		$second = $this->old( 'Mashua', array( 1, 0.2, 0 ) );

		$this->assertSame( array( $first, $second ), undergrowth_more_like_this( $this->source, 6 ) );
		undergrowth_forget_similar();
		$this->assertSame( array( $first, $second ), undergrowth_more_like_this( $this->source, 6 ) );
	}

	public function test_evergreen_rises_above_a_closer_seedling() {
		$seedling  = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$evergreen = $this->old( 'Mashua', array( 1, 0.5, 0 ) );

		$this->assertSame( array( $seedling, $evergreen ), undergrowth_more_like_this( $this->source, 6 ) );

		update_post_meta( $evergreen, 'undergrowth_stage', 'evergreen' );

		$this->assertSame( array( $evergreen, $seedling ), undergrowth_more_like_this( $this->source, 6 ) );
	}

	public function test_a_post_far_down_by_meaning_alone_can_still_rise_on_its_weights() {
		foreach ( range( 1, 40 ) as $n ) {
			$this->old( 'Post ' . $n, array( 1, 0.1 + $n / 1000, 0 ) );
		}

		$evergreen = $this->old( 'Mashua', array( 1, 0.15, 0 ) );
		update_post_meta( $evergreen, 'undergrowth_stage', 'evergreen' );
		$this->changed( $evergreen, 'now' );

		$this->assertSame( $evergreen, undergrowth_more_like_this( $this->source, 3 )[0] );
	}

	public function test_a_post_beyond_the_kept_closest_can_still_rise_on_its_weights() {
		foreach ( range( 1, 110 ) as $n ) {
			$this->old( 'Post ' . $n, array( 1, 0.1 + $n / 10000, 0 ) );
		}

		$evergreen = $this->old( 'Mashua', array( 1, 0.15, 0 ) );
		update_post_meta( $evergreen, 'undergrowth_stage', 'evergreen' );
		$this->changed( $evergreen, 'now' );

		$this->assertSame( $evergreen, undergrowth_more_like_this( $this->source, 3 )[0] );
	}

	public function test_posts_readers_cannot_see_never_crowd_out_one_they_can() {
		foreach ( range( 1, 105 ) as $n ) {
			$this->old( 'Private ' . $n, array( 1, $n / 10000, 0 ), array( 'post_status' => 'private' ) );
		}

		$visible = $this->old( 'Mashua', array( 1, 0.2, 0 ) );

		$this->assertSame( array( $visible ), undergrowth_more_like_this( $this->source, 3 ) );
	}

	public function test_hidden_posts_never_crowd_out_one_that_can_be_shown() {
		$hidden = array();

		foreach ( range( 1, 105 ) as $n ) {
			$hidden[] = $this->old( 'Hidden ' . $n, array( 1, $n / 10000, 0 ) );
		}

		$shown = $this->old( 'Mashua', array( 1, 0.2, 0 ) );

		foreach ( $hidden as $id ) {
			add_post_meta( $this->source, 'undergrowth_similar_hidden', $id );
		}

		$this->assertSame( array( $shown ), undergrowth_more_like_this( $this->source, 3 ) );
	}

	public function test_a_new_stage_measures_the_lists_again() {
		$yacon = $this->old( 'Yacon', array( 1, 0, 0 ) );
		undergrowth_similar_scores( $this->source );

		do_action( 'undergrowth_stage_changed', $yacon );
		undergrowth_similar_scores( $this->source );

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_measure_similar', array( $this->source ) ) );
	}

	public function test_stage_weights_can_be_filtered() {
		$seedling = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$budding  = $this->old( 'Mashua', array( 1, 0.5, 0 ) );
		update_post_meta( $budding, 'undergrowth_stage', 'budding' );

		add_filter(
			'undergrowth_similar_stage_weights',
			static function () {
				return array( 'budding' => 0.5 );
			}
		);

		$this->assertSame( array( $seedling, $budding ), undergrowth_more_like_this( $this->source, 6 ) );
	}

	public function test_a_recent_change_lifts_a_post_and_the_lift_fades() {
		$closer = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$fresh  = $this->old( 'Mashua', array( 1, 0.3, 0 ) );

		$this->changed( $fresh, 'now' );
		$this->assertSame( array( $fresh, $closer ), undergrowth_more_like_this( $this->source, 6 ) );

		// After four half-lives the lift is a sixteenth of what it was.
		$this->changed( $fresh, '-720 days' );
		$this->assertSame( array( $closer, $fresh ), undergrowth_more_like_this( $this->source, 6 ) );
	}

	public function test_freshness_can_be_filtered() {
		$closer = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$fresh  = $this->old( 'Mashua', array( 1, 0.3, 0 ) );
		$this->changed( $fresh, 'now' );

		add_filter(
			'undergrowth_similar_freshness',
			static function () {
				return array(
					'lift'      => 0,
					'half_life' => 180,
				);
			}
		);

		$this->assertSame( array( $closer, $fresh ), undergrowth_more_like_this( $this->source, 6 ) );
	}

	/**
	 * Never lists a post readers may not see.
	 *
	 * @dataProvider data_hidden_posts
	 *
	 * @param array<string, string> $args  Post fields.
	 * @param string                $stage Stage stored for the post.
	 */
	public function test_never_lists_a_post_outside_the_garden( array $args, string $stage ) {
		$hidden  = $this->old( 'Yacon', array( 1, 0, 0 ), $args );
		$visible = $this->old( 'Mashua', array( 1, 0.5, 0 ) );
		update_post_meta( $hidden, 'undergrowth_stage', $stage );

		$this->assertSame( array( $visible ), undergrowth_more_like_this( $this->source, 6 ) );
	}

	/**
	 * Every post the visibility rule leaves out.
	 *
	 * @return array<string, array{0: array<string, string>, 1: string}>
	 */
	public function data_hidden_posts(): array {
		return array(
			'draft'              => array( array( 'post_status' => 'draft' ), 'sprout' ),
			'private'            => array( array( 'post_status' => 'private' ), 'sprout' ),
			'password-protected' => array( array( 'post_password' => 'oca' ), 'sprout' ),
			'unindexed type'     => array( array( 'post_type' => 'page' ), 'sprout' ),
			'composted'          => array( array(), 'composting' ),
		);
	}

	public function test_a_post_outside_the_garden_has_no_list() {
		$this->old( 'Yacon', array( 1, 0, 0 ) );
		update_post_meta( $this->source, 'undergrowth_stage', 'composting' );

		$this->assertSame( array(), undergrowth_more_like_this( $this->source, 6 ) );
		$this->assertSame( '', $this->render( $this->source ) );
	}

	public function test_a_post_left_out_later_drops_off_the_cached_list() {
		$yacon = $this->old( 'Yacon', array( 1, 0, 0 ) );
		$this->assertSame( array( $yacon ), undergrowth_more_like_this( $this->source, 6 ) );

		wp_update_post(
			array(
				'ID'          => $yacon,
				'post_status' => 'private',
			)
		);

		$this->assertSame( array(), undergrowth_more_like_this( $this->source, 6 ) );
	}

	public function test_a_hidden_post_never_comes_back_until_shown_again() {
		$yacon  = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$mashua = $this->old( 'Mashua', array( 1, 0.5, 0 ) );

		$this->assertTrue( undergrowth_set_similar_hidden( $this->source, $yacon, true ) );
		$this->assertTrue( undergrowth_set_similar_hidden( $this->source, $yacon, true ) );
		$this->assertSame( array( $yacon ), undergrowth_similar_hidden( $this->source ) );
		$this->assertSame( array( $mashua ), undergrowth_more_like_this( $this->source, 6 ) );

		// Not when the list is measured again, nor when the post changes.
		$this->plant( 'Syrup', array( 0, 0, 1 ) );
		update_post_meta( $yacon, 'undergrowth_stage', 'evergreen' );
		$this->assertSame( array( $mashua ), undergrowth_more_like_this( $this->source, 6 ) );

		// Hiding is for this post's list only.
		$this->assertContains( $yacon, undergrowth_more_like_this( $mashua, 6 ) );

		$this->assertTrue( undergrowth_set_similar_hidden( $this->source, $yacon, false ) );
		$this->assertSame( array( $yacon, $mashua ), undergrowth_more_like_this( $this->source, 6 ) );
	}

	public function test_only_someone_who_can_edit_the_post_can_hide_from_its_list() {
		$yacon = $this->old( 'Yacon', array( 1, 0, 0 ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( undergrowth_set_similar_hidden( $this->source, $yacon, true ) );
		$this->assertFalse( undergrowth_set_similar_hidden( $this->source, $this->source, true ) );
		$this->assertFalse( undergrowth_set_similar_hidden( PHP_INT_MAX, $yacon, true ) );
		$this->assertSame( array(), undergrowth_similar_hidden( $this->source ) );
	}

	public function test_the_last_slot_goes_to_a_close_post_few_others_link_to() {
		$first  = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$second = $this->old( 'Mashua', array( 1, 0.2, 0 ) );
		$quiet  = $this->old( 'Syrup', array( 1, 0.3, 0 ) );
		$this->linked( $first, 3 );
		$this->linked( $second, 2 );

		$this->assertSame( array( $first, $quiet ), undergrowth_more_like_this( $this->source, 2 ) );

		// One post shown leaves no room to give away.
		$this->assertSame( array( $first ), undergrowth_more_like_this( $this->source, 1 ) );
	}

	public function test_a_list_that_already_holds_a_quiet_post_is_left_alone() {
		$first  = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$second = $this->old( 'Mashua', array( 1, 0.2, 0 ) );
		$this->old( 'Syrup', array( 1, 0.3, 0 ) );
		$this->linked( $first, 3 );

		$this->assertSame( array( $first, $second ), undergrowth_more_like_this( $this->source, 2 ) );
	}

	public function test_the_quiet_slot_only_goes_to_one_of_the_closest_posts() {
		$first  = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$second = $this->old( 'Mashua', array( 1, 0.15, 0 ) );
		$third  = $this->old( 'Syrup', array( 1, 0.2, 0 ) );
		$fourth = $this->old( 'Ledger', array( 1, 0.25, 0 ) );
		$this->old( 'Far', array( 1, 1, 0 ) );

		foreach ( array( $first, $second, $third, $fourth ) as $post_id ) {
			$this->linked( $post_id, 1 );
		}

		$this->assertSame( array( $first, $second ), undergrowth_more_like_this( $this->source, 2 ) );
	}

	public function test_the_list_is_kept_until_a_vector_changes_then_measured_again_in_cron() {
		$yacon = $this->old( 'Yacon', array( 1, 0, 0 ) );
		$this->assertSame( array( $yacon => 1.0 ), array_map( 'round', undergrowth_similar_scores( $this->source ) ) );

		// A vector stored without an embed is not seen until one happens.
		$mashua = $this->old( 'Mashua', array( 1, 0.1, 0 ) );
		$this->assertArrayNotHasKey( $mashua, undergrowth_similar_scores( $this->source ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_measure_similar', array( $this->source ) ) );

		// After one, a page view still gets the kept list and reads no
		// vectors; the list is measured again in cron.
		do_action( 'undergrowth_post_embedded', $mashua );
		$before = get_num_queries();
		$this->assertArrayNotHasKey( $mashua, undergrowth_similar_scores( $this->source ) );
		$this->assertLessThanOrEqual( 3, get_num_queries() - $before );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_measure_similar', array( $this->source ) ) );

		do_action( 'undergrowth_measure_similar', $this->source );
		$this->assertArrayHasKey( $mashua, undergrowth_similar_scores( $this->source ) );

		$this->old( 'Syrup', array( 1, 0.2, 0 ) );
		do_action( 'undergrowth_post_left_index', $yacon );
		undergrowth_measure_similar( $this->source );
		$this->assertCount( 3, undergrowth_similar_scores( $this->source ) );
	}

	public function test_a_post_without_a_vector_has_an_empty_list() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( array(), undergrowth_similar_scores( $post_id ) );
		$this->assertSame( array(), undergrowth_more_like_this( $post_id, 3 ) );
	}

	public function test_block_is_registered_from_block_json() {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'undergrowth/more-like-this' );

		$this->assertInstanceOf( WP_Block_Type::class, $type );
		$this->assertSame( 'More like this', $type->title );
		$this->assertSame( array( 'postId', 'postType' ), $type->uses_context );
		$this->assertSame( 'undergrowth_render_more_like_this_block', $type->render_callback );
		$this->assertSame( array( 'undergrowth-more-like-this' ), $type->style_handles );
		$this->assertSame( 3, $type->attributes['postsToShow']['default'] );
		$this->assertFalse( $type->attributes['showImage']['default'] );
		$this->assertFalse( $type->attributes['showExcerpt']['default'] );
		$this->assertArrayHasKey( 'color', $type->supports );
		$this->assertArrayHasKey( 'typography', $type->supports );
		$this->assertFalse( $type->supports['html'] );
	}

	public function test_renders_titles_and_links_in_a_labelled_list() {
		$yacon  = $this->old( 'Yacon <em>roots</em>', array( 1, 0.1, 0 ) );
		$mashua = $this->old( 'Mashua', array( 1, 0.5, 0 ) );

		$html = $this->render( $this->source );

		$this->assertStringStartsWith( '<nav ', $html );
		$this->assertStringContainsString( 'aria-label="More like this"', $html );
		$this->assertStringContainsString( 'class="wp-block-undergrowth-more-like-this"', $html );
		$this->assertStringContainsString( '<a class="undergrowth-more-like-this-link" href="' . get_permalink( $yacon ) . '">', $html );
		$this->assertStringContainsString( 'Yacon &lt;em&gt;roots&lt;/em&gt;', $html );
		$this->assertStringContainsString( get_permalink( $mashua ), $html );
		$this->assertLessThan( strpos( $html, 'Mashua' ), strpos( $html, 'Yacon' ) );
		$this->assertSame( 2, substr_count( $html, '<li ' ) );
	}

	public function test_shows_as_many_posts_as_asked_within_limits() {
		foreach ( range( 1, 8 ) as $n ) {
			$this->old( 'Post ' . $n, array( 1, $n / 10, 0 ) );
		}

		$this->assertSame( 3, substr_count( $this->render( $this->source ), '<li ' ) );
		$this->assertSame( 1, substr_count( $this->render( $this->source, array( 'postsToShow' => 1 ) ), '<li ' ) );
		$this->assertSame( 6, substr_count( $this->render( $this->source, array( 'postsToShow' => 6 ) ), '<li ' ) );
		$this->assertCount( 6, undergrowth_more_like_this( $this->source, 60 ) );
		$this->assertCount( 1, undergrowth_more_like_this( $this->source, -1 ) );
	}

	public function test_shows_only_excerpts_the_author_wrote_and_only_when_asked() {
		$this->old(
			'Yacon',
			array( 1, 0.1, 0 ),
			array(
				'post_excerpt' => 'Sweet <b>roots</b>.',
				'post_content' => 'The body never shows.',
			)
		);
		$this->old(
			'Mashua',
			array( 1, 0.5, 0 ),
			array(
				'post_content' => 'Nor does this body.',
				'post_excerpt' => '',
			)
		);

		$this->assertStringNotContainsString( 'Sweet', $this->render( $this->source ) );

		$html = $this->render( $this->source, array( 'showExcerpt' => true ) );

		$this->assertStringContainsString( '<p class="undergrowth-more-like-this-excerpt">Sweet roots.</p>', $html );
		$this->assertSame( 1, substr_count( $html, '-excerpt' ) );
		$this->assertStringNotContainsString( 'body', $html );
	}

	public function test_shows_the_featured_image_only_when_asked_and_without_alt_text() {
		$yacon      = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $yacon );
		set_post_thumbnail( $yacon, $attachment );

		$this->assertStringNotContainsString( '<img', $this->render( $this->source ) );

		$html = $this->render( $this->source, array( 'showImage' => true ) );

		$this->assertMatchesRegularExpression( '/<a class="undergrowth-more-like-this-link" href="[^"]+"><img [^>]*alt=""/', $html );

		wp_delete_attachment( $attachment, true );
	}

	public function test_renders_nothing_without_posts_to_show() {
		$this->assertSame( '', $this->render( $this->source ) );
		$this->assertSame( '', $this->render( 0 ) );
	}

	public function test_theme_styles_reach_the_wrapper() {
		$this->old( 'Yacon', array( 1, 0.1, 0 ) );

		$html = $this->render(
			$this->source,
			array(
				'textColor' => 'contrast',
				'fontSize'  => 'small',
				'align'     => 'wide',
			)
		);

		$this->assertMatchesRegularExpression( '/<nav class="[^"]*has-contrast-color[^"]*"/', $html );
		$this->assertMatchesRegularExpression( '/<nav class="[^"]*has-small-font-size[^"]*"/', $html );
		$this->assertMatchesRegularExpression( '/<nav class="[^"]*alignwide[^"]*"/', $html );
	}

	public function test_rest_lists_shown_and_hidden_posts_to_an_editor() {
		$yacon  = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$mashua = $this->old( 'Mashua', array( 1, 0.5, 0 ) );
		undergrowth_set_similar_hidden( $this->source, $mashua, true );

		$request = new WP_REST_Request( 'GET', "/undergrowth/v1/posts/{$this->source}/more-like-this" );
		$request->set_param( 'count', 2 );
		$data = rest_do_request( $request )->get_data();

		$this->assertSame( array( $yacon ), array_column( $data['shown'], 'id' ) );
		$this->assertSame( 'Yacon', $data['shown'][0]['title'] );
		$this->assertSame( get_permalink( $yacon ), $data['shown'][0]['link'] );
		$this->assertSame( array( $mashua ), array_column( $data['hidden'], 'id' ) );
	}

	public function test_rest_hides_and_shows_again() {
		$yacon = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$route = "/undergrowth/v1/posts/{$this->source}/more-like-this/hidden/{$yacon}";

		$this->assertSame( array( 'hidden' => true ), rest_do_request( new WP_REST_Request( 'POST', $route ) )->get_data() );
		$this->assertSame( array(), undergrowth_more_like_this( $this->source, 3 ) );

		$this->assertSame( array( 'hidden' => false ), rest_do_request( new WP_REST_Request( 'DELETE', $route ) )->get_data() );
		$this->assertSame( array( $yacon ), undergrowth_more_like_this( $this->source, 3 ) );

		$gone = rest_do_request( new WP_REST_Request( 'POST', "/undergrowth/v1/posts/{$this->source}/more-like-this/hidden/" . PHP_INT_MAX ) );
		$this->assertSame( 404, $gone->get_status() );
	}

	public function test_rest_refuses_someone_who_cannot_edit_the_post() {
		$yacon = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', "/undergrowth/v1/posts/{$this->source}/more-like-this" ) )->get_status() );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'POST', "/undergrowth/v1/posts/{$this->source}/more-like-this/hidden/{$yacon}" ) )->get_status() );
		$this->assertSame( array(), undergrowth_similar_hidden( $this->source ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', "/undergrowth/v1/posts/{$this->source}/more-like-this" ) )->get_status() );
	}

	public function test_cannot_hide_a_post_the_user_may_not_read() {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$own     = $this->old( 'Mine', array( 1, 0, 0 ), array( 'post_author' => $author ) );
		$private = $this->old( 'Secret', array( 1, 0, 0 ), array( 'post_status' => 'private' ) );
		wp_set_current_user( $author );

		$this->assertFalse( undergrowth_set_similar_hidden( $own, $private, true ) );
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'POST', "/undergrowth/v1/posts/{$own}/more-like-this/hidden/{$private}" ) )->get_status() );
		$this->assertSame( array(), undergrowth_similar_hidden( $own ) );
	}

	public function test_the_sidebar_never_names_a_post_the_user_may_not_read() {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$own     = $this->old( 'Mine', array( 1, 0, 0 ), array( 'post_author' => $author ) );
		$private = $this->old( 'Secret', array( 1, 0, 0 ), array( 'post_status' => 'private' ) );

		// Hidden earlier, by someone who could read it.
		add_post_meta( $own, 'undergrowth_similar_hidden', $private );
		wp_set_current_user( $author );

		$data = rest_do_request( new WP_REST_Request( 'GET', "/undergrowth/v1/posts/{$own}/more-like-this" ) )->get_data();

		$this->assertSame( array(), $data['hidden'] );
		$this->assertStringNotContainsString( 'Secret', wp_json_encode( $data ) );
	}
}
