<?php
/**
 * Tests for the one Undergrowth widget on the Dashboard, which holds the
 * question and, only with Jetpack Stats, the meter line.
 *
 * @package Undergrowth
 */

use Automattic\Jetpack\Connection\Manager;
use Automattic\Jetpack\Modules;
use Automattic\Jetpack\Stats\WPCOM_Stats;

/**
 * Every Undergrowth part of the Dashboard is in one widget named for it.
 */
class Test_Dashboard_Widget extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Modules::$active       = array();
		Manager::$connected    = false;
		WPCOM_Stats::$visits   = array();
		WPCOM_Stats::$requests = array();
		delete_transient( 'undergrowth_meter' );
		delete_option( 'undergrowth_prompt' );
		update_option( 'undergrowth_anthropic_api_key', 'sk-ant-test-0123456789abcd' );

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
	 * Waits a prompt about a new post.
	 */
	private function prompt(): void {
		update_option(
			'undergrowth_prompt',
			array(
				'key'      => 'gap:1',
				'source'   => 'gap',
				'post_id'  => self::factory()->post->create( array( 'post_status' => 'publish' ) ),
				'question' => 'What sits between these posts?',
				'time'     => time(),
			)
		);
	}

	/**
	 * Turns Jetpack Stats on with 412 views.
	 */
	private function stats(): void {
		Modules::$active     = array( 'stats' );
		Manager::$connected  = true;
		WPCOM_Stats::$visits = array(
			'unit'   => 'day',
			'fields' => array( 'period', 'views' ),
			'data'   => array( array( '2026-10-20', 412 ) ),
		);
	}

	/**
	 * Returns the Dashboard widgets added for the current user, by ID.
	 *
	 * @return array<string, array>
	 */
	private function widgets(): array {
		global $wp_meta_boxes;

		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		set_current_screen( 'dashboard' );
		$wp_meta_boxes = array();
		do_action( 'wp_dashboard_setup' );

		$boxes = array();

		foreach ( (array) ( $wp_meta_boxes['dashboard'] ?? array() ) as $contexts ) {
			foreach ( (array) $contexts as $priority ) {
				$boxes = array_merge( $boxes, (array) $priority );
			}
		}

		return $boxes;
	}

	/**
	 * Renders the widget.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		undergrowth_render_dashboard_widget();

		return (string) ob_get_clean();
	}

	public function test_nothing_to_show_means_no_widget() {
		$this->assertSame( array(), array_intersect( array_keys( $this->widgets() ), array( 'undergrowth', 'undergrowth_prompt', 'undergrowth_meter' ) ) );
		$this->assertSame( '', $this->render() );
	}

	public function test_one_widget_named_undergrowth_holds_both_parts() {
		$this->prompt();
		$this->stats();

		$widgets = $this->widgets();

		$this->assertSame( 'Undergrowth', $widgets['undergrowth']['title'] );
		$this->assertArrayNotHasKey( 'undergrowth_prompt', $widgets );
		$this->assertArrayNotHasKey( 'undergrowth_meter', $widgets );

		$html = $this->render();

		$this->assertMatchesRegularExpression( '#<div class="undergrowth-prompt"><h3>A question for you</h3>.*What sits between these posts\?.*</div><div class="undergrowth-meter"><h3>Page views, last 30 days</h3>.*412 of 1,000 views.*</div>#s', $html );
	}

	public function test_the_question_alone() {
		$this->prompt();

		$this->assertArrayHasKey( 'undergrowth', $this->widgets() );

		$html = $this->render();

		$this->assertStringContainsString( '<h3>A question for you</h3>', $html );
		$this->assertStringNotContainsString( 'Page views', $html );
	}

	public function test_the_meter_alone_only_with_jetpack_stats() {
		$this->stats();

		$this->assertArrayHasKey( 'undergrowth', $this->widgets() );

		$html = $this->render();

		$this->assertStringContainsString( '<h3>Page views, last 30 days</h3>', $html );
		$this->assertStringNotContainsString( 'A question for you', $html );
	}

	public function test_a_user_who_can_see_neither_part_gets_no_widget() {
		$this->prompt();
		$this->stats();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertArrayNotHasKey( 'undergrowth', $this->widgets() );
		$this->assertSame( '', $this->render() );
	}
}
