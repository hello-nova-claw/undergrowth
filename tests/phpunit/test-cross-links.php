<?php
/**
 * Tests for cross-pollination link suggestions across Map groups.
 *
 * Below 20 posts the fixed thresholds apply, so the band at the default
 * step runs from 0.45 up to 0.5, and links are proposed from 0.4. Receipt
 * sits at 0.46 to the first group and 0.888 to its own, whose three other
 * posts fill its three usual suggestions.
 *
 * @package Undergrowth
 */

/**
 * Cross-pollination links.
 */
class Test_Cross_Links extends WP_UnitTestCase {

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
		$this->start_planting();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * Publishes posts with vectors and content and puts them in one group,
	 * led by the first.
	 *
	 * @param array<string, array{0: float[], 1: string}> $posts Vector and content, keyed by title.
	 * @return int[] Post IDs, in order.
	 */
	private function group( array $posts ): array {
		$ids = array();

		foreach ( $posts as $title => $post ) {
			$ids[] = $this->seed( $title, $post[0], array( 'post_content' => $post[1] ) );
		}

		foreach ( $ids as $post_id ) {
			update_post_meta( $post_id, 'undergrowth_cluster', $ids[0] );
		}

		return $ids;
	}

	/**
	 * Returns a unit vector at a similarity to the first axis, turned
	 * towards the second.
	 *
	 * @param float $cosine Similarity to the first axis.
	 * @return float[]
	 */
	private function turned( float $cosine ): array {
		return array( $cosine, sqrt( 1 - $cosine * $cosine ), 0.0 );
	}

	/**
	 * Makes two groups, with Receipt in the second near the first.
	 *
	 * @param float $cosine Similarity of Receipt to the first group.
	 * @return array<string, int> Post IDs keyed by title.
	 */
	private function garden( float $cosine = 0.46 ): array {
		$a = $this->group(
			array(
				'Oca'    => array( array( 1.0, 0.0, 0.0 ), '<p>Oca grows in cold soil.</p>' ),
				'Ulluco' => array( array( 1.0, 0.0, 0.0 ), '<p>Ulluco grows in cold soil.</p>' ),
				'Mashua' => array( array( 1.0, 0.0, 0.0 ), '<p>Mashua grows in cold soil.</p>' ),
			)
		);
		$b = $this->group(
			array(
				'Ledger'  => array( array( 0.0, 1.0, 0.0 ), '<p>A ledger of the year.</p>' ),
				'Invoice' => array( array( 0.0, 1.0, 0.0 ), '<p>An invoice for seed.</p>' ),
				'Bill'    => array( array( 0.0, 1.0, 0.0 ), '<p>A bill for the plot.</p>' ),
				'Receipt' => array( $this->turned( $cosine ), '<p>The ledger shows it. The invoice came late. The bill was paid. Oca seed was on the receipt.</p>' ),
			)
		);

		return array_combine( array( 'Oca', 'Ulluco', 'Mashua', 'Ledger', 'Invoice', 'Bill', 'Receipt' ), array_merge( $a, $b ) );
	}

	/**
	 * Finds the pairs as the end of a cluster pass would.
	 */
	private function find(): void {
		undergrowth_start_cluster_labels();
		undergrowth_start_pollination();

		$record = get_option( 'undergrowth_pollination_cycle_pass' );
		undergrowth_run_pollination_cycle( $record['after'], $record['pass'] );
	}

	/**
	 * Returns a post's proposed rows, by target.
	 *
	 * @param int $source_id Source post ID.
	 * @return array<int, string> Kind of each proposal, keyed by target ID.
	 */
	private function proposals( int $source_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT target_id, kind FROM %i WHERE source_id = %d AND state = %s', undergrowth_links_table(), $source_id, 'proposed' ), ARRAY_A );

		return array_map( 'strval', array_column( $rows, 'kind', 'target_id' ) );
	}

	/**
	 * Returns the targets of a post's cross-pollination suggestions.
	 *
	 * @param int $source_id Source post ID.
	 * @return int[]
	 */
	private function crossed( int $source_id ): array {
		return array_map( 'intval', array_keys( array_filter( $this->proposals( $source_id ), static fn( $kind ) => 'cross' === $kind ) ) );
	}

