<?php
/**
 * Growth stages: storing each post's stage, measuring it on the monthly
 * cycle, counting stages for the admin screens, and the author's own
 * actions: pinning, composting and returning a post to the garden.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_STAGES        = array( 'seedling', 'sprout', 'budding', 'evergreen', 'composting' );
const UNDERGROWTH_GARDEN_STAGES = array( 'seedling', 'sprout', 'budding', 'evergreen' );
const UNDERGROWTH_STAGE_BATCH   = 100;

// A batch of a monthly pass that has not moved on this long after it
// started, with no next batch queued, died and is run again. A batch takes
// seconds, but WP-Cron can run without a time limit, so this is far longer
// than any batch that is still alive. The check runs hourly anyway.
const UNDERGROWTH_PASS_STALL = HOUR_IN_SECONDS;

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
add_action( 'add_meta_boxes', 'undergrowth_remove_added_custom_fields_box', 99 );
add_filter( 'cron_schedules', 'undergrowth_cron_schedules' );
// Activation does not run on plugin updates or on every site of a network.
add_action( 'init', 'undergrowth_schedule_stage_cycle' );
add_action( 'undergrowth_stage_cycle', 'undergrowth_run_stage_cycle' );
add_action( 'undergrowth_backfill_check', 'undergrowth_resume_stalled_passes' );
add_filter( 'posts_clauses', 'undergrowth_order_by_stage_clauses', 10, 2 );

/**
 * Registers the stage meta for every indexed post type, so themes and the
 * REST API can read it. Through REST, only someone who can edit the post
 * reads it; see undergrowth_hide_stage_from_readers().
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

		add_filter( 'rest_prepare_' . $post_type, 'undergrowth_hide_stage_from_readers', 10, 2 );

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
 * Leaves the stage keys out of a post's REST response for anyone who cannot
 * edit the post.
 *
 * Composting is the author's private choice, which the badge block never
 * shows. Leaving out only a composting post's stage would still give it
 * away, so readers get neither key for any post.
 *
 * @param WP_REST_Response $response Response.
 * @param WP_Post          $post     Post.
 * @return WP_REST_Response The response.
 */
