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
 * The gate fails when the baseline goes down, when coverage falls below the
 * base baseline, when the baseline is set above the coverage, and when
 * coverage rose by more than UNDERGROWTH_COVERAGE_SLACK without the baseline
 * following. A smaller rise passes and prints the figure to set.
 *
 * @package Undergrowth
 */

// Several pull requests are open at once. If each had to set the baseline to
// its exact figure, each merge would put every other one in conflict on this
// one file. So a small rise may wait for a later pull request to record it,
// and only a real gain must be written down before it can be lost again.
const UNDERGROWTH_COVERAGE_SLACK = 0.5;

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

if ( $statements < 0 || $covered < 0 || $covered > $statements ) {
	fwrite( STDERR, "The coverage report counts {$covered} covered statements of {$statements}, which cannot be.\n" );
	exit( 2 );
}

// A report with no statements means the source files were not found when it
// was built, not that everything is covered.
if ( 0 === $statements ) {
	fwrite( STDERR, "The coverage report lists no statements. Build it where the source files are available.\n" );
	exit( 1 );
}

/**
 * Reads a baseline file, which holds one percentage and nothing else.
 *
 * Any other content would read as 0, and let coverage pass below the bar.
 *
 * @param string $path Baseline file.
 * @return string The percentage to two decimals.
 */
function undergrowth_read_baseline( string $path ): string {
	$text = is_readable( $path ) ? trim( (string) file_get_contents( $path ) ) : '';

	if ( 1 !== preg_match( '/^[0-9]{1,3}(?:\.[0-9]+)?$/D', $text ) || (float) $text > 100 ) {
		fwrite( STDERR, "{$path} should hold one percentage, such as 98.34.\n" );
		exit( 2 );
	}

	return sprintf( '%.2f', (float) $text );
}

// Compared as printed, so a figure that rounds to the baseline matches it.
$coverage = sprintf( '%.2f', 100 * $covered / $statements );
$baseline = undergrowth_read_baseline( $argv[2] );
$base     = 4 === $argc ? undergrowth_read_baseline( $argv[3] ) : $baseline;

printf( "Statement coverage %s%%, baseline %s%%, base branch's baseline %s%%\n", $coverage, $baseline, $base );

if ( (float) $baseline < (float) $base ) {
	fwrite( STDERR, "The baseline went down from {$base} to {$baseline}. Put it back to {$base}, and add tests rather than lower it.\n" );
	exit( 1 );
}

if ( (float) $coverage < (float) $base ) {
	fwrite( STDERR, "Coverage went down from {$base}% to {$coverage}%. Add tests for the code this changes.\n" );
	exit( 1 );
}

if ( (float) $coverage < (float) $baseline ) {
	fwrite( STDERR, "Coverage is {$coverage}%, below the baseline, so set {$argv[2]} to {$coverage} in this pull request.\n" );
	exit( 1 );
}

if ( round( (float) $coverage - (float) $baseline, 2 ) > UNDERGROWTH_COVERAGE_SLACK ) {
	fwrite( STDERR, "Coverage is {$coverage}%, so set {$argv[2]} to {$coverage} in this pull request.\n" );
	exit( 1 );
}

if ( $coverage !== $baseline ) {
	echo "Coverage is {$coverage}%. Setting {$argv[2]} to {$coverage} would record the rise.\n";
	exit( 0 );
}

echo "Coverage matches the baseline.\n";
