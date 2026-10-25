<?php
/**
 * Dev tools for local test sites: Posts › Undergrowth › Dev tools, which
 * accepts every suggestion, runs the monthly cycle now, and undoes all of it.
 *
 * Nothing here exists on a staging or production site, where a human
 * approves every change one at a time. Even locally, each tool starts only
 * when the author clicks it, and accepting goes through the same
 * undergrowth_accept_link() as the Accept button, so no word is ever written
 * that Accept would not write.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

// Late on init, so a theme or plugin can turn the tools off first.
add_action( 'init', 'undergrowth_load_dev_tools', 99 );

/**
 * Tells whether the dev tools may exist on a site.
 *
 * Only a local or development site has them. The undergrowth_dev_tools_enabled
 * filter can turn them off there, but nothing can turn them on anywhere
 * else: the environment is checked before the filter runs.
 *
 * @param string|null $environment Environment type to check, or null for this site's.
 * @return bool True on a local or development site, unless filtered off.
 */
function undergrowth_dev_tools_enabled( ?string $environment = null ): bool {
	$environment = $environment ?? wp_get_environment_type();

	if ( ! in_array( $environment, array( 'local', 'development' ), true ) ) {
		return false;
	}

	/**
	 * Filters whether the dev tools are offered on a local or development
	 * site. Returning true elsewhere has no effect.
	 *
	 * @param bool   $enabled     Whether the dev tools are offered. Default true.
	 * @param string $environment The site's environment type.
	 */
	return true === apply_filters( 'undergrowth_dev_tools_enabled', true, $environment );
}

/**
 * Registers the dev tools when this site may have them.
 *
 * Actions expect no return value, so this wraps the registration.
 */
function undergrowth_load_dev_tools(): void {
	undergrowth_register_dev_tools();
}

/**
 * Registers the Dev tools tab, its handlers and its cron event, and the
 * WP-CLI commands when WP-CLI runs, only on a site that may have them.
 *
 * @param string|null $environment Environment type, or null for this site's.
 * @return bool True when the tools were registered.
 */
function undergrowth_register_dev_tools( ?string $environment = null ): bool {
	if ( ! undergrowth_dev_tools_enabled( $environment ) ) {
		return false;
	}

	add_filter( 'undergrowth_screen_tabs', 'undergrowth_add_dev_tab' );
	add_action( 'undergrowth_render_dev_tab', 'undergrowth_render_dev_tab' );
	add_action( 'admin_post_undergrowth_dev_accept', 'undergrowth_handle_dev_accept' );
	add_action( 'admin_post_undergrowth_dev_cycle', 'undergrowth_handle_dev_cycle' );
	add_action( 'admin_post_undergrowth_dev_reset', 'undergrowth_handle_dev_reset' );
	add_action( 'undergrowth_dev_job', 'undergrowth_continue_dev_job' );

	/**
	 * Fires once the dev tools are registered, which happens only on a local
	 * or development site. src/cli.php adds the WP-CLI commands here.
	 */
	do_action( 'undergrowth_dev_tools_registered' );

	return true;
}

/**
 * Adds the Dev tools tab for administrators.
 *
 * @param array<string, string> $tabs Tab labels keyed by tab.
 * @return array<string, string> Tabs.
 */
function undergrowth_add_dev_tab( $tabs ) {
	$tabs = (array) $tabs;

	if ( current_user_can( 'manage_options' ) ) {
		$tabs['dev'] = __( 'Dev tools', 'undergrowth' );
	}

	return $tabs;
}

/**
 * Returns the job a dev tool is running, or ran last.
 *
 * @return array{type: string, user: int, per_post: int, forget_vectors: bool, phase: string, cursor: int, counts: array<string, int>, done: bool, updated: int}|null
 *         The job, or null when there is none.
 */
function undergrowth_dev_job(): ?array {
	$job = get_option( 'undergrowth_dev_job' );

	return is_array( $job ) && isset( $job['type'], $job['phase'], $job['counts'], $job['done'] ) ? $job : null;
}

