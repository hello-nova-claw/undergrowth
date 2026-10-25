<?php
/**
 * Scanning a draft for links, when the author asks.
 *
 * Drafts are never read on their own. Only a press of Scan this draft in the
 * editor sends one, once, to the provider named beside the button. Its
 * vector lives only for that request, and nothing about the draft is
 * stored: no vector, no suggestion, no per-post file. The suggestions go back
 * to the editor and are gone once its sidebar closes.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

// How long a scan may hold the author's turn, and how long they wait after one.
const UNDERGROWTH_SCAN_RUNNING  = 60;
const UNDERGROWTH_SCAN_COOLDOWN = 10;

add_action( 'rest_api_init', 'undergrowth_register_draft_scan_routes' );

/**
 * Registers the routes that describe, run and accept a draft scan.
 */
function undergrowth_register_draft_scan_routes(): void {
	$post = array(
		'id' => array(
			'type'     => 'integer',
			'required' => true,
		),
	);

	register_rest_route(
		'undergrowth/v1',
		'/posts/(?P<id>\d+)/scan',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'undergrowth_rest_describe_scan',
				'permission_callback' => 'undergrowth_rest_can_edit_post',
				'args'                => $post,
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'undergrowth_rest_scan_draft',
				'permission_callback' => 'undergrowth_rest_can_edit_post',
				'args'                => $post + array(
					'title'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'content'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'password' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			),
		)
	);

	register_rest_route(
		'undergrowth/v1',
		'/posts/(?P<id>\d+)/scan/accept',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'undergrowth_rest_accept_scanned_link',
			'permission_callback' => 'undergrowth_rest_can_edit_post',
			'args'                => $post + array(
				'target'     => array(
					'type'     => 'integer',
					'required' => true,
				),
				'sentence'   => array(
					'type'     => 'string',
					'required' => true,
				),
				'offset'     => array(
					'type'     => 'integer',
					'minimum'  => 0,
					'required' => true,
				),
				'length'     => array(
					'type'     => 'integer',
					'minimum'  => 1,
					'required' => true,
				),
				'paragraphs' => undergrowth_editor_paragraphs_arg(),
			),
		)
	);
}

/**
 * Words the reasons a draft cannot be scanned.
 *
 * @return array<string, string> Message keyed by reason.
 */
function undergrowth_scan_messages(): array {
	return array(
		'no_provider'      => __( 'Scanning a draft needs something to read it with. Add an OpenAI key in Settings › Undergrowth.', 'undergrowth' ),
		'suggestions_off'  => __( 'Link suggestions are turned off in Settings › Undergrowth, so drafts are not scanned.', 'undergrowth' ),
		'scan_private'     => __( 'This post is private or has a password, so it is never sent anywhere.', 'undergrowth' ),
		'scan_published'   => __( 'This post is published, so its suggestions are listed above.', 'undergrowth' ),
		'scan_not_indexed' => __( 'Undergrowth does not read this kind of post.', 'undergrowth' ),
		'scan_wait'        => __( 'A scan has just run. Wait a few seconds, then try again.', 'undergrowth' ),
		'scan_empty'       => __( 'There is nothing in this draft to scan yet.', 'undergrowth' ),
		'scan_failed'      => __( 'The draft could not be read just now, so there are no suggestions. Try again later.', 'undergrowth' ),
	);
}

/**
 * Tells why a post may not be scanned, if anything stops it.
 *
 * @param WP_Post $post     Post as saved.
 * @param string  $password Password the editor holds for it, saved or not.
 * @return string|null A key of undergrowth_scan_messages(), or null when it may be scanned.
 */
function undergrowth_scan_refusal( WP_Post $post, string $password = '' ): ?string {
	if ( ! in_array( $post->post_type, undergrowth_indexed_post_types(), true ) ) {
		return 'scan_not_indexed';
	}

	// The same rule as the index: text behind a password, or kept private,
	// never reaches a provider.
	if ( '' !== $post->post_password || '' !== $password || 'private' === $post->post_status ) {
		return 'scan_private';
	}

	if ( 'publish' === $post->post_status ) {
		return 'scan_published';
	}

	if ( ! undergrowth_suggestions_enabled() ) {
		return 'suggestions_off';
	}

	return undergrowth_has_embed_provider() ? null : 'no_provider';
}

