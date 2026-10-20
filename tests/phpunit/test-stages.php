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

	public function tear_down() {
		global $wp_meta_boxes, $wp_rest_server;

		// The test case resets neither, and a failed assertion would skip
		// cleanup at the end of a test.
		if ( post_type_exists( 'recipe' ) ) {
			unregister_post_type( 'recipe' );
		}

		$wp_meta_boxes  = null;
		$wp_rest_server = null;

		parent::tear_down();
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

	/**
	 * Puts posts in one cluster, led by the first.
	 *
	 * @param int[] $post_ids Post IDs.
	 */
	private function cluster( array $post_ids ): void {
		foreach ( $post_ids as $post_id ) {
			update_post_meta( $post_id, 'undergrowth_cluster', $post_ids[0] );
		}
	}

	public function test_post_in_a_cluster_with_links_in_and_out_measures_budding() {
		list( $post_id, $a, $b ) = $this->publish( 3 );

		$this->cluster( array( $post_id, $a, $b ) );
		$this->link( $post_id, $a );
		$this->link( $b, $post_id );

		$this->assertSame( 'budding', undergrowth_measure_stage( $post_id ) );
	}

	public function test_links_in_and_out_may_lead_outside_the_cluster() {
		list( $post_id, $a, $b, $outside, $elsewhere ) = $this->publish( 5 );

		$this->cluster( array( $post_id, $a, $b ) );
		$this->link( $post_id, $outside );
		$this->link( $elsewhere, $post_id );

		$this->assertSame( 'budding', undergrowth_measure_stage( $post_id ) );
	}

	public function test_post_in_a_cluster_with_links_only_out_measures_sprout() {
		list( $post_id, $a, $b ) = $this->publish( 3 );

		$this->cluster( array( $post_id, $a, $b ) );
		$this->link( $post_id, $a );
		$this->link( $post_id, $b );

		$this->assertSame( 'sprout', undergrowth_measure_stage( $post_id ) );
	}

	public function test_post_in_a_cluster_with_links_only_in_measures_sprout() {
		list( $post_id, $a, $b ) = $this->publish( 3 );

		$this->cluster( array( $post_id, $a, $b ) );
		$this->link( $a, $post_id );
		$this->link( $b, $post_id );

		$this->assertSame( 'sprout', undergrowth_measure_stage( $post_id ) );
	}

	public function test_post_in_a_cluster_with_one_link_measures_seedling() {
		list( $post_id, $a, $b ) = $this->publish( 3 );

		$this->cluster( array( $post_id, $a, $b ) );
		$this->link( $post_id, $a );

		$this->assertSame( 'seedling', undergrowth_measure_stage( $post_id ) );
	}

	public function test_two_similar_posts_are_not_a_cluster_for_budding() {
		list( $post_id, $a, $b ) = $this->publish( 3 );

		$this->cluster( array( $post_id, $a ) );
		$this->link( $post_id, $a );
		$this->link( $b, $post_id );

		$this->assertSame( 'sprout', undergrowth_measure_stage( $post_id ) );
	}

	public function test_link_to_a_post_that_left_the_index_does_not_make_a_post_budding() {
		list( $post_id, $a, $b, $draft ) = $this->publish( 4 );

		$this->cluster( array( $post_id, $a, $b ) );
		$this->link( $post_id, $a );
		$this->link( $draft, $post_id );
		$this->link( $post_id, $b );

		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( 'sprout', undergrowth_measure_stage( $post_id ) );
	}

	/**
	 * Evergreen also needs a hub linking to the post, and hubs come only from
	 * reader-mode fusion, so no post measures Evergreen yet.
	 */
	public function test_post_with_every_link_but_no_hub_stays_budding() {
		$posts   = $this->publish( 8 );
		$post_id = $posts[0];

		$this->cluster( $posts );

		foreach ( array_slice( $posts, 1 ) as $other ) {
			$this->link( $post_id, $other );
			$this->link( $other, $post_id );
		}

		$this->assertSame( 'budding', undergrowth_measure_stage( $post_id ) );
	}

	public function test_cycle_stores_budding() {
		list( $post_id, $a, $b ) = $this->publish( 3 );

		$this->cluster( array( $post_id, $a, $b ) );
		$this->link( $post_id, $a );
		$this->link( $b, $post_id );

		undergrowth_run_stage_cycle();

		$this->assertSame( 'budding', undergrowth_get_stage( $post_id ) );
	}

	public function test_monthly_event_clusters_then_measures_budding() {
		$posts = $this->publish( 3 );

		foreach ( $posts as $i => $post_id ) {
			undergrowth_save_vector(
				$post_id,
				array( 1.0, 0.1 * $i ),
				array(
					'provider'     => 'test-provider',
					'model'        => 'test-model',
					'language'     => 'en_US',
					'content_hash' => 'hash',
				)
			);
		}

		$this->link( $posts[0], $posts[1] );
		$this->link( $posts[2], $posts[0] );

		do_action( 'undergrowth_cluster_cycle' );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_stage_cycle', array( 0 ) ) );
		do_action( 'undergrowth_stage_cycle', 0 );

		$this->assertSame( 'budding', undergrowth_get_stage( $posts[0] ) );
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
	}

	public function test_custom_fields_box_stays_off_for_a_type_that_only_gained_support_for_rest() {
		global $wp_meta_boxes;

		require_once ABSPATH . 'wp-admin/includes/template.php';

		register_post_type( 'recipe', array( 'supports' => array( 'title' ) ) );
		add_filter(
			'undergrowth_post_types',
			static function () {
				return array( 'post', 'recipe' );
			}
		);

		undergrowth_register_stage_meta();

		$post = self::factory()->post->create_and_get( array( 'post_type' => 'recipe' ) );

		foreach ( array( 'recipe', 'post' ) as $post_type ) {
			add_meta_box( 'postcustom', 'Custom Fields', '__return_null', $post_type, 'normal', 'core' );
			do_action( 'add_meta_boxes', $post_type, $post );
		}

		$this->assertFalse( $wp_meta_boxes['recipe']['normal']['core']['postcustom'] );
		$this->assertIsArray( $wp_meta_boxes['post']['normal']['core']['postcustom'] );
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

	public function test_monthly_schedule_is_offered() {
		$schedules = wp_get_schedules();

		$this->assertArrayHasKey( 'undergrowth_monthly', $schedules );
		$this->assertSame( MONTH_IN_SECONDS, $schedules['undergrowth_monthly']['interval'] );
	}

	public function test_cycle_is_scheduled_monthly_once() {
		wp_unschedule_hook( 'undergrowth_cluster_cycle' );

		undergrowth_schedule_stage_cycle();

		$this->assertSame( 'undergrowth_monthly', wp_get_schedule( 'undergrowth_cluster_cycle' ) );
	}

	public function test_cycle_that_is_already_scheduled_is_not_scheduled_again() {
		wp_unschedule_hook( 'undergrowth_cluster_cycle' );

		// An hour ahead, so a second event would land on a different
		// timestamp instead of replacing this one.
		$next = time() + HOUR_IN_SECONDS;
		wp_schedule_event( $next, 'undergrowth_monthly', 'undergrowth_cluster_cycle' );

		undergrowth_schedule_stage_cycle();

		$events = array_filter(
			_get_cron_array(),
			static function ( $hooks ) {
				return isset( $hooks['undergrowth_cluster_cycle'] );
			}
		);
		$this->assertSame( array( $next ), array_keys( $events ) );
	}

	public function test_monthly_event_from_before_clustering_is_replaced() {
		wp_schedule_event( time(), 'undergrowth_monthly', 'undergrowth_stage_cycle' );
		wp_schedule_single_event( time(), 'undergrowth_stage_cycle', array( 42 ) );

		undergrowth_schedule_stage_cycle();

		$this->assertFalse( wp_get_schedule( 'undergrowth_stage_cycle' ) );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_stage_cycle', array( 42 ) ) );
		$this->assertSame( 'undergrowth_monthly', wp_get_schedule( 'undergrowth_cluster_cycle' ) );
	}

	public function test_cycle_is_scheduled_on_load_for_sites_that_did_not_reactivate() {
		$this->assertNotFalse( has_action( 'init', 'undergrowth_schedule_stage_cycle' ) );
	}

	public function test_activation_schedules_the_cycle() {
		wp_unschedule_hook( 'undergrowth_cluster_cycle' );

		undergrowth_activate();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_cluster_cycle' ) );
	}

	public function test_deactivation_clears_every_cycle_event() {
		undergrowth_schedule_stage_cycle();
		wp_schedule_single_event( time(), 'undergrowth_stage_cycle', array( 42 ) );
		wp_schedule_single_event( time(), 'undergrowth_cluster_cycle', array( 42 ) );
		update_option( 'undergrowth_cluster_leaders', array( 'group' => array( 42 ) ), false );

		undergrowth_deactivate();

		$this->assertFalse( wp_next_scheduled( 'undergrowth_cluster_cycle' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', array( 42 ) ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_stage_cycle', array( 42 ) ) );
		$this->assertFalse( get_option( 'undergrowth_cluster_leaders' ) );
	}

	public function test_deactivation_hook_is_registered() {
		$this->assertNotFalse( has_action( 'deactivate_' . plugin_basename( UNDERGROWTH_FILE ), 'undergrowth_deactivate' ) );
	}

	public function test_uninstall_clears_the_cycle() {
		undergrowth_schedule_stage_cycle();
		wp_schedule_single_event( time(), 'undergrowth_stage_cycle', array( 42 ) );
		update_option( 'undergrowth_cluster_leaders', array( 'group' => array( 42 ) ), false );

		$this->uninstall();

		$this->assertFalse( wp_next_scheduled( 'undergrowth_cluster_cycle' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_stage_cycle', array( 42 ) ) );
		$this->assertFalse( get_option( 'undergrowth_cluster_leaders' ) );
	}

	public function test_cycle_stores_the_measured_stage_for_every_indexed_post() {
		list( $sprout, $a, $b ) = $this->publish( 3 );
		$draft                  = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->link( $sprout, $a );
		$this->link( $b, $sprout );

		undergrowth_run_stage_cycle();

		$this->assertSame( 'sprout', get_post_meta( $sprout, 'undergrowth_stage', true ) );

		foreach ( array( $a, $b ) as $seedling ) {
			$this->assertTrue( metadata_exists( 'post', $seedling, 'undergrowth_stage' ) );
			$this->assertSame( 'seedling', get_post_meta( $seedling, 'undergrowth_stage', true ) );
		}

		$this->assertFalse( metadata_exists( 'post', $draft, 'undergrowth_stage' ) );
	}

	public function test_cycle_raises_a_stored_lower_stage() {
		list( $post_id, $a, $b ) = $this->publish( 3 );

		undergrowth_set_stage( $post_id, 'seedling' );
		$this->link( $post_id, $a );
		$this->link( $b, $post_id );

		undergrowth_run_stage_cycle();

		$this->assertSame( 'sprout', get_post_meta( $post_id, 'undergrowth_stage', true ) );
	}

	public function test_cycle_skips_pinned_and_composted_posts() {
		list( $pinned, $composted, $a, $b ) = $this->publish( 4 );

		foreach ( array( $pinned, $composted ) as $post_id ) {
			$this->link( $post_id, $a );
			$this->link( $b, $post_id );
		}

		undergrowth_set_stage( $pinned, 'seedling' );
		update_post_meta( $pinned, 'undergrowth_stage_pinned', '1' );
		undergrowth_set_stage( $composted, 'composting' );

		undergrowth_run_stage_cycle();

		$this->assertSame( 'seedling', undergrowth_get_stage( $pinned ) );
		$this->assertSame( 'composting', undergrowth_get_stage( $composted ) );
	}

	public function test_cycle_never_lowers_a_stage() {
		list( $post_id ) = $this->publish( 1 );

		undergrowth_set_stage( $post_id, 'evergreen' );

		undergrowth_run_stage_cycle();

		$this->assertSame( 'evergreen', undergrowth_get_stage( $post_id ) );
	}

	public function test_cycle_runs_in_batches_and_chains_the_next_one() {
		$posts = $this->publish( UNDERGROWTH_STAGE_BATCH + 2 );
		sort( $posts );

		undergrowth_run_stage_cycle();

		$last_in_batch = $posts[ UNDERGROWTH_STAGE_BATCH - 1 ];

		$this->assertTrue( metadata_exists( 'post', $last_in_batch, 'undergrowth_stage' ) );
		$this->assertFalse( metadata_exists( 'post', $posts[ UNDERGROWTH_STAGE_BATCH ], 'undergrowth_stage' ) );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_stage_cycle', array( $last_in_batch ) ) );

		undergrowth_run_stage_cycle( $last_in_batch );

		$this->assertTrue( metadata_exists( 'post', end( $posts ), 'undergrowth_stage' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_stage_cycle', array( end( $posts ) ) ) );
	}

	public function test_cycle_with_no_indexed_post_types_measures_nothing() {
		list( $post_id ) = $this->publish( 1 );
		add_filter( 'undergrowth_post_types', '__return_empty_array' );

		undergrowth_run_stage_cycle();

		$this->assertFalse( metadata_exists( 'post', $post_id, 'undergrowth_stage' ) );
	}

	public function test_cycle_event_runs_the_cycle() {
		list( $post_id ) = $this->publish( 1 );

		do_action( 'undergrowth_stage_cycle' );

		$this->assertTrue( metadata_exists( 'post', $post_id, 'undergrowth_stage' ) );
	}

	public function test_saving_a_post_does_not_change_its_stage() {
		list( $post_id, $a, $b ) = $this->publish( 3 );

		$this->link( $post_id, $a );
		$this->link( $b, $post_id );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'New words.',
			)
		);

		$this->assertFalse( metadata_exists( 'post', $post_id, 'undergrowth_stage' ) );
		$this->assertSame( 'seedling', undergrowth_get_stage( $post_id ) );
	}

	public function test_change_of_stage_is_recorded_with_its_reason() {
		list( $post_id ) = $this->publish( 1 );

		undergrowth_set_stage( $post_id, 'sprout' );
		undergrowth_set_stage( $post_id, 'evergreen', 'pin' );

		$log = undergrowth_stage_log( $post_id );

		$this->assertCount( 2, $log );
		$this->assertSame(
			array(
				'from'   => 'seedling',
				'to'     => 'sprout',
				'reason' => 'cycle',
			),
			array_diff_key( $log[0], array( 'time' => true ) )
		);
		$this->assertSame( 'pin', $log[1]['reason'] );
		$this->assertEqualsWithDelta( time(), $log[1]['time'], 5 );
	}

	public function test_cycle_storing_an_unchanged_stage_records_nothing() {
		list( $post_id ) = $this->publish( 1 );

		undergrowth_run_stage_cycle();

		$this->assertSame( array(), undergrowth_stage_log( $post_id ) );
	}

	public function test_author_action_is_recorded_even_without_a_change_of_stage() {
		list( $post_id ) = $this->publish( 1 );

		undergrowth_set_stage( $post_id, 'seedling', 'pin' );

		$this->assertCount( 1, undergrowth_stage_log( $post_id ) );
	}

	public function test_unknown_reason_is_refused() {
		list( $post_id ) = $this->publish( 1 );

		$this->assertFalse( undergrowth_set_stage( $post_id, 'sprout', 'magic' ) );
		$this->assertSame( 'seedling', undergrowth_get_stage( $post_id ) );
		$this->assertSame( array(), undergrowth_stage_log( $post_id ) );
	}

	public function test_damaged_log_entries_are_skipped() {
		list( $post_id ) = $this->publish( 1 );
		$valid           = array(
			'time'   => 1793491200,
			'from'   => 'seedling',
			'to'     => 'sprout',
			'reason' => 'cycle',
		);

		add_post_meta( $post_id, 'undergrowth_stage_log', 'junk' );
		add_post_meta( $post_id, 'undergrowth_stage_log', array( 'to' => 'sprout' ) );
		add_post_meta( $post_id, 'undergrowth_stage_log', array_merge( $valid, array( 'from' => array( 'seedling' ) ) ) );
		add_post_meta( $post_id, 'undergrowth_stage_log', array_merge( $valid, array( 'to' => 'wilting' ) ) );
		add_post_meta( $post_id, 'undergrowth_stage_log', array_merge( $valid, array( 'reason' => 'magic' ) ) );
		add_post_meta( $post_id, 'undergrowth_stage_log', array_merge( $valid, array( 'time' => 'soon' ) ) );
		add_post_meta( $post_id, 'undergrowth_stage_log', $valid );

		$this->assertSame( array( $valid ), undergrowth_stage_log( $post_id ) );
	}

	public function test_each_change_is_its_own_meta_row() {
		list( $post_id ) = $this->publish( 1 );

		undergrowth_set_stage( $post_id, 'sprout' );
		undergrowth_set_stage( $post_id, 'evergreen', 'pin' );

		// Separate rows are appended one insert at a time, so two writers
		// at once cannot overwrite each other's entry.
		$this->assertCount( 2, get_post_meta( $post_id, 'undergrowth_stage_log', false ) );
	}

	public function test_change_that_cannot_be_recorded_is_undone() {
		list( $post_id ) = $this->publish( 1 );
		$announced       = 0;
		add_filter(
			'add_post_metadata',
			static function ( $check, $object_id, $meta_key ) {
				return 'undergrowth_stage_log' === $meta_key ? false : $check;
			},
			10,
			3
		);
		add_action(
			'undergrowth_stage_changed',
			static function () use ( &$announced ) {
				++$announced;
			}
		);

		$this->assertFalse( undergrowth_set_stage( $post_id, 'sprout' ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, 'undergrowth_stage' ) );

		update_post_meta( $post_id, 'undergrowth_stage', 'budding' );

		$this->assertFalse( undergrowth_set_stage( $post_id, 'evergreen', 'pin' ) );
		$this->assertSame( 'budding', undergrowth_get_stage( $post_id ) );
		$this->assertSame( 0, $announced );
	}

	public function test_change_of_stage_announces_itself() {
		list( $post_id ) = $this->publish( 1 );
		$announced       = array();
		add_action(
			'undergrowth_stage_changed',
			static function ( $id ) use ( &$announced ) {
				$announced[] = $id;
			}
		);

		undergrowth_set_stage( $post_id, 'seedling' );
		undergrowth_set_stage( $post_id, 'sprout' );

		$this->assertSame( array( $post_id ), $announced );
	}

	public function test_stage_log_is_hidden_and_removed_on_uninstall() {
		list( $post_id ) = $this->publish( 1 );

		undergrowth_set_stage( $post_id, 'sprout' );

		$this->assertTrue( is_protected_meta( 'undergrowth_stage_log', 'post' ) );

		$this->uninstall();

		$this->assertFalse( metadata_exists( 'post', $post_id, 'undergrowth_stage_log' ) );
	}

	/**
	 * Logs in a user who can edit every post.
	 */
	private function log_in_editor(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	public function test_pinning_sets_the_stage_and_the_marker_at_once() {
		list( $post_id ) = $this->publish( 1 );
		$this->log_in_editor();

		$this->assertTrue( undergrowth_pin_stage( $post_id, 'evergreen' ) );
		$this->assertSame( 'evergreen', undergrowth_get_stage( $post_id ) );
		$this->assertTrue( undergrowth_is_stage_pinned( $post_id ) );

		$log = undergrowth_stage_log( $post_id );
		$this->assertSame( 'pin', end( $log )['reason'] );
	}

	public function test_cycle_leaves_a_pinned_post_alone() {
		list( $post_id, $a, $b ) = $this->publish( 3 );
		$this->log_in_editor();
		$this->link( $post_id, $a );
		$this->link( $b, $post_id );

		undergrowth_pin_stage( $post_id, 'seedling' );
		undergrowth_run_stage_cycle();

		$this->assertSame( 'seedling', undergrowth_get_stage( $post_id ) );
	}

	public function test_unpinning_measures_the_stage_at_once() {
		list( $post_id, $a, $b ) = $this->publish( 3 );
		$this->log_in_editor();
		$this->link( $post_id, $a );
		$this->link( $b, $post_id );

		undergrowth_pin_stage( $post_id, 'evergreen' );

		$this->assertTrue( undergrowth_unpin_stage( $post_id ) );
		$this->assertFalse( undergrowth_is_stage_pinned( $post_id ) );
		$this->assertSame( 'sprout', undergrowth_get_stage( $post_id ) );

		$log = undergrowth_stage_log( $post_id );
		$this->assertSame( 'unpin', end( $log )['reason'] );
	}

	public function test_unpinning_a_post_that_is_not_pinned_is_refused() {
		list( $post_id ) = $this->publish( 1 );
		$this->log_in_editor();

		$result = undergrowth_unpin_stage( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_not_pinned', $result->get_error_code() );
		$this->assertSame( array(), undergrowth_stage_log( $post_id ) );
	}

	public function test_user_who_cannot_edit_the_post_cannot_pin_or_unpin() {
		list( $post_id ) = $this->publish( 1 );
		$this->log_in_editor();
		undergrowth_pin_stage( $post_id, 'sprout' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$pin   = undergrowth_pin_stage( $post_id, 'evergreen' );
		$unpin = undergrowth_unpin_stage( $post_id );

		$this->assertWPError( $pin );
		$this->assertSame( 'undergrowth_cannot_edit', $pin->get_error_code() );
		$this->assertWPError( $unpin );
		$this->assertSame( 'sprout', undergrowth_get_stage( $post_id ) );
		$this->assertTrue( undergrowth_is_stage_pinned( $post_id ) );
	}

	/**
	 * Only the four measured stages can be pinned.
	 *
	 * @dataProvider data_unpinnable_stages
	 *
	 * @param string $stage Stage to try to pin.
	 */
	public function test_composting_and_unknown_stages_cannot_be_pinned( string $stage ) {
		list( $post_id ) = $this->publish( 1 );
		$this->log_in_editor();

		$result = undergrowth_pin_stage( $post_id, $stage );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_unknown_stage', $result->get_error_code() );
		$this->assertSame( 'seedling', undergrowth_get_stage( $post_id ) );
		$this->assertFalse( undergrowth_is_stage_pinned( $post_id ) );
	}

	/**
	 * Stages that can never be pinned.
	 *
	 * @return array<string, string[]>
	 */
	public function data_unpinnable_stages(): array {
		return array(
			'composting' => array( 'composting' ),
			'wilting'    => array( 'wilting' ),
		);
	}

	public function test_composted_post_cannot_be_pinned() {
		list( $post_id ) = $this->publish( 1 );
		$this->log_in_editor();
		undergrowth_set_stage( $post_id, 'composting', 'compost' );

		$result = undergrowth_pin_stage( $post_id, 'sprout' );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_composting', $result->get_error_code() );
		$this->assertSame( 'composting', undergrowth_get_stage( $post_id ) );
		$this->assertFalse( undergrowth_is_stage_pinned( $post_id ) );
	}

	public function test_post_outside_the_index_cannot_be_pinned() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->log_in_editor();

		$result = undergrowth_pin_stage( $post_id, 'sprout' );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_not_indexed', $result->get_error_code() );
		$this->assertFalse( metadata_exists( 'post', $post_id, 'undergrowth_stage' ) );
	}

	/**
	 * Makes every stage log write fail, as a full disk or a filter might.
	 */
	private function fail_log_writes(): void {
		add_filter(
			'add_post_metadata',
			static function ( $check, $object_id, $meta_key ) {
				return 'undergrowth_stage_log' === $meta_key ? false : $check;
			},
			10,
			3
		);
	}

	public function test_pin_that_cannot_be_saved_leaves_no_pin_behind() {
		list( $post_id ) = $this->publish( 1 );
		$this->log_in_editor();
		$this->fail_log_writes();

		$result = undergrowth_pin_stage( $post_id, 'evergreen' );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_not_saved', $result->get_error_code() );
		$this->assertFalse( undergrowth_is_stage_pinned( $post_id ) );
		$this->assertSame( 'seedling', undergrowth_get_stage( $post_id ) );
	}

	public function test_unpin_that_cannot_be_saved_keeps_the_pin() {
		list( $post_id ) = $this->publish( 1 );
		$this->log_in_editor();
		undergrowth_pin_stage( $post_id, 'evergreen' );
		$this->fail_log_writes();

		$result = undergrowth_unpin_stage( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_not_saved', $result->get_error_code() );
		$this->assertTrue( undergrowth_is_stage_pinned( $post_id ) );
		$this->assertSame( 'evergreen', undergrowth_get_stage( $post_id ) );
	}

	/**
	 * Makes a metadata write to the pin flag fail.
	 *
	 * @param string $operation 'add', 'update' or 'delete'.
	 */
	private function fail_pin_flag( string $operation ): void {
		add_filter(
			$operation . '_post_metadata',
			static function ( $check, $object_id, $meta_key ) {
				return 'undergrowth_stage_pinned' === $meta_key ? false : $check;
			},
			10,
			3
		);
	}

	public function test_pin_whose_flag_cannot_be_stored_changes_nothing() {
		list( $post_id ) = $this->publish( 1 );
		$this->log_in_editor();
		$this->fail_pin_flag( 'add' );
		$this->fail_pin_flag( 'update' );

		$result = undergrowth_pin_stage( $post_id, 'evergreen' );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_not_saved', $result->get_error_code() );
		$this->assertSame( 'seedling', undergrowth_get_stage( $post_id ) );
		$this->assertSame( array(), undergrowth_stage_log( $post_id ) );
	}

	public function test_unpin_whose_flag_cannot_be_removed_changes_nothing() {
		list( $post_id ) = $this->publish( 1 );
		$this->log_in_editor();
		undergrowth_pin_stage( $post_id, 'evergreen' );
		$this->fail_pin_flag( 'delete' );

		$result = undergrowth_unpin_stage( $post_id );

		$this->assertWPError( $result );
		$this->assertTrue( undergrowth_is_stage_pinned( $post_id ) );
		$this->assertSame( 'evergreen', undergrowth_get_stage( $post_id ) );
		$this->assertCount( 1, undergrowth_stage_log( $post_id ) );
	}

	public function test_failed_repin_keeps_the_existing_pin() {
		list( $post_id ) = $this->publish( 1 );
		$this->log_in_editor();
		undergrowth_pin_stage( $post_id, 'sprout' );
		$this->fail_log_writes();

		$result = undergrowth_pin_stage( $post_id, 'evergreen' );

		$this->assertWPError( $result );
		$this->assertTrue( undergrowth_is_stage_pinned( $post_id ) );
		$this->assertSame( 'sprout', undergrowth_get_stage( $post_id ) );
	}

	public function test_failed_pin_says_when_its_flag_could_not_be_taken_back() {
		list( $post_id ) = $this->publish( 1 );
		$this->log_in_editor();
		$this->fail_log_writes();
		$this->fail_pin_flag( 'delete' );

		$result = undergrowth_pin_stage( $post_id, 'evergreen' );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_pin_left', $result->get_error_code() );
		$this->assertStringContainsString( 'pin marker', $result->get_error_message() );
	}

	public function test_failed_unpin_says_when_its_flag_could_not_be_put_back() {
		list( $post_id ) = $this->publish( 1 );
		$this->log_in_editor();
		undergrowth_pin_stage( $post_id, 'evergreen' );
		$this->fail_log_writes();
		$this->fail_pin_flag( 'add' );
		$this->fail_pin_flag( 'update' );

		$result = undergrowth_unpin_stage( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_pin_lost', $result->get_error_code() );
		$this->assertStringContainsString( 'pin marker', $result->get_error_message() );
	}

	public function test_missing_post_cannot_be_pinned() {
		$this->log_in_editor();

		$this->assertWPError( undergrowth_pin_stage( PHP_INT_MAX, 'sprout' ) );
		$this->assertWPError( undergrowth_unpin_stage( PHP_INT_MAX ) );
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
		update_post_meta( $post_id, 'undergrowth_cluster', $post_id );

		$this->uninstall();

		$this->assertFalse( metadata_exists( 'post', $post_id, 'undergrowth_stage' ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, 'undergrowth_stage_pinned' ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, 'undergrowth_cluster' ) );
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
			foreach ( array( 'undergrowth_stage', 'undergrowth_stage_pinned', 'undergrowth_cluster' ) as $key ) {
				$this->assertContains( "SELECT meta_id FROM {$wpdb->get_blog_prefix( $site_id )}postmeta WHERE meta_key = '{$key}'", $queries );
			}
		}
	}

	public function test_post_with_no_stored_stage_is_a_seedling_without_loading_the_post() {
		$post_id = self::factory()->post->create();
		delete_post_meta( $post_id, 'undergrowth_stage' );
		clean_post_cache( $post_id );

		$this->assertSame( 'seedling', undergrowth_get_stage( $post_id ) );
		$this->assertFalse( wp_cache_get( $post_id, 'posts' ) );
	}

	public function test_checking_target_posts_reads_their_stages_in_one_query() {
		$ids = self::factory()->post->create_many( 3 );
		update_post_meta( $ids[1], 'undergrowth_stage', 'composting' );

		foreach ( $ids as $id ) {
			wp_cache_delete( $id, 'post_meta' );
		}

		$selects = array();
		$record  = static function ( $query ) use ( &$selects ) {
			if ( 1 === preg_match( '/FROM `?' . preg_quote( $GLOBALS['wpdb']->postmeta, '/' ) . '\\b/', $query ) ) {
				$selects[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $record );

		$open = undergrowth_open_target_ids( $ids );

		remove_filter( 'query', $record );
		$this->assertSame( array( $ids[0], $ids[2] ), array_keys( $open ) );
		$this->assertCount( 1, $selects );
	}

	public function test_checking_target_posts_leaves_their_other_meta_unread() {
		$ids = self::factory()->post->create_many( 2 );
		update_post_meta( $ids[0], 'undergrowth_stage', 'composting' );
		update_post_meta( $ids[1], 'builder_data', str_repeat( 'x', 1000 ) );

		foreach ( $ids as $id ) {
			wp_cache_delete( $id, 'post_meta' );
		}

		$this->assertSame( array( $ids[1] ), array_keys( undergrowth_open_target_ids( $ids ) ) );

		foreach ( $ids as $id ) {
			$this->assertFalse( wp_cache_get( $id, 'post_meta' ) );
		}
	}
}
