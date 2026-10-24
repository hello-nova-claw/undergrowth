<?php
/**
 * Tests for scanning a draft from the block editor.
 *
 * @package Undergrowth
 */

/**
 * Draft scan routes.
 */
class Test_Draft_Scan extends WP_UnitTestCase {

	const DRAFT_TEXT = 'I keep the seed ledger by the door.';

	/**
	 * Requests sent out, as URL and arguments.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private $requests = array();

	/**
	 * Status the fake provider answers with.
	 *
	 * @var int
	 */
	private $status = 200;

	/**
	 * Draft being scanned.
	 *
	 * @var int
	 */
	private $draft;

	/**
	 * Published post the draft should link to.
	 *
	 * @var int
	 */
	private $ledger;

	/**
	 * Published post about something else.
	 *
	 * @var int
	 */
	private $far;

	public function set_up() {
		parent::set_up();

		$this->requests = array();
		$this->status   = 200;
		update_option( 'undergrowth_openai_api_key', 'sk-test-not-a-real-key' );
		delete_option( 'undergrowth_last_embed_error' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		undergrowth_register_stage_meta();

		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->ledger = $this->published( 'Seed ledger', '<!-- wp:paragraph --><p>The seed ledger lists every oca I planted.</p><!-- /wp:paragraph -->', array( 1.0, 0.0, 0.0 ) );
		$this->far    = $this->published( 'Far away', '<!-- wp:paragraph --><p>Nothing in common.</p><!-- /wp:paragraph -->', array( 0.0, 1.0, 0.0 ) );
		$this->draft  = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Planting notes',
				'post_content' => '<!-- wp:paragraph --><p>Old text.</p><!-- /wp:paragraph -->',
				'post_author'  => $user,
			)
		);
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;

