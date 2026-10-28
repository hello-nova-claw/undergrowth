<?php
/**
 * The Cross-pollination view on the Map tab, and dismissing a pair from it.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

// Pairs the Map shows before folding the rest away.
const UNDERGROWTH_POLLINATION_SHOWN = 10;

add_action( 'admin_post_undergrowth_dismiss_pollination', 'undergrowth_handle_dismiss_pollination' );

/**
 * Dismisses the pair posted from the Map's Cross-pollination view, then
 * returns there.
 */
function undergrowth_handle_dismiss_pollination(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() runs before anything changes.
	$a = isset( $_POST['a'] ) ? absint( wp_unslash( $_POST['a'] ) ) : 0;
	$b = isset( $_POST['b'] ) ? absint( wp_unslash( $_POST['b'] ) ) : 0;
	// phpcs:enable

	check_admin_referer( 'undergrowth_dismiss_pollination_' . $a . '_' . $b );

	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'You cannot change the Map.', 'undergrowth' ), '', array( 'response' => 403 ) );
	}

	wp_safe_redirect( undergrowth_suggestions_url( undergrowth_dismiss_pollination( $a, $b ) ? 'pollination_dismissed' : 'pollination_gone', 'map' ) . '#undergrowth-pollination' );
	exit;
}

/**
 * Returns the notices the Cross-pollination view's actions come back with.
 *
 * @return array<string, string> Messages keyed by notice.
 */
function undergrowth_pollination_result_messages(): array {
	return array(
		'pollination_dismissed' => __( 'Dismissed. This pair will not be shown again.', 'undergrowth' ),
		'pollination_gone'      => __( 'That pair is no longer shown, so nothing was changed.', 'undergrowth' ),
	);
}

/**
 * Prints the Cross-pollination view on the Map tab: each pair of groups,
 * why it was found, in the author's own titles and words, and a way to
 * dismiss it.
 *
 * @param array<int, array{leader: int, members: int[], gap: bool}>|null $map Result of undergrowth_cluster_map(), when the tab already built it.
 */
function undergrowth_render_pollination_view( ?array $map = null ): void {
	$pairs = undergrowth_pollination_pairs( 0, null, $map );
	$steps = undergrowth_surprise_steps();

	printf( '<h2 id="undergrowth-pollination">%s</h2>', esc_html__( 'Cross-pollination', 'undergrowth' ) );
	printf(
		'<p>%1$s %2$s</p>',
		esc_html__( 'Pairs of groups that sit apart but could spark something together, found from your own posts once a month.', 'undergrowth' ),
		wp_kses(
			sprintf(
				/* translators: 1: name of the step on the surprise scale, such as "Cautious", 2: address of Settings > Undergrowth. */
				__( 'The surprise scale is at %1$s. <a href="%2$s">Change it on Settings › Undergrowth</a>.', 'undergrowth' ),
				'<strong>' . esc_html( $steps[ undergrowth_surprise_step() ] ) . '</strong>',
				esc_url( undergrowth_settings_url() . '#undergrowth_surprise_1' )
			),
			array(
				'a'      => array( 'href' => array() ),
				'strong' => array(),
			)
		)
	);

	if ( array() === $pairs ) {
		echo '<p class="undergrowth-pollination-none">' . esc_html__( 'No pairs at this step yet. A higher step on the surprise scale reaches further.', 'undergrowth' ) . '</p>';

		return;
	}

	// The strongest pairs come first, and a large garden can have hundreds.
	$shown = array_slice( $pairs, 0, UNDERGROWTH_POLLINATION_SHOWN );
	$more  = array_slice( $pairs, UNDERGROWTH_POLLINATION_SHOWN );

	echo '<ul class="undergrowth-pollination" aria-labelledby="undergrowth-pollination">';
	array_map( 'undergrowth_render_pollination_pair', $shown );
	echo '</ul>';

	if ( array() === $more ) {
		return;
	}

	printf(
		'<details class="undergrowth-pollination-more"><summary>%s</summary><ul class="undergrowth-pollination" aria-labelledby="undergrowth-pollination">',
		/* translators: %1$s: number of pairs of groups. */
		esc_html( sprintf( _n( 'Show the %1$s pair', 'Show all %1$s pairs', count( $pairs ), 'undergrowth' ), number_format_i18n( count( $pairs ) ) ) )
	);
	array_map( 'undergrowth_render_pollination_pair', $more );
	echo '</ul></details>';
}

