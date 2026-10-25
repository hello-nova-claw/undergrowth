<?php
/**
 * Tests for the vectors table and its lifecycle.
 *
 * @package Undergrowth
 */

/**
 * Schema install, upgrade and uninstall.
 */
class Test_Schema extends WP_UnitTestCase {

	/**
	 * Column names of the vectors table, in table order.
	 *
	 * @return string[]
	 */
	private function columns(): array {
		global $wpdb;

		return $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', undergrowth_vectors_table() ) );
	}

	/**
	 * Whether a test replaced the plugin's real tables.
	 *
	 * @var bool
	 */
	private $replaced_tables = false;

	/**
	 * Puts the plugin's real tables back in their current shape after a test
	 * that replaced them.
	 */
	public function tear_down() {
		global $wpdb;

		$restored = true;

		if ( $this->replaced_tables ) {
			foreach ( array( undergrowth_vectors_table(), undergrowth_links_table() ) as $table ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
			}
			$restored              = undergrowth_install_schema();
			$this->replaced_tables = false;
		}

		// Failing before the parent's cleanup would skip its rollback.
		parent::tear_down();

		if ( ! $restored ) {
			$this->fail( 'The plugin tables could not be put back, so later tests will fail too.' );
		}
	}

	/**
	 * Column names of any table, in table order.
	 *
	 * @param string $table Table name.
	 * @return string[]
	 */
	private function column_names( string $table ): array {
		global $wpdb;

		return $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
	}

