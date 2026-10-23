<?php
/**
 * Growth stages on the core Posts screen.
 *
 * The Posts screen is where an author already acts on a post as a whole,
 * such as trashing or restoring it, so stages sit there too: a column that
 * sorts and filters, row and bulk actions, a post state and a view, all
 * core parts.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'post_row_actions', 'undergrowth_compost_row_actions', 10, 2 );
add_filter( 'page_row_actions', 'undergrowth_compost_row_actions', 10, 2 );
add_filter( 'display_post_states', 'undergrowth_compost_post_states', 10, 2 );
add_filter( 'removable_query_args', 'undergrowth_compost_removable_query_args' );
add_action( 'load-edit.php', 'undergrowth_load_posts_screen' );
add_action( 'pre_get_posts', 'undergrowth_filter_composting_view' );
add_action( 'pre_get_posts', 'undergrowth_filter_posts_by_stage' );
add_action( 'admin_post_undergrowth_compost', 'undergrowth_handle_compost_row' );
add_action( 'admin_post_undergrowth_return', 'undergrowth_handle_return_row' );

/**
 * Adds the compost or return link under a post's title.
 *
 * Only posts in the garden have a stage, so other posts get neither.
 *
 * @param array<string, string> $actions Row actions.
 * @param WP_Post               $post    Post in the row.
 * @return array<string, string> Row actions with the stage action added.
 */
function undergrowth_compost_row_actions( $actions, $post ) {
	if ( ! $post instanceof WP_Post || ! undergrowth_is_indexable( $post ) || ! current_user_can( 'edit_post', $post->ID ) ) {
		return $actions;
	}

	$title = _draft_or_post_title( $post );

	if ( 'composting' === undergrowth_get_stage( $post->ID ) ) {
		$action = 'return';
		/* translators: %s: post title. */
		$label = sprintf( __( 'Return &#8220;%s&#8221; to the garden', 'undergrowth' ), $title );
		$text  = __( 'Return to garden', 'undergrowth' );
	} else {
		$action = 'compost';
		/* translators: %s: post title. */
		$label = sprintf( __( 'Compost &#8220;%s&#8221;', 'undergrowth' ), $title );
		$text  = __( 'Compost', 'undergrowth' );
	}

	$actions[ 'undergrowth_' . $action ] = sprintf(
		'<a href="%1$s" aria-label="%2$s">%3$s</a>',
		esc_url( undergrowth_stage_row_url( $action, $post->ID ) ),
		esc_attr( $label ),
		esc_html( $text )
	);

	return $actions;
}

/**
 * Builds the address of a post's compost or return link.
 *
 * @param string $action  'compost' or 'return'.
 * @param int    $post_id Post ID.
 * @return string Address with a nonce, not yet escaped.
 */
function undergrowth_stage_row_url( string $action, int $post_id ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'undergrowth_' . $action,
				'post'   => $post_id,
			),
			admin_url( 'admin-post.php' )
		),
		'undergrowth_' . $action . '_' . $post_id
	);
}

/**
 * Marks a composting post in the Posts list, beside states such as Draft.
 *
 * @param array<string, string> $states Post states.
 * @param WP_Post               $post   Post in the row.
 * @return array<string, string> Post states.
 */
function undergrowth_compost_post_states( $states, $post ) {
	if ( $post instanceof WP_Post && undergrowth_is_indexable( $post ) && 'composting' === undergrowth_get_stage( $post->ID ) ) {
		$states['undergrowth_composting'] = undergrowth_stage_label( 'composting' );
	}

	return $states;
}

/**
 * Adds the bulk action, view and notices when the Posts screen loads.
 */
function undergrowth_load_posts_screen(): void {
	global $typenow;

	undergrowth_add_posts_list_hooks( (string) $typenow );
}

/**
 * Hooks the bulk actions, the Composting view, the notices, and the
 * column with its sorting and filter into the list of one post type, when
 * that type is in the garden.
 *
 * @param string $post_type Post type of the list.
 */
