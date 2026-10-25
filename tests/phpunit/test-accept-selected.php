<?php
/**
 * Tests for accepting several suggestions at once from the Suggestions table.
 *
 * @package Undergrowth
 */

/**
 * Accept selected: each checked suggestion goes through Accept as the user,
 * and one notice says what happened to each.
 */
class Test_Accept_Selected extends WP_UnitTestCase {

	use Plants_Posts;
	use Stops_Redirects;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		delete_option( 'undergrowth_suggest_links' );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		unset( $_POST['link'], $_POST['undergrowth_bulk'], $_POST['undergrowth_bulk2'], $_REQUEST['_wpnonce'], $_GET['undergrowth_notice'], $_GET['undergrowth_results'] );
		undergrowth_delete_memory_folder();
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Plants three posts that each name the other two, so each has
	 * suggestions to the others.
	 *
	 * @return int[] Oca, yacon and ulluco IDs.
	 */
	private function garden(): array {
		return array(
			$this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca grows here. I keep yacon nearby. Ulluco shares the bed.</p>' ) ),
			$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon syrup is sweet. Oca grows beside it. Ulluco too.</p>' ) ),
			$this->plant( 'Ulluco', array( 0.85, 0.0, 0.15 ), array( 'post_content' => '<p>Ulluco is bright. Oca likes the same soil. Yacon is taller.</p>' ) ),
		);
	}

	/**
	 * Sends the bulk form.
	 *
	 * @param string[] $links  Pairs as "source-target".
	 * @param string   $choice Bulk action chosen at the top.
	 * @param string   $nonce  Nonce to send, or an empty string for a valid one.
	 * @return string Redirect location.
	 * @throws WPDieException When the handler refuses the request.
	 */
	private function submit( array $links, string $choice = 'accept', string $nonce = '' ): string {
		$_POST['link']             = $links;
		$_POST['undergrowth_bulk'] = $choice;
		$_REQUEST['_wpnonce']      = '' === $nonce ? wp_create_nonce( 'undergrowth_accept_selected' ) : $nonce;

		try {
			undergrowth_handle_accept_selected();
		} catch ( WPDieException $e ) {
			throw $e;
		} catch ( Exception $e ) {
			return $e->getMessage();
		}

		return '';
	}

	/**
	 * Renders the Suggestions screen as the redirect after the bulk form shows it.
	 *
	 * @param string $location Redirect location, or an empty string for the plain screen.
	 * @return string
	 */
	private function render( string $location = '' ): string {
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );

		foreach ( array( 'undergrowth_notice', 'undergrowth_results' ) as $name ) {
			if ( isset( $query[ $name ] ) ) {
				$_GET[ $name ] = $query[ $name ];
			}
		}

		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();

