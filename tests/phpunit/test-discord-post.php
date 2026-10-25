<?php
/**
 * Tests for posting link suggestions to Discord.
 *
 * @package Undergrowth
 */

/**
 * New suggestions go to the owner's Discord channel, once each.
 */
class Test_Discord_Post extends WP_UnitTestCase {

	use Plants_Posts;

	const TOKEN = 'not-a-real-bot-token.0123.wxyz';

	const CHANNEL = '998877665544332211';

	/**
	 * Requests made to Discord, each with its URL and arguments.
	 *
	 * @var array<int, array{url: string, args: array}>
	 */
	private $requests = array();

	/**
	 * Status the fake Discord answers with.
	 *
	 * @var int
	 */
	private $status = 200;

	/**
	 * Statuses for the next requests, used before $status.
	 *
	 * @var int[]
	 */
	private $statuses = array();

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		$this->requests = array();
		$this->status   = 200;
		$this->statuses = array();
		add_filter( 'pre_http_request', array( $this, 'fake_discord' ), 10, 3 );
	}

	public function tear_down() {
		foreach ( undergrowth_discord_options() as $option ) {
			delete_option( $option );
		}
		delete_option( 'undergrowth_discord_since' );
		delete_option( 'undergrowth_last_discord_error' );
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Answers every request to Discord as Discord would, and records it.
	 *
	 * @param mixed  $preempt Response so far.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return mixed
	 */
	public function fake_discord( $preempt, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://discord.com/' ) ) {
			return $preempt;
		}

		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		$status = array() === $this->statuses ? $this->status : array_shift( $this->statuses );

		return array(
			'headers'  => array(),
			'body'     => 200 === $status ? (string) wp_json_encode( array( 'id' => (string) ( 1000 + count( $this->requests ) ) ) ) : '{"message":"You are being rate limited."}',
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Saves every Discord setting.
	 */
	private function configure(): void {
		update_option( 'undergrowth_discord_application_id', '112233445566778899' );
		update_option( 'undergrowth_discord_public_key', str_repeat( '0f', 32 ) );
		update_option( 'undergrowth_discord_bot_token', self::TOKEN );
		update_option( 'undergrowth_discord_channel_id', self::CHANNEL );
	}

	/**
	 * Publishes Oca with a proposed link to Yacon.
	 *
	 * @return int[] Oca's ID, then Yacon's.
	 */
	private function pair(): array {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca grows in the Andes. Like yacon, it is a tuber.</p>' ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon is a sweet tuber. The yacon root stores well.</p>' ) );

		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );

		return array( $oca, $yacon );
	}

	/**
	 * Returns the decoded body of a recorded request.
	 *
	 * @param int $index Request number.
	 * @return array
	 */
	private function body( int $index = 0 ): array {
		return json_decode( $this->requests[ $index ]['args']['body'], true );
	}

	public function test_saving_the_channel_marks_when_posting_starts() {
		$this->configure();

		$this->assertNotSame( '', get_option( 'undergrowth_discord_since', '' ) );
	}

	public function test_refresh_schedules_posting_once_discord_is_set_up() {
		$this->configure();
		list( $oca ) = $this->pair();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_discord_post_proposals', array( $oca ) ) );
	}

	public function test_refresh_schedules_nothing_without_discord() {
		list( $oca ) = $this->pair();

		$this->assertFalse( wp_next_scheduled( 'undergrowth_discord_post_proposals', array( $oca ) ) );
	}

	public function test_new_suggestion_is_posted_with_buttons() {
		$this->configure();
		list( $oca, $yacon ) = $this->pair();

		undergrowth_discord_post_proposals( $oca );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://discord.com/api/v10/channels/' . self::CHANNEL . '/messages', $this->requests[0]['url'] );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );
		$this->assertSame( 'Bot ' . self::TOKEN, $this->requests[0]['args']['headers']['Authorization'] );

		$body = $this->body();
		$this->assertStringContainsString( 'Oca', $body['content'] );
		$this->assertStringContainsString( 'Yacon', $body['content'] );
		$this->assertStringContainsString( 'Like yacon, it is a tuber.', $body['content'] );
		$this->assertStringContainsString( '<' . get_permalink( $oca ) . '>', $body['content'] );
		$this->assertStringContainsString( '<' . get_permalink( $yacon ) . '>', $body['content'] );
		$this->assertSame( array(), $body['allowed_mentions']['parse'] );

		$buttons = $body['components'][0]['components'];
		$this->assertSame( 'undergrowth:accept:' . $oca . ':' . $yacon, $buttons[0]['custom_id'] );
		$this->assertSame( 'undergrowth:veto:' . $oca . ':' . $yacon, $buttons[1]['custom_id'] );
		$this->assertSame( undergrowth_suggestions_url(), $buttons[2]['url'] );
	}

	public function test_suggestion_is_posted_only_once() {
		$this->configure();
		list( $oca, $yacon ) = $this->pair();

		undergrowth_discord_post_proposals( $oca );
		undergrowth_refresh_proposals( $oca );
		undergrowth_discord_post_proposals( $oca );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( array( $yacon => '1001' ), get_post_meta( $oca, 'undergrowth_discord_messages', true ) );
	}

	public function test_vetoed_suggestion_is_never_posted() {
		$this->configure();
		list( $oca, $yacon ) = $this->pair();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		undergrowth_veto_link( $oca, $yacon );
		undergrowth_discord_post_proposals( $oca );

		$this->assertSame( array(), $this->requests );
	}

	public function test_suggestions_made_before_discord_was_set_up_are_not_posted() {
		$this->configure();
		list( $oca ) = $this->pair();

		update_option( 'undergrowth_discord_since', gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );
		undergrowth_discord_post_proposals( $oca );

		$this->assertSame( array(), $this->requests );
	}

	public function test_nothing_is_sent_while_any_setting_is_missing() {
		$this->configure();
		list( $oca ) = $this->pair();

		delete_option( 'undergrowth_discord_bot_token' );
		undergrowth_discord_post_proposals( $oca );

		$this->assertSame( array(), $this->requests );
	}

	public function test_failed_request_is_recorded_without_the_token_and_tried_again() {
		$this->configure();
		list( $oca, $yacon ) = $this->pair();

		$this->status = 429;
		undergrowth_discord_post_proposals( $oca );

		$error = get_option( 'undergrowth_last_discord_error' );
		$this->assertSame( 429, $error['status'] );
		$this->assertStringNotContainsString( self::TOKEN, (string) wp_json_encode( $error ) );
		$this->assertSame( '', get_post_meta( $oca, 'undergrowth_discord_messages', true ) );

		$this->status = 200;
		undergrowth_discord_post_proposals( $oca );

		$this->assertCount( 2, $this->requests );
		$this->assertFalse( get_option( 'undergrowth_last_discord_error' ) );
		$this->assertSame( array( $yacon => '1002' ), get_post_meta( $oca, 'undergrowth_discord_messages', true ) );
	}

	public function test_titles_cannot_mention_anyone_or_change_formatting() {
		$this->configure();
		$oca   = $this->plant( '@everyone **Oca**', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca grows in the Andes. Like yacon, it is a _tuber_.</p>' ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon is a sweet tuber. The yacon root stores well.</p>' ) );

		undergrowth_discord_post_proposals( $oca );

		$body = $this->body();
		$this->assertStringContainsString( '\\*\\*Oca\\*\\*', $body['content'] );
		$this->assertStringContainsString( '\\_tuber\\_', $body['content'] );
		$this->assertSame( array(), $body['allowed_mentions']['parse'] );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_long_sentence_is_cut_to_fit_a_message() {
		$this->assertLessThanOrEqual( 300, mb_strlen( undergrowth_discord_plain( str_repeat( 'oca ', 500 ), 300 ) ) );
	}

	public function test_settings_page_shows_the_last_failed_request() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option(
			'undergrowth_last_discord_error',
			array(
				'time'   => 1767225600,
				'status' => 429,
			)
		);

		ob_start();
		undergrowth_render_discord_section();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( '429', $html );
	}

	public function test_discord_messages_meta_is_protected() {
		$this->assertTrue( is_protected_meta( 'undergrowth_discord_messages', 'post' ) );
	}

	/**
	 * Publishes Oca with proposed links to Yacon and to Ulluco.
	 *
	 * @return int[] Oca's ID, then the two targets'.
	 */
	private function three(): array {
		$oca    = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca grows in the Andes. Like yacon and ulluco, it is a tuber.</p>' ) );
		$yacon  = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon is a sweet tuber. The yacon root stores well.</p>' ) );
		$ulluco = $this->plant( 'Ulluco', array( 0.8, 0.2, 0.0 ), array( 'post_content' => '<p>Ulluco is a bright tuber. The ulluco grows high.</p>' ) );

		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $ulluco ) );

		return array( $oca, $yacon, $ulluco );
	}

	public function test_a_refused_message_does_not_hold_back_the_rest() {
		$this->configure();
		list( $oca ) = $this->three();

		$this->statuses = array( 400 );
		undergrowth_discord_post_proposals( $oca );

		$this->assertCount( 2, $this->requests );
		$this->assertCount( 1, get_post_meta( $oca, 'undergrowth_discord_messages', true ) );
	}

	/**
	 * Statuses after which Discord should not be asked again straight away.
	 *
	 * @return array<string, int[]>
	 */
	public function data_statuses_that_stop_posting() {
		return array(
			'bad token'    => array( 401 ),
			'no access'    => array( 403 ),
			'no channel'   => array( 404 ),
			'rate limited' => array( 429 ),
			'outage'       => array( 503 ),
			'no response'  => array( 0 ),
		);
	}

	/**
	 * Each of these would refuse the next message too.
	 *
	 * @dataProvider data_statuses_that_stop_posting
	 *
	 * @param int $status Status of the first response.
	 */
	public function test_a_rate_limit_or_outage_stops_the_rest( $status ) {
		$this->configure();
		list( $oca ) = $this->three();

		$this->statuses = array( $status );
		undergrowth_discord_post_proposals( $oca );

		$this->assertCount( 1, $this->requests );
	}

	public function test_each_message_carries_a_nonce_for_its_pair() {
		$this->configure();
		list( $oca ) = $this->three();

		undergrowth_discord_post_proposals( $oca );

		$first  = $this->body( 0 );
		$second = $this->body( 1 );
		$this->assertTrue( $first['enforce_nonce'] );
		$this->assertLessThanOrEqual( 25, strlen( $first['nonce'] ) );
		$this->assertNotSame( $first['nonce'], $second['nonce'] );
	}

	public function test_a_retry_after_no_response_reuses_the_nonce() {
		$this->configure();
		list( $oca ) = $this->pair();

		$this->statuses = array( 0 );
		undergrowth_discord_post_proposals( $oca );
		undergrowth_discord_post_proposals( $oca );

		$this->assertCount( 2, $this->requests );
		$this->assertSame( $this->body( 0 )['nonce'], $this->body( 1 )['nonce'] );
	}

	public function test_a_message_posted_by_another_request_is_not_posted_again() {
		global $wpdb;

		$this->configure();
		list( $oca, $yacon ) = $this->pair();

		// Warms this request's cache, then writes the way another request
		// would, past this request's cache.
		get_post_meta( $oca, 'undergrowth_discord_messages', true );
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $oca,
				'meta_key'   => 'undergrowth_discord_messages', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => maybe_serialize( array( $yacon => '1' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		undergrowth_discord_post_proposals( $oca );

		$this->assertSame( array(), $this->requests );
	}

	public function test_failure_stays_shown_while_its_suggestion_waits() {
		$this->configure();
		list( $oca, $yacon, $ulluco ) = $this->three();

		$this->statuses = array( 400 );
		undergrowth_discord_post_proposals( $oca );

		$this->assertSame( 400, get_option( 'undergrowth_last_discord_error' )['status'] );

		// The other suggestion was posted. Vetoing the refused one leaves
		// nothing waiting, so the warning goes.
		$refused = isset( get_post_meta( $oca, 'undergrowth_discord_messages', true )[ $yacon ] ) ? $ulluco : $yacon;
		undergrowth_veto_link( $oca, $refused );
		undergrowth_discord_post_proposals( $oca );

		$this->assertFalse( get_option( 'undergrowth_last_discord_error' ) );
	}

	public function test_failure_is_forgotten_when_posting_starts_again() {
		$this->configure();
		list( $oca ) = $this->pair();

		$this->status = 401;
		undergrowth_discord_post_proposals( $oca );
		$this->assertSame( 401, get_option( 'undergrowth_last_discord_error' )['status'] );

		delete_option( 'undergrowth_discord_bot_token' );
		update_option( 'undergrowth_discord_bot_token', self::TOKEN );

		$this->assertFalse( get_option( 'undergrowth_last_discord_error' ) );
	}

	public function test_failure_is_forgotten_when_its_suggestion_will_not_be_sent() {
		global $wpdb;

		$this->configure();
		list( $oca, $yacon ) = $this->pair();

		$this->status = 400;
		undergrowth_discord_post_proposals( $oca );
		$this->assertNotFalse( get_option( 'undergrowth_last_discord_error' ) );

		// Changed past the save hooks, so the suggestion stays proposed and
		// only the posting check can drop it.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $yacon ) );
		clean_post_cache( $yacon );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );

		undergrowth_discord_post_proposals( $oca );

		$this->assertFalse( get_option( 'undergrowth_last_discord_error' ) );
	}

	public function test_failure_is_forgotten_when_the_source_is_unpublished() {
		global $wpdb;

		$this->configure();
		list( $oca ) = $this->pair();

		$this->status = 400;
		undergrowth_discord_post_proposals( $oca );
		$this->assertSame( 400, get_option( 'undergrowth_last_discord_error' )['status'] );

		// Changed past the save hooks, so only the posting check sees it.
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'private' ), array( 'ID' => $oca ) );
		clean_post_cache( $oca );
		undergrowth_discord_post_proposals( $oca );

		$this->assertFalse( get_option( 'undergrowth_last_discord_error' ) );
	}

	/**
	 * Ways a post leaves for good, each through WordPress's own hooks.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function data_posts_leaving() {
		return array(
			'source trashed' => array( 'source', 'trash' ),
			'target trashed' => array( 'target', 'trash' ),
			'source deleted' => array( 'source', 'delete' ),
			'target deleted' => array( 'target', 'delete' ),
		);
	}

	/**
	 * Nothing is posted to or from a post once it is gone, so its failures
	 * have to go without another posting run.
	 *
	 * @dataProvider data_posts_leaving
	 *
	 * @param string $which  Which post leaves.
	 * @param string $action How it leaves.
	 */
	public function test_failure_is_forgotten_when_either_post_leaves( $which, $action ) {
		$this->configure();
		list( $oca, $yacon ) = $this->pair();

		$this->status = 400;
		undergrowth_discord_post_proposals( $oca );
		$this->assertNotFalse( get_option( 'undergrowth_last_discord_error' ) );

		$post_id = 'source' === $which ? $oca : $yacon;

		if ( 'trash' === $action ) {
			wp_trash_post( $post_id );
		} else {
			wp_delete_post( $post_id, true );
		}

		$this->assertFalse( get_option( 'undergrowth_last_discord_error' ) );
	}

	public function test_a_reply_without_a_message_id_counts_as_a_failure() {
		$this->configure();
		list( $oca ) = $this->pair();

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => '{}',
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			20
		);
		undergrowth_discord_post_proposals( $oca );

		$this->assertSame( 200, get_option( 'undergrowth_last_discord_error' )['status'] );
		$this->assertSame( '', get_post_meta( $oca, 'undergrowth_discord_messages', true ) );
	}

	public function test_unpublished_source_is_never_posted() {
		$this->configure();
		list( $oca ) = $this->pair();

		wp_update_post(
			array(
				'ID'          => $oca,
				'post_status' => 'private',
			)
		);
		undergrowth_discord_post_proposals( $oca );

		$this->assertSame( array(), $this->requests );
	}

	public function test_a_message_with_buttons_says_a_veto_is_permanent() {
		list( $oca, $yacon ) = $this->pair();

		$asking = undergrowth_discord_proposal_message( get_post( $oca ), get_post( $yacon ), 'Like yacon, it is a tuber.' );
		$done   = undergrowth_discord_proposal_message( get_post( $oca ), get_post( $yacon ), 'Like yacon, it is a tuber.', 'Done.' );

		// Discord has no Undo, unlike the Suggestions screen.
		$this->assertStringContainsString( 'A veto is permanent.', $asking['content'] );
		$this->assertStringNotContainsString( 'A veto is permanent.', $done['content'] );
	}

	public function test_long_titles_and_addresses_still_fit_one_message() {
		$this->configure();
		$long  = str_repeat( '*Oca_ ', 400 );
		$oca   = $this->plant( $long, array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca grows in the Andes. ' . str_repeat( 'Like yacon, it is a *tuber*. ', 60 ) . '</p>' ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon is a sweet tuber. The yacon root stores well.</p>' ) );

		add_filter(
			'post_link',
			static function ( $link ) {
				return $link . '?' . str_repeat( 'x', 600 );
			}
		);

		$message = undergrowth_discord_proposal_message( get_post( $oca ), get_post( $yacon ), str_repeat( 'Like yacon, it is a *tuber*. ', 60 ), str_repeat( 'Done. ', 50 ) );

		$this->assertLessThanOrEqual( 2000, mb_strlen( $message['content'] ) );
		$this->assertStringContainsString( 'Done.', $message['content'] );
	}

	public function test_setting_up_again_starts_posting_from_then() {
		$this->configure();
		delete_option( 'undergrowth_discord_bot_token' );
		$this->assertSame( 'missing', get_option( 'undergrowth_discord_since', 'missing' ) );

		update_option( 'undergrowth_discord_bot_token', self::TOKEN );

		$this->assertNotSame( 'missing', get_option( 'undergrowth_discord_since', 'missing' ) );
	}

	public function test_uninstall_removes_what_posting_stored() {
		$this->configure();
		list( $oca ) = $this->pair();
		undergrowth_discord_post_proposals( $oca );
		update_option( 'undergrowth_last_discord_error', array( 'time' => 1 ) );

		undergrowth_uninstall_site();

		$this->assertSame( 'missing', get_option( 'undergrowth_discord_since', 'missing' ) );
		$this->assertSame( 'missing', get_option( 'undergrowth_last_discord_error', 'missing' ) );
		$this->assertSame( '', get_post_meta( $oca, 'undergrowth_discord_messages', true ) );
	}
}