function undergrowth_add_posts_list_hooks( string $post_type ): void {
	if ( ! in_array( $post_type, undergrowth_indexed_post_types(), true ) ) {
		return;
	}

	undergrowth_forget_shown_results();
	add_filter( 'bulk_actions-edit-' . $post_type, 'undergrowth_compost_bulk_actions' );
	add_filter( 'handle_bulk_actions-edit-' . $post_type, 'undergrowth_handle_compost_bulk', 10, 3 );
	add_filter( 'views_edit-' . $post_type, 'undergrowth_composting_view' );
	add_action( 'admin_notices', 'undergrowth_render_compost_notice' );
	add_filter( 'manage_' . $post_type . '_posts_columns', 'undergrowth_add_stage_column' );
	add_action( 'manage_' . $post_type . '_posts_custom_column', 'undergrowth_render_stage_column', 10, 2 );
	add_filter( 'manage_edit-' . $post_type . '_sortable_columns', 'undergrowth_sortable_stage_column' );
	add_action( 'restrict_manage_posts', 'undergrowth_render_stage_filter', 10, 2 );
	undergrowth_add_compost_help();
}

/**
 * Adds a Growth stage column after the title, so a post's stage sits with
 * the post, beside Compost and the Composting view.
 *
 * @param array<string, string> $columns Column labels keyed by name.
 * @return array<string, string> Columns.
 */
function undergrowth_add_stage_column( $columns ) {
	$added = array();

	foreach ( (array) $columns as $name => $label ) {
		$added[ $name ] = $label;

		if ( 'title' === $name ) {
			$added['undergrowth_stage'] = __( 'Growth stage', 'undergrowth' );
		}
	}

	if ( ! isset( $added['undergrowth_stage'] ) ) {
		$added['undergrowth_stage'] = __( 'Growth stage', 'undergrowth' );
	}

	return $added;
}

/**
 * Prints a post's stage in the Growth stage column, with the pin marker.
 *
 * A post outside the garden, such as a draft, has no stage yet, and gets
 * core's dash for an empty cell.
 *
 * @param string $column  Column being printed.
 * @param int    $post_id Post ID.
 */
function undergrowth_render_stage_column( $column, $post_id ): void {
	if ( 'undergrowth_stage' !== $column ) {
		return;
	}

	$post = get_post( (int) $post_id );

	if ( ! $post instanceof WP_Post || ! undergrowth_is_indexable( $post ) ) {
		printf( '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">%s</span>', esc_html__( 'No growth stage', 'undergrowth' ) );
		return;
	}

	$stage = undergrowth_get_stage( $post->ID );

	echo esc_html( undergrowth_stage_label( $stage ) ) . undergrowth_pinned_marker( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The marker is escaped where it is built.
}

/**
 * Adds Pin stage, Unpin stage, Compost and Return to garden to the bulk
 * actions menu.
 *
 * @param array<string, string> $actions Bulk actions.
 * @return array<string, string> Bulk actions.
 */
function undergrowth_compost_bulk_actions( $actions ) {
	global $typenow;

	$type = get_post_type_object( '' === (string) $typenow ? 'post' : (string) $typenow );

	if ( ! $type instanceof WP_Post_Type || ! current_user_can( $type->cap->edit_posts ) ) {
		return $actions;
	}

	$actions['undergrowth_pin']     = __( 'Pin stage', 'undergrowth' );
	$actions['undergrowth_unpin']   = __( 'Unpin stage', 'undergrowth' );
	$actions['undergrowth_compost'] = __( 'Compost', 'undergrowth' );
	$actions['undergrowth_return']  = __( 'Return to garden', 'undergrowth' );

	return $actions;
}

/**
 * Pins, unpins, composts or returns each chosen post, counting what changed.
 *
 * Core has checked the bulk nonce already. Each post is checked on its
 * own, so a post that cannot change is counted and left as it was. Pin
 * stage keeps each post at the stage it has now, and leaves a post that is
 * already pinned as it was, so its record shows no second pin.
 *
 * @param string $redirect  Address to return to.
 * @param string $action    Chosen bulk action.
 * @param int[]  $post_ids  Chosen posts.
 * @return string Address with the counts added.
 */
function undergrowth_handle_compost_bulk( $redirect, $action, $post_ids ) {
	$results = array(
		'undergrowth_pin'     => 'pinned',
		'undergrowth_unpin'   => 'unpinned',
		'undergrowth_compost' => 'composted',
		'undergrowth_return'  => 'returned',
	);

	if ( ! isset( $results[ $action ] ) ) {
		return $redirect;
	}

	$done    = 0;
	$skipped = 0;

	foreach ( array_map( 'intval', (array) $post_ids ) as $post_id ) {
		switch ( $action ) {
			case 'undergrowth_pin':
				$result = undergrowth_is_stage_pinned( $post_id ) ? false : undergrowth_pin_stage( $post_id, undergrowth_get_stage( $post_id ) );
				break;
			case 'undergrowth_unpin':
				$result = undergrowth_unpin_stage( $post_id );
				break;
			case 'undergrowth_compost':
				$result = undergrowth_compost_post( $post_id );
				break;
			default:
				$result = undergrowth_return_post( $post_id );
		}

		if ( true === $result ) {
			++$done;
		} else {
			++$skipped;
		}
	}

	return undergrowth_compost_result_url( $redirect, $results[ $action ], $done, $skipped );
}

/**
 * Builds the address to return to after a stage action.
 *
 * @param string $url     Address of the list.
 * @param string $result  'pinned', 'unpinned', 'composted' or 'returned'.
 * @param int    $done    Posts that changed.
 * @param int    $skipped Posts left as they were.
 * @return string Address.
 */
function undergrowth_compost_result_url( string $url, string $result, int $done, int $skipped ): string {
	$url = remove_query_arg( undergrowth_compost_result_args(), $url );
	$url = add_query_arg( 'undergrowth_' . $result, $done, $url );

	return $skipped > 0 ? add_query_arg( 'undergrowth_skipped', $skipped, $url ) : $url;
}

/**
 * Names the address arguments that carry a stage action's result.
 *
 * @return string[] Argument names.
 */
function undergrowth_compost_result_args(): array {
	return array( 'undergrowth_pinned', 'undergrowth_unpinned', 'undergrowth_composted', 'undergrowth_returned', 'undergrowth_skipped' );
}

/**
 * Takes results already shown out of the address that a list's sort links
 * are built from, as core's Posts screen does with its own, so sorting
 * the list does not show the notice again.
 *
 * The notice still reads them from the request.
 */
function undergrowth_forget_shown_results(): void {
	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only arguments are taken out, as core's edit.php does.
		$_SERVER['REQUEST_URI'] = remove_query_arg( array_merge( undergrowth_compost_result_args(), array( 'undergrowth_notice' ) ), $_SERVER['REQUEST_URI'] );
	}
}

