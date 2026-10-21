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

	public function test_outdated_schema_version_triggers_install() {
		update_option( 'undergrowth_schema_version', 0 );

		undergrowth_maybe_upgrade_schema();

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
		update_option( 'undergrowth_anthropic_api_key', 'sk-ant-test' );
		update_option( 'undergrowth_last_prompt_error', array( 'code' => 'x' ) );
		update_option( 'undergrowth_prompt', array( 'key' => 'comment:1' ) );
		$post_id = self::factory()->post->create();
		add_post_meta( $post_id, 'undergrowth_prompt_keys', 'comment:1' );
		add_post_meta( $post_id, 'undergrowth_prompt_log', array( 'event' => 'asked' ) );
		update_option( 'undergrowth_snoozed_prompts', array( array( 'key' => 'comment:2' ) ) );
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
		$this->assertFalse( get_option( 'undergrowth_anthropic_api_key' ) );
		$this->assertFalse( get_option( 'undergrowth_last_prompt_error' ) );
		$this->assertFalse( get_option( 'undergrowth_prompt' ) );
		$this->assertSame( array(), get_post_meta( $post_id, 'undergrowth_prompt_keys', false ) );
		$this->assertSame( array(), get_post_meta( $post_id, 'undergrowth_prompt_log', false ) );
		$this->assertFalse( get_option( 'undergrowth_snoozed_prompts' ) );
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
}
