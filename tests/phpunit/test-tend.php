<?php
/**
 * Tests for "Worth a look" notes on stale Evergreen posts.
 *
 * @package Undergrowth
 */

/**
 * "Worth a look".
 */
class Test_Tend extends WP_UnitTestCase {

	use Stops_Redirects;

	public function set_up() {
		parent::set_up();

		undergrowth_load_stages_table();
		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		foreach ( array( 'tab', 'post', '_wpnonce' ) as $arg ) {
			unset( $_GET[ $arg ], $_POST[ $arg ], $_REQUEST[ $arg ] );
		}

		parent::tear_down();
	}

	/**
	 * Publishes a post at a stage, last edited some time ago.
	 *
	 * @param string               $stage  Stage.
	 * @param string               $edited strtotime() phrase for the last edit.
	 * @param array<string, mixed> $args   Other post fields.
	 * @return int Post ID.
	 */
	private function post( string $stage, string $edited, array $args = array() ): int {
		$post_id = self::factory()->post->create( $args + array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, 'undergrowth_stage', $stage );
		$this->edited( $post_id, $edited );

		return $post_id;
	}

	/**
	 * Sets when a post was last edited, without firing a save.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $when    strtotime() phrase.
	 */
	private function edited( int $post_id, string $when ): void {
		global $wpdb;

		$date = gmdate( 'Y-m-d H:i:s', strtotime( $when ) );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $date,
				'post_modified_gmt' => $date,
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );
	}

	/**
	 * Adds posts to a post's Map group, published at a moment.
	 *
	 * @param int                  $leader Group leader.
	 * @param int                  $count  How many.
	 * @param string               $when   strtotime() phrase for their publication.
	 * @param array<string, mixed> $args   Other post fields.
	 */
	private function join( int $leader, int $count, string $when, array $args = array() ): void {
		update_post_meta( $leader, 'undergrowth_cluster', $leader );

		for ( $i = 0; $i < $count; $i++ ) {
			$post_id = self::factory()->post->create( $args + array( 'post_status' => 'publish' ) );
			update_post_meta( $post_id, 'undergrowth_cluster', $leader );

			global $wpdb;
			$date = gmdate( 'Y-m-d H:i:s', strtotime( $when ) );
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_date'     => $date,
					'post_date_gmt' => $date,
				),
				array( 'ID' => $post_id )
			);
			clean_post_cache( $post_id );
		}
	}

	public function test_an_evergreen_post_unedited_for_a_year_is_worth_a_look() {
		$post_id = $this->post( 'evergreen', '-400 days' );

		$this->assertSame( 'stale', undergrowth_tend_reason( $post_id )['reason'] );
		$this->assertSame( 400, undergrowth_tend_reason( $post_id )['count'] );
		$this->assertStringStartsWith( 'Worth a look: you last edited this Evergreen post 1 year ago.', undergrowth_tend_note( $post_id ) );
	}

	public function test_a_recently_edited_evergreen_post_is_not() {
		$this->assertNull( undergrowth_tend_note( $this->post( 'evergreen', '-100 days' ) ) );
	}

	/**
	 * Only Evergreen posts readers may see qualify.
	 *
	 * @dataProvider data_not_worth_a_look
	 *
	 * @param string               $stage Stage.
	 * @param array<string, mixed> $args  Post fields.
	 */
	public function test_only_a_visible_evergreen_post_qualifies( string $stage, array $args ) {
		$this->assertNull( undergrowth_tend_reason( $this->post( $stage, '-800 days', $args ) ) );
	}

	/**
	 * Stale posts that are not Evergreen, or not in the garden.
	 *
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public function data_not_worth_a_look(): array {
		return array(
			'budding'            => array( 'budding', array() ),
			'seedling'           => array( 'seedling', array() ),
			'composting'         => array( 'composting', array() ),
			'private'            => array( 'evergreen', array( 'post_status' => 'private' ) ),
			'draft'              => array( 'evergreen', array( 'post_status' => 'draft' ) ),
			'password-protected' => array( 'evergreen', array( 'post_password' => 'oca' ) ),
			'unindexed type'     => array( 'evergreen', array( 'post_type' => 'page' ) ),
		);
	}

	public function test_a_group_that_grew_since_the_last_edit_makes_it_worth_a_look() {
		$post_id = $this->post( 'evergreen', '-60 days' );

		$this->join( $post_id, 2, '-10 days' );
		$this->assertNull( undergrowth_tend_reason( $post_id ) );

		$this->join( $post_id, 1, '-5 days' );
		$this->assertSame(
			array(
				'reason' => 'grown',
				'count'  => 3,
			),
			undergrowth_tend_reason( $post_id )
		);
		$this->assertSame( 'Worth a look: 3 posts have joined this one’s group on the Map since you last edited it.', undergrowth_tend_note( $post_id ) );
	}

	public function test_only_visible_posts_joining_after_the_last_edit_count() {
		$post_id = $this->post( 'evergreen', '-60 days' );

		$this->join( $post_id, 5, '-90 days' );
		$this->join( $post_id, 5, '-10 days', array( 'post_status' => 'draft' ) );
		$this->join( $post_id, 2, '-10 days' );

		$this->assertNull( undergrowth_tend_reason( $post_id ) );
	}

	public function test_the_thresholds_can_be_filtered() {
		$stale = $this->post( 'evergreen', '-40 days' );
		$grown = $this->post( 'evergreen', '-20 days' );
		$this->join( $grown, 1, '-1 day' );

		add_filter(
			'undergrowth_tend_after_days',
			static function () {
				return 30;
			}
		);
		add_filter(
			'undergrowth_tend_group_grown',
			static function () {
				return 1;
			}
		);

		$this->assertSame( 'stale', undergrowth_tend_reason( $stale )['reason'] );
		$this->assertSame( 'grown', undergrowth_tend_reason( $grown )['reason'] );
		$this->assertSame( 'Worth a look: 1 post has joined this one’s group on the Map since you last edited it.', undergrowth_tend_note( $grown ) );
	}

	public function test_editing_the_post_clears_the_note() {
		$post_id = $this->post( 'evergreen', '-400 days' );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Freshly tended.',
			)
		);

		$this->assertNull( undergrowth_tend_reason( $post_id ) );
	}

	public function test_a_dismissed_note_stays_away_until_the_next_edit() {
		$post_id = $this->post( 'evergreen', '-400 days' );

		$this->assertTrue( undergrowth_dismiss_tend( $post_id ) );
		$this->assertNull( undergrowth_tend_reason( $post_id ) );
		$this->assertFalse( undergrowth_dismiss_tend( $post_id ) );

		// Edited since, and left long enough to go stale again.
		$this->edited( $post_id, '-380 days' );
		$this->assertSame( 'stale', undergrowth_tend_reason( $post_id )['reason'] );
	}

	public function test_only_someone_who_can_edit_the_post_can_dismiss_its_note() {
		$post_id = $this->post( 'evergreen', '-400 days' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( undergrowth_dismiss_tend( $post_id ) );
		$this->assertNotNull( undergrowth_tend_reason( $post_id ) );
	}

	public function test_the_growth_stages_tab_shows_the_note_with_a_dismiss_button() {
		$stale = $this->post( 'evergreen', '-400 days', array( 'post_title' => 'Oca' ) );
		$fresh = $this->post( 'evergreen', '-10 days', array( 'post_title' => 'Yacon' ) );

		$_GET['tab'] = 'stages';
		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();
		$html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $html, '<p class="undergrowth-tend">' ) );
		$this->assertStringContainsString( 'Worth a look: you last edited this Evergreen post 1 year ago.', $html );
		$this->assertStringContainsString( 'form="undergrowth-dismiss_tend-' . $stale . '" class="button-link" aria-label="Dismiss note on Oca">Dismiss note</button>', $html );
		$this->assertStringContainsString( 'id="undergrowth-dismiss_tend-' . $stale . '"', $html );
		$this->assertStringNotContainsString( 'undergrowth-dismiss_tend-' . $fresh, $html );
	}

	public function test_the_dismiss_form_dismisses_and_returns_to_the_tab() {
		$post_id = $this->post( 'evergreen', '-400 days' );

		$_POST['post']        = (string) $post_id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_dismiss_tend_' . $post_id );

		try {
			undergrowth_handle_dismiss_tend();
			$this->fail( 'No redirect.' );
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'tab=stages', $e->getMessage() );
			$this->assertStringContainsString( 'undergrowth_notice=tend_dismissed', $e->getMessage() );
		}

		$this->assertNull( undergrowth_tend_reason( $post_id ) );
	}

	public function test_the_dismiss_form_refuses_a_bad_nonce() {
		$post_id              = $this->post( 'evergreen', '-400 days' );
		$_POST['post']        = (string) $post_id;
		$_REQUEST['_wpnonce'] = 'nope';

		$this->expectException( WPDieException::class );

		undergrowth_handle_dismiss_tend();
	}

	public function test_the_editor_panel_shows_and_dismisses_the_note() {
		$post_id = $this->post( 'evergreen', '-400 days' );
		$route   = "/undergrowth/v1/posts/{$post_id}/stage";

		$this->assertStringStartsWith( 'Worth a look:', rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_data()['tend'] );

		$request = new WP_REST_Request( 'POST', $route );
		$request->set_param( 'action', 'dismiss_tend' );
		$data = rest_do_request( $request )->get_data();

		$this->assertNull( $data['tend'] );
		$this->assertSame( undergrowth_tend_result_messages()['tend_dismissed'], $data['message'] );
		$this->assertNull( rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_data()['tend'] );
	}

	public function test_the_note_never_reaches_readers() {
		$post_id = $this->post( 'evergreen', '-400 days', array( 'post_content' => '<!-- wp:undergrowth/growth-stage /-->' ) );
		wp_set_current_user( 0 );

		$this->go_to( get_permalink( $post_id ) );
		$this->assertStringNotContainsString( 'Worth a look', apply_filters( 'the_content', get_post( $post_id )->post_content ) );
	}
}
