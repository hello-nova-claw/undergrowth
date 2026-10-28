<?php
/**
 * Writing prompts: questions to the author, written by Claude from the
 * author's own garden, which the Prompt studio lists.
 *
 * A prompt is never a draft and never reaches readers. Whatever a source
 * carries from a stranger is passed to the model as data inside a JSON
 * string, never as part of the rules, and every reply is checked before it
 * is kept.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

// The rules ask for under 250 characters, so a question that runs a little
// over is still kept, not thrown away.
const UNDERGROWTH_PROMPT_MAX_LENGTH  = 300;
const UNDERGROWTH_PROMPT_MAX_CALLS   = 3;
const UNDERGROWTH_PROMPT_BATCH       = 5;
const UNDERGROWTH_PROMPT_BATCH_CALLS = 10;
const UNDERGROWTH_PROMPT_MORE        = 3;
const UNDERGROWTH_PROMPT_MORE_CALLS  = 6;
const UNDERGROWTH_PROMPT_MORE_WAIT   = 10 * MINUTE_IN_SECONDS;
const UNDERGROWTH_PROMPT_DATA_CHARS  = 1000;
const UNDERGROWTH_PROMPT_COMMENTS    = 50;
const UNDERGROWTH_PROMPT_COMPOSTED   = 20;
const UNDERGROWTH_PROMPT_PAGES       = 10;
const UNDERGROWTH_PROMPT_GAP_POSTS   = 3;
const UNDERGROWTH_PROMPT_SENTENCES   = 20;

// Events a post's prompt log may hold. A snooze no longer exists, but logs
// written before the Prompt studio still hold it.
const UNDERGROWTH_PROMPT_EVENTS = array( 'asked', 'dismissed', 'snoozed', 'restored', 'saved', 'removed', 'replaced' );

// What a stored question can be: new in the batch, kept in the idea bank, or
// dismissed and kept a little while so an Undo can bring it back.
const UNDERGROWTH_PROMPT_STATUSES = array( 'new', 'saved', 'dismissed' );

// Dismissed questions kept for Undo. Their sources are recorded as sent, so a
// dismissed question never comes back once it is dropped.
const UNDERGROWTH_PROMPT_KEEP_DISMISSED = 10;

// Every write to the stored questions takes one short lock. A lock left by a
// stopped request is taken over after this many seconds, and a writer that
// finds the lock taken tries this many times, a twentieth of a second apart.
const UNDERGROWTH_PROMPT_LOCK_TIMEOUT = 10;
const UNDERGROWTH_PROMPT_LOCK_TRIES   = 20;

add_action( 'init', 'undergrowth_schedule_prompt_batch' );
add_action( 'init', 'undergrowth_queue_config_first_prompt', 20 );
add_action( 'undergrowth_prompt_batch', 'undergrowth_run_prompt_batch' );
add_action( 'undergrowth_write_prompt', 'undergrowth_write_first_prompt' );
add_action( 'add_option_undergrowth_anthropic_api_key', 'undergrowth_queue_first_prompt' );
add_action( 'update_option_undergrowth_anthropic_api_key', 'undergrowth_queue_first_prompt' );
add_action( 'undergrowth_prompt_written', 'undergrowth_log_written_prompt', 10, 2 );

/**
 * Schedules the weekly prompt batch when it is not scheduled yet.
 *
 * The batch replaced a monthly cycle that wrote one question, so a site
 * upgraded from it loses that event here.
 */
function undergrowth_schedule_prompt_batch(): void {
	if ( false !== wp_next_scheduled( 'undergrowth_prompt_cycle' ) ) {
		wp_unschedule_hook( 'undergrowth_prompt_cycle' );
	}

	if ( false === wp_next_scheduled( 'undergrowth_prompt_batch' ) ) {
		wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', 'undergrowth_prompt_batch' );
	}
}

