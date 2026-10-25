<?php
/**
 * The table of orphans on the link suggestions screen.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists the posts no accepted link points to, newest first, each with the
 * best suggestions that would link to it.
 */
class Undergrowth_Orphans_List_Table extends WP_List_Table {

	const PER_PAGE = 20;

	/**
	 * Sets up the table.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'undergrowth-orphan',
				'plural'   => 'undergrowth-orphans',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Names the table's columns.
	 *
	 * The suggestions only show while suggestions are on, as on the
	 * Suggestions tab.
	 *
	 * @return array<string, string> Column labels keyed by column name.
	 */
	public function get_columns() {
		$columns = array(
			'post'  => __( 'Post', 'undergrowth' ),
			'stage' => __( 'Growth stage', 'undergrowth' ),
			'date'  => __( 'Published', 'undergrowth' ),
		);

		if ( undergrowth_suggestions_enabled() ) {
			$columns['links'] = __( 'Could link here', 'undergrowth' );
		}

		return $columns;
	}

	/**
	 * Says that no post is an orphan.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Every post in your garden has at least one accepted link pointing to it.', 'undergrowth' );
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
	 * Loads the orphans for the current page.
	 *
	 * A page past the end, as after the last orphan on it gained a link,
	 * shows the last page instead, as core's pagination does.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array(), $this->get_default_primary_column_name() );

		$total = undergrowth_count_orphans();
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = min( $this->get_pagenum(), $pages );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);

		$post_ids = undergrowth_orphan_post_ids( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );

		_prime_post_caches( $post_ids, false, true );

		$this->items = array_values( array_filter( array_map( 'get_post', $post_ids ) ) );
	}

	/**
	 * Prints the post's title, linked to its editor when the user can edit it.
	 *
	 * @param WP_Post $item Post.
	 * @return string Cell HTML.
	 */
	public function column_post( $item ) {
		return '<strong>' . undergrowth_post_link( $item->ID, 'row-title' ) . '</strong>';
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
	 * Prints the date the post was published, in the site's date format.
	 *
	 * @param WP_Post $item Post.
	 * @return string Cell HTML.
	 */
	public function column_date( $item ) {
		return esc_html( (string) get_the_date( '', $item ) );
	}

	/**
	 * Prints the best suggestions that would link to the post, each with
	 * Accept and Veto as the Suggestions tab gives them, and a link to the
	 * rest.
	 *
	 * @param WP_Post $item Post.
	 * @return string Cell HTML.
	 */
	public function column_links( $item ) {
		$suggestions = undergrowth_suggestions_to( $item->ID );

		if ( array() === $suggestions ) {
			return undergrowth_empty_cell( __( 'No suggestions yet', 'undergrowth' ) );
		}

		$html = '<ul class="undergrowth-orphan-links">';

		foreach ( array_slice( $suggestions, 0, UNDERGROWTH_ORPHAN_SUGGESTIONS ) as $suggestion ) {
			$html .= '<li>' . $this->suggestion( $suggestion ) . '</li>';
		}

		$html .= '</ul>';

		if ( count( $suggestions ) > UNDERGROWTH_ORPHAN_SUGGESTIONS ) {
			$html .= sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( undergrowth_links_in_url( $item->ID ) ),
				esc_html(
					sprintf(
						/* translators: %1$s: number of suggestions. */
						_n( 'See all %1$s suggestion', 'See all %1$s suggestions', count( $suggestions ), 'undergrowth' ),
						number_format_i18n( count( $suggestions ) )
					)
				)
			);
		}

		return $html;
	}

	/**
	 * Prints one suggestion: the author's sentence with the words Accept
	 * would link marked, the post it sits in, and Accept and Veto.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string, anchor: string, offset: int} $item Suggestion.
	 * @return string HTML.
	 */
	private function suggestion( array $item ): string {
		$choice = undergrowth_link_word_choice( $item['source_id'], $item['target_id'], $item['sentence'], $item['anchor'], $item['offset'] );
		$pair   = array(
			'source' => $item['source_id'],
			'target' => $item['target_id'],
		);
		$source = undergrowth_post_title( $item['source_id'] );
		$target = undergrowth_post_title( $item['target_id'] );

		return '<q dir="auto">' . undergrowth_marked_sentence( $item['sentence'], $choice ) . '</q>'
			. '<p>' . sprintf(
				/* translators: %1$s: title of the post the link would sit in, linked to its editor. */
				esc_html__( 'In %1$s', 'undergrowth' ),
				undergrowth_post_link( $item['source_id'] )
			) . '</p>'
			. $this->row_actions(
				array(
					'accept' => undergrowth_row_action_button(
						'accept',
						$pair,
						/* translators: 1: title of the post the link would sit in, 2: title of the post it would point to. Must begin with the translation of “Accept”, the word on the button, so voice control can find it. */
						sprintf( __( 'Accept the link from %1$s to %2$s', 'undergrowth' ), $source, $target ),
						__( 'Accept', 'undergrowth' )
					),
					'veto'   => undergrowth_row_action_button(
						'veto',
						$pair,
						/* translators: 1: title of the post the link would sit in, 2: title of the post it would point to. Must begin with the translation of “Veto”, the word on the button, so voice control can find it. */
						sprintf( __( 'Veto the link from %1$s to %2$s', 'undergrowth' ), $source, $target ),
						__( 'Veto', 'undergrowth' )
					),
				),
				true
			)
			. undergrowth_row_action_form( 'accept', $pair ) . undergrowth_row_action_form( 'veto', $pair );
	}
}