	public function test_post_in_a_sweet_spot_pair_gets_one_cross_link_on_top_of_its_three() {
		$posts = $this->garden();
		$this->find();

		undergrowth_refresh_proposals( $posts['Receipt'] );

		$proposals = $this->proposals( $posts['Receipt'] );

		$this->assertCount( 4, $proposals );
		$this->assertSame( array( $posts['Oca'] ), $this->crossed( $posts['Receipt'] ) );
		$this->assertSame( '', $proposals[ $posts['Ledger'] ] );
	}

	public function test_no_post_gets_more_than_one_cross_link() {
		$posts = $this->garden();
		$this->find();

		foreach ( $posts as $post_id ) {
			undergrowth_refresh_proposals( $post_id );
			$this->assertLessThanOrEqual( 1, count( $this->crossed( $post_id ) ) );
		}

		// The group's three posts are all at the same distance from Receipt;
		// the lowest ID is offered.
		$this->assertSame( array( $posts['Oca'] ), $this->crossed( $posts['Receipt'] ) );
		// Oca's own group leaves room among its usual three, so the link
		// back is a usual one.
		$this->assertSame( '', $this->proposals( $posts['Oca'] )[ $posts['Receipt'] ] );
	}

	public function test_pair_outside_the_band_of_the_step_gets_no_cross_link() {
		$posts = $this->garden( 0.43 );
		$this->find();

		undergrowth_refresh_proposals( $posts['Receipt'] );

		$this->assertSame( array(), $this->crossed( $posts['Receipt'] ) );

		update_option( 'undergrowth_surprise', 3 );
		undergrowth_refresh_proposals( $posts['Receipt'] );

		$this->assertSame( array( $posts['Oca'] ), $this->crossed( $posts['Receipt'] ) );
	}

	public function test_changing_the_step_refreshes_the_posts_it_affects() {
		$posts = $this->garden();
		$this->find();
		undergrowth_refresh_proposals( $posts['Receipt'] );
		wp_unschedule_hook( 'undergrowth_refresh_sources' );

		update_option( 'undergrowth_surprise', 1 );

		$event = $this->refresh_event();

		$this->assertNotNull( $event );
		// Receipt holds one now; at step 1 nobody could be offered one.
		$this->assertSame( array( $posts['Receipt'] ), $event );

		undergrowth_refresh_sources( $event );

		$this->assertSame( array(), $this->crossed( $posts['Receipt'] ) );
	}

	/**
	 * Returns the posts of the queued refresh, or null.
	 *
	 * @return int[]|null
	 */
	private function refresh_event(): ?array {
		foreach ( _get_cron_array() as $hooks ) {
			foreach ( $hooks['undergrowth_refresh_sources'] ?? array() as $event ) {
				return array_map( 'intval', $event['args'][0] );
			}
		}

		return null;
	}

	public function test_end_of_a_pass_refreshes_every_post_offered_one() {
		$posts = $this->garden();
		wp_unschedule_hook( 'undergrowth_refresh_sources' );
		$this->find();

		$event = $this->refresh_event();

		$this->assertContains( $posts['Receipt'], $event );
		$this->assertContains( $posts['Oca'], $event );
	}

	public function test_vetoed_cross_link_is_never_proposed_again() {
		$posts = $this->garden();
		$this->find();
		undergrowth_refresh_proposals( $posts['Receipt'] );

		$this->assertTrue( undergrowth_veto_link( $posts['Receipt'], $posts['Oca'] ) );

		undergrowth_refresh_proposals( $posts['Receipt'] );

		$this->assertSame( array(), $this->crossed( $posts['Receipt'] ) );
		$this->assertSame( 'vetoed', undergrowth_link_state( $posts['Receipt'], $posts['Oca'] ) );
	}

