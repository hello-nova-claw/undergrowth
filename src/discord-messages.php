<?php
/**
 * Posting new link suggestions to the owner's Discord channel, with Accept
 * and Veto buttons.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_DISCORD_API = 'https://discord.com/api/v10';

// Discord refuses a message over 2,000 characters. Titles and the sentence
// start at these lengths and are cut further when a message would not fit.
const UNDERGROWTH_DISCORD_MAX_CONTENT  = 2000;
const UNDERGROWTH_DISCORD_MAX_TITLE    = 150;
const UNDERGROWTH_DISCORD_MAX_SENTENCE = 500;

add_action( 'undergrowth_proposals_refreshed', 'undergrowth_discord_queue_proposals' );
add_action( 'undergrowth_discord_post_proposals', 'undergrowth_discord_post_proposals' );
add_action( 'add_option_undergrowth_discord_channel_id', 'undergrowth_discord_start_posting' );
add_action( 'update_option_undergrowth_discord_channel_id', 'undergrowth_discord_start_posting' );

foreach ( array( 'application_id', 'public_key', 'bot_token', 'channel_id' ) as $undergrowth_discord_field ) {
	add_action( 'add_option_undergrowth_discord_' . $undergrowth_discord_field, 'undergrowth_discord_track_setup' );
	add_action( 'update_option_undergrowth_discord_' . $undergrowth_discord_field, 'undergrowth_discord_track_setup' );
	add_action( 'delete_option_undergrowth_discord_' . $undergrowth_discord_field, 'undergrowth_discord_track_setup' );
}
unset( $undergrowth_discord_field );
add_filter( 'is_protected_meta', 'undergrowth_protect_discord_meta', 10, 2 );

/**
 * Records when the channel was set, so only suggestions made from then on
 * are posted. Setting Discord up on a large site would otherwise post its
 * whole backlog at once.
 */
function undergrowth_discord_start_posting(): void {
	update_option( 'undergrowth_discord_since', current_time( 'mysql', true ), false );
}

/**
 * Forgets when posting started while Discord is not fully set up, and
 * starts again from now once it is, so suggestions made in between are
 * never posted as a backlog.
 */
function undergrowth_discord_track_setup(): void {
	if ( ! undergrowth_discord_enabled() ) {
		delete_option( 'undergrowth_discord_since' );
	} elseif ( '' === (string) get_option( 'undergrowth_discord_since', '' ) ) {
		undergrowth_discord_start_posting();
	}
}

/**
 * Keeps the record of posted messages out of the Custom Fields box.
 *
 * @param bool   $is_protected Whether the key is protected so far.
 * @param string $meta_key     Meta key.
 * @return bool
 */
function undergrowth_protect_discord_meta( $is_protected, $meta_key ) {
	return 'undergrowth_discord_messages' === $meta_key ? true : (bool) $is_protected;
}

/**
 * Schedules posting a post's new suggestions, so a refresh never waits on
 * Discord.
 *
 * @param int $post_id Post whose suggestions were refreshed.
 */
function undergrowth_discord_queue_proposals( int $post_id ): void {
	if ( ! undergrowth_discord_enabled() ) {
		return;
	}

	if ( false === wp_next_scheduled( 'undergrowth_discord_post_proposals', array( $post_id ) ) ) {
		wp_schedule_single_event( time(), 'undergrowth_discord_post_proposals', array( $post_id ) );
	}
}

/**
 * Posts each of a post's suggestions that is not in Discord yet.
 *
 * A refusal that would apply to every message, such as a bad token or a
 * rate limit, stops the rest. Any failure is tried again the
 * next time the post's suggestions are refreshed.
 *
 * @param int $post_id Source post ID.
 */
