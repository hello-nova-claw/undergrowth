<?php
/**
 * WP-CLI commands, loaded only when WP-CLI runs.
 *
 * `wp undergrowth scores` is there on every site. `accept`, `run-cycle` and
 * `reset` are the Dev tools tab's tools, registered with it, so only on a
 * local or development site.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

undergrowth_register_cli();
add_action( 'undergrowth_dev_tools_registered', 'undergrowth_register_dev_cli' );

/**
 * Registers the commands every site has.
 */
function undergrowth_register_cli(): void {
	WP_CLI::add_command( 'undergrowth scores', 'undergrowth_cli_scores' );
}

/**
 * Registers the dev tools commands, once undergrowth_register_dev_tools()
 * has found the site may have them.
 */
function undergrowth_register_dev_cli(): void {
	WP_CLI::add_command( 'undergrowth accept', 'undergrowth_cli_accept' );
	WP_CLI::add_command( 'undergrowth run-cycle', 'undergrowth_cli_run_cycle' );
	WP_CLI::add_command( 'undergrowth reset', 'undergrowth_cli_reset' );
}

/**
 * Prints how the site's posts score against each other, and the thresholds in use.
 *
 * The monthly cluster cycle measures the scores. To measure them now, run
 * `wp cron event run undergrowth_cluster_cycle` first.
 *
 * ## EXAMPLES
 *
 *     wp undergrowth scores
 */
function undergrowth_cli_scores(): void {
	foreach ( undergrowth_scores_report() as $line ) {
		WP_CLI::log( $line );
	}
}

/**
 * Stops unless the command runs as a user who may use the dev tools.
 */
function undergrowth_cli_check_user(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		WP_CLI::error( 'Run this as an administrator, with --user=<login>. Accept and the undo follow the same rules as the Dev tools tab.' );
	}
}

/**
 * Prints a job's counts, one line each, as the Dev tools tab names them.
 *
 * @param array<string, int> $counts Counts.
 */
function undergrowth_cli_counts( array $counts ): void {
	$labels = undergrowth_dev_count_labels();

	foreach ( $counts as $key => $count ) {
		if ( isset( $labels[ $key ] ) && $count > 0 ) {
			WP_CLI::log( sprintf( '%1$s: %2$d', $labels[ $key ], $count ) );
		}
	}
}

/**
 * Runs a dev tools job to its end in this process.
 *
 * @param string $type    'accept' or 'reset'.
 * @param array  $options Options for undergrowth_start_dev_job().
 * @return array<string, int> The job's counts.
 *
 * @phpstan-param array{per_post?: int, forget_vectors?: bool} $options
 */
function undergrowth_cli_run_job( string $type, array $options ): array {
	if ( ! undergrowth_start_dev_job( $type, $options ) ) {
		WP_CLI::error( 'Another dev tools job is still running. Wait for it to finish.' );
	}

	$job = undergrowth_run_dev_job( null );

	return null === $job ? array() : (array) $job['counts'];
}

/**
 * Accepts suggestions exactly as the Accept button does, one by one.
 *
 * A suggestion Accept would refuse is refused here too, and counted. Local
 * and development sites only.
 *
 * ## OPTIONS
 *
 * [--all]
 * : Accept every suggestion. Required, so nothing is accepted by accident.
 *
 * [--per-post=<n>]
 * : Accept only the n best suggestions in each post.
 *
 * [--dry-run]
 * : List what would be accepted, and change nothing.
 *
 * ## EXAMPLES
 *
 *     wp undergrowth accept --all --user=admin
 *     wp undergrowth accept --all --per-post=2 --dry-run --user=admin
 *
 * @param string[]             $args       Positional arguments.
 * @param array<string, mixed> $assoc_args Options.
 */
function undergrowth_cli_accept( array $args, array $assoc_args ): void {
	unset( $args );

	if ( empty( $assoc_args['all'] ) ) {
		WP_CLI::error( 'Give --all to accept every suggestion, or --all --per-post=<n> for the best few in each post.' );
	}

	undergrowth_cli_check_user();

	$per_post = absint( $assoc_args['per-post'] ?? 0 );

	if ( ! empty( $assoc_args['dry-run'] ) ) {
		$count = 0;

		foreach ( undergrowth_dev_suggesting_posts() as $source_id ) {
			foreach ( undergrowth_dev_suggested_targets( $source_id, $per_post ) as $target_id ) {
				WP_CLI::log( sprintf( '%1$s → %2$s', undergrowth_plain_line( undergrowth_post_title( $source_id ) ), undergrowth_plain_line( undergrowth_post_title( $target_id ) ) ) );
				++$count;
			}
		}

		WP_CLI::success( sprintf( 'Would accept %d suggestions. Nothing was changed.', $count ) );
		return;
	}

	$counts = undergrowth_cli_run_job( 'accept', array( 'per_post' => $per_post ) );

	undergrowth_cli_counts( $counts );
	WP_CLI::success( sprintf( 'Accepted %d suggestions.', $counts['accepted'] ?? 0 ) );
}

/**
 * Runs the monthly cycle now, to the end: groups for the Map, then growth
 * stages and resurfacing, which it chains. Local and development sites only.
 *
 * ## EXAMPLES
 *
 *     wp undergrowth run-cycle --user=admin
 *
 * @param string[]             $args       Positional arguments.
 * @param array<string, mixed> $assoc_args Options.
 */
function undergrowth_cli_run_cycle( array $args = array(), array $assoc_args = array() ): void {
	unset( $args, $assoc_args );

	undergrowth_cli_check_user();

	// The undo clears what the cycle writes, so the two never run together.
	if ( undergrowth_dev_job_running() ) {
		WP_CLI::error( 'Another dev tools job is still running. Wait for it to finish.' );
	}

	$runs = undergrowth_dev_run_cycle();

	WP_CLI::success( sprintf( 'The cycle ran in %1$d batches and made %2$d groups.', $runs, count( undergrowth_cluster_map() ) ) );
}

/**
 * Undoes everything Undergrowth changed: removes the links it added, keeping
 * their words and any edits made since, and forgets every suggestion, veto,
 * stage, pin, compost mark, group, resurfacing signal and per-post file.
 * Local and development sites only.
 *
 * ## OPTIONS
 *
 * [--forget-vectors]
 * : Forget what was read from each post too, and read every post again.
 *
 * [--yes]
 * : Undo without asking first.
 *
 * ## EXAMPLES
 *
 *     wp undergrowth reset --user=admin
 *     wp undergrowth reset --forget-vectors --yes --user=admin
 *
 * @param string[]             $args       Positional arguments.
 * @param array<string, mixed> $assoc_args Options.
 */
function undergrowth_cli_reset( array $args, array $assoc_args ): void {
	unset( $args );

	undergrowth_cli_check_user();

	WP_CLI::confirm( 'Undo everything Undergrowth changed on this site?', $assoc_args );

	undergrowth_cli_counts( undergrowth_cli_run_job( 'reset', array( 'forget_vectors' => ! empty( $assoc_args['forget-vectors'] ) ) ) );
	WP_CLI::success( 'Undone.' );
}
