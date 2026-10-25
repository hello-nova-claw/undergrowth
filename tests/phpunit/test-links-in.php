<?php
/**
 * Tests for the per-post view of the suggestions that would link to a post.
 *
 * @package Undergrowth
 */

/**
 * Posts that could link here: the Suggestions table narrowed to the
 * suggestions whose target is one post, with the same actions.
 */
class Test_Links_In extends WP_UnitTestCase {

	use Plants_Posts;
	use Stops_Redirects;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		delete_option( 'undergrowth_suggest_links' );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		unset( $_GET['undergrowth_to'], $_GET['choose'], $_POST['source'], $_POST['target'], $_REQUEST['_wpnonce'], $_REQUEST['_wp_http_referer'] );
		undergrowth_delete_memory_folder();
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Plants oca and three posts that each mention it, so each of the three
	 * has a suggestion to oca.
	 *
	 * @return int[] Oca, then the three posts that could link to it.
	 */
	private function garden(): array {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca is a tuber from the Andes.</p>' ) );

		return array(
			$oca,
			$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon is sweet. I grow oca beside it.</p>' ) ),
			$this->plant( 'Ulluco', array( 0.88, 0.0, 0.12 ), array( 'post_content' => '<p>Ulluco is bright. Oca shares its bed.</p>' ) ),
			$this->plant( 'Mashua', array( 0.86, 0.07, 0.07 ), array( 'post_content' => '<p>Mashua is peppery. Oca is milder.</p>' ) ),
		);
	}

	/**
	 * Renders the Suggestions tab, narrowed to one post when given.
	 *
	 * @param int $to Post the suggestions would link to, or 0 for all.
	 * @return string
	 */
	private function render( int $to = 0 ): string {
		if ( $to > 0 ) {
			$_GET['undergrowth_to'] = (string) $to;
		}

		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();

		return (string) ob_get_clean();
	}

	public function test_the_view_lists_only_the_suggestions_that_would_link_to_the_post() {
		list( $oca, $yacon, $ulluco, $mashua ) = $this->garden();

		$html = $this->render( $oca );

		foreach ( array( $yacon, $ulluco, $mashua ) as $source ) {
			$this->assertStringContainsString( 'value="' . $source . '-' . $oca . '"', $html );
		}

		$this->assertSame( 3, substr_count( $html, 'name="link[]"' ) );
		// Each source's own sentence, with its actions.
		$this->assertStringContainsString( 'I grow <mark', $html );
		$this->assertStringContainsString( 'form="undergrowth-accept-' . $yacon . '-' . $oca . '"', $html );
		$this->assertStringContainsString( 'form="undergrowth-veto-' . $ulluco . '-' . $oca . '"', $html );
		$this->assertStringContainsString( 'undergrowth_to=' . $oca, $html );
	}

	public function test_the_view_is_named_after_the_post_and_leads_back_to_every_suggestion() {
		list( $oca ) = $this->garden();

		$html = $this->render( $oca );

		$this->assertMatchesRegularExpression( '/<h2>[^<]*<bdi>Oca<\/bdi>[^<]*<\/h2>/', $html );
		$this->assertStringContainsString( 'href="' . esc_url( undergrowth_suggestions_url() ) . '"', $html );
	}

	public function test_a_post_no_suggestion_points_to_says_so() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		$html = $this->render( $oca );

		$this->assertStringNotContainsString( 'name="link[]"', $html );
		$this->assertStringContainsString( 'No other post is suggested to link to', $html );
	}

	public function test_an_address_naming_a_post_that_is_not_read_shows_every_suggestion() {
		list( $oca, $yacon ) = $this->garden();
		$draft               = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$html = $this->render( $draft );

		$this->assertStringContainsString( 'value="' . $yacon . '-' . $oca . '"', $html );
		$this->assertStringContainsString( 'value="' . $oca . '-' . $yacon . '"', $html );
	}

	public function test_accepting_from_the_view_returns_to_the_view() {
		list( $oca, $yacon )          = $this->garden();
		$_POST['source']              = (string) $yacon;
		$_POST['target']              = (string) $oca;
		$_REQUEST['_wpnonce']         = wp_create_nonce( 'undergrowth_accept_' . $yacon . '_' . $oca );
		$_REQUEST['_wp_http_referer'] = '/wp-admin/edit.php?page=undergrowth-links&undergrowth_to=' . $oca;

		try {
			undergrowth_handle_accept();
			$this->fail( 'Expected a redirect.' );
		} catch ( WPDieException $e ) {
			throw $e;
		} catch ( Exception $redirect ) {
			$this->assertStringContainsString( 'undergrowth_to=' . $oca, $redirect->getMessage() );
			$this->assertStringContainsString( 'undergrowth_notice=accepted', $redirect->getMessage() );
		}
	}

	public function test_a_handler_reached_from_the_whole_list_returns_to_the_whole_list() {
		$this->assertSame( undergrowth_suggestions_url( 'vetoed' ), undergrowth_suggestions_return_url( 'vetoed' ) );
	}

	public function test_choose_words_keeps_the_view() {
		list( $oca, $yacon ) = $this->garden();

		$this->assertSame( 1, preg_match( '/<a href="([^"]*choose=' . $yacon . '-' . $oca . '[^"]*)"/', $this->render( $oca ), $link ) );
		$this->assertStringContainsString( 'undergrowth_to=' . $oca, $link[1] );
	}

	public function test_the_posts_list_row_leads_to_the_view() {
		list( $oca ) = $this->garden();

		$actions = undergrowth_compost_row_actions( array(), get_post( $oca ) );

		$this->assertArrayHasKey( 'undergrowth_links_in', $actions );
		$this->assertStringContainsString( esc_url( undergrowth_links_in_url( $oca ) ), $actions['undergrowth_links_in'] );
		$this->assertStringContainsString( 'aria-label="', $actions['undergrowth_links_in'] );
	}

	public function test_the_row_action_is_only_for_users_who_can_open_the_screen() {
		list( $oca ) = $this->garden();
		$author      = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_update_post(
			array(
				'ID'          => $oca,
				'post_author' => $author,
			)
		);
		wp_set_current_user( $author );

		$this->assertArrayNotHasKey( 'undergrowth_links_in', undergrowth_compost_row_actions( array(), get_post( $oca ) ) );
	}

	public function test_the_editor_panel_gets_the_view_s_address_when_the_user_can_open_it() {
		list( $oca ) = $this->garden();
		$request     = new WP_REST_Request( 'GET', '/undergrowth/v1/posts/' . $oca . '/links' );
		$request->set_url_params( array( 'id' => $oca ) );

		$this->assertSame( undergrowth_links_in_url( $oca ), undergrowth_rest_get_links( $request )->get_data()['incoming_screen'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertNull( undergrowth_rest_get_links( $request )->get_data()['incoming_screen'] );
	}
}
