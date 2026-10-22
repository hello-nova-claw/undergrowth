<?php
/**
 * The settings page: where the owner enters the API keys for embeddings and
 * for writing prompts.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'undergrowth_add_settings_page' );
add_action( 'admin_init', 'undergrowth_register_settings' );
add_action( 'add_option_undergrowth_openai_api_key', 'undergrowth_stop_autoloading_key' );
add_action( 'add_option_undergrowth_anthropic_api_key', 'undergrowth_stop_autoloading_key' );
add_action( 'admin_enqueue_scripts', 'undergrowth_enqueue_settings_style' );
add_action( 'load-settings_page_undergrowth', 'undergrowth_add_settings_help' );
add_filter( 'plugin_action_links_' . plugin_basename( UNDERGROWTH_FILE ), 'undergrowth_add_settings_link' );

/**
 * Adds Settings > Undergrowth.
 */
function undergrowth_add_settings_page(): void {
	add_options_page(
		__( 'Undergrowth', 'undergrowth' ),
		__( 'Undergrowth', 'undergrowth' ),
		'manage_options',
		'undergrowth',
		'undergrowth_render_settings_page'
	);
}

/**
 * Puts a Settings link first in Undergrowth's row on the Plugins screen,
 * where a new owner looks first after activating.
 *
 * @param string[] $links Action links for the plugin's row.
 * @return string[] The links, with Settings first for users who can use it.
 */
function undergrowth_add_settings_link( $links ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return $links;
	}

	return array_merge(
		array(
			'settings' => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'options-general.php?page=undergrowth' ) ),
				esc_html__( 'Settings', 'undergrowth' )
			),
		),
		(array) $links
	);
}

/**
 * Loads the settings page stylesheet, on that page only.
 *
 * @param string $hook_suffix The current admin page.
 */
function undergrowth_enqueue_settings_style( string $hook_suffix ): void {
	if ( 'settings_page_undergrowth' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_style(
		'undergrowth-settings',
		plugins_url( 'assets/settings.css', UNDERGROWTH_FILE ),
		array(),
		UNDERGROWTH_VERSION
	);
}

/**
 * Registers the API key settings.
 */
function undergrowth_register_settings(): void {
	$keys = array(
		'undergrowth_openai_api_key'    => 'undergrowth_sanitize_openai_key',
		'undergrowth_anthropic_api_key' => 'undergrowth_sanitize_anthropic_key',
	);

	foreach ( $keys as $option => $sanitize_callback ) {
		register_setting(
			'undergrowth',
			$option,
			array(
				'type'              => 'string',
				'default'           => '',
				'show_in_rest'      => false,
				'sanitize_callback' => $sanitize_callback,
			)
		);
	}

	// Discord adds the third section, from discord-settings.php, after these.
	add_settings_section( 'undergrowth_links', __( 'Link suggestions', 'undergrowth' ), 'undergrowth_render_links_section', 'undergrowth' );

	add_settings_field(
		'undergrowth_openai_api_key',
		__( 'OpenAI API key', 'undergrowth' ),
		'undergrowth_render_key_field',
		'undergrowth',
		'undergrowth_links',
		array(
			'label_for' => 'undergrowth_openai_api_key',
			'provider'  => 'openai',
		)
	);

	// Without a provider there is nothing to read with, so no count.
	if ( undergrowth_has_embed_provider() ) {
		add_settings_field( 'undergrowth_posts_read', __( 'Posts read', 'undergrowth' ), 'undergrowth_render_posts_read_field', 'undergrowth', 'undergrowth_links' );
	}

	add_settings_section( 'undergrowth_prompts', __( 'Questions on your Dashboard', 'undergrowth' ), 'undergrowth_render_prompts_section', 'undergrowth' );

	add_settings_field(
		'undergrowth_anthropic_api_key',
		__( 'Anthropic API key', 'undergrowth' ),
		'undergrowth_render_key_field',
		'undergrowth',
		'undergrowth_prompts',
		array(
			'label_for' => 'undergrowth_anthropic_api_key',
			'provider'  => 'anthropic',
		)
	);
}

/**
 * Keeps an API key out of the options loaded on every request.
 *
 * @param string $option Name of the option just added.
 */
function undergrowth_stop_autoloading_key( string $option = 'undergrowth_openai_api_key' ): void {
	wp_set_option_autoload( $option, false );
}

/**
 * Cleans a submitted OpenAI API key.
 *
 * @param mixed $value Submitted value.
 * @return string The key to save.
 */
function undergrowth_sanitize_openai_key( $value ): string {
	return undergrowth_sanitize_api_key( $value, 'undergrowth_remove_openai_key', undergrowth_openai_key() );
}

/**
 * Cleans a submitted Anthropic API key.
 *
 * @param mixed $value Submitted value.
 * @return string The key to save.
 */
function undergrowth_sanitize_anthropic_key( $value ): string {
	return undergrowth_sanitize_api_key( $value, 'undergrowth_remove_anthropic_key', undergrowth_anthropic_key() );
}

/**
 * Cleans a submitted API key.
 *
 * The field is always shown empty so the saved key never reaches the page,
 * which means an empty submission has to keep the saved key rather than
 * clear it. Clearing takes the separate remove checkbox.
 *
 * @param mixed  $value  Submitted value.
 * @param string $remove Name of the key's remove checkbox.
 * @param string $saved  The key saved now.
 * @return string The key to save.
 */
function undergrowth_sanitize_api_key( $value, string $remove, string $saved ): string {
	// options.php has already checked the nonce and capability for this form.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! empty( $_POST[ $remove ] ) ) {
		return '';
	}

	$key = is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';

	return '' === $key ? $saved : $key;
}

