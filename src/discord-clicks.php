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

const UNDERGROWTH_DISCORD_UPDATE_MESSAGE = 7;

add_action( 'show_user_profile', 'undergrowth_render_discord_user_field' );
add_action( 'edit_user_profile', 'undergrowth_render_discord_user_field' );
add_action( 'user_profile_update_errors', 'undergrowth_check_discord_user_field', 10, 3 );
add_action( 'personal_options_update', 'undergrowth_save_discord_user_field' );
add_action( 'edit_user_profile_update', 'undergrowth_save_discord_user_field' );

/**
 * Acts on a click on one of Undergrowth's buttons.
 *
 * Only the button's ID and the clicking user's ID are read from the
 * payload. The message shown afterwards is rebuilt from the database.
 *
 * @param array<string, mixed> $interaction Signed interaction payload.
 * @return WP_REST_Response The reply Discord shows.
 */
function undergrowth_discord_click( array $interaction ): WP_REST_Response {
	$custom_id = $interaction['data']['custom_id'] ?? '';

	if ( ! is_string( $custom_id ) || 1 !== preg_match( '/^undergrowth:(accept|veto):([0-9]{1,20}):([0-9]{1,20})$/D', $custom_id, $button ) ) {
		return undergrowth_discord_ephemeral( __( 'Undergrowth does not know this button, so nothing was changed.', 'undergrowth' ) );
	}

	$source_id = (int) $button[2];
	$target_id = (int) $button[3];
	$user      = undergrowth_discord_linked_user( $interaction['member']['user']['id'] ?? $interaction['user']['id'] ?? '' );

	if ( ! $user instanceof WP_User || ! user_can( $user, 'edit_post', $source_id ) ) {
		return undergrowth_discord_ephemeral( __( 'Only someone the site owner has linked to a WordPress account that can edit this post can use these buttons, so nothing was changed.', 'undergrowth' ) );
	}

	// Accepting checks the current user's capabilities, such as whether they
	// may save unfiltered HTML, so it runs as the linked user.
	$previous_user = get_current_user_id();
	wp_set_current_user( $user->ID );

	if ( 'accept' === $button[1] ) {
		$result = undergrowth_accept_link( $source_id, $target_id );
	} elseif ( undergrowth_veto_link( $source_id, $target_id ) ) {
		$result = 'vetoed';
	} else {
		$result = 'accepted' === undergrowth_link_state( $source_id, $target_id ) ? 'unchanged' : 'gone';
	}

	wp_set_current_user( $previous_user );

	return undergrowth_discord_click_reply( $source_id, $target_id, $result );
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
 * @return WP_REST_Response
 */
function undergrowth_discord_click_reply( int $source_id, int $target_id, string $result ): WP_REST_Response {
	global $wpdb;

	$messages = array(
		'accepted'     => __( 'Linked. One word in your sentence now links to the suggested post.', 'undergrowth' ),
		'vetoed'       => __( 'Done. This link won’t be suggested again.', 'undergrowth' ),
		'unchanged'    => __( 'That link was already accepted, so it was left as it is.', 'undergrowth' ),
		'gone'         => __( 'One of those posts no longer exists or is no longer published, so nothing was changed.', 'undergrowth' ),
		'not_proposed' => __( 'That suggestion is no longer on the list, so nothing was changed.', 'undergrowth' ),
		'edited'       => __( 'That sentence has changed since the link was suggested, so nothing was changed. You can add the link by hand.', 'undergrowth' ),
		'no_word'      => __( 'No word in that sentence could be linked safely, so nothing was changed. You can add the link by hand.', 'undergrowth' ),
		'needs_admin'  => __( 'Saving this post would also remove content that only someone allowed to post unfiltered HTML can save, such as an embed, so nothing was changed. Someone with that permission can accept this link.', 'undergrowth' ),
	);
	$message  = $messages[ $result ] ?? $messages['gone'];

	if ( in_array( $result, array( 'edited', 'no_word', 'needs_admin' ), true ) ) {
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
	if ( ! is_string( $discord_id ) || 1 !== preg_match( '/^[0-9]{1,20}$/', $discord_id ) ) {
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

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	return trim( sanitize_text_field( wp_unslash( $_POST['undergrowth_discord_user_id'] ) ) );
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

	if ( 1 !== preg_match( '/^[0-9]{1,20}$/', $discord_id ) ) {
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
