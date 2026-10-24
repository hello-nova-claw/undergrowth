<?php
/**
 * Tests for thresholds derived from the site's own scores.
 *
 * @package Undergrowth
 */

/**
 * Link, cluster and gap thresholds that follow how a site's posts score.
 */
class Test_Thresholds extends WP_UnitTestCase {

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		delete_option( 'undergrowth_scores' );
	}

	/**
	 * Plants posts in groups of identical vectors, every group equally far from the others.
	 *
	 * @param int $groups Number of groups.
	 * @param int $size   Posts in each group.
	 * @return int[] Post IDs, group by group.
	 */
	private function plant_groups( int $groups, int $size ): array {
		$ids = array();

		for ( $g = 0; $g < $groups; $g++ ) {
			$vector       = array_fill( 0, $groups, 0.5 );
			$vector[ $g ] = 1.5;

			for ( $i = 0; $i < $size; $i++ ) {
				$ids[] = $this->plant( "Group {$g} post {$i}", $vector );
			}
		}

		return $ids;
	}

	/**
	 * Works out the pairwise scores of some posts the way the sample does.
	 *
	 * @param int[] $ids Post IDs.
	 * @return float[] Scores, lowest first.
	 */
	private function pairwise_scores( array $ids ): array {
		$packed = array();

		foreach ( $ids as $id ) {
			$packed[] = undergrowth_unit_vector( undergrowth_get_vector( $id )['vector'] );
		}

		return undergrowth_sample_scores( array( $packed ), PHP_INT_MAX );
	}

	/**
	 * Treats one provider as one whose thresholds stay fixed.
	 *
	 * @param string[] $providers Provider names.
	 */
	private function fixed_providers( array $providers ): void {
		add_filter(
			'undergrowth_fixed_threshold_providers',
			static function () use ( $providers ) {
				return $providers;
			}
		);
	}

	public function test_percentile_reads_the_value_at_that_share_of_the_scores() {
		$scores = array( 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0, 1.1 );

		$this->assertSame( 0.5, undergrowth_score_percentile( $scores, 0.4 ) );
		$this->assertSame( 1.0, undergrowth_score_percentile( $scores, 0.9 ) );
		$this->assertSame( 0.1, undergrowth_score_percentile( $scores, 0.0 ) );
		$this->assertSame( 1.1, undergrowth_score_percentile( $scores, 1.0 ) );
	}

	public function test_sample_scores_every_pair_within_each_group_when_few() {
		$a = undergrowth_unit_vector( array( 1.0, 0.0 ) );
		$b = undergrowth_unit_vector( array( 0.0, 1.0 ) );
		$c = undergrowth_unit_vector( array( 1.0, 1.0 ) );

		$scores = undergrowth_sample_scores( array( array( $a, $b, $c ), array( $a, $b ) ), 100 );

		$this->assertCount( 4, $scores );
		$this->assertEqualsWithDelta( array( 0.0, 0.0, M_SQRT1_2, M_SQRT1_2 ), $scores, 1e-6 );
	}

	public function test_sample_never_scores_more_pairs_than_its_limit() {
		$vectors = array();

		for ( $i = 0; $i < 40; $i++ ) {
			$vectors[] = undergrowth_unit_vector( array( 1.0, $i / 10 ) );
		}

		$scores = undergrowth_sample_scores( array( $vectors ), 50 );

		$this->assertCount( 50, $scores );
		$this->assertSame( $scores, array_values( $scores ) );
		$this->assertLessThanOrEqual( 1.0 + 1e-6, max( $scores ) );
		$this->assertGreaterThan( 0.0, min( $scores ) );
	}

	public function test_groups_are_picked_in_proportion_to_their_pairs() {
		$sizes = array(
			0 => 3.0,
			2 => 6.0,
		);

		$this->assertSame( 0, undergrowth_pick_group( $sizes, 1 ) );
		$this->assertSame( 0, undergrowth_pick_group( $sizes, 3 ) );
		$this->assertSame( 2, undergrowth_pick_group( $sizes, 4 ) );
		$this->assertSame( 2, undergrowth_pick_group( $sizes, 9 ) );
		$this->assertSame( 2, undergrowth_pick_group( $sizes, 10 ) );
	}

	public function test_sample_of_several_groups_scores_only_pairs_within_one() {
		$ones  = array_fill( 0, 10, undergrowth_unit_vector( array( 1.0, 0.0 ) ) );
		$twos  = array_fill( 0, 10, undergrowth_unit_vector( array( 0.0, 1.0 ) ) );
		$mixed = undergrowth_sample_scores( array( $ones, $twos ), 30 );

		$this->assertCount( 30, $mixed );
		$this->assertEqualsWithDelta( 1.0, min( $mixed ), 1e-6 );
	}

	public function test_reservoir_keeps_at_most_its_limit_of_what_it_was_given() {
		$kept = array();

		for ( $seen = 1; $seen <= 100; $seen++ ) {
			undergrowth_keep_in_reservoir( $kept, "vector {$seen}", $seen, 10 );
		}

		$this->assertCount( 10, $kept );
		$this->assertSame( array(), array_diff( $kept, array_map( static fn( $n ) => "vector {$n}", range( 1, 100 ) ) ) );
	}

	public function test_reservoir_keeps_everything_below_its_limit() {
		$kept = array();

		for ( $seen = 1; $seen <= 5; $seen++ ) {
			undergrowth_keep_in_reservoir( $kept, "vector {$seen}", $seen, 10 );
		}

		$this->assertSame( array( 'vector 1', 'vector 2', 'vector 3', 'vector 4', 'vector 5' ), $kept );
	}

	public function test_sample_weighs_groups_by_the_pairs_they_stand_for() {
		$same      = array_fill( 0, 10, undergrowth_unit_vector( array( 1.0, 0.0 ) ) );
		$different = array( undergrowth_unit_vector( array( 1.0, 0.0 ) ), undergrowth_unit_vector( array( 0.0, 1.0 ) ) );

		$scores = undergrowth_sample_scores( array( $same, $different ), 20, array( 0.0, 1000.0 ) );

		$this->assertCount( 20, $scores );
		$this->assertEqualsWithDelta( 0.0, max( $scores ), 1e-6 );
	}

	public function test_outlier_floor_sits_below_the_lower_quartile() {
		$floor = undergrowth_outlier_floor( array( 0.962, 0.927, 0.949, 0.951, 0.954, 0.957, 0.962 ) );

		$this->assertEqualsWithDelta( 0.949 - 1.5 * ( 0.957 - 0.949 ), $floor, 1e-9 );
	}

	public function test_outlier_floor_needs_four_clusters() {
		$this->assertNull( undergrowth_outlier_floor( array( 0.9, 0.95, 0.97 ) ) );
	}

	public function test_measuring_stores_the_link_and_cluster_percentiles() {
		$ids    = $this->plant_groups( 5, 4 );
		$scores = $this->pairwise_scores( $ids );

		undergrowth_measure_scores();
		$stored = get_option( 'undergrowth_scores' );

		$this->assertSame( 'test-provider', $stored['provider'] );
		$this->assertSame( 'test-model', $stored['model'] );
		$this->assertSame( 20, $stored['posts'] );
		$this->assertSame( 190, $stored['pairs'] );
		$this->assertSame( undergrowth_score_percentile( $scores, 0.4 ), $stored['link'] );
		$this->assertSame( undergrowth_score_percentile( $scores, 0.9 ), $stored['cluster'] );
		$this->assertSame( undergrowth_score_percentile( $scores, 0.5 ), $stored['percentiles']['50'] );
	}

	public function test_measurement_pairs_only_posts_in_one_language() {
		add_filter(
			'undergrowth_post_language',
			static function ( $locale, $post ) {
				return 0 === strpos( $post->post_title, 'Group 0' ) ? 'de_DE' : $locale;
			},
			10,
			2
		);
		$this->plant_groups( 2, 3 );

		undergrowth_measure_scores();

		$this->assertSame( 6, get_option( 'undergrowth_scores' )['pairs'] );
	}

	public function test_measurement_never_compares_vectors_of_different_lengths() {
		$this->plant( 'Short one', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Short two', array( 0.0, 1.0, 0.0 ) );
		$this->plant( 'Long one', array( 1.0, 0.0, 0.0, 0.0 ) );
		$this->plant( 'Long two', array( 0.0, 0.0, 0.0, 1.0 ) );

		undergrowth_measure_scores();

		$this->assertSame( 2, get_option( 'undergrowth_scores' )['pairs'] );
		$this->assertSame( 0.0, get_option( 'undergrowth_scores' )['percentiles']['95'] );
	}

	public function test_scores_are_forgotten_when_the_site_falls_below_twenty_posts() {
		$ids = $this->plant_groups( 5, 4 );
		undergrowth_measure_scores();

		wp_delete_post( $ids[0], true );

		$this->assertFalse( get_option( 'undergrowth_scores' ) );
		$this->assertSame( UNDERGROWTH_MIN_LINK_SIMILARITY, undergrowth_min_link_similarity() );
	}

	public function test_scores_stay_when_a_larger_site_loses_a_post() {
		$ids = $this->plant_groups( 7, 3 );
		undergrowth_measure_scores();
		$score = get_option( 'undergrowth_scores' );

		wp_delete_post( $ids[0], true );

		$this->assertSame( $score, get_option( 'undergrowth_scores' ) );
	}

	public function test_centre_is_measured_on_gardened_members_up_to_one_batch() {
		$ids     = $this->plant_groups( 1, 3 );
		$members = array_merge( $ids, self::factory()->post->create_many( UNDERGROWTH_CLUSTER_BATCH ) );

		foreach ( $members as $id ) {
			update_post_meta( $id, 'undergrowth_cluster', $ids[0] );
		}

		undergrowth_set_stage( $ids[1], 'composting', 'compost' );
		$measured = undergrowth_centre_members( $ids[0] );

		$this->assertNotContains( $ids[1], $measured );
		$this->assertCount( UNDERGROWTH_CLUSTER_BATCH, $measured );
		$this->assertSame( $ids[0], $measured[0] );
	}

	public function test_measured_thresholds_are_the_defaults_from_twenty_posts() {
		$this->plant_groups( 5, 4 );

		undergrowth_measure_scores();
		$stored = get_option( 'undergrowth_scores' );

		// Identical vectors score a hair over 1 in single precision, and a
		// threshold never goes past 1.
		$this->assertSame( min( 1.0, $stored['link'] ), undergrowth_min_link_similarity() );
		$this->assertSame( min( 1.0, $stored['cluster'] ), undergrowth_min_cluster_similarity() );
	}

	public function test_fixed_thresholds_stay_below_twenty_posts() {
		$this->plant_groups( 1, 19 );

		undergrowth_measure_scores();

		$this->assertSame( 19, get_option( 'undergrowth_scores' )['posts'] );
		$this->assertSame( UNDERGROWTH_MIN_LINK_SIMILARITY, undergrowth_min_link_similarity() );
		$this->assertSame( UNDERGROWTH_MIN_CLUSTER_SIMILARITY, undergrowth_min_cluster_similarity() );
		$this->assertSame( UNDERGROWTH_GAP_SIMILARITY, undergrowth_gap_similarity() );
	}

	public function test_fixed_thresholds_stay_before_anything_is_measured() {
		$this->assertSame( UNDERGROWTH_MIN_LINK_SIMILARITY, undergrowth_min_link_similarity() );
		$this->assertSame( UNDERGROWTH_MIN_CLUSTER_SIMILARITY, undergrowth_min_cluster_similarity() );
		$this->assertSame( UNDERGROWTH_GAP_SIMILARITY, undergrowth_gap_similarity() );
	}

	public function test_scores_measured_with_another_model_are_not_used() {
		$this->plant_groups( 5, 4 );
		undergrowth_measure_scores();

		update_option( 'undergrowth_scores', array_merge( get_option( 'undergrowth_scores' ), array( 'model' => 'old-model' ) ) );

		$this->assertSame( UNDERGROWTH_MIN_LINK_SIMILARITY, undergrowth_min_link_similarity() );
	}

	public function test_measured_thresholds_start_at_exactly_twenty_posts() {
		$this->plant_groups( 1, 19 );
		undergrowth_measure_scores();

		$this->assertSame( 19, get_option( 'undergrowth_scores' )['posts'] );
		$this->assertSame( UNDERGROWTH_MIN_LINK_SIMILARITY, undergrowth_min_link_similarity() );
		$this->assertSame( UNDERGROWTH_MIN_CLUSTER_SIMILARITY, undergrowth_min_cluster_similarity() );

		$this->plant( 'Group 0 post 19', array( 1.5 ) );
		undergrowth_measure_scores();
		$stored = get_option( 'undergrowth_scores' );

		$this->assertSame( 20, $stored['posts'] );
		$this->assertSame( min( 1.0, $stored['link'] ), undergrowth_min_link_similarity() );
		$this->assertSame( min( 1.0, $stored['cluster'] ), undergrowth_min_cluster_similarity() );
		$this->assertNotSame( UNDERGROWTH_MIN_LINK_SIMILARITY, undergrowth_min_link_similarity() );
	}

	/**
	 * OpenAI as the site's own provider, from a saved key, keeps the fixed
	 * thresholds with twenty posts and more, and its scores are still stored.
	 */
	public function test_the_openai_identity_keeps_the_fixed_thresholds_but_is_still_measured() {
		remove_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );
		update_option( 'undergrowth_openai_api_key', 'sk-test-thresholds' );
		$this->assertSame(
			array(
				'provider' => 'openai',
				'model'    => UNDERGROWTH_OPENAI_MODEL,
			),
			undergrowth_embed_identity()
		);

		// OpenAI ignores a filter's vector, so the planted vectors come back
		// from a stand-in for its API instead, by post title.
		$answer = function ( $preempt, $args, $url ) {
			if ( UNDERGROWTH_OPENAI_URL !== $url ) {
				return $preempt;
			}

			$input = (string) ( json_decode( (string) $args['body'], true )['input'] ?? '' );

			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'data' => array( array( 'embedding' => $this->provider( null, $input ) ) ) ) ),
			);
		};
		add_filter( 'pre_http_request', $answer, 10, 3 );

		$this->plant_groups( 5, 4 );
		undergrowth_measure_scores();

		remove_filter( 'pre_http_request', $answer, 10 );
		$stored = get_option( 'undergrowth_scores' );
		$this->assertSame( 'openai', $stored['provider'] );
		$this->assertSame( 20, $stored['posts'] );
		$this->assertIsNumeric( $stored['link'] );
		$this->assertSame( UNDERGROWTH_MIN_LINK_SIMILARITY, undergrowth_min_link_similarity() );
		$this->assertSame( UNDERGROWTH_MIN_CLUSTER_SIMILARITY, undergrowth_min_cluster_similarity() );
		$this->assertSame( UNDERGROWTH_GAP_SIMILARITY, undergrowth_gap_similarity() );
	}

	public function test_openai_keeps_its_fixed_thresholds() {
		$this->assertSame( array( 'openai' ), undergrowth_fixed_threshold_providers() );
	}

	public function test_a_fixed_provider_keeps_the_fixed_thresholds_but_is_still_measured() {
		$this->fixed_providers( array( 'test-provider' ) );
		$this->plant_groups( 5, 4 );

		undergrowth_measure_scores();

		$this->assertSame( 20, get_option( 'undergrowth_scores' )['posts'] );
		$this->assertSame( UNDERGROWTH_MIN_LINK_SIMILARITY, undergrowth_min_link_similarity() );
		$this->assertSame( UNDERGROWTH_MIN_CLUSTER_SIMILARITY, undergrowth_min_cluster_similarity() );
	}

	public function test_filters_receive_the_measured_value_and_override_it() {
		$this->plant_groups( 5, 4 );
		undergrowth_measure_scores();
		$measured = get_option( 'undergrowth_scores' )['link'];
		$received = null;

		add_filter(
			'undergrowth_min_link_similarity',
			static function ( $similarity ) use ( &$received ) {
				$received = $similarity;
				return 0.65;
			}
		);

		$this->assertSame( 0.65, undergrowth_min_link_similarity() );
		$this->assertSame( $measured, $received );
	}

	public function test_measured_cluster_threshold_splits_what_the_fixed_one_lumps_together() {
		$this->fixed_providers( array( 'test-provider' ) );
		$this->plant_groups( 5, 4 );

		undergrowth_run_cluster_cycle( 0 );
		$fixed = count( undergrowth_cluster_leaders() );

		remove_all_filters( 'undergrowth_fixed_threshold_providers' );
		undergrowth_run_cluster_cycle( 0 );

		$this->assertSame( 1, $fixed );
		$this->assertCount( 5, undergrowth_cluster_leaders() );
	}

	public function test_cluster_cycle_measures_the_scores_first() {
		$this->plant_groups( 5, 4 );

		undergrowth_run_cluster_cycle( 0 );

		$this->assertCount( 5, undergrowth_cluster_leaders() );
		$this->assertSame( 20, get_option( 'undergrowth_scores' )['posts'] );
	}

	public function test_finished_cluster_cycle_stores_the_gap_floor() {
		$this->plant_groups( 5, 4 );

		undergrowth_run_cluster_cycle( 0 );

		$centres = array();

		foreach ( undergrowth_cluster_leaders() as $leader ) {
			$centres[] = undergrowth_measure_cluster( undergrowth_centre_members( $leader ) )['centre'];
		}

		$this->assertSame( undergrowth_outlier_floor( $centres ), get_option( 'undergrowth_scores' )['gap'] );
		$this->assertSame( min( 1.0, get_option( 'undergrowth_scores' )['gap'] ), undergrowth_gap_similarity() );
	}

	public function test_gap_stays_fixed_with_too_few_clusters_to_compare() {
		$this->plant_groups( 3, 7 );

		undergrowth_run_cluster_cycle( 0 );

		$this->assertNull( get_option( 'undergrowth_scores' )['gap'] );
		$this->assertSame( UNDERGROWTH_GAP_SIMILARITY, undergrowth_gap_similarity() );
	}

	public function test_uninstall_removes_the_measured_scores() {
		update_option( 'undergrowth_scores', array( 'posts' => 1 ) );

		undergrowth_uninstall_site();

		$this->assertFalse( get_option( 'undergrowth_scores' ) );
	}

	public function test_report_lists_the_measured_scores_and_thresholds_in_use() {
		$this->plant_groups( 5, 4 );
		undergrowth_run_cluster_cycle( 0 );

		$report = implode( "\n", undergrowth_scores_report() );

		$this->assertStringContainsString( 'Provider: test-provider', $report );
		$this->assertStringContainsString( 'Posts: 20', $report );
		$this->assertStringContainsString( 'p40: ' . number_format( get_option( 'undergrowth_scores' )['link'], 3 ), $report );
		$this->assertStringContainsString( 'Link threshold in use: ' . number_format( undergrowth_min_link_similarity(), 3 ) . ' (measured)', $report );
	}

	public function test_report_says_when_nothing_is_measured_yet() {
		$report = implode( "\n", undergrowth_scores_report() );

		$this->assertStringContainsString( 'No scores measured yet', $report );
		$this->assertStringContainsString( 'Link threshold in use: 0.400 (fixed)', $report );
	}
}
