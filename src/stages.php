<?php
/**
 * Growth stages: where each post's stage is stored.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_STAGES      = array( 'seedling', 'sprout', 'budding', 'evergreen', 'composting' );
const UNDERGROWTH_STAGE_BATCH = 100;

// How each reason for a change reads in the per-post file. The file is a
// plain record in one fixed format, so these are not translated.
const UNDERGROWTH_STAGE_REASONS = array(
	'cycle'   => 'monthly cycle',
	'pin'     => 'pinned by the author',
	'unpin'   => 'unpinned by the author',
	'compost' => 'composted by the author',
	'return'  => 'returned to the garden by the author',
);

// Late, so a filter that adds post types to the index has been added first.
add_action( 'init', 'undergrowth_register_stage_meta', 20 );
add_filter( 'is_protected_meta', 'undergrowth_protect_stage_meta', 10, 2 );
add_action( 'add_meta_boxes', 'undergrowth_remove_added_custom_fields_box', 99 );
add_filter( 'cron_schedules', 'undergrowth_cron_schedules' );
// Activation does not run on plugin updates or on every site of a network.
add_action( 'init', 'undergrowth_schedule_stage_cycle' );
add_action( 'undergrowth_stage_cycle', 'undergrowth_run_stage_cycle' );
register_deactivation_hook( UNDERGROWTH_FILE, 'undergrowth_deactivate' );

/**
 * Registers the stage meta for every indexed post type, so themes and the
 * REST API can read it.
 *
 * A stage changes only on the monthly cycle or through an author action, so
 * no client may write it through REST. A post with nothing stored reads as a
 * seedling, the stage of a new post, so saving a post never has to write it.
 */
function undergrowth_register_stage_meta(): void {
	foreach ( undergrowth_indexed_post_types() as $post_type ) {
		// The REST API leaves out the meta of any post type without this
		// support. The Custom Fields box it would also switch on is removed
		// again, so the editor looks the same as before.
		if ( ! post_type_supports( $post_type, 'custom-fields' ) ) {
			add_post_type_support( $post_type, 'custom-fields' );
			undergrowth_custom_fields_added( $post_type );
		}

		register_post_meta(
			$post_type,
			'undergrowth_stage',
			array(
				'type'          => 'string',
				'description'   => __( 'Growth stage, measured from the links between posts or chosen by the author.', 'undergrowth' ),
				'single'        => true,
				'default'       => 'seedling',
				'show_in_rest'  => array(
					'schema' => array(
						'enum' => UNDERGROWTH_STAGES,
					),
				),
				'auth_callback' => '__return_false',
			)
		);

		register_post_meta(
			$post_type,
			'undergrowth_stage_pinned',
			array(
				'type'          => 'boolean',
				'description'   => __( 'Whether the author pinned the growth stage.', 'undergrowth' ),
				'single'        => true,
				'default'       => false,
				'show_in_rest'  => true,
				'auth_callback' => '__return_false',
			)
		);
	}
}

/**
 * Remembers the post types given custom-fields support only so the REST API
 * includes the stage keys.
 *
 * @param string $post_type Post type to add to the list, or an empty string to only read it.
 * @return string[] Post types on the list.
 */
function undergrowth_custom_fields_added( string $post_type = '' ): array {
	static $added = array();

	if ( '' !== $post_type ) {
		$added[ $post_type ] = true;
	}

	return array_keys( $added );
}

/**
 * Removes the Custom Fields box from post types that had no such box before
 * the plugin added the support.
 *
 * @param string $post_type Post type of the edit screen.
 */
function undergrowth_remove_added_custom_fields_box( $post_type ): void {
	if ( in_array( $post_type, undergrowth_custom_fields_added(), true ) ) {
		remove_meta_box( 'postcustom', $post_type, 'normal' );
	}
}

/**
 * Keeps the stage keys out of the Custom Fields box, where they could be
 * edited by hand.
 *
 * @param bool   $is_protected Whether the key is protected.
 * @param string $meta_key     Meta key.
 * @return bool True for the stage keys, otherwise unchanged.
 */