	public function test_accepted_or_written_link_is_not_offered_again() {
		$posts = $this->garden();
		$this->find();

		wp_update_post(
			array(
				'ID'           => $posts['Receipt'],
				'post_content' => '<p>The ledger shows it. The invoice came late. The bill was paid. <a href="' . esc_url( get_permalink( $posts['Oca'] ) ) . '">Oca</a> seed was on the receipt.</p>',
			)
		);
		undergrowth_refresh_proposals( $posts['Receipt'] );

		$this->assertSame( array(), $this->crossed( $posts['Receipt'] ) );
	}

	public function test_composting_post_is_never_offered_one() {
		$posts = $this->garden();
		$this->find();

		update_post_meta( $posts['Receipt'], 'undergrowth_stage', 'composting' );

		$this->assertNull( undergrowth_cross_link_target( $posts['Receipt'], array() ) );
	}

	public function test_target_already_among_the_usual_suggestions_is_not_doubled() {
		$posts = $this->garden();
		$this->find();

		$this->assertNull( undergrowth_cross_link_target( $posts['Receipt'], array( $posts['Oca'] ) ) );
	}

	public function test_target_that_cannot_receive_a_link_is_not_offered() {
		$posts = $this->garden();
		$this->find();

		update_post_meta( $posts['Oca'], 'undergrowth_stage', 'composting' );

		$this->assertNull( undergrowth_cross_link_target( $posts['Receipt'], array() ) );
	}

	public function test_dismissing_the_pair_takes_its_cross_links_away() {
		$posts = $this->garden();
		$this->find();
		undergrowth_refresh_proposals( $posts['Receipt'] );
		wp_unschedule_hook( 'undergrowth_refresh_sources' );

		$this->assertTrue( undergrowth_dismiss_pollination( $posts['Oca'], $posts['Ledger'] ) );

		undergrowth_refresh_sources( $this->refresh_event() );

		$this->assertSame( array(), $this->crossed( $posts['Receipt'] ) );
	}

	public function test_cross_link_that_becomes_a_usual_one_loses_its_label() {
		$posts = $this->garden();
		$this->find();
		undergrowth_refresh_proposals( $posts['Receipt'] );

		wp_delete_post( $posts['Bill'], true );
		undergrowth_refresh_proposals( $posts['Receipt'] );

		$proposals = $this->proposals( $posts['Receipt'] );

		$this->assertSame( '', $proposals[ $posts['Oca'] ] );
	}

	public function test_accepting_a_cross_link_works_as_for_any_suggestion() {
		$posts = $this->garden();
		$this->find();
		undergrowth_refresh_proposals( $posts['Receipt'] );

		$this->assertSame( 'accepted', undergrowth_accept_link( $posts['Receipt'], $posts['Oca'] ) );
		$this->assertStringContainsString( get_permalink( $posts['Oca'] ), get_post( $posts['Receipt'] )->post_content );
	}

	public function test_suggestions_list_labels_the_cross_link_with_the_shared_words() {
		$posts = $this->garden();
		$this->find();

		// The two groups share a rare word, as the Map's labels find it.
		$labels            = get_option( 'undergrowth_cluster_labels' );
		$labels['threads'] = array( undergrowth_pollination_key( $posts['Oca'], $posts['Ledger'] ) => array( 'seed' ) );
		update_option( 'undergrowth_cluster_labels', $labels );

		undergrowth_refresh_proposals( $posts['Receipt'] );

		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();
		$html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $html, 'class="undergrowth-cross"' ) );
		$this->assertStringContainsString( '<strong>Cross-pollination.</strong>', $html );
		$this->assertStringContainsString( 'both groups use <bdi>seed</bdi>.', $html );
	}

	public function test_label_without_shared_words_says_why_the_posts_were_paired() {
		$posts = $this->garden();
		$this->find();

		$this->assertStringContainsString( 'they are not far apart themselves.', undergrowth_cross_link_label( $posts['Receipt'], $posts['Oca'] ) );
		// A row from before the pairs changed is still labelled.
		$this->assertStringContainsString( 'Cross-pollination.', undergrowth_cross_link_label( $posts['Ledger'], $posts['Oca'] ) );
	}
}