/**
 * Prints the settings page.
 */
function undergrowth_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap undergrowth-settings">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p id="undergrowth-intro"><?php esc_html_e( 'Undergrowth suggests links between your own posts with an OpenAI key, and asks you an occasional question about your writing with an Anthropic key.', 'undergrowth' ); ?></p>

		<form action="options.php" method="post">
			<?php settings_fields( 'undergrowth' ); ?>

			<?php do_settings_sections( 'undergrowth' ); ?>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Prints the one-sentence introduction to the link suggestions section.
 */
function undergrowth_render_links_section(): void {
	echo '<p id="undergrowth-links-intro">' . esc_html__( 'Undergrowth reads your published posts and suggests links between them, and changes a post only when you accept a link.', 'undergrowth' ) . '</p>';
}

/**
 * Prints the one-sentence introduction to the Dashboard questions section.
 */
function undergrowth_render_prompts_section(): void {
	echo '<p id="undergrowth-prompts-intro">' . esc_html__( 'Undergrowth asks Claude to write you an occasional question about your own writing, shown only to you, on your Dashboard.', 'undergrowth' ) . '</p>';
}

/**
 * Prints an API key field: always empty, with what is sent in two
 * sentences, a link to where the key comes from, the saved key's last four
 * characters, and the last failed request.
 *
 * The full detail of what is sent is in the section's Help tab.
 *
 * @param array $args Field arguments.
 *
 * @phpstan-param array{label_for: string, provider: 'openai'|'anthropic'} $args
 */
