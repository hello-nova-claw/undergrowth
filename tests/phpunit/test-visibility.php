<?php
/**
 * Tests for the one rule for what readers may see of the garden.
 *
 * @package Undergrowth
 */

/**
 * Garden visibility.
 */
class Test_Visibility extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
	}

	public function test_a_published_post_is_visible() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertTrue( undergrowth_is_garden_visible( $post_id ) );
		$this->assertTrue( undergrowth_is_garden_visible( get_post( $post_id ) ) );
	}

	/**
	 * Hides each kind of post readers must not find, whoever is logged in.
	 *
	 * @dataProvider data_hidden_posts
	 *
	 * @param array<string, string> $args  Post fields.
	 * @param string                $stage Stage stored for the post.
	 */
	public function test_a_post_outside_the_garden_is_hidden( array $args, string $stage ) {
		$post_id = self::factory()->post->create( $args + array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, 'undergrowth_stage', $stage );

		$this->assertFalse( undergrowth_is_garden_visible( $post_id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( undergrowth_is_garden_visible( $post_id ) );
	}

	/**
	 * Every exclusion in the rule.
	 *
	 * @return array<string, array{0: array<string, string>, 1: string}>
	 */
	public function data_hidden_posts(): array {
		return array(
			'draft'              => array( array( 'post_status' => 'draft' ), 'sprout' ),
			'scheduled'          => array(
				array(
					'post_status' => 'future',
					'post_date'   => '2099-01-01 00:00:00',
				),
				'sprout',
			),
			'private'            => array( array( 'post_status' => 'private' ), 'sprout' ),
			'password-protected' => array( array( 'post_password' => 'oca' ), 'sprout' ),
			'unindexed type'     => array( array( 'post_type' => 'page' ), 'sprout' ),
			'composted'          => array( array(), 'composting' ),
		);
	}

	public function test_an_indexed_post_type_readers_cannot_view_is_hidden() {
		register_post_type( 'undergrowth_note', array( 'public' => false ) );
		add_filter(
			'undergrowth_post_types',
			static function () {
				return array( 'post', 'undergrowth_note' );
			}
		);
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'undergrowth_note',
			)
		);

		$this->assertFalse( undergrowth_is_garden_visible( $post_id ) );
	}

	public function test_a_post_type_added_to_the_index_is_visible() {
		add_filter(
			'undergrowth_post_types',
			static function () {
				return array( 'post', 'page' );
			}
		);

		$this->assertTrue(
			undergrowth_is_garden_visible(
				self::factory()->post->create(
					array(
						'post_status' => 'publish',
						'post_type'   => 'page',
					)
				)
			)
		);
	}

	public function test_a_queryable_type_that_is_not_public_is_hidden() {
		register_post_type(
			'undergrowth_note',
			array(
				'public'             => false,
				'publicly_queryable' => true,
			)
		);
		add_filter(
			'undergrowth_post_types',
			static function () {
				return array( 'post', 'undergrowth_note' );
			}
		);

		$this->assertFalse(
			undergrowth_is_garden_visible(
				self::factory()->post->create(
					array(
						'post_status' => 'publish',
						'post_type'   => 'undergrowth_note',
					)
				)
			)
		);
	}

	public function test_a_missing_post_is_hidden_even_with_a_global_post() {
		$GLOBALS['post'] = get_post( self::factory()->post->create( array( 'post_status' => 'publish' ) ) );

		$this->assertFalse( undergrowth_is_garden_visible( 0 ) );
		$this->assertFalse( undergrowth_is_garden_visible( '' ) );
		$this->assertFalse( undergrowth_is_garden_visible( PHP_INT_MAX ) );
		$this->assertFalse( undergrowth_is_garden_visible( null ) );

		unset( $GLOBALS['post'] );
	}

	public function test_the_batch_ignores_negative_ids() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( array(), undergrowth_filter_garden_visible( array( -$post_id ) ) );
	}

	public function test_the_batch_keeps_visible_posts_in_order_once_each() {
		$first   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$second  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft   = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$compost = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $compost, 'undergrowth_stage', 'composting' );

		$this->assertSame(
			array( $second, $first ),
			undergrowth_filter_garden_visible( array( $second, $draft, (string) $first, $compost, $second, 0, -3, PHP_INT_MAX ) )
		);
		$this->assertSame( array(), undergrowth_filter_garden_visible( array() ) );
	}

	public function test_the_batch_loads_posts_and_meta_in_a_fixed_number_of_queries() {
		$post_ids = self::factory()->post->create_many( 12, array( 'post_status' => 'publish' ) );

		foreach ( $post_ids as $post_id ) {
			update_post_meta( $post_id, 'undergrowth_stage', 'sprout' );
		}

		wp_cache_flush();
		undergrowth_indexed_post_types();

		$before  = get_num_queries();
		$visible = undergrowth_filter_garden_visible( array_merge( $post_ids, range( PHP_INT_MAX - 20, PHP_INT_MAX - 1 ) ) );

		$this->assertSame( $post_ids, $visible );
		$this->assertLessThanOrEqual( 3, get_num_queries() - $before );
	}

	/**
	 * Checks each kind of post without loading it, and agrees with the full check.
	 *
	 * @dataProvider data_hidden_posts
	 *
	 * @param array<string, string> $args  Post fields.
	 * @param string                $stage Stage stored for the post.
	 */
	public function test_the_batch_hides_the_same_posts_without_loading_them( array $args, string $stage ) {
		$visible = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$hidden  = self::factory()->post->create( $args + array( 'post_status' => 'publish' ) );
		update_post_meta( $hidden, 'undergrowth_stage', $stage );
		wp_cache_flush();

		$this->assertSame( array( $visible ), undergrowth_filter_garden_visible( array( $hidden, $visible ), false ) );
		$this->assertFalse( wp_cache_get( $visible, 'posts' ) );
		$this->assertFalse( wp_cache_get( $hidden, 'posts' ) );
		$this->assertSame( array( $visible ), undergrowth_filter_garden_visible( array( $hidden, $visible ) ) );
	}

	public function test_the_batch_without_loading_keeps_visible_posts_in_order_once_each() {
		$first   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$second  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft   = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$compost = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $compost, 'undergrowth_stage', 'composting' );
		wp_cache_flush();

		$this->assertSame(
			array( $second, $first ),
			undergrowth_filter_garden_visible( array( $second, $draft, (string) $first, $compost, $second, 0, -3, PHP_INT_MAX ), false )
		);
	}

	public function test_the_batch_without_loading_reads_each_post_once_a_request() {
		$post_ids = self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
		wp_cache_flush();
		undergrowth_filter_garden_visible( $post_ids, false );

		$before = get_num_queries();

		$this->assertSame( $post_ids, undergrowth_filter_garden_visible( $post_ids, false ) );
		$this->assertSame( $before, get_num_queries() );
	}

	public function test_the_batch_without_loading_sees_a_post_that_changed_since() {
		$post_ids = self::factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );
		wp_cache_flush();
		undergrowth_filter_garden_visible( $post_ids, false );

		wp_update_post(
			array(
				'ID'          => $post_ids[0],
				'post_status' => 'draft',
			)
		);
		wp_cache_delete( $post_ids[0], 'posts' );

		$this->assertSame( array( $post_ids[1] ), undergrowth_filter_garden_visible( $post_ids, false ) );
	}

	public function test_the_batch_without_loading_forgets_what_it_read_when_the_site_switches() {
		global $wpdb;

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_cache_flush();
		undergrowth_filter_garden_visible( array( $post_id ), false );

		// Another site's post with the same ID, as far as the kept columns know.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $post_id ) );
		do_action( 'switch_blog', get_current_blog_id(), get_current_blog_id(), 'switch' );

		$this->assertSame( array(), undergrowth_filter_garden_visible( array( $post_id ), false ) );
	}

	public function test_the_batch_without_loading_reads_posts_and_meta_in_a_fixed_number_of_queries() {
		$post_ids = self::factory()->post->create_many( 12, array( 'post_status' => 'publish' ) );

		foreach ( $post_ids as $post_id ) {
			update_post_meta( $post_id, 'undergrowth_stage', 'sprout' );
		}

		wp_cache_flush();
		undergrowth_indexed_post_types();

		$before  = get_num_queries();
		$visible = undergrowth_filter_garden_visible( array_merge( $post_ids, range( PHP_INT_MAX - 20, PHP_INT_MAX - 1 ) ), false );

		$this->assertSame( $post_ids, $visible );
		$this->assertLessThanOrEqual( 3, get_num_queries() - $before );

		// The stages were read with the batch, for callers that sort by them.
		$after = get_num_queries();
		$this->assertSame( 'sprout', undergrowth_get_stage( $post_ids[0] ) );
		$this->assertSame( $after, get_num_queries() );
	}
}
