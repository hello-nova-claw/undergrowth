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
				esc_url( undergrowth_settings_url() ),
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

	register_setting(
		'undergrowth',
		'undergrowth_suggest_links',
		array(
			'type'              => 'string',
			'default'           => '1',
			'show_in_rest'      => false,
			'sanitize_callback' => 'undergrowth_sanitize_suggest_links',
		)
	);

	register_setting(
		'undergrowth',
		'undergrowth_post_types',
		array(
			'type'              => 'array',
			'default'           => array( 'post' ),
			'show_in_rest'      => false,
			'sanitize_callback' => 'undergrowth_sanitize_post_types',
		)
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

	// With a provider set up by code there is no key to enter, so the row
	// says why instead of offering a field.
	if ( undergrowth_embed_provider_set_by_code() ) {
		add_settings_field( 'undergrowth_openai_api_key', __( 'OpenAI API key', 'undergrowth' ), 'undergrowth_render_unused_openai_key_field', 'undergrowth', 'undergrowth_links' );
	} else {
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
	}

	// Without a provider there is nothing to read with, so no count, and a
	// switch that could not do anything yet.
	if ( undergrowth_has_embed_provider() ) {
		add_settings_field( 'undergrowth_posts_read', __( 'Posts read', 'undergrowth' ), 'undergrowth_render_posts_read_field', 'undergrowth', 'undergrowth_links' );
		add_settings_field( 'undergrowth_suggest_links', __( 'Suggestions', 'undergrowth' ), 'undergrowth_render_suggest_links_field', 'undergrowth', 'undergrowth_links' );
	}

	add_settings_field( 'undergrowth_post_types', __( 'Post types', 'undergrowth' ), 'undergrowth_render_post_types_field', 'undergrowth', 'undergrowth_links' );
	// A failed save on Accept is the only thing this row ever says, so it is
	// there only while there is one to report.
	if ( null !== undergrowth_last_failure( 'undergrowth_last_accept_error' ) ) {
		add_settings_field( 'undergrowth_accept_error', __( 'Accept', 'undergrowth' ), 'undergrowth_render_accept_error_field', 'undergrowth', 'undergrowth_links' );
	}

	add_settings_field( 'undergrowth_memory_folder', __( 'Per-post files', 'undergrowth' ), 'undergrowth_render_memory_folder_field', 'undergrowth', 'undergrowth_links' );

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
function undergrowth_stop_autoloading_key( string $option ): void {
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
 * Cleans the submitted "Suggest links between my posts" choice.
 *
 * An unticked box sends nothing, which reads as off. The switch is only on
 * the page once there is a provider, so a save without it keeps the saved
 * choice rather than turning suggestions off.
 *
 * @param mixed $value Submitted value.
 * @return string '1' for on, '0' for off.
 */
function undergrowth_sanitize_suggest_links( $value ): string {
	// options.php has already checked the nonce and capability for this form.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( empty( $_POST['undergrowth_suggest_links_shown'] ) ) {
		return undergrowth_suggestions_enabled() ? '1' : '0';
	}

	return '1' === $value ? '1' : '0';
}

/**
 * Lists the post types the author can choose: the public ones, apart from
 * media.
 *
 * @return array<string, WP_Post_Type> Post type objects, by name.
 */
function undergrowth_selectable_post_types(): array {
	$post_types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $post_types['attachment'] );

	return $post_types;
}

/**
 * Cleans the submitted post types.
 *
 * Saving with none ticked keeps the ones chosen before, and says so, since
 * an empty choice would take every post out of the index.
 *
 * @param mixed $value Submitted value.
 * @return string[] The post types to save.
 */
