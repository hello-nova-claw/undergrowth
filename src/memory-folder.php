<?php
/**
 * The folder that holds the per-post files: its unguessable name, moving
 * the old folder, and keeping it closed to the web.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'undergrowth_prepare_memory_folder' );
add_action( 'undergrowth_backfill_check', 'undergrowth_recheck_memory_folder' );
add_action( 'undergrowth_memory_folder_check', 'undergrowth_recheck_memory_folder' );

/**
 * Returns the folder that holds the plugin's files for the current site.
 *
 * @return string Absolute path, without a trailing slash, or an empty string
 *                when the folder has no stored name and so must not be used.
 */
function undergrowth_memory_root(): string {
	$folder = undergrowth_memory_folder();

	if ( '' === $folder ) {
		return '';
	}

	$uploads = wp_upload_dir( null, false );

	return $uploads['basedir'] . '/' . $folder;
}

/**
 * Tells whether a value is a folder name this plugin makes.
 *
 * @param mixed $folder Value to check.
 * @return bool True for undergrowth- and 12 hexadecimal characters.
 */
function undergrowth_is_memory_folder( $folder ): bool {
	return is_string( $folder ) && 1 === preg_match( '/^undergrowth-[0-9a-f]{12}$/D', $folder );
}

/**
 * Returns the name of the site's files folder, making it the first time.
 *
 * The name ends in random characters, so the files cannot be found at a
 * guessable address on a server that ignores .htaccess. Only a name that is
 * stored is ever used, so no file can land in a folder the plugin would
 * later lose track of. Folders from before the random name are moved only
 * by the request whose name was stored.
 *
 * @return string Folder name, such as undergrowth-0123456789ab, or an empty
 *                string when no name could be stored.
 */
function undergrowth_memory_folder(): string {
	$folder = get_option( 'undergrowth_memory_folder' );

	if ( undergrowth_is_memory_folder( $folder ) ) {
		return $folder;
	}

	// A value that is not one of ours is removed only while it is still
	// that value, so a good name another request stored meanwhile stays.
	if ( false !== $folder ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- delete_option() deletes by name alone.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, 'undergrowth_memory_folder', maybe_serialize( $folder ) ) );
		wp_cache_delete( 'alloptions', 'options' );
	}

	try {
		$folder = 'undergrowth-' . bin2hex( random_bytes( 6 ) );
	} catch ( Exception $e ) {
		$folder = 'undergrowth-' . substr( hash( 'sha256', wp_generate_password( 64, true, true ) ), 0, 12 );
	}

	$stored = undergrowth_claim_memory_folder( $folder );

	if ( $stored === $folder ) {
		$uploads = wp_upload_dir( null, false );
		undergrowth_move_legacy_memory_folder( $uploads['basedir'] . '/' . $folder );
	}

	return $stored;
}

/**
 * Stores a folder name unless another request stored one first, and
 * returns whichever name is stored.
 *
 * Calling add_option() would overwrite a row added since it last looked,
 * so two first requests could each think their name was kept. INSERT
 * IGNORE lets only one name in, and the row is read back from the
 * database, not the cache.
 *
 * @param string $folder Name this request would like to use.
 * @return string The stored name, or an empty string when none is stored.
 */
function undergrowth_claim_memory_folder( string $folder ): string {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- One row, once per site; add_option() cannot refuse a second writer.
	$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, 'undergrowth_memory_folder', $folder, 'on' ) );

	$notoptions = wp_cache_get( 'notoptions', 'options' );

	if ( is_array( $notoptions ) && isset( $notoptions['undergrowth_memory_folder'] ) ) {
		unset( $notoptions['undergrowth_memory_folder'] );
		wp_cache_set( 'notoptions', $notoptions, 'options' );
	}

	wp_cache_delete( 'undergrowth_memory_folder', 'options' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must see the row as stored, past any cache.
	$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'undergrowth_memory_folder' ) );

	return undergrowth_is_memory_folder( $stored ) ? (string) $stored : '';
}

