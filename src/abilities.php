<?php
/**
 * Abilities: what the plugin knows about a post, for agents and other
 * plugins, through the Abilities API in core.
 *
 * Only the author's own words and the plugin's arithmetic go out. No vector
 * or other model output is ever returned, and the only sentences are quotes
 * from the post.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'undergrowth_register_ability_category' );
add_action( 'wp_abilities_api_init', 'undergrowth_register_read_abilities' );

/**
 * Registers the category every Undergrowth ability belongs to.
 */
function undergrowth_register_ability_category(): void {
	wp_register_ability_category(
		'undergrowth',
		array(
			'label'       => __( 'Undergrowth', 'undergrowth' ),
			'description' => __( 'Read what Undergrowth knows about a post.', 'undergrowth' ),
		)
	);
}

/**
 * Registers the abilities that read one post's has, lacks, scent and stage.
 *
 * Proposals and vetoes are the author's notes, like the per-post file, so
 * reading needs the right to edit the post. They are shown in REST, where
 * the same check applies, so another site can later ask over a signed request.
 */
function undergrowth_register_read_abilities(): void {
	$meta = array(
		'annotations'  => array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		),
		'show_in_rest' => true,
	);

	$abilities = array(
		'undergrowth/has'   => array(
			'label'         => __( 'What a post has', 'undergrowth' ),
			'description'   => __( 'Lists the words that most set a post apart from the rest of the site, taken from its own text, and the published posts it already links to.', 'undergrowth' ),
			'callback'      => 'undergrowth_ability_has',
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'words'   => array(
						'type'        => 'array',
						'description' => __( 'Words from the post, most distinctive first.', 'undergrowth' ),
						'items'       => array( 'type' => 'string' ),
					),
					'links'   => array(
						'type'        => 'array',
						'description' => __( 'Published posts the post links to.', 'undergrowth' ),
						'items'       => undergrowth_ability_post_schema(),
					),
				),
				'required'   => array( 'post_id', 'words', 'links' ),
			),
		),
		'undergrowth/lacks' => array(
			'label'         => __( 'What a post lacks', 'undergrowth' ),
			'description'   => __( 'Lists the links suggested for a post that the author has not yet accepted or vetoed, best first, each with the sentence from the post where it fits.', 'undergrowth' ),
			'callback'      => 'undergrowth_ability_lacks',
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'links'   => array(
						'type'        => 'array',
						'description' => __( 'Suggested links, best first.', 'undergrowth' ),
						'items'       => array(
							'type'       => 'object',
							'properties' => array_merge(
								undergrowth_ability_post_schema()['properties'],
								array(
									'sentence' => array(
										'type'        => 'string',
										'description' => __( 'The sentence from the post where the link fits, quoted with formatting removed.', 'undergrowth' ),
									),
								)
							),
							'required'   => array( 'post_id', 'title', 'url', 'sentence' ),
						),
					),
				),
				'required'   => array( 'post_id', 'links' ),
			),
		),
		'undergrowth/scent' => array(
			'label'         => __( 'A post’s scent', 'undergrowth' ),
			'description'   => __( 'Reports how readers move through a post. Undergrowth reads no reader data until reader mode, so for now the value is always empty.', 'undergrowth' ),
			'callback'      => 'undergrowth_ability_scent',
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'mode'    => array(
						'type' => 'string',
						'enum' => array( 'germination', 'reader' ),
					),
					'value'   => array( 'type' => array( 'number', 'null' ) ),
				),
				'required'   => array( 'post_id', 'mode', 'value' ),
			),
		),
		'undergrowth/stage' => array(
			'label'         => __( 'A post’s growth stage', 'undergrowth' ),
			'description'   => __( 'Reports a post’s growth stage, whether the author pinned it, and every recorded change to it, oldest first.', 'undergrowth' ),
			'callback'      => 'undergrowth_ability_stage',
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'stage'   => array(
						'type' => 'string',
						'enum' => UNDERGROWTH_STAGES,
					),
					'pinned'  => array( 'type' => 'boolean' ),
					'changes' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'date'   => array(
									'type'   => 'string',
									'format' => 'date-time',
								),
								'from'   => array(
									'type' => 'string',
									'enum' => UNDERGROWTH_STAGES,
								),
								'to'     => array(
									'type' => 'string',
									'enum' => UNDERGROWTH_STAGES,
								),
								'reason' => array(
									'type' => 'string',
									'enum' => array_keys( UNDERGROWTH_STAGE_REASONS ),
								),
							),
							'required'   => array( 'date', 'from', 'to', 'reason' ),
						),
					),
				),
				'required'   => array( 'post_id', 'stage', 'pinned', 'changes' ),
			),
		),
	);

	foreach ( $abilities as $name => $ability ) {
		wp_register_ability(
			$name,
			array(
				'label'               => $ability['label'],
				'description'         => $ability['description'],
				'category'            => 'undergrowth',
				'input_schema'        => undergrowth_ability_post_input_schema(),
				'output_schema'       => $ability['output_schema'],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					$post = undergrowth_ability_post( (int) $input['post_id'] );

					return $post instanceof WP_Post ? call_user_func( $ability['callback'], $post ) : $post;
				},
				'permission_callback' => 'undergrowth_ability_can_edit_post',
				'meta'                => $meta,
			)
		);
	}
}

/**
 * Returns the input schema of the abilities that read one post.
 *
 * @return array<string, mixed> JSON schema.
 */
