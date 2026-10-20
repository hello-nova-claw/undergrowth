<?php
/**
 * Tests for growth stages.
 *
 * @package Undergrowth
 */

/**
 * Growth stages.
 */
class Test_Stages extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		// The test case unregisters every meta key after each test.
		undergrowth_register_stage_meta();
	}

	/**
	 * Stores a link row between two posts.
	 *
	 * @param int    $source_id Source post ID.
	 * @param int    $target_id Target post ID.
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
	 * Publishes posts.
	 *
	 * @param int $count How many.
	 * @return int[] Post IDs.
	 */
	private function publish( int $count ): array {
		return self::factory()->post->create_many( $count, array( 'post_status' => 'publish' ) );
	}

	public function test_post_with_no_accepted_links_measures_seedling() {
		list( $post_id ) = $this->publish( 1 );

		$this->assertSame( 'seedling', undergrowth_measure_stage( $post_id ) );
	}

	public function test_post_with_one_accepted_link_measures_seedling() {
		list( $post_id, $other ) = $this->publish( 2 );

		$this->link( $post_id, $other );

		$this->assertSame( 'seedling', undergrowth_measure_stage( $post_id ) );
		$this->assertSame( 'seedling', undergrowth_measure_stage( $other ) );
	}

	public function test_post_with_two_accepted_links_measures_sprout() {
		list( $post_id, $a, $b ) = $this->publish( 3 );

		$this->link( $post_id, $a );
		$this->link( $b, $post_id );

		$this->assertSame( 'sprout', undergrowth_measure_stage( $post_id ) );
	}

	public function test_links_both_ways_between_two_posts_count_as_two() {
		list( $post_id, $other ) = $this->publish( 2 );

		$this->link( $post_id, $other );
		$this->link( $other, $post_id );

		$this->assertSame( 'sprout', undergrowth_measure_stage( $post_id ) );
		$this->assertSame( 'sprout', undergrowth_measure_stage( $other ) );
	}

	public function test_proposed_and_vetoed_links_do_not_count() {
		list( $post_id, $a, $b, $c ) = $this->publish( 4 );

		$this->link( $post_id, $a, 'proposed' );
		$this->link( $post_id, $b, 'vetoed' );
		$this->link( $c, $post_id, 'proposed' );

		$this->assertSame( 'seedling', undergrowth_measure_stage( $post_id ) );
	}

	public function test_accepted_link_to_a_post_that_left_the_index_does_not_count() {
		list( $post_id, $deleted, $draft, $live ) = $this->publish( 4 );

		$this->link( $post_id, $deleted );
		$this->link( $draft, $post_id );
		$this->link( $post_id, $live );

		wp_delete_post( $deleted, true );
		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( 'seedling', undergrowth_measure_stage( $post_id ) );
	}

	public function test_many_accepted_links_still_measure_sprout_without_clusters() {
		$posts   = $this->publish( 8 );
		$post_id = array_shift( $posts );

		foreach ( $posts as $other ) {
			$this->link( $post_id, $other );
			$this->link( $other, $post_id );
		}

		$this->assertSame( 'sprout', undergrowth_measure_stage( $post_id ) );
	}

	public function test_measure_ignores_a_stored_stage() {
		list( $post_id ) = $this->publish( 1 );

		undergrowth_set_stage( $post_id, 'composting' );

		$this->assertSame( 'seedling', undergrowth_measure_stage( $post_id ) );
	}

	public function test_post_with_no_stored_stage_reads_as_seedling() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( 'seedling', get_post_meta( $post_id, 'undergrowth_stage', true ) );
		$this->assertSame( 'seedling', undergrowth_get_stage( $post_id ) );
		$this->assertFalse( undergrowth_is_stage_pinned( $post_id ) );
	}

	public function test_known_stage_is_stored() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertTrue( undergrowth_set_stage( $post_id, 'sprout' ) );
		$this->assertSame( 'sprout', get_post_meta( $post_id, 'undergrowth_stage', true ) );
		$this->assertSame( 'sprout', undergrowth_get_stage( $post_id ) );
	}

	public function test_unknown_stage_is_refused() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertFalse( undergrowth_set_stage( $post_id, 'wilting' ) );
		$this->assertSame( 'seedling', undergrowth_get_stage( $post_id ) );
	}

	public function test_stage_is_not_stored_for_a_missing_post() {
		$this->assertFalse( undergrowth_set_stage( PHP_INT_MAX, 'sprout' ) );
	}

	public function test_setting_the_same_stage_again_succeeds() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		undergrowth_set_stage( $post_id, 'sprout' );

		$this->assertTrue( undergrowth_set_stage( $post_id, 'sprout' ) );
	}

	public function test_unknown_stored_value_reads_as_seedling() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		update_post_meta( $post_id, 'undergrowth_stage', 'mushroom' );

		$this->assertSame( 'seedling', undergrowth_get_stage( $post_id ) );
	}

	public function test_pinned_flag_reads_from_meta() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		update_post_meta( $post_id, 'undergrowth_stage_pinned', '1' );

		$this->assertTrue( undergrowth_is_stage_pinned( $post_id ) );
	}

	public function test_stage_keys_are_hidden_from_the_custom_fields_box() {
		$this->assertTrue( is_protected_meta( 'undergrowth_stage', 'post' ) );
		$this->assertTrue( is_protected_meta( 'undergrowth_stage_pinned', 'post' ) );
		$this->assertFalse( is_protected_meta( 'undergrowth_other', 'post' ) );
	}

	public function test_stage_meta_is_registered_for_every_indexed_post_type() {
		register_post_type( 'recipe' );
		add_filter(
			'undergrowth_post_types',
			static function () {
				return array( 'post', 'recipe' );
			}
		);

		undergrowth_register_stage_meta();

		$this->assertArrayHasKey( 'undergrowth_stage', get_registered_meta_keys( 'post', 'recipe' ) );
		$this->assertArrayHasKey( 'undergrowth_stage_pinned', get_registered_meta_keys( 'post', 'recipe' ) );

		unregister_post_type( 'recipe' );
	}

	public function test_stage_can_be_read_through_rest_for_a_post_type_without_custom_fields() {
		global $wp_rest_server;

		register_post_type(
			'recipe',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor' ),
			)
		);
		add_filter(
			'undergrowth_post_types',
			static function () {
				return array( 'post', 'recipe' );
			}
		);

		undergrowth_register_stage_meta();

		// Routes are built once per server, so a fresh one picks up the new type.
		$wp_rest_server = null;

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'recipe',
				'post_status' => 'publish',
			)
		);

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/recipe/' . $post_id ) );
		$data     = $response->get_data();

		$this->assertSame( 'seedling', $data['meta']['undergrowth_stage'] );
		$this->assertFalse( $data['meta']['undergrowth_stage_pinned'] );

		unregister_post_type( 'recipe' );
		$wp_rest_server = null;
	}

	public function test_stage_can_be_read_through_rest() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		undergrowth_set_stage( $post_id, 'sprout' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$data     = $response->get_data();

		$this->assertSame( 'sprout', $data['meta']['undergrowth_stage'] );
		$this->assertFalse( $data['meta']['undergrowth_stage_pinned'] );
	}

	/**
	 * Neither key can be written, whoever asks.
	 *
	 * @dataProvider data_stage_keys
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Value to try to write.
	 */
	public function test_stage_cannot_be_written_through_rest( string $key, $value ) {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params( array( 'meta' => array( $key => $value ) ) );

		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'seedling', undergrowth_get_stage( $post_id ) );
		$this->assertFalse( undergrowth_is_stage_pinned( $post_id ) );
	}

	/**
	 * Meta keys and values a REST client might try to write.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public function data_stage_keys(): array {
		return array(
			'stage'  => array( 'undergrowth_stage', 'evergreen' ),
			'pinned' => array( 'undergrowth_stage_pinned', true ),
		);
	}

	/**
	 * Runs uninstall without dropping the tables.
	 *
	 * The test suite's tables are temporary, and dropping them aborts the
	 * transaction the rest of the test runs in.
	 */
	private function uninstall(): void {
		$keep_tables = static function ( $query ) {
			return 0 === strpos( $query, 'DROP TABLE' ) ? 'SELECT 1' : $query;
		};
		add_filter( 'query', $keep_tables, 1 );

		undergrowth_uninstall();

		remove_filter( 'query', $keep_tables, 1 );
	}

	public function test_uninstall_removes_stage_meta() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		undergrowth_set_stage( $post_id, 'sprout' );
		update_post_meta( $post_id, 'undergrowth_stage_pinned', '1' );

		$this->uninstall();

		$this->assertFalse( metadata_exists( 'post', $post_id, 'undergrowth_stage' ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, 'undergrowth_stage_pinned' ) );
	}

	/**
	 * Checks the query each site's meta removal starts with, as the schema
	 * tests do, because the test suite cannot switch into a new site's tables
	 * mid-uninstall.
	 *
	 * @group ms-required
	 */
	public function test_network_uninstall_removes_stage_meta_on_every_site() {
		global $wpdb;

		$site_ids = self::factory()->blog->create_many( 2 );

		$queries = array();
		$record  = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record, 2 );

		$this->uninstall();

		remove_filter( 'query', $record, 2 );

		foreach ( array_merge( array( get_current_blog_id() ), $site_ids ) as $site_id ) {
			foreach ( array( 'undergrowth_stage', 'undergrowth_stage_pinned' ) as $key ) {
				$this->assertContains( "SELECT meta_id FROM {$wpdb->get_blog_prefix( $site_id )}postmeta WHERE meta_key = '{$key}'", $queries );
			}
		}
	}
}