/**
 * Writes a first prompt soon after an Anthropic key appears in wp-config.php.
 *
 * Such a key is never saved, so the option hooks that ask for the first
 * prompt never fire. This asks once, and remembers that it did: a prompt
 * that is dismissed or saved leaves nothing queued, and asking again on
 * every request would skip the weekly wait.
 */
function undergrowth_queue_config_first_prompt(): void {
	if ( '' === undergrowth_key_from_config( 'UNDERGROWTH_ANTHROPIC_API_KEY' ) || false !== get_option( 'undergrowth_config_prompt_queued' ) ) {
		return;
	}

	// A stored question means the author has been asked already, with a key
	// that was saved before it moved to wp-config.php.
	if ( array() !== undergrowth_stored_prompts() ) {
		update_option( 'undergrowth_config_prompt_queued', 1 );
		return;
	}

	undergrowth_queue_first_prompt();

	// Scheduling can fail, and then the next request tries again.
	if ( false !== wp_next_scheduled( 'undergrowth_write_prompt' ) || null !== undergrowth_current_prompt() ) {
		update_option( 'undergrowth_config_prompt_queued', 1 );
	}
}

/**
 * Fills the first batch soon after a key is saved, so the author does not
 * wait a week for it.
 *
 * Saving the same key again, or removing it, writes nothing.
 */
function undergrowth_queue_first_prompt(): void {
	if ( undergrowth_prompts_enabled() && null === undergrowth_current_prompt() && false === wp_next_scheduled( 'undergrowth_write_prompt' ) ) {
		wp_schedule_single_event( time(), 'undergrowth_write_prompt' );
	}
}

/**
 * Removes the plugin's prompt events.
 */
function undergrowth_unschedule_prompts(): void {
	// A first prompt for a key in wp-config.php that was never written is
	// asked for again once the plugin is active again.
	if ( false !== wp_next_scheduled( 'undergrowth_write_prompt' ) ) {
		delete_option( 'undergrowth_config_prompt_queued' );
	}

	wp_unschedule_hook( 'undergrowth_prompt_cycle' );
	wp_unschedule_hook( 'undergrowth_prompt_batch' );
	wp_unschedule_hook( 'undergrowth_write_prompt' );
}

/**
 * Fills the first batch, queued when a key is saved.
 */
function undergrowth_write_first_prompt(): void {
	undergrowth_run_prompt_batch();
}

/**
 * Runs the weekly prompt batch: tops the new questions up to
 * UNDERGROWTH_PROMPT_BATCH, from sources not used before.
 *
 * With the batch already full, nothing is sent. When the garden has no
 * source left, or a request fails, the batch stops short rather than pad.
 * A batch with room holds one cross-pollination question whenever a pair
 * can give one.
 */
function undergrowth_run_prompt_batch(): void {
	if ( ! undergrowth_prompts_enabled() ) {
		return;
	}

	$wanted = UNDERGROWTH_PROMPT_BATCH - count( undergrowth_prompts( 'new' ) );
	$calls  = 0;

	// One place goes to a cross-pollination question first, when a pair can
	// give one (handover 12.6). Without a pair the batch fills as before. Both
	// share the batch's one budget of requests.
	if ( $wanted > 0 ) {
		$wanted -= undergrowth_write_cross_prompt( $calls );
	}

	if ( $wanted > 0 && $calls < UNDERGROWTH_PROMPT_BATCH_CALLS ) {
		undergrowth_write_prompts( $wanted, UNDERGROWTH_PROMPT_BATCH_CALLS - $calls, null, $calls );
	}
}

/**
 * Returns every stored question, whatever its status, oldest first.
 *
 * The first call after an upgrade moves the single waiting question, and any
 * snoozed or just dismissed one, from the options that held them before the
 * Prompt studio.
 *
 * @return array<int, array{key: string, source: string, post_id: int, posts: int[], question: string, time: int, status: string}> Questions.
 */
