<?php
/**
 * Accept and Veto clicks from Discord, and the link between a Discord user
 * and the WordPress user they act as.
 *
 * A Discord channel can have other members, so a click proves nothing on
 * its own. Only a Discord user the site owner has linked to a WordPress
 * user who can edit the post may change it.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_DISCORD_DEFERRED_UPDATE = 6;
const UNDERGROWTH_DISCORD_UPDATE_MESSAGE  = 7;

// A rate limit on editing the clicked message is waited out once, and only
// when it is this many seconds or shorter.
const UNDERGROWTH_DISCORD_LONGEST_WAIT = 5;

add_action( 'undergrowth_discord_finish_click', 'undergrowth_discord_finish_click', 10, 5 );

add_action( 'show_user_profile', 'undergrowth_render_discord_user_field' );
add_action( 'edit_user_profile', 'undergrowth_render_discord_user_field' );
add_action( 'user_profile_update_errors', 'undergrowth_check_discord_user_field', 10, 3 );
add_action( 'personal_options_update', 'undergrowth_save_discord_user_field' );
add_action( 'edit_user_profile_update', 'undergrowth_save_discord_user_field' );

/**
 * Acts on a click on one of Undergrowth's buttons.
 *
 * Only the button's ID, the clicking user's ID and the interaction's token
 * are read from the payload. Discord gives up on a reply after three
 * seconds, and accepting saves the post, which rewrites files, so the click
 * is answered at once and acted on in WP-Cron, which then edits the message.
 *
 * @param array<string, mixed> $interaction Signed interaction payload.
 * @return WP_REST_Response The reply Discord shows.
 */
function undergrowth_discord_click( array $interaction ): WP_REST_Response {
	$custom_id = $interaction['data']['custom_id'] ?? '';

	if ( ! is_string( $custom_id ) || 1 !== preg_match( '/^undergrowth:(accept|veto):(' . UNDERGROWTH_DISCORD_ID . '):(' . UNDERGROWTH_DISCORD_ID . ')$/D', $custom_id, $button ) ) {
		return undergrowth_discord_ephemeral( __( 'Undergrowth does not know this button, so nothing was changed.', 'undergrowth' ) );
	}

	$source_id = (int) $button[2];
	$target_id = (int) $button[3];
	$user      = undergrowth_discord_linked_user( $interaction['member']['user']['id'] ?? $interaction['user']['id'] ?? '' );

	if ( ! $user instanceof WP_User || ! user_can( $user, 'edit_post', $source_id ) ) {
		return undergrowth_discord_ephemeral( undergrowth_discord_refused_message() );
	}

	// The token lets the job edit the message for 15 minutes. It sits in the
	// cron option only until the job runs.
	$token = $interaction['token'] ?? '';

	if ( ! is_string( $token ) || 1 !== preg_match( '/^[A-Za-z0-9_.-]{1,500}$/D', $token ) ) {
		return undergrowth_discord_ephemeral( __( 'Discord did not say which message to update, so nothing was changed.', 'undergrowth' ) );
	}

	$queued = wp_schedule_single_event( time(), 'undergrowth_discord_finish_click', array( $button[1], $source_id, $target_id, $user->ID, $token ), true );

	if ( true !== $queued ) {
		return undergrowth_discord_ephemeral( __( 'Undergrowth could not queue this click, so nothing was changed. Try again, or use Posts > Undergrowth.', 'undergrowth' ) );
	}

	// WordPress starts WP-Cron at shutdown, once this reply has been sent, so
	// the job never edits the message before Discord has the reply.
	return new WP_REST_Response( array( 'type' => UNDERGROWTH_DISCORD_DEFERRED_UPDATE ) );
}

/**
 * Explains why a click from this Discord user changed nothing.
 *
 * @return string
 */
function undergrowth_discord_refused_message(): string {
	return __( 'Only someone the site owner has linked to a WordPress account that can edit this post can use these buttons, so nothing was changed.', 'undergrowth' );
}

/**
 * Acts on a click in WP-Cron, then updates the message that was clicked.
 *
 * The user is checked again, since they may have lost the right to edit the
 * post since clicking.
 *
 * @param string $action    accept or veto.
 * @param int    $source_id Post the link sits in.
 * @param int    $target_id Post the link points to.
 * @param int    $user_id   WordPress user the clicking Discord user is linked to.
 * @param string $token     Interaction token.
 *
 * @throws Throwable What acting on the click threw, once the clicker is told.
 */
