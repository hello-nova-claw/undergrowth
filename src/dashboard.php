<?php
/**
 * The Dashboard widget that shows the author one prompt at a time.
 *
 * There is no list of prompts, no count and no empty state: with nothing
 * waiting, there is no widget at all.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_dashboard_setup', 'undergrowth_add_prompt_widget' );
add_action( 'admin_post_undergrowth_prompt', 'undergrowth_handle_prompt' );
add_action( 'admin_notices', 'undergrowth_render_prompt_notice' );

/**
 * Adds the prompt widget when a prompt is waiting for someone who can edit
 * its post.
 */
function undergrowth_add_prompt_widget(): void {
	$prompt = undergrowth_current_prompt();

	if ( null === $prompt || ! current_user_can( 'edit_post', $prompt['post_id'] ) ) {
		return;
	}

	wp_add_dashboard_widget( 'undergrowth_prompt', __( 'A question for you', 'undergrowth' ), 'undergrowth_render_prompt_widget' );
}

/**
 * Prints the waiting prompt, where it came from, and the two choices.
 */
function undergrowth_render_prompt_widget(): void {
	$prompt = undergrowth_current_prompt();

	if ( null === $prompt || ! current_user_can( 'edit_post', $prompt['post_id'] ) ) {
		return;
	}

	$link = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( (string) get_edit_post_link( $prompt['post_id'], 'raw' ) ),
		esc_html( get_the_title( $prompt['post_id'] ) )
	);

	if ( 'comment' === $prompt['source'] ) {
		/* translators: %1$s: title of the post, linked to its editor. */
		$from = __( 'Claude wrote this question from a reader’s comment on %1$s and your own words there. Only you can see it.', 'undergrowth' );
	} elseif ( 'compost' === $prompt['source'] ) {
		/* translators: %1$s: title of the post, linked to its editor. */
		$from = __( 'Claude wrote this question from %1$s, a post you moved to compost. Only you can see it.', 'undergrowth' );
	} else {
		/* translators: %1$s: title of the post, linked to its editor. */
		$from = __( 'Claude wrote this question from your post %1$s. Only you can see it.', 'undergrowth' );
	}
	?>
	<p><strong><?php echo esc_html( $prompt['question'] ); ?></strong></p>
	<p class="description"><?php echo wp_kses( sprintf( esc_html( $from ), $link ), array( 'a' => array( 'href' => array() ) ) ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="undergrowth_prompt" />
		<input type="hidden" name="key" value="<?php echo esc_attr( $prompt['key'] ); ?>" />
		<?php wp_nonce_field( 'undergrowth_prompt_' . $prompt['key'] ); ?>
		<p>
			<button type="submit" class="button" name="undergrowth_choice" value="snooze"><?php esc_html_e( 'Ask me next month', 'undergrowth' ); ?></button>
			<button type="submit" class="button" name="undergrowth_choice" value="dismiss"><?php esc_html_e( 'Dismiss for good', 'undergrowth' ); ?></button>
		</p>
	</form>
	<?php
}

/**
 * Dismisses or snoozes the prompt posted from the widget, then returns to
 * the Dashboard.
 */
function undergrowth_handle_prompt(): void {
	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';

	check_admin_referer( 'undergrowth_prompt_' . $key );

	$choice = isset( $_POST['undergrowth_choice'] ) ? sanitize_key( wp_unslash( $_POST['undergrowth_choice'] ) ) : '';
	$prompt = undergrowth_current_prompt();
	$url    = admin_url( 'index.php' );

	if ( null !== $prompt && $prompt['key'] === $key && ! current_user_can( 'edit_post', $prompt['post_id'] ) ) {
		wp_die( esc_html__( 'You cannot answer questions about this post.', 'undergrowth' ), '', array( 'response' => 403 ) );
	}

	if ( 'dismiss' === $choice ) {
		$url = add_query_arg( 'undergrowth_notice', undergrowth_dismiss_prompt( $key ) ? 'prompt_dismissed' : 'prompt_gone', $url );
	} elseif ( 'snooze' === $choice ) {
		$url = add_query_arg( 'undergrowth_notice', undergrowth_snooze_prompt( $key ) ? 'prompt_snoozed' : 'prompt_gone', $url );
	}

	wp_safe_redirect( $url );
	exit;
}

/**
 * Prints the notice for the choice that brought the author back to the
 * Dashboard.
 *
 * The notice takes focus, so a keyboard or screen reader user hears the
 * result instead of landing at the top of the page.
 */
function undergrowth_render_prompt_notice(): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || 'dashboard' !== $screen->id ) {
		return;
	}

	// The notice only selects which fixed message to show; nothing is changed.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notice = isset( $_GET['undergrowth_notice'] ) ? sanitize_key( wp_unslash( $_GET['undergrowth_notice'] ) ) : '';

	$messages = array(
		'prompt_dismissed' => __( 'Dismissed. Undergrowth won’t ask that again.', 'undergrowth' ),
		'prompt_snoozed'   => __( 'Snoozed. This question comes back next month.', 'undergrowth' ),
		'prompt_gone'      => __( 'That question is no longer waiting, so nothing was changed.', 'undergrowth' ),
	);

	if ( ! isset( $messages[ $notice ] ) ) {
		return;
	}

	printf(
		'<div id="undergrowth-notice" class="notice %1$s" role="status" tabindex="-1"><p>%2$s</p></div>',
		'prompt_gone' === $notice ? 'notice-warning' : 'notice-success',
		esc_html( $messages[ $notice ] )
	);

	wp_print_inline_script_tag( "document.getElementById( 'undergrowth-notice' ).focus();" );
}