		remove_filter( 'pre_http_request', array( $this, 'fake_http' ) );
		delete_option( 'undergrowth_openai_api_key' );
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Answers OpenAI as it would, with a vector close to the ledger's.
	 *
	 * @param false|array<string, mixed>|WP_Error $preempt Short-circuit value.
	 * @param array<string, mixed>                $args    Request arguments.
	 * @param string                              $url     Request URL.
	 * @return array<string, mixed>
	 */
	public function fake_http( $preempt, $args, $url ) {
		$this->requests[] = array( $url, $args );

		return array(
			'headers'  => array(),
			'body'     => 200 === $this->status ? (string) wp_json_encode( array( 'data' => array( array( 'embedding' => array( 0.9, 0.1, 0.0 ) ) ) ) ) : '{}',
			'response' => array(
				'code'    => $this->status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Publishes a post with an OpenAI vector stored for it.
	 *
	 * @param string  $title   Title.
	 * @param string  $content Content.
	 * @param float[] $vector  Vector.
	 * @return int Post ID.
	 */
	private function published( string $title, string $content, array $vector ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);

		undergrowth_save_vector(
			$post_id,
			$vector,
			array(
				'provider'     => 'openai',
				'model'        => UNDERGROWTH_OPENAI_MODEL,
				'language'     => get_locale(),
				'content_hash' => hash( 'sha256', $title ),
			)
		);

		return $post_id;
	}

	/**
	 * Calls one of the scan routes.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route after the post, such as '' or '/accept'.
	 * @param array<string, mixed> $body   JSON body.
	 * @param int                  $post   Post the route is for. Defaults to the draft.
	 * @return WP_REST_Response
	 */
	private function call( string $method, string $route = '', array $body = array(), int $post = 0 ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/undergrowth/v1/posts/' . ( $post ? $post : $this->draft ) . '/scan' . $route );

		if ( array() !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Scans a post with the text the editor holds.
	 *
	 * @param int                  $post  Post. Defaults to the draft.
	 * @param array<string, mixed> $extra Fields to add.
	 * @return WP_REST_Response
	 */
	private function scan( int $post = 0, array $extra = array() ): WP_REST_Response {
		return $this->call(
			'POST',
			'',
			$extra + array(
				'title'   => 'Planting notes',
				'content' => '<!-- wp:paragraph --><p>' . self::DRAFT_TEXT . '</p><!-- /wp:paragraph -->',
			),
			$post
		);
	}

	/**
	 * Counts the rows a table holds for a post.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column that holds the post ID.
	 * @return int
	 */
	private function rows_for_draft( string $table, string $column ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i = %d', $table, $column, $this->draft ) );
	}

	public function test_names_openai_before_anything_is_sent() {
		$data = $this->call( 'GET' )->get_data();

		$this->assertTrue( $data['available'] );
		$this->assertStringContainsString( 'OpenAI', $data['destination'] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_names_a_provider_set_up_by_code() {
		$identity = static function () {
			return array(
				'provider' => 'ollama',
				'model'    => 'nomic-embed-text',
			);
		};
		add_filter( 'undergrowth_embed_identity', $identity );

		$data = $this->call( 'GET' )->get_data();

		remove_filter( 'undergrowth_embed_identity', $identity );

		$this->assertStringContainsString( 'ollama (nomic-embed-text)', $data['destination'] );
		$this->assertStringNotContainsString( 'OpenAI', $data['destination'] );
	}

	public function test_without_a_provider_offers_no_scan_and_sends_nothing() {
		delete_option( 'undergrowth_openai_api_key' );

		$data = $this->call( 'GET' )->get_data();

		$this->assertFalse( $data['available'] );
		$this->assertSame( '', $data['destination'] );
		$this->assertNotEmpty( $data['unavailable'] );

		$response = $this->scan();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'undergrowth_no_provider', $response->get_data()['code'] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_scans_the_text_the_editor_holds_and_suggests_links() {
		$response = $this->scan();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( self::DRAFT_TEXT, (string) $this->requests[0][1]['body'] );
		$this->assertStringNotContainsString( 'Old text', (string) $this->requests[0][1]['body'] );

		$this->assertSame( array( $this->ledger ), array_column( $data['outgoing'], 'target' ) );
		$this->assertSame( 'Seed ledger', $data['outgoing'][0]['title'] );
		$this->assertSame( get_permalink( $this->ledger ), $data['outgoing'][0]['url'] );
		$this->assertSame( self::DRAFT_TEXT, $data['outgoing'][0]['sentence'] );
		$this->assertSame(
			array(
				'offset' => mb_strpos( self::DRAFT_TEXT, 'seed ledger' ),
				'length' => 11,
			),
			$data['outgoing'][0]['span']
		);
	}

	public function test_a_scan_leaves_no_trace() {
		$content = get_post_field( 'post_content', $this->draft );
		$meta    = get_post_meta( $this->draft );

		$this->scan();

		$this->assertSame( 0, $this->rows_for_draft( undergrowth_links_table(), 'source_id' ) );
		$this->assertSame( 0, $this->rows_for_draft( undergrowth_links_table(), 'target_id' ) );
		$this->assertSame( 0, $this->rows_for_draft( undergrowth_vectors_table(), 'post_id' ) );
		$this->assertFileDoesNotExist( undergrowth_memory_path( $this->draft ) );
		$this->assertSame( $content, get_post_field( 'post_content', $this->draft ) );
		$this->assertSame( $meta, get_post_meta( $this->draft ) );
		$this->assertFalse( get_option( 'undergrowth_last_embed_error' ) );
	}

	public function test_leaves_out_posts_the_draft_already_links_to() {
		$data = $this->scan(
			0,
			array( 'content' => '<!-- wp:paragraph --><p>I keep the <a href="' . esc_url( get_permalink( $this->ledger ) ) . '">ledger</a>. ' . self::DRAFT_TEXT . '</p><!-- /wp:paragraph -->' )
		)->get_data();

		$this->assertSame( array(), $data['outgoing'] );
	}

	/**
	 * Refuses to send a post that is not the author's open draft.
	 *
	 * @dataProvider data_posts_never_scanned
	 *
	 * @param array<string, string> $fields Post fields to save first.
	 * @param array<string, string> $extra  Fields the editor sends.
	 * @param string                $code   Expected error code.
	 */
	public function test_never_sends_a_post_that_is_not_an_open_draft( array $fields, array $extra, string $code ) {
		if ( array() !== $fields ) {
			wp_update_post( array( 'ID' => $this->draft ) + $fields );
		}

		$response = $this->scan( 0, $extra );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Posts a scan must never send.
	 *
	 * @return array<string, array{0: array<string, string>, 1: array<string, string>, 2: string}>
	 */
	public function data_posts_never_scanned(): array {
		return array(
			'saved password'    => array( array( 'post_password' => 'oca' ), array(), 'undergrowth_scan_private' ),
			'password in field' => array( array(), array( 'password' => 'oca' ), 'undergrowth_scan_private' ),
			'private'           => array( array( 'post_status' => 'private' ), array(), 'undergrowth_scan_private' ),
			'published'         => array( array( 'post_status' => 'publish' ), array(), 'undergrowth_scan_published' ),
		);
	}

	public function test_never_sends_a_post_type_that_is_not_indexed() {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);

		$response = $this->scan( $page );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'undergrowth_scan_not_indexed', $response->get_data()['code'] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_sends_nothing_while_suggestions_are_off() {
		update_option( 'undergrowth_suggest_links', '0' );

		$this->assertFalse( $this->call( 'GET' )->get_data()['available'] );
		$this->assertSame( 'undergrowth_suggestions_off', $this->scan()->get_data()['code'] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_one_scan_at_a_time_with_a_cooldown() {
		$this->assertSame( 200, $this->scan()->get_status() );

		$response = $this->scan();

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'undergrowth_scan_wait', $response->get_data()['code'] );
		$this->assertCount( 1, $this->requests );

		// Once the cooldown has passed, the author can scan again.
		update_user_meta( get_current_user_id(), 'undergrowth_scan_until', time() - 1 );

		$this->assertSame( 200, $this->scan()->get_status() );
		$this->assertCount( 2, $this->requests );
	}

	public function test_a_scan_still_running_blocks_another() {
		update_user_meta( get_current_user_id(), 'undergrowth_scan_until', time() + UNDERGROWTH_SCAN_RUNNING );

		$this->assertSame( 429, $this->scan()->get_status() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_failed_request_says_so_and_still_cools_down() {
		$this->status = 500;

		$response = $this->scan();

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'undergrowth_scan_failed', $response->get_data()['code'] );

		$until = (int) get_user_meta( get_current_user_id(), 'undergrowth_scan_until', true );

		$this->assertGreaterThan( time(), $until );
		$this->assertLessThanOrEqual( time() + UNDERGROWTH_SCAN_COOLDOWN, $until );

		// The failed scan still used the turn, so trying again at once
		// sends nothing more.
		$this->assertSame( 429, $this->scan()->get_status() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_turn_another_request_has_just_claimed_is_refused() {
		global $wpdb;

		$user = get_current_user_id();

		// This request read an expired turn into the cache, then another
		// request claimed it in the database.
		update_user_meta( $user, 'undergrowth_scan_until', time() - 1 );
		get_user_meta( $user, 'undergrowth_scan_until', true );
		$wpdb->update(
			$wpdb->usermeta,
			array( 'meta_value' => (string) ( time() + UNDERGROWTH_SCAN_RUNNING ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			array(
				'user_id'  => $user,
				'meta_key' => 'undergrowth_scan_until', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		$response = $this->scan();

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_an_empty_draft_is_refused_without_using_the_turn() {
		$response = $this->scan(
			0,
			array(
				'title'   => ' ',
				'content' => '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->',
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'undergrowth_scan_empty', $response->get_data()['code'] );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( 200, $this->scan()->get_status() );
	}

	public function test_someone_who_cannot_edit_the_post_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->assertSame( 403, $this->call( 'GET' )->get_status() );
		$this->assertSame( 403, $this->scan()->get_status() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_readers_get_nothing() {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->call( 'GET' )->get_status() );
		$this->assertSame( 401, $this->scan()->get_status() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_accept_links_the_words_in_the_paragraph_and_stores_nothing() {
		$response = $this->call(
			'POST',
			'/accept',
			array(
				'target'     => $this->ledger,
				'sentence'   => self::DRAFT_TEXT,
				'offset'     => 11,
				'length'     => 11,
				'paragraphs' => array(
					array(
						'id'      => 'p',
						'content' => self::DRAFT_TEXT,
					),
				),
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'p', $data['id'] );
		$this->assertSame( 'I keep the <a href="' . esc_url( get_permalink( $this->ledger ) ) . '">seed ledger</a> by the door.', $data['content'] );
		$this->assertSame( array(), get_post_meta( $this->draft, 'undergrowth_pending_links', false ) );
		$this->assertSame( 0, $this->rows_for_draft( undergrowth_links_table(), 'source_id' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_accept_refuses_words_that_are_not_in_the_sentence() {
		$response = $this->call(
			'POST',
			'/accept',
			array(
				'target'     => $this->ledger,
				'sentence'   => self::DRAFT_TEXT,
				'offset'     => 30,
				'length'     => 20,
				'paragraphs' => array(
					array(
						'id'      => 'p',
						'content' => self::DRAFT_TEXT,
					),
				),
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'undergrowth_no_word', $response->get_data()['code'] );
	}

	public function test_accept_refuses_a_target_outside_the_garden() {
		$response = $this->call(
			'POST',
			'/accept',
			array(
				'target'     => $this->draft,
				'sentence'   => self::DRAFT_TEXT,
				'offset'     => 11,
				'length'     => 11,
				'paragraphs' => array(
					array(
						'id'      => 'p',
						'content' => self::DRAFT_TEXT,
					),
				),
			)
		);

		$this->assertSame( 'undergrowth_gone', $response->get_data()['code'] );
	}
}
