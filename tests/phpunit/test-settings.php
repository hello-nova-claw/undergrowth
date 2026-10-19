<?php
/**
 * Tests for the settings page.
 *
 * @package Undergrowth
 */

/**
 * Settings page and API key handling.
 */
class Test_Settings extends WP_UnitTestCase {

	const KEY = 'sk-test-0123456789abcd';

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'undergrowth_openai_api_key' );
		delete_option( 'undergrowth_last_embed_error' );
	}

	public function tear_down() {
		unset( $_POST['undergrowth_remove_openai_key'] );

		parent::tear_down();
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

	public function test_settings_page_is_added_under_settings() {
		do_action( 'admin_menu' );

		$this->assertNotSame( '', menu_page_url( 'undergrowth', false ) );
	}

	public function test_new_key_is_trimmed() {
		$this->assertSame( self::KEY, undergrowth_sanitize_openai_key( '  ' . self::KEY . "\n" ) );
	}

	public function test_blank_field_keeps_the_saved_key() {
		update_option( 'undergrowth_openai_api_key', self::KEY );

		$this->assertSame( self::KEY, undergrowth_sanitize_openai_key( '' ) );
	}

	public function test_remove_checkbox_clears_the_key() {
		update_option( 'undergrowth_openai_api_key', self::KEY );
		$_POST['undergrowth_remove_openai_key'] = '1';

		$this->assertSame( '', undergrowth_sanitize_openai_key( '' ) );
	}

	public function test_key_is_not_autoloaded() {
		global $wpdb;

		update_option( 'undergrowth_openai_api_key', self::KEY );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'undergrowth_openai_api_key' ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_page_never_prints_the_saved_key() {
		update_option( 'undergrowth_openai_api_key', self::KEY );

		$html = $this->render();

		$this->assertStringNotContainsString( self::KEY, $html );
		$this->assertStringContainsString( 'abcd', $html );
	}

	public function test_page_says_what_is_sent_to_openai() {
		$this->assertStringContainsString( 'sent to OpenAI', $this->render() );
	}

	public function test_key_field_is_labelled_and_described() {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<label for="undergrowth_openai_api_key">/', $html );
		$this->assertMatchesRegularExpression( '/id="undergrowth_openai_api_key"[^>]*aria-describedby="undergrowth-openai-notice"/', $html );
	}

	public function test_page_reports_the_last_failed_request() {
		update_option(
			'undergrowth_last_embed_error',
			array(
				'code'   => 'http_status',
				'status' => 401,
				'time'   => time(),
			)
		);

		$this->assertStringContainsString( '401', $this->render() );
	}

	public function test_page_is_empty_for_users_who_cannot_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->render() );
	}
}
