<?php
/**
 * Tests for storing vectors packed, and for converting rows stored as JSON.
 *
 * @package Undergrowth
 */

/**
 * Packed vector storage.
 */
class Test_Vectors_Packed extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'undergrowth_pack_batch_size' );
		delete_option( 'undergrowth_pack_vectors.lock' );

		// A test that drops the packed column puts it back even if it fails,
		// since a rollback does not undo a change to a table.
		undergrowth_install_schema();

		parent::tear_down();
	}

	/**
	 * Stores a vector the way versions before packing did.
	 *
	 * @param int                   $post_id  Post ID.
	 * @param float[]|string        $vector   Vector, or the stored text itself.
	 * @param array<string, string> $identity Provider, model and language.
	 * @return int Row ID.
	 */
	private function store_json( int $post_id, $vector, array $identity = array() ): int {
		global $wpdb;

		$identity += array(
			'provider' => 'openai',
			'model'    => 'text-embedding-3-small',
			'language' => 'en_US',
		);

		$wpdb->insert(
			undergrowth_vectors_table(),
			array(
				'post_id'      => $post_id,
				'provider'     => $identity['provider'],
				'model'        => $identity['model'],
				'language'     => $identity['language'],
				'dimensions'   => is_array( $vector ) ? count( $vector ) : 0,
				'embedding'    => is_array( $vector ) ? wp_json_encode( $vector ) : $vector,
				'content_hash' => str_repeat( 'a', 64 ),
				'embedded_at'  => '2026-01-02 03:04:05',
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Reads a stored row as it is in the table.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function row( int $post_id ): array {
		global $wpdb;

		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', undergrowth_vectors_table(), $post_id ), ARRAY_A );
	}

	/**
	 * Saves a vector with an ordinary identity.
	 *
	 * @param int     $post_id Post ID.
	 * @param float[] $vector  Vector.
	 * @return bool
	 */
	private function save( int $post_id, array $vector ): bool {
		return undergrowth_save_vector(
			$post_id,
			$vector,
			array(
				'provider'     => 'openai',
				'model'        => 'text-embedding-3-small',
				'language'     => 'en_US',
				'content_hash' => str_repeat( 'b', 64 ),
			)
		);
	}

	/**
	 * Runs every queued conversion batch.
	 *
	 * @return int How many batches ran.
	 */
	private function convert(): int {
		$runs = 0;

		while ( false !== wp_next_scheduled( 'undergrowth_pack_vectors' ) && $runs < 50 ) {
			wp_clear_scheduled_hook( 'undergrowth_pack_vectors' );
			do_action( 'undergrowth_pack_vectors' );
			++$runs;
		}

		return $runs;
	}

	public function test_a_saved_vector_is_stored_packed_not_as_json() {
		$this->assertTrue( $this->save( 7, array( 0.6, 0.8, -0.25 ) ) );

		$row = $this->row( 7 );

		$this->assertSame( '', $row['embedding'] );
		$this->assertSame( pack( 'g*', 0.6, 0.8, -0.25 ), $row['packed'] );
		$this->assertSame( 12, strlen( $row['packed'] ) );
		$this->assertEqualsWithDelta( array( 0.6, 0.8, -0.25 ), undergrowth_get_vector( 7 )['vector'], 1e-7 );
	}

	public function test_awkward_bytes_survive_the_database() {
		// Floats whose bytes are a NUL, a quote, a backslash and a double quote.
		$vector = array_values( unpack( 'g*', "\x00\x27\x5c\x3f\x22\x00\x00\x3f\x5c\x5c\x27\x3e\x00\x00\x80\x3f" ) );

		$this->assertTrue( $this->save( 7, $vector ) );

		$this->assertSame( pack( 'g*', ...$vector ), $this->row( 7 )['packed'] );
		$this->assertSame( array( 7 => pack( 'g*', ...$vector ) ), undergrowth_vectors_in_space( 'openai', 'text-embedding-3-small', 'en_US' ) );
	}

	public function test_a_vector_saves_after_an_upgrade_adds_the_column_in_the_same_request() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN packed', undergrowth_vectors_table() ) );

		// wpdb now remembers the table without the column, as it would after
		// any query on it earlier in the request that upgrades.
		$wpdb->get_col_charset( undergrowth_vectors_table(), 'embedding' );
		$this->assertTrue( undergrowth_install_schema() );

		$this->assertTrue( $this->save( 7, array( 0.6, 0.8 ) ) );
		$this->assertSame( pack( 'g*', 0.6, 0.8 ), $this->row( 7 )['packed'] );
	}

	public function test_a_vector_too_large_for_single_precision_is_refused() {
		$this->assertFalse( $this->save( 7, array( 1.0, 1e39 ) ) );
		$this->assertNull( undergrowth_get_vector( 7 ) );
	}

	public function test_rows_stored_as_json_are_read_until_converted() {
		$this->store_json( 7, array( 0.6, 0.8 ) );
		$this->assertTrue( $this->save( 8, array( 0.8, 0.6 ) ) );

		$this->assertSame( array( 0.6, 0.8 ), undergrowth_get_vector( 7 )['vector'] );
		$this->assertSame(
			array(
				7 => pack( 'g*', 0.6, 0.8 ),
				8 => pack( 'g*', 0.8, 0.6 ),
			),
			undergrowth_vectors_in_space( 'openai', 'text-embedding-3-small', 'en_US' )
		);
		$this->assertSame(
			array(
				7 => pack( 'g*', 0.6, 0.8 ),
				8 => pack( 'g*', 0.8, 0.6 ),
			),
			undergrowth_comparable_vectors( 7 )
		);
	}

	public function test_the_upgrade_queues_the_conversion_only_when_json_rows_are_left() {
		undergrowth_install_schema();
		$this->assertFalse( wp_next_scheduled( 'undergrowth_pack_vectors' ) );

		$this->store_json( 7, array( 0.6, 0.8 ) );
		delete_option( 'undergrowth_schema_version' );
		undergrowth_maybe_upgrade_schema();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_pack_vectors' ) );
	}

	public function test_conversion_runs_in_batches_and_keeps_each_vector_s_identity() {
		$spaces = array(
			array(
				'provider' => 'openai',
				'model'    => 'text-embedding-3-small',
				'language' => 'en_US',
			),
			array(
				'provider' => 'ollama',
				'model'    => 'nomic-embed-text',
				'language' => 'fr_FR',
			),
		);

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->store_json( $i, array( $i / 10, 1 - $i / 10, 0.5 ), $spaces[ $i % 2 ] );
		}

		$before = array(
			undergrowth_vectors_in_space( 'openai', 'text-embedding-3-small', 'en_US' ),
			undergrowth_vectors_in_space( 'ollama', 'nomic-embed-text', 'fr_FR' ),
		);

		add_filter(
			'undergrowth_pack_batch_size',
			static function () {
				return 2;
			}
		);
		undergrowth_queue_pack_vectors();

		$this->assertSame( 3, $this->convert() );

		for ( $i = 1; $i <= 5; $i++ ) {
			$row = $this->row( $i );
			$this->assertSame( '', $row['embedding'] );
			$this->assertSame( pack( 'g*', $i / 10, 1 - $i / 10, 0.5 ), $row['packed'] );
			$this->assertSame( $spaces[ $i % 2 ]['provider'], $row['provider'] );
			$this->assertSame( $spaces[ $i % 2 ]['model'], $row['model'] );
			$this->assertSame( $spaces[ $i % 2 ]['language'], $row['language'] );
			$this->assertSame( '3', (string) $row['dimensions'] );
			$this->assertSame( str_repeat( 'a', 64 ), $row['content_hash'] );
			$this->assertSame( '2026-01-02 03:04:05', $row['embedded_at'] );
		}

		$this->assertSame(
			$before,
			array(
				undergrowth_vectors_in_space( 'openai', 'text-embedding-3-small', 'en_US' ),
				undergrowth_vectors_in_space( 'ollama', 'nomic-embed-text', 'fr_FR' ),
			)
		);
		$this->assertFalse( wp_next_scheduled( 'undergrowth_pack_vectors' ) );
	}

	public function test_conversion_can_stop_and_start_again_without_touching_converted_rows() {
		global $wpdb;

		for ( $i = 1; $i <= 4; $i++ ) {
			$this->store_json( $i, array( $i, 1.0 ) );
		}

		add_filter(
			'undergrowth_pack_batch_size',
			static function () {
				return 2;
			}
		);

		do_action( 'undergrowth_pack_vectors' );
		$first = $this->row( 1 );

		// A batch run twice, as when cron fires an event again, does no harm.
		do_action( 'undergrowth_pack_vectors' );
		do_action( 'undergrowth_pack_vectors' );

		$this->assertSame( $first, $this->row( 1 ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE packed IS NULL', undergrowth_vectors_table() ) ) );
		$this->assertSame( pack( 'g*', 4.0, 1.0 ), $this->row( 4 )['packed'] );
	}

	public function test_a_batch_that_converts_nothing_queues_no_next_batch() {
		$this->store_json( 7, array( 0.6, 0.8 ) );
		$this->store_json( 8, array( 0.8, 0.6 ) );
		add_filter(
			'undergrowth_pack_batch_size',
			static function () {
				return 2;
			}
		);
		// Every update changes nothing, as on a database that refuses writes.
		$refuse = static function ( $query ) {
			return 0 === strpos( $query, 'UPDATE' ) ? $GLOBALS['wpdb']->prepare( 'UPDATE %i SET embedding = embedding WHERE 1 = 0', undergrowth_vectors_table() ) : $query;
		};
		add_filter( 'query', $refuse );

		do_action( 'undergrowth_pack_vectors' );

		remove_filter( 'query', $refuse );
		$this->assertNull( $this->row( 7 )['packed'] );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_pack_vectors' ) );
	}

	public function test_the_version_is_not_recorded_when_the_packed_column_is_missing() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN packed', undergrowth_vectors_table() ) );
		delete_option( 'undergrowth_schema_version' );
		$refuse = static function ( $queries ) {
			return array_filter(
				$queries,
				static function ( $query ) {
					return false === strpos( $query, 'packed' );
				}
			);
		};
		add_filter( 'dbdelta_queries', $refuse );

		$installed = undergrowth_install_schema();

		remove_filter( 'dbdelta_queries', $refuse );
		$this->assertFalse( $installed );
		$this->assertFalse( get_option( 'undergrowth_schema_version' ) );
		$this->assertTrue( undergrowth_install_schema() );
	}

	public function test_a_batch_waits_while_another_holds_the_lock() {
		$this->store_json( 7, array( 0.6, 0.8 ) );
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		WP_Upgrader::create_lock( 'undergrowth_pack_vectors', HOUR_IN_SECONDS );

		do_action( 'undergrowth_pack_vectors' );

		$this->assertNull( $this->row( 7 )['packed'] );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_pack_vectors' ) );
	}

	public function test_the_hourly_check_starts_a_conversion_that_died() {
		$this->store_json( 7, array( 0.6, 0.8 ) );
		wp_clear_scheduled_hook( 'undergrowth_pack_vectors' );

		do_action( 'undergrowth_backfill_check' );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_pack_vectors' ) );

		$this->convert();
		do_action( 'undergrowth_backfill_check' );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_pack_vectors' ) );
	}

	public function test_an_unreadable_row_stays_unreadable_and_is_not_tried_again() {
		$this->store_json( 7, 'not a vector' );
		$this->store_json( 8, array( 0.6, 0.8 ) );

		undergrowth_queue_pack_vectors();
		$this->assertSame( 1, $this->convert() );

		$this->assertSame( '', $this->row( 7 )['packed'] );
		$this->assertSame( array(), undergrowth_get_vector( 7 )['vector'] );
		$this->assertSame( array( 8 ), array_keys( undergrowth_vectors_in_space( 'openai', 'text-embedding-3-small', 'en_US' ) ) );
	}

	public function test_a_row_saved_during_the_conversion_keeps_its_new_vector() {
		$this->store_json( 7, array( 0.6, 0.8 ) );
		$this->assertTrue( $this->save( 7, array( 0.8, 0.6 ) ) );

		undergrowth_queue_pack_vectors();
		$this->convert();

		$this->assertSame( pack( 'g*', 0.8, 0.6 ), $this->row( 7 )['packed'] );
	}

	public function test_clusters_read_json_and_packed_rows_alike() {
		$this->store_json( 7, array( 0.6, 0.8 ) );
		$this->assertTrue( $this->save( 8, array( 0.6, 0.8 ) ) );

		$leaders = undergrowth_leader_vectors(
			array( 7, 8 ),
			array(
				'provider' => 'openai',
				'model'    => 'text-embedding-3-small',
				'language' => 'en_US',
			)
		);

		$this->assertSame( $leaders[7], $leaders[8] );
	}

	/**
	 * Every site converts its own table, in its own cron.
	 *
	 * @group ms-required
	 */
	public function test_each_site_of_a_network_converts_its_own_table() {
		$site = self::factory()->blog->create();
		$this->store_json( 7, array( 0.6, 0.8 ) );

		switch_to_blog( $site );
		undergrowth_install_schema();
		$this->store_json( 7, array( 0.8, 0.6 ) );
		undergrowth_queue_pack_vectors();
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_pack_vectors' ) );
		$this->convert();
		$converted = $this->row( 7 );
		restore_current_blog();

		$this->assertSame( pack( 'g*', 0.8, 0.6 ), $converted['packed'] );
		$this->assertNull( $this->row( 7 )['packed'] );
		$this->assertSame( '[0.6,0.8]', $this->row( 7 )['embedding'] );

		undergrowth_queue_pack_vectors();
		$this->convert();
		$this->assertSame( pack( 'g*', 0.6, 0.8 ), $this->row( 7 )['packed'] );
	}
}