function undergrowth_protect_stage_meta( $is_protected, $meta_key ) {
	return in_array( $meta_key, array( 'undergrowth_stage', 'undergrowth_stage_pinned', 'undergrowth_stage_log' ), true ) ? true : $is_protected;
}

/**
 * Returns a post's stage.
 *
 * @param int $post_id Post ID.
 * @return string One of UNDERGROWTH_STAGES. Seedling when nothing valid is stored.
 *
 * @phpstan-impure It reads meta that the caller may have just changed.
 */
function undergrowth_get_stage( int $post_id ): string {
	$stage = get_post_meta( $post_id, 'undergrowth_stage', true );

	return in_array( $stage, UNDERGROWTH_STAGES, true ) ? $stage : 'seedling';
}

/**
 * Names a stage for the screen.
 *
 * @param string $stage One of UNDERGROWTH_STAGES.
 * @return string Translated name.
 */
function undergrowth_stage_label( string $stage ): string {
	$labels = array(
		'seedling'   => _x( 'Seedling', 'growth stage', 'undergrowth' ),
		'sprout'     => _x( 'Sprout', 'growth stage', 'undergrowth' ),
		'budding'    => _x( 'Budding', 'growth stage', 'undergrowth' ),
		'evergreen'  => _x( 'Evergreen', 'growth stage', 'undergrowth' ),
		'composting' => _x( 'Composting', 'growth stage', 'undergrowth' ),
	);

	return $labels[ $stage ] ?? $labels['seedling'];
}

/**
 * Tells whether the author has pinned a post's stage.
 *
 * @param int $post_id Post ID.
 * @return bool True when pinned.
 *
 * @phpstan-impure It reads meta that the caller may have just changed.
 */
function undergrowth_is_stage_pinned( int $post_id ): bool {
	return (bool) get_post_meta( $post_id, 'undergrowth_stage_pinned', true );
}

/**
 * Stores a post's stage and records the change.
 *
 * Every change of stage goes through here, so the rules about who may
 * change it are kept by the callers, not repeated for each meta write.
 * A change of stage, or any author action, is recorded with its reason for
 * the per-post file. The cycle storing a stage that did not change is not.
 *
 * @param int    $post_id Post ID.
 * @param string $stage   One of UNDERGROWTH_STAGES.
 * @param string $reason  What made the change: a key of UNDERGROWTH_STAGE_REASONS.
 * @return bool True when the post holds the stage and the change was recorded.
 */
function undergrowth_set_stage( int $post_id, string $stage, string $reason = 'cycle' ): bool {
	if ( ! in_array( $stage, UNDERGROWTH_STAGES, true ) || ! isset( UNDERGROWTH_STAGE_REASONS[ $reason ] ) || ! get_post( $post_id ) instanceof WP_Post ) {
		return false;
	}

	$from   = undergrowth_get_stage( $post_id );
	$stored = metadata_exists( 'post', $post_id, 'undergrowth_stage' );

	update_post_meta( $post_id, 'undergrowth_stage', $stage );

	if ( undergrowth_get_stage( $post_id ) !== $stage ) {
		return false;
	}

	if ( $from !== $stage || 'cycle' !== $reason ) {
		// One row per change, added rather than rewritten, so a cycle and an
		// author acting at the same moment cannot overwrite each other.
		$logged = add_post_meta(
			$post_id,
			'undergrowth_stage_log',
			array(
				'time'   => time(),
				'from'   => $from,
				'to'     => $stage,
				'reason' => $reason,
			)
		);

		// A change the file would never show is undone, so the stage and
		// its record always agree. Only the value written here is undone,
		// never one another request stored since.
		if ( false === $logged ) {
			if ( $stored ) {
				update_post_meta( $post_id, 'undergrowth_stage', $from, $stage );
			} else {
				delete_post_meta( $post_id, 'undergrowth_stage', $stage );
			}

			return false;
		}

		/**
		 * Fires after a post's stage changes or an author acts on it.
		 *
		 * @param int $post_id Post ID.
		 */
		do_action( 'undergrowth_stage_changed', $post_id );
	}

	return true;
}

