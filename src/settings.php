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

	$key             = undergrowth_openai_key();
	$error           = get_option( 'undergrowth_last_embed_error' );
	$anthropic_key   = undergrowth_anthropic_key();
	$anthropic_error = get_option( 'undergrowth_last_prompt_error' );
	?>
	<div class="wrap undergrowth-settings">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<form action="options.php" method="post">
			<?php settings_fields( 'undergrowth' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="undergrowth_openai_api_key"><?php esc_html_e( 'OpenAI API key', 'undergrowth' ); ?></label>
					</th>
					<td>
						<input type="password" class="regular-text" id="undergrowth_openai_api_key" name="undergrowth_openai_api_key" value="" autocomplete="off" spellcheck="false" aria-describedby="undergrowth-openai-notice" />

						<p class="description" id="undergrowth-openai-notice">
							<?php esc_html_e( 'With a key saved, the title and text of each published post are sent to OpenAI to be turned into vectors, which Undergrowth uses to suggest links between your posts. Nothing is sent until you save a key. Undergrowth never writes or changes your posts.', 'undergrowth' ); ?>
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
											__( 'The last request to OpenAI failed on %1$s (status %2$s). Posts are tried again the next time they are saved.', 'undergrowth' ),
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
				<tr>
					<th scope="row">
						<label for="undergrowth_anthropic_api_key"><?php esc_html_e( 'Anthropic API key', 'undergrowth' ); ?></label>
					</th>
					<td>
						<input type="password" class="regular-text" id="undergrowth_anthropic_api_key" name="undergrowth_anthropic_api_key" value="" autocomplete="off" spellcheck="false" aria-describedby="undergrowth-anthropic-notice" />

						<p class="description" id="undergrowth-anthropic-notice">
							<?php esc_html_e( 'With a key saved, Undergrowth asks Claude, through the Anthropic API, to write you an occasional question about your own writing. Each request sends one post’s title, a few sentences quoted from that post and, when the question comes from a reader’s comment, the text of that comment. Whole posts are never sent. A question about a post you moved to compost is sent back once more, on its own, to check it. Nothing is sent until you save a key. The questions appear only on your Dashboard, never to your readers.', 'undergrowth' ); ?>
						</p>

						<?php if ( '' !== $anthropic_key ) : ?>
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

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