function undergrowth_discord_finish_click( $action, $source_id, $target_id, $user_id, $token ): void {
	$source_id = (int) $source_id;
	$target_id = (int) $target_id;
	$user_id   = (int) $user_id;

	if ( ! user_can( $user_id, 'edit_post', $source_id ) ) {
		undergrowth_discord_answer_click( (string) $token, $source_id, $target_id, undergrowth_discord_ephemeral( undergrowth_discord_refused_message() ) );
		return;
	}

	// Accepting checks the current user's capabilities, such as whether they
	// may save unfiltered HTML, so it runs as the linked user.
	$previous_user = get_current_user_id();
	wp_set_current_user( $user_id );

	try {
		if ( 'accept' === $action ) {
			$result = undergrowth_accept_link( $source_id, $target_id );
		} else {
			$result = undergrowth_veto_result( $source_id, $target_id );
		}
	} catch ( Throwable $e ) {
		// WP-Cron does not run the job again, so the clicker hears of it now
		// and the error still reaches the log.
		undergrowth_discord_answer_click( (string) $token, $source_id, $target_id, undergrowth_discord_ephemeral( __( 'Something went wrong while acting on this click. Check the suggestion on Posts > Undergrowth.', 'undergrowth' ) ) );
		throw $e;
	} finally {
		wp_set_current_user( $previous_user );
	}

	undergrowth_discord_answer_click( (string) $token, $source_id, $target_id, undergrowth_discord_click_reply( $source_id, $target_id, $result ) );
}

/**
 * Sends the answer to a click once it has been acted on.
 *
 * A reply that updates the message edits the one clicked. A private reply
 * goes to the person who clicked as a follow-up. One that fails is recorded
 * as a Discord failure and not sent again, apart from waiting out one short
 * rate limit.
 *
 * @param string           $token     Interaction token.
 * @param int              $source_id Post the link sits in.
 * @param int              $target_id Post the link points to.
 * @param WP_REST_Response $reply     The reply, as it would have been given at once.
 */
function undergrowth_discord_answer_click( string $token, int $source_id, int $target_id, WP_REST_Response $reply ): void {
	$answer = $reply->get_data();
	$edit   = UNDERGROWTH_DISCORD_UPDATE_MESSAGE === $answer['type'];
	$url    = UNDERGROWTH_DISCORD_API . '/webhooks/' . undergrowth_discord_setting( 'application_id' ) . '/' . $token . ( $edit ? '/messages/@original' : '' );
	$args   = array(
		'method'      => $edit ? 'PATCH' : 'POST',
		'headers'     => array(
			'Content-Type' => 'application/json',
			'User-Agent'   => 'DiscordBot (https://github.com/hello-nova-claw/undergrowth, ' . UNDERGROWTH_VERSION . ')',
		),
		'body'        => (string) wp_json_encode( $answer['data'] ),
		'timeout'     => 10,
		'redirection' => 0,
	);

	$response = wp_remote_request( $url, $args );
	$status   = (int) wp_remote_retrieve_response_code( $response );
	$wait     = wp_remote_retrieve_header( $response, 'retry-after' );

	if ( 429 === $status && is_numeric( $wait ) && (float) $wait >= 0 && (float) $wait <= UNDERGROWTH_DISCORD_LONGEST_WAIT ) {
		usleep( (int) ceil( (float) $wait * 1000000 ) );

		$response = wp_remote_request( $url, $args );
		$status   = (int) wp_remote_retrieve_response_code( $response );
	}

	undergrowth_discord_record_result( $source_id . ':' . $target_id, $status >= 200 && $status < 300, $status );
}

/**
 * Builds the reply to a click from what it did.
 *
 * A finished suggestion's message loses its buttons. One that is still open
 * keeps them, and only the person who clicked hears why nothing changed.
 *
 * @param int    $source_id Post the link sits in.
 * @param int    $target_id Post the link points to.
 * @param string $result    What the click did.
 * @return WP_REST_Response The reply, of type 4 to tell only the person who
 *                          clicked, or type 7 to update the message.
 */
function undergrowth_discord_click_reply( int $source_id, int $target_id, string $result ): WP_REST_Response {
	global $wpdb;

	$messages = undergrowth_link_result_messages();
	$message  = $messages[ $result ] ?? $messages['gone'];

	if ( in_array( $result, array( 'edited', 'no_word', 'not_saved', 'needs_admin', 'forbidden' ), true ) ) {
		return undergrowth_discord_ephemeral( $message );
	}

	$source = get_post( $source_id );
	$target = get_post( $target_id );

	if ( ! $source instanceof WP_Post || ! $target instanceof WP_Post ) {
		$data = array(
			'content'          => $message,
			'components'       => array(),
			'allowed_mentions' => array( 'parse' => array() ),
		);
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; the row just changed.
		$sentence = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT sentence FROM %i WHERE source_id = %d AND target_id = %d',
				undergrowth_links_table(),
				$source_id,
				$target_id
			)
		);
		$data     = undergrowth_discord_proposal_message( $source, $target, $sentence, $message );
	}

	return new WP_REST_Response(
		array(
			'type' => UNDERGROWTH_DISCORD_UPDATE_MESSAGE,
			'data' => $data,
		)
	);
}

/**
 * Finds the WordPress user a Discord user is linked to on this site.
 *
 * @param mixed $discord_id Discord user ID from the payload.
 * @return WP_User|null The user, or null when none or more than one is linked.
 */
