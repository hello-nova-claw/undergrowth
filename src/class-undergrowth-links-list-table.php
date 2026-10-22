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
	 * As on core's Comments screen, the author's own words come first and
	 * hold the row actions, so a narrow screen still shows the sentence,
	 * where the link goes, and Accept and Veto.
	 *
	 * @return array<string, string> Column labels keyed by column name.
	 */
	public function get_columns() {
		return array(
			'sentence' => __( 'Suggestion', 'undergrowth' ),
			'source'   => __( 'In this post', 'undergrowth' ),
		);
	}

	/**
	 * Names the column that holds the row actions.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'sentence';
	}

	/**
	 * Loads the proposals for the current page.
	 *
	 * Rows are filtered by permission before paging, so every page is full
	 * and the count matches what the user can act on. Composting removes a
	 * post's proposals, but any left from before are not listed either.
	 *
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;

		$this->_column_headers = array( $this->get_columns(), array(), array(), $this->get_default_primary_column_name() );

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

		// One query for every post on the screen, and one for their stages,
		// instead of one per row for the checks and for each title and address.
		_prime_post_caches(
			array_unique( array_map( 'intval', array_merge( array_column( $rows, 'source_id' ), array_column( $rows, 'target_id' ) ) ) ),
			false,
			true
		);

		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return current_user_can( 'edit_post', (int) $row['source_id'] )
						&& 'composting' !== undergrowth_get_stage( (int) $row['source_id'] )
						&& 'composting' !== undergrowth_get_stage( (int) $row['target_id'] );
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
		return $this->edit_link( $item['source_id'] );
	}

	/**
	 * Prints the sentence the link would sit in, with the word Accept would
	 * link marked, and the post the link would point to under it.
	 *
	 * The sentence is the author's own text, which may run the other way
	 * from the admin language, so the browser takes its direction from the
	 * text. The forms behind the row actions sit here too, holding only
	 * hidden fields.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string} $item Row.
	 * @return string Cell HTML.
	 */
	public function column_sentence( $item ) {
		return '<q dir="auto">' . $this->marked_sentence( $item ) . '</q>'
			. '<p>' . sprintf(
				/* translators: %1$s: title of the post the link would point to, linked to its editor. */
				esc_html__( 'Link to %1$s', 'undergrowth' ),
				$this->edit_link( $item['target_id'], 'row-title' )
			) . '</p>'
			. $this->action_form( $item, 'accept' ) . $this->action_form( $item, 'veto' );
	}

	/**
	 * Prints Accept and Veto under the sentence, as core's Comments screen
	 * prints Approve under a comment.
	 *
	 * They are this screen's whole purpose, so they are always shown rather
	 * than waiting for hover or focus.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string} $item        Row.
	 * @param string                                                  $column_name Column being printed.
	 * @param string                                                  $primary     Primary column name.
	 * @return string Row actions HTML, in the primary column only.
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}

		$source = get_the_title( $item['source_id'] );
		$target = get_the_title( $item['target_id'] );

		return $this->row_actions(
			array(
				'accept' => $this->action_button(
					$item,
					'accept',
					/* translators: 1: title of the post the link would sit in, 2: title of the post it would point to. */
					sprintf( __( 'Accept the link from %1$s to %2$s', 'undergrowth' ), $source, $target ),
					__( 'Accept', 'undergrowth' )
				),
				'veto'   => $this->action_button(
					$item,
					'veto',
					/* translators: 1: title of the post the link would sit in, 2: title of the post it would point to. */
					sprintf( __( 'Veto the link from %1$s to %2$s', 'undergrowth' ), $source, $target ),
					__( 'Veto', 'undergrowth' )
				),
			),
			true
		);
	}

	/**
	 * Escapes the sentence and marks the word Accept would link in it.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string} $item Row.
	 * @return string HTML.
	 */
	private function marked_sentence( array $item ): string {
		$sentence = $item['sentence'];
		$choice   = undergrowth_link_word_choice( $item['source_id'], $item['target_id'], $sentence );

		if ( null === $choice || mb_substr( $sentence, $choice['offset'], mb_strlen( $choice['word'] ) ) !== $choice['word'] ) {
			return esc_html( $sentence );
		}

		$length = mb_strlen( $choice['word'] );

		return esc_html( mb_substr( $sentence, 0, $choice['offset'] ) )
			. '<mark>' . esc_html( $choice['word'] ) . '</mark>'
			. esc_html( mb_substr( $sentence, $choice['offset'] + $length ) );
	}

	/**
	 * Links a post's title to its editor.
	 *
	 * Inside a line of text the title is a core row-title, which sets it
	 * apart from the words around it by weight, since list tables show
	 * links without an underline.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $link_class Class for the link, if any.
	 * @return string Link HTML.
	 */
	private function edit_link( int $post_id, string $link_class = '' ): string {
		return sprintf(
			'<a%1$s href="%2$s">%3$s</a>',
			'' === $link_class ? '' : ' class="' . esc_attr( $link_class ) . '"',
			esc_url( (string) get_edit_post_link( $post_id, 'raw' ) ),
			esc_html( get_the_title( $post_id ) )
		);
	}

	/**
	 * Returns the id of the form behind one action button.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string} $item   Row.
	 * @param string                                                  $action Action name.
	 * @return string Element id.
	 */
	private function form_id( array $item, string $action ): string {
		return sprintf( 'undergrowth-%1$s-%2$d-%3$d', $action, $item['source_id'], $item['target_id'] );
	}

	/**
	 * Builds one row action, a button tied to its form by the form attribute.
	 *
	 * The buttons sit outside their forms so they flow in the row actions
	 * like core's own, which use the same button-link class.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string} $item   Row.
	 * @param string                                                  $action Action name.
	 * @param string                                                  $label  Accessible name.
	 * @param string                                                  $text   Visible text.
	 * @return string Button HTML.
	 */
	private function action_button( array $item, string $action, string $label, string $text ): string {
		return sprintf(
			'<button type="submit" form="%1$s" class="button-link" aria-label="%2$s">%3$s</button>',
			esc_attr( $this->form_id( $item, $action ) ),
			esc_attr( $label ),
			esc_html( $text )
		);
	}

	/**
	 * Builds the form behind one action button, holding only hidden fields.
	 *
	 * Each action is a separate form so it works without JavaScript and
	 * carries a nonce for that exact pair of posts.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string} $item   Row.
	 * @param string                                                  $action Action name.
	 * @return string Form HTML.
	 */
	private function action_form( array $item, string $action ): string {
		return sprintf(
			'<form method="post" action="%1$s" id="%2$s"><input type="hidden" name="action" value="undergrowth_%3$s" /><input type="hidden" name="source" value="%4$d" /><input type="hidden" name="target" value="%5$d" />%6$s</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $this->form_id( $item, $action ) ),
			esc_attr( $action ),
			$item['source_id'],
			$item['target_id'],
			wp_nonce_field( 'undergrowth_' . $action . '_' . $item['source_id'] . '_' . $item['target_id'], '_wpnonce', true, false )
		);
	}
}
