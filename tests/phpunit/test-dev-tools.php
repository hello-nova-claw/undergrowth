<?php
/**
 * Tests for the dev tools on Posts › Undergrowth › Dev tools.
 *
 * @package Undergrowth
 */

/**
 * The dev tools: the environment gate, Accept all, the cycle and the undo.
 */
class Test_Dev_Tools extends WP_UnitTestCase {

	use Plants_Posts;
	use Stops_Redirects;

	public function set_up() {
		parent::set_up();

		// A local test site, such as wp-env's, registers the tools on init.
		// Each test starts without them; the base class puts them back after.
		$this->unregister_dev_tools();
		$this->start_planting();
		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
	}

	public function tear_down() {
		unset( $_POST['per_post'], $_POST['forget_vectors'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_GET['tab'], $_GET['confirm'], $_GET['undergrowth_notice'] );
		delete_option( 'undergrowth_dev_job' );
		wp_unschedule_hook( 'undergrowth_dev_job' );
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Removes every hook undergrowth_register_dev_tools() adds.
	 */
	private function unregister_dev_tools(): void {
		remove_filter( 'undergrowth_screen_tabs', 'undergrowth_add_dev_tab' );
		remove_action( 'undergrowth_render_dev_tab', 'undergrowth_render_dev_tab' );
		remove_action( 'admin_post_undergrowth_dev_accept', 'undergrowth_handle_dev_accept' );
		remove_action( 'admin_post_undergrowth_dev_cycle', 'undergrowth_handle_dev_cycle' );
		remove_action( 'admin_post_undergrowth_dev_reset', 'undergrowth_handle_dev_reset' );
		remove_action( 'undergrowth_dev_job', 'undergrowth_continue_dev_job' );
	}

	/**
	 * Environment types WordPress knows, and whether dev tools belong there.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function environments(): array {
		return array(
			'local'       => array( 'local', true ),
			'development' => array( 'development', true ),
			'staging'     => array( 'staging', false ),
			'production'  => array( 'production', false ),
		);
	}

	/**
	 * Environment types where dev tools must never appear.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function real_sites(): array {
		return array(
			'staging'    => array( 'staging' ),
			'production' => array( 'production' ),
		);
	}

	/**
	 * Lists every hook name a dev tool could be reached through.
	 *
	 * @return string[]
	 */
	private function dev_hooks(): array {
		global $wp_filter;

		return array_values(
			array_filter(
				array_keys( $wp_filter ),
				static function ( $hook ) {
					return 0 === strpos( $hook, 'admin_post_undergrowth_dev' )
						|| 0 === strpos( $hook, 'wp_ajax_undergrowth' )
						|| 0 === strpos( $hook, 'wp_ajax_nopriv_undergrowth' )
						|| in_array( $hook, array( 'undergrowth_dev_job', 'undergrowth_render_dev_tab' ), true );
				}
			)
		);
	}

	/**
	 * Renders the screen on a tab and returns its HTML.
	 *
	 * @param string $tab Tab to open.
	 * @return string
	 */
	private function render( string $tab = 'dev' ): string {
		$_GET['tab'] = $tab;
		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();

		return (string) ob_get_clean();
	}

	/**
	 * Fills a dev tools form and calls its handler.
	 *
	 * @param string               $action 'accept', 'cycle' or 'reset'.
	 * @param array<string, mixed> $fields Other form fields.
	 * @return string Redirect location.
	 * @throws WPDieException When the handler refuses the request.
	 */
	private function submit( string $action, array $fields = array() ): string {
		foreach ( $fields as $name => $value ) {
			$_POST[ $name ] = $value;
		}

		$nonce                = wp_create_nonce( 'undergrowth_dev_' . $action );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		try {
			call_user_func( 'undergrowth_handle_dev_' . $action );
		} catch ( WPDieException $e ) {
			throw $e;
		} catch ( Exception $e ) {
			return $e->getMessage();
		}

		$this->fail( 'The handler did not redirect.' );
	}

	/**
	 * Publishes Oca with suggestions to Yacon and Mashua, Yacon nearer.
	 *
	 * @param string $oca_content Content of Oca.
	 * @return int[] Oca, Yacon and Mashua.
	 */
	private function garden( string $oca_content = '' ): array {
		$oca_content = '' === $oca_content
			? '<!-- wp:paragraph --><p>Oca grows in the Andes. I keep a few yacon plants nearby for their syrup. Mashua flowers climb the fence beside them.</p><!-- /wp:paragraph -->'
			: $oca_content;

		$yacon  = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon syrup is a gentle sweetener. The yacon root stores well.</p>' ) );
		$mashua = $this->plant( 'Mashua', array( 0.8, 0.0, 0.3 ), array( 'post_content' => '<p>Mashua is a climbing tuber. Mashua flowers are orange.</p>' ) );
		$oca    = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => $oca_content ) );

		return array( $oca, $yacon, $mashua );
	}

