<?php
/**
 * Link suggestions in the block editor's Undergrowth sidebar.
 *
 * Accept in the editor never saves the post. The server finds the stored
 * words in the paragraph the editor holds, with the same rules as Accept on
 * the Suggestions tab, and sends the paragraph back with the link in it.
 * The editor puts it in place, where Undo can take it out again, and the
 * suggestion counts as accepted only once the post is saved with the link.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'undergrowth_register_editor_link_routes' );
add_action( 'wp_after_insert_post', 'undergrowth_accept_saved_links', 10, 2 );

/**
 * Registers the routes the sidebar lists, accepts and vetoes through.
 */
function undergrowth_register_editor_link_routes(): void {
	$post   = array(
		'id' => array(
			'type'     => 'integer',
			'required' => true,
		),
	);
	$target = $post + array(
		'target' => array(
			'type'     => 'integer',
			'required' => true,
		),
	);

	register_rest_route(
		'undergrowth/v1',
		'/posts/(?P<id>\d+)/links',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'undergrowth_rest_get_links',
			'permission_callback' => 'undergrowth_rest_can_edit_post',
			'args'                => $post,
		)
	);

	register_rest_route(
		'undergrowth/v1',
		'/posts/(?P<id>\d+)/links/(?P<target>\d+)/accept',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'undergrowth_rest_accept_link',
			'permission_callback' => 'undergrowth_rest_can_edit_post',
			'args'                => $target + array(
				'paragraphs' => undergrowth_editor_paragraphs_arg(),
			),
		)
	);

	register_rest_route(
		'undergrowth/v1',
		'/posts/(?P<id>\d+)/links/(?P<target>\d+)/words',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'undergrowth_rest_choose_words',
			'permission_callback' => 'undergrowth_rest_can_edit_post',
			'args'                => $target + array(
				'first' => array(
					'type'     => 'integer',
					'minimum'  => 0,
					'required' => true,
				),
				'last'  => array(
					'type'     => 'integer',
					'minimum'  => 0,
					'required' => true,
				),
				'shown' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		)
	);

	register_rest_route(
		'undergrowth/v1',
		'/posts/(?P<id>\d+)/links/(?P<target>\d+)/veto',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'undergrowth_rest_veto_link',
			'permission_callback' => 'undergrowth_rest_can_edit_post',
			'args'                => $target,
		)
	);
}

/**
 * Lists the post's proposed links, and the proposed links to it.
 *
 * As on the Suggestions tab, a composting post's suggestions are left out.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response Outgoing and incoming suggestions.
 */
function undergrowth_rest_get_links( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;

	$post_id = (int) $request['id'];

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every refresh.
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT source_id, target_id FROM %i WHERE state = %s AND ( source_id = %d OR target_id = %d ) ORDER BY score DESC, source_id ASC, target_id ASC',
			undergrowth_links_table(),
			'proposed',
			$post_id,
			$post_id
		),
		ARRAY_A
	);

	_prime_post_caches( array_unique( array_map( 'intval', array_merge( array_column( $rows, 'source_id' ), array_column( $rows, 'target_id' ) ) ) ), false, true );

	$outgoing = array();
	$incoming = array();

	foreach ( $rows as $row ) {
		$source_id = (int) $row['source_id'];
		$target_id = (int) $row['target_id'];

		if ( 'composting' === undergrowth_get_stage( $source_id ) || 'composting' === undergrowth_get_stage( $target_id ) ) {
			continue;
		}

		if ( $source_id === $post_id ) {
			$outgoing[] = array(
				'target' => $target_id,
				'title'  => undergrowth_editor_title( $target_id ),
				'url'    => (string) get_permalink( $target_id ),
			) + undergrowth_editor_words( $source_id, $target_id );
		} else {
			$incoming[] = array(
				'source'   => $source_id,
				'title'    => undergrowth_editor_title( $source_id ),
				'url'      => (string) get_permalink( $source_id ),
				'edit_url' => current_user_can( 'edit_post', $source_id ) ? (string) get_edit_post_link( $source_id, 'raw' ) : null,
			);
		}
	}

	return new WP_REST_Response(
		array(
			'outgoing'        => $outgoing,
			'incoming'        => $incoming,
			// Posts > Undergrowth opens only for those who may edit others' posts.
			'incoming_screen' => current_user_can( 'edit_others_posts' ) ? undergrowth_links_in_url( $post_id ) : null,
		)
	);
}

