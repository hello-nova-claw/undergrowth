<?php
/**
 * Tests for the resurfacing log.
 *
 * @package Undergrowth
 */

/**
 * Resurfacing log.
 */
class Test_Resurfacing extends WP_UnitTestCase {

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	public function tear_down() {
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Returns the lines listed under one heading of a post's file.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $heading Section heading, without the hashes.
	 * @return string[]
	 */
	private function section( int $post_id, string $heading ): array {
		$path = undergrowth_memory_path( $post_id );
		$file = file_exists( $path ) ? (string) file_get_contents( $path ) : '';

		if ( ! preg_match( '/^## ' . preg_quote( $heading, '/' ) . "\n(.*?)(?=\n## |\\z)/ms", $file, $match ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( "\n", $match[1] ) ) ) );
	}

	/**
	 * Plants two related posts and composts the first.
	 *
	 * @return int Post ID of the composted post.
	 */
	private function composted(): int {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		undergrowth_compost_post( $oca );

		return $oca;
	}

	public function test_a_signal_shown_is_logged() {
		$oca = $this->composted();

		$this->assertTrue( undergrowth_log_resurfacing( $oca, 'shown' ) );

		$log = undergrowth_resurfacing_log( $oca );
		$this->assertCount( 1, $log );
		$this->assertSame( 'shown', $log[0]['event'] );
		$this->assertEqualsWithDelta( time(), $log[0]['time'], 5 );
	}

	public function test_a_dismissal_is_logged() {
		$oca = $this->composted();

		undergrowth_log_resurfacing( $oca, 'shown' );
		undergrowth_log_resurfacing( $oca, 'dismissed' );

		$this->assertSame( array( 'shown', 'dismissed' ), wp_list_pluck( undergrowth_resurfacing_log( $oca ), 'event' ) );
	}

	public function test_file_lists_each_event_under_its_own_heading() {
		$oca   = $this->composted();
		$today = wp_date( 'Y-m-d', null, new DateTimeZone( 'UTC' ) );

		undergrowth_log_resurfacing( $oca, 'shown' );
		undergrowth_log_resurfacing( $oca, 'dismissed' );

		$this->assertSame(
			array(
				'- ' . $today . ': signal shown',
				'- ' . $today . ': signal dismissed by the author',
			),
			$this->section( $oca, 'resurfacing' )
		);
	}

	public function test_logging_fires_an_action() {
		$oca = $this->composted();

		undergrowth_log_resurfacing( $oca, 'shown' );

		$this->assertSame( 1, did_action( 'undergrowth_resurfacing_logged' ) );
	}

	public function test_event_that_cannot_be_stored_is_not_announced() {
		$oca = $this->composted();
		add_filter(
			'add_post_metadata',
			static function ( $check, $object_id, $meta_key ) {
				return 'undergrowth_resurfacing_log' === $meta_key ? false : $check;
			},
			10,
			3
		);

		$this->assertFalse( undergrowth_log_resurfacing( $oca, 'shown' ) );
		$this->assertSame( 0, did_action( 'undergrowth_resurfacing_logged' ) );
	}

	public function test_unknown_event_is_refused() {
		$oca = $this->composted();

		$this->assertFalse( undergrowth_log_resurfacing( $oca, 'snoozed' ) );
		$this->assertSame( array(), undergrowth_resurfacing_log( $oca ) );
	}

	public function test_post_that_is_not_composting_is_refused() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		$this->assertFalse( undergrowth_log_resurfacing( $oca, 'shown' ) );
		$this->assertSame( array(), undergrowth_resurfacing_log( $oca ) );
		$this->assertSame( 0, did_action( 'undergrowth_resurfacing_logged' ) );
	}

	public function test_missing_post_is_refused() {
		$this->assertFalse( undergrowth_log_resurfacing( PHP_INT_MAX, 'shown' ) );
	}

	public function test_log_skips_malformed_rows() {
		$oca = $this->composted();

		add_post_meta( $oca, 'undergrowth_resurfacing_log', 'shown' );
		add_post_meta(
			$oca,
			'undergrowth_resurfacing_log',
			array(
				'time'  => 0,
				'event' => 'shown',
			)
		);
		add_post_meta(
			$oca,
			'undergrowth_resurfacing_log',
			array(
				'time'  => time(),
				'event' => 'snoozed',
			)
		);
		undergrowth_log_resurfacing( $oca, 'dismissed' );

		$this->assertSame( array( 'dismissed' ), wp_list_pluck( undergrowth_resurfacing_log( $oca ), 'event' ) );
	}

	public function test_log_stays_after_the_post_returns() {
		$oca = $this->composted();
		undergrowth_log_resurfacing( $oca, 'shown' );

		undergrowth_return_post( $oca );

		$this->assertCount( 1, $this->section( $oca, 'resurfacing' ) );
	}

	public function test_compost_signal_dismissal_and_return_all_appear_in_the_file() {
		$oca   = $this->composted();
		$today = wp_date( 'Y-m-d', null, new DateTimeZone( 'UTC' ) );

		undergrowth_log_resurfacing( $oca, 'shown' );
		undergrowth_log_resurfacing( $oca, 'dismissed' );
		undergrowth_return_post( $oca );

		$this->assertSame(
			array(
				'seedling',
				'- ' . $today . ': seedling -> composting (composted by the author)',
				'- ' . $today . ': composting -> seedling (returned to the garden by the author)',
			),
			$this->section( $oca, 'stage' )
		);
		$this->assertSame(
			array(
				'- ' . $today . ': signal shown',
				'- ' . $today . ': signal dismissed by the author',
			),
			$this->section( $oca, 'resurfacing' )
		);
	}

	public function test_file_mentions_no_expiry_or_due_date() {
		$oca = $this->composted();

		undergrowth_log_resurfacing( $oca, 'shown' );
		undergrowth_log_resurfacing( $oca, 'dismissed' );

		$lines = implode( "\n", array_merge( $this->section( $oca, 'stage' ), $this->section( $oca, 'resurfacing' ) ) );

		$this->assertDoesNotMatchRegularExpression( '/\b(until|expires?|due|days?|remaining|since|return by)\b/i', $lines );
	}

	public function test_log_key_is_protected() {
		$this->assertTrue( is_protected_meta( 'undergrowth_resurfacing_log', 'post' ) );
	}
}
