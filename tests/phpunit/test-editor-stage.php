<?php
/**
 * Tests for the growth stage panel in the block editor.
 *
 * @package Undergrowth
 */

/**
 * Editor stage route and script.
 */
class Test_Editor_Stage extends WP_UnitTestCase {

	use Plants_Posts;

	/**
	 * Editor who acts on the posts.
	 *
	 * @var int
	 */
	private $editor;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		undergrowth_register_stage_meta();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;

		wp_dequeue_script( 'undergrowth-editor' );
		wp_deregister_script( 'undergrowth-editor' );
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Calls the stage route.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $body    Fields to post, or none for a GET.
	 * @return WP_REST_Response
	 */
	private function call( int $post_id, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( array() === $body ? 'GET' : 'POST', '/undergrowth/v1/posts/' . $post_id . '/stage' );

		if ( array() !== $body ) {
			$request->set_body_params( $body );
		}

		return rest_do_request( $request );
	}

	/**
	 * Publishes a post with no links.
	 *
	 * @return int Post ID.
	 */
	private function published(): int {
		return self::factory()->post->create( array( 'post_status' => 'publish' ) );
	}

	public function test_reads_the_stage_of_a_published_post() {
		$post_id  = $this->published();
		$response = $this->call( $post_id );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['in_garden'] );
		$this->assertSame( 'seedling', $data['stage'] );
		$this->assertSame( undergrowth_stage_label( 'seedling' ), $data['label'] );
		$this->assertFalse( $data['pinned'] );
		$this->assertSame( array( 'seedling', 'sprout', 'budding', 'evergreen' ), wp_list_pluck( $data['choices'], 'value' ) );
		$this->assertSame( undergrowth_stage_label( 'budding' ), $data['choices'][2]['label'] );
	}

	public function test_a_draft_is_not_in_the_garden() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$data    = $this->call( $post_id )->get_data();

		$this->assertFalse( $data['in_garden'] );
		$this->assertNull( $data['stage'] );
	}

	public function test_pins_a_stage_as_the_stages_tab_does() {
		$post_id  = $this->published();
		$response = $this->call(
			$post_id,
			array(
				'action' => 'pin',
				'stage'  => 'sprout',
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'sprout', $data['stage'] );
		$this->assertTrue( $data['pinned'] );
		$this->assertNotEmpty( $data['message'] );
		$this->assertSame( 'sprout', undergrowth_get_stage( $post_id ) );
		$this->assertTrue( undergrowth_is_stage_pinned( $post_id ) );
		$this->assertSame( 'pin', array_column( undergrowth_stage_log( $post_id ), 'reason' )[0] ?? null );
	}

	public function test_unpins_and_measures_again() {
		$post_id = $this->published();
		undergrowth_pin_stage( $post_id, 'evergreen' );

		$data = $this->call( $post_id, array( 'action' => 'unpin' ) )->get_data();

		$this->assertFalse( $data['pinned'] );
		$this->assertSame( 'seedling', $data['stage'] );
		$this->assertFalse( undergrowth_is_stage_pinned( $post_id ) );
	}

	public function test_composts_and_returns_as_the_posts_list_does() {
		$post_id = $this->published();
		undergrowth_pin_stage( $post_id, 'sprout' );

		$data = $this->call( $post_id, array( 'action' => 'compost' ) )->get_data();

		$this->assertSame( 'composting', $data['stage'] );
		$this->assertFalse( $data['pinned'] );
		$this->assertSame( 'composting', undergrowth_get_stage( $post_id ) );

		$data = $this->call( $post_id, array( 'action' => 'return' ) )->get_data();

		$this->assertSame( 'seedling', $data['stage'] );
		$this->assertSame( 'seedling', undergrowth_get_stage( $post_id ) );
	}

	public function test_each_answer_reads_its_sentence_from_the_stage_result_table() {
		$post_id  = $this->published();
		$messages = undergrowth_stage_result_messages();
		$actions  = array(
			'pin'     => 'pinned',
			'unpin'   => 'unpinned',
			'compost' => 'composted',
			'return'  => 'returned',
		);

		foreach ( $actions as $action => $key ) {
			$data = $this->call(
				$post_id,
				array(
					'action' => $action,
					'stage'  => 'sprout',
				)
			)->get_data();

			$this->assertSame( $messages[ $key ], $data['message'], $action );
		}
	}

	public function test_pinnable_stages_are_the_garden_stages() {
		$routes = rest_get_server()->get_routes();
		$args   = $routes['/undergrowth/v1/posts/(?P<id>\\d+)/stage'][1]['args'];

		$this->assertFalse( function_exists( 'undergrowth_pinnable_stages' ) );
		$this->assertSame( UNDERGROWTH_GARDEN_STAGES, $args['stage']['enum'] );
		$this->assertSame( UNDERGROWTH_GARDEN_STAGES, array_column( undergrowth_editor_stage( $this->published() )['choices'], 'value' ) );
	}

	public function test_refusals_carry_the_reason_and_change_nothing() {
		$post_id = $this->published();
		undergrowth_compost_post( $post_id );

		$response = $this->call(
			$post_id,
			array(
				'action' => 'pin',
				'stage'  => 'sprout',
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'undergrowth_composting', $response->get_data()['code'] );
		$this->assertSame( 'composting', undergrowth_get_stage( $post_id ) );
	}

	public function test_a_draft_cannot_be_pinned() {
		$post_id  = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$response = $this->call(
			$post_id,
			array(
				'action' => 'pin',
				'stage'  => 'sprout',
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertFalse( undergrowth_is_stage_pinned( $post_id ) );
	}

	public function test_only_known_actions_and_pinnable_stages_are_accepted() {
		$post_id = $this->published();

		$this->assertSame( 400, $this->call( $post_id, array( 'action' => 'delete' ) )->get_status() );
		$this->assertSame(
			400,
			$this->call(
				$post_id,
				array(
					'action' => 'pin',
					'stage'  => 'composting',
				)
			)->get_status()
		);
		$this->assertSame( 400, $this->call( $post_id, array( 'action' => 'pin' ) )->get_status() );
		$this->assertSame( 'seedling', undergrowth_get_stage( $post_id ) );
	}

	public function test_someone_who_cannot_edit_the_post_is_refused() {
		$post_id = $this->published();
		undergrowth_pin_stage( $post_id, 'sprout' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->assertSame( 403, $this->call( $post_id )->get_status() );
		$this->assertSame( 403, $this->call( $post_id, array( 'action' => 'unpin' ) )->get_status() );
		$this->assertTrue( undergrowth_is_stage_pinned( $post_id ) );
	}

	public function test_readers_get_nothing() {
		$post_id = $this->published();
		undergrowth_compost_post( $post_id );

		wp_set_current_user( 0 );

		$response = $this->call( $post_id );

		$this->assertSame( 401, $response->get_status() );
		$this->assertStringNotContainsString( 'composting', (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 401, $this->call( $post_id, array( 'action' => 'return' ) )->get_status() );
		$this->assertSame( 'composting', undergrowth_get_stage( $post_id ) );
	}

	public function test_a_missing_post_is_refused_without_saying_so() {
		$this->assertSame( 403, $this->call( PHP_INT_MAX )->get_status() );
	}

	public function test_script_loads_only_in_the_editor() {
		$this->assertNotFalse( has_action( 'enqueue_block_editor_assets', 'undergrowth_enqueue_editor_panel' ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', 'undergrowth_enqueue_editor_panel' ) );
	}

	public function test_script_waits_for_a_build_and_an_indexed_post_type() {
		$built = __DIR__ . '/includes/editor-asset.php';

		set_current_screen( 'post' );
		undergrowth_enqueue_editor_panel( __DIR__ . '/includes/no-such-file.php' );
		$this->assertFalse( wp_script_is( 'undergrowth-editor', 'enqueued' ) );

		set_current_screen( 'page' );
		undergrowth_enqueue_editor_panel( $built );
		$this->assertFalse( wp_script_is( 'undergrowth-editor', 'enqueued' ) );

		set_current_screen( 'post' );
		undergrowth_enqueue_editor_panel( $built );
		$this->assertTrue( wp_script_is( 'undergrowth-editor', 'enqueued' ) );
		$this->assertContains( 'wp-plugins', wp_scripts()->registered['undergrowth-editor']->deps );
		$this->assertSame( 'undergrowth', wp_scripts()->registered['undergrowth-editor']->textdomain );

		set_current_screen( 'front' );
	}
}
