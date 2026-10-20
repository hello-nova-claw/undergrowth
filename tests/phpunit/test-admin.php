<?php
/**
 * Tests for the link suggestions screen.
 *
 * @package Undergrowth
 */

/**
 * Link suggestions screen, veto and toggle.
 */
class Test_Admin extends WP_UnitTestCase {

	use Plants_Posts;

	/**
	 * Editor who can see the screen.
	 *
	 * @var int
	 */
	private $editor;

	public function set_up() {
		parent::set_up();

		// Menus registered by an earlier test would otherwise still be listed.
		$GLOBALS['submenu'] = array();

		$this->start_planting();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );
		delete_option( 'undergrowth_suggest_links' );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		unset( $_POST['source'], $_POST['target'], $_POST['_wpnonce'], $_POST['undergrowth_suggest_links'], $_REQUEST['_wpnonce'] );
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Stops a redirect so the handler's exit never runs.
	 *
	 * @param string $location Redirect target.
	 * @throws Exception Always, carrying the location.
	 */
	public function stop_redirect( $location ) {
		throw new Exception( esc_url_raw( $location ) );
	}

	/**
	 * Renders the screen and returns its HTML.
	 *
	 * @return string
	 */
	private function render(): string {
		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();

		return (string) ob_get_clean();
	}

	/**
	 * Calls a form handler and returns where it redirected.
	 *
	 * @param callable $handler Handler function.
	 * @return string Redirect location.
	 * @throws WPDieException When the handler refuses the request.
	 */
	private function submit( callable $handler ): string {
		try {
			$handler();
		} catch ( WPDieException $e ) {
			throw $e;
		} catch ( Exception $e ) {
			return $e->getMessage();
		}

		return '';
	}

	/**
	 * Fills the veto form fields.
	 *
	 * @param int $source Source post ID.
	 * @param int $target Target post ID.
	 */
	private function veto_form( int $source, int $target ): void {
		$_POST['source']      = (string) $source;
		$_POST['target']      = (string) $target;
		$nonce                = wp_create_nonce( 'undergrowth_veto_' . $source . '_' . $target );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
	}

	public function test_screen_is_listed_under_posts_for_editors() {
		do_action( 'admin_menu' );

		$this->assertStringContainsString( 'edit.php?page=undergrowth-links', menu_page_url( 'undergrowth-links', false ) );
	}

	public function test_screen_is_not_offered_to_authors() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		do_action( 'admin_menu' );

		$this->assertSame( '', menu_page_url( 'undergrowth-links', false ) );
	}

	public function test_suggestions_are_on_by_default() {
		$this->assertTrue( undergrowth_suggestions_enabled() );
	}

	public function test_turned_off_suggestions_create_no_proposals() {
		update_option( 'undergrowth_suggest_links', '0' );

		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$this->assertSame( array(), $this->proposed( $oca ) );
		$this->assertNotNull( undergrowth_get_vector( $oca ) );
	}

	public function test_vetoing_marks_the_row_and_rewrites_the_file() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$this->assertTrue( undergrowth_veto_link( $oca, $yacon ) );
		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
		$this->assertStringContainsString( "## vetoes\n- " . get_permalink( $yacon ), (string) file_get_contents( undergrowth_memory_path( $oca ) ) );
	}

	public function test_only_a_proposed_link_can_be_vetoed() {
		$this->assertFalse( undergrowth_veto_link( 1, 2 ) );
	}

	public function test_veto_form_vetoes_and_returns_to_the_list_with_a_notice() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->veto_form( $oca, $yacon );

		$location = $this->submit( 'undergrowth_handle_veto' );

		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
		$this->assertStringContainsString( 'page=undergrowth-links', $location );
		$this->assertStringContainsString( 'undergrowth_notice=vetoed', $location );
	}

	public function test_veto_form_without_a_valid_nonce_is_refused() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->veto_form( $oca, $yacon );
		$_POST['_wpnonce']    = 'forged';
		$_REQUEST['_wpnonce'] = 'forged';

		$this->expectException( WPDieException::class );
		$this->submit( 'undergrowth_handle_veto' );
	}

	public function test_veto_form_for_a_post_the_user_cannot_edit_is_refused() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->veto_form( $oca, $yacon );

		try {
			$this->submit( 'undergrowth_handle_veto' );
			$this->fail( 'The veto was not refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
		}
	}

	public function test_toggle_form_saves_the_choice() {
		$nonce                = wp_create_nonce( 'undergrowth_toggle' );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$location = $this->submit( 'undergrowth_handle_toggle' );

		$this->assertFalse( undergrowth_suggestions_enabled() );
		$this->assertStringContainsString( 'undergrowth_notice=saved', $location );

		$_POST['undergrowth_suggest_links'] = '1';
		$this->submit( 'undergrowth_handle_toggle' );

		$this->assertTrue( undergrowth_suggestions_enabled() );
	}

	public function test_toggle_form_is_refused_for_authors() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$nonce                = wp_create_nonce( 'undergrowth_toggle' );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$this->expectException( WPDieException::class );
		$this->submit( 'undergrowth_handle_toggle' );
	}

	public function test_screen_lists_each_proposal_with_its_sentence_and_a_labelled_veto() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$html = $this->render();

		$this->assertStringContainsString( 'Notes about Oca.', $html );
		$this->assertStringContainsString( esc_url( (string) get_edit_post_link( $oca, 'raw' ) ), $html );
		$this->assertMatchesRegularExpression( '/<button[^>]*aria-label="[^"]*Oca[^"]*Yacon[^"]*"/', $html );
	}

	public function test_screen_only_lists_proposals_the_user_can_edit() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_author' => $author ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		wp_set_current_user( $author );

		set_current_screen( 'posts_page_undergrowth-links' );
		undergrowth_load_list_table();
		$table = new Undergrowth_Links_List_Table();
		$table->prepare_items();

		$this->assertCount( 1, $table->items );
		$this->assertSame( 'Oca', get_the_title( $table->items[0]['source_id'] ) );
	}

	public function test_screen_explains_when_no_provider_is_set() {
		remove_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'options-general.php?page=undergrowth', $html );
		$this->assertStringNotContainsString( '<table', $html );
	}

	public function test_screen_says_when_suggestions_are_off() {
		update_option( 'undergrowth_suggest_links', '0' );

		$this->assertStringContainsString( 'Suggestions are off', $this->render() );
	}

	public function test_screen_explains_when_there_are_no_proposals_yet() {
		$this->assertStringContainsString( 'No link suggestions yet', $this->render() );
	}

	public function test_screen_shows_the_notice_after_a_veto() {
		$_GET['undergrowth_notice'] = 'vetoed';

		$html = $this->render();
		unset( $_GET['undergrowth_notice'] );

		$this->assertStringContainsString( 'won’t be suggested again', $html );
		$this->assertStringContainsString( 'id="undergrowth-notice"', $html );
	}

	public function test_screen_is_empty_for_users_without_access() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_uninstall_removes_the_toggle() {
		update_option( 'undergrowth_suggest_links', '0' );

		undergrowth_uninstall_site();

		$this->assertFalse( get_option( 'undergrowth_suggest_links' ) );
	}
}
