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

		$this->assertSame( array( 'stage', 'has', 'lacks', 'scent', 'proposed links', 'accepted links', 'vetoes' ), $headings[1] );
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