	/**
	 * Counts the links table's rows in a state.
	 *
	 * @param string $state 'proposed', 'accepted' or 'vetoed'.
	 * @return int
	 */
	private function rows( string $state ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE state = %s', undergrowth_links_table(), $state ) );
	}

	/**
	 * Counts the stored vectors.
	 *
	 * @return int
	 */
	private function vectors(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', undergrowth_vectors_table() ) );
	}

	/**
	 * Builds the link Accept adds to a post.
	 *
	 * @param int    $target Post linked to.
	 * @param string $words  Linked words.
	 * @return string
	 */
	private function link( int $target, string $words ): string {
		return '<a href="' . esc_url( get_permalink( $target ) ) . '">' . $words . '</a>';
	}

	/**
	 * Lets the current user save unfiltered HTML, or not, whatever the
	 * site, and stops kses filtering saves, so content with an embed
	 * survives until Accept's own check is what decides.
	 *
	 * @param bool $allowed Whether the user may save unfiltered HTML.
	 */
	private function unfiltered_html( bool $allowed ): void {
		kses_remove_filters();
		remove_all_filters( 'map_meta_cap' );
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap ) use ( $allowed ) {
				return 'unfiltered_html' === $cap ? array( $allowed ? 'exist' : 'do_not_allow' ) : $caps;
			},
			10,
			2
		);
	}

	/**
	 * Runs a dev job to its end, as cron would.
	 *
	 * @return array<string, mixed> The finished job.
	 */
	private function finish_job(): array {
		// Cron takes an event off the queue before running it.
		$runs = 0;

		while ( ! undergrowth_dev_job()['done'] && ++$runs <= 50 ) {
			wp_unschedule_hook( 'undergrowth_dev_job' );
			undergrowth_continue_dev_job();
		}

		return undergrowth_dev_job();
	}

	/**
	 * Only a local or development site has the dev tools.
	 *
	 * @dataProvider environments
	 *
	 * @param string $environment Environment type.
	 * @param bool   $expected    Whether dev tools belong there.
	 */
	public function test_dev_tools_are_enabled_only_on_local_and_development( string $environment, bool $expected ) {
		$this->assertSame( $expected, undergrowth_dev_tools_enabled( $environment ) );
	}

	public function test_an_unknown_environment_has_no_dev_tools() {
		$this->assertFalse( undergrowth_dev_tools_enabled( 'locale' ) );
		$this->assertFalse( undergrowth_dev_tools_enabled( '' ) );
	}

	public function test_the_site_environment_is_used_by_default() {
		$this->assertSame( undergrowth_dev_tools_enabled( wp_get_environment_type() ), undergrowth_dev_tools_enabled() );
	}

	public function test_the_filter_can_turn_dev_tools_off() {
		add_filter( 'undergrowth_dev_tools_enabled', '__return_false' );

		$this->assertFalse( undergrowth_dev_tools_enabled( 'local' ) );
		$this->assertFalse( undergrowth_register_dev_tools( 'local' ) );
		$this->assertSame( array(), $this->dev_hooks() );
	}

	/**
	 * A filter returning true never turns the tools on on a real site.
	 *
	 * @dataProvider real_sites
	 *
	 * @param string $environment Environment type.
	 */
	public function test_the_filter_cannot_turn_dev_tools_on( string $environment ) {
		add_filter( 'undergrowth_dev_tools_enabled', '__return_true' );

		$this->assertFalse( undergrowth_dev_tools_enabled( $environment ) );
		$this->assertFalse( undergrowth_register_dev_tools( $environment ) );
		$this->assertSame( array(), $this->dev_hooks() );
	}

	/**
	 * No tab, handler, AJAX action, REST route or cron handler exists on a real site.
	 *
	 * @dataProvider real_sites
	 *
	 * @param string $environment Environment type.
	 */
	public function test_nothing_is_registered_on_a_real_site( string $environment ) {
		$this->assertFalse( undergrowth_register_dev_tools( $environment ) );

		$this->assertSame( array(), $this->dev_hooks() );
		$this->assertFalse( has_filter( 'undergrowth_screen_tabs', 'undergrowth_add_dev_tab' ) );

		foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
			$this->assertStringNotContainsString( 'dev', $route );
		}

		$this->assertStringNotContainsString( 'tab=dev', $this->render( '' ) );
		$this->assertStringNotContainsString( 'undergrowth_dev_', $this->render( 'dev' ) );
	}

	public function test_this_test_site_registers_the_tools_only_if_it_is_local() {
		undergrowth_load_dev_tools();

		$this->assertSame( undergrowth_dev_tools_enabled(), array() !== $this->dev_hooks() );
	}

	/**
	 * Each tool is registered on a local or development site and nowhere else.
	 *
	 * @dataProvider environments
	 *
	 * @param string $environment Environment type.
	 * @param bool   $expected    Whether dev tools belong there.
	 */
	public function test_every_tool_is_registered_only_where_it_belongs( string $environment, bool $expected ) {
		$this->assertSame( $expected, undergrowth_register_dev_tools( $environment ) );

		foreach ( array( 'admin_post_undergrowth_dev_accept', 'admin_post_undergrowth_dev_cycle', 'admin_post_undergrowth_dev_reset', 'undergrowth_dev_job', 'undergrowth_render_dev_tab' ) as $hook ) {
			$this->assertSame( $expected, has_action( $hook ), $hook );
		}

		$this->assertSame( $expected, (bool) has_filter( 'undergrowth_screen_tabs', 'undergrowth_add_dev_tab' ) );
	}

	public function test_the_tab_is_offered_to_administrators_with_a_local_only_notice() {
		undergrowth_register_dev_tools( 'local' );

		$this->assertStringContainsString( 'tab=dev', $this->render( '' ) );

		$html = $this->render( 'dev' );

		$this->assertStringContainsString( 'nav-tab-active" aria-current="page">Dev tools<', $html );
		$this->assertStringContainsString( 'Local development only.', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'value="undergrowth_dev_accept"', $html );
		$this->assertStringContainsString( 'value="undergrowth_dev_cycle"', $html );
		$this->assertSame( 2, substr_count( $html, 'name="_wpnonce"' ) );
	}

	public function test_editors_do_not_see_the_tab() {
		undergrowth_register_dev_tools( 'local' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertStringNotContainsString( 'tab=dev', $this->render( '' ) );
		$this->assertStringNotContainsString( 'undergrowth_dev_', $this->render( 'dev' ) );
	}

	/**
	 * Dev tools forms.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function actions(): array {
		return array(
			'accept' => array( 'accept' ),
			'cycle'  => array( 'cycle' ),
			'reset'  => array( 'reset' ),
		);
	}

	/**
	 * Only administrators may use a tool.
	 *
	 * @dataProvider actions
	 *
	 * @param string $action Form to send.
	 */
	public function test_every_handler_refuses_editors( string $action ) {
		undergrowth_register_dev_tools( 'local' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->expectException( WPDieException::class );
		$this->submit( $action );
	}

	/**
	 * A form without its own nonce is refused.
	 *
	 * @dataProvider actions
	 *
	 * @param string $action Form to send.
	 */
	public function test_every_handler_checks_its_nonce( string $action ) {
		undergrowth_register_dev_tools( 'local' );
		$nonce                = wp_create_nonce( 'something_else' );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$this->expectException( WPDieException::class );
		call_user_func( 'undergrowth_handle_dev_' . $action );
	}

	public function test_accept_all_accepts_every_suggestion_through_accept() {
		undergrowth_register_dev_tools( 'local' );
		list( $oca, $yacon, $mashua ) = $this->garden();
		$proposed                     = $this->rows( 'proposed' );

		$this->assertSame( array( $yacon, $mashua ), $this->proposed( $oca ) );

		$location = $this->submit( 'accept', array( 'per_post' => '' ) );
		$job      = $this->finish_job();

		$this->assertStringContainsString( 'tab=dev', $location );
		$this->assertStringContainsString( 'undergrowth_notice=dev_accept_started', $location );
		$this->assertSame( 0, $this->rows( 'proposed' ) );
		$this->assertSame( $proposed, $this->rows( 'accepted' ) );
		$this->assertSame( $proposed, $job['counts']['accepted'] );

		$content = get_post( $oca )->post_content;

		$this->assertStringContainsString( '<a href="' . esc_url( get_permalink( $yacon ) ) . '">', $content );
		$this->assertStringContainsString( '<a href="' . esc_url( get_permalink( $mashua ) ) . '">', $content );
	}

	public function test_accept_all_can_stop_at_the_best_suggestions_in_each_post() {
		undergrowth_register_dev_tools( 'local' );
		list( $oca, $yacon, $mashua ) = $this->garden();

		$this->submit( 'accept', array( 'per_post' => '1' ) );
		$this->finish_job();

		$this->assertSame( 'accepted', undergrowth_link_state( $oca, $yacon ) );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $mashua ) );
	}

	public function test_accept_all_keeps_accepts_refusals_and_counts_them() {
		global $wpdb;

		undergrowth_register_dev_tools( 'local' );
		list( $oca, $yacon, $mashua ) = $this->garden();

		// The sentence the Mashua suggestion sits in is no longer in the post.
		$wpdb->update(
			undergrowth_links_table(),
			array( 'sentence' => 'A sentence the author has since rewritten.' ),
			array(
				'source_id' => $oca,
				'target_id' => $mashua,
			)
		);

		$this->submit( 'accept' );
		$job = $this->finish_job();

		$this->assertSame( 'accepted', undergrowth_link_state( $oca, $yacon ) );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $mashua ) );
		$this->assertSame( 1, $job['counts']['edited'] );
		$this->assertStringNotContainsString( esc_url( get_permalink( $mashua ) ), get_post( $oca )->post_content );
	}

	public function test_accept_all_refuses_what_kses_would_change_for_a_user_without_unfiltered_html() {
		undergrowth_register_dev_tools( 'local' );
		$this->unfiltered_html( true );
		list( $oca, $yacon ) = $this->garden( '<!-- wp:paragraph --><p>Oca grows in the Andes. I keep a few yacon plants nearby for their syrup.</p><!-- /wp:paragraph --><iframe src="https://example.com/map"></iframe>' );
		$before              = get_post( $oca )->post_content;

		$this->unfiltered_html( false );

		$this->submit( 'accept' );
		$job = $this->finish_job();

		$this->assertSame( $before, get_post( $oca )->post_content );
		$this->assertGreaterThan( 0, $job['counts']['needs_admin'] );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_a_large_site_is_accepted_in_chunks_through_cron() {
		undergrowth_register_dev_tools( 'local' );
		$this->garden();
		add_filter( 'undergrowth_dev_job_budget', '__return_zero' );

		$this->submit( 'accept' );

		$job = undergrowth_dev_job();

		$this->assertFalse( $job['done'] );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_dev_job' ) );

		wp_set_current_user( 0 );
		$job = $this->finish_job();

		$this->assertTrue( $job['done'] );
		$this->assertSame( 0, $this->rows( 'proposed' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_dev_job' ) );
		// Cron acts as the user who clicked, so Accept's rules are theirs.
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_a_second_job_is_refused_while_one_is_running() {
		undergrowth_register_dev_tools( 'local' );
		$this->garden();
		add_filter( 'undergrowth_dev_job_budget', '__return_zero' );

		$this->submit( 'accept' );

		$this->assertStringContainsString( 'undergrowth_notice=dev_busy', $this->submit( 'reset' ) );
		$this->assertSame( 'accept', undergrowth_dev_job()['type'] );
	}

	public function test_the_tab_shows_the_counts_of_the_last_job() {
		undergrowth_register_dev_tools( 'local' );
		$this->garden();

		$this->submit( 'accept' );
		$this->finish_job();

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/Accepted: [1-9]/', $html );
	}

	public function test_run_cycle_queues_the_monthly_cycle() {
		undergrowth_register_dev_tools( 'local' );
		wp_unschedule_hook( 'undergrowth_cluster_cycle' );

		$location = $this->submit( 'cycle' );

		$this->assertStringContainsString( 'undergrowth_notice=dev_cycle_queued', $location );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_cluster_cycle', array( 0 ) ) );
	}

	public function test_the_undo_asks_for_confirmation_on_the_page_first() {
		undergrowth_register_dev_tools( 'local' );

		$first = $this->render();

		$this->assertStringContainsString( 'confirm=reset', $first );
		$this->assertStringNotContainsString( 'value="undergrowth_dev_reset"', $first );

		$_GET['confirm'] = 'reset';
		$html            = $this->render();

		$this->assertStringContainsString( 'value="undergrowth_dev_reset"', $html );
		$this->assertStringContainsString( 'name="forget_vectors"', $html );
		$this->assertStringNotContainsString( 'confirm(', $html );
	}

	public function test_the_undo_gives_back_each_post_as_it_was_before_accept() {
		undergrowth_register_dev_tools( 'local' );
		list( $oca, $yacon, $mashua ) = $this->garden();
		$before                       = get_post( $oca )->post_content;
		$untouched                    = get_post( self::factory()->post->create( array( 'post_status' => 'publish' ) ) );

		$this->submit( 'accept' );
		$accepted = $this->finish_job()['counts']['accepted'];
		$this->assertNotSame( $before, get_post( $oca )->post_content );

		$location = $this->submit( 'reset' );
		$job      = $this->finish_job();

		$this->assertStringContainsString( 'undergrowth_notice=dev_reset_started', $location );
		$this->assertSame( $before, get_post( $oca )->post_content );
		$this->assertSame( $accepted, $job['counts']['links_removed'] );
		$this->assertSame( 0, $job['counts']['links_left'] );
		$this->assertSame( $accepted, $job['counts']['accepted_rows'] );
		// A post Undergrowth never changed is not saved again.
		$this->assertSame( $untouched->post_modified_gmt, get_post( $untouched->ID )->post_modified_gmt );
		$this->assertSame( array(), wp_get_post_revisions( $untouched->ID ) );
		$this->assertSame( 0, $this->rows( 'accepted' ) );
		unset( $yacon, $mashua );
	}

	public function test_the_undo_keeps_edits_made_after_accept_and_the_authors_own_links() {
		undergrowth_register_dev_tools( 'local' );
		list( $oca, $yacon, $mashua ) = $this->garden();

		$this->submit( 'accept', array( 'per_post' => '1' ) );
		$count = $this->finish_job()['counts']['accepted'];

		$accepted = get_post( $oca )->post_content;
		$this->assertStringContainsString( esc_url( get_permalink( $yacon ) ), $accepted );

		// The author adds a paragraph, with links of their own in the same
		// form, one of them to the same post.
		$own = '<!-- wp:paragraph --><p>See ' . $this->link( $yacon, 'my yacon notes' ) . ' and ' . $this->link( $mashua, 'mashua' ) . '.</p><!-- /wp:paragraph -->';
		wp_update_post(
			array(
				'ID'           => $oca,
				'post_content' => $accepted . $own,
			)
		);

		$this->submit( 'reset' );
		$job = $this->finish_job();

		$content = get_post( $oca )->post_content;

		$this->assertStringEndsWith( $own, $content );
		$this->assertSame( 1, substr_count( $content, esc_url( get_permalink( $yacon ) ) ) );
		$this->assertStringContainsString( 'I keep a few yacon plants nearby for their syrup.', $content );
		$this->assertSame( $count, $job['counts']['links_removed'] );
		$this->assertArrayNotHasKey( 'links_left', array_filter( $job['counts'] ) );
	}

	public function test_the_undo_leaves_a_link_the_author_changed_and_counts_it() {
		undergrowth_register_dev_tools( 'local' );
		list( $oca, $yacon ) = $this->garden();

		$this->submit( 'accept', array( 'per_post' => '1' ) );
		$count = $this->finish_job()['counts']['accepted'];

		$changed = str_replace( '<a href="' . esc_url( get_permalink( $yacon ) ) . '">', '<a class="mine" href="' . esc_url( get_permalink( $yacon ) ) . '">', get_post( $oca )->post_content );
		wp_update_post(
			array(
				'ID'           => $oca,
				'post_content' => $changed,
			)
		);

		$this->submit( 'reset' );
		$job = $this->finish_job();

		$this->assertSame( $changed, get_post( $oca )->post_content );
		$this->assertSame( $count - 1, $job['counts']['links_removed'] ?? 0 );
		$this->assertSame( 1, $job['counts']['links_left'] );
	}

	public function test_the_undo_refuses_what_kses_would_change_for_a_user_without_unfiltered_html() {
		undergrowth_register_dev_tools( 'local' );
		$this->unfiltered_html( true );
		list( $oca, $yacon ) = $this->garden( '<!-- wp:paragraph --><p>Oca grows in the Andes. I keep a few yacon plants nearby for their syrup.</p><!-- /wp:paragraph --><iframe src="https://example.com/map"></iframe>' );

		$this->submit( 'accept' );
		$this->finish_job();
		$accepted = get_post( $oca )->post_content;
		$this->assertStringContainsString( esc_url( get_permalink( $yacon ) ), $accepted );

		$this->unfiltered_html( false );

		$this->submit( 'reset' );
		$job = $this->finish_job();

		$this->assertSame( $accepted, get_post( $oca )->post_content );
		$this->assertGreaterThan( 0, $job['counts']['links_needs_admin'] );
	}

	public function test_a_link_removed_only_from_inside_its_sentence() {
		$url      = 'https://example.com/yacon/';
		$link     = '<a href="' . $url . '">yacon</a>';
		$sentence = 'I keep yacon plants.';
		$content  = '<p>Notes on ' . $link . '. I keep ' . $link . ' plants.</p>';

		$this->assertSame( '<p>Notes on ' . $link . '. I keep yacon plants.</p>', undergrowth_dev_remove_link( $content, $url, $sentence ) );
		$this->assertNull( undergrowth_dev_remove_link( '<p>Notes on ' . $link . '.</p>', $url, $sentence ) );
		$this->assertNull( undergrowth_dev_remove_link( '<p>I keep <a href="' . $url . '"><em>yacon</em></a> plants.</p>', $url, $sentence ) );
	}

	public function test_the_undo_clears_everything_undergrowth_stored_and_keeps_vectors() {
		undergrowth_register_dev_tools( 'local' );
		list( $oca, $yacon, $mashua ) = $this->garden();
		$vectors                      = $this->vectors();

		undergrowth_veto_link( $oca, $mashua );
		undergrowth_set_stage( $oca, 'budding' );
		undergrowth_pin_stage( $yacon, 'sprout' );
		undergrowth_compost_post( $mashua );
		update_post_meta( $oca, 'undergrowth_cluster', $oca );
		update_post_meta( $mashua, 'undergrowth_resurfacing', $oca );
		add_post_meta( $mashua, 'undergrowth_resurfacing_log', array( 'time' => time() ) );
		update_option( 'undergrowth_previous_clusters', array( $oca ), false );
		update_option( 'undergrowth_new_clusters', array( $oca ), false );
		update_option( 'undergrowth_cluster_labels', array( 'terms' => array() ), false );
		undergrowth_write_memory( $oca );
		$file = undergrowth_memory_path( $oca );
		$this->assertFileExists( $file );

		$counts = undergrowth_dev_reset_clear( false );

		$this->assertSame( 0, $this->rows( 'vetoed' ) + $this->rows( 'accepted' ) + $this->rows( 'proposed' ) );
		$this->assertSame( 1, $counts['vetoed'] );
		$this->assertGreaterThan( 0, $counts['proposed'] );

		foreach ( array( $oca, $yacon, $mashua ) as $post_id ) {
			foreach ( array( 'undergrowth_stage', 'undergrowth_stage_log', 'undergrowth_stage_pinned', 'undergrowth_cluster', 'undergrowth_resurfacing', 'undergrowth_resurfacing_log' ) as $key ) {
				$this->assertFalse( metadata_exists( 'post', $post_id, $key ), $key );
			}
		}

		foreach ( array( 'undergrowth_previous_clusters', 'undergrowth_new_clusters', 'undergrowth_cluster_labels', 'undergrowth_cluster_leaders' ) as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}

		$this->assertFileDoesNotExist( $file );
		$this->assertSame( $vectors, $this->vectors() );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_cluster_cycle' ) );
	}

	public function test_the_undo_suggests_links_again_from_the_vectors_it_kept() {
		undergrowth_register_dev_tools( 'local' );
		list( $oca, $yacon, $mashua ) = $this->garden();
		undergrowth_veto_link( $oca, $mashua );

		$this->submit( 'reset' );
		$job = $this->finish_job();

		$this->assertTrue( $job['done'] );
		$this->assertSame( array( $yacon, $mashua ), $this->proposed( $oca ) );
	}

	public function test_the_undo_can_forget_vectors_too() {
		undergrowth_register_dev_tools( 'local' );
		$this->garden();

		$this->submit( 'reset', array( 'forget_vectors' => '1' ) );
		$job = $this->finish_job();

		$this->assertSame( 0, $this->vectors() );
		$this->assertSame( 3, $job['counts']['vectors'] );
		$this->assertSame( 0, $this->rows( 'proposed' ) );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_backfill' ) );
	}

	public function test_the_tab_reports_the_counts_of_the_undo() {
		undergrowth_register_dev_tools( 'local' );
		$this->garden();

		$this->submit( 'accept' );
		$accepted = $this->finish_job()['counts']['accepted'];
		$this->submit( 'reset' );
		$this->finish_job();

		$_GET['undergrowth_notice'] = 'dev_reset_started';
		$html                       = $this->render();

		$this->assertStringContainsString( 'Links removed: ' . $accepted . '<', $html );
		$this->assertStringContainsString( 'Accepted links forgotten: ' . $accepted . '<', $html );
		$this->assertStringContainsString( 'id="undergrowth-notice"', $html );
	}

	public function test_with_no_job_nothing_runs() {
		$this->assertNull( undergrowth_dev_job() );
		$this->assertNull( undergrowth_run_dev_job( null ) );

		undergrowth_continue_dev_job();

		$this->assertNull( undergrowth_dev_job() );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_dev_job' ) );
	}

	public function test_the_tab_says_a_job_is_still_running_and_links_to_its_progress() {
		undergrowth_register_dev_tools( 'local' );
		$this->garden();
		add_filter( 'undergrowth_dev_job_budget', '__return_zero' );
		undergrowth_start_dev_job( 'reset' );

		$this->assertStringContainsString( 'Nothing to count yet.', $this->render() );

		undergrowth_run_dev_job( 0.0 );
		$html = $this->render();

		$this->assertStringContainsString( 'Still running in WP-Cron.', $html );
		$this->assertStringContainsString( 'Last undo', $html );
	}

	public function test_the_tab_prints_nothing_for_an_editor_who_reaches_it() {
		undergrowth_register_dev_tools( 'local' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		ob_start();
		do_action( 'undergrowth_render_dev_tab' );

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_a_job_that_stopped_moving_can_be_replaced() {
		$this->assertTrue( undergrowth_start_dev_job( 'accept' ) );
		$this->assertFalse( undergrowth_start_dev_job( 'reset' ) );

		$job            = undergrowth_dev_job();
		$job['updated'] = time() - 11 * MINUTE_IN_SECONDS;
		update_option( 'undergrowth_dev_job', $job, false );

		$this->assertTrue( undergrowth_start_dev_job( 'reset', array( 'forget_vectors' => true ) ) );
		$this->assertSame( 'reset', undergrowth_dev_job()['type'] );
		$this->assertTrue( undergrowth_dev_job()['forget_vectors'] );
	}

	public function test_the_undo_leaves_links_in_a_post_the_user_cannot_edit() {
		list( $oca, $yacon ) = $this->garden();
		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $yacon ) );
		$accepted = get_post( $oca )->post_content;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( array( 'links_left' => 1 ), undergrowth_dev_unlink_source( $oca ) );
		$this->assertSame( $accepted, get_post( $oca )->post_content );
	}

	public function test_no_link_is_removed_without_its_sentence() {
		$this->assertNull( undergrowth_dev_remove_link( '<p>I keep <a href="https://example.com/">yacon</a> plants.</p>', 'https://example.com/', '' ) );
	}

	public function test_a_link_in_the_second_copy_of_its_sentence_is_found() {
		$url      = 'https://example.com/yacon/';
		$sentence = 'I keep yacon plants.';
		$content  = '<p>I keep yacon plants.</p><p>I keep <a href="' . $url . '">yacon</a> plants.</p>';

		$this->assertSame( '<p>I keep yacon plants.</p><p>I keep yacon plants.</p>', undergrowth_dev_remove_link( $content, $url, $sentence ) );
	}

	public function test_run_cycle_queues_one_cycle_however_often_it_is_clicked() {
		undergrowth_register_dev_tools( 'local' );

		$this->submit( 'cycle' );
		$this->submit( 'cycle' );

		$queued = 0;

		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( $events['undergrowth_cluster_cycle'] ?? array() as $event ) {
				$queued += false === $event['schedule'] ? 1 : 0;
			}
		}

		$this->assertSame( 1, $queued );
	}

	public function test_accept_all_is_refused_while_a_job_is_running() {
		undergrowth_register_dev_tools( 'local' );
		undergrowth_start_dev_job( 'reset' );

		$this->assertStringContainsString( 'undergrowth_notice=dev_busy', $this->submit( 'accept' ) );
		$this->assertSame( 'reset', undergrowth_dev_job()['type'] );
	}

	public function test_a_link_is_found_in_a_sentence_that_overlaps_itself() {
		$url = 'https://example.com/yacon/';

		$this->assertSame( '<p>yacon yacon yacon.</p>', undergrowth_dev_remove_link( '<p>yacon yacon <a href="' . $url . '">yacon</a>.</p>', $url, 'yacon yacon.' ) );
	}

	public function test_the_undo_counts_a_link_left_when_the_post_cannot_be_saved() {
		list( $oca, $yacon ) = $this->garden();
		$this->assertSame( 'accepted', undergrowth_accept_link( $oca, $yacon ) );
		$accepted = get_post( $oca )->post_content;

		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertSame( array( 'links_left' => 1 ), undergrowth_dev_unlink_source( $oca ) );
		$this->assertSame( $accepted, get_post( $oca )->post_content );
	}
}