/**
 * Returns the recorded changes to a post's stage, oldest first.
 *
 * @param int $post_id Post ID.
 * @return array<int, array{time: int, from: string, to: string, reason: string}> Changes.
 */
function undergrowth_stage_log( int $post_id ): array {
	$entries = array();

	// Rows come back in the order they were added.
	foreach ( get_post_meta( $post_id, 'undergrowth_stage_log', false ) as $entry ) {
		if (
			! is_array( $entry )
			|| ! isset( $entry['time'], $entry['from'], $entry['to'], $entry['reason'] )
			|| ! is_int( $entry['time'] )
			|| $entry['time'] <= 0
			|| ! in_array( $entry['from'], UNDERGROWTH_STAGES, true )
			|| ! in_array( $entry['to'], UNDERGROWTH_STAGES, true )
			|| ! is_string( $entry['reason'] )
			|| ! isset( UNDERGROWTH_STAGE_REASONS[ $entry['reason'] ] )
		) {
			continue;
		}

		$entries[] = array(
			'time'   => $entry['time'],
			'from'   => $entry['from'],
			'to'     => $entry['to'],
			'reason' => $entry['reason'],
		);
	}

	return $entries;
}

/**
 * Measures a post's stage from its accepted links.
 *
 * Only links accepted through Undergrowth count, in either direction, and
 * only while the post at the other end is still in the index. Budding and
 * Evergreen need clusters (#41), which do not exist yet, so no post is in a
 * cluster and the measure stops at Sprout. Composting is never measured;
 * only the author chooses it.
 *
 * @param int $post_id Post ID.
 * @return string 'seedling' or 'sprout'.
 */
function undergrowth_measure_stage( int $post_id ): string {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; states change on every accept.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT source_id, target_id FROM %i WHERE state = %s AND ( source_id = %d OR target_id = %d )',
			undergrowth_links_table(),
			'accepted',
			$post_id,
			$post_id
		),
		ARRAY_A
	);

	$links = 0;

	foreach ( (array) $rows as $row ) {
		$other_id = (int) $row['source_id'] === $post_id ? (int) $row['target_id'] : (int) $row['source_id'];
		$other    = get_post( $other_id );

		if ( $other instanceof WP_Post && undergrowth_is_indexable( $other ) ) {
			++$links;
		}
	}

	return $links >= 2 ? 'sprout' : 'seedling';
}

/**
 * Adds a monthly interval to WP-Cron, which has none of its own.
 *
 * @param array<string, array{interval: int, display: string}> $schedules Intervals by name.
 * @return array<string, array{interval: int, display: string}> Intervals with the monthly one added.
 */
function undergrowth_cron_schedules( $schedules ) {
	$schedules['undergrowth_monthly'] = array(
		'interval' => MONTH_IN_SECONDS,
		'display'  => __( 'Once a month', 'undergrowth' ),
	);

	return $schedules;
}

/**
 * Schedules the monthly stage cycle when it is not scheduled yet.
 */
function undergrowth_schedule_stage_cycle(): void {
	if ( false === wp_next_scheduled( 'undergrowth_stage_cycle' ) ) {
		wp_schedule_event( time(), 'undergrowth_monthly', 'undergrowth_stage_cycle' );
	}
}

/**
 * Measures one batch of indexed posts and stores any stage that went up.
 *
 * The monthly event starts at the first post. Each batch that fills up
 * schedules the next, after the last post it measured, so a large site is
 * measured across several requests instead of timing out in one.
 *
 * @param int|string $after_id Measure posts with a higher ID than this. Cron
 *                            passes an empty string when an event has no arguments.
 */
