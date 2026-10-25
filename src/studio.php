<?php
/**
 * Posts › Prompt studio: the questions Claude wrote from the author's own
 * posts, to dismiss, replace or save, and the idea bank of saved ones.
 *
 * Only the author sees a question. Nothing here is ever shown to readers.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'undergrowth_add_studio_page' );
add_action( 'admin_post_undergrowth_studio', 'undergrowth_handle_studio' );
add_action( 'admin_post_undergrowth_restore_prompt', 'undergrowth_handle_restore_prompt' );
add_action( 'load-posts_page_undergrowth-studio', 'undergrowth_add_studio_help' );
add_filter( 'removable_query_args', 'undergrowth_studio_removable_query_args' );

/**
 * Adds Posts › Prompt studio.
 */
function undergrowth_add_studio_page(): void {
	add_posts_page(
		__( 'Prompt studio', 'undergrowth' ),
		__( 'Prompt studio', 'undergrowth' ),
		'edit_posts',
		'undergrowth-studio',
		'undergrowth_render_studio_page'
	);
}

/**
 * Returns the address of the Prompt studio.
 *
 * @param string $view   '' for new questions, 'saved' for the idea bank.
 * @param string $notice Result to show there, if any.
 * @param string $key    Key of the question the result is about, if any.
 * @return string Admin URL.
 */
function undergrowth_studio_url( string $view = '', string $notice = '', string $key = '' ): string {
	$args = array( 'page' => 'undergrowth-studio' );

	if ( 'saved' === $view ) {
		$args['view'] = 'saved';
	}

	if ( '' !== $notice ) {
		$args['undergrowth_notice'] = $notice;
	}

	if ( '' !== $key ) {
		$args['undergrowth_prompt'] = rawurlencode( $key );
	}

	return add_query_arg( $args, admin_url( 'edit.php' ) );
}

/**
 * Lets core remove the question named by a result from the address once the
 * result is shown, as it does the result itself.
 *
 * @param string[] $args Removable argument names.
 * @return string[] Argument names.
 */
function undergrowth_studio_removable_query_args( $args ) {
	$args   = (array) $args;
	$args[] = 'undergrowth_prompt';

	return $args;
}

/**
 * Returns the questions with one status that the current user may answer,
 * because they can edit the post each is about.
 *
 * @param string $status 'new' or 'saved'.
 * @return array<int, array{key: string, source: string, post_id: int, posts: int[], question: string, time: int, status: string}> Questions.
 */
function undergrowth_user_prompts( string $status ): array {
	return array_values(
		array_filter(
			undergrowth_prompts( $status ),
			static function ( $prompt ) {
				return current_user_can( 'edit_post', $prompt['post_id'] );
			}
		)
	);
}

/**
 * Prints the Prompt studio: the new questions, or the idea bank.
 */
function undergrowth_render_studio_page(): void {
	// The view only selects what to show; nothing is changed.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$view  = isset( $_GET['view'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['view'] ) ) ? 'saved' : '';
	$fresh = undergrowth_user_prompts( 'new' );
	$saved = undergrowth_user_prompts( 'saved' );
	$tabs  = array(
		/* translators: %s: number of new questions. */
		''      => sprintf( __( 'New questions (%s)', 'undergrowth' ), number_format_i18n( count( $fresh ) ) ),
		/* translators: %s: number of saved questions. */
		'saved' => sprintf( __( 'Idea bank (%s)', 'undergrowth' ), number_format_i18n( count( $saved ) ) ),
	);
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<?php
		if ( undergrowth_prompts_enabled() ) {
			undergrowth_render_studio( $view, $tabs, $fresh, $saved );
		} else {
			undergrowth_render_studio_off();
		}
		?>
	</div>
	<?php
}

/**
 * Prints why the Prompt studio is off, with the way to Settings for a user
 * who can save the key.
 */
