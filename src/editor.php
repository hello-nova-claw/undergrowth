<?php
/**
 * The Undergrowth panel in the block editor.
 *
 * The panel reads and changes a post's growth stage through one REST route,
 * which only someone who can edit the post may call. It acts through the
 * same functions as the Growth stages tab and the Posts list, so the rules
 * live in one place.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'undergrowth_register_editor_routes' );
add_action( 'enqueue_block_editor_assets', 'undergrowth_enqueue_editor_panel' );

/**
 * Registers the route the editor panel reads and changes the stage through.
 */
function undergrowth_register_editor_routes(): void {
	$post = array(
		'id' => array(
			'type'     => 'integer',
			'required' => true,
		),
	);

	register_rest_route(
		'undergrowth/v1',
		'/posts/(?P<id>\d+)/stage',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'undergrowth_rest_get_stage',
				'permission_callback' => 'undergrowth_rest_can_edit_post',
				'args'                => $post,
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'undergrowth_rest_change_stage',
				'permission_callback' => 'undergrowth_rest_can_edit_post',
				'args'                => $post + array(
					'action' => array(
						'type'     => 'string',
						'enum'     => array( 'pin', 'unpin', 'compost', 'return' ),
						'required' => true,
					),
					'stage'  => array(
						'type' => 'string',
						'enum' => UNDERGROWTH_GARDEN_STAGES,
					),
				),
			),
		)
	);
}

/**
 * Lets through only someone who can edit the post the route names.
 *
 * A post that does not exist is refused in the same way, so the route does
 * not say which IDs are in use.
 *
 * @param WP_REST_Request $request Request.
 * @return bool True when the user may edit the post.
 */
function undergrowth_rest_can_edit_post( WP_REST_Request $request ): bool {
	return current_user_can( 'edit_post', (int) $request['id'] );
}

/**
 * Answers with the post's stage, for the panel to show.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response The stage.
 */
function undergrowth_rest_get_stage( WP_REST_Request $request ): WP_REST_Response {
	return new WP_REST_Response( undergrowth_editor_stage( (int) $request['id'] ) );
}

/**
 * Pins, unpins, composts or returns the post, then answers with its stage.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error The new stage with a message, or why it was refused.
 */
function undergrowth_rest_change_stage( WP_REST_Request $request ) {
	$post_id = (int) $request['id'];
	$action  = (string) $request['action'];

	if ( 'pin' === $action && ! isset( $request['stage'] ) ) {
		return new WP_Error( 'rest_missing_callback_param', __( 'Choose a stage to pin.', 'undergrowth' ), array( 'status' => 400 ) );
	}

	switch ( $action ) {
		case 'pin':
			$result = undergrowth_pin_stage( $post_id, (string) $request['stage'] );
			$done   = 'pinned';
			break;
		case 'unpin':
			$result = undergrowth_unpin_stage( $post_id );
			$done   = 'unpinned';
			break;
		case 'compost':
			$result = undergrowth_compost_post( $post_id );
			$done   = 'composted';
			break;
		default:
			$result = undergrowth_return_post( $post_id );
			$done   = 'returned';
	}

	if ( is_wp_error( $result ) ) {
		$result->add_data( array( 'status' => 'undergrowth_cannot_edit' === $result->get_error_code() ? 403 : 409 ) );

		return $result;
	}

	return new WP_REST_Response( undergrowth_editor_stage( $post_id ) + array( 'message' => undergrowth_stage_result_messages()[ $done ] ) );
}

/**
 * Describes a post's stage for the editor panel.
 *
 * @param int $post_id Post ID.
 * @return array{in_garden: bool, stage: ?string, label: string, pinned: bool, choices: array<int, array{value: string, label: string}>} The stage.
 */
function undergrowth_editor_stage( int $post_id ): array {
	$post      = get_post( $post_id );
	$in_garden = $post instanceof WP_Post && undergrowth_is_indexable( $post );
	$stage     = $in_garden ? undergrowth_get_stage( $post_id ) : null;

	return array(
		'in_garden' => $in_garden,
		'stage'     => $stage,
		'label'     => null === $stage ? '' : undergrowth_stage_label( $stage ),
		'pinned'    => $in_garden && 'composting' !== $stage && undergrowth_is_stage_pinned( $post_id ),
		'choices'   => array_map(
			static function ( string $choice ): array {
				return array(
					'value' => $choice,
					'label' => undergrowth_stage_label( $choice ),
				);
			},
			UNDERGROWTH_GARDEN_STAGES
		),
	);
}

/**
 * Loads the panel into the editor of an indexed post type.
 *
 * The script exists only once built, so an unbuilt checkout loads nothing.
 *
 * @param string $asset_file Asset file wp-scripts wrote beside the script. Defaults to the built one.
 */
function undergrowth_enqueue_editor_panel( $asset_file = '' ): void {
	$asset_file = is_string( $asset_file ) && '' !== $asset_file ? $asset_file : dirname( UNDERGROWTH_FILE ) . '/build/editor/index.asset.php';
	$screen     = get_current_screen();

	if ( ! file_exists( $asset_file ) || null === $screen || ! in_array( $screen->post_type, undergrowth_indexed_post_types(), true ) ) {
		return;
	}

	$asset = require $asset_file;

	wp_enqueue_script(
		'undergrowth-editor',
		plugins_url( 'build/editor/index.js', UNDERGROWTH_FILE ),
		$asset['dependencies'],
		$asset['version'],
		true
	);
	wp_set_script_translations( 'undergrowth-editor', 'undergrowth' );
}
