<?php
/**
 * Keeps the vectors table in step with published posts.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_BACKFILL_BATCH   = 20;
const UNDERGROWTH_BACKFILL_SECONDS = 15;

add_action( 'wp_after_insert_post', 'undergrowth_queue_embed', 10, 2 );
add_action( 'undergrowth_embed_post', 'undergrowth_embed_post' );
add_action( 'deleted_post', 'undergrowth_delete_vector' );
add_action( 'undergrowth_backfill', 'undergrowth_run_backfill' );
add_action( 'undergrowth_backfill_check', 'undergrowth_start_backfill' );
// Activation does not run on plugin updates or on every site of a network.
add_action( 'init', 'undergrowth_schedule_backfill_check' );
add_action( 'add_option_undergrowth_openai_api_key', 'undergrowth_start_backfill', 20, 0 );
add_action( 'update_option_undergrowth_openai_api_key', 'undergrowth_start_backfill', 10, 0 );

/**
 * Lists the post types whose published posts are indexed.
 *
 * @return string[] Post type names.
 */
function undergrowth_indexed_post_types(): array {
	/**
	 * Filters which post types are indexed.
	 *
	 * @param string[] $post_types Post type names. Default only 'post'.
	 */
	$post_types = apply_filters( 'undergrowth_post_types', array( 'post' ) );

	return is_array( $post_types ) ? array_values( array_filter( $post_types, 'is_string' ) ) : array( 'post' );
}

/**
 * Decides whether a post belongs in the index.
 *
 * Password-protected posts are left out because their body is not public,
 * so it must neither reach a provider nor shape links readers can see.
 *
 * @param WP_Post $post Post to check.
 * @return bool True when the post should have a vector.
 */
function undergrowth_is_indexable( WP_Post $post ): bool {
	return 'publish' === $post->post_status
		&& '' === $post->post_password
		&& in_array( $post->post_type, undergrowth_indexed_post_types(), true );
}

/**
 * Builds undergrowth_is_indexable() as an SQL condition, for queries that
 * pick indexable posts without loading each one.
 *
 * @param string $alias Prefix for the posts table's columns, such as 'p.', or empty.
 * @return array{0: string, 1: array<int, string>}|null The condition with its placeholders, and their values in order, or null when no post type is indexed.
 */
function undergrowth_indexable_sql( string $alias ): ?array {
	$post_types = undergrowth_indexed_post_types();

	if ( array() === $post_types ) {
		return null;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

	return array(
		"{$alias}post_status = %s AND {$alias}post_password = %s AND {$alias}post_type IN ({$placeholders})",
		array_merge( array( 'publish', '' ), $post_types ),
	);
}

/**
 * Schedules an embed when a post is saved, or removes its vector when it
 * leaves the index.
 *
 * The embed runs from cron because the provider may be slow or remote, and
 * the editor should not wait for it.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post that was saved.
 */
function undergrowth_queue_embed( int $post_id, WP_Post $post ): void {
	// Core saves a revision on every update. Post meta functions map a
	// revision's ID to its parent, so treating the revision as a post that
	// left the index would wipe the parent's cluster and resurfacing meta.
	if ( wp_is_post_revision( $post ) ) {
		return;
	}

	if ( ! undergrowth_is_indexable( $post ) ) {
		wp_clear_scheduled_hook( 'undergrowth_embed_post', array( $post_id ) );

		// Menu items, synced patterns and the like are never indexed and are
		// saved often, so only a post that has a vector, or whose type is
		// indexed, is announced as leaving.
		if ( in_array( $post->post_type, undergrowth_indexed_post_types(), true ) || null !== undergrowth_get_vector( $post_id ) ) {
			undergrowth_remove_from_index( $post_id );
		}

		return;
	}

	if ( false === wp_next_scheduled( 'undergrowth_embed_post', array( $post_id ) ) ) {
		wp_schedule_single_event( time(), 'undergrowth_embed_post', array( $post_id ) );
	}
}

/**
 * Embeds a post and stores its vector, unless nothing has changed.
 *
 * @param int $post_id Post ID.
 */
function undergrowth_embed_post( int $post_id ): void {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return;
	}

	if ( ! undergrowth_is_indexable( $post ) ) {
		undergrowth_remove_from_index( $post_id );
		return;
	}

	$identity = undergrowth_embed_identity();

	if ( ! undergrowth_identity_is_complete( $identity ) ) {
		return;
	}

	$text     = undergrowth_post_text( $post );
	$language = undergrowth_post_language( $post );
	$hash     = hash( 'sha256', $text );
	$existing = undergrowth_get_vector( $post_id );

	if (
		null !== $existing
		&& $existing['content_hash'] === $hash
		&& $existing['provider'] === $identity['provider']
		&& $existing['model'] === $identity['model']
		&& $existing['language'] === $language
	) {
		return;
	}

	$vector = undergrowth_embed( $text );

	// A vector for text the post no longer has would keep shaping proposals,
	// so a failed re-embed removes it until the next attempt succeeds. One
	// that only came from another model still describes the post, and is
	// never compared with the new model's, so it stays until it is replaced.
	if ( array() === $vector ) {
		if ( null !== $existing && ( $existing['content_hash'] !== $hash || $existing['language'] !== $language ) ) {
			undergrowth_remove_from_index( $post_id );
		}
		return;
	}

	$saved = undergrowth_save_vector(
		$post_id,
		$vector,
		array(
			'provider'     => $identity['provider'],
			'model'        => $identity['model'],
			'language'     => $language,
			'content_hash' => $hash,
		)
	);

	if ( $saved ) {
		/**
		 * Fires after a post's vector is stored or replaced.
		 *
		 * @param int $post_id Post ID.
		 */
		do_action( 'undergrowth_post_embedded', $post_id );
	}
}