		return (string) ob_get_clean();
	}

	public function test_each_row_has_an_unchecked_box_and_the_table_offers_accept_selected() {
		list( $oca, $yacon ) = $this->garden();

		$html = $this->render();

		$this->assertStringContainsString( 'id="undergrowth-accept-selected"', $html );
		$this->assertStringContainsString( 'name="link[]" value="' . $oca . '-' . $yacon . '" form="undergrowth-accept-selected"', $html );
		$this->assertStringNotContainsString( 'checked', $html );
		$this->assertStringContainsString( '<select name="undergrowth_bulk" id="bulk-action-selector-top" form="undergrowth-accept-selected">', $html );
		$this->assertStringContainsString( '<option value="accept">Accept selected</option>', $html );
		// Each box is named by the link it selects.
		$this->assertMatchesRegularExpression( '/<label class="screen-reader-text" for="cb-select-' . $oca . '-' . $yacon . '">[^<]*Oca[^<]*Yacon[^<]*<\/label>/', $html );
	}

	public function test_three_selected_suggestions_are_each_linked_with_their_own_revision() {
		list( $oca, $yacon, $ulluco ) = $this->garden();
		$pairs                        = array( array( $oca, $yacon ), array( $yacon, $ulluco ), array( $ulluco, $oca ) );

		$location = $this->submit(
			array_map(
				static function ( $pair ) {
					return $pair[0] . '-' . $pair[1];
				},
				$pairs
			)
		);

		foreach ( $pairs as $pair ) {
			$this->assertSame( 'accepted', undergrowth_link_state( $pair[0], $pair[1] ) );
			$this->assertStringContainsString( get_permalink( $pair[1] ), get_post( $pair[0] )->post_content );
			$this->assertCount( 1, wp_get_post_revisions( $pair[0] ) );
		}

		$this->assertStringContainsString( 'undergrowth_notice=accepted_selected', $location );

		$html = $this->render( $location );

		$this->assertStringContainsString( 'Linked 3 of 3 selected suggestions.', $html );
		$this->assertSame( 3, substr_count( $html, 'Linked. The highlighted words' ) );
		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'role="status"', $html );
	}

	public function test_a_refused_suggestion_is_listed_with_its_reason_and_stays_proposed() {
		global $wpdb;

		list( $oca, $yacon, $ulluco ) = $this->garden();
		$wpdb->update(
			undergrowth_links_table(),
			array( 'sentence' => 'A sentence no longer in the post.' ),
			array(
				'source_id' => $yacon,
				'target_id' => $ulluco,
			)
		);

		$html = $this->render( $this->submit( array( $oca . '-' . $yacon, $yacon . '-' . $ulluco ) ) );

		$this->assertSame( 'accepted', undergrowth_link_state( $oca, $yacon ) );
		$this->assertSame( 'proposed', undergrowth_link_state( $yacon, $ulluco ) );
		$this->assertStringContainsString( 'Linked 1 of 2 selected suggestions.', $html );
		$this->assertStringContainsString( 'That sentence has changed since the link was suggested', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
	}

	public function test_a_suggestion_in_a_post_the_user_cannot_edit_is_refused_and_left_alone() {
		list( $oca, $yacon ) = $this->garden();
		$author              = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_update_post(
			array(
				'ID'          => $yacon,
				'post_author' => $author,
			)
		);
		wp_set_current_user( $author );

		// An author cannot open the screen, but could still send the form.
		$location = $this->submit( array( $oca . '-' . $yacon, $yacon . '-' . $oca ) );

		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
		$this->assertSame( 'accepted', undergrowth_link_state( $yacon, $oca ) );
		$this->assertStringContainsString( $oca . '-' . $yacon . '.forbidden', $location );
	}

	public function test_the_bulk_form_without_a_valid_nonce_is_refused() {
		list( $oca, $yacon ) = $this->garden();

		try {
			$this->submit( array( $oca . '-' . $yacon ), 'accept', wp_create_nonce( 'undergrowth_accept_' . $oca . '_' . $yacon ) );
			$this->fail( 'The bulk form was not refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
		}
	}

	public function test_apply_with_nothing_selected_or_no_action_changes_nothing_and_says_so() {
		list( $oca, $yacon ) = $this->garden();

		$this->assertStringContainsString( 'undergrowth_notice=nothing_selected', $this->submit( array() ) );

		$location = $this->submit( array( $oca . '-' . $yacon ), '-1' );

		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
		$this->assertStringContainsString( 'undergrowth_notice=nothing_selected', $location );
		$this->assertStringContainsString( 'nothing was changed', $this->render( $location ) );
	}

	public function test_the_bottom_bulk_menu_works_too() {
		list( $oca, $yacon )        = $this->garden();
		$_POST['undergrowth_bulk2'] = 'accept';

		$this->submit( array( $oca . '-' . $yacon ), '-1' );

		$this->assertSame( 'accepted', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_a_malformed_result_address_shows_no_list() {
		$html = $this->render( admin_url( 'edit.php?page=undergrowth-links&undergrowth_notice=accepted_selected&undergrowth_results=x-y.accepted,1-2.<script>' ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<li>', $html );
	}

	public function test_the_results_address_is_removed_once_shown() {
		set_current_screen( 'posts_page_undergrowth-links' );

		$this->assertContains( 'undergrowth_results', wp_removable_query_args() );
	}
}