/**
 * Names where a scan would send the draft, in the words Settings uses.
 *
 * @return string The sentence shown beside the button, or an empty string when nothing can read it.
 */
function undergrowth_scan_destination(): string {
	if ( ! undergrowth_has_embed_provider() ) {
		return '';
	}

	if ( ! undergrowth_embed_provider_set_by_code() ) {
		return __( 'Sends this draft’s title and text to OpenAI once, to compare it with your published posts. Nothing about the draft is kept.', 'undergrowth' );
	}

	$identity = undergrowth_embed_identity();

	return sprintf(
		/* translators: 1: provider name, such as ollama, 2: model name, such as nomic-embed-text. */
		__( 'Gives this draft’s title and text once to %1$s (%2$s), set up by code on this site, to compare it with your published posts. Where that code sends it depends on how it was set up. Nothing about the draft is kept.', 'undergrowth' ),
		$identity['provider'],
		$identity['model']
	);
}

/**
 * Says whether the post can be scanned, and where its text would go.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response Whether a scan is offered, the destination and, if not, why.
 */
function undergrowth_rest_describe_scan( WP_REST_Request $request ): WP_REST_Response {
	$post    = get_post( (int) $request['id'] );
	$refusal = $post instanceof WP_Post ? undergrowth_scan_refusal( $post ) : 'scan_not_indexed';

	return new WP_REST_Response(
		array(
			'available'   => null === $refusal,
			'destination' => undergrowth_scan_destination(),
			'unavailable' => null === $refusal ? '' : undergrowth_scan_messages()[ $refusal ],
		)
	);
}

/**
 * Scans the draft the editor holds and answers with link suggestions.
 *
 * One scan runs at a time for each author, with a short wait after it, so a
 * held-down key never sends the draft over and over.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error Suggestions, or why there are none.
 */
function undergrowth_rest_scan_draft( WP_REST_Request $request ) {
	$post = get_post( (int) $request['id'] );

	if ( ! $post instanceof WP_Post ) {
		return undergrowth_scan_error( 'scan_not_indexed', 409 );
	}

	$refusal = undergrowth_scan_refusal( $post, (string) $request['password'] );

	if ( null !== $refusal ) {
		return undergrowth_scan_error( $refusal, 409 );
	}

	$draft               = clone $post;
	$draft->post_title   = (string) $request['title'];
	$draft->post_content = (string) $request['content'];

	// Nothing would be sent, so the author's turn is not used up.
	if ( '' === trim( undergrowth_post_text( $draft ) ) ) {
		return undergrowth_scan_error( 'scan_empty', 409 );
	}

	$user = get_current_user_id();

	if ( ! undergrowth_claim_scan_turn( $user ) ) {
		return undergrowth_scan_error( 'scan_wait', 429 );
	}

	try {
		$outgoing = undergrowth_scan_draft( $draft );
	} finally {
		update_user_meta( $user, 'undergrowth_scan_until', time() + UNDERGROWTH_SCAN_COOLDOWN );
	}

	if ( null === $outgoing ) {
		return undergrowth_scan_error( 'scan_failed', 502 );
	}

	return new WP_REST_Response( array( 'outgoing' => $outgoing ) );
}

/**
 * Claims the author's turn to scan, unless a scan holds it or has just run.
 *
 * One conditional update both checks and claims the turn, so two requests
 * arriving together can never both pass and both send the draft.
 *
 * @param int $user User ID.
 * @return bool True when this request holds the turn.
 */
function undergrowth_claim_scan_turn( int $user ): bool {
	global $wpdb;

	add_user_meta( $user, 'undergrowth_scan_until', '0', true );

	$now = time();

	// Times have ten digits until the year 2286, and '0' sorts first, so
	// comparing the stored text compares the times.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Only the database can check and claim the turn in one step; the cache is cleared below.
	$claimed = $wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET meta_value = %s WHERE user_id = %d AND meta_key = %s AND meta_value <= %s',
			$wpdb->usermeta,
			(string) ( $now + UNDERGROWTH_SCAN_RUNNING ),
			$user,
			'undergrowth_scan_until',
			(string) $now
		)
	);

	wp_cache_delete( $user, 'user_meta' );

	return is_int( $claimed ) && $claimed > 0;
}

/**
 * Builds the refusal for a scan.
 *
 * @param string $reason A key of undergrowth_scan_messages().
 * @param int    $status HTTP status.
 * @return WP_Error The refusal.
 */
