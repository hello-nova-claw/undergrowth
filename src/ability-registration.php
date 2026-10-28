<?php
/**
 * Registering the abilities in abilities.php with the Abilities API in core:
 * their category, names, descriptions and schemas.
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
 * Describes the abilities that read has, lacks, scent and stage.
 *
 * Each names its label and description, the callback for one post, the
 * callback for many posts, and the shape of what it returns.
 *
 * @return array<string, array<string, mixed>> Definitions keyed by ability name.
 */
function undergrowth_read_ability_definitions(): array {
	return array(
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

	$abilities = undergrowth_read_ability_definitions();

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