function undergrowth_sanitize_post_types( $value ): array {
	$saved = undergrowth_saved_post_types();

	// options.php has already checked the nonce and capability for this form.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( empty( $_POST['undergrowth_post_types_shown'] ) ) {
		return $saved;
	}

	$submitted = array_filter( (array) $value, 'is_string' );
	$chosen    = array_values( array_intersect( array_keys( undergrowth_selectable_post_types() ), $submitted ) );

	if ( array() !== $chosen ) {
		return $chosen;
	}

	// Core runs this twice when the option is first added.
	if ( array() === get_settings_errors( 'undergrowth_post_types' ) ) {
		add_settings_error( 'undergrowth_post_types', 'undergrowth_post_types', __( 'Choose at least one post type for Undergrowth to read. The ones chosen before are kept.', 'undergrowth' ) );
	}

	return $saved;
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
	?>
	<div class="wrap undergrowth-settings">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p id="undergrowth-intro">
			<?php
			if ( undergrowth_embed_provider_set_by_code() ) {
				esc_html_e( 'Undergrowth suggests links between your own posts, and asks you an occasional question about your writing with an Anthropic key.', 'undergrowth' );
			} else {
				esc_html_e( 'Undergrowth suggests links between your own posts with an OpenAI key, and asks you an occasional question about your writing with an Anthropic key.', 'undergrowth' );
			}
			?>
		</p>

		<form action="options.php" method="post">
			<?php settings_fields( 'undergrowth' ); ?>

			<?php do_settings_sections( 'undergrowth' ); ?>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Prints the one-sentence introduction to the link suggestions section and,
 * when code on the site reads the posts, which model that is and what it is
 * given.
 */
function undergrowth_render_links_section(): void {
	echo '<p id="undergrowth-links-intro">' . esc_html__( 'Undergrowth reads your published posts and suggests links between them, and changes a post only when you accept a link.', 'undergrowth' ) . '</p>';

	if ( ! undergrowth_embed_provider_set_by_code() ) {
		return;
	}

	$identity = undergrowth_embed_identity();

	// Model names are code, so they keep left-to-right order in any language.
	echo '<p id="undergrowth-links-provider">' . sprintf(
		/* translators: 1: model name, such as nomic-embed-text, 2: provider name, such as ollama. */
		esc_html__( 'Posts are read by %1$s (%2$s), set up by code on this site. Nothing is sent to OpenAI. That code is given the title and text of each published post without a password. Where it sends them depends on how it was set up, so ask whoever added it.', 'undergrowth' ),
		'<bdi dir="ltr">' . esc_html( $identity['model'] ) . '</bdi>',
		'<bdi dir="ltr">' . esc_html( $identity['provider'] ) . '</bdi>'
	) . '</p>';
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
		$error  = undergrowth_last_failure( 'undergrowth_last_embed_error' );
		$notice = __( 'With a key, the title and text of each published post without a password are sent to OpenAI. Nothing is sent while there is no key.', 'undergrowth' );
		$link   = array( 'https://platform.openai.com/api-keys', __( 'Get a key from OpenAI', 'undergrowth' ) );
		$remove = __( 'Remove the saved key and stop sending posts to OpenAI', 'undergrowth' );
		$wait   = undergrowth_openai_retry_time();
		$retry  = 0 !== $wait
			? sprintf(
				/* translators: %1$s: date and time. */
				__( 'Undergrowth waits until %1$s, then sends the posts that were not read.', 'undergrowth' ),
				undergrowth_date_time( $wait )
			)
			: __( 'Posts that were not read are tried again within the hour.', 'undergrowth' );
	} else {
		$key    = undergrowth_anthropic_key();
		$error  = undergrowth_last_failure( 'undergrowth_last_prompt_error' );
		$notice = __( 'With a key, each request sends Anthropic a post’s title and a few sentences quoted from your posts, never a whole post, plus a reader’s comment when the question comes from one. Nothing is sent while there is no key.', 'undergrowth' );
		$link   = array( 'https://platform.claude.com/settings/keys', __( 'Get a key from Anthropic', 'undergrowth' ) );
		$remove = __( 'Remove the saved key and stop sending anything to Anthropic', 'undergrowth' );
		$retry  = __( 'Undergrowth tries again at its next monthly cycle.', 'undergrowth' );
	}

	$provider  = $args['provider'];
	$constant  = 'openai' === $provider ? 'UNDERGROWTH_OPENAI_API_KEY' : 'UNDERGROWTH_ANTHROPIC_API_KEY';
	$in_config = '' !== undergrowth_key_from_config( $constant );
	$failed    = null !== $error;

	// Everything said about the field below it, so a screen reader reads
	// "leave the field blank to keep it" and the last failure on focus.
	$described = array( 'notice' );

	if ( $in_config ) {
		$described[] = 'config';
	} elseif ( '' !== $key ) {
		$described[] = 'saved';
	}

	if ( $failed ) {
		$described[] = 'error';
	}

	$described = implode(
		' ',
		array_map(
			static function ( string $part ) use ( $provider ): string {
				return 'undergrowth-' . $provider . '-' . $part;
			},
			$described
		)
	);
	?>
	<input type="password" class="regular-text" id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( $args['label_for'] ); ?>" value="" autocomplete="off" spellcheck="false" aria-describedby="<?php echo esc_attr( $described ); ?>"<?php disabled( $in_config ); ?> />

	<p class="description" id="undergrowth-<?php echo esc_attr( $provider ); ?>-notice">
		<?php echo esc_html( $notice ); ?>
		<a id="undergrowth-<?php echo esc_attr( $provider ); ?>-get-key" href="<?php echo esc_url( $link[0] ); ?>"><?php echo esc_html( $link[1] ); ?></a>
	</p>

	<?php if ( $in_config ) : ?>
		<p class="description" id="undergrowth-<?php echo esc_attr( $provider ); ?>-config">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: file name, wp-config.php, 2: name of the constant. */
					__( 'This key is set in %1$s as %2$s, so it is used instead of one saved here. Change or remove it in that file.', 'undergrowth' ),
					'wp-config.php',
					$constant
				)
			);
			?>
		</p>
	<?php elseif ( '' !== $key ) : ?>
		<p class="description" id="undergrowth-<?php echo esc_attr( $provider ); ?>-saved">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %1$s: last four characters of the saved API key. */
					__( 'A key ending in %1$s is saved. Leave the field blank to keep it.', 'undergrowth' ),
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

	<?php if ( 'anthropic' === $provider && '' !== $key && ! undergrowth_has_written_prompt() ) : ?>
		<p class="description" id="undergrowth-anthropic-no-question">
			<?php esc_html_e( 'No question yet. Undergrowth asks one when a reader asks a question in an approved comment, a gap appears on the Map, or you move a post to compost.', 'undergrowth' ); ?>
		</p>
	<?php endif; ?>

	<?php
	if ( $failed ) {
		undergrowth_print_failure_notice( undergrowth_failure_text( 'openai' === $provider ? 'OpenAI' : 'Anthropic', $error, $retry ), 'undergrowth-' . $provider . '-error' );
	}
}

