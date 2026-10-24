<?php
/**
 * Tests for the check that the per-post folder cannot be read from the web.
 *
 * @package Undergrowth
 */

/**
 * The folder is protected by files some servers ignore, so Undergrowth asks
 * for a file over the site's own address. While it can be read, the
 * questions Claude wrote are left out of every file.
 */
class Test_Memory_Folder_Check extends WP_UnitTestCase {

	use Plants_Posts;

	/**
	 * How the fake web server answers: 'serve' reads the file from disk,
	 * 'deny' answers 403 inside the plugin's folder and serves the rest,
	 * 'deny all' answers 403 everywhere, 'fail' gives no response, and a
	 * number answers with that status.
	 *
	 * @var string
	 */
	private $server = 'deny';

	/**
	 * Addresses the fake web server was asked for.
	 *
	 * @var string[]
	 */
	private $asked = array();

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		$this->server = 'deny';
		$this->asked  = array();
		delete_option( 'undergrowth_memory_exposed' );
		add_filter( 'pre_http_request', array( $this, 'fake_server' ), 10, 3 );
	}

	public function tear_down() {
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Answers requests for the uploads folder as a web server would.
	 *
	 * @param false|array $preempt Earlier response.
	 * @param array       $args    Request arguments.
	 * @param string      $url     Request URL.
	 * @return array|WP_Error|false
	 */
	public function fake_server( $preempt, $args, $url ) {
		$uploads = wp_upload_dir( null, false );

		if ( 0 !== strpos( $url, $uploads['baseurl'] ) ) {
			return $preempt;
		}

		$this->asked[] = $url;

		if ( 'fail' === $this->server ) {
			return new WP_Error( 'http_request_failed', 'Could not connect.' );
		}

		$path   = $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) );
		$denied = 'deny all' === $this->server || ( 'deny' === $this->server && false !== strpos( $url, '/' . undergrowth_memory_folder() . '/' ) );
		$served = in_array( $this->server, array( 'serve', 'deny' ), true ) && ! $denied && is_file( $path );
		$status = ctype_digit( $this->server ) ? (int) $this->server : 403;

		return array(
			'headers'  => array(),
			'body'     => $served ? (string) file_get_contents( $path ) : 'Not here',
			'response' => array(
				'code'    => $served ? 200 : $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Publishes a post and writes its file, so the folder exists.
	 *
	 * @return int Post ID.
	 */
	private function written(): int {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->assertTrue( undergrowth_write_memory( $oca ) );

		return $oca;
	}

	public function test_folder_holds_a_web_config_that_denies_access() {
		$this->written();

		$config = (string) file_get_contents( undergrowth_memory_root() . '/web.config' );

		$this->assertStringContainsString( '<add accessType="Deny" users="*" />', $config );
	}

	public function test_folder_a_server_serves_is_found_readable() {
		$this->written();
		$this->server = 'serve';

		$this->assertTrue( undergrowth_check_memory_folder() );
		$this->assertTrue( undergrowth_memory_exposed() );
		$this->assertCount( 2, $this->asked );
		$this->assertStringStartsWith( wp_upload_dir( null, false )['baseurl'] . '/' . undergrowth_memory_folder() . '/posts/', $this->asked[1] );
	}

	public function test_folder_a_server_refuses_is_not_readable() {
		$this->written();

		$this->assertFalse( undergrowth_check_memory_folder() );
		$this->assertFalse( undergrowth_memory_exposed() );
	}

	public function test_check_leaves_no_file_behind() {
		$this->written();
		$this->server = 'serve';

		undergrowth_check_memory_folder();

		$this->assertSame( array(), glob( undergrowth_memory_root() . '/posts/undergrowth-check-*' ) );
		$this->assertSame( array(), glob( wp_upload_dir( null, false )['basedir'] . '/undergrowth-check-*' ) );
	}

	public function test_check_that_gets_no_answer_forgets_that_the_folder_was_refused() {
		$oca = $this->written();
		undergrowth_check_memory_folder();
		$this->assertTrue( undergrowth_memory_private() );

		$this->server = 'fail';

		$this->assertNull( undergrowth_check_memory_folder() );
		$this->assertFalse( undergrowth_memory_private() );
		$this->assertFalse( undergrowth_memory_exposed() );

		undergrowth_write_memory( $oca );
		$this->assertStringNotContainsString( '## prompts', (string) file_get_contents( undergrowth_memory_path( $oca ) ) );
	}

	public function test_nothing_is_asked_before_the_folder_exists() {
		$this->assertNull( undergrowth_check_memory_folder() );
		$this->assertSame( array(), $this->asked );
	}

	public function test_hourly_check_runs_the_folder_check() {
		$this->assertSame( 10, has_action( 'undergrowth_backfill_check', 'undergrowth_recheck_memory_folder' ) );
	}

	public function test_questions_are_left_out_until_a_check_has_answered() {
		$oca = $this->written();

		$this->assertStringNotContainsString( '## prompts', (string) file_get_contents( undergrowth_memory_path( $oca ) ) );
		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_memory_folder_check' ) );
		$this->assertSame( array(), $this->asked );

		do_action( 'undergrowth_memory_folder_check' );
		undergrowth_write_memory( $oca );

		$this->assertStringContainsString( "\n## prompts", (string) file_get_contents( undergrowth_memory_path( $oca ) ) );
	}

	public function test_questions_are_left_out_while_the_folder_can_be_read() {
		$oca = $this->written();
		undergrowth_check_memory_folder();
		undergrowth_write_memory( $oca );
		$this->assertStringContainsString( "\n## prompts", (string) file_get_contents( undergrowth_memory_path( $oca ) ) );

		$this->server = 'serve';
		undergrowth_check_memory_folder();

		// Files already written lose the section at once.
		$file = (string) file_get_contents( undergrowth_memory_path( $oca ) );
		$this->assertStringNotContainsString( '## prompts', $file );
		$this->assertStringContainsString( "## vetoes\n", $file );

		undergrowth_write_memory( $oca );
		$this->assertStringNotContainsString( '## prompts', (string) file_get_contents( undergrowth_memory_path( $oca ) ) );
	}

	public function test_questions_are_taken_out_again_on_each_check_while_the_folder_can_be_read() {
		$oca          = $this->written();
		$this->server = 'serve';
		undergrowth_check_memory_folder();

		// As if taking them out had failed last time.
		file_put_contents( undergrowth_memory_path( $oca ), "## prompts\n- a question\n", FILE_APPEND );
		undergrowth_check_memory_folder();

		$this->assertStringNotContainsString( '## prompts', (string) file_get_contents( undergrowth_memory_path( $oca ) ) );
	}

	/**
	 * Answers that say nothing about what a visitor would get.
	 *
	 * @return array<string, string[]>
	 */
	public function data_answers_that_prove_nothing() {
		return array(
			'redirect'      => array( '301' ),
			'password'      => array( '401' ),
			'server error'  => array( '500' ),
			'other content' => array( '200' ),
		);
	}

	/**
	 * An answer that proves nothing leaves the questions out.
	 *
	 * @dataProvider data_answers_that_prove_nothing
	 *
	 * @param string $status Status the server answers with.
	 */
	public function test_an_answer_that_proves_nothing_keeps_the_questions_out( $status ) {
		$oca          = $this->written();
		$this->server = $status;

		$this->assertNull( undergrowth_check_memory_folder() );
		$this->assertFalse( undergrowth_memory_exposed() );
		$this->assertFalse( undergrowth_memory_private() );

		undergrowth_write_memory( $oca );
		$this->assertStringNotContainsString( '## prompts', (string) file_get_contents( undergrowth_memory_path( $oca ) ) );
	}

	public function test_a_site_that_refuses_every_upload_proves_nothing() {
		$this->written();
		$this->server = 'deny all';

		$this->assertNull( undergrowth_check_memory_folder() );
		$this->assertCount( 1, $this->asked );
		$this->assertFalse( undergrowth_memory_private() );
	}

	public function test_first_check_that_proves_nothing_takes_the_questions_out_of_existing_files() {
		$oca = $this->written();
		file_put_contents( undergrowth_memory_path( $oca ), "\n## prompts\n- a question\n", FILE_APPEND );
		$this->server = '301';

		$this->assertNull( undergrowth_check_memory_folder() );
		$this->assertStringNotContainsString( '## prompts', (string) file_get_contents( undergrowth_memory_path( $oca ) ) );
	}

	public function test_site_health_says_when_it_could_not_tell() {
		$this->written();
		$this->server = 'fail';

		$tests  = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$result = call_user_func( $tests['direct']['undergrowth_memory_folder']['test'] );

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'could not check', $result['label'] );
	}

	public function test_uploads_on_another_host_are_not_asked_for() {
		$this->written();
		add_filter(
			'upload_dir',
			static function ( $dirs ) {
				$dirs['baseurl'] = 'https://cdn.example.net/uploads';
				return $dirs;
			}
		);

		$this->assertNull( undergrowth_check_memory_folder() );
		$this->assertSame( array(), $this->asked );
		$this->assertFalse( undergrowth_memory_private() );
	}

	public function test_questions_return_once_the_folder_is_refused_again() {
		$oca          = $this->written();
		$this->server = 'serve';
		undergrowth_check_memory_folder();

		$this->server = 'deny';
		undergrowth_check_memory_folder();
		undergrowth_write_memory( $oca );

		$this->assertStringContainsString( "\n## prompts", (string) file_get_contents( undergrowth_memory_path( $oca ) ) );
	}

	public function test_site_health_warns_with_the_deny_rules_while_the_folder_can_be_read() {
		$this->written();
		$this->server = 'serve';

		$tests  = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$result = call_user_func( $tests['direct']['undergrowth_memory_folder']['test'] );

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'deny all;', $result['description'] );
		$this->assertStringContainsString( 'respond @undergrowth 403', $result['description'] );
		$this->assertStringContainsString( 'docs/per-post-files.md', $result['actions'] );
		$this->assertTrue( undergrowth_memory_exposed() );
	}

	public function test_site_health_passes_while_the_folder_is_refused() {
		$this->written();

		$tests  = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$result = call_user_func( $tests['direct']['undergrowth_memory_folder']['test'] );

		$this->assertSame( 'good', $result['status'] );
	}

	public function test_settings_warn_beside_the_folder_while_it_can_be_read() {
		$this->written();
		$this->server = 'serve';
		undergrowth_check_memory_folder();

		ob_start();
		undergrowth_render_memory_folder_field();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="undergrowth-memory-exposed"', $html );
	}

	public function test_settings_say_nothing_more_while_the_folder_is_refused() {
		$this->written();
		undergrowth_check_memory_folder();

		ob_start();
		undergrowth_render_memory_folder_field();

		$this->assertStringNotContainsString( 'undergrowth-memory-exposed', (string) ob_get_clean() );
	}

	public function test_uninstall_forgets_the_result() {
		update_option( 'undergrowth_memory_exposed', array( 'exposed' => true ) );

		undergrowth_uninstall_site();

		$this->assertFalse( get_option( 'undergrowth_memory_exposed' ) );
	}
}
