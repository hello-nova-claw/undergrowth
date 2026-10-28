<?php
/**
 * Tests for ranking link targets between posts.
 *
 * @package Undergrowth
 */

/**
 * Self-trade link proposals.
 */
class Test_Links extends WP_UnitTestCase {

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
	}

	/**
	 * Sets the state of a link row, creating it when needed.
	 *
	 * @param int    $source_id Source post ID.
	 * @param int    $target_id Target post ID.
	 * @param string $state     New state.
	 */
	private function set_state( int $source_id, int $target_id, string $state ): void {
		global $wpdb;

		$wpdb->replace(
			undergrowth_links_table(),
			array(
				'source_id'  => $source_id,
				'target_id'  => $target_id,
				'state'      => $state,
				'score'      => 0.9,
				'sentence'   => '',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);
	}

	public function test_cosine_of_identical_vectors_is_one() {
		$this->assertEqualsWithDelta( 1.0, undergrowth_cosine( array( 1.0, 2.0, 3.0 ), array( 1.0, 2.0, 3.0 ) ), 1e-9 );
	}

	public function test_cosine_of_orthogonal_vectors_is_zero() {
		$this->assertEqualsWithDelta( 0.0, undergrowth_cosine( array( 1.0, 0.0 ), array( 0.0, 1.0 ) ), 1e-9 );
	}

	public function test_cosine_of_mismatched_or_empty_vectors_is_zero() {
		$this->assertSame( 0.0, undergrowth_cosine( array( 1.0, 0.0 ), array( 1.0, 0.0, 0.0 ) ) );
		$this->assertSame( 0.0, undergrowth_cosine( array( 0.0, 0.0 ), array( 1.0, 0.0 ) ) );
	}

	public function test_links_table_has_one_row_per_pair_with_state_sentence_and_anchor() {
		global $wpdb;

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', undergrowth_links_table() ) );

		$this->assertSame( array( 'id', 'source_id', 'target_id', 'state', 'score', 'sentence', 'anchor', 'anchor_offset', 'anchor_chosen', 'kind', 'created_at', 'updated_at' ), $columns );
	}

	public function test_closest_posts_are_proposed_best_first() {
		$oca    = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon  = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$mashua = $this->plant( 'Mashua', array( 0.8, 0.3, 0.0 ) );

		$this->assertSame( array( $yacon, $mashua ), $this->proposed( $oca ) );
	}

	public function test_post_is_never_proposed_to_itself() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$this->assertNotContains( $oca, $this->proposed( $oca ) );
	}

	public function test_at_most_three_targets_are_proposed() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		foreach ( array( 'Yacon', 'Mashua', 'Ulluco', 'Achira', 'Arracacha' ) as $i => $title ) {
			$this->plant( $title, array( 1.0, 0.05 * ( $i + 1 ), 0.0 ) );
		}

		$this->assertCount( 3, $this->proposed( $oca ) );
	}

	public function test_two_suggestions_never_sit_on_the_same_words_of_one_sentence() {
		global $wpdb;

		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Rocky valley tubers grow slowly.</p>' ) );
		// Both share "rocky valley" with the one sentence; only Ulluco also
		// shares other words, and Yacon is nearer, so it chooses first.
		$yacon  = $this->plant( 'Yacon', array( 0.95, 0.05, 0.0 ), array( 'post_content' => '<p>A rocky valley.</p>' ) );
		$ulluco = $this->plant( 'Ulluco', array( 0.9, 0.0, 0.1 ), array( 'post_content' => '<p>A rocky valley where tubers grow slowly.</p>' ) );

		undergrowth_refresh_proposals( $oca );

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT target_id, sentence, anchor, anchor_offset FROM %i WHERE source_id = %d AND state = %s', undergrowth_links_table(), $oca, 'proposed' ), ARRAY_A );
		$rows = array_column( $rows, null, 'target_id' );

		$this->assertSame( 'rocky valley', strtolower( $rows[ $yacon ]['anchor'] ) );
		$this->assertArrayHasKey( $ulluco, $rows );
		$this->assertSame( $rows[ $yacon ]['sentence'], $rows[ $ulluco ]['sentence'] );
		$this->assertNotSame( '', $rows[ $ulluco ]['anchor'] );
		// Ulluco's words start after Yacon's end, or end before they start.
		$yacon_end  = (int) $rows[ $yacon ]['anchor_offset'] + mb_strlen( $rows[ $yacon ]['anchor'] );
		$ulluco_end = (int) $rows[ $ulluco ]['anchor_offset'] + mb_strlen( $rows[ $ulluco ]['anchor'] );
		$this->assertTrue( (int) $rows[ $ulluco ]['anchor_offset'] >= $yacon_end || $ulluco_end <= (int) $rows[ $yacon ]['anchor_offset'] );
	}

	public function test_a_sentence_whose_only_shared_words_are_taken_is_skipped() {
		global $wpdb;

		$oca    = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Rocky valley tubers grow slowly.</p>' ) );
		$yacon  = $this->plant( 'Yacon', array( 0.95, 0.05, 0.0 ), array( 'post_content' => '<p>A rocky valley.</p>' ) );
		$mashua = $this->plant( 'Mashua', array( 0.9, 0.0, 0.1 ), array( 'post_content' => '<p>The rocky valley.</p>' ) );

		undergrowth_refresh_proposals( $oca );

		$targets = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT target_id FROM %i WHERE source_id = %d AND state = %s', undergrowth_links_table(), $oca, 'proposed' ) ) );

		$this->assertSame( array( $yacon ), $targets );
		$this->assertNotContains( $mashua, $targets );
	}

	public function test_a_site_on_the_earlier_rules_chooses_its_suggestions_again() {
		update_option( 'undergrowth_index_identity', undergrowth_index_target(), false );
		update_option( 'undergrowth_anchor_rules', 2 );
		wp_clear_scheduled_hook( 'undergrowth_refresh_index', array( 0 ) );

		undergrowth_maybe_refresh_anchors();

		$this->assertSame( 3, (int) get_option( 'undergrowth_anchor_rules' ) );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_refresh_index', array( 0 ) ) );
	}

	/**
	 * Counts the refreshes of every suggestion waiting in cron.
	 *
	 * @return int
	 */
	private function queued_index_refreshes(): int {
		$count = 0;

		foreach ( (array) _get_cron_array() as $hooks ) {
			$count += count( $hooks['undergrowth_refresh_index'] ?? array() );
		}

		return $count;
	}

	public function test_turning_suggestions_back_on_chooses_them_again_once() {
		update_option( 'undergrowth_index_identity', undergrowth_index_target(), false );
		update_option( 'undergrowth_suggest_links', '0' );
		wp_clear_scheduled_hook( 'undergrowth_refresh_index', array( 0 ) );

		// Rules that changed while suggestions were off are recorded, but
		// the refresh they queue changes nothing until suggestions are on.
		update_option( 'undergrowth_anchor_rules', UNDERGROWTH_ANCHOR_RULES - 1 );
		undergrowth_maybe_refresh_anchors();
		wp_clear_scheduled_hook( 'undergrowth_refresh_index', array( 0 ) );

		update_option( 'undergrowth_suggest_links', '1' );

		$this->assertSame( 1, $this->queued_index_refreshes() );

		// Off and on again before cron runs queues no second refresh.
		update_option( 'undergrowth_suggest_links', '0' );
		update_option( 'undergrowth_suggest_links', '1' );

		$this->assertSame( 1, $this->queued_index_refreshes() );
	}

	public function test_turning_suggestions_off_queues_nothing() {
		update_option( 'undergrowth_index_identity', undergrowth_index_target(), false );
		update_option( 'undergrowth_suggest_links', '1' );
		wp_clear_scheduled_hook( 'undergrowth_refresh_index', array( 0 ) );

		update_option( 'undergrowth_suggest_links', '0' );

		$this->assertSame( 0, $this->queued_index_refreshes() );
	}

	public function test_turning_suggestions_on_while_the_site_is_still_read_queues_nothing() {
		delete_option( 'undergrowth_index_identity' );
		update_option( 'undergrowth_suggest_links', '0' );
		wp_clear_scheduled_hook( 'undergrowth_refresh_index', array( 0 ) );

		update_option( 'undergrowth_suggest_links', '1' );

		$this->assertSame( 0, $this->queued_index_refreshes() );
	}

	public function test_dissimilar_posts_are_not_proposed() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Tax returns', array( 0.0, 0.0, 1.0 ) );

		$this->assertSame( array(), $this->proposed( $oca ) );
	}

	public function test_minimum_similarity_is_filterable() {
		add_filter(
			'undergrowth_min_link_similarity',
			static function () {
				return 0.99;
			}
		);
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.3, 0.0 ) );

		$this->assertSame( array(), $this->proposed( $oca ) );
	}

	public function test_vectors_from_another_model_are_never_compared() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$other = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		undergrowth_save_vector(
			$other,
			array( 1.0, 0.0, 0.0 ),
			array(
				'provider'     => 'test-provider',
				'model'        => 'another-model',
				'language'     => get_locale(),
				'content_hash' => str_repeat( 'b', 64 ),
			)
		);

		undergrowth_refresh_proposals( $oca );

		$this->assertNotContains( $other, $this->proposed( $oca ) );
	}

	public function test_vectors_in_another_language_are_never_compared() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$other = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		undergrowth_save_vector(
			$other,
			array( 1.0, 0.0, 0.0 ),
			array(
				'provider'     => 'test-provider',
				'model'        => 'test-model',
				'language'     => 'de_DE',
				'content_hash' => str_repeat( 'b', 64 ),
			)
		);

		undergrowth_refresh_proposals( $oca );

		$this->assertNotContains( $other, $this->proposed( $oca ) );
	}

	public function test_post_already_linked_from_the_source_is_not_proposed() {
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$oca   = $this->plant(
			'Oca',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => 'See <a href="' . esc_url( get_permalink( $yacon ) ) . '">yacon</a>.' )
		);

		$this->assertNotContains( $yacon, $this->proposed( $oca ) );
	}

	public function test_link_without_quotes_is_found() {
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		// Saving would add the quotes, so the post is read as it was written.
		$post = new WP_Post( (object) array( 'post_content' => 'See <a href=' . get_permalink( $yacon ) . '>yacon</a>.' ) );

		$this->assertSame( array( $yacon ), undergrowth_linked_post_ids( $post ) );
	}

	public function test_link_inside_a_comment_or_an_attribute_is_no_link() {
		// An author with unfiltered HTML keeps the comment as written.
		kses_remove_filters();

		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$url   = esc_url( get_permalink( $yacon ) );
		$oca   = $this->plant(
			'Oca',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => '<!-- <a href="' . $url . '">old</a> --><p data-note=\'href="' . $url . '"\'>Oca and yacon.</p>' )
		);

		$this->assertSame( array(), undergrowth_linked_post_ids( get_post( $oca ) ) );
		$this->assertContains( $yacon, $this->proposed( $oca ) );
	}

	public function test_link_address_with_entities_is_decoded_once() {
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$oca   = self::factory()->post->create(
			array(
				'post_content' => '<a href="' . str_replace( '?', '&#63;', get_permalink( $yacon ) ) . '">yacon</a>',
			)
		);

		$this->assertSame( array( $yacon ), undergrowth_linked_post_ids( get_post( $oca ) ) );
	}

	public function test_vetoed_pair_is_never_proposed_again() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->set_state( $oca, $yacon, 'vetoed' );

		undergrowth_refresh_proposals( $oca );

		$this->assertNotContains( $yacon, $this->proposed( $oca ) );
		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_veto_result_names_what_the_veto_did() {
		$oca    = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon  = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$ulluco = $this->plant( 'Ulluco', array( 0.8, 0.2, 0.0 ) );
		$this->set_state( $oca, $yacon, 'proposed' );
		$this->set_state( $oca, $ulluco, 'accepted' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 'vetoed', undergrowth_veto_result( $oca, $yacon ) );
		$this->assertSame( 'unchanged', undergrowth_veto_result( $oca, $ulluco ) );
		$this->assertSame( 'gone', undergrowth_veto_result( $oca, PHP_INT_MAX ) );
	}

	public function test_every_link_result_has_a_message() {
		$messages = undergrowth_link_result_messages();

		foreach ( array( 'accepted', 'vetoed', 'unchanged', 'gone', 'not_proposed', 'edited', 'no_word', 'not_saved', 'needs_admin', 'forbidden' ) as $result ) {
			$this->assertNotSame( '', $messages[ $result ] ?? '', $result );
		}
	}

	public function test_accepted_pair_is_left_alone() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->set_state( $oca, $yacon, 'accepted' );

		undergrowth_refresh_proposals( $oca );

		$this->assertSame( 'accepted', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_new_post_gains_proposals_from_existing_neighbours() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$this->assertContains( $yacon, $this->proposed( $oca ) );
	}

	public function test_proposal_that_falls_out_of_the_top_three_is_removed() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$tax = $this->plant( 'Tax returns', array( 0.6, 0.0, 0.8 ) );
		foreach ( array( 'Yacon', 'Mashua', 'Ulluco' ) as $title ) {
			$this->plant( $title, array( 1.0, 0.1, 0.0 ) );
		}

		$this->assertNotContains( $tax, $this->proposed( $oca ) );
	}

	public function test_unpublishing_removes_proposals_both_ways_but_keeps_vetoes() {
		$oca    = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon  = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$mashua = $this->plant( 'Mashua', array( 0.8, 0.3, 0.0 ) );
		$this->set_state( $mashua, $yacon, 'vetoed' );

		wp_update_post(
			array(
				'ID'          => $yacon,
				'post_status' => 'draft',
			)
		);

		$this->assertNotContains( $yacon, $this->proposed( $oca ) );
		$this->assertSame( array(), $this->proposed( $yacon ) );
		$this->assertSame( 'vetoed', undergrowth_link_state( $mashua, $yacon ) );
	}

	/**
	 * Plants three sources that each propose one target, and a farther post
	 * that is each source's next best target.
	 *
	 * @return array{target: int, sources: int[], spare: int}
	 */
	private function plant_three_sources(): array {
		$spare   = $this->plant( 'Ulluco', array( 0.6, 0.0, 0.8 ) );
		$target  = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$sources = array(
			$this->plant( 'Yacon', array( 0.99, 0.1, 0.0 ) ),
			$this->plant( 'Mashua', array( 0.98, 0.15, 0.0 ) ),
			$this->plant( 'Achira', array( 0.97, 0.2, 0.0 ) ),
		);

		foreach ( $sources as $source ) {
			$this->assertContains( $target, $this->proposed( $source ) );
			$this->assertNotContains( $spare, $this->proposed( $source ) );
		}

		return array(
			'target'  => $target,
			'sources' => $sources,
			'spare'   => $spare,
		);
	}

	/**
	 * Counts the word counts built across the pool from now on.
	 *
	 * @return int[] Reference to the count.
	 */
	private function &count_corpus_builds(): array {
		$builds = array( 0 );

		add_filter(
			'query',
			static function ( $query ) use ( &$builds ) {
				if ( false !== strpos( $query, 'SELECT ID, post_title, post_content FROM' ) && false !== strpos( $query, ' IN (' ) ) {
					++$builds[0];
				}

				return $query;
			}
		);

		return $builds;
	}

	/**
	 * Lists the posts queued to pick a replacement target.
	 *
	 * @return int[]
	 */
	private function queued_sources(): array {
		$queued = array();

		foreach ( _get_cron_array() as $hooks ) {
			foreach ( $hooks['undergrowth_refresh_sources'] ?? array() as $event ) {
				$queued = array_merge( $queued, $event['args'][0] );
			}
		}

		return $queued;
	}

	/**
	 * Runs every queued refresh of the posts that proposed a post that left.
	 */
	private function run_queued_refreshes(): void {
		$runs = 0;

		while ( array() !== $this->queued_sources() ) {
			$this->assertLessThan( 50, ++$runs );

			foreach ( _get_cron_array() as $timestamp => $hooks ) {
				foreach ( $hooks['undergrowth_refresh_sources'] ?? array() as $event ) {
					wp_unschedule_event( $timestamp, 'undergrowth_refresh_sources', $event['args'] );
					do_action_ref_array( 'undergrowth_refresh_sources', $event['args'] );
				}
			}
		}
	}

	/**
	 * Takes a post out of the index in each way an author can.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function leaving(): array {
		return array(
			'trashed'     => array( 'trash' ),
			'unpublished' => array( 'draft' ),
			'deleted'     => array( 'delete' ),
			'composted'   => array( 'compost' ),
		);
	}

	/**
	 * Leaves the target, in the request the author made.
	 *
	 * @param string $how One of the keys of leaving().
	 * @param int    $post_id Post that leaves.
	 */
	private function leave( string $how, int $post_id ): void {
		if ( 'trash' === $how ) {
			wp_trash_post( $post_id );
		} elseif ( 'draft' === $how ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			);
		} elseif ( 'delete' === $how ) {
			wp_delete_post( $post_id, true );
		} else {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$this->assertTrue( undergrowth_compost_post( $post_id ) );
		}
	}

	/**
	 * Checks that the sources are refreshed in cron, not in the request.
	 *
	 * @dataProvider leaving
	 *
	 * @param string $how How the target leaves.
	 */
	public function test_sources_of_a_post_that_left_are_refreshed_in_cron( string $how ) {
		$planted   = $this->plant_three_sources();
		$refreshes = did_action( 'undergrowth_proposals_refreshed' );

		$this->leave( $how, $planted['target'] );

		// The request only removes the proposals to the post that left.
		$this->assertSame( $refreshes, did_action( 'undergrowth_proposals_refreshed' ) );
		foreach ( $planted['sources'] as $source ) {
			$this->assertNotContains( $planted['target'], $this->proposed( $source ) );
			$this->assertCount( 2, $this->proposed( $source ) );
		}
		$this->assertSame( array(), array_diff( $planted['sources'], $this->queued_sources() ) );

		// The files and Discord have their own tests, and count words of their own.
		remove_all_actions( 'undergrowth_proposals_refreshed' );
		// Cron runs in a request of its own, with nothing counted yet.
		undergrowth_forget_reusable_corpus();
		$builds = &$this->count_corpus_builds();

		$this->run_queued_refreshes();

		foreach ( $planted['sources'] as $source ) {
			$this->assertContains( $planted['spare'], $this->proposed( $source ) );
		}
		$this->assertSame( 1, $builds[0] );
	}

	public function test_queued_refresh_leaves_vetoes_and_accepted_links_alone() {
		$planted = $this->plant_three_sources();
		$yacon   = $planted['sources'][0];
		$mashua  = $planted['sources'][1];
		$this->set_state( $yacon, $planted['spare'], 'vetoed' );
		$this->set_state( $mashua, $planted['spare'], 'accepted' );

		wp_trash_post( $planted['target'] );
		$this->run_queued_refreshes();

		$this->assertSame( 'vetoed', undergrowth_link_state( $yacon, $planted['spare'] ) );
		$this->assertSame( 'accepted', undergrowth_link_state( $mashua, $planted['spare'] ) );
	}

	public function test_queued_refresh_skips_a_source_that_left_meanwhile() {
		$planted = $this->plant_three_sources();
		wp_trash_post( $planted['target'] );

		$source = $planted['sources'][0];
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $source ) );
		clean_post_cache( $source );

		$this->run_queued_refreshes();

		$this->assertNotContains( $planted['spare'], $this->proposed( $source ) );
	}

	public function test_queued_refresh_skips_a_source_composted_meanwhile() {
		$planted = $this->plant_three_sources();
		wp_trash_post( $planted['target'] );

		$source = $planted['sources'][0];
		update_post_meta( $source, 'undergrowth_stage', 'composting' );
		$refreshes = did_action( 'undergrowth_proposals_refreshed' );

		do_action( 'undergrowth_refresh_sources', array( $source ) );

		$this->assertSame( $refreshes, did_action( 'undergrowth_proposals_refreshed' ) );
	}

	public function test_many_sources_are_refreshed_in_batches() {
		$ids = range( 1, UNDERGROWTH_REFRESH_BATCH + 5 );

		undergrowth_refresh_sources( $ids );

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_refresh_sources', array( array_slice( $ids, UNDERGROWTH_REFRESH_BATCH ) ) ) );
	}

	public function test_deleting_a_post_removes_its_proposals() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		wp_delete_post( $yacon, true );

		$this->assertSame( array(), $this->proposed( $oca ) );
	}

	public function test_post_without_a_vector_has_no_targets_or_neighbours() {
		$this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$bare = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		undergrowth_refresh_around( $bare );

		$this->assertSame( array(), undergrowth_comparable_vectors( $bare ) );
		$this->assertSame( array(), undergrowth_test_rank_targets( $bare ) );
		$this->assertSame( array(), $this->proposed( $bare ) );
	}

	public function test_draft_has_no_targets_even_with_a_stale_vector() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $oca ) );
		clean_post_cache( $oca );

		$this->assertSame( array(), undergrowth_test_rank_targets( $oca ) );
	}

	public function test_target_with_a_stale_vector_is_not_proposed() {
		global $wpdb;

		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $yacon ) );
		clean_post_cache( $yacon );

		undergrowth_refresh_proposals( $oca );

		$this->assertNotContains( $yacon, $this->proposed( $oca ) );
	}

	public function test_moving_a_post_away_removes_proposals_that_pointed_to_it() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		for ( $i = 0; $i < UNDERGROWTH_NEIGHBOURS_TO_UPDATE; $i++ ) {
			$this->plant( 'Ledger ' . $i, array( 0.0, 0.0, 1.0 ) );
		}
		$this->assertContains( $yacon, $this->proposed( $oca ) );

		$this->vectors['Yacon'] = array( 0.0, 0.0, 1.0 );
		wp_update_post(
			array(
				'ID'           => $yacon,
				'post_content' => 'Notes about ledgers.',
			)
		);
		undergrowth_embed_post( $yacon );

		$this->assertNotContains( $yacon, $this->proposed( $oca ) );
	}

	public function test_target_changing_language_leaves_the_sources_other_proposals() {
		$oca    = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon  = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$mashua = $this->plant( 'Mashua', array( 0.8, 0.3, 0.0 ) );
		$this->assertSame( array( $yacon, $mashua ), $this->proposed( $oca ) );

		add_filter(
			'undergrowth_post_language',
			static function ( $locale, $post ) use ( $yacon ) {
				return $post->ID === $yacon ? 'de_DE' : $locale;
			},
			10,
			2
		);
		undergrowth_embed_post( $yacon );

		$this->assertSame( array( $mashua ), $this->proposed( $oca ) );
	}

	public function test_post_leaving_the_index_keeps_other_sources_proposals() {
		$oca    = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon  = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$mashua = $this->plant( 'Mashua', array( 0.8, 0.3, 0.0 ) );

		wp_delete_post( $yacon, true );

		$this->assertSame( array( $mashua ), $this->proposed( $oca ) );
	}

	public function test_source_without_a_vector_loses_its_proposals_when_the_target_goes() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		undergrowth_delete_vector( $oca );

		wp_delete_post( $yacon, true );

		$this->assertSame( array(), $this->proposed( $oca ) );
	}

	public function test_incomparable_vectors_are_never_proposed_even_with_no_threshold() {
		add_filter(
			'undergrowth_min_link_similarity',
			static function () {
				return -1.0;
			}
		);
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$zero  = $this->plant( 'Blank', array( 0.0, 0.0, 0.0 ) );
		$short = $this->plant( 'Short', array( 1.0, 0.0 ) );

		$proposed = $this->proposed( $oca );

		$this->assertNotContains( $zero, $proposed );
		$this->assertNotContains( $short, $proposed );
	}

	public function test_vectors_of_equal_length_with_some_magnitude_are_comparable() {
		$this->assertTrue( undergrowth_vectors_comparable( array( 1.0, 0.0 ), array( 0.0, 2.0 ) ) );
		$this->assertFalse( undergrowth_vectors_comparable( array( 1.0, 0.0 ), array( 0.0, 0.0 ) ) );
		$this->assertFalse( undergrowth_vectors_comparable( array( 1.0, 0.0 ), array( 1.0, 0.0, 0.0 ) ) );
		$this->assertFalse( undergrowth_vectors_comparable( array(), array() ) );
	}

	public function test_failed_re_embedding_removes_proposals_to_and_from_the_post() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->assertContains( $yacon, $this->proposed( $oca ) );

		unset( $this->vectors['Yacon'] );
		wp_update_post(
			array(
				'ID'           => $yacon,
				'post_content' => 'Notes about yacon, rewritten.',
			)
		);
		undergrowth_embed_post( $yacon );

		$this->assertNull( undergrowth_get_vector( $yacon ) );
		$this->assertNotContains( $yacon, $this->proposed( $oca ) );
		$this->assertSame( array(), $this->proposed( $yacon ) );
	}

	public function test_incomparable_vectors_never_crowd_out_neighbours_to_refresh() {
		add_filter(
			'undergrowth_min_link_similarity',
			static function () {
				return -1.0;
			}
		);
		for ( $i = 0; $i <= UNDERGROWTH_NEIGHBOURS_TO_UPDATE; $i++ ) {
			$this->plant( 'Blank ' . $i, array( 0.0, 0.0, 0.0 ) );
		}
		$ulluco = $this->plant( 'Ulluco', array( 0.0, 1.0, 0.0 ) );

		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		$this->assertContains( $oca, $this->proposed( $ulluco ) );
	}

	public function test_stale_vectors_never_crowd_out_neighbours_to_refresh() {
		global $wpdb;

		for ( $i = 0; $i <= UNDERGROWTH_NEIGHBOURS_TO_UPDATE; $i++ ) {
			$hidden = $this->plant( 'Hidden ' . $i, array( 1.0, 0.01 * ( $i + 1 ), 0.0 ) );
			$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $hidden ) );
			clean_post_cache( $hidden );
		}
		$mashua = $this->plant( 'Mashua', array( 0.5, 0.5, 0.0 ) );

		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		$this->assertContains( $oca, $this->proposed( $mashua ) );
	}

	public function test_link_state_of_an_unknown_pair_is_empty() {
		$this->assertSame( '', undergrowth_link_state( 1, 2 ) );
	}
}
