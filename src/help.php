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
 * @param int      $priority   Where the tab sits among the screen's tabs. Core's default is 10.
 */
function undergrowth_add_help_tab( string $id, string $title, array $paragraphs, int $priority = 10 ): void {
	$screen = get_current_screen();

	if ( ! $screen ) {
		return;
	}

	$screen->add_help_tab(
		array(
			'id'       => $id,
			'title'    => $title,
			'content'  => '<p>' . implode( '</p><p>', array_map( 'esc_html', $paragraphs ) ) . '</p>',
			'priority' => $priority,
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
			__( 'Each suggestion is a link from one of your posts to another, at a sentence you wrote. The highlighted words are the words Accept will make into the link.', 'undergrowth' ),
			__( 'Choose words lets you pick other words of the same sentence for the link: choose the first word and the last, then save. Only words already in your sentence can be chosen; nothing can be typed or added.', 'undergrowth' ),
			__( 'Accept adds that one link and saves a revision, so you can see or undo the change. Veto removes the suggestion, and it is never suggested again, even after you edit either post. The notice after a veto has an Undo, in case you chose it by mistake.', 'undergrowth' ),
		)
	);

	undergrowth_add_help_tab(
		'undergrowth-orphans',
		__( 'Orphans', 'undergrowth' ),
		array(
			__( 'An orphan is a post in your garden that no accepted link from another of your published posts points to. Readers who follow links, and search engines, reach it less easily. Undergrowth only knows the links it suggested and you accepted, so a link you added by hand is not counted.', 'undergrowth' ),
			__( 'Beside each orphan are the best suggestions that would link to it, with Accept and Veto as on the Suggestions tab. Once you accept one, the post leaves the list. Composting posts and posts not read yet are never listed.', 'undergrowth' ),
		)
	);

	undergrowth_add_help_tab(
		'undergrowth-stages',
		__( 'Growth stages', 'undergrowth' ),
		array(
			__( 'Seedling: a new post, with no more than one accepted link in or out.', 'undergrowth' ),
			__( 'Sprout: two or more accepted links, but not yet in a group of three or more posts on the Map.', 'undergrowth' ),
			__( 'Budding: in a group, with accepted links both in and out.', 'undergrowth' ),
			__( 'Evergreen: in a group, with three or more accepted links in, and a published hub page that links to it. A hub is a page you started from the Map, wrote and published yourself.', 'undergrowth' ),
			__( 'Stages are measured once a month from the links you have accepted, and never lowered. Pin a stage to keep a post at it, and unpin it to have it measured again.', 'undergrowth' ),
		)
	);

	undergrowth_add_help_tab(
		'undergrowth-map',
		__( 'Map', 'undergrowth' ),
		array(
			__( 'Each group is a set of your posts that are close in meaning, found once a month. A group is named after the title of its post nearest the middle, with a few words its posts share that the rest of your site rarely uses. Every word is your own; nothing on the Map is written by a model.', 'undergrowth' ),
			__( 'A post is not in a group yet when it has not been read, was read after the groups were last found, or is not close enough in meaning to two other posts. Composting posts rest outside the groups and are only counted.', 'undergrowth' ),
			__( 'A group marked “with a gap” has no post at its middle: its posts circle something none of them is about yet. Whether to write about it is up to you.', 'undergrowth' ),
			__( 'A large group whose posts already link to each other may get a hub proposal: a page that ties the group together for readers. You write the hub. Start a hub draft makes a draft page holding only the group’s titles, each linked to its post, and Undergrowth never publishes it. Not for this group means it is never proposed for that group again.', 'undergrowth' ),
			__( 'When a post you moved to compost sits near a group that is new this month, a notice says so here. Return it to the garden or dismiss the notice; either is fine.', 'undergrowth' ),
			__( 'Export, at the end of the Map, downloads every post on it as CSV for a spreadsheet or as a Markdown table for your notes: one row per post, with its group, the group\'s words, its link, its stage and whether its group has a gap.', 'undergrowth' ),
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
