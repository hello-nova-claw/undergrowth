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
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', array( $last_in_batch ) ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_stage_cycle', array( 0 ) ) );

		undergrowth_run_cluster_cycle( $last_in_batch );

		// The leader chosen in the first request is still known in the second.
		$this->assertSame( $posts[0], undergrowth_cluster_leader( end( $posts ) ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', array( end( $posts ) ) ) );
	}

	public function test_last_batch_starts_the_stage_cycle_and_forgets_its_leaders() {
		$this->post_with_vector( array( 1.0, 0.0 ) );

		undergrowth_run_cluster_cycle();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_stage_cycle', array( 0 ) ) );
		$this->assertFalse( get_option( 'undergrowth_cluster_leaders' ) );
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
