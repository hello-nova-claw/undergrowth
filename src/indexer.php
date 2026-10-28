<?php
/**
 * Keeps the vectors table in step with published posts.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_BACKFILL_BATCH   = 20;
const UNDERGROWTH_BACKFILL_SECONDS = 15;
const UNDERGROWTH_FORGET_BATCH     = 100;

add_action( 'wp_after_insert_post', 'undergrowth_queue_embed', 10, 2 );
add_action( 'undergrowth_embed_post', 'undergrowth_embed_post' );
add_action( 'deleted_post', 'undergrowth_delete_vector', 10, 2 );
add_action( 'undergrowth_backfill', 'undergrowth_run_backfill' );
add_action( 'undergrowth_backfill_check', 'undergrowth_start_backfill' );
add_action( 'undergrowth_backfill_check', 'undergrowth_queue_stale_embeds' );
add_action( 'undergrowth_stale_check', 'undergrowth_queue_stale_embeds' );
// Activation does not run on plugin updates or on every site of a network.
add_action( 'init', 'undergrowth_schedule_backfill_check' );
add_action( 'add_option_undergrowth_openai_api_key', 'undergrowth_start_backfill', 20, 0 );
add_action( 'update_option_undergrowth_openai_api_key', 'undergrowth_start_backfill', 10, 0 );
// Polylang and WPML load their functions with the other plugins.
add_action( 'init', 'undergrowth_hook_multilingual_language', 0 );
add_action( 'add_option_undergrowth_post_types', 'undergrowth_post_types_changed', 10, 0 );
add_action( 'update_option_undergrowth_post_types', 'undergrowth_post_types_changed', 10, 0 );
add_action( 'undergrowth_forget_unindexed_posts', 'undergrowth_forget_unindexed_posts', 10, 0 );

/**
 * Lists the post types chosen on Settings > Undergrowth.
 *
 * @return string[] Post type names, only 'post' until some are chosen.
 */
function undergrowth_saved_post_types(): array {
	$saved = get_option( 'undergrowth_post_types', array( 'post' ) );

	return is_array( $saved ) ? array_values( array_filter( $saved, 'is_string' ) ) : array( 'post' );
}

/**
 * Lists the post types whose published posts are indexed.
 *
 * @return string[] Post type names.
 */
