<?php
/**
 * Tests for hub proposals on the Map, hub drafts and the hub part of the
 * Evergreen rule.
 *
 * @package Undergrowth
 */

/**
 * Hubs.
 */
class Test_Hubs extends WP_UnitTestCase {

	use Plants_Posts;

	use Stops_Redirects;

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		unset( $_POST['post'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['tab'], $_GET['undergrowth_notice'], $_GET['undergrowth_hub'] );

		parent::tear_down();
	}

	/**
	 * Publishes posts with vectors and puts them in one cluster, led by the first.
	 *
	 * @param string[] $titles Post titles.
	 * @return int[] Post IDs, in the order of the titles.
	 */
	private function group( array $titles ): array {
		$ids = array();

		foreach ( $titles as $i => $title ) {
			$ids[] = $this->seed( $title, array( 1.0, 0.01 * $i, 0.0 ) );
		}

		foreach ( $ids as $post_id ) {
			update_post_meta( $post_id, 'undergrowth_cluster', $ids[0] );
		}

		return $ids;
	}

	/**
	 * Stores an accepted link between two posts.
	 *
	 * @param int    $source_id Source post ID.
	 * @param int    $target_id Target post ID.
	 * @param string $state     Row state.
	 */
	private function link( int $source_id, int $target_id, string $state = 'accepted' ): void {
		global $wpdb;

		$wpdb->insert(
			undergrowth_links_table(),
			array(
				'source_id'  => $source_id,
				'target_id'  => $target_id,
				'state'      => $state,
				'score'      => 0.5,
				'sentence'   => '',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Makes a group of five posts, three of them linked to each other.
	 *
	 * @return int[] Post IDs.
	 */
	private function dense_group(): array {
		$ids = $this->group( array( 'Oca', 'Ulluco', 'Mashua', 'Yacon', 'Achira' ) );

		$this->link( $ids[0], $ids[1] );
		$this->link( $ids[2], $ids[0] );

		return $ids;
	}

	/**
	 * Posts a hub action through its handler and returns where it went.
	 *
	 * @param string $action 'start_hub' or 'veto_hub'.
	 * @param int    $leader Leader ID.
	 * @param string $nonce  Nonce to send, or an empty string for the right one.
	 * @return string Redirect address.
	 * @throws WPDieException When the handler refuses the request.
	 */
	private function post_action( string $action, int $leader, string $nonce = '' ): string {
		$nonce                = '' === $nonce ? wp_create_nonce( 'undergrowth_' . $action . '_' . $leader ) : $nonce;
		$_POST['post']        = (string) $leader;
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		try {
			call_user_func( 'start_hub' === $action ? 'undergrowth_handle_start_hub' : 'undergrowth_handle_veto_hub' );
		} catch ( WPDieException $e ) {
			throw $e;
		} catch ( Exception $e ) {
			return $e->getMessage();
		}

		return '';
	}

	/**
	 * Renders the Map tab and returns its HTML.
	 *
	 * @return string
	 */
	private function render_map(): string {
		$_GET['tab'] = 'map';
		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();

		return (string) ob_get_clean();
	}

	public function test_large_group_whose_posts_link_to_each_other_is_a_candidate() {
		$this->assertTrue( undergrowth_is_hub_candidate( $this->dense_group() ) );
	}

	public function test_group_below_the_size_is_not_a_candidate() {
		$ids = $this->group( array( 'Oca', 'Ulluco', 'Mashua', 'Yacon' ) );

		$this->link( $ids[0], $ids[1] );
		$this->link( $ids[2], $ids[3] );

		$this->assertFalse( undergrowth_is_hub_candidate( $ids ) );
	}

	public function test_group_with_too_few_linked_posts_is_not_a_candidate() {
		$ids = $this->group( array( 'Oca', 'Ulluco', 'Mashua', 'Yacon', 'Achira' ) );

		$this->link( $ids[0], $ids[1] );

		$this->assertSame( 2, undergrowth_hub_linked_members( $ids ) );
		$this->assertFalse( undergrowth_is_hub_candidate( $ids ) );
	}

	public function test_only_accepted_links_inside_the_group_count() {
		$ids     = $this->group( array( 'Oca', 'Ulluco', 'Mashua', 'Yacon', 'Achira' ) );
		$outside = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->link( $ids[0], $ids[1], 'proposed' );
		$this->link( $ids[2], $ids[3], 'vetoed' );
		$this->link( $ids[4], $outside );
		$this->link( $outside, $ids[0] );

		$this->assertSame( 0, undergrowth_hub_linked_members( $ids ) );
	}

	public function test_filters_set_the_size_and_share_with_a_floor_on_size() {
		$ids = $this->group( array( 'Oca', 'Ulluco', 'Mashua' ) );
		$this->link( $ids[0], $ids[1] );

		add_filter(
			'undergrowth_hub_min_size',
			static function () {
				return 1;
			}
		);
		add_filter(
			'undergrowth_hub_min_linked_share',
			static function () {
				return 0.6;
			}
		);

		$this->assertSame( UNDERGROWTH_MIN_CLUSTER_SIZE, undergrowth_hub_min_size() );
		$this->assertSame( 0.6, undergrowth_hub_min_linked_share() );
		$this->assertTrue( undergrowth_is_hub_candidate( $ids ) );

		add_filter(
			'undergrowth_hub_min_linked_share',
			static function () {
				return 'lots';
			},
			20
		);

		$this->assertSame( UNDERGROWTH_HUB_MIN_LINKED_SHARE, undergrowth_hub_min_linked_share() );
	}

	public function test_size_filter_that_returns_no_number_keeps_the_default() {
		add_filter(
			'undergrowth_hub_min_size',
			static function () {
				return 'many';
			}
		);

		$this->assertSame( UNDERGROWTH_HUB_MIN_SIZE, undergrowth_hub_min_size() );
	}

	public function test_fewer_than_two_posts_have_no_links_between_them() {
		$ids = $this->group( array( 'Oca' ) );

		$this->link( $ids[0], $ids[0] );

		$this->assertSame( 0, undergrowth_hub_linked_members( $ids ) );
		$this->assertSame( 0, undergrowth_hub_linked_members( array() ) );
	}

	public function test_hub_draft_holds_only_the_titles_as_links_and_stays_a_draft() {
		$ids = $this->dense_group();
		wp_update_post(
			array(
				'ID'         => $ids[3],
				'post_title' => 'Yacon <em>&</em> "syrup"',
			)
		);

		$hub_id = undergrowth_start_hub( $ids[0] );

		$this->assertIsInt( $hub_id );

		$hub = get_post( $hub_id );

		$this->assertSame( 'page', $hub->post_type );
		$this->assertSame( 'draft', $hub->post_status );
		$this->assertSame( '', $hub->post_title );
		$this->assertSame( get_current_user_id(), (int) $hub->post_author );

		// One list block, one item per post in Map order, nothing else.
		$blocks = parse_blocks( $hub->post_content );
		$blocks = array_values(
			array_filter(
				$blocks,
				static function ( $block ) {
					return null !== $block['blockName'];
				}
			)
		);

		$this->assertCount( 1, $blocks );
		$this->assertSame( 'core/list', $blocks[0]['blockName'] );

		$map    = undergrowth_map_group( $ids[0] );
		$links  = array();
		$titles = array();

		foreach ( $blocks[0]['innerBlocks'] as $item ) {
			$this->assertSame( 'core/list-item', $item['blockName'] );
			$this->assertSame( 1, preg_match( '#^\s*<li><a href="([^"]+)">([^<]*)</a></li>\s*$#', $item['innerHTML'], $match ) );
			$links[]  = $match[1];
			$titles[] = html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		$this->assertSame( array_map( 'get_permalink', $map ), $links );
		$this->assertSame(
			array_map(
				static function ( $post_id ) {
					return undergrowth_plain_line( get_post_field( 'post_title', $post_id, 'raw' ) );
				},
				$map
			),
			$titles
		);
		$this->assertContains( 'Yacon & "syrup"', $titles );
		$this->assertSame( '', trim( wp_strip_all_tags( preg_replace( '#<a [^>]*>[^<]*</a>#', '', $hub->post_content ) ) ) );
	}

	public function test_untitled_post_is_named_by_its_address_in_the_draft() {
		$ids = $this->dense_group();
		wp_update_post(
			array(
				'ID'         => $ids[4],
				'post_title' => '',
			)
		);

		$content = undergrowth_hub_draft_content( array( $ids[4] ) );
		$url     = esc_url( get_permalink( $ids[4] ) );

		$this->assertStringContainsString( '<a href="' . $url . '">' . esc_html( get_permalink( $ids[4] ) ) . '</a>', $content );
	}

	public function test_title_filters_never_reach_the_draft() {
		$ids = $this->dense_group();

		add_filter(
			'the_title',
			static function ( $title ) {
				return $title . ' (written by a model)';
			}
		);

		$this->assertStringNotContainsString( 'model', undergrowth_hub_draft_content( $ids ) );
	}

	public function test_group_with_a_hub_gets_no_second_one() {
		$ids = $this->dense_group();
		$hub = undergrowth_start_hub( $ids[0] );

		$this->assertSame( $hub, undergrowth_group_hub( $ids ) );
		$this->assertFalse( undergrowth_is_hub_candidate( $ids ) );

		$again = undergrowth_start_hub( $ids[0] );

		$this->assertWPError( $again );
		$this->assertSame( 'hub_gone', $again->get_error_code() );
	}

	public function test_trashed_hub_no_longer_counts() {
		$ids = $this->dense_group();
		$hub = undergrowth_start_hub( $ids[0] );

		wp_trash_post( $hub );

		$this->assertSame( 0, undergrowth_group_hub( $ids ) );
		$this->assertTrue( undergrowth_is_hub_candidate( $ids ) );
	}

	public function test_no_hub_starts_for_a_group_not_on_the_map_or_not_dense() {
		$ids = $this->group( array( 'Oca', 'Ulluco', 'Mashua', 'Yacon', 'Achira' ) );

		$this->assertWPError( undergrowth_start_hub( $ids[0] ) );
		$this->assertWPError( undergrowth_start_hub( $ids[1] ) );
		$this->assertSame( array(), undergrowth_hubs() );
	}

	public function test_vetoed_group_is_never_proposed_again_even_under_a_new_leader() {
		$ids = $this->dense_group();

		$this->assertTrue( undergrowth_veto_hub( $ids[0] ) );
		$this->assertFalse( undergrowth_is_hub_candidate( $ids ) );
		$this->assertFalse( undergrowth_veto_hub( $ids[0] ) );

		// Next month the same posts are led by another, and one more joins.
		$more = $this->seed( 'Arracacha', array( 1.0, 0.0, 0.01 ) );
		$now  = array_merge( $ids, array( $more ) );

		foreach ( $now as $post_id ) {
			update_post_meta( $post_id, 'undergrowth_cluster', $ids[2] );
		}

		$this->assertFalse( undergrowth_is_hub_candidate( undergrowth_map_group( $ids[2] ) ) );
		$this->assertWPError( undergrowth_start_hub( $ids[2] ) );
		$this->assertSame( array( $ids ), undergrowth_hub_vetoes() );
	}

	public function test_group_that_changed_by_more_than_half_is_a_new_group() {
		$this->assertTrue( undergrowth_same_hub_group( array( 1, 2, 3, 4, 5 ), array( 1, 2, 3, 6, 7 ) ) );
		$this->assertFalse( undergrowth_same_hub_group( array( 1, 2, 3, 4, 5 ), array( 1, 2, 6, 7, 8 ) ) );
		$this->assertFalse( undergrowth_same_hub_group( array( 1, 2, 3, 4, 5 ), array( 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 ) ) );
		$this->assertFalse( undergrowth_same_hub_group( array(), array() ) );
	}

	public function test_malformed_vetoes_are_left_out() {
		update_option( 'undergrowth_hub_vetoes', array( 'x', array(), array( '3', 2 ) ) );

		$this->assertSame( array( array( 3, 2 ) ), undergrowth_hub_vetoes() );

		update_option( 'undergrowth_hub_vetoes', 'x' );

		$this->assertSame( array(), undergrowth_hub_vetoes() );
	}

	public function test_hub_records_the_posts_it_links_to_each_time_it_is_saved() {
		$ids = $this->dense_group();
		$hub = undergrowth_start_hub( $ids[0] );

		$linked = array_map( 'intval', get_post_meta( $hub, 'undergrowth_hub_link', false ) );
		sort( $linked );
		$this->assertSame( $ids, $linked );

		wp_update_post(
			array(
				'ID'           => $hub,
				'post_content' => '<p>My favourite is <a href="' . esc_url( get_permalink( $ids[1] ) ) . '">ulluco</a>.</p>',
			)
		);

		$this->assertSame( array( (string) $ids[1] ), get_post_meta( $hub, 'undergrowth_hub_link', false ) );
	}

	public function test_page_that_is_no_hub_records_nothing() {
		$ids  = $this->dense_group();
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<a href="' . esc_url( get_permalink( $ids[0] ) ) . '">Oca</a>',
			)
		);

		$this->assertSame( array(), get_post_meta( $page, 'undergrowth_hub_link', false ) );
	}

	/**
	 * Makes a post in a group with three accepted links in and a hub draft
	 * linking to it.
	 *
	 * @return array{0: int, 1: int} The post's ID and the hub's.
	 */
	private function post_with_hub(): array {
		$ids = $this->dense_group();

		$this->link( $ids[3], $ids[0] );
		$this->link( $ids[4], $ids[0] );

		return array( $ids[0], undergrowth_start_hub( $ids[0] ) );
	}

	public function test_post_a_published_hub_links_to_measures_evergreen() {
		list( $post_id, $hub ) = $this->post_with_hub();

		$this->assertSame( 'budding', undergrowth_measure_stage( $post_id ) );

		wp_publish_post( $hub );

		$this->assertSame( 'evergreen', undergrowth_measure_stage( $post_id ) );
	}

	public function test_hub_behind_a_password_does_not_count() {
		list( $post_id, $hub ) = $this->post_with_hub();

		wp_update_post(
			array(
				'ID'            => $hub,
				'post_status'   => 'publish',
				'post_password' => 'tuber',
			)
		);

		$this->assertSame( 'budding', undergrowth_measure_stage( $post_id ) );
	}

	public function test_hub_link_needs_three_accepted_links_in() {
		$ids = $this->dense_group();
		$hub = undergrowth_start_hub( $ids[0] );

		wp_publish_post( $hub );

		// Oca has one link in and one out, Ulluco one in. The hub's own link
		// is not one of the three.
		$this->assertTrue( undergrowth_has_hub_link( $ids[0] ) );
		$this->assertTrue( undergrowth_has_hub_link( $ids[1] ) );
		$this->assertSame( 'budding', undergrowth_measure_stage( $ids[0] ) );
		$this->assertSame( 'seedling', undergrowth_measure_stage( $ids[1] ) );
	}

	public function test_accepted_link_from_the_hub_is_not_one_of_the_three() {
		add_filter(
			'undergrowth_post_types',
			static function () {
				return array( 'post', 'page' );
			}
		);

		$ids = $this->dense_group();
		$hub = undergrowth_start_hub( $ids[0] );

		wp_publish_post( $hub );
		$this->link( $ids[3], $ids[0] );
		$this->link( $hub, $ids[0] );

		// Ulluco's link is out; Mashua's, Yacon's and the hub's are in.
		$this->assertSame( 'budding', undergrowth_measure_stage( $ids[0] ) );

		$this->link( $ids[4], $ids[0] );

		$this->assertSame( 'evergreen', undergrowth_measure_stage( $ids[0] ) );
	}

	public function test_post_out_of_any_group_is_not_evergreen_even_with_a_hub_link() {
		list( $post_id, $hub ) = $this->post_with_hub();

		wp_publish_post( $hub );
		delete_post_meta( $post_id, 'undergrowth_cluster' );

		$this->assertNotSame( 'evergreen', undergrowth_measure_stage( $post_id ) );
	}

	public function test_map_proposes_a_hub_with_both_buttons() {
		$ids  = $this->dense_group();
		$html = $this->render_map();

		$this->assertStringContainsString( 'undergrowth-hub-proposal', $html );
		$this->assertStringContainsString( 'name="action" value="undergrowth_start_hub"', $html );
		$this->assertStringContainsString( 'name="action" value="undergrowth_veto_hub"', $html );
		$this->assertStringContainsString( 'aria-describedby="undergrowth-hub-' . $ids[0] . '"', $html );
		$this->assertStringContainsString( 'id="undergrowth-hub-' . $ids[0] . '"', $html );
	}

	public function test_map_proposes_nothing_for_a_sparse_group() {
		$this->group( array( 'Oca', 'Ulluco', 'Mashua', 'Yacon', 'Achira' ) );

		$this->assertStringNotContainsString( 'undergrowth-hub', $this->render_map() );
	}

	public function test_map_shows_the_hub_draft_instead_of_a_proposal() {
		$ids = $this->dense_group();
		$hub = undergrowth_start_hub( $ids[0] );
		$map = $this->render_map();

		$this->assertStringNotContainsString( 'undergrowth-hub-proposal', $map );
		$this->assertStringContainsString( 'class="undergrowth-hub"', $map );
		$this->assertStringContainsString( esc_url( get_edit_post_link( $hub, 'raw' ) ), $map );
		$this->assertStringContainsString( 'until you publish it', $map );

		wp_publish_post( $hub );

		$this->assertStringNotContainsString( 'until you publish it', $this->render_map() );
	}

	public function test_map_shows_no_hub_to_a_user_who_cannot_make_pages() {
		$this->dense_group();

		$user = wp_get_current_user();
		$user->add_cap( 'edit_pages', false );

		$this->assertStringNotContainsString( 'undergrowth-hub', $this->render_map() );
	}

	public function test_start_hub_handler_starts_a_draft_and_links_to_it() {
		$ids = $this->dense_group();
		$url = $this->post_action( 'start_hub', $ids[0] );
		$hub = undergrowth_group_hub( $ids );

		$this->assertGreaterThan( 0, $hub );
		$this->assertStringContainsString( 'tab=map', $url );
		$this->assertStringContainsString( 'undergrowth_notice=hub_started', $url );
		$this->assertStringContainsString( 'undergrowth_hub=' . $hub, $url );

		$_GET['undergrowth_notice'] = 'hub_started';
		$_GET['undergrowth_hub']    = (string) $hub;

		$html = $this->render_map();

		$this->assertStringContainsString( 'notice-success', $html );
		// Core escapes the ampersand either way, depending on the context.
		$this->assertMatchesRegularExpression( '#post\.php\?post=' . $hub . '&(?:amp|\#038);action=edit">Edit the draft</a></p>#', $html );
	}

	public function test_started_notice_links_to_no_page_that_is_no_hub() {
		$this->dense_group();
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$_GET['undergrowth_notice'] = 'hub_started';
		$_GET['undergrowth_hub']    = (string) $page;

		$this->assertStringNotContainsString( 'Edit the draft', $this->render_map() );
	}

	public function test_start_hub_handler_reports_a_group_with_no_proposal() {
		$ids = $this->group( array( 'Oca', 'Ulluco', 'Mashua', 'Yacon', 'Achira' ) );

		$this->assertStringContainsString( 'undergrowth_notice=hub_gone', $this->post_action( 'start_hub', $ids[0] ) );
	}

	public function test_veto_hub_handler_stores_the_veto() {
		$ids = $this->dense_group();

		$this->assertStringContainsString( 'undergrowth_notice=hub_vetoed', $this->post_action( 'veto_hub', $ids[0] ) );
		$this->assertTrue( undergrowth_hub_vetoed( $ids ) );
		$this->assertStringContainsString( 'undergrowth_notice=hub_gone', $this->post_action( 'veto_hub', $ids[0] ) );
	}

	public function test_handlers_refuse_a_wrong_nonce() {
		$ids = $this->dense_group();

		$this->expectException( WPDieException::class );

		$this->post_action( 'start_hub', $ids[0], 'wrong' );
	}

	public function test_handlers_refuse_a_user_who_cannot_make_pages() {
		$ids = $this->dense_group();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		try {
			$this->post_action( 'veto_hub', $ids[0] );
			$this->fail( 'The veto went through.' );
		} catch ( WPDieException $e ) {
			$this->assertFalse( undergrowth_hub_vetoed( $ids ) );
		}

		$this->expectException( WPDieException::class );

		$this->post_action( 'start_hub', $ids[0] );
	}
}
