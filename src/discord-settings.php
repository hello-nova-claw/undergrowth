<?php
/**
 * The Discord section of the settings page: an optional second place to
 * accept or veto link suggestions.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_DISCORD_NAMESPACE = 'undergrowth/v1';
const UNDERGROWTH_DISCORD_ROUTE     = '/discord';
// A Discord ID, or snowflake: a 64-bit number in decimal.
const UNDERGROWTH_DISCORD_ID = '[0-9]{1,20}';

add_action( 'admin_init', 'undergrowth_register_discord_settings' );
add_action( 'add_option_undergrowth_discord_bot_token', 'undergrowth_stop_autoloading_key' );

/**
 * Returns the names of the options that hold the Discord settings.
 *
 * @return string[] Option names.
 */
function undergrowth_discord_options(): array {
	return array(
		'undergrowth_discord_application_id',
		'undergrowth_discord_public_key',
		'undergrowth_discord_bot_token',
		'undergrowth_discord_channel_id',
	);
}

/**
 * Returns one saved Discord setting.
 *
 * @param string $name Setting name without its prefix, such as 'public_key'.
 * @return string The value, or an empty string when none is saved.
 */
function undergrowth_discord_setting( string $name ): string {
	$value = get_option( 'undergrowth_discord_' . $name, '' );

	return is_string( $value ) ? $value : '';
}

/**
 * Tells whether Discord is set up, which it is only once every field is
 * saved. Until then nothing is sent to Discord.
 *
 * @return bool True when every Discord setting is saved.
 */
