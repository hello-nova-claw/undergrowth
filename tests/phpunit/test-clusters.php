<?php
/**
 * Tests for clustering posts by their vectors.
 *
 * @package Undergrowth
 */

/**
 * Clusters.
 */
class Test_Clusters extends WP_UnitTestCase {

	/**
	 * Publishes a post and stores a vector for it.
	 *
	 * @param float[]              $vector Vector to store.
	 * @param array<string, mixed> $meta   Provider, model or language to use instead of the defaults.
	 * @return int Post ID.
	 */
	private function post_with_vector( array $vector, array $meta = array() ): int {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		undergrowth_save_vector(
			$post_id,
			$vector,
			array_merge(
				array(
					'provider'     => 'test-provider',
					'model'        => 'test-model',
					'language'     => 'en_US',
					'content_hash' => 'hash',
				),
				$meta
			)
		);

		return $post_id;
	}

	/**
	 * Runs every batch of a clustering pass, as cron would.
	 */
	private function cluster(): void {
		$after_id = 0;

		do {
			undergrowth_run_cluster_cycle( $after_id );
			$after_id = $this->take_next_batch();
		} while ( null !== $after_id );
	}

	/**
	 * Unschedules the next clustering batch, if one is waiting.
	 *
	 * @return int|null Post ID the batch starts after, or null when none is waiting.
	 */
	private function take_next_batch(): ?int {
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( $hooks['undergrowth_cluster_cycle'] ?? array() as $event ) {
				if ( array() !== $event['args'] ) {
					wp_clear_scheduled_hook( 'undergrowth_cluster_cycle', $event['args'] );
					return (int) $event['args'][0];
				}
			}
		}

