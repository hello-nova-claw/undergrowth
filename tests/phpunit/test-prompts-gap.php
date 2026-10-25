<?php
/**
 * Tests for gaps in the author's own clusters as a prompt source.
 *
 * @package Undergrowth
 */

/**
 * Cluster-gap prompts.
 */
class Test_Prompts_Gap extends WP_UnitTestCase {

	use Plants_Posts;

	const QUESTION = 'Between "Oca grows in cold soil." and "Yacon needs a long season." is there a post you haven’t written?';

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

		// Files hold the questions only once visitors are known to be refused.
		update_option( 'undergrowth_memory_exposed', array( 'exposed' => false ), false );

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
	 * Publishes a post with a stored vector and puts it in a cluster.
	 *
	 * @param string  $title   Post title.
	 * @param string  $content Post content.
	 * @param float[] $vector  Vector to store.
	 * @param int     $leader  Leader of the cluster, or 0 for the post to lead.
	 * @return int Post ID.
	 */
	private function member( string $title, string $content, array $vector, int $leader = 0 ): int {
		$post_id = $this->seed( $title, $vector, array( 'post_content' => $content ) );

		update_post_meta( $post_id, 'undergrowth_cluster', 0 === $leader ? $post_id : $leader );

		return $post_id;
	}

	/**
	 * Plants a cluster of three posts with nothing at its centre.
	 *
	 * @return int[] Post IDs, nearest the centre first.
	 */
	private function gap(): array {
		$oca    = $this->member( 'Oca', '<p>Oca grows in cold soil. Short.</p>', array( 1.0, 0.0, 0.0 ) );
		$yacon  = $this->member( 'Yacon', '<p>Yacon needs a long season.</p>', array( 0.0, 1.0, 0.0 ), $oca );
		$mashua = $this->member( 'Mashua', '<p>Mashua climbs whatever is near it.</p>', array( 0.0, 0.0, 1.0 ), $oca );

		return array( $oca, $yacon, $mashua );
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

	public function test_gap_is_a_source_about_the_post_nearest_its_centre() {
		list( $oca ) = $this->gap();

		$records = undergrowth_prompt_candidates();

		$this->assertCount( 1, $records );
		$this->assertSame( 'gap', $records[0]['source'] );
		$this->assertStringStartsWith( 'gap:', $records[0]['key'] );
		$this->assertSame( $oca, $records[0]['post_id'] );
		$this->assertSame( '', $records[0]['data'] );
	}

	public function test_gap_quotes_one_sentence_from_each_of_the_nearest_posts() {
		$this->gap();

		$this->assertSame(
			array( 'Oca grows in cold soil.', 'Yacon needs a long season.', 'Mashua climbs whatever is near it.' ),
			undergrowth_prompt_candidates()[0]['quotes']
		);
	}

	public function test_gap_quotes_no_more_than_three_posts() {
		list( $oca ) = $this->gap();
		$this->member( 'Ulluco', '<p>Ulluco is the brightest of them.</p>', array( -0.5, 0.5, 0.5 ), $oca );

		$this->assertCount( 3, undergrowth_prompt_candidates()[0]['quotes'] );
	}

	public function test_cluster_with_a_post_at_its_centre_is_not_a_source() {
		$oca = $this->member( 'Oca', '<p>Oca.</p>', array( 1.0, 0.0, 0.0 ) );
		$this->member( 'Oca again', '<p>Oca again.</p>', array( 0.99, 0.1, 0.0 ), $oca );
		$this->member( 'More oca', '<p>More oca.</p>', array( 0.99, 0.0, 0.1 ), $oca );

		$this->assertSame( array(), undergrowth_prompt_candidates() );
	}

	public function test_gap_key_stays_while_the_cluster_does_and_changes_with_it() {
		list( $oca ) = $this->gap();
		$key         = undergrowth_prompt_candidates()[0]['key'];

		$this->assertSame( $key, undergrowth_prompt_candidates()[0]['key'] );

		$this->member( 'Ulluco', '<p>Ulluco is the brightest of them.</p>', array( -0.5, 0.5, 0.5 ), $oca );

		$this->assertNotSame( $key, undergrowth_prompt_candidates()[0]['key'] );
	}

	public function test_reader_questions_come_before_gaps() {
		list( $oca ) = $this->gap();
		self::factory()->comment->create(
			array(
				'comment_post_ID'      => $oca,
				'comment_content'      => 'How cold can oca go?',
				'comment_approved'     => '1',
				'comment_author_email' => 'reader@example.com',
			)
		);

		$this->assertSame( array( 'comment', 'gap' ), array_column( undergrowth_prompt_candidates(), 'source' ) );
	}

	public function test_request_describes_a_gap_and_sends_only_the_authors_words() {
		$this->gap();

		undergrowth_write_prompt();

		$data = $this->sent_data( 0 );
		$this->assertStringContainsString( 'no post sits at its centre', $data['source'] );
		$this->assertArrayNotHasKey( 'reader_text', $data );
		$this->assertCount( 3, $data['author_sentences'] );
	}

	public function test_rules_say_a_gap_question_never_names_a_topic() {
		$this->assertStringContainsString( 'For a gap, never name a topic', undergrowth_prompt_system() );
	}

	public function test_question_quoting_two_of_the_posts_is_kept() {
		list( $oca ) = $this->gap();

		$this->assertTrue( undergrowth_write_prompt() );
		$this->assertSame( self::QUESTION, undergrowth_current_prompt()['question'] );
		$this->assertSame( $oca, undergrowth_current_prompt()['post_id'] );
	}

	public function test_question_quoting_anything_else_is_thrown_away() {
		$this->gap();
		$this->reply = 'You wrote "tubers are easy" there. Are they?';

		$this->assertFalse( undergrowth_write_prompt() );
	}

	public function test_dismissed_gap_never_comes_back_while_the_cluster_stays() {
		$this->gap();
		undergrowth_write_prompt();
		undergrowth_dismiss_prompt( undergrowth_current_prompt()['key'] );

		do_action( 'undergrowth_prompt_cycle' );
		do_action( 'undergrowth_prompt_cycle' );

		$this->assertNull( undergrowth_current_prompt() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_gap_prompt_is_logged_for_the_post_nearest_the_centre() {
		list( $oca ) = $this->gap();

		undergrowth_write_prompt();

		$this->assertSame( array( 'asked' ), array_column( undergrowth_prompt_log( $oca ), 'event' ) );
		$this->assertStringContainsString( ': asked (gap) "', (string) file_get_contents( undergrowth_memory_path( $oca ) ) );
	}

	public function test_widget_says_the_question_comes_from_a_gap() {
		$this->gap();
		undergrowth_write_prompt();

		ob_start();
		undergrowth_render_prompt_widget();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'gap among your posts', $html );
		$this->assertStringNotContainsString( 'reader', $html );
	}

	public function test_gap_with_no_sentence_of_the_authors_own_is_not_a_source() {
		$quote = '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Someone else said this.</p></blockquote><!-- /wp:quote -->';
		$oca   = $this->member( 'Oca', $quote, array( 1.0, 0.0, 0.0 ) );
		$this->member( 'Yacon', $quote, array( 0.0, 1.0, 0.0 ), $oca );
		$this->member( 'Mashua', $quote, array( 0.0, 0.0, 1.0 ), $oca );

		$this->assertSame( array(), undergrowth_prompt_candidates() );
	}

	public function test_gap_key_stays_when_a_member_is_embedded_again_with_another_model() {
		list( $oca ) = $this->gap();
		$ulluco      = $this->member( 'Ulluco', '<p>Ulluco is the brightest of them.</p>', array( -0.5, 0.5, 0.5 ), $oca );
		$key         = undergrowth_prompt_candidates()[0]['key'];

		undergrowth_save_vector(
			$ulluco,
			array( 0.3, 0.3, 0.3 ),
			array(
				'provider'     => 'test-provider',
				'model'        => 'other-model',
				'language'     => 'en_US',
				'content_hash' => 'hash',
			)
		);

		$this->assertSame( $key, undergrowth_prompt_candidates()[0]['key'] );
	}

	public function test_dismissed_gap_stays_dismissed_when_another_post_becomes_nearest() {
		add_filter(
			'undergrowth_gap_similarity',
			static function () {
				return 0.99;
			}
		);
		list( $oca, $yacon ) = $this->gap();
		undergrowth_write_prompt();
		undergrowth_dismiss_prompt( undergrowth_current_prompt()['key'] );

		undergrowth_save_vector(
			$yacon,
			array( 0.5, 0.7, 0.5 ),
			array(
				'provider'     => 'test-provider',
				'model'        => 'test-model',
				'language'     => 'en_US',
				'content_hash' => 'hash',
			)
		);

		$this->assertSame( $yacon, undergrowth_cluster_gaps()[0]['members'][0] );
		$this->assertSame( array(), undergrowth_prompt_candidates() );
	}

	public function test_gap_quotes_the_nearest_posts_that_have_sentences_of_their_own() {
		add_filter(
			'undergrowth_gap_similarity',
			static function () {
				return 0.99;
			}
		);

		// The quoted post sits nearest the centre, then Ulluco, then the others.
		$quoted = $this->member( 'Quoted', '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Someone else said this.</p></blockquote><!-- /wp:quote -->', array( 0.3, 0.7, 0.7 ) );
		$this->member( 'Yacon', '<p>Yacon needs a long season.</p>', array( 0.0, 1.0, 0.0 ), $quoted );
		$this->member( 'Mashua', '<p>Mashua climbs whatever is near it.</p>', array( 0.0, 0.0, 1.0 ), $quoted );
		$this->member( 'Ulluco', '<p>Ulluco is the brightest of them.</p>', array( -0.5, 0.5, 0.5 ), $quoted );

		$this->assertSame(
			array( 'Ulluco is the brightest of them.', 'Yacon needs a long season.', 'Mashua climbs whatever is near it.' ),
			undergrowth_prompt_candidates()[0]['quotes']
		);
	}
}