/**
 * Describes a suggestion's sentence for the sidebar: the words a link would
 * sit on, and the words the author can choose from instead.
 *
 * The choice is the one on the Suggestions tab, so the words are those of
 * undergrowth_anchor_words() and the version is the one
 * undergrowth_choose_words() checks.
 *
 * @param int $source_id Post the link sits in.
 * @param int $target_id Post the link points to.
 * @return array{sentence: string, span: array{offset: int, length: int}|null, words: string[], first: int, last: int, version: string}
 */
function undergrowth_editor_words( int $source_id, int $target_id ): array {
	$span     = undergrowth_editor_link_span( $source_id, $target_id );
	$sentence = null === $span ? undergrowth_stored_sentence( $source_id, $target_id ) : $span['sentence'];
	$words    = undergrowth_can_choose_words( $sentence ) ? undergrowth_anchor_words( $sentence ) : array();
	$first    = 0;
	$last     = 0;

	foreach ( $words as $index => $word ) {
		if ( null !== $span && $word['offset'] === $span['offset'] ) {
			$first = $index;
		}

		if ( null !== $span && $word['offset'] + $word['length'] === $span['offset'] + $span['length'] ) {
			$last = $index;
		}
	}

	return array(
		'sentence' => $sentence,
		'span'     => null === $span ? null : array(
			'offset' => $span['offset'],
			'length' => $span['length'],
		),
		'words'    => array_column( $words, 'text' ),
		'first'    => $first,
		'last'     => $last,
		'version'  => undergrowth_sentence_version( $sentence ),
	);
}

/**
 * Returns a post's title as plain text, for the sidebar to show.
 *
 * @param int $post_id Post ID.
 * @return string Title.
 */
function undergrowth_editor_title( int $post_id ): string {
	return html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Returns the sentence stored with a suggestion.
 *
 * @param int $source_id Post the link sits in.
 * @param int $target_id Post the link points to.
 * @return string Sentence, or an empty string.
 */
function undergrowth_stored_sentence( int $source_id, int $target_id ): string {
	return (string) ( undergrowth_link_row( $source_id, $target_id )['sentence'] ?? '' );
}

/**
 * Reads the sentence and words stored with a suggestion.
 *
 * @param int $source_id Post the link sits in.
 * @param int $target_id Post the link points to.
 * @return array{sentence?: string, anchor?: string, anchor_offset?: string} The row's fields, or none.
 */
function undergrowth_link_row( int $source_id, int $target_id ): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every refresh.
	return (array) $wpdb->get_row(
		$wpdb->prepare(
			'SELECT sentence, anchor, anchor_offset FROM %i WHERE source_id = %d AND target_id = %d',
			undergrowth_links_table(),
			$source_id,
			$target_id
		),
		ARRAY_A
	);
}

/**
 * Tells which words of a suggestion's sentence the editor would link.
 *
 * The words stored with the suggestion are used while they fit its
 * sentence. Otherwise they are the words the Suggestions tab marks, worked
 * out from the saved post by undergrowth_link_word_choice().
 *
 * @param int $source_id Post the link sits in.
 * @param int $target_id Post the link points to.
 * @return array{sentence: string, offset: int, length: int}|null The sentence and the run of words in it, or null when none would be linked.
 */
function undergrowth_editor_link_span( int $source_id, int $target_id ): ?array {
	$row      = undergrowth_link_row( $source_id, $target_id );
	$sentence = (string) ( $row['sentence'] ?? '' );
	$anchor   = (string) ( $row['anchor'] ?? '' );
	$offset   = (int) ( $row['anchor_offset'] ?? -1 );

	if ( '' === $sentence ) {
		return null;
	}

	if ( '' !== $anchor && $offset >= 0 && mb_substr( $sentence, $offset, mb_strlen( $anchor ) ) === $anchor ) {
		return array(
			'sentence' => $sentence,
			'offset'   => $offset,
			'length'   => mb_strlen( $anchor ),
		);
	}

	$choice = undergrowth_link_word_choice( $source_id, $target_id, $sentence );

	if ( null === $choice ) {
		return null;
	}

	// A line break inside the words in the post shows as a space.
	$words = undergrowth_collapse_spaces( $choice['word'] );

	return mb_substr( $sentence, $choice['offset'], mb_strlen( $words ) ) === $words ? array(
		'sentence' => $sentence,
		'offset'   => $choice['offset'],
		'length'   => mb_strlen( $words ),
	) : null;
}

