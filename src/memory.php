<?php
/**
 * The readable per-post file: a plain-text record the owner can read and
 * carry to another site.
 *
 * The links table is the record; each file is written from it.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'undergrowth_proposals_refreshed', 'undergrowth_update_memory' );
add_action( 'undergrowth_post_left_index', 'undergrowth_delete_memory' );
add_action( 'deleted_post', 'undergrowth_delete_memory' );

/**
 * Returns the folder that holds the plugin's files for the current site.
 *
 * @return string Absolute path, without a trailing slash.
 */
function undergrowth_memory_root(): string {
	$uploads = wp_upload_dir( null, false );

	return $uploads['basedir'] . '/undergrowth';
}

/**
 * Returns the path of a post's file.
 *
 * @param int $post_id Post ID.
 * @return string Absolute path.
 */
function undergrowth_memory_path( int $post_id ): string {
	return undergrowth_memory_root() . '/posts/' . $post_id . '.txt';
}

/**
 * Returns the filesystem when it can write without asking for credentials.
 *
 * Files are written from cron, where nobody is present to enter FTP details,
 * so only direct access is used.
 *
 * @return WP_Filesystem_Base|null The filesystem, or null when direct access is unavailable.
 */
function undergrowth_filesystem(): ?WP_Filesystem_Base {
	global $wp_filesystem;

	require_once ABSPATH . 'wp-admin/includes/file.php';

	$uploads = wp_upload_dir( null, false );

	if ( 'direct' !== get_filesystem_method( array(), $uploads['basedir'] ) || ! WP_Filesystem() ) {
		return null;
	}

	return $wp_filesystem instanceof WP_Filesystem_Base ? $wp_filesystem : null;
}

/**
 * Builds the text of a post's file from the links table.
 *
 * Only the post's title, address and slug, other posts' addresses, and
 * sentences already quoted from the post are written.
 *
 * @param WP_Post $post Post the file describes.
 * @return string File contents.
 */
function undergrowth_memory_text( WP_Post $post ): string {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every refresh.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT target_id, state, sentence FROM %i WHERE source_id = %d ORDER BY score DESC, target_id ASC',
			undergrowth_links_table(),
			$post->ID
		),
		ARRAY_A
	);

	$links = array(
		'proposed' => array(),
		'accepted' => array(),
		'vetoed'   => array(),
	);

	foreach ( (array) $rows as $row ) {
		$state = (string) $row['state'];
		$url   = get_permalink( (int) $row['target_id'] );
		$line  = '- ' . ( false !== $url ? $url : 'post ' . (int) $row['target_id'] . ' (deleted)' );

		if ( 'vetoed' !== $state ) {
			$line .= ' | "' . $row['sentence'] . '"';
		}

		$links[ $state ][] = $line;
	}

	$lines = array(
		'# ' . $post->post_title,
		'url: ' . get_permalink( $post ),
		'slug: ' . $post->post_name,
		'updated: ' . wp_date( 'c', null, new DateTimeZone( 'UTC' ) ),
		'',
		'## stage',
		'',
		'## has',
		'',
		'## lacks',
		'',
		'## scent',
		'',
		'## proposed links',
	);

	$lines   = array_merge( $lines, $links['proposed'], array( '', '## accepted links' ), $links['accepted'], array( '', '## vetoes' ), $links['vetoed'] );
	$lines[] = '';

	return implode( "\n", $lines );
}

/**
 * Writes a post's file, or removes it when the post is not in the index.
 *
 * @param int $post_id Post ID.
 * @return bool True when the file was written.
 */
function undergrowth_write_memory( int $post_id ): bool {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || ! undergrowth_is_indexable( $post ) || null === undergrowth_get_vector( $post_id ) ) {
		undergrowth_delete_memory( $post_id );
		return false;
	}

	$filesystem = undergrowth_filesystem();

	if ( null === $filesystem ) {
		undergrowth_record_file_error( 'no_direct_access' );
		return false;
	}

	$root = undergrowth_memory_root();

	if ( ! wp_mkdir_p( $root . '/posts' ) ) {
		undergrowth_record_file_error( 'no_folder' );
		return false;
	}

	// The files hold no secrets, but they are the owner's notes, not pages
	// for visitors. nginx ignores .htaccess; the readme gives its equivalent.
	// Without its protection files, the folder is never written to at all.
	$protection = array(
		'index.php' => "<?php\n// Silence is golden.\n",
		'.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
	);

	foreach ( $protection as $name => $contents ) {
		$path = $root . '/' . $name;

		if ( ! $filesystem->is_file( $path ) && ! $filesystem->put_contents( $path, $contents, FS_CHMOD_FILE ) ) {
			undergrowth_record_file_error( 'no_protection' );
			return false;
		}
	}

	if ( ! $filesystem->put_contents( undergrowth_memory_path( $post_id ), undergrowth_memory_text( $post ), FS_CHMOD_FILE ) ) {
		undergrowth_record_file_error( 'write_failed' );
		return false;
	}

	delete_option( 'undergrowth_last_file_error' );

	return true;
}

/**
 * Rewrites a post's file after its proposals change.
 *
 * Actions expect no return value, so this wraps the writer.
 *
 * @param int $post_id Post ID.
 */
function undergrowth_update_memory( int $post_id ): void {
	undergrowth_write_memory( $post_id );
}

/**
 * Removes a post's file, if it has one.
 *
 * @param int $post_id Post ID.
 */
function undergrowth_delete_memory( int $post_id ): void {
	$path = undergrowth_memory_path( $post_id );

	if ( file_exists( $path ) ) {
		wp_delete_file( $path );
	}
}

/**
 * Removes the plugin's folder and every file in it, for the current site.
 */
function undergrowth_delete_memory_folder(): void {
	$filesystem = undergrowth_filesystem();
	$root       = undergrowth_memory_root();

	if ( null !== $filesystem && $filesystem->is_dir( $root ) ) {
		$filesystem->rmdir( $root, true );
	}
}

/**
 * Records why the last file could not be written.
 *
 * @param string $code Short error code.
 */
function undergrowth_record_file_error( string $code ): void {
	update_option(
		'undergrowth_last_file_error',
		array(
			'code' => $code,
			'time' => time(),
		),
		false
	);
}
