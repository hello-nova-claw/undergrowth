<?php
/**
 * Tests for the growth stages table on the link suggestions screen.
 *
 * @package Undergrowth
 */

/**
 * Growth stages table, pin and unpin.
 */
class Test_Admin_Stages extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		unset( $_POST['post'], $_POST['stage'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['undergrowth_notice'], $_GET['tab'] );

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
	 * Renders the screen's stages tab and returns its HTML.
	 *
	 * @return string
	 */
	private function render(): string {
		$_GET['tab'] = 'stages';
		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();

		return (string) ob_get_clean();
	}

	/**
	 * Fills a stage form and calls its handler.
	 *
	 * @param string $action  'pin' or 'unpin'.
	 * @param int    $post_id Post ID.
	 * @param string $stage   Stage to pin, for the pin form.
	 * @return string Redirect location.
	 * @throws WPDieException When the handler refuses the request.
	 */
	private function submit( string $action, int $post_id, string $stage = '' ): string {
		$_POST['post']        = (string) $post_id;
		$_POST['stage']       = $stage;
		$nonce                = wp_create_nonce( 'undergrowth_' . $action . '_' . $post_id );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		try {
			call_user_func( 'undergrowth_handle_' . $action );
		} catch ( WPDieException $e ) {
			throw $e;
		} catch ( Exception $e ) {
			return $e->getMessage();
		}

		return '';
	}

	public function test_stage_labels_are_translatable_names() {
		$this->assertSame( 'Sprout', undergrowth_stage_label( 'sprout' ) );
		$this->assertSame( 'Composting', undergrowth_stage_label( 'composting' ) );
	}

	public function test_screen_lists_each_published_post_with_its_stage() {
		$oca   = self::factory()->post->create(
			array(
				'post_title'  => 'Oca',
				'post_status' => 'publish',
			)
		);
		$draft = self::factory()->post->create(
			array(
				'post_title'  => 'Unfinished',
				'post_status' => 'draft',
			)
		);
		undergrowth_set_stage( $oca, 'sprout' );

		$html = $this->render();

		$this->assertStringContainsString( 'Growth stages', $html );
		$this->assertStringContainsString( 'undergrowth-stages', $html );
		$this->assertStringContainsString( '>Oca<', $html );
		$this->assertStringContainsString( 'Sprout', $html );
		$this->assertStringNotContainsString( 'Unfinished', $html );
		$this->assertStringNotContainsString( 'undergrowth-pinned', $html );
	}

	public function test_stages_tab_explains_when_there_are_no_posts() {
		$html = $this->render();

		$this->assertStringNotContainsString( 'wp-list-table', $html );
		$this->assertStringContainsString( 'No published posts yet', $html );
	}

	public function test_screen_offers_both_tabs_and_marks_the_current_one() {
		$html = $this->render();

		$this->assertStringContainsString( 'nav-tab-wrapper', $html );
		$this->assertMatchesRegularExpression( '/<a href="[^"]*tab=stages[^"]*" class="nav-tab nav-tab-active" aria-current="page">Growth stages<\/a>/', $html );
		$this->assertStringNotContainsString( 'name="undergrowth_suggest_links"', $html );
	}

	public function test_suggestions_tab_is_the_default() {
		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="undergrowth_suggest_links"', $html );
		$this->assertStringNotContainsString( 'undergrowth-stages', $html );
	}

	public function test_post_the_user_cannot_edit_is_listed_without_controls() {
		$admin_post = self::factory()->post->create(
			array(
				'post_title'  => 'Private notes',
				'post_status' => 'publish',
				'post_author' => self::factory()->user->create( array( 'role' => 'administrator' ) ),
			)
		);
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) use ( $admin_post ) {
				return 'edit_post' === $cap && isset( $args[0] ) && $admin_post === (int) $args[0] ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);

		$html = $this->render();

		$this->assertStringContainsString( 'Private notes', $html );
		$this->assertStringNotContainsString( 'value="undergrowth_pin"', $html );
	}

	public function test_unpinned_post_offers_a_labelled_stage_choice_and_pin_button() {
		$oca = self::factory()->post->create(
			array(
				'post_title'  => 'Oca',
				'post_status' => 'publish',
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'value="undergrowth_pin"', $html );
		$this->assertStringContainsString( 'for="undergrowth-stage-' . $oca . '"', $html );
		$this->assertStringContainsString( 'Stage to pin for Oca', $html );
		$this->assertStringContainsString( 'Pin the stage of Oca', $html );
		$this->assertStringNotContainsString( 'value="composting"', $html );
	}

	public function test_pinned_post_shows_the_marker_and_an_unpin_button() {
		$oca = self::factory()->post->create(
			array(
				'post_title'  => 'Oca',
				'post_status' => 'publish',
			)
		);
		undergrowth_pin_stage( $oca, 'evergreen' );

		$html = $this->render();

		$this->assertStringContainsString( 'undergrowth-pinned', $html );
		$this->assertStringContainsString( '<span class="screen-reader-text">Pinned</span>', $html );
		$this->assertStringContainsString( 'Unpin the stage of Oca', $html );
		$this->assertStringNotContainsString( 'value="undergrowth_pin"', $html );
	}

	public function test_composting_post_shows_its_stage_and_no_pin_control() {
		$oca = self::factory()->post->create(
			array(
				'post_title'  => 'Oca',
				'post_status' => 'publish',
			)
		);
		undergrowth_compost_post( $oca );

		$html = $this->render();

		$this->assertStringContainsString( 'Composting', $html );
		$this->assertStringNotContainsString( 'value="undergrowth_pin"', $html );
		$this->assertStringNotContainsString( 'value="undergrowth_unpin"', $html );
	}

	public function test_pin_form_pins_and_returns_with_a_notice() {
		$oca = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$location = $this->submit( 'pin', $oca, 'budding' );

		$this->assertStringContainsString( 'undergrowth_notice=pinned', $location );
		$this->assertStringContainsString( 'tab=stages', $location );
		$this->assertSame( 'budding', undergrowth_get_stage( $oca ) );
		$this->assertTrue( undergrowth_is_stage_pinned( $oca ) );
	}

	public function test_unpin_form_unpins_and_returns_with_a_notice() {
		$oca = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		undergrowth_pin_stage( $oca, 'budding' );

		$location = $this->submit( 'unpin', $oca );

		$this->assertStringContainsString( 'undergrowth_notice=unpinned', $location );
		$this->assertFalse( undergrowth_is_stage_pinned( $oca ) );
		$this->assertSame( 'seedling', undergrowth_get_stage( $oca ) );
	}

	/**
	 * Each refusal returns with its own notice, and changes nothing.
	 *
	 * @dataProvider data_refusals
	 *
	 * @param string $action Form action.
	 * @param string $setup  What to do to the post first.
	 * @param string $stage  Stage posted.
	 * @param string $notice Notice expected.
	 */
	public function test_refused_stage_form_returns_with_a_notice( string $action, string $setup, string $stage, string $notice ) {
		$oca = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		if ( 'compost' === $setup ) {
			undergrowth_compost_post( $oca );
		} elseif ( 'draft' === $setup ) {
			wp_update_post(
				array(
					'ID'          => $oca,
					'post_status' => 'draft',
				)
			);
		}

		$this->assertStringContainsString( 'undergrowth_notice=' . $notice, $this->submit( $action, $oca, $stage ) );
		$this->assertFalse( undergrowth_is_stage_pinned( $oca ) );
	}

	/**
	 * Refused stage forms.
	 *
	 * @return array<string, string[]>
	 */
	public function data_refusals(): array {
		return array(
			'unknown stage'   => array( 'pin', '', 'wilting', 'stage_refused' ),
			'composting post' => array( 'pin', 'compost', 'sprout', 'stage_composting' ),
			'unpublished'     => array( 'pin', 'draft', 'sprout', 'gone' ),
			'not pinned'      => array( 'unpin', '', '', 'not_pinned' ),
		);
	}

	public function test_stage_that_cannot_be_saved_returns_with_a_notice() {
		$oca = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		add_filter(
			'add_post_metadata',
			static function ( $check, $object_id, $meta_key ) {
				return 'undergrowth_stage_log' === $meta_key ? false : $check;
			},
			10,
			3
		);

		$this->assertStringContainsString( 'undergrowth_notice=stage_not_saved', $this->submit( 'pin', $oca, 'sprout' ) );
	}

	public function test_stage_form_without_a_valid_nonce_is_refused() {
		$oca                  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$_POST['post']        = (string) $oca;
		$_POST['stage']       = 'sprout';
		$_POST['_wpnonce']    = 'nope';
		$_REQUEST['_wpnonce'] = 'nope';

		$this->expectException( WPDieException::class );

		undergrowth_handle_pin();
	}

	public function test_stage_form_for_a_post_the_user_cannot_edit_is_refused() {
		$oca = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		try {
			$this->submit( 'pin', $oca, 'sprout' );
			$this->fail( 'The form was not refused.' );
		} catch ( WPDieException $e ) {
			$this->assertFalse( undergrowth_is_stage_pinned( $oca ) );
		}
	}

	/**
	 * Each stage action has its own notice.
	 *
	 * @dataProvider data_notices
	 *
	 * @param string $notice  Notice key.
	 * @param string $message Part of the message.
	 */
	public function test_screen_shows_the_notice_after_a_stage_action( string $notice, string $message ) {
		$_GET['undergrowth_notice'] = $notice;

		$this->assertStringContainsString( $message, $this->render() );
	}

	/**
	 * Notices the stage forms return with.
	 *
	 * @return array<string, string[]>
	 */
	public function data_notices(): array {
		return array(
			'pinned'           => array( 'pinned', 'pinned' ),
			'unpinned'         => array( 'unpinned', 'measured again' ),
			'stage_refused'    => array( 'stage_refused', 'cannot be pinned' ),
			'stage_composting' => array( 'stage_composting', 'composting post' ),
			'not_pinned'       => array( 'not_pinned', 'not pinned' ),
			'stage_not_saved'  => array( 'stage_not_saved', 'could not be saved' ),
		);
	}
}
