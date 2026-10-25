<?php
/**
 * Tests for composting a post and returning it to the garden.
 *
 * @package Undergrowth
 */

/**
 * Composting.
 */
class Test_Compost extends WP_UnitTestCase {

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	public function tear_down() {
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Returns every link row that touches a post, as "source>target:state".
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function rows( int $post_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source_id, target_id, state FROM %i WHERE source_id = %d OR target_id = %d ORDER BY source_id, target_id',
				undergrowth_links_table(),
				$post_id,
				$post_id
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return $row['source_id'] . '>' . $row['target_id'] . ':' . $row['state'];
			},
			$rows
		);
	}

	/**
	 * Sets the state of a link row directly, adding the row when there is none.
	 *
	 * @param int    $source_id Source post ID.
	 * @param int    $target_id Target post ID.
	 * @param string $state     New state.
	 */
	private function set_state( int $source_id, int $target_id, string $state ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE source_id = %d AND target_id = %d',
				undergrowth_links_table(),
				$source_id,
				$target_id
			)
		);
		$wpdb->insert(
			undergrowth_links_table(),
			array(
				'source_id'  => $source_id,
				'target_id'  => $target_id,
				'state'      => $state,
				'score'      => 0.5,
				'sentence'   => 'Notes.',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Plants three related posts that propose links to each other.
	 *
	 * @return int[] Post IDs of Oca, Yacon and Mashua.
	 */
	private function plant_garden(): array {
		return array(
			$this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) ),
			$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) ),
			$this->plant( 'Mashua', array( 0.8, 0.3, 0.0 ) ),
		);
	}

	public function test_composting_takes_effect_at_once() {
		list( $oca ) = $this->plant_garden();

		$this->assertTrue( undergrowth_compost_post( $oca ) );
		$this->assertSame( 'composting', undergrowth_get_stage( $oca ) );

		$log = undergrowth_stage_log( $oca );
		$this->assertSame( 'compost', end( $log )['reason'] );
	}

	public function test_composting_clears_a_pin() {
		list( $oca ) = $this->plant_garden();
		undergrowth_pin_stage( $oca, 'evergreen' );

		undergrowth_compost_post( $oca );

		$this->assertFalse( undergrowth_is_stage_pinned( $oca ) );
		$this->assertFalse( metadata_exists( 'post', $oca, 'undergrowth_stage_pinned' ) );
	}

	public function test_composting_stores_no_timer_or_expiry() {
		list( $oca ) = $this->plant_garden();

		undergrowth_compost_post( $oca );

		$keys = array_filter(
			array_keys( get_post_meta( $oca ) ),
			static function ( $key ) {
				return false !== strpos( $key, 'undergrowth' );
			}
		);

		$this->assertEqualsCanonicalizing( array( 'undergrowth_stage', 'undergrowth_stage_log' ), array_values( $keys ) );
	}

	public function test_composting_removes_proposals_in_and_out_and_keeps_decisions() {
		list( $oca, $yacon, $mashua ) = $this->plant_garden();
		$this->set_state( $oca, $yacon, 'accepted' );
		$this->set_state( $mashua, $oca, 'vetoed' );

		$this->assertContains( $yacon . '>' . $oca . ':proposed', $this->rows( $oca ) );
		$this->assertContains( $oca . '>' . $mashua . ':proposed', $this->rows( $oca ) );

		undergrowth_compost_post( $oca );

		$this->assertSame(
			array( $oca . '>' . $yacon . ':accepted', $mashua . '>' . $oca . ':vetoed' ),
			$this->rows( $oca )
		);
	}

	public function test_composted_post_gets_no_new_proposals_in_or_out() {
		list( $oca, $yacon, $mashua ) = $this->plant_garden();

		undergrowth_compost_post( $oca );
		undergrowth_refresh_proposals( $oca );
		undergrowth_refresh_proposals( $yacon );
		undergrowth_embed_post( $oca );

		$this->assertSame( array(), $this->rows( $oca ) );
		$this->assertArrayNotHasKey( $oca, undergrowth_rank_targets( $mashua, null, 0 ) );
		$this->assertSame( array(), undergrowth_rank_targets( $oca, null, 0 ) );
	}

	public function test_cycle_leaves_a_composted_post_alone() {
		list( $oca, $yacon, $mashua ) = $this->plant_garden();
		$this->set_state( $oca, $yacon, 'accepted' );
		$this->set_state( $mashua, $oca, 'accepted' );

		undergrowth_compost_post( $oca );
		undergrowth_run_stage_cycle();

		$this->assertSame( 'composting', undergrowth_get_stage( $oca ) );
	}

	public function test_returning_measures_the_stage_at_once() {
		list( $oca, $yacon, $mashua ) = $this->plant_garden();
		undergrowth_set_stage( $oca, 'evergreen' );
		undergrowth_compost_post( $oca );
		$this->set_state( $oca, $yacon, 'accepted' );
		$this->set_state( $mashua, $oca, 'accepted' );

		$this->assertTrue( undergrowth_return_post( $oca ) );
		$this->assertSame( 'sprout', undergrowth_get_stage( $oca ) );

		$log = undergrowth_stage_log( $oca );
		$this->assertSame( 'return', end( $log )['reason'] );
	}

	public function test_returning_a_post_with_no_links_measures_seedling() {
		list( $oca ) = $this->plant_garden();
		undergrowth_compost_post( $oca );

		undergrowth_return_post( $oca );

		$this->assertSame( 'seedling', undergrowth_get_stage( $oca ) );
	}

	public function test_returned_post_gets_proposals_again() {
		list( $oca, $yacon ) = $this->plant_garden();
		undergrowth_compost_post( $oca );

		undergrowth_return_post( $oca );

		$this->assertNotSame( array(), $this->proposed( $oca ) );
		$this->assertContains( $oca, $this->proposed( $yacon ) );
	}

	public function test_composting_twice_is_refused() {
		list( $oca ) = $this->plant_garden();
		undergrowth_compost_post( $oca );

		$result = undergrowth_compost_post( $oca );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_composting', $result->get_error_code() );
		$this->assertCount( 1, undergrowth_stage_log( $oca ) );
	}

	public function test_returning_a_post_that_is_not_composting_is_refused() {
		list( $oca ) = $this->plant_garden();

		$result = undergrowth_return_post( $oca );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_not_composting', $result->get_error_code() );
	}

	public function test_user_who_cannot_edit_the_post_cannot_compost_or_return_it() {
		list( $oca, $yacon ) = $this->plant_garden();
		undergrowth_compost_post( $yacon );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$compost = undergrowth_compost_post( $oca );
		$return  = undergrowth_return_post( $yacon );

		$this->assertWPError( $compost );
		$this->assertSame( 'undergrowth_cannot_edit', $compost->get_error_code() );
		$this->assertWPError( $return );
		$this->assertSame( 'seedling', undergrowth_get_stage( $oca ) );
		$this->assertSame( 'composting', undergrowth_get_stage( $yacon ) );
	}

	public function test_post_outside_the_index_cannot_be_composted() {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->assertWPError( undergrowth_compost_post( $draft ) );
		$this->assertFalse( metadata_exists( 'post', $draft, 'undergrowth_stage' ) );
	}

	/**
	 * Makes every stage log write fail, as a full disk or a filter might.
	 */
	private function fail_log_writes(): void {
		add_filter(
			'add_post_metadata',
			static function ( $check, $object_id, $meta_key ) {
				return 'undergrowth_stage_log' === $meta_key ? false : $check;
			},
			10,
			3
		);
	}

	public function test_compost_that_cannot_be_saved_changes_nothing() {
		list( $oca, $yacon ) = $this->plant_garden();
		undergrowth_pin_stage( $oca, 'evergreen' );
		$rows = $this->rows( $oca );
		$this->fail_log_writes();

		$result = undergrowth_compost_post( $oca );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_not_saved', $result->get_error_code() );
		$this->assertSame( 'evergreen', undergrowth_get_stage( $oca ) );
		$this->assertTrue( undergrowth_is_stage_pinned( $oca ) );
		$this->assertSame( $rows, $this->rows( $oca ) );
	}

	public function test_compost_whose_pin_cannot_be_removed_changes_nothing() {
		list( $oca ) = $this->plant_garden();
		undergrowth_pin_stage( $oca, 'evergreen' );
		$rows = $this->rows( $oca );
		add_filter(
			'delete_post_metadata',
			static function ( $check, $object_id, $meta_key ) {
				return 'undergrowth_stage_pinned' === $meta_key ? false : $check;
			},
			10,
			3
		);

		$result = undergrowth_compost_post( $oca );

		$this->assertWPError( $result );
		$this->assertSame( 'evergreen', undergrowth_get_stage( $oca ) );
		$this->assertTrue( undergrowth_is_stage_pinned( $oca ) );
		$this->assertSame( $rows, $this->rows( $oca ) );
	}

	public function test_failed_compost_says_when_the_pin_could_not_be_put_back() {
		list( $oca ) = $this->plant_garden();
		undergrowth_pin_stage( $oca, 'evergreen' );
		$this->fail_log_writes();
		foreach ( array( 'add', 'update' ) as $operation ) {
			add_filter(
				$operation . '_post_metadata',
				static function ( $check, $object_id, $meta_key ) {
					return 'undergrowth_stage_pinned' === $meta_key ? false : $check;
				},
				10,
				3
			);
		}

		$result = undergrowth_compost_post( $oca );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_pin_lost', $result->get_error_code() );
		$this->assertStringContainsString( 'pin marker', $result->get_error_message() );
	}

	public function test_failed_compost_of_an_unpinned_post_leaves_a_pin_made_meanwhile() {
		list( $oca ) = $this->plant_garden();

		// Another request pins the post just as compost's stage write fails.
		add_filter(
			'update_post_metadata',
			static function ( $check, $object_id, $meta_key ) use ( $oca ) {
				if ( $object_id === $oca && 'undergrowth_stage' === $meta_key ) {
					add_metadata( 'post', $oca, 'undergrowth_stage_pinned', '1', true );

					return false;
				}

				return $check;
			},
			10,
			3
		);

		$result = undergrowth_compost_post( $oca );

		$this->assertSame( 'undergrowth_not_saved', $result->get_error_code() );
		$this->assertTrue( undergrowth_is_stage_pinned( $oca ) );
	}

	public function test_return_that_cannot_be_saved_leaves_the_post_composting() {
		list( $oca ) = $this->plant_garden();
		undergrowth_compost_post( $oca );
		$this->fail_log_writes();

		$result = undergrowth_return_post( $oca );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_not_saved', $result->get_error_code() );
		$this->assertSame( 'composting', undergrowth_get_stage( $oca ) );
		$this->assertSame( array(), $this->proposed( $oca ) );
	}

	public function test_composted_posts_file_lists_no_proposed_links() {
		list( $oca ) = $this->plant_garden();
		$path        = undergrowth_memory_path( $oca );
		$this->assertMatchesRegularExpression( "/## proposed links\n- /", (string) file_get_contents( $path ) );

		undergrowth_compost_post( $oca );

		$this->assertStringContainsString( "## proposed links\n\n## accepted links", (string) file_get_contents( $path ) );
	}

	public function test_compost_and_return_are_written_to_the_file() {
		list( $oca ) = $this->plant_garden();

		undergrowth_compost_post( $oca );
		undergrowth_return_post( $oca );

		$file = (string) file_get_contents( undergrowth_memory_path( $oca ) );

		$this->assertStringContainsString( 'seedling -> composting (composted by the author)', $file );
		$this->assertStringContainsString( 'composting -> seedling (returned to the garden by the author)', $file );
	}
}