function undergrowth_discord_post_proposals( int $post_id ): void {
	global $wpdb;

	$source = get_post( $post_id );

	// A post that is no longer published must not have its title sent.
	if ( ! undergrowth_discord_enabled() || ! $source instanceof WP_Post || ! undergrowth_is_indexable( $source ) ) {
		return;
	}

	$since = get_option( 'undergrowth_discord_since', '' );

	if ( ! is_string( $since ) || '' === $since ) {
		undergrowth_discord_start_posting();
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every refresh.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT target_id, sentence, created_at FROM %i WHERE source_id = %d AND state = %s ORDER BY score DESC',
			undergrowth_links_table(),
			$post_id,
			'proposed'
		),
		ARRAY_A
	);

	$open    = array();
	$stopped = false;

	foreach ( (array) $rows as $row ) {
		$target_id          = (int) $row['target_id'];
		$open[ $target_id ] = true;
		$target             = get_post( $target_id );

		// Read again before each send, so a run that overlaps this one and
		// has just posted a suggestion is not followed by a second copy.
		if ( $stopped || isset( undergrowth_discord_posted( $post_id )[ $target_id ] ) || (string) $row['created_at'] < $since || ! $target instanceof WP_Post || ! undergrowth_is_indexable( $target ) ) {
			continue;
		}

		$sent = undergrowth_discord_send(
			'/channels/' . undergrowth_discord_setting( 'channel_id' ) . '/messages',
			array_merge(
				undergrowth_discord_proposal_message( $source, $target, (string) $row['sentence'] ),
				array(
					// Discord drops a repeat with the same nonce, so a retry
					// after a request that timed out does not post twice.
					'nonce'         => substr( md5( 'undergrowth:' . $post_id . ':' . $target_id . ':' . $row['created_at'] ), 0, 25 ),
					'enforce_nonce' => true,
				)
			)
		);

		undergrowth_discord_record_result( $post_id . ':' . $target_id, '' !== $sent['id'], $sent['status'] );

		if ( '' === $sent['id'] ) {
			// One message Discord refuses should not hold back the rest, but a
			// bad token, a channel the bot cannot reach, a rate limit or an
			// outage would refuse every one of them.
			$stopped = in_array( $sent['status'], array( 0, 401, 403, 404, 429 ), true ) || $sent['status'] >= 500;
			continue;
		}

		$posted               = undergrowth_discord_posted( $post_id );
		$posted[ $target_id ] = $sent['id'];
		update_post_meta( $post_id, 'undergrowth_discord_messages', $posted );
	}

	// Only open suggestions are kept, so one withdrawn and later made again
	// is posted again. A withdrawn one no longer counts as failing either.
	$kept = array_intersect_key( undergrowth_discord_posted( $post_id ), $open );

	$error = get_option( 'undergrowth_last_discord_error' );

	if ( is_array( $error ) && isset( $error['pending'] ) && is_array( $error['pending'] ) ) {
		foreach ( array_keys( $error['pending'] ) as $pair ) {
			if ( 0 === strpos( (string) $pair, $post_id . ':' ) && ! isset( $open[ (int) substr( (string) $pair, strlen( $post_id . ':' ) ) ] ) ) {
				undergrowth_discord_record_result( (string) $pair, true );
			}
		}
	}

	if ( array() === $kept ) {
		delete_post_meta( $post_id, 'undergrowth_discord_messages' );
	} else {
		update_post_meta( $post_id, 'undergrowth_discord_messages', $kept );
	}
}

/**
 * Records whether one suggestion reached Discord.
 *
 * The settings page shows the last failure for as long as any suggestion
 * that failed is still waiting, so one success does not hide another
 * suggestion's failure.
 *
 * @param string $pair   Source and target post IDs, as "source:target".
 * @param bool   $posted Whether the suggestion is in Discord now.
 * @param int    $status HTTP status of a failure, or 0 with no response.
 */
function undergrowth_discord_record_result( string $pair, bool $posted, int $status = 0 ): void {
	$error   = get_option( 'undergrowth_last_discord_error' );
	$pending = is_array( $error ) && isset( $error['pending'] ) && is_array( $error['pending'] ) ? $error['pending'] : array();

	if ( $posted ) {
		unset( $pending[ $pair ] );

		if ( array() === $pending ) {
			delete_option( 'undergrowth_last_discord_error' );
			return;
		}

		$error['pending'] = $pending;
		update_option( 'undergrowth_last_discord_error', $error, false );
		return;
	}

	$pending[ $pair ] = true;

	// The status says enough to act on. The response body could echo the
	// request, so it is not kept.
	update_option(
		'undergrowth_last_discord_error',
		array(
			'time'    => time(),
			'status'  => $status,
			'pending' => $pending,
		),
		false
	);
}

/**
 * Returns the suggestions already posted from a post.
 *
 * @param int $post_id Source post ID.
 * @return array<int, string> Message ID keyed by target post ID.
 */
function undergrowth_discord_posted( int $post_id ): array {
	// Without a persistent object cache, a write from another request would
	// otherwise stay hidden behind this request's copy.
	wp_cache_delete( $post_id, 'post_meta' );

	$posted = get_post_meta( $post_id, 'undergrowth_discord_messages', true );

	return is_array( $posted ) ? $posted : array();
}

/**
 * Builds the message for one suggestion.
 *
 * Titles and the sentence are the author's own, but a title can still hold
 * text Discord would read as formatting or a mention, so both are escaped
 * and mentions are switched off.
 *
 * @param WP_Post $source   Post the link would sit in.
 * @param WP_Post $target   Post the link would point to.
 * @param string  $sentence The sentence the link would sit in.
 * @param string  $result   What happened to the suggestion, once handled.
 *                          A handled message has no buttons.
 * @return array<string, mixed> Message fields for the Discord API.
 */
