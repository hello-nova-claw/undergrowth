<?php
/**
 * Tests for the Discord settings.
 *
 * @package Undergrowth
 */

/**
 * The Discord section of Settings > Undergrowth.
 */
class Test_Discord_Settings extends WP_UnitTestCase {

	const APPLICATION = '112233445566778899';

	const PUBLIC_KEY = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0';

	const TOKEN = 'not-a-real-bot-token.0123.wxyz';

	const CHANNEL = '998877665544332211';

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( undergrowth_discord_options() as $option ) {
			delete_option( $option );
		}

		undergrowth_register_settings();
		undergrowth_register_discord_settings();
	}

	public function tear_down() {
		global $wp_settings_errors, $wp_settings_sections, $wp_settings_fields;

		unset( $_POST['undergrowth_remove_discord_token'] );
		$wp_settings_errors = array();
		unset( $wp_settings_sections['undergrowth'], $wp_settings_fields['undergrowth'] );

		parent::tear_down();
	}

	/**
	 * Saves every Discord setting.
	 */
	private function configure(): void {
		update_option( 'undergrowth_discord_application_id', self::APPLICATION );
		update_option( 'undergrowth_discord_public_key', self::PUBLIC_KEY );
		update_option( 'undergrowth_discord_bot_token', self::TOKEN );
		update_option( 'undergrowth_discord_channel_id', self::CHANNEL );
	}

	/**
	 * Renders the settings page and returns its HTML.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		undergrowth_render_settings_page();

		return (string) ob_get_clean();
	}

	public function test_settings_are_registered_hidden_from_rest() {
		$settings = get_registered_settings();

		foreach ( undergrowth_discord_options() as $option ) {
			$this->assertArrayHasKey( $option, $settings );
			$this->assertFalse( $settings[ $option ]['show_in_rest'] );
		}
	}

	public function test_discord_is_off_until_every_field_is_saved() {
		$this->assertFalse( undergrowth_discord_enabled() );

		$this->configure();
		$this->assertTrue( undergrowth_discord_enabled() );

		delete_option( 'undergrowth_discord_channel_id' );
		$this->assertFalse( undergrowth_discord_enabled() );
	}

	public function test_valid_ids_are_saved_trimmed() {
		$this->assertSame( self::APPLICATION, undergrowth_sanitize_discord_application_id( ' ' . self::APPLICATION . "\n" ) );
		$this->assertSame( self::CHANNEL, undergrowth_sanitize_discord_channel_id( self::CHANNEL ) );
	}

	public function test_an_empty_id_clears_the_setting() {
		update_option( 'undergrowth_discord_channel_id', self::CHANNEL );

		$this->assertSame( '', undergrowth_sanitize_discord_channel_id( '' ) );
	}

	public function test_an_invalid_id_keeps_the_saved_one_and_reports_an_error() {
		update_option( 'undergrowth_discord_application_id', self::APPLICATION );

		$this->assertSame( self::APPLICATION, undergrowth_sanitize_discord_application_id( 'abc' ) );
		$this->assertSame( 'undergrowth_discord_application_id', get_settings_errors( 'undergrowth_discord_application_id' )[0]['code'] );
	}

	public function test_public_key_is_lowercased() {
		$this->assertSame( self::PUBLIC_KEY, undergrowth_sanitize_discord_public_key( strtoupper( self::PUBLIC_KEY ) ) );
	}

	public function test_an_invalid_public_key_keeps_the_saved_one_and_reports_an_error() {
		update_option( 'undergrowth_discord_public_key', self::PUBLIC_KEY );

		$this->assertSame( self::PUBLIC_KEY, undergrowth_sanitize_discord_public_key( substr( self::PUBLIC_KEY, 2 ) ) );
		$this->assertNotEmpty( get_settings_errors( 'undergrowth_discord_public_key' ) );
	}

	public function test_blank_token_field_keeps_the_saved_token() {
		update_option( 'undergrowth_discord_bot_token', self::TOKEN );

		$this->assertSame( self::TOKEN, undergrowth_sanitize_discord_bot_token( '' ) );
	}

	public function test_remove_checkbox_clears_the_token() {
		update_option( 'undergrowth_discord_bot_token', self::TOKEN );
		$_POST['undergrowth_remove_discord_token'] = '1';

		$this->assertSame( '', undergrowth_sanitize_discord_bot_token( '' ) );
	}

	public function test_token_is_not_autoloaded() {
		global $wpdb;

		update_option( 'undergrowth_discord_bot_token', self::TOKEN );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'undergrowth_discord_bot_token' ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_page_shows_the_discord_section_with_labelled_fields() {
		$html = $this->render();

		foreach ( array( 'application_id', 'public_key', 'bot_token', 'channel_id' ) as $field ) {
			$this->assertMatchesRegularExpression( '/<label for="undergrowth_discord_' . $field . '">/', $html );
		}
	}

	public function test_page_says_in_one_sentence_what_discord_is_for() {
		$html = $this->render();

		$this->assertStringContainsString( 'id="undergrowth-discord-notice"', $html );
		$this->assertStringContainsString( 'Accept and Veto buttons', $html );
		$this->assertStringContainsString( 'private channel', $html );
	}

	public function test_each_field_is_described_by_its_own_line() {
		$html = $this->render();

		foreach ( array( 'application_id', 'public_key', 'bot_token', 'channel_id' ) as $field ) {
			$id = 'undergrowth-discord-' . str_replace( '_', '-', $field ) . '-description';

			$this->assertMatchesRegularExpression( '/id="undergrowth_discord_' . $field . '"[^>]*aria-describedby="' . $id . '"/', $html, $field );
			$this->assertSame( 1, preg_match( '#<p class="description" id="' . $id . '">(.*?)</p>#s', $html, $line ), $field );
			$this->assertSame( 1, preg_match_all( '/[.!?](\s|$)/', trim( wp_strip_all_tags( $line[1] ) ) ), $field );
		}

		$this->assertStringNotContainsString( 'aria-describedby="undergrowth-discord-notice"', $html );
		$this->assertStringContainsString( 'General Information', $html );
		$this->assertStringContainsString( 'Copy Channel ID', $html );
	}

	public function test_page_shows_the_interactions_endpoint_url() {
		$this->assertStringContainsString( '<code id="undergrowth-discord-endpoint" dir="ltr">' . esc_html( rest_url( 'undergrowth/v1/discord' ) ) . '</code>', $this->render() );
	}

	public function test_page_never_prints_the_saved_token() {
		$this->configure();

		$html = $this->render();

		$this->assertStringNotContainsString( self::TOKEN, $html );
		$this->assertStringContainsString( 'wxyz', $html );
		$this->assertStringContainsString( 'name="undergrowth_remove_discord_token"', $html );
	}

	public function test_page_shows_saved_ids_and_key() {
		$this->configure();

		$html = $this->render();

		$this->assertStringContainsString( 'value="' . self::APPLICATION . '"', $html );
		$this->assertStringContainsString( 'value="' . self::PUBLIC_KEY . '"', $html );
		$this->assertStringContainsString( 'value="' . self::CHANNEL . '"', $html );
	}

	public function test_uninstall_removes_every_discord_option() {
		$this->configure();

		undergrowth_uninstall_site();

		foreach ( undergrowth_discord_options() as $option ) {
			$this->assertSame( 'missing', get_option( $option, 'missing' ) );
		}
	}
}