function undergrowth_scan_error( string $reason, int $status ): WP_Error {
	return new WP_Error( 'undergrowth_' . $reason, undergrowth_scan_messages()[ $reason ], array( 'status' => $status ) );
}

/**
 * Finds the published posts a draft could link to, and the words in it each
 * link would sit on.
 *
 * The ranking is the one a published post gets: the same provider, model
 * and language only, the site's measured threshold, and no post the draft
 * already links to or the author has decided on. Nothing is written.
 *
 * @param WP_Post $draft Post with the title and content the editor holds.
 * @return array<int, array{target: int, title: string, url: string, sentence: string, span: array{offset: int, length: int}}>|null
 *         Suggestions, best first, or null when the draft could not be read.
 */
function undergrowth_scan_draft( WP_Post $draft ): ?array {
	global $wpdb;

	$vector = undergrowth_embed( undergrowth_post_text( $draft ) );

	if ( array() === $vector ) {
		return null;
	}

	$identity = undergrowth_embed_identity();
	$pool     = undergrowth_vectors_in_space( $identity['provider'], $identity['model'], undergrowth_post_language( $draft ) );

	unset( $pool[ $draft->ID ] );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; states change on every refresh.
	$decided  = $wpdb->get_col( $wpdb->prepare( 'SELECT target_id FROM %i WHERE source_id = %d AND state IN (%s, %s)', undergrowth_links_table(), $draft->ID, 'accepted', 'vetoed' ) );
	$excluded = array_flip( array_merge( undergrowth_linked_post_ids( $draft ), array_map( 'intval', $decided ) ) );
	$minimum  = undergrowth_min_link_similarity();
	$scores   = array();

	foreach ( $pool as $target_id => $packed ) {
		$target = undergrowth_unpack_vector( $packed );

		if ( isset( $excluded[ $target_id ] ) || ! undergrowth_vectors_comparable( $vector, $target ) ) {
			continue;
		}

		$score = undergrowth_cosine( $vector, $target );

		if ( $score >= $minimum ) {
			$scores[ $target_id ] = $score;
		}
	}

	arsort( $scores );

	$corpus   = undergrowth_build_corpus( array_keys( $pool ) );
	$outgoing = array();
	$taken    = array();

	foreach ( undergrowth_open_targets( $scores ) as $target_id => $score ) {
		$sentence = undergrowth_choose_sentence_in( $draft, $target_id, $corpus, $taken );
		$target   = get_post( $target_id );
		$choice   = null === $sentence || ! $target instanceof WP_Post ? null : undergrowth_choose_link_span( $draft, $target, $sentence['text'], $corpus );

		if ( null === $choice ) {
			continue;
		}

		$taken[]    = $sentence['text'];
		$outgoing[] = array(
			'target'   => $target_id,
			'title'    => undergrowth_editor_title( $target_id ),
			'url'      => (string) get_permalink( $target ),
			'sentence' => $sentence['text'],
			'span'     => array(
				'offset' => $choice['offset'],
				'length' => mb_strlen( undergrowth_collapse_spaces( $choice['word'] ) ),
			),
		);

		if ( count( $outgoing ) >= UNDERGROWTH_MAX_PROPOSALS ) {
			break;
		}
	}

	return $outgoing;
}

/**
 * Links a scanned suggestion's words in the paragraph the editor holds.
 *
 * There is no stored suggestion to accept, so nothing is recorded. The link
 * is the author's own once they save it.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error The paragraph's block ID and new HTML, or why nothing can be linked.
 */
function undergrowth_rest_accept_scanned_link( WP_REST_Request $request ) {
	$target = get_post( (int) $request['target'] );

	if ( ! $target instanceof WP_Post || ! undergrowth_is_indexable( $target ) ) {
		return undergrowth_editor_link_error( 'gone' );
	}

	$linked = undergrowth_link_in_paragraphs( (array) $request['paragraphs'], (string) $request['sentence'], (int) $request['offset'], (int) $request['length'], (string) get_permalink( $target ) );

	if ( is_string( $linked ) ) {
		return undergrowth_editor_link_error( $linked );
	}

	return new WP_REST_Response(
		array(
			'id'      => $linked['id'],
			'content' => $linked['content'],
			'message' => __( 'Linked in the editor. Save the post to keep the link.', 'undergrowth' ),
		)
	);
}