/**
 * Prints the OpenAI key row while code on the site reads the posts: no
 * field, since the key would not be used, and a saved key said to be
 * ignored, with its remove checkbox.
 */
function undergrowth_render_unused_openai_key_field(): void {
	$constant  = 'UNDERGROWTH_OPENAI_API_KEY';
	$saved     = undergrowth_saved_key( 'undergrowth_openai_api_key' );
	$in_config = '' !== undergrowth_key_from_config( $constant );

	// A key saved before the constant was added can still be removed here.
	if ( $in_config && '' !== $saved ) {
		$text = sprintf(
			/* translators: 1: file name, wp-config.php, 2: name of the constant, 3: last four characters of the saved API key. */
			__( 'A key is set in %1$s as %2$s, and a key ending in %3$s is saved, but both are ignored while code on this site reads the posts.', 'undergrowth' ),
			'wp-config.php',
			$constant,
			substr( $saved, -4 )
		);
	} elseif ( $in_config ) {
		$text = sprintf(
			/* translators: 1: file name, wp-config.php, 2: name of the constant. */
			__( 'A key is set in %1$s as %2$s, but it is ignored while code on this site reads the posts.', 'undergrowth' ),
			'wp-config.php',
			$constant
		);
	} elseif ( '' !== $saved ) {
		$text = sprintf(
			/* translators: %1$s: last four characters of the saved API key. */
			__( 'A key ending in %1$s is saved, but it is ignored while code on this site reads the posts.', 'undergrowth' ),
			substr( $saved, -4 )
		);
	} else {
		$text = __( 'Not used. Code on this site reads the posts, so no OpenAI key is needed.', 'undergrowth' );
	}
	?>
	<p id="undergrowth-openai-unused"><?php echo esc_html( $text ); ?></p>
	<?php if ( '' !== $saved ) : ?>
		<p>
			<label>
				<input type="checkbox" name="undergrowth_remove_openai_key" value="1" />
				<?php esc_html_e( 'Remove the saved key', 'undergrowth' ); ?>
			</label>
		</p>
	<?php endif; ?>
	<?php
}

