<?php
/**
 * The plugin's tables: install, upgrade and uninstall.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_SCHEMA_VERSION = 3;

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
 * Installs the schema and schedules the stage cycle on activation.
 *
 * Activation hooks expect no return value, so this wraps the installer
 * rather than registering it directly.
 */
function undergrowth_activate(): void {
	undergrowth_install_schema();
	undergrowth_schedule_stage_cycle();
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
	delete_option( 'undergrowth_last_embed_error' );
	delete_option( 'undergrowth_last_prompt_error' );
	delete_option( 'undergrowth_prompt' );
	delete_option( 'undergrowth_last_file_error' );
	delete_option( 'undergrowth_suggest_links' );
	delete_option( 'undergrowth_schema_version' );
	wp_unschedule_hook( 'undergrowth_embed_post' );
	delete_option( 'undergrowth_cluster_leaders' );
	wp_unschedule_hook( 'undergrowth_cluster_cycle' );
	wp_unschedule_hook( 'undergrowth_stage_cycle' );
	wp_unschedule_hook( 'undergrowth_prompt_cycle' );
	wp_unschedule_hook( 'undergrowth_write_prompt' );
	delete_post_meta_by_key( 'undergrowth_cluster' );
	delete_post_meta_by_key( 'undergrowth_stage' );
	delete_post_meta_by_key( 'undergrowth_stage_pinned' );
	delete_post_meta_by_key( 'undergrowth_stage_log' );
	delete_post_meta_by_key( 'undergrowth_prompt_keys' );
	undergrowth_delete_memory_folder();

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