/**
 * Tells whether a dev tools job is still under way.
 *
 * A job that has not moved for ten minutes counts as stopped, so a site
 * whose cron never ran can start again.
 *
 * @return bool True while a job is unfinished and moving.
 */
function undergrowth_dev_job_running(): bool {
	$job = undergrowth_dev_job();

	return null !== $job && ! $job['done'] && time() - (int) $job['updated'] < 10 * MINUTE_IN_SECONDS;
}

/**
 * Starts Accept all or the undo, unless a job is already running.
 *
 * @param string $type    'accept' or 'reset'.
 * @param array  $options per_post for Accept all, forget_vectors for the undo.
 * @return bool True when the job was started.
 *
 * @phpstan-param array{per_post?: int, forget_vectors?: bool} $options
 */
function undergrowth_start_dev_job( string $type, array $options = array() ): bool {
	if ( undergrowth_dev_job_running() ) {
		return false;
	}

	wp_unschedule_hook( 'undergrowth_dev_job' );

	return update_option(
		'undergrowth_dev_job',
		array(
			'type'           => $type,
			'user'           => get_current_user_id(),
			'per_post'       => max( 0, (int) ( $options['per_post'] ?? 0 ) ),
			'forget_vectors' => (bool) ( $options['forget_vectors'] ?? false ),
			'phase'          => 'accept' === $type ? 'accept' : 'unlink',
			'cursor'         => 0,
			'counts'         => array(),
			'done'           => false,
			'updated'        => time(),
		),
		false
	);
}

/**
 * Seconds a request or a cron run may spend on a job before handing the
 * rest to the next cron run.
 *
 * @return float Seconds.
 */
function undergrowth_dev_job_budget(): float {
	/**
	 * Filters how long one request may spend on a dev tools job. It always
	 * does at least one post.
	 *
	 * @param float $seconds Seconds per run. Default the backfill's budget.
	 */
	return max( 0.0, (float) apply_filters( 'undergrowth_dev_job_budget', undergrowth_backfill_time_budget() ) );
}

/**
 * Works on the current job, one post at a time, until it is done or the
 * time is up, and queues the rest for cron.
 *
 * @param float|null $budget Seconds to spend, or null for no limit.
 * @return array<string, mixed>|null The job afterwards, as undergrowth_dev_job() returns it, or null when there is none.
 */
function undergrowth_run_dev_job( ?float $budget ): ?array {
	$job = undergrowth_dev_job();

	if ( null === $job || $job['done'] ) {
		return $job;
	}

	$start = microtime( true );

	do {
		$job = undergrowth_dev_job_step( $job );
		update_option( 'undergrowth_dev_job', array_merge( $job, array( 'updated' => time() ) ), false );
	} while ( ! $job['done'] && ( null === $budget || microtime( true ) - $start < $budget ) );

	if ( ! $job['done'] && false === wp_next_scheduled( 'undergrowth_dev_job' ) ) {
		wp_schedule_single_event( time(), 'undergrowth_dev_job' );
	}

	return $job;
}

/**
 * Carries on with the current job from cron, as the user who started it,
 * so Accept's rules are that user's, as they are for the Accept button.
 */
function undergrowth_continue_dev_job(): void {
	$job = undergrowth_dev_job();

	if ( null === $job || $job['done'] ) {
		return;
	}

	$previous = get_current_user_id();
	wp_set_current_user( (int) $job['user'] );

	try {
		undergrowth_run_dev_job( undergrowth_dev_job_budget() );
	} finally {
		wp_set_current_user( $previous );
	}
}

/**
 * Does one step of a job: one post, or the clearing in between.
 *
 * Accept all walks the posts with suggestions. The undo walks the posts with
 * accepted links, then clears what Undergrowth stored, then suggests links
 * again from the vectors it kept.
 *
 * @param array<string, mixed> $job The job, as undergrowth_dev_job() returns it.
 * @return array<string, mixed> The job afterwards.
 */
