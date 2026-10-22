<?php
/**
 * Tests for API keys defined as constants in wp-config.php.
 *
 * A constant cannot be undefined again, so the tests that define the real
 * key constants each run in a process of their own.
 *
 * @package Undergrowth
 */

/**
 * Keys from wp-config.php.
 */
class Test_Config_Keys extends WP_UnitTestCase {

	const OPENAI_KEY = 'sk-config-openai-Q7vZ';

	const ANTHROPIC_KEY = 'sk-ant-config-anthropic-J4kX';

	const SAVED_KEY = 'sk-saved-option-0123';

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'undergrowth_openai_api_key' );
		delete_option( 'undergrowth_anthropic_api_key' );
	}

	/**
	 * Renders the settings page and returns its HTML.
	 *
	 * The page title comes from the admin menu, which a process of its own
	 * has not built.
	 *
	 * @return string
	 */
	private function render(): string {
		global $wp_settings_sections, $wp_settings_fields;

		$GLOBALS['title'] = 'Undergrowth';
		unset( $wp_settings_sections['undergrowth'], $wp_settings_fields['undergrowth'] );
		undergrowth_register_settings();

		ob_start();
		undergrowth_render_settings_page();

		return (string) ob_get_clean();
	}

	public function test_config_key_is_empty_when_the_constant_is_not_defined() {
		$this->assertSame( '', undergrowth_key_from_config( 'UNDERGROWTH_TEST_UNDEFINED_KEY' ) );
	}

	public function test_config_key_ignores_an_empty_or_non_string_constant() {
		define( 'UNDERGROWTH_TEST_EMPTY_KEY', '  ' );
		define( 'UNDERGROWTH_TEST_ARRAY_KEY', array( 'sk-in-an-array' ) );

		$this->assertSame( '', undergrowth_key_from_config( 'UNDERGROWTH_TEST_EMPTY_KEY' ) );
		$this->assertSame( '', undergrowth_key_from_config( 'UNDERGROWTH_TEST_ARRAY_KEY' ) );
	}

	public function test_config_key_is_trimmed() {
		define( 'UNDERGROWTH_TEST_PADDED_KEY', " sk-padded\n" );

		$this->assertSame( 'sk-padded', undergrowth_key_from_config( 'UNDERGROWTH_TEST_PADDED_KEY' ) );
	}

	public function test_saved_option_is_used_while_no_constant_is_defined() {
		update_option( 'undergrowth_openai_api_key', self::SAVED_KEY );
		update_option( 'undergrowth_anthropic_api_key', self::SAVED_KEY );

		$this->assertSame( self::SAVED_KEY, undergrowth_openai_key() );
		$this->assertSame( self::SAVED_KEY, undergrowth_anthropic_key() );
	}

	public function test_settings_page_does_not_say_a_key_must_be_saved_here() {
		$html = $this->render();

		$this->assertStringNotContainsString( 'until you save a key', $html );
		$this->assertStringNotContainsString( 'With a key saved', $html );
		$this->assertSame( 2, substr_count( $html, 'Nothing is sent while there is no key.' ) );
	}

	public function test_scheduling_the_prompt_cycle_writes_no_first_prompt_for_a_saved_key() {
		update_option( 'undergrowth_anthropic_api_key', self::SAVED_KEY );
		wp_unschedule_hook( 'undergrowth_prompt_cycle' );
		wp_unschedule_hook( 'undergrowth_write_prompt' );

		undergrowth_schedule_prompt_cycle();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_prompt_cycle' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_write_prompt' ) );
	}

	public function test_no_first_prompt_is_queued_for_a_saved_key_on_init() {
		update_option( 'undergrowth_anthropic_api_key', self::SAVED_KEY );
		wp_unschedule_hook( 'undergrowth_write_prompt' );

		undergrowth_queue_config_first_prompt();

		$this->assertFalse( wp_next_scheduled( 'undergrowth_write_prompt' ) );
		$this->assertFalse( get_option( 'undergrowth_config_prompt_queued' ) );
	}

	/**
	 * A key in wp-config.php is never saved, so nothing else would ask for
	 * the first prompt before the monthly cycle, which may be scheduled
	 * already when the constant is added to a running site.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_first_prompt_is_queued_once_for_a_key_in_wp_config() {
		define( 'UNDERGROWTH_ANTHROPIC_API_KEY', self::ANTHROPIC_KEY );
		wp_unschedule_hook( 'undergrowth_write_prompt' );
		undergrowth_schedule_prompt_cycle();

		undergrowth_queue_config_first_prompt();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_write_prompt' ) );

		// The prompt was written and then dismissed or snoozed, which leaves
		// no current prompt and nothing queued. Later requests must not ask
		// for another before the monthly cycle.
		wp_unschedule_hook( 'undergrowth_write_prompt' );
		undergrowth_queue_config_first_prompt();

		$this->assertFalse( wp_next_scheduled( 'undergrowth_write_prompt' ) );
	}

	public function test_deactivation_keeps_the_record_of_a_first_prompt_already_written() {
		update_option( 'undergrowth_config_prompt_queued', 1 );
		wp_unschedule_hook( 'undergrowth_write_prompt' );

		undergrowth_unschedule_prompts();

		$this->assertNotFalse( get_option( 'undergrowth_config_prompt_queued' ) );
	}

	/**
	 * Runs alone, since the constant stays defined once it is.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_first_prompt_still_waiting_at_deactivation_is_asked_for_again() {
		define( 'UNDERGROWTH_ANTHROPIC_API_KEY', self::ANTHROPIC_KEY );
		wp_unschedule_hook( 'undergrowth_write_prompt' );
		undergrowth_queue_config_first_prompt();

		undergrowth_unschedule_prompts();
		undergrowth_queue_config_first_prompt();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_write_prompt' ) );
	}

	/**
	 * Runs alone, since the constant stays defined once it is.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_first_prompt_is_asked_for_again_when_scheduling_it_failed() {
		define( 'UNDERGROWTH_ANTHROPIC_API_KEY', self::ANTHROPIC_KEY );
		wp_unschedule_hook( 'undergrowth_write_prompt' );
		add_filter( 'pre_schedule_event', '__return_false' );

		undergrowth_queue_config_first_prompt();

		$this->assertFalse( get_option( 'undergrowth_config_prompt_queued' ) );

		remove_filter( 'pre_schedule_event', '__return_false' );
		undergrowth_queue_config_first_prompt();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_write_prompt' ) );
		$this->assertNotFalse( get_option( 'undergrowth_config_prompt_queued' ) );
	}

	/**
	 * Runs alone, since the constant stays defined once it is.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_openai_constant_takes_precedence_over_the_saved_option() {
		define( 'UNDERGROWTH_OPENAI_API_KEY', self::OPENAI_KEY );
		update_option( 'undergrowth_openai_api_key', self::SAVED_KEY );

		$this->assertSame( self::OPENAI_KEY, undergrowth_openai_key() );
		$this->assertSame( 'openai', undergrowth_embed_identity()['provider'] );
		$this->assertSame( '', undergrowth_anthropic_key() );
	}

	/**
	 * Runs alone, since the constant stays defined once it is.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_anthropic_constant_takes_precedence_over_the_saved_option() {
		define( 'UNDERGROWTH_ANTHROPIC_API_KEY', self::ANTHROPIC_KEY );
		update_option( 'undergrowth_anthropic_api_key', self::SAVED_KEY );

		$this->assertSame( self::ANTHROPIC_KEY, undergrowth_anthropic_key() );
		$this->assertTrue( undergrowth_prompts_enabled() );
		$this->assertSame( '', undergrowth_openai_key() );
	}

	/**
	 * Runs alone, since the constant stays defined once it is.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_settings_page_says_the_openai_key_is_in_wp_config_without_showing_it() {
		define( 'UNDERGROWTH_OPENAI_API_KEY', self::OPENAI_KEY );
		update_option( 'undergrowth_openai_api_key', self::SAVED_KEY );

		$html = $this->render();

		$this->assertStringNotContainsString( self::OPENAI_KEY, $html );
		$this->assertStringNotContainsString( 'Q7vZ', $html );
		$this->assertStringNotContainsString( '0123', $html );
		$this->assertMatchesRegularExpression( '/id="undergrowth_openai_api_key"[^>]*\sdisabled/', $html );
		$this->assertStringContainsString( 'id="undergrowth-openai-config"', $html );
		$this->assertStringContainsString( 'wp-config.php', $html );
		$this->assertStringNotContainsString( 'name="undergrowth_remove_openai_key"', $html );
		$this->assertStringContainsString( 'sent to OpenAI', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="undergrowth_anthropic_api_key"[^>]*\sdisabled/', $html );
	}

	/**
	 * Runs alone, since the constant stays defined once it is.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_settings_page_says_the_anthropic_key_is_in_wp_config_without_showing_it() {
		define( 'UNDERGROWTH_ANTHROPIC_API_KEY', self::ANTHROPIC_KEY );

		$html = $this->render();

		$this->assertStringNotContainsString( self::ANTHROPIC_KEY, $html );
		$this->assertStringNotContainsString( 'J4kX', $html );
		$this->assertMatchesRegularExpression( '/id="undergrowth_anthropic_api_key"[^>]*\sdisabled/', $html );
		$this->assertStringContainsString( 'id="undergrowth-anthropic-config"', $html );
		$this->assertStringNotContainsString( 'name="undergrowth_remove_anthropic_key"', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="undergrowth_openai_api_key"[^>]*\sdisabled/', $html );
	}

	/**
	 * A disabled field is not submitted, so the sanitiser sees nothing and
	 * must keep what the option already holds, not the constant.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_saving_the_form_never_copies_a_constant_into_the_option() {
		define( 'UNDERGROWTH_OPENAI_API_KEY', self::OPENAI_KEY );
		define( 'UNDERGROWTH_ANTHROPIC_API_KEY', self::ANTHROPIC_KEY );

		$this->assertSame( '', undergrowth_sanitize_openai_key( null ) );
		$this->assertSame( '', undergrowth_sanitize_anthropic_key( null ) );

		update_option( 'undergrowth_openai_api_key', self::SAVED_KEY );
		update_option( 'undergrowth_anthropic_api_key', self::SAVED_KEY );

		$this->assertSame( self::SAVED_KEY, get_option( 'undergrowth_openai_api_key' ) );
		$this->assertSame( self::SAVED_KEY, get_option( 'undergrowth_anthropic_api_key' ) );
		$this->assertSame( self::SAVED_KEY, undergrowth_sanitize_openai_key( null ) );
		$this->assertSame( self::SAVED_KEY, undergrowth_sanitize_anthropic_key( null ) );
	}
}
