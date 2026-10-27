<?php
/**
 * Tests for the Prompt studio, the Dashboard pointer to it, and the prompt
 * log in each post's file.
 *
 * @package Undergrowth
 */

/**
 * Questions to browse, dismiss, replace and save.
 */
class Test_Prompt_Studio extends WP_UnitTestCase {

	use Plants_Posts;
	use Stops_Redirects;

	const QUESTION = 'What do you actually do with oca over winter?';

	/**
	 * Requests sent to the Messages API.
	 *
	 * @var int
	 */
	private $requests = 0;

	/**
	 * HTTP status the fake Messages API answers with.
	 *
	 * @var int
	 */
	private $status = 200;

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

		// Files hold the questions only once visitors are known to be refused.
		update_option( 'undergrowth_memory_exposed', array( 'exposed' => false ), false );

		$this->requests = 0;
		$this->start_planting();
		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author );
		update_option( 'undergrowth_anthropic_api_key', 'sk-ant-test-0123456789abcd' );
		undergrowth_unschedule_prompts();
		delete_option( 'undergrowth_prompts' );
		$this->status = 200;
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
		unset( $_POST['key'], $_POST['undergrowth_choice'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['undergrowth_notice'], $_GET['undergrowth_prompt'], $_GET['view'] );
		undergrowth_unschedule_prompts();
		undergrowth_delete_memory_folder();
		unset( $GLOBALS['wp_meta_boxes'] );

		parent::tear_down();
	}

	/**
	 * Answers every Messages API request with a question, numbered after the
	 * first so each one can be told apart.
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
							'text' => 1 === $this->requests ? self::QUESTION : 'What else do you do with oca, number ' . $this->requests . '?',
						),
					),
					'stop_reason' => 'end_turn',
				)
			),
			'response' => array(
				'code'    => $this->status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
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
		undergrowth_add_dashboard_widget();

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
	 * Renders the Prompt studio and returns its HTML.
	 *
	 * @param string $view '' or 'saved'.
	 * @return string
	 */
	private function studio( string $view = '' ): string {
		$_GET['view'] = $view;
		ob_start();
		undergrowth_render_studio_page();

		return (string) ob_get_clean();
	}

	/**
	 * Stores one question about the test post.
	 *
	 * @param array<string, mixed> $fields Fields to change.
	 */
	private function store( array $fields = array() ): void {
		update_option(
			'undergrowth_prompts',
			array(
				array_merge(
					array(
						'key'      => 'comment:1',
						'source'   => 'comment',
						'post_id'  => $this->post_id,
						'question' => self::QUESTION,
						'time'     => time(),
						'status'   => 'new',
					),
					$fields
				),
			)
		);
	}

	/**
	 * Fills a question's form in the studio and calls its handler.
	 *
	 * @param string $choice 'dismiss', 'save', 'replace' or 'remove'.
	 * @param string $key    Prompt key, or null for the current one.
	 * @return string Redirect location.
	 * @throws WPDieException When the handler refuses the request.
	 */
	private function choose( string $choice, ?string $key = null ): string {
		$key                         = $key ?? undergrowth_current_prompt()['key'];
		$_POST['key']                = $key;
		$_POST['undergrowth_choice'] = $choice;
		$nonce                       = wp_create_nonce( 'undergrowth_studio_' . $key );
		$_POST['_wpnonce']           = $nonce;
		$_REQUEST['_wpnonce']        = $nonce;

		try {
			undergrowth_handle_studio();
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
		$this->assertNotContains( 'undergrowth', $this->widgets() );
	}

	public function test_widget_points_the_posts_author_to_the_studio() {
		undergrowth_write_prompt();

		$html = $this->render();

		$this->assertContains( 'undergrowth', $this->widgets() );
		$this->assertStringContainsString( '1 new question from your own posts is in the Prompt studio.', $html );
		$this->assertStringContainsString( 'href="' . esc_url( undergrowth_studio_url() ) . '"', $html );
		$this->assertStringNotContainsString( self::QUESTION, $html );
	}

	public function test_widget_prints_nothing_without_a_new_question() {
		$this->assertSame( '', $this->render() );
	}

	public function test_the_first_prompt_event_tops_up_the_batch() {
		undergrowth_write_prompt();
		$this->comment( $this->post_id, 'When do you lift them?' );

		do_action( 'undergrowth_write_prompt' );

		$this->assertSame( 2, $this->requests );
		$this->assertCount( 2, undergrowth_prompts( 'new' ) );
	}

	public function test_widget_counts_every_new_question() {
		$this->comment( $this->post_id, 'When do you lift them?' );
		undergrowth_write_prompt();
		undergrowth_write_prompt();

		$this->assertStringContainsString( '2 new questions from your own posts are in the Prompt studio.', $this->render() );
	}

	public function test_widget_leaves_out_saved_questions() {
		undergrowth_write_prompt();
		undergrowth_save_prompt( undergrowth_current_prompt()['key'] );

		$this->assertNotContains( 'undergrowth', $this->widgets() );
		$this->assertSame( '', $this->render() );
	}

	public function test_widget_is_hidden_from_users_who_cannot_edit_the_post() {
		undergrowth_write_prompt();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertNotContains( 'undergrowth', $this->widgets() );
		$this->assertSame( '', $this->render() );
	}

	public function test_no_widget_once_the_key_is_removed() {
		undergrowth_write_prompt();
		update_option( 'undergrowth_anthropic_api_key', '' );

		$this->assertNull( undergrowth_current_prompt() );
		$this->assertNotContains( 'undergrowth', $this->widgets() );
	}

	public function test_widget_never_counts_days_or_streaks() {
		undergrowth_write_prompt();

		$this->assertDoesNotMatchRegularExpression( '/streak|days?\b|since|haven|yet/i', wp_strip_all_tags( $this->render() ) );
	}

	public function test_studio_is_under_posts_for_anyone_who_can_write_posts() {
		global $submenu;

		$submenu = array();
		wp_set_current_user( $this->author );
		undergrowth_add_studio_page();

		$this->assertSame( array( 'Prompt studio', 'edit_posts', 'undergrowth-studio' ), array_slice( $submenu['edit.php'][0], 0, 3 ) );
	}

	public function test_studio_is_off_without_a_key() {
		update_option( 'undergrowth_anthropic_api_key', '' );

		$html = $this->studio();

		$this->assertStringContainsString( 'off until an Anthropic API key is saved', $html );
		$this->assertStringNotContainsString( 'Open Settings', $html );
		$this->assertStringNotContainsString( 'nav-tab', $html );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertStringContainsString( 'href="' . esc_url( undergrowth_settings_url() ) . '"', $this->studio() );
	}

	public function test_studio_lists_a_new_question_with_its_post_and_three_choices() {
		undergrowth_write_prompt();

		$html = $this->studio();
		$key  = undergrowth_current_prompt()['key'];

		$this->assertStringContainsString( self::QUESTION, $html );
		$this->assertStringContainsString( 'New questions (1)', $html );
		$this->assertStringContainsString( 'Idea bank (0)', $html );
		$this->assertMatchesRegularExpression( '/class="nav-tab nav-tab-active" aria-current="page">New questions/', $html );
		// Older versions of wp_kses() write the escaped & differently.
		$this->assertMatchesRegularExpression( '/href="[^"]*post\.php\?post=' . $this->post_id . '(&amp;|&#038;)action=edit"/', $html );
		$this->assertStringContainsString( '<bdi>Growing oca</bdi></a>', $html );
		$this->assertStringContainsString( 'reader', $html );
		$this->assertStringContainsString( 'Only people who can edit those posts see it', $html );
		$this->assertStringContainsString( 'using this site’s Anthropic API key', $html );

		foreach ( array( 'save', 'replace', 'dismiss' ) as $choice ) {
			$this->assertMatchesRegularExpression( '/<button type="submit" class="button" name="undergrowth_choice" value="' . $choice . '" aria-describedby="undergrowth-question-[0-9a-f]{12}">/', $html );
		}

		$this->assertStringNotContainsString( 'value="remove"', $html );
		$this->assertStringContainsString( 'value="' . $key . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
	}

	public function test_studio_says_when_no_new_question_waits() {
		$this->assertStringContainsString( 'No new questions right now.', $this->studio() );
	}

	public function test_idea_bank_lists_saved_questions_with_their_posts() {
		$other = $this->plant( 'Yacon', array( 0.0, 1.0 ), array( 'post_author' => $this->author ) );
		$this->store(
			array(
				'source' => 'gap',
				'posts'  => array( $this->post_id, $other ),
				'status' => 'saved',
			)
		);

		$html = $this->studio( 'saved' );

		$this->assertStringContainsString( self::QUESTION, $html );
		$this->assertStringContainsString( 'Idea bank (1)', $html );
		$this->assertMatchesRegularExpression( '/aria-current="page">Idea bank/', $html );
		// Each title is quoted, so a title holding a comma never runs into the next.
		$this->assertStringContainsString( 'From a gap among your posts: “<a href=', $html );
		$this->assertStringContainsString( '<bdi>Growing oca</bdi></a>” and “<a ', $html );
		$this->assertStringContainsString( '<bdi>Yacon</bdi></a>”.', $html );
		$this->assertStringContainsString( 'value="remove"', $html );
		$this->assertStringNotContainsString( 'value="dismiss"', $html );
		$this->assertStringNotContainsString( self::QUESTION, $this->studio() );
	}

	public function test_idea_bank_leaves_out_posts_no_longer_read() {
		$other = $this->plant( 'Yacon', array( 0.0, 1.0 ), array( 'post_author' => $this->author ) );
		$this->store(
			array(
				'source' => 'gap',
				'posts'  => array( $this->post_id, $other ),
				'status' => 'saved',
			)
		);
		wp_update_post(
			array(
				'ID'          => $other,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( array( $this->post_id ), undergrowth_prompts( 'saved' )[0]['posts'] );
		$this->assertStringNotContainsString( 'Yacon', $this->studio( 'saved' ) );
	}

	public function test_idea_bank_says_when_it_is_empty() {
		$this->assertStringContainsString( 'Your idea bank is empty.', $this->studio( 'saved' ) );
	}

	public function test_studio_hides_questions_about_posts_the_user_cannot_edit() {
		undergrowth_write_prompt();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$html = $this->studio();

		$this->assertStringNotContainsString( self::QUESTION, $html );
		$this->assertStringContainsString( 'New questions (0)', $html );
	}

	public function test_a_question_drawing_on_a_post_the_user_cannot_edit_is_hidden_and_refused() {
		$other = $this->plant( 'Yacon', array( 0.0, 1.0 ), array( 'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ) ) );
		$this->store(
			array(
				'key'    => 'gap:1',
				'source' => 'gap',
				'posts'  => array( $this->post_id, $other ),
			)
		);

		$this->assertStringNotContainsString( self::QUESTION, $this->studio() );
		$this->assertSame( '', $this->render() );

		try {
			$this->choose( 'dismiss', 'gap:1' );
			$this->fail( 'The choice was not refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'new', undergrowth_find_prompt( 'gap:1' )['status'] );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertStringContainsString( self::QUESTION, $this->studio() );
	}

	public function test_studio_names_an_untitled_post() {
		wp_update_post(
			array(
				'ID'         => $this->post_id,
				'post_title' => '',
			)
		);
		$this->store();

		$this->assertMatchesRegularExpression( '#<a href="[^"]*post=' . $this->post_id . '[^"]*"><bdi>\(no title\)</bdi></a>#', $this->studio() );
	}

	public function test_question_is_escaped() {
		$this->store( array( 'question' => 'Why <script>x</script>?' ) );

		$html = $this->studio();

		$this->assertStringNotContainsString( '<script>x', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_studio_names_a_source_it_has_no_wording_for_as_your_posts() {
		$this->store(
			array(
				'key'    => 'other:1',
				'source' => 'other',
			)
		);

		$this->assertStringContainsString( 'From your posts: “<a ', $this->studio() );
	}

	public function test_dismiss_removes_the_question_and_says_so() {
		undergrowth_write_prompt();
		$key = undergrowth_current_prompt()['key'];

		$location = $this->choose( 'dismiss' );

		$this->assertStringContainsString( 'page=undergrowth-studio', $location );
		$this->assertStringContainsString( 'undergrowth_notice=prompt_dismissed', $location );
		$this->assertStringContainsString( 'undergrowth_prompt=' . rawurlencode( $key ), $location );
		$this->assertNull( undergrowth_current_prompt() );
		$this->assertSame( array( 'asked', 'dismissed' ), $this->events( $this->post_id ) );
	}

	public function test_dismissed_prompt_never_comes_back() {
		undergrowth_write_prompt();
		$this->choose( 'dismiss' );

		do_action( 'undergrowth_prompt_batch' );
		do_action( 'undergrowth_prompt_batch' );

		$this->assertNull( undergrowth_current_prompt() );
		$this->assertSame( 1, $this->requests );
	}

	public function test_save_moves_the_question_to_the_idea_bank() {
		undergrowth_write_prompt();
		$key = undergrowth_current_prompt()['key'];

		$this->assertStringContainsString( 'undergrowth_notice=prompt_saved', $this->choose( 'save' ) );
		$this->assertNull( undergrowth_current_prompt() );
		$this->assertSame( array( $key ), array_column( undergrowth_prompts( 'saved' ), 'key' ) );
		$this->assertSame( array( 'asked', 'saved' ), $this->events( $this->post_id ) );
	}

	public function test_saved_question_stays_through_every_cycle() {
		undergrowth_write_prompt();
		$this->choose( 'save' );

		do_action( 'undergrowth_prompt_batch' );
		do_action( 'undergrowth_prompt_batch' );

		$this->assertCount( 1, undergrowth_prompts( 'saved' ) );
		$this->assertSame( 1, $this->requests );
	}

	public function test_saved_question_can_be_removed_for_good() {
		undergrowth_write_prompt();
		$key = undergrowth_current_prompt()['key'];
		$this->choose( 'save' );

		$location = $this->choose( 'remove', $key );

		$this->assertStringContainsString( 'view=saved', $location );
		$this->assertStringContainsString( 'undergrowth_notice=prompt_removed', $location );
		$this->assertSame( array(), undergrowth_stored_prompts() );
		$this->assertSame( array( 'asked', 'saved', 'removed' ), $this->events( $this->post_id ) );

		do_action( 'undergrowth_prompt_batch' );
		$this->assertSame( 1, $this->requests );
	}

	public function test_a_saved_question_cannot_be_dismissed_and_a_new_one_cannot_be_removed() {
		undergrowth_write_prompt();
		$key = undergrowth_current_prompt()['key'];

		$this->assertStringContainsString( 'prompt_gone', $this->choose( 'remove', $key ) );
		$this->choose( 'save', $key );
		$this->assertStringContainsString( 'prompt_gone', $this->choose( 'dismiss', $key ) );
		$this->assertStringContainsString( 'prompt_gone', $this->choose( 'replace', $key ) );
		$this->assertCount( 1, undergrowth_prompts( 'saved' ) );
		$this->assertSame( 1, $this->requests );
	}

	public function test_replace_swaps_the_question_for_one_from_another_source() {
		undergrowth_write_prompt();
		$old = undergrowth_current_prompt()['key'];
		$this->comment( $this->post_id, 'When do you lift them?' );

		$this->assertStringContainsString( 'undergrowth_notice=prompt_replaced', $this->choose( 'replace', $old ) );

		$new = undergrowth_prompts( 'new' );

		$this->assertCount( 1, $new );
		$this->assertNotSame( $old, $new[0]['key'] );
		$this->assertStringContainsString( 'number 2', $new[0]['question'] );
		$this->assertSame( 'dismissed', undergrowth_find_prompt( $old )['status'] );
		$this->assertSame( array( 'asked', 'asked', 'replaced' ), $this->events( $this->post_id ) );
		$this->assertSame( 2, $this->requests );
	}

	public function test_replace_uses_only_a_source_the_author_can_edit() {
		undergrowth_write_prompt();
		$waiting = undergrowth_current_prompt();
		$other   = $this->plant(
			'Yacon',
			array( 0.0, 1.0 ),
			array(
				'post_author'  => self::factory()->user->create( array( 'role' => 'author' ) ),
				'post_content' => '<!-- wp:paragraph --><p>Yacon needs a long season.</p><!-- /wp:paragraph -->',
			)
		);
		$this->comment( $other, 'How long is the season?' );

		$this->assertStringContainsString( 'undergrowth_notice=prompt_not_replaced', $this->choose( 'replace' ) );
		$this->assertSame( array( $waiting ), undergrowth_prompts( 'new' ) );
		$this->assertSame( 1, $this->requests );
	}

	public function test_replace_keeps_the_question_when_no_other_source_is_left() {
		undergrowth_write_prompt();
		$waiting = undergrowth_current_prompt();

		$this->assertStringContainsString( 'undergrowth_notice=prompt_not_replaced', $this->choose( 'replace' ) );
		$this->assertSame( $waiting, undergrowth_current_prompt() );
		$this->assertSame( array( 'asked' ), $this->events( $this->post_id ) );
	}

	public function test_replace_keeps_the_question_when_the_request_fails() {
		undergrowth_write_prompt();
		$waiting = undergrowth_current_prompt();
		$this->comment( $this->post_id, 'When do you lift them?' );
		$this->status = 500;

		$this->assertStringContainsString( 'undergrowth_notice=prompt_not_replaced', $this->choose( 'replace' ) );
		$this->assertSame( array( $waiting ), undergrowth_prompts( 'new' ) );
	}

	public function test_choice_for_a_question_no_longer_here_changes_nothing() {
		undergrowth_write_prompt();
		$waiting = undergrowth_current_prompt();

		$this->assertStringContainsString( 'undergrowth_notice=prompt_gone', $this->choose( 'dismiss', 'comment:999999' ) );
		$this->assertStringContainsString( 'undergrowth_notice=prompt_gone', $this->choose( 'replace', 'comment:999999' ) );
		$this->assertSame( $waiting, undergrowth_current_prompt() );
	}

	public function test_unknown_choice_changes_nothing() {
		undergrowth_write_prompt();
		$waiting = undergrowth_current_prompt();

		$this->assertSame( undergrowth_studio_url(), $this->choose( 'later' ) );
		$this->assertSame( $waiting, undergrowth_current_prompt() );
	}

	public function test_choice_without_a_valid_nonce_is_refused() {
		undergrowth_write_prompt();
		$_POST['key']                = undergrowth_current_prompt()['key'];
		$_POST['undergrowth_choice'] = 'dismiss';
		$_POST['_wpnonce']           = 'bad';
		$_REQUEST['_wpnonce']        = 'bad';

		$this->expectException( WPDieException::class );
		undergrowth_handle_studio();
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

	public function test_notice_takes_focus() {
		$_GET['undergrowth_notice'] = 'prompt_saved';

		ob_start();
		undergrowth_render_studio_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="undergrowth-notice"', $html );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( 'class="notice notice-success is-dismissible"', $html );
		$this->assertStringContainsString( 'idea bank', $html );
		$this->assertStringContainsString( '.focus()', $html );
	}

	public function test_notice_when_no_question_could_replace_it_is_a_warning() {
		$_GET['undergrowth_notice'] = 'prompt_not_replaced';

		ob_start();
		undergrowth_render_studio_notice();

		$this->assertStringContainsString( 'class="notice notice-warning is-dismissible"', (string) ob_get_clean() );
	}

	public function test_unknown_notice_prints_nothing() {
		$_GET['undergrowth_notice'] = 'praise';

		ob_start();
		undergrowth_render_studio_notice();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_studio_shows_its_notice_under_the_tabs() {
		$_GET['undergrowth_notice'] = 'prompt_saved';

		$this->assertMatchesRegularExpression( '#</nav>\s*<div id="undergrowth-notice"#', $this->studio() );
	}

	public function test_core_removes_the_question_named_by_a_result() {
		$this->assertContains( 'undergrowth_prompt', wp_removable_query_args() );
	}

	public function test_studio_has_a_help_tab() {
		set_current_screen( 'posts_page_undergrowth-studio' );

		undergrowth_add_studio_help();

		$this->assertNotNull( get_current_screen()->get_help_tab( 'undergrowth-studio' ) );
		set_current_screen( 'front' );
	}

	public function test_file_lists_each_prompt_event_with_its_date_and_question() {
		undergrowth_write_prompt();
		$this->choose( 'save' );

		$file = (string) file_get_contents( undergrowth_memory_path( $this->post_id ) );
		$day  = wp_date( 'Y-m-d', null, new DateTimeZone( 'UTC' ) );

		$this->assertStringContainsString( "## prompts\n- {$day}: asked (comment) \"" . self::QUESTION . "\"\n- {$day}: saved (comment) \"" . self::QUESTION . '"', $file );
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
