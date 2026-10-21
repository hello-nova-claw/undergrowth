<?php
/**
 * Tests for Accept and Veto clicks from Discord.
 *
 * @package Undergrowth
 */

/**
 * A click acts like the button on Posts > Link suggestions, and only for a
 * linked Discord user who can edit the post.
 */
class Test_Discord_Click extends WP_UnitTestCase {

	use Plants_Posts;
	use Signs_Discord_Interactions;

	const DISCORD_USER = '445566778899001122';

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
	}

	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;
		unset( $_POST['undergrowth_discord_user_id'] );
		delete_option( 'undergrowth_discord_public_key' );
		undergrowth_delete_memory_folder();

		parent::tear_down();
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
				'token'   => 'fake',
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
	 * Asserts a reply is shown only to the person who clicked.
	 *
	 * @param WP_REST_Response $response Response.
	 */
	private function assertEphemeral( WP_REST_Response $response ): void {
		$data = $response->get_data();

		$this->assertSame( 4, $data['type'] );
		$this->assertSame( 64, $data['data']['flags'] );
	}

	public function test_accept_links_the_word_and_updates_the_message() {
		list( $oca, $yacon ) = $this->pair();

		$data = $this->click( 'undergrowth:accept:' . $oca . ':' . $yacon )->get_data();

		$this->assertSame( 'accepted', undergrowth_link_state( $oca, $yacon ) );
		$this->assertStringContainsString( get_permalink( $yacon ), get_post( $oca )->post_content );
		$this->assertSame( 7, $data['type'] );
		$this->assertSame( array(), $data['data']['components'] );
		$this->assertStringContainsString( 'Linked.', $data['data']['content'] );
		$this->assertStringContainsString( 'Like yacon, it is a tuber.', $data['data']['content'] );
		$this->assertSame( array(), $data['data']['allowed_mentions']['parse'] );
	}

	public function test_veto_is_stored_and_updates_the_message() {
		list( $oca, $yacon ) = $this->pair();

		$data = $this->click( 'undergrowth:veto:' . $oca . ':' . $yacon )->get_data();

		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
		$this->assertSame( 7, $data['type'] );
		$this->assertSame( array(), $data['data']['components'] );
		$this->assertStringContainsString( 'won’t be suggested again', $data['data']['content'] );
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

		$this->assertSame( $this->editor, $saved_by );
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
				'type' => 3,
				'user' => array( 'id' => self::DISCORD_USER ),
				'data' => array( 'custom_id' => 'undergrowth:veto:' . $oca . ':' . $yacon ),
			)
		);

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

		$data = $this->click( 'undergrowth:accept:' . $oca . ':' . $yacon )->get_data();

		$this->assertSame( 7, $data['type'] );
		$this->assertStringContainsString( 'no longer on the list', $data['data']['content'] );
		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_vetoing_an_accepted_link_leaves_it() {
		list( $oca, $yacon ) = $this->pair();
		wp_set_current_user( $this->editor );
		undergrowth_accept_link( $oca, $yacon );
		wp_set_current_user( 0 );

		$data = $this->click( 'undergrowth:veto:' . $oca . ':' . $yacon )->get_data();

		$this->assertSame( 'accepted', undergrowth_link_state( $oca, $yacon ) );
		$this->assertStringContainsString( 'already accepted', $data['data']['content'] );
	}

	public function test_click_for_a_deleted_post_changes_nothing() {
		list( $oca, $yacon ) = $this->pair();
		wp_delete_post( $yacon, true );

		$data = $this->click( 'undergrowth:accept:' . $oca . ':' . $yacon )->get_data();

		$this->assertSame( 7, $data['type'] );
		$this->assertSame( array(), $data['data']['components'] );
		$this->assertStringContainsString( 'no longer exists', $data['data']['content'] );
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

		$response = $this->click( 'undergrowth:accept:' . $oca . ':' . $yacon );

		$this->assertEphemeral( $response );
		$this->assertStringContainsString( 'sentence has changed', $response->get_data()['data']['content'] );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
		delete_option( 'undergrowth_suggest_links' );
	}

	public function test_nothing_from_the_payload_is_echoed() {
		list( $oca, $yacon ) = $this->pair();

		$content = $this->click( 'undergrowth:veto:' . $oca . ':' . $yacon )->get_data()['data']['content'];

		$this->assertStringNotContainsString( 'Ignore the rules', $content );
		$this->assertStringNotContainsString( '@everyone', $content );
	}

	public function test_profile_field_is_shown_only_to_administrators() {
		$user = get_userdata( $this->editor );

		wp_set_current_user( $this->editor );
		ob_start();
		undergrowth_render_discord_user_field( $user );
		$this->assertSame( '', trim( (string) ob_get_clean() ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		undergrowth_render_discord_user_field( $user );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<label for="undergrowth_discord_user_id">/', $html );
		$this->assertStringContainsString( 'value="' . self::DISCORD_USER . '"', $html );
	}

	public function test_administrator_links_a_discord_user() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['undergrowth_discord_user_id'] = ' 223344556677889900 ';

		undergrowth_save_discord_user_field( $author );

		$this->assertSame( '223344556677889900', get_user_meta( $author, 'undergrowth_discord_user_id', true ) );
	}

	public function test_blank_field_unlinks_the_discord_user() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
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
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

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
