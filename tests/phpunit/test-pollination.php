<?php
/**
 * Tests for cross-pollination: the surprise scale, the three signals, the
 * Map view and dismissals.
 *
 * Below 20 posts the fixed thresholds apply: 0.5 to cluster, 0.4 to link,
 * so the scale's gap is 0.1. The sweet-spot band runs from 0.475 at step 1,
 * 0.45 at step 2, 0.425, 0.4 and 0.35, up to 0.5. A bridge must reach 0.5
 * at step 1, then 0.475, 0.45, 0.425 and 0.4.
 *
 * @package Undergrowth
 */

/**
 * Cross-pollination.
 */
class Test_Pollination extends WP_UnitTestCase {

	use Plants_Posts;

	use Stops_Redirects;

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		unset( $_POST['a'], $_POST['b'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['tab'], $_GET['undergrowth_notice'] );

		parent::tear_down();
	}

	/**
	 * Returns a unit vector at a given similarity to the first axis, turned
	 * towards another axis.
	 *
	 * @param float $cosine Similarity to the first axis.
	 * @param int   $axis   Axis it turns towards, 1 to 3.
	 * @return float[]
	 */
	private function turned( float $cosine, int $axis = 1 ): array {
		$vector          = array( $cosine, 0.0, 0.0, 0.0 );
		$vector[ $axis ] = sqrt( 1 - $cosine * $cosine );

		return $vector;
	}

	/**
	 * Publishes posts with vectors and puts them in one group, led by the first.
	 *
	 * @param array<string, float[]> $posts Vectors keyed by title.
	 * @param array<string, mixed>   $meta  Provider, model or language to store instead.
	 * @return int[] Post IDs, in order.
	 */
	private function group( array $posts, array $meta = array() ): array {
		$ids = array();

		foreach ( $posts as $title => $vector ) {
			$ids[] = $this->seed( $title, $vector, array(), $meta );
		}

		foreach ( $ids as $post_id ) {
			update_post_meta( $post_id, 'undergrowth_cluster', $ids[0] );
		}

		return $ids;
	}

