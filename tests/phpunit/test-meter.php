<?php
/**
 * Tests for the threshold meter: views in the last 30 days from Jetpack
 * Stats, against the switch line.
 *
 * @package Undergrowth
 */

use Automattic\Jetpack\Connection\Manager;
use Automattic\Jetpack\Modules;
use Automattic\Jetpack\Stats\WPCOM_Stats;

/**
 * One number on the Dashboard until the line is crossed.
 */
class Test_Meter extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Modules::$active       = array();
		Manager::$connected    = false;
		WPCOM_Stats::$visits   = array();
		WPCOM_Stats::$requests = array();
		delete_transient( 'undergrowth_meter' );

		// Jetpack maps view_stats to the roles chosen in its Stats settings.
		$editor = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$editor->add_cap( 'view_stats' );
		wp_set_current_user( $editor->ID );
	}

	public function tear_down() {
		unset( $GLOBALS['wp_meta_boxes'] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Turns Jetpack Stats on and has it answer with these daily view counts.
	 *
	 * @param int[] $daily Views per day, oldest first.
	 */
	private function stats( array $daily ): void {
		Modules::$active    = array( 'stats' );
		Manager::$connected = true;

		$data = array();

		foreach ( $daily as $i => $views ) {
			$data[] = array( gmdate( 'Y-m-d', strtotime( '2026-09-22 +' . $i . ' days' ) ), $views, (int) ceil( $views / 3 ) );
		}

		WPCOM_Stats::$visits = array(
			'unit'   => 'day',
			'fields' => array( 'period', 'views', 'visitors' ),
			'data'   => $data,
		);
	}

	/**
	 * Returns the IDs of the Dashboard widgets added for the current user.
	 *
	 * @return string[]
	 */
	private function widgets(): array {
		global $wp_meta_boxes;

		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		set_current_screen( 'dashboard' );
		$wp_meta_boxes = array();
		do_action( 'wp_dashboard_setup' );

		$ids = array();

		foreach ( (array) ( $wp_meta_boxes['dashboard'] ?? array() ) as $contexts ) {
			foreach ( (array) $contexts as $boxes ) {
				$ids = array_merge( $ids, array_keys( (array) $boxes ) );
			}
		}

		return $ids;
	}

	/**
	 * Renders the widget and returns its text without markup.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		undergrowth_render_meter_widget();

		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ob_get_clean() ) ) );
	}

	public function test_widget_is_titled_by_what_it_counts() {
		global $wp_meta_boxes;

		$this->widgets();

		$this->assertSame( 'Page views, last 30 days', $wp_meta_boxes['dashboard']['normal']['core']['undergrowth_meter']['title'] );
	}

	public function test_line_is_one_thousand_views() {
		$this->assertSame( 1000, UNDERGROWTH_METER_LINE );
		$this->assertSame( 1000, undergrowth_meter_line() );
	}

	public function test_line_can_be_changed_with_a_filter() {
		add_filter(
			'undergrowth_meter_line',
			static function () {
				return 50;
			}
		);
		$this->stats( array( 60 ) );

		$this->assertSame( 50, undergrowth_meter_line() );
		$this->assertSame( 'crossed', undergrowth_meter_reading()['state'] );
	}

	public function test_a_line_below_one_falls_back_to_the_constant() {
		add_filter(
			'undergrowth_meter_line',
			static function () {
				return 0;
			}
		);

		$this->assertSame( UNDERGROWTH_METER_LINE, undergrowth_meter_line() );
	}

	public function test_a_line_below_one_is_replaced_by_one_thousand() {
		$this->assertSame( 1000, undergrowth_meter_sanitize_line( 0 ) );
		$this->assertSame( 1000, undergrowth_meter_sanitize_line( -5 ) );
		$this->assertSame( 50, undergrowth_meter_sanitize_line( 50 ) );
	}

	public function test_without_jetpack_stats_there_is_no_number_and_no_request() {
		$reading = undergrowth_meter_reading();

		$this->assertSame( 'no-stats', $reading['state'] );
		$this->assertSame( array(), WPCOM_Stats::$requests );
	}

	public function test_stats_module_off_counts_as_missing() {
		Manager::$connected = true;

		$this->assertSame( 'no-stats', undergrowth_meter_reading()['state'] );
		$this->assertSame( array(), WPCOM_Stats::$requests );
	}

	public function test_a_site_not_connected_counts_as_missing() {
		Modules::$active = array( 'stats' );

		$this->assertSame( 'no-stats', undergrowth_meter_reading()['state'] );
		$this->assertSame( array(), WPCOM_Stats::$requests );
	}

	public function test_missing_stats_are_not_cached_so_connecting_shows_at_once() {
		undergrowth_meter_reading();
		$this->stats( array( 10 ) );

		$this->assertSame( 'below', undergrowth_meter_reading()['state'] );
	}

	public function test_widget_without_jetpack_says_what_is_needed() {
		$text = $this->render();

		$this->assertStringContainsString( 'Jetpack', $text );
		$this->assertStringContainsString( 'Stats', $text );
		$this->assertDoesNotMatchRegularExpression( '/\d/', $text );
	}

	public function test_asks_jetpack_for_thirty_days_by_day() {
		$this->stats( array( 1 ) );

		undergrowth_meter_reading();

		$this->assertSame(
			array(
				array(
					'unit'     => 'day',
					'quantity' => 30,
				),
			),
			WPCOM_Stats::$requests
		);
	}

	public function test_below_the_line_sums_the_daily_views() {
		$this->stats( array_fill( 0, 30, 13 ) );

		$reading = undergrowth_meter_reading();

		$this->assertSame( 'below', $reading['state'] );
		$this->assertSame( 390, $reading['views'] );
		$this->assertSame( 1000, $reading['line'] );
	}

	public function test_views_are_found_by_field_name() {
		$this->stats( array() );
		WPCOM_Stats::$visits['fields'] = array( 'period', 'visitors', 'views' );
		WPCOM_Stats::$visits['data']   = array( array( '2026-10-20', 4, 9 ), array( '2026-10-21', 2, 5 ) );

		$this->assertSame( 14, undergrowth_meter_reading()['views'] );
	}

	public function test_widget_below_the_line_shows_one_number_against_the_line() {
		$this->stats( array( 400, 12 ) );

		$text = $this->render();

		$this->assertStringContainsString( '412 of 1,000 views in the last 30 days', $text );
		$this->assertStringContainsString( 'not part of this version', $text );
		$this->assertStringContainsString( 'enough visits', $text );
		$this->assertStringContainsString( 'once a day', $text );
	}

	public function test_numbers_are_formatted_for_the_locale() {
		add_filter(
			'number_format_i18n',
			static function ( $formatted ) {
				return '[' . $formatted . ']';
			}
		);
		$this->stats( array( 412 ) );

		$this->assertStringContainsString( '[412] of [1,000] views', $this->render() );
	}

	public function test_at_the_line_counts_as_crossed() {
		$this->stats( array( 600, 400 ) );

		$this->assertSame( 'crossed', undergrowth_meter_reading()['state'] );
	}

	public function test_widget_over_the_line_says_so_plainly_and_that_nothing_changes_yet() {
		$this->stats( array( 1000, 234 ) );

		$text = $this->render();

		$this->assertStringContainsString( '1,234 views in the last 30 days', $text );
		$this->assertStringContainsString( 'passed the line of 1,000', $text );
		$this->assertStringContainsString( 'nothing changes', $text );
		$this->assertStringContainsString( 'enough visits', $text );
	}

	public function test_jetpack_is_asked_at_most_once_a_day() {
		$this->stats( array( 100 ) );

		undergrowth_meter_reading();
		WPCOM_Stats::$visits['data'][0][1] = 900;

		$reading = undergrowth_meter_reading();

		$this->assertCount( 1, WPCOM_Stats::$requests );
		$this->assertSame( 100, $reading['views'] );
	}

	public function test_count_is_cached_for_a_day() {
		$this->stats( array( 100 ) );
		$before = time();

		undergrowth_meter_reading();

		$timeout = (int) get_option( '_transient_timeout_undergrowth_meter' );
		$this->assertGreaterThanOrEqual( $before + DAY_IN_SECONDS, $timeout );
		$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS, $timeout );
	}

	public function test_a_stale_cache_is_refreshed() {
		$this->stats( array( 700 ) );
		set_transient(
			'undergrowth_meter',
			array(
				'views' => 100,
				'time'  => time() - DAY_IN_SECONDS - 1,
			),
			DAY_IN_SECONDS
		);

		$reading = undergrowth_meter_reading();

		$this->assertCount( 1, WPCOM_Stats::$requests );
		$this->assertSame( 700, $reading['views'] );
		$this->assertSame( 700, get_transient( 'undergrowth_meter' )['views'] );
	}

	public function test_a_cache_that_is_not_ours_is_ignored() {
		$this->stats( array( 700 ) );
		set_transient( 'undergrowth_meter', 'garbage', DAY_IN_SECONDS );

		$this->assertSame( 700, undergrowth_meter_reading()['views'] );
	}

	public function test_a_failed_answer_shows_no_number_and_waits_a_day() {
		Modules::$active     = array( 'stats' );
		Manager::$connected  = true;
		WPCOM_Stats::$visits = new WP_Error( 'stats_error', 'Failed to fetch Stats from WPCOM' );

		$this->assertSame( 'no-answer', undergrowth_meter_reading()['state'] );
		$this->assertSame( 'no-answer', undergrowth_meter_reading()['state'] );
		$this->assertCount( 1, WPCOM_Stats::$requests );

		$text = $this->render();
		$this->assertStringContainsString( 'Jetpack Stats did not answer', $text );
		$this->assertStringNotContainsString( 'of 1,000 views', $text );
	}

	public function test_an_answer_without_views_counts_as_failed() {
		$this->stats( array( 5 ) );
		WPCOM_Stats::$visits['fields'] = array( 'period', 'visitors' );

		$this->assertSame( 'no-answer', undergrowth_meter_reading()['state'] );
	}

	public function test_widget_shows_for_users_who_can_edit_others_posts() {
		$this->assertContains( 'undergrowth_meter', $this->widgets() );
	}

	public function test_widget_is_hidden_from_authors() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertNotContains( 'undergrowth_meter', $this->widgets() );
		$this->assertSame( '', $this->render() );
	}

	public function test_widget_is_hidden_from_editors_jetpack_stats_does_not_show_to() {
		$this->stats( array( 412 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertNotContains( 'undergrowth_meter', $this->widgets() );
		$this->assertSame( '', $this->render() );
		$this->assertSame( array(), WPCOM_Stats::$requests );
	}

	public function test_without_jetpack_editors_see_what_is_needed() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertContains( 'undergrowth_meter', $this->widgets() );
		$this->assertStringContainsString( 'Jetpack', $this->render() );
	}
}