/**
 * Prints the "Suggest links between my posts" switch, with a hidden marker
 * that tells the sanitiser the switch was on the page, so an unticked box
 * reads as off rather than as a switch left out because there is no provider.
 */
function undergrowth_render_suggest_links_field(): void {
	?>
	<input type="hidden" name="undergrowth_suggest_links_shown" value="1" />
	<label for="undergrowth_suggest_links">
		<input type="checkbox" id="undergrowth_suggest_links" name="undergrowth_suggest_links" value="1"<?php checked( undergrowth_suggestions_enabled() ); ?> />
		<?php esc_html_e( 'Suggest links between my posts', 'undergrowth' ); ?>
	</label>
	<p class="description"><?php esc_html_e( 'Turning this off keeps your suggestions and vetoes, and suggests nothing new until you turn it back on.', 'undergrowth' ); ?></p>
	<?php
}

/**
 * Prints a checkbox for each post type the author can choose. While code
 * chooses the post types, the boxes show its choice and cannot be changed.
 */
function undergrowth_render_post_types_field(): void {
	$by_code = has_filter( 'undergrowth_post_types' );
	$indexed = undergrowth_indexed_post_types();
	?>
	<fieldset>
		<legend class="screen-reader-text"><?php esc_html_e( 'Post types', 'undergrowth' ); ?></legend>
		<?php if ( ! $by_code ) : ?>
			<input type="hidden" name="undergrowth_post_types_shown" value="1" />
		<?php endif; ?>
		<?php foreach ( undergrowth_selectable_post_types() as $name => $post_type ) : ?>
			<label for="undergrowth_post_type_<?php echo esc_attr( $name ); ?>">
				<input type="checkbox" id="undergrowth_post_type_<?php echo esc_attr( $name ); ?>" name="undergrowth_post_types[]" value="<?php echo esc_attr( $name ); ?>"<?php checked( in_array( $name, $indexed, true ) ); ?><?php disabled( $by_code ); ?> />
				<?php echo esc_html( $post_type->labels->name ); ?>
			</label><br />
		<?php endforeach; ?>
	</fieldset>
	<p class="description">
		<?php
		if ( $by_code ) {
			esc_html_e( 'Code on this site chooses which post types are read, so these boxes cannot be changed here.', 'undergrowth' );
		} else {
			esc_html_e( 'Undergrowth reads the published posts of each type ticked here, and suggests links among them. Unticking a type takes its posts out of suggestions, growth stages and the Map.', 'undergrowth' );
		}
		?>
	</p>
	<?php
}

/**
 * Records the last failure of one kind, so Settings › Undergrowth can say
 * what went wrong.
 *
 * Only the error code, HTTP status and time are kept. Keys, post text and
 * response bodies never reach the record.
 *
 * @param string $option Option that holds the record, such as 'undergrowth_last_embed_error'.
 * @param string $code   Error code.
 * @param int    $status HTTP status, or 0 when there was no request or no response.
 */
function undergrowth_record_failure( string $option, string $code, int $status = 0 ): void {
	update_option(
		$option,
		array(
			'code'   => sanitize_key( $code ),
			'status' => $status,
			'time'   => time(),
		),
		false
	);
}

/**
 * Says in plain words why the last request to a service failed, when, with
 * the status code in brackets, and when it is tried again.
 *
 * @param string $service Service name, such as "OpenAI".
 * @param array  $error   Saved error, with 'time' and 'status'.
 * @param string $retry   Sentence saying when it is tried again.
 * @param string $secret  'key', or 'token' for Discord's bot token.
 * @return string Plain text.
 *
 * @phpstan-param array<string, mixed> $error
 */
