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
add_action( 'undergrowth_stage_changed', 'undergrowth_update_memory' );
// How distinctive a post's words are depends on every other post, so each
// file is rewritten once a month even when nothing of its own changed.
add_action( 'undergrowth_stage_cycled', 'undergrowth_update_memory' );
add_action( 'undergrowth_resurfacing_logged', 'undergrowth_update_memory' );
add_action( 'undergrowth_prompt_logged', 'undergrowth_update_memory' );
add_action( 'post_updated', 'undergrowth_update_memory_around' );
add_action( 'undergrowth_post_left_index', 'undergrowth_update_memory_around' );
// After the post cache is cleared, so a deleted post no longer looks published.
add_action( 'after_delete_post', 'undergrowth_update_memory_around' );
add_action( 'init', 'undergrowth_prepare_memory_folder' );

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

	if ( false !== $folder ) {
		delete_option( 'undergrowth_memory_folder' );
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
 */
function undergrowth_prepare_memory_folder(): void {
	if ( ! undergrowth_is_memory_folder( get_option( 'undergrowth_memory_folder' ) ) ) {
		undergrowth_memory_folder();
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
	if ( ! file_exists( $root ) && @rename( $legacy, $root ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
		return;
	}

	$filesystem = undergrowth_filesystem();

	if ( null === $filesystem || ! $filesystem->rmdir( $legacy, true ) ) {
		undergrowth_record_file_error( 'old_folder_left' );
	}
}

/**
 * Returns the path of a post's file.
 *
 * @param int $post_id Post ID.
 * @return string Absolute path, or an empty string when the folder has no stored name.
 */
function undergrowth_memory_path( int $post_id ): string {
	$root = undergrowth_memory_root();

	return '' === $root ? '' : $root . '/posts/' . $post_id . '.txt';
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
 * Builds the text of a post's file from its stage and the links table.
 *
 * Only the post's title, address and slug, words and sentences from the
 * post, other posts' addresses, and the questions Undergrowth asked the
 * author about it are written. Has and lacks come from the same functions
 * as the abilities, so the file and an agent always read the same answer.
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
		$line  = '- ' . undergrowth_memory_target( (int) $row['target_id'] );

		if ( 'vetoed' !== $state ) {
			$line .= ' | "' . $row['sentence'] . '"';
		}

		$links[ $state ][] = $line;
	}

	$lines   = array_merge(
		array(
			'# ' . $post->post_title,
			'url: ' . get_permalink( $post ),
			'slug: ' . $post->post_name,
			'updated: ' . wp_date( 'c', null, new DateTimeZone( 'UTC' ) ),
			'',
			'## stage',
		),
		undergrowth_memory_stage( $post->ID ),
		array( '', '## resurfacing' ),
		undergrowth_memory_resurfacing( $post->ID ),
		array( '', '## has' ),
		undergrowth_memory_has( $post ),
		array( '', '## lacks' ),
		undergrowth_memory_lacks( $post ),
		array( '', '## scent', '', '## proposed links' ),
		$links['proposed'],
		array( '', '## accepted links' ),
		$links['accepted'],
		array( '', '## vetoes' ),
		$links['vetoed'],
		array( '', '## prompts' ),
		undergrowth_memory_prompts( $post->ID )
	);
	$lines[] = '';

	return implode( "\n", $lines );
}

/**
 * Builds the stage section of a post's file: the current stage, then one
 * line for each recorded change, oldest first.
 *
 * Composting is never pinned, so it never carries the marker.
 *
 * @param int $post_id Post ID.
 * @return string[] Lines of the section, without its heading.
 */
function undergrowth_memory_stage( int $post_id ): array {
	$stage = undergrowth_get_stage( $post_id );
	$lines = array( 'composting' !== $stage && undergrowth_is_stage_pinned( $post_id ) ? $stage . ' (pinned)' : $stage );

	foreach ( undergrowth_stage_log( $post_id ) as $change ) {
		$reason  = UNDERGROWTH_STAGE_REASONS[ $change['reason'] ];
		$lines[] = '- ' . wp_date( 'Y-m-d', $change['time'], new DateTimeZone( 'UTC' ) ) . ': ' . $change['from'] . ' -> ' . $change['to'] . ' (' . $reason . ')';
	}

	return $lines;
}

/**
 * Builds the has section of a post's file: its most distinctive words on one
 * line, then the address of each published post it links to.
 *
 * @param WP_Post $post Post the file describes.
 * @return string[] Lines of the section, without its heading.
 */
function undergrowth_memory_has( WP_Post $post ): array {
	$has   = undergrowth_ability_has( $post );
	$lines = array() === $has['words'] ? array() : array( 'words: ' . implode( ', ', $has['words'] ) );

	foreach ( $has['links'] as $link ) {
		$lines[] = '- ' . $link['url'];
	}

	return $lines;
}

/**
 * Builds the lacks section of a post's file: each open suggestion, best
 * first, with the sentence from the post where it fits.
 *
 * @param WP_Post $post Post the file describes.
 * @return string[] Lines of the section, without its heading.
 */
function undergrowth_memory_lacks( WP_Post $post ): array {
	$lines = array();

	foreach ( undergrowth_ability_lacks( $post )['links'] as $link ) {
		$lines[] = '- ' . $link['url'] . ' | "' . $link['sentence'] . '"';
	}

	return $lines;
}

/**
 * Builds the resurfacing section of a post's file: one line for each signal
 * shown or dismissed, oldest first.
 *
 * @param int $post_id Post ID.
 * @return string[] Lines of the section, without its heading.
 */
function undergrowth_memory_resurfacing( int $post_id ): array {
	$lines = array();

	foreach ( undergrowth_resurfacing_log( $post_id ) as $entry ) {
		$lines[] = '- ' . wp_date( 'Y-m-d', $entry['time'], new DateTimeZone( 'UTC' ) ) . ': ' . UNDERGROWTH_RESURFACING_EVENTS[ $entry['event'] ];
	}

	return $lines;
}

/**
 * Builds the prompts section of a post's file: one line for each prompt
 * asked, dismissed or snoozed, oldest first.
 *
 * @param int $post_id Post ID.
 * @return string[] Lines of the section, without its heading.
 */
function undergrowth_memory_prompts( int $post_id ): array {
	$lines = array();

	foreach ( undergrowth_prompt_log( $post_id ) as $event ) {
		$lines[] = '- ' . wp_date( 'Y-m-d', $event['time'], new DateTimeZone( 'UTC' ) ) . ': ' . $event['event'] . ' (' . $event['source'] . ') "' . $event['question'] . '"';
	}

	return $lines;
}

/**
 * Names a link target in a file.
 *
 * Accepted links and vetoes are kept after their target leaves the index,
 * so the file still records the decision. Only a target that is in the index
 * is named by its address; an unpublished post's address is never written.
 *
 * @param int $target_id Target post ID.
 * @return string The address, or a placeholder naming the post ID.
 */
function undergrowth_memory_target( int $target_id ): string {
	$target = get_post( $target_id );

	if ( ! $target instanceof WP_Post ) {
		return 'post ' . $target_id . ' (deleted)';
	}

	if ( ! undergrowth_is_indexable( $target ) ) {
		return 'post ' . $target_id . ' (not published)';
	}

	return (string) get_permalink( $target );
}

/**
 * Writes a post's file, or removes it when the post is not in the index.
 *
 * A post without a vector has nothing else to record, unless Undergrowth
 * has asked the author about it, which needs no embedding provider.
 *
 * @param int $post_id Post ID.
 * @return bool True when the file was written.
 */
function undergrowth_write_memory( int $post_id ): bool {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || ! undergrowth_is_indexable( $post ) || ( null === undergrowth_get_vector( $post_id ) && array() === undergrowth_prompt_log( $post_id ) ) ) {
		undergrowth_delete_memory( $post_id );
		return false;
	}

	$filesystem = undergrowth_filesystem();

	if ( null === $filesystem ) {
		undergrowth_record_file_error( 'no_direct_access' );
		return false;
	}

	$root = undergrowth_memory_root();

	if ( '' === $root || ! wp_mkdir_p( $root . '/posts' ) ) {
		undergrowth_record_file_error( 'no_folder' );
		return false;
	}

	// The files hold no secrets, but they are the owner's notes, not pages
	// for visitors. nginx ignores .htaccess; the readme gives its equivalent.
	// Without its protection files, the folder is never written to at all.
	$protection = array(
		'index.php'       => "<?php\n// Silence is golden.\n",
		'posts/index.php' => "<?php\n// Silence is golden.\n",
		'.htaccess'       => "Require all denied\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
	);

	foreach ( $protection as $name => $contents ) {
		if ( ! undergrowth_write_protection_file( $filesystem, $root . '/' . $name, $contents ) ) {
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
 * Rewrites a post's file and the files of posts that link to it, after the
 * post itself changes.
 *
 * A new slug changes the post's address without changing its text, so the
 * post is not re-embedded and nothing else would rewrite these files.
 *
 * @param int $post_id Post that was updated.
 */
function undergrowth_update_memory_around( int $post_id ): void {
	global $wpdb;

	undergrowth_write_memory( $post_id );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every refresh.
	$sources = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT DISTINCT source_id FROM %i WHERE target_id = %d',
			undergrowth_links_table(),
			$post_id
		)
	);

	foreach ( array_map( 'intval', $sources ) as $source_id ) {
		undergrowth_write_memory( $source_id );
	}
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

	if ( '' !== $path && file_exists( $path ) ) {
		wp_delete_file( $path );
	}
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
		if ( $was_pinned ) {
			update_post_meta( $post_id, 'undergrowth_stage_pinned', '1' );
		} else {
			delete_post_meta( $post_id, 'undergrowth_stage_pinned' );
		}

		if ( undergrowth_is_stage_pinned( $post_id ) !== $was_pinned ) {
			return new WP_Error( 'undergrowth_pin_lost', __( 'The stage could not be saved, and the pin marker could not be put back. Pin the stage again to keep it.', 'undergrowth' ) );
		}

		return undergrowth_stage_not_saved();
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
		$day = wp_date( 'Y-m-d', $row['time'], new DateTimeZone( 'UTC' ) );
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
