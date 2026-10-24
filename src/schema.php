<?php
/**
 * The plugin's tables and lifecycle: install, upgrade, deactivation and
 * uninstall.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_SCHEMA_VERSION = 5;

add_filter( 'is_protected_meta', 'undergrowth_protect_meta', 10, 2 );

/**
 * Lists the post meta keys the plugin keeps.
 *
 * @return string[] Meta keys.
 */
function undergrowth_protected_meta_keys(): array {
	return array(
		'undergrowth_cluster',
		'undergrowth_discord_messages',
		'undergrowth_pending_links',
		'undergrowth_prompt_keys',
		'undergrowth_prompt_log',
		'undergrowth_resurfacing',
		'undergrowth_resurfacing_log',
		'undergrowth_stage',
		'undergrowth_stage_log',
		'undergrowth_stage_pinned',
	);
}

/**
 * Keeps the plugin's post meta out of the Custom Fields box, where it could
 * be edited by hand.
 *
 * @param bool   $is_protected Whether the key is protected so far.
 * @param string $meta_key     Meta key.
 * @return bool True for the plugin's keys, otherwise unchanged.
 */
function undergrowth_protect_meta( $is_protected, $meta_key ) {
	return in_array( $meta_key, undergrowth_protected_meta_keys(), true ) ? true : (bool) $is_protected;
}

/**
 * Returns the name of the vectors table for the current site.
 *
 * @return string Table name with the site's prefix.
 */
function undergrowth_vectors_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'undergrowth_vectors';
}

/**
 * Returns the name of the links table for the current site.
 *
 * @return string Table name with the site's prefix.
 */
function undergrowth_links_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'undergrowth_links';
}

/**
 * Creates or updates the plugin's tables and records the schema version.
 *
 * Provider, model and language sit beside every vector because vectors from
 * different models or languages must never be compared. The links table keeps
 * one row per source and target pair, so a veto is remembered for good.
 *
 * @return bool True when every table exists afterwards.
 */
function undergrowth_install_schema(): bool {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$vectors         = undergrowth_vectors_table();
	$links           = undergrowth_links_table();
	$charset_collate = $wpdb->get_charset_collate();

	dbDelta(
		array(
			"CREATE TABLE {$vectors} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			provider varchar(100) NOT NULL,
			model varchar(191) NOT NULL,
			language varchar(20) NOT NULL,
			dimensions int(10) unsigned NOT NULL,
			embedding longtext NOT NULL,
			content_hash char(64) NOT NULL,
			embedded_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id)
			) {$charset_collate};",
			"CREATE TABLE {$links} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_id bigint(20) unsigned NOT NULL,
			target_id bigint(20) unsigned NOT NULL,
			state varchar(20) NOT NULL,
			score double NOT NULL,
			sentence text NOT NULL,
			sentence_index int(11) NOT NULL DEFAULT -1,
			anchor varchar(255) NOT NULL DEFAULT '',
			anchor_offset int(11) NOT NULL DEFAULT -1,
			anchor_chosen tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY pair (source_id,target_id),
			KEY target_id (target_id)
			) {$charset_collate};",
		)
	);

	// dbDelta() reports no errors, so check the result. Recording the version
	// after a failed CREATE would stop every later load from retrying it.
	foreach ( array( $vectors, $links ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking the plugin's own table exists.
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return false;
		}
	}

	update_option( 'undergrowth_schema_version', UNDERGROWTH_SCHEMA_VERSION, false );

	return true;
}

/**
 * Sets up the plugin on activation, on every site when it is activated
 * across a network.
 *
 * Each site keeps its own tables and cron queue, and network activation
 * runs this once, on the main site. Activation hooks expect no return
 * value, so this wraps the installer rather than registering it directly.
 *
 * @param bool $network_wide Whether the plugin is activated across the network.
 */
