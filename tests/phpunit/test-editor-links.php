<?php
/**
 * Tests for the link suggestions sidebar in the block editor.
 *
 * @package Undergrowth
 */

/**
 * Editor links routes and the save that accepts a link.
 */
class Test_Editor_Links extends WP_UnitTestCase {

	use Plants_Posts;

	const SENTENCE = 'I keep yacon syrup beside the seed ledger.';

	/**
	 * Post the links sit in.
	 *
	 * @var int
	 */
	private $source;

	/**
	 * Post the links point to.
	 *
	 * @var int
	 */
	private $target;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->source = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Yacon',
				'post_content' => $this->paragraph( self::SENTENCE ),
			)
		);
		$this->target = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Seed ledger',
			)
		);
		$this->row( $this->source, $this->target, 'proposed', self::SENTENCE, 'seed ledger' );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;

		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Wraps text in a paragraph block.
	 *
	 * @param string $html Inner HTML.
	 * @return string Block markup.
	 */
	private function paragraph( string $html ): string {
		return "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Stores a link row.
	 *
	 * @param int    $source_id Source post ID.
	 * @param int    $target_id Target post ID.
	 * @param string $state     State.
	 * @param string $sentence  Sentence.
	 * @param string $anchor    Words to link, found in the sentence.
	 */
	private function row( int $source_id, int $target_id, string $state, string $sentence = self::SENTENCE, string $anchor = '' ): void {
		global $wpdb;

		$offset = '' === $anchor ? -1 : (int) mb_strpos( $sentence, $anchor );

		$wpdb->insert(
			undergrowth_links_table(),
			array(
				'source_id'     => $source_id,
				'target_id'     => $target_id,
				'state'         => $state,
				'score'         => 0.5,
				'sentence'      => $sentence,
				'anchor'        => $anchor,
				'anchor_offset' => $offset,
				'created_at'    => current_time( 'mysql', true ),
				'updated_at'    => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Calls one of the links routes.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route after the post, such as '' or '/12/accept'.
	 * @param array<string, mixed> $body   JSON body.
	 * @param int                  $post   Post the route is for. Defaults to the source.
	 * @return WP_REST_Response
	 */
	private function call( string $method, string $route = '', array $body = array(), int $post = 0 ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/undergrowth/v1/posts/' . ( $post ? $post : $this->source ) . '/links' . $route );

		if ( array() !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Asks the accept route to link the target in the given paragraphs.
	 *
	 * @param array<string, string> $paragraphs Paragraph HTML keyed by block ID.
	 * @return WP_REST_Response
	 */
	private function accept( array $paragraphs ): WP_REST_Response {
		$list = array();

		foreach ( $paragraphs as $id => $content ) {
			$list[] = array(
				'id'      => (string) $id,
				'content' => $content,
			);
		}

		return $this->call( 'POST', '/' . $this->target . '/accept', array( 'paragraphs' => $list ) );
	}

	/**
	 * Saves the source post with new content, as the editor does.
	 *
	 * @param string $content Post content.
	 */
	private function save( string $content ): void {
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $this->source,
					'post_content' => $content,
				)
			)
		);
	}

	/**
	 * Lists the targets whose links wait for the source post to be saved.
	 *
	 * @return int[]
	 */
	private function pending_targets(): array {
		return array_map( 'intval', array_column( get_post_meta( $this->source, 'undergrowth_pending_links', false ), 'target' ) );
	}

	public function test_lists_this_posts_suggestions_with_their_words() {
		$response = $this->call( 'GET' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data['outgoing'] );
		$this->assertSame( $this->target, $data['outgoing'][0]['target'] );
		$this->assertSame( 'Seed ledger', $data['outgoing'][0]['title'] );
		$this->assertSame( get_permalink( $this->target ), $data['outgoing'][0]['url'] );
		$this->assertSame( self::SENTENCE, $data['outgoing'][0]['sentence'] );
		$this->assertSame(
			array(
				'offset' => mb_strpos( self::SENTENCE, 'seed ledger' ),
				'length' => 11,
			),
			$data['outgoing'][0]['span']
		);
		$this->assertSame( array(), $data['incoming'] );
	}

	public function test_lists_the_posts_that_could_link_here() {
		$other = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Oca & mashua',
			)
		);
		$this->row( $other, $this->target, 'proposed' );

		$data = $this->call( 'GET', '', array(), $this->target )->get_data();

		$this->assertSame( array( $this->source, $other ), array_column( $data['incoming'], 'source' ) );
		$this->assertSame( 'Oca & mashua', $data['incoming'][1]['title'] );
		$this->assertSame( get_edit_post_link( $other, 'raw' ), $data['incoming'][1]['edit_url'] );
		$this->assertSame( array(), $data['outgoing'] );
	}

	public function test_leaves_out_decided_and_composting_suggestions() {
		$vetoed   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$accepted = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$resting  = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->row( $this->source, $vetoed, 'vetoed' );
		$this->row( $this->source, $accepted, 'accepted' );
		$this->row( $this->source, $resting, 'proposed' );
		undergrowth_set_stage( $resting, 'composting', 'compost' );

		$this->assertSame( array( $this->target ), array_column( $this->call( 'GET' )->get_data()['outgoing'], 'target' ) );
	}

	public function test_a_sentence_no_longer_in_the_post_has_no_words() {
		global $wpdb;

		$wpdb->update( undergrowth_links_table(), array( 'anchor' => '' ), array( 'source_id' => $this->source ) );
		$this->save( $this->paragraph( 'I rewrote all of it.' ) );

		$this->assertNull( $this->call( 'GET' )->get_data()['outgoing'][0]['span'] );
	}

	public function test_offers_the_sentences_words_to_choose_from() {
		$item = $this->call( 'GET' )->get_data()['outgoing'][0];

		$this->assertSame( array( 'I', 'keep', 'yacon', 'syrup', 'beside', 'the', 'seed', 'ledger' ), $item['words'] );
		$this->assertSame( 6, $item['first'] );
		$this->assertSame( 7, $item['last'] );
		$this->assertSame( undergrowth_sentence_version( self::SENTENCE ), $item['version'] );
	}

	public function test_choose_words_saves_them_as_the_suggestions_tab_does() {
		$response = $this->call(
			'POST',
			'/' . $this->target . '/words',
			array(
				'first' => 2,
				'last'  => 3,
				'shown' => undergrowth_sentence_version( self::SENTENCE ),
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( undergrowth_link_result_messages()['words_saved'], $data['message'] );
		$this->assertSame(
			array(
				'offset' => mb_strpos( self::SENTENCE, 'yacon' ),
				'length' => 11,
			),
			$data['span']
		);
		$this->assertSame( 2, $data['first'] );
		$this->assertSame( 3, $data['last'] );
		$this->assertSame( 'yacon syrup', undergrowth_link_row( $this->source, $this->target )['anchor'] );
	}

	public function test_choose_words_refuses_as_the_suggestions_tab_does() {
		$response = $this->call(
			'POST',
			'/' . $this->target . '/words',
			array(
				'first' => 3,
				'last'  => 2,
				'shown' => undergrowth_sentence_version( self::SENTENCE ),
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'undergrowth_words_invalid', $response->get_data()['code'] );

		$response = $this->call(
			'POST',
			'/' . $this->target . '/words',
			array(
				'first' => 0,
				'last'  => 1,
				'shown' => 'an older sentence',
			)
		);

		$this->assertSame( 'undergrowth_edited', $response->get_data()['code'] );
		$this->assertSame( 'seed ledger', undergrowth_link_row( $this->source, $this->target )['anchor'] );
	}

	public function test_accept_links_the_stored_words_in_the_paragraph_without_saving() {
		$before   = get_post_field( 'post_content', $this->source );
		$response = $this->accept(
			array(
				'first'  => 'Nothing to see here.',
				'second' => 'I keep <em>yacon</em> syrup beside the seed ledger.',
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'second', $data['id'] );
		$this->assertSame( 'I keep <em>yacon</em> syrup beside the <a href="' . esc_url( get_permalink( $this->target ) ) . '">seed ledger</a>.', $data['content'] );
		$this->assertNotEmpty( $data['message'] );

		// The editor saves the post; the route changes nothing stored.
		$this->assertSame( $before, get_post_field( 'post_content', $this->source ) );
		$this->assertSame( 'proposed', undergrowth_link_state( $this->source, $this->target ) );
	}

	public function test_saving_with_the_link_accepts_it() {
		$content = $this->accept( array( 'p' => self::SENTENCE ) )->get_data()['content'];

		$this->save( $this->paragraph( $content ) );

		$this->assertSame( 'accepted', undergrowth_link_state( $this->source, $this->target ) );
		$this->assertSame( array(), get_post_meta( $this->source, 'undergrowth_pending_links', false ) );
	}

	public function test_a_failed_claim_keeps_the_link_pending_for_the_next_save() {
		$content = $this->accept( array( 'p' => self::SENTENCE ) )->get_data()['content'];
		$table   = undergrowth_links_table();
		$fail    = static function ( $query ) use ( $table ) {
			return 0 === strpos( ltrim( $query ), 'UPDATE `' . $table . '`' ) ? '' : $query;
		};

		add_filter( 'query', $fail );
		$this->save( $this->paragraph( $content ) );
		remove_filter( 'query', $fail );

		$this->assertSame( 'proposed', undergrowth_link_state( $this->source, $this->target ) );
		$this->assertSame( array( $this->target ), $this->pending_targets() );

		$this->save( $this->paragraph( $content ) );

		$this->assertSame( 'accepted', undergrowth_link_state( $this->source, $this->target ) );
	}

	public function test_undo_with_another_link_to_the_target_leaves_it_proposed() {
		$this->accept( array( 'p' => self::SENTENCE ) );

		// The suggested words stay unlinked, but the author links the target
		// from words of their own.
		$this->save( $this->paragraph( self::SENTENCE . ' See <a href="' . esc_url( get_permalink( $this->target ) ) . '">my notes</a>.' ) );

		$this->assertSame( 'proposed', undergrowth_link_state( $this->source, $this->target ) );
		$this->assertSame( array(), $this->pending_targets() );
	}

	public function test_saving_the_accepted_words_with_their_formatting_accepts_it() {
		$content = $this->accept( array( 'p' => 'I keep <em>yacon</em> syrup beside the seed ledger.' ) )->get_data()['content'];

		$this->save( $this->paragraph( $content ) );

		$this->assertSame( 'accepted', undergrowth_link_state( $this->source, $this->target ) );
	}

	public function test_saving_after_undo_leaves_it_proposed() {
		$this->accept( array( 'p' => self::SENTENCE ) );

		$this->save( $this->paragraph( self::SENTENCE . ' And one more.' ) );

		$this->assertSame( 'proposed', undergrowth_link_state( $this->source, $this->target ) );
		$this->assertSame( array(), get_post_meta( $this->source, 'undergrowth_pending_links', false ) );
	}

	public function test_a_link_added_by_hand_is_not_counted() {
		$this->save( $this->paragraph( 'I keep yacon syrup beside the <a href="' . esc_url( get_permalink( $this->target ) ) . '">seed ledger</a>.' ) );

		$this->assertSame( 'proposed', undergrowth_link_state( $this->source, $this->target ) );
	}

	public function test_a_revision_leaves_the_pending_link_for_the_real_save() {
		$this->accept( array( 'p' => self::SENTENCE ) );

		_wp_put_post_revision( get_post( $this->source ), true );

		$this->assertSame( array( $this->target ), $this->pending_targets() );
	}

	/**
	 * Refuses to link where accept.php would refuse.
	 *
	 * @dataProvider data_unsafe_paragraphs
	 *
	 * @param string $paragraph Paragraph HTML as the editor holds it.
	 * @param string $code      Expected error code.
	 */
	public function test_accept_keeps_every_safety_rule( string $paragraph, string $code ) {
		$response = $this->accept( array( 'p' => $paragraph ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
		$this->assertSame( undergrowth_link_result_messages()[ substr( $code, strlen( 'undergrowth_' ) ) ], $response->get_data()['message'] );
		$this->assertSame( array(), get_post_meta( $this->source, 'undergrowth_pending_links', false ) );
	}

	/**
	 * Paragraphs the stored words cannot be linked in.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function data_unsafe_paragraphs(): array {
		return array(
			'sentence edited'    => array( 'I keep yacon syrup near the seed ledger.', 'undergrowth_edited' ),
			'words already link' => array( 'I keep yacon syrup beside the <a href="https://example.org/">seed ledger</a>.', 'undergrowth_no_word' ),
			'words split by tag' => array( 'I keep yacon syrup beside the <em>seed</em> ledger.', 'undergrowth_no_word' ),
		);
	}

	public function test_accept_never_links_inside_a_hyphenated_word() {
		global $wpdb;

		$sentence = 'I keep yacon syrup beside the self-ledger.';
		$wpdb->update(
			undergrowth_links_table(),
			array(
				'sentence'      => $sentence,
				'anchor'        => 'ledger',
				'anchor_offset' => mb_strpos( $sentence, 'ledger' ),
			),
			array( 'source_id' => $this->source )
		);

		$this->assertSame( 'undergrowth_no_word', $this->accept( array( 'p' => $sentence ) )->get_data()['code'] );
	}

	public function test_accept_refuses_a_suggestion_that_is_not_proposed() {
		undergrowth_veto_link( $this->source, $this->target );

		$response = $this->accept( array( 'p' => self::SENTENCE ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'undergrowth_not_proposed', $response->get_data()['code'] );
	}

	public function test_accept_refuses_a_target_that_left_the_garden() {
		wp_update_post(
			array(
				'ID'          => $this->target,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( 'undergrowth_gone', $this->accept( array( 'p' => self::SENTENCE ) )->get_data()['code'] );
	}

	public function test_accept_needs_paragraphs() {
		$this->assertSame( 400, $this->call( 'POST', '/' . $this->target . '/accept', array( 'paragraphs' => 'no' ) )->get_status() );
	}

	public function test_veto_stores_a_veto() {
		$response = $this->call( 'POST', '/' . $this->target . '/veto' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( undergrowth_link_result_messages()['vetoed'], $response->get_data()['message'] );
		$this->assertSame( 'vetoed', undergrowth_link_state( $this->source, $this->target ) );
	}

	public function test_veto_leaves_an_accepted_link_alone() {
		undergrowth_claim_link( $this->source, $this->target, 'proposed', 'accepted' );

		$response = $this->call( 'POST', '/' . $this->target . '/veto' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'undergrowth_unchanged', $response->get_data()['code'] );
		$this->assertSame( 'accepted', undergrowth_link_state( $this->source, $this->target ) );
	}

	public function test_someone_who_cannot_edit_the_post_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->assertSame( 403, $this->call( 'GET' )->get_status() );
		$this->assertSame( 403, $this->accept( array( 'p' => self::SENTENCE ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/' . $this->target . '/veto' )->get_status() );
		$this->assertSame(
			403,
			$this->call(
				'POST',
				'/' . $this->target . '/words',
				array(
					'first' => 2,
					'last'  => 3,
					'shown' => undergrowth_sentence_version( self::SENTENCE ),
				)
			)->get_status()
		);
		$this->assertSame( 'proposed', undergrowth_link_state( $this->source, $this->target ) );
		$this->assertSame( 'seed ledger', undergrowth_link_row( $this->source, $this->target )['anchor'] );
	}

	public function test_readers_get_nothing() {
		wp_set_current_user( 0 );

		$response = $this->call( 'GET' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertStringNotContainsString( 'seed ledger', (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 401, $this->call( 'POST', '/' . $this->target . '/veto' )->get_status() );
	}
}
