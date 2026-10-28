<?php
/**
 * The Map tab of Posts › Undergrowth: every post counted, each group under
 * its name with its hub or hub proposal, and the posts not in a group yet.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints the map tab: a count of every post, each cluster as a heading over
 * its posts, then the posts not in a group yet.
 *
 * A cluster is named after the title of its post nearest the middle, with
 * a few words its posts share, all stored by the monthly pass. Every word
 * is the author's own; none is written by a model. A gap is marked in the
 * heading, where a screen reader's list of headings finds it, and said in
 * words that name no post or topic.
 */
function undergrowth_render_map_tab(): void {
	echo '<p>' . esc_html__( 'Each group below is a set of your posts that are close in meaning, found once a month. A group is named after the post nearest its middle, and posts nearest the middle come first.', 'undergrowth' ) . '</p>';

	$map    = undergrowth_cluster_map();
	$counts = undergrowth_map_accounting( $map );

	undergrowth_render_map_counts( count( $map ), $counts );

	foreach ( $map as $cluster ) {
		$id    = 'undergrowth-cluster-' . $cluster['leader'];
		$title = undergrowth_post_title( $cluster['members'][0] );

		printf(
			'<h2 id="%1$s">%2$s</h2>',
			esc_attr( $id ),
			sprintf(
				$cluster['gap']
					/* translators: %1$s: title of the post nearest the middle of the group. */
					? esc_html__( 'Around “%1$s”, with a gap', 'undergrowth' )
					/* translators: %1$s: title of the post nearest the middle of the group. */
					: esc_html__( 'Around “%1$s”', 'undergrowth' ),
				'<bdi>' . esc_html( $title ) . '</bdi>'
			)
		);

		if ( $cluster['gap'] ) {
			printf(
				'<p class="undergrowth-gap"><span class="dashicons dashicons-flag" aria-hidden="true"></span> %s</p>',
				esc_html__( 'No post sits at the middle of this group. These posts circle something none of them is about yet.', 'undergrowth' )
			);
		}

		$size  = count( $cluster['members'] );
		$about = array(
			/* translators: %1$s: number of posts in the group. */
			esc_html( sprintf( _n( '%1$s post.', '%1$s posts.', $size, 'undergrowth' ), number_format_i18n( $size ) ) ),
		);
		$terms = undergrowth_cluster_terms( $cluster['leader'], undergrowth_plain_line( $title ) );

		if ( array() !== $terms ) {
			$about[] = sprintf(
				/* translators: %1$s: a list of words from the group's posts, such as "oca, raised bed, and frost". */
				esc_html__( 'Words that set this group apart: %1$s.', 'undergrowth' ),
				wp_sprintf_l(
					'%l',
					array_map(
						static function ( $term ) {
							return '<bdi>' . esc_html( $term ) . '</bdi>';
						},
						$terms
					)
				)
			);
		}

		echo '<p class="undergrowth-group-about"><span>' . implode( '</span> <span>', $about ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is escaped above.

		printf( '<ul class="undergrowth-cluster" aria-labelledby="%s">', esc_attr( $id ) );

		foreach ( $cluster['members'] as $post_id ) {
			undergrowth_render_map_post( $post_id, undergrowth_stage_label( undergrowth_get_stage( $post_id ) ) );
		}

		echo '</ul>';

		undergrowth_render_hub_proposal( $cluster['leader'], $cluster['members'] );
	}

	if ( count( $map ) >= 2 ) {
		undergrowth_render_pollination_view( $map );
	}

	undergrowth_render_ungrouped_posts( $counts['ungrouped'] );
	undergrowth_render_map_export_form();
}

/**
 * Prints a group's hub, when it has one, or the proposal to start one when
 * the group is dense enough and the author never vetoed it.
 *
 * Only users who can make pages see the proposal or its buttons.
 *
 * @param int   $leader  Leader ID labelling the group.
 * @param int[] $members Post IDs in the group.
 */
function undergrowth_render_hub_proposal( int $leader, array $members ): void {
	if ( ! current_user_can( 'edit_pages' ) ) {
		return;
	}

	$hub = undergrowth_group_hub( $members );

	if ( $hub > 0 ) {
		// A draft has no title until the author gives it one.
		if ( 'publish' === get_post_status( $hub ) ) {
			printf(
				'<p class="undergrowth-hub">%s</p>',
				wp_kses(
					/* translators: %1$s: title of the hub page, linked to its editor. */
					sprintf( __( 'This group has a hub: %1$s.', 'undergrowth' ), undergrowth_post_link( $hub ) ),
					array(
						'a'   => array( 'href' => array() ),
						'bdi' => array(),
					)
				)
			);
		} else {
			$link = get_edit_post_link( $hub, 'raw' );

			printf(
				'<p class="undergrowth-hub">%1$s%2$s</p>',
				esc_html__( 'This group has a hub draft. It stays a draft until you publish it.', 'undergrowth' ),
				null === $link || '' === $link ? '' : sprintf( ' <a href="%1$s">%2$s</a>', esc_url( $link ), esc_html__( 'Edit the draft', 'undergrowth' ) )
			);
		}

		return;
	}

	if ( ! undergrowth_is_hub_candidate( $members ) ) {
		return;
	}

	$id = 'undergrowth-hub-' . $leader;

	printf(
		'<div class="notice notice-info inline undergrowth-hub-proposal"><p id="%1$s">%2$s</p><div class="undergrowth-hub-actions">%3$s %4$s</div></div>',
		esc_attr( $id ),
		esc_html__( 'Many of these posts already link to each other. A hub page that ties them together could help readers find their way. You write it. Undergrowth can start a draft page holding only these posts’ titles, each linked to its post, and never publishes it.', 'undergrowth' ),
		undergrowth_hub_action_form( 'start_hub', $leader, $id, __( 'Start a hub draft', 'undergrowth' ), 'button button-primary' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in undergrowth_hub_action_form().
		undergrowth_hub_action_form( 'veto_hub', $leader, $id, __( 'Not for this group', 'undergrowth' ), 'button' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in undergrowth_hub_action_form().
	);
}

/**
 * Builds the form behind one of a hub proposal's buttons.
 *
 * @param string $action      'start_hub' or 'veto_hub'.
 * @param int    $leader      Leader ID labelling the group.
 * @param string $described   Id of the proposal's text, which describes the button.
 * @param string $text        Button text.
 * @param string $button_class Button classes.
 * @return string Form HTML.
 */
function undergrowth_hub_action_form( string $action, int $leader, string $described, string $text, string $button_class ): string {
	return sprintf(
		'<form method="post" action="%1$s" class="undergrowth-hub-action"><input type="hidden" name="action" value="undergrowth_%2$s" /><input type="hidden" name="post" value="%3$d" />%4$s<button type="submit" class="%5$s" aria-describedby="%6$s">%7$s</button></form>',
		esc_url( admin_url( 'admin-post.php' ) ),
		esc_attr( $action ),
		$leader,
		undergrowth_nonce_input( 'undergrowth_' . $action . '_' . $leader ),
		esc_attr( $button_class ),
		esc_attr( $described ),
		esc_html( $text )
	);
}

/**
 * Prints the form that downloads the Map, every post on it one row, as CSV
 * or Markdown, to take to a spreadsheet or a notes app.
 *
 * It comes last, so the keyboard reaches the groups' posts first.
 */
function undergrowth_render_map_export_form(): void {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="undergrowth-map-export">
		<input type="hidden" name="action" value="undergrowth_export_map" />
		<?php wp_nonce_field( 'undergrowth_export_map', '_wpnonce', false ); ?>
		<p>
			<label for="undergrowth-export-format"><?php esc_html_e( 'Export the Map as', 'undergrowth' ); ?></label>
			<select id="undergrowth-export-format" name="format">
				<option value="csv"><?php esc_html_e( 'CSV, for a spreadsheet', 'undergrowth' ); ?></option>
				<option value="markdown"><?php esc_html_e( 'Markdown, for notes', 'undergrowth' ); ?></option>
			</select>
			<?php submit_button( __( 'Export', 'undergrowth' ), 'secondary', 'submit', false ); ?>
		</p>
	</form>
	<?php
}

/**
 * Prints the line that accounts for every post on the map.
 *
 * Each count is its own sentence, translated whole. A count of none is
 * left out, as on the growth stages tab.
 *
 * @param int   $groups Number of groups on the map.
 * @param array $counts Result of undergrowth_map_accounting().
 *
 * @phpstan-param array{grouped: int, ungrouped: array<int, string>, composting: int} $counts
 */
function undergrowth_render_map_counts( int $groups, array $counts ): void {
	$sentences = array(
		sprintf(
			/* translators: 1: number of posts, 2: number of groups, such as "4 groups". */
			_n( '%1$s post is in %2$s.', '%1$s posts are in %2$s.', $counts['grouped'], 'undergrowth' ),
			number_format_i18n( $counts['grouped'] ),
			/* translators: %1$s: number of groups. */
			sprintf( _n( '%1$s group', '%1$s groups', $groups, 'undergrowth' ), number_format_i18n( $groups ) )
		),
	);
	$ungrouped = count( $counts['ungrouped'] );

	if ( $ungrouped > 0 ) {
		/* translators: %1$s: number of posts. */
		$sentences[] = sprintf( _n( '%1$s post is not in a group yet.', '%1$s posts are not in a group yet.', $ungrouped, 'undergrowth' ), number_format_i18n( $ungrouped ) );
	}

	if ( $counts['composting'] > 0 ) {
		$sentences[] = undergrowth_stage_count_sentence( 'composting', $counts['composting'] );
	}

	echo '<p class="undergrowth-map-counts"><span>' . implode( '</span> <span>', array_map( 'esc_html', $sentences ) ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each sentence is escaped here.
}

/**
 * Prints the posts not in a group yet, each with the reason.
 *
 * A long list starts collapsed, in core's own disclosure element, so the
 * groups above stay in reach.
 *
 * @param array<int, string> $ungrouped Reason keyed by post ID, from undergrowth_map_accounting().
 */
function undergrowth_render_ungrouped_posts( array $ungrouped ): void {
	if ( array() === $ungrouped ) {
		return;
	}

	$reasons = array(
		'unread' => __( 'Not read yet', 'undergrowth' ),
		'new'    => __( 'Read since the groups were last found', 'undergrowth' ),
		'apart'  => __( 'Not close enough to other posts yet', 'undergrowth' ),
	);
	$count   = count( $ungrouped );
	$long    = $count > 10;

	printf( '<h2 id="undergrowth-ungrouped">%s</h2>', esc_html__( 'Not in a group yet', 'undergrowth' ) );
	echo '<p>' . esc_html__( 'A post joins a group once it has been read and is close in meaning to at least two others. Groups are found again once a month.', 'undergrowth' ) . '</p>';

	if ( $long ) {
		printf(
			'<details class="undergrowth-ungrouped-list"><summary>%s</summary>',
			/* translators: %1$s: number of posts. */
			esc_html( sprintf( _n( 'Show the %1$s post', 'Show all %1$s posts', $count, 'undergrowth' ), number_format_i18n( $count ) ) )
		);
	}

	echo '<ul class="undergrowth-ungrouped" aria-labelledby="undergrowth-ungrouped">';

	// One query for every post's title and edit link, however long the list.
	_prime_post_caches( array_keys( $ungrouped ), false, false );

	foreach ( $ungrouped as $post_id => $reason ) {
		undergrowth_render_map_post( $post_id, $reasons[ $reason ] ?? $reasons['apart'] );
	}

	echo '</ul>';

	if ( $long ) {
		echo '</details>';
	}
}

/**
 * Prints one post on the map as a list item: its title, linked to its
 * editor when the user can edit it, and a note in brackets.
 *
 * @param int    $post_id Post ID.
 * @param string $note    Growth stage or reason, not escaped.
 */
function undergrowth_render_map_post( int $post_id, string $note ): void {
	printf(
		'<li>%s</li>',
		wp_kses(
			sprintf(
				/* translators: 1: post title, linked to its editor when you can edit it, 2: growth stage, or why the post is not in a group. */
				__( '%1$s (%2$s)', 'undergrowth' ),
				undergrowth_post_link( $post_id ),
				esc_html( $note )
			),
			array(
				'a'   => array( 'href' => array() ),
				'bdi' => array(),
			)
		)
	);
}
