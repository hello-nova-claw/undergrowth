<?php
/**
 * Fails unless statement coverage in a Clover report matches the baseline.
 *
 * Usage: php bin/coverage-gate.php <clover.xml> <baseline-file> [<base-baseline-file>]
 *
 * The baseline file is the pull request's own. The base baseline file is the
 * one on the branch it merges into, so a pull request cannot lower the bar
 * it is measured against. Without it, as on a push, the two are the same.
 *
 * The gate passes only when coverage equals the baseline, to two decimals,
 * and neither is below the base baseline. Coverage that went up without the
 * baseline following would let the next pull request drop it again.
 *
 * @package Undergrowth
 */

if ( 3 !== $argc && 4 !== $argc ) {
	fwrite( STDERR, "Usage: php bin/coverage-gate.php <clover.xml> <baseline-file> [<base-baseline-file>]\n" );
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

// A report with no statements means the source files were not found when it
// was built, not that everything is covered.
if ( 0 === $statements ) {
	fwrite( STDERR, "The coverage report lists no statements. Build it where the source files are available.\n" );
	exit( 1 );
}

// Compared as printed, so a figure that rounds to the baseline matches it.
$coverage = sprintf( '%.2f', 100 * $covered / $statements );
$baseline = sprintf( '%.2f', (float) trim( (string) file_get_contents( $argv[2] ) ) );
$base     = 4 === $argc ? sprintf( '%.2f', (float) trim( (string) file_get_contents( $argv[3] ) ) ) : $baseline;

printf( "Statement coverage %s%%, baseline %s%%, base branch's baseline %s%%\n", $coverage, $baseline, $base );

if ( (float) $baseline < (float) $base ) {
	fwrite( STDERR, "The baseline went down from {$base} to {$baseline}. Put it back to {$base}, and add tests rather than lower it.\n" );
	exit( 1 );
}

if ( (float) $coverage < (float) $base ) {
	fwrite( STDERR, "Coverage went down from {$base}% to {$coverage}%. Add tests for the code this changes.\n" );
	exit( 1 );
}

if ( $coverage !== $baseline ) {
	fwrite( STDERR, "Coverage is {$coverage}%, so set {$argv[2]} to {$coverage} in this pull request.\n" );
	exit( 1 );
}

echo "Coverage matches the baseline.\n";