/**
 * Removes a post that is no longer published from the index.
 *
 * @param int $post_id Post ID.
 */
function undergrowth_remove_from_index( int $post_id ): void {
	undergrowth_delete_vector( $post_id );

	/**
	 * Fires after a post leaves the index, for example when it is unpublished.
	 *
	 * @param int $post_id Post ID.
	 */
	do_action( 'undergrowth_post_left_index', $post_id );
}

/**
 * Builds the text that represents a post: its title, then its body as plain text.
 *
 * @param WP_Post $post Post to read.
 * @return string Title and body, separated by a blank line.
 */
function undergrowth_post_text( WP_Post $post ): string {
	return trim( $post->post_title . "\n\n" . undergrowth_plain_line( strip_shortcodes( $post->post_content ) ) );
}

/**
 * Turns markup into one line of plain text, as a reader would read it.
 *
 * Entities are decoded, so `&amp;` reads as `&` and is never taken for a
 * word, and every run of whitespace, a non-breaking space among them,
 * becomes one space.
 *
 * @param string $html Markup, or text with entities.
 * @return string Plain text on one line.
 */
function undergrowth_plain_line( string $html ): string {
	$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Returns the language of a post, as a locale.
 *
 * @param WP_Post $post Post to check.
 * @return string Locale such as en_US.
 */
function undergrowth_post_language( WP_Post $post ): string {
	/**
	 * Filters the language stored with a post's vector.
	 *
	 * Multilingual plugins can return the post's own language. Vectors in
	 * different languages are never compared.
	 *
	 * @param string  $locale Site locale.
	 * @param WP_Post $post   Post being embedded.
	 */
	$locale = apply_filters( 'undergrowth_post_language', get_locale(), $post );
	$locale = is_string( $locale ) && '' !== $locale ? sanitize_text_field( $locale ) : get_locale();

	return undergrowth_fit_language( $locale );
}

/**
 * Fits a language to the 20 characters its column holds.
 *
 * The value stored is then the value compared on the next save. A longer
 * one keeps its start and ends in a short hash of the whole, so two
 * languages that begin alike are never compared as one.
 *
 * @param string $locale Language, such as en_US.
 * @return string The language, at most 20 characters.
 */
function undergrowth_fit_language( string $locale ): string {
	if ( mb_strlen( $locale ) <= 20 ) {
		return $locale;
	}

	return mb_substr( $locale, 0, 11 ) . '~' . substr( md5( $locale ), 0, 8 );
}

/**
 * Returns the current provider and model, with the site's language when
 * every post takes its language from the site.
 *
 * A change of site language then reads the whole index again, as a change
 * of model does, so it never splits into two halves that are never
 * compared. Once a multilingual plugin sets each post's language, posts in
 * different languages rightly stay apart, so the language is left out.
 *
 * @return array{provider: string, model: string, language: string} The language is empty when posts set their own.
 */
function undergrowth_index_target(): array {
	$identity = undergrowth_embed_identity();

	return array(
		'provider' => $identity['provider'],
		'model'    => $identity['model'],
		'language' => has_filter( 'undergrowth_post_language' ) ? '' : undergrowth_fit_language( get_locale() ),
	);
}

/**
 * Returns how many posts one backfill run embeds.
 *
 * @return int Posts per run, at least one.
 */
function undergrowth_backfill_batch_size(): int {
	/**
	 * Filters how many posts one backfill run sends to the provider.
	 *
	 * Each post is one request, so a slow provider may need a smaller batch
	 * to finish within one cron request.
	 *
	 * @param int $size Posts per run. Default 20.
	 */
	return max( 1, (int) apply_filters( 'undergrowth_backfill_batch_size', UNDERGROWTH_BACKFILL_BATCH ) );
}

/**
 * Returns how long one backfill run may keep starting new posts.
 *
 * @return float Seconds, at least zero.
 */
function undergrowth_backfill_time_budget(): float {
	/**
	 * Filters how long one backfill run may keep starting new posts.
	 *
	 * Each post embedded also refreshes the proposals around it, which takes
	 * longer as the site grows. WP-Cron often runs inside a visitor's request,
	 * and many hosts stop a request after 30 seconds, so a run starts no new
	 * post once this much time has passed. It always embeds at least one.
	 *
	 * @param float $seconds Seconds per run. Default 15.
	 */
	return max( 0.0, (float) apply_filters( 'undergrowth_backfill_time_budget', UNDERGROWTH_BACKFILL_SECONDS ) );
}

/**
 * Lists indexable posts that have no vector from the current provider and
 * model, or none in the site language when posts take theirs from the site.
 *
 * Uses undergrowth_indexable_sql(), the rule of undergrowth_is_indexable(),
 * so posts that must not be sent are never picked. undergrowth_embed_post()
 * checks each post again.
 *
 * @param array $identity {
 *     The current provider and model, and the site language when posts take theirs from the site.
 *
 *     @type string $provider Provider name.
 *     @type string $model    Model name.
 *     @type string $language Site locale, or empty when each post sets its own language.
 * }
 * @param int   $after_id Only posts with a higher ID than this.
 * @param int   $limit    Most posts to return.
 * @return int[] Post IDs in ascending order.
 *
 * @phpstan-param array{provider: string, model: string, language: string} $identity
 */
function undergrowth_unindexed_post_ids( array $identity, int $after_id, int $limit ): array {
	global $wpdb;

	$indexable = undergrowth_indexable_sql( 'p.' );

	if ( null === $indexable ) {
		return array();
	}

	// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The condition's placeholders come with it, one per value.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Joins the plugin's own table, which WP_Query cannot.
	$post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM %i p LEFT JOIN %i v ON v.post_id = p.ID AND v.provider = %s AND v.model = %s AND ( %s = '' OR v.language = %s ) WHERE v.post_id IS NULL AND p.ID > %d AND {$indexable[0]} ORDER BY p.ID ASC LIMIT %d",
			array_merge( array( $wpdb->posts, undergrowth_vectors_table(), $identity['provider'], $identity['model'], $identity['language'], $identity['language'], $after_id ), $indexable[1], array( $limit ) )
		)
	);
	// phpcs:enable

	return array_map( 'intval', $post_ids );
}