function undergrowth_activate( $network_wide = false ): void {
	if ( ! $network_wide || ! is_multisite() ) {
		undergrowth_activate_site();
		return;
	}

	// get_sites() stops at 100 sites unless told otherwise, and reads every
	// network unless given one. The plugin is only active on this network.
	$site_ids = get_sites(
		array(
			'fields'     => 'ids',
			'number'     => 0,
			'network_id' => get_current_network_id(),
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		undergrowth_activate_site();
		restore_current_blog();
	}
}

/**
 * Installs the schema on the current site, schedules the monthly cycle and
 * the hourly backfill check, starts a backfill when posts are waiting to be
 * read, and queues the first-run notice.
 */
function undergrowth_activate_site(): void {
	undergrowth_install_schema();
	undergrowth_schedule_stage_cycle();
	undergrowth_schedule_backfill_check();
	undergrowth_start_backfill();
	undergrowth_queue_first_run();
}

/**
 * Sets up a new site of a network the plugin is active across.
 *
 * Runs after core has created the site's own tables.
 *
 * @param WP_Site $site The new site.
 */
function undergrowth_initialize_site( WP_Site $site ): void {
	// The new site's network, which need not be the one handling the request.
	$active = (array) get_network_option( $site->network_id, 'active_sitewide_plugins', array() );

	if ( ! isset( $active[ plugin_basename( UNDERGROWTH_FILE ) ] ) ) {
		return;
	}

	switch_to_blog( $site->id );
	undergrowth_activate_site();
	restore_current_blog();
}

/**
 * Installs the schema when the stored version is behind the code.
 *
 * Activation hooks do not run on plugin updates, so the version check
 * catches sites that updated without reactivating.
 */
function undergrowth_maybe_upgrade_schema(): void {
	if ( UNDERGROWTH_SCHEMA_VERSION !== (int) get_option( 'undergrowth_schema_version' ) ) {
		undergrowth_install_schema();
	}
}

/**
 * Stops every scheduled event while the plugin is inactive, on every site
 * when it is deactivated across a network.
 *
 * Each site keeps its own cron queue, and network deactivation runs this
 * once, on the main site.
 *
 * @param bool $network_wide Whether the plugin is deactivated across the network.
 */
function undergrowth_deactivate( $network_wide = false ): void {
	if ( ! $network_wide || ! is_multisite() ) {
		undergrowth_deactivate_site();
		return;
	}

	// get_sites() stops at 100 sites unless told otherwise, and reads every
	// network unless given one. The plugin stays active on other networks.
	$site_ids = get_sites(
		array(
			'fields'     => 'ids',
			'number'     => 0,
			'network_id' => get_current_network_id(),
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		undergrowth_deactivate_site();
		restore_current_blog();
	}
}

/**
 * Stops the monthly cycles, the prompt events and every queued embed or
 * Discord post on the current site, and forgets the clusters and any
 * clustering pass that was under way.
 */
function undergrowth_deactivate_site(): void {
	undergrowth_unschedule_prompts();
	wp_unschedule_hook( 'undergrowth_cluster_cycle' );
	wp_unschedule_hook( 'undergrowth_stage_cycle' );
	wp_unschedule_hook( 'undergrowth_resurfacing_cycle' );
	wp_unschedule_hook( 'undergrowth_backfill' );
	wp_unschedule_hook( 'undergrowth_backfill_check' );
	wp_unschedule_hook( 'undergrowth_embed_post' );
	// A suggestion left unposted is posted with the post's next refresh.
	wp_unschedule_hook( 'undergrowth_discord_post_proposals' );
	// A dev tools job left half done would carry on when reactivated.
	wp_unschedule_hook( 'undergrowth_dev_job' );
	delete_option( 'undergrowth_dev_job' );
	delete_option( 'undergrowth_backfill' );
	delete_option( 'undergrowth_backfill.lock' );
	delete_option( 'undergrowth_backfill_progress' );
	delete_option( 'undergrowth_cluster_leaders' );
	// Clusters, and the record of the last pass to compare new ones with,
	// would be stale by the time the plugin is active again.
	delete_post_meta_by_key( 'undergrowth_cluster' );
	delete_option( 'undergrowth_previous_clusters' );
	undergrowth_forget_resurfacing_signals();
}

/**
 * Removes everything the plugin stored for the current site.
 */
function undergrowth_uninstall_site(): void {
	global $wpdb;

	// The API keys go first, so a failure further down never leaves one behind.
	delete_option( 'undergrowth_openai_api_key' );
	delete_option( 'undergrowth_anthropic_api_key' );
	delete_option( 'undergrowth_discord_bot_token' );
	delete_option( 'undergrowth_discord_public_key' );
	delete_option( 'undergrowth_discord_application_id' );
	delete_option( 'undergrowth_discord_channel_id' );
	delete_option( 'undergrowth_discord_since' );
	delete_option( 'undergrowth_last_discord_error' );
	wp_unschedule_hook( 'undergrowth_discord_post_proposals' );
	delete_post_meta_by_key( 'undergrowth_discord_messages' );
	delete_metadata( 'user', 0, 'undergrowth_discord_user_id', '', true );
	delete_metadata( 'user', 0, 'undergrowth_scan_until', '', true );
	delete_option( 'undergrowth_last_embed_error' );
	delete_option( 'undergrowth_embed_retry_at' );
	delete_option( 'undergrowth_last_prompt_error' );
	delete_option( 'undergrowth_prompt' );
	delete_option( 'undergrowth_config_prompt_queued' );
	delete_option( 'undergrowth_snoozed_prompts' );
	delete_option( 'undergrowth_last_file_error' );
	delete_option( 'undergrowth_suggest_links' );
	delete_option( 'undergrowth_first_run' );
	delete_option( 'undergrowth_schema_version' );
	delete_transient( 'undergrowth_meter' );
	wp_unschedule_hook( 'undergrowth_embed_post' );
	wp_unschedule_hook( 'undergrowth_backfill' );
	wp_unschedule_hook( 'undergrowth_backfill_check' );
	delete_option( 'undergrowth_backfill' );
	delete_option( 'undergrowth_backfill.lock' );
	delete_option( 'undergrowth_backfill_progress' );
	delete_option( 'undergrowth_index_identity' );
	delete_option( 'undergrowth_cluster_leaders' );
	delete_option( 'undergrowth_cluster_labels' );
	delete_option( 'undergrowth_scores' );
	delete_option( 'undergrowth_dev_job' );
	wp_unschedule_hook( 'undergrowth_dev_job' );
	wp_unschedule_hook( 'undergrowth_cluster_cycle' );
	wp_unschedule_hook( 'undergrowth_stage_cycle' );
	wp_unschedule_hook( 'undergrowth_resurfacing_cycle' );
	delete_option( 'undergrowth_previous_clusters' );
	delete_option( 'undergrowth_new_clusters' );
	wp_unschedule_hook( 'undergrowth_prompt_cycle' );
	wp_unschedule_hook( 'undergrowth_write_prompt' );
	delete_post_meta_by_key( 'undergrowth_cluster' );
	delete_post_meta_by_key( 'undergrowth_stage' );
	delete_post_meta_by_key( 'undergrowth_stage_pinned' );
	delete_post_meta_by_key( 'undergrowth_stage_log' );
	delete_post_meta_by_key( 'undergrowth_resurfacing' );
	delete_post_meta_by_key( 'undergrowth_resurfacing_log' );
	delete_post_meta_by_key( 'undergrowth_prompt_keys' );
	delete_post_meta_by_key( 'undergrowth_pending_links' );
	delete_post_meta_by_key( 'undergrowth_prompt_log' );
	undergrowth_delete_memory_folder();
	delete_option( 'undergrowth_memory_folder' );
	delete_transient( 'undergrowth_old_folder_retry' );

	// A stage lock is released as soon as the stage is written, so one is
	// left only by a request that died while holding it.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock names include each post's ID, so there is no list to delete by name.
	$locks = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'undergrowth_stage_' ) . '%' . $wpdb->esc_like( '.lock' ) ) );

	foreach ( $locks as $lock ) {
		delete_option( $lock );
	}

	foreach ( array( undergrowth_vectors_table(), undergrowth_links_table() ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own tables on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}
}

/**
 * Removes everything the plugin stored, on every site of a network.
 */
function undergrowth_uninstall(): void {
	if ( ! is_multisite() ) {
		undergrowth_uninstall_site();
		return;
	}

	// get_sites() stops at 100 sites unless told otherwise.
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		undergrowth_uninstall_site();
		restore_current_blog();
	}
}
