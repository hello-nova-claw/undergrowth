<?php
/**
 * The author's own stage actions: pinning, composting and returning a post
 * to the garden, and the messages each one ends with.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks that the current user may act on a post's stage.
 *
 * @param int $post_id Post ID.
 * @return WP_Error|null Why the action is refused, or null when it may go ahead.
 */
function undergrowth_stage_action_error( int $post_id ): ?WP_Error {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || ! undergrowth_is_indexable( $post ) ) {
		return new WP_Error( 'undergrowth_not_indexed', __( 'Only published posts in the garden have a growth stage.', 'undergrowth' ) );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'undergrowth_cannot_edit', __( 'You cannot change the growth stage of this post.', 'undergrowth' ) );
	}

	return null;
}

/**
 * Returns the error for a stage change that could not be stored.
 *
 * @return WP_Error The error.
 */
function undergrowth_stage_not_saved(): WP_Error {
	return new WP_Error( 'undergrowth_not_saved', undergrowth_stage_result_messages()['stage_not_saved'] );
}

/**
 * Returns the error for a stage change that failed after the pin marker
 * was taken away, when the marker could not be put back either.
 *
 * @return WP_Error The error.
 */
function undergrowth_pin_lost(): WP_Error {
	return new WP_Error( 'undergrowth_pin_lost', undergrowth_stage_result_messages()['stage_pin_lost'] );
}

/**
 * Returns the error for a pin that failed after its marker was stored, when
 * the marker could not be taken away again.
 *
 * @return WP_Error The error.
 */
function undergrowth_pin_left(): WP_Error {
	return new WP_Error( 'undergrowth_pin_left', undergrowth_stage_result_messages()['stage_pin_left'] );
}

/**
 * Returns the sentence for each result of a stage action, by result.
 *
 * The admin notice and the editor's REST route both read from here, so each
 * sentence is written once.
 *
 * @return array<string, string> Sentences, by result.
 */
function undergrowth_stage_result_messages(): array {
	return array(
		'pinned'           => __( 'Stage pinned. The monthly cycle will leave it as it is.', 'undergrowth' ),
		'unpinned'         => __( 'Stage unpinned and measured again from the links you have accepted.', 'undergrowth' ),
		'composted'        => __( 'Moved to compost. The post stays published, and no links are suggested to or from it.', 'undergrowth' ),
		'returned'         => __( 'Returned to the garden. Its stage was measured again from the links you have accepted.', 'undergrowth' ),
		'stage_refused'    => __( 'That stage cannot be pinned, so nothing was changed.', 'undergrowth' ),
		'stage_composting' => __( 'A composting post cannot be pinned, so nothing was changed.', 'undergrowth' ),
		'not_pinned'       => __( 'That stage was not pinned, so nothing was changed.', 'undergrowth' ),
		'stage_not_saved'  => __( 'The stage could not be saved, so nothing was changed. Try again in a moment.', 'undergrowth' ),
		'stage_pin_left'   => __( 'The stage could not be saved, and the pin marker could not be removed. Unpin the post to clear it.', 'undergrowth' ),
		'stage_pin_lost'   => __( 'The stage could not be saved, and the pin marker could not be put back. Pin the stage again to keep it.', 'undergrowth' ),
	);
}

/**
 * Puts the pin marker back as it was before a stage change that failed.
 *
 * Pins are the author's, so a failed change must leave the marker as the
 * author left it.
 *
 * @param int  $post_id Post ID.
 * @param bool $pinned  Whether the post was pinned before the change.
 * @return WP_Error|null The pin_lost error when the marker could not be put back, or null.
 */
function undergrowth_restore_pin( int $post_id, bool $pinned ): ?WP_Error {
	if ( $pinned ) {
		update_post_meta( $post_id, 'undergrowth_stage_pinned', '1' );
	} else {
		delete_post_meta( $post_id, 'undergrowth_stage_pinned' );
	}

	return undergrowth_is_stage_pinned( $post_id ) === $pinned ? null : undergrowth_pin_lost();
}

/**
 * Pins a post's stage, so the monthly cycle leaves it alone.
 *
 * Pinning is an author action, so it takes effect at once. Any measured
 * stage can be pinned, including those the measure cannot reach yet.
 * Composting is outside the pinning rules: it is neither pinned nor
 * pinnable.
 *
 * @param int    $post_id Post ID.
 * @param string $stage   Seedling, sprout, budding or evergreen.
 * @return true|WP_Error True when pinned, or why it was refused.
 */