function undergrowth_stored_prompts(): array {
	$stored = get_option( 'undergrowth_prompts', null );

	if ( null === $stored ) {
		$stored = undergrowth_migrate_prompts();
	}

	$prompts = array();

	foreach ( (array) $stored as $prompt ) {
		$prompt = undergrowth_clean_stored_prompt( $prompt );

		if ( null !== $prompt ) {
			$prompts[] = $prompt;
		}
	}

	return $prompts;
}

/**
 * Checks the shape of one stored question.
 *
 * @param mixed  $prompt Stored question.
 * @param string $status Status to give a question stored without one.
 * @return array{key: string, source: string, post_id: int, posts: int[], question: string, time: int, status: string}|null The question, or null.
 */
function undergrowth_clean_stored_prompt( $prompt, string $status = 'new' ): ?array {
	if ( ! is_array( $prompt ) || ! is_string( $prompt['key'] ?? null ) || '' === $prompt['key'] || ! is_string( $prompt['question'] ?? null ) || ! is_string( $prompt['source'] ?? null ) ) {
		return null;
	}

	$post_id = (int) ( $prompt['post_id'] ?? 0 );
	$posts   = array_map( 'intval', (array) ( $prompt['posts'] ?? array() ) );
	$status  = is_string( $prompt['status'] ?? null ) ? $prompt['status'] : $status;

	if ( $post_id <= 0 || ! in_array( $status, UNDERGROWTH_PROMPT_STATUSES, true ) ) {
		return null;
	}

	return array(
		'key'      => $prompt['key'],
		'source'   => $prompt['source'],
		'post_id'  => $post_id,
		'posts'    => array_values( array_unique( array_merge( array( $post_id ), array_filter( $posts ) ) ) ),
		'question' => $prompt['question'],
		'time'     => (int) ( $prompt['time'] ?? 0 ),
		'status'   => $status,
	);
}

/**
 * Moves questions from the options used before the Prompt studio.
 *
 * The waiting question and every snoozed one join the batch as new, since
 * there is no snooze any more, and the question dismissed last stays for its
 * Undo. Nothing is written for a site that never had a question.
 *
 * The list is only added when none is stored yet, so a move that started
 * before another request stored the list never overwrites it.
 *
 * @return array<int, array<string, mixed>> The stored questions.
 */
function undergrowth_migrate_prompts(): array {
	global $wpdb;

	$legacy = array(
		'undergrowth_prompt'           => 'new',
		'undergrowth_snoozed_prompts'  => 'new',
		'undergrowth_dismissed_prompt' => 'dismissed',
	);
	$found  = false;
	$moved  = array();
	$keys   = array();

	foreach ( $legacy as $option => $status ) {
		$value = get_option( $option );

		if ( false === $value ) {
			continue;
		}

		$found = true;
		$list  = 'undergrowth_snoozed_prompts' === $option ? (array) $value : array( $value );

		foreach ( $list as $prompt ) {
			$prompt = is_array( $prompt ) ? array_merge( $prompt, array( 'status' => $status ) ) : $prompt;
			$prompt = undergrowth_clean_stored_prompt( $prompt );

			if ( null !== $prompt && ! isset( $keys[ $prompt['key'] ] ) ) {
				$keys[ $prompt['key'] ] = true;
				$moved[]                = $prompt;
			}
		}
	}

	if ( $found ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One row, once per site; add_option() cannot refuse a second writer, and the cache is dropped next.
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, 'undergrowth_prompts', maybe_serialize( $moved ), 'off' ) );
		undergrowth_forget_cached_prompts();

		$stored = get_option( 'undergrowth_prompts' );

		// The old options go only once a list is stored, so a failed write
		// leaves them for the next request to move.
		if ( ! is_array( $stored ) ) {
			return $moved;
		}

		foreach ( array_keys( $legacy ) as $option ) {
			delete_option( $option );
		}

		return $stored;
	}

	return $moved;
}

