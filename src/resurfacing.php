<?php
/**
 * Resurfacing: the record of signals that a composting post sits close to
 * what the author is writing now.
 *
 * The signal itself needs clusters (#6). This keeps only its record, which
 * the per-post file shows.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

// How each event reads in the per-post file. The file is a plain record in
// one fixed format, so these are not translated.
const UNDERGROWTH_RESURFACING_EVENTS = array(
	'shown'     => 'signal shown',
	'dismissed' => 'signal dismissed by the author',
);

/**
 * Records that a resurfacing signal was shown for a post, or dismissed.
 *
 * Only a composting post can resurface. Nothing here limits how often a
 * signal is shown; that is the signal's own rule.
 *
 * @param int    $post_id Post ID.
 * @param string $event   A key of UNDERGROWTH_RESURFACING_EVENTS.
 * @return bool True when the event was recorded.
 */
function undergrowth_log_resurfacing( int $post_id, string $event ): bool {
	if ( ! isset( UNDERGROWTH_RESURFACING_EVENTS[ $event ] ) || ! get_post( $post_id ) instanceof WP_Post || 'composting' !== undergrowth_get_stage( $post_id ) ) {
		return false;
	}

	$logged = add_post_meta(
		$post_id,
		'undergrowth_resurfacing_log',
		array(
			'time'  => time(),
			'event' => $event,
		)
	);

	if ( false === $logged ) {
		return false;
	}

	/**
	 * Fires after a resurfacing signal is shown or dismissed.
	 *
	 * @param int $post_id Post ID.
	 */
	do_action( 'undergrowth_resurfacing_logged', $post_id );

	return true;
}

/**
 * Returns the recorded resurfacing events for a post, oldest first.
 *
 * @param int $post_id Post ID.
 * @return array<int, array{time: int, event: string}> Events.
 */
function undergrowth_resurfacing_log( int $post_id ): array {
	$entries = array();

	// Rows come back in the order they were added.
	foreach ( get_post_meta( $post_id, 'undergrowth_resurfacing_log', false ) as $entry ) {
		if (
			! is_array( $entry )
			|| ! isset( $entry['time'], $entry['event'] )
			|| ! is_int( $entry['time'] )
			|| $entry['time'] <= 0
			|| ! is_string( $entry['event'] )
			|| ! isset( UNDERGROWTH_RESURFACING_EVENTS[ $entry['event'] ] )
		) {
			continue;
		}

		$entries[] = array(
			'time'  => $entry['time'],
			'event' => $entry['event'],
		);
	}

	return $entries;
}
