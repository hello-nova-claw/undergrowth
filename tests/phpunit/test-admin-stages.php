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

	use Stops_Redirects;

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

	public function test_the_stage_sits_beside_the_title_as_a_post_state() {
		$oca = self::factory()->post->create(
			array(
				'post_title'  => 'Oca',
				'post_status' => 'publish',
			)
		);
		undergrowth_set_stage( $oca, 'sprout' );

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<t[hd] class=.post column-post has-row-actions column-primary. data-colname="Post"[^>]*><strong><a class="row-title" href="[^"]+">Oca</a> &mdash; <span class="post-state">Sprout</span></strong>#', $html );
		$this->assertStringNotContainsString( 'column-stage', $html );
		$this->assertStringNotContainsString( 'column-actions', $html );
	}

	public function test_pin_is_a_row_action_with_its_stage_choice() {
		$oca = self::factory()->post->create(
			array(
				'post_title'  => 'Oca',
				'post_status' => 'publish',
			)
		);

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<div class="row-actions visible"><span class=\'pin\'><label for="undergrowth-stage-' . $oca . '" class="screen-reader-text">Stage to pin for Oca</label><select id="undergrowth-stage-' . $oca . '" name="stage" form="undergrowth-pin-' . $oca . '">.*?</select> <button type="submit" form="undergrowth-pin-' . $oca . '" class="button-link" aria-label="Pin the stage of Oca">Pin</button></span></div>#', $html );
		$this->assertMatchesRegularExpression( '#<form method="post" action="[^"]+" id="undergrowth-pin-' . $oca . '">#', $html );
	}

	public function test_an_untitled_post_is_named_as_core_names_it() {
		self::factory()->post->create(
			array(
				'post_title'  => '',
				'post_status' => 'publish',
			)
		);

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<a class="row-title" href="[^"]+">\(no title\)</a>#', $html );
		$this->assertStringContainsString( 'aria-label="Pin the stage of (no title)"', $html );
	}

	public function test_translators_can_put_the_stage_before_the_title() {
		self::factory()->post->create(
			array(
				'post_title'  => 'Oca',
				'post_status' => 'publish',
			)
		);
		add_filter(
			'gettext_undergrowth',
			static function ( $translation, $text ) {
				return '%1$s &mdash; %2$s' === $text ? '%2$s: %1$s' : $translation;
			},
			10,
			2
		);

		$this->assertMatchesRegularExpression( '#<strong><span class="post-state">Seedling</span>: <a class="row-title" href="[^"]+">Oca</a></strong>#', $this->render() );
	}

	public function test_unpin_is_a_row_action() {
		$oca = self::factory()->post->create(
			array(
				'post_title'  => 'Oca',
				'post_status' => 'publish',
			)
		);
		undergrowth_pin_stage( $oca, 'evergreen' );

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<div class="row-actions visible"><span class=\'unpin\'><button type="submit" form="undergrowth-unpin-' . $oca . '" class="button-link" aria-label="Unpin the stage of Oca">Unpin</button></span></div>#', $html );
		$this->assertStringNotContainsString( 'class="button"', $html );
	}

	public function test_a_post_the_user_cannot_edit_has_a_plain_title_and_no_row_actions() {
		$oca = self::factory()->post->create(
			array(
				'post_title'  => 'Oca',
				'post_status' => 'publish',
			)
		);
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) use ( $oca ) {
				return 'edit_post' === $cap && isset( $args[0] ) && $oca === (int) $args[0] ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);

		$html = $this->render();

		$this->assertStringContainsString( '<strong>Oca &mdash; <span class="post-state">Seedling</span></strong>', $html );
		$this->assertStringNotContainsString( 'form="undergrowth-pin-' . $oca . '"', $html );
		$this->assertStringNotContainsString( 'form="undergrowth-unpin-' . $oca . '"', $html );
	}

	public function test_screen_stylesheet_loads_on_the_suggestions_screen_only() {
		undergrowth_enqueue_screen_style( 'index.php' );
		$this->assertFalse( wp_style_is( 'undergrowth-screen', 'enqueued' ) );

		undergrowth_enqueue_screen_style( 'posts_page_undergrowth-links' );
		$this->assertTrue( wp_style_is( 'undergrowth-screen', 'enqueued' ) );
		$this->assertStringStartsWith( plugins_url( 'assets/', UNDERGROWTH_FILE ), wp_styles()->registered['undergrowth-screen']->src );

		wp_dequeue_style( 'undergrowth-screen' );
		wp_deregister_style( 'undergrowth-screen' );
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

		$this->assertStringContainsString( 'aria-current="page">Suggestions<', $html );
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

	public function test_stages_tab_lists_nothing_when_no_post_type_is_indexed() {
		self::factory()->post->create(
			array(
				'post_title'  => 'Oca',
				'post_status' => 'publish',
			)
		);
		add_filter( 'undergrowth_post_types', '__return_empty_array' );

		$html = $this->render();

		$this->assertStringNotContainsString( 'wp-list-table', $html );
		$this->assertStringNotContainsString( 'value="undergrowth_pin"', $html );
		$this->assertStringContainsString( 'No published posts yet', $html );
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

	/**
	 * Makes a metadata operation fail for one key.
	 *
	 * @param string $operation 'add', 'update' or 'delete'.
	 * @param string $key       Meta key.
	 */
	private function fail_meta( string $operation, string $key ): void {
		add_filter(
			$operation . '_post_metadata',
			static function ( $check, $object_id, $meta_key ) use ( $key ) {
				return $key === $meta_key ? false : $check;
			},
			10,
			3
		);
	}

	public function test_pin_left_behind_by_a_failed_pin_has_its_own_notice() {
		$oca = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->fail_meta( 'add', 'undergrowth_stage_log' );
		$this->fail_meta( 'delete', 'undergrowth_stage_pinned' );

		$this->assertStringContainsString( 'undergrowth_notice=stage_pin_left', $this->submit( 'pin', $oca, 'sprout' ) );
	}

	public function test_pin_lost_by_a_failed_unpin_has_its_own_notice() {
		$oca = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		undergrowth_pin_stage( $oca, 'sprout' );
		$this->fail_meta( 'add', 'undergrowth_stage_log' );
		$this->fail_meta( 'add', 'undergrowth_stage_pinned' );
		$this->fail_meta( 'update', 'undergrowth_stage_pinned' );

		$this->assertStringContainsString( 'undergrowth_notice=stage_pin_lost', $this->submit( 'unpin', $oca ) );
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
			'stage_pin_left'   => array( 'stage_pin_left', 'Unpin the post to clear it' ),
			'stage_pin_lost'   => array( 'stage_pin_lost', 'Pin the stage again to keep it' ),
		);
	}

	/**
	 * Publishes one post at each stage, some of them more than once, and
	 * posts the stages table leaves out.
	 *
	 * @return void
	 */
	private function plant_garden(): void {
		$stages = array( 'seedling', 'sprout', 'sprout', 'budding', 'budding', 'budding', 'evergreen', 'composting', 'composting' );

		foreach ( $stages as $i => $stage ) {
			$post_id = self::factory()->post->create(
				array(
					'post_title'  => 'Post ' . $i,
					'post_status' => 'publish',
				)
			);

			if ( 'seedling' !== $stage ) {
				undergrowth_set_stage( $post_id, $stage );
			}
		}

		// No stage stored and an unknown one both read as seedling.
		$unknown = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $unknown, 'undergrowth_stage', 'wilting' );
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		undergrowth_set_stage( $draft, 'evergreen' );
		$locked = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		undergrowth_set_stage( $locked, 'evergreen' );
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
	}

	public function test_stage_counts_cover_the_posts_the_tab_lists() {
		$this->plant_garden();

		$this->assertSame(
			array(
				'seedling'   => 3,
				'sprout'     => 2,
				'budding'    => 3,
				'evergreen'  => 1,
				'composting' => 2,
			),
			undergrowth_count_stages()
		);
	}

	public function test_post_with_two_stage_rows_is_counted_once_by_the_stage_it_reads_as() {
		$oca = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		add_post_meta( $oca, 'undergrowth_stage', 'budding' );
		add_post_meta( $oca, 'undergrowth_stage', 'evergreen' );

		$counts = undergrowth_count_stages();

		$this->assertSame( 'budding', undergrowth_get_stage( $oca ) );
		$this->assertSame( 1, $counts['budding'] );
		$this->assertSame( 1, array_sum( $counts ) );
	}

	public function test_stage_counts_are_zero_when_no_post_type_is_indexed() {
		$this->plant_garden();
		add_filter( 'undergrowth_post_types', '__return_empty_array' );

		$this->assertSame( array( 0, 0, 0, 0, 0 ), array_values( undergrowth_count_stages() ) );
	}

	public function test_stages_tab_sums_up_the_garden_with_composting_apart() {
		$this->plant_garden();

		$html = $this->render();

		$this->assertMatchesRegularExpression(
			'/<p class="undergrowth-stage-counts"><span>3 posts are seedlings\.<\/span> <span>2 posts are sprouts\.<\/span> <span>3 posts are budding\.<\/span> <span>1 post is evergreen\.<\/span><\/p>/',
			$html
		);
		$this->assertStringContainsString( '<p class="undergrowth-composting-count"><span>2 posts are composting.</span> <span>Composting posts rest outside the garden', $html );
	}

	public function test_stages_tab_leaves_out_stages_with_no_posts() {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$html = $this->render();

		// Zeros on a new site would read like a scorecard.
		$this->assertStringContainsString( '<p class="undergrowth-stage-counts"><span>1 post is a seedling.</span></p>', $html );
		$this->assertStringNotContainsString( '0 posts', $html );
		$this->assertStringNotContainsString( 'undergrowth-composting-count', $html );
	}

	public function test_stages_tab_shows_no_counts_without_published_posts() {
		$this->assertStringNotContainsString( 'undergrowth-stage-counts', $this->render() );
	}

	public function test_stages_tab_shows_no_counts_when_every_post_is_composting() {
		undergrowth_compost_post( self::factory()->post->create( array( 'post_status' => 'publish' ) ) );

		$html = $this->render();

		$this->assertStringNotContainsString( 'undergrowth-stage-counts', $html );
		$this->assertStringContainsString( 'Every published post is composting', $html );
	}

	public function test_stage_counts_are_formatted_for_the_locale() {
		global $wp_locale;

		$separator                                 = $wp_locale->number_format['thousands_sep'];
		$wp_locale->number_format['thousands_sep'] = '.';

		try {
			$this->assertSame( '1.200 posts are seedlings.', undergrowth_stage_count_sentence( 'seedling', 1200 ) );
		} finally {
			$wp_locale->number_format['thousands_sep'] = $separator;
		}
	}
}
