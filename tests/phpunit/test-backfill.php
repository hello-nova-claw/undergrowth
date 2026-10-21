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

	public function set_up() {
		parent::set_up();

		$this->embedded = array();
		$this->failing  = array();
		$this->model    = 'test-model-v1';

		delete_option( 'undergrowth_openai_api_key' );
		delete_option( 'undergrowth_backfill' );
		delete_option( 'undergrowth_backfill.lock' );
		delete_option( 'undergrowth_index_identity' );
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
		$ids             = $this->archive( 2 );
		$this->failing[] = undergrowth_post_text( get_post( $ids[0] ) );
		undergrowth_start_backfill();
		$this->run_cron();
		wp_unschedule_hook( 'undergrowth_cluster_cycle' );

		$this->failing = array();
		do_action( 'undergrowth_backfill_check' );
		$this->run_cron();

		$this->assertFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', array( 0 ) ) );
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

	/**
	 * Indexes posts with the first model, as if they were embedded before the switch.
	 *
	 * @param int $count How many.
	 * @return int[] Post IDs.
	 */
	private function indexed_archive( int $count ): array {
		$ids = $this->archive( $count );

		foreach ( $ids as $id ) {
			undergrowth_embed_post( $id );
		}

		undergrowth_start_backfill();
		$this->run_cron();
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
}
