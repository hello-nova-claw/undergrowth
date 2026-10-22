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
 * Returns an API key defined as a constant, usually in wp-config.php, which
 * keeps it out of the database and its backups.
 *
 * @param string $constant Name of the constant.
 * @return string The key, or an empty string when the constant is not a
 *                non-empty string.
 */
function undergrowth_key_from_config( string $constant ): string {
	$key = defined( $constant ) ? constant( $constant ) : '';

	return is_string( $key ) ? trim( $key ) : '';
}

/**
 * Returns an API key saved on the settings page.
 *
 * @param string $option Option name.
 * @return string The key, or an empty string when none is saved.
 */
function undergrowth_saved_key( string $option ): string {
	$key = get_option( $option, '' );

	return is_string( $key ) ? $key : '';
}

/**
 * Cleans a submitted OpenAI API key.
 *
 * @param mixed $value Submitted value.
 * @return string The key to save.
 */
function undergrowth_sanitize_openai_key( $value ): string {
	return undergrowth_sanitize_api_key( $value, 'undergrowth_remove_openai_key', undergrowth_saved_key( 'undergrowth_openai_api_key' ) );
}

/**
 * Cleans a submitted Anthropic API key.
 *
 * @param mixed $value Submitted value.
 * @return string The key to save.
 */
function undergrowth_sanitize_anthropic_key( $value ): string {
	return undergrowth_sanitize_api_key( $value, 'undergrowth_remove_anthropic_key', undergrowth_saved_key( 'undergrowth_anthropic_api_key' ) );
}