function undergrowth_failure_text( string $service, array $error, string $retry, string $secret = 'key' ): string {
	$status = (int) ( $error['status'] ?? 0 );
	$code   = (string) ( $error['code'] ?? '' );

	// The request went through and Claude answered without a question. That
	// source is skipped for good, so nothing failed and nothing is retried.
	if ( 'refusal' === $code || 'max_tokens' === $code ) {
		$cause = 'refusal' === $code
			/* translators: %1$s: service name, such as Anthropic. */
			? __( '%1$s declined to write a question from one of your posts or comments, so Undergrowth skipped it and went on to the others.', 'undergrowth' )
			/* translators: %1$s: service name, such as Anthropic. */
			: __( 'The reply from %1$s about one of your posts or comments was cut off, so Undergrowth skipped it and went on to the others.', 'undergrowth' );
		$when = sprintf(
			/* translators: %1$s: date and time. */
			__( 'That was on %1$s.', 'undergrowth' ),
			undergrowth_date_time( (int) ( $error['time'] ?? 0 ) )
		);

		return sprintf( $cause, $service ) . ' ' . $when;
	}

	if ( 401 === $status || 403 === $status ) {
		$cause = 'token' === $secret
			/* translators: %1$s: service name, such as Discord. */
			? __( '%1$s did not accept the bot token. Check that it is pasted in full and still current.', 'undergrowth' )
			/* translators: %1$s: service name, such as OpenAI. */
			: __( '%1$s did not accept the key. Check that it is pasted in full and still active.', 'undergrowth' );
	} elseif ( 429 === $status ) {
		$cause = 'token' === $secret
			/* translators: %1$s: service name, such as Discord. */
			? __( '%1$s turned the message away because messages came too fast.', 'undergrowth' )
			/* translators: %1$s: service name, such as OpenAI. */
			: __( '%1$s turned the request away: requests came too fast, or the account is out of credit.', 'undergrowth' );
	} elseif ( 0 === $status ) {
		/* translators: %1$s: service name, such as OpenAI. */
		$cause = __( '%1$s could not be reached. Your site may be offline, or blocked from connecting to it.', 'undergrowth' );
	} else {
		/* translators: %1$s: service name, such as OpenAI. */
		$cause = __( '%1$s had a problem on its side.', 'undergrowth' );
	}

	$when = sprintf(
		/* translators: 1: date and time, 2: HTTP status code, or 0 when there was no response. */
		__( 'That was on %1$s (status %2$s).', 'undergrowth' ),
		undergrowth_date_time( (int) ( $error['time'] ?? 0 ) ),
		number_format_i18n( $status )
	);

	// Each part is a whole sentence, translated on its own.
	return sprintf( $cause, $service ) . ' ' . $when . ' ' . $retry;
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
	if ( undergrowth_backfill_stalled() ) {
		undergrowth_print_failure_notice( undergrowth_backfill_stalled_text(), 'undergrowth-backfill-stalled' );
	}
}

/**
 * Prints the folder that holds the per-post files.
 */
function undergrowth_render_memory_folder_field(): void {
	$root = undergrowth_memory_root();

	if ( '' === $root ) {
		?>
		<p><?php esc_html_e( 'No folder could be made for the per-post files, so none are written. Undergrowth tries again on the next page load.', 'undergrowth' ); ?></p>
		<?php
		undergrowth_render_file_failure_notice();
		return;
	}
	?>
	<p><code id="undergrowth-memory-folder" dir="ltr"><?php echo esc_html( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $root ) ) . '/posts/' ); ?></code></p>
	<p class="description">
		<?php esc_html_e( 'Each post’s plain-text record is kept in this folder.', 'undergrowth' ); ?>
	</p>
	<?php
	if ( undergrowth_memory_exposed() ) {
		undergrowth_print_failure_notice( undergrowth_memory_exposed_text(), 'undergrowth-memory-exposed' );
	}

	undergrowth_render_file_failure_notice();
}

/**
 * Prints why the last per-post file was not written, when one was not.
 */
function undergrowth_render_file_failure_notice(): void {
	$error = undergrowth_last_failure( 'undergrowth_last_file_error' );

	if ( null === $error ) {
		return;
	}

	undergrowth_print_failure_notice( undergrowth_file_failure_text( $error ), 'undergrowth-file-error' );
}

/**
 * Reads the failure recorded in an option, if there is one.
 *
 * @param string $option Option a failure is recorded in.
 * @return array<string, mixed>|null The failure, or null when none is recorded.
 */
function undergrowth_last_failure( string $option ): ?array {
	$error = get_option( $option );

	return is_array( $error ) && isset( $error['time'] ) ? $error : null;
}

