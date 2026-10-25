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
		delete_option( 'undergrowth_backfill_progress' );
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

	public function test_per_post_files_row_sits_in_the_link_suggestions_section() {
		global $wp_settings_fields;

		$this->render();

		$this->assertArrayHasKey( 'undergrowth_memory_folder', $wp_settings_fields['undergrowth']['undergrowth_links'] );
	}

	public function test_help_tab_explains_the_per_post_files_folder() {
		set_current_screen( 'settings_page_undergrowth' );
		$screen = get_current_screen();
		$screen->remove_help_tabs();
		do_action( 'load-settings_page_undergrowth' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's hook name.

		$tab = $screen->get_help_tab( 'undergrowth-files' );

		$this->assertIsArray( $tab );
		$this->assertStringContainsString( 'random characters', wp_strip_all_tags( $tab['content'] ) );
	}

	public function test_page_says_so_when_there_is_no_folder_for_the_per_post_files() {
		delete_option( 'undergrowth_memory_folder' );
		add_filter(
			'query',
			static function ( $query ) {
				return false !== strpos( $query, 'undergrowth_memory_folder' ) && 0 === stripos( ltrim( $query ), 'INSERT' ) ? 'SELECT 1' : $query;
			}
		);

		$html = $this->render();

		$this->assertStringNotContainsString( 'id="undergrowth-memory-folder"', $html );
		$this->assertStringContainsString( 'No folder could be made for the per-post files', $html );
	}

	public function test_page_says_why_the_last_file_failed_when_there_is_no_folder() {
		delete_option( 'undergrowth_memory_folder' );
		update_option(
			'undergrowth_last_file_error',
			array(
				'code' => 'no_folder',
				'time' => 1767225600,
			)
		);
		add_filter(
			'query',
			static function ( $query ) {
				return false !== strpos( $query, 'undergrowth_memory_folder' ) && 0 === stripos( ltrim( $query ), 'INSERT' ) ? 'SELECT 1' : $query;
			}
		);

		$this->assertStringContainsString( 'id="undergrowth-file-error"', $this->render() );
	}

	public function test_page_shows_where_the_per_post_files_are() {
		$this->assertMatchesRegularExpression( '#<code id="undergrowth-memory-folder"[^>]*>[^<]*' . preg_quote( undergrowth_memory_folder(), '#' ) . '/posts/</code>#', $this->render() );
	}

	/**
	 * The per-post files field says why the last file was not written.
	 *
	 * @dataProvider data_file_errors
	 *
	 * @param string $code  Recorded error code.
	 * @param string $words Words the warning should hold.
	 */
	public function test_page_says_why_the_last_per_post_file_was_not_written( string $code, string $words ) {
		update_option(
			'undergrowth_last_file_error',
			array(
				'code' => $code,
				'time' => 1767225600,
			)
		);

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<div class="notice notice-warning inline" id="undergrowth-file-error">\s*<p>[^<]*' . preg_quote( $words, '#' ) . '#', $html );
		$this->assertStringContainsString( undergrowth_date_time( 1767225600 ), $html );
	}

	/**
	 * Recorded file errors and the words each should give.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function data_file_errors(): array {
		return array(
			'no direct access' => array( 'no_direct_access', 'cannot write files directly' ),
			'no folder'        => array( 'no_folder', 'folder for the per-post files could not be made' ),
			'no protection'    => array( 'no_protection', 'keep visitors out' ),
			'write failed'     => array( 'write_failed', 'could not be written' ),
			'old folder left'  => array( 'old_folder_left', 'old folder' ),
			'unknown code'     => array( 'something_new', 'could not be written' ),
		);
	}

	public function test_page_shows_no_file_warning_while_files_are_written() {
		delete_option( 'undergrowth_last_file_error' );

		$this->assertStringNotContainsString( 'id="undergrowth-file-error"', $this->render() );
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

	public function test_saved_key_and_last_failure_describe_the_key_field() {
		update_option( 'undergrowth_anthropic_api_key', self::ANTHROPIC_KEY );
		update_option(
			'undergrowth_last_prompt_error',
			array(
				'code'   => 'http_status',
				'status' => 529,
				'time'   => time(),
			)
		);

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/id="undergrowth_anthropic_api_key"[^>]*aria-describedby="undergrowth-anthropic-notice undergrowth-anthropic-saved undergrowth-anthropic-error"/', $html );
		$this->assertMatchesRegularExpression( '#id="undergrowth-anthropic-saved">\s*A key ending in \w{4} is saved\. Leave the field blank to keep it\.#', $html );
		$this->assertMatchesRegularExpression( '#id="undergrowth-anthropic-error"><p>[^<]*529#', $html );
	}

	public function test_page_says_why_there_is_no_question_yet() {
		update_option( 'undergrowth_anthropic_api_key', self::ANTHROPIC_KEY );

		$this->assertStringContainsString( 'No question yet. Undergrowth asks one when a reader asks a question in an approved comment, a gap appears on the Map, or you move a post to compost.', $this->render() );
	}

	public function test_page_says_nothing_of_a_question_without_an_anthropic_key() {
		$this->assertStringNotContainsString( 'No question yet', $this->render() );
	}

	public function test_page_stops_saying_there_is_no_question_once_one_is_written() {
		update_option( 'undergrowth_anthropic_api_key', self::ANTHROPIC_KEY );
		$post_id = self::factory()->post->create();
		update_option(
			'undergrowth_prompt',
			array(
				'key'      => 'comment:1',
				'source'   => 'comment',
				'post_id'  => $post_id,
				'question' => 'What made you stop there?',
				'time'     => time(),
			),
			false
		);

		$this->assertStringNotContainsString( 'No question yet', $this->render() );
	}

	public function test_page_stops_saying_there_is_no_question_once_one_was_dismissed() {
		update_option( 'undergrowth_anthropic_api_key', self::ANTHROPIC_KEY );
		$post_id = self::factory()->post->create();
		undergrowth_log_prompt(
			$post_id,
			'dismissed',
			array(
				'source'   => 'comment',
				'question' => 'What made you stop there?',
			)
		);

		$this->assertStringNotContainsString( 'No question yet', $this->render() );
	}

	public function test_page_stops_saying_there_is_no_question_after_the_post_it_was_about_is_deleted() {
		update_option( 'undergrowth_anthropic_api_key', self::ANTHROPIC_KEY );
		$post_id = self::factory()->post->create();
		undergrowth_log_prompt(
			$post_id,
			'asked',
			array(
				'source'   => 'comment',
				'question' => 'What made you stop there?',
			)
		);
		wp_delete_post( $post_id, true );

		$this->assertSame( array(), get_post_meta( $post_id, 'undergrowth_prompt_log', false ) );
		$this->assertStringNotContainsString( 'No question yet', $this->render() );
	}

	public function test_page_stops_saying_there_is_no_question_while_one_is_snoozed() {
		update_option( 'undergrowth_anthropic_api_key', self::ANTHROPIC_KEY );
		update_option( 'undergrowth_snoozed_prompts', array( array( 'key' => 'comment:1' ) ), false );

		$this->assertStringNotContainsString( 'No question yet', $this->render() );
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

	public function test_page_says_when_no_post_has_been_read_for_a_day() {
		update_option( 'undergrowth_openai_api_key', self::KEY );
		self::factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );
		$this->set_progress( 0, 2, 25 * HOUR_IN_SECONDS );

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<div class="notice notice-warning inline" id="undergrowth-backfill-stalled"><p>No post has been read for a day[^<]*WP-Cron[^<]*DISABLE_WP_CRON[^<]*<\/p><\/div>/', $html );
	}

	public function test_page_says_nothing_of_a_stall_once_every_post_is_read() {
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
		$this->set_progress( 1, 1, 25 * HOUR_IN_SECONDS );

		$this->assertStringNotContainsString( 'undergrowth-backfill-stalled', $this->render() );
	}

	public function test_page_says_nothing_of_a_stall_within_a_day() {
		update_option( 'undergrowth_openai_api_key', self::KEY );
		self::factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );
		$this->set_progress( 0, 2, 23 * HOUR_IN_SECONDS );

		$this->assertStringNotContainsString( 'undergrowth-backfill-stalled', $this->render() );
	}

	/**
	 * Records the backfill's progress as a run that long ago would have.
	 *
	 * @param int $indexed Posts read then.
	 * @param int $total   Posts that could be read then.
	 * @param int $ago     Seconds since.
	 */
	private function set_progress( int $indexed, int $total, int $ago ): void {
		update_option(
			'undergrowth_backfill_progress',
			array(
				'time'    => time() - $ago,
				'indexed' => $indexed,
				'total'   => $total,
			),
			false
		);
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

		// Discord's intro keeps the id its fields once pointed at.
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
			$this->assertStringContainsString( 'Nothing is sent while there is no key.', $notice[1] );
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

	public function test_the_suggestions_switch_is_in_the_link_suggestions_section() {
		update_option( 'undergrowth_openai_api_key', self::KEY );

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<h2[^>]*>Link suggestions</h2>.*<input type="checkbox" id="undergrowth_suggest_links" name="undergrowth_suggest_links" value="1" checked=\'checked\' />.*<h2[^>]*>Questions on your Dashboard</h2>#s', $html );
		$this->assertStringContainsString( 'Suggest links between my posts', $html );
	}

	public function test_the_switch_is_left_out_until_there_is_a_provider() {
		$this->assertStringNotContainsString( 'name="undergrowth_suggest_links"', $this->render() );
	}

	public function test_the_switch_setting_saves_on_or_off() {
		undergrowth_register_settings();

		$setting = get_registered_settings()['undergrowth_suggest_links'];
		$this->assertFalse( $setting['show_in_rest'] );

		$_POST['undergrowth_suggest_links_shown'] = '1';
		$this->assertSame( '0', undergrowth_sanitize_suggest_links( null ) );
		$this->assertSame( '1', undergrowth_sanitize_suggest_links( '1' ) );
		unset( $_POST['undergrowth_suggest_links_shown'] );
	}

	public function test_post_types_row_lists_each_public_post_type_with_posts_ticked() {
		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<th scope="row">Post types</th>#', $html );
		$this->assertMatchesRegularExpression( '#<legend class="screen-reader-text">Post types</legend>#', $html );
		$this->assertMatchesRegularExpression( '#<input type="checkbox" id="undergrowth_post_type_post" name="undergrowth_post_types\[\]" value="post" checked=\'checked\' />\s*Posts#', $html );
		$this->assertMatchesRegularExpression( '#<input type="checkbox" id="undergrowth_post_type_page" name="undergrowth_post_types\[\]" value="page" />\s*Pages#', $html );
		$this->assertStringNotContainsString( 'value="attachment"', $html );
	}

	public function test_post_types_setting_is_registered_and_hidden_from_rest() {
		undergrowth_register_settings();

		$setting = get_registered_settings()['undergrowth_post_types'];
		$this->assertSame( 'undergrowth_sanitize_post_types', $setting['sanitize_callback'] );
		$this->assertFalse( $setting['show_in_rest'] );
		$this->assertSame( array( 'post' ), $setting['default'] );
	}

	public function test_saving_post_types_keeps_only_public_ones() {
		$_POST['undergrowth_post_types_shown'] = '1';

		$this->assertSame( array( 'page' ), undergrowth_sanitize_post_types( array( 'page', 'attachment', 'no-such-type', 3 ) ) );

		unset( $_POST['undergrowth_post_types_shown'] );
	}

	public function test_saving_no_post_type_keeps_the_ones_before_and_says_why() {
		update_option( 'undergrowth_post_types', array( 'post', 'page' ) );
		$_POST['undergrowth_post_types_shown'] = '1';

		$this->assertSame( array( 'post', 'page' ), undergrowth_sanitize_post_types( null ) );

		unset( $_POST['undergrowth_post_types_shown'] );
		$errors = get_settings_errors( 'undergrowth_post_types' );
		$this->assertCount( 1, $errors );
		$this->assertSame( 'Choose at least one post type for Undergrowth to read. The ones chosen before are kept.', $errors[0]['message'] );
	}

	public function test_saving_settings_without_the_post_types_on_the_page_keeps_them() {
		update_option( 'undergrowth_post_types', array( 'page' ) );

		$this->assertSame( array( 'page' ), undergrowth_sanitize_post_types( null ) );
	}

	public function test_post_types_row_says_code_chooses_them_while_the_filter_is_used() {
		add_filter(
			'undergrowth_post_types',
			static function () {
				return array( 'page' );
			}
		);

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#value="page" checked=\'checked\' disabled=\'disabled\' />#', $html );
		$this->assertMatchesRegularExpression( '#value="post" disabled=\'disabled\' />#', $html );
		$this->assertStringContainsString( 'Code on this site chooses which post types are read, so these boxes cannot be changed here.', $html );
		$this->assertStringNotContainsString( 'name="undergrowth_post_types_shown"', $html );
	}

	public function test_page_has_no_accept_row_while_no_save_has_failed() {
		$this->assertStringNotContainsString( 'undergrowth-accept-error', $this->render() );
	}

	public function test_accept_row_says_when_a_save_failed_and_what_wordpress_reported() {
		update_option(
			'undergrowth_last_accept_error',
			array(
				'code'   => 'empty_content',
				'status' => 0,
				'time'   => 1767225600,
			)
		);

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<th scope="row">Accept</th>#', $html );
		$this->assertStringContainsString( 'id="undergrowth-accept-error"', $html );
		$this->assertStringContainsString( 'WordPress treated the post as empty and refused to save it, so the link was not added.', $html );
		$this->assertStringContainsString( undergrowth_date_time( 1767225600 ), $html );
	}

	public function test_accept_row_names_an_error_code_it_has_no_sentence_for() {
		update_option(
			'undergrowth_last_accept_error',
			array(
				'code'   => 'some_plugin_refused',
				'status' => 0,
				'time'   => time(),
			)
		);

		$this->assertStringContainsString( 'WordPress did not save the post, so the link was not added. It reported the error some_plugin_refused.', undergrowth_accept_failure_text( get_option( 'undergrowth_last_accept_error' ) ) );
	}

	public function test_saving_settings_without_the_switch_on_the_page_keeps_it() {
		update_option( 'undergrowth_suggest_links', '0' );

		$this->assertSame( '0', undergrowth_sanitize_suggest_links( null ) );

		update_option( 'undergrowth_suggest_links', '1' );

		$this->assertSame( '1', undergrowth_sanitize_suggest_links( null ) );
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
