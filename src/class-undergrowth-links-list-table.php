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
				'SELECT source_id, target_id, sentence, anchor, anchor_offset FROM %i WHERE state = %s ORDER BY updated_at DESC, source_id ASC, score DESC',
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
					'anchor'    => (string) $row['anchor'],
					'offset'    => (int) $row['anchor_offset'],
				);
			},
			array_slice( $rows, ( $this->get_pagenum() - 1 ) * self::PER_PAGE, self::PER_PAGE )
		);
	}

	/**
	 * Prints the source post's title, linked to its editor.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string, anchor: string, offset: int} $item Row.
	 * @return string Cell HTML.
	 */
	public function column_source( $item ) {
		return $this->edit_link( $item['source_id'] );
	}

	/**
	 * Prints the sentence the link would sit in, with the words Accept would
	 * link marked, and the post the link would point to under it.
	 *
	 * The sentence is the author's own text, which may run the other way
	 * from the admin language, so the browser takes its direction from the
	 * text. The forms behind the row actions sit here too, holding only
	 * hidden fields.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string, anchor: string, offset: int} $item Row.
	 * @return string Cell HTML.
	 */
	public function column_sentence( $item ) {
		$choice = undergrowth_link_word_choice( $item['source_id'], $item['target_id'], $item['sentence'], $item['anchor'], $item['offset'] );

		return '<q dir="auto">' . $this->marked_sentence( $item, $choice ) . '</q>'
			. '<p>' . sprintf(
				/* translators: %1$s: title of the post the link would point to, linked to its editor. */
				esc_html__( 'Link to %1$s', 'undergrowth' ),
				$this->edit_link( $item['target_id'], 'row-title' )
			) . '</p>'
			. ( $this->is_choosing( $item ) ? $this->choose_form( $item, $choice ) : '' )
			. $this->action_form( $item, 'accept' ) . $this->action_form( $item, 'veto' );
	}

	/**
	 * Prints Accept and Veto under the sentence, as core's Comments screen
	 * prints Approve under a comment.
	 *
	 * They are this screen's whole purpose, so they are always shown rather
	 * than waiting for hover or focus.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string, anchor: string, offset: int} $item        Row.
	 * @param string                                                                               $column_name Column being printed.
	 * @param string                                                                               $primary     Primary column name.
	 * @return string Row actions HTML, in the primary column only.
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}

		$source = undergrowth_post_title( $item['source_id'] );
		$target = undergrowth_post_title( $item['target_id'] );

		return $this->row_actions(
			array(
				'accept' => $this->action_button(
					$item,
					'accept',
					/* translators: 1: title of the post the link would sit in, 2: title of the post it would point to. Must begin with the translation of “Accept”, the word on the button, so voice control can find it. */
					sprintf( __( 'Accept the link from %1$s to %2$s', 'undergrowth' ), $source, $target ),
					__( 'Accept', 'undergrowth' )
				),
				'veto'   => $this->action_button(
					$item,
					'veto',
					/* translators: 1: title of the post the link would sit in, 2: title of the post it would point to. Must begin with the translation of “Veto”, the word on the button, so voice control can find it. */
					sprintf( __( 'Veto the link from %1$s to %2$s', 'undergrowth' ), $source, $target ),
					__( 'Veto', 'undergrowth' )
				),
			)
			+ ( undergrowth_can_choose_words( $item['sentence'] ) ? array(
				'choose' => sprintf(
					'<a href="%1$s" aria-label="%2$s">%3$s</a>',
					esc_url( $this->choose_url( $item ) ),
					/* translators: 1: title of the post the link would sit in, 2: title of the post it would point to. Must begin with the translation of “Choose words”, the words on the link, so voice control can find it. */
					esc_attr( sprintf( __( 'Choose words for the link from %1$s to %2$s', 'undergrowth' ), $source, $target ) ),
					esc_html__( 'Choose words', 'undergrowth' )
				),
			) : array() ),
			true
		);
	}

	/**
	 * Returns the address that opens a row's Choose words form.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string, anchor: string, offset: int} $item Row.
	 * @return string URL, ending at the form.
	 */
	private function choose_url( array $item ): string {
		$pagenum = $this->get_pagenum();

		return add_query_arg(
			array(
				'choose' => $item['source_id'] . '-' . $item['target_id'],
				'paged'  => $pagenum > 1 ? $pagenum : false,
			),
			undergrowth_suggestions_url()
		) . '#' . $this->form_id( $item, 'choose' );
	}

	/**
	 * Tells whether a row's Choose words form is open.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string, anchor: string, offset: int} $item Row.
	 * @return bool
	 */
	private function is_choosing( array $item ): bool {
		// Only opens a form; nothing is changed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$choose = isset( $_GET['choose'] ) ? sanitize_text_field( wp_unslash( $_GET['choose'] ) ) : '';

		return $item['source_id'] . '-' . $item['target_id'] === $choose && undergrowth_can_choose_words( $item['sentence'] );
	}

	/**
	 * Builds the form where the author chooses the first and last of their
	 * own words to link.
	 *
	 * Two native selects list the sentence's words in order, so the form
	 * works by keyboard and with a screen reader, and nothing can be typed.
	 * The words now marked are selected.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string, anchor: string, offset: int} $item   Row.
	 * @param array{word: string, offset: int, position: int}|null                                 $choice Words Accept would link now.
	 * @return string Form HTML.
	 */
	private function choose_form( array $item, ?array $choice ): string {
		$words = undergrowth_anchor_words( $item['sentence'] );
		$count = count( $words );
		$first = 0;
		$last  = 0;

		if ( null !== $choice ) {
			$end = $choice['offset'] + mb_strlen( (string) preg_replace( '/\s+/u', ' ', $choice['word'] ) );

			foreach ( $words as $index => $word ) {
				$first = $word['offset'] === $choice['offset'] ? $index : $first;
				$last  = $word['offset'] + $word['length'] === $end ? $index : $last;
			}
		}

		$suffix = $item['source_id'] . '-' . $item['target_id'];
		$fields = '';

		foreach ( array(
			'first' => array( __( 'First word', 'undergrowth' ), $first ),
			'last'  => array( __( 'Last word', 'undergrowth' ), $last ),
		) as $name => $field ) {
			$options = '';

			foreach ( $words as $index => $word ) {
				$options .= sprintf(
					'<option value="%1$d"%2$s>%3$s</option>',
					$index,
					selected( $field[1], $index, false ),
					/* translators: 1: a word from the author's sentence, 2: its position in the sentence, 3: how many words the sentence has. */
					esc_html( sprintf( __( '%1$s (%2$s of %3$s)', 'undergrowth' ), $word['text'], number_format_i18n( $index + 1 ), number_format_i18n( $count ) ) )
				);
			}

			$fields .= sprintf(
				'<p><label for="undergrowth-%1$s-%2$s">%3$s</label> <select id="undergrowth-%1$s-%2$s" name="%1$s" dir="auto">%4$s</select></p>',
				esc_attr( $name ),
				esc_attr( $suffix ),
				esc_html( $field[0] ),
				$options
			);
		}

		return sprintf(
			'<form method="post" action="%1$s" id="%2$s" class="undergrowth-choose-words"><fieldset><legend>%3$s</legend><p class="description">%4$s</p>%5$s</fieldset>'
			. '<input type="hidden" name="action" value="undergrowth_choose_words" /><input type="hidden" name="source" value="%6$d" /><input type="hidden" name="target" value="%7$d" /><input type="hidden" name="shown" value="%8$s" />%9$s'
			. '<p><button type="submit" class="button button-primary">%10$s</button> <a href="%11$s" class="button">%12$s</a></p></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $this->form_id( $item, 'choose' ) ),
			esc_html__( 'Choose the words to link', 'undergrowth' ),
			esc_html__( 'The link goes around your own words, from the first word to the last. Only words already in this sentence can be chosen.', 'undergrowth' ),
			$fields,
			$item['source_id'],
			$item['target_id'],
			esc_attr( undergrowth_sentence_version( $item['sentence'] ) ),
			wp_nonce_field( 'undergrowth_choose_words_' . $item['source_id'] . '_' . $item['target_id'], '_wpnonce', true, false ),
			esc_html__( 'Save words', 'undergrowth' ),
			esc_url( undergrowth_suggestions_url() ),
			esc_html__( 'Cancel', 'undergrowth' )
		) . wp_get_inline_script_tag( "window.addEventListener( 'load', function () { var first = document.getElementById( " . wp_json_encode( 'undergrowth-first-' . $suffix ) . ' ); if ( first ) { first.focus(); } } );' );
	}

	/**
	 * Escapes the sentence and marks the words Accept would link in it.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string, anchor: string, offset: int} $item Row.
	 * @param array{word: string, offset: int, position: int}|null                                 $choice Result of undergrowth_link_word_choice() for the row.
	 * @return string HTML.
	 */
	private function marked_sentence( array $item, ?array $choice ): string {
		$sentence = $item['sentence'];

		// A line break inside the words in the post shows as a space.
		$words  = null === $choice ? '' : (string) preg_replace( '/\s+/u', ' ', $choice['word'] );
		$length = mb_strlen( $words );

		if ( null === $choice || mb_substr( $sentence, $choice['offset'], $length ) !== $words ) {
			return esc_html( $sentence );
		}

		return esc_html( mb_substr( $sentence, 0, $choice['offset'] ) )
			. '<mark>' . esc_html( $words ) . '</mark>'
			. esc_html( mb_substr( $sentence, $choice['offset'] + $length ) );
	}

	/**
	 * Links a post's title to its editor, when the user can edit it.
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
		$title = '<bdi>' . esc_html( undergrowth_post_title( $post_id ) ) . '</bdi>';
		$link  = get_edit_post_link( $post_id, 'raw' );

		// A post the user cannot edit is named, not linked. In a line of
		// text it keeps the weight a row-title would have given it.
		if ( null === $link ) {
			return '' === $link_class ? $title : '<strong>' . $title . '</strong>';
		}

		return sprintf(
			'<a%1$s href="%2$s">%3$s</a>',
			'' === $link_class ? '' : ' class="' . esc_attr( $link_class ) . '"',
			esc_url( $link ),
			$title
		);
	}

	/**
	 * Returns the id of the form behind one action button.
	 *
	 * @param array{source_id: int, target_id: int, sentence: string, anchor: string, offset: int} $item   Row.
	 * @param string                                                                               $action Action name.
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
	 * @param array{source_id: int, target_id: int, sentence: string, anchor: string, offset: int} $item   Row.
	 * @param string                                                                               $action Action name.
	 * @param string                                                                               $label  Accessible name.
	 * @param string                                                                               $text   Visible text.
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
	 * @param array{source_id: int, target_id: int, sentence: string, anchor: string, offset: int} $item   Row.
	 * @param string                                                                               $action Action name.
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
