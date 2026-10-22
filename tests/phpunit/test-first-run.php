<?php
/**
 * Tests for the first-run path: the notice after activation, and the link on
 * to suggestions once the first key is saved.
 *
 * @package Undergrowth
 */

/**
 * One notice, on the Plugins screen, until a key is saved or it is dismissed.
 */
class Test_First_Run extends WP_UnitTestCase {

	const KEY = 'sk-test-0123456789abcd';

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'undergrowth_openai_api_key' );
		delete_option( 'undergrowth_first_run' );
		$GLOBALS['wp_settings_errors'] = array();
	}

	public function tear_down() {
		set_current_screen( 'front' );
		$GLOBALS['wp_settings_errors'] = array();

		parent::tear_down();
	}

	/**
	 * Renders admin notices on a screen and returns the first-run notice.
	 *
	 * @param string $screen Screen ID.
	 * @return string
	 */
	private function notice_on( string $screen = 'plugins' ): string {
		set_current_screen( $screen );

		ob_start();
		undergrowth_render_first_run_notice();

		return (string) ob_get_clean();
	}

	public function test_activating_without_a_key_queues_the_notice() {
		undergrowth_activate();

		$this->assertSame( 'waiting', get_option( 'undergrowth_first_run' ) );
	}

	public function test_activating_with_a_key_queues_nothing() {
		update_option( 'undergrowth_openai_api_key', self::KEY );
		delete_option( 'undergrowth_first_run' );

		undergrowth_activate();

		$this->assertFalse( get_option( 'undergrowth_first_run' ) );
	}

	public function test_reactivating_after_dismissal_does_not_bring_it_back() {
		update_option( 'undergrowth_first_run', 'done' );

		undergrowth_activate();

		$this->assertSame( 'done', get_option( 'undergrowth_first_run' ) );
	}

	public function test_notice_shows_on_the_plugins_screen_and_links_to_settings() {
		undergrowth_activate();

		$html = $this->notice_on();

		$this->assertStringContainsString( 'id="undergrowth-first-run"', $html );
		$this->assertStringContainsString( 'notice-info', $html );
		$this->assertStringContainsString( esc_url( admin_url( 'options-general.php?page=undergrowth' ) ), $html );
		$this->assertStringContainsString( 'OpenAI API key', wp_strip_all_tags( $html ) );
	}

	public function test_notice_has_a_dismiss_link_with_a_nonce() {
		undergrowth_activate();

		$html = $this->notice_on();

		$this->assertMatchesRegularExpression( '/admin-post\.php\?action=undergrowth_dismiss_first_run&amp;_wpnonce=[0-9a-f]+/', $html );
		$this->assertStringContainsString( 'Dismiss for good', $html );
	}

	public function test_notice_shows_nowhere_but_the_plugins_screen() {
		undergrowth_activate();

		$this->assertSame( '', $this->notice_on( 'dashboard' ) );
		$this->assertSame( '', $this->notice_on( 'settings_page_undergrowth' ) );
	}

	public function test_notice_is_hidden_from_users_who_cannot_manage_options() {
		undergrowth_activate();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->notice_on() );
	}

	public function test_notice_needs_activation_first() {
		$this->assertSame( '', $this->notice_on() );
	}

	public function test_saving_a_key_ends_the_first_run_for_good() {
		undergrowth_activate();

		update_option( 'undergrowth_openai_api_key', self::KEY );
		$this->assertSame( 'done', get_option( 'undergrowth_first_run' ) );

		update_option( 'undergrowth_openai_api_key', '' );
		$this->assertSame( '', $this->notice_on() );
	}

	public function test_a_provider_from_a_filter_counts_as_a_key() {
		undergrowth_activate();
		add_filter(
			'undergrowth_embed_identity',
			static function () {
				return array(
					'provider' => 'ollama',
					'model'    => 'nomic-embed-text',
				);
			}
		);

		$this->assertSame( '', $this->notice_on() );
		$this->assertSame( 'done', get_option( 'undergrowth_first_run' ) );
	}

	public function test_dismissing_ends_the_first_run_and_returns_to_plugins() {
		undergrowth_activate();
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_dismiss_first_run' );

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new Exception( esc_url_raw( $location ) );
			}
		);

		try {
			undergrowth_dismiss_first_run();
			$this->fail( 'Expected a redirect.' );
		} catch ( Exception $redirect ) {
			$this->assertSame( admin_url( 'plugins.php' ), $redirect->getMessage() );
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}

		$this->assertSame( 'done', get_option( 'undergrowth_first_run' ) );
	}

	public function test_dismissing_needs_the_nonce() {
		undergrowth_activate();
		$_REQUEST['_wpnonce'] = 'nope';

		add_filter(
			'wp_die_handler',
			static function () {
				return static function () {
					throw new WPDieException( 'die' );
				};
			}
		);

		try {
			undergrowth_dismiss_first_run();
			$this->fail( 'Expected wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'waiting', get_option( 'undergrowth_first_run' ) );
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}
	}

	public function test_dismissing_needs_manage_options() {
		undergrowth_activate();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_dismiss_first_run' );

		add_filter(
			'wp_die_handler',
			static function () {
				return static function () {
					throw new WPDieException( 'die' );
				};
			}
		);

		try {
			undergrowth_dismiss_first_run();
			$this->fail( 'Expected wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'waiting', get_option( 'undergrowth_first_run' ) );
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}
	}

	public function test_saving_the_first_key_links_on_to_suggestions() {
		update_option( 'undergrowth_openai_api_key', self::KEY );

		$errors = get_settings_errors( 'undergrowth' );

		$this->assertCount( 1, $errors );
		$this->assertSame( 'success', $errors[0]['type'] );
		$this->assertStringContainsString( 'Settings saved.', $errors[0]['message'] );
		$this->assertStringContainsString( esc_url( admin_url( 'edit.php?page=undergrowth-links' ) ), $errors[0]['message'] );
	}

	public function test_replacing_a_saved_key_does_not_link_on_again() {
		update_option( 'undergrowth_openai_api_key', self::KEY );
		$GLOBALS['wp_settings_errors'] = array();

		update_option( 'undergrowth_openai_api_key', 'sk-test-other' );

		$this->assertSame( array(), get_settings_errors( 'undergrowth' ) );
	}

	public function test_removing_the_key_does_not_link_on() {
		update_option( 'undergrowth_openai_api_key', self::KEY );
		$GLOBALS['wp_settings_errors'] = array();

		update_option( 'undergrowth_openai_api_key', '' );

		$this->assertSame( array(), get_settings_errors( 'undergrowth' ) );
	}

	public function test_uninstall_forgets_the_first_run() {
		update_option( 'undergrowth_first_run', 'done' );

		undergrowth_uninstall_site();

		$this->assertFalse( get_option( 'undergrowth_first_run' ) );
	}
}