/**
 * Cleans a submitted API key.
 *
 * The field is always shown empty so the saved key never reaches the page,
 * which means an empty submission has to keep the saved key rather than
 * clear it. Clearing takes the separate remove checkbox. A field disabled
 * because its key is in wp-config.php is not submitted at all, and keeps
 * the saved key the same way.
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

	$key              = undergrowth_openai_key();
	$key_in_config    = '' !== undergrowth_key_from_config( 'UNDERGROWTH_OPENAI_API_KEY' );
	$error            = get_option( 'undergrowth_last_embed_error' );
	$anthropic_key    = undergrowth_anthropic_key();
	$anthropic_config = '' !== undergrowth_key_from_config( 'UNDERGROWTH_ANTHROPIC_API_KEY' );
	$anthropic_error  = get_option( 'undergrowth_last_prompt_error' );
	$identity         = undergrowth_embed_identity();
	$counts           = '' !== $identity['provider'] && '' !== $identity['model'] ? undergrowth_index_counts() : null;
	?>
	<div class="wrap undergrowth-settings">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p id="undergrowth-intro"><?php esc_html_e( 'Undergrowth suggests links between your own posts with an OpenAI key, and asks you an occasional question about your writing with an Anthropic key.', 'undergrowth' ); ?></p>

		<form action="options.php" method="post">
			<?php settings_fields( 'undergrowth' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="undergrowth_openai_api_key"><?php esc_html_e( 'OpenAI API key', 'undergrowth' ); ?></label>
					</th>
					<td>
						<input type="password" class="regular-text" id="undergrowth_openai_api_key" name="undergrowth_openai_api_key" value="" autocomplete="off" spellcheck="false" aria-describedby="undergrowth-openai-notice<?php echo $key_in_config ? ' undergrowth-openai-config' : ''; ?>"<?php disabled( $key_in_config ); ?> />

						<p class="description" id="undergrowth-openai-notice">
							<?php esc_html_e( 'With a key, the title and text of each published post without a password are sent to OpenAI, so Undergrowth can tell which of your posts are about similar things and suggest links between them. Nothing is sent while there is no key. Undergrowth changes a post only when you accept a link in it.', 'undergrowth' ); ?>
							<a id="undergrowth-openai-get-key" href="https://platform.openai.com/api-keys"><?php esc_html_e( 'Get a key from OpenAI', 'undergrowth' ); ?></a>
						</p>

						<?php if ( $key_in_config ) : ?>
							<p class="description" id="undergrowth-openai-config">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: file name, wp-config.php, 2: name of the constant. */
										__( 'This key is set in %1$s as %2$s, so it is used instead of one saved here. Change or remove it in that file.', 'undergrowth' ),
										'wp-config.php',
										'UNDERGROWTH_OPENAI_API_KEY'
									)
								);
								?>
							</p>
						<?php elseif ( '' !== $key ) : ?>
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
									<input type="checkbox" name="undergrowth_remove_openai_key" value="1" />
									<?php esc_html_e( 'Remove the saved key and stop sending posts to OpenAI', 'undergrowth' ); ?>
								</label>
							</p>
						<?php endif; ?>

						<?php if ( is_array( $error ) && isset( $error['time'] ) ) : ?>
							<div class="notice notice-warning inline">
								<p>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: date and time, 2: HTTP status code, or 0 when there was no response. */
											__( 'The last request to OpenAI failed on %1$s (status %2$s). Posts that were not read are tried again within the hour.', 'undergrowth' ),
											wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $error['time'] ),
											number_format_i18n( (int) ( $error['status'] ?? 0 ) )
										)
									);
									?>
								</p>
							</div>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( null !== $counts ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Posts read', 'undergrowth' ); ?></th>
						<td>
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
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row">
						<label for="undergrowth_anthropic_api_key"><?php esc_html_e( 'Anthropic API key', 'undergrowth' ); ?></label>
					</th>
					<td>
						<input type="password" class="regular-text" id="undergrowth_anthropic_api_key" name="undergrowth_anthropic_api_key" value="" autocomplete="off" spellcheck="false" aria-describedby="undergrowth-anthropic-notice<?php echo $anthropic_config ? ' undergrowth-anthropic-config' : ''; ?>"<?php disabled( $anthropic_config ); ?> />

						<p class="description" id="undergrowth-anthropic-notice">
							<?php esc_html_e( 'With a key, Undergrowth asks Claude, through the Anthropic API, to write you an occasional question about your own writing. Each request sends one post’s title, a few sentences quoted from that post and, when the question comes from a reader’s comment, the text of that comment. A question about a gap between your posts quotes one sentence from each of up to three of them instead. Whole posts are never sent. A question about a post you moved to compost is sent back once more, on its own, to check it. Nothing is sent while there is no key. The questions appear only on your Dashboard, never to your readers.', 'undergrowth' ); ?>
							<a id="undergrowth-anthropic-get-key" href="https://platform.claude.com/settings/keys"><?php esc_html_e( 'Get a key from Anthropic', 'undergrowth' ); ?></a>
						</p>

						<?php if ( $anthropic_config ) : ?>
							<p class="description" id="undergrowth-anthropic-config">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: file name, wp-config.php, 2: name of the constant. */
										__( 'This key is set in %1$s as %2$s, so it is used instead of one saved here. Change or remove it in that file.', 'undergrowth' ),
										'wp-config.php',
										'UNDERGROWTH_ANTHROPIC_API_KEY'
									)
								);
								?>
							</p>
						<?php elseif ( '' !== $anthropic_key ) : ?>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: last four characters of the saved API key. */
										__( 'A key ending in %s is saved. Leave the field blank to keep it.', 'undergrowth' ),
										substr( $anthropic_key, -4 )
									)
								);
								?>
							</p>
							<p>
								<label>
									<input type="checkbox" name="undergrowth_remove_anthropic_key" value="1" />
									<?php esc_html_e( 'Remove the saved key and stop sending anything to Anthropic', 'undergrowth' ); ?>
								</label>
							</p>
						<?php endif; ?>

						<?php if ( is_array( $anthropic_error ) && isset( $anthropic_error['time'] ) ) : ?>
							<div class="notice notice-warning inline">
								<p>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: date and time, 2: HTTP status code, or 0 when there was no response. */
											__( 'The last request to Anthropic failed on %1$s (status %2$s). Undergrowth tries again at its next monthly cycle.', 'undergrowth' ),
											wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $anthropic_error['time'] ),
											number_format_i18n( (int) ( $anthropic_error['status'] ?? 0 ) )
										)
									);
									?>
								</p>
							</div>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php do_settings_sections( 'undergrowth' ); ?>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