/**
 * Changes the stored questions as one step.
 *
 * Every write to the list goes through here. The weekly batch, More
 * questions and the author's own choices each read the list, change it and
 * store it, so a short lock makes those three one step: two writers that land
 * at once both keep their change. A writer that finds the lock taken tries
 * again for about a second, then gives up and says so.
 *
 * No request to Anthropic is sent while the lock is held.
 *
 * @param callable $change Takes the stored questions, oldest first, and returns the new list, or null to change nothing.
 * @return bool True when the change was stored.
 */
function undergrowth_change_prompts( callable $change ): bool {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$tries = 1;

	while ( ! WP_Upgrader::create_lock( 'undergrowth_prompts', UNDERGROWTH_PROMPT_LOCK_TIMEOUT ) ) {
		if ( ++$tries > UNDERGROWTH_PROMPT_LOCK_TRIES ) {
			return false;
		}

		usleep( 50000 );
	}

	$stored = false;

	try {
		// The list this request read before it had the lock may be stale.
		undergrowth_forget_cached_prompts();

		$prompts = $change( undergrowth_stored_prompts() );

		if ( null !== $prompts ) {
			$stored = undergrowth_store_prompts( $prompts );
		}
	} finally {
		WP_Upgrader::release_lock( 'undergrowth_prompts' );
	}

	return $stored;
}

/**
 * Drops this request's cached copy of the stored questions, so the next read
 * sees the list as another request may have stored it.
 */
function undergrowth_forget_cached_prompts(): void {
	$notoptions = wp_cache_get( 'notoptions', 'options' );

	if ( is_array( $notoptions ) && isset( $notoptions['undergrowth_prompts'] ) ) {
		unset( $notoptions['undergrowth_prompts'] );
		wp_cache_set( 'notoptions', $notoptions, 'options' );
	}

	wp_cache_delete( 'undergrowth_prompts', 'options' );
}

/**
 * Stores the list of questions, keeping only the latest dismissed ones.
 *
 * Only undergrowth_change_prompts() calls this, with the lock held.
 *
 * @param array<int, array<string, mixed>> $prompts Questions, oldest first.
 * @return bool True when WordPress stored the list.
 */
function undergrowth_store_prompts( array $prompts ): bool {
	$dismissed = 0;

	for ( $i = count( $prompts ) - 1; $i >= 0; $i-- ) {
		if ( 'dismissed' === $prompts[ $i ]['status'] && ++$dismissed > UNDERGROWTH_PROMPT_KEEP_DISMISSED ) {
			unset( $prompts[ $i ] );
		}
	}

	return update_option( 'undergrowth_prompts', array_values( $prompts ), false );
}

/**
 * Returns the questions with one status that the author can still read:
 * questions are on, and the post each is about is still in the index.
 *
 * @param string $status 'new', 'saved' or 'dismissed'.
 * @return array<int, array{key: string, source: string, post_id: int, posts: int[], question: string, time: int, status: string}> Questions, oldest first.
 */
function undergrowth_prompts( string $status = 'new' ): array {
	$prompts = array();

	foreach ( undergrowth_stored_prompts() as $prompt ) {
		if ( $status === $prompt['status'] ) {
			$prompt = undergrowth_readable_prompt( $prompt );

			if ( null !== $prompt ) {
				$prompts[] = $prompt;
			}
		}
	}

	return $prompts;
}

/**
 * Returns the oldest new question, if one is waiting.
 *
 * With no key saved the feature is off, so nothing is waiting.
 *
 * @return array{key: string, source: string, post_id: int, posts: int[], question: string, time: int, status: string}|null The question, or null.
 */
function undergrowth_current_prompt(): ?array {
	return undergrowth_prompts( 'new' )[0] ?? null;
}

/**
 * Returns one readable question by its key, whatever its status.
 *
 * @param string $key Key of the question.
 * @return array{key: string, source: string, post_id: int, posts: int[], question: string, time: int, status: string}|null The question, or null.
 */
