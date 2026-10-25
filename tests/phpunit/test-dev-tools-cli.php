<?php
/**
 * Tests for the dev tools' WP-CLI commands.
 *
 * @package Undergrowth
 */

/**
 * The accept, run-cycle and reset commands.
 */
class Test_Dev_Tools_CLI extends WP_UnitTestCase {

	use Plants_Posts;

	/**
	 * Keeps what the commands print.
	 *
	 * @var Undergrowth_Test_CLI_Logger
	 */
	private $logger;

	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'WP_CLI' ) ) {
			$this->markTestSkipped( 'WP-CLI is not installed with Composer.' );
		}

		$this->logger = new Undergrowth_Test_CLI_Logger();
		WP_CLI::set_logger( $this->logger );
		$this->capture_exit( true );
		$this->start_planting();
		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		if ( class_exists( 'WP_CLI' ) ) {
			$this->capture_exit( false );
		}

		delete_option( 'undergrowth_dev_job' );
		wp_unschedule_hook( 'undergrowth_dev_job' );
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Makes WP-CLI throw where it would end the process, as its own tests do.
	 *
	 * @param bool $capture Whether to throw instead of exiting.
	 */
	private function capture_exit( bool $capture ): void {
		$property = new ReflectionProperty( 'WP_CLI', 'capture_exit' );

		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}

		$property->setValue( null, $capture );
	}

	/**
	 * Publishes Oca with suggestions to Yacon and Mashua, Yacon nearer.
	 *
	 * @return int[] Oca, Yacon and Mashua.
	 */
	private function garden(): array {
		$yacon  = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon syrup is a gentle sweetener. The yacon root stores well.</p>' ) );
		$mashua = $this->plant( 'Mashua', array( 0.8, 0.0, 0.3 ), array( 'post_content' => '<p>Mashua is a climbing tuber. Mashua flowers are orange.</p>' ) );
		$oca    = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<!-- wp:paragraph --><p>Oca grows in the Andes. I keep a few yacon plants nearby for their syrup. Mashua flowers climb the fence beside them.</p><!-- /wp:paragraph -->' ) );

		return array( $oca, $yacon, $mashua );
	}

	/**
	 * Runs a command as WP-CLI would.
	 *
	 * @param string               $name       Command name, after "undergrowth".
	 * @param array<string, mixed> $assoc_args Options.
	 * @return bool True when the command ran to its end; false when it stopped.
	 */
	private function run_command( string $name, array $assoc_args = array() ): bool {
		try {
			call_user_func( 'undergrowth_cli_' . str_replace( '-', '_', $name ), array(), $assoc_args );
		} catch ( WP_CLI\ExitException $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns what a command printed, one line each, with its kind.
	 *
	 * @return string
	 */
	private function output(): string {
		return implode(
			"\n",
			array_map(
				static function ( $line ) {
					return $line[0] . ': ' . $line[1];
				},
				$this->logger->lines
			)
		);
	}

	/**
	 * Counts the links table's rows in a state.
	 *
	 * @param string $state 'proposed', 'accepted' or 'vetoed'.
	 * @return int
	 */
	private function rows( string $state ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE state = %s', undergrowth_links_table(), $state ) );
	}

	/**
	 * Environment types, and whether the commands belong there.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function environments(): array {
		return array(
			'local'       => array( 'local', true ),
			'development' => array( 'development', true ),
			'staging'     => array( 'staging', false ),
			'production'  => array( 'production', false ),
		);
	}

	/**
	 * The commands are added on local and development sites only.
	 *
	 * @dataProvider environments
	 *
	 * @param string $environment Environment type.
	 * @param bool   $expected    Whether the commands belong there.
	 */
	public function test_the_commands_are_added_only_where_they_belong( string $environment, bool $expected ) {
		add_filter( 'undergrowth_dev_tools_enabled', '__return_true' );
		$before = did_action( 'undergrowth_dev_tools_registered' );

		undergrowth_register_dev_tools( $environment );

		$this->assertSame( $expected, did_action( 'undergrowth_dev_tools_registered' ) > $before );
		$this->assertSame( 10, has_action( 'undergrowth_dev_tools_registered', 'undergrowth_register_dev_cli' ) );
	}

	public function test_the_filter_turns_the_commands_off() {
		add_filter( 'undergrowth_dev_tools_enabled', '__return_false' );
		$before = did_action( 'undergrowth_dev_tools_registered' );

		undergrowth_register_dev_tools( 'local' );

		$this->assertSame( $before, did_action( 'undergrowth_dev_tools_registered' ) );
	}

	public function test_accept_needs_all_so_nothing_is_accepted_by_accident() {
		undergrowth_register_dev_tools( 'local' );
		$this->garden();
		$proposed = $this->rows( 'proposed' );

		$this->assertFalse( $this->run_command( 'accept' ) );
		$this->assertSame( $proposed, $this->rows( 'proposed' ) );
		$this->assertStringContainsString( 'error: ', $this->output() );
	}

	public function test_accept_all_accepts_every_suggestion_through_accept() {
		undergrowth_register_dev_tools( 'local' );
		list( $oca, $yacon, $mashua ) = $this->garden();
		$proposed                     = $this->rows( 'proposed' );

		$this->assertTrue( $this->run_command( 'accept', array( 'all' => true ) ) );

		$this->assertSame( 0, $this->rows( 'proposed' ) );
		$this->assertSame( $proposed, $this->rows( 'accepted' ) );
		$this->assertStringContainsString( '<a href="' . esc_url( get_permalink( $mashua ) ) . '">', get_post( $oca )->post_content );
		$this->assertStringContainsString( 'success: Accepted ' . $proposed . ' ', $this->output() );
		unset( $yacon );
	}

	public function test_accept_all_can_stop_at_the_best_in_each_post() {
		undergrowth_register_dev_tools( 'local' );
		list( $oca, $yacon, $mashua ) = $this->garden();

		$this->run_command(
			'accept',
			array(
				'all'      => true,
				'per-post' => '1',
			)
		);

		$this->assertSame( 'accepted', undergrowth_link_state( $oca, $yacon ) );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $mashua ) );
	}

	public function test_a_dry_run_lists_what_would_be_accepted_and_changes_nothing() {
		undergrowth_register_dev_tools( 'local' );
		list( $oca, $yacon, $mashua ) = $this->garden();
		$before                       = get_post( $oca )->post_content;
		$proposed                     = $this->rows( 'proposed' );

		$this->assertTrue(
			$this->run_command(
				'accept',
				array(
					'all'     => true,
					'dry-run' => true,
				)
			)
		);

		$this->assertSame( $before, get_post( $oca )->post_content );
		$this->assertSame( $proposed, $this->rows( 'proposed' ) );
		$this->assertNull( undergrowth_dev_job() );
		$this->assertStringContainsString( 'Oca → Yacon', $this->output() );
		$this->assertStringContainsString( 'Oca → Mashua', $this->output() );
		$this->assertStringContainsString( 'Would accept ' . $proposed . ' ', $this->output() );
		unset( $yacon, $mashua );
	}

	/**
	 * Commands, with options that would otherwise make them run.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public function commands(): array {
		return array(
			'accept'    => array( 'accept', array( 'all' => true ) ),
			'run-cycle' => array( 'run-cycle', array() ),
			'reset'     => array( 'reset', array( 'yes' => true ) ),
		);
	}

	/**
	 * Each command runs only as an administrator, as the tab does.
	 *
	 * @dataProvider commands
	 *
	 * @param string               $name       Command.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function test_every_command_needs_an_administrator( string $name, array $assoc_args ) {
		undergrowth_register_dev_tools( 'local' );
		list( $oca ) = $this->garden();
		$before      = get_post( $oca )->post_content;
		wp_set_current_user( 0 );

		$this->assertFalse( $this->run_command( $name, $assoc_args ) );
		$this->assertStringContainsString( '--user', $this->output() );
		$this->assertSame( $before, get_post( $oca )->post_content );
		$this->assertFalse( undergrowth_has_clusters() );
	}

	public function test_run_cycle_runs_the_cycle_and_what_it_chains_to_the_end() {
		undergrowth_register_dev_tools( 'local' );
		// The monthly event is scheduled on init, which an earlier test may have undone.
		undergrowth_schedule_stage_cycle();
		list( $oca, $yacon, $mashua ) = $this->garden();

		$this->assertTrue( $this->run_command( 'run-cycle' ) );

		$this->assertTrue( undergrowth_has_clusters() );

		foreach ( array( $oca, $yacon, $mashua ) as $post_id ) {
			$this->assertTrue( metadata_exists( 'post', $post_id, 'undergrowth_stage' ) );
		}

		foreach ( array( 'undergrowth_cluster_cycle', 'undergrowth_stage_cycle', 'undergrowth_resurfacing_cycle' ) as $hook ) {
			foreach ( (array) _get_cron_array() as $events ) {
				foreach ( $events[ $hook ] ?? array() as $event ) {
					$this->assertNotFalse( $event['schedule'], $hook . ' is still queued.' );
				}
			}
		}

		// The monthly event itself is left as it was.
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_cluster_cycle' ) );
		$this->assertStringContainsString( 'success: ', $this->output() );
	}

	public function test_run_cycle_says_so_when_a_clustering_batch_is_already_running() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		undergrowth_register_dev_tools( 'local' );
		$this->garden();
		WP_Upgrader::create_lock( 'undergrowth_cluster_cycle', HOUR_IN_SECONDS );

		$ran = $this->run_command( 'run-cycle' );

		WP_Upgrader::release_lock( 'undergrowth_cluster_cycle' );

		$this->assertFalse( $ran );
		$this->assertFalse( undergrowth_has_clusters() );
		$this->assertStringContainsString( 'already running', $this->output() );
		$this->assertStringNotContainsString( 'success: ', $this->output() );
	}

	public function test_reset_undoes_what_accept_did() {
		undergrowth_register_dev_tools( 'local' );
		list( $oca ) = $this->garden();
		$before      = get_post( $oca )->post_content;
		$this->run_command( 'accept', array( 'all' => true ) );
		$accepted = $this->rows( 'accepted' );

		$this->assertTrue( $this->run_command( 'reset', array( 'yes' => true ) ) );

		$this->assertSame( $before, get_post( $oca )->post_content );
		$this->assertSame( 0, $this->rows( 'accepted' ) );
		$this->assertGreaterThan( 0, $this->rows( 'proposed' ) );
		$this->assertStringContainsString( 'Links removed: ' . $accepted, $this->output() );
	}

	public function test_reset_can_forget_vectors_too() {
		global $wpdb;

		undergrowth_register_dev_tools( 'local' );
		$this->garden();

		$this->run_command(
			'reset',
			array(
				'yes'            => true,
				'forget-vectors' => true,
			)
		);

		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', undergrowth_vectors_table() ) ) );
		$this->assertStringContainsString( 'Vectors forgotten: 3', $this->output() );
	}

	public function test_a_command_is_refused_while_the_tab_runs_a_job() {
		undergrowth_register_dev_tools( 'local' );
		$this->garden();
		add_filter( 'undergrowth_dev_job_budget', '__return_zero' );
		undergrowth_start_dev_job( 'accept' );
		undergrowth_run_dev_job( 0.0 );

		$this->assertFalse( $this->run_command( 'reset', array( 'yes' => true ) ) );
		$this->assertSame( 'accept', undergrowth_dev_job()['type'] );
	}

	public function test_run_cycle_is_refused_while_the_tab_runs_a_job() {
		undergrowth_register_dev_tools( 'local' );
		$this->garden();
		add_filter( 'undergrowth_dev_job_budget', '__return_zero' );
		undergrowth_start_dev_job( 'reset' );
		undergrowth_run_dev_job( 0.0 );

		$this->assertFalse( $this->run_command( 'run-cycle' ) );
		$this->assertFalse( undergrowth_has_clusters() );
		$this->assertStringContainsString( 'still running', $this->output() );
	}
}
