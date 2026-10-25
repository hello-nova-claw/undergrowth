<?php
/**
 * Tests for the weekly batch, which tops the new questions up to five.
 *
 * @package Undergrowth
 */

/**
 * Up to five questions a week, never padded and never past the request limit.
 */
class Test_Prompt_Batch extends WP_UnitTestCase {

	/**
	 * Messages API requests, as decoded bodies.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $requests = array();

	/**
	 * Text of the reply to a request for a question, or null to number them.
	 *
	 * @var string|null
	 */
	private $reply = null;

	/**
	 * HTTP status the fake Messages API answers with.
	 *
	 * @var int
	 */
	private $status = 200;

	/**
	 * Source records the test offers.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $sources = array();

	public function set_up() {
		parent::set_up();

		$this->requests = array();
		$this->reply    = null;
		$this->status   = 200;
		$this->sources  = array();
		update_option( 'undergrowth_anthropic_api_key', 'sk-ant-test-0123456789abcd' );
		delete_option( 'undergrowth_prompts' );
		undergrowth_unschedule_prompts();
		remove_all_filters( 'undergrowth_prompt_sources' );
		add_filter( 'undergrowth_prompt_sources', array( $this, 'sources' ) );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 2 );
	}

	public function tear_down() {
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
	 * Answers a Messages API request: a numbered question, or NO to a check
	 * on a question about a resting post.
	 *
	 * @param false|array<string, mixed>|WP_Error $preempt Short-circuit value.
	 * @param array<string, mixed>                $args    Request arguments.
	 * @return array<string, mixed>
	 */
	public function fake_http( $preempt, $args ): array {
		$body             = json_decode( $args['body'], true );
		$this->requests[] = $body;
		$check            = 0 === strpos( $body['system'], 'You check one question' );
		// No digits, which a question about a resting post may not hold.
		$text = $check ? 'NO' : ( $this->reply ?? 'What else is there to say about part ' . str_repeat( 'i', count( $this->requests ) ) . '?' );

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode(
				array(
					'content'     => array(
						array(
							'type' => 'text',
							'text' => $text,
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
	 * Adds source records about new published posts.
	 *
	 * @param int  $count How many.
	 * @param bool $rest  True for posts the author chose to rest, which need
	 *                    a check request too.
	 */
	private function offer( int $count, bool $rest = false ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$post_id         = self::factory()->post->create(
				array(
					'post_status'  => 'publish',
					'post_content' => 'Oca grows well here.',
				)
			);
			$this->sources[] = array(
				'source'  => $rest ? 'compost' : 'gap',
				'key'     => 'test:' . $post_id,
				'post_id' => $post_id,
				'quotes'  => array( 'Oca grows well here.' ),
				'rest'    => $rest,
			);
		}
	}

	/**
	 * Stores new questions about a new post.
	 *
	 * @param int    $count  How many.
	 * @param string $status Their status.
	 */
	private function waiting( int $count, string $status = 'new' ): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$prompts = (array) get_option( 'undergrowth_prompts', array() );

		for ( $i = 0; $i < $count; $i++ ) {
			$prompts[] = array(
				'key'      => $status . ':' . $i,
				'source'   => 'gap',
				'post_id'  => $post_id,
				'question' => 'What sits between these posts?',
				'time'     => time(),
				'status'   => $status,
			);
		}

		update_option( 'undergrowth_prompts', $prompts );
	}

	public function test_the_batch_writes_up_to_five_new_questions() {
		$this->offer( 7 );

		do_action( 'undergrowth_prompt_batch' );

		$this->assertCount( UNDERGROWTH_PROMPT_BATCH, undergrowth_prompts( 'new' ) );
		$this->assertCount( 5, $this->requests );
	}

	public function test_a_full_batch_sends_nothing() {
		$this->offer( 3 );
		$this->waiting( 5 );

		do_action( 'undergrowth_prompt_batch' );

		$this->assertSame( array(), $this->requests );
	}

	public function test_the_batch_only_tops_up_what_is_missing() {
		$this->offer( 4 );
		$this->waiting( 3 );

		do_action( 'undergrowth_prompt_batch' );

		$this->assertCount( 5, undergrowth_prompts( 'new' ) );
		$this->assertCount( 2, $this->requests );
	}

	public function test_saved_and_dismissed_questions_leave_room_in_the_batch() {
		$this->offer( 5 );
		$this->waiting( 4, 'saved' );
		$this->waiting( 2, 'dismissed' );

		do_action( 'undergrowth_prompt_batch' );

		$this->assertCount( 5, undergrowth_prompts( 'new' ) );
		$this->assertCount( 4, undergrowth_prompts( 'saved' ) );
	}

	public function test_the_batch_stops_short_when_no_source_is_left() {
		$this->offer( 2 );

		do_action( 'undergrowth_prompt_batch' );
		do_action( 'undergrowth_prompt_batch' );

		$this->assertCount( 2, undergrowth_prompts( 'new' ) );
		$this->assertCount( 2, $this->requests );
	}

	public function test_no_key_no_requests() {
		$this->offer( 3 );
		update_option( 'undergrowth_anthropic_api_key', '' );

		do_action( 'undergrowth_prompt_batch' );
		undergrowth_run_prompt_batch();

		$this->assertSame( array(), $this->requests );
		$this->assertSame( array(), undergrowth_stored_prompts() );
	}

	public function test_a_failed_request_ends_the_batch_and_keeps_the_source() {
		$this->offer( 3 );
		$this->status = 529;

		do_action( 'undergrowth_prompt_batch' );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( array(), undergrowth_prompts( 'new' ) );

		$this->status = 200;
		do_action( 'undergrowth_prompt_batch' );

		$this->assertCount( 3, undergrowth_prompts( 'new' ) );
	}

	public function test_a_batch_never_sends_more_than_ten_requests() {
		$this->offer( 6, true );

		do_action( 'undergrowth_prompt_batch' );

		$this->assertCount( UNDERGROWTH_PROMPT_BATCH_CALLS, $this->requests );
		$this->assertCount( 5, undergrowth_prompts( 'new' ) );
	}

	public function test_replies_that_are_not_questions_are_thrown_away_within_the_limit() {
		$this->offer( 12 );
		$this->reply = 'Title: Ten things about oca';

		do_action( 'undergrowth_prompt_batch' );

		$this->assertCount( UNDERGROWTH_PROMPT_BATCH_CALLS, $this->requests );
		$this->assertSame( array(), undergrowth_prompts( 'new' ) );
	}

	public function test_a_draft_or_a_quote_of_someone_else_is_never_kept() {
		$this->offer( 2 );
		$this->reply = "Here is an opening line for your next post:\nOca is the best tuber.";

		do_action( 'undergrowth_prompt_batch' );

		$this->assertSame( array(), undergrowth_prompts( 'new' ) );

		$this->offer( 1 );
		$this->reply = 'You wrote "tubers are easy" there. Are they?';

		do_action( 'undergrowth_prompt_batch' );

		$this->assertSame( array(), undergrowth_prompts( 'new' ) );
	}

	public function test_a_run_can_be_kept_to_some_sources() {
		$this->offer( 2 );
		$this->offer( 2, true );

		$this->assertSame(
			2,
			undergrowth_write_prompts(
				5,
				UNDERGROWTH_PROMPT_BATCH_CALLS,
				static function ( $record ) {
					return 'compost' === $record['source'];
				}
			)
		);
		$this->assertSame( array( 'compost', 'compost' ), array_column( undergrowth_prompts( 'new' ), 'source' ) );
	}

	public function test_saving_a_key_fills_the_first_batch() {
		$this->offer( 6 );
		update_option( 'undergrowth_anthropic_api_key', '' );
		update_option( 'undergrowth_anthropic_api_key', 'sk-ant-test-abcdefghijklmnop' );

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_write_prompt' ) );

		do_action( 'undergrowth_write_prompt' );

		$this->assertCount( 5, undergrowth_prompts( 'new' ) );
	}
}
