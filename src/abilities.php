<?php
/**
 * Abilities: what the plugin knows about a post, and the author's answer to
 * a suggestion, for agents and other plugins, through the Abilities API in core.
 *
 * Only the author's own words and the plugin's arithmetic go out. No vector
 * or other model output is ever returned, and the only sentences are quotes
 * from the post.
 *
 * The read abilities keep the fractal contract: the same fields answer for one
 * post, one cluster or the whole site, and only posts the current user can
 * edit are ever counted. One post's words are weighed against every post it
 * can be compared with; a cluster's or the site's against the posts in the
 * garden the user can edit, so one post can show different words alone than
 * as part of a set.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'undergrowth_register_ability_category' );
add_action( 'wp_abilities_api_init', 'undergrowth_register_read_abilities' );
add_action( 'wp_abilities_api_init', 'undergrowth_register_write_abilities' );

/**
 * Registers the category every Undergrowth ability belongs to.
 */
function undergrowth_register_ability_category(): void {
	wp_register_ability_category(
		'undergrowth',
		array(
			'label'       => __( 'Undergrowth', 'undergrowth' ),
			'description' => __( 'Read what Undergrowth knows about a post, a cluster or the whole site, and accept or veto the links it suggests.', 'undergrowth' ),
		)
	);
}

/**
 * Registers the abilities that read has, lacks, scent and stage for a post,
 * a cluster or the whole site.
 *
 * Proposals and vetoes are the author's notes, like the per-post file, so
 * reading needs the right to edit the post, and a cluster or the site is read
 * only through the posts the user can edit. They are shown in REST, where the
 * same check applies, so another site can later ask over a signed request.
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
			'label'         => __( 'What a post, cluster or site has', 'undergrowth' ),
			'description'   => __( 'Lists the words that most set the posts apart from the rest of the site, taken from their own text, and the published posts they already link to.', 'undergrowth' ),
			'callback'      => 'undergrowth_ability_has',
			'many'          => 'undergrowth_ability_has_many',
			'output_schema' => array(
				'type'       => 'object',
				'properties' => undergrowth_ability_scope_schema()
					+ array(
						'words' => array(
							'type'        => 'array',
							'description' => __( 'Words from the posts, most distinctive first.', 'undergrowth' ),
							'items'       => array( 'type' => 'string' ),
						),
						'links' => array(
							'type'        => 'array',
							'description' => __( 'Published posts the posts link to.', 'undergrowth' ),
							'items'       => undergrowth_ability_post_schema(),
						),
					),
				'required'   => array( 'scale', 'words', 'links' ),
			),
		),
		'undergrowth/lacks' => array(
			'label'         => __( 'What a post, cluster or site lacks', 'undergrowth' ),
			'description'   => __( 'Lists the links suggested for the posts that the author has not yet accepted or vetoed, best first, each with the post it would sit in and the sentence from that post where it fits.', 'undergrowth' ),
			'callback'      => 'undergrowth_ability_lacks',
			'many'          => 'undergrowth_ability_lacks_many',
			'output_schema' => array(
				'type'       => 'object',
				'properties' => undergrowth_ability_scope_schema()
					+ array(
						'links' => array(
							'type'        => 'array',
							'description' => __( 'Suggested links, best first.', 'undergrowth' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array_merge(
									array(
										'source_id' => array(
											'type'        => 'integer',
											'description' => __( 'ID of the post the link would sit in.', 'undergrowth' ),
										),
									),
									undergrowth_ability_post_schema()['properties'],
									array(
										'sentence' => array(
											'type'        => 'string',
											'description' => __( 'The sentence from the post where the link fits, quoted with formatting removed.', 'undergrowth' ),
										),
									)
								),
								'required'   => array( 'source_id', 'post_id', 'title', 'url', 'sentence' ),
							),
						),
					),
				'required'   => array( 'scale', 'links' ),
			),
		),
		'undergrowth/scent' => array(
			'label'         => __( 'The scent of a post, cluster or site', 'undergrowth' ),
			'description'   => __( 'Reports how readers move through the posts. Undergrowth reads no reader data until reader mode, so for now the value is always empty.', 'undergrowth' ),
			'callback'      => 'undergrowth_ability_scent',
			'many'          => 'undergrowth_ability_scent_many',
			'output_schema' => array(
				'type'       => 'object',
				'properties' => undergrowth_ability_scope_schema()
					+ array(
						'mode'  => array(
							'type' => 'string',
							'enum' => array( 'germination', 'reader' ),
						),
						'value' => array( 'type' => array( 'number', 'null' ) ),
					),
				'required'   => array( 'scale', 'mode', 'value' ),
			),
		),
		'undergrowth/stage' => array(
			'label'         => __( 'Growth stages of a post, cluster or site', 'undergrowth' ),
			'description'   => __( 'Counts the posts at each growth stage, with composting posts counted apart. For one post it also reports its stage, whether the author pinned it, and every recorded change to it, oldest first.', 'undergrowth' ),
			'callback'      => 'undergrowth_ability_stage',
			'many'          => 'undergrowth_ability_stage_many',
			'output_schema' => array(
				'type'       => 'object',
				'properties' => undergrowth_ability_scope_schema() + array(
					'counts'  => array(
						'type'        => 'object',
						'description' => __( 'Number of posts at each stage. Composting posts are counted apart and are in no other stage.', 'undergrowth' ),
						'properties'  => array_fill_keys( UNDERGROWTH_STAGES, array( 'type' => 'integer' ) ),
						'required'    => UNDERGROWTH_STAGES,
					),
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
				'required'   => array( 'scale', 'counts' ),
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
				'input_schema'        => undergrowth_ability_scope_input_schema(),
				'output_schema'       => $ability['output_schema'],
				'execute_callback'    => static function ( $input ) use ( $ability ) {
					$scope = undergrowth_ability_scope( is_array( $input ) ? $input : array() );

					if ( is_wp_error( $scope ) ) {
						return $scope;
					}

					if ( 'post' === $scope['scale'] ) {
						return call_user_func( $ability['callback'], get_post( $scope['post_id'] ) );
					}

					return undergrowth_ability_scope_head( $scope ) + call_user_func( $ability['many'], $scope['posts'], $scope['scale'] );
				},
				'permission_callback' => 'undergrowth_ability_can_read',
				'meta'                => $meta,
			)
		);
	}
}

/**
 * Registers the abilities that accept or veto a suggested link.
 *
 * Each changes the site, so neither is ever public: they are kept out of
 * REST and MCP, and can only be run from code on the site itself, by a user
 * who can edit the post the link would sit in.
 */