function undergrowth_indexed_post_types(): array {
	/**
	 * Filters which post types are indexed.
	 *
	 * Code that uses this filter has the last word over the setting, and the
	 * setting's boxes cannot be changed while it does.
	 *
	 * @param string[] $post_types Post type names. Default the ones chosen on Settings > Undergrowth, only 'post' until some are chosen.
	 */
	$post_types = apply_filters( 'undergrowth_post_types', undergrowth_saved_post_types() );

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
 * Decides whether a core post hook can be ignored for a post.
 *
 * Core fires its save and delete hooks for every revision it prunes, every
 * autosave and every attachment. None of them can ever have been published,
 * so none has a vector, links or a file. Any other post may have been
 * indexed under an earlier post type filter, and is never ignored. Nor is
 * a hook that passes no post.
 *
 * @param WP_Post|null $post The post the hook fired for, if it passed one.
 * @return bool True for a revision, an autosave or an attachment.
 */
function undergrowth_ignores_post( ?WP_Post $post ): bool {
	return null !== $post && ( 'revision' === $post->post_type || 'inherit' === $post->post_status );
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
 * Returns the query vars that keep a query to published posts without a
 * password, as undergrowth_is_indexable() does.
 *
 * @return array{post_status: string, has_password: bool}
 */
function undergrowth_garden_query_vars(): array {
	return array(
		'post_status'  => 'publish',
		'has_password' => false,
	);
}

/**
 * Builds the WP_Query arguments for the posts in the garden: every
 * indexable post, as IDs.
 *
 * @param array<string, mixed> $extra Arguments to add, or to use in place of the defaults.
 * @return array<string, mixed> WP_Query arguments.
 */
function undergrowth_garden_query_args( array $extra = array() ): array {
	$args = array_merge(
		array( 'post_type' => undergrowth_indexed_post_types() ),
		undergrowth_garden_query_vars(),
		array(
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		),
		$extra
	);

	// WP_Query reads an empty list of types as posts, but no type is indexed.
	if ( array() === $args['post_type'] ) {
		$args['post__in'] = array( 0 );
	}

	return $args;
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

		if ( undergrowth_ignores_post( $post ) ) {
			return;
		}

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

	// A revision is never read. Treating it as a post that left the index
	// would wipe its parent's cluster and resurfacing meta, as in
	// undergrowth_queue_embed().
	if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) ) {
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

	// The vector is dated from when the text was read, so an edit saved while
	// the provider works still counts as newer than it.
	$read_at  = current_time( 'mysql', true );
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
			'embedded_at'  => $read_at,
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
 * Reads the posts of a newly chosen post type, and takes those of a type no
 * longer chosen out of the index, after the post types setting changes.
 */
function undergrowth_post_types_changed(): void {
	if ( false === wp_next_scheduled( 'undergrowth_forget_unindexed_posts' ) ) {
		wp_schedule_single_event( time(), 'undergrowth_forget_unindexed_posts' );
	}

	undergrowth_start_backfill();
}

/**
 * Takes posts that have a vector but are no longer indexable out of the
 * index, a batch at a time, the way an unpublished post leaves it.
 *
 * @param int $limit Posts per run.
 */
function undergrowth_forget_unindexed_posts( int $limit = UNDERGROWTH_FORGET_BATCH ): void {
	global $wpdb;

	$indexable = undergrowth_indexable_sql( 'p.' );
	$condition = null === $indexable ? array( '1 = 1', array() ) : array( "NOT ( {$indexable[0]} )", $indexable[1] );

	// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The condition's placeholders come with it, one per value.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Joins the plugin's own table, which WP_Query cannot.
	$post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT v.post_id FROM %i v INNER JOIN %i p ON p.ID = v.post_id WHERE {$condition[0]} ORDER BY v.post_id ASC LIMIT %d",
			array_merge( array( undergrowth_vectors_table(), $wpdb->posts ), $condition[1], array( $limit + 1 ) )
		)
	);
	// phpcs:enable

	foreach ( array_slice( array_map( 'intval', $post_ids ), 0, $limit ) as $post_id ) {
		undergrowth_remove_from_index( $post_id );
	}

	if ( count( $post_ids ) > $limit ) {
		wp_schedule_single_event( time(), 'undergrowth_forget_unindexed_posts' );
	}
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
 * Makes every run of whitespace in a text one space, trimming nothing.
 *
 * Core's normalize_whitespace() keeps line breaks, so it does not do this.
 *
 * @param string $text Text.
 * @return string The text with single spaces.
 */
function undergrowth_collapse_spaces( string $text ): string {
	return (string) preg_replace( '/\s+/u', ' ', $text );
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

	return trim( undergrowth_collapse_spaces( $text ) );
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
 * Takes each post's language from Polylang or WPML, when either is active.
 *
 * Only then is the filter added, so a site without one keeps comparing every
 * post in the site language.
 */
function undergrowth_hook_multilingual_language(): void {
	if ( function_exists( 'pll_get_post_language' ) || has_filter( 'wpml_post_language_details' ) ) {
		add_filter( 'undergrowth_post_language', 'undergrowth_multilingual_language', 5, 2 );
	}
}

/**
 * Reads a post's language from Polylang or WPML.
 *
 * @param mixed   $locale Language so far.
 * @param WP_Post $post   Post being embedded.
 * @return mixed The post's locale, or the language so far when neither plugin gives one.
 */
function undergrowth_multilingual_language( $locale, $post ) {
	if ( ! $post instanceof WP_Post ) {
		return $locale;
	}

	if ( function_exists( 'pll_get_post_language' ) ) {
		$language = pll_get_post_language( $post->ID, 'locale' );

		if ( is_string( $language ) && '' !== $language ) {
			return $language;
		}
	}

	$details = apply_filters( 'wpml_post_language_details', null, $post->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook.

	return is_array( $details ) && is_string( $details['locale'] ?? null ) && '' !== $details['locale'] ? $details['locale'] : $locale;
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
	$stale     = undergrowth_stale_index_language( $identity );

	if ( null === $indexable ) {
		return array();
	}

	// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The condition's placeholders come with it, one per value.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Joins the plugin's own table, which WP_Query cannot.
	$post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM %i p LEFT JOIN %i v ON v.post_id = p.ID AND v.provider = %s AND v.model = %s AND ( %s = '' OR v.language = %s ) WHERE ( v.post_id IS NULL OR ( %s <> '' AND v.language = %s ) ) AND p.ID > %d AND {$indexable[0]} ORDER BY p.ID ASC LIMIT %d",
			array_merge( array( $wpdb->posts, undergrowth_vectors_table(), $identity['provider'], $identity['model'], $identity['language'], $identity['language'], $stale, $stale, $after_id ), $indexable[1], array( $limit ) )
		)
	);
	// phpcs:enable

	return array_map( 'intval', $post_ids );
}

/**
 * Returns the site language the index was filled in, once posts have
 * started to take their own language, as when Polylang or WPML is turned on.
 *
 * Vectors still in that language may be in the wrong one, so a pass reads
 * them again. Embedding a post whose language has not changed sends nothing.
 * Once the pass fills the index in the new way, there is none.
 *
 * @param array $identity {
 *     The target, as from undergrowth_index_target().
 *
 *     @type string $provider Provider name.
 *     @type string $model    Model name.
 *     @type string $language Site locale, or empty when each post sets its own language.
 * }
 * @return string The old site language, or an empty string.
 *
 * @phpstan-param array{provider: string, model: string, language: string} $identity
 */
function undergrowth_stale_index_language( array $identity ): string {
	$recorded = get_option( 'undergrowth_index_identity' );

	if ( '' !== $identity['language'] || ! is_array( $recorded ) || ( $recorded['provider'] ?? '' ) !== $identity['provider'] || ( $recorded['model'] ?? '' ) !== $identity['model'] ) {
		return '';
	}

	return is_string( $recorded['language'] ?? null ) ? $recorded['language'] : '';
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
 * run is queued for when the wait is over. When every post has a vector,
 * it clusters an index that no pass has recorded yet.
 */
function undergrowth_start_backfill(): void {
	$identity = undergrowth_index_target();

	if ( ! undergrowth_identity_is_complete( $identity ) ) {
		return;
	}

	if ( array() !== undergrowth_unindexed_post_ids( $identity, 0, 1 ) ) {
		wp_schedule_single_event( max( time(), undergrowth_backfill_retry_time( $identity ) ), 'undergrowth_backfill' );
		// Saving a key runs this in the request, so a run that never comes
		// is still noticed a day later.
		undergrowth_backfill_moved( undergrowth_index_counts() );
		return;
	}

	undergrowth_record_index_identity( $identity );
}

/**
 * Records the identity of a filled index and clusters it.
 *
 * Does nothing while some indexable post has no vector for this identity,
 * so a pass whose embeds mostly failed does not cluster a nearly empty
 * index, or while there is no indexable post yet, so the first posts on a
 * new site are still clustered early. Once the identity is recorded,
 * clustering is left to the monthly cycle. Every post's proposals are
 * refreshed once too, with the word counts of the whole site.
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
function undergrowth_record_index_identity( array $identity ): void {
	// The counts are for the current target, which a batch that read the
	// identity before a model or language switch no longer matches.
	if ( get_option( 'undergrowth_index_identity' ) === $identity || undergrowth_index_target() !== $identity ) {
		return;
	}

	$counts = undergrowth_index_counts();

	if ( 0 === $counts['total'] || $counts['indexed'] < $counts['total'] ) {
		return;
	}

	update_option( 'undergrowth_index_identity', $identity, false );
	wp_schedule_single_event( time(), 'undergrowth_cluster_cycle', array( 0 ) );
	wp_schedule_single_event( time(), 'undergrowth_refresh_index', array( 0 ) );
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
 * Returns how many posts one hourly check reads for text that changed unseen.
 *
 * @return int Posts, at least one.
 */
function undergrowth_stale_check_size(): int {
	/**
	 * Filters how many posts one hourly check reads for text that changed
	 * without being embedded again.
	 *
	 * Each is read and hashed, without calling the provider. Only one whose
	 * text really changed is embedded.
	 *
	 * @param int $size Posts per check. Default 100.
	 */
	return max( 1, (int) apply_filters( 'undergrowth_stale_check_size', 100 ) );
}

/**
 * Queues an embed for each post whose text changed after its vector was
 * made, as when an edit's embed was lost while the plugin was inactive.
 *
 * Only posts saved since their vector was stored can have changed, so those
 * are read, a limited number per run, each run queueing the next until the
 * walk ends. A post saved with the same text keeps its vector.
 */
function undergrowth_queue_stale_embeds(): void {
	global $wpdb;

	$identity  = undergrowth_embed_identity();
	$indexable = undergrowth_indexable_sql( 'p.' );

	if ( ! undergrowth_identity_is_complete( $identity ) || null === $indexable ) {
		return;
	}

	$after = (int) get_option( 'undergrowth_stale_check', 0 );
	$size  = undergrowth_stale_check_size();

	// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The condition's placeholders come with it, one per value.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Joins the plugin's own table, which WP_Query cannot.
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, v.content_hash FROM %i p JOIN %i v ON v.post_id = p.ID WHERE p.post_modified_gmt >= v.embedded_at AND p.ID > %d AND {$indexable[0]} ORDER BY p.ID ASC LIMIT %d",
			array_merge( array( $wpdb->posts, undergrowth_vectors_table(), $after ), $indexable[1], array( $size ) )
		),
		ARRAY_A
	);
	// phpcs:enable

	// A full page leaves more to read, which the next run reads at once, so
	// the walk ends within the hour. A short one ends the walk.
	$full = count( $rows ) >= $size;

	update_option( 'undergrowth_stale_check', $full ? (int) end( $rows )['ID'] : 0, false );

	if ( $full && false === wp_next_scheduled( 'undergrowth_stale_check' ) ) {
		wp_schedule_single_event( time(), 'undergrowth_stale_check' );
	}

	foreach ( $rows as $row ) {
		$post_id = (int) $row['ID'];
		$post    = get_post( $post_id );

		if ( $post instanceof WP_Post && hash( 'sha256', undergrowth_post_text( $post ) ) !== (string) $row['content_hash'] && false === wp_next_scheduled( 'undergrowth_embed_post', array( $post_id ) ) ) {
			wp_schedule_single_event( time(), 'undergrowth_embed_post', array( $post_id ) );
		}
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
 * post. The first pass to finish with every post embedded for a provider,
 * model and language clusters the new index, which would otherwise wait a
 * month for the next cycle. When some embeds failed, the hourly check
 * clusters it once they have been retried.
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
		undergrowth_note_backfill_progress( undergrowth_index_counts() );
	} finally {
		WP_Upgrader::release_lock( 'undergrowth_backfill' );
	}
}