function undergrowth_run_stage_cycle( $after_id = 0 ): void {
	global $wpdb;

	$after_id = (int) $after_id;

	$post_types = undergrowth_indexed_post_types();

	if ( array() === $post_types ) {
		return;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

	// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The placeholders are built above, one per post type.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WP_Query cannot page by ID, which keeps batches stable while posts are published mid-cycle.
	$post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM %i WHERE ID > %d AND post_status = %s AND post_password = %s AND post_type IN ({$placeholders}) ORDER BY ID ASC LIMIT %d",
			array_merge( array( $wpdb->posts, $after_id, 'publish', '' ), $post_types, array( UNDERGROWTH_STAGE_BATCH ) )
		)
	);
	// phpcs:enable

	$post_ids = array_map( 'intval', $post_ids );

	foreach ( $post_ids as $post_id ) {
		undergrowth_cycle_post( $post_id );
	}

	if ( count( $post_ids ) === UNDERGROWTH_STAGE_BATCH ) {
		wp_schedule_single_event( time(), 'undergrowth_stage_cycle', array( end( $post_ids ) ) );
	}
}

/**
 * Stores a post's measured stage when it is higher than the stored one.
 *
 * Pinned and composted posts are the author's choice and are left alone.
 * In germination mode nothing is demoted, so a lower measure is ignored.
 *
 * @param int $post_id Post ID.
 */
function undergrowth_cycle_post( int $post_id ): void {
	$stage = undergrowth_get_stage( $post_id );

	if ( 'composting' === $stage || undergrowth_is_stage_pinned( $post_id ) ) {
		return;
	}

	$order    = array_flip( array( 'seedling', 'sprout', 'budding', 'evergreen' ) );
	$measured = undergrowth_measure_stage( $post_id );

	if ( $order[ $measured ] > $order[ $stage ] || ! metadata_exists( 'post', $post_id, 'undergrowth_stage' ) ) {
		undergrowth_set_stage( $post_id, $measured );
	}
}

/**
 * Stops the stage cycle while the plugin is inactive.
 */
function undergrowth_deactivate(): void {
	wp_unschedule_hook( 'undergrowth_stage_cycle' );
}

/**
 * Checks that the current user may act on a post's stage.
 *
 * @param int $post_id Post ID.
 * @return WP_Error|null Why the action is refused, or null when it may go ahead.
 */
function undergrowth_stage_action_error( int $post_id ): ?WP_Error {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || ! undergrowth_is_indexable( $post ) ) {
		return new WP_Error( 'undergrowth_not_indexed', __( 'Only published posts in the garden have a growth stage.', 'undergrowth' ) );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'undergrowth_cannot_edit', __( 'You cannot change the growth stage of this post.', 'undergrowth' ) );
	}

	return null;
}

/**
 * Returns the error for a stage change that could not be stored.
 *
 * @return WP_Error The error.
 */
function undergrowth_stage_not_saved(): WP_Error {
	return new WP_Error( 'undergrowth_not_saved', __( 'The stage could not be saved, so nothing was changed.', 'undergrowth' ) );
}

/**
 * Pins a post's stage, so the monthly cycle leaves it alone.
 *
 * Pinning is an author action, so it takes effect at once. Any measured
 * stage can be pinned, including those the measure cannot reach yet.
 * Composting is outside the pinning rules: it is neither pinned nor
 * pinnable.
 *
 * @param int    $post_id Post ID.
 * @param string $stage   Seedling, sprout, budding or evergreen.
 * @return true|WP_Error True when pinned, or why it was refused.
 */
function undergrowth_pin_stage( int $post_id, string $stage ) {
	$error = undergrowth_stage_action_error( $post_id );

	if ( null !== $error ) {
		return $error;
	}

	if ( ! in_array( $stage, array( 'seedling', 'sprout', 'budding', 'evergreen' ), true ) ) {
		return new WP_Error( 'undergrowth_unknown_stage', __( 'That stage cannot be pinned.', 'undergrowth' ) );
	}

	if ( 'composting' === undergrowth_get_stage( $post_id ) ) {
		return new WP_Error( 'undergrowth_composting', __( 'A composting post cannot be pinned.', 'undergrowth' ) );
	}

	$was_pinned = undergrowth_is_stage_pinned( $post_id );

	// The flag goes first, so the file written for the change shows the marker.
	if ( ! $was_pinned ) {
		update_post_meta( $post_id, 'undergrowth_stage_pinned', '1' );

		if ( ! undergrowth_is_stage_pinned( $post_id ) ) {
			return undergrowth_stage_not_saved();
		}
	}

	if ( ! undergrowth_set_stage( $post_id, $stage, 'pin' ) ) {
		// Only a flag added here is taken away; an earlier pin stays.
		if ( ! $was_pinned ) {
			delete_post_meta( $post_id, 'undergrowth_stage_pinned' );
		}

		return undergrowth_stage_not_saved();
	}

	return true;
}

