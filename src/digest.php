<?php
/**
 * The monthly email: the best new link suggestions, once a cycle, to each
 * user who turned it on for themselves.
 *
 * Every word in it is the author's own or the plugin's: the sentence where
 * the link would sit, the posts' titles, and the name of a group on the Map
 * or words both posts use. It links to the Suggestions screen and never
 * accepts anything itself. Nothing is sent when nothing is new. When new
 * questions wait in the Prompt studio, one line says so, once.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_DIGEST_SUGGESTIONS = 5;
const UNDERGROWTH_DIGEST_BATCH       = 50;

// A cycle can end early after a new provider or model is set up, so a second
// email waits until most of a month has gone by.
const UNDERGROWTH_DIGEST_GAP = 20 * DAY_IN_SECONDS;

add_action( 'undergrowth_stage_cycle_finished', 'undergrowth_queue_digests' );
add_action( 'undergrowth_send_digests', 'undergrowth_send_digests' );
add_action( 'show_user_profile', 'undergrowth_render_digest_field', 20 );
add_action( 'personal_options_update', 'undergrowth_save_digest_field' );
add_action( 'admin_post_undergrowth_digest_off', 'undergrowth_handle_digest_off' );
add_action( 'admin_post_nopriv_undergrowth_digest_off', 'undergrowth_handle_digest_off' );
add_action( 'load-posts_page_undergrowth-studio', 'undergrowth_note_studio_visit' );

/**
 * Tells whether a user has turned the monthly email on.
 *
 * @param int $user_id User ID.
 * @return bool True when it is on.
 */
function undergrowth_digest_is_on( int $user_id ): bool {
	return '' !== (string) get_user_meta( $user_id, 'undergrowth_digest', true );
}

/**
 * Returns the secret in a user's link that turns the email off.
 *
 * @param int $user_id User ID.
 * @return string The secret, or an empty string while the email is off.
 */
function undergrowth_digest_key( int $user_id ): string {
	return (string) get_user_meta( $user_id, 'undergrowth_digest_key', true );
}

/**
 * Turns the monthly email on for a user. Only suggestions made from now on
 * are sent.
 *
 * @param int $user_id User ID.
 */
function undergrowth_turn_digest_on( int $user_id ): void {
	if ( undergrowth_digest_is_on( $user_id ) ) {
		return;
	}

	update_user_meta( $user_id, 'undergrowth_digest', (string) time() );
	update_user_meta( $user_id, 'undergrowth_digest_key', wp_generate_password( 32, false ) );
}

/**
 * Turns the monthly email off for a user, and forgets when they last opened
 * the Prompt studio, which is kept only for the email.
 *
 * @param int $user_id User ID.
 */
function undergrowth_turn_digest_off( int $user_id ): void {
	delete_user_meta( $user_id, 'undergrowth_digest' );
	delete_user_meta( $user_id, 'undergrowth_digest_key' );
	delete_user_meta( $user_id, 'undergrowth_digest_sent' );
	delete_user_meta( $user_id, 'undergrowth_studio_seen' );
}

/**
 * Returns the address that turns a user's email off, which works without
 * logging in.
 *
 * @param int $user_id User ID.
 * @return string The address.
 */
function undergrowth_digest_off_url( int $user_id ): string {
	return undergrowth_digest_off_url_for( $user_id, undergrowth_digest_key( $user_id ) );
}

/**
 * Turns a user's email off when the secret from their link matches.
 *
 * @param int    $user_id User ID.
 * @param string $key     Secret from the link.
 * @return bool True when it was turned off.
 */
function undergrowth_digest_off( int $user_id, string $key ): bool {
	$expected = undergrowth_digest_key( $user_id );

	if ( '' === $expected || '' === $key || ! hash_equals( $expected, $key ) ) {
		return false;
	}

	undergrowth_turn_digest_off( $user_id );

	return true;
}

/**
 * Answers the link that turns the email off.
 *
 * Opening the link shows one button, so a mail scanner that follows links
 * turns nothing off. Pressing it, or a mail program's own unsubscribe
 * button, turns the email off at once.
 */