	/**
	 * Stores an accepted link between two posts.
	 *
	 * @param int $source_id Source post ID.
	 * @param int $target_id Target post ID.
	 */
	private function link( int $source_id, int $target_id ): void {
		global $wpdb;

		$wpdb->insert(
			undergrowth_links_table(),
			array(
				'source_id'  => $source_id,
				'target_id'  => $target_id,
				'state'      => 'accepted',
				'score'      => 0.5,
				'sentence'   => '',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Runs the finder as the end of a cluster pass would, without labels.
	 */
	private function find(): void {
		undergrowth_start_cluster_labels();
		undergrowth_start_pollination();
		$this->run_batches();
	}

	/**
	 * Runs the queued batches of the pass under way, as cron would.
	 *
	 * @param int $most Most batches to run.
	 */
	private function run_batches( int $most = 10 ): void {
		for ( $i = 0; $i < $most; $i++ ) {
			$record = get_option( 'undergrowth_pollination_cycle_pass' );

			if ( ! is_array( $record ) ) {
				return;
			}

			undergrowth_run_pollination_cycle( $record['after'], $record['pass'] );
		}
	}

	/**
	 * Makes two groups on the first and second axes, with one post of the
	 * second at a chosen similarity to the first group's posts.
	 *
	 * @param float $cosine Similarity of the odd post to the first group.
	 * @return array{0: int[], 1: int[]} Post IDs of both groups.
	 */
	private function two_groups( float $cosine ): array {
		$a = $this->group(
			array(
				'Oca'    => array( 1.0, 0.0, 0.0, 0.0 ),
				'Ulluco' => array( 1.0, 0.0, 0.0, 0.0 ),
				'Mashua' => array( 1.0, 0.0, 0.0, 0.0 ),
			)
		);
		$b = $this->group(
			array(
				'Ledger'  => array( 0.0, 1.0, 0.0, 0.0 ),
				'Invoice' => array( 0.0, 1.0, 0.0, 0.0 ),
				'Receipt' => $this->turned( $cosine ),
			)
		);

		return array( $a, $b );
	}

	/**
	 * Returns the sweet-spot posts of the only pair, or null.
	 *
	 * @param int $step Step of the surprise scale.
	 * @return array{0: int, 1: int, 2: float}|null
	 */
	private function sweet( int $step ): ?array {
		$pairs = undergrowth_pollination_pairs( 0, $step );

		return array() === $pairs ? null : $pairs[0]['sweet'];
	}

	public function test_surprise_step_defaults_to_cautious_and_stays_on_the_scale() {
		$this->assertSame( 2, undergrowth_surprise_step() );

		update_option( 'undergrowth_surprise', 5 );
		$this->assertSame( 5, undergrowth_surprise_step() );

		foreach ( array( 0, 6, 'wild', null, '3' ) as $value ) {
			$this->assertSame( '3' === $value ? 3 : 2, undergrowth_sanitize_surprise( $value ) );
		}

		$this->assertCount( 5, undergrowth_surprise_steps() );
	}

	public function test_raising_the_scale_widens_the_band_and_weakens_the_bridge() {
		$previous = null;

		foreach ( array( 1, 2, 3, 4, 5 ) as $step ) {
			$band = undergrowth_surprise_band( $step );

			$this->assertEqualsWithDelta( 0.5, $band[1], 1e-9 );

			if ( null !== $previous ) {
				$this->assertLessThan( $previous[0], $band[0] );
				$this->assertLessThan( $previous[1], undergrowth_bridge_strength( $step ) );
			}

			$previous = array( $band[0], undergrowth_bridge_strength( $step ) );
		}

		$this->assertEqualsWithDelta( 0.475, undergrowth_surprise_band( 1 )[0], 1e-9 );
		$this->assertEqualsWithDelta( 0.45, undergrowth_surprise_band( 2 )[0], 1e-9 );
		$this->assertEqualsWithDelta( 0.35, undergrowth_surprise_band( 5 )[0], 1e-9 );
		$this->assertEqualsWithDelta( 0.5, undergrowth_bridge_strength( 1 ), 1e-9 );
		$this->assertEqualsWithDelta( 0.4, undergrowth_bridge_strength( 5 ), 1e-9 );
	}

	public function test_filters_set_the_band_and_the_bridge_and_bad_values_fall_back() {
		add_filter(
			'undergrowth_surprise_band',
			static function ( $band, $step ) {
				return 3 === $step ? array( 0.1, 0.2 ) : array( 0.9, 0.1 );
			},
			10,
			2
		);
		add_filter(
			'undergrowth_bridge_strength',
			static function ( $strength, $step ) {
				return 3 === $step ? 0.3 : 'strong';
			},
			10,
			2
		);

		$this->assertSame( array( 0.1, 0.2 ), undergrowth_surprise_band( 3 ) );
		$this->assertEqualsWithDelta( 0.45, undergrowth_surprise_band( 2 )[0], 1e-9 );
		$this->assertSame( 0.3, undergrowth_bridge_strength( 3 ) );
		$this->assertEqualsWithDelta( 0.475, undergrowth_bridge_strength( 2 ), 1e-9 );
	}

	public function test_gap_never_shrinks_below_a_twentieth() {
		add_filter(
			'undergrowth_min_link_similarity',
			static function () {
				return 0.6;
			}
		);

		$this->assertEqualsWithDelta( array( 0.5, 0.05 ), undergrowth_surprise_measure(), 1e-9 );
	}

	public function test_sweet_spot_pair_sits_in_the_band_of_the_step() {
		list( $a, $b ) = $this->two_groups( 0.46 );
		$this->find();

		$this->assertNull( $this->sweet( 1 ) );

		$sweet = $this->sweet( 2 );

		$this->assertSame( array( $a[0], $b[2] ), array_slice( $sweet, 0, 2 ) );
		$this->assertEqualsWithDelta( 0.46, $sweet[2], 1e-6 );
		$this->assertSame( $sweet, $this->sweet( 5 ) );
	}

	public function test_a_wider_step_reaches_a_pair_a_narrower_one_does_not() {
		$this->two_groups( 0.43 );
		$this->find();

		$this->assertNull( $this->sweet( 2 ) );
		$this->assertNotNull( $this->sweet( 3 ) );
		$this->assertNotNull( $this->sweet( 4 ) );
	}

	public function test_posts_close_enough_to_cluster_make_no_sweet_spot() {
		$this->two_groups( 0.6 );
		$this->find();

		foreach ( array( 1, 2, 3, 4, 5 ) as $step ) {
			$this->assertNull( $this->sweet( $step ) );
		}
	}

	public function test_no_step_pairs_posts_of_the_same_group() {
		$this->group(
			array(
				'Oca'    => array( 1.0, 0.0, 0.0, 0.0 ),
				'Ulluco' => $this->turned( 0.46 ),
				'Mashua' => $this->turned( 0.48, 2 ),
			)
		);
		$this->find();

		foreach ( array( 1, 2, 3, 4, 5 ) as $step ) {
			$this->assertSame( array(), undergrowth_pollination_pairs( 0, $step ) );
		}
	}

	public function test_vectors_from_another_model_are_never_compared() {
		$this->group(
			array(
				'Oca'    => array( 1.0, 0.0, 0.0, 0.0 ),
				'Ulluco' => array( 1.0, 0.0, 0.0, 0.0 ),
				'Mashua' => array( 1.0, 0.0, 0.0, 0.0 ),
			)
		);
		$this->group(
			array(
				'Ledger'  => $this->turned( 0.46 ),
				'Invoice' => $this->turned( 0.46 ),
				'Receipt' => $this->turned( 0.46 ),
			),
			array( 'model' => 'other-model' )
		);
		$this->find();

		$this->assertSame( array(), undergrowth_pollination_pairs( 0, 5 ) );
	}

	public function test_bridge_post_sits_near_both_unlinked_groups() {
		list( $a, $b ) = $this->two_groups( 0.0 );
		$bridge        = $this->seed( 'Roots and ledgers', array( 1.0, 1.0, 0.0, 0.0 ) );
		$this->find();

		$pairs = undergrowth_pollination_pairs( 0, 1 );

		$this->assertCount( 1, $pairs );
		$this->assertSame( array( $a[0], $b[0] ), $pairs[0]['groups'] );
		$this->assertSame( $bridge, $pairs[0]['bridge'][0] );
		$this->assertFalse( $pairs[0]['linked'] );
		$this->assertNull( $pairs[0]['sweet'] );
	}

	public function test_weak_bridge_needs_a_higher_step() {
		$this->two_groups( 0.0 );
		// 0.44 to each centre: past step 3's 0.45, within step 4's 0.425.
		$this->seed( 'Roots and ledgers', array( 0.44, 0.44, sqrt( 1 - 2 * 0.44 * 0.44 ), 0.0 ) );
		$this->find();

		$this->assertSame( array(), undergrowth_pollination_pairs( 0, 3 ) );
		$this->assertCount( 1, undergrowth_pollination_pairs( 0, 4 ) );
	}

	public function test_linked_groups_have_no_bridge_or_threads_and_no_bridge_idea() {
		list( $a, $b ) = $this->two_groups( 0.46 );
		$this->seed( 'Roots and ledgers', array( 1.0, 1.0, 0.0, 0.0 ) );
		$this->link( $a[1], $b[0] );
		$this->find();

		$pairs = undergrowth_pollination_pairs( 0, 2 );

		$this->assertCount( 1, $pairs );
		$this->assertTrue( $pairs[0]['linked'] );
		$this->assertNull( $pairs[0]['bridge'] );
		$this->assertNotNull( $pairs[0]['sweet'] );
	}

	public function test_linked_pairs_count_only_accepted_links_between_two_groups() {
		global $wpdb;

		list( $a, $b ) = $this->two_groups( 0.46 );
		$apart         = $this->seed( 'Roots and ledgers', array( 1.0, 1.0, 0.0, 0.0 ) );
		$c             = $this->group(
			array(
				'Bread'     => array( 0.0, 0.0, 1.0, 0.0 ),
				'Sourdough' => array( 0.0, 0.0, 1.0, 0.0 ),
				'Starter'   => array( 0.0, 0.0, 1.0, 0.0 ),
			)
		);

		$this->link( $a[1], $b[0] );
		$this->link( $b[2], $a[0] );
		$this->link( $a[0], $a[2] );
		$this->link( $apart, $b[1] );
		$wpdb->insert(
			undergrowth_links_table(),
			array(
				'source_id'  => $a[2],
				'target_id'  => $c[1],
				'state'      => 'proposed',
				'score'      => 0.5,
				'sentence'   => '',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);

		$this->assertSame( array( undergrowth_pollination_key( $a[0], $b[0] ) => true ), undergrowth_linked_group_pairs() );
	}

	public function test_linked_pairs_are_read_from_the_links_first() {
		$sql = '';
		add_filter(
			'query',
			static function ( $query ) use ( &$sql ) {
				if ( false !== strpos( $query, 'DISTINCT a.meta_value' ) ) {
					$sql = $query;
				}

				return $query;
			}
		);

		undergrowth_linked_group_pairs();

		// Joined the other way, SQLite paired every grouped post with every
		// other before looking for a link: 3 seconds at 5,000 posts.
		$this->assertStringContainsString( 'FROM `' . undergrowth_links_table() . '` l CROSS JOIN', $sql );
	}

	/**
	 * Adds a fourth post to a group, so it stays a group when one leaves.
	 *
	 * @param int[] $group Post IDs, the leader first.
	 */
	private function grow( array $group ): void {
		update_post_meta( $this->seed( 'Bill', array( 0.0, 1.0, 0.0, 0.0 ) ), 'undergrowth_cluster', $group[0] );
	}

	public function test_composting_post_can_bridge_but_never_makes_a_sweet_spot() {
		list( , $b ) = $this->two_groups( 0.46 );
		$bridge      = $this->seed( 'Roots and ledgers', array( 1.0, 1.0, 0.0, 0.0 ) );
		$this->grow( $b );

		update_post_meta( $bridge, 'undergrowth_stage', 'composting' );
		// Composting takes a post out of its group, as composting does.
		delete_post_meta( $b[2], 'undergrowth_cluster' );
		update_post_meta( $b[2], 'undergrowth_stage', 'composting' );
		$this->find();

		$pairs = undergrowth_pollination_pairs( 0, 2 );

		$this->assertCount( 1, $pairs );
		$this->assertNull( $pairs[0]['sweet'] );
		$this->assertSame( $bridge, $pairs[0]['bridge'][0] );
	}

	public function test_sweet_spot_whose_post_left_its_group_is_not_shown() {
		list( , $b ) = $this->two_groups( 0.46 );
		$this->grow( $b );
		$this->find();

		$this->assertNotNull( $this->sweet( 2 ) );

		delete_post_meta( $b[2], 'undergrowth_cluster' );

		// The sweet spot was the pair's only signal.
		$this->assertSame( array(), undergrowth_pollination_pairs( 0, 2 ) );
	}

	public function test_pairs_from_an_older_pass_are_not_shown() {
		$this->two_groups( 0.46 );
		$this->find();

		$labels         = get_option( 'undergrowth_cluster_labels' );
		$labels['made'] = '2001-01-01 00:00:00';
		update_option( 'undergrowth_cluster_labels', $labels );

		$this->assertSame( array(), undergrowth_pollination_pairs( 0, 2 ) );
	}

	public function test_a_batch_of_an_older_pass_stops() {
		$this->two_groups( 0.46 );
		undergrowth_start_cluster_labels();
		update_option(
			'undergrowth_pollination_work',
			array(
				'made'  => 'earlier',
				'pairs' => array(),
			)
		);

		undergrowth_run_pollination_cycle( 0, '' );

		$this->assertFalse( get_option( 'undergrowth_pollination' ) );
	}

	public function test_a_batch_of_another_run_of_the_pass_stops() {
		$this->two_groups( 0.46 );
		undergrowth_start_cluster_labels();
		undergrowth_start_pollination();

		undergrowth_run_pollination_cycle( 0, 'another-run' );

		$this->assertFalse( get_option( 'undergrowth_pollination' ) );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_pollination_cycle', array( 0, get_option( 'undergrowth_pollination_work' )['pass'] ) ) );
	}

	public function test_the_first_batch_waits_for_cron() {
		$this->two_groups( 0.46 );
		undergrowth_start_cluster_labels();
		undergrowth_start_pollination();

		$this->assertFalse( get_option( 'undergrowth_pollination' ) );

		$this->run_batches();

		$this->assertNotNull( $this->sweet( 2 ) );
		$this->assertFalse( get_option( 'undergrowth_pollination_cycle_pass' ) );
	}

	public function test_a_batch_waits_while_a_start_or_another_batch_holds_the_lock() {
		$this->two_groups( 0.46 );
		undergrowth_start_cluster_labels();
		undergrowth_start_pollination();

		$pass = get_option( 'undergrowth_pollination_work' )['pass'];

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		WP_Upgrader::create_lock( 'undergrowth_pollination_cycle' );
		// The start queued this batch already, so only a retry can queue it now.
		wp_clear_scheduled_hook( 'undergrowth_pollination_cycle', array( 0, $pass ) );

		undergrowth_run_pollination_cycle( 0, $pass );

		$this->assertFalse( get_option( 'undergrowth_pollination' ) );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_pollination_cycle', array( 0, $pass ) ) );

		// A new pass waits too, so the batch running never stores over it.
		undergrowth_start_pollination();

		$this->assertSame( $pass, get_option( 'undergrowth_pollination_work' )['pass'] );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_pollination_start' ) );

		WP_Upgrader::release_lock( 'undergrowth_pollination_cycle' );
		$this->run_batches();

		$this->assertNotNull( $this->sweet( 2 ) );
	}

	public function test_hourly_check_runs_a_stalled_batch_again() {
		$this->two_groups( 0.46 );
		undergrowth_start_cluster_labels();
		undergrowth_start_pollination();

		$work = get_option( 'undergrowth_pollination_work' );

		// The queued batch died, an hour and more ago.
		wp_clear_scheduled_hook( 'undergrowth_pollination_cycle', array( 0, $work['pass'] ) );
		$record       = get_option( 'undergrowth_pollination_cycle_pass' );
		$record['at'] = time() - UNDERGROWTH_PASS_STALL - 1;
		update_option( 'undergrowth_pollination_cycle_pass', $record );

		undergrowth_resume_stalled_passes();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_pollination_cycle', array( 0, $work['pass'] ) ) );
	}

	public function test_a_run_that_lost_its_pass_stores_nothing() {
		$this->two_groups( 0.46 );
		undergrowth_start_cluster_labels();
		undergrowth_start_pollination();

		$pass = get_option( 'undergrowth_pollination_work' )['pass'];

		// The hourly check took the pass over while this run was comparing.
		add_filter(
			'pre_option_undergrowth_pollination_cycle_pass',
			static function () use ( $pass ) {
				return array(
					'after' => 0,
					'at'    => time(),
					'run'   => 'someone-else',
					'pass'  => $pass,
				);
			}
		);

		undergrowth_run_pollination_cycle( 0, $pass );

		$this->assertFalse( get_option( 'undergrowth_pollination' ) );
	}

	public function test_a_large_site_is_read_in_batches() {
		$this->two_groups( 0.46 );

		for ( $i = 0; $i < UNDERGROWTH_CLUSTER_BATCH; $i++ ) {
			$this->seed( '', array( 0.0, 0.0, 1.0, 0.0 ) );
		}

		undergrowth_start_cluster_labels();
		undergrowth_start_pollination();
		$this->run_batches( 1 );

		$this->assertFalse( get_option( 'undergrowth_pollination' ) );
		$this->assertGreaterThan( 0, get_option( 'undergrowth_pollination_cycle_pass' )['after'] );

		$this->run_batches();

		$this->assertNotNull( $this->sweet( 2 ) );
		$this->assertFalse( get_option( 'undergrowth_pollination_work' ) );
	}

	public function test_a_batch_whose_time_is_spent_compares_one_post_and_queues_the_next() {
		list( $a ) = $this->two_groups( 0.46 );
		add_filter( 'undergrowth_backfill_time_budget', '__return_zero' );

		undergrowth_start_cluster_labels();
		undergrowth_start_pollination();
		$this->run_batches( 1 );

		$this->assertFalse( get_option( 'undergrowth_pollination' ) );
		$this->assertSame( $a[0], get_option( 'undergrowth_pollination_cycle_pass' )['after'] );
	}

	public function test_a_pass_cut_short_by_time_finds_the_same_pairs() {
		$this->two_groups( 0.46 );
		$this->seed( 'Roots and ledgers', array( 1.0, 1.0, 0.0, 0.0 ) );
		$this->find();
		$whole = get_option( 'undergrowth_pollination' )['pairs'];

		delete_option( 'undergrowth_pollination' );
		add_filter( 'undergrowth_backfill_time_budget', '__return_zero' );
		$this->find();

		$this->assertSame( $whole, get_option( 'undergrowth_pollination' )['pairs'] );
	}

	public function test_pairs_with_more_signals_come_first_and_limit_applies() {
		list( $a ) = $this->two_groups( 0.46 );
		$c         = $this->group(
			array(
				'Bread'     => array( 0.0, 0.0, 1.0, 0.0 ),
				'Sourdough' => array( 0.0, 0.0, 1.0, 0.0 ),
				'Starter'   => array( 0.0, 0.0, 1.0, 0.0 ),
			)
		);
		// Bridges the first group and the bread, which have no sweet spot.
		$this->seed( 'Roots and bread', array( 1.0, 0.0, 1.0, 0.0 ) );
		$this->find();

		$pairs = undergrowth_pollination_pairs( 0, 2 );

		$this->assertCount( 2, $pairs );
		// One signal each: the sweet spot first, then the bridge alone.
		$this->assertNotNull( $pairs[0]['sweet'] );
		$this->assertSame( array( $a[0], $c[0] ), $pairs[1]['groups'] );
		$this->assertCount( 1, undergrowth_pollination_pairs( 1, 2 ) );
	}

	public function test_dismissed_pair_never_returns_even_under_new_leaders() {
		list( $a, $b ) = $this->two_groups( 0.46 );
		$this->find();

		$this->assertTrue( undergrowth_dismiss_pollination( $b[0], $a[0] ) );
		$this->assertSame( array(), undergrowth_pollination_pairs( 0, 5 ) );
		$this->assertFalse( undergrowth_dismiss_pollination( $a[0], $b[0] ) );

		// Next month the same posts have other leaders.
		foreach ( $a as $post_id ) {
			update_post_meta( $post_id, 'undergrowth_cluster', $a[2] );
		}

		foreach ( $b as $post_id ) {
			update_post_meta( $post_id, 'undergrowth_cluster', $b[1] );
		}

		$this->find();

		$this->assertSame( array(), undergrowth_pollination_pairs( 0, 5 ) );
	}

	public function test_unpublished_post_bridges_nothing() {
		$this->two_groups( 0.0 );
		$bridge = $this->seed( 'Roots and ledgers', array( 1.0, 1.0, 0.0, 0.0 ) );

		// The vector outlived the post's publication, as it can without a save.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $bridge ) );
		clean_post_cache( $bridge );
		$this->find();

		$this->assertSame( array(), undergrowth_pollination_pairs( 0, 5 ) );
	}

	public function test_member_with_a_vector_of_another_length_is_left_out_of_the_centre() {
		list( $a ) = $this->two_groups( 0.46 );
		$odd       = $this->seed( 'Odd', array( 1.0, 0.0, 0.0 ) );

		update_post_meta( $odd, 'undergrowth_cluster', $a[0] );
		$this->find();

		$space = undergrowth_pollination_space( (array) undergrowth_get_vector( $a[0] ), undergrowth_cluster_leader_map() );

		$this->assertArrayNotHasKey( $odd, $space['vectors'] );
		$this->assertCount( 4, $space['centres'][ $a[0] ] );
		$this->assertNotNull( $this->sweet( 2 ) );

		// Nor is it compared, as a source, with posts of the other length.
		$this->assertNotSame( $odd, $this->sweet( 5 )[0] );
	}

	public function test_dismissing_one_pair_leaves_the_others() {
		list( $a ) = $this->two_groups( 0.46 );
		$c         = $this->group(
			array(
				'Bread'     => array( 0.0, 0.0, 1.0, 0.0 ),
				'Sourdough' => array( 0.0, 0.0, 1.0, 0.0 ),
				'Starter'   => array( 0.0, 0.0, 1.0, 0.0 ),
			)
		);
		$this->seed( 'Roots and bread', array( 1.0, 0.0, 1.0, 0.0 ) );
		$this->find();

		$this->assertTrue( undergrowth_dismiss_pollination( $c[0], $a[0] ) );

		$pairs = undergrowth_pollination_pairs( 0, 2 );

		$this->assertCount( 1, $pairs );
		$this->assertNotNull( $pairs[0]['sweet'] );
	}

	public function test_malformed_dismissals_are_left_out() {
		update_option( 'undergrowth_pollination_dismissed', array( 'x', array( array( 1 ) ), array( array( 1 ), array() ), array( array( '2' ), array( 3 ) ) ) );

		$this->assertSame( array( array( array( 2 ), array( 3 ) ) ), undergrowth_dismissed_pollination() );

		update_option( 'undergrowth_pollination_dismissed', 'x' );

		$this->assertSame( array(), undergrowth_dismissed_pollination() );
	}

	public function test_threads_are_words_two_groups_share_and_the_rest_rarely_use() {
		$groups = array(
			10 => array(
				'size'    => 3,
				'counts'  => array(
					'frost'      => 2,
					'raised'     => 2,
					'bed'        => 2,
					'raised bed' => 2,
					'garden'     => 3,
				),
				'spelled' => array(
					'frost'      => array( 'Frost' => 2 ),
					'raised bed' => array( 'raised bed' => 2 ),
				),
			),
			20 => array(
				'size'    => 3,
				'counts'  => array(
					'frost'      => 3,
					'raised'     => 2,
					'bed'        => 2,
					'raised bed' => 2,
					'garden'     => 2,
				),
				'spelled' => array(
					'frost'      => array( 'frost' => 3 ),
					'raised bed' => array( 'raised bed' => 2 ),
				),
			),
			30 => array(
				'size'    => 3,
				'counts'  => array( 'ledger' => 3 ),
				'spelled' => array( 'ledger' => array( 'ledger' => 3 ) ),
			),
		);
		$site   = array(
			'frost'      => 5,
			'raised'     => 4,
			'bed'        => 4,
			'raised bed' => 4,
			// Used by most of the other posts too, so not rare.
			'garden'     => 15,
			'ledger'     => 3,
		);

		$this->assertSame( array( '10-20' => array( 'frost', 'raised bed' ) ), undergrowth_find_threads( $groups, $site, 20 ) );
	}

	public function test_threads_are_only_words_each_group_is_known_for() {
		$rare   = array();
		$counts = array( 'mass' => 2 );

		// Eleven words the second group is better known for than "mass".
		for ( $i = 0; $i < 11; $i++ ) {
			$rare[ 'rare' . $i ]   = 3;
			$counts[ 'rare' . $i ] = 3;
		}

		$groups = array(
			10 => array(
				'size'    => 3,
				'counts'  => array( 'mass' => 2 ),
				'spelled' => array( 'mass' => array( 'mass' => 2 ) ),
			),
			20 => array(
				'size'    => 3,
				'counts'  => $counts,
				'spelled' => array( 'mass' => array( 'mass' => 2 ) ),
			),
		);

		// Both groups use "mass" far more than the rest of the site, but only
		// the first is known for it.
		$this->assertSame( array(), undergrowth_find_threads( $groups, array( 'mass' => 10 ) + $rare, 100 ) );
		// Either group may be the one that is not.
		$this->assertSame(
			array(),
			undergrowth_find_threads(
				array(
					5  => $groups[20],
					10 => $groups[10],
				),
				array( 'mass' => 10 ) + $rare,
				100
			)
		);

		unset( $groups[20]['counts']['rare9'], $groups[20]['counts']['rare10'] );

		$this->assertSame( array( '10-20' => array( 'mass' ) ), undergrowth_find_threads( $groups, array( 'mass' => 10 ) + $rare, 100 ) );
	}

	public function test_labelling_stores_threads_from_the_authors_words() {
		$a = $this->group(
			array(
				'Oca'    => array( 1.0, 0.0, 0.0, 0.0 ),
				'Ulluco' => array( 1.0, 0.0, 0.0, 0.0 ),
				'Mashua' => array( 1.0, 0.0, 0.0, 0.0 ),
			)
		);
		$b = $this->group(
			array(
				'Ledger'  => array( 0.0, 1.0, 0.0, 0.0 ),
				'Invoice' => array( 0.0, 1.0, 0.0, 0.0 ),
				'Receipt' => array( 0.0, 1.0, 0.0, 0.0 ),
			)
		);

		foreach ( array( $a[0], $a[1], $b[0], $b[1] ) as $post_id ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => 'Notes on patience and Moonlight.',
				)
			);
		}

		undergrowth_start_cluster_labels();
		undergrowth_label_clusters();

		$labels = get_option( 'undergrowth_cluster_labels' );

		$this->assertContains( 'Moonlight', $labels['threads'][ undergrowth_pollination_key( $a[0], $b[0] ) ] );

		undergrowth_start_pollination();
		$this->run_batches();

		$pairs = undergrowth_pollination_pairs( 0, 1 );

		$this->assertCount( 1, $pairs );
		$this->assertContains( 'Moonlight', $pairs[0]['threads'] );
	}

	public function test_cluster_pass_ends_by_finding_pairs() {
		$this->two_groups( 0.46 );

		undergrowth_run_cluster_cycle( 0 );
		$this->run_batches();

		$stored = get_option( 'undergrowth_pollination' );

		$this->assertIsArray( $stored );
		$this->assertSame( undergrowth_cluster_labels_made(), $stored['made'] );
	}

	public function test_map_view_shows_each_pair_with_reasons_and_a_dismiss_button() {
		list( $a, $b ) = $this->two_groups( 0.46 );
		$bridge        = $this->seed( 'Roots and ledgers', array( 1.0, 1.0, 0.0, 0.0 ) );
		$this->find();

		$_GET['tab'] = 'map';
		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();
		$html = (string) ob_get_clean();

		$id = 'undergrowth-pollination-' . $a[0] . '-' . $b[0];

		$this->assertStringContainsString( 'id="undergrowth-pollination"', $html );
		$this->assertStringContainsString( 'id="' . $id . '"', $html );
		$this->assertStringContainsString( 'are about different things, but not far apart.', $html );
		$this->assertMatchesRegularExpression( '#post=' . $bridge . '&(?:amp|\#038);action=edit#', $html );
		$this->assertStringContainsString( 'would link both halves of your garden.', $html );
		$this->assertStringContainsString( 'value="undergrowth_dismiss_pollination"', $html );
		$this->assertStringContainsString( 'aria-describedby="' . $id . '"', $html );
		$this->assertStringContainsString( '<strong>Cautious</strong>', $html );
		// Many forms on one screen never repeat core's nonce id.
		$this->assertStringNotContainsString( 'id="_wpnonce" name="_wpnonce" value="' . wp_create_nonce( 'undergrowth_dismiss_pollination_' . $a[0] . '_' . $b[0] ), $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( 'undergrowth_dismiss_pollination_' . $a[0] . '_' . $b[0] ) . '"', $html );
	}

	public function test_map_view_folds_the_pairs_after_the_tenth() {
		$leaders = array();
		$threads = array();

		for ( $i = 0; $i < 6; $i++ ) {
			$vector       = array( 0.0, 0.0, 0.0, 0.0, 0.0, 0.0 );
			$vector[ $i ] = 1.0;
			$leaders[]    = $this->group(
				array(
					'First ' . $i  => $vector,
					'Second ' . $i => $vector,
					'Third ' . $i  => $vector,
				)
			)[0];
		}

		foreach ( $leaders as $i => $a ) {
			foreach ( array_slice( $leaders, $i + 1 ) as $b ) {
				$threads[ undergrowth_pollination_key( $a, $b ) ] = array( 'moonlight' );
			}
		}

		update_option(
			'undergrowth_cluster_labels',
			array(
				'made'    => current_time( 'mysql', true ),
				'terms'   => array(),
				'threads' => $threads,
			)
		);

		ob_start();
		undergrowth_render_pollination_view();
		$html = (string) ob_get_clean();

		list( $shown, $folded ) = explode( '<details', $html ) + array( '', '' );

		$this->assertSame( 10, substr_count( $shown, 'class="undergrowth-pollination-pair"' ) );
		$this->assertSame( 5, substr_count( $folded, 'class="undergrowth-pollination-pair"' ) );
		$this->assertStringContainsString( '<summary>Show all 15 pairs</summary>', $folded );
	}

	public function test_map_view_says_so_when_no_pair_is_found() {
		$this->two_groups( 0.0 );
		$this->find();

		ob_start();
		undergrowth_render_pollination_view();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'undergrowth-pollination-none', $html );
		$this->assertStringNotContainsString( '<ul class="undergrowth-pollination"', $html );
	}

	public function test_map_with_one_group_has_no_view() {
		$this->group(
			array(
				'Oca'    => array( 1.0, 0.0, 0.0, 0.0 ),
				'Ulluco' => array( 1.0, 0.0, 0.0, 0.0 ),
				'Mashua' => array( 1.0, 0.0, 0.0, 0.0 ),
			)
		);

		ob_start();
		undergrowth_render_map_tab();

		$this->assertStringNotContainsString( 'undergrowth-pollination', (string) ob_get_clean() );
	}

	/**
	 * Posts a dismissal through its handler and returns where it went.
	 *
	 * @param int    $a     Leader ID.
	 * @param int    $b     Leader ID.
	 * @param string $nonce Nonce, or an empty string for the right one.
	 * @return string
	 * @throws WPDieException When the handler refuses.
	 */
	private function dismiss( int $a, int $b, string $nonce = '' ): string {
		$nonce                = '' === $nonce ? wp_create_nonce( 'undergrowth_dismiss_pollination_' . $a . '_' . $b ) : $nonce;
		$_POST['a']           = (string) $a;
		$_POST['b']           = (string) $b;
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		try {
			undergrowth_handle_dismiss_pollination();
		} catch ( WPDieException $e ) {
			throw $e;
		} catch ( Exception $e ) {
			return $e->getMessage();
		}

		return '';
	}

	public function test_dismiss_handler_stores_the_dismissal_and_returns_to_the_view() {
		list( $a, $b ) = $this->two_groups( 0.46 );
		$this->find();

		$url = $this->dismiss( $a[0], $b[0] );

		$this->assertStringContainsString( 'undergrowth_notice=pollination_dismissed', $url );
		$this->assertStringEndsWith( '#undergrowth-pollination', $url );
		$this->assertStringContainsString( 'undergrowth_notice=pollination_gone', $this->dismiss( $a[0], $b[0] ) );
	}

	public function test_dismiss_handler_refuses_a_wrong_nonce_or_an_author() {
		list( $a, $b ) = $this->two_groups( 0.46 );
		$this->find();

		try {
			$this->dismiss( $a[0], $b[0], 'wrong' );
			$this->fail( 'A wrong nonce went through.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), undergrowth_dismissed_pollination() );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->expectException( WPDieException::class );

		$this->dismiss( $a[0], $b[0] );
	}

	public function test_settings_show_the_scale_with_the_current_step_chosen() {
		update_option( 'undergrowth_surprise', 4 );

		ob_start();
		undergrowth_render_surprise_field();
		$html = (string) ob_get_clean();

		$this->assertSame( 5, substr_count( $html, 'type="radio"' ) );
		$this->assertMatchesRegularExpression( '#id="undergrowth_surprise_4" name="undergrowth_surprise" value="4"\s*checked#', $html );
		$this->assertStringContainsString( '<legend class="screen-reader-text">Surprise scale</legend>', $html );
	}
}
