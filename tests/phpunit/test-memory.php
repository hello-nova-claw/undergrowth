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

		// The questions are written only once visitors are known to be refused.
		update_option( 'undergrowth_memory_exposed', array( 'exposed' => false ), false );
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

	public function test_file_lives_in_a_folder_with_a_random_name_in_the_sites_uploads_folder() {
		$uploads = wp_upload_dir();

		$this->assertMatchesRegularExpression( '#^' . preg_quote( $uploads['basedir'], '#' ) . '/undergrowth-[0-9a-f]{12}/posts/42\.txt$#D', undergrowth_memory_path( 42 ) );
	}

	public function test_folder_name_is_made_once_and_kept() {
		$folder = undergrowth_memory_folder();

		$this->assertSame( $folder, get_option( 'undergrowth_memory_folder' ) );
		$this->assertSame( $folder, undergrowth_memory_folder() );
	}

	public function test_each_site_gets_its_own_folder_name() {
		$first = undergrowth_memory_folder();
		delete_option( 'undergrowth_memory_folder' );

		$this->assertNotSame( $first, undergrowth_memory_folder() );
	}

	public function test_a_stored_folder_name_that_is_not_one_of_ours_is_replaced() {
		update_option( 'undergrowth_memory_folder', '../../wp-content' );

		$this->assertMatchesRegularExpression( '/^undergrowth-[0-9a-f]{12}$/D', undergrowth_memory_folder() );
	}

	public function test_posts_folder_has_its_own_index_file() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$this->assertTrue( undergrowth_write_memory( $oca ) );
		$this->assertFileExists( undergrowth_memory_root() . '/posts/index.php' );
	}

	public function test_writing_a_file_reads_no_stored_vectors() {
		$ids = array();

		for ( $i = 0; $i < 10; $i++ ) {
			$ids[] = $this->plant( 'Root ' . $i, array( 1.0, $i / 10, 0.0 ) );
		}

		$queries = array();
		$record  = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;

			return $query;
		};

		add_filter( 'query', $record );
		$written = undergrowth_write_memory( $ids[0] );
		remove_filter( 'query', $record );

		$this->assertTrue( $written );

		$table   = undergrowth_vectors_table();
		$selects = array_values(
			array_filter(
				$queries,
				static function ( $query ) use ( $table ) {
					return false !== strpos( $query, $table ) && preg_match( '/^\s*SELECT\b/i', $query );
				}
			)
		);
		$reads   = array_filter(
			$selects,
			static function ( $query ) {
				return preg_match( '/^\s*SELECT\b.*(\*|\bembedding\b).*\bFROM\b/is', $query );
			}
		);

		$this->assertSame( array(), array_values( $reads ) );

		// A query per comparable post would grow with the ten planted here.
		$this->assertLessThanOrEqual( 3, count( $selects ), implode( "\n", $selects ) );
	}

	public function test_files_at_the_old_address_move_to_the_new_folder() {
		$uploads = wp_upload_dir();
		$legacy  = $uploads['basedir'] . '/undergrowth';
		wp_mkdir_p( $legacy . '/posts' );
		file_put_contents( $legacy . '/posts/7.txt', 'notes' );
		delete_option( 'undergrowth_memory_folder' );

		undergrowth_prepare_memory_folder();

		$this->assertDirectoryDoesNotExist( $legacy );
		$this->assertSame( 'notes', file_get_contents( undergrowth_memory_root() . '/posts/7.txt' ) );
	}

	public function test_old_folder_is_removed_when_it_cannot_be_moved() {
		// The name is made first, so making it does not move the old folder.
		$root    = undergrowth_memory_root();
		$uploads = wp_upload_dir();
		$legacy  = $uploads['basedir'] . '/undergrowth';
		wp_mkdir_p( $root . '/posts' );
		file_put_contents( $root . '/posts/8.txt', 'newer' );
		wp_mkdir_p( $legacy . '/posts' );
		file_put_contents( $legacy . '/posts/7.txt', 'notes' );

		undergrowth_move_legacy_memory_folder( $root );

		$this->assertDirectoryDoesNotExist( $legacy );
		$this->assertSame( 'newer', file_get_contents( $root . '/posts/8.txt' ) );
		$this->assertFileDoesNotExist( $root . '/posts/7.txt' );
	}

	public function test_nothing_moves_or_is_written_when_the_folder_name_cannot_be_stored() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		undergrowth_delete_memory_folder();
		delete_option( 'undergrowth_memory_folder' );
		$uploads = wp_upload_dir();
		$legacy  = $uploads['basedir'] . '/undergrowth';
		wp_mkdir_p( $legacy . '/posts' );
		$before = scandir( $uploads['basedir'] );
		add_filter(
			'query',
			static function ( $query ) {
				return false !== strpos( $query, 'undergrowth_memory_folder' ) && 0 === stripos( ltrim( $query ), 'INSERT' ) ? 'SELECT 1' : $query;
			}
		);

		$this->assertSame( '', undergrowth_memory_folder() );
		$this->assertFalse( undergrowth_write_memory( $oca ) );
		$this->assertSame( 'no_folder', get_option( 'undergrowth_last_file_error' )['code'] );
		$this->assertDirectoryExists( $legacy . '/posts' );
		$this->assertSame( $before, scandir( $uploads['basedir'] ) );
		$this->assertSame( '', undergrowth_memory_path( $oca ) );
	}

	public function test_a_name_stored_first_by_another_request_is_used_and_nothing_is_moved() {
		global $wpdb;

		delete_option( 'undergrowth_memory_folder' );
		$uploads = wp_upload_dir();
		$legacy  = $uploads['basedir'] . '/undergrowth';
		wp_mkdir_p( $legacy . '/posts' );
		get_option( 'undergrowth_memory_folder' );

		// Another request stores its name after this one found none.
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => 'undergrowth_memory_folder',
				'option_value' => 'undergrowth-aaaaaaaaaaaa',
				'autoload'     => 'on',
			)
		);

		$this->assertSame( 'undergrowth-aaaaaaaaaaaa', undergrowth_memory_folder() );
		$this->assertSame( 'undergrowth-aaaaaaaaaaaa', get_option( 'undergrowth_memory_folder' ) );
		$this->assertDirectoryExists( $legacy . '/posts' );
	}

	public function test_a_moved_folder_gets_an_index_file_in_posts_at_once() {
		$uploads = wp_upload_dir();
		$legacy  = $uploads['basedir'] . '/undergrowth';
		wp_mkdir_p( $legacy . '/posts' );
		delete_option( 'undergrowth_memory_folder' );

		undergrowth_prepare_memory_folder();

		$this->assertFileExists( undergrowth_memory_root() . '/posts/index.php' );
	}

	public function test_an_old_folder_left_behind_is_removed_on_a_later_request() {
		$root    = undergrowth_memory_root();
		$uploads = wp_upload_dir();
		$legacy  = $uploads['basedir'] . '/undergrowth';
		wp_mkdir_p( $legacy . '/posts' );
		file_put_contents( $legacy . '/posts/7.txt', 'notes' );

		undergrowth_prepare_memory_folder();

		$this->assertDirectoryDoesNotExist( $legacy );
		$this->assertDirectoryExists( $root );
	}

	public function test_an_old_folder_that_cannot_be_removed_is_not_retried_on_every_request() {
		$root    = undergrowth_memory_root();
		$uploads = wp_upload_dir();
		$legacy  = $uploads['basedir'] . '/undergrowth';
		wp_mkdir_p( $root . '/posts' );
		wp_mkdir_p( $legacy . '/posts' );
		$checks = 0;
		$ftp    = static function () use ( &$checks ) {
			++$checks;
			return 'ftpext';
		};
		add_filter( 'filesystem_method', $ftp );

		undergrowth_prepare_memory_folder();
		$this->assertSame( 'old_folder_left', get_option( 'undergrowth_last_file_error' )['code'] );

		update_option(
			'undergrowth_last_file_error',
			array(
				'code' => 'old_folder_left',
				'time' => 1,
			)
		);
		$before = $checks;
		undergrowth_prepare_memory_folder();
		$this->assertSame( $before, $checks, 'Within the hour, the filesystem is not even probed.' );

		delete_transient( 'undergrowth_old_folder_retry' );
		undergrowth_prepare_memory_folder();
		$this->assertGreaterThan( $before, $checks, 'After the hour, it is tried again.' );

		remove_filter( 'filesystem_method', $ftp );

		$this->assertSame( 1, get_option( 'undergrowth_last_file_error' )['time'], 'The same error is not written again.' );
		$this->assertDirectoryExists( $legacy );
	}

	public function test_a_name_stored_by_another_request_in_place_of_a_bad_one_is_kept() {
		global $wpdb;

		update_option( 'undergrowth_memory_folder', 'not-ours' );
		get_option( 'undergrowth_memory_folder' );

		// Another request replaced the bad value after this one read it.
		$wpdb->update( $wpdb->options, array( 'option_value' => 'undergrowth-bbbbbbbbbbbb' ), array( 'option_name' => 'undergrowth_memory_folder' ) );

		$this->assertSame( 'undergrowth-bbbbbbbbbbbb', undergrowth_memory_folder() );
	}

	public function test_preparing_the_folder_again_leaves_it_alone() {
		$folder = undergrowth_memory_folder();

		undergrowth_prepare_memory_folder();

		$this->assertSame( $folder, undergrowth_memory_folder() );
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

		$this->assertSame( array( 'stage', 'resurfacing', 'has', 'lacks', 'scent', 'proposed links', 'accepted links', 'vetoes', 'prompts' ), $headings[1] );
	}

	public function test_stage_section_names_a_seedling_with_no_changes() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$this->assertSame( array( 'seedling' ), $this->section( (string) $this->read( $oca ), 'stage' ) );
	}

	/**
	 * The file is the same wherever the site is: its dates are UTC days, not
	 * the site's.
	 */
	public function test_dates_in_the_file_are_utc_days_whatever_the_site_timezone() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		update_option( 'timezone_string', 'Pacific/Auckland' );

		// 23:30 UTC on 1 January is already 2 January in Auckland.
		add_post_meta(
			$oca,
			'undergrowth_stage_log',
			array(
				'time'   => 1767310200,
				'from'   => 'seedling',
				'to'     => 'sprout',
				'reason' => 'cycle',
			)
		);
		undergrowth_write_memory( $oca );
		$file = (string) $this->read( $oca );

		$this->assertContains( '- 2026-01-01: seedling -> sprout (monthly cycle)', $this->section( $file, 'stage' ) );
		$this->assertMatchesRegularExpression( '/^updated: \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/m', $file );
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

		// Oca picks a replacement in cron, which writes its file again.
		do_action( 'undergrowth_refresh_sources', array( $oca ) );

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

	public function test_has_section_lists_the_words_and_links_the_has_ability_returns() {
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon roots keep in sand.</p>' ) );
		$oca   = $this->plant(
			'Oca',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => '<p>Oca tubers sweeten after frost. Tubers keep in sand, like <a href="' . get_permalink( $yacon ) . '">yacon</a>.</p>' )
		);

		undergrowth_write_memory( $oca );
		$has = undergrowth_ability_has( get_post( $oca ) );

		$this->assertContains( 'tubers', $has['words'] );
		$this->assertSame(
			array(
				'words: ' . implode( ', ', $has['words'] ),
				'- ' . get_permalink( $yacon ),
			),
			$this->section( (string) $this->read( $oca ), 'has' )
		);
	}

	public function test_lacks_section_lists_the_suggestions_the_lacks_ability_returns() {
		$oca    = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca grows in the Andes. Like yacon and mashua, it is a tuber.</p>' ) );
		$yacon  = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon is a sweet tuber.</p>' ) );
		$mashua = $this->plant( 'Mashua', array( 0.7, 0.3, 0.0 ), array( 'post_content' => '<p>Mashua is a peppery tuber.</p>' ) );

		$lacks = undergrowth_ability_lacks( get_post( $oca ) );
		$lines = array();

		foreach ( $lacks['links'] as $link ) {
			$lines[] = '- ' . $link['url'] . ' | "' . $link['sentence'] . '"';
		}

		$this->assertSame( array( $yacon, $mashua ), array_column( $lacks['links'], 'post_id' ) );
		$this->assertSame( $lines, $this->section( (string) $this->read( $oca ), 'lacks' ) );
	}

	public function test_lacks_section_leaves_out_accepted_and_vetoed_links() {
		$oca    = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon  = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$mashua = $this->plant( 'Mashua', array( 0.8, 0.3, 0.0 ) );
		$this->set_state( $oca, $yacon, 'accepted' );
		$this->set_state( $oca, $mashua, 'vetoed' );

		undergrowth_write_memory( $oca );

		$this->assertSame( array(), $this->section( (string) $this->read( $oca ), 'lacks' ) );
	}

	public function test_has_section_follows_a_new_link_in_the_post() {
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		$this->assertNotContains( '- ' . get_permalink( $yacon ), $this->section( (string) $this->read( $oca ), 'has' ) );

		wp_update_post(
			array(
				'ID'           => $oca,
				'post_content' => 'Notes about Oca, and <a href="' . get_permalink( $yacon ) . '">yacon</a>.',
			)
		);

		$this->assertContains( '- ' . get_permalink( $yacon ), $this->section( (string) $this->read( $oca ), 'has' ) );
	}

	public function test_has_and_lacks_are_read_from_the_post_with_suggestions_off() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca grows in the Andes. Like yacon, it is a tuber.</p>' ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon is a sweet tuber.</p>' ) );
		update_option( 'undergrowth_suggest_links', '0' );

		$this->assertNotSame( array(), $this->section( (string) $this->read( $oca ), 'lacks' ) );

		wp_update_post(
			array(
				'ID'           => $oca,
				'post_content' => '<p>Oca grows high in the Andes.</p>',
			)
		);

		$this->assertSame( array(), $this->section( (string) $this->read( $oca ), 'lacks' ) );
	}

	public function test_monthly_stage_pass_rewrites_every_file() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca tubers sweeten after frost.</p>' ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon roots keep in sand.</p>' ) );
		undergrowth_set_stage( $oca, 'sprout' );

		// Another post's words change how distinctive this post's words are,
		// without anything that would rewrite this post's file.
		file_put_contents( undergrowth_memory_path( $oca ), 'stale' );
		file_put_contents( undergrowth_memory_path( $yacon ), 'stale' );

		undergrowth_run_stage_cycle( 0 );

		$undated = static function ( $text ) {
			return preg_replace( '/^updated: .*$/m', '', (string) $text );
		};

		foreach ( array( $oca, $yacon ) as $post_id ) {
			$this->assertSame( $undated( undergrowth_memory_text( get_post( $post_id ) ) ), $undated( $this->read( $post_id ) ) );
		}
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
}
