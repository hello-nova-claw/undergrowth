<?php
/**
 * "Worth a look": a quiet note to the author on an Evergreen post that may
 * have gone stale.
 *
 * An Evergreen post is one the author keeps returning to. When it has not
 * been edited in a long time, or its group on the Map has grown since, the
 * Growth stages tab and the post's editor panel say so, once, until the
 * author edits the post or dismisses the note. It is never a demotion, never
 * shown to readers and never sent anywhere.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_TEND_AFTER_DAYS  = 365;
const UNDERGROWTH_TEND_GROUP_GROWN = 3;

add_action( 'admin_post_undergrowth_dismiss_tend', 'undergrowth_handle_dismiss_tend' );

/**
 * Returns how long an Evergreen post may go unedited before it is worth a look.
 *
 * @return int Days, at least one.
 */
function undergrowth_tend_after_days(): int {
	/**
	 * Filters how many days an Evergreen post may go unedited before it is worth a look.
	 *
	 * @param int $days Days. Default 365.
	 */
	return max( 1, (int) apply_filters( 'undergrowth_tend_after_days', UNDERGROWTH_TEND_AFTER_DAYS ) );
}

/**
 * Returns how many posts must join an Evergreen post's group since its last
 * edit for it to be worth a look.
 *
 * @return int Posts, at least one.
 */
function undergrowth_tend_group_grown(): int {
	/**
	 * Filters how many posts must join an Evergreen post's Map group since its last edit for it to be worth a look.
	 *
	 * @param int $posts Posts. Default 3.
	 */
	return max( 1, (int) apply_filters( 'undergrowth_tend_group_grown', UNDERGROWTH_TEND_GROUP_GROWN ) );
}

/**
 * Counts the posts readers may see that joined a post's Map group after a
 * moment.
 *
 * @param int    $post_id Post ID.
 * @param string $since   GMT date and time, as stored by WordPress.
 * @return int Posts published after it, the post itself left out.
 */
function undergrowth_tend_newer_in_group( int $post_id, string $since ): int {
	$leader = (int) get_post_meta( $post_id, 'undergrowth_cluster', true );

	if ( $leader <= 0 ) {
		return 0;
	}

	$members = get_posts(
		array(
			'post_type'        => undergrowth_indexed_post_types(),
			'post_status'      => 'publish',
			'fields'           => 'ids',
			'posts_per_page'   => -1,
			'post__not_in'     => array( $post_id ),
			'no_found_rows'    => true,
			'suppress_filters' => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One group's members, for the few Evergreen rows on screen.
			'meta_query'       => array(
				array(
					'key'   => 'undergrowth_cluster',
					'value' => $leader,
				),
			),
			'date_query'       => array(
				array(
					'column' => 'post_date_gmt',
					'after'  => $since,
				),
			),
		)
	);

	return count( undergrowth_filter_garden_visible( array_map( 'intval', $members ) ) );
}

/**
 * Tells why an Evergreen post is worth a look, if it is.
 *
 * Only an Evergreen post readers may see qualifies. A note the author
 * dismissed stays away until the post is edited again.
 *
 * @param int $post_id Post ID.
 * @return array{reason: string, count: int}|null 'stale' with the days since the
 *         last edit, or 'grown' with the posts that joined its group; null when
 *         it is not worth a look.
 */
function undergrowth_tend_reason( int $post_id ): ?array {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || 'evergreen' !== undergrowth_get_stage( $post_id ) || ! undergrowth_is_garden_visible( $post ) ) {
		return null;
	}

	if ( get_post_meta( $post_id, 'undergrowth_tend_dismissed', true ) === $post->post_modified_gmt ) {
		return null;
	}

	$edited = strtotime( $post->post_modified_gmt . ' UTC' );

	if ( false === $edited ) {
		return null;
	}

	$days = (int) floor( ( time() - $edited ) / DAY_IN_SECONDS );

	if ( $days >= undergrowth_tend_after_days() ) {
		return array(
			'reason' => 'stale',
			'count'  => $days,
		);
	}

	$grown = undergrowth_tend_newer_in_group( $post_id, $post->post_modified_gmt );

	if ( $grown >= undergrowth_tend_group_grown() ) {
		return array(
			'reason' => 'grown',
			'count'  => $grown,
		);
	}

	return null;
}

/**
 * Words the note for an Evergreen post worth a look.
 *
 * @param int $post_id Post ID.
 * @return string|null The note, or null when there is none.
 */
function undergrowth_tend_note( int $post_id ): ?string {
	$why = undergrowth_tend_reason( $post_id );

	if ( null === $why ) {
		return null;
	}

	if ( 'stale' === $why['reason'] ) {
		return sprintf(
			/* translators: %1$s: how long ago, such as "1 year". */
			__( 'Worth a look: you last edited this Evergreen post %1$s ago.', 'undergrowth' ),
			human_time_diff( time() - $why['count'] * DAY_IN_SECONDS )
		);
	}

	return sprintf(
		/* translators: %1$s: number of posts. */
		_n( 'Worth a look: %1$s post has joined this one’s group on the Map since you last edited it.', 'Worth a look: %1$s posts have joined this one’s group on the Map since you last edited it.', $why['count'], 'undergrowth' ),
		number_format_i18n( $why['count'] )
	);
}

/**
 * Puts the note away until the post is next edited.
 *
 * @param int $post_id Post ID.
 * @return bool True when there was a note to dismiss.
 */
function undergrowth_dismiss_tend( int $post_id ): bool {
	if ( ! current_user_can( 'edit_post', $post_id ) || null === undergrowth_tend_reason( $post_id ) ) {
		return false;
	}

	// The date of the last edit, so the next edit brings the note back
	// whenever the post is worth a look again.
	return (bool) update_post_meta( $post_id, 'undergrowth_tend_dismissed', get_post( $post_id )->post_modified_gmt );
}

/**
 * Words the result of dismissing a note, for the Growth stages tab and the
 * editor panel.
 *
 * @return array<string, string> Messages keyed by result.
 */
function undergrowth_tend_result_messages(): array {
	return array(
		'tend_dismissed' => __( 'Dismissed. The note will not come back until you edit the post and it is worth a look again.', 'undergrowth' ),
		'tend_gone'      => __( 'That note is no longer shown, so nothing was changed.', 'undergrowth' ),
	);
}

/**
 * Dismisses the note posted from a row's form, then returns to the Growth
 * stages tab.
 */
function undergrowth_handle_dismiss_tend(): void {
	$post_id = undergrowth_posted_post_id( 'dismiss_tend' );

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( esc_html__( 'You cannot change this post.', 'undergrowth' ), '', array( 'response' => 403 ) );
	}

	wp_safe_redirect( undergrowth_suggestions_url( undergrowth_dismiss_tend( $post_id ) ? 'tend_dismissed' : 'tend_gone', 'stages' ) );
	exit;
}
