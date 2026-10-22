<?php
/**
 * Tests for the Dashboard prompt widget, dismiss and snooze, and the prompt
 * log in each post's file.
 *
 * @package Undergrowth
 */

/**
 * One prompt at a time on the Dashboard.
 */
class Test_Prompt_Panel extends WP_UnitTestCase {

	use Plants_Posts;

	const QUESTION = 'What do you actually do with oca over winter?';

	/**
	 * Requests sent to the Messages API.
	 *
	 * @var int
	 */
	private $requests = 0;

	/**
	 * Author of the test posts.
	 *
	 * @var int
	 */
	private $author;

	/**
	 * Post the prompt is about.
	 *
	 * @var int
	 */
	private $post_id;

	public function set_up() {
		parent::set_up();

		$this->requests = 0;
		$this->start_planting();
		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author );
		update_option( 'undergrowth_anthropic_api_key', 'sk-ant-test-0123456789abcd' );
		undergrowth_unschedule_prompts();
		delete_option( 'undergrowth_prompt' );
		delete_option( 'undergrowth_snoozed_prompts' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ) );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );

		$this->post_id = $this->plant(
			'Growing oca',
			array( 1.0, 0.0 ),
			array(
				'post_author'  => $this->author,
				'post_content' => '<!-- wp:paragraph --><p>I lift the tubers after the first frost.</p><!-- /wp:paragraph -->',
			)
		);
		$this->comment( $this->post_id, 'How do you store the tubers over winter?' );
	}

	public function tear_down() {
		unset( $_POST['key'], $_POST['undergrowth_choice'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['undergrowth_notice'] );
		undergrowth_unschedule_prompts();
		undergrowth_delete_memory_folder();
		unset( $GLOBALS['wp_meta_boxes'] );

		parent::tear_down();
	}

	/**
	 * Answers every Messages API request with the same question.
	 *
	 * @return array<string, mixed>
	 */
	public function fake_http() {
		++$this->requests;

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode(
				array(
					'content'     => array(
						array(
							'type' => 'text',
							'text' => self::QUESTION,
						),
					),
					'stop_reason' => 'end_turn',
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Stops a redirect so the handler's exit never runs.
	 *
	 * @param string $location Redirect target.
	 * @throws Exception Always, carrying the location.
	 */
	public function stop_redirect( $location ) {
		throw new Exception( esc_url_raw( $location ) );
	}

	/**
	 * Adds an approved comment from a reader.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Comment text.
	 * @return int Comment ID.
	 */
	private function comment( int $post_id, string $content ): int {
		return self::factory()->comment->create(
			array(
				'comment_post_ID'      => $post_id,
				'comment_content'      => $content,
				'comment_approved'     => '1',
				'comment_author_email' => 'reader@example.com',
			)
		);
	}

	/**
	 * Registers the Dashboard widgets and returns the IDs added.
	 *
	 * @return string[]
	 */
	private function widgets(): array {
		global $wp_meta_boxes;

		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		set_current_screen( 'dashboard' );
		$wp_meta_boxes = array();
		undergrowth_add_prompt_widget();

		$ids = array();

		foreach ( (array) ( $wp_meta_boxes['dashboard'] ?? array() ) as $contexts ) {
			foreach ( (array) $contexts as $boxes ) {
				$ids = array_merge( $ids, array_keys( (array) $boxes ) );
			}
		}

		return $ids;
	}

	/**
	 * Renders the widget and returns its HTML.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		undergrowth_render_prompt_widget();

		return (string) ob_get_clean();
	}

	/**
	 * Fills the widget's form and calls its handler.
	 *
	 * @param string $choice 'dismiss' or 'snooze'.
	 * @param string $key    Prompt key, or null for the current one.
	 * @return string Redirect location.
	 * @throws WPDieException When the handler refuses the request.
	 */
	private function choose( string $choice, ?string $key = null ): string {
		$key                         = $key ?? undergrowth_current_prompt()['key'];
		$_POST['key']                = $key;
		$_POST['undergrowth_choice'] = $choice;
		$nonce                       = wp_create_nonce( 'undergrowth_prompt_' . $key );
		$_POST['_wpnonce']           = $nonce;
		$_REQUEST['_wpnonce']        = $nonce;

		try {
			undergrowth_handle_prompt();
		} catch ( WPDieException $e ) {
			throw $e;
		} catch ( Exception $e ) {
			return $e->getMessage();
		}

		return '';
	}

	/**
	 * Returns the events in a post's prompt log, oldest first.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function events( int $post_id ): array {
		return array_column( undergrowth_prompt_log( $post_id ), 'event' );
	}

	public function test_no_widget_without_a_prompt() {
		$this->assertNotContains( 'undergrowth_prompt', $this->widgets() );
	}

	public function test_widget_shows_for_the_posts_author() {
		undergrowth_write_prompt();

		$this->assertContains( 'undergrowth_prompt', $this->widgets() );
	}

	public function test_widget_is_hidden_from_users_who_cannot_edit_the_post() {
		undergrowth_write_prompt();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertNotContains( 'undergrowth_prompt', $this->widgets() );
		$this->assertSame( '', $this->render() );
	}

	public function test_no_widget_once_the_key_is_removed() {
		undergrowth_write_prompt();
		update_option( 'undergrowth_anthropic_api_key', '' );

		$this->assertNull( undergrowth_current_prompt() );
		$this->assertNotContains( 'undergrowth_prompt', $this->widgets() );
	}

	public function test_widget_shows_the_question_the_post_and_both_choices() {
		undergrowth_write_prompt();

		$html = $this->render();

		$this->assertStringContainsString( self::QUESTION, $html );
		// Older versions of wp_kses() write the escaped & differently.
		$this->assertMatchesRegularExpression( '/href="[^"]*post\.php\?post=' . $this->post_id . '(&amp;|&#038;)action=edit"/', $html );
		$this->assertStringContainsString( 'Growing oca', $html );
		$this->assertStringContainsString( 'reader', $html );
		$this->assertStringContainsString( 'Only you can see it', $html );
		$this->assertMatchesRegularExpression( '/<button type="submit" class="button" name="undergrowth_choice" value="snooze">/', $html );
		$this->assertMatchesRegularExpression( '/<button type="submit" class="button" name="undergrowth_choice" value="dismiss">/', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
	}

	public function test_question_is_escaped() {
		update_option(
			'undergrowth_prompt',
			array(
				'key'      => 'comment:1',
				'source'   => 'comment',
				'post_id'  => $this->post_id,
				'question' => 'Why <script>x</script>?',
				'time'     => time(),
			)
		);

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>x', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_widget_never_counts_days_or_streaks() {
		undergrowth_write_prompt();

		$text = wp_strip_all_tags( $this->render() );

		$this->assertDoesNotMatchRegularExpression( '/\d|streak|days?\b|since/i', str_replace( self::QUESTION, '', $text ) );
	}

	public function test_dismiss_clears_the_prompt_and_says_so() {
		undergrowth_write_prompt();

		$this->assertStringContainsString( 'undergrowth_notice=prompt_dismissed', $this->choose( 'dismiss' ) );
		$this->assertNull( undergrowth_current_prompt() );
		$this->assertSame( array( 'asked', 'dismissed' ), $this->events( $this->post_id ) );
	}

	public function test_dismissed_prompt_never_comes_back() {
		undergrowth_write_prompt();
		$this->choose( 'dismiss' );

		do_action( 'undergrowth_prompt_cycle' );
		do_action( 'undergrowth_prompt_cycle' );

		$this->assertNull( undergrowth_current_prompt() );
		$this->assertSame( 1, $this->requests );
	}

	public function test_snooze_clears_the_prompt_until_the_next_cycle() {
		undergrowth_write_prompt();

		$this->assertStringContainsString( 'undergrowth_notice=prompt_snoozed', $this->choose( 'snooze' ) );
		$this->assertNull( undergrowth_current_prompt() );

		do_action( 'undergrowth_write_prompt' );
		$this->assertNull( undergrowth_current_prompt() );
	}

	public function test_snoozed_prompt_returns_at_the_next_cycle_without_a_new_request() {
		undergrowth_write_prompt();
		$this->choose( 'snooze' );

		do_action( 'undergrowth_prompt_cycle' );

		$prompt = undergrowth_current_prompt();
		$this->assertSame( self::QUESTION, $prompt['question'] );
		$this->assertSame( $this->post_id, $prompt['post_id'] );
		$this->assertSame( 1, $this->requests );
		$this->assertSame( array( 'asked', 'snoozed', 'asked' ), $this->events( $this->post_id ) );
	}

	public function test_snoozed_prompt_comes_back_before_a_new_one() {
		undergrowth_write_prompt();
		$this->choose( 'snooze' );
		$this->comment( $this->post_id, 'When do you lift them?' );

		do_action( 'undergrowth_prompt_cycle' );

		$this->assertSame( 1, $this->requests );
		$this->assertSame( array(), get_option( 'undergrowth_snoozed_prompts', array() ) );
	}

	public function test_prompt_snoozed_then_dismissed_never_comes_back() {
		undergrowth_write_prompt();
		$this->choose( 'snooze' );
		do_action( 'undergrowth_prompt_cycle' );
		$this->choose( 'dismiss' );

		do_action( 'undergrowth_prompt_cycle' );

		$this->assertNull( undergrowth_current_prompt() );
		$this->assertSame( array( 'asked', 'snoozed', 'asked', 'dismissed' ), $this->events( $this->post_id ) );
	}

	public function test_snoozed_prompt_about_a_post_that_left_the_index_is_dropped() {
		undergrowth_write_prompt();
		$this->choose( 'snooze' );
		wp_update_post(
			array(
				'ID'          => $this->post_id,
				'post_status' => 'draft',
			)
		);

		do_action( 'undergrowth_prompt_cycle' );

		$this->assertNull( undergrowth_current_prompt() );
		$this->assertSame( array(), get_option( 'undergrowth_snoozed_prompts', array() ) );
	}

	public function test_cycle_leaves_a_waiting_prompt_alone() {
		undergrowth_write_prompt();
		$this->comment( $this->post_id, 'When do you lift them?' );

		do_action( 'undergrowth_prompt_cycle' );

		$this->assertSame( 1, $this->requests );
		$this->assertSame( array( 'asked' ), $this->events( $this->post_id ) );
	}

	public function test_choice_for_a_prompt_no_longer_waiting_changes_nothing() {
		undergrowth_write_prompt();
		$waiting = undergrowth_current_prompt();

		$this->assertStringContainsString( 'undergrowth_notice=prompt_gone', $this->choose( 'dismiss', 'comment:999999' ) );
		$this->assertSame( $waiting, undergrowth_current_prompt() );
	}

	public function test_unknown_choice_changes_nothing() {
		undergrowth_write_prompt();
		$waiting = undergrowth_current_prompt();

		$this->assertStringNotContainsString( 'undergrowth_notice', $this->choose( 'later' ) );
		$this->assertSame( $waiting, undergrowth_current_prompt() );
	}

	public function test_choice_without_a_valid_nonce_is_refused() {
		undergrowth_write_prompt();
		$_POST['key']                = undergrowth_current_prompt()['key'];
		$_POST['undergrowth_choice'] = 'dismiss';
		$_POST['_wpnonce']           = 'bad';
		$_REQUEST['_wpnonce']        = 'bad';

		$this->expectException( WPDieException::class );
		undergrowth_handle_prompt();
	}

	public function test_choice_from_a_user_who_cannot_edit_the_post_is_refused() {
		undergrowth_write_prompt();
		$key = undergrowth_current_prompt()['key'];
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		try {
			$this->choose( 'dismiss', $key );
			$this->fail( 'The choice was not refused.' );
		} catch ( WPDieException $e ) {
			$this->assertNotNull( undergrowth_current_prompt() );
		}
	}

	public function test_notice_on_the_dashboard_takes_focus() {
		set_current_screen( 'dashboard' );
		$_GET['undergrowth_notice'] = 'prompt_snoozed';

		ob_start();
		undergrowth_render_prompt_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="undergrowth-notice"', $html );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( 'class="notice notice-success is-dismissible"', $html );
		$this->assertStringContainsString( 'next month', $html );
		$this->assertStringContainsString( '.focus()', $html );
	}

	public function test_notice_is_only_shown_on_the_dashboard() {
		set_current_screen( 'edit-post' );
		$_GET['undergrowth_notice'] = 'prompt_snoozed';

		ob_start();
		undergrowth_render_prompt_notice();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_file_lists_each_prompt_event_with_its_date_and_question() {
		undergrowth_write_prompt();
		$this->choose( 'snooze' );

		$file = (string) file_get_contents( undergrowth_memory_path( $this->post_id ) );
		$day  = wp_date( 'Y-m-d', null, new DateTimeZone( 'UTC' ) );

		$this->assertStringContainsString( "## prompts\n- {$day}: asked (comment) \"" . self::QUESTION . "\"\n- {$day}: snoozed (comment) \"" . self::QUESTION . '"', $file );
	}

	public function test_file_never_holds_the_readers_words() {
		undergrowth_write_prompt();

		$this->assertStringNotContainsString( 'How do you store the tubers', (string) file_get_contents( undergrowth_memory_path( $this->post_id ) ) );
	}

	public function test_log_with_unreadable_rows_skips_them() {
		add_post_meta( $this->post_id, 'undergrowth_prompt_log', 'not a row' );
		add_post_meta( $this->post_id, 'undergrowth_prompt_log', array( 'event' => 'praised' ) );

		$this->assertSame( array(), undergrowth_prompt_log( $this->post_id ) );
	}

	public function test_prompt_log_is_protected_meta() {
		$this->assertTrue( is_protected_meta( 'undergrowth_prompt_log', 'post' ) );
	}

	public function test_widget_names_a_source_it_has_no_wording_for_as_your_post() {
		update_option(
			'undergrowth_prompt',
			array(
				'key'      => 'other:1',
				'source'   => 'other',
				'post_id'  => $this->post_id,
				'question' => self::QUESTION,
				'time'     => time(),
			)
		);

		$this->assertStringContainsString( 'from your post', $this->render() );
	}

	public function test_snoozing_a_prompt_that_is_not_waiting_changes_nothing() {
		undergrowth_write_prompt();

		$this->assertFalse( undergrowth_snooze_prompt( 'comment:999999' ) );
		$this->assertSame( array(), get_option( 'undergrowth_snoozed_prompts', array() ) );
	}

	public function test_unreadable_snoozed_entry_is_skipped() {
		undergrowth_write_prompt();
		$this->choose( 'snooze' );
		update_option( 'undergrowth_snoozed_prompts', array_merge( array( 'not a prompt' ), get_option( 'undergrowth_snoozed_prompts' ) ) );

		do_action( 'undergrowth_prompt_cycle' );

		$this->assertSame( self::QUESTION, undergrowth_current_prompt()['question'] );
	}

	public function test_unknown_notice_prints_nothing() {
		set_current_screen( 'dashboard' );
		$_GET['undergrowth_notice'] = 'praise';

		ob_start();
		undergrowth_render_prompt_notice();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_post_without_a_vector_gets_a_file_for_its_prompts() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Unread oca',
				'post_status'  => 'publish',
				'post_author'  => $this->author,
				'post_content' => 'I lift the tubers after the first frost.',
			)
		);
		wp_delete_comment(
			(int) get_comments(
				array(
					'post_id' => $this->post_id,
					'fields'  => 'ids',
				)
			)[0],
			true
		);
		$this->comment( $post_id, 'How do you store the tubers?' );

		undergrowth_write_prompt();

		$this->assertNull( undergrowth_get_vector( $post_id ) );
		$this->assertStringContainsString( "## prompts\n- ", (string) file_get_contents( undergrowth_memory_path( $post_id ) ) );
	}

	public function test_post_without_a_vector_or_prompts_gets_no_file() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		undergrowth_write_memory( $post_id );

		$this->assertFileDoesNotExist( undergrowth_memory_path( $post_id ) );
	}
}
