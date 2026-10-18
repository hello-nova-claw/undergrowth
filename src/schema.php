<?php
/**
 * The vectors table: install, upgrade and uninstall.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_SCHEMA_VERSION = 1;

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
 * Creates or updates the vectors table and records the schema version.
 *
 * Provider, model and language sit beside every vector because vectors from
 * different models or languages must never be compared.
 *
 * @return bool True when the table exists afterwards.
 */
function undergrowth_install_schema(): bool {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = undergrowth_vectors_table();
	$charset_collate = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$table} (
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
		) {$charset_collate};"
	);

	// dbDelta() reports no errors, so check the result. Recording the version
	// after a failed CREATE would stop every later load from retrying it.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking the plugin's own table exists.
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
		return false;
	}

	update_option( 'undergrowth_schema_version', UNDERGROWTH_SCHEMA_VERSION, false );

	return true;
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

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own table on uninstall.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', undergrowth_vectors_table() ) );

	delete_option( 'undergrowth_schema_version' );
	wp_unschedule_hook( 'undergrowth_embed_post' );
}

/**
 * Removes everything the plugin stored, on every site of a network.
 */
function undergrowth_uninstall(): void {
	if ( ! is_multisite() ) {
		undergrowth_uninstall_site();
		return;
	}

	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
		switch_to_blog( (int) $site_id );
		undergrowth_uninstall_site();
		restore_current_blog();
	}
}
