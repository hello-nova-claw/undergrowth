<?php
/**
 * The one Undergrowth widget on the Dashboard. It points to the Prompt studio
 * when new questions are waiting there and, only with Jetpack Stats, holds
 * the page views meter line.
 *
 * With nothing to show, there is no widget at all.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_dashboard_setup', 'undergrowth_add_dashboard_widget' );

/**
 * Tells whether new questions are waiting for the current user: questions
 * about posts they can edit.
 *
 * @return bool
 */
function undergrowth_user_has_prompt(): bool {
	return array() !== undergrowth_user_prompts( 'new' );
}

/**
 * Adds the Undergrowth widget when it has a question or a meter line for the
 * current user.
 */
function undergrowth_add_dashboard_widget(): void {
	if ( undergrowth_user_has_prompt() || undergrowth_meter_user_can_see() ) {
		wp_add_dashboard_widget( 'undergrowth', __( 'Undergrowth', 'undergrowth' ), 'undergrowth_render_dashboard_widget' );
	}
}

/**
 * Prints each part of the widget the current user may see, under its own
 * heading, so the widget never shows an empty part.
 */
function undergrowth_render_dashboard_widget(): void {
	if ( undergrowth_user_has_prompt() ) {
		echo '<div class="undergrowth-prompt"><h3>' . esc_html__( 'Prompt studio', 'undergrowth' ) . '</h3>';
		undergrowth_render_prompt_widget();
		echo '</div>';
	}

	if ( undergrowth_meter_user_can_see() ) {
		echo '<div class="undergrowth-meter"><h3>' . esc_html__( 'Page views, last 30 days', 'undergrowth' ) . '</h3>';
		undergrowth_render_meter_widget();
		echo '</div>';
	}
}

/**
 * Prints how many new questions wait in the Prompt studio, with a link.
 */
function undergrowth_render_prompt_widget(): void {
	$count = count( undergrowth_user_prompts( 'new' ) );

	if ( 0 === $count ) {
		return;
	}

	/* translators: %s: number of new questions. */
	$waiting = _n( '%s new question from your own posts is in the Prompt studio.', '%s new questions from your own posts are in the Prompt studio.', $count, 'undergrowth' );
	?>
	<p><?php echo esc_html( sprintf( $waiting, number_format_i18n( $count ) ) ); ?></p>
	<p><a href="<?php echo esc_url( undergrowth_studio_url() ); ?>"><?php esc_html_e( 'Open the Prompt studio', 'undergrowth' ); ?></a></p>
	<?php
}