/**
 * Unpins a post's stage and measures it at once.
 *
 * The stage it had before the pin may be long out of date, so it is
 * measured again rather than restored.
 *
 * @param int $post_id Post ID.
 * @return true|WP_Error True when unpinned, or why it was refused.
 */
function undergrowth_unpin_stage( int $post_id ) {
	$error = undergrowth_stage_action_error( $post_id );

	if ( null !== $error ) {
		return $error;
	}

	if ( ! undergrowth_is_stage_pinned( $post_id ) ) {
		return new WP_Error( 'undergrowth_not_pinned', __( 'This post\'s stage is not pinned.', 'undergrowth' ) );
	}

	delete_post_meta( $post_id, 'undergrowth_stage_pinned' );

	if ( undergrowth_is_stage_pinned( $post_id ) ) {
		return undergrowth_stage_not_saved();
	}

	if ( ! undergrowth_set_stage( $post_id, undergrowth_measure_stage( $post_id ), 'unpin' ) ) {
		update_post_meta( $post_id, 'undergrowth_stage_pinned', '1' );
		return undergrowth_stage_not_saved();
	}

	return true;
}

/**
 * Moves a post to compost, from any stage.
 *
 * Composting is the author's choice alone and takes effect at once. The post
 * stays published and indexed, but leaves the active garden: its pending
 * proposals, and other posts' proposals to it, are removed. Links the author
 * accepted stay, because removing them would change the site without the
 * author's approval, and vetoes stay forever. No date or timer is stored.
 *
 * @param int $post_id Post ID.
 * @return true|WP_Error True when composted, or why it was refused.
 */
function undergrowth_compost_post( int $post_id ) {
	$error = undergrowth_stage_action_error( $post_id );

	if ( null !== $error ) {
		return $error;
	}

	if ( 'composting' === undergrowth_get_stage( $post_id ) ) {
		return new WP_Error( 'undergrowth_composting', __( 'This post is already composting.', 'undergrowth' ) );
	}

	// Composting is outside the pinning rules, so a pin does not survive it.
	$pinned = undergrowth_is_stage_pinned( $post_id );
	delete_post_meta( $post_id, 'undergrowth_stage_pinned' );

	if ( ! undergrowth_set_stage( $post_id, 'composting', 'compost' ) ) {
		if ( $pinned ) {
			update_post_meta( $post_id, 'undergrowth_stage_pinned', '1' );
		}

		return undergrowth_stage_not_saved();
	}

	undergrowth_forget_post_links( $post_id );

	return true;
}

/**
 * Returns a composting post to the garden.
 *
 * Its stage is measured at that moment. The stage it had before may be years
 * out of date, and resetting it to Seedling would treat a rest as a failure.
 * Its proposals are worked out again straight away.
 *
 * @param int $post_id Post ID.
 * @return true|WP_Error True when returned, or why it was refused.
 */
function undergrowth_return_post( int $post_id ) {
	$error = undergrowth_stage_action_error( $post_id );

	if ( null !== $error ) {
		return $error;
	}

	if ( 'composting' !== undergrowth_get_stage( $post_id ) ) {
		return new WP_Error( 'undergrowth_not_composting', __( 'This post is not composting.', 'undergrowth' ) );
	}

	if ( ! undergrowth_set_stage( $post_id, undergrowth_measure_stage( $post_id ), 'return' ) ) {
		return undergrowth_stage_not_saved();
	}

	undergrowth_refresh_around( $post_id );

	return true;
}
