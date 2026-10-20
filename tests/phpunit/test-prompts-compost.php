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

	public function set_up() {
		parent::set_up();

		$this->requests = array();
		$this->reply    = self::QUESTION;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'undergrowth_anthropic_api_key', 'sk-ant-test-0123456789abcd' );
		undergrowth_unschedule_prompts();
		delete_option( 'undergrowth_prompt' );
		delete_option( 'undergrowth_snoozed_prompts' );
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
		$this->requests[] = json_decode( $args['body'], true );

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode(
				array(
					'content'     => array(
						array(
							'type' => 'text',
							'text' => $this->reply,
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

		do_action( 'undergrowth_prompt_cycle' );
		do_action( 'undergrowth_prompt_cycle' );

		$this->assertNull( undergrowth_current_prompt() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_snoozed_composted_post_prompt_returns_next_cycle() {
		$this->composted();
		undergrowth_write_prompt();
		undergrowth_snooze_prompt( undergrowth_current_prompt()['key'] );

		do_action( 'undergrowth_prompt_cycle' );

		$this->assertSame( self::QUESTION, undergrowth_current_prompt()['question'] );
		$this->assertCount( 1, $this->requests );
	}

	public function test_widget_says_the_question_comes_from_a_composted_post() {
		$this->composted();
		undergrowth_write_prompt();

		ob_start();
		undergrowth_render_prompt_widget();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'moved to compost', $html );
		$this->assertStringNotContainsString( 'reader', $html );
	}
}
