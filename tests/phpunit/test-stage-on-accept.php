<?php
/**
 * Tests for measuring a growth stage when a link is accepted.
 *
 * @package Undergrowth
 */

/**
 * Accepting a link measures the stage of both its posts at once.
 */
class Test_Stage_On_Accept extends WP_UnitTestCase {

	use Plants_Posts;

	/**
	 * Posts whose stage changed during a test, one entry per change.
	 *
	 * @var int[]
	 */
	private $changed = array();

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		// The test case unregisters every meta key after each test.
		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	public function tear_down() {
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Publishes Oca, whose text can link to Yacon and to Mashua, and both
	 * of those.
	 *
	 * @return int[] Oca's ID, then Yacon's, then Mashua's.
	 */
	private function garden(): array {
		$oca    = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca grows here. I keep yacon plants nearby. Mashua climbs the fence.</p>' ) );
		$yacon  = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$mashua = $this->plant( 'Mashua', array( 0.8, 0.2, 0.0 ) );

		$this->row( $oca, $yacon, 'proposed', 'I keep yacon plants nearby.' );
		$this->row( $oca, $mashua, 'proposed', 'Mashua climbs the fence.' );

		return array( $oca, $yacon, $mashua );
	}

	/**
	 * Stores a link row, in place of any the posts already have.
	 *
	 * @param int    $source_id Post the link sits in.
	 * @param int    $target_id Post the link points to.
	 * @param string $state     Row state.
	 * @param string $sentence  Sentence the link fits in.
	 */
	private function row( int $source_id, int $target_id, string $state, string $sentence = '' ): void {
		global $wpdb;

		$wpdb->replace(
			undergrowth_links_table(),
			array(
				'source_id'  => $source_id,
				'target_id'  => $target_id,
				'state'      => $state,
				'score'      => 0.9,
				'sentence'   => $sentence,
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Notes a post whose stage changed.
	 *
	 * @param int $post_id Post ID.
	 */
	public function note_change( $post_id ): void {
		$this->changed[] = (int) $post_id;
	}

	public function test_one_accepted_link_leaves_both_posts_seedlings() {
		list( $oca, $yacon ) = $this->garden();

		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $yacon ) );

		$this->assertSame( 'seedling', undergrowth_get_stage( $oca ) );
		$this->assertSame( 'seedling', undergrowth_get_stage( $yacon ) );
		$this->assertSame( array(), undergrowth_stage_log( $oca ) );
		$this->assertSame( array(), undergrowth_stage_log( $yacon ) );
	}

	public function test_second_accepted_link_makes_the_source_a_sprout_at_once() {
		list( $oca, $yacon, $mashua ) = $this->garden();

		undergrowth_accept_link( $oca, $yacon );
		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $mashua ) );

		$this->assertSame( 'sprout', undergrowth_get_stage( $oca ) );
		$this->assertSame( 'seedling', undergrowth_get_stage( $mashua ) );
	}