function undergrowth_dev_job_step( array $job ): array {
	global $wpdb;

	$counts = (array) $job['counts'];

	if ( 'clear' === $job['phase'] ) {
		$counts        = undergrowth_dev_add_counts( $counts, undergrowth_dev_reset_clear( (bool) $job['forget_vectors'] ) );
		$job['phase']  = $job['forget_vectors'] ? 'done' : 'propose';
		$job['cursor'] = 0;
	} elseif ( 'propose' === $job['phase'] ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; vectors change on every embed.
		$post_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM %i WHERE post_id > %d ORDER BY post_id ASC LIMIT 1', undergrowth_vectors_table(), (int) $job['cursor'] ) );

		if ( 0 === $post_id ) {
			$job['phase'] = 'done';
		} else {
			$post = get_post( $post_id );

			if ( $post instanceof WP_Post && undergrowth_is_indexable( $post ) ) {
				undergrowth_refresh_proposals( $post_id );
			}

			$job['cursor'] = $post_id;
		}
	} else {
		$state = 'accept' === $job['phase'] ? 'proposed' : 'accepted';

		$source_id = undergrowth_dev_next_source( $state, (int) $job['cursor'] );

		if ( 0 === $source_id ) {
			$job['phase'] = 'accept' === $job['phase'] ? 'done' : 'clear';
		} else {
			$counts        = undergrowth_dev_add_counts( $counts, 'accept' === $job['phase'] ? undergrowth_dev_accept_source( $source_id, (int) $job['per_post'] ) : undergrowth_dev_unlink_source( $source_id ) );
			$job['cursor'] = $source_id;
		}
	}

	$job['counts'] = $counts;
	$job['done']   = 'done' === $job['phase'];

	return $job;
}

/**
 * Finds the next post with a link in a state.
 *
 * @param string $state    'proposed' or 'accepted'.
 * @param int    $after_id Look at posts with a higher ID than this.
 * @return int Post ID, or 0 when there is none.
 */
function undergrowth_dev_next_source( string $state, int $after_id ): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; states change as the job runs.
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(source_id) FROM %i WHERE state = %s AND source_id > %d', undergrowth_links_table(), $state, $after_id ) );
}

/**
 * Lists the posts that have suggestions, in the order Accept all takes them.
 *
 * @return int[] Post IDs.
 */
function undergrowth_dev_suggesting_posts(): array {
	$posts  = array();
	$source = undergrowth_dev_next_source( 'proposed', 0 );

	while ( 0 !== $source ) {
		$posts[] = $source;
		$source  = undergrowth_dev_next_source( 'proposed', $source );
	}

	return $posts;
}

/**
 * Runs the monthly cycle now, to its end, in this request: the cluster
 * pass, then every batch it queues, and the stage cycle and resurfacing it
 * chains. The monthly event itself is left where it is.
 *
 * @return int How many batches ran, or 0 when a clustering batch was already
 *             running and the pass was queued to start a minute later.
 */
