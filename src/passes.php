<?php
/**
 * Monthly passes in batches: which pass a batch belongs to, queueing the
 * next batch, and running again a batch that died.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

// A batch of a monthly pass that has not moved on this long after it
// started, with no next batch queued, died and is run again. A batch takes
// seconds, but WP-Cron can run without a time limit, so this is far longer
// than any batch that is still alive. The check runs hourly anyway.
const UNDERGROWTH_PASS_STALL = HOUR_IN_SECONDS;

add_action( 'undergrowth_backfill_check', 'undergrowth_resume_stalled_passes' );

/**
 * Records that a batch of a monthly pass has started.
 *
 * WP-Cron takes an event off the queue before running it, so a batch that
 * dies from a timeout or a fatal error leaves nothing queued. The record
 * names the post the batch started after, so the hourly check can run it
 * again.
 *
 * Each run gets its own token. When the hourly check queues a batch again
 * while a slow run of it is still alive, the new run takes the token, and
 * the old one stops before it stores anything for the pass; see
 * undergrowth_owns_pass().
 *
 * @param string $hook     Cron hook of the pass.
 * @param int    $after_id Post ID the batch starts after.
 * @param string $pass     ID of the pass, or an empty string for a pass without one.
 * @return string Token of this run.
 */
function undergrowth_mark_pass( string $hook, int $after_id, string $pass = '' ): string {
	$run = wp_generate_uuid4();

	update_option(
		$hook . '_pass',
		array(
			'after' => $after_id,
			'at'    => time(),
			'run'   => $run,
			'pass'  => $pass,
		),
		false
	);

	return $run;
}

/**
 * Returns the ID of the pass now under way.
 *
 * @param string $hook Cron hook of the pass.
 * @return string Pass ID, or an empty string when no pass with an ID is under way.
 */
function undergrowth_current_pass( string $hook ): string {
	// Another request may have started a new pass since this one read the record.
	wp_cache_delete( $hook . '_pass', 'options' );

	$pass = get_option( $hook . '_pass' );

	return is_array( $pass ) ? (string) ( $pass['pass'] ?? '' ) : '';
}

/**
 * Returns the arguments of a batch's cron event.
 *
 * @param int    $after_id Post ID the batch starts after.
 * @param string $pass     ID of the pass, or an empty string for a pass without one.
 * @return array<int, int|string> Event arguments.
 */
function undergrowth_pass_args( int $after_id, string $pass ): array {
	return '' === $pass ? array( $after_id ) : array( $after_id, $pass );
}

/**
 * Tells whether a run of a batch still owns its pass.
 *
 * @param string $hook Cron hook of the pass.
 * @param string $run  Token undergrowth_mark_pass() gave the run.
 * @return bool False once another run has taken the pass over.
 */
function undergrowth_owns_pass( string $hook, string $run ): bool {
	// Another request may have changed the record since this one read it.
	wp_cache_delete( $hook . '_pass', 'options' );

	$pass = get_option( $hook . '_pass' );

	return is_array( $pass ) && ( $pass['run'] ?? '' ) === $run;
}

/**
 * Queues the next batch of a monthly pass, and records it.
 *
 * @param string $hook     Cron hook of the pass.
 * @param int    $after_id Post ID the batch starts after.
 * @param string $pass     ID of the pass, or an empty string for a pass without one.
 */
function undergrowth_queue_pass_batch( string $hook, int $after_id, string $pass = '' ): void {
	undergrowth_mark_pass( $hook, $after_id, $pass );
	wp_schedule_single_event( time(), $hook, undergrowth_pass_args( $after_id, $pass ) );
}

/**
 * Forgets the record of a monthly pass once it is complete.
 *
 * @param string $hook Cron hook of the pass.
 */
function undergrowth_end_pass( string $hook ): void {
	delete_option( $hook . '_pass' );
}

/**
 * Runs again the batch of a clustering, stage or cross-pollination pass
 * that died.
 *
 * Runs from the hourly check. A pass is resumed only when its batch started
 * at least UNDERGROWTH_PASS_STALL ago and neither that batch nor a new pass
 * from the first post is queued. A run that is still alive after that
 * long gives the pass up to the new one.
 */
function undergrowth_resume_stalled_passes(): void {
	foreach ( array( 'undergrowth_cluster_cycle', 'undergrowth_stage_cycle', 'undergrowth_pollination_cycle' ) as $hook ) {
		$pass = get_option( $hook . '_pass' );

		if ( ! is_array( $pass ) || ! isset( $pass['after'], $pass['at'] ) || time() - (int) $pass['at'] < UNDERGROWTH_PASS_STALL ) {
			continue;
		}

		$after_id = (int) $pass['after'];
		$pass_id  = (string) ( $pass['pass'] ?? '' );

		if ( false !== wp_next_scheduled( $hook, undergrowth_pass_args( $after_id, $pass_id ) ) || false !== wp_next_scheduled( $hook, array( 0 ) ) ) {
			continue;
		}

		undergrowth_queue_pass_batch( $hook, $after_id, $pass_id );
	}
}
