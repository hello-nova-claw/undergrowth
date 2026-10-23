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
	 * One column, as on core's Posts screen: the stage sits beside the
	 * title as a post state, and Pin is a row action, so a narrow screen
	 * shows all of it.
	 *
	 * @return array<string, string> Column labels keyed by column name.
	 */
	public function get_columns() {
		return array(
			'post' => __( 'Post', 'undergrowth' ),
		);
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
		$this->_column_headers = array( $this->get_columns(), array(), array(), $this->get_default_primary_column_name() );

		$post_types = undergrowth_indexed_post_types();

		// WP_Query reads an empty post type list as "posts", which would list
		// posts outside the index.
		if ( array() === $post_types ) {
			$this->items = array();
			return;
		}

		$query = new WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'has_password'   => false,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $this->get_pagenum(),
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A post with no stage stored is a seedling, so only a composting value can leave it out.
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => 'undergrowth_stage',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => 'undergrowth_stage',
						'value'   => 'composting',
						'compare' => '!=',
					),
				),
			)
		);

		$post_ids = array_map( 'intval', $query->posts );

		// The query fetched IDs only, so the rows would otherwise load each
		// post and its stage one at a time.
		_prime_post_caches( $post_ids, false, true );

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => self::PER_PAGE,
			)
		);

		$this->items = array_values( array_filter( array_map( 'get_post', $post_ids ) ) );
	}

	/**
	 * Prints the post's title, linked to its editor when the user can edit
	 * it, with its stage beside it as core prints "Draft" beside a title,
	 * and a marker when the stage is pinned. The forms behind the row
	 * actions sit here too, holding only hidden fields.
	 *
	 * @param WP_Post $item Post.
	 * @return string Cell HTML.
	 */
	public function column_post( $item ) {
		$post_id = $item->ID;
		$title   = undergrowth_post_link( $post_id, 'row-title' );
		$stage   = undergrowth_get_stage( $post_id );

		$state = esc_html( undergrowth_stage_label( $stage ) );

		if ( 'composting' !== $stage && undergrowth_is_stage_pinned( $post_id ) ) {
			$state .= sprintf(
				' <span class="dashicons dashicons-sticky undergrowth-pinned" aria-hidden="true" title="%1$s"></span><span class="screen-reader-text">%1$s</span>',
				esc_attr__( 'Pinned', 'undergrowth' )
			);
		}

		$html = '<strong>' . sprintf(
			/* translators: 1: post title, 2: its growth stage. */
			__( '%1$s &mdash; %2$s', 'undergrowth' ),
			$title,
			'<span class="post-state">' . $state . '</span>'
		) . '</strong>';

		if ( $this->can_pin( $post_id ) ) {
			$html .= $this->form( $post_id, undergrowth_is_stage_pinned( $post_id ) ? 'unpin' : 'pin' );
		}

		return $html;
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

		foreach ( array( 'seedling', 'sprout', 'budding', 'evergreen' ) as $choice ) {
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