function undergrowth_dev_run_cycle(): int {
	$hooks = array( 'undergrowth_cluster_cycle', 'undergrowth_stage_cycle', 'undergrowth_resurfacing_cycle' );

	// A clustering batch is running; the pass is queued for a minute later.
	if ( ! undergrowth_cluster_batch_under_lock( 0, '' ) ) {
		return 0;
	}

	$runs = 1;

	// Each run may queue the next, so the queue is read again after each.
	do {
		$ran = false;

		foreach ( wp_get_ready_cron_jobs() as $timestamp => $events ) {
			foreach ( $hooks as $hook ) {
				foreach ( $events[ $hook ] ?? array() as $event ) {
					if ( false !== $event['schedule'] ) {
						continue;
					}

					wp_unschedule_event( (int) $timestamp, $hook, $event['args'] );
					do_action_ref_array( $hook, $event['args'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- One of the plugin's own cron hooks, listed above.
					++$runs;
					$ran = true;

					continue 4;
				}
			}
		}
	} while ( $ran && $runs < 10000 );

	return $runs;
}

/**
 * Adds one step's counts to a job's.
 *
 * @param array<string, int> $counts Counts so far.
 * @param array<string, int> $more   Counts to add.
 * @return array<string, int> Counts.
 */
function undergrowth_dev_add_counts( array $counts, array $more ): array {
	foreach ( $more as $key => $count ) {
		$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + $count;
	}

	return $counts;
}

/**
 * Accepts a post's suggestions, best first, as the Accept button would.
 *
 * @param int $source_id Post the links would sit in.
 * @param int $per_post  Most suggestions to try in the post, or 0 for all.
 * @return array<string, int> How many ended each way: 'accepted', or why
 *                            not, in undergrowth_accept_link()'s words, or
 *                            'forbidden' when the user cannot edit the post.
 */
function undergrowth_dev_accept_source( int $source_id, int $per_post ): array {
	$counts = array();

	foreach ( undergrowth_dev_suggested_targets( $source_id, $per_post ) as $target_id ) {
		$result            = current_user_can( 'edit_post', $source_id ) ? undergrowth_accept_link( $source_id, $target_id ) : 'forbidden';
		$counts[ $result ] = ( $counts[ $result ] ?? 0 ) + 1;
	}

	return $counts;
}

/**
 * Lists a post's suggested targets, best first.
 *
 * @param int $source_id Post the links would sit in.
 * @param int $per_post  Most targets to list, or 0 for all.
 * @return int[] Post IDs.
 */
function undergrowth_dev_suggested_targets( int $source_id, int $per_post ): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; states change as the job runs.
	$targets = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT target_id FROM %i WHERE source_id = %d AND state = %s ORDER BY score DESC, target_id ASC',
			undergrowth_links_table(),
			$source_id,
			'proposed'
		)
	);

	$targets = array_map( 'intval', $targets );

	return $per_post > 0 ? array_slice( $targets, 0, $per_post ) : $targets;
}

/**
 * Removes the links Undergrowth added to a post, keeping everything else.
 *
 * Each accepted link is found as Accept wrote it, with the target's address
 * and nothing else in the tag, around plain words inside the sentence it was
 * accepted in. A post nobody edited since gets back exactly the content it
 * had before; a post edited since keeps those edits. A link the author has
 * changed, moved out of its sentence or removed is left as it is.
 *
 * @param int $source_id Post the links sit in.
 * @return array<string, int> 'links_removed', 'links_left', and
 *                            'links_needs_admin' for links kses kept in place.
 */
function undergrowth_dev_unlink_source( int $source_id ): array {
	global $wpdb;

	$post = get_post( $source_id );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; states change as the job runs.
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT target_id, sentence FROM %i WHERE source_id = %d AND state = %s',
			undergrowth_links_table(),
			$source_id,
			'accepted'
		),
		ARRAY_A
	);

	if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $source_id ) ) {
		return array( 'links_left' => count( $rows ) );
	}

	$content = $post->post_content;
	$removed = 0;

	foreach ( $rows as $row ) {
		$url     = get_permalink( (int) $row['target_id'] );
		$without = false === $url ? null : undergrowth_dev_remove_link( $content, esc_url( $url ), (string) $row['sentence'] );

		if ( null !== $without ) {
			$content = $without;
			++$removed;
		}
	}

	$left = count( $rows ) - $removed;

	if ( 0 === $removed ) {
		return array( 'links_left' => $left );
	}

	// As for Accept: a save that kses would change elsewhere is refused.
	if ( ! current_user_can( 'unfiltered_html' ) && wp_kses_post( $content ) !== $content ) {
		return array(
			'links_left'        => $left,
			'links_needs_admin' => $removed,
		);
	}

	$updated = wp_update_post(
		wp_slash(
			array(
				'ID'           => $source_id,
				'post_content' => $content,
			)
		),
		true
	);

	if ( is_wp_error( $updated ) || 0 === $updated ) {
		return array( 'links_left' => count( $rows ) );
	}

	return array(
		'links_removed' => $removed,
		'links_left'    => $left,
	);
}

/**
 * Removes one link exactly as Accept wrote it, keeping its words.
 *
 * Only a link with the address alone in its tag, around words with no tag
 * of their own, and lying inside the sentence it was accepted in, counts.
 *
 * @param string $content  Post content.
 * @param string $url      The address as Accept wrote it, already escaped.
 * @param string $sentence Sentence the link was accepted in, as stored.
 * @return string|null The content without that link, or null when there is none.
 */