function undergrowth_discord_proposal_message( WP_Post $source, WP_Post $target, string $sentence, string $result = '' ): array {
	$title_max    = UNDERGROWTH_DISCORD_MAX_TITLE;
	$sentence_max = UNDERGROWTH_DISCORD_MAX_SENTENCE;

	// Escaping, long addresses and long translations can all push a message
	// past Discord's limit, so the author's text is cut until it fits.
	do {
		$lines = array(
			sprintf(
				/* translators: 1: title of the post the link would sit in, 2: title of the post it would point to. */
				__( 'Suggested link from “%1$s” to “%2$s”', 'undergrowth' ),
				undergrowth_discord_plain( get_the_title( $source ), $title_max ),
				undergrowth_discord_plain( get_the_title( $target ), $title_max )
			),
			'> ' . undergrowth_discord_plain( $sentence, $sentence_max ),
			/* translators: %s: address of the post the link would sit in. */
			sprintf( __( 'In: %s', 'undergrowth' ), '<' . get_permalink( $source ) . '>' ),
			/* translators: %s: address of the post the link would point to. */
			sprintf( __( 'To: %s', 'undergrowth' ), '<' . get_permalink( $target ) . '>' ),
		);

		if ( '' !== $result ) {
			$lines[] = $result;
		}

		$content      = implode( "\n", $lines );
		$fits         = mb_strlen( $content ) <= UNDERGROWTH_DISCORD_MAX_CONTENT;
		$sentence_max = (int) floor( $sentence_max / 2 );
		$title_max    = $sentence_max < 20 ? (int) floor( $title_max / 2 ) : $title_max;
	} while ( ! $fits && $title_max >= 10 );

	$content = mb_substr( $content, 0, UNDERGROWTH_DISCORD_MAX_CONTENT );

	$components = array();

	if ( '' === $result ) {
		$pair       = $source->ID . ':' . $target->ID;
		$components = array(
			array(
				'type'       => 1,
				'components' => array(
					array(
						'type'      => 2,
						'style'     => 3,
						'label'     => __( 'Accept', 'undergrowth' ),
						'custom_id' => 'undergrowth:accept:' . $pair,
					),
					array(
						'type'      => 2,
						'style'     => 4,
						'label'     => __( 'Veto', 'undergrowth' ),
						'custom_id' => 'undergrowth:veto:' . $pair,
					),
					array(
						'type'  => 2,
						'style' => 5,
						'label' => __( 'Open in WordPress', 'undergrowth' ),
						'url'   => undergrowth_suggestions_url(),
					),
				),
			),
		);
	}

	return array(
		'content'          => $content,
		'components'       => $components,
		'allowed_mentions' => array( 'parse' => array() ),
	);
}

/**
 * Turns text into one line Discord shows exactly as written.
 *
 * @param string $text Text, possibly with markup or entities.
 * @param int    $max  Most characters to keep, before escaping.
 * @return string The text, cut to length, with Discord's formatting
 *                characters escaped.
 */
function undergrowth_discord_plain( string $text, int $max ): string {
	$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

	if ( mb_strlen( $text ) > $max ) {
		$text = rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
	}

	return (string) preg_replace( '/([\\\\*_~`|>#\-\[\]()<])/u', '\\\\$1', $text );
}

/**
 * Sends one message to Discord with the bot token.
 *
 * @param string               $path    API path, starting with a slash.
 * @param array<string, mixed> $message Message fields.
 * @return array{id: string, status: int} ID of the new message, or an empty
 *                                        string when it failed, and the HTTP
 *                                        status, or 0 with no response.
 */
function undergrowth_discord_send( string $path, array $message ): array {
	$response = wp_remote_post(
		UNDERGROWTH_DISCORD_API . $path,
		array(
			'headers'     => array(
				'Authorization' => 'Bot ' . undergrowth_discord_setting( 'bot_token' ),
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'DiscordBot (https://github.com/hello-nova-claw/undergrowth, ' . UNDERGROWTH_VERSION . ')',
			),
			'body'        => (string) wp_json_encode( $message ),
			'timeout'     => 10,
			'redirection' => 0,
		)
	);

	$status = (int) wp_remote_retrieve_response_code( $response );
	$data   = json_decode( wp_remote_retrieve_body( $response ), true );
	$id     = is_array( $data ) && isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '';

	if ( 200 !== $status || 1 !== preg_match( '/^[0-9]{1,20}$/', $id ) ) {
		return array(
			'id'     => '',
			'status' => $status,
		);
	}

	return array(
		'id'     => $id,
		'status' => $status,
	);
}