/**
 * Records when the backfill was last seen working, with the counts it saw.
 *
 * @param array $counts {
 *     Counts from undergrowth_index_counts().
 *
 *     @type int $indexed Posts with a current vector.
 *     @type int $total   Posts that can be indexed.
 * }
 *
 * @phpstan-param array{indexed: int, total: int} $counts
 */
function undergrowth_note_backfill_progress( array $counts ): void {
	update_option( 'undergrowth_backfill_progress', array_merge( array( 'time' => time() ), $counts ), false );
}

/**
 * Says whether posts are waiting to be read and the backfill has not run, nor
 * the counts moved, for a day.
 *
 * Every backfill run records the time, so a run whose embeds all failed
 * still counts as moving: the failure notices cover that case. Only cron
 * that never fires leaves the time behind. Counts that differ from the
 * recorded ones, after a post is published or read on save for example,
 * start the day again, so new work is never taken for a stall.
 *
 * @return bool True when the backfill looks stalled.
 */
function undergrowth_backfill_stalled(): bool {
	if ( ! undergrowth_has_embed_provider() ) {
		return false;
	}

	$counts = undergrowth_index_counts();

	if ( $counts['indexed'] >= $counts['total'] ) {
		return false;
	}

	if ( undergrowth_backfill_moved( $counts ) ) {
		return false;
	}

	$progress = get_option( 'undergrowth_backfill_progress' );

	return time() - (int) ( $progress['time'] ?? 0 ) >= DAY_IN_SECONDS;
}

