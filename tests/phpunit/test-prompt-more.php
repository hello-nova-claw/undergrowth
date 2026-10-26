<?php
/**
 * Tests for More questions in the Prompt studio.
 *
 * @package Undergrowth
 */

/**
 * More on demand, only on a click that says what it sends and costs.
 */
class Test_Prompt_More extends WP_UnitTestCase {

	use Stops_Redirects;

	/**
	 * Messages API requests, as decoded bodies.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $requests = array();

	/**
	 * HTTP status for each request in turn; 200 once the list runs out.
	 *
	 * @var int[]
	 */
	private $statuses = array();

	/**
	 * True to answer every request with a refusal.
	 *
	 * @var bool
	 */
	private $refuse = false;

	/**
	 * Source records the test offers.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $sources = array();

	public function set_up() {
		parent::set_up();

		$this->requests = array();
		$this->statuses = array();
		$this->sources  = array();
		$this->refuse   = false;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_option( 'undergrowth_anthropic_api_key', 'sk-ant-test-0123456789abcd' );
		delete_option( 'undergrowth_prompts' );
		delete_option( 'undergrowth_last_prompt_error' );
		undergrowth_unschedule_prompts();
		remove_all_filters( 'undergrowth_prompt_sources' );
		add_filter( 'undergrowth_prompt_sources', array( $this, 'sources' ) );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 2 );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		unset( $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['undergrowth_notice'], $_GET['undergrowth_count'], $_GET['view'] );
		undergrowth_unschedule_prompts();
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Offers the test's source records.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function sources(): array {
		return $this->sources;
	}

	/**
	 * Answers a Messages API request with a question, or with the next
	 * queued failure.
	 *
	 * @param false|array<string, mixed>|WP_Error $preempt Short-circuit value.
	 * @param array<string, mixed>                $args    Request arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	public function fake_http( $preempt, $args ) {
		$this->requests[] = json_decode( $args['body'], true );
		$status           = array_shift( $this->statuses ) ?? 200;

		if ( 0 === $status ) {
			return new WP_Error( 'http_request_failed', 'Could not resolve host' );
		}

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode(
				array(
					'content'     => array(
						array(
							'type' => 'text',
							'text' => 'What else is there to say about part ' . str_repeat( 'i', count( $this->requests ) ) . '?',
						),
					),
					'stop_reason' => $this->refuse ? 'refusal' : 'end_turn',
				)
			),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Adds source records about new published posts.
	 *
	 * @param int $count How many.
	 */
	private function offer( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$post_id         = self::factory()->post->create(
				array(
					'post_status'  => 'publish',
					'post_content' => 'Oca grows well here.',
				)
			);
			$this->sources[] = array(
				'source'  => 'gap',
				'key'     => 'test:' . $post_id,
				'post_id' => $post_id,
				'quotes'  => array( 'Oca grows well here.' ),
			);
		}
	}

	/**
	 * Dismisses every new question, so More questions is offered again.
	 */
	private function dismiss_all(): void {
		foreach ( undergrowth_prompts( 'new' ) as $prompt ) {
			undergrowth_dismiss_prompt( $prompt['key'] );
		}
	}

	/**
	 * Clicks More questions and returns where the handler redirected.
	 *
	 * @return string Redirect location.
	 * @throws WPDieException When the handler refuses the request.
	 */
	private function click(): string {
		$nonce                = wp_create_nonce( 'undergrowth_more_prompts' );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		try {
			undergrowth_handle_more_prompts();
		} catch ( WPDieException $e ) {
			throw $e;
		} catch ( Exception $e ) {
			return $e->getMessage();
		}

		return '';
	}

	/**
	 * Renders the notice the studio shows after a redirect.
	 *
	 * @param string $location Redirect location.
	 * @return string
	 */
	private function notice( string $location ): string {
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$_GET['undergrowth_notice'] = $query['undergrowth_notice'] ?? '';
		$_GET['undergrowth_count']  = $query['undergrowth_count'] ?? '';
		ob_start();
		undergrowth_render_studio_notice();

		return (string) ob_get_clean();
	}

	/**
	 * Renders the studio's new questions.
	 *
	 * @return string
	 */
	private function studio(): string {
		$_GET['view'] = '';
		ob_start();
		undergrowth_render_studio_page();

		return (string) ob_get_clean();
	}

	public function test_the_button_and_its_cost_show_once_no_new_question_is_left() {
		$html = $this->studio();

		$this->assertStringContainsString( 'name="action" value="undergrowth_more_prompts"', $html );
		$this->assertStringContainsString( 'aria-describedby="undergrowth-more-cost"', $html );
		$this->assertMatchesRegularExpression( '#<p id="undergrowth-more-cost">\s*The More questions button asks Claude for up to 3 questions straight away, using this site’s Anthropic API key\. One click sends at most 6 requests, each usually one or two cents at Anthropic’s published prices\. A request may be charged even if it produces no question\.#', $html );
		$this->assertLessThan( strpos( $html, '>More questions</button>' ), strpos( $html, 'id="undergrowth-more-cost"' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_no_button_while_new_questions_wait() {
		$this->offer( 1 );
		undergrowth_write_prompt();

		$html = $this->studio();

		$this->assertStringNotContainsString( 'undergrowth_more_prompts', $html );
		$this->assertStringContainsString( 'one or two cents', $html );
	}

	public function test_no_button_for_a_user_who_cannot_edit_every_post() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertStringNotContainsString( 'undergrowth_more_prompts', $this->studio() );
	}

	public function test_a_click_writes_up_to_three_questions_and_says_how_many() {
		$this->offer( 5 );

		$location = $this->click();

		$this->assertStringContainsString( 'undergrowth_notice=more_written', $location );
		$this->assertStringContainsString( 'undergrowth_count=3', $location );
		$this->assertCount( 3, undergrowth_prompts( 'new' ) );
		$this->assertCount( 3, $this->requests );
		$this->assertStringContainsString( 'Claude wrote 3 new questions for you.', $this->notice( $location ) );
	}

	public function test_a_second_click_within_ten_minutes_sends_nothing() {
		$this->offer( 5 );
		$this->click();
		$this->dismiss_all();

		$location = $this->click();

		$this->assertStringContainsString( 'undergrowth_notice=more_wait', $location );
		$this->assertCount( 3, $this->requests );
		$this->assertStringContainsString( 'notice-warning', $this->notice( $location ) );
	}

	public function test_another_author_may_click_at_once() {
		$this->offer( 5 );
		$this->click();
		$this->dismiss_all();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertStringContainsString( 'undergrowth_notice=more_written', $this->click() );
		$this->assertCount( 5, $this->requests );
	}

	public function test_a_click_after_ten_minutes_asks_again() {
		$this->offer( 5 );
		$this->click();
		$this->dismiss_all();
		update_user_meta( get_current_user_id(), 'undergrowth_more_prompts_at', time() - UNDERGROWTH_PROMPT_MORE_WAIT - 1 );

		$this->assertStringContainsString( 'undergrowth_count=2', $this->click() );
	}

	public function test_a_click_while_new_questions_wait_sends_nothing() {
		$this->offer( 5 );
		$this->click();
		update_user_meta( get_current_user_id(), 'undergrowth_more_prompts_at', time() - UNDERGROWTH_PROMPT_MORE_WAIT - 1 );

		$location = $this->click();

		$this->assertStringContainsString( 'undergrowth_notice=more_waiting', $location );
		$this->assertCount( 3, $this->requests );
		$this->assertStringContainsString( 'already waiting', $this->notice( $location ) );
	}

	public function test_a_click_sent_at_the_same_time_as_another_sends_nothing() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$this->offer( 3 );
		WP_Upgrader::create_lock( 'undergrowth_more_prompts_' . get_current_user_id(), MINUTE_IN_SECONDS );

		$this->assertStringContainsString( 'undergrowth_notice=more_wait', $this->click() );
		$this->assertSame( array(), $this->requests );

		WP_Upgrader::release_lock( 'undergrowth_more_prompts_' . get_current_user_id() );
	}

	public function test_a_failure_like_an_earlier_one_is_still_reported() {
		$this->offer( 3 );
		$this->statuses = array( 429 );
		$this->click();
		update_user_meta( get_current_user_id(), 'undergrowth_more_prompts_at', time() - UNDERGROWTH_PROMPT_MORE_WAIT - 1 );
		$this->statuses = array( 429 );

		$this->assertStringContainsString( 'undergrowth_notice=more_failed', $this->click() );
	}

	public function test_nothing_left_to_ask_about_says_so_without_a_request() {
		$location = $this->click();

		$this->assertStringContainsString( 'undergrowth_notice=more_none', $location );
		$this->assertSame( array(), $this->requests );
		$this->assertStringContainsString( 'no new question for you', $this->notice( $location ) );
	}

	public function test_rate_limit_from_anthropic_writes_nothing_and_says_why() {
		$this->offer( 3 );
		$this->statuses = array( 429 );

		$location = $this->click();

		$this->assertStringContainsString( 'undergrowth_notice=more_failed', $location );
		$this->assertSame( array(), undergrowth_prompts( 'new' ) );

		$html = $this->notice( $location );

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'No new question was written.', $html );
		$this->assertStringContainsString( 'requests came too fast, or the account is out of credit', $html );
		$this->assertStringContainsString( 'status 429', $html );
	}

	public function test_network_failure_says_anthropic_could_not_be_reached() {
		$this->offer( 3 );
		$this->statuses = array( 0 );

		$this->assertStringContainsString( 'Anthropic could not be reached', $this->notice( $this->click() ) );
	}

	public function test_a_failure_after_one_question_keeps_it_and_says_so() {
		$this->offer( 3 );
		$this->statuses = array( 200, 500 );

		$location = $this->click();

		$this->assertStringContainsString( 'undergrowth_notice=more_partial', $location );
		$this->assertCount( 1, undergrowth_prompts( 'new' ) );

		$html = $this->notice( $location );

		$this->assertStringContainsString( 'Claude wrote 1 new question for you. Then a request to Anthropic failed', $html );
		$this->assertStringContainsString( 'had a problem on its side', $html );
	}

	public function test_an_older_failure_is_not_blamed_on_this_click() {
		$this->offer( 3 );
		undergrowth_record_failure( 'undergrowth_last_prompt_error', 'http_status', 529 );
		update_option( 'undergrowth_last_prompt_error', array_merge( get_option( 'undergrowth_last_prompt_error' ), array( 'time' => time() - HOUR_IN_SECONDS ) ) );

		$this->assertStringContainsString( 'undergrowth_notice=more_written', $this->click() );
	}

	public function test_a_refusal_is_not_a_failure() {
		$this->offer( 2 );
		$this->refuse = true;

		$location = $this->click();

		$this->assertCount( 2, $this->requests );
		$this->assertStringContainsString( 'undergrowth_notice=more_none', $location );

		$html = $this->notice( $location );

		$this->assertStringContainsString( 'no new question for you', $html );
		$this->assertStringNotContainsString( 'nothing was sent', $html );
	}

	public function test_no_key_sends_nothing() {
		$this->offer( 3 );
		update_option( 'undergrowth_anthropic_api_key', '' );

		$location = $this->click();

		$this->assertStringContainsString( 'undergrowth_notice=more_no_key', $location );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_click_without_a_valid_nonce_is_refused() {
		$this->offer( 3 );
		$_REQUEST['_wpnonce'] = 'bad';

		$this->expectException( WPDieException::class );
		undergrowth_handle_more_prompts();
	}

	public function test_a_user_who_cannot_edit_every_post_is_refused() {
		$this->offer( 3 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		try {
			$this->click();
			$this->fail( 'The click was not refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), $this->requests );
		}
	}

	public function test_core_removes_the_count_from_the_address() {
		$this->assertContains( 'undergrowth_count', wp_removable_query_args() );
	}

	public function test_uninstall_forgets_when_each_author_last_asked() {
		$this->offer( 1 );
		$this->click();

		undergrowth_uninstall_site();

		$this->assertSame( '', get_user_meta( get_current_user_id(), 'undergrowth_more_prompts_at', true ) );
	}
}