function undergrowth_render_studio_off(): void {
	echo '<p>' . esc_html__( 'The Prompt studio is off until an Anthropic API key is saved in Settings › Undergrowth. Then Claude writes questions for you from your own posts.', 'undergrowth' ) . '</p>';

	if ( current_user_can( 'manage_options' ) ) {
		echo '<p><a href="' . esc_url( undergrowth_settings_url() ) . '">' . esc_html__( 'Open Settings › Undergrowth', 'undergrowth' ) . '</a></p>';
	}
}

/**
 * Prints the studio's tabs, its result notice and the chosen view.
 *
 * @param string                           $view  '' or 'saved'.
 * @param array<string, string>            $tabs  Tab labels keyed by view.
 * @param array<int, array<string, mixed>> $fresh New questions.
 * @param array<int, array<string, mixed>> $saved Saved questions.
 */
function undergrowth_render_studio( string $view, array $tabs, array $fresh, array $saved ): void {
	?>
	<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Prompt studio', 'undergrowth' ); ?>">
		<?php
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( undergrowth_studio_url( $key ) ),
				$key === $view ? ' nav-tab-active' : '',
				$key === $view ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		?>
	</nav>

	<?php undergrowth_render_studio_notice(); ?>

	<p class="description"><?php esc_html_e( 'Claude writes each question from your own posts. Only you can see them. Nothing here is ever shown to readers.', 'undergrowth' ); ?></p>
	<?php
	if ( 'saved' === $view ) {
		undergrowth_render_idea_bank( $saved );
	} else {
		undergrowth_render_new_prompts( $fresh );
	}
}

/**
 * Prints the new questions, each with Save, Replace and Dismiss.
 *
 * @param array<int, array<string, mixed>> $prompts New questions.
 */
function undergrowth_render_new_prompts( array $prompts ): void {
	if ( array() === $prompts ) {
		echo '<p>' . esc_html__( 'No new questions right now. Undergrowth writes another from your posts each month, and the questions you saved are in the idea bank.', 'undergrowth' ) . '</p>';
		return;
	}

	echo '<p>' . esc_html__( 'Replace asks Claude for a different question straight away, using this site’s Anthropic API key.', 'undergrowth' ) . '</p>';
	echo '<ul class="undergrowth-questions">';

	foreach ( $prompts as $prompt ) {
		undergrowth_render_studio_prompt(
			$prompt,
			array(
				'save'    => __( 'Save to idea bank', 'undergrowth' ),
				'replace' => __( 'Replace', 'undergrowth' ),
				'dismiss' => __( 'Dismiss', 'undergrowth' ),
			)
		);
	}

	echo '</ul>';
}

/**
 * Prints the idea bank: every saved question, with the posts it came from.
 *
 * @param array<int, array<string, mixed>> $prompts Saved questions.
 */
function undergrowth_render_idea_bank( array $prompts ): void {
	if ( array() === $prompts ) {
		echo '<p>' . esc_html__( 'Your idea bank is empty. Save a new question to keep it here for as long as you like.', 'undergrowth' ) . '</p>';
		return;
	}

	echo '<ul class="undergrowth-questions">';

	foreach ( $prompts as $prompt ) {
		undergrowth_render_studio_prompt( $prompt, array( 'remove' => __( 'Remove from idea bank', 'undergrowth' ) ) );
	}

	echo '</ul>';
}

/**
 * Prints one question, where it came from, and its buttons.
 *
 * @param array{key: string, source: string, post_id: int, posts: int[], question: string, time: int, status: string} $prompt  The question.
 * @param array<string, string>                                                                                       $choices Button labels keyed by choice.
 */