/**
 * Lets core remove the result arguments from the address once shown, so a
 * reload does not repeat the notice.
 *
 * @param string[] $args Removable argument names.
 * @return string[] Argument names.
 */
function undergrowth_compost_removable_query_args( $args ) {
	return array_merge( (array) $args, undergrowth_compost_result_args() );
}

/**
 * Composts the post in a row action's link, then returns to the list.
 */
function undergrowth_handle_compost_row(): void {
	undergrowth_handle_stage_row( 'compost' );
}

/**
 * Returns the post in a row action's link to the garden, then returns to the list.
 */
function undergrowth_handle_return_row(): void {
	undergrowth_handle_stage_row( 'return' );
}

/**
 * Carries out a compost or return row action.
 *
 * Like core's Trash link, the action is a link with a nonce, and a refusal
 * is explained on its own page with a link back.
 *
 * @param string $action 'compost' or 'return'.
 */
function undergrowth_handle_stage_row( string $action ): void {
	$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

	check_admin_referer( 'undergrowth_' . $action . '_' . $post_id );

	$result = 'compost' === $action ? undergrowth_compost_post( $post_id ) : undergrowth_return_post( $post_id );

	if ( is_wp_error( $result ) ) {
		wp_die(
			esc_html( $result->get_error_message() ),
			'',
			array(
				'response'  => 'undergrowth_cannot_edit' === $result->get_error_code() ? 403 : 409,
				'back_link' => true,
			)
		);
	}

	$post     = get_post( $post_id );
	$fallback = admin_url( 'edit.php' );

	if ( $post instanceof WP_Post && 'post' !== $post->post_type ) {
		$fallback = add_query_arg( 'post_type', $post->post_type, $fallback );
	}

	// Back to the page of the list the link was on, when it came from one.
	$referer = wp_get_referer();
	$url     = false !== $referer && 0 === strpos( $referer, admin_url( 'edit.php' ) ) ? $referer : $fallback;

	wp_safe_redirect( undergrowth_compost_result_url( $url, 'compost' === $action ? 'composted' : 'returned', 1, 0 ) );
	exit;
}

/**
 * Adds a Composting view to the list, beside All and Published, when any
 * post of the type is composting.
 *
 * @param array<string, string> $views Views.
 * @return array<string, string> Views.
 */