/**
 * Links a suggestion's words in the paragraph the editor holds them in.
 *
 * Nothing stored changes except a note that the link is waiting for the
 * post to be saved. See undergrowth_accept_saved_links().
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error The paragraph's block ID and new HTML, or why nothing can be linked.
 */
function undergrowth_rest_accept_link( WP_REST_Request $request ) {
	$source_id = (int) $request['id'];
	$target_id = (int) $request['target'];
	$target    = get_post( $target_id );

	if ( ! $target instanceof WP_Post || ! undergrowth_is_indexable( $target ) ) {
		return undergrowth_editor_link_error( 'gone' );
	}

	if ( 'proposed' !== undergrowth_link_state( $source_id, $target_id ) ) {
		return undergrowth_editor_link_error( 'not_proposed' );
	}

	$span = undergrowth_editor_link_span( $source_id, $target_id );

	if ( null === $span ) {
		return undergrowth_editor_link_error( 'edited' );
	}

	$linked = undergrowth_link_in_paragraphs( (array) $request['paragraphs'], $span['sentence'], $span['offset'], $span['length'], (string) get_permalink( $target ) );

	if ( is_string( $linked ) ) {
		return undergrowth_editor_link_error( $linked );
	}

	// The words are kept with the note, so the save counts only a link
	// around them, not another link to the same post.
	$note = array(
		'target' => $target_id,
		'words'  => $linked['words'],
	);

	if ( ! in_array( $note, get_post_meta( $source_id, 'undergrowth_pending_links', false ), true ) ) {
		add_post_meta( $source_id, 'undergrowth_pending_links', $note );
	}

	return new WP_REST_Response(
		array(
			'id'      => $linked['id'],
			'content' => $linked['content'],
			'message' => __( 'Linked in the editor. Save the post to keep the link.', 'undergrowth' ),
		)
	);
}

/**
 * Describes the paragraphs the sidebar sends, as a REST argument.
 *
 * @return array<string, mixed> Argument schema.
 */
function undergrowth_editor_paragraphs_arg(): array {
	return array(
		'type'     => 'array',
		'required' => true,
		'maxItems' => 1000,
		'items'    => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'      => array(
					'type'     => 'string',
					'required' => true,
				),
				'content' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
			'additionalProperties' => false,
		),
	);
}

/**
 * Links a run of words in the first paragraph that holds its sentence.
 *
 * Each paragraph is checked alone, as its block would sit in a post, so the
 * rules for where a link may go are the ones Accept uses on a whole post.
 *
 * @param array<int, array{id: string, content: string}> $paragraphs Paragraphs as the editor holds them.
 * @param string                                         $sentence   Sentence the link belongs in.
 * @param int                                            $offset     Character offset of the words in the sentence.
 * @param int                                            $length     Length of the words in characters.
 * @param string                                         $url        Address to link to.
 * @return array{id: string, content: string, words: string}|string The paragraph's block ID, its new HTML and the linked words as a reader sees them, or 'edited' or 'no_word'.
 */
function undergrowth_link_in_paragraphs( array $paragraphs, string $sentence, int $offset, int $length, string $url ) {
	$open   = "<!-- wp:paragraph -->\n<p>";
	$close  = "</p>\n<!-- /wp:paragraph -->";
	$result = 'edited';

	foreach ( $paragraphs as $paragraph ) {
		$content = $open . $paragraph['content'] . $close;

		if ( ! in_array( $sentence, array_column( undergrowth_split_sentences( $content ), 'text' ), true ) ) {
			continue;
		}

		$found = undergrowth_find_link_span( $content, $sentence, $offset, $length );

		if ( null === $found ) {
			$result = 'no_word';
			continue;
		}

		return array(
			'id'      => $paragraph['id'],
			'content' => substr( undergrowth_wrap_link_word( $content, $found, $url ), strlen( $open ), -strlen( $close ) ),
			'words'   => undergrowth_plain_line( $found['word'] ),
		);
	}

	return $result;
}