function undergrowth_pin_stage( int $post_id, string $stage ) {
	$error = undergrowth_stage_action_error( $post_id );

	if ( null !== $error ) {
		return $error;
	}

	if ( ! in_array( $stage, UNDERGROWTH_GARDEN_STAGES, true ) ) {
		return new WP_Error( 'undergrowth_unknown_stage', __( 'That stage cannot be pinned.', 'undergrowth' ) );
	}

	if ( 'composting' === undergrowth_get_stage( $post_id ) ) {
		return new WP_Error( 'undergrowth_composting', __( 'A composting post cannot be pinned.', 'undergrowth' ) );
	}

	$was_pinned = undergrowth_is_stage_pinned( $post_id );

	// The flag goes first, so the file written for the change shows the marker.
	if ( ! $was_pinned ) {
		update_post_meta( $post_id, 'undergrowth_stage_pinned', '1' );

		if ( ! undergrowth_is_stage_pinned( $post_id ) ) {
			return undergrowth_stage_not_saved();
		}
	}

	if ( ! undergrowth_set_stage( $post_id, $stage, 'pin' ) ) {
		// Only a flag added here is taken away; an earlier pin stays.
		if ( ! $was_pinned ) {
			delete_post_meta( $post_id, 'undergrowth_stage_pinned' );

			if ( undergrowth_is_stage_pinned( $post_id ) ) {
				return undergrowth_pin_left();
			}
		}

		return undergrowth_stage_not_saved();
	}

	return true;
}

/**
 * Unpins a post's stage and measures it at once.
 *
 * The stage it had before the pin may be long out of date, so it is
 * measured again rather than restored.
 *
 * @param int $post_id Post ID.
 * @return true|WP_Error True when unpinned, or why it was refused.
 */
function undergrowth_unpin_stage( int $post_id ) {
	$error = undergrowth_stage_action_error( $post_id );

	if ( null !== $error ) {
		return $error;
	}

	if ( ! undergrowth_is_stage_pinned( $post_id ) ) {
		return new WP_Error( 'undergrowth_not_pinned', __( 'This post\'s stage is not pinned.', 'undergrowth' ) );
	}

	delete_post_meta( $post_id, 'undergrowth_stage_pinned' );

	if ( undergrowth_is_stage_pinned( $post_id ) ) {
		return undergrowth_stage_not_saved();
	}

	if ( ! undergrowth_set_stage( $post_id, undergrowth_measure_stage( $post_id ), 'unpin' ) ) {
		return undergrowth_restore_pin( $post_id, true ) ?? undergrowth_stage_not_saved();
	}

	return true;
}

/**
 * Moves a post to compost, from any stage.
 *
 * Composting is the author's choice alone and takes effect at once. The post
 * stays published and indexed, but leaves the active garden: its pending
 * proposals, and other posts' proposals to it, are removed. Links the author
 * accepted stay, because removing them would change the site without the
 * author's approval, and vetoes stay forever. No date or timer is stored.
 *
 * @param int $post_id Post ID.
 * @return true|WP_Error True when composted, or why it was refused.
 */
function undergrowth_compost_post( int $post_id ) {
	$error = undergrowth_stage_action_error( $post_id );

	if ( null !== $error ) {
		return $error;
	}

	if ( 'composting' === undergrowth_get_stage( $post_id ) ) {
		return new WP_Error( 'undergrowth_composting', __( 'This post is already composting.', 'undergrowth' ) );
	}

	// Composting is outside the pinning rules, so a pin does not survive it.
	$pinned = undergrowth_is_stage_pinned( $post_id );
	delete_post_meta( $post_id, 'undergrowth_stage_pinned' );

	if ( undergrowth_is_stage_pinned( $post_id ) ) {
		return undergrowth_stage_not_saved();
	}

	if ( ! undergrowth_set_stage( $post_id, 'composting', 'compost' ) ) {
		// Only a marker taken away here is put back. A post that was not
		// pinned is left alone, so a pin made meanwhile stays.
		return ( $pinned ? undergrowth_restore_pin( $post_id, true ) : null ) ?? undergrowth_stage_not_saved();
	}

	undergrowth_forget_post_links( $post_id );

	// The file was written for the change of stage, before the proposals
	// went, so it is written again without them.
	undergrowth_write_memory( $post_id );

	return true;
}

/**
 * Returns a composting post to the garden.
 *
 * Its stage is measured at that moment. The stage it had before may be years
 * out of date, and resetting it to Seedling would treat a rest as a failure.
 * Its proposals are worked out again straight away.
 *
 * @param int $post_id Post ID.
 * @return true|WP_Error True when returned, or why it was refused.
 */
function undergrowth_return_post( int $post_id ) {
	$error = undergrowth_stage_action_error( $post_id );

	if ( null !== $error ) {
		return $error;
	}

	if ( 'composting' !== undergrowth_get_stage( $post_id ) ) {
		return new WP_Error( 'undergrowth_not_composting', __( 'This post is not composting.', 'undergrowth' ) );
	}

	if ( ! undergrowth_set_stage( $post_id, undergrowth_measure_stage( $post_id ), 'return' ) ) {
		return undergrowth_stage_not_saved();
	}

	undergrowth_refresh_around( $post_id );

	return true;
}