function undergrowth_register_write_abilities(): void {
	$abilities = array(
		'undergrowth/accept-link' => array(
			'label'       => __( 'Accept a link', 'undergrowth' ),
			'description' => __( 'Accepts a suggested link by linking a few of the author’s own words in the suggested sentence to the suggested post, exactly as the Accept button does. Nothing changes when the words cannot be linked safely.', 'undergrowth' ),
			'callback'    => 'undergrowth_ability_accept_link',
			'state'       => 'accepted',
		),
		'undergrowth/veto-link'   => array(
			'label'       => __( 'Veto a link', 'undergrowth' ),
			'description' => __( 'Vetoes a link between two posts, so it is never suggested again. An accepted link is left as it is.', 'undergrowth' ),
			'callback'    => 'undergrowth_ability_veto_link',
			'state'       => 'vetoed',
		),
	);

	foreach ( $abilities as $name => $ability ) {
		wp_register_ability(
			$name,
			array(
				'label'               => $ability['label'],
				'description'         => $ability['description'],
				'category'            => 'undergrowth',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'source_id' => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post the link sits in.', 'undergrowth' ),
							'minimum'     => 1,
						),
						'target_id' => array(
							'type'        => 'integer',
							'description' => __( 'ID of the post the link points to.', 'undergrowth' ),
							'minimum'     => 1,
						),
					),
					'required'             => array( 'source_id', 'target_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'source_id' => array( 'type' => 'integer' ),
						'target_id' => array( 'type' => 'integer' ),
						'state'     => array(
							'type' => 'string',
							'enum' => array( $ability['state'] ),
						),
					),
					'required'   => array( 'source_id', 'target_id', 'state' ),
				),
				'execute_callback'    => $ability['callback'],
				'permission_callback' => 'undergrowth_ability_can_edit_source_post',
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'public'       => false,
					'show_in_rest' => false,
					'mcp'          => array( 'public' => false ),
				),
			)
		);
	}
}