function undergrowth_handle_digest_off(): void {
	// The secret in the link stands in for a nonce, which would expire long
	// before the email is read.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
	$key     = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	// phpcs:enable

	$title = __( 'Undergrowth monthly email', 'undergrowth' );

	if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		$message = undergrowth_digest_off( $user_id, $key ) || ! undergrowth_digest_is_on( $user_id )
			? __( 'The monthly email is off. You can turn it back on from your profile.', 'undergrowth' )
			: __( 'This link no longer works. You can turn the monthly email off from your profile.', 'undergrowth' );

		wp_die( '<p>' . esc_html( $message ) . '</p>', esc_html( $title ), array( 'response' => 200 ) );
	}

	$form = sprintf(
		'<p>%1$s</p><form method="post" action="%2$s"><p><button type="submit" class="button button-primary">%3$s</button></p></form>',
		esc_html__( 'Stop the monthly email of new link suggestions?', 'undergrowth' ),
		esc_url( undergrowth_digest_off_url_for( $user_id, $key ) ),
		esc_html__( 'Turn off the monthly email', 'undergrowth' )
	);

	wp_die( $form, esc_html( $title ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is escaped above.
}

/**
 * Builds the address that turns an email off from the parts of a link.
 *
 * @param int    $user_id User ID.
 * @param string $key     Secret from the link.
 * @return string The address.
 */
function undergrowth_digest_off_url_for( int $user_id, string $key ): string {
	return add_query_arg(
		array(
			'action' => 'undergrowth_digest_off',
			'user'   => $user_id,
			'key'    => $key,
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * Queues the emails once the monthly stage pass has finished, so they are
 * sent in their own cron run.
 */
function undergrowth_queue_digests(): void {
	if ( false === wp_next_scheduled( 'undergrowth_send_digests' ) ) {
		wp_schedule_single_event( time(), 'undergrowth_send_digests' );
	}
}

/**
 * Sends each user who turned the email on the best suggestions made since
 * their last one, when there are any.
 */
function undergrowth_send_digests(): void {
	$users = get_users(
		array(
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs once a month, on a key few users have.
			'meta_key' => 'undergrowth_digest',
			'fields'   => 'all',
		)
	);

	foreach ( $users as $user ) {
		if ( $user instanceof WP_User ) {
			undergrowth_send_digest( $user );
		}
	}
}

/**
 * Sends one user their email, unless they had one this cycle, can no longer
 * see the suggestions, or nothing is new.
 *
 * @param WP_User $user User.
 * @return bool True when an email was sent.
 */
function undergrowth_send_digest( WP_User $user ): bool {
	$sent = (int) get_user_meta( $user->ID, 'undergrowth_digest_sent', true );

	if ( ! undergrowth_digest_is_on( $user->ID ) || ! user_can( $user, 'edit_others_posts' ) || $sent > time() - UNDERGROWTH_DIGEST_GAP ) {
		return false;
	}

	$since = max( $sent, (int) get_user_meta( $user->ID, 'undergrowth_digest', true ) );
	$rows  = undergrowth_digest_suggestions( $since, $user );

	if ( array() === $rows ) {
		return false;
	}

	$switched = switch_to_user_locale( $user->ID );

	$url     = undergrowth_digest_off_url( $user->ID );
	$subject = sprintf(
		/* translators: %s: site title. */
		__( '[%s] Link suggestions worth a look', 'undergrowth' ),
		wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
	);
	$message = undergrowth_digest_message( $rows, $url, undergrowth_digest_has_new_questions( $user, $since ) );

	if ( $switched ) {
		restore_previous_locale();
	}

	$mailed = wp_mail(
		$user->user_email,
		$subject,
		$message,
		array(
			'List-Unsubscribe: <' . $url . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		)
	);

	if ( $mailed ) {
		update_user_meta( $user->ID, 'undergrowth_digest_sent', (string) time() );
	}

	return $mailed;
}

/**
 * Lists the best suggestions still waiting that were made after a time, on
 * posts that still exist and that the user can edit.
 *
 * Rows are filtered before the list is cut to its length, so a suggestion
 * the user cannot act on never takes the place of one they can.
 *
 * @param int     $since Unix time.
 * @param WP_User $user  The user the email is for.
 * @return array<int, array{source_id: int, target_id: int, sentence: string, anchor: string, anchor_offset: int}> Rows, best first.
 */
function undergrowth_digest_suggestions( int $since, WP_User $user ): array {
	global $wpdb;

	$clean  = array();
	$offset = 0;

	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table has no core API.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source_id, target_id, sentence, anchor, anchor_offset FROM %i WHERE state = %s AND created_at > %s ORDER BY score DESC, id ASC LIMIT %d OFFSET %d',
				undergrowth_links_table(),
				'proposed',
				(string) wp_date( 'Y-m-d H:i:s', $since, new DateTimeZone( 'UTC' ) ),
				UNDERGROWTH_DIGEST_BATCH,
				$offset
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$source_id = (int) $row['source_id'];
			$target_id = (int) $row['target_id'];

			if ( ! get_post( $source_id ) instanceof WP_Post || ! get_post( $target_id ) instanceof WP_Post || ! user_can( $user, 'edit_post', $source_id ) ) {
				continue;
			}

			$clean[] = array(
				'source_id'     => $source_id,
				'target_id'     => $target_id,
				'sentence'      => (string) $row['sentence'],
				'anchor'        => (string) $row['anchor'],
				'anchor_offset' => (int) $row['anchor_offset'],
			);

			if ( count( $clean ) === UNDERGROWTH_DIGEST_SUGGESTIONS ) {
				return $clean;
			}
		}

		$offset += UNDERGROWTH_DIGEST_BATCH;
		$more    = count( $rows ) === UNDERGROWTH_DIGEST_BATCH;
	} while ( $more );

	return $clean;
}

/**
 * Remembers when a user who gets the email last opened the Prompt studio,
 * so the email never points them to questions they have already seen there.
 * Nothing is kept for anyone else.
 */
function undergrowth_note_studio_visit(): void {
	$user_id = get_current_user_id();

	if ( $user_id > 0 && undergrowth_digest_is_on( $user_id ) ) {
		update_user_meta( $user_id, 'undergrowth_studio_seen', (string) time() );
	}
}

/**
 * Tells whether a question new to the user waits in the Prompt studio: one
 * written after their last email and their last visit to the studio, about
 * posts they can edit.
 *
 * @param WP_User $user  The user the email is for.
 * @param int     $since Unix time of their last email, or of turning it on.
 * @return bool
 */
function undergrowth_digest_has_new_questions( WP_User $user, int $since ): bool {
	$since = max( $since, (int) get_user_meta( $user->ID, 'undergrowth_studio_seen', true ) );

	foreach ( undergrowth_prompts( 'new' ) as $prompt ) {
		if ( $prompt['time'] <= $since ) {
			continue;
		}

		// As undergrowth_user_can_answer_prompt() asks, for a user who is
		// not the current one: the email is sent from cron.
		$can = true;

		foreach ( array_merge( array( $prompt['post_id'] ), $prompt['posts'] ) as $post_id ) {
			$can = $can && user_can( $user, 'edit_post', (int) $post_id );
		}

		if ( $can ) {
			return true;
		}
	}

	return false;
}

/**
 * Writes the email's plain text.
 *
 * @param array<int, array{source_id: int, target_id: int, sentence: string, anchor: string, anchor_offset: int}> $rows      Suggestions, best first.
 * @param string                                                                                                  $off       Address that turns the email off.
 * @param bool                                                                                                    $questions Whether new questions wait in the Prompt studio.
 * @return string The message.
 */
function undergrowth_digest_message( array $rows, string $off, bool $questions = false ): string {
	$lines = array(
		__( 'These are the strongest link suggestions Undergrowth made since its last email. Nothing changes on your site until you accept one.', 'undergrowth' ),
		'',
	);

	foreach ( array_values( $rows ) as $number => $row ) {
		$source = get_post( $row['source_id'] );
		$target = get_post( $row['target_id'] );

		if ( ! $source instanceof WP_Post || ! $target instanceof WP_Post ) {
			continue;
		}

		$lines[] = sprintf(
			/* translators: 1: number of the suggestion in the list, 2: title of the post the link would point to. */
			__( '%1$s. A link to “%2$s”', 'undergrowth' ),
			number_format_i18n( $number + 1 ),
			undergrowth_plain_line( get_the_title( $target ) )
		);
		$lines[] = '   ' . sprintf(
			/* translators: 1: title of the post the link would sit in, 2: the sentence, with the words to link in square brackets. */
			__( 'In “%1$s”: %2$s', 'undergrowth' ),
			undergrowth_plain_line( get_the_title( $source ) ),
			undergrowth_digest_sentence( $row['sentence'], $row['anchor'], $row['anchor_offset'] )
		);

		$reason = undergrowth_digest_reason( $source, $target, $row['anchor'] );

		if ( '' !== $reason ) {
			$lines[] = '   ' . $reason;
		}

		$lines[] = '';
	}

	$lines[] = __( 'Accept or veto them on Posts > Undergrowth:', 'undergrowth' );
	$lines[] = undergrowth_suggestions_url();
	$lines[] = '';

	if ( $questions ) {
		$lines[] = __( 'New questions are waiting in your Prompt studio:', 'undergrowth' );
		$lines[] = undergrowth_studio_url();
		$lines[] = '';
	}

	$lines[] = __( 'You get this email because you turned it on in your profile. To stop it:', 'undergrowth' );
	$lines[] = $off;

	return implode( "\n", $lines );
}

/**
 * Marks the words to link in a sentence with square brackets.
 *
 * @param string $sentence The sentence.
 * @param string $anchor   The words to link, or an empty string.
 * @param int    $offset   Their character offset in the sentence, or -1.
 * @return string The sentence on one line, with the words marked when they are found.
 */
function undergrowth_digest_sentence( string $sentence, string $anchor, int $offset ): string {
	$length = mb_strlen( $anchor );

	if ( '' === $anchor || $offset < 0 || mb_substr( $sentence, $offset, $length ) !== $anchor ) {
		$found  = '' === $anchor ? false : mb_stripos( $sentence, $anchor );
		$offset = false === $found ? -1 : $found;
	}

	if ( $offset >= 0 ) {
		$sentence = mb_substr( $sentence, 0, $offset ) . '[' . mb_substr( $sentence, $offset, $length ) . ']' . mb_substr( $sentence, $offset + $length );
	}

	return undergrowth_plain_line( $sentence );
}

/**
 * Says why two posts belong together, in the author's own words: the group
 * on the Map they share, or the words both use.
 *
 * @param WP_Post $source Post the link would sit in.
 * @param WP_Post $target Post the link would point to.
 * @param string  $anchor The words to link.
 * @return string The reason, or an empty string when there is none to give.
 */
function undergrowth_digest_reason( WP_Post $source, WP_Post $target, string $anchor ): string {
	$leader = undergrowth_cluster_leader( $source->ID );

	if ( 0 !== $leader && undergrowth_cluster_leader( $target->ID ) === $leader ) {
		return sprintf(
			/* translators: %s: title of the post nearest the middle of the group. */
			__( 'Both posts are in the group around “%s” on the Map.', 'undergrowth' ),
			undergrowth_plain_line( get_the_title( $leader ) )
		);
	}

	if ( '' !== $anchor && false !== mb_stripos( undergrowth_post_text( $target ), $anchor ) ) {
		return sprintf(
			/* translators: %s: words from the sentence that the other post uses too. */
			__( 'Both posts use the words “%s”.', 'undergrowth' ),
			$anchor
		);
	}

	return '';
}

/**
 * Prints the monthly email checkbox on a user's own profile, for users who
 * can see the suggestions.
 *
 * @param WP_User $user User whose profile it is.
 */
function undergrowth_render_digest_field( $user ): void {
	if ( ! $user instanceof WP_User || get_current_user_id() !== $user->ID || ! user_can( $user, 'edit_others_posts' ) ) {
		return;
	}

	// Administrators already have the section's heading, above the Discord field.
	if ( ! current_user_can( 'manage_options' ) ) {
		echo '<h2>' . esc_html__( 'Undergrowth', 'undergrowth' ) . '</h2>';
	}
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Monthly email', 'undergrowth' ); ?></th>
			<td>
				<input type="hidden" name="undergrowth_digest_shown" value="1" />
				<label for="undergrowth_digest">
					<input type="checkbox" id="undergrowth_digest" name="undergrowth_digest" value="1"<?php checked( undergrowth_digest_is_on( $user->ID ) ); ?> aria-describedby="undergrowth-digest-description" />
					<?php esc_html_e( 'Email me the best new link suggestions once a month', 'undergrowth' ); ?>
				</label>
				<p class="description" id="undergrowth-digest-description">
					<?php esc_html_e( 'At most five, after the monthly cycle, and only when there are new ones. Each email has a link to turn it off.', 'undergrowth' ); ?>
				</p>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Saves the monthly email checkbox from a user's own profile.
 *
 * @param int $user_id User being saved.
 */
function undergrowth_save_digest_field( $user_id ): void {
	$user_id = (int) $user_id;

	// The profile form's nonce and the edit_user capability are checked by
	// core before its update hooks run.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( empty( $_POST['undergrowth_digest_shown'] ) || get_current_user_id() !== $user_id || ! user_can( $user_id, 'edit_others_posts' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! empty( $_POST['undergrowth_digest'] ) ) {
		undergrowth_turn_digest_on( $user_id );
	} else {
		undergrowth_turn_digest_off( $user_id );
	}
}
