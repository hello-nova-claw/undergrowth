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
			$centres[] = undergrowth_measure_cluster( undergrowth_cluster_members( $leader ) )['centre'];
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
