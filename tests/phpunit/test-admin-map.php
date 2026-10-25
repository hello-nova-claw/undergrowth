<?php
/**
 * Tests for the Map tab on the link suggestions screen.
 *
 * @package Undergrowth
 */

/**
 * Map tab.
 */
class Test_Admin_Map extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	public function tear_down() {
		unset( $_GET['tab'], $_POST['format'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		parent::tear_down();
	}

	/**
	 * Renders the screen on a tab and returns its HTML.
	 *
	 * @param string $tab Tab to ask for.
	 * @return string
	 */
	private function render( string $tab = 'map' ): string {
		$_GET['tab'] = $tab;
		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();

		return (string) ob_get_clean();
	}

	/**
	 * Publishes a post, stores its vector and puts it in a cluster.
	 *
	 * @param string  $title  Post title.
	 * @param float[] $vector Vector to store.
	 * @param int     $leader Leader of the cluster, 0 for the post to lead, or -1 for no cluster.
	 * @return int Post ID.
	 */
	private function member( string $title, array $vector, int $leader = 0 ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		undergrowth_save_vector(
			$post_id,
			$vector,
			array(
				'provider'     => 'test-provider',
				'model'        => 'test-model',
				'language'     => 'en_US',
				'content_hash' => 'hash',
			)
		);
		if ( $leader >= 0 ) {
			update_post_meta( $post_id, 'undergrowth_cluster', 0 === $leader ? $post_id : $leader );
		}

		return $post_id;
	}

	/**
	 * Makes two clusters: a gap around Oca, and a dense one around Yacon.
	 *
	 * @return array<string, int> Post IDs keyed by title.
	 */
	private function plant_clusters(): array {
		$posts                 = array();
		$posts['Oca']          = $this->member( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$posts['Ulluco']       = $this->member( 'Ulluco', array( 0.0, 1.0, 0.0 ), $posts['Oca'] );
		$posts['Mashua']       = $this->member( 'Mashua', array( 0.0, 0.0, 1.0 ), $posts['Oca'] );
		$posts['Yacon']        = $this->member( 'Yacon', array( 0.0, 0.0, 1.0 ) );
		$posts['Yacon syrup']  = $this->member( 'Yacon syrup', array( 0.1, 0.0, 0.99 ), $posts['Yacon'] );
		$posts['Yacon leaves'] = $this->member( 'Yacon leaves', array( 0.0, 0.1, 0.99 ), $posts['Yacon'] );

		return $posts;
	}

	public function test_map_tab_is_missing_without_clusters() {
		$html = $this->render( '' );

		$this->assertStringNotContainsString( 'tab=map', $html );
	}

	public function test_asking_for_the_map_without_clusters_shows_the_suggestions() {
		$html = $this->render();

		$this->assertStringContainsString( 'aria-current="page">Suggestions<', $html );
		$this->assertStringNotContainsString( 'undergrowth-cluster', $html );
	}

	public function test_map_tab_is_offered_once_there_is_a_cluster() {
		$this->plant_clusters();

		$this->assertMatchesRegularExpression( '/<a href="[^"]*tab=map[^"]*" class="nav-tab">Map<\/a>/', $this->render( '' ) );
		$this->assertMatchesRegularExpression( '/<a href="[^"]*tab=map[^"]*" class="nav-tab nav-tab-active" aria-current="page">Map<\/a>/', $this->render() );
	}

	public function test_each_cluster_is_a_heading_over_its_posts_with_their_stages() {
		$posts = $this->plant_clusters();
		undergrowth_set_stage( $posts['Yacon syrup'], 'budding' );

		$html = $this->render();

		$this->assertSame( 2, substr_count( $html, '<ul class="undergrowth-cluster"' ) );
		$this->assertStringContainsString( '<h2 id="undergrowth-cluster-' . $posts['Yacon'] . '">Around “Yacon”</h2>', $html );
		$this->assertStringContainsString( 'aria-labelledby="undergrowth-cluster-' . $posts['Yacon'] . '"', $html );
		$this->assertMatchesRegularExpression( '/<li><a href="[^"]*post\\.php\\?post=' . $posts['Yacon syrup'] . '&[^"]*action=edit">Yacon syrup<\\/a> \\(Budding\\)<\\/li>/', $html );

		foreach ( $posts as $title => $post_id ) {
			$this->assertStringContainsString( '>' . $title . '</a> (', $html );
		}
	}

	public function test_map_calls_clusters_groups_on_screen() {
		$this->plant_clusters();

		$this->assertStringNotContainsStringIgnoringCase( 'cluster', wp_strip_all_tags( $this->render() ) );
	}

	public function test_posts_are_listed_nearest_the_centre_first() {
		$this->plant_clusters();
		$html = $this->render();

		// Yacon sits nearest the middle; its two neighbours are equally far.
		$this->assertLessThan( strpos( $html, '>Yacon syrup<' ), strpos( $html, '>Yacon<' ) );
		$this->assertLessThan( strpos( $html, '>Yacon leaves<' ), strpos( $html, '>Yacon syrup<' ) );
	}

	public function test_gap_cluster_is_marked_in_its_heading_and_in_words() {
		$posts = $this->plant_clusters();
		$html  = $this->render();

		$this->assertStringContainsString( '<h2 id="undergrowth-cluster-' . $posts['Oca'] . '">Around “Oca”, with a gap</h2>', $html );
		$this->assertSame( 1, substr_count( $html, 'class="undergrowth-gap"' ) );
		$this->assertMatchesRegularExpression( '/<p class="undergrowth-gap"><span class="dashicons dashicons-flag" aria-hidden="true"><\/span> [^<]+<\/p>/', $html );
	}

	public function test_gap_mark_names_no_post() {
		$this->plant_clusters();

		preg_match( '/<p class="undergrowth-gap">(.*?)<\/p>/', $this->render(), $gap );

		foreach ( array( 'Ulluco', 'Mashua', 'Yacon' ) as $title ) {
			$this->assertStringNotContainsString( $title, $gap[1] );
		}
	}

	public function test_composting_post_is_not_listed() {
		$posts = $this->plant_clusters();
		undergrowth_compost_post( $posts['Yacon leaves'] );

		$this->assertStringNotContainsString( 'Yacon leaves', $this->render() );
	}

	public function test_post_the_user_cannot_edit_is_listed_without_a_link() {
		$posts = $this->plant_clusters();
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) use ( $posts ) {
				return 'edit_post' === $cap && isset( $args[0] ) && $posts['Mashua'] === (int) $args[0] ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);

		$this->assertStringContainsString( '<li>Mashua (Seedling)</li>', $this->render() );
	}

	public function test_group_is_named_after_its_post_nearest_the_middle() {
		$posts = $this->plant_clusters();
		$this->member( 'Yacon pickles', array( 0.05, 0.05, 0.99 ), $posts['Yacon'] );

		$this->assertStringContainsString( '>Around “Yacon pickles”</h2>', $this->render() );
	}

	public function test_each_group_says_its_size() {
		$posts = $this->plant_clusters();
		$this->member( 'Yacon pickles', array( 0.05, 0.05, 0.99 ), $posts['Yacon'] );

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/<p class="undergrowth-group-about"><span>3 posts\.<\/span>/', $html );
		$this->assertMatchesRegularExpression( '/<p class="undergrowth-group-about"><span>4 posts\.<\/span>/', $html );
	}

	public function test_group_shows_its_stored_words_except_those_in_its_name() {
		$posts = $this->plant_clusters();
		update_option(
			'undergrowth_cluster_labels',
			array(
				'made'  => '2026-10-01 00:00:00',
				'terms' => array( $posts['Yacon'] => array( 'Yacon', 'sweet syrup', 'leaves', '<b>tubers</b>' ) ),
			),
			false
		);

		$html = $this->render();

		$this->assertStringContainsString( '<span>Words that set this group apart: <bdi>sweet syrup</bdi>, <bdi>leaves</bdi>, and <bdi>&lt;b&gt;tubers&lt;/b&gt;</bdi>.</span>', $html );
		$this->assertStringNotContainsString( '<bdi>Yacon</bdi>', $html );
	}

	public function test_group_without_stored_words_says_only_its_size() {
		$this->plant_clusters();

		$this->assertStringNotContainsString( 'Words that set', $this->render() );
	}

	public function test_summary_accounts_for_every_post() {
		$posts = $this->plant_clusters();
		self::factory()->post->create(
			array(
				'post_title'  => 'Bread',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->member( 'Resting', array( 1.0, 0.0, 0.0 ), $posts['Oca'] ), 'undergrowth_stage', 'composting' );

		$this->assertStringContainsString( '<p class="undergrowth-map-counts"><span>6 posts are in 2 groups.</span> <span>1 post is not in a group yet.</span> <span>1 post is composting.</span></p>', $this->render() );
	}

	public function test_summary_leaves_out_counts_of_none() {
		$this->plant_clusters();

		$this->assertStringContainsString( '<p class="undergrowth-map-counts"><span>6 posts are in 2 groups.</span></p>', $this->render() );
	}

	public function test_posts_not_in_a_group_are_listed_with_a_reason_and_composting_ones_are_not() {
		$posts   = $this->plant_clusters();
		$bread   = self::factory()->post->create(
			array(
				'post_title'  => 'Bread',
				'post_status' => 'publish',
			)
		);
		$cycle   = $this->member( 'Cycling', array( 0.0, 1.0, 0.0 ), -1 );
		$resting = $this->member( 'Resting', array( 0.0, 1.0, 0.0 ), -1 );
		update_post_meta( $resting, 'undergrowth_stage', 'composting' );
		update_option(
			'undergrowth_cluster_labels',
			array(
				'made'  => '2999-01-01 00:00:00',
				'terms' => array(),
			),
			false
		);

		$html = $this->render();

		$this->assertStringContainsString( '<h2 id="undergrowth-ungrouped">Not in a group yet</h2>', $html );
		$this->assertStringContainsString( '<ul class="undergrowth-ungrouped" aria-labelledby="undergrowth-ungrouped">', $html );
		$this->assertMatchesRegularExpression( '/<li><a href="[^"]*post=' . $bread . '&[^"]*">Bread<\/a> \(Not read yet\)<\/li>/', $html );
		$this->assertMatchesRegularExpression( '/<li><a href="[^"]*post=' . $cycle . '&[^"]*">Cycling<\/a> \(Not close enough to other posts yet\)<\/li>/', $html );
		$this->assertStringNotContainsString( 'Resting', $html );
		$this->assertStringNotContainsString( '<details', $html );
	}

	public function test_post_read_since_the_last_pass_says_so() {
		$this->plant_clusters();
		$cycle = $this->member( 'Cycling', array( 0.0, 1.0, 0.0 ), -1 );
		update_option(
			'undergrowth_cluster_labels',
			array(
				'made'  => '2000-01-01 00:00:00',
				'terms' => array(),
			),
			false
		);

		$this->assertMatchesRegularExpression( '/>Cycling<\/a> \(Read since the groups were last found\)<\/li>/', $this->render() );
	}

	public function test_long_list_of_posts_not_in_a_group_starts_collapsed() {
		$this->plant_clusters();
		self::factory()->post->create_many( 11, array( 'post_status' => 'publish' ) );

		$html = $this->render();

		$this->assertStringContainsString( '<details class="undergrowth-ungrouped-list"><summary>Show all 11 posts</summary><ul class="undergrowth-ungrouped" aria-labelledby="undergrowth-ungrouped">', $html );
		$this->assertSame( 11, preg_match_all( '/\(Not read yet\)<\/li>/', $html ) );
	}

	public function test_posts_not_in_a_group_are_read_in_one_query_however_many() {
		$this->plant_clusters();
		$few  = $this->count_render_queries( 2 );
		$many = $this->count_render_queries( 12 );

		$this->assertLessThan( 5, $many - $few );
	}

	/**
	 * Adds posts outside any group, empties the caches, and counts the
	 * queries the Map makes.
	 *
	 * @param int $extra Number of posts to add.
	 * @return int Number of queries.
	 */
	private function count_render_queries( int $extra ): int {
		global $wpdb;

		foreach ( self::factory()->post->create_many( $extra, array( 'post_status' => 'publish' ) ) as $post_id ) {
			clean_post_cache( $post_id );
		}

		wp_cache_flush();
		$before = $wpdb->num_queries;
		$this->render();
		$queries = $wpdb->num_queries - $before;

		foreach ( undergrowth_garden_posts() as $post_id => $read ) {
			if ( '' === $read ) {
				wp_delete_post( $post_id, true );
			}
		}

		return $queries;
	}

	public function test_no_section_when_every_post_is_in_a_group() {
		$this->plant_clusters();

		$this->assertStringNotContainsString( 'undergrowth-ungrouped', $this->render() );
	}

	public function test_map_ends_with_an_export_form_with_a_labelled_format() {
		$this->plant_clusters();

		$html = $this->render();
		$form = substr( $html, (int) strpos( $html, '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="undergrowth-map-export">' ) );

		$this->assertStringStartsWith( '<form', $form );
		$this->assertStringContainsString( '<input type="hidden" name="action" value="undergrowth_export_map"', $form );
		$this->assertStringContainsString( 'name="_wpnonce" value="' . wp_create_nonce( 'undergrowth_export_map' ) . '"', $form );
		$this->assertStringContainsString( '<label for="undergrowth-export-format">Export the Map as</label>', $form );
		$this->assertStringContainsString( '<select id="undergrowth-export-format" name="format">', $form );
		$this->assertStringContainsString( '<option value="csv">CSV, for a spreadsheet</option>', $form );
		$this->assertStringContainsString( '<option value="markdown">Markdown, for notes</option>', $form );
		$this->assertMatchesRegularExpression( '/<input type="submit"[^>]* value="Export"/', $form );
		// After every group and post, so the keyboard reaches the posts first.
		$this->assertGreaterThan( strrpos( $html, 'undergrowth-cluster' ), strpos( $html, 'undergrowth-map-export' ) );
	}

	public function test_export_lists_every_post_the_map_shows() {
		$posts = $this->plant_clusters();
		$bread = self::factory()->post->create(
			array(
				'post_title'  => 'Bread',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->member( 'Resting', array( 1.0, 0.0, 0.0 ), $posts['Oca'] ), 'undergrowth_stage', 'composting' );
		undergrowth_set_stage( $posts['Yacon syrup'], 'budding' );

		$rows = array_column( undergrowth_map_export_rows(), null, 'title' );

		$this->assertSame( array( 'Bread', 'Mashua', 'Oca', 'Ulluco', 'Yacon', 'Yacon leaves', 'Yacon syrup' ), $this->sorted( array_keys( $rows ) ) );
		$this->assertSame(
			array(
				'group'     => 'Yacon',
				'words'     => '',
				'title'     => 'Yacon syrup',
				'permalink' => get_permalink( $posts['Yacon syrup'] ),
				'stage'     => 'Budding',
				'gap'       => false,
			),
			$rows['Yacon syrup']
		);
		$this->assertSame( 'Oca', $rows['Mashua']['group'] );
		$this->assertTrue( $rows['Mashua']['gap'] );
		$this->assertSame(
			array(
				'group'     => 'Not in a group yet',
				'words'     => '',
				'title'     => 'Bread',
				'permalink' => get_permalink( $bread ),
				'stage'     => 'Seedling',
				'gap'       => false,
			),
			$rows['Bread']
		);
		// The posts not in a group come last, as on the Map.
		$this->assertSame( 'Bread', array_key_last( $rows ) );
	}

	/**
	 * Sorts strings.
	 *
	 * @param string[] $strings Strings.
	 * @return string[] The strings, sorted.
	 */
	private function sorted( array $strings ): array {
		sort( $strings );

		return $strings;
	}

	public function test_export_reads_posts_not_in_a_group_in_a_fixed_number_of_queries() {
		global $wpdb;

		$this->plant_clusters();
		$counts = array();

		foreach ( array( 2, 12 ) as $extra ) {
			foreach ( self::factory()->post->create_many( $extra, array( 'post_status' => 'publish' ) ) as $post_id ) {
				clean_post_cache( $post_id );
			}

			wp_cache_flush();
			$before = $wpdb->num_queries;
			undergrowth_map_export_rows();
			$counts[ $extra ] = $wpdb->num_queries - $before;
		}

		// Twelve more posts, but no query for each one's title or stage.
		$this->assertLessThan( 5, $counts[12] - $counts[2] );
	}

	public function test_export_keeps_titles_as_the_map_shows_them() {
		$posts = $this->plant_clusters();
		wp_update_post(
			array(
				'ID'         => $posts['Yacon'],
				'post_title' => "<em>Yacon</em> &amp; the frost's\nedge",
			)
		);

		$rows = array_column( undergrowth_map_export_rows(), null, 'permalink' );
		$row  = $rows[ get_permalink( $posts['Yacon'] ) ];

		// Markup reads as written, as on the escaped screen; entities become characters.
		$this->assertSame( '<em>Yacon</em> & the frost’s edge', $row['title'] );
		$this->assertSame( $row['title'], $row['group'] );
	}

	public function test_export_carries_the_words_the_map_shows() {
		$posts = $this->plant_clusters();
		update_option(
			'undergrowth_cluster_labels',
			array(
				'made'  => current_time( 'mysql', true ),
				'terms' => array( $posts['Yacon'] => array( 'yacon', 'syrup', 'frost' ) ),
			),
			false
		);

		$rows = undergrowth_map_export_rows();

		// As on screen, a word the group's name already holds is left out.
		$rows = array_column( $rows, 'words', 'title' );

		$this->assertSame( 'syrup, frost', $rows['Yacon leaves'] );
		$this->assertSame( '', $rows['Oca'] );
	}

	public function test_csv_has_a_header_and_one_row_per_post_and_disarms_formulas() {
		$rows = array(
			array(
				'group'     => 'Oca',
				'words'     => 'tubers, frost',
				'title'     => '=HYPERLINK("http://example.org")',
				'permalink' => 'http://example.org/?p=1',
				'stage'     => 'Seedling',
				'gap'       => true,
			),
		);

		$lines = array_map( 'str_getcsv', explode( "\n", trim( undergrowth_map_export_csv( $rows ) ) ) );

		$this->assertSame( array( 'Group', 'Words', 'Post', 'Link', 'Stage', 'Gap' ), $lines[0] );
		$this->assertSame( array( 'Oca', 'tubers, frost', "'=HYPERLINK(\"http://example.org\")", 'http://example.org/?p=1', 'Seedling', 'Yes' ), $lines[1] );
		$this->assertCount( 2, $lines );
	}

	public function test_markdown_is_a_table_with_one_row_per_post() {
		$rows = array(
			array(
				'group'     => 'Oca | ulluco',
				'words'     => '',
				'title'     => "Two\nlines, a \\| b, <em>c</em> *d* [e](f) `g`_h_~i~",
				'permalink' => 'http://example.org/?p=1',
				'stage'     => 'Sprout',
				'gap'       => false,
			),
		);

		$lines = explode( "\n", trim( undergrowth_map_export_markdown( $rows ) ) );

		$this->assertSame( '| Group | Words | Post | Link | Stage | Gap |', $lines[0] );
		$this->assertSame( '| --- | --- | --- | --- | --- | --- |', $lines[1] );
		// A backslash is escaped too, so one before a pipe cannot unescape it,
		// and markup in a title reads as written.
		$this->assertSame( '| Oca \\| ulluco |  | Two lines, a \\\\\\| b, \\<em\\>c\\</em\\> \\*d\\* \\[e\\](f) \\`g\\`\\_h\\_\\~i\\~ | <http://example.org/?p=1> | Sprout | No |', $lines[2] );
		$this->assertCount( 3, $lines );
	}

	/**
	 * Posts the export form with a valid nonce and returns what it sends.
	 *
	 * @param string $format Format posted.
	 * @return string The file.
	 * @throws WPDieException When the handler refuses the request.
	 */
	private function export( string $format ): string {
		$nonce                = wp_create_nonce( 'undergrowth_export_map' );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
		$_POST['format']      = $format;

		ob_start();

		try {
			undergrowth_handle_export_map();
		} finally {
			$file = (string) ob_get_clean();
		}

		return $file;
	}

	public function test_export_sends_the_format_asked_for_and_csv_otherwise() {
		$this->plant_clusters();
		$rows = undergrowth_map_export_rows();

		$this->assertSame( undergrowth_map_export_markdown( $rows ), $this->export( 'markdown' ) );
		$this->assertSame( undergrowth_map_export_csv( $rows ), $this->export( 'csv' ) );
		$this->assertSame( undergrowth_map_export_csv( $rows ), $this->export( 'pdf' ) );
	}

	public function test_export_is_a_dated_download_that_is_never_cached() {
		$markdown = undergrowth_map_export_headers( true );
		$csv      = undergrowth_map_export_headers( false );

		$this->assertSame( 'text/markdown; charset=utf-8', $markdown['Content-Type'] );
		$this->assertSame( 'attachment; filename="undergrowth-map-' . wp_date( 'Y-m-d' ) . '.md"', $markdown['Content-Disposition'] );
		$this->assertSame( 'text/csv; charset=utf-8', $csv['Content-Type'] );
		$this->assertStringEndsWith( '.csv"', $csv['Content-Disposition'] );
		$this->assertStringContainsString( 'no-cache', $csv['Cache-Control'] );
		$this->assertContainsOnly( 'string', $csv );
	}

	public function test_export_refuses_a_wrong_nonce() {
		$this->plant_clusters();
		$_POST['_wpnonce']    = 'wrong';
		$_REQUEST['_wpnonce'] = 'wrong';

		$this->expectException( WPDieException::class );

		undergrowth_handle_export_map();
	}

	public function test_export_refuses_a_user_who_cannot_see_the_map() {
		$this->plant_clusters();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->expectException( WPDieException::class );

		$this->export( 'csv' );
	}
}
