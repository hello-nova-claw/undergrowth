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
				'paragraphs' => array(
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
				),
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
			'outgoing' => $outgoing,
			'incoming' => $incoming,
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
	$words = (string) preg_replace( '/\s+/u', ' ', $choice['word'] );

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

	$result = 'edited';

	foreach ( (array) $request['paragraphs'] as $paragraph ) {
		// The paragraph alone, as its block would sit in a post, so the rules
		// for where a link may go are the ones Accept uses on a whole post.
		$open    = "<!-- wp:paragraph -->\n<p>";
		$close   = "</p>\n<!-- /wp:paragraph -->";
		$content = $open . $paragraph['content'] . $close;

		if ( ! in_array( $span['sentence'], array_column( undergrowth_split_sentences( $content ), 'text' ), true ) ) {
			continue;
		}

		$found = undergrowth_find_link_span( $content, $span['sentence'], $span['offset'], $span['length'] );

		if ( null === $found ) {
			$result = 'no_word';
			continue;
		}

		$linked = undergrowth_wrap_link_word( $content, $found, (string) get_permalink( $target ) );

		if ( ! in_array( (string) $target_id, get_post_meta( $source_id, 'undergrowth_pending_links' ), true ) ) {
			add_post_meta( $source_id, 'undergrowth_pending_links', (string) $target_id );
		}

		return new WP_REST_Response(
			array(
				'id'      => $paragraph['id'],
				'content' => substr( $linked, strlen( $open ), -strlen( $close ) ),
				'message' => __( 'Linked in the editor. Save the post to keep the link.', 'undergrowth' ),
			)
		);
	}

	return undergrowth_editor_link_error( $result );
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
 * A link the author took out again before saving leaves its suggestion as
 * it was. Either way the note is cleared, so a link added later by hand is
 * not counted as one accepted through Undergrowth. Revisions and autosaves
 * are not the post, so they leave the note for the real save.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post as saved.
 */
function undergrowth_accept_saved_links( $post_id, $post ): void {
	if ( ! $post instanceof WP_Post || 'revision' === $post->post_type ) {
		return;
	}

	$pending = get_post_meta( $post->ID, 'undergrowth_pending_links' );

	if ( array() === $pending ) {
		return;
	}

	delete_post_meta( $post->ID, 'undergrowth_pending_links' );

	$accepted = false;

	foreach ( array_unique( array_map( 'intval', $pending ) ) as $target_id ) {
		if ( undergrowth_content_links_to( $post->post_content, (string) get_permalink( $target_id ) ) && undergrowth_claim_link( $post->ID, $target_id, 'proposed', 'accepted' ) ) {
			$accepted = true;
		}
	}

	if ( $accepted ) {
		undergrowth_write_memory( $post->ID );
	}
}

/**
 * Tells whether content holds a link to an address.
 *
 * @param string $content Post content.
 * @param string $url     Address.
 * @return bool True when a link points there.
 */
function undergrowth_content_links_to( string $content, string $url ): bool {
	if ( '' === $url ) {
		return false;
	}

	$tags = new WP_HTML_Tag_Processor( $content );

	while ( $tags->next_tag( 'A' ) ) {
		if ( $tags->get_attribute( 'href' ) === $url ) {
			return true;
		}
	}

	return false;
}