function undergrowth_render_studio_prompt( array $prompt, array $choices ): void {
	$id      = 'undergrowth-question-' . substr( md5( $prompt['key'] ), 0, 12 );
	$links   = implode( wp_get_list_item_separator(), array_map( 'undergrowth_post_link', $prompt['posts'] ) );
	$allowed = array(
		'a'   => array( 'href' => array() ),
		'bdi' => array(),
	);
	?>
	<li class="card">
		<p id="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $prompt['question'] ); ?></strong></p>
		<p class="description"><?php echo wp_kses( sprintf( esc_html( undergrowth_prompt_source_text( $prompt['source'] ) ), $links ), $allowed ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="undergrowth_studio" />
			<input type="hidden" name="key" value="<?php echo esc_attr( $prompt['key'] ); ?>" />
			<?php wp_nonce_field( 'undergrowth_studio_' . $prompt['key'] ); ?>
			<p>
				<?php foreach ( $choices as $choice => $label ) : ?>
					<button type="submit" class="button" name="undergrowth_choice" value="<?php echo esc_attr( $choice ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></button>
				<?php endforeach; ?>
			</p>
		</form>
	</li>
	<?php
}

/**
 * Returns the line that says where a question came from.
 *
 * @param string $source Source of the question.
 * @return string Text with %1$s for the linked titles of its posts.
 */
function undergrowth_prompt_source_text( string $source ): string {
	if ( 'comment' === $source ) {
		/* translators: %1$s: title of the post, linked to its editor. */
		return __( 'From a reader’s comment on %1$s, and your own words there.', 'undergrowth' );
	}

	if ( 'compost' === $source ) {
		/* translators: %1$s: title of the post, linked to its editor. */
		return __( 'From %1$s, a post you moved to compost.', 'undergrowth' );
	}

	if ( 'gap' === $source ) {
		/* translators: %1$s: titles of the posts, each linked to its editor, separated by commas. */
		return __( 'From a gap among your posts: %1$s.', 'undergrowth' );
	}

	/* translators: %1$s: titles of the posts, each linked to its editor, separated by commas. */
	return __( 'From your posts: %1$s.', 'undergrowth' );
}

/**
 * Acts on the choice posted from the Prompt studio, then returns there.
 */
function undergrowth_handle_studio(): void {
	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';

	check_admin_referer( 'undergrowth_studio_' . $key );

	$choice = isset( $_POST['undergrowth_choice'] ) ? sanitize_key( wp_unslash( $_POST['undergrowth_choice'] ) ) : '';
	$prompt = undergrowth_find_prompt( $key );

	if ( null !== $prompt && ! current_user_can( 'edit_post', $prompt['post_id'] ) ) {
		wp_die( esc_html__( 'You cannot answer questions about this post.', 'undergrowth' ), '', array( 'response' => 403 ) );
	}

	$actions = array(
		'dismiss' => array( 'undergrowth_dismiss_prompt', 'prompt_dismissed', '' ),
		'save'    => array( 'undergrowth_save_prompt', 'prompt_saved', '' ),
		'replace' => array( 'undergrowth_replace_prompt', 'prompt_replaced', '' ),
		'remove'  => array( 'undergrowth_remove_saved_prompt', 'prompt_removed', 'saved' ),
	);

	$url = undergrowth_studio_url();

	if ( isset( $actions[ $choice ] ) ) {
		list( $callback, $notice, $view ) = $actions[ $choice ];

		if ( ! $callback( $key ) ) {
			// Replace keeps the question when no other could be written.
			$notice = 'replace' === $choice && null !== $prompt && 'new' === $prompt['status'] ? 'prompt_not_replaced' : 'prompt_gone';
		}

		$url = undergrowth_studio_url( $view, $notice, 'prompt_dismissed' === $notice ? $key : '' );
	}

	wp_safe_redirect( $url );
	exit;
}

/**
 * Brings back the question named by the Undo link in the dismissed notice,
 * then returns to the Prompt studio.
 */
function undergrowth_handle_restore_prompt(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- check_admin_referer() runs before anything changes.
	$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

	check_admin_referer( 'undergrowth_restore_prompt_' . $key );

	$prompt = undergrowth_dismissed_prompt( $key );

	if ( null !== $prompt && ! current_user_can( 'edit_post', $prompt['post_id'] ) ) {
		wp_die( esc_html__( 'You cannot answer questions about this post.', 'undergrowth' ), '', array( 'response' => 403 ) );
	}

	wp_safe_redirect( undergrowth_studio_url( '', undergrowth_restore_prompt( $key ) ? 'prompt_restored' : 'prompt_gone' ) );
	exit;
}

/**
 * Builds the Undo link for a question just dismissed, for a user who may
 * answer it.
 *
 * @param string $key Key of the dismissed question.
 * @return string Escaped link, or an empty string when it cannot come back.
 */
function undergrowth_restore_prompt_link_html( string $key ): string {
	$prompt = undergrowth_dismissed_prompt( $key );

	if ( null === $prompt || ! current_user_can( 'edit_post', $prompt['post_id'] ) ) {
		return '';
	}

	$url = add_query_arg(
		array(
			'action' => 'undergrowth_restore_prompt',
			'key'    => rawurlencode( $prompt['key'] ),
		),
		admin_url( 'admin-post.php' )
	);

	return sprintf(
		' <a href="%1$s">%2$s</a>',
		esc_url( wp_nonce_url( $url, 'undergrowth_restore_prompt_' . $prompt['key'] ) ),
		esc_html__( 'Undo', 'undergrowth' )
	);
}

/**
 * Prints the notice for the choice that brought the author back to the
 * Prompt studio.
 *
 * The notice takes focus, so a keyboard or screen reader user hears the
 * result instead of landing at the top of the page.
 */
function undergrowth_render_studio_notice(): void {
	// The notice only selects which fixed message to show; nothing is changed.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$notice = isset( $_GET['undergrowth_notice'] ) ? sanitize_key( wp_unslash( $_GET['undergrowth_notice'] ) ) : '';
	$key    = isset( $_GET['undergrowth_prompt'] ) ? sanitize_text_field( wp_unslash( $_GET['undergrowth_prompt'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$messages = array(
		'prompt_dismissed'    => __( 'Dismissed. Undergrowth won’t ask that again.', 'undergrowth' ),
		'prompt_saved'        => __( 'Saved to your idea bank.', 'undergrowth' ),
		'prompt_replaced'     => __( 'Replaced. The new question is at the end of the list.', 'undergrowth' ),
		'prompt_removed'      => __( 'Removed from your idea bank.', 'undergrowth' ),
		'prompt_restored'     => __( 'Undone. The question is back.', 'undergrowth' ),
		'prompt_gone'         => __( 'That question is no longer here, so nothing was changed.', 'undergrowth' ),
		'prompt_not_replaced' => __( 'Claude could not write a different question just now, so this one stays. Try again later.', 'undergrowth' ),
	);

	if ( ! isset( $messages[ $notice ] ) ) {
		return;
	}

	$undo  = 'prompt_dismissed' === $notice ? undergrowth_restore_prompt_link_html( $key ) : '';
	$class = in_array( $notice, array( 'prompt_gone', 'prompt_not_replaced' ), true ) ? 'notice-warning' : 'notice-success';

	undergrowth_print_result_notices( array( $class => '<p>' . esc_html( $messages[ $notice ] ) . $undo . '</p>' ) );
}

/**
 * Adds the Help tab on the Prompt studio.
 */
function undergrowth_add_studio_help(): void {
	undergrowth_add_help_tab(
		'undergrowth-studio',
		__( 'Prompt studio', 'undergrowth' ),
		array(
			__( 'Claude writes each question from your own posts: a gap among them, a post you moved to compost, or a reader’s question in a comment. It only ever asks; it never writes a draft, a title or an outline, and it quotes nobody but you.', 'undergrowth' ),
			__( 'Save keeps a question in your idea bank, with the posts it came from, until you remove it. Dismiss removes it for good, and its notice has an Undo. Replace asks Claude for a different question straight away, using this site’s Anthropic API key.', 'undergrowth' ),
		)
	);
}
