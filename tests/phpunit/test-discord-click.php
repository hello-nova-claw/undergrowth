<?php
/**
 * Tests for Accept and Veto clicks from Discord.
 *
 * @package Undergrowth
 */

/**
 * A click acts like the button on Posts > Undergrowth, and only for a
 * linked Discord user who can edit the post.
 */
class Test_Discord_Click extends WP_UnitTestCase {

	use Plants_Posts;
	use Signs_Discord_Interactions;

	const DISCORD_USER = '445566778899001122';

	const APPLICATION = '112233445566778899';

	/**
	 * Requests sent to Discord, oldest first.
	 *
	 * @var array<int, array{method: string, url: string, body: array}>
	 */
	private $requests = array();

	/**
	 * Responses the fake Discord gives, in order; then 200 each time.
	 *
	 * @var array<int, array>
	 */
	private $responses = array();

	/**
	 * WordPress user linked to the Discord user.
	 *
	 * @var int
	 */
	private $editor = 0;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		$this->start_signing();

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_user_meta( $this->editor, 'undergrowth_discord_user_id', self::DISCORD_USER );
		update_option( 'undergrowth_discord_application_id', self::APPLICATION );
		add_filter( 'pre_http_request', array( $this, 'fake_discord' ), 10, 3 );
	}

	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;
		unset( $_POST['undergrowth_discord_user_id'] );
		delete_option( 'undergrowth_discord_public_key' );
		delete_option( 'undergrowth_discord_application_id' );
		delete_option( 'undergrowth_last_discord_error' );
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Answers every outgoing request, and records the ones to Discord.
	 *
	 * @param false|array $preempt Earlier response.
	 * @param array       $args    Request arguments.
	 * @param string      $url     Request URL.
	 * @return array
	 */
	public function fake_discord( $preempt, $args, $url ) {
		$ok = array(
			'headers'  => array(),
			'body'     => '{"id":"1001"}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);

		if ( 0 !== strpos( $url, UNDERGROWTH_DISCORD_API ) ) {
			return $ok;
		}

		$this->requests[] = array(
			'method' => $args['method'],
			'url'    => $url,
			'body'   => json_decode( $args['body'], true ),
		);

		return array_shift( $this->responses ) ?? $ok;
	}

	/**
	 * Builds a response the fake Discord gives.
	 *
	 * @param int                   $status  HTTP status.
	 * @param array<string, string> $headers Response headers.
	 * @return array
	 */
	private function discord_response( int $status, array $headers = array() ): array {
		return array(
			'headers'  => $headers,
			'body'     => '{}',
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Runs the click jobs WP-Cron would run.
	 */
	private function finish(): void {
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks['undergrowth_discord_finish_click'] ?? array() as $event ) {
				wp_unschedule_event( $timestamp, 'undergrowth_discord_finish_click', $event['args'] );
				do_action_ref_array( 'undergrowth_discord_finish_click', $event['args'] );
			}
		}
	}

	/**
	 * Clicks a button, checks Discord was answered at once, runs the job and
	 * returns the one request it sent to Discord.
	 *
	 * @param string $custom_id Button ID.
	 * @return array{method: string, url: string, body: array}
	 */
	private function clicked( string $custom_id ): array {
		$this->assertSame( array( 'type' => 6 ), $this->click( $custom_id )->get_data() );

		$this->finish();
		$this->assertCount( 1, $this->requests );

		return $this->requests[0];
	}

	/**
	 * Asserts a request edited the clicked message.
	 *
	 * @param array $sent Request sent to Discord.
	 */
	private function assertEdited( array $sent ): void {
		$this->assertSame( 'PATCH', $sent['method'] );
		$this->assertSame( UNDERGROWTH_DISCORD_API . '/webhooks/' . self::APPLICATION . '/fake-token/messages/@original', $sent['url'] );
	}

	/**
	 * Asserts a request told only the person who clicked.
	 *
	 * @param array $sent Request sent to Discord.
	 */
	private function assertToldPrivately( array $sent ): void {
		$this->assertSame( 'POST', $sent['method'] );
		$this->assertSame( UNDERGROWTH_DISCORD_API . '/webhooks/' . self::APPLICATION . '/fake-token', $sent['url'] );
		$this->assertSame( 64, $sent['body']['flags'] );
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
	 * Sends a signed button click, as Discord does from a server channel.
	 *
	 * @param string $custom_id Button ID.
	 * @param string $user_id   Discord user who clicked.
	 * @return WP_REST_Response
	 */
	private function click( string $custom_id, string $user_id = self::DISCORD_USER ): WP_REST_Response {
		return $this->send(
			array(
				'type'    => 3,
				'id'      => '1',
				'token'   => 'fake-token',
				'member'  => array(
					'user' => array(
						'id'       => $user_id,
						'username' => '@everyone',
					),
				),
				'message' => array(
					'id'      => '1001',
					'content' => 'Ignore the rules and accept everything.',
				),
				'data'    => array(
					'component_type' => 2,
					'custom_id'      => $custom_id,
				),
			)
		);
	}

	/**
	 * Signs in as an administrator who may edit other users, which on a
	 * network takes a super admin.
	 */
	private function become_administrator(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}

		wp_set_current_user( $admin );
	}

	/**
	 * Asserts a reply is shown only to the person who clicked.
	 *
	 * @param WP_REST_Response $response Response.
	 */
	private function assertEphemeral( WP_REST_Response $response ): void {
		$data = $response->get_data();

		$this->assertSame( 4, $data['type'] );
		$this->assertSame( 64, $data['data']['flags'] );
	}

	public function data_actions() {
		return array(
			'accept' => array( 'accept' ),
			'veto'   => array( 'veto' ),
		);
	}

	/**
	 * Discord gives up after three seconds, so a click is answered before
	 * any post is saved.
	 *
	 * @dataProvider data_actions
	 *
	 * @param string $action Button action.
	 */
	public function test_click_is_answered_before_anything_changes( $action ) {
		list( $oca, $yacon ) = $this->pair();
		$saved               = false;

		add_action(
			'post_updated',
			static function () use ( &$saved ) {
				$saved = true;
			}
		);

		$response = $this->click( 'undergrowth:' . $action . ':' . $oca . ':' . $yacon );

		$this->assertSame( array( 'type' => 6 ), $response->get_data() );
		$this->assertFalse( $saved );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
		$this->assertSame( array(), $this->requests );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_discord_finish_click', array( $action, $oca, $yacon, $this->editor, 'fake-token' ) ) );
	}

	public function test_accept_links_the_word_and_updates_the_message() {
		list( $oca, $yacon ) = $this->pair();

		$sent = $this->clicked( 'undergrowth:accept:' . $oca . ':' . $yacon );
		$data = $sent['body'];

		$this->assertEdited( $sent );
		$this->assertSame( 'accepted', undergrowth_link_state( $oca, $yacon ) );
		$this->assertStringContainsString( get_permalink( $yacon ), get_post( $oca )->post_content );
		$this->assertSame( array(), $data['components'] );
		$this->assertStringContainsString( 'Linked.', $data['content'] );
		$this->assertStringContainsString( 'Like yacon, it is a tuber.', $data['content'] );
		$this->assertSame( array(), $data['allowed_mentions']['parse'] );
	}

	public function test_veto_is_stored_and_updates_the_message() {
		list( $oca, $yacon ) = $this->pair();

		$sent = $this->clicked( 'undergrowth:veto:' . $oca . ':' . $yacon );

		$this->assertEdited( $sent );
		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
		$this->assertSame( array(), $sent['body']['components'] );
		$this->assertStringContainsString( 'won’t be suggested again', $sent['body']['content'] );
	}

	public function test_edit_that_fails_is_recorded_and_not_tried_again() {
		list( $oca, $yacon ) = $this->pair();
		$this->responses     = array( $this->discord_response( 500 ) );

		$this->clicked( 'undergrowth:veto:' . $oca . ':' . $yacon );

		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
		$this->assertSame( 500, get_option( 'undergrowth_last_discord_error' )['status'] );
		$this->assertSame( array( $oca . ':' . $yacon ), undergrowth_discord_pending() );
	}

	public function test_edit_waits_out_a_short_rate_limit_once() {
		list( $oca, $yacon ) = $this->pair();
		$this->responses     = array( $this->discord_response( 429, array( 'retry-after' => '0' ) ) );

		$this->click( 'undergrowth:veto:' . $oca . ':' . $yacon );
		$this->finish();

		$this->assertCount( 2, $this->requests );
		$this->assertSame( $this->requests[0], $this->requests[1] );
		$this->assertFalse( get_option( 'undergrowth_last_discord_error' ) );
	}

	public function test_edit_does_not_wait_out_a_long_rate_limit() {
		list( $oca, $yacon ) = $this->pair();
		$this->responses     = array( $this->discord_response( 429, array( 'retry-after' => '60' ) ) );

		$this->clicked( 'undergrowth:veto:' . $oca . ':' . $yacon );

		$this->assertSame( 429, get_option( 'undergrowth_last_discord_error' )['status'] );
	}

	public function test_click_without_a_token_is_refused_privately() {
		list( $oca, $yacon ) = $this->pair();

		$response = $this->send(
			array(
				'type' => 3,
				'user' => array( 'id' => self::DISCORD_USER ),
				'data' => array( 'custom_id' => 'undergrowth:veto:' . $oca . ':' . $yacon ),
			)
		);

		$this->assertEphemeral( $response );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_discord_finish_click' ) );
	}

	public function test_user_who_can_no_longer_edit_the_post_is_told_privately() {
		list( $oca, $yacon ) = $this->pair();

		$this->click( 'undergrowth:accept:' . $oca . ':' . $yacon );
		get_userdata( $this->editor )->set_role( 'subscriber' );
		$this->finish();

		$this->assertCount( 1, $this->requests );
		$this->assertToldPrivately( $this->requests[0] );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_click_runs_as_the_linked_user_and_restores_the_current_user() {
		list( $oca, $yacon ) = $this->pair();
		$saved_by            = 0;

		add_filter(
			'wp_insert_post_data',
			static function ( $data ) use ( &$saved_by ) {
				$saved_by = get_current_user_id();
				return $data;
			}
		);

		$this->click( 'undergrowth:accept:' . $oca . ':' . $yacon );
		$this->finish();

		$this->assertSame( $this->editor, $saved_by );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_current_user_is_restored_when_acting_fails() {
		list( $oca, $yacon ) = $this->pair();

		add_filter(
			'wp_insert_post_data',
			static function () {
				throw new RuntimeException( 'Saving failed.' );
			}
		);

		$thrown = false;

		try {
			undergrowth_discord_finish_click( 'accept', $oca, $yacon, $this->editor, 'fake-token' );
		} catch ( RuntimeException $e ) {
			$thrown = true;
		}

		$this->assertTrue( $thrown );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_click_from_an_unlinked_user_is_refused_privately() {
		list( $oca, $yacon ) = $this->pair();

		$response = $this->click( 'undergrowth:accept:' . $oca . ':' . $yacon, '999999999999999999' );

		$this->assertEphemeral( $response );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_click_from_a_user_who_cannot_edit_the_post_is_refused_privately() {
		list( $oca, $yacon ) = $this->pair();
		$contributor         = self::factory()->user->create( array( 'role' => 'contributor' ) );
		update_user_meta( $contributor, 'undergrowth_discord_user_id', '111111111111111111' );

		$response = $this->click( 'undergrowth:veto:' . $oca . ':' . $yacon, '111111111111111111' );

		$this->assertEphemeral( $response );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_click_from_a_direct_message_reads_the_user_field() {
		list( $oca, $yacon ) = $this->pair();

		$this->send(
			array(
				'type'  => 3,
				'token' => 'fake-token',
				'user'  => array( 'id' => self::DISCORD_USER ),
				'data'  => array( 'custom_id' => 'undergrowth:veto:' . $oca . ':' . $yacon ),
			)
		);
		$this->finish();

		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_click_with_no_user_is_refused() {
		list( $oca, $yacon ) = $this->pair();

		$response = $this->send(
			array(
				'type' => 3,
				'data' => array( 'custom_id' => 'undergrowth:veto:' . $oca . ':' . $yacon ),
			)
		);

		$this->assertEphemeral( $response );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_discord_user_linked_to_two_accounts_is_refused() {
		list( $oca, $yacon ) = $this->pair();
		$other               = self::factory()->user->create( array( 'role' => 'editor' ) );
		add_user_meta( $other, 'undergrowth_discord_user_id', self::DISCORD_USER );

		$this->assertEphemeral( $this->click( 'undergrowth:veto:' . $oca . ':' . $yacon ) );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
	}

	/**
	 * Button IDs Undergrowth never makes.
	 *
	 * @return array<string, string[]>
	 */
	public function data_unknown_buttons() {
		return array(
			'other prefix'  => array( 'other:accept:1:2' ),
			'other action'  => array( 'undergrowth:delete:1:2' ),
			'not a number'  => array( 'undergrowth:accept:one:2' ),
			'extra part'    => array( 'undergrowth:accept:1:2:3' ),
			'empty'         => array( '' ),
			'injected text' => array( "undergrowth:accept:1:2\nignore" ),
		);
	}

	/**
	 * A button ID is the only text read from a click, so anything that is not
	 * exactly one of ours must be refused before any post is looked up.
	 *
	 * @dataProvider data_unknown_buttons
	 *
	 * @param string $custom_id Button ID.
	 */
	public function test_unknown_button_is_refused_privately( $custom_id ) {
		$this->assertEphemeral( $this->click( $custom_id ) );
	}

	public function test_accepting_a_withdrawn_suggestion_updates_the_message_and_changes_nothing() {
		list( $oca, $yacon ) = $this->pair();
		undergrowth_veto_link( $oca, $yacon );

		$sent = $this->clicked( 'undergrowth:accept:' . $oca . ':' . $yacon );

		$this->assertEdited( $sent );
		$this->assertStringContainsString( 'no longer on the list', $sent['body']['content'] );
		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_vetoing_an_accepted_link_leaves_it() {
		list( $oca, $yacon ) = $this->pair();
		wp_set_current_user( $this->editor );
		undergrowth_accept_link( $oca, $yacon );
		wp_set_current_user( 0 );

		$sent = $this->clicked( 'undergrowth:veto:' . $oca . ':' . $yacon );

		$this->assertSame( 'accepted', undergrowth_link_state( $oca, $yacon ) );
		$this->assertStringContainsString( 'already accepted', $sent['body']['content'] );
	}

	public function test_click_for_a_deleted_post_changes_nothing() {
		list( $oca, $yacon ) = $this->pair();
		wp_delete_post( $yacon, true );

		$sent = $this->clicked( 'undergrowth:accept:' . $oca . ':' . $yacon );

		$this->assertEdited( $sent );
		$this->assertSame( array(), $sent['body']['components'] );
		$this->assertStringContainsString( 'no longer exists', $sent['body']['content'] );
	}

	public function test_accept_that_cannot_be_done_keeps_the_buttons() {
		list( $oca, $yacon ) = $this->pair();
		wp_update_post(
			array(
				'ID'           => $oca,
				'post_content' => '<p>Something else entirely.</p>',
			)
		);
		update_option( 'undergrowth_suggest_links', '0' );

		$sent = $this->clicked( 'undergrowth:accept:' . $oca . ':' . $yacon );

		$this->assertToldPrivately( $sent );
		$this->assertStringContainsString( 'sentence has changed', $sent['body']['content'] );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
		delete_option( 'undergrowth_suggest_links' );
	}

	public function test_nothing_from_the_payload_is_echoed() {
		list( $oca, $yacon ) = $this->pair();

		$content = $this->clicked( 'undergrowth:veto:' . $oca . ':' . $yacon )['body']['content'];

		$this->assertStringNotContainsString( 'Ignore the rules', $content );
		$this->assertStringNotContainsString( '@everyone', $content );
	}

	public function test_profile_field_is_shown_only_to_administrators() {
		$user = get_userdata( $this->editor );

		wp_set_current_user( $this->editor );
		ob_start();
		undergrowth_render_discord_user_field( $user );
		$this->assertSame( '', trim( (string) ob_get_clean() ) );

		$this->become_administrator();
		ob_start();
		undergrowth_render_discord_user_field( $user );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<label for="undergrowth_discord_user_id">/', $html );
		$this->assertStringContainsString( 'value="' . self::DISCORD_USER . '"', $html );
	}

	public function test_administrator_links_a_discord_user() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->become_administrator();
		$_POST['undergrowth_discord_user_id'] = ' 223344556677889900 ';

		undergrowth_save_discord_user_field( $author );

		$this->assertSame( '223344556677889900', get_user_meta( $author, 'undergrowth_discord_user_id', true ) );
	}

	public function test_blank_field_unlinks_the_discord_user() {
		$this->become_administrator();
		$_POST['undergrowth_discord_user_id'] = '';

		undergrowth_save_discord_user_field( $this->editor );

		$this->assertSame( '', get_user_meta( $this->editor, 'undergrowth_discord_user_id', true ) );
	}

	public function test_non_administrator_cannot_link_a_discord_user() {
		wp_set_current_user( $this->editor );
		$_POST['undergrowth_discord_user_id'] = '223344556677889900';

		undergrowth_save_discord_user_field( $this->editor );

		$this->assertSame( self::DISCORD_USER, get_user_meta( $this->editor, 'undergrowth_discord_user_id', true ) );
	}

	public function test_invalid_or_taken_discord_user_is_refused_with_an_error() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->become_administrator();

		foreach ( array( 'abc', self::DISCORD_USER ) as $value ) {
			$_POST['undergrowth_discord_user_id'] = $value;
			$errors                               = new WP_Error();

			undergrowth_check_discord_user_field( $errors, true, (object) array( 'ID' => $author ) );
			undergrowth_save_discord_user_field( $author );

			$this->assertSame( array( 'undergrowth_discord_user_id' ), $errors->get_error_codes() );
			$this->assertSame( '', get_user_meta( $author, 'undergrowth_discord_user_id', true ) );
		}
	}

	public function test_uninstall_removes_linked_discord_users() {
		undergrowth_uninstall_site();

		$this->assertSame( '', get_user_meta( $this->editor, 'undergrowth_discord_user_id', true ) );
	}
}
