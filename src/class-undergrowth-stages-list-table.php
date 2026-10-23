<?php
/**
 * The table of growth stages on the link suggestions screen.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists published, indexed posts with their stage and a pin control.
 */
class Undergrowth_Stages_List_Table extends WP_List_Table {

	const PER_PAGE = 20;

	/**
	 * Each row's form, printed after the table so it never sits inside the
	 * form that holds the bulk actions.
	 *
	 * @var string[]
	 */
	private $row_forms = array();

	/**
	 * Sets up the table.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'undergrowth-stage',
				'plural'   => 'undergrowth-stages',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Names the table's columns.
	 *
	 * The stage and the pin have columns of their own, so each can be
	 * sorted. Pin and Unpin stay row actions under the title.
	 *
	 * @return array<string, string> Column labels keyed by column name.
	 */
	public function get_columns() {
		$columns = array();

		if ( array() !== $this->get_bulk_actions() ) {
			$columns['cb'] = '<input type="checkbox" />';
		}

		return $columns + array(
			'post'   => __( 'Post', 'undergrowth' ),
			'stage'  => __( 'Growth stage', 'undergrowth' ),
			'pinned' => __( 'Pinned', 'undergrowth' ),
		);
	}

	/**
	 * Names the sortable columns and the order each sorts in first.
	 *
	 * Pinned sorts pinned posts first the first time it is chosen.
	 *
	 * @return array<string, array{0: string, 1: bool}> Sortable columns.
	 */
	public function get_sortable_columns() {
		return array(
			'post'   => array( 'title', false ),
			'stage'  => array( 'stage', false ),
			'pinned' => array( 'pinned', true ),
		);
	}