function undergrowth_find_prompt( string $key ): ?array {
	foreach ( undergrowth_stored_prompts() as $prompt ) {
		if ( $key === $prompt['key'] ) {
			return undergrowth_readable_prompt( $prompt );
		}
	}

	return null;
}

/**
 * Moves a question from one status to another, and logs the change in its
 * post's record.
 *
 * @param string      $key   Key of the question.
 * @param string      $from  Status it must have now.
 * @param string|null $to    New status, or null to forget it.
 * @param string      $event Event for the post's log.
 * @return bool True when the question had that status and was moved, or false when it did not or the list was busy.
 */
function undergrowth_move_prompt( string $key, string $from, ?string $to, string $event ): bool {
	$prompt = undergrowth_find_prompt( $key );

	if ( null === $prompt || $from !== $prompt['status'] ) {
		return false;
	}

	$changed = undergrowth_change_prompts(
		static function ( array $stored ) use ( $key, $from, $to ): ?array {
			$prompts = array();
			$moved   = null;
			$found   = false;

			foreach ( $stored as $prompt ) {
				if ( $key !== $prompt['key'] ) {
					$prompts[] = $prompt;
					continue;
				}

				// Another writer may have moved it since it was read above.
				if ( $from !== $prompt['status'] ) {
					return null;
				}

				$found = true;

				if ( null !== $to ) {
					// A question moved to the end, so the latest dismissed are
					// kept and the idea bank lists questions in the order they
					// were saved.
					$moved = array_merge( $prompt, array( 'status' => $to ) );
				}
			}

			if ( null !== $moved ) {
				$prompts[] = $moved;
			}

			return $found ? $prompts : null;
		}
	);

	if ( ! $changed ) {
		return false;
	}

	undergrowth_log_prompt( $prompt['post_id'], $event, $prompt );

	return true;
}

/**
 * Dismisses a new question for good.
 *
 * Its source was recorded as sent when the question was written, so the
 * same source never prompts again.
 *
 * @param string $key Key of the question the author chose to dismiss.
 * @return bool True when it was a new question and is gone.
 */
function undergrowth_dismiss_prompt( string $key ): bool {
	return undergrowth_move_prompt( $key, 'new', 'dismissed', 'dismissed' );
}

/**
 * Returns a question dismissed lately, while the Undo can still bring it
 * back.
 *
 * @param string $key Key of the question.
 * @return array{key: string, source: string, post_id: int, posts: int[], question: string, time: int, status: string}|null The question, or null.
 */
function undergrowth_dismissed_prompt( string $key ): ?array {
	$prompt = undergrowth_find_prompt( $key );

	return null !== $prompt && 'dismissed' === $prompt['status'] ? $prompt : null;
}

/**
 * Brings back a dismissed question, from the Undo in the result notice.
 *
 * @param string $key Key of the question to bring back.
 * @return bool True when it is new again.
 */
function undergrowth_restore_prompt( string $key ): bool {
	return undergrowth_move_prompt( $key, 'dismissed', 'new', 'restored' );
}

/**
 * Saves a new question to the idea bank, where it stays until the author
 * removes it.
 *
 * @param string $key Key of the question.
 * @return bool True when it was a new question and is saved.
 */
function undergrowth_save_prompt( string $key ): bool {
	return undergrowth_move_prompt( $key, 'new', 'saved', 'saved' );
}

/**
 * Removes a question from the idea bank for good.
 *
 * @param string $key Key of the question.
 * @return bool True when it was saved and is gone.
 */
function undergrowth_remove_saved_prompt( string $key ): bool {
	return undergrowth_move_prompt( $key, 'saved', null, 'removed' );
}

/**
 * Swaps a new question for another one, written straight away from the
 * next unused source in the garden.
 *
 * The old question goes only once the new one is written, so a failed
 * request or a garden with no source left changes nothing. Only sources
 * the author may answer about are tried, so the new question is theirs.
 *
 * @param string $key Key of the question to replace.
 * @return bool True when a new question took its place.
 */