/**
 * Counts indexable posts, and how many of them have a vector from the
 * current provider and model.
 *
 * @return array {
 *     @type int $indexed Posts with a current vector.
 *     @type int $total   Posts that can be indexed.
 * }
 *
 * @phpstan-return array{indexed: int, total: int}
 */
function undergrowth_index_counts(): array {
	global $wpdb;

	$indexable = undergrowth_indexable_sql( 'p.' );

	if ( null === $indexable ) {
		return array(
			'indexed' => 0,
			'total'   => 0,
		);
	}

	$identity = undergrowth_index_target();

	// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The condition's placeholders come with it, one per value.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Joins the plugin's own table, which WP_Query cannot.
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT COUNT(p.ID) AS total, COUNT(v.post_id) AS indexed FROM %i p LEFT JOIN %i v ON v.post_id = p.ID AND v.provider = %s AND v.model = %s AND ( %s = '' OR v.language = %s ) WHERE {$indexable[0]}",
			array_merge( array( $wpdb->posts, undergrowth_vectors_table(), $identity['provider'], $identity['model'], $identity['language'], $identity['language'] ), $indexable[1] )
		),
		ARRAY_A
	);
	// phpcs:enable

	return array(
		'indexed' => (int) ( $row['indexed'] ?? 0 ),
		'total'   => (int) ( $row['total'] ?? 0 ),
	);
}

/**
 * Queues a backfill run when some indexable post has no current vector.
 *
 * Runs on activation, when an OpenAI key is saved and from the hourly
 * check, which also retries posts whose embed failed. WP-Cron refuses a
 * second identical event within ten minutes, so calling this while a run
 * is queued adds nothing. While OpenAI has asked Undergrowth to wait, the
 * run is queued for when the wait is over.
 */
function undergrowth_start_backfill(): void {
	$identity = undergrowth_index_target();

	if ( ! undergrowth_identity_is_complete( $identity ) ) {
		return;
	}

	if ( array() !== undergrowth_unindexed_post_ids( $identity, 0, 1 ) ) {
		wp_schedule_single_event( max( time(), undergrowth_backfill_retry_time( $identity ) ), 'undergrowth_backfill' );
	}
}

