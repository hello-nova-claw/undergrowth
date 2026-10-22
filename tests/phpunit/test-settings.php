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

	const ANTHROPIC_KEY = 'sk-ant-test-0123456789wxyz';

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'undergrowth_openai_api_key' );
		delete_option( 'undergrowth_last_embed_error' );
		delete_option( 'undergrowth_anthropic_api_key' );
		delete_option( 'undergrowth_last_prompt_error' );
	}

	public function tear_down() {
		global $wp_settings_sections, $wp_settings_fields;

		unset( $_POST['undergrowth_remove_openai_key'], $_POST['undergrowth_remove_anthropic_key'] );
		unset( $wp_settings_sections['undergrowth'], $wp_settings_fields['undergrowth'] );
		set_current_screen( 'front' );
		wp_dequeue_style( 'undergrowth-settings' );
		wp_deregister_style( 'undergrowth-settings' );

		parent::tear_down();
	}

	/**
	 * Registers the page's sections and fields as admin_init does, then
	 * renders the page and returns its HTML.
	 *
	 * @return string
	 */
	private function render(): string {
		global $wp_settings_sections, $wp_settings_fields;

		unset( $wp_settings_sections['undergrowth'], $wp_settings_fields['undergrowth'] );
		undergrowth_register_settings();
		undergrowth_register_discord_settings();

		ob_start();
		undergrowth_render_settings_page();

		return (string) ob_get_clean();
	}

	public function test_settings_page_is_added_under_settings() {
		do_action( 'admin_menu' );

		$this->assertNotSame( '', menu_page_url( 'undergrowth', false ) );
	}

	public function test_key_setting_is_registered_with_its_sanitiser_and_hidden_from_rest() {
		undergrowth_register_settings();

		$setting = get_registered_settings()['undergrowth_openai_api_key'];
		$this->assertSame( 'undergrowth_sanitize_openai_key', $setting['sanitize_callback'] );
		$this->assertFalse( $setting['show_in_rest'] );
	}

	public function test_stylesheet_loads_on_the_settings_page() {
		undergrowth_enqueue_settings_style( 'settings_page_undergrowth' );

		$this->assertTrue( wp_style_is( 'undergrowth-settings', 'enqueued' ) );
		$this->assertStringStartsWith( plugins_url( 'assets/', UNDERGROWTH_FILE ), wp_styles()->registered['undergrowth-settings']->src );
	}

	public function test_stylesheet_stays_off_other_admin_pages() {
		undergrowth_enqueue_settings_style( 'index.php' );

		$this->assertFalse( wp_style_is( 'undergrowth-settings', 'enqueued' ) );
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

	public function test_anthropic_key_setting_is_registered_with_its_sanitiser_and_hidden_from_rest() {
		undergrowth_register_settings();

		$setting = get_registered_settings()['undergrowth_anthropic_api_key'];
		$this->assertSame( 'undergrowth_sanitize_anthropic_key', $setting['sanitize_callback'] );
		$this->assertFalse( $setting['show_in_rest'] );
	}

	public function test_new_anthropic_key_is_trimmed() {
		$this->assertSame( self::ANTHROPIC_KEY, undergrowth_sanitize_anthropic_key( ' ' . self::ANTHROPIC_KEY . "\t" ) );
	}

	public function test_blank_field_keeps_the_saved_anthropic_key() {
		update_option( 'undergrowth_anthropic_api_key', self::ANTHROPIC_KEY );

		$this->assertSame( self::ANTHROPIC_KEY, undergrowth_sanitize_anthropic_key( '' ) );
	}

	public function test_anthropic_remove_checkbox_clears_only_the_anthropic_key() {
		update_option( 'undergrowth_openai_api_key', self::KEY );
		update_option( 'undergrowth_anthropic_api_key', self::ANTHROPIC_KEY );
		$_POST['undergrowth_remove_anthropic_key'] = '1';

		$this->assertSame( '', undergrowth_sanitize_anthropic_key( '' ) );
		$this->assertSame( self::KEY, undergrowth_sanitize_openai_key( '' ) );
	}

	public function test_anthropic_key_is_not_autoloaded() {
		global $wpdb;

		update_option( 'undergrowth_anthropic_api_key', self::ANTHROPIC_KEY );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'undergrowth_anthropic_api_key' ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_page_never_prints_the_saved_anthropic_key() {
		update_option( 'undergrowth_anthropic_api_key', self::ANTHROPIC_KEY );

		$html = $this->render();

		$this->assertStringNotContainsString( self::ANTHROPIC_KEY, $html );
		$this->assertStringContainsString( 'wxyz', $html );
		$this->assertStringContainsString( 'name="undergrowth_remove_anthropic_key"', $html );
	}

	public function test_page_says_what_is_sent_to_anthropic() {
		$html = $this->render();

		$this->assertStringContainsString( 'Anthropic', $html );
		$this->assertStringContainsString( 'never a whole post', $html );
	}

	public function test_anthropic_key_field_is_labelled_and_described() {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<label for="undergrowth_anthropic_api_key">/', $html );
		$this->assertMatchesRegularExpression( '/id="undergrowth_anthropic_api_key"[^>]*aria-describedby="undergrowth-anthropic-notice"/', $html );
	}

	public function test_page_reports_the_last_failed_prompt_request() {
		update_option(
			'undergrowth_last_prompt_error',
			array(
				'code'   => 'http_status',
				'status' => 529,
				'time'   => time(),
			)
		);

		$this->assertStringContainsString( '529', $this->render() );
	}

	public function test_page_counts_indexed_posts_out_of_indexable_ones() {
		update_option( 'undergrowth_openai_api_key', self::KEY );
		$ids = self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		undergrowth_save_vector(
			$ids[0],
			array( 1.0 ),
			array(
				'provider'     => 'openai',
				'model'        => UNDERGROWTH_OPENAI_MODEL,
				'language'     => 'en_US',
				'content_hash' => 'x',
			)
		);

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/id="undergrowth-index-count"[^>]*>\s*1 of 3 posts read\./', $html );
		$this->assertStringContainsString( 'in the background', $html );
	}

	public function test_page_says_nothing_is_left_once_every_post_is_indexed() {
		update_option( 'undergrowth_openai_api_key', self::KEY );
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		undergrowth_save_vector(
			$id,
			array( 1.0 ),
			array(
				'provider'     => 'openai',
				'model'        => UNDERGROWTH_OPENAI_MODEL,
				'language'     => 'en_US',
				'content_hash' => 'x',
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( '1 of 1 post read.', $html );
		$this->assertStringNotContainsString( 'in the background', $html );
	}

	public function test_page_speaks_of_reading_posts_not_of_vectors_or_indexes() {
		update_option( 'undergrowth_openai_api_key', self::KEY );
		update_option(
			'undergrowth_last_embed_error',
			array(
				'code'   => 'http_status',
				'status' => 500,
				'time'   => time(),
			)
		);
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// The Discord endpoint address is code, not words, and may hold index.php.
		$text = wp_strip_all_tags( (string) preg_replace( '#<code.*?</code>#s', '', $this->render() ) );

		$this->assertStringNotContainsStringIgnoringCase( 'vector', $text );
		$this->assertStringNotContainsStringIgnoringCase( 'index', $text );
		$this->assertStringContainsString( 'title and text of each published post without a password are sent to OpenAI', $text );
		$this->assertStringContainsString( 'only when you accept a link', $text );
	}

	public function test_page_shows_no_count_until_a_provider_is_set_up() {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertStringNotContainsString( 'undergrowth-index-count', $this->render() );
	}

	public function test_failed_request_notice_says_when_posts_are_tried_again() {
		update_option(
			'undergrowth_last_embed_error',
			array(
				'code'   => 'http_status',
				'status' => 429,
				'time'   => time(),
			)
		);

		$this->assertStringContainsString( 'tried again within the hour', $this->render() );
	}

	public function test_page_opens_with_one_sentence_on_what_undergrowth_does() {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '#</h1>\s*<p id="undergrowth-intro">[^<]*OpenAI[^<]*Anthropic[^<]*</p>#', $html );
		$this->assertSame( 1, preg_match_all( '/[.!?](\s|$)/', wp_strip_all_tags( (string) preg_replace( '#.*<p id="undergrowth-intro">(.*?)</p>.*#s', '$1', $html ) ) ) );
	}

	public function test_each_key_field_links_to_the_providers_key_page() {
		$html = $this->render();

		$this->assertStringContainsString( 'href="https://platform.openai.com/api-keys"', $html );
		$this->assertStringContainsString( 'href="https://platform.claude.com/settings/keys"', $html );
	}

	public function test_page_has_three_sections_in_order() {
		$html = $this->render();

		$this->assertSame( 3, preg_match_all( '#<h2[^>]*>([^<]+)</h2>#', $html, $headings ) );
		$this->assertSame( array( 'Link suggestions', 'Questions on your Dashboard', 'Discord (optional)' ), $headings[1] );
	}

	public function test_each_section_opens_with_one_sentence() {
		$html = $this->render();

		// Discord's intro also describes each of its fields.
		foreach ( array( 'links-intro', 'prompts-intro', 'discord-notice' ) as $id ) {
			$this->assertSame( 1, preg_match( '#<p id="undergrowth-' . $id . '">(.*?)</p>#s', $html, $intro ), $id );
			$this->assertSame( 1, preg_match_all( '/[.!?](\s|$)/', trim( wp_strip_all_tags( $intro[1] ) ) ), $id );
		}
	}

	public function test_key_fields_are_rows_of_their_sections() {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<h2[^>]*>Link suggestions</h2>.*<th scope="row"><label for="undergrowth_openai_api_key">OpenAI API key</label></th>.*<h2[^>]*>Questions on your Dashboard</h2>.*<th scope="row"><label for="undergrowth_anthropic_api_key">Anthropic API key</label></th>.*<h2[^>]*>Discord \(optional\)</h2>#s', $html );
	}

	public function test_key_descriptions_say_in_two_sentences_what_is_sent() {
		$html = $this->render();

		foreach ( array( 'openai', 'anthropic' ) as $provider ) {
			$this->assertSame( 1, preg_match( '#<p class="description" id="undergrowth-' . $provider . '-notice">(.*?)<a #s', $html, $notice ), $provider );
			$this->assertLessThanOrEqual( 2, preg_match_all( '/[.!?](\s|$)/', trim( wp_strip_all_tags( $notice[1] ) ) ), $provider );
			$this->assertStringContainsString( 'Nothing is sent until you save a key.', $notice[1] );
		}
	}

	public function test_help_tabs_keep_the_full_detail_of_what_each_part_sends() {
		set_current_screen( 'settings_page_undergrowth' );
		$screen = get_current_screen();
		$screen->remove_help_tabs();
		do_action( 'load-settings_page_undergrowth' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's hook name.

		$text = array();

		foreach ( array( 'undergrowth-links', 'undergrowth-prompts', 'undergrowth-discord' ) as $id ) {
			$tab = $screen->get_help_tab( $id );
			$this->assertIsArray( $tab, $id );
			$text[ $id ] = wp_strip_all_tags( $tab['content'] );
		}

		$this->assertStringContainsString( 'Undergrowth changes a post only when you accept a link in it.', $text['undergrowth-links'] );
		$this->assertStringContainsString( 'Whole posts are never sent.', $text['undergrowth-prompts'] );
		$this->assertStringContainsString( 'up to three of them', $text['undergrowth-prompts'] );
		$this->assertStringContainsString( 'never to your readers', $text['undergrowth-prompts'] );
		$this->assertStringContainsString( 'titles of both posts', $text['undergrowth-discord'] );
		$this->assertStringContainsString( 'Use a private channel', $text['undergrowth-discord'] );
	}

	public function test_posts_read_is_a_row_only_once_there_is_a_provider() {
		$this->assertStringNotContainsString( 'Posts read', $this->render() );

		update_option( 'undergrowth_openai_api_key', self::KEY );

		$this->assertMatchesRegularExpression( '#<th scope="row">Posts read</th>#', $this->render() );
	}

	public function test_plugins_screen_links_to_settings_before_deactivate() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$links = apply_filters( 'plugin_action_links_' . plugin_basename( UNDERGROWTH_FILE ), array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertSame( array( 'settings', 'deactivate' ), array_keys( $links ) );
		$this->assertStringContainsString( 'options-general.php?page=undergrowth', $links['settings'] );
		$this->assertStringContainsString( '>Settings</a>', $links['settings'] );
	}

	public function test_plugins_screen_has_no_settings_link_for_users_who_cannot_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$links = apply_filters( 'plugin_action_links_' . plugin_basename( UNDERGROWTH_FILE ), array() );

		$this->assertArrayNotHasKey( 'settings', $links );
	}
}
