<?php
/**
 * Tests for cross-pollination pairs as a prompt source, with a place of
 * their own in the weekly batch.
 *
 * Below 20 posts the fixed thresholds apply, so the band at the default
 * step runs from 0.45 up to 0.5. Receipt sits at 0.46 to the roots.
 *
 * @package Undergrowth
 */

/**
 * Cross-pollination prompts.
 */
class Test_Prompts_Cross extends WP_UnitTestCase {

	use Plants_Posts;

	const QUESTION = 'What connects "Oca grows in cold, wet soil." with "Every receipt goes into the ledger."?';

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

		update_option( 'undergrowth_memory_exposed', array( 'exposed' => false ), false );

		$this->requests = array();
		$this->reply    = self::QUESTION;
		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'undergrowth_anthropic_api_key', 'sk-ant-test-0123456789abcd' );
		undergrowth_unschedule_prompts();
		delete_option( 'undergrowth_prompts' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 2 );
	}

	public function tear_down() {
		undergrowth_unschedule_prompts();
		undergrowth_delete_memory_folder();

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
	 * Publishes posts with vectors and content and puts them in one group,
	 * led by the first.
	 *
	 * @param array<string, array{0: float[], 1: string}> $posts Vector and content, keyed by title.
	 * @return int[] Post IDs, in order.
	 */
	private function group( array $posts ): array {
		$ids = array();

		foreach ( $posts as $title => $post ) {
			$ids[] = $this->seed( $title, $post[0], array( 'post_content' => $post[1] ) );
		}

		foreach ( $ids as $post_id ) {
			update_post_meta( $post_id, 'undergrowth_cluster', $ids[0] );
		}

		return $ids;
	}

	/**
	 * Makes two groups, with Receipt in the second at a chosen similarity to
	 * the first, and finds the pairs.
	 *
	 * @param float $cosine Similarity of Receipt to the first group.
	 * @return array{0: int[], 1: int[]} Post IDs of both groups.
	 */
	private function garden( float $cosine = 0.46 ): array {
		$a = $this->group(
			array(
				'Oca'    => array( array( 1.0, 0.0, 0.0 ), '<p>Oca grows in cold, wet soil. Short.</p>' ),
				'Ulluco' => array( array( 1.0, 0.0, 0.0 ), '<p>Ulluco grows on the same terraces.</p>' ),
				'Mashua' => array( array( 1.0, 0.0, 0.0 ), '<p>Mashua climbs whatever is near it.</p>' ),
			)
		);
		$b = $this->group(
			array(
				'Ledger'  => array( array( 0.0, 1.0, 0.0 ), '<p>The ledger holds the whole year.</p>' ),
				'Invoice' => array( array( 0.0, 1.0, 0.0 ), '<p>An invoice came for the seed order.</p>' ),
				'Receipt' => array( array( $cosine, sqrt( 1 - $cosine * $cosine ), 0.0 ), '<p>Every receipt goes into the ledger. Short.</p>' ),
			)
		);

		undergrowth_start_cluster_labels();
		undergrowth_start_pollination();

		$record = get_option( 'undergrowth_pollination_cycle_pass' );
		undergrowth_run_pollination_cycle( $record['after'], $record['pass'] );

		return array( $a, $b );
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

	/**
	 * Returns the new questions of one source.
	 *
	 * @param string $source Source.
	 * @return array<int, array<string, mixed>>
	 */
	private function new_from( string $source ): array {
		return array_values(
			array_filter(
				undergrowth_prompts( 'new' ),
				static function ( $prompt ) use ( $source ) {
					return $source === $prompt['source'];
				}
			)
		);
	}

	public function test_pair_is_a_source_quoting_one_sentence_from_each_post() {
		list( $a, $b ) = $this->garden();

		$records = array_values( array_filter( undergrowth_prompt_candidates(), 'undergrowth_is_cross_prompt_record' ) );

		$this->assertCount( 1, $records );
		$this->assertSame( 'cross:' . $a[0] . '-' . $b[2], $records[0]['key'] );
		$this->assertSame( $a[0], $records[0]['post_id'] );
		$this->assertSame( array( $a[0], $b[2] ), $records[0]['posts'] );
		$this->assertSame( array( 'Oca grows in cold, wet soil.', 'Every receipt goes into the ledger.' ), $records[0]['quotes'] );
		$this->assertSame( '', $records[0]['data'] );
	}

	public function test_pair_with_only_a_bridge_quotes_the_post_each_group_is_named_after() {
		list( $a, $b ) = $this->garden( 0.0 );
		$this->seed( 'Roots and ledgers', array( 1.0, 1.0, 0.0 ), array( 'post_content' => '<p>Roots and ledgers.</p>' ) );

		undergrowth_start_cluster_labels();
		undergrowth_start_pollination();
		$record = get_option( 'undergrowth_pollination_cycle_pass' );
		undergrowth_run_pollination_cycle( $record['after'], $record['pass'] );

		$records = array_values( array_filter( undergrowth_prompt_candidates(), 'undergrowth_is_cross_prompt_record' ) );
		$map     = undergrowth_cluster_map();

		$this->assertCount( 1, $records );
		$this->assertSame( array( $map[0]['members'][0], $map[1]['members'][0] ), $records[0]['posts'] );
	}

	public function test_weekly_batch_holds_a_cross_pollination_question_first() {
		$this->garden();

		undergrowth_run_prompt_batch();

		$this->assertCount( 1, $this->new_from( 'cross' ) );
		$this->assertSame( 'These two posts by the author sit in groups of their writing that rarely meet. One sentence comes from each post.', $this->sent_data( 0 )['source'] );
	}

	public function test_cross_question_takes_its_place_before_the_other_sources() {
		list( $a ) = $this->garden();

		// Five reader questions would fill the batch on their own.
		for ( $i = 0; $i < 5; $i++ ) {
			self::factory()->comment->create(
				array(
					'comment_post_ID'  => $a[1],
					'comment_content'  => 'How do you store it over winter, part ' . $i . '?',
					'comment_approved' => 1,
				)
			);
		}

		$this->reply = 'What would you tell a reader who asks how you store it?';

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) {
				$data        = json_decode( $args['body'], true );
				$this->reply = false !== strpos( $data['messages'][0]['content'], 'rarely meet' ) ? self::QUESTION : 'What would you tell a reader who asks how you store it?';

				return $preempt;
			},
			5,
			2
		);

		undergrowth_run_prompt_batch();

		$this->assertCount( UNDERGROWTH_PROMPT_BATCH, undergrowth_prompts( 'new' ) );
		$this->assertCount( 1, $this->new_from( 'cross' ) );
		$this->assertCount( UNDERGROWTH_PROMPT_BATCH - 1, $this->new_from( 'comment' ) );
	}

	public function test_cross_question_shares_the_batchs_budget_of_requests() {
		list( $a ) = $this->garden();

		for ( $i = 0; $i < 20; $i++ ) {
			self::factory()->comment->create(
				array(
					'comment_post_ID'  => $a[1],
					'comment_content'  => 'How do you store it over winter, part ' . $i . '?',
					'comment_approved' => 1,
				)
			);
		}

		// No reply is a question, so every source is tried.
		$this->reply = 'Not a question.';

		undergrowth_run_prompt_batch();

		$this->assertCount( UNDERGROWTH_PROMPT_BATCH_CALLS, $this->requests );
		$this->assertStringContainsString( 'rarely meet', $this->requests[0]['messages'][0]['content'] );
	}

	public function test_without_a_pair_the_batch_is_unchanged() {
		$this->garden( 0.0 );

		$this->assertSame( array(), array_filter( undergrowth_prompt_candidates(), 'undergrowth_is_cross_prompt_record' ) );

		undergrowth_run_prompt_batch();

		$this->assertSame( array(), $this->new_from( 'cross' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_batch_that_already_holds_one_asks_for_no_second_first() {
		$this->garden();
		undergrowth_run_prompt_batch();
		$sent = count( $this->requests );

		$this->assertSame( 0, undergrowth_write_cross_prompt() );
		$this->assertCount( $sent, $this->requests );
	}

	public function test_pair_is_asked_about_once() {
		$this->garden();
		undergrowth_run_prompt_batch();

		$this->assertTrue( undergrowth_dismiss_prompt( $this->new_from( 'cross' )[0]['key'] ) );

		undergrowth_run_prompt_batch();

		$this->assertSame( array(), $this->new_from( 'cross' ) );
	}

	public function test_request_sends_only_the_two_posts_sentences_and_the_rules() {
		$this->garden();
		undergrowth_write_cross_prompt();

		$data = $this->sent_data( 0 );

		$this->assertSame( array( 'Oca grows in cold, wet soil.', 'Every receipt goes into the ledger.' ), $data['author_sentences'] );
		$this->assertArrayNotHasKey( 'reader_text', $data );
		$this->assertStringContainsString( 'Ask what connects them, never name a topic for the author.', $this->requests[0]['system'] );
	}

	public function test_question_quoting_anything_but_the_two_posts_is_thrown_away() {
		$this->garden();
		$this->reply = 'What connects "Oca grows in cold, wet soil." with "Mashua climbs whatever is near it."?';

		$this->assertSame( 0, undergrowth_write_cross_prompt() );
		$this->assertSame( array(), $this->new_from( 'cross' ) );
	}

	public function test_dismissed_pair_gives_no_question() {
		list( $a, $b ) = $this->garden();

		$this->assertTrue( undergrowth_dismiss_pollination( $a[0], $b[0] ) );
		$this->assertSame( 0, undergrowth_write_cross_prompt() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_pair_with_a_post_of_no_sentence_of_its_own_is_no_source() {
		list( , $b ) = $this->garden();

		wp_update_post(
			array(
				'ID'           => $b[2],
				'post_content' => '<blockquote><p>Someone else wrote this.</p></blockquote>',
			)
		);

		$this->assertSame( array(), array_values( array_filter( undergrowth_prompt_candidates(), 'undergrowth_is_cross_prompt_record' ) ) );
	}

	public function test_studio_labels_the_question_and_links_both_posts() {
		list( $a, $b ) = $this->garden();
		undergrowth_write_cross_prompt();

		ob_start();
		undergrowth_render_new_prompts( undergrowth_prompts( 'new' ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Cross-pollination: from “<a ', $html );
		$this->assertStringContainsString( '</a>” and “<a ', $html );
		$this->assertMatchesRegularExpression( '#post=' . $a[0] . '&(?:amp|\#038);action=edit#', $html );
		$this->assertMatchesRegularExpression( '#post=' . $b[2] . '&(?:amp|\#038);action=edit#', $html );
	}
}
