<?php
/**
 * Reading a per-post file back in: its stage and resurfacing history,
 * carried from another site.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the stage and resurfacing sections back from a post's file.
 *
 * The owner may edit the file, so any line that is not in the format the
 * plugin writes is skipped rather than guessed at. Dates are written without
 * a time, so each change reads back as midnight UTC on its day.
 *
 * @param string $text File contents.
 * @return array{stage: string|null, pinned: bool, changes: array<int, array{time: int, from: string, to: string, reason: string}>, resurfacing: array<int, array{time: int, event: string}>}
 *               The current stage, or null when the file names none, and the recorded history.
 */
function undergrowth_read_memory( string $text ): array {
	$read    = array(
		'stage'       => null,
		'pinned'      => false,
		'changes'     => array(),
		'resurfacing' => array(),
	);
	$section = '';

	foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
		$line = trim( $line );

		if ( preg_match( '/^## (.+)$/', $line, $match ) ) {
			$section = trim( $match[1] );
			continue;
		}

		if ( 'stage' === $section && null === $read['stage'] && preg_match( '/^([a-z]+)( \(pinned\))?$/', $line, $match ) ) {
			if ( in_array( $match[1], UNDERGROWTH_STAGES, true ) ) {
				$read['stage']  = $match[1];
				$read['pinned'] = 'composting' !== $match[1] && isset( $match[2] );
			}
			continue;
		}

		if ( ! preg_match( '/^- (\d{4}-\d{2}-\d{2}): (.+)$/', $line, $match ) ) {
			continue;
		}

		$time = undergrowth_memory_day( $match[1] );

		if ( null === $time ) {
			continue;
		}

		if ( 'stage' === $section && preg_match( '/^([a-z]+) -> ([a-z]+) \((.+)\)$/', $match[2], $change ) ) {
			$reason = array_search( $change[3], UNDERGROWTH_STAGE_REASONS, true );

			if ( false !== $reason && in_array( $change[1], UNDERGROWTH_STAGES, true ) && in_array( $change[2], UNDERGROWTH_STAGES, true ) ) {
				$read['changes'][] = array(
					'time'   => $time,
					'from'   => $change[1],
					'to'     => $change[2],
					'reason' => $reason,
				);
			}
		} elseif ( 'resurfacing' === $section ) {
			$event = array_search( $match[2], UNDERGROWTH_RESURFACING_EVENTS, true );

			if ( false !== $event ) {
				$read['resurfacing'][] = array(
					'time'  => $time,
					'event' => $event,
				);
			}
		}
	}

	return $read;
}

/**
 * Turns a date written in a file back into a time.
 *
 * @param string $date Date as Y-m-d.
 * @return int|null Midnight UTC on that day, or null when it is not a real date
 *                  or falls before 1970, which the history keeps no record of.
 */
function undergrowth_memory_day( string $date ): ?int {
	$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'UTC' ) );

	if ( false === $day || $day->format( 'Y-m-d' ) !== $date || $day->getTimestamp() <= 0 ) {
		return null;
	}

	return $day->getTimestamp();
}

/**
 * Applies a post's file, perhaps written on another site, to a post.
 *
 * Only the stage, its pin and the recorded history are taken. Links name
 * other posts by address, which may not exist here, so they are left for
 * the proposals to find again. History already recorded for the post on
 * the same day is not added twice, so importing a file again is harmless.
 * Restoring a stage is not a new change, so no line is added for it.
 *
 * @param int    $post_id Post ID.
 * @param string $text    File contents.
 * @return true|WP_Error True when imported, or why it was refused.
 */
function undergrowth_import_memory( int $post_id, string $text ) {
	$error = undergrowth_stage_action_error( $post_id );

	if ( null !== $error ) {
		return $error;
	}

	$read = undergrowth_read_memory( $text );

	if ( null === $read['stage'] ) {
		return new WP_Error( 'undergrowth_no_stage', __( 'The file does not name a growth stage, so nothing was imported.', 'undergrowth' ) );
	}

	$from       = undergrowth_get_stage( $post_id );
	$was_pinned = undergrowth_is_stage_pinned( $post_id );

	// The pin goes first, so a stage is never stored with the wrong marker.
	// The reader never pins a composting post.
	if ( $read['pinned'] ) {
		update_post_meta( $post_id, 'undergrowth_stage_pinned', '1' );
	} else {
		delete_post_meta( $post_id, 'undergrowth_stage_pinned' );
	}

	if ( undergrowth_is_stage_pinned( $post_id ) !== $read['pinned'] ) {
		return undergrowth_stage_not_saved();
	}

	update_post_meta( $post_id, 'undergrowth_stage', $read['stage'] );

	if ( undergrowth_get_stage( $post_id ) !== $read['stage'] ) {
		return undergrowth_restore_pin( $post_id, $was_pinned ) ?? undergrowth_stage_not_saved();
	}

	// The same effects as composting a post, or returning it, by hand.
	if ( 'composting' === $read['stage'] && 'composting' !== $from ) {
		undergrowth_forget_post_links( $post_id );
	} elseif ( 'composting' !== $read['stage'] && 'composting' === $from ) {
		undergrowth_refresh_around( $post_id );
	}

	$complete = undergrowth_import_history( $post_id, 'undergrowth_stage_log', undergrowth_stage_log( $post_id ), $read['changes'] )
		&& undergrowth_import_history( $post_id, 'undergrowth_resurfacing_log', undergrowth_resurfacing_log( $post_id ), $read['resurfacing'] );

	undergrowth_write_memory( $post_id );

	// The stage is in place by now, so a history row that could not be added
	// leaves a partial import, which is said plainly rather than undone.
	if ( ! $complete ) {
		return new WP_Error( 'undergrowth_import_partial', __( 'The stage was imported, but some of its history could not be saved.', 'undergrowth' ) );
	}

	return true;
}

/**
 * Adds the history rows from a file that a post does not have yet.
 *
 * Rows are matched by day and contents, since the file keeps no time of day.
 * Matching counts rather than presence, because the same event can happen
 * twice in one day and both lines are real history.
 *
 * @param int                              $post_id  Post ID.
 * @param string                           $meta_key Meta key of the history.
 * @param array<int, array<string, mixed>> $have     Rows the post already has.
 * @param array<int, array<string, mixed>> $rows     Rows read from the file.
 * @return bool True when every missing row was added.
 */
function undergrowth_import_history( int $post_id, string $meta_key, array $have, array $rows ): bool {
	$key = static function ( array $row ): string {
		$day = gmdate( 'Y-m-d', $row['time'] );
		unset( $row['time'] );

		return $day . ' ' . implode( ' ', $row );
	};

	$known = array_count_values( array_map( $key, $have ) );

	foreach ( $rows as $row ) {
		$match = $key( $row );

		if ( ! empty( $known[ $match ] ) ) {
			--$known[ $match ];
			continue;
		}

		if ( false === add_post_meta( $post_id, $meta_key, $row ) ) {
			return false;
		}
	}

	return true;
}
