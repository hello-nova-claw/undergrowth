<?php
/**
 * The table of proposed links on the link suggestions screen.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists proposed links whose source post the current user can edit.
 */
class Undergrowth_Links_List_Table extends WP_List_Table {

	const PER_PAGE = 20;

	/**
	 * Sets up the table.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'undergrowth-link',
				'plural'   => 'undergrowth-links',
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
			'source'   => __( 'In this post', 'undergrowth' ),
			'sentence' => __( 'At this sentence', 'undergrowth' ),
			'target'   => __( 'Link to', 'undergrowth' ),
			'actions'  => __( 'Actions', 'undergrowth' ),
		);
	}

	/**
	 * Loads the proposals for the current page.
	 *
	 * Rows are filtered by permission before paging, so every page is full
	 * and the count matches what the user can act on.
	 *
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every refresh.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source_id, target_id, sentence FROM %i WHERE state = %s ORDER BY updated_at DESC, source_id ASC, score DESC',
				undergrowth_links_table(),
				'proposed'
			),
			ARRAY_A
		);

		$rows = (array) $rows;

		// One query for every post on the screen, instead of one per row for
		// the permission checks and again for each title and address.
		_prime_post_caches(
			array_unique( array_map( 'intval', array_merge( array_column( $rows, 'source_id' ), array_column( $rows, 'target_id' ) ) ) ),
			false,
			false
		);

		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return current_user_can( 'edit_post', (int) $row['source_id'] );
				}
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => count( $rows ),
				'per_page'    => self::PER_PAGE,
			)
		);

		$this->items = array_map(
			static function ( $row ) {
				return array(
					'source_id' => (int) $row['source_id'],
					'target_id' => (int) $row['target_id'],
					'sentence'  => (string) $row['sentence'],
				);
			},
			array_slice( $rows, ( $this->get_pagenum() - 1 ) * self::PER_PAGE, self::PER_PAGE )
		);
	}

	/**
	 * Prints the source post's title, linked to its editor.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string} $item Row.
	 * @return string Cell HTML.
	 */
	public function column_source( $item ) {
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( (string) get_edit_post_link( $item['source_id'], 'raw' ) ),
			esc_html( get_the_title( $item['source_id'] ) )
		);
	}

	/**
	 * Prints the sentence the link would sit in.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string} $item Row.
	 * @return string Cell HTML.
	 */
	public function column_sentence( $item ) {
		return '<q>' . esc_html( $item['sentence'] ) . '</q>';
	}

	/**
	 * Prints the target post's title, linked to the published post.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string} $item Row.
	 * @return string Cell HTML.
	 */
	public function column_target( $item ) {
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( (string) get_permalink( $item['target_id'] ) ),
			esc_html( get_the_title( $item['target_id'] ) )
		);
	}

	/**
	 * Prints the accept and veto buttons for a row.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string} $item Row.
	 * @return string Cell HTML.
	 */
	public function column_actions( $item ) {
		$source = get_the_title( $item['source_id'] );
		$target = get_the_title( $item['target_id'] );

		return $this->action_form(
			$item,
			'accept',
			/* translators: 1: title of the post the link would sit in, 2: title of the post it would point to. */
			sprintf( __( 'Accept the link from %1$s to %2$s', 'undergrowth' ), $source, $target ),
			__( 'Accept', 'undergrowth' ),
			'button-primary'
		) . ' ' . $this->action_form(
			$item,
			'veto',
			/* translators: 1: title of the post the link would sit in, 2: title of the post it would point to. */
			sprintf( __( 'Veto the link from %1$s to %2$s', 'undergrowth' ), $source, $target ),
			__( 'Veto', 'undergrowth' ),
			''
		);
	}

	/**
	 * Builds one action button as its own small form.
	 *
	 * Each button is a separate form so it works without JavaScript and
	 * carries a nonce for that exact pair of posts.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string} $item   Row.
	 * @param string                                                  $action Action name.
	 * @param string                                                  $label  Accessible name.
	 * @param string                                                  $text   Visible text.
	 * @param string                                                  $style  Extra button class.
	 * @return string Form HTML.
	 */
	private function action_form( array $item, string $action, string $label, string $text, string $style ): string {
		return sprintf(
			'<form method="post" action="%1$s" style="display:inline"><input type="hidden" name="action" value="undergrowth_%2$s" /><input type="hidden" name="source" value="%3$d" /><input type="hidden" name="target" value="%4$d" />%5$s<button type="submit" class="button %6$s" aria-label="%7$s">%8$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $action ),
			$item['source_id'],
			$item['target_id'],
			wp_nonce_field( 'undergrowth_' . $action . '_' . $item['source_id'] . '_' . $item['target_id'], '_wpnonce', true, false ),
			esc_attr( $style ),
			esc_attr( $label ),
			esc_html( $text )
		);
	}
}
