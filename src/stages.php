<?php
/**
 * Growth stages: where each post's stage is stored.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_STAGES = array( 'seedling', 'sprout', 'budding', 'evergreen', 'composting' );

// Late, so a filter that adds post types to the index has been added first.
add_action( 'init', 'undergrowth_register_stage_meta', 20 );
add_filter( 'is_protected_meta', 'undergrowth_protect_stage_meta', 10, 2 );
add_action( 'add_meta_boxes', 'undergrowth_remove_added_custom_fields_box', 99 );

/**
 * Registers the stage meta for every indexed post type, so themes and the
 * REST API can read it.
 *
 * A stage changes only on the monthly cycle or through an author action, so
 * no client may write it through REST. A post with nothing stored reads as a
 * seedling, the stage of a new post, so saving a post never has to write it.
 */
function undergrowth_register_stage_meta(): void {
	foreach ( undergrowth_indexed_post_types() as $post_type ) {
		// The REST API leaves out the meta of any post type without this
		// support. The Custom Fields box it would also switch on is removed
		// again, so the editor looks the same as before.
		if ( ! post_type_supports( $post_type, 'custom-fields' ) ) {
			add_post_type_support( $post_type, 'custom-fields' );
			undergrowth_custom_fields_added( $post_type );
		}

		register_post_meta(
			$post_type,
			'undergrowth_stage',
			array(
				'type'          => 'string',
				'description'   => __( 'Growth stage, measured from the links between posts or chosen by the author.', 'undergrowth' ),
				'single'        => true,
				'default'       => 'seedling',
				'show_in_rest'  => array(
					'schema' => array(
						'enum' => UNDERGROWTH_STAGES,
					),
				),
				'auth_callback' => '__return_false',
			)
		);

		register_post_meta(
			$post_type,
			'undergrowth_stage_pinned',
			array(
				'type'          => 'boolean',
				'description'   => __( 'Whether the author pinned the growth stage.', 'undergrowth' ),
				'single'        => true,
				'default'       => false,
				'show_in_rest'  => true,
				'auth_callback' => '__return_false',
			)
		);
	}
}

/**
 * Remembers the post types given custom-fields support only so the REST API
 * includes the stage keys.
 *
 * @param string $post_type Post type to add to the list, or an empty string to only read it.
 * @return string[] Post types on the list.
 */
function undergrowth_custom_fields_added( string $post_type = '' ): array {
	static $added = array();

	if ( '' !== $post_type ) {
		$added[ $post_type ] = true;
	}

	return array_keys( $added );
}

/**
 * Removes the Custom Fields box from post types that had no such box before
 * the plugin added the support.
 *
 * @param string $post_type Post type of the edit screen.
 */
function undergrowth_remove_added_custom_fields_box( $post_type ): void {
	if ( in_array( $post_type, undergrowth_custom_fields_added(), true ) ) {
		remove_meta_box( 'postcustom', $post_type, 'normal' );
	}
}

/**
 * Keeps the stage keys out of the Custom Fields box, where they could be
 * edited by hand.
 *
 * @param bool   $is_protected Whether the key is protected.
 * @param string $meta_key     Meta key.
 * @return bool True for the stage keys, otherwise unchanged.
 */
function undergrowth_protect_stage_meta( $is_protected, $meta_key ) {
	return in_array( $meta_key, array( 'undergrowth_stage', 'undergrowth_stage_pinned' ), true ) ? true : $is_protected;
}

/**
 * Returns a post's stage.
 *
 * @param int $post_id Post ID.
 * @return string One of UNDERGROWTH_STAGES. Seedling when nothing valid is stored.
 */
function undergrowth_get_stage( int $post_id ): string {
	$stage = get_post_meta( $post_id, 'undergrowth_stage', true );

	return in_array( $stage, UNDERGROWTH_STAGES, true ) ? $stage : 'seedling';
}

/**
 * Tells whether the author has pinned a post's stage.
 *
 * @param int $post_id Post ID.
 * @return bool True when pinned.
 */
function undergrowth_is_stage_pinned( int $post_id ): bool {
	return (bool) get_post_meta( $post_id, 'undergrowth_stage_pinned', true );
}

/**
 * Stores a post's stage.
 *
 * Every change of stage goes through here, so the rules about who may
 * change it are kept by the callers, not repeated for each meta write.
 *
 * @param int    $post_id Post ID.
 * @param string $stage   One of UNDERGROWTH_STAGES.
 * @return bool True when the post holds the stage afterwards.
 */
function undergrowth_set_stage( int $post_id, string $stage ): bool {
	if ( ! in_array( $stage, UNDERGROWTH_STAGES, true ) || ! get_post( $post_id ) instanceof WP_Post ) {
		return false;
	}

	update_post_meta( $post_id, 'undergrowth_stage', $stage );

	return undergrowth_get_stage( $post_id ) === $stage;
}