/**
 * Returns when the backfill may send posts again, after OpenAI turned a
 * request away. Code that reads the posts itself never waits for OpenAI.
 *
 * @param array $identity The current provider and model.
 * @return int Unix time, or 0 when posts may be sent now.
 *
 * @phpstan-param array{provider: string, model: string} $identity
 */
function undergrowth_backfill_retry_time( array $identity ): int {
	return undergrowth_embed_identity_is_built_in( $identity ) ? undergrowth_openai_retry_time() : 0;
}

/**
 * Schedules the hourly check for posts without a vector.
 */
function undergrowth_schedule_backfill_check(): void {
	if ( false === wp_next_scheduled( 'undergrowth_backfill_check' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'undergrowth_backfill_check' );
	}
}

/**
 * Embeds one batch of indexable posts that have no current vector.
 *
 * A pass walks the posts by ID, and each full batch, or one cut short by
 * the time budget, queues the next run after the last post it tried, so a
 * post whose embed failed does not hold up the rest. The hourly check starts a new pass for the posts left over.
 * When OpenAI turns a request away with a 429 or 5xx, the batch stops and
 * the next run waits as long as OpenAI asked, starting with that post.
 *
 * Posts whose vector came from another provider or model, or in another
 * site language, count as having none, so the same passes re-embed the
 * whole index when the model or the site's language changes. A pass that
 * was under way for another model or language starts again from the first
 * post. The first pass to finish with a provider, model and language
 * clusters the new index, which would otherwise wait a month for the next
 * cycle.
 */
function undergrowth_run_backfill(): void {
	$identity = undergrowth_index_target();

	if ( ! undergrowth_identity_is_complete( $identity ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	// Two runs at once, from a server cron job beside WP-Cron for example,
	// would share the cursor and send the same posts twice. The lock is taken
	// atomically, and one left by a run that died expires after ten minutes.
	if ( ! WP_Upgrader::create_lock( 'undergrowth_backfill', 10 * MINUTE_IN_SECONDS ) ) {
		return;
	}

	try {
		undergrowth_run_backfill_batch( $identity );
	} finally {
		WP_Upgrader::release_lock( 'undergrowth_backfill' );
	}
}

/**
 * Embeds the next batch of the current pass, while holding the backfill lock.
 *
 * @param array $identity {
 *     The current provider and model, and the site language when posts take theirs from the site.
 *
 *     @type string $provider Provider name.
 *     @type string $model    Model name.
 *     @type string $language Site locale, or empty when each post sets its own language.
 * }
 *
 * @phpstan-param array{provider: string, model: string, language: string} $identity
 */
function undergrowth_run_backfill_batch( array $identity ): void {
	$pass     = get_option( 'undergrowth_backfill' );
	$after    = is_array( $pass ) && ( $pass['provider'] ?? '' ) === $identity['provider'] && ( $pass['model'] ?? '' ) === $identity['model'] && ( $pass['language'] ?? '' ) === $identity['language'] ? (int) ( $pass['after'] ?? 0 ) : 0;
	$size     = undergrowth_backfill_batch_size();
	$budget   = undergrowth_backfill_time_budget();
	$started  = microtime( true );
	$post_ids = undergrowth_unindexed_post_ids( $identity, $after, $size );
	$tried    = array();
	$retry    = undergrowth_backfill_retry_time( $identity );

	foreach ( $post_ids as $post_id ) {
		if ( 0 !== $retry || ( array() !== $tried && microtime( true ) - $started >= $budget ) ) {
			break;
		}

		undergrowth_embed_post( $post_id );
		$retry = undergrowth_backfill_retry_time( $identity );

		// A post OpenAI turned away is tried again first after the wait.
		if ( 0 === $retry ) {
			$tried[] = $post_id;
		}
	}

	if ( 0 !== $retry ) {
		update_option( 'undergrowth_backfill', array_merge( $identity, array( 'after' => array() !== $tried ? end( $tried ) : $after ) ), false );
		wp_schedule_single_event( $retry, 'undergrowth_backfill' );
		return;
	}

	// A batch cut short by the budget left posts behind, like a full batch may.
	if ( count( $post_ids ) === $size || count( $tried ) < count( $post_ids ) ) {
		update_option( 'undergrowth_backfill', array_merge( $identity, array( 'after' => end( $tried ) ) ), false );
		wp_schedule_single_event( time(), 'undergrowth_backfill' );
		return;
	}

	delete_option( 'undergrowth_backfill' );

	if ( get_option( 'undergrowth_index_identity' ) !== $identity ) {
		update_option( 'undergrowth_index_identity', $identity, false );
		wp_schedule_single_event( time(), 'undergrowth_cluster_cycle', array( 0 ) );
	}
}
