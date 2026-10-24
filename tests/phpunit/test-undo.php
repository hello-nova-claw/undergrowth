<?php
/**
 * Tests for Undo after Veto and after each "Dismiss for good".
 *
 * @package Undergrowth
 */

/**
 * One keypress that cannot be taken back is not a decision, so each of
 * them offers an Undo in its result notice.
 */
class Test_Undo extends WP_UnitTestCase {

	use Plants_Posts;
	use Stops_Redirects;

	/**
	 * Editor who can see the Suggestions screen.
	 *
	 * @var int
	 */
	private $editor;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );
		delete_option( 'undergrowth_suggest_links' );
		delete_option( 'undergrowth_prompt' );
		delete_option( 'undergrowth_dismissed_prompt' );
		delete_option( 'undergrowth_openai_api_key' );
		delete_option( 'undergrowth_first_run' );
		update_option( 'undergrowth_anthropic_api_key', 'sk-ant-test-0123456789abcd' );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		unset( $_GET['source'], $_GET['target'], $_GET['key'], $_GET['undergrowth_notice'], $_GET['undergrowth_undo'], $_REQUEST['_wpnonce'] );
		undergrowth_delete_memory_folder();
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Calls a handler and returns where it redirected.
	 *
	 * @param callable $handler Handler function.
	 * @return string Redirect location.
	 * @throws WPDieException When the handler refuses the request.
	 */
	private function call( callable $handler ): string {
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
	 * Follows an Undo link's query, as a click on it would.
	 *
	 * @param string $url Undo link from a notice.
	 */
	private function follow( string $url ): void {
		wp_parse_str( (string) wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY ), $query );

		foreach ( $query as $name => $value ) {
			$_GET[ $name ] = $value;
		}

		$_REQUEST['_wpnonce'] = $query['_wpnonce'] ?? '';
	}

	/**
	 * Finds the Undo link in some HTML.
	 *
	 * @param string $html   HTML holding a notice.
	 * @param string $action admin-post.php action the link goes to.
	 * @return string The link's address.
	 */
	private function undo_link( string $html, string $action ): string {
		$this->assertSame( 1, preg_match( '/<a href="([^"]*action=' . $action . '[^"]*)">Undo<\/a>/', $html, $match ), 'No Undo link in: ' . $html );

		return $match[1];
	}

	/**
	 * Renders the Suggestions screen and returns its HTML.
	 *
	 * @return string
	 */
	private function suggestions(): string {
		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();

		return (string) ob_get_clean();
	}

	/**
	 * Returns the Suggestions screen as the redirect after a handler shows it.
	 *
	 * @param string $location Redirect location.
	 * @return string
	 */
	private function arrive( string $location ): string {
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		unset( $_GET['source'], $_GET['target'], $_GET['action'], $_GET['key'] );
		$_GET['undergrowth_notice'] = $query['undergrowth_notice'] ?? '';

		if ( isset( $query['undergrowth_undo'] ) ) {
			$_GET['undergrowth_undo'] = $query['undergrowth_undo'];
		}

		return $this->suggestions();
	}

	/**
	 * Plants two close posts, so the first has the second proposed.
	 *
	 * @return int[] Source and target IDs.
	 */
	private function pair(): array {
		return array(
			$this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) ),
			$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) ),
		);
	}

	/**
	 * Vetoes a pair from its row's form and returns the redirect.
	 *
	 * @param int $source Source post ID.
	 * @param int $target Target post ID.
	 * @return string Redirect location.
	 */
	private function veto( int $source, int $target ): string {
		$_POST['source']      = (string) $source;
		$_POST['target']      = (string) $target;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_veto_' . $source . '_' . $target );

		try {
			return $this->call( 'undergrowth_handle_veto' );
		} finally {
			unset( $_POST['source'], $_POST['target'] );
		}
	}

	public function test_undoing_a_veto_restores_the_proposal_with_its_sentence() {
		global $wpdb;

		list( $oca, $yacon ) = $this->pair();
		$table               = undergrowth_links_table();
		$before              = $wpdb->get_row( $wpdb->prepare( 'SELECT score, sentence FROM %i WHERE source_id = %d AND target_id = %d', $table, $oca, $yacon ), ARRAY_A );

		$this->assertTrue( undergrowth_veto_link( $oca, $yacon ) );
		$this->assertTrue( undergrowth_unveto_link( $oca, $yacon ) );

		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
		$this->assertSame( $before, $wpdb->get_row( $wpdb->prepare( 'SELECT score, sentence FROM %i WHERE source_id = %d AND target_id = %d', $table, $oca, $yacon ), ARRAY_A ) );
		$this->assertStringNotContainsString( "## vetoes\n- ", (string) file_get_contents( undergrowth_memory_path( $oca ) ) );
	}

	public function test_undoing_a_veto_of_a_withdrawn_suggestion_forgets_the_veto() {
		global $wpdb;

		list( $oca, $yacon ) = $this->pair();
		$wpdb->delete(
			undergrowth_links_table(),
			array(
				'source_id' => $oca,
				'target_id' => $yacon,
			)
		);
		$this->assertTrue( undergrowth_veto_link( $oca, $yacon ) );

		$this->assertTrue( undergrowth_unveto_link( $oca, $yacon ) );

		// Nothing was suggested before the veto, so nothing is suggested now,
		// and the next refresh may suggest the pair again.
		$this->assertSame( '', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_only_a_veto_can_be_undone() {
		list( $oca, $yacon ) = $this->pair();

		$this->assertFalse( undergrowth_unveto_link( $oca, $yacon ) );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );

		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $yacon ) );
		$this->assertFalse( undergrowth_unveto_link( $oca, $yacon ) );
		$this->assertSame( 'accepted', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_a_user_who_cannot_edit_the_post_cannot_undo_its_veto() {
		list( $oca, $yacon ) = $this->pair();
		undergrowth_veto_link( $oca, $yacon );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertFalse( undergrowth_unveto_link( $oca, $yacon ) );
		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_a_veto_is_not_undone_once_the_target_has_left_the_index() {
		list( $oca, $yacon ) = $this->pair();
		undergrowth_veto_link( $oca, $yacon );
		wp_update_post(
			array(
				'ID'          => $yacon,
				'post_status' => 'draft',
			)
		);

		$this->assertFalse( undergrowth_unveto_link( $oca, $yacon ) );
		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
		$this->assertStringNotContainsString( 'undergrowth_unveto', $this->arrive( admin_url( 'edit.php?page=undergrowth-links&undergrowth_notice=vetoed&undergrowth_undo=' . $oca . '-' . $yacon ) ) );
	}

	public function test_the_veto_notice_offers_an_undo_that_restores_the_proposal() {
		list( $oca, $yacon ) = $this->pair();

		$html = $this->arrive( $this->veto( $oca, $yacon ) );

		$this->assertStringContainsString( 'won’t be suggested again', $html );
		$this->assertStringContainsString( 'role="status"', $html );

		$this->follow( $this->undo_link( $html, 'undergrowth_unveto' ) );
		$location = $this->call( 'undergrowth_handle_unveto' );

		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
		$this->assertStringContainsString( 'undergrowth_notice=unvetoed', $location );
		$this->assertStringContainsString( 'can be suggested again', $this->arrive( $location ) );
	}

	public function test_the_veto_notice_offers_no_undo_once_the_veto_is_gone() {
		list( $oca, $yacon ) = $this->pair();
		$location            = $this->veto( $oca, $yacon );
		undergrowth_unveto_link( $oca, $yacon );

		$this->assertStringNotContainsString( 'undergrowth_unveto', $this->arrive( $location ) );
	}

	public function test_the_veto_notice_offers_no_undo_for_a_post_the_user_cannot_edit() {
		list( $oca, $yacon ) = $this->pair();
		$location            = $this->veto( $oca, $yacon );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertStringNotContainsString( 'undergrowth_unveto', $this->arrive( $location ) );
	}

	public function test_the_undo_address_is_removed_once_shown() {
		set_current_screen( 'posts_page_undergrowth-links' );

		$this->assertContains( 'undergrowth_undo', wp_removable_query_args() );
	}

	public function test_undoing_a_veto_without_a_valid_nonce_is_refused() {
		list( $oca, $yacon ) = $this->pair();
		undergrowth_veto_link( $oca, $yacon );
		$_GET['source']       = (string) $oca;
		$_GET['target']       = (string) $yacon;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_veto_' . $oca . '_' . $yacon );

		try {
			$this->call( 'undergrowth_handle_unveto' );
			$this->fail( 'The undo was not refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
		}
	}

	public function test_undoing_a_veto_in_a_post_the_user_cannot_edit_is_refused() {
		list( $oca, $yacon ) = $this->pair();
		undergrowth_veto_link( $oca, $yacon );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$_GET['source']       = (string) $oca;
		$_GET['target']       = (string) $yacon;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_unveto_' . $oca . '_' . $yacon );

		try {
			$this->call( 'undergrowth_handle_unveto' );
			$this->fail( 'The undo was not refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
		}
	}

	public function test_undoing_a_veto_that_is_gone_says_nothing_was_changed() {
		list( $oca, $yacon )  = $this->pair();
		$_GET['source']       = (string) $oca;
		$_GET['target']       = (string) $yacon;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_unveto_' . $oca . '_' . $yacon );

		$location = $this->call( 'undergrowth_handle_unveto' );

		$this->assertStringContainsString( 'undergrowth_notice=unveto_gone', $location );
		$this->assertStringContainsString( 'can no longer be undone', $this->arrive( $location ) );
	}

	/**
	 * Waits a prompt about a post the editor can edit.
	 *
	 * @return array<string, mixed> The prompt.
	 */
	private function prompt(): array {
		$prompt = array(
			'key'      => 'gap:1',
			'source'   => 'gap',
			'post_id'  => self::factory()->post->create( array( 'post_status' => 'publish' ) ),
			'question' => 'What sits between these posts?',
			'time'     => time(),
		);

		update_option( 'undergrowth_prompt', $prompt );

		return $prompt;
	}

	/**
	 * Renders the Dashboard's result notice.
	 *
	 * @param string $notice Notice the Dashboard was returned to with.
	 * @return string
	 */
	private function dashboard_notice( string $notice ): string {
		$_GET['undergrowth_notice'] = $notice;
		set_current_screen( 'dashboard' );
		ob_start();
		undergrowth_render_prompt_notice();

		return (string) ob_get_clean();
	}

	public function test_a_dismissed_question_can_be_restored() {
		$prompt = $this->prompt();

		$this->assertTrue( undergrowth_dismiss_prompt( 'gap:1' ) );
		$this->assertNull( undergrowth_current_prompt() );

		$this->assertTrue( undergrowth_restore_prompt( 'gap:1' ) );
		$this->assertSame( $prompt, undergrowth_current_prompt() );
		$this->assertSame( array( 'dismissed', 'restored' ), array_column( undergrowth_prompt_log( $prompt['post_id'] ), 'event' ) );

		// It can be restored once.
		$this->assertFalse( undergrowth_restore_prompt( 'gap:1' ) );
	}

	public function test_a_question_is_not_restored_over_a_newer_one() {
		$this->prompt();
		undergrowth_dismiss_prompt( 'gap:1' );

		$newer = array_merge( $this->prompt(), array( 'key' => 'gap:2' ) );
		update_option( 'undergrowth_prompt', $newer );

		$this->assertFalse( undergrowth_restore_prompt( 'gap:1' ) );
		$this->assertSame( 'gap:2', undergrowth_current_prompt()['key'] );
	}

	public function test_only_the_last_dismissed_question_can_be_restored() {
		$this->prompt();
		undergrowth_dismiss_prompt( 'gap:1' );

		$this->assertFalse( undergrowth_restore_prompt( 'gap:9' ) );
		$this->assertNull( undergrowth_current_prompt() );
	}

	public function test_the_dismissed_notice_offers_an_undo_that_brings_the_question_back() {
		$this->prompt();
		undergrowth_dismiss_prompt( 'gap:1' );

		$html = $this->dashboard_notice( 'prompt_dismissed' );

		$this->assertStringContainsString( 'won’t ask that again', $html );
		$this->assertStringContainsString( 'role="status"', $html );

		$this->follow( $this->undo_link( $html, 'undergrowth_restore_prompt' ) );
		$location = $this->call( 'undergrowth_handle_restore_prompt' );

		$this->assertSame( admin_url( 'index.php?undergrowth_notice=prompt_restored' ), $location );
		$this->assertSame( 'gap:1', undergrowth_current_prompt()['key'] );
		$this->assertStringContainsString( 'The question is back', $this->dashboard_notice( 'prompt_restored' ) );
	}

	public function test_the_dismissed_notice_offers_no_undo_once_another_question_waits() {
		$this->prompt();
		undergrowth_dismiss_prompt( 'gap:1' );
		update_option( 'undergrowth_prompt', array_merge( $this->prompt(), array( 'key' => 'gap:2' ) ) );

		$this->assertStringNotContainsString( 'undergrowth_restore_prompt', $this->dashboard_notice( 'prompt_dismissed' ) );
	}

	public function test_the_dismissed_notice_offers_no_undo_to_a_user_who_cannot_edit_the_post() {
		$this->prompt();
		undergrowth_dismiss_prompt( 'gap:1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertStringNotContainsString( 'undergrowth_restore_prompt', $this->dashboard_notice( 'prompt_dismissed' ) );
	}

	public function test_restoring_a_question_without_a_valid_nonce_is_refused() {
		$this->prompt();
		undergrowth_dismiss_prompt( 'gap:1' );
		$_GET['key']          = 'gap:1';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_prompt_gap:1' );

		try {
			$this->call( 'undergrowth_handle_restore_prompt' );
			$this->fail( 'The undo was not refused.' );
		} catch ( WPDieException $e ) {
			$this->assertNull( undergrowth_current_prompt() );
		}
	}

	public function test_restoring_a_question_about_a_post_the_user_cannot_edit_is_refused() {
		$this->prompt();
		undergrowth_dismiss_prompt( 'gap:1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$_GET['key']          = 'gap:1';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_restore_prompt_gap:1' );

		try {
			$this->call( 'undergrowth_handle_restore_prompt' );
			$this->fail( 'The undo was not refused.' );
		} catch ( WPDieException $e ) {
			$this->assertNull( undergrowth_current_prompt() );
		}
	}

	public function test_restoring_a_question_that_cannot_come_back_says_so() {
		$_GET['key']          = 'gap:1';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_restore_prompt_gap:1' );

		$this->assertSame( admin_url( 'index.php?undergrowth_notice=prompt_gone' ), $this->call( 'undergrowth_handle_restore_prompt' ) );
	}

	public function test_uninstall_forgets_the_dismissed_question() {
		$this->prompt();
		undergrowth_dismiss_prompt( 'gap:1' );

		undergrowth_uninstall_site();

		$this->assertFalse( get_option( 'undergrowth_dismissed_prompt' ) );
	}

	/**
	 * Takes away the planting provider, as on a site with no key yet.
	 */
	private function without_provider(): void {
		remove_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );
	}

	/**
	 * Renders the first-run notices on the Plugins screen.
	 *
	 * @param string $notice Notice the screen was returned to with, if any.
	 * @return string
	 */
	private function plugins_notice( string $notice = '' ): string {
		if ( '' !== $notice ) {
			$_GET['undergrowth_notice'] = $notice;
		}

		set_current_screen( 'plugins' );
		ob_start();
		undergrowth_render_first_run_notice();

		return (string) ob_get_clean();
	}

	public function test_the_first_run_dismissed_notice_offers_an_undo_that_brings_the_notice_back() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'undergrowth_first_run', 'waiting' );
		$this->without_provider();
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_dismiss_first_run' );

		$location = $this->call( 'undergrowth_dismiss_first_run' );

		$this->assertSame( admin_url( 'plugins.php?undergrowth_notice=first_run_dismissed' ), $location );
		$this->assertSame( 'dismissed', get_option( 'undergrowth_first_run' ) );

		$html = $this->plugins_notice( 'first_run_dismissed' );

		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringNotContainsString( 'id="undergrowth-first-run"', $html );

		$this->follow( $this->undo_link( $html, 'undergrowth_restore_first_run' ) );

		$this->assertSame( admin_url( 'plugins.php?undergrowth_notice=first_run_restored' ), $this->call( 'undergrowth_restore_first_run' ) );
		$this->assertSame( 'waiting', get_option( 'undergrowth_first_run' ) );

		$html = $this->plugins_notice( 'first_run_restored' );

		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( 'The notice is back', $html );
		$this->assertStringContainsString( 'id="undergrowth-first-run"', $html );
	}

	public function test_the_first_run_notice_is_not_restored_after_a_key_was_saved_and_removed() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->without_provider();
		update_option( 'undergrowth_first_run', 'dismissed' );
		update_option( 'undergrowth_openai_api_key', 'sk-test-0123456789abcd' );
		update_option( 'undergrowth_openai_api_key', '' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_restore_first_run' );

		$this->assertSame( 'done', get_option( 'undergrowth_first_run' ) );
		$this->assertStringNotContainsString( 'undergrowth_restore_first_run', $this->plugins_notice( 'first_run_dismissed' ) );

		$location = $this->call( 'undergrowth_restore_first_run' );

		$this->assertSame( admin_url( 'plugins.php?undergrowth_notice=first_run_gone' ), $location );
		$this->assertSame( 'done', get_option( 'undergrowth_first_run' ) );
		$this->assertStringContainsString( 'nothing was changed', $this->plugins_notice( 'first_run_gone' ) );
	}

	public function test_the_first_run_notice_is_not_restored_once_a_key_is_saved() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'undergrowth_first_run', 'done' );
		update_option( 'undergrowth_openai_api_key', 'sk-test-0123456789abcd' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_restore_first_run' );

		$this->assertStringNotContainsString( 'undergrowth_restore_first_run', $this->plugins_notice( 'first_run_dismissed' ) );

		$this->call( 'undergrowth_restore_first_run' );

		$this->assertSame( 'done', get_option( 'undergrowth_first_run' ) );
	}

	public function test_a_stale_dismiss_link_leaves_a_saved_key_s_end_in_place() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'undergrowth_first_run', 'done' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_dismiss_first_run' );

		// Nothing changed, so there is no result to announce.
		$this->assertSame( admin_url( 'plugins.php' ), $this->call( 'undergrowth_dismiss_first_run' ) );
		$this->assertSame( 'done', get_option( 'undergrowth_first_run' ) );
	}

	public function test_restoring_the_first_run_notice_without_a_valid_nonce_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->without_provider();
		update_option( 'undergrowth_first_run', 'dismissed' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_dismiss_first_run' );

		try {
			$this->call( 'undergrowth_restore_first_run' );
			$this->fail( 'The undo was not refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'dismissed', get_option( 'undergrowth_first_run' ) );
		}
	}

	public function test_restoring_the_first_run_notice_needs_manage_options() {
		$this->without_provider();
		update_option( 'undergrowth_first_run', 'dismissed' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'undergrowth_restore_first_run' );

		$this->assertSame( '', $this->plugins_notice( 'first_run_dismissed' ) );

		try {
			$this->call( 'undergrowth_restore_first_run' );
			$this->fail( 'The undo was not refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'dismissed', get_option( 'undergrowth_first_run' ) );
		}
	}
}