function undergrowth_replace_prompt( string $key ): bool {
	$prompt = undergrowth_find_prompt( $key );

	if ( null === $prompt || 'new' !== $prompt['status'] || ! undergrowth_write_prompt( 'undergrowth_user_can_answer_prompt' ) ) {
		return false;
	}

	return undergrowth_move_prompt( $key, 'new', 'dismissed', 'replaced' );
}

/**
 * Tells whether the current user may see and answer a question: they can
 * edit every post it came from, since a question may quote any of them.
 *
 * @param array{post_id: int, posts: int[]} $prompt The question.
 * @return bool True when they can edit each of its posts.
 */
function undergrowth_user_can_answer_prompt( array $prompt ): bool {
	foreach ( array_merge( array( $prompt['post_id'] ), $prompt['posts'] ) as $post_id ) {
		if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Logs a newly written prompt in its post's record.
 *
 * @param int                  $post_id Post the prompt is about.
 * @param array<string, mixed> $prompt  The prompt.
 */
function undergrowth_log_written_prompt( int $post_id, array $prompt ): void {
	undergrowth_log_prompt( $post_id, 'asked', $prompt );
}

/**
 * Adds one event to a post's prompt log, which the post's file is written
 * from.
 *
 * Only the date, the event, the source's name and the question are kept.
 * Text from a reader never is.
 *
 * @param int                  $post_id Post the prompt is about.
 * @param string               $event   One of UNDERGROWTH_PROMPT_EVENTS.
 * @param array<string, mixed> $prompt  The prompt.
 */
function undergrowth_log_prompt( int $post_id, string $event, array $prompt ): void {
	add_post_meta(
		$post_id,
		'undergrowth_prompt_log',
		array(
			'time'     => time(),
			'event'    => $event,
			'source'   => (string) ( $prompt['source'] ?? '' ),
			'question' => (string) ( $prompt['question'] ?? '' ),
		)
	);

	// Deleting the post deletes its log, so the site remembers on its own.
	update_option( 'undergrowth_prompt_written', 1, false );

	/**
	 * Fires after an event is added to a post's prompt log.
	 *
	 * @param int $post_id Post ID.
	 */
	do_action( 'undergrowth_prompt_logged', $post_id );
}

/**
 * Returns a post's prompt log, oldest first.
 *
 * @param int $post_id Post ID.
 * @return array<int, array{time: int, event: string, source: string, question: string}> Events.
 */
function undergrowth_prompt_log( int $post_id ): array {
	$log = array();

	foreach ( get_post_meta( $post_id, 'undergrowth_prompt_log', false ) as $row ) {
		if (
			! is_array( $row )
			|| ! isset( $row['time'], $row['event'], $row['source'], $row['question'] )
			|| ! in_array( $row['event'], UNDERGROWTH_PROMPT_EVENTS, true )
			|| ! is_string( $row['source'] )
			|| ! is_string( $row['question'] )
		) {
			continue;
		}

		$log[] = array(
			'time'     => (int) $row['time'],
			'event'    => $row['event'],
			'source'   => $row['source'],
			'question' => $row['question'],
		);
	}

	return $log;
}

/**
 * Returns a stored question while questions are on and its post is still
 * read, with only the source posts that are still in the index.
 *
 * @param array{key: string, source: string, post_id: int, posts: int[], question: string, time: int, status: string} $prompt Stored question.
 * @return array{key: string, source: string, post_id: int, posts: int[], question: string, time: int, status: string}|null The question, or null.
 */
function undergrowth_readable_prompt( array $prompt ): ?array {
	if ( ! undergrowth_prompts_enabled() ) {
		return null;
	}

	$post = get_post( $prompt['post_id'] );

	if ( ! $post instanceof WP_Post || ! undergrowth_is_indexable( $post ) ) {
		return null;
	}

	$prompt['posts'] = array_values(
		array_filter(
			$prompt['posts'],
			static function ( $post_id ) {
				$source = get_post( $post_id );

				return $source instanceof WP_Post && undergrowth_is_indexable( $source );
			}
		)
	);

	return $prompt;
}

/**
 * Tells whether a question has ever been written for the author: one is
 * stored, or one was logged, even on a post since deleted.
 *
 * @return bool True once a question has been written.
 */
function undergrowth_has_written_prompt(): bool {
	return false !== get_option( 'undergrowth_prompt_written' ) || array() !== undergrowth_stored_prompts();
}

/**
 * Tells whether an error from the Messages API is the model's answer rather
 * than a failed request.
 *
 * A refusal, or a reply cut off at the token limit, would come back the same
 * way if the source were sent again.
 *
 * @param WP_Error $error Error from undergrowth_anthropic_message().
 * @return bool True when the request went through and the model answered.
 */
function undergrowth_prompt_reply_is_final( WP_Error $error ): bool {
	return in_array( $error->get_error_code(), array( 'refusal', 'max_tokens' ), true );
}

/**
 * Asks Claude for one more question, trying sources in order, and adds it
 * to the batch as new.
 *
 * @param callable|null $accept Tells whether a source record may be used, or null for any.
 * @return bool True when a prompt was written.
 */
function undergrowth_write_prompt( ?callable $accept = null ): bool {
	return 1 === undergrowth_write_prompts( 1, UNDERGROWTH_PROMPT_MAX_CALLS, $accept );
}

/**
 * Asks Claude for up to UNDERGROWTH_PROMPT_MORE questions straight away,
 * when the author chooses More questions.
 *
 * Each question is kept as soon as it is written, so one paid for is never
 * thrown away when a later request fails. As with Replace, only sources the
 * author may answer about are tried.
 *
 * @return array{written: int, error: array<string, mixed>|null} Questions written, and the failure that stopped the run, if one did.
 */
function undergrowth_write_more_prompts(): array {
	$calls   = 0;
	$failed  = false;
	$written = undergrowth_write_prompts( UNDERGROWTH_PROMPT_MORE, UNDERGROWTH_PROMPT_MORE_CALLS, 'undergrowth_user_can_answer_prompt', $calls, $failed );

	// The failure that ended this run is the last one recorded.
	return array(
		'written' => $written,
		'error'   => $failed ? undergrowth_last_failure( 'undergrowth_last_prompt_error' ) : null,
	);
}

/**
 * Asks Claude for new questions, trying sources in order, and adds each one
 * to the batch as new.
 *
 * Each source is sent at most once, whatever the model answers, so a source
 * it cannot turn into a valid question is not paid for again. A failed
 * request ends the run and the source is tried again next time.
 *
 * Passing $accept limits the run, for instance to the sources an author may
 * answer about, or to one kind of question that holds a place in the batch.
 *
 * @param int           $wanted Questions to write at most.
 * @param int           $limit  Requests to send at most.
 * @param callable|null $accept Tells whether a source record may be used, or null for any.
 * @param int           $calls  Requests sent, counted up here, so a caller can share one budget across runs.
 * @param bool|null     $failed Set to true when a failed request ended the run.
 * @param-out bool       $failed
 * @return int Questions written.
 */
function undergrowth_write_prompts( int $wanted, int $limit, ?callable $accept = null, int &$calls = 0, ?bool &$failed = null ): int {
	$failed = false;

	if ( ! undergrowth_prompts_enabled() ) {
		return 0;
	}

	$limit  += $calls;
	$written = 0;

	foreach ( undergrowth_prompt_candidates() as $record ) {
		if ( $written >= $wanted ) {
			break;
		}

		if ( null !== $accept && ! $accept( $record ) ) {
			continue;
		}

		// A source that needs a check needs two requests. One that would go
		// over the limit waits for the next run without anything being sent.
		if ( $calls + ( empty( $record['rest'] ) ? 1 : 2 ) > $limit ) {
			continue;
		}

		$result = undergrowth_write_prompt_from( $record, $calls );

		if ( 'failed' === $result ) {
			$failed = true;
			break;
		}

		if ( 'written' === $result ) {
			++$written;
		}
	}

	return $written;
}

/**
 * Asks Claude for a prompt from one source, and keeps it when it passes.
 *
 * The source is recorded as sent once its outcome is known. A request that
 * failed is not an outcome, so the source stays for the next run.
 *
 * @param array{source: string, key: string, post_id: int, posts: int[], title: string, quotes: string[], data: string, exact?: bool, rest?: bool} $record Source record.
 * @param int                                                                                                                                      $calls  Requests made so far this run, counted up here.
 * @return string 'written', 'skipped' when this source gave no prompt, or 'failed' when a request failed or the question could not be stored.
 */
function undergrowth_write_prompt_from( array $record, int &$calls ): string {
	++$calls;
	$reply = undergrowth_anthropic_message( undergrowth_prompt_system( undergrowth_prompt_language( $record['post_id'] ) ), undergrowth_prompt_user( $record ) );

	if ( is_wp_error( $reply ) && ! undergrowth_prompt_reply_is_final( $reply ) ) {
		return 'failed';
	}

	$question = is_string( $reply ) ? undergrowth_validate_prompt( $reply, $record ) : null;

	// A post the author chose to rest is never nudged back, so the question
	// is checked once more, on its own, before it is kept.
	if ( null !== $question && ! empty( $record['rest'] ) ) {
		++$calls;
		$rests = undergrowth_prompt_rests( $question );

		if ( is_wp_error( $rests ) ) {
			return 'failed';
		}

		$question = $rests ? $question : null;
	}

	if ( null === $question ) {
		add_post_meta( $record['post_id'], 'undergrowth_prompt_keys', $record['key'] );

		return 'skipped';
	}

	$prompt = array(
		'key'      => $record['key'],
		'source'   => $record['source'],
		'post_id'  => $record['post_id'],
		'posts'    => $record['posts'],
		'question' => $question,
		'time'     => time(),
		'status'   => 'new',
	);

	// A question that cannot be stored fails the run like a failed request.
	// Its source is not recorded as sent, so it is written again next time.
	$stored = undergrowth_change_prompts(
		static function ( array $prompts ) use ( $prompt ): array {
			$prompts[] = $prompt;

			return $prompts;
		}
	);

	if ( ! $stored ) {
		return 'failed';
	}

	add_post_meta( $record['post_id'], 'undergrowth_prompt_keys', $record['key'] );

	/**
	 * Fires after a prompt is written and waiting for the author.
	 *
	 * @param int                  $post_id Post the prompt is about.
	 * @param array<string, mixed> $prompt  The prompt.
	 */
	do_action( 'undergrowth_prompt_written', $record['post_id'], $prompt );

	return 'written';
}

/**
 * Returns the language a question about a post is written in.
 *
 * That is the post's own language when a multilingual plugin gives one
 * through the undergrowth_post_language filter, and otherwise the language
 * its author chose for their dashboard, where the question is shown. Readers'
 * questions on the post are read in the same language. Prompts are written
 * in WP-Cron, so the site's language is used only when there is neither.
 *
 * @param int $post_id Post the question is about.
 * @return string Locale such as fr_FR.
 */
function undergrowth_prompt_language( int $post_id ): string {
	$post = $post_id > 0 ? get_post( $post_id ) : null;

	if ( ! $post instanceof WP_Post ) {
		return get_locale();
	}

	$author        = (int) $post->post_author;
	$author_locale = $author > 0 ? get_user_locale( $author ) : get_locale();

	if ( ! has_filter( 'undergrowth_post_language' ) ) {
		return $author_locale;
	}

	/** This filter is documented in src/indexer.php */
	$locale = apply_filters( 'undergrowth_post_language', get_locale(), $post );

	return is_string( $locale ) && '' !== trim( $locale ) ? sanitize_text_field( $locale ) : $author_locale;
}
