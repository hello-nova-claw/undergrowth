<?php
/**
 * Growth stages: where each post's stage is stored.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_STAGES      = array( 'seedling', 'sprout', 'budding', 'evergreen', 'composting' );
const UNDERGROWTH_STAGE_BATCH = 100;

// Late, so a filter that adds post types to the index has been added first.
add_action( 'init', 'undergrowth_register_stage_meta', 20 );
add_filter( 'is_protected_meta', 'undergrowth_protect_stage_meta', 10, 2 );
add_filter( 'cron_schedules', 'undergrowth_cron_schedules' );
// Activation does not run on plugin updates or on every site of a network.
add_action( 'init', 'undergrowth_schedule_stage_cycle' );
add_action( 'undergrowth_stage_cycle', 'undergrowth_run_stage_cycle' );

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
		// support. The keys are protected, so the Custom Fields box it
		// switches on never lists them.
		if ( ! post_type_supports( $post_type, 'custom-fields' ) ) {
			add_post_type_support( $post_type, 'custom-fields' );
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
 * Keeps the stage keys out of the Custom Fields box, where they could be
 * edited by hand.
 *
 * @param bool   $is_protected Whether the key is protected.
 * @param string $meta_key     Meta key.
 * @return bool True for the stage keys, otherwise unchanged.
 */
function undergrowth_protect_stage_meta( $is_protected, $meta_key ) {
	return in_array( $meta_key, array( 'undergrowth_stage', 'undergrowth_stage_pinned' ), true ) ? true : $is_protected;
}

/**
 * Returns a post's stage.
 *
 * @param int $post_id Post ID.
 * @return string One of UNDERGROWTH_STAGES. Seedling when nothing valid is stored.
 */
function undergrowth_get_stage( int $post_id ): string {
	$stage = get_post_meta( $post_id, 'undergrowth_stage', true );

	return in_array( $stage, UNDERGROWTH_STAGES, true ) ? $stage : 'seedling';
}

/**
 * Tells whether the author has pinned a post's stage.
 *
 * @param int $post_id Post ID.
 * @return bool True when pinned.
 */
function undergrowth_is_stage_pinned( int $post_id ): bool {
	return (bool) get_post_meta( $post_id, 'undergrowth_stage_pinned', true );
}

/**
 * Stores a post's stage.
 *
 * Every change of stage goes through here, so the rules about who may
 * change it are kept by the callers, not repeated for each meta write.
 *
 * @param int    $post_id Post ID.
 * @param string $stage   One of UNDERGROWTH_STAGES.
 * @return bool True when the post holds the stage afterwards.
 */
function undergrowth_set_stage( int $post_id, string $stage ): bool {
	if ( ! in_array( $stage, UNDERGROWTH_STAGES, true ) || ! get_post( $post_id ) instanceof WP_Post ) {
		return false;
	}

	update_post_meta( $post_id, 'undergrowth_stage', $stage );

	return undergrowth_get_stage( $post_id ) === $stage;
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
