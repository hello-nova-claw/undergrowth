<?php
/**
 * Tests for the readable per-post file.
 *
 * @package Undergrowth
 */

/**
 * Per-post files.
 */
class Test_Memory extends WP_UnitTestCase {

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		delete_option( 'undergrowth_last_file_error' );
	}

	public function tear_down() {
		undergrowth_delete_memory_folder();
		$this->set_permalink_structure( '' );

		parent::tear_down();
	}

	/**
	 * Reads a post's file, or returns null when there is none.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	private function read( int $post_id ): ?string {
		$path = undergrowth_memory_path( $post_id );

		return file_exists( $path ) ? (string) file_get_contents( $path ) : null;
	}

	/**
	 * Returns the lines listed under one heading of a post's file.
	 *
	 * @param string $file    File contents.
	 * @param string $heading Section heading, without the hashes.
	 * @return string[]
	 */
	private function section( string $file, string $heading ): array {
		if ( ! preg_match( '/^## ' . preg_quote( $heading, '/' ) . "\n(.*?)(?=\n## |\\z)/ms", $file, $match ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( "\n", $match[1] ) ) ) );
	}

	/**
	 * Sets the state of a link row directly.
	 *
	 * @param int    $source_id Source post ID.
	 * @param int    $target_id Target post ID.
	 * @param string $state     New state.
	 */
	private function set_state( int $source_id, int $target_id, string $state ): void {
		global $wpdb;

		$wpdb->update(
			undergrowth_links_table(),
			array( 'state' => $state ),
			array(
				'source_id' => $source_id,
				'target_id' => $target_id,
			)
		);
	}

	public function test_file_lives_in_the_sites_uploads_folder() {
		$uploads = wp_upload_dir();

		$this->assertSame( $uploads['basedir'] . '/undergrowth/posts/42.txt', undergrowth_memory_path( 42 ) );
	}

	public function test_file_is_written_when_a_post_gets_proposals() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$file = $this->read( $oca );

		$this->assertNotNull( $file );
		$this->assertStringContainsString( 'url: ' . get_permalink( $oca ), $file );
		$this->assertStringContainsString( 'slug: ' . get_post( $oca )->post_name, $file );
		$this->assertSame(
			array( '- ' . get_permalink( $yacon ) . ' | "Notes about Oca."' ),
			$this->section( $file, 'proposed links' )
		);
	}

	public function test_file_has_every_section_in_order() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		preg_match_all( '/^## (.+)$/m', (string) $this->read( $oca ), $headings );

		$this->assertSame( array( 'stage', 'resurfacing', 'has', 'lacks', 'scent', 'proposed links', 'accepted links', 'vetoes' ), $headings[1] );
	}

	public function test_stage_section_names_a_seedling_with_no_changes() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$this->assertSame( array( 'seedling' ), $this->section( (string) $this->read( $oca ), 'stage' ) );
	}

	public function test_stage_section_lists_each_change_after_the_current_stage() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$today = wp_date( 'Y-m-d', null, new DateTimeZone( 'UTC' ) );

		undergrowth_set_stage( $oca, 'sprout' );
		undergrowth_set_stage( $oca, 'composting', 'compost' );

		$this->assertSame(
			array(
				'composting',
				'- ' . $today . ': seedling -> sprout (monthly cycle)',
				'- ' . $today . ': sprout -> composting (composted by the author)',
			),
			$this->section( (string) $this->read( $oca ), 'stage' )
		);
	}

	public function test_pinned_stage_carries_a_marker() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		update_post_meta( $oca, 'undergrowth_stage_pinned', '1' );
		undergrowth_set_stage( $oca, 'evergreen', 'pin' );

		$section = $this->section( (string) $this->read( $oca ), 'stage' );

		$this->assertSame( 'evergreen (pinned)', $section[0] );
		$this->assertSame( '- ' . wp_date( 'Y-m-d', null, new DateTimeZone( 'UTC' ) ) . ': seedling -> evergreen (pinned by the author)', $section[1] );
	}

	public function test_composted_stage_never_carries_a_pin_marker() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		update_post_meta( $oca, 'undergrowth_stage_pinned', '1' );
		undergrowth_set_stage( $oca, 'composting', 'compost' );

		$this->assertSame( 'composting', $this->section( (string) $this->read( $oca ), 'stage' )[0] );
	}

	public function test_stage_section_mentions_no_expiry_or_due_date() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		undergrowth_set_stage( $oca, 'composting', 'compost' );
		undergrowth_set_stage( $oca, 'seedling', 'return' );

		$section = implode( "\n", $this->section( (string) $this->read( $oca ), 'stage' ) );

		$this->assertDoesNotMatchRegularExpression( '/\b(until|expires?|due|days?|remaining|since)\b/i', $section );
		$this->assertStringContainsString( 'composting -> seedling (returned to the garden by the author)', $section );
	}

	public function test_accepted_and_vetoed_links_are_listed() {
		$oca    = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon  = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$mashua = $this->plant( 'Mashua', array( 0.8, 0.3, 0.0 ) );
		$this->set_state( $oca, $yacon, 'accepted' );
		$this->set_state( $oca, $mashua, 'vetoed' );

		undergrowth_write_memory( $oca );
		$file = (string) $this->read( $oca );

		$this->assertSame( array( '- ' . get_permalink( $yacon ) . ' | "Notes about Oca."' ), $this->section( $file, 'accepted links' ) );
		$this->assertSame( array( '- ' . get_permalink( $mashua ) ), $this->section( $file, 'vetoes' ) );
		$this->assertSame( array(), $this->section( $file, 'proposed links' ) );
	}

	public function test_unpublished_target_of_a_kept_link_is_named_without_its_address() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->set_state( $oca, $yacon, 'accepted' );
		$address = get_permalink( $yacon );

		wp_update_post(
			array(
				'ID'          => $yacon,
				'post_status' => 'private',
			)
		);
		$file = (string) $this->read( $oca );

		$this->assertSame( array( '- post ' . $yacon . ' (not published) | "Notes about Oca."' ), $this->section( $file, 'accepted links' ) );
		$this->assertStringNotContainsString( $address, $file );
	}

	public function test_deleted_target_of_a_veto_is_named_as_deleted() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->set_state( $oca, $yacon, 'vetoed' );
		undergrowth_write_memory( $oca );

		wp_delete_post( $yacon, true );

		$this->assertSame( array( '- post ' . $yacon . ' (deleted)' ), $this->section( (string) $this->read( $oca ), 'vetoes' ) );
	}

	public function test_file_follows_the_table_when_proposals_change() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		wp_delete_post( $yacon, true );

		$this->assertSame( array(), $this->section( (string) $this->read( $oca ), 'proposed links' ) );
	}

	public function test_file_holds_nothing_from_the_body_but_quoted_sentences() {
		$oca = $this->plant(
			'Oca',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => '<p>Notes about Oca. A private aside about the neighbours.</p>' )
		);
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$this->assertStringNotContainsString( 'private aside', (string) $this->read( $oca ) );
	}

	public function test_unpublishing_removes_the_file() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		wp_update_post(
			array(
				'ID'          => $oca,
				'post_status' => 'draft',
			)
		);

		$this->assertNull( $this->read( $oca ) );
	}

	public function test_deleting_a_post_removes_its_file() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		wp_delete_post( $oca, true );

		$this->assertNull( $this->read( $oca ) );
	}

	public function test_post_outside_the_index_gets_no_file() {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->assertFalse( undergrowth_write_memory( $draft ) );
		$this->assertNull( $this->read( $draft ) );
	}

	public function test_folder_refuses_web_access() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$root = dirname( undergrowth_memory_path( $oca ), 2 );

		$this->assertFileExists( $root . '/index.php' );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $root . '/.htaccess' ) );
	}

	public function test_without_direct_file_access_nothing_is_written_and_the_failure_is_recorded() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		undergrowth_delete_memory_folder();
		add_filter(
			'filesystem_method',
			static function () {
				return 'ftpext';
			}
		);

		$this->assertFalse( undergrowth_write_memory( $oca ) );
		$this->assertNull( $this->read( $oca ) );
		$this->assertSame( 'no_direct_access', get_option( 'undergrowth_last_file_error' )['code'] );
	}

	public function test_blocked_folder_is_recorded_and_nothing_is_written() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		undergrowth_delete_memory_folder();
		wp_mkdir_p( undergrowth_memory_root() );
		file_put_contents( undergrowth_memory_root() . '/posts', 'not a folder' );

		$this->assertFalse( undergrowth_write_memory( $oca ) );
		$this->assertSame( 'no_folder', get_option( 'undergrowth_last_file_error' )['code'] );
	}

	public function test_failed_write_is_recorded() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		undergrowth_delete_memory( $oca );
		wp_mkdir_p( undergrowth_memory_path( $oca ) );

		$this->assertFalse( undergrowth_write_memory( $oca ) );
		$this->assertSame( 'write_failed', get_option( 'undergrowth_last_file_error' )['code'] );
	}

	public function test_failed_protection_file_stops_the_write() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		undergrowth_delete_memory_folder();
		wp_mkdir_p( undergrowth_memory_root() . '/.htaccess' );

		$this->assertFalse( undergrowth_write_memory( $oca ) );
		$this->assertNull( $this->read( $oca ) );
		$this->assertSame( 'no_protection', get_option( 'undergrowth_last_file_error' )['code'] );
	}

	public function test_damaged_protection_file_is_restored() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$htaccess = undergrowth_memory_root() . '/.htaccess';
		file_put_contents( $htaccess, '' );

		$this->assertTrue( undergrowth_write_memory( $oca ) );
		$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $htaccess ) );
	}

	public function test_failed_repair_keeps_the_old_denial_rule_and_the_old_file() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$htaccess = undergrowth_memory_root() . '/.htaccess';
		$old_rule = "# Older rule\nRequire all denied\n";
		file_put_contents( $htaccess, $old_rule );
		$before = $this->read( $oca );
		wp_mkdir_p( $htaccess . '.tmp' );

		$this->assertFalse( undergrowth_write_memory( $oca ) );
		$this->assertSame( $old_rule, file_get_contents( $htaccess ) );
		$this->assertSame( $before, $this->read( $oca ) );
		$this->assertSame( 'no_protection', get_option( 'undergrowth_last_file_error' )['code'] );
	}

	public function test_short_write_during_repair_leaves_the_old_rule_and_no_temporary_file() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		wp_mkdir_p( undergrowth_memory_root() );
		$htaccess = undergrowth_memory_root() . '/.htaccess';
		$old_rule = "# Older rule\nRequire all denied\n";
		file_put_contents( $htaccess, $old_rule );

		// Writes half the contents, then reports failure, as a full disk would.
		$short_writer = new class( null ) extends WP_Filesystem_Direct {
			/**
			 * Writes a truncated file and reports failure.
			 *
			 * @param string    $file     Path.
			 * @param string    $contents Contents.
			 * @param int|false $mode     Permissions.
			 * @return bool
			 */
			public function put_contents( $file, $contents, $mode = false ) {
				file_put_contents( $file, substr( $contents, 0, 5 ) );
				return false;
			}
		};

		$this->assertFalse( undergrowth_write_protection_file( $short_writer, $htaccess, "Require all denied\n" ) );
		$this->assertSame( $old_rule, file_get_contents( $htaccess ) );
		$this->assertFileDoesNotExist( $htaccess . '.tmp' );
	}

	public function test_changing_only_the_slug_updates_the_file() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		wp_update_post(
			array(
				'ID'        => $oca,
				'post_name' => 'oca-in-winter',
			)
		);

		$this->assertStringContainsString( 'slug: oca-in-winter', (string) $this->read( $oca ) );
	}

	public function test_changing_a_targets_slug_updates_files_that_link_to_it() {
		$this->set_permalink_structure( '/%postname%/' );
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		wp_update_post(
			array(
				'ID'        => $yacon,
				'post_name' => 'yacon-syrup',
			)
		);

		$this->assertStringContainsString( '/yacon-syrup/', (string) $this->read( $oca ) );
	}

	public function test_update_time_is_iso_8601_in_utc() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$this->assertMatchesRegularExpression( '/^updated: \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/m', (string) $this->read( $oca ) );
	}

	public function test_uninstall_removes_the_folder() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$root = dirname( undergrowth_memory_path( $oca ), 2 );

		undergrowth_uninstall_site();

		$this->assertDirectoryDoesNotExist( $root );
	}

	public function test_reading_a_file_returns_its_stage_and_history() {
		$file = implode(
			"\n",
			array(
				'# Oca',
				'slug: oca',
				'',
				'## stage',
				'composting',
				'- 2024-03-01: seedling -> sprout (monthly cycle)',
				'- 2025-11-02: sprout -> composting (composted by the author)',
				'',
				'## resurfacing',
				'- 2026-04-05: signal shown',
				'- 2026-04-06: signal dismissed by the author',
				'',
				'## has',
				'',
			)
		);

		$this->assertSame(
			array(
				'stage'       => 'composting',
				'pinned'      => false,
				'changes'     => array(
					array(
						'time'   => gmmktime( 0, 0, 0, 3, 1, 2024 ),
						'from'   => 'seedling',
						'to'     => 'sprout',
						'reason' => 'cycle',
					),
					array(
						'time'   => gmmktime( 0, 0, 0, 11, 2, 2025 ),
						'from'   => 'sprout',
						'to'     => 'composting',
						'reason' => 'compost',
					),
				),
				'resurfacing' => array(
					array(
						'time'  => gmmktime( 0, 0, 0, 4, 5, 2026 ),
						'event' => 'shown',
					),
					array(
						'time'  => gmmktime( 0, 0, 0, 4, 6, 2026 ),
						'event' => 'dismissed',
					),
				),
			),
			undergrowth_read_memory( $file )
		);
	}

	public function test_reading_a_file_reads_the_pin_marker() {
		$read = undergrowth_read_memory( "## stage\nevergreen (pinned)\n" );

		$this->assertSame( 'evergreen', $read['stage'] );
		$this->assertTrue( $read['pinned'] );
	}

	public function test_reading_a_file_skips_lines_the_owner_added_or_broke() {
		$file = implode(
			"\n",
			array(
				'## stage',
				'  composting  ',
				'Resting this one for a while.',
				'- 2025-02-30: sprout -> composting (composted by the author)',
				'- 2025-11-02: sprout -> wilted (composted by the author)',
				'- 2025-11-02: sprout -> composting (because I said so)',
				'- 2025-11-02: sprout -> composting (composted by the author)',
				'## resurfacing',
				'- 2026-04-05: signal snoozed',
				'- someday: signal shown',
			)
		);

		$read = undergrowth_read_memory( $file );

		$this->assertSame( 'composting', $read['stage'] );
		$this->assertSame( array( 'compost' ), wp_list_pluck( $read['changes'], 'reason' ) );
		$this->assertSame( array(), $read['resurfacing'] );
	}

	public function test_reading_a_file_with_no_stage_returns_null() {
		$this->assertNull( undergrowth_read_memory( "# Oca\n\n## has\n" )['stage'] );
		$this->assertNull( undergrowth_read_memory( "## stage\nwilting\n" )['stage'] );
	}

	public function test_composted_file_imports_onto_another_post_still_composted() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->plant( 'Mashua', array( 0.8, 0.3, 0.0 ) );
		undergrowth_compost_post( $oca );
		undergrowth_log_resurfacing( $oca, 'shown' );
		undergrowth_log_resurfacing( $oca, 'dismissed' );
		$file = (string) $this->read( $oca );

		$this->assertNotEmpty( $this->proposed( $yacon ) );

		$this->assertTrue( undergrowth_import_memory( $yacon, $file ) );

		$this->assertSame( 'composting', undergrowth_get_stage( $yacon ) );
		$this->assertFalse( undergrowth_is_stage_pinned( $yacon ) );
		$this->assertSame( array( 'compost' ), wp_list_pluck( undergrowth_stage_log( $yacon ), 'reason' ) );
		$this->assertSame( array( 'shown', 'dismissed' ), wp_list_pluck( undergrowth_resurfacing_log( $yacon ), 'event' ) );
		$this->assertSame( array(), $this->proposed( $yacon ) );
		$this->assertSame( $this->section( $file, 'stage' ), $this->section( (string) $this->read( $yacon ), 'stage' ) );
		$this->assertSame( $this->section( $file, 'resurfacing' ), $this->section( (string) $this->read( $yacon ), 'resurfacing' ) );
	}

	public function test_importing_the_same_file_twice_adds_no_duplicate_history() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		undergrowth_compost_post( $oca );
		undergrowth_log_resurfacing( $oca, 'shown' );
		$file = (string) $this->read( $oca );

		undergrowth_import_memory( $oca, $file );
		undergrowth_import_memory( $oca, $file );

		$this->assertCount( 1, undergrowth_stage_log( $oca ) );
		$this->assertCount( 1, undergrowth_resurfacing_log( $oca ) );
	}

	public function test_import_restores_a_pin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		$this->assertTrue( undergrowth_import_memory( $oca, "## stage\nevergreen (pinned)\n- 2025-01-01: seedling -> evergreen (pinned by the author)\n" ) );

		$this->assertSame( 'evergreen', undergrowth_get_stage( $oca ) );
		$this->assertTrue( undergrowth_is_stage_pinned( $oca ) );
	}

	public function test_import_of_a_returned_post_brings_a_composting_post_back() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		undergrowth_compost_post( $yacon );

		$this->assertTrue( undergrowth_import_memory( $yacon, "## stage\nsprout\n" ) );

		$this->assertSame( 'sprout', undergrowth_get_stage( $yacon ) );
		$this->assertContains( $oca, $this->proposed( $yacon ) );
	}

	public function test_import_of_a_file_with_no_stage_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		$result = undergrowth_import_memory( $oca, "# Oca\n" );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_no_stage', $result->get_error_code() );
		$this->assertFalse( metadata_exists( 'post', $oca, 'undergrowth_stage' ) );
	}

	public function test_import_needs_permission_to_edit_the_post() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		$result = undergrowth_import_memory( $oca, "## stage\ncomposting\n" );

		$this->assertWPError( $result );
		$this->assertSame( 'seedling', undergrowth_get_stage( $oca ) );
	}
}