function undergrowth_render_key_field( array $args ): void {
	if ( 'openai' === $args['provider'] ) {
		$key    = undergrowth_openai_key();
		$error  = get_option( 'undergrowth_last_embed_error' );
		$notice = __( 'With a key saved, the title and text of each published post without a password are sent to OpenAI. Nothing is sent until you save a key.', 'undergrowth' );
		$link   = array( 'https://platform.openai.com/api-keys', __( 'Get a key from OpenAI', 'undergrowth' ) );
		$remove = __( 'Remove the saved key and stop sending posts to OpenAI', 'undergrowth' );
		/* translators: 1: date and time, 2: HTTP status code, or 0 when there was no response. */
		$failed = __( 'The last request to OpenAI failed on %1$s (status %2$s). Posts that were not read are tried again within the hour.', 'undergrowth' );
	} else {
		$key    = undergrowth_anthropic_key();
		$error  = get_option( 'undergrowth_last_prompt_error' );
		$notice = __( 'With a key saved, each request sends Anthropic a post’s title and a few sentences quoted from your posts, never a whole post, plus a reader’s comment when the question comes from one. Nothing is sent until you save a key.', 'undergrowth' );
		$link   = array( 'https://platform.claude.com/settings/keys', __( 'Get a key from Anthropic', 'undergrowth' ) );
		$remove = __( 'Remove the saved key and stop sending anything to Anthropic', 'undergrowth' );
		/* translators: 1: date and time, 2: HTTP status code, or 0 when there was no response. */
		$failed = __( 'The last request to Anthropic failed on %1$s (status %2$s). Undergrowth tries again at its next monthly cycle.', 'undergrowth' );
	}

	$provider = $args['provider'];
	?>
	<input type="password" class="regular-text" id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( $args['label_for'] ); ?>" value="" autocomplete="off" spellcheck="false" aria-describedby="undergrowth-<?php echo esc_attr( $provider ); ?>-notice" />

	<p class="description" id="undergrowth-<?php echo esc_attr( $provider ); ?>-notice">
		<?php echo esc_html( $notice ); ?>
		<a id="undergrowth-<?php echo esc_attr( $provider ); ?>-get-key" href="<?php echo esc_url( $link[0] ); ?>"><?php echo esc_html( $link[1] ); ?></a>
	</p>

	<?php if ( '' !== $key ) : ?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: last four characters of the saved API key. */
					__( 'A key ending in %s is saved. Leave the field blank to keep it.', 'undergrowth' ),
					substr( $key, -4 )
				)
			);
			?>
		</p>
		<p>
			<label>
				<input type="checkbox" name="undergrowth_remove_<?php echo esc_attr( $provider ); ?>_key" value="1" />
				<?php echo esc_html( $remove ); ?>
			</label>
		</p>
	<?php endif; ?>

	<?php if ( is_array( $error ) && isset( $error['time'] ) ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				echo esc_html(
					sprintf(
						$failed,
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $error['time'] ),
						number_format_i18n( (int) ( $error['status'] ?? 0 ) )
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>
	<?php
}

/**
 * Prints how many posts have been read so far.
 */
function undergrowth_render_posts_read_field(): void {
	$counts = undergrowth_index_counts();
	?>
	<p id="undergrowth-index-count">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: number of posts read, 2: number of posts that can be read. */
				_n( '%1$s of %2$s post read.', '%1$s of %2$s posts read.', $counts['total'], 'undergrowth' ),
				number_format_i18n( $counts['indexed'] ),
				number_format_i18n( $counts['total'] )
			)
		);
		?>
	</p>
	<?php if ( $counts['indexed'] < $counts['total'] ) : ?>
		<p class="description">
			<?php esc_html_e( 'The rest are read in the background, a few at a time.', 'undergrowth' ); ?>
		</p>
	<?php endif; ?>
	<?php
}

/**
 * Adds Help tabs with the full detail of what each part of the page sends,
 * which the fields themselves keep to two sentences.
 */
function undergrowth_add_settings_help(): void {
	$screen = get_current_screen();

	if ( ! $screen ) {
		return;
	}

	$tabs = array(
		'undergrowth-links'   => array(
			__( 'Link suggestions', 'undergrowth' ),
			array(
				__( 'With a key saved, the title and text of each published post without a password are sent to OpenAI, so Undergrowth can tell which of your posts are about similar things and suggest links between them. Nothing is sent until you save a key. Undergrowth changes a post only when you accept a link in it.', 'undergrowth' ),
			),
		),
		'undergrowth-prompts' => array(
			__( 'Questions', 'undergrowth' ),
			array(
				__( 'With a key saved, Undergrowth asks Claude, through the Anthropic API, to write you an occasional question about your own writing. Each request sends one post’s title, a few sentences quoted from that post and, when the question comes from a reader’s comment, the text of that comment. A question about a gap between your posts quotes one sentence from each of up to three of them instead. Whole posts are never sent. A question about a post you moved to compost is sent back once more, on its own, to check it. Nothing is sent until you save a key. The questions appear only on your Dashboard, never to your readers.', 'undergrowth' ),
			),
		),
		'undergrowth-discord' => array(
			__( 'Discord', 'undergrowth' ),
			array(
				__( 'Optional. Undergrowth can post each new link suggestion to a channel in your own Discord server, with Accept and Veto buttons that work like the ones on Posts > Link suggestions. Each message sends Discord the titles of both posts, the suggested sentence, which is in your own words, and links to both posts. Nothing is sent until every Discord field on this page is filled in. Use a private channel: anyone who can read it sees your suggestions, and nothing is ever posted to your readers.', 'undergrowth' ),
			),
		),
	);

	foreach ( $tabs as $id => $tab ) {
		$screen->add_help_tab(
			array(
				'id'      => $id,
				'title'   => $tab[0],
				'content' => '<p>' . implode( '</p><p>', array_map( 'esc_html', $tab[1] ) ) . '</p>',
			)
		);
	}
}
