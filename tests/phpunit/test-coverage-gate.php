<?php
/**
 * Tests for the coverage gate CI runs, bin/coverage-gate.php.
 *
 * @package Undergrowth
 */

/**
 * Runs the gate on made-up Clover reports and baselines.
 */
class Test_Coverage_Gate extends WP_UnitTestCase {

	/**
	 * Folder for this test's files.
	 *
	 * @var string
	 */
	private $dir = '';

	public function set_up() {
		parent::set_up();

		$this->dir = get_temp_dir() . 'undergrowth-coverage-gate-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir );
	}

	public function tear_down() {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			wp_delete_file( $file );
		}
		rmdir( $this->dir );

		parent::tear_down();
	}

	/**
	 * Runs the gate.
	 *
	 * @param int         $covered    Covered statements, of 10,000.
	 * @param string      $baseline   The pull request's baseline.
	 * @param string|null $base       The base branch's baseline, or null to leave it out.
	 * @return array{0: int, 1: string} Exit code, and everything it printed.
	 */
	private function gate( int $covered, string $baseline, ?string $base = null ): array {
		$clover = $this->dir . '/clover.xml';
		file_put_contents( $clover, '<?xml version="1.0"?><coverage><project><metrics statements="10000" coveredstatements="' . $covered . '"/></project></coverage>' );
		file_put_contents( $this->dir . '/baseline', $baseline . "\n" );

		$args = array( PHP_BINARY, dirname( __DIR__, 2 ) . '/bin/coverage-gate.php', $clover, $this->dir . '/baseline' );

		if ( null !== $base ) {
			file_put_contents( $this->dir . '/base', $base . "\n" );
			$args[] = $this->dir . '/base';
		}

		return $this->run_gate( $args );
	}

	/**
	 * Runs the gate's script in its own PHP process, as CI does.
	 *
	 * @param string[] $args The command and its arguments.
	 * @return array{0: int, 1: string} Exit code, and everything it printed.
	 */
	private function run_gate( array $args ): array {
		$output = array();
		$code   = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- The gate is a command-line script, and its exit code is what CI reads.
		exec( implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1', $output, $code );

		return array( $code, implode( "\n", $output ) );
	}

	public function test_coverage_matching_the_baseline_passes() {
		list( $code, $output ) = $this->gate( 9834, '98.34', '98.34' );

		$this->assertSame( 0, $code, $output );
		$this->assertStringContainsString( 'Statement coverage 98.34%', $output );
	}

	public function test_lowering_the_baseline_alone_fails() {
		list( $code, $output ) = $this->gate( 9834, '98.00', '98.34' );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'The baseline went down from 98.34 to 98.00', $output );
	}

	public function test_lowering_the_baseline_with_the_coverage_fails() {
		list( $code, $output ) = $this->gate( 9800, '98.00', '98.34' );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'The baseline went down', $output );
	}

	public function test_coverage_below_the_base_baseline_fails() {
		list( $code, $output ) = $this->gate( 9820, '98.34', '98.34' );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Coverage went down from 98.34% to 98.20%', $output );
	}

	public function test_coverage_that_went_up_without_the_baseline_fails_with_the_figure_to_set() {
		list( $code, $output ) = $this->gate( 9851, '98.34', '98.34' );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Coverage is 98.51%, so set', $output );
		$this->assertStringContainsString( 'to 98.51 in this pull request', $output );
	}

	public function test_raising_the_baseline_with_the_coverage_passes() {
		list( $code, $output ) = $this->gate( 9851, '98.51', '98.34' );

		$this->assertSame( 0, $code, $output );
	}

	public function test_a_baseline_above_the_coverage_fails() {
		list( $code, $output ) = $this->gate( 9840, '98.60', '98.34' );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'Coverage is 98.40%, so set', $output );
	}

	public function test_without_a_base_baseline_the_baseline_is_the_floor() {
		$this->assertSame( 0, $this->gate( 9834, '98.34' )[0] );
		$this->assertSame( 1, $this->gate( 9820, '98.34' )[0] );
		$this->assertSame( 1, $this->gate( 9851, '98.34' )[0] );
	}

	public function test_a_figure_that_rounds_to_the_baseline_matches_it() {
		list( $code ) = $this->gate( 9834, '98.340', '98.34' );

		$this->assertSame( 0, $code );
	}

	public function test_a_report_with_no_statements_fails() {
		$clover = $this->dir . '/empty.xml';
		file_put_contents( $clover, '<?xml version="1.0"?><coverage><project><metrics statements="0" coveredstatements="0"/></project></coverage>' );
		file_put_contents( $this->dir . '/baseline', "98.34\n" );

		list( $code, $output ) = $this->run_gate( array( PHP_BINARY, dirname( __DIR__, 2 ) . '/bin/coverage-gate.php', $clover, $this->dir . '/baseline' ) );

		$this->assertSame( 1, $code );
		$this->assertStringContainsString( 'lists no statements', $output );
	}
}