function undergrowth_composting_view( $views ) {
	global $typenow;

	$post_type = '' === (string) $typenow ? 'post' : (string) $typenow;

	$count = new WP_Query(
		array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'has_password'           => false,
			'meta_key'               => 'undergrowth_stage', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Counted once per screen.
			'meta_value'             => 'composting', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Counted once per screen.
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	if ( 0 === (int) $count->found_posts ) {
		return $views;
	}

	$url = add_query_arg( 'undergrowth_stage', 'composting', admin_url( 'edit.php' ) );

	if ( 'post' !== $post_type ) {
		$url = add_query_arg( 'post_type', $post_type, $url );
	}

	$current = undergrowth_is_composting_view();

	$views['undergrowth_composting'] = sprintf(
		'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
		esc_url( $url ),
		$current ? ' class="current" aria-current="page"' : '',
		esc_html( undergrowth_stage_label( 'composting' ) ),
		esc_html( number_format_i18n( (int) $count->found_posts ) )
	);

	return $views;
}

/**
 * Tells whether the Posts screen was asked for its Composting view.
 *
 * @return bool True for the Composting view.
 */
function undergrowth_is_composting_view(): bool {
	// The view only selects which posts to list; nothing is changed.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return isset( $_GET['undergrowth_stage'] ) && 'composting' === sanitize_key( wp_unslash( $_GET['undergrowth_stage'] ) );
}

/**
 * Lists only composting posts on the Composting view.
 *
 * @param WP_Query $query Query about to run.
 */
function undergrowth_filter_composting_view( $query ): void {
	global $pagenow;

	if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() || ! undergrowth_is_composting_view() ) {
		return;
	}

	$query->set( 'post_status', 'publish' );
	$query->set( 'has_password', false );
	$query->set( 'meta_key', 'undergrowth_stage' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only on the view the author chose.
	$query->set( 'meta_value', 'composting' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Only on the view the author chose.
}

/**
 * Makes the Growth stage column sortable.
 *
 * It sorts from Seedling up the first time it is chosen, as the stages
 * are read.
 *
 * @param array<string, mixed> $columns Sortable columns.
 * @return array<string, mixed> Sortable columns.
 */
function undergrowth_sortable_stage_column( $columns ) {
	$columns = (array) $columns;

	$columns['undergrowth_stage'] = array( 'undergrowth_stage', false );

	return $columns;
}

/**
 * Prints the Growth stage filter beside core's date and category filters.
 *
 * Composting is a view of its own, beside All and Published, so it is not
 * offered here. On that view a hidden field keeps it when another filter
 * is applied.
 *
 * @param string $post_type Post type of the list.
 * @param string $which     Which table navigation is being printed.
 */
function undergrowth_render_stage_filter( $post_type, $which = 'top' ): void {
	if ( 'top' !== $which || ! in_array( (string) $post_type, undergrowth_indexed_post_types(), true ) ) {
		return;
	}

	if ( undergrowth_is_composting_view() ) {
		echo '<input type="hidden" name="undergrowth_stage" value="composting" />';
		return;
	}

	$current = undergrowth_stage_filter_value();
	$choices = array( '' => __( 'All stages', 'undergrowth' ) ) + undergrowth_stage_filters();

	printf( '<label for="undergrowth-stage-filter" class="screen-reader-text">%s</label>', esc_html__( 'Filter by growth stage', 'undergrowth' ) );
	echo '<select name="undergrowth_stage" id="undergrowth-stage-filter">';

	foreach ( $choices as $value => $label ) {
		printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $value, $current, false ), esc_html( $label ) );
	}

	echo '</select>';
}

/**
 * Tells whether a query lists the posts of a garden post type on the Posts screen.
 *
 * @param WP_Query $query Query about to run.
 * @return bool True for the main query of edit.php for an indexed type.
 */
function undergrowth_is_garden_list_query( $query ): bool {
	global $pagenow;

	if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
		return false;
	}

	$post_type = $query->get( 'post_type' );
	$post_type = '' === $post_type ? 'post' : $post_type;

	return is_string( $post_type ) && in_array( $post_type, undergrowth_indexed_post_types(), true );
}

/**
 * Lists only the posts the Growth stage filter chose, and sorts by stage
 * when the column is sorted.
 *
 * Only posts in the garden have a stage, so the filter lists published
 * posts alone, as the Composting view does. Sorted by stage, posts at the
 * same stage stay newest first.
 *
 * @param WP_Query $query Query about to run.
 */