/**
 * Prints one pair of the Cross-pollination view.
 *
 * @param array $pair One entry of undergrowth_pollination_pairs().
 *
 * @phpstan-param array{groups: array{0: int, 1: int}, members: array{0: int[], 1: int[]}, sweet: array{0: int, 1: int, 2: float}|null, bridge: array{0: int, 1: float}|null, threads: string[], linked: bool} $pair
 */
function undergrowth_render_pollination_pair( array $pair ): void {
	$id      = 'undergrowth-pollination-' . $pair['groups'][0] . '-' . $pair['groups'][1];
	$allowed = array(
		'a'   => array( 'href' => array() ),
		'bdi' => array(),
	);
	$reasons = array();

	if ( null !== $pair['sweet'] ) {
		$reasons[] = sprintf(
			/* translators: 1: title of a post in one group, 2: title of a post in the other. */
			__( '%1$s and %2$s are about different things, but not far apart.', 'undergrowth' ),
			undergrowth_post_link( $pair['sweet'][0] ),
			undergrowth_post_link( $pair['sweet'][1] )
		);
	}

	if ( null !== $pair['bridge'] ) {
		$reasons[] = sprintf(
			/* translators: %1$s: title of a post. */
			__( '%1$s sits near both groups.', 'undergrowth' ),
			undergrowth_post_link( $pair['bridge'][0] )
		);
	}

	if ( array() !== $pair['threads'] ) {
		$reasons[] = sprintf(
			/* translators: %1$s: a list of words from the posts, such as "oca, frost, and ledger". */
			__( 'Both groups use words the rest of your site rarely does: %1$s.', 'undergrowth' ),
			wp_sprintf_l(
				'%l',
				array_map(
					static function ( $term ) {
						return '<bdi>' . esc_html( $term ) . '</bdi>';
					},
					$pair['threads']
				)
			)
		);
	}

	if ( ! $pair['linked'] ) {
		$reasons[] = esc_html__( 'A post connecting these two groups would link both halves of your garden.', 'undergrowth' );
	}

	printf(
		'<li class="undergrowth-pollination-pair"><h3 id="%1$s">%2$s</h3><ul>',
		esc_attr( $id ),
		wp_kses(
			sprintf(
				/* translators: 1: title the first group is named after, 2: title the second group is named after. */
				__( 'Around “%1$s”, and around “%2$s”', 'undergrowth' ),
				'<bdi>' . esc_html( undergrowth_post_title( $pair['members'][0][0] ) ) . '</bdi>',
				'<bdi>' . esc_html( undergrowth_post_title( $pair['members'][1][0] ) ) . '</bdi>'
			),
			$allowed
		)
	);

	foreach ( $reasons as $reason ) {
		echo '<li>' . wp_kses( $reason, $allowed ) . '</li>';
	}

	printf(
		'</ul><form method="post" action="%1$s"><input type="hidden" name="action" value="undergrowth_dismiss_pollination" /><input type="hidden" name="a" value="%2$d" /><input type="hidden" name="b" value="%3$d" />%4$s<button type="submit" class="button" aria-describedby="%5$s">%6$s</button></form></li>',
		esc_url( admin_url( 'admin-post.php' ) ),
		(int) $pair['groups'][0],
		(int) $pair['groups'][1],
		undergrowth_nonce_input( 'undergrowth_dismiss_pollination_' . $pair['groups'][0] . '_' . $pair['groups'][1] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in undergrowth_nonce_input().
		esc_attr( $id ),
		esc_html__( 'Dismiss', 'undergrowth' )
	);
}