/**
 * Starts the day again when the counts differ from the recorded ones, or
 * none are recorded. The same counts keep the recorded time.
 *
 * @param array $counts {
 *     Counts from undergrowth_index_counts().
 *
 *     @type int $indexed Posts with a current vector.
 *     @type int $total   Posts that can be indexed.
 * }
 * @return bool True when the day started again.
 *
 * @phpstan-param array{indexed: int, total: int} $counts
 */
function undergrowth_backfill_moved( array $counts ): bool {
	$progress = get_option( 'undergrowth_backfill_progress' );

	if ( is_array( $progress ) && ( $progress['indexed'] ?? null ) === $counts['indexed'] && ( $progress['total'] ?? null ) === $counts['total'] ) {
		return false;
	}

	undergrowth_note_backfill_progress( $counts );

	return true;
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

	// Until the index is recorded, the pass ends by refreshing every post's
	// suggestions with the whole site's word counts. Refreshing the posts
	// around each one as it is read would be thrown away, and it compares
	// each post with the whole index, which made a read of 5,000 posts take
	// hours of cron. A post read once the index is recorded has no such
	// refresh to follow it, so it still refreshes the posts around it.
	$deferred = get_option( 'undergrowth_index_identity' ) !== $identity ? has_action( 'undergrowth_post_embedded', 'undergrowth_refresh_around' ) : false;

	if ( false !== $deferred ) {
		remove_action( 'undergrowth_post_embedded', 'undergrowth_refresh_around', $deferred );
	}

	undergrowth_openai_deadline( $started + $budget );

	try {
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
	} finally {
		undergrowth_openai_deadline( INF );

		if ( false !== $deferred ) {
			add_action( 'undergrowth_post_embedded', 'undergrowth_refresh_around', $deferred );
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
	undergrowth_record_index_identity( $identity );
}
