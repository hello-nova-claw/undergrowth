<?php
/**
 * Tests for the settings page on a site where code names the provider.
 *
 * @package Undergrowth
 */

/**
 * Settings with a provider hooked through undergrowth_embed_provider and
 * undergrowth_embed_identity, as the local embeddings example does.
 */
class Test_Settings_Code_Provider extends WP_UnitTestCase {

	const KEY = 'sk-test-0123456789abcd';

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'undergrowth_openai_api_key' );
		delete_option( 'undergrowth_last_embed_error' );
		add_filter( 'undergrowth_embed_provider', array( $this, 'vector' ) );
		add_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );
	}

	public function tear_down() {
		global $wp_settings_sections, $wp_settings_fields;

		unset( $_POST['undergrowth_remove_openai_key'] );
		unset( $wp_settings_sections['undergrowth'], $wp_settings_fields['undergrowth'] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * The vector a local model would give.
	 *
	 * @return float[]
	 */
	public function vector(): array {
		return array( 0.1, 0.2, 0.3 );
	}

	/**
	 * The identity the local embeddings example declares.
	 *
	 * @return array<string, string>
	 */
	public function identity(): array {
		return array(
			'provider' => 'ollama',
			'model'    => 'nomic-embed-text',
		);
	}

	/**
	 * Registers the page's sections and fields as admin_init does, then
	 * renders the page and returns its HTML.
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
		undergrowth_register_discord_settings();

		ob_start();
		undergrowth_render_settings_page();

		return (string) ob_get_clean();
	}

	/**
	 * Returns the Link suggestions Help tab as plain text.
	 *
	 * @return string
	 */
	private function links_help(): string {
		set_current_screen( 'settings_page_undergrowth' );
		$screen = get_current_screen();
		$screen->remove_help_tabs();
		undergrowth_add_settings_help();

		return wp_strip_all_tags( $screen->get_help_tab( 'undergrowth-links' )['content'] );
	}

	public function test_a_provider_named_by_code_counts_as_set_by_code() {
		$this->assertTrue( undergrowth_embed_provider_set_by_code() );
	}

	public function test_openai_named_by_its_key_is_not_set_by_code() {
		remove_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );
		update_option( 'undergrowth_openai_api_key', self::KEY );

		$this->assertFalse( undergrowth_embed_provider_set_by_code() );
	}

	public function test_no_provider_is_not_set_by_code() {
		remove_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );

		$this->assertFalse( undergrowth_embed_provider_set_by_code() );
	}

	public function test_link_suggestions_name_the_model_and_provider_that_read_the_posts() {
		$this->assertSame( 1, preg_match( '#<h2[^>]*>Link suggestions</h2>.*<p id="undergrowth-links-provider">(.*?)</p>.*<h2[^>]*>Questions on your Dashboard</h2>#s', $this->render(), $provider ) );

		$text = wp_strip_all_tags( $provider[1] );

		$this->assertStringContainsString( 'Posts are read by nomic-embed-text (ollama), set up by code on this site.', $text );
		$this->assertStringContainsString( 'Nothing is sent to OpenAI.', $text );
	}

	public function test_the_provider_paragraph_says_what_undergrowth_hands_the_code() {
		$this->assertStringContainsString( 'title and text of each published post without a password', $this->render() );
	}

	public function test_the_model_and_provider_names_keep_their_own_direction() {
		$this->assertStringContainsString( '<bdi dir="ltr">nomic-embed-text</bdi>', $this->render() );
	}

	public function test_the_section_still_opens_with_one_sentence() {
		$this->assertSame( 1, preg_match( '#<p id="undergrowth-links-intro">(.*?)</p>#s', $this->render(), $intro ) );
		$this->assertSame( 1, preg_match_all( '/[.!?](\s|$)/', trim( wp_strip_all_tags( $intro[1] ) ) ) );
	}

	public function test_there_is_no_openai_key_field_or_link_to_get_one() {
		$html = $this->render();

		$this->assertStringNotContainsString( 'id="undergrowth_openai_api_key"', $html );
		$this->assertStringNotContainsString( 'platform.openai.com', $html );
		$this->assertStringNotContainsString( 'sent to OpenAI.', wp_strip_all_tags( (string) preg_replace( '#<p id="undergrowth-links-provider">.*?</p>#s', '', $html ) ) );
	}

	public function test_the_openai_row_says_the_key_is_not_used() {
		$this->assertSame( 1, preg_match( '#<th scope="row">OpenAI API key</th>\s*<td>(.*?)</td>#s', $this->render(), $row ) );
		$this->assertStringContainsString( 'Not used.', wp_strip_all_tags( $row[1] ) );
	}

	public function test_a_saved_key_is_said_to_be_ignored_and_can_be_removed() {
		update_option( 'undergrowth_openai_api_key', self::KEY );

		$html = $this->render();

		$this->assertStringContainsString( 'A key ending in abcd is saved, but it is ignored while code on this site reads the posts.', $html );
		$this->assertStringContainsString( 'name="undergrowth_remove_openai_key"', $html );
		$this->assertStringNotContainsString( self::KEY, $html );
	}

	public function test_saving_the_page_without_the_key_field_keeps_the_saved_key() {
		update_option( 'undergrowth_openai_api_key', self::KEY );

		$this->assertSame( self::KEY, undergrowth_sanitize_openai_key( null ) );
	}

	public function test_the_remove_checkbox_still_clears_the_saved_key() {
		update_option( 'undergrowth_openai_api_key', self::KEY );
		$_POST['undergrowth_remove_openai_key'] = '1';

		$this->assertSame( '', undergrowth_sanitize_openai_key( null ) );
	}

	public function test_a_saved_key_is_never_sent_to_openai() {
		update_option( 'undergrowth_openai_api_key', self::KEY );
		$requests = 0;
		$count    = static function ( $preempt ) use ( &$requests ) {
			++$requests;
			return $preempt;
		};
		add_filter( 'pre_http_request', $count );

		$this->assertSame( $this->vector(), undergrowth_embed( 'Oca grows here.' ) );
		$this->assertSame( 0, $requests );

		remove_filter( 'pre_http_request', $count );
	}

	public function test_an_old_openai_failure_is_not_shown() {
		update_option(
			'undergrowth_last_embed_error',
			array(
				'code'   => 'http_status',
				'status' => 401,
				'time'   => time(),
			)
		);

		$html = $this->render();

		$this->assertStringNotContainsString( 'OpenAI did not accept the key', $html );
		$this->assertStringNotContainsString( 'notice-warning', $html );
	}

	public function test_the_page_opens_without_naming_openai() {
		$this->assertSame( 1, preg_match( '#<p id="undergrowth-intro">(.*?)</p>#s', $this->render(), $intro ) );
		$this->assertStringNotContainsString( 'OpenAI', $intro[1] );
		$this->assertStringContainsString( 'Anthropic', $intro[1] );
	}

	public function test_posts_read_and_the_switch_are_rows() {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<th scope="row">Posts read</th>#', $html );
		$this->assertStringContainsString( 'name="undergrowth_suggest_links"', $html );
	}

	public function test_the_help_tab_names_the_provider_and_says_nothing_goes_to_openai() {
		$text = $this->links_help();

		$this->assertStringContainsString( 'nomic-embed-text (ollama)', $text );
		$this->assertStringContainsString( 'Nothing is sent to OpenAI, even if an OpenAI key is saved.', $text );
		$this->assertStringContainsString( 'Undergrowth changes a post only when you accept a link in it.', $text );
		$this->assertStringNotContainsString( 'are sent to OpenAI', $text );
	}

	public function test_the_help_tab_still_describes_openai_without_such_code() {
		remove_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );

		$this->assertStringContainsString( 'are sent to OpenAI', $this->links_help() );
	}

	public function test_the_page_still_asks_for_an_openai_key_without_such_code() {
		remove_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'id="undergrowth_openai_api_key"', $html );
		$this->assertStringNotContainsString( 'undergrowth-links-provider', $html );
	}

	/**
	 * A constant cannot be undefined again, so this runs in a process of
	 * its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_key_in_wp_config_is_said_to_be_ignored() {
		define( 'UNDERGROWTH_OPENAI_API_KEY', 'sk-config-openai-Q7vZ' );

		$html = $this->render();

		$this->assertStringContainsString( 'A key is set in wp-config.php as UNDERGROWTH_OPENAI_API_KEY, but it is ignored while code on this site reads the posts.', $html );
		$this->assertStringNotContainsString( 'sk-config-openai-Q7vZ', $html );
		$this->assertStringNotContainsString( 'name="undergrowth_remove_openai_key"', $html );
	}

	/**
	 * A constant cannot be undefined again, so this runs in a process of
	 * its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_key_saved_beside_one_in_wp_config_can_still_be_removed() {
		define( 'UNDERGROWTH_OPENAI_API_KEY', 'sk-config-openai-Q7vZ' );
		update_option( 'undergrowth_openai_api_key', self::KEY );

		$html = $this->render();

		$this->assertStringContainsString( 'as UNDERGROWTH_OPENAI_API_KEY, but it is ignored', $html );
		$this->assertStringContainsString( 'A key ending in abcd is saved, but it is ignored', $html );
		$this->assertStringContainsString( 'name="undergrowth_remove_openai_key"', $html );
	}

	public function test_the_provider_paragraph_is_one_string_for_translators() {
		$strings = array();
		$keep    = static function ( $translation, $text ) use ( &$strings ) {
			$strings[] = $text;
			return $translation;
		};
		add_filter( 'gettext', $keep, 10, 2 );

		ob_start();
		undergrowth_render_links_section();
		ob_end_clean();

		remove_filter( 'gettext', $keep, 10 );

		$this->assertCount( 1, preg_grep( '/^Posts are read by %1\$s \(%2\$s\).*ask whoever added it\.$/', $strings ) );
	}
}
