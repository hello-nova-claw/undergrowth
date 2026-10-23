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

		$this->assertSame( array( 'id', 'source_id', 'target_id', 'state', 'score', 'sentence', 'sentence_index', 'anchor', 'anchor_offset', 'anchor_chosen', 'created_at', 'updated_at' ), $columns );
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

		$this->assertSame( 'vetoed', undergrowth_veto_result( $oca, $yacon ) );
		$this->assertSame( 'unchanged', undergrowth_veto_result( $oca, $ulluco ) );
		$this->assertSame( 'gone', undergrowth_veto_result( $oca, PHP_INT_MAX ) );
	}

	public function test_every_link_result_has_a_message() {
		$messages = undergrowth_link_result_messages();

		foreach ( array( 'accepted', 'vetoed', 'unchanged', 'gone', 'not_proposed', 'edited', 'no_word', 'needs_admin' ) as $result ) {
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
		$this->assertSame( array(), undergrowth_rank_targets( $bare ) );
		$this->assertSame( array(), $this->proposed( $bare ) );
	}

	public function test_draft_has_no_targets_even_with_a_stale_vector() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $oca ) );
		clean_post_cache( $oca );

		$this->assertSame( array(), undergrowth_rank_targets( $oca ) );
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
