<?php
/**
 * Small pieces the admin screens share: row action forms, post titles and
 * links, and the result notices after an action.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds a nonce field without the id core gives it, for a screen with many
 * small forms, where core's would repeat one id on every form.
 *
 * @param string $action Nonce action.
 * @return string Hidden input HTML.
 */
function undergrowth_nonce_input( string $action ): string {
	return sprintf( '<input type="hidden" name="_wpnonce" value="%s" />', esc_attr( wp_create_nonce( $action ) ) );
}

/**
 * Returns the id of the form behind a row action on Posts > Undergrowth.
 *
 * @param string             $action Action name, such as 'veto'.
 * @param array<string, int> $fields Post IDs the action names, in order, keyed by field name.
 * @return string Element id.
 */
function undergrowth_row_action_form_id( string $action, array $fields ): string {
	return 'undergrowth-' . $action . '-' . implode( '-', array_map( 'intval', $fields ) );
}

/**
 * Builds one row action, a button tied to its form by the form attribute.
 *
 * The buttons sit outside their forms so they flow in the row actions like
 * core's own, which use the same button-link class. Both list tables build
 * them here, so their markup cannot drift apart.
 *
 * @param string             $action Action name.
 * @param array<string, int> $fields Post IDs the action names, as for undergrowth_row_action_form().
 * @param string             $label  Accessible name.
 * @param string             $text   Visible text.
 * @return string Button HTML.
 */
function undergrowth_row_action_button( string $action, array $fields, string $label, string $text ): string {
	return sprintf(
		'<button type="submit" form="%1$s" class="button-link" aria-label="%2$s">%3$s</button>',
		esc_attr( undergrowth_row_action_form_id( $action, $fields ) ),
		esc_attr( $label ),
		esc_html( $text )
	);
}

/**
 * Builds the form behind one row action, holding only hidden fields.
 *
 * Each action is a separate form so it works without JavaScript and carries
 * a nonce for exactly the posts it names, which its handler checks.
 *
 * @param string             $action Action name.
 * @param array<string, int> $fields Post IDs the action names, in order, keyed by field name.
 * @return string Form HTML.
 */
function undergrowth_row_action_form( string $action, array $fields ): string {
	$ids    = array_map( 'intval', $fields );
	$hidden = '';

	foreach ( $ids as $name => $id ) {
		$hidden .= sprintf( '<input type="hidden" name="%1$s" value="%2$d" />', esc_attr( $name ), $id );
	}

	return sprintf(
		'<form method="post" action="%1$s" id="%2$s"><input type="hidden" name="action" value="undergrowth_%3$s" />%4$s%5$s</form>',
		esc_url( admin_url( 'admin-post.php' ) ),
		esc_attr( undergrowth_row_action_form_id( $action, $ids ) ),
		esc_attr( $action ),
		$hidden,
		wp_nonce_field( 'undergrowth_' . $action . '_' . implode( '_', $ids ), '_wpnonce', true, false )
	);
}

/**
 * Returns a post's title, or "(no title)" as core's lists name an untitled
 * post, so a link or a button's name is never empty.
 *
 * @param int $post_id Post ID.
 * @return string Title, not escaped.
 */
function undergrowth_post_title( int $post_id ): string {
	$title = get_the_title( $post_id );

	return '' === $title ? __( '(no title)', 'undergrowth' ) : $title;
}

/**
 * Escapes a suggestion's sentence and marks the words Accept would link in it.
 *
 * @param string                                               $sentence The author's sentence.
 * @param array{word: string, offset: int, position: int}|null $choice   Result of undergrowth_link_word_choice() for the suggestion.
 * @return string HTML.
 */
function undergrowth_marked_sentence( string $sentence, ?array $choice ): string {
	// A line break inside the words in the post shows as a space.
	$words  = null === $choice ? '' : undergrowth_collapse_spaces( $choice['word'] );
	$length = mb_strlen( $words );

	if ( null === $choice || mb_substr( $sentence, $choice['offset'], $length ) !== $words ) {
		return esc_html( $sentence );
	}

	return esc_html( mb_substr( $sentence, 0, $choice['offset'] ) )
		. '<mark>' . esc_html( $words ) . '</mark>'
		. esc_html( mb_substr( $sentence, $choice['offset'] + $length ) );
}

/**
 * Prints the script that moves focus to the result notice, so a keyboard or
 * screen reader user hears the result instead of landing at the top.
 *
 * Core moves notices under the page heading once the page is ready, and
 * moving an element takes focus away from it, so focus waits for load.
 */
function undergrowth_print_notice_focus_script(): void {
	wp_print_inline_script_tag( "window.addEventListener( 'load', function () { var notice = document.getElementById( 'undergrowth-notice' ); if ( notice ) { notice.focus(); } } );" );
}

/**
 * Prints the notices that report the action which brought the user back,
 * and moves focus to the first.
 *
 * The first is heard at once; a second is read after it.
 *
 * @param array<string, string> $notices Markup of paragraphs, and links within them, keyed by notice class such as 'notice-success'.
 */
function undergrowth_print_result_notices( array $notices ): void {
	$id = ' id="undergrowth-notice"';

	foreach ( $notices as $class => $paragraphs ) {
		printf(
			'<div%1$s class="notice %2$s is-dismissible" role="status"%3$s>%4$s</div>',
			$id, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed string.
			esc_attr( $class ),
			'' !== $id ? ' tabindex="-1"' : '',
			wp_kses(
				$paragraphs,
				array(
					'p'  => array(),
					'a'  => array( 'href' => array() ),
					'ul' => array(),
					'li' => array(),
				)
			)
		);
		$id = '';
	}

	undergrowth_print_notice_focus_script();
}

/**
 * Builds a post's title, linked to its editor when the user can edit it.
 *
 * The title is isolated, so a title in another script never reorders the
 * sentence or the quotation marks around it.
 *
 * @param int    $post_id    Post ID.
 * @param string $link_class Class for the link, such as core's row-title, if any.
 * @return string Escaped HTML.
 */
function undergrowth_post_link( int $post_id, string $link_class = '' ): string {
	$title = '<bdi>' . esc_html( undergrowth_post_title( $post_id ) ) . '</bdi>';
	$link  = get_edit_post_link( $post_id, 'raw' );

	// Core's own edit_post_link() also gives no link for an empty address.
	if ( null === $link || '' === $link ) {
		return $title;
	}

	return sprintf(
		'<a%1$s href="%2$s">%3$s</a>',
		'' === $link_class ? '' : ' class="' . esc_attr( $link_class ) . '"',
		esc_url( $link ),
		$title
	);
}

/**
 * Returns the address of Settings › Undergrowth.
 *
 * @return string Admin URL.
 */
function undergrowth_settings_url(): string {
	return admin_url( 'options-general.php?page=undergrowth' );
}