		return null;
	}

	/**
	 * Makes a pass's record look as if its last batch started long ago.
	 *
	 * @param string $hook Cron hook of the pass.
	 */
	private function age_pass( string $hook ): void {
		$pass        = get_option( $hook . '_pass' );
		$pass['at'] -= UNDERGROWTH_PASS_STALL;

		update_option( $hook . '_pass', $pass, false );
	}

	/**
	 * Runs every due cron event, oldest first, the way wp-cron.php does,
	 * until none is due.
	 *
	 * @return string[] Hooks that ran, in order.
	 */
	private function run_due_events(): array {
		$ran = array();

		// Enough for any pass these tests plant; a chain that never ends fails.
		for ( $guard = 0; $guard <= 100; $guard++ ) {
			$due = null;

			foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
				if ( $timestamp > time() ) {
					break;
				}

				foreach ( $hooks as $hook => $events ) {
					$due = array( $timestamp, $hook, reset( $events ) );
					break 2;
				}
			}

			if ( null === $due ) {
				return $ran;
			}

			if ( 100 === $guard ) {
				break;
			}

			list( $timestamp, $hook, $event ) = $due;

			if ( false !== $event['schedule'] ) {
				wp_reschedule_event( $timestamp, $event['schedule'], $hook, $event['args'] );
			}

			wp_unschedule_event( $timestamp, $hook, $event['args'] );
			do_action_ref_array( $hook, $event['args'] );
			$ran[] = $hook;
		}

		$this->fail( 'More than 100 cron events came due; the last ran were ' . implode( ', ', array_slice( $ran, -5 ) ) );
	}

	/**
	 * Unschedules the next clustering batch, if one is waiting, keeping all
	 * its arguments.
	 *
	 * @return array|null The batch's arguments, or null when none is waiting.
	 */
	private function take_next_batch_args(): ?array {
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( $hooks['undergrowth_cluster_cycle'] ?? array() as $event ) {
				if ( array() !== $event['args'] ) {
					wp_clear_scheduled_hook( 'undergrowth_cluster_cycle', $event['args'] );
					return $event['args'];
				}
			}
		}

		return null;
	}

	/**
	 * Returns the event arguments of the current pass's batch after a post.
	 *
	 * @param int $after_id Post ID the batch starts after.
	 * @return array Event arguments.
	 */
	private function batch_args( int $after_id ): array {
		return undergrowth_pass_args( $after_id, undergrowth_current_pass( 'undergrowth_cluster_cycle' ) );
	}

	/**
	 * Builds a unit vector along one axis.
	 *
	 * @param int $axis       Axis to point along.
	 * @param int $dimensions Length of the vector.
	 * @return float[] The vector.
	 */
	private function axis( int $axis, int $dimensions ): array {
		$vector          = array_fill( 0, $dimensions, 0.0 );
		$vector[ $axis ] = 1.0;

		return $vector;
	}

	public function test_similar_posts_form_one_cluster() {
		$a     = $this->post_with_vector( array( 1.0, 0.0, 0.0 ) );
		$b     = $this->post_with_vector( array( 0.9, 0.1, 0.0 ) );
		$c     = $this->post_with_vector( array( 0.8, 0.0, 0.2 ) );
		$apart = $this->post_with_vector( array( 0.0, 1.0, 0.0 ) );

		$this->cluster();

		foreach ( array( $a, $b, $c ) as $post_id ) {
			$this->assertSame( array( $a, $b, $c ), undergrowth_cluster_members( $post_id ) );
			$this->assertSame( $a, undergrowth_cluster_leader( $post_id ) );
		}

		$this->assertSame( array(), undergrowth_cluster_members( $apart ) );
	}

	public function test_fewer_than_three_posts_are_no_cluster() {
		$a = $this->post_with_vector( array( 1.0, 0.0 ) );
		$b = $this->post_with_vector( array( 0.9, 0.1 ) );

		$this->cluster();

		$this->assertSame( 3, UNDERGROWTH_MIN_CLUSTER_SIZE );
		$this->assertSame( $a, undergrowth_cluster_leader( $b ) );
		$this->assertSame( array(), undergrowth_cluster_members( $a ) );
		$this->assertSame( array(), undergrowth_cluster_members( $b ) );
	}

	public function test_post_that_was_never_clustered_has_no_cluster() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( 0, undergrowth_cluster_leader( $post_id ) );
		$this->assertSame( array(), undergrowth_cluster_members( $post_id ) );
	}

	public function test_post_joins_the_more_similar_of_two_leaders() {
		$a = $this->post_with_vector( array( 1.0, 0.0 ) );
		$b = $this->post_with_vector( array( 0.0, 1.0 ) );
		$c = $this->post_with_vector( array( 0.6, 0.8 ) );

		$this->cluster();

		$this->assertSame( $b, undergrowth_cluster_leader( $c ) );
	}

	public function test_tie_between_leaders_goes_to_the_older_one() {
		$a = $this->post_with_vector( array( 1.0, 0.0 ) );
		$b = $this->post_with_vector( array( 0.0, 1.0 ) );
		$c = $this->post_with_vector( array( 1.0, 1.0 ) );

		$this->cluster();

		$this->assertSame( $b, undergrowth_cluster_leader( $b ) );
		$this->assertSame( $a, undergrowth_cluster_leader( $c ) );
	}

	public function test_clustering_twice_gives_the_same_clusters() {
		$posts = array();

		foreach ( array( array( 1.0, 0.1 ), array( 0.2, 1.0 ), array( 0.9, 0.3 ), array( 0.1, 0.9 ), array( 0.7, 0.7 ) ) as $vector ) {
			$posts[] = $this->post_with_vector( $vector );
		}

		$this->cluster();
		$first = array_map( 'undergrowth_cluster_leader', $posts );

		$this->cluster();

		$this->assertSame( $first, array_map( 'undergrowth_cluster_leader', $posts ) );
	}

	/**
	 * Identical vectors stay apart when any part of where they came from differs.
	 *
	 * @dataProvider data_identity_fields
	 *
	 * @param string $field Provider, model or language.
	 */
	public function test_vectors_from_another_provider_model_or_language_never_share_a_cluster( string $field ) {
		$a     = $this->post_with_vector( array( 1.0, 0.0 ) );
		$b     = $this->post_with_vector( array( 1.0, 0.0 ) );
		$other = $this->post_with_vector( array( 1.0, 0.0 ), array( $field => 'other' ) );

		$this->cluster();

		$this->assertSame( $a, undergrowth_cluster_leader( $b ) );
		$this->assertSame( $other, undergrowth_cluster_leader( $other ) );
	}

	/**
	 * Fields that identify where a vector came from.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_identity_fields(): array {
		return array(
			'provider' => array( 'provider' ),
			'model'    => array( 'model' ),
			'language' => array( 'language' ),
		);
	}

	public function test_vectors_of_another_length_never_share_a_cluster() {
		$a     = $this->post_with_vector( array( 1.0, 0.0 ) );
		$other = $this->post_with_vector( array( 1.0, 0.0, 0.0 ) );

		$this->cluster();

		$this->assertSame( $a, undergrowth_cluster_leader( $a ) );
		$this->assertSame( $other, undergrowth_cluster_leader( $other ) );
	}

	public function test_composted_posts_are_in_no_cluster() {
		$a         = $this->post_with_vector( array( 1.0, 0.0 ) );
		$composted = $this->post_with_vector( array( 1.0, 0.0 ) );

		undergrowth_set_stage( $composted, 'composting', 'compost' );

		$this->cluster();

		$this->assertSame( $a, undergrowth_cluster_leader( $a ) );
		$this->assertSame( 0, undergrowth_cluster_leader( $composted ) );
	}

	public function test_posts_outside_the_index_are_in_no_cluster() {
		$a     = $this->post_with_vector( array( 1.0, 0.0 ) );
		$draft = $this->post_with_vector( array( 1.0, 0.0 ) );

		// Straight to the database, so the vector stays behind as it would
		// if the post were unpublished between two batches.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $draft ) );
		clean_post_cache( $draft );

		$this->cluster();

		$this->assertSame( $a, undergrowth_cluster_leader( $a ) );
		$this->assertSame( 0, undergrowth_cluster_leader( $draft ) );
	}

	public function test_post_leaving_the_index_loses_its_cluster_at_once() {
		$post_id = $this->post_with_vector( array( 1.0, 0.0 ) );

		$this->cluster();
		undergrowth_remove_from_index( $post_id );

		$this->assertFalse( metadata_exists( 'post', $post_id, 'undergrowth_cluster' ) );
	}

	public function test_composting_a_post_takes_it_out_of_its_cluster_at_once() {
		$post_id = $this->post_with_vector( array( 1.0, 0.0 ) );

		$this->cluster();
		undergrowth_set_stage( $post_id, 'composting', 'compost' );

		$this->assertFalse( metadata_exists( 'post', $post_id, 'undergrowth_cluster' ) );
	}

	public function test_composting_a_leader_leaves_the_rest_of_its_cluster_in_place() {
		$leader = $this->post_with_vector( array( 1.0, 0.0 ) );
		$a      = $this->post_with_vector( array( 0.9, 0.1 ) );
		$b      = $this->post_with_vector( array( 0.8, 0.2 ) );
		$c      = $this->post_with_vector( array( 0.9, 0.2 ) );

		$this->cluster();
		undergrowth_set_stage( $leader, 'composting', 'compost' );

		$this->assertSame( array(), undergrowth_cluster_members( $leader ) );

		foreach ( array( $a, $b, $c ) as $post_id ) {
			$this->assertSame( array( $a, $b, $c ), undergrowth_cluster_members( $post_id ) );
		}
	}

	public function test_cluster_that_composting_leaves_with_two_posts_is_no_cluster() {
		$leader = $this->post_with_vector( array( 1.0, 0.0 ) );
		$a      = $this->post_with_vector( array( 0.9, 0.1 ) );
		$b      = $this->post_with_vector( array( 0.8, 0.2 ) );

		$this->cluster();
		undergrowth_set_stage( $leader, 'composting', 'compost' );

		$this->assertSame( array(), undergrowth_cluster_members( $a ) );
		$this->assertSame( array(), undergrowth_cluster_members( $b ) );
	}

	public function test_other_stage_changes_keep_the_cluster() {
		$post_id = $this->post_with_vector( array( 1.0, 0.0 ) );

		$this->cluster();
		undergrowth_set_stage( $post_id, 'sprout' );

		$this->assertSame( $post_id, undergrowth_cluster_leader( $post_id ) );
	}

	public function test_post_with_no_magnitude_is_in_no_cluster() {
		$a    = $this->post_with_vector( array( 1.0, 0.0 ) );
		$zero = $this->post_with_vector( array( 0.0, 0.0 ) );

		$this->cluster();

		$this->assertSame( $a, undergrowth_cluster_leader( $a ) );
		$this->assertSame( 0, undergrowth_cluster_leader( $zero ) );
	}

	public function test_new_pass_forgets_last_months_clusters() {
		$post_id = $this->post_with_vector( array( 1.0, 0.0 ) );
		$gone    = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		update_post_meta( $gone, 'undergrowth_cluster', $post_id );

		$this->cluster();

		$this->assertFalse( metadata_exists( 'post', $gone, 'undergrowth_cluster' ) );
	}

	public function test_minimum_similarity_defaults_to_the_constant() {
		$this->assertSame( 0.5, UNDERGROWTH_MIN_CLUSTER_SIMILARITY );
		$this->assertSame( UNDERGROWTH_MIN_CLUSTER_SIMILARITY, undergrowth_min_cluster_similarity() );
	}

	public function test_post_just_below_the_minimum_similarity_starts_its_own_cluster() {
		$a = $this->post_with_vector( array( 1.0, 0.0 ) );
		// Cosine 0.49 against the first post.
		$b = $this->post_with_vector( array( 0.49, sqrt( 1 - 0.49 * 0.49 ) ) );

		$this->cluster();

		$this->assertSame( $b, undergrowth_cluster_leader( $b ) );
	}

	public function test_minimum_similarity_can_be_filtered() {
		$a = $this->post_with_vector( array( 1.0, 0.0 ) );
		$b = $this->post_with_vector( array( 0.8, 0.6 ) );

		add_filter(
			'undergrowth_min_cluster_similarity',
			static function () {
				return 0.9;
			}
		);

		$this->cluster();

		$this->assertSame( $b, undergrowth_cluster_leader( $b ) );
	}

	/**
	 * A filtered similarity outside -1 to 1, or not a number, is corrected.
	 *
	 * @dataProvider data_filtered_similarities
	 *
	 * @param mixed $filtered Value the filter returns.
	 * @param float $expected Similarity used.
	 */
	public function test_filtered_minimum_similarity_is_kept_in_range( $filtered, float $expected ) {
		add_filter(
			'undergrowth_min_cluster_similarity',
			static function () use ( $filtered ) {
				return $filtered;
			}
		);

		$this->assertSame( $expected, undergrowth_min_cluster_similarity() );
	}

	/**
	 * Filtered values and the similarity each one gives.
	 *
	 * @return array<string, array{mixed, float}>
	 */
	public function data_filtered_similarities(): array {
		return array(
			'above one'   => array( 5, 1.0 ),
			'below minus' => array( -3, -1.0 ),
			'numeric'     => array( '0.7', 0.7 ),
			'not numeric' => array( 'close', UNDERGROWTH_MIN_CLUSTER_SIMILARITY ),
		);
	}

	public function test_clustering_runs_in_batches_and_chains_the_next_one() {
		$posts = array();

		for ( $i = 0; $i < UNDERGROWTH_CLUSTER_BATCH + 2; $i++ ) {
			$posts[] = $this->post_with_vector( array( 1.0, 0.0 ) );
		}

		undergrowth_run_cluster_cycle();

		$last_in_batch = $posts[ UNDERGROWTH_CLUSTER_BATCH - 1 ];

		$this->assertSame( $posts[0], undergrowth_cluster_leader( $last_in_batch ) );
		$this->assertSame( 0, undergrowth_cluster_leader( $posts[ UNDERGROWTH_CLUSTER_BATCH ] ) );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', $this->batch_args( $last_in_batch ) ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_stage_cycle', array( 0 ) ) );

		undergrowth_run_cluster_cycle( $last_in_batch );

		// The leader chosen in the first request is still known in the second.
		$this->assertSame( $posts[0], undergrowth_cluster_leader( end( $posts ) ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', $this->batch_args( end( $posts ) ) ) );
	}

	public function test_last_batch_starts_the_stage_cycle_and_forgets_its_leaders() {
		$this->post_with_vector( array( 1.0, 0.0 ) );

		undergrowth_run_cluster_cycle();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_stage_cycle', array( 0 ) ) );
		$this->assertFalse( get_option( 'undergrowth_cluster_leaders' ) );
	}

	public function test_threshold_read_when_the_pass_starts_holds_for_every_batch() {
		$identity = static function () {
			return array(
				'provider' => 'test-provider',
				'model'    => 'test-model',
			);
		};
		$measured = static function () {
			return array(
				'provider' => 'test-provider',
				'model'    => 'test-model',
				'posts'    => UNDERGROWTH_DERIVED_MIN_POSTS,
				'cluster'  => 0.9,
			);
		};
		$posts    = array();

		add_filter( 'undergrowth_embed_identity', $identity );
		add_filter( 'pre_option_undergrowth_scores', $measured );

		for ( $i = 0; $i < UNDERGROWTH_CLUSTER_BATCH; $i++ ) {
			$posts[] = $this->post_with_vector( array( 1.0, 0.0 ) );
		}

		// 0.8 from the first post: in its cluster at the fixed 0.5, not at the measured 0.9.
		$later = $this->post_with_vector( array( 0.8, 0.6 ) );

		undergrowth_run_cluster_cycle();

		// A post leaving the index between batches can forget the scores.
		remove_filter( 'pre_option_undergrowth_scores', $measured );
		delete_option( 'undergrowth_scores' );
		$this->assertSame( UNDERGROWTH_MIN_CLUSTER_SIMILARITY, undergrowth_min_cluster_similarity() );

		undergrowth_run_cluster_cycle( $this->take_next_batch() );

		$this->assertSame( $posts[0], undergrowth_cluster_leader( end( $posts ) ) );
		$this->assertSame( $later, undergrowth_cluster_leader( $later ) );
		$this->assertFalse( get_option( 'undergrowth_cluster_similarity' ) );
	}

	public function test_pass_whose_next_batch_is_lost_is_resumed_by_the_hourly_check() {
		$posts = array();

		for ( $i = 0; $i < UNDERGROWTH_CLUSTER_BATCH + 2; $i++ ) {
			$posts[] = $this->post_with_vector( array( 1.0, 0.0 ) );
		}

		undergrowth_run_cluster_cycle();
		$last_in_batch = $this->take_next_batch();
		$this->age_pass( 'undergrowth_cluster_cycle' );

		do_action( 'undergrowth_backfill_check' );

		$this->assertSame( $posts[ UNDERGROWTH_CLUSTER_BATCH - 1 ], $last_in_batch );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', $this->batch_args( $last_in_batch ) ) );

		$this->assertSame( $last_in_batch, $this->take_next_batch() );
		undergrowth_run_cluster_cycle( $last_in_batch );

		$this->assertSame( $posts[0], undergrowth_cluster_leader( end( $posts ) ) );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_stage_cycle', array( 0 ) ) );
		$this->assertFalse( get_option( 'undergrowth_cluster_cycle_pass' ) );
		$this->assertFalse( get_option( 'undergrowth_cluster_leaders' ) );
	}

	public function test_pass_whose_batch_may_still_be_running_is_not_restarted() {
		for ( $i = 0; $i < UNDERGROWTH_CLUSTER_BATCH + 2; $i++ ) {
			$this->post_with_vector( array( 1.0, 0.0 ) );
		}

		undergrowth_run_cluster_cycle();
		$last_in_batch = $this->take_next_batch();

		do_action( 'undergrowth_backfill_check' );

		$this->assertFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', $this->batch_args( $last_in_batch ) ) );
	}

	public function test_batch_that_started_well_within_the_stall_age_is_not_restarted() {
		for ( $i = 0; $i < UNDERGROWTH_CLUSTER_BATCH + 2; $i++ ) {
			$this->post_with_vector( array( 1.0, 0.0 ) );
		}

		undergrowth_run_cluster_cycle();
		$last_in_batch = $this->take_next_batch();
		$pass          = get_option( 'undergrowth_cluster_cycle_pass' );
		$pass['at']   -= 30 * MINUTE_IN_SECONDS;
		update_option( 'undergrowth_cluster_cycle_pass', $pass, false );

		do_action( 'undergrowth_backfill_check' );

		$this->assertFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', $this->batch_args( $last_in_batch ) ) );
	}

	public function test_slow_batch_queued_again_while_it_runs_leaves_the_pass_to_the_new_run() {
		$posts = array();

		for ( $i = 0; $i < UNDERGROWTH_CLUSTER_BATCH + 2; $i++ ) {
			$posts[] = $this->post_with_vector( array( 1.0, 0.0 ) );
		}

		// As if the hourly check took this run for dead while it compared posts.
		$resume = static function ( $similarity ) {
			undergrowth_queue_pass_batch( 'undergrowth_cluster_cycle', 0 );
			return $similarity;
		};
		add_filter( 'undergrowth_min_cluster_similarity', $resume );

		undergrowth_run_cluster_cycle();

		$this->assertFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', $this->batch_args( $posts[ UNDERGROWTH_CLUSTER_BATCH - 1 ] ) ) );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', array( 0 ) ) );
		$this->assertSame( array(), get_option( 'undergrowth_cluster_leaders' ) );
		$this->assertSame( 0, get_option( 'undergrowth_cluster_cycle_pass' )['after'] );
	}

	public function test_pass_with_its_next_batch_queued_is_left_alone() {
		for ( $i = 0; $i < UNDERGROWTH_CLUSTER_BATCH + 2; $i++ ) {
			$this->post_with_vector( array( 1.0, 0.0 ) );
		}

		undergrowth_run_cluster_cycle();
		$this->age_pass( 'undergrowth_cluster_cycle' );
		$pass = get_option( 'undergrowth_cluster_cycle_pass' );

		do_action( 'undergrowth_backfill_check' );

		$this->assertSame( $pass, get_option( 'undergrowth_cluster_cycle_pass' ) );
	}

	public function test_new_pass_queued_from_the_first_post_is_not_interleaved_with_a_stalled_one() {
		for ( $i = 0; $i < UNDERGROWTH_CLUSTER_BATCH + 2; $i++ ) {
			$this->post_with_vector( array( 1.0, 0.0 ) );
		}

		undergrowth_run_cluster_cycle();
		$last_in_batch = $this->take_next_batch();
		$this->age_pass( 'undergrowth_cluster_cycle' );
		wp_schedule_single_event( time(), 'undergrowth_cluster_cycle', array( 0 ) );

		do_action( 'undergrowth_backfill_check' );

		$this->assertFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', $this->batch_args( $last_in_batch ) ) );
	}

	public function test_last_batch_that_dies_before_it_finishes_runs_again_with_its_leaders() {
		$posts = array(
			$this->post_with_vector( array( 1.0, 0.0 ) ),
			$this->post_with_vector( array( 1.0, 0.0 ) ),
			$this->post_with_vector( array( 1.0, 0.0 ) ),
		);
		$die   = static function () {
			throw new RuntimeException( 'Timed out.' );
		};

		add_filter( 'pre_option_undergrowth_previous_clusters', $die );

		try {
			undergrowth_run_cluster_cycle();
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		remove_filter( 'pre_option_undergrowth_previous_clusters', $die );

		$this->assertSame( array( 'after' => 0 ), array_intersect_key( get_option( 'undergrowth_cluster_cycle_pass' ), array( 'after' => true ) ) );
		$this->assertNotFalse( get_option( 'undergrowth_cluster_leaders' ) );

		$this->age_pass( 'undergrowth_cluster_cycle' );
		do_action( 'undergrowth_backfill_check' );
		$recovered = $this->take_next_batch_args();

		// The batch run again keeps the pass it belonged to.
		$this->assertSame( 0, $recovered[0] );
		$this->assertSame( undergrowth_current_pass( 'undergrowth_cluster_cycle' ), $recovered[1] );
		do_action_ref_array( 'undergrowth_cluster_cycle', $recovered );

		$this->assertSame( $posts[0], undergrowth_cluster_leader( $posts[2] ) );
		$this->assertNotFalse( get_option( 'undergrowth_previous_clusters' ) );
		$this->assertFalse( get_option( 'undergrowth_cluster_cycle_pass' ) );
	}

	public function test_stage_cycle_lost_after_the_last_batch_is_queued_again() {
		$this->post_with_vector( array( 1.0, 0.0 ) );

		undergrowth_run_cluster_cycle();
		wp_clear_scheduled_hook( 'undergrowth_stage_cycle', array( 0 ) );
		$this->age_pass( 'undergrowth_stage_cycle' );

		do_action( 'undergrowth_backfill_check' );

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_stage_cycle', array( 0 ) ) );
	}

	public function test_monthly_event_drives_the_whole_chain_through_wp_cron() {
		// Last month's pass: one cluster, and a composting post on its own.
		$old = $this->post_with_vector( array( 1.0, 0.0, 0.0 ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->post_with_vector( array( 0.0, 1.0, 0.0 ) );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( undergrowth_compost_post( $old ) );
		_set_cron_array( array() );
		wp_schedule_event( time(), 'undergrowth_monthly', 'undergrowth_cluster_cycle' );
		$this->run_due_events();

		// This month: a new cluster near the composting post, and enough
		// other posts for clustering to take two batches.
		$near = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$near[] = $this->post_with_vector( array( 0.95, 0.0, 0.05 ) );
		}

		for ( $i = 0; $i < UNDERGROWTH_CLUSTER_BATCH; $i++ ) {
			$this->post_with_vector( array( 0.0, 0.0, 1.0 ) );
		}

		$monthly = wp_next_scheduled( 'undergrowth_cluster_cycle' );
		_set_cron_array( array() );
		wp_schedule_event( time(), 'undergrowth_monthly', 'undergrowth_cluster_cycle' );

		// Whether the first batch, which is not the last, queued the stage cycle.
		$early = null;
		$watch = static function () use ( &$early ) {
			$early = $early ?? wp_next_scheduled( 'undergrowth_stage_cycle', array( 0 ) );
		};
		add_action( 'undergrowth_cluster_cycle', $watch, 99 );

		$ran = $this->run_due_events();

		remove_action( 'undergrowth_cluster_cycle', $watch, 99 );

		$this->assertGreaterThan( time(), $monthly );
		$this->assertSame( 'undergrowth_cluster_cycle', $ran[0] );
		$this->assertGreaterThanOrEqual( 2, count( array_keys( $ran, 'undergrowth_cluster_cycle', true ) ) );
		$this->assertFalse( $early, 'The first clustering batch queued the stage cycle.' );
		$this->assertContains( 'undergrowth_stage_cycle', $ran );
		$this->assertGreaterThan(
			max( array_keys( $ran, 'undergrowth_cluster_cycle', true ) ),
			min( array_keys( $ran, 'undergrowth_stage_cycle', true ) ),
			'The stage cycle ran before clustering finished.'
		);
		$this->assertSame( $near[0], undergrowth_cluster_leader( end( $near ) ) );
		$this->assertTrue( metadata_exists( 'post', end( $near ), 'undergrowth_stage' ) );
		$this->assertSame( $near[0], undergrowth_resurfacing_signal( $old ) );
		$this->assertFalse( get_option( 'undergrowth_cluster_cycle_pass' ) );
		$this->assertFalse( get_option( 'undergrowth_stage_cycle_pass' ) );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_cluster_cycle' ) );
	}

	public function test_second_pass_started_while_one_runs_replaces_it() {
		$posts = array();

		for ( $i = 0; $i < UNDERGROWTH_CLUSTER_BATCH + 2; $i++ ) {
			$posts[] = $this->post_with_vector( array( 1.0, 0.0 ) );
		}

		// Pass A's first batch, then pass B starts, as when a backfill ends
		// while the monthly pass is still chaining batches.
		undergrowth_run_cluster_cycle();
		$pass_a = $this->take_next_batch_args();
		undergrowth_run_cluster_cycle( 0 );
		$pass_b  = $this->take_next_batch_args();
		$leaders = get_option( 'undergrowth_cluster_leaders' );

		do_action_ref_array( 'undergrowth_cluster_cycle', $pass_a );

		// A's batch changed nothing and queued nothing.
		$this->assertCount( 2, $pass_a );
		$this->assertNotSame( $pass_a[1], $pass_b[1] );
		$this->assertSame( $leaders, get_option( 'undergrowth_cluster_leaders' ) );
		$this->assertSame( $pass_b[1], get_option( 'undergrowth_cluster_cycle_pass' )['pass'] );
		$this->assertSame( 0, undergrowth_cluster_leader( end( $posts ) ) );
		$this->assertNull( $this->take_next_batch_args() );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_stage_cycle', array( 0 ) ) );
		$this->assertFalse( get_option( 'undergrowth_previous_clusters' ) );

		do_action_ref_array( 'undergrowth_cluster_cycle', $pass_b );

		// Only B finishes: its clusters, its snapshot, its stage cycle.
		$this->assertSame( $posts[0], undergrowth_cluster_leader( end( $posts ) ) );
		$this->assertNotFalse( get_option( 'undergrowth_previous_clusters' ) );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_stage_cycle', array( 0 ) ) );
		$this->assertFalse( get_option( 'undergrowth_cluster_cycle_pass' ) );
	}

	public function test_pass_started_while_a_batch_runs_waits_for_it_then_replaces_the_pass() {
		$posts = array();

		for ( $i = 0; $i < UNDERGROWTH_CLUSTER_BATCH + 2; $i++ ) {
			$posts[] = $this->post_with_vector( array( 1.0, 0.0 ) );
		}

		undergrowth_run_cluster_cycle();
		$pass_a = $this->take_next_batch_args();

		// Pass B is started while A's next batch is reading its first post.
		$started = false;
		$start_b = static function ( $types ) use ( &$started ) {
			if ( ! $started ) {
				$started = true;
				undergrowth_run_cluster_cycle( 0 );
			}

			return $types;
		};
		add_filter( 'undergrowth_post_types', $start_b );

		do_action_ref_array( 'undergrowth_cluster_cycle', $pass_a );

		remove_filter( 'undergrowth_post_types', $start_b );

		// A's batch ran undisturbed, and B waits a minute for the lock.
		$this->assertTrue( $started );
		$this->assertSame( $posts[0], undergrowth_cluster_leader( end( $posts ) ) );
		$this->assertGreaterThan( time(), wp_next_scheduled( 'undergrowth_cluster_cycle', array( 0 ) ) );

		wp_clear_scheduled_hook( 'undergrowth_cluster_cycle', array( 0 ) );
		undergrowth_run_cluster_cycle( 0 );

		$pass_b = undergrowth_current_pass( 'undergrowth_cluster_cycle' );

		$this->assertNotSame( '', $pass_b );
		$this->assertNotSame( $pass_a[1], $pass_b );
	}

	public function test_batch_that_finds_the_lock_taken_tries_again_in_a_minute() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$post_id = $this->post_with_vector( array( 1.0, 0.0 ) );
		WP_Upgrader::create_lock( 'undergrowth_cluster_cycle', HOUR_IN_SECONDS );

		undergrowth_run_cluster_cycle( 0 );

		WP_Upgrader::release_lock( 'undergrowth_cluster_cycle' );

		$this->assertSame( 0, undergrowth_cluster_leader( $post_id ) );
		$this->assertFalse( get_option( 'undergrowth_cluster_cycle_pass' ) );
		$this->assertGreaterThan( time(), wp_next_scheduled( 'undergrowth_cluster_cycle', array( 0 ) ) );
	}

	public function test_first_batch_queued_again_for_a_replaced_pass_changes_nothing() {
		$post_id = $this->post_with_vector( array( 1.0, 0.0 ) );
		update_post_meta( $post_id, 'undergrowth_cluster', $post_id );
		undergrowth_mark_pass( 'undergrowth_cluster_cycle', 0, 'pass-b' );

		undergrowth_run_cluster_cycle( 0, 'pass-a' );

		$this->assertSame( $post_id, undergrowth_cluster_leader( $post_id ) );
		$this->assertSame( 'pass-b', undergrowth_current_pass( 'undergrowth_cluster_cycle' ) );
	}

	public function test_resumed_batch_keeps_its_pass() {
		for ( $i = 0; $i < UNDERGROWTH_CLUSTER_BATCH + 2; $i++ ) {
			$this->post_with_vector( array( 1.0, 0.0 ) );
		}

		undergrowth_run_cluster_cycle();
		$lost = $this->take_next_batch_args();
		$this->age_pass( 'undergrowth_cluster_cycle' );

		do_action( 'undergrowth_backfill_check' );

		$this->assertSame( $lost, $this->take_next_batch_args() );
	}

	public function test_batch_leaves_no_rows_in_wpdb() {
		global $wpdb;

		$this->post_with_vector( array( 1.0, 0.0 ) );

		undergrowth_run_cluster_cycle();

		$this->assertSame( array(), $wpdb->last_result );
	}

	public function test_no_new_cluster_starts_once_the_cap_is_reached() {
		$dimensions = UNDERGROWTH_MAX_CLUSTERS + 1;
		$leaders    = array();

		for ( $i = 0; $i < UNDERGROWTH_MAX_CLUSTERS; $i++ ) {
			$leaders[] = $this->post_with_vector( $this->axis( $i, $dimensions ) );
		}

		$over   = $this->post_with_vector( $this->axis( UNDERGROWTH_MAX_CLUSTERS, $dimensions ) );
		$joiner = $this->post_with_vector( $this->axis( 0, $dimensions ) );

		$this->cluster();

		$this->assertSame( end( $leaders ), undergrowth_cluster_leader( end( $leaders ) ) );
		$this->assertSame( 0, undergrowth_cluster_leader( $over ) );
		$this->assertSame( $leaders[0], undergrowth_cluster_leader( $joiner ) );
	}

	public function test_cluster_event_runs_the_pass() {
		$post_id = $this->post_with_vector( array( 1.0, 0.0 ) );

		do_action( 'undergrowth_cluster_cycle' );

		$this->assertSame( $post_id, undergrowth_cluster_leader( $post_id ) );
	}

	public function test_cluster_key_is_hidden_from_the_custom_fields_box() {
		$this->assertTrue( is_protected_meta( 'undergrowth_cluster', 'post' ) );
	}
}