	public function test_table_name_uses_site_prefix() {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'undergrowth_vectors', undergrowth_vectors_table() );
	}

	public function test_table_exists_after_plugin_loads() {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', undergrowth_vectors_table() ) );

		$this->assertSame( undergrowth_vectors_table(), $found );
	}

	public function test_table_stores_provider_model_and_language_with_each_vector() {
		$this->assertSame(
			array( 'id', 'post_id', 'provider', 'model', 'language', 'dimensions', 'embedding', 'content_hash', 'embedded_at' ),
			$this->columns()
		);
	}

	public function test_install_is_idempotent() {
		$before = $this->columns();

		undergrowth_install_schema();

		$this->assertSame( $before, $this->columns() );
		$this->assertSame( UNDERGROWTH_SCHEMA_VERSION, (int) get_option( 'undergrowth_schema_version' ) );
	}

	public function test_schema_version_is_not_recorded_when_table_is_missing_after_install() {
		delete_option( 'undergrowth_schema_version' );

		// Make the post-install check look for a table that does not exist, as if CREATE TABLE had failed.
		$hide_table = static function ( $query ) {
			if ( 0 === strpos( $query, 'SHOW TABLES LIKE' ) ) {
				return "SHOW TABLES LIKE 'undergrowth_missing_table'";
			}
			return $query;
		};
		add_filter( 'query', $hide_table );

		$installed = undergrowth_install_schema();

		remove_filter( 'query', $hide_table );
		$this->assertFalse( $installed );
		$this->assertFalse( get_option( 'undergrowth_schema_version' ) );
	}

	public function test_successful_install_reports_success() {
		$this->assertTrue( undergrowth_install_schema() );
	}

	public function test_activation_installs_schema() {
		delete_option( 'undergrowth_schema_version' );

		undergrowth_activate();

		$this->assertSame( UNDERGROWTH_SCHEMA_VERSION, (int) get_option( 'undergrowth_schema_version' ) );
	}

	public function test_activation_hook_is_registered() {
		$this->assertNotFalse( has_action( 'activate_' . plugin_basename( UNDERGROWTH_FILE ), 'undergrowth_activate' ) );
	}

	public function test_deactivation_hook_is_registered() {
		$this->assertNotFalse( has_action( 'deactivate_' . plugin_basename( UNDERGROWTH_FILE ), 'undergrowth_deactivate' ) );
	}

	public function test_outdated_schema_version_triggers_install() {
		update_option( 'undergrowth_schema_version', 0 );

		undergrowth_maybe_upgrade_schema();

		$this->assertSame( UNDERGROWTH_SCHEMA_VERSION, (int) get_option( 'undergrowth_schema_version' ) );
	}

	/**
	 * Replaces the plugin's tables with the shape a released version left,
	 * empty, and records that version.
	 *
	 * Version 1 had only the vectors table, already in its current shape.
	 * Version 2 added the links table, which versions 3 to 5 widened.
	 *
	 * The test suite makes every table it creates temporary, and the SQLite
	 * driver cannot alter a temporary table, so this replaces the real
	 * tables and tear_down() puts them back.
	 *
	 * @param int $version 1 or 2.
	 * @return array<string, string[]> The current column names, keyed by table.
	 */
	private function install_version( int $version ): array {
		global $wpdb;

		$vectors = undergrowth_vectors_table();
		$links   = undergrowth_links_table();
		$current = array(
			$vectors => $this->column_names( $vectors ),
			$links   => $this->column_names( $links ),
		);

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->replaced_tables = true;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $vectors ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $links ) );

		$wpdb->query(
			$wpdb->prepare(
				'CREATE TABLE %i (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			provider varchar(100) NOT NULL,
			model varchar(191) NOT NULL,
			language varchar(20) NOT NULL,
			dimensions int(10) unsigned NOT NULL,
			embedding longtext NOT NULL,
			content_hash char(64) NOT NULL,
			embedded_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id)
			)',
				$vectors
			)
		);

		if ( $version >= 2 ) {
			$wpdb->query(
				$wpdb->prepare(
					'CREATE TABLE %i (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_id bigint(20) unsigned NOT NULL,
				target_id bigint(20) unsigned NOT NULL,
				state varchar(20) NOT NULL,
				score double NOT NULL,
				sentence text NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY pair (source_id,target_id),
				KEY target_id (target_id)
				)',
					$links
				)
			);
			$this->assertNotContains( 'sentence_index', $this->column_names( $links ) );
		} else {
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $links ) ) );
		}

		update_option( 'undergrowth_schema_version', $version );

		return $current;
	}

	/**
	 * Asserts both tables have the current columns again.
	 *
	 * @param array<string, string[]> $current The current column names, keyed by table.
	 */
	private function assert_current_columns( array $current ): void {
		// dbDelta() adds a missing column at the end, so only the set is compared.
		foreach ( $current as $table => $columns ) {
			$after = $this->column_names( $table );
			sort( $after );
			sort( $columns );
			$this->assertSame( $columns, $after, $table );
		}
		$this->assertSame( UNDERGROWTH_SCHEMA_VERSION, (int) get_option( 'undergrowth_schema_version' ) );
	}

	public function test_upgrade_from_version_two_adds_the_link_columns_and_keeps_links() {
		global $wpdb;

		$current = $this->install_version( 2 );
		$links   = undergrowth_links_table();
		$wpdb->insert(
			$links,
			array(
				'source_id'  => 7,
				'target_id'  => 8,
				'state'      => 'vetoed',
				'score'      => 0.75,
				'sentence'   => 'An old sentence.',
				'created_at' => '2026-01-02 03:04:05',
				'updated_at' => '2026-01-03 03:04:05',
			)
		);

		undergrowth_maybe_upgrade_schema();

		$this->assert_current_columns( $current );
		$link = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source_id = %d AND target_id = %d', $links, 7, 8 ), ARRAY_A );
		$this->assertSame( 'vetoed', $link['state'] );
		$this->assertEqualsWithDelta( 0.75, (float) $link['score'], 0.0001 );
		$this->assertSame( 'An old sentence.', $link['sentence'] );
		$this->assertSame( '2026-01-03 03:04:05', $link['updated_at'] );
		$this->assertSame( '', $link['anchor'] );
		$this->assertSame( -1, (int) $link['anchor_offset'] );
		$this->assertSame( 0, (int) $link['anchor_chosen'] );
	}

	public function test_upgrade_from_version_one_adds_the_links_table_and_keeps_vectors() {
		global $wpdb;

		$current = $this->install_version( 1 );
		$vectors = undergrowth_vectors_table();
		$wpdb->insert(
			$vectors,
			array(
				'post_id'      => 7,
				'provider'     => 'openai',
				'model'        => 'text-embedding-3-small',
				'language'     => 'en_US',
				'dimensions'   => 2,
				'embedding'    => '[0.6,0.8]',
				'content_hash' => str_repeat( 'a', 64 ),
				'embedded_at'  => '2026-01-02 03:04:05',
			)
		);

		undergrowth_maybe_upgrade_schema();

		$this->assert_current_columns( $current );
		$vector = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', $vectors, 7 ), ARRAY_A );
		$this->assertSame( 'openai', $vector['provider'] );
		$this->assertSame( 'text-embedding-3-small', $vector['model'] );
		$this->assertSame( 'en_US', $vector['language'] );
		$this->assertSame( 2, (int) $vector['dimensions'] );
		$this->assertSame( '[0.6,0.8]', $vector['embedding'] );
		$this->assertSame( str_repeat( 'a', 64 ), $vector['content_hash'] );
		$this->assertSame( '2026-01-02 03:04:05', $vector['embedded_at'] );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', undergrowth_links_table() ) ) );
	}

	public function test_upgrade_drops_the_sentence_index_nothing_reads() {
		global $wpdb;

		$current = $this->install_version( 2 );
		$links   = undergrowth_links_table();
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN sentence_index int(11) NOT NULL DEFAULT -1', $links ) );
		$this->assertContains( 'sentence_index', $this->column_names( $links ) );
		update_option( 'undergrowth_schema_version', 5 );

		undergrowth_maybe_upgrade_schema();

		$this->assertNotContains( 'sentence_index', $this->column_names( $links ) );
		$this->assert_current_columns( $current );
	}

	public function test_version_is_not_recorded_when_the_sentence_index_stays() {
		global $wpdb;

		$this->install_version( 2 );
		$links = undergrowth_links_table();
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN sentence_index int(11) NOT NULL DEFAULT -1', $links ) );
		update_option( 'undergrowth_schema_version', 5 );
		// wpdb sends no empty query and reports it as failed, as for a refused ALTER.
		$refuse = static function ( $query ) {
			return false !== stripos( $query, 'DROP COLUMN sentence_index' ) ? '' : $query;
		};
		add_filter( 'query', $refuse );

		$this->assertFalse( undergrowth_install_schema() );
		$this->assertContains( 'sentence_index', $this->column_names( $links ) );
		$this->assertSame( 5, (int) get_option( 'undergrowth_schema_version' ) );

		// The next load tries again. On MySQL the table changes commit the
		// option too, so a version left behind would reach later tests.
		remove_filter( 'query', $refuse );
		$this->assertTrue( undergrowth_install_schema() );
		$this->assertNotContains( 'sentence_index', $this->column_names( $links ) );
		$this->assertSame( UNDERGROWTH_SCHEMA_VERSION, (int) get_option( 'undergrowth_schema_version' ) );
	}

	public function test_current_schema_version_skips_install() {
		$queries = array();
		$record  = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record );

		undergrowth_maybe_upgrade_schema();

		remove_filter( 'query', $record );
		$this->assertSame( array(), preg_grep( '/^\s*(CREATE|ALTER|DESCRIBE)/i', $queries ) );
	}

	public function test_uninstall_drops_table_and_options_and_events() {
		global $wpdb;

		wp_schedule_single_event( time() + 60, 'undergrowth_embed_post', array( 1 ) );
		update_option( 'undergrowth_openai_api_key', 'sk-test' );
		update_option( 'undergrowth_last_embed_error', array( 'code' => 'x' ) );
		update_option( 'undergrowth_embed_retry_at', time() + 60 );
		update_option( 'undergrowth_anthropic_api_key', 'sk-ant-test' );
		update_option( 'undergrowth_last_prompt_error', array( 'code' => 'x' ) );
		update_option( 'undergrowth_prompt', array( 'key' => 'comment:1' ) );
		$post_id = self::factory()->post->create();
		add_post_meta( $post_id, 'undergrowth_prompt_keys', 'comment:1' );
		add_post_meta( $post_id, 'undergrowth_prompt_log', array( 'event' => 'asked' ) );
		update_option( 'undergrowth_snoozed_prompts', array( array( 'key' => 'comment:2' ) ) );
		update_option( 'undergrowth_prompt_written', 1 );
		update_option( 'undergrowth_config_prompt_queued', 1 );
		wp_schedule_single_event( time(), 'undergrowth_write_prompt' );
		undergrowth_schedule_prompt_cycle();
		wp_schedule_single_event( time(), 'undergrowth_backfill' );
		undergrowth_schedule_backfill_check();
		update_option( 'undergrowth_backfill', array( 'after' => 5 ) );
		update_option( 'undergrowth_backfill.lock', time(), false );
		update_option(
			'undergrowth_index_identity',
			array(
				'provider' => 'openai',
				'model'    => UNDERGROWTH_OPENAI_MODEL,
			)
		);

		// Recorded ahead of the test suite's own filter, which would turn the drop into a no-op on temporary tables.
		$queries = array();
		$record  = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record, 1 );

		undergrowth_uninstall();

		remove_filter( 'query', $record, 1 );

		$expected = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', undergrowth_vectors_table() );
		$this->assertContains( $expected, $queries );
		$this->assertContains( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', undergrowth_links_table() ), $queries );
		$this->assertFalse( get_option( 'undergrowth_schema_version' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_embed_post', array( 1 ) ) );
		$this->assertFalse( get_option( 'undergrowth_openai_api_key' ) );
		$this->assertFalse( get_option( 'undergrowth_last_embed_error' ) );
		$this->assertFalse( get_option( 'undergrowth_embed_retry_at' ) );
		$this->assertFalse( get_option( 'undergrowth_anthropic_api_key' ) );
		$this->assertFalse( get_option( 'undergrowth_last_prompt_error' ) );
		$this->assertFalse( get_option( 'undergrowth_prompt' ) );
		$this->assertSame( array(), get_post_meta( $post_id, 'undergrowth_prompt_keys', false ) );
		$this->assertSame( array(), get_post_meta( $post_id, 'undergrowth_prompt_log', false ) );
		$this->assertFalse( get_option( 'undergrowth_snoozed_prompts' ) );
		$this->assertFalse( get_option( 'undergrowth_prompt_written' ) );
		$this->assertFalse( get_option( 'undergrowth_config_prompt_queued' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_write_prompt' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_prompt_cycle' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_backfill' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_backfill_check' ) );
		$this->assertFalse( get_option( 'undergrowth_backfill' ) );
		$this->assertFalse( get_option( 'undergrowth_backfill.lock' ) );
		$this->assertFalse( get_option( 'undergrowth_index_identity' ) );
	}

	public function test_uninstall_removes_the_files_folder_its_name_and_any_old_folder() {
		$uploads = wp_upload_dir();
		$root    = undergrowth_memory_root();
		wp_mkdir_p( $root . '/posts' );
		wp_mkdir_p( $uploads['basedir'] . '/undergrowth/posts' );

		undergrowth_uninstall();

		$this->assertDirectoryDoesNotExist( $root );
		$this->assertDirectoryDoesNotExist( $uploads['basedir'] . '/undergrowth' );
		$this->assertFalse( get_option( 'undergrowth_memory_folder' ) );
	}

	public function test_uninstall_makes_no_folder_name() {
		delete_option( 'undergrowth_memory_folder' );

		undergrowth_uninstall();

		$this->assertFalse( get_option( 'undergrowth_memory_folder' ) );
	}

	public function test_uninstall_removes_the_meter_reading() {
		set_transient(
			'undergrowth_meter',
			array(
				'views' => 10,
				'time'  => time(),
			),
			DAY_IN_SECONDS
		);

		undergrowth_uninstall();

		$this->assertFalse( get_transient( 'undergrowth_meter' ) );
	}

	/**
	 * Uninstall drops the table on every site, not only the first hundred.
	 *
	 * @group ms-required
	 */
	public function test_network_uninstall_cleans_every_site() {
		global $wpdb;

		$site_ids = self::factory()->blog->create_many( 2 );

		$queries = array();
		$record  = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record, 1 );

		undergrowth_uninstall();

		remove_filter( 'query', $record, 1 );

		foreach ( array_merge( array( get_current_blog_id() ), $site_ids ) as $site_id ) {
			foreach ( array( 'undergrowth_vectors', 'undergrowth_links' ) as $table ) {
				$this->assertContains( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->get_blog_prefix( $site_id ) . $table ), $queries );
			}
		}
	}

	/**
	 * Clears what activation sets up on the current site.
	 */
	private function forget_activation(): void {
		delete_option( 'undergrowth_schema_version' );
		wp_unschedule_hook( 'undergrowth_cluster_cycle' );
		wp_unschedule_hook( 'undergrowth_backfill_check' );
	}

	/**
	 * Runs a callback and returns every query it made.
	 *
	 * @param callable $callback The code under test.
	 * @return array{0: mixed, 1: string[]} The callback's result and the queries.
	 */
	private function record_queries( callable $callback ): array {
		$queries = array();
		$record  = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record, 1 );
		$result = $callback();
		remove_filter( 'query', $record, 1 );

		return array( $result, $queries );
	}

	/**
	 * Picks out the queries that create a plugin table on one site.
	 *
	 * Site IDs come round again between tests, so a table left by an earlier
	 * test would pass a check of the table itself.
	 *
	 * @param string[] $queries Recorded queries.
	 * @param int      $site_id The site.
	 * @return string[]
	 */
	private function plugin_creates( array $queries, int $site_id ): array {
		global $wpdb;

		return preg_grep( '/^\s*CREATE TABLE ' . preg_quote( $wpdb->get_blog_prefix( $site_id ), '/' ) . 'undergrowth_/i', $queries );
	}

	/**
	 * Asserts the current site has both tables and both recurring events.
	 *
	 * The test suite makes every table it creates temporary, and SHOW TABLES
	 * leaves temporary tables out, so this reads each table instead.
	 */
	private function assert_site_is_set_up(): void {
		global $wpdb;

		$suppress = $wpdb->suppress_errors();
		foreach ( array( undergrowth_vectors_table(), undergrowth_links_table() ) as $table ) {
			$this->assertNotFalse( $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) ), $table );
		}
		$wpdb->suppress_errors( $suppress );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_cluster_cycle' ), (string) get_current_blog_id() );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_backfill_check' ), (string) get_current_blog_id() );
	}

	public function test_new_site_hook_is_registered() {
		$this->assertNotFalse( has_action( 'wp_initialize_site', 'undergrowth_initialize_site' ) );
	}

	/**
	 * Network activation sets up every site, not only the main one.
	 *
	 * @group ms-required
	 */
	public function test_network_activation_sets_up_every_site() {
		$new_ids  = self::factory()->blog->create_many( 2 );
		$site_ids = array_merge( array( get_current_blog_id() ), $new_ids );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			$this->forget_activation();
			restore_current_blog();
		}

		list( , $queries ) = $this->record_queries(
			static function () {
				undergrowth_activate( true );
			}
		);

		// The main site's tables are lasting ones from the bootstrap, so dbDelta
		// has nothing to create there.
		foreach ( $new_ids as $site_id ) {
			$this->assertCount( 2, $this->plugin_creates( $queries, $site_id ), (string) $site_id );
		}

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			$this->assert_site_is_set_up();
			restore_current_blog();
		}
	}

	/**
	 * Activation on one site of a network leaves the other sites alone.
	 *
	 * @group ms-required
	 */
	public function test_site_activation_on_a_network_sets_up_only_that_site() {
		$other_id = self::factory()->blog->create();

		switch_to_blog( $other_id );
		$this->forget_activation();
		restore_current_blog();
		$this->forget_activation();

		list( , $queries ) = $this->record_queries(
			static function () {
				undergrowth_activate( false );
			}
		);

		$this->assert_site_is_set_up();
		$this->assertSame( array(), $this->plugin_creates( $queries, $other_id ) );
		switch_to_blog( $other_id );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_cluster_cycle' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_backfill_check' ) );
		restore_current_blog();
	}

	/**
	 * A site created while the plugin is active across the network is set up
	 * at once.
	 *
	 * @group ms-required
	 */
	public function test_new_site_is_set_up_when_network_active() {
		update_site_option( 'active_sitewide_plugins', array( plugin_basename( UNDERGROWTH_FILE ) => time() ) );

		list( $site_id, $queries ) = $this->record_queries(
			static function () {
				return self::factory()->blog->create();
			}
		);

		$this->assertCount( 2, $this->plugin_creates( $queries, $site_id ) );
		switch_to_blog( $site_id );
		$this->assert_site_is_set_up();
		restore_current_blog();
	}

	/**
	 * A site created while the plugin is not active across the network gets
	 * nothing until it is activated there.
	 *
	 * @group ms-required
	 */
	public function test_new_site_is_left_alone_when_not_network_active() {
		update_site_option( 'active_sitewide_plugins', array() );

		list( $site_id, $queries ) = $this->record_queries(
			static function () {
				return self::factory()->blog->create();
			}
		);

		$this->assertSame( array(), $this->plugin_creates( $queries, $site_id ) );

		// Events left on a reused site ID would hide any the hook scheduled,
		// so the hook is also run again on a cleared site.
		switch_to_blog( $site_id );
		$this->forget_activation();
		restore_current_blog();

		undergrowth_initialize_site( get_site( $site_id ) );

		switch_to_blog( $site_id );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_cluster_cycle' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_backfill_check' ) );
		restore_current_blog();
	}

	/**
	 * A site on another network is set up only when the plugin is active
	 * across that network, whichever network handles the request.
	 *
	 * @group ms-required
	 */
	public function test_new_site_follows_its_own_network() {
		update_site_option( 'active_sitewide_plugins', array( plugin_basename( UNDERGROWTH_FILE ) => time() ) );
		$network_id = self::factory()->network->create();

		list( $site_id, $queries ) = $this->record_queries(
			static function () use ( $network_id ) {
				return self::factory()->blog->create( array( 'network_id' => $network_id ) );
			}
		);

		$this->assertSame( array(), $this->plugin_creates( $queries, $site_id ) );

		update_site_option( 'active_sitewide_plugins', array() );
		update_network_option( $network_id, 'active_sitewide_plugins', array( plugin_basename( UNDERGROWTH_FILE ) => time() ) );

		list( , $queries ) = $this->record_queries(
			static function () use ( $site_id ) {
				undergrowth_initialize_site( get_site( $site_id ) );
			}
		);

		$this->assertCount( 2, $this->plugin_creates( $queries, $site_id ) );
	}
}