/**
 * Returns the input schema of the read abilities: a post, a cluster, or
 * neither for the whole site.
 *
 * @return array<string, mixed> JSON schema.
 */
function undergrowth_ability_scope_input_schema(): array {
	return array(
		'type'                 => 'object',
		'properties'           => array(
			'post_id' => array(
				'type'        => 'integer',
				'description' => __( 'ID of a published post. Leave out, with cluster, to ask about the whole site.', 'undergrowth' ),
				'minimum'     => 1,
			),
			'cluster' => array(
				'type'        => 'integer',
				'description' => __( 'ID of a cluster from the monthly clustering, which is the ID of the post that leads it. Leave out, with post_id, to ask about the whole site.', 'undergrowth' ),
				'minimum'     => 1,
			),
		),
		'maxProperties'        => 1,
		'additionalProperties' => false,
		'default'              => array(),
	);
}

/**
 * Returns the schema of the fields that say what a read ability answered for.
 *
 * @return array<string, mixed> JSON schema properties.
 */
function undergrowth_ability_scope_schema(): array {
	return array(
		'scale'   => array(
			'type' => 'string',
			'enum' => array( 'post', 'cluster', 'site' ),
		),
		'post_id' => array(
			'type'        => 'integer',
			'description' => __( 'The post, at post scale.', 'undergrowth' ),
		),
		'cluster' => array(
			'type'        => 'integer',
			'description' => __( 'The cluster, at cluster scale.', 'undergrowth' ),
		),
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
 * Tells whether the current user may read the fields asked for.
 *
 * A cluster or the site is read only through the posts the user can edit,
 * so anyone who can edit posts of an indexed type may ask; each post is
 * checked when counted.
 *
 * @param mixed $input Ability input.
 * @return bool True when the user can edit the post, or can edit posts of an indexed type.
 */
function undergrowth_ability_can_read( $input ): bool {
	if ( is_array( $input ) && isset( $input['post_id'] ) ) {
		return current_user_can( 'edit_post', (int) $input['post_id'] );
	}

	foreach ( undergrowth_indexed_post_types() as $post_type ) {
		$object = get_post_type_object( $post_type );

		if ( null !== $object && current_user_can( $object->cap->edit_posts ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Tells whether the current user may accept or veto a link from a post.
 *
 * @param mixed $input Ability input.
 * @return bool True when the user can edit the post the link sits in.
 */
function undergrowth_ability_can_edit_source_post( $input ): bool {
	return is_array( $input ) && isset( $input['source_id'] ) && current_user_can( 'edit_post', (int) $input['source_id'] );
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
			__( 'Undergrowth does not read that post, so it knows nothing about it. Only published posts without a password are read.', 'undergrowth' ),
			array( 'status' => 404 )
		);
	}

	return $post;
}

/**
 * Works out which posts a read ability answers for.
 *
 * A cluster the user can see no post of is refused as if it did not exist,
 * so nothing about posts they cannot edit is given away.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array{scale: string, post_id?: int, cluster?: int, posts: int[]}|WP_Error The scope, or why it cannot be read.
 */
function undergrowth_ability_scope( array $input ) {
	if ( isset( $input['post_id'] ) ) {
		$post = undergrowth_ability_post( (int) $input['post_id'] );

		return $post instanceof WP_Post
			? array(
				'scale'   => 'post',
				'post_id' => $post->ID,
				'posts'   => array( $post->ID ),
			)
			: $post;
	}

	if ( ! isset( $input['cluster'] ) ) {
		return array(
			'scale' => 'site',
			'posts' => undergrowth_ability_site_posts(),
		);
	}

	$cluster = (int) $input['cluster'];
	$members = undergrowth_cluster_posts( $cluster );
	$posts   = array();

	if ( count( $members ) >= UNDERGROWTH_MIN_CLUSTER_SIZE ) {
		foreach ( $members as $post_id ) {
			if ( undergrowth_can_cluster( $post_id ) && current_user_can( 'edit_post', $post_id ) ) {
				$posts[] = $post_id;
			}
		}
	}

	if ( array() === $posts ) {
		return new WP_Error(
			'undergrowth_no_cluster',
			__( 'There is no such cluster among the posts you can edit. Clusters are made once a month.', 'undergrowth' ),
			array( 'status' => 404 )
		);
	}

	return array(
		'scale'   => 'cluster',
		'cluster' => $cluster,
		'posts'   => $posts,
	);
}

/**
 * Lists every post in the index that the current user can edit.
 *
 * Composting posts are included, so they can be counted apart. For each
 * indexed post type, someone who cannot edit its published posts gets none,
 * and someone who cannot edit other people's is only asked about their own,
 * before any post is loaded.
 * Posts are checked a chunk at a time and their rows dropped again, so a
 * long archive never sits in memory; their stages stay, to be counted.
 *
 * @return int[] Post IDs, lowest first.
 */
function undergrowth_ability_site_posts(): array {
	$ids = array();

	foreach ( undergrowth_indexed_post_types() as $post_type ) {
		$object = get_post_type_object( $post_type );

		if ( null === $object || ! current_user_can( $object->cap->edit_published_posts ) ) {
			continue;
		}

		$args = undergrowth_garden_query_args(
			array(
				'post_type'      => $post_type,
				'posts_per_page' => -1,
			)
		);

		if ( ! current_user_can( $object->cap->edit_others_posts ) ) {
			$args['author'] = get_current_user_id();
		}

		$query = new WP_Query( $args );
		$ids   = array_merge( $ids, array_map( 'intval', $query->posts ) );
	}

	sort( $ids );
	$editable = array();

	foreach ( array_chunk( $ids, UNDERGROWTH_POST_CHUNK ) as $chunk ) {
		_prime_post_caches( $chunk, false, true );

		foreach ( $chunk as $post_id ) {
			if ( current_user_can( 'edit_post', $post_id ) ) {
				$editable[] = $post_id;
			}

			wp_cache_delete( $post_id, 'posts' );
		}
	}

	return $editable;
}

/**
 * Starts a cluster or site answer with what it answers for.
 *
 * @param array{scale: string, post_id?: int, cluster?: int, posts: int[]} $scope Scope.
 * @return array<string, mixed> The scale, and the cluster at cluster scale.
 */
function undergrowth_ability_scope_head( array $scope ): array {
	return array_intersect_key( $scope, array_flip( array( 'scale', 'cluster' ) ) );
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
 * @return array{scale: string, post_id: int, words: string[], links: array<int, array{post_id: int, title: string, url: string}>}
 */
function undergrowth_ability_has( WP_Post $post ): array {
	$corpus = undergrowth_reusable_corpus( array_merge( undergrowth_comparable_post_ids( $post->ID ), array( $post->ID ) ) );

	return array(
		'scale'   => 'post',
		'post_id' => $post->ID,
		'words'   => array_map( 'strval', array_keys( undergrowth_distinctive_terms( $post->ID, $corpus ) ) ),
		'links'   => undergrowth_ability_linked_posts( $post ),
	);
}

/**
 * Lists the published posts a post links to.
 *
 * @param WP_Post $post Post.
 * @return array<int, array{post_id: int, title: string, url: string}>
 */
function undergrowth_ability_linked_posts( WP_Post $post ): array {
	$links = array();

	foreach ( undergrowth_linked_post_ids( $post ) as $target_id ) {
		$target = get_post( $target_id );

		if ( $target instanceof WP_Post && $target->ID !== $post->ID && undergrowth_is_indexable( $target ) ) {
			$links[] = undergrowth_ability_describe_post( $target );
		}
	}

	return $links;
}

/**
 * Answers undergrowth/has for a cluster or the site.
 *
 * Each post's distinctive words are weighed against every post the user can
 * edit, and the weights are added up, so a word many of the posts lean on
 * rises. Composting posts have left the garden and add nothing.
 *
 * @param int[]  $post_ids Posts the user can edit.
 * @param string $scale    'cluster' or 'site'. At site scale the posts are
 *                         already every post the user can edit.
 * @return array{words: string[], links: array<int, array{post_id: int, title: string, url: string}>}
 */
function undergrowth_ability_has_many( array $post_ids, string $scale = 'cluster' ): array {
	$growing = undergrowth_ability_growing_posts( $post_ids );
	$corpus  = undergrowth_reusable_corpus( 'site' === $scale ? $growing : undergrowth_ability_growing_posts( undergrowth_ability_site_posts() ) );
	$weights = array();
	$links   = array();

	foreach ( $growing as $post_id ) {
		foreach ( undergrowth_distinctive_terms( $post_id, $corpus ) as $word => $weight ) {
			$weights[ (string) $word ] = ( $weights[ (string) $word ] ?? 0.0 ) + $weight;
		}

		foreach ( undergrowth_ability_linked_posts( get_post( $post_id ) ) as $link ) {
			$links[ $link['post_id'] ] = $link;
		}
	}

	uksort(
		$weights,
		static function ( $a, $b ) use ( $weights ) {
			$order = $weights[ $b ] <=> $weights[ $a ];

			return 0 !== $order ? $order : strcmp( (string) $a, (string) $b );
		}
	);

	return array(
		'words' => array_map( 'strval', array_keys( array_slice( $weights, 0, UNDERGROWTH_DISTINCTIVE_TERMS, true ) ) ),
		'links' => array_values( $links ),
	);
}

/**
 * Leaves out the composting posts, which have left the garden.
 *
 * @param int[] $post_ids Post IDs.
 * @return int[] Post IDs that are not composting, in the same order.
 */
function undergrowth_ability_growing_posts( array $post_ids ): array {
	$growing = array();

	foreach ( $post_ids as $post_id ) {
		if ( 'composting' !== undergrowth_get_stage( $post_id ) ) {
			$growing[] = $post_id;
		}
	}

	return $growing;
}

/**
 * Answers undergrowth/lacks: the links a post does not have yet.
 *
 * @param WP_Post $post Post.
 * @return array{scale: string, post_id: int, links: array<int, array{source_id: int, post_id: int, title: string, url: string, sentence: string}>}
 */
function undergrowth_ability_lacks( WP_Post $post ): array {
	return array(
		'scale'   => 'post',
		'post_id' => $post->ID,
	) + undergrowth_ability_lacks_many( array( $post->ID ) );
}

/**
 * Answers undergrowth/lacks for any set of posts: the links they do not have
 * yet, best first across all of them.
 *
 * The score stays behind: it is arithmetic on vectors, and the order
 * already says which suggestion is best.
 *
 * @param int[] $post_ids Posts the user can edit.
 * @return array{links: array<int, array{source_id: int, post_id: int, title: string, url: string, sentence: string}>}
 */
function undergrowth_ability_lacks_many( array $post_ids ): array {
	global $wpdb;

	$links = array();

	foreach ( array_chunk( array_map( 'intval', $post_ids ), UNDERGROWTH_POST_CHUNK ) as $chunk ) {
		// Each post's sentences are kept for its own chunk only.
		$sentences    = array();
		$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The plugin's own table; there is one placeholder for each ID in the chunk.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT source_id, target_id, sentence, score FROM %i WHERE state = %s AND source_id IN (' . $placeholders . ')', undergrowth_links_table(), 'proposed', ...$chunk ), ARRAY_A );

		foreach ( $rows as $row ) {
			$source_id = (int) $row['source_id'];
			$target    = get_post( (int) $row['target_id'] );

			// While suggestions are off, an edit does not refresh them, so a stored
			// sentence may no longer be in the post. Only the author's current words go out.
			if ( ! isset( $sentences[ $source_id ] ) ) {
				$sentences[ $source_id ] = array_flip( array_column( undergrowth_split_sentences( (string) get_post_field( 'post_content', $source_id ) ), 'text' ) );
			}

			if ( isset( $sentences[ $source_id ][ (string) $row['sentence'] ] ) && $target instanceof WP_Post && undergrowth_is_indexable( $target ) ) {
				$links[] = array(
					'score' => (float) $row['score'],
					'link'  => array( 'source_id' => $source_id ) + undergrowth_ability_describe_post( $target ) + array( 'sentence' => (string) $row['sentence'] ),
				);
			}
		}
	}

	usort(
		$links,
		static function ( $a, $b ) {
			return array( $b['score'], $a['link']['source_id'], $a['link']['post_id'] ) <=> array( $a['score'], $b['link']['source_id'], $b['link']['post_id'] );
		}
	);

	return array( 'links' => array_column( $links, 'link' ) );
}

/**
 * Answers undergrowth/scent: how readers move through a post.
 *
 * @param WP_Post $post Post.
 * @return array{scale: string, post_id: int, mode: string, value: null}
 */
function undergrowth_ability_scent( WP_Post $post ): array {
	return array(
		'scale'   => 'post',
		'post_id' => $post->ID,
	) + undergrowth_ability_scent_many( array( $post->ID ) );
}

/**
 * Answers undergrowth/scent for any set of posts.
 *
 * Germination mode reads no reader data, so there is no value yet. The
 * shape is fixed now so callers need not change when reader mode arrives.
 *
 * @param int[] $post_ids Posts the user can edit.
 * @return array{mode: string, value: null}
 */
function undergrowth_ability_scent_many( array $post_ids ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Reader mode will read these posts.
	return array(
		'mode'  => 'germination',
		'value' => null,
	);
}

/**
 * Answers undergrowth/stage: a post's growth stage and its changes.
 *
 * Composting is never pinned, as in the per-post file.
 *
 * @param WP_Post $post Post.
 * @return array{scale: string, post_id: int, counts: array<string, int>, stage: string, pinned: bool, changes: array<int, array{date: string, from: string, to: string, reason: string}>}
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
		'scale'   => 'post',
		'post_id' => $post->ID,
	) + undergrowth_ability_stage_many( array( $post->ID ) ) + array(
		'stage'   => $stage,
		'pinned'  => 'composting' !== $stage && undergrowth_is_stage_pinned( $post->ID ),
		'changes' => $changes,
	);
}

/**
 * Answers undergrowth/stage for any set of posts: how many are at each stage.
 *
 * Composting is a stage of its own here, so resting posts never count
 * against the others.
 *
 * @param int[] $post_ids Posts the user can edit.
 * @return array{counts: array<string, int>}
 */
function undergrowth_ability_stage_many( array $post_ids ): array {
	$counts = array_fill_keys( UNDERGROWTH_STAGES, 0 );

	foreach ( $post_ids as $post_id ) {
		++$counts[ undergrowth_get_stage( $post_id ) ];
	}

	return array( 'counts' => $counts );
}

/**
 * Answers undergrowth/accept-link.
 *
 * @param array{source_id: int, target_id: int} $input Ability input.
 * @return array{source_id: int, target_id: int, state: string}|WP_Error The link, or why nothing changed.
 */
function undergrowth_ability_accept_link( array $input ) {
	$source_id = (int) $input['source_id'];
	$target_id = (int) $input['target_id'];
	$result    = undergrowth_accept_link( $source_id, $target_id );

	if ( 'accepted' !== $result ) {
		return undergrowth_ability_link_error( $result );
	}

	return array(
		'source_id' => $source_id,
		'target_id' => $target_id,
		'state'     => 'accepted',
	);
}

/**
 * Answers undergrowth/veto-link.
 *
 * @param array{source_id: int, target_id: int} $input Ability input.
 * @return array{source_id: int, target_id: int, state: string}|WP_Error The link, or why nothing changed.
 */
function undergrowth_ability_veto_link( array $input ) {
	$source_id = (int) $input['source_id'];
	$target_id = (int) $input['target_id'];

	$result = undergrowth_veto_result( $source_id, $target_id );

	if ( 'vetoed' !== $result ) {
		return undergrowth_ability_link_error( $result );
	}

	return array(
		'source_id' => $source_id,
		'target_id' => $target_id,
		'state'     => 'vetoed',
	);
}

/**
 * Says why accepting or vetoing a link changed nothing.
 *
 * The wording is the suggestions screen's, so the author reads the same
 * reason wherever the request came from.
 *
 * @param string $reason 'gone', 'unchanged', 'not_proposed', 'edited', 'no_word' or 'needs_admin'.
 * @return WP_Error The error.
 */
function undergrowth_ability_link_error( string $reason ): WP_Error {
	$messages = undergrowth_link_result_messages();

	return new WP_Error(
		'undergrowth_' . $reason,
		$messages[ $reason ] ?? $messages['gone'],
		array( 'status' => 'gone' === $reason ? 404 : 409 )
	);
}
