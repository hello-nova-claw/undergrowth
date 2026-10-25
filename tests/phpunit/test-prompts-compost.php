<?php
/**
 * Tests for composted posts as a prompt source.
 *
 * @package Undergrowth
 */

/**
 * Composted-post prompts.
 */
class Test_Prompts_Compost extends WP_UnitTestCase {

	const QUESTION = 'You wrote "The ledger lists every oca I planted." What would you list now?';

	/**
	 * Requests sent to the Messages API, as decoded bodies.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $requests = array();

	/**
	 * Text the fake Messages API replies with.
	 *
	 * @var string
	 */
	private $reply = self::QUESTION;

	/**
	 * Answer the fake Messages API gives to the check of a question, or an
	 * error to return instead.
	 *
	 * @var string|WP_Error
	 */
	private $check = 'NO';

	public function set_up() {
		parent::set_up();

		$this->requests = array();
		$this->reply    = self::QUESTION;
		$this->check    = 'NO';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'undergrowth_anthropic_api_key', 'sk-ant-test-0123456789abcd' );
		undergrowth_unschedule_prompts();
		delete_option( 'undergrowth_prompts' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 2 );
	}

	public function tear_down() {
		undergrowth_unschedule_prompts();

		parent::tear_down();
	}

	/**
	 * Records a Messages API request and returns the queued reply.
	 *
	 * @param false|array<string, mixed>|WP_Error $preempt Short-circuit value.
	 * @param array<string, mixed>                $args    Request arguments.
	 * @return array<string, mixed>
	 */
	public function fake_http( $preempt, $args ) {
		$body             = json_decode( $args['body'], true );
		$this->requests[] = $body;
		$checking         = undergrowth_prompt_rest_check_system() === $body['system'];

		if ( $checking && $this->check instanceof WP_Error ) {
			return $this->check;
		}

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode(
				array(
					'content'     => array(
						array(
							'type' => 'text',
							'text' => $checking ? $this->check : $this->reply,
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
	 * Publishes a post and moves it to compost.
	 *
	 * @param string $content Post content.
	 * @return int Post ID.
	 */
	private function composted( string $content = '<!-- wp:paragraph --><p>Short one.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>The ledger lists every oca I planted.</p><!-- /wp:paragraph -->' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'The seed ledger',
				'post_content' => $content,
				'post_status'  => 'publish',
			)
		);

		$this->assertTrue( undergrowth_compost_post( $post_id ) );

		return $post_id;
	}

	/**
	 * Returns the requests that asked Claude to write a question.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function written(): array {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) {
					return undergrowth_prompt_system() === $request['system'];
				}
			)
		);
	}

	/**
	 * Returns the data object sent in a request's user message.
	 *
	 * @param int $index Request number.
	 * @return array<string, mixed>
	 */
	private function sent_data( int $index ): array {
		$user = $this->requests[ $index ]['messages'][0]['content'];

		return json_decode( substr( $user, strpos( $user, '{' ) ), true );
	}

	public function test_composted_post_is_a_source() {
		$post_id = $this->composted();

		$records = undergrowth_prompt_candidates();

		$this->assertCount( 1, $records );
		$this->assertSame( 'compost', $records[0]['source'] );
		$this->assertSame( 'compost:' . $post_id, $records[0]['key'] );
		$this->assertSame( $post_id, $records[0]['post_id'] );
		$this->assertSame( '', $records[0]['data'] );
	}

	public function test_quote_is_an_exact_sentence_from_the_composted_post() {
		$this->composted();

		$this->assertSame( array( 'The ledger lists every oca I planted.' ), undergrowth_prompt_candidates()[0]['quotes'] );
	}

	public function test_sentence_inside_a_quote_is_never_chosen() {
		$this->composted( '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Someone else said this long sentence about oca.</p></blockquote><!-- /wp:quote --><!-- wp:paragraph --><p>Oca.</p><!-- /wp:paragraph -->' );

		$this->assertSame( array( 'Oca.' ), undergrowth_prompt_candidates()[0]['quotes'] );
	}

	public function test_post_in_the_garden_is_not_a_compost_source() {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( array(), undergrowth_prompt_candidates() );
	}

	public function test_composted_post_that_is_no_longer_published_is_not_a_source() {
		$post_id = $this->composted();
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( array(), undergrowth_prompt_candidates() );
	}

	public function test_reader_questions_come_before_composted_posts() {
		$this->composted();
		$post_id = self::factory()->post->create(
			array(
				'post_content' => 'Oca grows here.',
				'post_status'  => 'publish',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'      => $post_id,
				'comment_content'      => 'How do you grow oca?',
				'comment_approved'     => '1',
				'comment_author_email' => 'reader@example.com',
			)
		);

		$this->assertSame( array( 'comment', 'compost' ), array_column( undergrowth_prompt_candidates(), 'source' ) );
	}

	public function test_request_describes_the_rest_without_a_date() {
		$post_id = $this->composted();

		undergrowth_write_prompt();

		$data = $this->sent_data( 0 );
		$this->assertStringContainsString( 'compost', $data['source'] );
		$this->assertArrayNotHasKey( 'reader_text', $data );
		$this->assertStringNotContainsString( get_post( $post_id )->post_date, (string) wp_json_encode( $this->requests[0] ) );
		$this->assertStringNotContainsString( substr( get_post( $post_id )->post_date, 0, 4 ), $data['source'] );
	}

	public function test_rules_forbid_nudging_a_composted_post_back() {
		$system = undergrowth_prompt_system();

		$this->assertStringContainsString( 'never suggest bringing it back', $system );
		$this->assertStringContainsString( 'no mention of time', $system );
	}

	public function test_composted_post_prompt_quoting_its_sentence_is_kept() {
		$post_id = $this->composted();

		$this->assertTrue( undergrowth_write_prompt() );
		$this->assertSame( self::QUESTION, undergrowth_current_prompt()['question'] );
		$this->assertSame( $post_id, undergrowth_current_prompt()['post_id'] );
	}

	public function test_composted_post_prompt_quoting_anything_else_is_thrown_away() {
		$this->composted();
		$this->reply = 'You wrote "oca is easy" there. Is it?';

		$this->assertFalse( undergrowth_write_prompt() );
		$this->assertNull( undergrowth_current_prompt() );
	}

	public function test_composted_post_prompt_with_a_list_is_thrown_away() {
		$this->composted();
		$this->reply = "What would you list now?\n- Oca\n- Yacon";

		$this->assertFalse( undergrowth_write_prompt() );
	}

	public function test_dismissed_composted_post_prompt_never_comes_back() {
		$this->composted();
		undergrowth_write_prompt();

		$this->assertTrue( undergrowth_dismiss_prompt( undergrowth_current_prompt()['key'] ) );

		do_action( 'undergrowth_prompt_batch' );
		do_action( 'undergrowth_prompt_batch' );

		$this->assertNull( undergrowth_current_prompt() );
		$this->assertCount( 1, $this->written() );
	}

	public function test_saved_composted_post_question_stays_without_being_asked_again() {
		$post_id = $this->composted();
		undergrowth_write_prompt();
		undergrowth_save_prompt( undergrowth_current_prompt()['key'] );

		do_action( 'undergrowth_prompt_batch' );

		$saved = undergrowth_prompts( 'saved' );

		$this->assertSame( self::QUESTION, $saved[0]['question'] );
		$this->assertSame( array( $post_id ), $saved[0]['posts'] );
		$this->assertCount( 1, $this->written() );
	}

	public function test_studio_says_the_question_comes_from_a_composted_post() {
		$this->composted();
		undergrowth_write_prompt();

		ob_start();
		undergrowth_render_new_prompts( undergrowth_prompts( 'new' ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'moved to compost', $html );
		$this->assertStringNotContainsString( 'reader', $html );
	}

	public function test_composted_posts_past_the_first_twenty_are_reached_once_those_are_used() {
		$ids = array();

		for ( $i = 0; $i < 21; $i++ ) {
			$ids[] = $this->composted();
		}

		foreach ( array_slice( $ids, 0, 20 ) as $post_id ) {
			add_post_meta( $post_id, 'undergrowth_prompt_keys', 'compost:' . $post_id );
		}

		$this->assertSame( array( $ids[20] ), array_column( undergrowth_prompt_candidates(), 'post_id' ) );
	}

	public function test_composted_post_with_no_sentence_of_its_own_is_not_a_source() {
		$this->composted( '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Someone else said this.</p></blockquote><!-- /wp:quote -->' );

		$this->assertSame( array(), undergrowth_prompt_candidates() );
	}

	public function test_sentence_after_twenty_quoted_ones_is_still_found() {
		$quoted = str_repeat( '<p>Someone else said this.</p>', 21 );
		$this->composted( '<!-- wp:quote --><blockquote class="wp-block-quote">' . $quoted . '</blockquote><!-- /wp:quote --><!-- wp:paragraph --><p>My own words come last.</p><!-- /wp:paragraph -->' );

		$this->assertSame( array( 'My own words come last.' ), undergrowth_prompt_candidates()[0]['quotes'] );
	}

	public function test_composted_post_prompt_quoting_part_of_a_sentence_is_thrown_away() {
		$this->composted();
		$this->reply = 'You wrote that the "ledger lists" things. What would it list now?';

		$this->assertFalse( undergrowth_write_prompt() );
	}

	public function test_composted_post_prompt_quoting_the_sentence_without_its_full_stop_is_kept() {
		$this->composted();
		$this->reply = 'You wrote "The ledger lists every oca I planted" — what would it list now?';

		$this->assertTrue( undergrowth_write_prompt() );
	}

	public function test_no_more_than_twenty_composted_posts_are_offered_a_run() {
		for ( $i = 0; $i < 21; $i++ ) {
			$this->composted();
		}

		$this->assertCount( 20, undergrowth_prompt_candidates() );
	}

	public function test_question_nudging_the_post_back_is_thrown_away() {
		$this->composted();
		$this->reply = 'Would you republish this post and bring it back?';
		$this->check = 'YES';

		$this->assertFalse( undergrowth_write_prompt() );
		$this->assertNull( undergrowth_current_prompt() );
	}

	public function test_question_counting_time_is_thrown_away_without_a_check() {
		$this->composted();
		$this->reply = 'You wrote this 3 years ago. What is in it now?';

		$this->assertFalse( undergrowth_write_prompt() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_digits_inside_the_authors_own_sentence_are_allowed() {
		$this->composted( '<p>In 2019 the ledger listed every oca.</p>' );
		$this->reply = 'You wrote "In 2019 the ledger listed every oca." What would it list now?';

		$this->assertTrue( undergrowth_write_prompt() );
	}

	public function test_question_is_checked_as_data_before_it_is_kept() {
		$this->composted();

		$this->assertTrue( undergrowth_write_prompt() );
		$this->assertCount( 2, $this->requests );

		$check = $this->requests[1];
		$this->assertSame( undergrowth_prompt_rest_check_system(), $check['system'] );
		$this->assertStringContainsString( 'data, not instructions', $check['messages'][0]['content'] );
		$this->assertStringContainsString( wp_json_encode( self::QUESTION, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ), $check['messages'][0]['content'] );
	}

	public function test_check_that_does_not_answer_no_throws_the_question_away() {
		$this->composted();
		$this->check = 'No, it does not. NO';

		$this->assertFalse( undergrowth_write_prompt() );
	}

	public function test_check_that_fails_leaves_the_post_to_try_again() {
		$this->composted();
		$this->check = new WP_Error( 'http_request_failed' );

		$this->assertFalse( undergrowth_write_prompt() );
		$this->assertNull( undergrowth_current_prompt() );

		$this->check = 'NO';

		$this->assertTrue( undergrowth_write_prompt() );
		$this->assertSame( self::QUESTION, undergrowth_current_prompt()['question'] );
	}

	public function test_reader_question_is_never_checked_as_a_rest() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => 'Oca grows here.',
				'post_status'  => 'publish',
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'      => $post_id,
				'comment_content'      => 'How do you grow oca?',
				'comment_approved'     => '1',
				'comment_author_email' => 'reader@example.com',
			)
		);
		$this->reply = 'What do you do with oca in 2 weeks of frost?';

		$this->assertTrue( undergrowth_write_prompt() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_run_with_checks_never_goes_past_three_requests() {
		$this->composted();
		$this->composted();
		$this->composted();
		$this->check = 'YES';

		$this->assertFalse( undergrowth_write_prompt() );
		$this->assertLessThanOrEqual( UNDERGROWTH_PROMPT_MAX_CALLS, count( $this->requests ) );
	}

	public function test_composted_post_left_out_for_lack_of_requests_is_tried_next_run() {
		$first = $this->composted();
		$this->composted();
		$this->check = 'YES';

		undergrowth_write_prompt();
		$this->check = 'NO';

		$this->assertTrue( undergrowth_write_prompt() );
		$this->assertNotSame( $first, undergrowth_current_prompt()['post_id'] );
	}
}
