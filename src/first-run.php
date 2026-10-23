<?php
/**
 * The first-run path: one notice on the Plugins screen after activation,
 * while no key is saved, and a link on to suggestions once the first key is.
 *
 * The notice never comes back once it is dismissed or a key is saved, and it
 * never counts days. There is no wizard and nothing on any other screen.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_notices', 'undergrowth_render_first_run_notice' );
add_action( 'admin_post_undergrowth_dismiss_first_run', 'undergrowth_dismiss_first_run' );
add_action( 'add_option_undergrowth_openai_api_key', 'undergrowth_first_key_added', 10, 2 );
add_action( 'update_option_undergrowth_openai_api_key', 'undergrowth_first_key_saved', 10, 2 );

/**
 * Tells whether Undergrowth has something to read posts with: a saved key or
 * a provider hooked in through the filters.
 *
 * @return bool
 */
function undergrowth_has_embed_provider(): bool {
	$identity = undergrowth_embed_identity();

	return '' !== $identity['provider'] && '' !== $identity['model'];
}

/**
 * Queues the first-run notice on activation, unless a key is already saved
 * or the owner has been through it before.
 */
function undergrowth_queue_first_run(): void {
	if ( false === get_option( 'undergrowth_first_run' ) && ! undergrowth_has_embed_provider() ) {
		add_option( 'undergrowth_first_run', 'waiting', '', false );
	}
}

/**
 * Ends the first run for good.
 */
function undergrowth_end_first_run(): void {
	update_option( 'undergrowth_first_run', 'done', false );
}

/**
 * Prints the first-run notice on the Plugins screen.
 */
function undergrowth_render_first_run_notice(): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || 'plugins' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( 'waiting' !== get_option( 'undergrowth_first_run' ) ) {
		return;
	}

	if ( undergrowth_has_embed_provider() ) {
		undergrowth_end_first_run();

		return;
	}

	$settings = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( undergrowth_settings_url() ),
		esc_html__( 'Add a key in Settings', 'undergrowth' )
	);
	$dismiss  = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=undergrowth_dismiss_first_run' ), 'undergrowth_dismiss_first_run' ) ),
		esc_html__( 'Dismiss for good', 'undergrowth' )
	);
	?>
	<div id="undergrowth-first-run" class="notice notice-info">
		<p><?php esc_html_e( 'Undergrowth suggests links between your own posts once it has an OpenAI API key to read them with.', 'undergrowth' ); ?></p>
		<p><?php echo wp_kses( $settings . ' | ' . $dismiss, array( 'a' => array( 'href' => array() ) ) ); ?></p>
	</div>
	<?php
}

/**
 * Dismisses the first-run notice for good, then returns to Plugins.
 */
function undergrowth_dismiss_first_run(): void {
	check_admin_referer( 'undergrowth_dismiss_first_run' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You cannot change Undergrowth’s settings.', 'undergrowth' ), '', array( 'response' => 403 ) );
	}

	undergrowth_end_first_run();

	wp_safe_redirect( admin_url( 'plugins.php' ) );
	exit;
}

/**
 * Handles the first save of the OpenAI key, which adds the option.
 *
 * @param string $option Option name.
 * @param mixed  $value  The saved key.
 */
function undergrowth_first_key_added( $option, $value ): void {
	undergrowth_first_key_saved( '', $value );
}

/**
 * Ends the first run once a key is saved, and the first time a key replaces
 * none, links on from the save notice to the suggestions screen.
 *
 * Core shows "Settings saved." only when nothing else was reported, so this
 * message says it too.
 *
 * @param mixed $old_value The key before.
 * @param mixed $value     The key now.
 */
function undergrowth_first_key_saved( $old_value, $value ): void {
	if ( ! is_string( $value ) || '' === $value ) {
		return;
	}

	undergrowth_end_first_run();

	// A key saved outside wp-admin, as from WP-CLI, has no notice to show.
	if ( ( is_string( $old_value ) && '' !== $old_value ) || ! function_exists( 'add_settings_error' ) ) {
		return;
	}

	add_settings_error(
		'undergrowth',
		'undergrowth-first-key',
		sprintf(
			/* translators: %1$s: link to the suggestions screen. */
			esc_html__( 'Settings saved. Undergrowth is now reading your posts, a few at a time. Its suggestions appear on %1$s as they are found.', 'undergrowth' ),
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( undergrowth_suggestions_url() ),
				esc_html__( 'Posts > Undergrowth', 'undergrowth' )
			)
		),
		'success'
	);
}