/**
 * Saves the words the author chose in the sidebar, as the Suggestions tab
 * saves them.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error The message and the words now marked, or why nothing changed.
 */
function undergrowth_rest_choose_words( WP_REST_Request $request ) {
	$source_id = (int) $request['id'];
	$target_id = (int) $request['target'];
	$result    = undergrowth_choose_words( $source_id, $target_id, (int) $request['first'], (int) $request['last'], (string) $request['shown'] );

	if ( 'words_saved' !== $result ) {
		return undergrowth_editor_link_error( $result );
	}

	return new WP_REST_Response( array( 'message' => undergrowth_link_result_messages()['words_saved'] ) + undergrowth_editor_words( $source_id, $target_id ) );
}

/**
 * Vetoes a suggestion from the sidebar.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error The message, or why nothing changed.
 */
function undergrowth_rest_veto_link( WP_REST_Request $request ) {
	$result = undergrowth_veto_result( (int) $request['id'], (int) $request['target'] );

	if ( 'vetoed' !== $result ) {
		return undergrowth_editor_link_error( $result );
	}

	return new WP_REST_Response( array( 'message' => undergrowth_link_result_messages()['vetoed'] ) );
}

/**
 * Builds the refusal for a link result, worded as everywhere else.
 *
 * @param string $result A key of undergrowth_link_result_messages().
 * @return WP_Error The refusal.
 */
function undergrowth_editor_link_error( string $result ): WP_Error {
	return new WP_Error( 'undergrowth_' . $result, undergrowth_link_result_messages()[ $result ], array( 'status' => 409 ) );
}

/**
 * Accepts the links the sidebar put in a post, once it is saved with them.
 *
 * Only a link to the target around the words Accept linked counts. A link
 * the author took out again before saving, even with another link to the
 * same post kept elsewhere, leaves its suggestion as it was. Either way the
 * note is cleared, so a link added later by hand is not counted as one
 * accepted through Undergrowth, unless the link is saved and only storing
 * the acceptance failed. Revisions and autosaves, including a draft's
 * autosave to the post itself, leave the note for the real save.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post as saved.
 */
function undergrowth_accept_saved_links( $post_id, $post ): void {
	// A draft's autosave writes to the post itself rather than to a revision,
	// and may hold the editor's content from before a Redo.
	if ( ! $post instanceof WP_Post || 'revision' === $post->post_type || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
		return;
	}

	$pending = get_post_meta( $post->ID, 'undergrowth_pending_links', false );

	if ( array() === $pending ) {
		return;
	}

	delete_post_meta( $post->ID, 'undergrowth_pending_links' );

	$accepted = false;

	foreach ( $pending as $note ) {
		if ( ! is_array( $note ) || ! isset( $note['target'], $note['words'] ) ) {
			continue;
		}

		$target_id = (int) $note['target'];

		if ( ! undergrowth_content_links_words( $post->post_content, (string) get_permalink( $target_id ), (string) $note['words'] ) ) {
			continue;
		}

		if ( undergrowth_claim_link( $post->ID, $target_id, 'proposed', 'accepted' ) ) {
			$accepted = true;
		} elseif ( 'proposed' === undergrowth_link_state( $post->ID, $target_id ) ) {
			// Still proposed, so the claim itself failed, as on a database
			// error. The link is saved, so the next save tries again.
			add_post_meta( $post->ID, 'undergrowth_pending_links', $note );
		}
	}

	if ( $accepted ) {
		undergrowth_write_memory( $post->ID );
	}
}

/**
 * Tells whether content holds a link to an address around exactly some words.
 *
 * @param string $content Post content.
 * @param string $url     Address.
 * @param string $words   Words as a reader sees them.
 * @return bool True when such a link is there.
 */
function undergrowth_content_links_words( string $content, string $url, string $words ): bool {
	if ( '' === $url || '' === $words || ! preg_match_all( '#<a\b[^>]*>(.*?)</a\s*>#is', $content, $links, PREG_SET_ORDER ) ) {
		return false;
	}

	foreach ( $links as $link ) {
		$tag = new WP_HTML_Tag_Processor( $link[0] );

		if ( $tag->next_tag( array( 'tag_name' => 'A' ) ) && $tag->get_attribute( 'href' ) === $url && undergrowth_plain_line( $link[1] ) === $words ) {
			return true;
		}
	}

	return false;
}