/**
 * Prints a failure warning inside a settings field.
 *
 * @param string $text Plain text of the warning.
 * @param string $id   ID for the warning, if any.
 */
function undergrowth_print_failure_notice( string $text, string $id = '' ): void {
	printf(
		'<div class="notice notice-warning inline"%1$s><p>%2$s</p></div>',
		'' === $id ? '' : ' id="' . esc_attr( $id ) . '"',
		esc_html( $text )
	);
}

/**
 * Writes a moment as the site's date and time, joined the way the site's
 * language joins them.
 *
 * @param int $time Unix timestamp.
 * @return string Date and time, such as 24 October 2026 at 14:05.
 */
function undergrowth_date_time( int $time ): string {
	return sprintf(
		/* translators: 1: date, 2: time. */
		__( '%1$s at %2$s', 'undergrowth' ),
		wp_date( get_option( 'date_format' ), $time ),
		wp_date( get_option( 'time_format' ), $time )
	);
}

/**
 * Says in plain words why the last per-post file was not written, when, and
 * when it is tried again.
 *
 * @param array $error Saved error, with 'code' and 'time'.
 * @return string Plain text.
 *
 * @phpstan-param array<string, mixed> $error
 */
function undergrowth_file_failure_text( array $error ): string {
	$code  = (string) ( $error['code'] ?? '' );
	$retry = __( 'Undergrowth tries again each time a post’s file is updated, and for every post at the next monthly cycle.', 'undergrowth' );

	if ( 'no_direct_access' === $code ) {
		$cause = __( 'WordPress cannot write files directly on this server, so the per-post files are not being written. Your host can tell you how to allow it.', 'undergrowth' );
	} elseif ( 'no_folder' === $code ) {
		$cause = __( 'The folder for the per-post files could not be made, so they are not being written. Check that the uploads folder can be written to.', 'undergrowth' );
	} elseif ( 'no_protection' === $code ) {
		$cause = __( 'The files that keep visitors out of the per-post folder could not be written, so no per-post files are written either. Check that the uploads folder can be written to.', 'undergrowth' );
	} elseif ( 'old_folder_left' === $code ) {
		$uploads = wp_upload_dir( null, false );
		$cause   = sprintf(
			/* translators: %1$s: path of the old folder, such as wp-content/uploads/undergrowth. */
			__( 'The old folder for the per-post files, at an address anyone could guess, could not be removed. Delete %1$s by hand.', 'undergrowth' ),
			str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $uploads['basedir'] . '/undergrowth' ) )
		);
		$retry = __( 'Undergrowth tries again on the first page load after an hour has passed.', 'undergrowth' );
	} else {
		$cause = __( 'A post’s file could not be written. Check that the uploads folder can be written to.', 'undergrowth' );
	}

	$when = sprintf(
		/* translators: %1$s: date and time. */
		__( 'That was on %1$s.', 'undergrowth' ),
		undergrowth_date_time( (int) $error['time'] )
	);

	// Each part is a whole sentence, translated on its own.
	return $cause . ' ' . $when . ' ' . $retry;
}

/**
 * Prints why the last Accept could not save the post it would link.
 */
function undergrowth_render_accept_error_field(): void {
	$error = undergrowth_last_failure( 'undergrowth_last_accept_error' );

	if ( null === $error ) {
		return;
	}

	undergrowth_print_failure_notice( undergrowth_accept_failure_text( $error ), 'undergrowth-accept-error' );
}

/**
 * Says in plain words why the last Accept could not save the post, from the
 * error code WordPress gave, and when.
 *
 * @param array $error Saved error, with 'code' and 'time'.
 * @return string Plain text.
 *
 * @phpstan-param array<string, mixed> $error
 */