function undergrowth_ability_post_input_schema(): array {
	return array(
		'type'                 => 'object',
		'properties'           => array(
			'post_id' => array(
				'type'        => 'integer',
				'description' => __( 'ID of a published post.', 'undergrowth' ),
				'minimum'     => 1,
			),
		),
		'required'             => array( 'post_id' ),
		'additionalProperties' => false,
	);
}

/**
 * Returns the schema of a post named in an ability's output.
 *
 * @return array<string, mixed> JSON schema.
 */
function undergrowth_ability_post_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'post_id' => array( 'type' => 'integer' ),
			'title'   => array( 'type' => 'string' ),
			'url'     => array(
				'type'   => 'string',
				'format' => 'uri',
			),
		),
		'required'   => array( 'post_id', 'title', 'url' ),
	);
}

/**
 * Tells whether the current user may read or act on a post's fields.
 *
 * @param mixed $input Ability input.
 * @return bool True when the user can edit the post.
 */
function undergrowth_ability_can_edit_post( $input ): bool {
	return is_array( $input ) && isset( $input['post_id'] ) && current_user_can( 'edit_post', (int) $input['post_id'] );
}

/**
 * Returns the post an ability asks about, if it is in the index.
 *
 * @param int $post_id Post ID.
 * @return WP_Post|WP_Error The post, or why it cannot be read.
 */
function undergrowth_ability_post( int $post_id ) {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || ! undergrowth_is_indexable( $post ) ) {
		return new WP_Error(
			'undergrowth_not_indexed',
			__( 'That post is not published, so Undergrowth knows nothing about it.', 'undergrowth' ),
			array( 'status' => 404 )
		);
	}

	return $post;
}

/**
 * Describes a post named in an ability's output.
 *
 * @param WP_Post $post Post.
 * @return array{post_id: int, title: string, url: string}
 */
function undergrowth_ability_describe_post( WP_Post $post ): array {
	return array(
		'post_id' => $post->ID,
		'title'   => get_the_title( $post ),
		'url'     => (string) get_permalink( $post ),
	);
}

/**
 * Answers undergrowth/has: what a post already covers and connects to.
 *
 * The words are weighed against the posts it can be compared with, as for
 * choosing a sentence, so they read the same way the suggestions do.
 *
 * @param WP_Post $post Post.
 * @return array{post_id: int, words: string[], links: array<int, array{post_id: int, title: string, url: string}>}
 */
function undergrowth_ability_has( WP_Post $post ): array {
	$corpus = undergrowth_build_corpus( array_unique( array_merge( array_keys( undergrowth_comparable_vectors( $post->ID ) ), array( $post->ID ) ) ) );
	$links  = array();

	foreach ( undergrowth_linked_post_ids( $post ) as $target_id ) {
		$target = get_post( $target_id );

		if ( $target instanceof WP_Post && $target->ID !== $post->ID && undergrowth_is_indexable( $target ) ) {
			$links[] = undergrowth_ability_describe_post( $target );
		}
	}

	return array(
		'post_id' => $post->ID,
		'words'   => array_map( 'strval', array_keys( undergrowth_distinctive_terms( $post->ID, $corpus ) ) ),
		'links'   => $links,
	);
}

/**
 * Answers undergrowth/lacks: the links a post does not have yet.
 *
 * The score stays behind: it is arithmetic on vectors, and the order
 * already says which suggestion is best.
 *
 * @param WP_Post $post Post.
 * @return array{post_id: int, links: array<int, array{post_id: int, title: string, url: string, sentence: string}>}
 */
function undergrowth_ability_lacks( WP_Post $post ): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every refresh.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT target_id, sentence FROM %i WHERE source_id = %d AND state = %s ORDER BY score DESC, target_id ASC',
			undergrowth_links_table(),
			$post->ID,
			'proposed'
		),
		ARRAY_A
	);

	$links = array();

	foreach ( (array) $rows as $row ) {
		$target = get_post( (int) $row['target_id'] );

		if ( $target instanceof WP_Post && undergrowth_is_indexable( $target ) ) {
			$links[] = undergrowth_ability_describe_post( $target ) + array( 'sentence' => (string) $row['sentence'] );
		}
	}

	return array(
		'post_id' => $post->ID,
		'links'   => $links,
	);
}

/**
 * Answers undergrowth/scent: how readers move through a post.
 *
 * Germination mode reads no reader data, so there is no value yet. The
 * shape is fixed now so callers need not change when reader mode arrives.
 *
 * @param WP_Post $post Post.
 * @return array{post_id: int, mode: string, value: null}
 */
function undergrowth_ability_scent( WP_Post $post ): array {
	return array(
		'post_id' => $post->ID,
		'mode'    => 'germination',
		'value'   => null,
	);
}

/**
 * Answers undergrowth/stage: a post's growth stage and its changes.
 *
 * Composting is never pinned, as in the per-post file.
 *
 * @param WP_Post $post Post.
 * @return array{post_id: int, stage: string, pinned: bool, changes: array<int, array{date: string, from: string, to: string, reason: string}>}
 */
function undergrowth_ability_stage( WP_Post $post ): array {
	$stage   = undergrowth_get_stage( $post->ID );
	$changes = array();

	foreach ( undergrowth_stage_log( $post->ID ) as $change ) {
		$changes[] = array(
			'date'   => gmdate( 'c', $change['time'] ),
			'from'   => $change['from'],
			'to'     => $change['to'],
			'reason' => $change['reason'],
		);
	}

	return array(
		'post_id' => $post->ID,
		'stage'   => $stage,
		'pinned'  => 'composting' !== $stage && undergrowth_is_stage_pinned( $post->ID ),
		'changes' => $changes,
	);
}
