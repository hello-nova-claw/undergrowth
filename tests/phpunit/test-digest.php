<?php
/**
 * Tests for the opt-in monthly email of new link suggestions.
 *
 * @package Undergrowth
 */

/**
 * Monthly email.
 */
class Test_Digest extends WP_UnitTestCase {

	/**
	 * Emails sent, as wp_mail() was given them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $sent = array();

	public function set_up() {
		parent::set_up();

		$this->sent = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
	}

	public function tear_down() {
		unset( $_POST['undergrowth_digest'], $_POST['undergrowth_digest_shown'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		parent::tear_down();
	}

	/**
	 * Keeps each email instead of sending it.
	 *
	 * @param null|bool            $result Short-circuit value.
	 * @param array<string, mixed> $atts   The email.
	 * @return bool
	 */
	public function catch_mail( $result, $atts ) {
		$this->sent[] = $atts;

		return true;
	}

	/**
	 * Creates an editor who has turned the email on.
	 *
	 * @param int $since When they turned it on.
	 * @return int User ID.
	 */
	private function subscriber( int $since = 0 ): int {
		$user_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'author@example.org',
			)
		);
		undergrowth_turn_digest_on( $user_id );
		update_user_meta( $user_id, 'undergrowth_digest', (string) ( $since ? $since : time() - DAY_IN_SECONDS ) );

		return $user_id;
	}

	/**
	 * Adds a proposed link.
	 *
	 * @param array<string, mixed> $fields Columns to set.
	 * @return array{0: int, 1: int} Source and target post IDs.
	 */
	private function proposal( array $fields = array() ): array {
		global $wpdb;

		$source = self::factory()->post->create( array( 'post_title' => 'Storing oca' ) );
		$target = self::factory()->post->create( array( 'post_title' => 'Frost and tubers' ) );
		$now    = current_time( 'mysql', true );

		$wpdb->insert(
			undergrowth_links_table(),
			array_merge(
				array(
					'source_id'     => $source,
					'target_id'     => $target,
					'state'         => 'proposed',
					'score'         => 0.7,
					'sentence'      => 'Oca keeps well after the first frost.',
					'anchor'        => 'first frost',
					'anchor_offset' => 25,
					'created_at'    => $now,
					'updated_at'    => $now,
				),
				$fields
			)
		);

		return array( $source, $target );
	}

	public function test_turning_the_email_on_again_keeps_its_date_and_key() {
		$user_id = $this->subscriber();
		$since   = get_user_meta( $user_id, 'undergrowth_digest', true );
		$key     = undergrowth_digest_key( $user_id );

		undergrowth_turn_digest_on( $user_id );

		$this->assertSame( $since, get_user_meta( $user_id, 'undergrowth_digest', true ) );
		$this->assertSame( $key, undergrowth_digest_key( $user_id ) );
	}

	public function test_the_words_to_link_are_found_when_their_offset_is_stale() {
		$this->assertSame( 'Oca keeps well after the [first frost].', undergrowth_digest_sentence( 'Oca keeps well after the first frost.', 'first frost', 3 ) );
		$this->assertSame( 'Oca keeps well.', undergrowth_digest_sentence( 'Oca keeps well.', 'first frost', -1 ) );
	}

	public function test_the_email_is_off_until_the_user_turns_it_on() {
		self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->proposal();

		undergrowth_send_digests();

		$this->assertSame( array(), $this->sent );
	}

	public function test_a_subscriber_gets_one_email_with_the_new_suggestion() {
		$this->subscriber();
		$this->proposal();

		undergrowth_send_digests();

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'author@example.org', $this->sent[0]['to'] );
		$this->assertStringContainsString( 'Frost and tubers', $this->sent[0]['message'] );
		$this->assertStringContainsString( 'Storing oca', $this->sent[0]['message'] );
		$this->assertStringContainsString( 'Oca keeps well after the [first frost].', $this->sent[0]['message'] );
		$this->assertStringContainsString( undergrowth_suggestions_url(), $this->sent[0]['message'] );
	}

	public function test_no_email_without_a_new_suggestion() {
		$this->subscriber();
		$this->proposal( array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) );
		$this->proposal( array( 'state' => 'accepted' ) );

		undergrowth_send_digests();

		$this->assertSame( array(), $this->sent );
	}

	public function test_at_most_one_email_a_cycle() {
		$user_id = $this->subscriber();
		$this->proposal();

		undergrowth_send_digests();
		$this->proposal();
		undergrowth_send_digests();

		$this->assertCount( 1, $this->sent );
		$this->assertGreaterThan( time() - MINUTE_IN_SECONDS, (int) get_user_meta( $user_id, 'undergrowth_digest_sent', true ) );
	}

	public function test_the_next_cycle_sends_only_what_is_new_since() {
		$user_id = $this->subscriber();
		update_user_meta( $user_id, 'undergrowth_digest_sent', (string) ( time() - 30 * DAY_IN_SECONDS ) );
		$this->proposal( array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ) );

		undergrowth_send_digests();

		$this->assertSame( array(), $this->sent );
	}

	public function test_the_email_shows_the_five_best_by_score() {
		$this->subscriber();

		foreach ( array( 0.51, 0.9, 0.6, 0.8, 0.55, 0.7 ) as $score ) {
			list( $source ) = $this->proposal( array( 'score' => $score ) );
			wp_update_post(
				array(
					'ID'         => $source,
					'post_title' => 'Score ' . $score,
				)
			);
		}

		undergrowth_send_digests();

		$message = $this->sent[0]['message'];
		$this->assertStringNotContainsString( 'Score 0.51', $message );
		$this->assertLessThan( strpos( $message, 'Score 0.8' ), strpos( $message, 'Score 0.9' ) );
		$this->assertLessThan( strpos( $message, 'Score 0.55' ), strpos( $message, 'Score 0.6' ) );
	}

	public function test_suggestions_the_user_cannot_act_on_do_not_take_a_place() {
		$user_id    = $this->subscriber();
		$locked     = array();
		$best_score = 0.99;

		foreach ( range( 1, 5 ) as $n ) {
			list( $source ) = $this->proposal( array( 'score' => $best_score - $n / 100 ) );
			$locked[]       = $source;
		}

		$this->proposal( array( 'target_id' => PHP_INT_MAX ) );
		list( $open ) = $this->proposal( array( 'score' => 0.6 ) );
		wp_update_post(
			array(
				'ID'         => $open,
				'post_title' => 'One I can edit',
			)
		);
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user, $args ) use ( $locked ) {
				return 'edit_post' === $cap && in_array( (int) ( $args[0] ?? 0 ), $locked, true ) ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);

		$this->assertCount( 1, undergrowth_digest_suggestions( 0, new WP_User( $user_id ) ) );

		undergrowth_send_digests();

		$this->assertStringContainsString( 'One I can edit', $this->sent[0]['message'] );
		$this->assertStringContainsString( '1. A link to', $this->sent[0]['message'] );
		$this->assertStringNotContainsString( '2. A link to', $this->sent[0]['message'] );
	}

	public function test_suggestions_are_read_in_batches_past_ones_the_user_cannot_act_on() {
		$user_id = $this->subscriber();
		$locked  = array();

		foreach ( range( 1, UNDERGROWTH_DIGEST_BATCH ) as $n ) {
			list( $source ) = $this->proposal( array( 'score' => 0.99 - $n / 1000 ) );
			$locked[]       = $source;
		}

		list( $open ) = $this->proposal( array( 'score' => 0.5 ) );
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user, $args ) use ( $locked ) {
				return 'edit_post' === $cap && in_array( (int) ( $args[0] ?? 0 ), $locked, true ) ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);

		$rows = undergrowth_digest_suggestions( 0, new WP_User( $user_id ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( $open, $rows[0]['source_id'] );
	}

	public function test_a_shared_map_group_is_the_reason() {
		$this->subscriber();
		list( $source, $target ) = $this->proposal();
		$leader                  = self::factory()->post->create( array( 'post_title' => 'Root crops' ) );

		foreach ( array( $source, $target, $leader ) as $post_id ) {
			update_post_meta( $post_id, 'undergrowth_cluster', $leader );
		}

		undergrowth_send_digests();

		$this->assertStringContainsString( 'Both posts are in the group around “Root crops” on the Map.', $this->sent[0]['message'] );
	}

	public function test_words_both_posts_use_are_the_reason_without_a_shared_group() {
		$this->subscriber();
		list( , $target ) = $this->proposal();
		wp_update_post(
			array(
				'ID'           => $target,
				'post_content' => 'After the first frost the tubers sweeten.',
			)
		);

		undergrowth_send_digests();

		$this->assertStringContainsString( 'Both posts use the words “first frost”.', $this->sent[0]['message'] );
	}

	public function test_the_email_is_in_the_users_language_and_has_a_one_click_way_out() {
		$user_id = $this->subscriber();
		$this->proposal();

		undergrowth_send_digests();

		$url = undergrowth_digest_off_url( $user_id );
		$this->assertStringContainsString( $url, $this->sent[0]['message'] );
		$this->assertContains( 'List-Unsubscribe: <' . $url . '>', $this->sent[0]['headers'] );
		$this->assertContains( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $this->sent[0]['headers'] );
	}

	public function test_a_user_who_can_no_longer_see_suggestions_gets_none() {
		$user_id = $this->subscriber();
		( new WP_User( $user_id ) )->set_role( 'author' );
		$this->proposal();

		undergrowth_send_digests();

		$this->assertSame( array(), $this->sent );
	}

	public function test_a_failed_email_is_tried_again_next_cycle() {
		$user_id = $this->subscriber();
		$this->proposal();
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ) );
		add_filter( 'pre_wp_mail', '__return_false' );

		undergrowth_send_digests();

		$this->assertSame( '', get_user_meta( $user_id, 'undergrowth_digest_sent', true ) );
	}

	public function test_the_end_of_the_stage_cycle_queues_the_emails() {
		wp_clear_scheduled_hook( 'undergrowth_send_digests' );

		undergrowth_run_stage_cycle();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_send_digests' ) );
	}

	public function test_the_off_link_turns_the_email_off_by_post() {
		$user_id                   = $this->subscriber();
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->assertTrue( undergrowth_digest_off( $user_id, undergrowth_digest_key( $user_id ) ) );
		$this->assertSame( '', get_user_meta( $user_id, 'undergrowth_digest', true ) );
	}

	public function test_the_off_link_with_a_wrong_key_changes_nothing() {
		$user_id = $this->subscriber();

		$this->assertFalse( undergrowth_digest_off( $user_id, 'wrong' ) );
		$this->assertFalse( undergrowth_digest_off( $user_id, '' ) );
		$this->assertNotSame( '', get_user_meta( $user_id, 'undergrowth_digest', true ) );
	}

	/**
	 * Opens the link that turns the email off, and returns the page it shows.
	 *
	 * @param string $method Request method.
	 * @param int    $user_id User in the link.
	 * @param string $key     Secret in the link.
	 * @return string
	 */
	private function open_off_link( string $method, int $user_id, string $key ): string {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET['user']              = (string) $user_id;
		$_GET['key']               = $key;

		try {
			undergrowth_handle_digest_off();
		} catch ( WPDieException $e ) {
			return $e->getMessage();
		} finally {
			unset( $_GET['user'], $_GET['key'] );
		}

		return '';
	}

	public function test_opening_the_off_link_only_asks() {
		$user_id = $this->subscriber();

		$page = $this->open_off_link( 'GET', $user_id, undergrowth_digest_key( $user_id ) );

		$this->assertStringContainsString( 'Turn off the monthly email', $page );
		$this->assertStringContainsString( '<form method="post"', $page );
		$this->assertTrue( undergrowth_digest_is_on( $user_id ) );
	}

	public function test_pressing_the_button_turns_the_email_off() {
		$user_id = $this->subscriber();

		$page = $this->open_off_link( 'POST', $user_id, undergrowth_digest_key( $user_id ) );

		$this->assertStringContainsString( 'The monthly email is off.', $page );
		$this->assertFalse( undergrowth_digest_is_on( $user_id ) );
	}

	public function test_a_wrong_key_says_the_link_no_longer_works() {
		$user_id = $this->subscriber();

		$page = $this->open_off_link( 'POST', $user_id, 'wrong' );

		$this->assertStringContainsString( 'This link no longer works.', $page );
		$this->assertTrue( undergrowth_digest_is_on( $user_id ) );
	}

	public function test_profile_shows_the_checkbox_to_users_who_can_see_suggestions() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		ob_start();
		undergrowth_render_digest_field( new WP_User( $user_id ) );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#<input type="checkbox" id="undergrowth_digest" name="undergrowth_digest" value="1" aria-describedby="undergrowth-digest-description" />#', $html );
		$this->assertStringContainsString( 'Email me the best new link suggestions once a month', $html );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );

		ob_start();
		undergrowth_render_digest_field( new WP_User( $author ) );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_profile_saves_the_checkbox() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$_POST['undergrowth_digest_shown'] = '1';
		$_POST['undergrowth_digest']       = '1';
		undergrowth_save_digest_field( $user_id );

		$this->assertTrue( undergrowth_digest_is_on( $user_id ) );
		$this->assertNotSame( '', undergrowth_digest_key( $user_id ) );

		unset( $_POST['undergrowth_digest'] );
		undergrowth_save_digest_field( $user_id );

		$this->assertFalse( undergrowth_digest_is_on( $user_id ) );
	}

	public function test_saving_a_profile_without_the_checkbox_keeps_the_choice() {
		$user_id = $this->subscriber();
		wp_set_current_user( $user_id );

		undergrowth_save_digest_field( $user_id );

		$this->assertTrue( undergrowth_digest_is_on( $user_id ) );
	}
}
