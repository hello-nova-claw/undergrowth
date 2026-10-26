<?php
/**
 * Tests for the stored list of questions and the move from the options used
 * before the Prompt studio.
 *
 * @package Undergrowth
 */

/**
 * Many questions per site, each new, saved or dismissed.
 */
class Test_Prompt_Store extends WP_UnitTestCase {

	/**
	 * Published post the questions are about.
	 *
	 * @var int
	 */
	private $post_id;

	public function set_up() {
		parent::set_up();

		update_option( 'undergrowth_anthropic_api_key', 'sk-ant-test-0123456789abcd' );
		delete_option( 'undergrowth_prompts' );
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
	}

	/**
	 * Builds a question as the options before the Prompt studio held it.
	 *
	 * @param string $key Key of the question.
	 * @return array<string, mixed>
	 */
	private function legacy( string $key ): array {
		return array(
			'key'      => $key,
			'source'   => 'comment',
			'post_id'  => $this->post_id,
			'question' => 'What do you do with ' . $key . '?',
			'time'     => 1767225600,
		);
	}

	public function test_the_waiting_question_survives_the_upgrade() {
		update_option( 'undergrowth_prompt', $this->legacy( 'comment:1' ), false );

		$current = undergrowth_current_prompt();

		$this->assertSame( 'comment:1', $current['key'] );
		$this->assertSame( 'What do you do with comment:1?', $current['question'] );
		$this->assertSame( array( $this->post_id ), $current['posts'] );
		$this->assertSame( 'new', $current['status'] );
		$this->assertSame( 1767225600, $current['time'] );
		$this->assertFalse( get_option( 'undergrowth_prompt' ) );
		$this->assertCount( 1, get_option( 'undergrowth_prompts' ) );
	}

	public function test_snoozed_questions_join_the_batch_after_the_waiting_one() {
		update_option( 'undergrowth_prompt', $this->legacy( 'comment:1' ), false );
		update_option( 'undergrowth_snoozed_prompts', array( $this->legacy( 'comment:2' ), 'not a prompt', $this->legacy( 'comment:1' ), $this->legacy( 'comment:3' ) ), false );

		$this->assertSame( array( 'comment:1', 'comment:2', 'comment:3' ), array_column( undergrowth_prompts( 'new' ), 'key' ) );
		$this->assertFalse( get_option( 'undergrowth_snoozed_prompts' ) );
	}

	public function test_the_question_dismissed_last_keeps_its_undo() {
		update_option( 'undergrowth_dismissed_prompt', $this->legacy( 'comment:4' ), false );

		$this->assertNull( undergrowth_current_prompt() );
		$this->assertTrue( undergrowth_restore_prompt( 'comment:4' ) );
		$this->assertSame( 'comment:4', undergrowth_current_prompt()['key'] );
		$this->assertFalse( get_option( 'undergrowth_dismissed_prompt' ) );
	}

	public function test_an_unreadable_legacy_question_is_dropped() {
		update_option( 'undergrowth_prompt', array( 'key' => 'comment:1' ), false );

		$this->assertSame( array(), undergrowth_stored_prompts() );
		$this->assertSame( array(), get_option( 'undergrowth_prompts' ) );
		$this->assertFalse( get_option( 'undergrowth_prompt' ) );
	}

	public function test_a_site_that_never_had_a_question_stores_nothing() {
		$this->assertSame( array(), undergrowth_stored_prompts() );
		$this->assertFalse( get_option( 'undergrowth_prompts' ) );
	}

	public function test_stored_questions_of_the_wrong_shape_are_skipped() {
		update_option(
			'undergrowth_prompts',
			array(
				'not a question',
				array_merge( $this->legacy( 'comment:1' ), array( 'status' => 'snoozed' ) ),
				array_merge( $this->legacy( 'comment:2' ), array( 'post_id' => 0 ) ),
				array_merge( $this->legacy( '' ) ),
				array_merge( $this->legacy( 'comment:3' ), array( 'status' => 'saved' ) ),
			)
		);

		$this->assertSame( array( 'comment:3' ), array_column( undergrowth_stored_prompts(), 'key' ) );
	}

	public function test_only_the_latest_dismissed_questions_are_kept() {
		$prompts = array();

		for ( $i = 1; $i <= UNDERGROWTH_PROMPT_KEEP_DISMISSED + 2; $i++ ) {
			$prompts[] = array_merge( $this->legacy( 'comment:' . $i ), array( 'status' => 'new' ) );
		}

		update_option( 'undergrowth_prompts', $prompts );

		foreach ( $prompts as $prompt ) {
			undergrowth_dismiss_prompt( $prompt['key'] );
		}

		$dismissed = undergrowth_prompts( 'dismissed' );

		$this->assertCount( UNDERGROWTH_PROMPT_KEEP_DISMISSED, $dismissed );
		$this->assertSame( 'comment:3', $dismissed[0]['key'] );
		$this->assertFalse( undergrowth_restore_prompt( 'comment:1' ) );
	}

	public function test_a_question_about_a_post_that_left_the_index_is_kept_but_not_shown() {
		update_option( 'undergrowth_prompts', array( array_merge( $this->legacy( 'comment:1' ), array( 'status' => 'saved' ) ) ) );
		wp_update_post(
			array(
				'ID'          => $this->post_id,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( array(), undergrowth_prompts( 'saved' ) );
		$this->assertNull( undergrowth_find_prompt( 'comment:1' ) );
		$this->assertFalse( undergrowth_remove_saved_prompt( 'comment:1' ) );
		$this->assertCount( 1, undergrowth_stored_prompts() );
	}

	public function test_nothing_is_shown_without_a_key() {
		update_option( 'undergrowth_prompts', array( array_merge( $this->legacy( 'comment:1' ), array( 'status' => 'new' ) ) ) );
		update_option( 'undergrowth_anthropic_api_key', '' );

		$this->assertSame( array(), undergrowth_prompts( 'new' ) );
		$this->assertTrue( undergrowth_has_written_prompt() );
	}
}