/**
 * Makes the site's files folder name, and so moves any old folder, on the
 * first request after an update.
 *
 * An old folder that could not be moved or removed then is tried again,
 * at most once an hour, until it is gone.
 */
function undergrowth_prepare_memory_folder(): void {
	if ( ! undergrowth_is_memory_folder( get_option( 'undergrowth_memory_folder' ) ) ) {
		undergrowth_memory_folder();
		return;
	}

	$uploads = wp_upload_dir( null, false );

	if ( is_dir( $uploads['basedir'] . '/undergrowth' ) && false === get_transient( 'undergrowth_old_folder_retry' ) ) {
		undergrowth_move_legacy_memory_folder( undergrowth_memory_root() );
	}
}

/**
 * Moves the files folder from its old, guessable address to its new one.
 *
 * Rename is a single step, so the files are never in both places. When it
 * cannot be done, the old folder is removed instead: every file is written
 * again from the database, at the latest on the monthly cycle, so nothing
 * is lost that cannot be rebuilt.
 *
 * @param string $root New folder, absolute path.
 */
function undergrowth_move_legacy_memory_folder( string $root ): void {
	$uploads = wp_upload_dir( null, false );
	$legacy  = $uploads['basedir'] . '/undergrowth';

	if ( $legacy === $root || ! is_dir( $legacy ) || is_link( $legacy ) ) {
		return;
	}

	// One atomic step, where WP_Filesystem::move() may copy and delete. A
	// failed rename falls back below, so its warning is silenced.
	$moved      = ! file_exists( $root ) && @rename( $legacy, $root ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
	$filesystem = undergrowth_filesystem();

	// A folder from before posts/ had its own index file gets one now, not
	// only at the next write.
	if ( $moved ) {
		if ( null !== $filesystem && $filesystem->is_dir( $root . '/posts' ) ) {
			undergrowth_write_protection_file( $filesystem, $root . '/posts/index.php', UNDERGROWTH_MEMORY_INDEX );
		}
		return;
	}

	if ( null === $filesystem || ! $filesystem->rmdir( $legacy, true ) ) {
		// A folder that cannot be removed now is tried again within the hour,
		// not on every request, and the same error is not written again.
		set_transient( 'undergrowth_old_folder_retry', 1, HOUR_IN_SECONDS );
		$last = get_option( 'undergrowth_last_file_error' );

		if ( ! is_array( $last ) || 'old_folder_left' !== ( $last['code'] ?? '' ) ) {
			undergrowth_record_failure( 'undergrowth_last_file_error', 'old_folder_left' );
		}
	}
}

/**
 * Tells whether the last check found the per-post folder readable from the web.
 *
 * @return bool True while visitors could read the files.
 */
function undergrowth_memory_exposed(): bool {
	$check = get_option( 'undergrowth_memory_exposed' );

	return is_array( $check ) && true === ( $check['exposed'] ?? null );
}

/**
 * Tells whether the last check found that visitors are refused the per-post
 * folder.
 *
 * @return bool True only when a check has answered that they are.
 */
function undergrowth_memory_private(): bool {
	$check = get_option( 'undergrowth_memory_exposed' );

	return is_array( $check ) && false === ( $check['exposed'] ?? null );
}

/**
 * Checks whether visitors can read the per-post files, by writing a file
 * into the folder and asking for it at the site's own address.
 *
 * Each answer is kept until the next check, including one that proves
 * nothing. While the folder is not known to be refused, the questions are
 * taken out of the files already there, so a file that could not be
 * rewritten last time is tried again.
 *
 * @return bool|null True when the file could be read, false when it was
 *                   refused, or null when there is no folder yet or the
 *                   answer proves neither.
 */
function undergrowth_check_memory_folder(): ?bool {
	$root       = undergrowth_memory_root();
	$filesystem = undergrowth_filesystem();

	if ( '' === $root || null === $filesystem || ! $filesystem->is_dir( $root . '/posts' ) ) {
		return null;
	}

	$exposed = undergrowth_ask_for_memory_file( $filesystem, $root );

	// An answer that proves nothing replaces the last one too: a folder found
	// refused once may not be refused now.
	update_option(
		'undergrowth_memory_exposed',
		array(
			'exposed' => $exposed,
			'time'    => time(),
		),
		false
	);

	// An answer that could not be stored must not leave an old refusal in
	// place, so the folder then counts as never checked.
	if ( false !== $exposed && undergrowth_memory_private() ) {
		delete_option( 'undergrowth_memory_exposed' );
	}

	// The fresh answer decides, in case the stored one could not be replaced.
	if ( false !== $exposed ) {
		undergrowth_strip_memory_prompts( $filesystem, $root . '/posts' );
	}

	return $exposed;
}

/**
 * Asks for a file in the per-post folder at the site's own address, beside
 * one in the uploads folder that visitors may read.
 *
 * The second file shows the request reaches the uploads at all: a site
 * behind a password, or a loopback the host turns away, would refuse both,
 * which says nothing about the folder.
 *
 * @param WP_Filesystem_Base $filesystem Filesystem with direct access.
 * @param string             $root       The plugin's folder.
 * @return bool|null True when the folder's file came back, false when only it
 *                   was refused, or null when the answers prove neither.
 */
function undergrowth_ask_for_memory_file( WP_Filesystem_Base $filesystem, string $root ): ?bool {
	$uploads = wp_upload_dir( null, false );

	// Uploads served from another host, by an offload plugin say, would never
	// hold these files, so their absence there would prove nothing.
	if ( wp_parse_url( $uploads['baseurl'], PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		return null;
	}

	$control = undergrowth_fetch_check_file( $filesystem, $uploads['basedir'], $uploads['baseurl'] );

	if ( true !== $control ) {
		return null;
	}

	return undergrowth_fetch_check_file( $filesystem, $root . '/posts', $uploads['baseurl'] . '/' . undergrowth_memory_folder() . '/posts' );
}

/**
 * Writes a file with a body no other page holds, asks for it, and deletes it.
 *
 * @param WP_Filesystem_Base $filesystem Filesystem with direct access.
 * @param string             $folder     Folder to write it in.
 * @param string             $url        Address of that folder.
 * @return bool|null True when the file came back, false when it was refused,
 *                   or null when the answer proves neither.
 */
function undergrowth_fetch_check_file( WP_Filesystem_Base $filesystem, string $folder, string $url ): ?bool {
	// A name that is never a post's.
	$name  = 'undergrowth-check-' . strtolower( wp_generate_password( 12, false ) ) . '.txt';
	$token = wp_generate_password( 32, false );

	if ( ! $filesystem->put_contents( $folder . '/' . $name, $token, FS_CHMOD_FILE ) ) {
		return null;
	}

	$response = wp_remote_get(
		$url . '/' . $name,
		array(
			'timeout'     => 10,
			'redirection' => 0,
			/** This filter is documented in wp-includes/class-wp-http-streams.php */
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook, as core's own loopback requests use it.
		)
	);

	$filesystem->delete( $folder . '/' . $name );

	if ( is_wp_error( $response ) ) {
		return null;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 === $code ) {
		return false !== strpos( wp_remote_retrieve_body( $response ), $token ) ? true : null;
	}

	// A redirect, a password prompt or a server error says nothing about
	// what a visitor would get. Only a plain refusal does.
	return in_array( $code, array( 403, 404, 410 ), true ) ? false : null;
}

/**
 * Checks the per-post folder again, on the hourly check.
 */
function undergrowth_recheck_memory_folder(): void {
	undergrowth_check_memory_folder();
}

/**
 * Takes the prompts section out of every per-post file in a folder.
 *
 * It is the last section, and reading a file back never needs it.
 *
 * @param WP_Filesystem_Base $filesystem Filesystem with direct access.
 * @param string             $folder     Folder of per-post files.
 */
function undergrowth_strip_memory_prompts( WP_Filesystem_Base $filesystem, string $folder ): void {
	foreach ( array_keys( (array) $filesystem->dirlist( $folder, false ) ) as $name ) {
		if ( 1 !== preg_match( '/^[0-9]+\.txt$/D', (string) $name ) ) {
			continue;
		}

		$text = (string) $filesystem->get_contents( $folder . '/' . $name );
		$cut  = strpos( $text, "\n## prompts\n" );

		if ( false !== $cut ) {
			$filesystem->put_contents( $folder . '/' . $name, substr( $text, 0, $cut + 1 ), FS_CHMOD_FILE );
		}
	}
}

/**
 * Says that the per-post files can be read from the web, and what follows.
 *
 * @return string Plain text.
 */
function undergrowth_memory_exposed_text(): string {
	return __( 'Visitors can open the per-post files, because this server ignores the .htaccess and web.config files that refuse them. Add the deny rule for nginx or Caddy to the server’s settings. Until the files are refused, the questions Claude wrote are left out of them.', 'undergrowth' );
}

/**
 * Returns the rules that refuse the per-post folder on servers that ignore
 * .htaccess and web.config.
 *
 * @return array<string, string> Rule, keyed by server name.
 */
function undergrowth_memory_deny_rules(): array {
	$pattern = '^/wp-content/uploads/(sites/[0-9]+/)?undergrowth(-[0-9a-f]{12})?/';

	return array(
		'nginx' => 'location ~ "' . $pattern . '" { deny all; }',
		'Caddy' => '@undergrowth path_regexp ' . $pattern . "\nrespond @undergrowth 403",
	);
}

/**
 * Makes sure a protection file holds exactly the given contents.
 *
 * A file with other contents, such as one left half-written, is replaced.
 * The new version is written beside it and checked first, then swapped in
 * with one rename, so a failed repair always leaves the old rule in place.
 *
 * @param WP_Filesystem_Base $filesystem Filesystem with direct access.
 * @param string             $path       Protection file path.
 * @param string             $contents   Contents it must hold.
 * @return bool True when the file holds the contents afterwards.
 */
function undergrowth_write_protection_file( WP_Filesystem_Base $filesystem, string $path, string $contents ): bool {
	if ( $filesystem->is_file( $path ) && $contents === $filesystem->get_contents( $path ) ) {
		return true;
	}

	// Something other than a file in the way cannot be replaced safely.
	if ( $filesystem->is_dir( $path ) ) {
		return false;
	}

	$temporary = $path . '.tmp';

	// put_contents() fails on a short write, so a temporary file that made it
	// through holds the whole rule. A partial one is removed.
	if ( ! $filesystem->put_contents( $temporary, $contents, FS_CHMOD_FILE ) ) {
		$filesystem->delete( $temporary );
		return false;
	}

	// WP_Filesystem::move() deletes the destination before moving, which would
	// leave a moment with no rule at all. Only direct access is used here, so
	// PHP's rename() replaces the file in one step.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replacement; see above.
	return rename( $temporary, $path );
}

/**
 * Removes the plugin's folder and every file in it, for the current site,
 * and any folder left at the old address.
 *
 * A folder name is never made here, so uninstalling does not make one.
 */
function undergrowth_delete_memory_folder(): void {
	$filesystem = undergrowth_filesystem();
	$uploads    = wp_upload_dir( null, false );
	$folders    = array( $uploads['basedir'] . '/undergrowth' );
	$folder     = get_option( 'undergrowth_memory_folder' );

	if ( undergrowth_is_memory_folder( $folder ) ) {
		$folders[] = $uploads['basedir'] . '/' . $folder;
	}

	foreach ( $folders as $root ) {
		if ( null !== $filesystem && $filesystem->is_dir( $root ) ) {
			$filesystem->rmdir( $root, true );
		}
	}
}
