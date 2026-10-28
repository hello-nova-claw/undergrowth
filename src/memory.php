<?php
/**
 * The readable per-post file: a plain-text record the owner can read.
 *
 * The links table is the record; each file is written from it.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_MEMORY_INDEX = "<?php\n// Silence is golden.\n";

// IIS reads web.config the way Apache reads .htaccess.
const UNDERGROWTH_MEMORY_WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<security>\n\t\t\t<authorization>\n\t\t\t\t<add accessType=\"Deny\" users=\"*\" />\n\t\t\t</authorization>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n";

const UNDERGROWTH_MEMORY_DOCS = 'https://github.com/hello-nova-claw/undergrowth/blob/main/docs/per-post-files.md';

add_action( 'undergrowth_proposals_refreshed', 'undergrowth_update_memory' );
add_action( 'undergrowth_stage_changed', 'undergrowth_update_memory' );
// How distinctive a post's words are depends on every other post, so each
// file is rewritten once a month even when nothing of its own changed.
add_action( 'undergrowth_stage_cycled', 'undergrowth_update_memory' );
add_action( 'undergrowth_resurfacing_logged', 'undergrowth_update_memory' );
add_action( 'undergrowth_prompt_logged', 'undergrowth_update_memory' );
add_action( 'post_updated', 'undergrowth_update_memory_after_save', 10, 2 );
add_action( 'undergrowth_post_left_index', 'undergrowth_update_memory_around' );
// After the post cache is cleared, so a deleted post no longer looks published.
add_action( 'after_delete_post', 'undergrowth_update_memory_around', 10, 2 );

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

	$lines = array_merge(
		array(
			'# ' . $post->post_title,
			'url: ' . get_permalink( $post ),
			'slug: ' . $post->post_name,
			'updated: ' . gmdate( 'c' ),
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
		$links['vetoed']
	);

	// Questions a model wrote are the one thing in the file the author did not
	// write, so they are left out unless visitors are known to be refused.
	if ( undergrowth_memory_private() ) {
		$lines = array_merge( $lines, array( '', '## prompts' ), undergrowth_memory_prompts( $post->ID ) );
	}

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
		$lines[] = '- ' . gmdate( 'Y-m-d', $change['time'] ) . ': ' . $change['from'] . ' -> ' . $change['to'] . ' (' . $reason . ')';
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
		$lines[] = '- ' . gmdate( 'Y-m-d', $entry['time'] ) . ': ' . UNDERGROWTH_RESURFACING_EVENTS[ $entry['event'] ];
	}

	return $lines;
}

/**
 * Builds the prompts section of a post's file: one line for each prompt
 * asked, dismissed, replaced, saved, removed from the idea bank or brought
 * back, oldest first.
 *
 * @param int $post_id Post ID.
 * @return string[] Lines of the section, without its heading.
 */
function undergrowth_memory_prompts( int $post_id ): array {
	$lines = array();

	foreach ( undergrowth_prompt_log( $post_id ) as $event ) {
		$lines[] = '- ' . gmdate( 'Y-m-d', $event['time'] ) . ': ' . $event['event'] . ' (' . $event['source'] . ') "' . $event['question'] . '"';
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

	if ( ! $post instanceof WP_Post || ! undergrowth_is_indexable( $post ) || ( null === undergrowth_get_vector_space( $post_id ) && array() === undergrowth_prompt_log( $post_id ) ) ) {
		undergrowth_delete_memory( $post_id );
		return false;
	}

	$filesystem = undergrowth_filesystem();

	if ( null === $filesystem ) {
		undergrowth_record_failure( 'undergrowth_last_file_error', 'no_direct_access' );
		return false;
	}

	$root = undergrowth_memory_root();

	if ( '' === $root || ! wp_mkdir_p( $root . '/posts' ) ) {
		undergrowth_record_failure( 'undergrowth_last_file_error', 'no_folder' );
		return false;
	}

	// The files hold no secrets, but they are the owner's notes, not pages
	// for visitors. nginx and Caddy ignore both rules, which
	// undergrowth_check_memory_folder() finds out. Without its protection
	// files, the folder is never written to at all.
	$protection = array(
		'index.php'       => UNDERGROWTH_MEMORY_INDEX,
		'posts/index.php' => UNDERGROWTH_MEMORY_INDEX,
		'.htaccess'       => "Require all denied\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
		'web.config'      => UNDERGROWTH_MEMORY_WEB_CONFIG,
	);

	foreach ( $protection as $name => $contents ) {
		if ( ! undergrowth_write_protection_file( $filesystem, $root . '/' . $name, $contents ) ) {
			undergrowth_record_failure( 'undergrowth_last_file_error', 'no_protection' );
			return false;
		}
	}

	// Until a check has answered, the questions are left out. The first check
	// runs in WP-Cron, never in the request that wrote the file.
	if ( false === get_option( 'undergrowth_memory_exposed' ) && false === wp_next_scheduled( 'undergrowth_memory_folder_check' ) ) {
		wp_schedule_single_event( time(), 'undergrowth_memory_folder_check' );
	}

	if ( ! $filesystem->put_contents( undergrowth_memory_path( $post_id ), undergrowth_memory_text( $post ), FS_CHMOD_FILE ) ) {
		undergrowth_record_failure( 'undergrowth_last_file_error', 'write_failed' );
		return false;
	}

	delete_option( 'undergrowth_last_file_error' );

	return true;
}

/**
 * Rewrites a post's file and the files of posts that link to it, after the
 * post itself changes.
 *
 * A new slug changes the post's address without changing its text, so the
 * post is not re-embedded and nothing else would rewrite these files.
 *
 * @param int          $post_id Post that was updated.
 * @param WP_Post|null $post    The post, when core's hook passes it.
 */
function undergrowth_update_memory_around( int $post_id, ?WP_Post $post = null ): void {
	global $wpdb;

	if ( undergrowth_ignores_post( $post ) ) {
		return;
	}

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
 * Rewrites the files around a post after it is saved, when its type is
 * indexed or it still has a file.
 *
 * A post of another type has no file, unless it was indexed under an
 * earlier post type filter. One that also kept a vector leaves the index
 * on this same save, but one with only a file is caught here.
 *
 * @param int     $post_id Post that was updated.
 * @param WP_Post $post    The post as saved.
 */
function undergrowth_update_memory_after_save( int $post_id, WP_Post $post ): void {
	if ( undergrowth_ignores_post( $post ) ) {
		return;
	}

	$path = undergrowth_memory_path( $post_id );

	if ( in_array( $post->post_type, undergrowth_indexed_post_types(), true ) || ( '' !== $path && file_exists( $path ) ) ) {
		undergrowth_update_memory_around( $post_id, $post );
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
