<?php
/**
 * Tests for the one lock every write to the stored question list takes.
 *
 * @package Undergrowth
 */

/**
 * Two writers that land at once never undo each other's change.
 */
class Test_Prompt_Lock extends WP_UnitTestCase {

	/**
	 * Published post the questions are about.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Whether the prompt lock was held during each Messages API request.
	 *
	 * @var bool[]
	 */
	private $locked = array();

	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$this->locked = array();
		update_option( 'undergrowth_anthropic_api_key', 'sk-ant-test-0123456789abcd' );
		delete_option( 'undergrowth_prompts' );
		WP_Upgrader::release_lock( 'undergrowth_prompts' );
		$this->post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Oca grows well here.',
			)
		);

		update_option(
			'undergrowth_prompts',
			array(
				$this->prompt( 'comment:1' ),
				$this->prompt( 'comment:2' ),
			),
			false
		);
	}

	public function tear_down() {
		WP_Upgrader::release_lock( 'undergrowth_prompts' );
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Builds a new stored question.
	 *
	 * @param string $key Key of the question.
	 * @return array<string, mixed>
	 */
	private function prompt( string $key ): array {
		return array(
			'key'      => $key,
			'source'   => 'comment',
			'post_id'  => $this->post_id,
			'posts'    => array( $this->post_id ),
			'question' => 'What do you do with ' . $key . '?',
			'time'     => 1767225600,
			'status'   => 'new',
		);
	}

	/**
	 * Returns the status of each stored question, by key.
	 *
	 * @return array<string, string>
	 */
	private function statuses(): array {
		return array_column( undergrowth_stored_prompts(), 'status', 'key' );
	}

	/**
	 * Answers a Messages API request with a question, noting whether the
	 * prompt lock was held while it was sent.
	 *
	 * @return array<string, mixed>
	 */
	public function fake_http(): array {
		$this->locked[] = false !== get_option( 'undergrowth_prompts.lock' );

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode(
				array(
					'content'     => array(
						array(
							'type' => 'text',
							'text' => 'What else grows well beside the oca?',
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
	 * Builds the source record a test question is written from.
	 *
	 * @return array<string, mixed>
	 */
	private function record(): array {
		return array(
			'source'  => 'gap',
			'key'     => 'gap:' . $this->post_id,
			'post_id' => $this->post_id,
			'posts'   => array( $this->post_id ),
			'title'   => 'Oca',
			'quotes'  => array( 'Oca grows well here.' ),
			'data'    => 'Oca grows well here.',
		);
	}

	public function test_a_writer_that_finds_the_lock_taken_waits_and_keeps_both_changes() {
		// Another request is between its read and its write: it holds the
		// lock and has read the list as it was.
		$this->assertTrue( WP_Upgrader::create_lock( 'undergrowth_prompts', 10 ) );
		$stale       = get_option( 'undergrowth_prompts' );
		$stale[0]    = array_merge( $stale[0], array( 'status' => 'saved' ) );
		$other_wrote = false;

		// It finishes its write while this one waits for the lock.
		add_filter(
			'option_undergrowth_prompts.lock',
			static function ( $value ) use ( $stale, &$other_wrote ) {
				if ( ! $other_wrote ) {
					$other_wrote = true;
					update_option( 'undergrowth_prompts', $stale, false );
					WP_Upgrader::release_lock( 'undergrowth_prompts' );
				}

				return $value;
			}
		);

		$this->assertTrue( undergrowth_dismiss_prompt( 'comment:2' ) );
		$this->assertTrue( $other_wrote );
		$this->assertSame(
			array(
				'comment:1' => 'saved',
				'comment:2' => 'dismissed',
			),
			$this->statuses()
		);
		$this->assertFalse( get_option( 'undergrowth_prompts.lock' ) );
	}

	public function test_a_writer_reads_the_list_afresh_once_it_has_the_lock() {
		global $wpdb;

		// This request read the list into its cache before another request
		// stored a change straight to the database.
		$this->assertNotNull( undergrowth_find_prompt( 'comment:2' ) );
		$changed              = get_option( 'undergrowth_prompts' );
		$changed[0]['status'] = 'saved';
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $changed ) ), array( 'option_name' => 'undergrowth_prompts' ) );

		$this->assertTrue( undergrowth_dismiss_prompt( 'comment:2' ) );
		$this->assertSame(
			array(
				'comment:1' => 'saved',
				'comment:2' => 'dismissed',
			),
			$this->statuses()
		);
	}

	public function test_a_writer_that_lands_inside_another_write_changes_nothing_and_says_so() {
		$inner  = null;
		$landed = false;

		// The author's click lands while the first write is being stored.
		add_filter(
			'pre_update_option_undergrowth_prompts',
			static function ( $value ) use ( &$inner, &$landed ) {
				if ( ! $landed ) {
					$landed = true;
					$inner  = undergrowth_save_prompt( 'comment:2' );
				}

				return $value;
			}
		);

		$this->assertTrue( undergrowth_dismiss_prompt( 'comment:1' ) );
		$this->assertFalse( $inner );
		$this->assertSame(
			array(
				'comment:2' => 'new',
				'comment:1' => 'dismissed',
			),
			$this->statuses()
		);

		// Tried again once the lock is free, it goes through.
		$this->assertTrue( undergrowth_save_prompt( 'comment:2' ) );
		$this->assertSame( 'saved', $this->statuses()['comment:2'] );
	}

	public function test_a_move_finds_the_question_already_moved_by_another_writer() {
		// The other writer saved it between this writer's first look and
		// its turn with the lock.
		WP_Upgrader::create_lock( 'undergrowth_prompts', 10 );
		$other_wrote = false;
		add_filter(
			'option_undergrowth_prompts.lock',
			static function ( $value ) use ( &$other_wrote ) {
				if ( ! $other_wrote ) {
					$other_wrote          = true;
					$prompts              = get_option( 'undergrowth_prompts' );
					$prompts[1]['status'] = 'saved';
					update_option( 'undergrowth_prompts', $prompts, false );
					WP_Upgrader::release_lock( 'undergrowth_prompts' );
				}

				return $value;
			}
		);

		$this->assertFalse( undergrowth_dismiss_prompt( 'comment:2' ) );
		$this->assertSame( 'saved', $this->statuses()['comment:2'] );
		$this->assertSame( array(), undergrowth_prompt_log( $this->post_id ) );
	}

	public function test_a_lock_held_too_long_leaves_the_list_as_it_was() {
		WP_Upgrader::create_lock( 'undergrowth_prompts', 10 );

		$this->assertFalse( undergrowth_dismiss_prompt( 'comment:1' ) );
		$this->assertSame(
			array(
				'comment:1' => 'new',
				'comment:2' => 'new',
			),
			$this->statuses()
		);
	}

	public function test_a_lock_left_behind_by_a_stopped_request_is_taken_over() {
		update_option( 'undergrowth_prompts.lock', time() - MINUTE_IN_SECONDS, false );

		$this->assertTrue( undergrowth_dismiss_prompt( 'comment:1' ) );
		$this->assertSame( 'dismissed', $this->statuses()['comment:1'] );
	}

	public function test_no_lock_is_held_while_claude_writes_a_question() {
		add_filter( 'pre_http_request', array( $this, 'fake_http' ) );
		$calls = 0;

		$this->assertSame( 'written', undergrowth_write_prompt_from( $this->record(), $calls ) );
		$this->assertSame( array( false ), $this->locked );
		$this->assertArrayHasKey( 'gap:' . $this->post_id, $this->statuses() );
	}

	public function test_a_written_question_that_cannot_be_stored_is_reported_and_its_source_kept() {
		add_filter( 'pre_http_request', array( $this, 'fake_http' ) );
		WP_Upgrader::create_lock( 'undergrowth_prompts', 10 );
		$calls = 0;

		$this->assertSame( 'failed', undergrowth_write_prompt_from( $this->record(), $calls ) );
		$this->assertArrayNotHasKey( 'gap:' . $this->post_id, $this->statuses() );
		// The source was not recorded as sent, so the next run tries it again.
		$this->assertArrayNotHasKey( 'gap:' . $this->post_id, undergrowth_sent_prompt_keys() );
	}

	public function test_a_question_the_database_did_not_store_is_reported_and_its_source_kept() {
		add_filter( 'pre_http_request', array( $this, 'fake_http' ) );
		add_filter(
			'pre_update_option_undergrowth_prompts',
			static function ( $value, $old ) {
				return $old;
			},
			10,
			2
		);
		$calls = 0;

		$this->assertSame( 'failed', undergrowth_write_prompt_from( $this->record(), $calls ) );
		$this->assertArrayNotHasKey( 'gap:' . $this->post_id, undergrowth_sent_prompt_keys() );
		$this->assertFalse( undergrowth_dismiss_prompt( 'comment:1' ) );
		$this->assertSame( array(), undergrowth_prompt_log( $this->post_id ) );
	}

	public function test_a_move_from_the_old_options_never_overwrites_a_stored_list() {
		update_option(
			'undergrowth_prompt',
			array(
				'key'      => 'comment:9',
				'source'   => 'comment',
				'post_id'  => $this->post_id,
				'question' => 'What do you do with comment:9?',
			),
			false
		);

		// This request found no list and began the move, but another
		// request stored one first.
		$this->assertSame( array( 'comment:1', 'comment:2' ), array_column( undergrowth_migrate_prompts(), 'key' ) );
		$this->assertSame( array( 'comment:1', 'comment:2' ), array_keys( $this->statuses() ) );
		$this->assertFalse( get_option( 'undergrowth_prompt' ) );
	}
}
