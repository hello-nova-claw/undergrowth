<?php
/**
 * Tests for embedding posts that were published before a provider was set up.
 *
 * @package Undergrowth
 */

/**
 * Backfilling the index from cron.
 */
class Test_Backfill extends WP_UnitTestCase {

	/**
	 * Texts the test provider was asked to embed.
	 *
	 * @var string[]
	 */
	private $embedded = array();

	/**
	 * Texts the test provider fails to embed.
	 *
	 * @var string[]
	 */
	private $failing = array();

	/**
	 * Model name the test provider reports.
	 *
	 * @var string
	 */
	private $model = 'test-model-v1';

	/**
	 * Seconds the test provider takes for each text.
	 *
	 * @var float
	 */
	private $delay = 0.0;

	public function set_up() {
		parent::set_up();

		$this->embedded = array();
		$this->failing  = array();
		$this->model    = 'test-model-v1';
		$this->delay    = 0.0;

		delete_option( 'undergrowth_openai_api_key' );
		delete_option( 'undergrowth_backfill' );
		delete_option( 'undergrowth_backfill.lock' );
		delete_option( 'undergrowth_index_identity' );
		delete_option( 'undergrowth_backfill_progress' );
		wp_unschedule_hook( 'undergrowth_backfill' );
		wp_unschedule_hook( 'undergrowth_backfill_check' );
		wp_unschedule_hook( 'undergrowth_cluster_cycle' );

		add_filter( 'undergrowth_embed_provider', array( $this, 'provider' ), 10, 2 );
		add_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );
	}

	/**
	 * Test provider: records the text and returns a fixed vector.
	 *
	 * @param float[]|null $vector Vector from an earlier callback.
	 * @param string       $text   Text to embed.
	 * @return float[]
	 */
	public function provider( $vector, $text ) {
		$this->embedded[] = $text;

		usleep( (int) ( $this->delay * 1000000 ) );

		return in_array( $text, $this->failing, true ) ? array() : array( 0.1, 0.2, 0.3 );
	}

	/**
	 * Test provider identity.
	 *
	 * @return array{provider: string, model: string}
	 */
	public function identity() {
		return array(
			'provider' => 'test-provider',
			'model'    => $this->model,
		);
	}

	/**
	 * Creates published posts that have no vector yet, as if they predate the plugin.
	 *
	 * @param int                  $count How many.
	 * @param array<string, mixed> $args  Post fields.
	 * @return int[] Post IDs.
	 */
	private function archive( int $count, array $args = array() ): array {
		$ids = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = self::factory()->post->create(
				array_merge(
					array(
						'post_title'   => "Old post {$i}",
						'post_content' => "Something I wrote before, part {$i}.",
						'post_status'  => 'publish',
					),
					$args
				)
			);
		}

		// Saving schedules an embed per post. Those are not what is tested here.
		wp_unschedule_hook( 'undergrowth_embed_post' );

		return $ids;
	}

	/**
	 * Runs the backfill as cron would: one event per request, until none is left.
	 *
	 * @return int[] How many texts were sent in each run.
	 */
	private function run_cron(): array {
		$runs = array();

		while ( false !== wp_next_scheduled( 'undergrowth_backfill' ) ) {
			// A pass that never ends fails here rather than hanging the suite.
			$this->assertLessThan( 100, count( $runs ) );
			wp_unschedule_hook( 'undergrowth_backfill' );
			$before = count( $this->embedded );
			do_action( 'undergrowth_backfill' );
			$runs[] = count( $this->embedded ) - $before;
		}

		return $runs;
	}

	/**
	 * Sets the batch size through its filter.
	 *
	 * @param int $size Posts per batch.
	 */
	private function batch_size( int $size ): void {
		add_filter(
			'undergrowth_backfill_batch_size',
			static function () use ( $size ) {
				return $size;
			}
		);
	}

	/**
	 * Sets the time budget through its filter.
	 *
	 * @param float $seconds Seconds per run.
	 */
	private function time_budget( float $seconds ): void {
		add_filter(
			'undergrowth_backfill_time_budget',
			static function () use ( $seconds ) {
				return $seconds;
			}
		);
	}

	public function test_backfill_embeds_published_posts_without_a_vector() {
		$ids = $this->archive( 3 );

		undergrowth_start_backfill();
		$this->run_cron();

		foreach ( $ids as $id ) {
			$this->assertSame( 'test-model-v1', undergrowth_get_vector( $id )['model'] );
		}
	}

	public function test_each_run_embeds_at_most_one_batch() {
		$this->batch_size( 2 );
		$ids = $this->archive( 5 );

		undergrowth_start_backfill();
		$runs = $this->run_cron();

		$this->assertSame( array( 2, 2, 1 ), $runs );
		$this->assertSame( 5, undergrowth_index_counts()['indexed'] );
		$this->assertNotNull( undergrowth_get_vector( end( $ids ) ) );
	}

	public function test_full_batch_schedules_the_next_one_instead_of_running_it() {
		$this->batch_size( 2 );
		$this->archive( 3 );

		do_action( 'undergrowth_backfill' );

		$this->assertCount( 2, $this->embedded );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_backfill' ) );
	}

	public function test_batch_size_defaults_to_twenty() {
		$this->assertSame( 20, undergrowth_backfill_batch_size() );
	}

	public function test_batch_size_below_one_falls_back_to_one() {
		$this->batch_size( 0 );

		$this->assertSame( 1, undergrowth_backfill_batch_size() );
	}

	public function test_time_budget_defaults_to_fifteen_seconds() {
		$this->assertSame( 15.0, undergrowth_backfill_time_budget() );
	}

	public function test_time_budget_below_zero_falls_back_to_zero() {
		$this->time_budget( -5 );

		$this->assertSame( 0.0, undergrowth_backfill_time_budget() );
	}

	public function test_zero_budget_embeds_one_post_per_run_and_schedules_the_next() {
		$this->time_budget( 0 );
		$ids = $this->archive( 3 );

		do_action( 'undergrowth_backfill' );

		$this->assertCount( 1, $this->embedded );
		$this->assertNotNull( undergrowth_get_vector( $ids[0] ) );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_backfill' ) );
	}

	public function test_run_never_starts_a_post_once_its_budget_is_spent() {
		$this->time_budget( 0.1 );
		$this->delay = 0.2;
		$this->archive( 3 );

		do_action( 'undergrowth_backfill' );

		$this->assertCount( 1, $this->embedded );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_backfill' ) );
	}

	public function test_run_within_its_budget_embeds_the_whole_batch() {
		$this->batch_size( 3 );
		$this->archive( 5 );

		do_action( 'undergrowth_backfill' );

		$this->assertCount( 3, $this->embedded );
	}

	public function test_pass_cut_short_by_the_budget_finishes_on_later_runs() {
		$this->time_budget( 0 );
		$ids = $this->archive( 3 );

		undergrowth_start_backfill();

		$this->assertSame( array( 1, 1, 1 ), $this->run_cron() );
		$this->assertSame( 3, undergrowth_index_counts()['indexed'] );
		$this->assertNotNull( undergrowth_get_vector( end( $ids ) ) );
		$this->assertFalse( get_option( 'undergrowth_backfill' ) );
	}

	public function test_two_hundred_old_posts_reach_openai_over_cron_runs() {
		remove_filter( 'undergrowth_embed_provider', array( $this, 'provider' ) );
		remove_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );
		update_option( 'undergrowth_openai_api_key', 'sk-test-0123456789abcd' );
		$ids = $this->archive( 200 );

		// Two hundred identical vectors would each propose links to all the
		// others, which other tests cover and which would take minutes here.
		remove_all_actions( 'undergrowth_post_embedded' );

		$sent = array();
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$sent ) {
				$sent[] = json_decode( $args['body'], true )['input'];

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'data' => array( array( 'embedding' => array( 0.5, 0.5 ) ) ) ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			2
		);

		undergrowth_start_backfill();

		$runs = 0;
		while ( false !== wp_next_scheduled( 'undergrowth_backfill' ) && $runs < 100 ) {
			wp_unschedule_hook( 'undergrowth_backfill' );
			$before = count( $sent );
			do_action( 'undergrowth_backfill' );
			$this->assertLessThanOrEqual( 20, count( $sent ) - $before );
			++$runs;
		}

		// Ten full batches, then a run that finds nothing left and ends the pass.
		$this->assertSame( 11, $runs );
		$this->assertCount( 200, $sent );
		$this->assertSame(
			array(
				'indexed' => 200,
				'total'   => 200,
			),
			undergrowth_index_counts()
		);
		$this->assertSame( 'openai', undergrowth_get_vector( $ids[199] )['provider'] );
	}

	/**
	 * Sends the backfill to OpenAI, which answers every request with one status.
	 *
	 * @param int    $status      HTTP status code.
	 * @param string $retry_after Retry-After header, or empty for none.
	 * @return array<int, string> Reference to the texts sent, filled as requests go out.
	 */
	private function &openai_answers( int $status, string $retry_after = '' ): array {
		remove_filter( 'undergrowth_embed_provider', array( $this, 'provider' ) );
		remove_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );
		update_option( 'undergrowth_openai_api_key', 'sk-test-0123456789abcd' );
		delete_option( 'undergrowth_last_embed_error' );
		delete_option( 'undergrowth_embed_retry_at' );

		// Saving the key queued a run. Each test runs or queues its own.
		wp_unschedule_hook( 'undergrowth_backfill' );

		$sent = array();
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$sent, $status, $retry_after ) {
				$sent[] = json_decode( $args['body'], true )['input'];

				return array(
					'headers'  => '' !== $retry_after ? array( 'retry-after' => $retry_after ) : array(),
					'body'     => 200 === $status ? wp_json_encode( array( 'data' => array( array( 'embedding' => array( 0.5, 0.5 ) ) ) ) ) : '{}',
					'response' => array(
						'code'    => $status,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			2
		);

		return $sent;
	}

	public function test_too_many_requests_stops_the_batch_and_waits() {
		$this->archive( 3 );
		$sent   = &$this->openai_answers( 429, '120' );
		$before = time();

		do_action( 'undergrowth_backfill' );

		$this->assertCount( 1, $sent );
		$this->assertGreaterThanOrEqual( $before + 120, wp_next_scheduled( 'undergrowth_backfill' ) );
	}

	public function test_server_error_stops_the_batch_and_waits_five_minutes() {
		$this->archive( 3 );
		$sent   = &$this->openai_answers( 500 );
		$before = time();

		do_action( 'undergrowth_backfill' );

		$this->assertCount( 1, $sent );
		$this->assertGreaterThanOrEqual( $before + 5 * MINUTE_IN_SECONDS, wp_next_scheduled( 'undergrowth_backfill' ) );
	}

	public function test_post_that_was_turned_away_is_sent_first_after_the_wait() {
		$ids = $this->archive( 3 );
		$this->openai_answers( 429, '120' );

		do_action( 'undergrowth_backfill' );

		// The wait is over, and OpenAI answers again.
		update_option( 'undergrowth_embed_retry_at', time() - 1 );
		remove_all_filters( 'pre_http_request' );
		$sent = &$this->openai_answers( 200 );

		do_action( 'undergrowth_backfill' );

		$this->assertSame( undergrowth_post_text( get_post( $ids[0] ) ), $sent[0] );
		$this->assertSame( 3, undergrowth_index_counts()['indexed'] );
	}

	public function test_run_while_waiting_sends_nothing_and_waits_for_the_retry_time() {
		$this->archive( 1 );
		$sent  = &$this->openai_answers( 200 );
		$retry = time() + 120;
		update_option( 'undergrowth_embed_retry_at', $retry );

		do_action( 'undergrowth_backfill' );

		$this->assertCount( 0, $sent );
		$this->assertSame( $retry, wp_next_scheduled( 'undergrowth_backfill' ) );
	}

	public function test_check_while_waiting_queues_the_run_for_the_retry_time() {
		$this->archive( 1 );
		$this->openai_answers( 200 );
		$retry = time() + 120;
		update_option( 'undergrowth_embed_retry_at', $retry );

		do_action( 'undergrowth_backfill_check' );

		$this->assertSame( $retry, wp_next_scheduled( 'undergrowth_backfill' ) );
	}

	public function test_code_that_reads_the_posts_never_waits_for_openai() {
		$this->archive( 1 );
		update_option( 'undergrowth_embed_retry_at', time() + 120 );

		do_action( 'undergrowth_backfill' );

		$this->assertCount( 1, $this->embedded );
	}

	public function test_rejected_request_moves_on_to_the_next_post() {
		$this->archive( 3 );
		$sent = &$this->openai_answers( 400, '120' );

		do_action( 'undergrowth_backfill' );

		// Each post is sent twice, the second time cut shorter.
		$this->assertCount( 6, $sent );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_backfill' ) );
	}

	public function test_posts_that_must_not_be_sent_are_skipped() {
		register_post_type( 'recipe', array( 'public' => true ) );
		$this->archive( 1, array( 'post_status' => 'draft' ) );
		$this->archive( 1, array( 'post_password' => 'secret' ) );
		$this->archive( 1, array( 'post_type' => 'page' ) );
		$this->archive( 1, array( 'post_type' => 'recipe' ) );
		$this->archive( 1, array( 'post_status' => 'private' ) );
		$this->archive(
			1,
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);

		undergrowth_start_backfill();
		$this->run_cron();

		$this->assertSame( array(), $this->embedded );
		unregister_post_type( 'recipe' );
	}

	public function test_filtered_post_types_are_backfilled() {
		register_post_type( 'recipe', array( 'public' => true ) );
		add_filter(
			'undergrowth_post_types',
			static function () {
				return array( 'post', 'recipe' );
			}
		);
		$recipe = $this->archive( 1, array( 'post_type' => 'recipe' ) )[0];

		undergrowth_start_backfill();
		$this->run_cron();

		$this->assertNotNull( undergrowth_get_vector( $recipe ) );
		unregister_post_type( 'recipe' );
	}

	public function test_posts_already_indexed_are_not_sent_again() {
		$ids = $this->archive( 2 );
		undergrowth_embed_post( $ids[0] );
		$this->embedded = array();

		do_action( 'undergrowth_backfill' );

		$this->assertSame( array( undergrowth_post_text( get_post( $ids[1] ) ) ), $this->embedded );
	}

	public function test_failed_embed_is_tried_again_on_a_later_run() {
		$ids             = $this->archive( 2 );
		$this->failing[] = undergrowth_post_text( get_post( $ids[0] ) );

		undergrowth_start_backfill();
		$this->run_cron();

		$this->assertNull( undergrowth_get_vector( $ids[0] ) );
		$this->assertNotNull( undergrowth_get_vector( $ids[1] ) );

		$this->failing = array();
		do_action( 'undergrowth_backfill_check' );
		$this->run_cron();

		$this->assertNotNull( undergrowth_get_vector( $ids[0] ) );
	}

	public function test_failure_does_not_stop_the_pass_at_that_post() {
		$this->batch_size( 1 );
		$ids             = $this->archive( 3 );
		$this->failing[] = undergrowth_post_text( get_post( $ids[0] ) );

		undergrowth_start_backfill();
		$this->run_cron();

		$this->assertNotNull( undergrowth_get_vector( $ids[2] ) );
	}

	public function test_run_does_nothing_while_another_run_holds_the_lock() {
		$this->archive( 1 );
		update_option( 'undergrowth_backfill.lock', time(), false );

		do_action( 'undergrowth_backfill' );

		$this->assertSame( array(), $this->embedded );
	}

	public function test_run_takes_over_a_lock_left_by_a_run_that_died() {
		$this->archive( 1 );
		update_option( 'undergrowth_backfill.lock', time() - HOUR_IN_SECONDS, false );

		do_action( 'undergrowth_backfill' );

		$this->assertCount( 1, $this->embedded );
	}

	public function test_run_releases_its_lock() {
		$this->batch_size( 1 );
		$this->archive( 2 );

		do_action( 'undergrowth_backfill' );
		$this->assertFalse( get_option( 'undergrowth_backfill.lock' ) );

		$this->run_cron();
		$this->assertFalse( get_option( 'undergrowth_backfill.lock' ) );
	}

	public function test_pass_starts_over_when_the_model_changes_mid_pass() {
		$this->batch_size( 2 );
		$ids = $this->archive( 4 );

		undergrowth_start_backfill();
		wp_unschedule_hook( 'undergrowth_backfill' );
		do_action( 'undergrowth_backfill' );

		$this->model = 'test-model-v2';
		$this->run_cron();

		foreach ( $ids as $id ) {
			$this->assertSame( 'test-model-v2', undergrowth_get_vector( $id )['model'] );
		}
	}

	public function test_check_starts_a_backfill_when_a_post_has_no_vector() {
		$this->archive( 1 );

		do_action( 'undergrowth_backfill_check' );

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_backfill' ) );
	}

	public function test_check_does_nothing_when_every_post_is_indexed() {
		$ids = $this->archive( 1 );
		undergrowth_embed_post( $ids[0] );

		do_action( 'undergrowth_backfill_check' );

		$this->assertFalse( wp_next_scheduled( 'undergrowth_backfill' ) );
	}

	public function test_check_is_scheduled_hourly() {
		undergrowth_schedule_backfill_check();

		$this->assertSame( 'hourly', wp_get_schedule( 'undergrowth_backfill_check' ) );
	}

	public function test_check_is_scheduled_on_init() {
		$this->assertNotFalse( has_action( 'init', 'undergrowth_schedule_backfill_check' ) );
	}

	public function test_nothing_is_queued_without_a_provider() {
		remove_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );
		$this->archive( 1 );

		undergrowth_start_backfill();
		do_action( 'undergrowth_backfill' );

		$this->assertFalse( wp_next_scheduled( 'undergrowth_backfill' ) );
		$this->assertSame( array(), $this->embedded );
	}

	public function test_starting_twice_queues_one_run() {
		$this->archive( 1 );

		undergrowth_start_backfill();
		undergrowth_start_backfill();

		$this->assertCount( 1, array_filter( _get_cron_array(), static fn( $hooks ) => isset( $hooks['undergrowth_backfill'] ) ) );
	}

	public function test_activation_starts_the_backfill() {
		$this->archive( 1 );

		undergrowth_activate();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_backfill' ) );
	}

	public function test_saving_an_openai_key_starts_the_backfill() {
		remove_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );
		$this->archive( 1 );

		update_option( 'undergrowth_openai_api_key', 'sk-test-0123456789abcd' );

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_backfill' ) );
	}

	public function test_replacing_an_openai_key_starts_the_backfill() {
		remove_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );
		update_option( 'undergrowth_openai_api_key', 'sk-test-old-key-000000' );
		$this->archive( 1 );
		wp_unschedule_hook( 'undergrowth_backfill' );

		update_option( 'undergrowth_openai_api_key', 'sk-test-0123456789abcd' );

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_backfill' ) );
	}

	public function test_first_finished_pass_clusters_the_new_index() {
		$this->archive( 2 );

		undergrowth_start_backfill();
		$this->run_cron();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', array( 0 ) ) );
	}

	public function test_later_passes_leave_clustering_to_the_monthly_cycle() {
		$this->archive( 2 );
		undergrowth_start_backfill();
		$this->run_cron();
		wp_unschedule_hook( 'undergrowth_cluster_cycle' );

		$this->archive( 1 );
		do_action( 'undergrowth_backfill_check' );
		$this->run_cron();

		$this->assertFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', array( 0 ) ) );
	}

	public function test_pass_where_most_embeds_failed_leaves_the_index_unclustered() {
		$ids = $this->archive( 4 );

		foreach ( array_slice( $ids, 0, 3 ) as $id ) {
			$this->failing[] = undergrowth_post_text( get_post( $id ) );
		}

		undergrowth_start_backfill();
		$this->run_cron();

		$this->assertFalse( get_option( 'undergrowth_index_identity' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', array( 0 ) ) );
	}

	public function test_check_clusters_the_index_once_the_failed_posts_are_embedded() {
		$ids = $this->archive( 4 );

		foreach ( array_slice( $ids, 0, 2 ) as $id ) {
			$this->failing[] = undergrowth_post_text( get_post( $id ) );
		}

		undergrowth_start_backfill();
		$this->run_cron();

		$this->failing = array();
		do_action( 'undergrowth_backfill_check' );
		$this->run_cron();

		$this->assertSame( undergrowth_index_target(), get_option( 'undergrowth_index_identity' ) );
		$this->assertCount( 1, $this->cluster_cycles() );
	}

	public function test_check_clusters_an_index_filled_outside_the_backfill() {
		$ids             = $this->archive( 2 );
		$this->failing[] = undergrowth_post_text( get_post( $ids[0] ) );
		undergrowth_start_backfill();
		$this->run_cron();

		// The post's own embed on save, rather than a backfill pass, fills the index.
		$this->failing = array();
		undergrowth_embed_post( $ids[0] );
		do_action( 'undergrowth_backfill_check' );

		$this->assertFalse( wp_next_scheduled( 'undergrowth_backfill' ) );
		$this->assertSame( undergrowth_index_target(), get_option( 'undergrowth_index_identity' ) );
		$this->assertCount( 1, $this->cluster_cycles() );
	}

	public function test_empty_index_is_not_recorded() {
		undergrowth_start_backfill();

		$this->assertFalse( get_option( 'undergrowth_index_identity' ) );
		$this->assertSame( array(), $this->cluster_cycles() );
	}

	public function test_first_post_on_an_empty_site_clusters_the_index() {
		undergrowth_start_backfill();
		// Any cycle queued for the empty index has run by the time a post is published.
		wp_unschedule_hook( 'undergrowth_cluster_cycle' );

		$this->archive( 1 );
		do_action( 'undergrowth_backfill_check' );
		$this->run_cron();

		$this->assertSame( undergrowth_index_target(), get_option( 'undergrowth_index_identity' ) );
		$this->assertCount( 1, $this->cluster_cycles() );
	}

	public function test_identity_that_is_no_longer_the_target_is_not_recorded() {
		$ids = $this->archive( 1 );
		undergrowth_embed_post( $ids[0] );
		$stale = array_merge( undergrowth_index_target(), array( 'model' => 'test-model-v0' ) );

		// A batch that read the old model finishes after the switch, when every post has a current vector.
		undergrowth_record_index_identity( $stale );

		$this->assertFalse( get_option( 'undergrowth_index_identity' ) );
		$this->assertSame( array(), $this->cluster_cycles() );
	}

	public function test_check_does_not_cluster_a_recorded_index_again() {
		$this->indexed_archive( 2 );

		do_action( 'undergrowth_backfill_check' );

		$this->assertSame( array(), $this->cluster_cycles() );
	}

	/**
	 * Lists the queued cluster cycles.
	 *
	 * @return array<int, mixed> One entry per queued event.
	 */
	private function cluster_cycles(): array {
		$cycles = array();

		foreach ( _get_cron_array() as $hooks ) {
			foreach ( $hooks['undergrowth_cluster_cycle'] ?? array() as $event ) {
				$cycles[] = $event['args'];
			}
		}

		return $cycles;
	}

	public function test_index_counts_compare_current_vectors_with_indexable_posts() {
		$ids = $this->archive( 3 );
		$this->archive( 1, array( 'post_status' => 'draft' ) );
		undergrowth_embed_post( $ids[0] );

		$this->assertSame(
			array(
				'indexed' => 1,
				'total'   => 3,
			),
			undergrowth_index_counts()
		);
	}

	public function test_index_counts_leave_out_vectors_from_another_model() {
		$ids = $this->archive( 1 );
		undergrowth_save_vector(
			$ids[0],
			array( 1.0 ),
			array(
				'provider'     => 'test-provider',
				'model'        => 'test-model-v0',
				'language'     => 'en_US',
				'content_hash' => 'x',
			)
		);

		$this->assertSame( 0, undergrowth_index_counts()['indexed'] );
	}

	public function test_deactivation_stops_the_backfill() {
		$this->archive( 1 );
		undergrowth_start_backfill();
		undergrowth_schedule_backfill_check();
		update_option( 'undergrowth_backfill', array( 'after' => 5 ) );
		update_option( 'undergrowth_backfill.lock', time(), false );

		undergrowth_deactivate();

		$this->assertFalse( wp_next_scheduled( 'undergrowth_backfill' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_backfill_check' ) );
		$this->assertFalse( get_option( 'undergrowth_backfill' ) );
		$this->assertFalse( get_option( 'undergrowth_backfill.lock' ) );
	}

	public function test_deactivation_forgets_when_the_backfill_last_ran() {
		update_option( 'undergrowth_backfill_progress', array( 'time' => time() ), false );

		undergrowth_deactivate();

		$this->assertFalse( get_option( 'undergrowth_backfill_progress' ) );
	}

	public function test_run_records_when_it_ran_and_how_far_it_got() {
		$this->batch_size( 1 );
		$this->archive( 2 );

		do_action( 'undergrowth_backfill' );

		$progress = get_option( 'undergrowth_backfill_progress' );
		$this->assertSame( 1, $progress['indexed'] );
		$this->assertSame( 2, $progress['total'] );
		$this->assertEqualsWithDelta( time(), $progress['time'], 5 );
	}

	public function test_run_whose_embeds_all_fail_still_records_that_it_ran() {
		$ids             = $this->archive( 1 );
		$this->failing[] = undergrowth_post_text( get_post( $ids[0] ) );
		$this->set_progress( 0, 1, DAY_IN_SECONDS * 2 );

		do_action( 'undergrowth_backfill' );

		$this->assertFalse( undergrowth_backfill_stalled() );
	}

	public function test_backfill_is_stalled_when_nothing_moved_for_a_day() {
		$this->archive( 2 );
		$this->set_progress( 0, 2, 25 * HOUR_IN_SECONDS );

		$this->assertTrue( undergrowth_backfill_stalled() );
	}

	public function test_backfill_is_not_stalled_within_a_day() {
		$this->archive( 2 );
		$this->set_progress( 0, 2, 23 * HOUR_IN_SECONDS );

		$this->assertFalse( undergrowth_backfill_stalled() );
	}

	public function test_backfill_is_not_stalled_once_every_post_is_read() {
		$this->archive( 1 );
		undergrowth_start_backfill();
		$this->run_cron();
		$this->set_progress( 1, 1, 30 * DAY_IN_SECONDS );

		$this->assertFalse( undergrowth_backfill_stalled() );
	}

	public function test_a_count_that_moved_starts_the_day_again() {
		$this->archive( 3 );
		$this->set_progress( 0, 2, 25 * HOUR_IN_SECONDS );

		$this->assertFalse( undergrowth_backfill_stalled() );

		$progress = get_option( 'undergrowth_backfill_progress' );
		$this->assertSame( 3, $progress['total'] );
		$this->assertEqualsWithDelta( time(), $progress['time'], 5 );
	}

	public function test_first_check_starts_the_day() {
		$this->archive( 2 );

		$this->assertFalse( undergrowth_backfill_stalled() );
		$this->assertEqualsWithDelta( time(), get_option( 'undergrowth_backfill_progress' )['time'], 5 );
	}

	public function test_starting_the_backfill_starts_the_day() {
		$this->archive( 2 );

		undergrowth_start_backfill();

		$progress = get_option( 'undergrowth_backfill_progress' );
		$this->assertSame( 0, $progress['indexed'] );
		$this->assertSame( 2, $progress['total'] );
		$this->assertEqualsWithDelta( time(), $progress['time'], 5 );
	}

	public function test_starting_again_with_the_same_counts_keeps_the_day() {
		$this->archive( 2 );
		$this->set_progress( 0, 2, 25 * HOUR_IN_SECONDS );

		undergrowth_start_backfill();

		$this->assertTrue( undergrowth_backfill_stalled() );
	}

	public function test_backfill_is_not_stalled_without_a_provider() {
		$this->archive( 2 );
		$this->set_progress( 0, 2, 25 * HOUR_IN_SECONDS );
		remove_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );

		$this->assertFalse( undergrowth_backfill_stalled() );
	}

	/**
	 * Records the backfill's progress as a run that long ago would have.
	 *
	 * @param int $indexed Posts read then.
	 * @param int $total   Posts that could be read then.
	 * @param int $ago     Seconds since.
	 */
	private function set_progress( int $indexed, int $total, int $ago ): void {
		update_option(
			'undergrowth_backfill_progress',
			array(
				'time'    => time() - $ago,
				'indexed' => $indexed,
				'total'   => $total,
			),
			false
		);
	}

	/**
	 * Indexes posts with the first model through a finished backfill pass, as
	 * a site would be before the switch.
	 *
	 * @param int $count How many.
	 * @return int[] Post IDs.
	 */
	private function indexed_archive( int $count ): array {
		$ids = $this->archive( $count );

		undergrowth_start_backfill();
		$this->run_cron();
		$this->assertSame( undergrowth_index_target(), get_option( 'undergrowth_index_identity' ) );
		wp_unschedule_hook( 'undergrowth_cluster_cycle' );
		$this->embedded = array();

		return $ids;
	}

	public function test_changed_model_reembeds_every_post_one_batch_per_run() {
		$this->batch_size( 2 );
		$ids = $this->indexed_archive( 5 );

		$this->model = 'test-model-v2';
		do_action( 'undergrowth_backfill_check' );
		$runs = $this->run_cron();

		$this->assertSame( array( 2, 2, 1 ), $runs );

		foreach ( $ids as $id ) {
			$this->assertSame( 'test-model-v2', undergrowth_get_vector( $id )['model'] );
		}
	}

	public function test_old_vectors_stay_until_their_post_is_reembedded() {
		$this->batch_size( 2 );
		$ids = $this->indexed_archive( 3 );

		$this->model = 'test-model-v2';
		do_action( 'undergrowth_backfill_check' );
		wp_unschedule_hook( 'undergrowth_backfill' );
		do_action( 'undergrowth_backfill' );

		$this->assertSame( 'test-model-v2', undergrowth_get_vector( $ids[1] )['model'] );
		$this->assertSame( 'test-model-v1', undergrowth_get_vector( $ids[2] )['model'] );
	}

	public function test_failed_reembed_keeps_the_old_vector_and_is_tried_again() {
		$ids             = $this->indexed_archive( 1 );
		$this->model     = 'test-model-v2';
		$this->failing[] = undergrowth_post_text( get_post( $ids[0] ) );

		do_action( 'undergrowth_backfill_check' );
		$this->run_cron();

		$this->assertSame( 'test-model-v1', undergrowth_get_vector( $ids[0] )['model'] );

		$this->failing = array();
		do_action( 'undergrowth_backfill_check' );
		$this->run_cron();

		$this->assertSame( 'test-model-v2', undergrowth_get_vector( $ids[0] )['model'] );
	}

	public function test_reembed_never_compares_vectors_from_two_models() {
		$this->batch_size( 1 );
		$ids = $this->indexed_archive( 3 );

		$this->model = 'test-model-v2';
		do_action( 'undergrowth_backfill_check' );
		wp_unschedule_hook( 'undergrowth_backfill' );
		do_action( 'undergrowth_backfill' );

		$this->assertSame( array( $ids[0] ), array_keys( undergrowth_comparable_vectors( $ids[0] ) ) );
		$this->assertSame( array(), undergrowth_score_targets( $ids[0] ) );
		$this->assertNotContains( $ids[0], array_keys( undergrowth_comparable_vectors( $ids[1] ) ) );
	}

	public function test_switching_models_back_and_forth_queues_one_run() {
		$this->indexed_archive( 2 );

		foreach ( array( 'test-model-v2', 'test-model-v1', 'test-model-v2' ) as $model ) {
			$this->model = $model;
			do_action( 'undergrowth_backfill_check' );
		}

		$this->assertCount( 1, array_filter( _get_cron_array(), static fn( $hooks ) => isset( $hooks['undergrowth_backfill'] ) ) );
	}

	public function test_switching_back_before_the_reembed_runs_sends_nothing() {
		$this->indexed_archive( 2 );

		$this->model = 'test-model-v2';
		do_action( 'undergrowth_backfill_check' );
		$this->model = 'test-model-v1';
		$this->run_cron();

		$this->assertSame( array(), $this->embedded );
	}

	public function test_finished_reembed_clusters_the_new_vectors() {
		$this->indexed_archive( 2 );

		$this->model = 'test-model-v2';
		do_action( 'undergrowth_backfill_check' );
		$this->run_cron();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', array( 0 ) ) );
	}

	/**
	 * Sets the site locale through core's filter.
	 *
	 * @param string $locale Locale such as de_DE.
	 */
	private function site_locale( string $locale ): void {
		add_filter(
			'locale',
			static function () use ( $locale ) {
				return $locale;
			}
		);
	}

	public function test_changed_site_language_reembeds_every_post() {
		$this->batch_size( 2 );
		$ids = $this->indexed_archive( 3 );

		$this->site_locale( 'de_DE' );
		do_action( 'undergrowth_backfill_check' );
		$runs = $this->run_cron();

		$this->assertSame( array( 2, 1 ), $runs );

		foreach ( $ids as $id ) {
			$this->assertSame( 'de_DE', undergrowth_get_vector( $id )['language'] );
		}
	}

	public function test_changed_site_language_counts_old_vectors_as_unread() {
		$this->indexed_archive( 2 );

		$this->site_locale( 'de_DE' );

		$this->assertSame( 0, undergrowth_index_counts()['indexed'] );
	}

	public function test_finished_reembed_in_a_new_site_language_clusters_the_new_vectors() {
		$this->indexed_archive( 2 );

		$this->site_locale( 'de_DE' );
		do_action( 'undergrowth_backfill_check' );
		$this->run_cron();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', array( 0 ) ) );
	}

	public function test_languages_set_per_post_are_left_apart_when_the_site_language_changes() {
		add_filter(
			'undergrowth_post_language',
			static function ( $locale, $post ) {
				return 0 === $post->ID % 2 ? 'fr_FR' : $locale;
			},
			10,
			2
		);
		$this->indexed_archive( 2 );

		$this->site_locale( 'de_DE' );
		do_action( 'undergrowth_backfill_check' );

		$this->assertSame( array(), $this->run_cron() );
	}

	public function test_a_long_site_language_is_fitted_like_a_post_language() {
		$this->site_locale( 'en_GB-x-a-very-long-site-variant' );

		$language = undergrowth_index_target()['language'];

		$this->assertSame( undergrowth_fit_language( 'en_GB-x-a-very-long-site-variant' ), $language );
		$this->assertSame( undergrowth_post_language( get_post( self::factory()->post->create() ) ), $language );
	}
}
