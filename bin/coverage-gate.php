<?php
/**
 * Fails when line coverage in a Clover report is below the committed baseline.
 *
 * Usage: php bin/coverage-gate.php coverage/clover.xml .github/coverage-baseline
 *
 * @package Undergrowth
 */

if ( 3 !== $argc ) {
	fwrite( STDERR, "Usage: php bin/coverage-gate.php <clover.xml> <baseline-file>\n" );
	exit( 2 );
}

$report = simplexml_load_file( $argv[1] );

if ( false === $report || ! isset( $report->project->metrics ) ) {
	fwrite( STDERR, "Could not read coverage metrics from {$argv[1]}\n" );
	exit( 2 );
}

$metrics    = $report->project->metrics;
$statements = (int) $metrics['statements'];
$covered    = (int) $metrics['coveredstatements'];
$coverage   = $statements > 0 ? round( 100 * $covered / $statements, 2 ) : 100.0;
$baseline   = (float) trim( (string) file_get_contents( $argv[2] ) );

printf( "Line coverage %.2f%%, baseline %.2f%%\n", $coverage, $baseline );

if ( $coverage < $baseline ) {
	fwrite( STDERR, "Coverage went down. Add tests, or explain in the pull request why it must drop.\n" );
	exit( 1 );
}

if ( $coverage > $baseline ) {
	printf( "Coverage went up. Raise %s to %.2f in this pull request.\n", $argv[2], $coverage );
}