function undergrowth_dev_remove_link( string $content, string $url, string $sentence ): ?string {
	if ( '' === $sentence || ! preg_match_all( '#<a href="' . preg_quote( $url, '#' ) . '">([^<]*)</a>#', $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		return null;
	}

	$plain = undergrowth_plain_text( $content );

	foreach ( $matches as $match ) {
		list( $tag, $at ) = $match[0];

		$words = undergrowth_plain_text( $match[1][0] );
		$from  = mb_strlen( undergrowth_plain_text( substr( $content, 0, $at ) ) );
		$found = mb_strpos( $plain, $sentence, max( 0, $from - mb_strlen( $sentence ) ) );

		// The sentence must hold the link's words where the link has them.
		while ( false !== $found && $found <= $from ) {
			if ( $found + mb_strlen( $sentence ) >= $from + mb_strlen( $words ) ) {
				return substr( $content, 0, $at ) . $match[1][0] . substr( $content, $at + strlen( $tag ) );
			}

			$found = mb_strpos( $plain, $sentence, $found + 1 );
		}
	}

	return null;
}

/**
 * Clears everything Undergrowth stored about the posts, but not the posts.
 *
 * Every suggestion, accept and veto goes, with stages and their history,
 * pins, compost, clusters and their names, resurfacing signals and their
 * history, and the per-post files. Vectors and the scores measured from them
 * stay unless asked, so nothing needs reading again. Questions, Discord and
 * settings are left alone. The monthly cycle stays scheduled.
 *
 * @param bool $forget_vectors Whether to forget vectors too, and read every post again.
 * @return array<string, int> How many of each kind were cleared: 'proposed',
 *                            'accepted_rows', 'vetoed', 'stages', 'files', and 'vectors'.
 */
function undergrowth_dev_reset_clear( bool $forget_vectors ): array {
	global $wpdb;

	$counts = array(
		'proposed'      => 0,
		'accepted_rows' => 0,
		'vetoed'        => 0,
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; counted just before it is emptied.
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT state, COUNT(*) AS n FROM %i GROUP BY state', undergrowth_links_table() ), ARRAY_A ) as $row ) {
		$counts[ 'accepted' === $row['state'] ? 'accepted_rows' : (string) $row['state'] ] = (int) $row['n'];
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', undergrowth_links_table() ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counted once, just before the meta goes.
	$counts['stages'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", 'undergrowth_stage' ) );

	foreach ( array( 'undergrowth_stage', 'undergrowth_stage_log', 'undergrowth_stage_pinned', 'undergrowth_cluster', 'undergrowth_resurfacing', 'undergrowth_resurfacing_log' ) as $key ) {
		delete_post_meta_by_key( $key );
	}

	foreach ( array( 'undergrowth_cluster_leaders', 'undergrowth_cluster_cycle_pass', 'undergrowth_cluster_similarity', 'undergrowth_stage_cycle_pass', 'undergrowth_cluster_labels', 'undergrowth_previous_clusters', 'undergrowth_new_clusters' ) as $option ) {
		delete_option( $option );
	}

	// A pass under way would put back what was just cleared.
	foreach ( array( 'undergrowth_cluster_cycle', 'undergrowth_stage_cycle', 'undergrowth_resurfacing_cycle' ) as $hook ) {
		wp_unschedule_hook( $hook );
	}

	undergrowth_schedule_stage_cycle();

	$folder          = dirname( undergrowth_memory_path( 0 ) );
	$counts['files'] = '.' === $folder ? 0 : count( (array) glob( $folder . '/*.txt' ) );
	undergrowth_delete_memory_folder();

	$counts['vectors'] = 0;

	if ( $forget_vectors ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; counted just before it is emptied.
		$counts['vectors'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', undergrowth_vectors_table() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', undergrowth_vectors_table() ) );
		delete_option( 'undergrowth_scores' );
		delete_option( 'undergrowth_backfill' );
		undergrowth_start_backfill();
	}

	return $counts;
}

/**
 * Returns to the Dev tools tab with a notice.
 *
 * @param string $notice Notice to show.
 */
function undergrowth_finish_dev_action( string $notice ): void {
	wp_safe_redirect( undergrowth_suggestions_url( $notice, 'dev' ) );
	exit;
}

/**
 * Checks a dev tools form's nonce and that the user may use the tools.
 *
 * @param string $action 'accept', 'cycle' or 'reset'.
 */
function undergrowth_check_dev_action( string $action ): void {
	check_admin_referer( 'undergrowth_dev_' . $action );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You cannot use the dev tools.', 'undergrowth' ), '', array( 'response' => 403 ) );
	}
}

/**
 * Starts Accept all, does what fits in this request, and returns to the tab.
 */
function undergrowth_handle_dev_accept(): void {
	undergrowth_check_dev_action( 'accept' );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by undergrowth_check_dev_action().
	$per_post = isset( $_POST['per_post'] ) ? absint( wp_unslash( $_POST['per_post'] ) ) : 0;

	if ( ! undergrowth_start_dev_job( 'accept', array( 'per_post' => $per_post ) ) ) {
		undergrowth_finish_dev_action( 'dev_busy' );
	}

	undergrowth_run_dev_job( undergrowth_dev_job_budget() );
	undergrowth_finish_dev_action( 'dev_accept_started' );
}

/**
 * Queues the monthly cycle, which chains the stage cycle and resurfacing.
 */
function undergrowth_handle_dev_cycle(): void {
	undergrowth_check_dev_action( 'cycle' );

	if ( false === wp_next_scheduled( 'undergrowth_cluster_cycle', array( 0 ) ) ) {
		wp_schedule_single_event( time(), 'undergrowth_cluster_cycle', array( 0 ) );
	}

	undergrowth_finish_dev_action( 'dev_cycle_queued' );
}

/**
 * Starts the undo, does what fits in this request, and returns to the tab.
 */
function undergrowth_handle_dev_reset(): void {
	undergrowth_check_dev_action( 'reset' );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by undergrowth_check_dev_action().
	if ( ! undergrowth_start_dev_job( 'reset', array( 'forget_vectors' => ! empty( $_POST['forget_vectors'] ) ) ) ) {
		undergrowth_finish_dev_action( 'dev_busy' );
	}

	undergrowth_run_dev_job( undergrowth_dev_job_budget() );
	undergrowth_finish_dev_action( 'dev_reset_started' );
}

/**
 * Names each count a job keeps, for the tab.
 *
 * @return array<string, string> Labels keyed by count.
 */
function undergrowth_dev_count_labels(): array {
	return array(
		'accepted'          => __( 'Accepted', 'undergrowth' ),
		'edited'            => __( 'Not accepted, sentence changed', 'undergrowth' ),
		'no_word'           => __( 'Not accepted, no words could be linked safely', 'undergrowth' ),
		'not_saved'         => __( 'Not accepted, the post could not be saved', 'undergrowth' ),
		'needs_admin'       => __( 'Not accepted, saving would change other content', 'undergrowth' ),
		'not_proposed'      => __( 'Not accepted, no longer suggested', 'undergrowth' ),
		'gone'              => __( 'Not accepted, post gone', 'undergrowth' ),
		'forbidden'         => __( 'Not accepted, you cannot edit the post', 'undergrowth' ),
		'links_removed'     => __( 'Links removed', 'undergrowth' ),
		'links_left'        => __( 'Links left, changed or moved since', 'undergrowth' ),
		'links_needs_admin' => __( 'Links left, saving would change other content', 'undergrowth' ),
		'proposed'          => __( 'Suggestions cleared', 'undergrowth' ),
		'accepted_rows'     => __( 'Accepted links forgotten', 'undergrowth' ),
		'vetoed'            => __( 'Vetoes cleared', 'undergrowth' ),
		'stages'            => __( 'Stages cleared', 'undergrowth' ),
		'files'             => __( 'Per-post files removed', 'undergrowth' ),
		'vectors'           => __( 'Vectors forgotten', 'undergrowth' ),
	);
}

/**
 * Prints the notice for the dev tools action that brought the user back.
 */
function undergrowth_render_dev_notice(): void {
	// The notice only selects which fixed message to show; nothing is changed.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notice   = isset( $_GET['undergrowth_notice'] ) ? sanitize_key( wp_unslash( $_GET['undergrowth_notice'] ) ) : '';
	$messages = array(
		'dev_accept_started' => __( 'Accepting suggestions. The counts are below.', 'undergrowth' ),
		'dev_reset_started'  => __( 'Undoing what Undergrowth changed. The counts are below.', 'undergrowth' ),
		'dev_cycle_queued'   => __( 'The monthly cycle is queued. It runs with the next cron run; stages and the Map change when it ends.', 'undergrowth' ),
		'dev_busy'           => __( 'Another job is still running, so nothing was started. Wait for it to finish.', 'undergrowth' ),
	);

	if ( isset( $messages[ $notice ] ) ) {
		undergrowth_print_result_notices( array( 'dev_busy' === $notice ? 'notice-warning' : 'notice-success' => '<p>' . esc_html( $messages[ $notice ] ) . '</p>' ) );
	}
}

/**
 * Prints the state and counts of the last job.
 */
function undergrowth_render_dev_job(): void {
	$job = undergrowth_dev_job();

	if ( null === $job ) {
		return;
	}

	echo '<h2>' . esc_html( 'accept' === $job['type'] ? __( 'Last Accept all', 'undergrowth' ) : __( 'Last undo', 'undergrowth' ) ) . '</h2>';

	if ( $job['done'] ) {
		echo '<p>' . esc_html__( 'Finished.', 'undergrowth' ) . '</p>';
	} else {
		printf(
			'<p>%1$s <a href="%2$s">%3$s</a></p>',
			esc_html__( 'Still running in WP-Cron.', 'undergrowth' ),
			esc_url( undergrowth_suggestions_url( '', 'dev' ) ),
			esc_html__( 'Check progress', 'undergrowth' )
		);
	}

	$labels = undergrowth_dev_count_labels();
	$items  = array();

	foreach ( (array) $job['counts'] as $key => $count ) {
		if ( isset( $labels[ $key ] ) && $count > 0 ) {
			/* translators: %1$s: what was counted, such as "Accepted". %2$s: the number. */
			$items[] = '<li>' . esc_html( sprintf( __( '%1$s: %2$s', 'undergrowth' ), $labels[ $key ], number_format_i18n( $count ) ) ) . '</li>';
		}
	}

	echo array() === $items
		? '<p>' . esc_html__( 'Nothing to count yet.', 'undergrowth' ) . '</p>'
		: '<ul class="ul-disc">' . implode( '', $items ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each item is escaped above.
}

/**
 * Prints the Dev tools tab, or the undo's confirmation.
 */
function undergrowth_render_dev_tab(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	undergrowth_render_dev_notice();

	printf(
		'<div class="notice notice-warning inline"><p><strong>%1$s</strong> %2$s</p></div>',
		esc_html__( 'Local development only.', 'undergrowth' ),
		esc_html__( 'These tools exist only on a local or development site, to fill a test site quickly and empty it again. On a real site every link is approved one at a time.', 'undergrowth' )
	);

	// Only selects which step of the undo to show; nothing is changed.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['confirm'] ) && 'reset' === sanitize_key( wp_unslash( $_GET['confirm'] ) ) ) {
		undergrowth_render_dev_reset_confirmation();
		return;
	}

	undergrowth_render_dev_job();

	$action = admin_url( 'admin-post.php' );
	?>
	<h2><?php esc_html_e( 'Accept all suggestions', 'undergrowth' ); ?></h2>
	<p><?php esc_html_e( 'Accepts suggestions exactly as the Accept button does, one by one, as you. A suggestion Accept would refuse is refused here too, and counted.', 'undergrowth' ); ?></p>
	<form method="post" action="<?php echo esc_url( $action ); ?>">
		<input type="hidden" name="action" value="undergrowth_dev_accept" />
		<?php wp_nonce_field( 'undergrowth_dev_accept' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="undergrowth-dev-per-post"><?php esc_html_e( 'Most per post', 'undergrowth' ); ?></label></th>
				<td>
					<input type="number" id="undergrowth-dev-per-post" name="per_post" min="1" step="1" class="small-text" aria-describedby="undergrowth-dev-per-post-description" />
					<p class="description" id="undergrowth-dev-per-post-description"><?php esc_html_e( 'Accepts only the best suggestions in each post. Leave empty to accept every one.', 'undergrowth' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Accept all suggestions', 'undergrowth' ), 'primary', 'submit', false, array( 'id' => 'undergrowth-dev-accept' ) ); ?>
	</form>

	<h2><?php esc_html_e( 'Run the monthly cycle now', 'undergrowth' ); ?></h2>
	<p><?php esc_html_e( 'Groups the posts for the Map, then measures growth stages and looks for composting posts to resurface, as the monthly cycle does.', 'undergrowth' ); ?></p>
	<form method="post" action="<?php echo esc_url( $action ); ?>">
		<input type="hidden" name="action" value="undergrowth_dev_cycle" />
		<?php wp_nonce_field( 'undergrowth_dev_cycle' ); ?>
		<?php submit_button( __( 'Run the monthly cycle now', 'undergrowth' ), 'secondary', 'submit', false, array( 'id' => 'undergrowth-dev-cycle' ) ); ?>
	</form>

	<h2><?php esc_html_e( 'Undo everything Undergrowth changed', 'undergrowth' ); ?></h2>
	<p><?php esc_html_e( 'Removes every link Undergrowth added and forgets every suggestion, veto, stage, pin, group and per-post file. You confirm on the next screen.', 'undergrowth' ); ?></p>
	<p><a class="button" href="<?php echo esc_url( add_query_arg( 'confirm', 'reset', undergrowth_suggestions_url( '', 'dev' ) ) ); ?>"><?php esc_html_e( 'Undo everything…', 'undergrowth' ); ?></a></p>
	<?php
}

/**
 * Prints the undo's confirmation: what it removes, and what it keeps.
 */
function undergrowth_render_dev_reset_confirmation(): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; counted for this screen only.
	$accepted = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE state = %s', undergrowth_links_table(), 'accepted' ) );
	?>
	<h2><?php esc_html_e( 'Undo everything Undergrowth changed?', 'undergrowth' ); ?></h2>
	<ul class="ul-disc">
		<li>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of links. */
					_n( 'Removes %s link Undergrowth added, keeping its words. Edits made to a post since stay. A link changed since is left.', 'Removes %s links Undergrowth added, keeping their words. Edits made to a post since stay. A link changed since is left.', $accepted, 'undergrowth' ),
					number_format_i18n( $accepted )
				)
			);
			?>
		</li>
		<li><?php esc_html_e( 'Forgets every suggestion, veto, stage, pin, compost mark, group, resurfacing signal and per-post file.', 'undergrowth' ); ?></li>
		<li><?php esc_html_e( 'Keeps what was read from each post, and suggests links again from it.', 'undergrowth' ); ?></li>
	</ul>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="undergrowth_dev_reset" />
		<?php wp_nonce_field( 'undergrowth_dev_reset' ); ?>
		<p>
			<input type="checkbox" id="undergrowth-dev-forget-vectors" name="forget_vectors" value="1" aria-describedby="undergrowth-dev-forget-vectors-description" />
			<label for="undergrowth-dev-forget-vectors"><?php esc_html_e( 'Forget what was read from each post too', 'undergrowth' ); ?></label>
		</p>
		<p class="description" id="undergrowth-dev-forget-vectors-description"><?php esc_html_e( 'Every post is then read again, which sends it to the provider again.', 'undergrowth' ); ?></p>
		<p>
			<?php submit_button( __( 'Yes, undo everything', 'undergrowth' ), 'primary', 'submit', false, array( 'id' => 'undergrowth-dev-reset' ) ); ?>
			<a class="button" href="<?php echo esc_url( undergrowth_suggestions_url( '', 'dev' ) ); ?>"><?php esc_html_e( 'Cancel', 'undergrowth' ); ?></a>
		</p>
	</form>
	<?php
}
