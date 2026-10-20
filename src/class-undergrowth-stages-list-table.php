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
	 * @return array<string, string> Column labels keyed by column name.
	 */
	public function get_columns() {
		return array(
			'post'    => __( 'Post', 'undergrowth' ),
			'stage'   => __( 'Stage', 'undergrowth' ),
			'actions' => __( 'Actions', 'undergrowth' ),
		);
	}

	/**
	 * Loads the posts for the current page.
	 *
	 * Every indexed post is listed, so paging happens in the query. A post
	 * the user cannot edit is still listed, without controls.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$query = new WP_Query(
			array(
				'post_type'      => undergrowth_indexed_post_types(),
				'post_status'    => 'publish',
				'has_password'   => false,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $this->get_pagenum(),
				'fields'         => 'ids',
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

		$this->items = $post_ids;
	}

	/**
	 * Prints the post's title, linked to its editor when the user can edit it.
	 *
	 * @param int $item Post ID.
	 * @return string Cell HTML.
	 */
	public function column_post( $item ) {
		$title = esc_html( get_the_title( $item ) );
		$link  = get_edit_post_link( $item, 'raw' );

		return null === $link ? $title : sprintf( '<a href="%1$s">%2$s</a>', esc_url( $link ), $title );
	}

	/**
	 * Prints the post's stage, with a marker when it is pinned.
	 *
	 * @param int $item Post ID.
	 * @return string Cell HTML.
	 */
	public function column_stage( $item ) {
		$stage = undergrowth_get_stage( $item );
		$html  = esc_html( undergrowth_stage_label( $stage ) );

		if ( 'composting' !== $stage && undergrowth_is_stage_pinned( $item ) ) {
			$html .= sprintf(
				' <span class="dashicons dashicons-sticky undergrowth-pinned" aria-hidden="true" title="%1$s"></span><span class="screen-reader-text">%1$s</span>',
				esc_attr__( 'Pinned', 'undergrowth' )
			);
		}

		return $html;
	}

	/**
	 * Prints the pin or unpin form for a post.
	 *
	 * A composting post has neither: composting is outside the pinning rules.
	 *
	 * @param int $item Post ID.
	 * @return string Cell HTML.
	 */
	public function column_actions( $item ) {
		$stage = undergrowth_get_stage( $item );

		if ( 'composting' === $stage || ! current_user_can( 'edit_post', $item ) ) {
			return '';
		}

		$title = get_the_title( $item );

		if ( undergrowth_is_stage_pinned( $item ) ) {
			return $this->form(
				$item,
				'unpin',
				'',
				/* translators: %s: post title. */
				sprintf( __( 'Unpin the stage of %s', 'undergrowth' ), $title ),
				__( 'Unpin', 'undergrowth' )
			);
		}

		$options = '';

		foreach ( array( 'seedling', 'sprout', 'budding', 'evergreen' ) as $choice ) {
			$options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $choice ), selected( $choice, $stage, false ), esc_html( undergrowth_stage_label( $choice ) ) );
		}

		$select = sprintf(
			'<label for="undergrowth-stage-%1$d" class="screen-reader-text">%2$s</label><select id="undergrowth-stage-%1$d" name="stage">%3$s</select> ',
			$item,
			/* translators: %s: post title. */
			esc_html( sprintf( __( 'Stage to pin for %s', 'undergrowth' ), $title ) ),
			$options
		);

		return $this->form(
			$item,
			'pin',
			$select,
			/* translators: %s: post title. */
			sprintf( __( 'Pin the stage of %s', 'undergrowth' ), $title ),
			__( 'Pin', 'undergrowth' )
		);
	}

	/**
	 * Builds one row's form, with a nonce for that post and action.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  'pin' or 'unpin'.
	 * @param string $fields  Extra field HTML, already escaped.
	 * @param string $label   Accessible name of the button.
	 * @param string $text    Visible text of the button.
	 * @return string Form HTML.
	 */
	private function form( int $post_id, string $action, string $fields, string $label, string $text ): string {
		return sprintf(
			'<form method="post" action="%1$s"><input type="hidden" name="action" value="undergrowth_%2$s" /><input type="hidden" name="post" value="%3$d" />%4$s%5$s<button type="submit" class="button" aria-label="%6$s">%7$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $action ),
			$post_id,
			wp_nonce_field( 'undergrowth_' . $action . '_' . $post_id, '_wpnonce', true, false ),
			$fields,
			esc_attr( $label ),
			esc_html( $text )
		);
	}
}
