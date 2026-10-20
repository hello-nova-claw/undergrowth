<?php
/**
 * The link suggestions screen: where the author sees proposals and acts on them.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'undergrowth_add_suggestions_page' );
add_action( 'admin_post_undergrowth_veto', 'undergrowth_handle_veto' );
add_action( 'admin_post_undergrowth_toggle', 'undergrowth_handle_toggle' );

/**
 * Adds Posts > Link suggestions.
 */
function undergrowth_add_suggestions_page(): void {
	add_posts_page(
		__( 'Link suggestions', 'undergrowth' ),
		__( 'Link suggestions', 'undergrowth' ),
		'edit_others_posts',
		'undergrowth-links',
		'undergrowth_render_suggestions_page'
	);
}

/**
 * Loads the proposals table class.
 *
 * It extends core's WP_List_Table, which belongs to wp-admin, so it is only
 * loaded when the screen is drawn rather than on every request.
 */
function undergrowth_load_list_table(): void {
	require_once __DIR__ . '/class-undergrowth-links-list-table.php';
}

/**
 * Returns the address of the link suggestions screen.
 *
 * @param string $notice Notice to show on arrival, or an empty string.
 * @return string Admin URL.
 */
function undergrowth_suggestions_url( string $notice = '' ): string {
	// The screen is registered under plain edit.php; adding post_type=post
	// makes WordPress look for it under a different parent and refuse it.
	$url = admin_url( 'edit.php?page=undergrowth-links' );

	return '' === $notice ? $url : add_query_arg( 'undergrowth_notice', $notice, $url );
}

/**
 * Vetoes the link posted from a row's form, then returns to the screen.
 */
function undergrowth_handle_veto(): void {
	$source = isset( $_POST['source'] ) ? absint( wp_unslash( $_POST['source'] ) ) : 0;
	$target = isset( $_POST['target'] ) ? absint( wp_unslash( $_POST['target'] ) ) : 0;

	check_admin_referer( 'undergrowth_veto_' . $source . '_' . $target );

	if ( ! current_user_can( 'edit_post', $source ) ) {
		wp_die( esc_html__( 'You cannot change links in this post.', 'undergrowth' ), '', array( 'response' => 403 ) );
	}

	$vetoed = undergrowth_veto_link( $source, $target );

	wp_safe_redirect( undergrowth_suggestions_url( $vetoed ? 'vetoed' : 'unchanged' ) );
	exit;
}

/**
 * Saves the "Suggest links between my posts" choice, then returns to the screen.
 */
function undergrowth_handle_toggle(): void {
	check_admin_referer( 'undergrowth_toggle' );

	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'You cannot change this setting.', 'undergrowth' ), '', array( 'response' => 403 ) );
	}

	update_option( 'undergrowth_suggest_links', empty( $_POST['undergrowth_suggest_links'] ) ? '0' : '1' );

	wp_safe_redirect( undergrowth_suggestions_url( 'saved' ) );
	exit;
}

/**
 * Prints the link suggestions screen.
 */
function undergrowth_render_suggestions_page(): void {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}

	$identity = undergrowth_embed_identity();
	$enabled  = undergrowth_suggestions_enabled();
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<?php undergrowth_render_suggestions_notice(); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="undergrowth_toggle" />
			<?php wp_nonce_field( 'undergrowth_toggle' ); ?>
			<p>
				<label>
					<input type="checkbox" name="undergrowth_suggest_links" value="1" <?php checked( $enabled ); ?> />
					<?php esc_html_e( 'Suggest links between my posts', 'undergrowth' ); ?>
				</label>
				<?php submit_button( __( 'Save', 'undergrowth' ), 'secondary', 'submit', false ); ?>
			</p>
		</form>

		<?php
		if ( '' === $identity['provider'] || '' === $identity['model'] ) {
			echo '<p>' . wp_kses(
				sprintf(
					/* translators: %s: address of the Undergrowth settings page. */
					__( 'Link suggestions need an embedding provider. <a href="%s">Add an API key in Settings</a> to start.', 'undergrowth' ),
					esc_url( admin_url( 'options-general.php?page=undergrowth' ) )
				),
				array( 'a' => array( 'href' => array() ) )
			) . '</p>';
		} elseif ( ! $enabled ) {
			echo '<p>' . esc_html__( 'Suggestions are off. Existing suggestions and vetoes are kept, and nothing new is suggested until you turn them back on.', 'undergrowth' ) . '</p>';
		} else {
			undergrowth_load_list_table();
			$table = new Undergrowth_Links_List_Table();
			$table->prepare_items();

			if ( ! $table->has_items() ) {
				echo '<p>' . esc_html__( 'No link suggestions yet. They appear here once your published posts have been read, which happens a moment after each post is saved.', 'undergrowth' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Each suggestion is a link from one of your posts to another, at a sentence you wrote. Nothing changes in your posts until you accept a suggestion.', 'undergrowth' ) . '</p>';
				$table->display();
			}
		}
		?>
	</div>
	<?php
}

/**
 * Prints the notice for the action that brought the user back to the screen.
 *
 * The notice takes focus, so a keyboard or screen reader user hears the
 * result instead of landing at the top of the page.
 */
function undergrowth_render_suggestions_notice(): void {
	// The notice only selects which fixed message to show; nothing is changed.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notice = isset( $_GET['undergrowth_notice'] ) ? sanitize_key( wp_unslash( $_GET['undergrowth_notice'] ) ) : '';

	$messages = array(
		'vetoed'    => __( 'Done. This link won’t be suggested again.', 'undergrowth' ),
		'saved'     => __( 'Your choice is saved.', 'undergrowth' ),
		'unchanged' => __( 'That link was already accepted, so it was left as it is.', 'undergrowth' ),
	);

	if ( ! isset( $messages[ $notice ] ) ) {
		return;
	}

	printf(
		'<div id="undergrowth-notice" class="notice notice-success" role="status" tabindex="-1"><p>%s</p></div>',
		esc_html( $messages[ $notice ] )
	);

	wp_print_inline_script_tag( "document.getElementById( 'undergrowth-notice' ).focus();" );
}
