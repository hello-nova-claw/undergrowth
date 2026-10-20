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

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		$this->requests = array();
		$this->status   = 200;
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

		return array(
			'headers'  => array(),
			'body'     => 200 === $this->status ? (string) wp_json_encode( array( 'id' => (string) ( 1000 + count( $this->requests ) ) ) ) : '{"message":"You are being rate limited."}',
			'response' => array(
				'code'    => $this->status,
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