function undergrowth_discord_linked_user( $discord_id ): ?WP_User {
	if ( ! undergrowth_is_discord_id( $discord_id ) ) {
		return null;
	}

	$users = get_users(
		array(
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs once per click, on a key few users have.
			'meta_key'   => 'undergrowth_discord_user_id',
			'meta_value' => $discord_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'number'     => 2,
		)
	);

	return 1 === count( $users ) && $users[0] instanceof WP_User ? $users[0] : null;
}

/**
 * Prints the Discord user field on a profile screen, for administrators.
 *
 * @param WP_User $user User being edited.
 */
function undergrowth_render_discord_user_field( $user ): void {
	if ( ! current_user_can( 'manage_options' ) || ! $user instanceof WP_User ) {
		return;
	}
	?>
	<h2><?php esc_html_e( 'Undergrowth', 'undergrowth' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<label for="undergrowth_discord_user_id"><?php esc_html_e( 'Discord user ID', 'undergrowth' ); ?></label>
			</th>
			<td>
				<input type="text" class="regular-text code" id="undergrowth_discord_user_id" name="undergrowth_discord_user_id" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, 'undergrowth_discord_user_id', true ) ); ?>" autocomplete="off" spellcheck="false" dir="ltr" aria-describedby="undergrowth-discord-user-description" />
				<p class="description" id="undergrowth-discord-user-description">
					<?php esc_html_e( 'Lets this Discord user press Accept and Veto on Undergrowth’s Discord messages, with this account’s permissions. Only administrators can see or change this. In Discord, turn on Developer Mode, then right-click the user and choose Copy User ID. Leave it blank to unlink.', 'undergrowth' ); ?>
				</p>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Returns the Discord user ID submitted from a profile screen.
 *
 * @return string|null The trimmed ID, or null when the field was not sent.
 */
function undergrowth_submitted_discord_user(): ?string {
	// The profile form's nonce and the edit_user capability are checked by
	// core before its update hooks run.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! isset( $_POST['undergrowth_discord_user_id'] ) ) {
		return null;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only an ID of digits is ever saved; see undergrowth_discord_user_problem().
	$value = wp_unslash( $_POST['undergrowth_discord_user_id'] );

	// Spaces around a pasted ID are trimmed. A line break is left, so the ID
	// is refused rather than quietly mended.
	return is_string( $value ) ? trim( $value, " \t" ) : '';
}

/**
 * Tells why a Discord user ID cannot be linked to a user.
 *
 * @param string $discord_id Discord user ID, or an empty string to unlink.
 * @param int    $user_id    User it would be linked to.
 * @return string Why not, or an empty string when it can be.
 */
function undergrowth_discord_user_problem( string $discord_id, int $user_id ): string {
	if ( '' === $discord_id ) {
		return '';
	}

	if ( ! undergrowth_is_discord_id( $discord_id ) ) {
		return __( 'The Discord user ID should be a long number, so it was not saved.', 'undergrowth' );
	}

	$taken = get_users(
		array(
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs only when an administrator saves a profile.
			'meta_key'   => 'undergrowth_discord_user_id',
			'meta_value' => $discord_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'exclude'    => array( $user_id ),
			'blog_id'    => 0,
			'fields'     => 'ID',
			'number'     => 1,
		)
	);

	return array() === $taken ? '' : __( 'That Discord user is already linked to another account, so it was not saved.', 'undergrowth' );
}

/**
 * Reports a Discord user ID that cannot be saved, on the profile screen.
 *
 * @param WP_Error $errors Profile errors so far.
 * @param bool     $update Whether an existing user is being updated.
 * @param object   $user   User data being saved.
 */
function undergrowth_check_discord_user_field( $errors, $update, $user ): void {
	$discord_id = undergrowth_submitted_discord_user();

	if ( null === $discord_id || ! current_user_can( 'manage_options' ) || ! $errors instanceof WP_Error ) {
		return;
	}

	$problem = undergrowth_discord_user_problem( $discord_id, isset( $user->ID ) ? (int) $user->ID : 0 );

	if ( '' !== $problem ) {
		$errors->add( 'undergrowth_discord_user_id', $problem, array( 'form-field' => 'undergrowth_discord_user_id' ) );
	}
}

/**
 * Saves the Discord user ID from a profile screen, for administrators.
 *
 * @param int $user_id User being saved.
 */
function undergrowth_save_discord_user_field( $user_id ): void {
	$user_id    = (int) $user_id;
	$discord_id = undergrowth_submitted_discord_user();

	if ( null === $discord_id || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}

	if ( '' !== undergrowth_discord_user_problem( $discord_id, $user_id ) ) {
		return;
	}

	if ( '' === $discord_id ) {
		delete_user_meta( $user_id, 'undergrowth_discord_user_id' );
	} else {
		update_user_meta( $user_id, 'undergrowth_discord_user_id', $discord_id );
	}
}
