<?php
/**
 * Tests for naming groups from the author's own words and accounting for
 * every post on the Map.
 *
 * @package Undergrowth
 */

/**
 * Group labels and the Map's counts.
 */
class Test_Map extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
	}

	/**
	 * Publishes a post, stores its vector and puts it in a cluster.
	 *
	 * @param string  $title   Post title.
	 * @param string  $content Post content.
	 * @param float[] $vector  Vector to store.
	 * @param int     $leader  Leader of the cluster, 0 for the post to lead, or -1 for no cluster.
	 * @return int Post ID.
	 */
	private function post( string $title, string $content, array $vector, int $leader = -1 ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'publish',
			)
		);

		undergrowth_save_vector(
			$post_id,
			$vector,
			array(
				'provider'     => 'test-provider',
				'model'        => 'test-model',
				'language'     => 'en_US',
				'content_hash' => 'hash',
			)
		);

		if ( $leader >= 0 ) {
			update_post_meta( $post_id, 'undergrowth_cluster', 0 === $leader ? $post_id : $leader );
		}

		return $post_id;
	}

	/**
	 * Plants a group about oca, and some posts about other things.
	 *
	 * @return array<string, int> Post IDs keyed by title.
	 */
	private function plant_oca(): array {
		$posts                = array();
		$posts['Oca']         = $this->post( 'Oca', '<p>Planting Oca tubers in the raised bed. The tubers swell in autumn.</p>', array( 1.0, 0.0, 0.0 ), 0 );
		$posts['Oca harvest'] = $this->post( 'Oca harvest', '<p>Lifting the tubers after the first frost &amp; drying them. Raised bed again.</p>', array( 0.9, 0.1, 0.0 ), $posts['Oca'] );
		$posts['Oca seeds']   = $this->post( 'Oca seeds', '<p>Oca rarely sets seed&nbsp;here. 2024 was the first year in the raised bed.</p>', array( 0.9, 0.0, 0.1 ), $posts['Oca'] );
		$posts['Bread']       = $this->post( 'Bread', '<p>Sourdough loaves, the first of the year. Bread in the oven.</p>', array( 0.0, 1.0, 0.0 ) );
		$posts['Cycling']     = $this->post( 'Cycling', '<p>A first ride along the river, in autumn rain.</p>', array( 0.0, 0.0, 1.0 ) );

		return $posts;
	}

	public function test_label_words_are_words_and_adjacent_pairs_without_stop_words_or_numbers() {
		$words = undergrowth_label_words( 'The Raised bed, in 2024: raised beds and 3rd tubers.', 'en_US' );

		$this->assertSame(
			array(
				'raised'      => 'Raised',
				'bed'         => 'bed',
				'raised bed'  => 'Raised bed',
				'beds'        => 'beds',
				'raised beds' => 'raised beds',
				'tubers'      => 'tubers',
			),
			$words
		);
	}

	public function test_label_words_never_pair_across_punctuation_or_a_stop_word() {
		$words = undergrowth_label_words( 'Oca, tubers. Oca and tubers.', 'en_US' );

		$this->assertArrayNotHasKey( 'oca tubers', $words );
		$this->assertArrayNotHasKey( 'and tubers', $words );
	}

	public function test_label_words_keep_the_first_spelling_the_author_used() {
		// A word twice over is no phrase.
		$this->assertSame( array( 'lisbon' => 'Lisbon' ), undergrowth_label_words( 'Lisbon lisbon', 'en_US' ) );
	}

	public function test_thai_label_words_keep_combining_marks_like_tokens() {
		$this->assertSame( undergrowth_tokens( 'ก้ข' ), array_keys( undergrowth_label_words( 'ก้ข', 'th' ) ) );
	}

	public function test_two_letter_hebrew_word_can_be_a_label_term() {
		// "גן" is a garden; "ירק" a vegetable.
		$words = undergrowth_label_words( 'גן ירק', 'he_IL' );

		$this->assertArrayHasKey( 'גן', $words );
		$this->assertArrayHasKey( 'גן ירק', $words );
		$this->assertSame( array( 'גן' ), undergrowth_choose_label_terms( array( 'גן' => 3 ), 3, array( 'גן' => 3 ), 10 ) );
	}

	public function test_two_letter_english_word_is_no_label_term() {
		$this->assertArrayNotHasKey( 'ox', undergrowth_label_words( 'An ox ploughs.', 'en_US' ) );
	}

	public function test_label_terms_are_shared_in_the_group_and_rare_elsewhere() {
		$terms = undergrowth_choose_label_terms(
			array(
				'oca'    => 3,
				'tubers' => 2,
				'first'  => 2,
				'lonely' => 1,
			),
			3,
			array(
				'oca'    => 3,
				'tubers' => 2,
				'first'  => 6,
				'lonely' => 1,
			),
			10
		);

		// "first" is in two of three group posts but also four of seven others;
		// "lonely" is in only one post of the group.
		$this->assertSame( array( 'oca', 'tubers' ), $terms );
	}

	public function test_label_terms_are_at_most_four() {
		$group = array_fill_keys( array( 'alpha', 'beta', 'gamma', 'delta', 'epsilon' ), 3 );

		$this->assertCount( 4, undergrowth_choose_label_terms( $group, 3, $group, 10 ) );
	}

	public function test_label_phrase_takes_the_place_of_its_own_words() {
		$terms = undergrowth_choose_label_terms(
			array(
				'raised'     => 3,
				'bed'        => 3,
				'raised bed' => 3,
				'tubers'     => 2,
			),
			3,
			array(
				'raised'     => 3,
				'bed'        => 3,
				'raised bed' => 3,
				'tubers'     => 2,
			),
			10
		);

		$this->assertSame( array( 'raised bed', 'tubers' ), $terms );
	}

	public function test_label_terms_tie_in_alphabetical_order() {
		$group = array(
			'yacon' => 3,
			'oca'   => 3,
		);

		$this->assertSame( array( 'oca', 'yacon' ), undergrowth_choose_label_terms( $group, 3, $group, 10 ) );
	}

	public function test_labelling_stores_each_groups_words_as_the_author_wrote_them() {
		$posts = $this->plant_oca();

		undergrowth_label_clusters();

		$this->assertSame( array( 'Oca', 'raised bed', 'tubers' ), undergrowth_cluster_terms( $posts['Oca'] ) );
	}

	public function test_words_already_in_the_heading_are_left_out() {
		$posts = $this->plant_oca();

		undergrowth_label_clusters();

		$this->assertSame( array( 'raised bed', 'tubers' ), undergrowth_cluster_terms( $posts['Oca'], 'Oca' ) );
	}

	public function test_labels_never_hold_entity_names_numbers_or_stop_words() {
		$posts = $this->plant_oca();

		undergrowth_label_clusters();

		foreach ( undergrowth_cluster_terms( $posts['Oca'] ) as $term ) {
			$this->assertDoesNotMatchRegularExpression( '/amp|nbsp|\d|\bthe\b|\bfirst\b/i', $term );
		}
	}

	public function test_cluster_without_stored_words_has_none() {
		$this->assertSame( array(), undergrowth_cluster_terms( 12345 ) );
	}

	public function test_cluster_pass_stores_the_words_and_when_it_ran() {
		$posts = $this->plant_oca();

		undergrowth_run_cluster_cycle( 0 );

		$labels = get_option( 'undergrowth_cluster_labels' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $labels['made'] );
		$this->assertContains( 'Oca', undergrowth_cluster_terms( $posts['Oca'] ) );
	}

	public function test_stage_cycle_is_scheduled_before_the_words_are_counted() {
		$this->plant_oca();
		$scheduled = array();

		// Counting reads the stop-word lists, so a slow or failed count
		// comes after the stage cycle is already queued.
		add_filter(
			'undergrowth_anchor_stopwords',
			static function ( $lists ) use ( &$scheduled ) {
				$scheduled[] = false !== wp_next_scheduled( 'undergrowth_stage_cycle', array( 0 ) );

				return $lists;
			}
		);

		undergrowth_run_cluster_cycle( 0 );

		$this->assertNotEmpty( $scheduled );
		$this->assertNotContains( false, $scheduled );
	}

	public function test_composted_post_lends_no_words() {
		$posts = $this->plant_oca();
		$extra = $this->post( 'Oca leaves', '<p>Oca leaves taste of sorrel. Sorrel soup.</p>', array( 1.0, 0.0, 0.0 ), $posts['Oca'] );
		$more  = $this->post( 'Oca soup', '<p>Oca soup with sorrel.</p>', array( 1.0, 0.0, 0.0 ), $posts['Oca'] );
		update_post_meta( $extra, 'undergrowth_stage', 'composting' );
		update_post_meta( $more, 'undergrowth_stage', 'composting' );

		undergrowth_label_clusters();

		$this->assertNotContains( 'sorrel', array_map( 'strtolower', undergrowth_cluster_terms( $posts['Oca'] ) ) );
	}

	public function test_every_indexable_post_is_grouped_ungrouped_or_composting() {
		$posts = $this->plant_oca();
		update_option(
			'undergrowth_cluster_labels',
			array(
				'made'  => '2026-10-01 00:00:00',
				'terms' => array(),
			),
			false
		);

		$unread  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$compost = $this->post( 'Resting', '<p>Rest.</p>', array( 1.0, 0.0, 0.0 ), $posts['Oca'] );
		update_post_meta( $compost, 'undergrowth_stage', 'composting' );
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		$counts = undergrowth_map_accounting( undergrowth_cluster_map() );

		$this->assertSame( 3, $counts['grouped'] );
		$this->assertSame( 1, $counts['composting'] );
		$this->assertSame(
			array(
				$unread           => 'unread',
				$posts['Cycling'] => 'new',
				$posts['Bread']   => 'new',
			),
			$counts['ungrouped']
		);
		$this->assertSame( 7, $counts['grouped'] + count( $counts['ungrouped'] ) + $counts['composting'] );
	}

	public function test_post_read_before_the_pass_is_not_close_enough_yet() {
		$posts = $this->plant_oca();
		update_option(
			'undergrowth_cluster_labels',
			array(
				'made'  => '2999-01-01 00:00:00',
				'terms' => array(),
			),
			false
		);

		$counts = undergrowth_map_accounting( undergrowth_cluster_map() );

		$this->assertSame( 'apart', $counts['ungrouped'][ $posts['Bread'] ] );
	}

	public function test_without_a_recorded_pass_no_post_is_called_new() {
		$posts = $this->plant_oca();

		$counts = undergrowth_map_accounting( undergrowth_cluster_map() );

		$this->assertSame( 'apart', $counts['ungrouped'][ $posts['Bread'] ] );
	}
}
