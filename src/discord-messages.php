<?php
/**
 * Posting new link suggestions to the owner's Discord channel, with Accept
 * and Veto buttons.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_DISCORD_API = 'https://discord.com/api/v10';

// Discord refuses a message over 2,000 characters. Escaping can double these,
// which still leaves room for the fixed text and both addresses.
const UNDERGROWTH_DISCORD_MAX_TITLE    = 150;
const UNDERGROWTH_DISCORD_MAX_SENTENCE = 500;

add_action( 'undergrowth_proposals_refreshed', 'undergrowth_discord_queue_proposals' );
add_action( 'undergrowth_discord_post_proposals', 'undergrowth_discord_post_proposals' );
add_action( 'add_option_undergrowth_discord_channel_id', 'undergrowth_discord_start_posting' );
add_action( 'update_option_undergrowth_discord_channel_id', 'undergrowth_discord_start_posting' );
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
 * A failed request stops the rest, which are tried again the next time the
 * post's suggestions are refreshed.
 *
 * @param int $post_id Source post ID.
 */
function undergrowth_discord_post_proposals( int $post_id ): void {
	global $wpdb;

	$source = get_post( $post_id );

	if ( ! undergrowth_discord_enabled() || ! $source instanceof WP_Post ) {
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

	$posted = get_post_meta( $post_id, 'undergrowth_discord_messages', true );
	$posted = is_array( $posted ) ? $posted : array();
	$kept   = array();
	$failed = false;

	foreach ( (array) $rows as $row ) {
		$target_id = (int) $row['target_id'];

		if ( isset( $posted[ $target_id ] ) ) {
			$kept[ $target_id ] = $posted[ $target_id ];
			continue;
		}

		$target = get_post( $target_id );

		if ( $failed || (string) $row['created_at'] < $since || ! $target instanceof WP_Post || ! undergrowth_is_indexable( $target ) ) {
			continue;
		}

		$message_id = undergrowth_discord_send(
			'/channels/' . undergrowth_discord_setting( 'channel_id' ) . '/messages',
			undergrowth_discord_proposal_message( $source, $target, (string) $row['sentence'] )
		);

		if ( '' === $message_id ) {
			$failed = true;
			continue;
		}

		$kept[ $target_id ] = $message_id;
	}

	// Only open suggestions are kept, so one withdrawn and later made again
	// is posted again.
	if ( array() === $kept ) {
		delete_post_meta( $post_id, 'undergrowth_discord_messages' );
	} else {
		update_post_meta( $post_id, 'undergrowth_discord_messages', $kept );
	}
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
	$lines = array(
		sprintf(
			/* translators: 1: title of the post the link would sit in, 2: title of the post it would point to. */
			__( 'Suggested link from “%1$s” to “%2$s”', 'undergrowth' ),
			undergrowth_discord_plain( get_the_title( $source ), UNDERGROWTH_DISCORD_MAX_TITLE ),
			undergrowth_discord_plain( get_the_title( $target ), UNDERGROWTH_DISCORD_MAX_TITLE )
		),
		/* translators: %s: address of the post the link would sit in. */
		sprintf( __( 'In: %s', 'undergrowth' ), '<' . get_permalink( $source ) . '>' ),
		/* translators: %s: address of the post the link would point to. */
		sprintf( __( 'To: %s', 'undergrowth' ), '<' . get_permalink( $target ) . '>' ),
	);

	// A vetoed pair the author never saw suggested has no sentence to quote.
	if ( '' !== trim( $sentence ) ) {
		array_splice( $lines, 1, 0, '> ' . undergrowth_discord_plain( $sentence, UNDERGROWTH_DISCORD_MAX_SENTENCE ) );
	}

	$components = array();

	if ( '' !== $result ) {
		$lines[] = $result;
	} else {
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
		'content'          => implode( "\n", $lines ),
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
 * @return string ID of the new message, or an empty string when it failed.
 */
function undergrowth_discord_send( string $path, array $message ): string {
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
		// The status says enough to act on. The response body could echo
		// the request, so it is not kept.
		update_option(
			'undergrowth_last_discord_error',
			array(
				'time'   => time(),
				'status' => $status,
			),
			false
		);
		return '';
	}

	delete_option( 'undergrowth_last_discord_error' );

	return $id;
}