	public function test_accepted_link_measures_its_target_too() {
		list( $oca, $yacon, $mashua ) = $this->garden();
		$this->row( $mashua, $yacon, 'accepted' );

		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $yacon ) );

		$this->assertSame( 'sprout', undergrowth_get_stage( $yacon ) );
		$this->assertSame( 'seedling', undergrowth_get_stage( $oca ) );
	}

	public function test_a_post_in_a_group_with_links_both_ways_becomes_budding_at_once() {
		list( $oca, $yacon, $mashua ) = $this->garden();

		foreach ( array( $oca, $yacon, $mashua ) as $post_id ) {
			update_post_meta( $post_id, 'undergrowth_cluster', $oca );
		}
		$this->row( $yacon, $oca, 'accepted' );

		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $mashua ) );

		$this->assertSame( 'budding', undergrowth_get_stage( $oca ) );
	}

	public function test_a_pinned_post_keeps_its_stage() {
		list( $oca, $yacon, $mashua ) = $this->garden();

		update_post_meta( $oca, 'undergrowth_stage_pinned', '1' );
		undergrowth_set_stage( $oca, 'seedling', 'pin' );

		undergrowth_accept_link( $oca, $yacon );
		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $mashua ) );

		$this->assertSame( 'seedling', undergrowth_get_stage( $oca ) );
	}

	public function test_a_post_pinned_in_another_request_keeps_its_stage() {
		list( $oca, $yacon, $mashua ) = $this->garden();

		undergrowth_accept_link( $oca, $yacon );
		// The meta read for this request is cached before the pin is stored.
		$this->assertFalse( undergrowth_is_stage_pinned( $oca ) );
		$GLOBALS['wpdb']->insert(
			$GLOBALS['wpdb']->postmeta,
			array(
				'post_id'    => $oca,
				'meta_key'   => 'undergrowth_stage_pinned', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One row, in a test.
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One row, in a test.
			)
		);

		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $mashua ) );

		$this->assertSame( 'seedling', undergrowth_get_stage( $oca ) );
	}

	public function test_a_composting_post_keeps_its_stage() {
		list( $oca, $yacon, $mashua ) = $this->garden();
		$this->row( $mashua, $yacon, 'accepted' );
		undergrowth_set_stage( $yacon, 'composting', 'compost' );

		undergrowth_accept_link( $oca, $yacon );

		$this->assertSame( 'composting', undergrowth_get_stage( $yacon ) );
	}

	public function test_accept_never_lowers_a_stage() {
		list( $oca, $yacon ) = $this->garden();
		undergrowth_set_stage( $oca, 'evergreen' );
		undergrowth_set_stage( $yacon, 'budding' );

		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $yacon ) );

		$this->assertSame( 'evergreen', undergrowth_get_stage( $oca ) );
		$this->assertSame( 'budding', undergrowth_get_stage( $yacon ) );
	}

	public function test_an_accept_that_fails_changes_no_stage() {
		list( $oca, $yacon, $mashua ) = $this->garden();

		// Two links that would make Oca a sprout if it were measured.
		$this->row( $oca, $mashua, 'accepted' );
		$this->row( $mashua, $oca, 'accepted' );
		$this->row( $oca, $yacon, 'proposed', 'A sentence that is not in the post.' );

		$this->assertSame( 'edited', undergrowth_accept_link( $oca, $yacon ) );

		$this->assertSame( 'seedling', undergrowth_get_stage( $oca ) );
		$this->assertSame( array(), undergrowth_stage_log( $oca ) );
	}

	public function test_the_change_is_recorded_with_its_reason() {
		list( $oca, $yacon, $mashua ) = $this->garden();

		undergrowth_accept_link( $oca, $yacon );
		undergrowth_accept_link( $oca, $mashua );

		$log = undergrowth_stage_log( $oca );

		$this->assertCount( 1, $log );
		$this->assertSame( 'seedling', $log[0]['from'] );
		$this->assertSame( 'sprout', $log[0]['to'] );
		$this->assertSame( 'accept', $log[0]['reason'] );
	}

	public function test_the_post_file_says_why_the_stage_changed() {
		list( $oca, $yacon, $mashua ) = $this->garden();

		undergrowth_accept_link( $oca, $yacon );
		undergrowth_accept_link( $oca, $mashua );

		$this->assertStringContainsString(
			'- ' . wp_date( 'Y-m-d', null, new DateTimeZone( 'UTC' ) ) . ': seedling -> sprout (accepted a link)',
			(string) file_get_contents( undergrowth_memory_path( $oca ) )
		);
	}

	public function test_the_stage_change_fires_once_for_each_post_that_changed() {
		list( $oca, $yacon, $mashua ) = $this->garden();
		$this->row( $mashua, $yacon, 'accepted' );
		$this->row( $mashua, $oca, 'accepted' );

		add_action( 'undergrowth_stage_changed', array( $this, 'note_change' ) );
		undergrowth_accept_link( $oca, $yacon );
		remove_action( 'undergrowth_stage_changed', array( $this, 'note_change' ) );

		sort( $this->changed );
		$expected = array( $oca, $yacon );
		sort( $expected );

		$this->assertSame( $expected, $this->changed );
	}

	public function test_the_monthly_cycle_still_measures_what_accept_left() {
		list( $oca, $yacon, $mashua ) = $this->garden();

		undergrowth_accept_link( $oca, $yacon );
		undergrowth_accept_link( $oca, $mashua );
		undergrowth_run_stage_cycle();

		$this->assertSame( 'sprout', undergrowth_get_stage( $oca ) );
		$this->assertCount( 1, undergrowth_stage_log( $oca ) );
	}
}