	/**
	 * Names the bulk actions, for a user who can edit posts.
	 *
	 * Composting posts are not listed here, so Return to garden is not
	 * offered. Each post is still checked on its own when the action runs.
	 *
	 * @return array<string, string> Labels keyed by action.
	 */
	public function get_bulk_actions() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return array();
		}

		return array(
			'pin'     => __( 'Pin stage', 'undergrowth' ),
			'unpin'   => __( 'Unpin stage', 'undergrowth' ),
			'compost' => __( 'Compost', 'undergrowth' ),
		);
	}

	/**
	 * Builds the links above the table: All, each stage, Pinned and Not
	 * pinned, with their counts.
	 *
	 * A view with no posts is left out, as the summary leaves out an empty
	 * stage: on a new site, a row of zeros reads like a score.
	 *
	 * @return array<string, string> Link HTML keyed by view.
	 */
	protected function get_views() {
		$counts = undergrowth_count_stages();
		$all    = 0;

		foreach ( UNDERGROWTH_GARDEN_STAGES as $stage ) {
			$all += $counts[ $stage ];
		}

		$counts['pinned']   = undergrowth_count_pinned();
		$counts['unpinned'] = $all - $counts['pinned'];

		$current = undergrowth_stage_filter_value();
		$views   = array( 'all' => $this->view_link( '', __( 'All', 'undergrowth' ), $all, '' === $current ) );

		foreach ( undergrowth_stage_filters() as $filter => $label ) {
			if ( $counts[ $filter ] > 0 ) {
				$views[ $filter ] = $this->view_link( $filter, $label, $counts[ $filter ], $filter === $current );
			}
		}

		return $views;
	}

	/**
	 * Builds one view link, as core builds the links above the Posts list.
	 *
	 * @param string $filter  A key of undergrowth_stage_filters(), or an empty string for All.
	 * @param string $label   Translated name of the view.
	 * @param int    $count   Number of posts in it.
	 * @param bool   $current Whether it is the view on screen.
	 * @return string Link HTML.
	 */
	private function view_link( string $filter, string $label, int $count, bool $current ): string {
		$url = undergrowth_suggestions_url( '', 'stages' );

		if ( '' !== $filter ) {
			$url = add_query_arg( 'undergrowth_stage', $filter, $url );
		}

		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Says that the view on screen has no posts.
	 *
	 * The tab has its own message for a garden with no posts at all.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No posts in this view.', 'undergrowth' );
	}

	/**
	 * Names the column that holds the row actions.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'post';
	}

	/**
	 * Loads the posts for the current page.
	 *
	 * Every indexed post in the garden is listed, so paging happens in the
	 * query. A post the user cannot edit is still listed, without controls.
	 * Composting posts rest outside the garden and are left out.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), $this->get_default_primary_column_name() );

		$post_types = undergrowth_indexed_post_types();

		// WP_Query reads an empty post type list as "posts", which would list
		// posts outside the index.
		if ( array() === $post_types ) {
			$this->items = array();
			return;
		}

		$meta_query = array( undergrowth_garden_meta_query() );
		$filter     = undergrowth_stage_filter_value();

		if ( '' !== $filter ) {
			$meta_query[] = undergrowth_stage_filter_meta_query( $filter );
		}

		list( $orderby, $order ) = undergrowth_stages_table_order();

		$args  = array(
			'post_type'           => $post_types,
			'post_status'         => 'publish',
			'has_password'        => false,
			// A sort by stage or pin keeps the titles in order within it.
			'orderby'             => 'title',
			'order'               => 'title' === $orderby ? $order : 'ASC',
			'undergrowth_orderby' => 'title' === $orderby ? '' : $orderby,
			'undergrowth_order'   => $order,
			'posts_per_page'      => self::PER_PAGE,
			'paged'               => $this->get_pagenum(),
			'fields'              => 'ids',
			'meta_query'          => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The garden and the view the author chose.
		);
		$query = new WP_Query( $args );
		$total = (int) $query->found_posts;

		// WP_Query counts nothing for a page past the end, as after a bulk
		// action emptied the last page, so the pages are counted again.
		if ( array() === $query->posts && $this->get_pagenum() > 1 ) {
			$count = new WP_Query(
				array(
					'paged'          => 1,
					'posts_per_page' => 1,
				) + $args
			);
			$total = (int) $count->found_posts;
		}

		$post_ids = array_map( 'intval', $query->posts );

		// The query fetched IDs only, so the rows would otherwise load each
		// post and its stage one at a time.
		_prime_post_caches( $post_ids, false, true );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);

		$this->items = array_values( array_filter( array_map( 'get_post', $post_ids ) ) );
	}

	/**
	 * Prints the post's title, linked to its editor when the user can edit it.
	 *
	 * The form behind its row action is kept for print_row_forms().
	 *
	 * @param WP_Post $item Post.
	 * @return string Cell HTML.
	 */
	public function column_post( $item ) {
		$post_id = $item->ID;

		if ( $this->can_pin( $post_id ) ) {
			$this->row_forms[] = $this->form( $post_id, undergrowth_is_stage_pinned( $post_id ) ? 'unpin' : 'pin' );
		}

		return '<strong>' . undergrowth_post_link( $post_id, 'row-title' ) . '</strong>';
	}

	/**
	 * Prints the post's stage.
	 *
	 * @param WP_Post $item Post.
	 * @return string Cell HTML.
	 */
	public function column_stage( $item ) {
		return esc_html( undergrowth_stage_label( undergrowth_get_stage( $item->ID ) ) );
	}

	/**
	 * Prints the pin marker, or core's dash for an empty cell.
	 *
	 * @param WP_Post $item Post.
	 * @return string Cell HTML.
	 */
	public function column_pinned( $item ) {
		$marker = trim( undergrowth_pinned_marker( $item->ID ) );

		if ( '' !== $marker ) {
			return $marker;
		}

		return sprintf( '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">%s</span>', esc_html__( 'Not pinned', 'undergrowth' ) );
	}

	/**
	 * Prints a row's checkbox, for a post the user may change.
	 *
	 * @param WP_Post $item Post.
	 * @return string Checkbox HTML.
	 */
	public function column_cb( $item ) {
		if ( ! $this->can_pin( $item->ID ) ) {
			return '';
		}

		return sprintf(
			'<label class="screen-reader-text" for="cb-select-%1$d">%2$s</label><input id="cb-select-%1$d" type="checkbox" name="post[]" value="%1$d" />',
			$item->ID,
			/* translators: %s: post title. */
			esc_html( sprintf( __( 'Select %s', 'undergrowth' ), undergrowth_post_title( $item->ID ) ) )
		);
	}

	/**
	 * Prints the forms behind the rows' Pin and Unpin actions.
	 *
	 * Each holds only hidden fields. A form cannot sit inside another, so
	 * these come after the table's own form, and the row actions point at
	 * them by id.
	 */
	public function print_row_forms(): void {
		echo implode( '', $this->row_forms ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each form is escaped where it is built.
	}

	/**
	 * Prints Pin or Unpin under the title, as core prints Edit under a post.
	 *
	 * A composting post has neither: composting is outside the pinning rules.
	 * They are always shown, since Pin carries its own stage choice.
	 *
	 * @param WP_Post $item        Post.
	 * @param string  $column_name Column being printed.
	 * @param string  $primary     Primary column name.
	 * @return string Row actions HTML, in the primary column only.
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		$post_id = $item->ID;

		if ( $primary !== $column_name || ! $this->can_pin( $post_id ) ) {
			return '';
		}

		$title = undergrowth_post_title( $post_id );

		if ( undergrowth_is_stage_pinned( $post_id ) ) {
			return $this->row_actions(
				array(
					'unpin' => $this->button(
						$post_id,
						'unpin',
						/* translators: %1$s: post title. */
						sprintf( __( 'Unpin the stage of %1$s', 'undergrowth' ), $title ),
						__( 'Unpin', 'undergrowth' )
					),
				),
				true
			);
		}

		$stage   = undergrowth_get_stage( $post_id );
		$options = '';

		foreach ( UNDERGROWTH_GARDEN_STAGES as $choice ) {
			$options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $choice ), selected( $choice, $stage, false ), esc_html( undergrowth_stage_label( $choice ) ) );
		}

		$select = sprintf(
			'<label for="undergrowth-stage-%1$d" class="screen-reader-text">%2$s</label><select id="undergrowth-stage-%1$d" name="stage" form="%3$s">%4$s</select> ',
			$post_id,
			/* translators: %1$s: post title. */
			esc_html( sprintf( __( 'Stage to pin for %1$s', 'undergrowth' ), $title ) ),
			esc_attr( $this->form_id( $post_id, 'pin' ) ),
			$options
		);

		return $this->row_actions(
			array(
				'pin' => $select . $this->button(
					$post_id,
					'pin',
					/* translators: %1$s: post title. */
					sprintf( __( 'Pin the stage of %1$s', 'undergrowth' ), $title ),
					__( 'Pin', 'undergrowth' )
				),
			),
			true
		);
	}

	/**
	 * Tells whether the current user may pin or unpin a post's stage.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function can_pin( int $post_id ): bool {
		return 'composting' !== undergrowth_get_stage( $post_id ) && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Returns the id of a row's form.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  'pin' or 'unpin'.
	 * @return string Element id.
	 */
	private function form_id( int $post_id, string $action ): string {
		return sprintf( 'undergrowth-%1$s-%2$d', $action, $post_id );
	}

	/**
	 * Builds one row action, a button tied to its form by the form attribute.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  'pin' or 'unpin'.
	 * @param string $label   Accessible name of the button.
	 * @param string $text    Visible text of the button.
	 * @return string Button HTML.
	 */
	private function button( int $post_id, string $action, string $label, string $text ): string {
		return sprintf(
			'<button type="submit" form="%1$s" class="button-link" aria-label="%2$s">%3$s</button>',
			esc_attr( $this->form_id( $post_id, $action ) ),
			esc_attr( $label ),
			esc_html( $text )
		);
	}

	/**
	 * Builds one row's form, holding only hidden fields, with a nonce for
	 * that post and action. Its controls point at it from the row actions.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  'pin' or 'unpin'.
	 * @return string Form HTML.
	 */
	private function form( int $post_id, string $action ): string {
		return sprintf(
			'<form method="post" action="%1$s" id="%2$s"><input type="hidden" name="action" value="undergrowth_%3$s" /><input type="hidden" name="post" value="%4$d" />%5$s</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $this->form_id( $post_id, $action ) ),
			esc_attr( $action ),
			$post_id,
			wp_nonce_field( 'undergrowth_' . $action . '_' . $post_id, '_wpnonce', true, false )
		);
	}
}
