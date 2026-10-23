<?php
/**
 * Tests for resurfacing signals: composting posts close to a new cluster.
 *
 * @package Undergrowth
 */

/**
 * Resurfacing signals.
 */
class Test_Resurfacing_Signals extends WP_UnitTestCase {

	use Stops_Redirects;

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		unset( $_POST['post'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['tab'], $_GET['undergrowth_notice'], $_GET['undergrowth_returned'] );
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Publishes a post and stores a vector for it.
	 *
	 * @param float[]              $vector Vector to store.
	 * @param string               $title  Post title.
	 * @param array<string, mixed> $meta   Provider, model or language to use instead of the defaults.
	 * @return int Post ID.
	 */
	private function post_with_vector( array $vector, string $title = '', array $meta = array() ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		undergrowth_save_vector(
			$post_id,
			$vector,
			array_merge(
				array(
					'provider'     => 'test-provider',
					'model'        => 'test-model',
					'language'     => 'en_US',
					'content_hash' => 'hash',
				),
				$meta
			)
		);

		return $post_id;
	}

	/**
	 * Composts a post as the author would.
	 *
	 * @param int $post_id Post ID.
	 */
	private function compost( int $post_id ): void {
		$this->assertTrue( undergrowth_compost_post( $post_id ) );
	}

	/**
	 * Runs a whole monthly pass, as cron would: every clustering batch and
	 * every resurfacing batch. The stage cycle it starts is left out.
	 */
	private function cycle(): void {
		$after_id = 0;

		do {
			undergrowth_run_cluster_cycle( $after_id );
			$after_id = $this->take_next( 'undergrowth_cluster_cycle' );
		} while ( null !== $after_id );

		while ( null !== ( $after_id = $this->take_next( 'undergrowth_resurfacing_cycle' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			undergrowth_run_resurfacing_cycle( $after_id );
		}

		wp_clear_scheduled_hook( 'undergrowth_stage_cycle', array( 0 ) );
	}

	/**
	 * Unschedules the next batch of a hook, if one is waiting.
	 *
	 * @param string $hook Cron hook.
	 * @return int|null Post ID the batch starts after, or null when none is waiting.
	 */
	private function take_next( string $hook ): ?int {
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( $hooks[ $hook ] ?? array() as $event ) {
				if ( array() !== $event['args'] ) {
					wp_clear_scheduled_hook( $hook, $event['args'] );
					return (int) $event['args'][0];
				}
			}
		}

		return null;
	}

	/**
	 * Plants an older post that is then composted, and a cluster elsewhere,
	 * and runs one pass so there is a previous cycle to compare with.
	 *
	 * @return int The composting post's ID.
	 */
	private function garden_with_compost(): int {
		$old = $this->post_with_vector( array( 1.0, 0.0, 0.0 ), 'Oca in winter' );

		$this->post_with_vector( array( 0.0, 1.0, 0.0 ), 'Yacon' );
		$this->post_with_vector( array( 0.0, 0.95, 0.05 ), 'Yacon syrup' );
		$this->post_with_vector( array( 0.0, 0.9, 0.1 ), 'Yacon leaves' );
		$this->compost( $old );

		$this->cycle();

		return $old;
	}

	/**
	 * Plants three posts close to the composting post, forming a new cluster.
	 *
	 * @return int[] The new posts' IDs, oldest first.
	 */
	private function new_cluster_near_compost(): array {
		return array(
			$this->post_with_vector( array( 0.95, 0.05, 0.0 ), 'Oca tubers' ),
			$this->post_with_vector( array( 0.9, 0.0, 0.1 ), 'Oca harvest' ),
			$this->post_with_vector( array( 0.92, 0.04, 0.04 ), 'Oca seed' ),
		);
	}

	/**
	 * Returns the events in a post's resurfacing log.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function events( int $post_id ): array {
		return array_column( undergrowth_resurfacing_log( $post_id ), 'event' );
	}

	/**
	 * Renders one tab of the screen and returns its HTML.
	 *
	 * @param string $tab Tab to render, the Map by default.
	 * @return string
	 */
	private function render( string $tab = 'map' ): string {
		$_GET['tab'] = $tab;
		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();

		return (string) ob_get_clean();
	}

	/**
	 * Fills the dismiss form and calls its handler.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $nonce   Nonce to send, or an empty string for a valid one.
	 * @return string Redirect location.
	 * @throws WPDieException When the handler refuses the request.
	 */
	private function dismiss( int $post_id, string $nonce = '' ): string {
		$nonce                = '' === $nonce ? wp_create_nonce( 'undergrowth_dismiss_resurfacing_' . $post_id ) : $nonce;
		$_POST['post']        = (string) $post_id;
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		try {
			undergrowth_handle_dismiss_resurfacing();
		} catch ( WPDieException $e ) {
			throw $e;
		} catch ( Exception $e ) {
			return $e->getMessage();
		}

		return '';
	}

	public function test_a_cluster_no_earlier_cluster_held_most_of_is_new() {
		$previous = array(
			1 => 1,
			2 => 1,
			3 => 1,
		);
		$current  = array(
			1 => 1,
			2 => 1,
			3 => 1,
			7 => 7,
			8 => 7,
			9 => 7,
		);

		$this->assertSame( array( 7 ), undergrowth_new_cluster_leaders( $current, $previous ) );
	}

	public function test_a_cluster_keeps_being_the_same_under_a_new_leader() {
		$previous = array(
			1 => 1,
			2 => 1,
			3 => 1,
			4 => 1,
		);
		// The old leader left, so the next post leads the same posts.
		$current = array(
			2 => 2,
			3 => 2,
			4 => 2,
		);

		$this->assertSame( array(), undergrowth_new_cluster_leaders( $current, $previous ) );
	}

	public function test_a_cluster_that_grew_is_new_only_once_most_of_it_is_new() {
		$previous = array(
			1 => 1,
			2 => 1,
			3 => 1,
		);
		$grew     = array_fill_keys( array( 1, 2, 3, 4, 5 ), 1 );
		$doubled  = array_fill_keys( array( 1, 2, 3, 4, 5, 6 ), 1 );

		$this->assertSame( array(), undergrowth_new_cluster_leaders( $grew, $previous ) );
		$this->assertSame( array( 1 ), undergrowth_new_cluster_leaders( $doubled, $previous ) );
	}

	public function test_a_cluster_made_of_parts_of_several_old_ones_is_new() {
		$previous = array(
			1 => 1,
			2 => 1,
			3 => 1,
			4 => 4,
			5 => 4,
			6 => 4,
		);
		$current  = array(
			1 => 1,
			2 => 1,
			5 => 1,
			6 => 1,
		);

		$this->assertSame( array( 1 ), undergrowth_new_cluster_leaders( $current, $previous ) );
	}

	public function test_snapshot_holds_only_clusters_of_three_or_more() {
		$a    = $this->post_with_vector( array( 1.0, 0.0 ) );
		$b    = $this->post_with_vector( array( 0.9, 0.1 ) );
		$c    = $this->post_with_vector( array( 0.95, 0.05 ) );
		$pair = $this->post_with_vector( array( 0.0, 1.0 ) );
		$this->post_with_vector( array( 0.1, 0.9 ) );

		$this->cycle();

		$snapshot = undergrowth_cluster_snapshot();

		$this->assertSame(
			array(
				$a => $a,
				$b => $a,
				$c => $a,
			),
			$snapshot
		);
		$this->assertArrayNotHasKey( $pair, $snapshot );
		$this->assertSame( $snapshot, get_option( 'undergrowth_previous_clusters' ) );
	}

	public function test_composting_post_near_a_new_cluster_gets_one_signal() {
		$old = $this->garden_with_compost();
		$new = $this->new_cluster_near_compost();

		$this->cycle();

		$this->assertSame( $new, undergrowth_cluster_members( $new[0] ) );
		$this->assertNotContains( $old, undergrowth_cluster_members( $new[0] ) );
		$this->assertSame( $new[0], undergrowth_resurfacing_signal( $old ) );
		$this->assertSame( array( 'shown' ), $this->events( $old ) );
		$this->assertFalse( get_option( 'undergrowth_new_clusters' ) );
	}

	public function test_first_pass_has_no_earlier_cycle_so_nothing_is_new() {
		$old = $this->post_with_vector( array( 1.0, 0.0, 0.0 ) );
		$this->new_cluster_near_compost();
		$this->compost( $old );

		$this->cycle();

		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
		$this->assertSame( array(), $this->events( $old ) );
		$this->assertIsArray( get_option( 'undergrowth_previous_clusters' ) );
	}

	public function test_pass_after_one_with_no_clusters_is_a_baseline() {
		// As right after activation: the first pass ran before anything clustered.
		$old = $this->post_with_vector( array( 1.0, 0.0, 0.0 ) );
		$this->compost( $old );
		$this->cycle();
		$this->assertSame( array(), get_option( 'undergrowth_previous_clusters' ) );

		$this->new_cluster_near_compost();
		$this->cycle();

		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
		$this->assertSame( array(), $this->events( $old ) );
	}

	public function test_first_pass_under_a_new_index_identity_is_a_baseline() {
		$old = $this->garden_with_compost();
		update_option(
			'undergrowth_index_identity',
			array(
				'provider' => 'test-provider',
				'model'    => 'test-model-v2',
			),
			false
		);

		$this->new_cluster_near_compost();
		$this->cycle();

		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
		$this->assertSame( array(), $this->events( $old ) );
	}

	public function test_new_index_identity_stops_resurfacing_still_queued_from_the_old_one() {
		update_option( 'undergrowth_new_clusters', array( 1 ), false );
		wp_schedule_single_event( time(), 'undergrowth_resurfacing_cycle', array( 5 ) );

		update_option(
			'undergrowth_index_identity',
			array(
				'provider' => 'test-provider',
				'model'    => 'test-model-v2',
			),
			false
		);

		$this->assertFalse( get_option( 'undergrowth_new_clusters' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_resurfacing_cycle', array( 5 ) ) );
	}

	public function test_second_pass_under_a_new_index_identity_compares_with_the_first() {
		$old = $this->garden_with_compost();
		update_option(
			'undergrowth_index_identity',
			array(
				'provider' => 'test-provider',
				'model'    => 'test-model-v2',
			),
			false
		);
		$this->cycle();

		$new = $this->new_cluster_near_compost();
		$this->cycle();

		$this->assertSame( $new[0], undergrowth_resurfacing_signal( $old ) );
	}

	public function test_cluster_from_the_previous_cycle_is_not_new() {
		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();

		$this->cycle();

		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
		$this->assertSame( array( 'shown' ), $this->events( $old ) );
	}

	public function test_dismissed_signal_is_not_shown_again() {
		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();

		$this->assertTrue( undergrowth_dismiss_resurfacing( $old ) );
		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );

		$this->cycle();

		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
		$this->assertSame( array( 'shown', 'dismissed' ), $this->events( $old ) );
	}

	public function test_dismissing_without_a_signal_changes_nothing() {
		$old = $this->garden_with_compost();

		$this->assertFalse( undergrowth_dismiss_resurfacing( $old ) );
		$this->assertSame( array(), $this->events( $old ) );
	}

	public function test_a_second_run_of_the_same_batch_shows_no_second_signal() {
		$old = $this->garden_with_compost();
		$new = $this->new_cluster_near_compost();
		$this->cycle();

		update_option( 'undergrowth_new_clusters', array( $new[0] ), false );
		undergrowth_run_resurfacing_cycle( 0 );

		$this->assertSame( $new[0], undergrowth_resurfacing_signal( $old ) );
		$this->assertSame( array( 'shown' ), $this->events( $old ) );
	}

	public function test_signal_lasts_only_until_the_next_pass() {
		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();

		$this->cycle();

		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
	}

	public function test_composting_post_embedded_with_another_model_gets_no_signal() {
		global $wpdb;

		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();
		$wpdb->update( undergrowth_vectors_table(), array( 'model' => 'other-model' ), array( 'post_id' => $old ) );

		$this->cycle();

		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
		$this->assertSame( array(), $this->events( $old ) );
	}

	public function test_composting_post_in_another_language_gets_no_signal() {
		global $wpdb;

		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();
		$wpdb->update( undergrowth_vectors_table(), array( 'language' => 'he_IL' ), array( 'post_id' => $old ) );

		$this->cycle();

		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
	}

	public function test_composting_post_from_another_provider_gets_no_signal() {
		global $wpdb;

		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();
		$wpdb->update( undergrowth_vectors_table(), array( 'provider' => 'other-provider' ), array( 'post_id' => $old ) );

		$this->cycle();

		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
	}

	public function test_composting_post_far_from_the_new_cluster_gets_no_signal() {
		$old = $this->garden_with_compost();

		$this->post_with_vector( array( 0.0, 0.0, 1.0 ) );
		$this->post_with_vector( array( 0.05, 0.0, 0.95 ) );
		$this->post_with_vector( array( 0.0, 0.05, 0.95 ) );

		$this->cycle();

		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
	}

	public function test_post_in_the_garden_gets_no_signal() {
		$this->garden_with_compost();
		$active = $this->post_with_vector( array( 0.97, 0.03, 0.0 ) );
		$this->new_cluster_near_compost();

		$this->cycle();

		$this->assertSame( 0, undergrowth_resurfacing_signal( $active ) );
	}

	public function test_batch_without_new_clusters_does_nothing() {
		$old = $this->garden_with_compost();

		undergrowth_run_resurfacing_cycle( 0 );

		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
		$this->assertNull( $this->take_next( 'undergrowth_resurfacing_cycle' ) );
	}

	public function test_composting_post_with_an_empty_vector_gets_no_signal() {
		$old = $this->garden_with_compost();
		$new = $this->new_cluster_near_compost();
		undergrowth_save_vector(
			$old,
			array( 0.0, 0.0, 0.0 ),
			array(
				'provider'     => 'test-provider',
				'model'        => 'test-model',
				'language'     => 'en_US',
				'content_hash' => 'hash',
			)
		);

		$this->cycle();

		$this->assertSame( $new, undergrowth_cluster_members( $new[0] ) );
		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
	}

	public function test_closeness_threshold_is_the_fixed_cluster_threshold_until_one_is_measured() {
		$this->assertSame( UNDERGROWTH_MIN_CLUSTER_SIMILARITY, undergrowth_min_resurfacing_similarity() );
	}

	public function test_closeness_threshold_follows_the_cluster_threshold() {
		$measured = static function () {
			return 0.72;
		};
		add_filter( 'undergrowth_min_cluster_similarity', $measured );

		$this->assertSame( 0.72, undergrowth_min_resurfacing_similarity() );

		remove_filter( 'undergrowth_min_cluster_similarity', $measured );
	}

	public function test_closeness_threshold_can_be_filtered() {
		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();

		$strict = static function () {
			return 0.9999;
		};
		add_filter( 'undergrowth_min_resurfacing_similarity', $strict );

		$this->cycle();

		remove_filter( 'undergrowth_min_resurfacing_similarity', $strict );

		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
	}

	public function test_threshold_filter_is_kept_within_range() {
		$filter = static function () {
			return 4;
		};
		add_filter( 'undergrowth_min_resurfacing_similarity', $filter );
		$this->assertSame( 1.0, undergrowth_min_resurfacing_similarity() );
		remove_filter( 'undergrowth_min_resurfacing_similarity', $filter );

		$filter = static function () {
			return 'close';
		};
		add_filter( 'undergrowth_min_resurfacing_similarity', $filter );
		$this->assertSame( undergrowth_min_cluster_similarity(), undergrowth_min_resurfacing_similarity() );
		remove_filter( 'undergrowth_min_resurfacing_similarity', $filter );
	}

	public function test_signal_goes_when_the_post_returns_to_the_garden() {
		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();

		$this->assertTrue( undergrowth_return_post( $old ) );

		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
	}

	public function test_signal_goes_when_the_post_leaves_the_index() {
		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();

		wp_update_post(
			array(
				'ID'          => $old,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
	}

	public function test_composting_posts_are_resurfaced_in_batches() {
		$old = $this->garden_with_compost();
		$new = $this->new_cluster_near_compost();

		for ( $i = 0; $i < UNDERGROWTH_CLUSTER_BATCH; $i++ ) {
			$post_id = $this->post_with_vector( array( 0.96, 0.02, 0.02 ) );
			undergrowth_set_stage( $post_id, 'composting', 'compost' );
		}

		$last = $this->post_with_vector( array( 0.97, 0.0, 0.03 ) );
		undergrowth_set_stage( $last, 'composting', 'compost' );

		$after_id = 0;

		do {
			undergrowth_run_cluster_cycle( $after_id );
			$after_id = $this->take_next( 'undergrowth_cluster_cycle' );
		} while ( null !== $after_id );

		$next = $this->take_next( 'undergrowth_resurfacing_cycle' );

		$this->assertNotNull( $next );
		$this->assertSame( $new[0], undergrowth_resurfacing_signal( $old ) );
		$this->assertSame( 0, undergrowth_resurfacing_signal( $last ) );

		undergrowth_run_resurfacing_cycle( $next );

		$this->assertSame( $new[0], undergrowth_resurfacing_signal( $last ) );
		$this->assertNull( $this->take_next( 'undergrowth_resurfacing_cycle' ) );
		$this->assertFalse( get_option( 'undergrowth_new_clusters' ) );
	}

	public function test_signal_key_is_protected() {
		$this->assertTrue( is_protected_meta( 'undergrowth_resurfacing', 'post' ) );
	}

	public function test_map_tab_names_the_composting_post_and_the_new_cluster() {
		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<div class="notice notice-info inline undergrowth-resurfacing">/', $html );
		$this->assertStringContainsString( 'Oca in winter', $html );
		// The cluster is named by its newest post, the one written most recently.
		$this->assertStringContainsString( 'Oca seed', $html );
		$this->assertStringContainsString( 'near what you are writing now', $html );
		$this->assertStringContainsString( 'in the group with', $html );
		$this->assertStringContainsString( 'value="undergrowth_dismiss_resurfacing"', $html );
		$this->assertStringContainsString( 'name="post" value="' . $old . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'action=undergrowth_return', $html );
		// Each control is named by its visible text and described by the sentence.
		$this->assertSame( 2, substr_count( $html, 'aria-describedby="undergrowth-resurfacing-' . $old . '"' ) );
		$this->assertStringContainsString( '<p id="undergrowth-resurfacing-' . $old . '">', $html );
	}

	public function test_the_signal_is_on_the_map_not_on_growth_stages() {
		$this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();

		$this->assertStringContainsString( 'undergrowth-resurfacing', $this->render( 'map' ) );
		$this->assertStringNotContainsString( 'undergrowth-resurfacing', $this->render( 'stages' ) );
		$this->assertStringNotContainsString( 'undergrowth-resurfacing', $this->render( '' ) );
	}

	public function test_the_signal_comes_before_the_groups() {
		$this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();

		$html   = $this->render();
		$signal = strpos( $html, 'undergrowth-resurfacing' );

		$this->assertNotFalse( $signal );
		$this->assertLessThan( strpos( $html, '<h2 id="undergrowth-cluster-' ), $signal );
	}

	public function test_a_signal_whose_group_is_too_small_to_map_is_not_shown() {
		$old = $this->garden_with_compost();
		$new = $this->new_cluster_near_compost();
		$this->cycle();

		delete_post_meta_by_key( 'undergrowth_cluster' );
		add_post_meta( $new[0], 'undergrowth_cluster', $new[0] );

		$this->assertSame( $new[0], undergrowth_resurfacing_signal( $old ) );
		$this->assertFalse( undergrowth_has_clusters() );
		$this->assertSame( array(), undergrowth_visible_resurfacing_signals() );
	}

	public function test_without_a_signal_or_a_group_there_is_no_map_tab() {
		$this->garden_with_compost();
		delete_post_meta_by_key( 'undergrowth_cluster' );

		$this->assertStringNotContainsString( 'tab=map', $this->render( 'stages' ) );
	}

	public function test_signal_mentions_no_time_spent_composting() {
		$this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();

		$this->assertDoesNotMatchRegularExpression( '/\b(day|days|week|weeks|month|months|year|years|ago|since)\b/i', $this->signal_text( $this->render() ) );
	}

	/**
	 * Returns the text of the resurfacing notices in a page.
	 *
	 * @param string $html Page HTML.
	 * @return string Text with tags removed.
	 */
	private function signal_text( string $html ): string {
		preg_match_all( '/<div class="notice notice-info inline undergrowth-resurfacing">(.*?)<\/form>/s', $html, $matches );

		$this->assertNotEmpty( $matches[1] );

		return wp_strip_all_tags( implode( ' ', $matches[1] ) );
	}

	public function test_map_tab_shows_no_signal_when_no_post_type_is_read() {
		$this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();

		add_filter( 'undergrowth_post_types', '__return_empty_array' );
		$html = $this->render();
		remove_filter( 'undergrowth_post_types', '__return_empty_array' );

		$this->assertStringNotContainsString( 'undergrowth-resurfacing', $html );
	}

	public function test_map_tab_shows_no_signal_without_one() {
		$this->garden_with_compost();

		$this->assertStringNotContainsString( 'undergrowth-resurfacing', $this->render() );
	}

	public function test_map_tab_hides_signal_from_a_user_who_cannot_edit_the_post() {
		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();

		$deny = static function ( $caps, $cap, $user_id, $args ) use ( $old ) {
			return 'edit_post' === $cap && isset( $args[0] ) && $old === (int) $args[0] ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );

		$html = $this->render();

		remove_filter( 'map_meta_cap', $deny, 10 );

		$this->assertStringContainsString( 'nav-tab-wrapper', $html );
		$this->assertStringNotContainsString( 'undergrowth-resurfacing', $html );
	}

	public function test_map_tab_shows_every_signal() {
		$this->garden_with_compost();
		$new = $this->new_cluster_near_compost();
		$this->cycle();

		for ( $i = 0; $i < UNDERGROWTH_CLUSTER_BATCH; $i++ ) {
			$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
			undergrowth_set_stage( $post_id, 'composting', 'compost' );
			add_post_meta( $post_id, 'undergrowth_resurfacing', $new[0] );
		}

		$this->assertSame( UNDERGROWTH_CLUSTER_BATCH + 1, substr_count( $this->render(), 'class="notice notice-info inline undergrowth-resurfacing"' ) );
	}

	public function test_map_tab_skips_signal_whose_cluster_is_gone() {
		$old = $this->garden_with_compost();
		$new = $this->new_cluster_near_compost();
		$this->cycle();

		foreach ( $new as $post_id ) {
			delete_post_meta( $post_id, 'undergrowth_cluster' );
		}

		$this->assertSame( $new[0], undergrowth_resurfacing_signal( $old ) );
		$this->assertStringNotContainsString( 'undergrowth-resurfacing', $this->render() );
	}

	public function test_dismiss_form_removes_the_signal_and_returns_to_the_map_tab() {
		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();

		$location = $this->dismiss( $old );

		$this->assertStringContainsString( 'tab=map', $location );
		$this->assertStringContainsString( 'undergrowth_notice=resurfacing_dismissed', $location );
		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
		$this->assertSame( array( 'shown', 'dismissed' ), $this->events( $old ) );
	}

	public function test_dismiss_form_without_a_signal_says_so() {
		$old = $this->garden_with_compost();

		$this->assertStringContainsString( 'undergrowth_notice=resurfacing_gone', $this->dismiss( $old ) );
	}

	public function test_dismiss_form_checks_the_nonce() {
		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();

		$this->expectException( WPDieException::class );

		try {
			$this->dismiss( $old, 'not-a-nonce' );
		} finally {
			$this->assertSame( array( 'shown' ), $this->events( $old ) );
		}
	}

	public function test_dismiss_form_checks_the_user_may_edit_the_post() {
		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->expectException( WPDieException::class );

		try {
			$this->dismiss( $old );
		} finally {
			$this->assertNotSame( 0, undergrowth_resurfacing_signal( $old ) );
		}
	}

	public function test_dismissal_notices_are_shown_on_return() {
		$_GET['undergrowth_notice'] = 'resurfacing_dismissed';
		$this->assertMatchesRegularExpression( '/id="undergrowth-notice" class="notice notice-success is-dismissible"/', $this->render() );

		$_GET['undergrowth_notice'] = 'resurfacing_gone';
		$this->assertMatchesRegularExpression( '/id="undergrowth-notice" class="notice notice-warning is-dismissible"/', $this->render() );
	}

	public function test_map_tab_confirms_a_post_returned_from_its_signal() {
		$_GET['undergrowth_returned'] = '1';

		$this->assertStringContainsString( 'post returned to the garden', $this->render() );
	}

	public function test_deactivation_forgets_the_cycles_and_signals() {
		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();
		update_option( 'undergrowth_new_clusters', array( 1 ), false );

		undergrowth_deactivate();

		$this->assertFalse( get_option( 'undergrowth_previous_clusters' ) );
		$this->assertFalse( get_option( 'undergrowth_new_clusters' ) );
		$this->assertSame( 0, undergrowth_resurfacing_signal( $old ) );
	}

	public function test_uninstall_removes_the_cycles_and_signals() {
		$old = $this->garden_with_compost();
		$this->new_cluster_near_compost();
		$this->cycle();
		wp_schedule_single_event( time(), 'undergrowth_resurfacing_cycle', array( 5 ) );
		$this->assertNotSame( array(), get_post_meta( $old, 'undergrowth_resurfacing_log', false ) );

		// Only what uninstall deletes is checked here. A failed drop of the
		// test suite's temporary tables can roll back everything before it.
		$keep_tables = static function ( $query ) {
			return 0 === stripos( ltrim( $query ), 'DROP ' ) ? 'SELECT 1' : $query;
		};
		add_filter( 'query', $keep_tables, 1 );

		undergrowth_uninstall_site();

		remove_filter( 'query', $keep_tables, 1 );

		$this->assertFalse( get_option( 'undergrowth_previous_clusters' ) );
		$this->assertSame( '', get_post_meta( $old, 'undergrowth_resurfacing', true ) );
		$this->assertSame( array(), get_post_meta( $old, 'undergrowth_resurfacing_log', false ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_resurfacing_cycle', array( 5 ) ) );
	}
}