function undergrowth_accept_failure_text( array $error ): string {
	$code = (string) ( $error['code'] ?? '' );

	if ( 'empty_content' === $code ) {
		$cause = __( 'WordPress treated the post as empty and refused to save it, so the link was not added.', 'undergrowth' );
	} elseif ( 'invalid_post' === $code || 'invalid_page' === $code ) {
		$cause = __( 'WordPress could not find the post to save, so the link was not added.', 'undergrowth' );
	} elseif ( 'db_update_error' === $code ) {
		$cause = __( 'The database did not take the change, so the link was not added.', 'undergrowth' );
	} elseif ( '' === $code || 'not_saved' === $code ) {
		$cause = __( 'WordPress did not save the post, so the link was not added.', 'undergrowth' );
	} else {
		$cause = sprintf(
			/* translators: %1$s: error code WordPress gave, such as rest_cannot_edit. */
			__( 'WordPress did not save the post, so the link was not added. It reported the error %1$s.', 'undergrowth' ),
			$code
		);
	}

	$when = sprintf(
		/* translators: %1$s: date and time. */
		__( 'That was on %1$s.', 'undergrowth' ),
		undergrowth_date_time( (int) $error['time'] )
	);

	// Each part is a whole sentence, translated on its own.
	return $cause . ' ' . $when . ' ' . __( 'The suggestion is still on Posts > Undergrowth, so you can accept it again.', 'undergrowth' );
}

/**
 * Adds Help tabs with the full detail of what each part of the page sends,
 * which the fields themselves keep to two sentences.
 */
function undergrowth_add_settings_help(): void {
	if ( undergrowth_embed_provider_set_by_code() ) {
		$identity = undergrowth_embed_identity();
		$links    = sprintf(
			/* translators: 1: model name, such as nomic-embed-text, 2: provider name, such as ollama. */
			__( 'Posts are read by %1$s (%2$s), which code on this site set up instead of OpenAI. Undergrowth gives it the title and text of each published post without a password, so it can tell which of your posts are about similar things and suggest links between them. Where that code sends them depends on how it was set up, so ask whoever added it. Nothing is sent to OpenAI, even if an OpenAI key is saved. Undergrowth changes a post only when you accept a link in it.', 'undergrowth' ),
			$identity['model'],
			$identity['provider']
		);
	} else {
		$links = __( 'With a key, the title and text of each published post without a password are sent to OpenAI, so Undergrowth can tell which of your posts are about similar things and suggest links between them. Nothing is sent while there is no key. Undergrowth changes a post only when you accept a link in it.', 'undergrowth' );
	}

	$tabs = array(
		'undergrowth-links'   => array(
			__( 'Link suggestions', 'undergrowth' ),
			array(
				$links,
				__( 'A draft is read only when you press Scan this draft in the editor: its title and text are sent the same way, once, to suggest links for it, and nothing about it is kept. Private posts, posts behind a password and comments are never sent.', 'undergrowth' ),
				__( 'With Polylang or WPML active, each post keeps the language it is written in, and only posts in the same language are suggested to each other. A post the plugin gives no language keeps the site’s language.', 'undergrowth' ),
			),
		),
		'undergrowth-prompts' => array(
			__( 'Questions', 'undergrowth' ),
			array(
				__( 'With a key, Undergrowth asks Claude, through the Anthropic API, to write you an occasional question about your own writing. Each request sends one post’s title, a few sentences quoted from that post and, when the question comes from a reader’s comment, the text of that comment. A question about a gap between your posts quotes one sentence from each of up to three of them instead. Whole posts are never sent. A question about a post you moved to compost is sent back once more, on its own, to check it. Nothing is sent while there is no key. The questions appear only on your Dashboard, never to your readers.', 'undergrowth' ),
			),
		),
		'undergrowth-files'   => array(
			__( 'Per-post files', 'undergrowth' ),
			array(
				__( 'Each post that has been read, or that you were asked a question about, has a plain-text file in the folder shown on this page. It holds the post’s stage, its suggestions, your vetoes and the questions you were asked, and nothing written for your readers. The folder’s name ends in random characters made once for this site, so visitors cannot guess its address.', 'undergrowth' ),
			),
		),
		'undergrowth-discord' => array(
			__( 'Discord', 'undergrowth' ),
			array(
				__( 'Optional. Undergrowth can post each new link suggestion to a channel in your own Discord server, with Accept and Veto buttons that work like the ones on Posts > Undergrowth. Each message sends Discord the titles of both posts, the suggested sentence, which is in your own words, and links to both posts. Nothing is sent until every Discord field on this page is filled in. Use a private channel: anyone who can read it sees your suggestions, and nothing is ever posted to your readers.', 'undergrowth' ),
			),
		),
	);

	foreach ( $tabs as $id => $tab ) {
		undergrowth_add_help_tab( $id, $tab[0], $tab[1] );
	}
}
