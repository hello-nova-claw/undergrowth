<?php
/**
 * Suggested privacy policy text, and the personal data exporter and eraser
 * for the one thing Undergrowth keeps about a person: a linked Discord user
 * ID.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', 'undergrowth_add_privacy_policy_content' );
add_filter( 'wp_privacy_personal_data_exporters', 'undergrowth_register_personal_data_exporter' );
add_filter( 'wp_privacy_personal_data_erasers', 'undergrowth_register_personal_data_eraser' );

/**
 * Adds Undergrowth's section to Settings > Privacy > Policy Guide.
 */
function undergrowth_add_privacy_policy_content(): void {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$paragraphs = array(
		__( 'Undergrowth sends text to outside services only once the site owner sets each one up, and each is used under its own terms and privacy policy.', 'undergrowth' ),
		__( 'With an OpenAI key, the title and text of each published post without a password are sent to OpenAI to find related posts. Code added to the site can send them to another service instead.', 'undergrowth' ),
		__( 'With an Anthropic key, Anthropic is sent a post’s title and a few sentences quoted from the site’s posts to write the site owner a question. When the question comes from a reader’s approved comment, the text of that comment is sent too, without the commenter’s name, email address or IP address.', 'undergrowth' ),
		__( 'With Discord set up, each new link suggestion, made of the titles of two posts, a sentence from one of them and links to both, is posted to a private Discord channel.', 'undergrowth' ),
		__( 'A site user who links their Discord account on their profile has their Discord user ID stored with their account, so that clicks in Discord can be matched to them. It is included in personal data exports and removed by personal data erasure.', 'undergrowth' ),
	);

	$content = '<p class="privacy-policy-tutorial">' . esc_html__( 'Undergrowth sets no cookies and records nothing about visitors. What it sends depends on which services are set up on Settings > Undergrowth.', 'undergrowth' ) . '</p>'
		. '<strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'undergrowth' ) . ' </strong>';

	foreach ( $paragraphs as $paragraph ) {
		$content .= '<p>' . esc_html( $paragraph ) . '</p>';
	}

	wp_add_privacy_policy_content( 'Undergrowth', wp_kses_post( $content ) );
}

/**
 * Registers the exporter for a user's linked Discord user ID.
 *
 * @param array $exporters Exporters, by ID.
 * @return array The exporters, with Undergrowth's.
 *
 * @phpstan-param array<string, array{exporter_friendly_name: string, callback: callable}> $exporters
 * @phpstan-return array<string, array{exporter_friendly_name: string, callback: callable}>
 */
function undergrowth_register_personal_data_exporter( array $exporters ): array {
	$exporters['undergrowth'] = array(
		'exporter_friendly_name' => __( 'Undergrowth', 'undergrowth' ),
		'callback'               => 'undergrowth_export_personal_data',
	);

	return $exporters;
}

/**
 * Registers the eraser for a user's linked Discord user ID.
 *
 * @param array $erasers Erasers, by ID.
 * @return array The erasers, with Undergrowth's.
 *
 * @phpstan-param array<string, array{eraser_friendly_name: string, callback: callable}> $erasers
 * @phpstan-return array<string, array{eraser_friendly_name: string, callback: callable}>
 */
function undergrowth_register_personal_data_eraser( array $erasers ): array {
	$erasers['undergrowth'] = array(
		'eraser_friendly_name' => __( 'Undergrowth', 'undergrowth' ),
		'callback'             => 'undergrowth_erase_personal_data',
	);

	return $erasers;
}

/**
 * Exports the Discord user ID linked to the user with this email address.
 *
 * There is at most one item, so every request is the last page.
 *
 * @param string $email_address Email address of the user.
 * @return array{data: array<int, array{group_id: string, group_label: string, group_description: string, item_id: string, data: array<int, array{name: string, value: string}>}>, done: bool} Export.
 */
function undergrowth_export_personal_data( string $email_address ): array {
	$user       = get_user_by( 'email', $email_address );
	$discord_id = $user instanceof WP_User ? (string) get_user_meta( $user->ID, 'undergrowth_discord_user_id', true ) : '';

	if ( ! $user instanceof WP_User || '' === $discord_id ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	return array(
		'data' => array(
			array(
				'group_id'          => 'undergrowth',
				'group_label'       => __( 'Undergrowth', 'undergrowth' ),
				'group_description' => __( 'The Discord account linked on your profile, so that link suggestions can be accepted or vetoed from Discord.', 'undergrowth' ),
				'item_id'           => 'undergrowth-' . $user->ID,
				'data'              => array(
					array(
						'name'  => __( 'Discord user ID', 'undergrowth' ),
						'value' => $discord_id,
					),
				),
			),
		),
		'done' => true,
	);
}

/**
 * Removes the Discord user ID linked to the user with this email address.
 *
 * @param string $email_address Email address of the user.
 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool} Result.
 */
function undergrowth_erase_personal_data( string $email_address ): array {
	$user = get_user_by( 'email', $email_address );

	return array(
		'items_removed'  => $user instanceof WP_User && delete_user_meta( $user->ID, 'undergrowth_discord_user_id' ),
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}