function undergrowth_discord_enabled(): bool {
	foreach ( undergrowth_discord_options() as $option ) {
		if ( '' === undergrowth_discord_setting( substr( $option, strlen( 'undergrowth_discord_' ) ) ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Returns the address Discord sends button clicks to.
 *
 * @return string The interactions endpoint URL.
 */
function undergrowth_discord_endpoint_url(): string {
	return rest_url( UNDERGROWTH_DISCORD_NAMESPACE . UNDERGROWTH_DISCORD_ROUTE );
}

/**
 * Registers the Discord settings and their section on the settings page.
 */
function undergrowth_register_discord_settings(): void {
	$sanitizers = array(
		'undergrowth_discord_application_id' => 'undergrowth_sanitize_discord_application_id',
		'undergrowth_discord_public_key'     => 'undergrowth_sanitize_discord_public_key',
		'undergrowth_discord_bot_token'      => 'undergrowth_sanitize_discord_bot_token',
		'undergrowth_discord_channel_id'     => 'undergrowth_sanitize_discord_channel_id',
	);

	foreach ( $sanitizers as $option => $sanitize_callback ) {
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

	add_settings_section(
		'undergrowth_discord',
		__( 'Discord (optional)', 'undergrowth' ),
		'undergrowth_render_discord_section',
		'undergrowth'
	);

	$fields = array(
		'application_id' => __( 'Application ID', 'undergrowth' ),
		'public_key'     => __( 'Public key', 'undergrowth' ),
		'bot_token'      => __( 'Bot token', 'undergrowth' ),
		'channel_id'     => __( 'Channel ID for link suggestions', 'undergrowth' ),
	);

	foreach ( $fields as $name => $title ) {
		add_settings_field(
			'undergrowth_discord_' . $name,
			$title,
			'undergrowth_render_discord_field',
			'undergrowth',
			'undergrowth_discord',
			array(
				'label_for' => 'undergrowth_discord_' . $name,
				'name'      => $name,
			)
		);
	}

	add_settings_field(
		'undergrowth_discord_endpoint',
		__( 'Interactions endpoint URL', 'undergrowth' ),
		'undergrowth_render_discord_endpoint',
		'undergrowth',
		'undergrowth_discord'
	);
}

/**
 * Cleans a submitted Discord application ID.
 *
 * @param mixed $value Submitted value.
 * @return string The ID to save.
 */
function undergrowth_sanitize_discord_application_id( $value ): string {
	return undergrowth_sanitize_discord_id(
		$value,
		'undergrowth_discord_application_id',
		__( 'The Discord application ID should be a long number, so the saved one was kept.', 'undergrowth' )
	);
}

/**
 * Cleans a submitted Discord channel ID.
 *
 * @param mixed $value Submitted value.
 * @return string The ID to save.
 */
function undergrowth_sanitize_discord_channel_id( $value ): string {
	return undergrowth_sanitize_discord_id(
		$value,
		'undergrowth_discord_channel_id',
		__( 'The Discord channel ID should be a long number, so the saved one was kept.', 'undergrowth' )
	);
}

/**
 * Cleans a submitted Discord ID, which is a snowflake: a number of up to
 * twenty digits.
 *
 * @param mixed  $value   Submitted value.
 * @param string $option  Option the ID is saved in.
 * @param string $message Error shown when the value is not an ID.
 * @return string The ID to save: the new one, an empty string to clear it,
 *                or the saved one when the new one is not an ID.
 */
function undergrowth_sanitize_discord_id( $value, string $option, string $message ): string {
	$id = is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';

	if ( '' === $id || undergrowth_is_discord_id( $id ) ) {
		return $id;
	}

	add_settings_error( $option, $option, $message );

	return undergrowth_discord_setting( substr( $option, strlen( 'undergrowth_discord_' ) ) );
}

/**
 * Tells whether a value is a Discord ID and nothing else.
 *
 * The D modifier stops $ from matching before a final newline.
 *
 * @param mixed $value Value to check.
 * @return bool True for one to twenty digits.
 */
function undergrowth_is_discord_id( $value ): bool {
	return is_string( $value ) && 1 === preg_match( '/^' . UNDERGROWTH_DISCORD_ID . '$/D', $value );
}

/**
 * Cleans a submitted Discord public key: 64 hexadecimal characters.
 *
 * @param mixed $value Submitted value.
 * @return string The key to save, or the saved one when the new one is not a key.
 */
function undergrowth_sanitize_discord_public_key( $value ): string {
	$key = is_string( $value ) ? strtolower( trim( sanitize_text_field( $value ) ) ) : '';

	if ( '' === $key || 1 === preg_match( '/^[0-9a-f]{64}$/', $key ) ) {
		return $key;
	}

	add_settings_error(
		'undergrowth_discord_public_key',
		'undergrowth_discord_public_key',
		__( 'The Discord public key should be 64 letters and numbers, as shown in the developer portal, so the saved one was kept.', 'undergrowth' )
	);

	return undergrowth_discord_setting( 'public_key' );
}

/**
 * Cleans a submitted Discord bot token, which is kept like an API key: the
 * field is always shown empty, and clearing it takes the remove checkbox.
 *
 * @param mixed $value Submitted value.
 * @return string The token to save.
 */
function undergrowth_sanitize_discord_bot_token( $value ): string {
	return undergrowth_sanitize_api_key( $value, 'undergrowth_remove_discord_token', undergrowth_discord_setting( 'bot_token' ) );
}

/**
 * Prints the one-sentence introduction to the Discord section. What each
 * message sends is in the Discord Help tab.
 */
function undergrowth_render_discord_section(): void {
	$error = get_option( 'undergrowth_last_discord_error' );
	?>
	<p id="undergrowth-discord-notice"><?php esc_html_e( 'Undergrowth can also post each new link suggestion to a private channel in your own Discord server, with Accept and Veto buttons, once every field below is filled in.', 'undergrowth' ); ?></p>

	<?php
	if ( is_array( $error ) && isset( $error['time'] ) ) {
		undergrowth_print_failure_notice( undergrowth_failure_text( 'Discord', $error, __( 'Suggestions not yet posted are tried again the next time they are refreshed.', 'undergrowth' ), 'token' ), 'undergrowth-discord-error' );
	}
}

/**
 * Prints one Discord field, described by one line saying where to find its
 * value, rather than by the whole section introduction.
 *
 * @param array $args Field arguments.
 *
 * @phpstan-param array{label_for: string, name: string} $args
 */
function undergrowth_render_discord_field( array $args ): void {
	$name        = $args['name'];
	$value       = undergrowth_discord_setting( $name );
	$description = 'undergrowth-discord-' . str_replace( '_', '-', $name ) . '-description';
	$where       = array(
		'application_id' => __( 'On your application’s General Information page in the Discord developer portal.', 'undergrowth' ),
		'public_key'     => __( 'On the same General Information page, under the application ID.', 'undergrowth' ),
		'bot_token'      => __( 'On your application’s Bot page: copy the token, or reset it only if you no longer have it.', 'undergrowth' ),
		'channel_id'     => __( 'In Discord, with Developer Mode on, right-click the channel and choose Copy Channel ID.', 'undergrowth' ),
	);

	if ( 'bot_token' !== $name ) {
		printf(
			'<input type="text" class="regular-text code" id="%1$s" name="%1$s" value="%2$s" autocomplete="off" spellcheck="false" dir="ltr" aria-describedby="%3$s" />',
			esc_attr( $args['label_for'] ),
			esc_attr( $value ),
			esc_attr( $description )
		);
		printf( '<p class="description" id="%1$s">%2$s</p>', esc_attr( $description ), esc_html( $where[ $name ] ) );
		return;
	}
	$error = get_option( 'undergrowth_last_discord_error' );

	// The saved token's line and the last failure describe the field too, so
	// a screen reader reads "leave the field blank to keep it" on focus.
	$described = $description
		. ( '' !== $value ? ' undergrowth-discord-bot-token-saved' : '' )
		. ( is_array( $error ) && isset( $error['time'] ) ? ' undergrowth-discord-error' : '' );
	?>
	<input type="password" class="regular-text" id="undergrowth_discord_bot_token" name="undergrowth_discord_bot_token" value="" autocomplete="off" spellcheck="false" aria-describedby="<?php echo esc_attr( $described ); ?>" />
	<p class="description" id="<?php echo esc_attr( $description ); ?>"><?php echo esc_html( $where[ $name ] ); ?></p>

	<?php if ( '' !== $value ) : ?>
		<p class="description" id="undergrowth-discord-bot-token-saved">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %1$s: last four characters of the saved bot token. */
					__( 'A token ending in %1$s is saved. Leave the field blank to keep it.', 'undergrowth' ),
					substr( $value, -4 )
				)
			);
			?>
		</p>
		<p>
			<label>
				<input type="checkbox" name="undergrowth_remove_discord_token" value="1" />
				<?php esc_html_e( 'Remove the saved token and stop sending anything to Discord', 'undergrowth' ); ?>
			</label>
		</p>
	<?php endif; ?>
	<?php
}

/**
 * Prints the interactions endpoint URL the owner pastes into Discord.
 */
function undergrowth_render_discord_endpoint(): void {
	?>
	<p><code id="undergrowth-discord-endpoint" dir="ltr"><?php echo esc_html( undergrowth_discord_endpoint_url() ); ?></code></p>
	<p class="description">
		<?php esc_html_e( 'Paste this into Interactions Endpoint URL on your application’s General Information page in the Discord developer portal. Discord checks the address when you save it there, so save the public key here first. Your site must be reachable from the internet.', 'undergrowth' ); ?>
	</p>
	<?php
}