function undergrowth_hide_stage_from_readers( $response, $post ) {
	if ( ! $response instanceof WP_REST_Response || ! $post instanceof WP_Post || current_user_can( 'edit_post', $post->ID ) ) {
		return $response;
	}

	$data = $response->get_data();

	if ( is_array( $data ) && isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
		unset( $data['meta']['undergrowth_stage'], $data['meta']['undergrowth_stage_pinned'] );
		$response->set_data( $data );
	}

	return $response;
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
 * Returns a post's stage.
 *
 * @param int $post_id Post ID.
 * @return string One of UNDERGROWTH_STAGES. Seedling when nothing valid is stored.
 *
 * @phpstan-impure It reads meta that the caller may have just changed.
 */
function undergrowth_get_stage( int $post_id ): string {
	// The registered default loads the whole post to learn its type, which
	// a refresh checking hundreds of targets cannot afford.
	if ( ! metadata_exists( 'post', $post_id, 'undergrowth_stage' ) ) {
		return 'seedling';
	}

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
 * Counts the posts in the garden at each stage.
 *
 * These are the posts the growth stages table lists, plus the composting
 * ones it leaves out. Each post is counted once, by the stage
 * undergrowth_get_stage() reads: its first stored row, or seedling when it
 * has none or one Undergrowth does not know.
 *
 * @return array<string, int> Number of posts keyed by stage, in UNDERGROWTH_STAGES order.
 */
function undergrowth_count_stages(): array {
	global $wpdb;

	$counts    = array_fill_keys( UNDERGROWTH_STAGES, 0 );
	$indexable = undergrowth_indexable_sql( 'p.' );

	if ( null === $indexable ) {
		return $counts;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One grouped query instead of loading every post; the condition's placeholders come with it.
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT m.meta_value AS stage, COUNT(*) AS posts FROM %i p LEFT JOIN %i m ON m.meta_id = ( SELECT MIN(f.meta_id) FROM %i f WHERE f.post_id = p.ID AND f.meta_key = %s ) WHERE ' . $indexable[0] . ' GROUP BY m.meta_value',
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->postmeta,
			'undergrowth_stage',
			...$indexable[1]
		),
		ARRAY_A
	);
	// phpcs:enable

	foreach ( $rows as $row ) {
		$stage             = in_array( $row['stage'], UNDERGROWTH_STAGES, true ) ? $row['stage'] : 'seedling';
		$counts[ $stage ] += (int) $row['posts'];
	}

	return $counts;
}

/**
 * Says how many posts are at a stage, in one sentence.
 *
 * @param string $stage One of UNDERGROWTH_STAGES.
 * @param int    $count Number of posts.
 * @return string Translated sentence.
 */
function undergrowth_stage_count_sentence( string $stage, int $count ): string {
	$number = number_format_i18n( $count );

	switch ( $stage ) {
		case 'sprout':
			/* translators: %1$s: number of posts. */
			return sprintf( _n( '%1$s post is a sprout.', '%1$s posts are sprouts.', $count, 'undergrowth' ), $number );
		case 'budding':
			/* translators: %1$s: number of posts. */
			return sprintf( _n( '%1$s post is budding.', '%1$s posts are budding.', $count, 'undergrowth' ), $number );
		case 'evergreen':
			/* translators: %1$s: number of posts. */
			return sprintf( _n( '%1$s post is evergreen.', '%1$s posts are evergreen.', $count, 'undergrowth' ), $number );
		case 'composting':
			/* translators: %1$s: number of posts. */
			return sprintf( _n( '%1$s post is composting.', '%1$s posts are composting.', $count, 'undergrowth' ), $number );
		default:
			/* translators: %1$s: number of posts. */
			return sprintf( _n( '%1$s post is a seedling.', '%1$s posts are seedlings.', $count, 'undergrowth' ), $number );
	}
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
 * the per-post file. The cycle storing a stage that did not change is not,
 * and the cycle never writes the stage of a pinned post.
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

	// Every write of a stage holds this lock, so the cycle can never write
	// between an author's pin and its stage. The cycle gives way to a
	// change in progress, and an author's change waits for the cycle.
	if ( ! undergrowth_lock_stage( $post_id, 'cycle' !== $reason ) ) {
		return false;
	}

	try {
		$changed = undergrowth_store_stage( $post_id, $stage, $reason );
	} finally {
		WP_Upgrader::release_lock( "undergrowth_stage_{$post_id}" );
	}

	if ( null === $changed ) {
		return false;
	}

	if ( $changed ) {
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
 * Takes the lock on a post's stage.
 *
 * A lock left by a request that died is taken over after a minute.
 *
 * @param int  $post_id Post ID.
 * @param bool $wait    Whether to wait up to half a second for another write to finish.
 * @return bool True when this request holds the lock.
 */
function undergrowth_lock_stage( int $post_id, bool $wait ): bool {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$tries = $wait ? 20 : 1;

	for ( $try = 1; $try <= $tries; $try++ ) {
		if ( WP_Upgrader::create_lock( "undergrowth_stage_{$post_id}", MINUTE_IN_SECONDS ) ) {
			return true;
		}

		if ( $try < $tries ) {
			usleep( 25000 );
		}
	}

	return false;
}

/**
 * Stores a post's stage and records the change, while holding its lock.
 *
 * @param int    $post_id Post ID.
 * @param string $stage   One of UNDERGROWTH_STAGES.
 * @param string $reason  What made the change: a key of UNDERGROWTH_STAGE_REASONS.
 * @return bool|null True when the change was recorded, false when the cycle
 *                   stored a stage that did not change, null when nothing was stored.
 */
function undergrowth_store_stage( int $post_id, string $stage, string $reason ): ?bool {
	// The cycle checked the pin before measuring, and the author may have
	// pinned the post since, in another request. The meta cached for this
	// one would not show it, so the pin is read again from the database.
	if ( 'cycle' === $reason ) {
		wp_cache_delete( $post_id, 'post_meta' );

		if ( undergrowth_is_stage_pinned( $post_id ) ) {
			return null;
		}
	}

	$from   = undergrowth_get_stage( $post_id );
	$stored = metadata_exists( 'post', $post_id, 'undergrowth_stage' );

	update_post_meta( $post_id, 'undergrowth_stage', $stage );

	if ( undergrowth_get_stage( $post_id ) !== $stage ) {
		return null;
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

			return null;
		}

		return true;
	}

	return false;
}

/**
 * Returns the recorded changes to a post's stage, oldest first.
 *
 * @param int $post_id Post ID.
 * @return array<int, array{time: int, from: string, to: string, reason: string}> Changes.
 */
function undergrowth_stage_log( int $post_id ): array {
	$entries = array();

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

	// An import can add older rows after newer ones, so rows are put in time
	// order. The index keeps rows from the same second in the order they were
	// added, since usort() is not stable before PHP 8.
	$order = array_keys( $entries );
	array_multisort( array_column( $entries, 'time' ), SORT_ASC, SORT_NUMERIC, $order, SORT_ASC, SORT_NUMERIC, $entries );

	return $entries;
}

/**
 * Measures a post's stage from its accepted links and its cluster.
 *
 * Only links accepted through Undergrowth count, in either direction, and
 * only while the post at the other end is still in the index. A post in a
 * cluster of three or more with links both in and out is Budding. A post with
 * two or more links that is not Budding is a Sprout, including one in a
 * cluster whose links all run one way, which the handover's rules leave
 * between Sprout and Budding.
 *
 * Evergreen is never measured yet. Its rule also needs a hub linking to the
 * post, and hubs come only from fusion in reader mode, so until hubs exist
 * the author can pin Evergreen but the cycle never reaches it. Composting is
 * never measured; only the author chooses it.
 *
 * @param int $post_id Post ID.
 * @return string 'seedling', 'sprout' or 'budding'.
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

	$out = 0;
	$in  = 0;

	foreach ( (array) $rows as $row ) {
		$outgoing = (int) $row['source_id'] === $post_id;
		$other    = get_post( $outgoing ? (int) $row['target_id'] : (int) $row['source_id'] );

		if ( ! $other instanceof WP_Post || ! undergrowth_is_indexable( $other ) ) {
			continue;
		}

		if ( $outgoing ) {
			++$out;
		} else {
			++$in;
		}
	}

	if ( $out > 0 && $in > 0 && array() !== undergrowth_cluster_members( $post_id ) ) {
		return 'budding';
	}

	return $out + $in >= 2 ? 'sprout' : 'seedling';
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
 * Schedules the monthly cycle when it is not scheduled yet.
 *
 * The monthly event clusters the posts first, and its last batch starts the
 * stage cycle, so stages are always measured against complete clusters.
 */
function undergrowth_schedule_stage_cycle(): void {
	// Before clustering, the monthly event measured stages straight away. Only
	// that event has no arguments; the batches it chained are left to finish.
	if ( false !== wp_next_scheduled( 'undergrowth_stage_cycle' ) ) {
		wp_clear_scheduled_hook( 'undergrowth_stage_cycle' );
	}

	if ( false === wp_next_scheduled( 'undergrowth_cluster_cycle' ) ) {
		wp_schedule_event( time(), 'undergrowth_monthly', 'undergrowth_cluster_cycle' );
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

	$indexable = undergrowth_indexable_sql( '' );

	if ( null === $indexable ) {
		undergrowth_end_pass( 'undergrowth_stage_cycle' );
		return;
	}

	$run = undergrowth_mark_pass( 'undergrowth_stage_cycle', $after_id );

	// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The condition's placeholders come with it, one per value.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WP_Query cannot page by ID, which keeps batches stable while posts are published mid-cycle.
	$post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM %i WHERE ID > %d AND {$indexable[0]} ORDER BY ID ASC LIMIT %d",
			array_merge( array( $wpdb->posts, $after_id ), $indexable[1], array( UNDERGROWTH_STAGE_BATCH ) )
		)
	);
	// phpcs:enable

	$post_ids = array_map( 'intval', $post_ids );

	foreach ( $post_ids as $post_id ) {
		undergrowth_cycle_post( $post_id );

		/**
		 * Fires after the monthly stage pass has measured a post.
		 *
		 * @param int $post_id Post ID.
		 */
		do_action( 'undergrowth_stage_cycled', $post_id );
	}

	if ( ! undergrowth_owns_pass( 'undergrowth_stage_cycle', $run ) ) {
		return;
	}

	if ( count( $post_ids ) === UNDERGROWTH_STAGE_BATCH ) {
		undergrowth_queue_pass_batch( 'undergrowth_stage_cycle', end( $post_ids ) );
		return;
	}

	undergrowth_end_pass( 'undergrowth_stage_cycle' );
}

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
 * @return string Token of this run.
 */
function undergrowth_mark_pass( string $hook, int $after_id ): string {
	$run = wp_generate_uuid4();

	update_option(
		$hook . '_pass',
		array(
			'after' => $after_id,
			'at'    => time(),
			'run'   => $run,
		),
		false
	);

	return $run;
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
 */
function undergrowth_queue_pass_batch( string $hook, int $after_id ): void {
	undergrowth_mark_pass( $hook, $after_id );
	wp_schedule_single_event( time(), $hook, array( $after_id ) );
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
 * Runs again the batch of a clustering or stage pass that died.
 *
 * Runs from the hourly check. A pass is resumed only when its batch started
 * at least UNDERGROWTH_PASS_STALL ago and neither that batch nor a new pass
 * from the first post is queued. A run that is still alive after that
 * long gives the pass up to the new one.
 */
function undergrowth_resume_stalled_passes(): void {
	foreach ( array( 'undergrowth_cluster_cycle', 'undergrowth_stage_cycle' ) as $hook ) {
		$pass = get_option( $hook . '_pass' );

		if ( ! is_array( $pass ) || ! isset( $pass['after'], $pass['at'] ) || time() - (int) $pass['at'] < UNDERGROWTH_PASS_STALL ) {
			continue;
		}

		$after_id = (int) $pass['after'];

		if ( false !== wp_next_scheduled( $hook, array( $after_id ) ) || false !== wp_next_scheduled( $hook, array( 0 ) ) ) {
			continue;
		}

		undergrowth_queue_pass_batch( $hook, $after_id );
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

	$order    = array_flip( UNDERGROWTH_GARDEN_STAGES );
	$measured = undergrowth_measure_stage( $post_id );

	if ( $order[ $measured ] > $order[ $stage ] || ! metadata_exists( 'post', $post_id, 'undergrowth_stage' ) ) {
		undergrowth_set_stage( $post_id, $measured );
	}
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
	return new WP_Error( 'undergrowth_not_saved', undergrowth_stage_result_messages()['stage_not_saved'] );
}

/**
 * Returns the error for a stage change that failed after the pin marker
 * was taken away, when the marker could not be put back either.
 *
 * @return WP_Error The error.
 */
function undergrowth_pin_lost(): WP_Error {
	return new WP_Error( 'undergrowth_pin_lost', undergrowth_stage_result_messages()['stage_pin_lost'] );
}

/**
 * Returns the error for a pin that failed after its marker was stored, when
 * the marker could not be taken away again.
 *
 * @return WP_Error The error.
 */
function undergrowth_pin_left(): WP_Error {
	return new WP_Error( 'undergrowth_pin_left', undergrowth_stage_result_messages()['stage_pin_left'] );
}

/**
 * Returns the sentence for each result of a stage action, by result.
 *
 * The admin notice and the editor's REST route both read from here, so each
 * sentence is written once.
 *
 * @return array<string, string> Sentences, by result.
 */
function undergrowth_stage_result_messages(): array {
	return array(
		'pinned'           => __( 'Stage pinned. The monthly cycle will leave it as it is.', 'undergrowth' ),
		'unpinned'         => __( 'Stage unpinned and measured again from the links you have accepted.', 'undergrowth' ),
		'composted'        => __( 'Moved to compost. The post stays published, and no links are suggested to or from it.', 'undergrowth' ),
		'returned'         => __( 'Returned to the garden. Its stage was measured again from the links you have accepted.', 'undergrowth' ),
		'stage_refused'    => __( 'That stage cannot be pinned, so nothing was changed.', 'undergrowth' ),
		'stage_composting' => __( 'A composting post cannot be pinned, so nothing was changed.', 'undergrowth' ),
		'not_pinned'       => __( 'That stage was not pinned, so nothing was changed.', 'undergrowth' ),
		'stage_not_saved'  => __( 'The stage could not be saved, so nothing was changed. Try again in a moment.', 'undergrowth' ),
		'stage_pin_left'   => __( 'The stage could not be saved, and the pin marker could not be removed. Unpin the post to clear it.', 'undergrowth' ),
		'stage_pin_lost'   => __( 'The stage could not be saved, and the pin marker could not be put back. Pin the stage again to keep it.', 'undergrowth' ),
	);
}

/**
 * Puts the pin marker back as it was before a stage change that failed.
 *
 * Pins are the author's, so a failed change must leave the marker as the
 * author left it.
 *
 * @param int  $post_id Post ID.
 * @param bool $pinned  Whether the post was pinned before the change.
 * @return WP_Error|null The pin_lost error when the marker could not be put back, or null.
 */
function undergrowth_restore_pin( int $post_id, bool $pinned ): ?WP_Error {
	if ( $pinned ) {
		update_post_meta( $post_id, 'undergrowth_stage_pinned', '1' );
	} else {
		delete_post_meta( $post_id, 'undergrowth_stage_pinned' );
	}

	return undergrowth_is_stage_pinned( $post_id ) === $pinned ? null : undergrowth_pin_lost();
}

/**
 * Builds the marker a pinned stage shows beside its name.
 *
 * A composting post is never shown as pinned, since composting is always
 * the author's own choice.
 *
 * @param int $post_id Post ID.
 * @return string Escaped HTML, starting with a space, or an empty string when the stage is not pinned.
 */
function undergrowth_pinned_marker( int $post_id ): string {
	if ( 'composting' === undergrowth_get_stage( $post_id ) || ! undergrowth_is_stage_pinned( $post_id ) ) {
		return '';
	}

	return sprintf(
		' <span class="dashicons dashicons-sticky undergrowth-pinned" aria-hidden="true" title="%1$s"></span><span class="screen-reader-text">%1$s</span>',
		esc_attr__( 'Pinned', 'undergrowth' )
	);
}

/**
 * Names the choices a list of posts can be narrowed to by stage.
 *
 * The Posts list's filter and the growth stages table's views both offer
 * these, so the two never disagree. Composting is not one of them: it is a
 * view of its own on the Posts list, and rests outside the stages table.
 *
 * @return array<string, string> Translated labels keyed by value.
 */
function undergrowth_stage_filters(): array {
	$filters = array();

	foreach ( UNDERGROWTH_GARDEN_STAGES as $stage ) {
		$filters[ $stage ] = undergrowth_stage_label( $stage );
	}

	$filters['pinned']   = __( 'Pinned', 'undergrowth' );
	$filters['unpinned'] = __( 'Not pinned', 'undergrowth' );

	return $filters;
}

/**
 * Reads the stage filter's value from the address.
 *
 * @return string A key of undergrowth_stage_filters(), or an empty string for none or anything else.
 */
function undergrowth_stage_filter_value(): string {
	// The filter only selects which posts to list; nothing is changed.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$value = isset( $_GET['undergrowth_stage'] ) && is_string( $_GET['undergrowth_stage'] ) ? sanitize_key( wp_unslash( $_GET['undergrowth_stage'] ) ) : '';
	// phpcs:enable

	return isset( undergrowth_stage_filters()[ $value ] ) ? $value : '';
}

/**
 * Builds the meta query clause that leaves composting posts out.
 *
 * A post with no stage stored is a seedling, so only a composting value
 * can leave it out.
 *
 * @return array<int|string, mixed> Meta query clause.
 */
function undergrowth_garden_meta_query(): array {
	return array(
		'relation' => 'OR',
		array(
			'key'     => 'undergrowth_stage',
			'compare' => 'NOT EXISTS',
		),
		array(
			'key'     => 'undergrowth_stage',
			'value'   => 'composting',
			'compare' => '!=',
		),
	);
}

/**
 * Builds the meta query that keeps a query to composting posts.
 *
 * @return array<int, array<string, string>> Meta query.
 */
function undergrowth_composting_meta_query(): array {
	return array(
		array(
			'key'   => 'undergrowth_stage',
			'value' => 'composting',
		),
	);
}

/**
 * Builds the meta query clause for one of the stage filters.
 *
 * Each post is read as undergrowth_get_stage() reads it: one with no stage
 * stored, or one Undergrowth does not know, is a seedling. A composting
 * post is never pinned, and is not counted as unpinned either.
 *
 * @param string $filter A key of undergrowth_stage_filters().
 * @return array<int|string, mixed> Meta query clause.
 */
function undergrowth_stage_filter_meta_query( string $filter ): array {
	switch ( $filter ) {
		case 'pinned':
			return array(
				'key'     => 'undergrowth_stage_pinned',
				'compare' => 'EXISTS',
			);
		case 'unpinned':
			return array(
				'relation' => 'AND',
				array(
					'key'     => 'undergrowth_stage_pinned',
					'compare' => 'NOT EXISTS',
				),
				undergrowth_garden_meta_query(),
			);
		case 'seedling':
			return array(
				'relation' => 'OR',
				array(
					'key'   => 'undergrowth_stage',
					'value' => 'seedling',
				),
				array(
					'key'     => 'undergrowth_stage',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'undergrowth_stage',
					'value'   => UNDERGROWTH_STAGES,
					'compare' => 'NOT IN',
				),
			);
		default:
			return array(
				'key'   => 'undergrowth_stage',
				'value' => $filter,
			);
	}
}

/**
 * Counts the published garden posts whose stage is pinned.
 *
 * @return int Number of posts.
 */
function undergrowth_count_pinned(): int {
	$post_types = undergrowth_indexed_post_types();

	if ( array() === $post_types ) {
		return 0;
	}

	$query = new WP_Query(
		undergrowth_garden_query_args(
			array(
				'meta_query'             => array( undergrowth_stage_filter_meta_query( 'pinned' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Counted once per screen.
				'posts_per_page'         => 1,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
			)
		)
	);

	return (int) $query->found_posts;
}

/**
 * Orders a query by growth stage or by pin, when it asks with the
 * undergrowth_orderby query variable, in the direction undergrowth_order
 * gives, or order when that is not set.
 *
 * Stages sort by rank, from Seedling to Evergreen with Composting after,
 * not by name, which would differ in every language. Pinned sorts pinned
 * posts last, or first when the order is descending. Only a post's first
 * row counts, as undergrowth_get_stage() reads it. Posts outside the
 * garden have no stage and stay last in either direction. The query's own
 * order breaks ties.
 *
 * @param array<string, string> $clauses Query clauses.
 * @param WP_Query              $query   Query about to run.
 * @return array<string, string> Query clauses.
 */
function undergrowth_order_by_stage_clauses( $clauses, $query ) {
	global $wpdb;

	$by = $query instanceof WP_Query ? $query->get( 'undergrowth_orderby' ) : '';

	if ( 'stage' !== $by && 'pinned' !== $by ) {
		return $clauses;
	}

	$indexable = undergrowth_indexable_sql( "{$wpdb->posts}." );

	if ( null === $indexable ) {
		return $clauses;
	}

	// The query's own order may run the other way, to break ties.
	$order = $query->get( 'undergrowth_order' );
	$order = 'DESC' === strtoupper( (string) ( '' === $order ? $query->get( 'order' ) : $order ) ) ? 'DESC' : 'ASC';

	$clauses['join'] .= $wpdb->prepare(
		' LEFT JOIN %i AS undergrowth_sort ON undergrowth_sort.meta_id = ( SELECT MIN(f.meta_id) FROM %i f WHERE f.post_id = %i.ID AND f.meta_key = %s )',
		$wpdb->postmeta,
		$wpdb->postmeta,
		$wpdb->posts,
		'stage' === $by ? 'undergrowth_stage' : 'undergrowth_stage_pinned'
	);

	if ( 'stage' === $by ) {
		$rank = 'CASE undergrowth_sort.meta_value';

		foreach ( UNDERGROWTH_STAGES as $position => $stage ) {
			$rank .= $wpdb->prepare( ' WHEN %s THEN %d', $stage, $position );
		}

		$rank .= ' ELSE 0 END';
	} else {
		$rank = 'CASE WHEN undergrowth_sort.meta_id IS NULL THEN 0 ELSE 1 END';
	}

	$then = trim( (string) $clauses['orderby'] );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The condition's placeholders come with it, and the ranks were prepared above.
	$clauses['orderby'] = $wpdb->prepare( "CASE WHEN {$indexable[0]} THEN 0 ELSE 1 END ASC, ", ...$indexable[1] )
		. "{$rank} {$order}" . ( '' === $then ? '' : ', ' . $then );
	// phpcs:enable

	return $clauses;
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

	if ( ! in_array( $stage, UNDERGROWTH_GARDEN_STAGES, true ) ) {
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

			if ( undergrowth_is_stage_pinned( $post_id ) ) {
				return undergrowth_pin_left();
			}
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
		return undergrowth_restore_pin( $post_id, true ) ?? undergrowth_stage_not_saved();
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

	if ( undergrowth_is_stage_pinned( $post_id ) ) {
		return undergrowth_stage_not_saved();
	}

	if ( ! undergrowth_set_stage( $post_id, 'composting', 'compost' ) ) {
		// Only a marker taken away here is put back. A post that was not
		// pinned is left alone, so a pin made meanwhile stays.
		return ( $pinned ? undergrowth_restore_pin( $post_id, true ) : null ) ?? undergrowth_stage_not_saved();
	}

	undergrowth_forget_post_links( $post_id );

	// The file was written for the change of stage, before the proposals
	// went, so it is written again without them.
	undergrowth_write_memory( $post_id );

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
