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
		$this->assertFalse( get_option( 'undergrowth_schema_version' ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_embed_post', array( 1 ) ) );
	}
}
