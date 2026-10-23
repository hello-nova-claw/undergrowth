<?php
/**
 * Help tabs that explain the words Undergrowth uses, where core explains
 * things: on Posts > Undergrowth, and Compost on the Posts list.
 *
 * Help is read only when the author opens it, so it may say more than the
 * screens do. It never counts days or nudges a composting post back.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'load-posts_page_undergrowth-links', 'undergrowth_add_screen_help' );

/**
 * Adds one Help tab to the current screen.
 *
 * @param string   $id         Tab ID.
 * @param string   $title      Tab title.
 * @param string[] $paragraphs Plain-text paragraphs.
 */
function undergrowth_add_help_tab( string $id, string $title, array $paragraphs ): void {
	$screen = get_current_screen();

	if ( ! $screen ) {
		return;
	}

	$screen->add_help_tab(
		array(
			'id'      => $id,
			'title'   => $title,
			'content' => '<p>' . implode( '</p><p>', array_map( 'esc_html', $paragraphs ) ) . '</p>',
		)
	);
}

/**
 * Adds the Help tabs on Posts > Undergrowth: what the garden is, then one
 * tab for each of the screen's tabs, and Compost.
 */
function undergrowth_add_screen_help(): void {
	undergrowth_add_help_tab(
		'undergrowth-overview',
		__( 'Overview', 'undergrowth' ),
		array(
			__( 'Undergrowth reads your published posts and asks you about them. It never writes a word your readers will see, and it changes a post only when you accept a suggestion.', 'undergrowth' ),
			__( 'Your garden is every published post Undergrowth reads, without a password, except the posts you moved to compost.', 'undergrowth' ),
		)
	);

	undergrowth_add_help_tab(
		'undergrowth-suggestions',
		__( 'Suggestions', 'undergrowth' ),
		array(
			__( 'Each suggestion is a link from one of your posts to another, at a sentence you wrote. The highlighted word is the word Accept will make into the link.', 'undergrowth' ),
			__( 'Accept adds that one link and saves a revision, so you can see or undo the change. Veto removes the suggestion, and it is never suggested again, even after you edit either post.', 'undergrowth' ),
		)
	);

	undergrowth_add_help_tab(
		'undergrowth-stages',
		__( 'Growth stages', 'undergrowth' ),
		array(
			__( 'Seedling: a new post, with no more than one accepted link in or out.', 'undergrowth' ),
			__( 'Sprout: two or more accepted links, but not yet in a group of three or more posts on the Map.', 'undergrowth' ),
			__( 'Budding: in a group, with accepted links both in and out.', 'undergrowth' ),
			__( 'Evergreen: in a group, with three or more links in. In this version a post becomes evergreen only when you pin it there.', 'undergrowth' ),
			__( 'Stages are measured once a month from the links you have accepted, and never lowered. Pin a stage to keep a post at it, and unpin it to have it measured again.', 'undergrowth' ),
		)
	);

	undergrowth_add_help_tab(
		'undergrowth-map',
		__( 'Map', 'undergrowth' ),
		array(
			__( 'Each group is a set of your posts that are close in meaning, found once a month. Groups are numbered, never named, so nothing on the Map is written by a model.', 'undergrowth' ),
			__( 'A group marked "with a gap" has no post at its middle: its posts circle something none of them is about yet. Whether to write about it is up to you.', 'undergrowth' ),
			__( 'When a post you moved to compost sits near a group that is new this month, a notice says so here. Return it to the garden or dismiss the notice; either is fine.', 'undergrowth' ),
		)
	);

	undergrowth_add_compost_help();
}

/**
 * Adds the Compost Help tab, on this screen and on the Posts list, where
 * Compost sits beside Move to Trash and could read as a kind of deletion.
 */
function undergrowth_add_compost_help(): void {
	undergrowth_add_help_tab(
		'undergrowth-compost',
		__( 'Compost', 'undergrowth' ),
		array(
			__( 'Compost rests a post. It stays published, its links stay in place, and readers see no difference. Nothing is deleted.', 'undergrowth' ),
			__( 'A composting post leaves the garden: no link is suggested to or from it, and its stage does not change. Undergrowth may still draw on it for a question to you.', 'undergrowth' ),
			__( 'Return to garden brings it back, and its stage is measured again from the links you have accepted.', 'undergrowth' ),
		)
	);
}