function undergrowth_filter_posts_by_stage( $query ): void {
	if ( ! undergrowth_is_garden_list_query( $query ) ) {
		return;
	}

	if ( 'undergrowth_stage' === $query->get( 'orderby' ) ) {
		$query->set( 'undergrowth_orderby', 'stage' );
		$query->set(
			'orderby',
			array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			)
		);
	}

	$filter = undergrowth_stage_filter_value();

	if ( '' === $filter ) {
		return;
	}

	$meta_query   = (array) $query->get( 'meta_query' );
	$meta_query[] = undergrowth_stage_filter_meta_query( $filter );

	$query->set( 'post_status', 'publish' );
	$query->set( 'has_password', false );
	$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Only on the filter the author chose.
}

/**
 * Prints the result of a stage action on the Posts screen.
 *
 * Only counts are shown. Nothing says how long a post has rested or
 * suggests returning it. The notice takes focus, as on the link
 * suggestions screen, so a keyboard user hears the result.
 */
function undergrowth_render_compost_notice(): void {
	// The arguments only select which fixed message to show; nothing is changed.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$counts = array();

	foreach ( undergrowth_compost_result_args() as $arg ) {
		$counts[ $arg ] = isset( $_GET[ $arg ] ) ? absint( wp_unslash( $_GET[ $arg ] ) ) : 0;
	}
	// phpcs:enable

	$success = array();

	if ( $counts['undergrowth_pinned'] > 0 ) {
		$success[] = sprintf(
			/* translators: %s: number of posts. */
			_n( '%s post pinned at its stage. The monthly cycle leaves it there until you unpin it.', '%s posts pinned at their stages. The monthly cycle leaves them there until you unpin them.', $counts['undergrowth_pinned'], 'undergrowth' ),
			number_format_i18n( $counts['undergrowth_pinned'] )
		);
	}

	if ( $counts['undergrowth_unpinned'] > 0 ) {
		$success[] = sprintf(
			/* translators: %s: number of posts. */
			_n( '%s post unpinned. Its stage was measured again from the links you have accepted.', '%s posts unpinned. Their stages were measured again from the links you have accepted.', $counts['undergrowth_unpinned'], 'undergrowth' ),
			number_format_i18n( $counts['undergrowth_unpinned'] )
		);
	}

	if ( $counts['undergrowth_composted'] > 0 ) {
		$success[] = sprintf(
			/* translators: %s: number of posts. */
			_n( '%s post moved to compost. It stays published, and no links are suggested to or from it.', '%s posts moved to compost. They stay published, and no links are suggested to or from them.', $counts['undergrowth_composted'], 'undergrowth' ),
			number_format_i18n( $counts['undergrowth_composted'] )
		);
	}

	if ( $counts['undergrowth_returned'] > 0 ) {
		$success[] = sprintf(
			/* translators: %s: number of posts. */
			_n( '%s post returned to the garden. Its stage was measured again from the links you have accepted.', '%s posts returned to the garden. Their stages were measured again from the links you have accepted.', $counts['undergrowth_returned'], 'undergrowth' ),
			number_format_i18n( $counts['undergrowth_returned'] )
		);
	}

	$warning = '';

	if ( $counts['undergrowth_skipped'] > 0 ) {
		$warning = sprintf(
			/* translators: %s: number of posts. */
			_n( '%s post was left as it was, because it is not published, the action does not apply to it, or you cannot edit it.', '%s posts were left as they were, because they are not published, the action does not apply to them, or you cannot edit them.', $counts['undergrowth_skipped'], 'undergrowth' ),
			number_format_i18n( $counts['undergrowth_skipped'] )
		);
	}

	if ( array() === $success && '' === $warning ) {
		return;
	}

	$notices = array();

	if ( array() !== $success ) {
		$notices['notice-success'] = '<p>' . implode( '</p><p>', array_map( 'esc_html', $success ) ) . '</p>';
	}

	if ( '' !== $warning ) {
		$notices['notice-warning'] = '<p>' . esc_html( $warning ) . '</p>';
	}

	$id = ' id="undergrowth-notice" tabindex="-1"';

	// The first notice takes focus; a second one is read after it.
	foreach ( $notices as $class => $paragraphs ) {
		printf(
			'<div%1$s class="notice %2$s is-dismissible" role="status">%3$s</div>',
			$id, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed string.
			esc_attr( $class ),
			wp_kses( $paragraphs, array( 'p' => array() ) )
		);
		$id = '';
	}

	undergrowth_print_notice_focus_script();
}
