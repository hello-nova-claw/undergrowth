<?php
/**
 * Tests for the monthly email's one line about new questions.
 *
 * @package Undergrowth
 */

/**
 * The email says once that new questions are waiting in the Prompt studio,
 * only while some are new to the author.
 */
class Test_Digest_Questions extends WP_UnitTestCase {

	/**
	 * Emails sent, as wp_mail() was given them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $sent = array();

	public function set_up() {
		parent::set_up();

		$this->sent = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
		update_option( 'undergrowth_anthropic_api_key', 'sk-ant-test-0123456789abcd' );
		delete_option( 'undergrowth_prompts' );
	}

	/**
	 * Keeps each email instead of sending it.
	 *
	 * @param null|bool            $result Short-circuit value.
	 * @param array<string, mixed> $atts   The email.
	 * @return bool
	 */
	public function catch_mail( $result, $atts ) {
		$this->sent[] = $atts;

		return true;
	}

	/**
	 * Creates an editor who turned the email on a day ago, with one new
	 * suggestion waiting, so an email goes out.
	 *
	 * @return int User ID.
	 */
	private function subscriber(): int {
		global $wpdb;

		$user_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'author@example.org',
			)
		);
		undergrowth_turn_digest_on( $user_id );
		update_user_meta( $user_id, 'undergrowth_digest', (string) ( time() - DAY_IN_SECONDS ) );

		$now = current_time( 'mysql', true );

		$wpdb->insert(
			undergrowth_links_table(),
			array(
				'source_id'     => self::factory()->post->create( array( 'post_title' => 'Storing oca' ) ),
				'target_id'     => self::factory()->post->create( array( 'post_title' => 'Frost and tubers' ) ),
				'state'         => 'proposed',
				'score'         => 0.7,
				'sentence'      => 'Oca keeps well after the first frost.',
				'anchor'        => 'first frost',
				'anchor_offset' => 25,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);

		return $user_id;
	}

	/**
	 * Stores a question about a new post.
	 *
	 * @param int    $time   When it was written.
	 * @param string $status 'new', 'saved' or 'dismissed'.
	 */
	private function question( int $time, string $status = 'new' ): void {
		$prompts   = (array) get_option( 'undergrowth_prompts', array() );
		$prompts[] = array(
			'key'      => 'gap-' . count( $prompts ),
			'source'   => 'gap',
			'post_id'  => self::factory()->post->create( array( 'post_title' => 'Yacon' ) ),
			'posts'    => array(),
			'question' => 'What grows beside your yacon?',
			'time'     => $time,
			'status'   => $status,
		);

		update_option( 'undergrowth_prompts', $prompts, false );
	}

	/**
	 * Returns the one email sent.
	 *
	 * @return string Its message.
	 */
	private function message(): string {
		undergrowth_send_digests();

		$this->assertCount( 1, $this->sent );

		return (string) $this->sent[0]['message'];
	}

	public function test_the_email_says_new_questions_are_waiting() {
		$this->subscriber();
		$this->question( time() - HOUR_IN_SECONDS );

		$message = $this->message();

		$this->assertStringContainsString( 'New questions are waiting in your Prompt studio', $message );
		$this->assertStringContainsString( undergrowth_studio_url(), $message );
		$this->assertSame( 1, substr_count( $message, 'New questions are waiting' ) );
	}

	public function test_no_line_when_no_question_is_new() {
		$this->subscriber();
		$this->question( time() - HOUR_IN_SECONDS, 'saved' );
		$this->question( time() - HOUR_IN_SECONDS, 'dismissed' );

		$this->assertStringNotContainsString( 'Prompt studio', $this->message() );
	}

	public function test_a_question_already_mentioned_by_an_earlier_email_is_not_mentioned_again() {
		$user_id = $this->subscriber();
		$this->question( time() - 30 * DAY_IN_SECONDS );
		update_user_meta( $user_id, 'undergrowth_digest_sent', (string) ( time() - 25 * DAY_IN_SECONDS ) );

		$this->assertStringNotContainsString( 'Prompt studio', $this->message() );
	}

	public function test_no_line_once_the_author_has_opened_the_studio_since() {
		$user_id = $this->subscriber();
		$this->question( time() - HOUR_IN_SECONDS );

		wp_set_current_user( $user_id );
		do_action( 'load-posts_page_undergrowth-studio' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's hook for the screen.
		wp_set_current_user( 0 );

		$this->assertGreaterThanOrEqual( time() - 5, (int) get_user_meta( $user_id, 'undergrowth_studio_seen', true ) );
		$this->assertStringNotContainsString( 'Prompt studio', $this->message() );
	}

	public function test_no_line_while_questions_are_off() {
		$this->subscriber();
		$this->question( time() - HOUR_IN_SECONDS );
		delete_option( 'undergrowth_anthropic_api_key' );

		$this->assertStringNotContainsString( 'Prompt studio', $this->message() );
	}

	public function test_no_line_for_a_question_the_author_cannot_answer() {
		$this->subscriber();
		$this->question( time() - HOUR_IN_SECONDS );
		add_filter( 'map_meta_cap', array( $this, 'refuse_yacon' ), 10, 4 );

		$this->assertStringNotContainsString( 'Prompt studio', $this->message() );
	}

	/**
	 * Refuses editing the post the question is about.
	 *
	 * @param string[] $caps    Capabilities needed.
	 * @param string   $cap     Capability asked for.
	 * @param int      $user_id User ID.
	 * @param array    $args    Post ID first, for edit_post.
	 * @return string[]
	 */
	public function refuse_yacon( $caps, $cap, $user_id, $args ) {
		if ( 'edit_post' === $cap && isset( $args[0] ) && 'Yacon' === get_the_title( (int) $args[0] ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	public function test_no_email_for_an_author_who_has_not_opted_in() {
		self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->question( time() - HOUR_IN_SECONDS );

		undergrowth_send_digests();

		$this->assertSame( array(), $this->sent );
	}
}
