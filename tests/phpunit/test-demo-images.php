<?php
/**
 * Tests for serving the demo blog's illustrations from the plugin's own copy
 * while the WordPress Importer runs.
 *
 * @package Undergrowth
 */

/**
 * The importer streams each attachment to a temporary file. The plugin
 * answers the download from demo/images/ when it has the file, and leaves
 * every other request alone.
 */
class Test_Demo_Images extends WP_UnitTestCase {

	const BASE = 'https://raw.githubusercontent.com/hello-nova-claw/undergrowth/main/demo/images/';

	/**
	 * The importer's temporary file.
	 *
	 * @var string
	 */
	private $tmp = '';

	/**
	 * URLs that reached the stand-in for the network.
	 *
	 * @var string[]
	 */
	private $reached = array();

	public function set_up() {
		parent::set_up();

		$this->tmp     = wp_tempnam( 'birds-1.jpg' );
		$this->reached = array();
		add_filter( 'pre_http_request', array( $this, 'network' ), 20, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'network' ), 20 );
		do_action( 'import_end' );

		if ( file_exists( $this->tmp ) ) {
			unlink( $this->tmp );
		}

		parent::tear_down();
	}

	/**
	 * Stands in for the network, after the plugin's filter: records the URL
	 * and answers 404, as GitHub does for a file it lacks.
	 *
	 * @param false|array<string, mixed>|WP_Error $preempt Short-circuit value.
	 * @param array<string, mixed>                $args    Request arguments.
	 * @param string                              $url     Request URL.
	 * @return array<string, mixed>|WP_Error
	 */
	public function network( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}

		$this->reached[] = $url;

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 404,
				'message' => 'Not Found',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Downloads a file as the importer does.
	 *
	 * @param string $url Address to fetch.
	 * @return array<string, mixed>|WP_Error
	 */
	private function download( string $url ) {
		return wp_safe_remote_get(
			$url,
			array(
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $this->tmp,
				'headers'  => array( 'Accept-Encoding' => 'identity' ),
			)
		);
	}

	public function test_images_dir_is_the_plugin_copy() {
		$dir = undergrowth_demo_images_dir();

		$this->assertSame( untrailingslashit( plugin_dir_path( UNDERGROWTH_FILE ) ) . '/demo/images', $dir );
		$this->assertFileExists( $dir . '/birds-1.jpg' );
	}

	public function test_path_finds_a_file_under_the_base() {
		$dir = undergrowth_demo_images_dir();

		$this->assertSame( $dir . '/birds-1.jpg', undergrowth_demo_image_path( $dir, self::BASE . 'birds-1.jpg' ) );
	}

	/**
	 * Addresses that name a demo illustration, by its path in the repository.
	 *
	 * @dataProvider urls_that_name_an_illustration
	 *
	 * @param string $url Address requested.
	 */
	public function test_name_is_the_file_under_the_repository_path( string $url ) {
		$this->assertSame( 'birds-1.jpg', undergrowth_demo_image_name( $url ) );
	}

	/**
	 * The address the WXR files give, the same file on another branch, and
	 * the same path served by another host.
	 *
	 * @return array<string, array{string}>
	 */
	public function urls_that_name_an_illustration(): array {
		return array(
			'main'           => array( self::BASE . 'birds-1.jpg' ),
			'another branch' => array( 'https://raw.githubusercontent.com/hello-nova-claw/undergrowth/602-images-fallback/demo/images/birds-1.jpg' ),
			'another host'   => array( 'https://example.com/hello-nova-claw/undergrowth/main/demo/images/birds-1.jpg' ),
			'a query string' => array( self::BASE . 'birds-1.jpg?raw=true' ),
			'encoded name'   => array( self::BASE . 'birds%2D1.jpg' ),
		);
	}

	/**
	 * Addresses that do not name a demo illustration.
	 *
	 * @dataProvider urls_that_name_no_illustration
	 *
	 * @param string $url Address requested.
	 */
	public function test_name_is_empty_off_the_repository_path( string $url ) {
		$this->assertSame( '', undergrowth_demo_image_name( $url ) );
	}

	/**
	 * Another folder of the repository, another repository, a folder above
	 * the images, and the base alone.
	 *
	 * @return array<string, array{string}>
	 */
	public function urls_that_name_no_illustration(): array {
		return array(
			'another folder'     => array( 'https://raw.githubusercontent.com/hello-nova-claw/undergrowth/main/demo/undergrowth-demo.xml' ),
			'another repository' => array( 'https://raw.githubusercontent.com/example/undergrowth/main/demo/images/birds-1.jpg' ),
			'parent folder'      => array( self::BASE . '../../undergrowth.php' ),
			'the base alone'     => array( self::BASE ),
			'no path'            => array( 'https://example.com' ),
		);
	}

	/**
	 * Addresses the plugin has no file for, each left to the network.
	 *
	 * @dataProvider urls_with_no_file
	 *
	 * @param string $url Address requested.
	 */
	public function test_path_is_empty_when_there_is_no_file( string $url ) {
		$this->assertSame( '', undergrowth_demo_image_path( undergrowth_demo_images_dir(), $url ) );
	}

	/**
	 * Addresses under the base with no file, and one off the repository path.
	 *
	 * @return array<string, array{string}>
	 */
	public function urls_with_no_file(): array {
		return array(
			'missing name'   => array( self::BASE . 'nothing-here.jpg' ),
			'another path'   => array( 'https://example.com/demo/images/birds-1.jpg' ),
			'parent folder'  => array( self::BASE . '../../undergrowth.php' ),
			'the base alone' => array( self::BASE ),
		);
	}

	public function test_path_is_empty_without_a_folder() {
		$this->assertSame( '', undergrowth_demo_image_path( '', self::BASE . 'birds-1.jpg' ) );
	}

	public function test_response_streams_the_file_with_its_type_and_length() {
		$path     = undergrowth_demo_images_dir() . '/birds-1.jpg';
		$response = undergrowth_demo_image_response( $path, array( 'filename' => $this->tmp ) );

		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( $this->tmp, $response['filename'] );
		$this->assertSame( 'image/jpeg', wp_remote_retrieve_header( $response, 'content-type' ) );
		$this->assertSame( (string) filesize( $path ), wp_remote_retrieve_header( $response, 'content-length' ) );
		$this->assertSame( file_get_contents( $path ), file_get_contents( $this->tmp ) );
	}

	public function test_response_is_404_when_the_copy_cannot_be_written() {
		$path     = undergrowth_demo_images_dir() . '/birds-1.jpg';
		$response = undergrowth_demo_image_response( $path, array( 'filename' => $this->tmp . '/none/birds-1.jpg' ) );

		$this->assertSame( 404, wp_remote_retrieve_response_code( $response ) );
		$this->assertNull( $response['filename'] );
	}

	public function test_response_is_404_without_a_file() {
		$response = undergrowth_demo_image_response( '', array( 'filename' => $this->tmp ) );

		$this->assertSame( 404, wp_remote_retrieve_response_code( $response ) );
		$this->assertNull( $response['filename'] );
		$this->assertSame( array(), wp_remote_retrieve_headers( $response ) );
		$this->assertSame( '', file_get_contents( $this->tmp ) );
	}

	public function test_download_is_answered_only_while_an_import_runs() {
		$this->assertFalse( has_filter( 'pre_http_request', 'undergrowth_demo_image_download' ) );

		do_action( 'import_start' );
		$this->assertSame( 10, has_filter( 'pre_http_request', 'undergrowth_demo_image_download' ) );

		do_action( 'import_end' );
		$this->assertFalse( has_filter( 'pre_http_request', 'undergrowth_demo_image_download' ) );
	}

	public function test_import_download_is_served_from_disk() {
		do_action( 'import_start' );

		$response = $this->download( self::BASE . 'birds-1.jpg' );
		$path     = undergrowth_demo_images_dir() . '/birds-1.jpg';

		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( (string) filesize( $path ), wp_remote_retrieve_header( $response, 'content-length' ) );
		$this->assertSame( file_get_contents( $path ), file_get_contents( $this->tmp ) );
		$this->assertSame( array(), $this->reached );
	}

	/**
	 * A file the copy lacks goes to the network as before. GitHub's 404 is
	 * what the importer sees, and it records the failed download and moves
	 * on to the next item, as it does for any attachment it cannot fetch.
	 */
	public function test_import_download_of_a_missing_file_is_left_to_the_network() {
		do_action( 'import_start' );

		$response = $this->download( self::BASE . 'nothing-here.jpg' );

		$this->assertSame( 404, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( array( self::BASE . 'nothing-here.jpg' ), $this->reached );
		$this->assertSame( '', file_get_contents( $this->tmp ) );
	}

	/**
	 * A file that is there but cannot be copied goes to the network too,
	 * rather than ending as a 404 the plugin made up.
	 */
	public function test_import_download_that_cannot_be_copied_is_left_to_the_network() {
		do_action( 'import_start' );

		$response = wp_safe_remote_get(
			self::BASE . 'birds-1.jpg',
			array(
				'stream'   => true,
				'filename' => $this->tmp . '/none/birds-1.jpg',
			)
		);

		$this->assertSame( 404, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( array( self::BASE . 'birds-1.jpg' ), $this->reached );
	}

	public function test_import_download_off_the_repository_path_is_untouched() {
		do_action( 'import_start' );

		$response = $this->download( 'https://example.com/demo/images/birds-1.jpg' );

		$this->assertSame( 404, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( array( 'https://example.com/demo/images/birds-1.jpg' ), $this->reached );
		$this->assertSame( '', file_get_contents( $this->tmp ) );
	}

	public function test_request_that_does_not_stream_is_untouched() {
		do_action( 'import_start' );

		$response = wp_safe_remote_get( self::BASE . 'birds-1.jpg' );

		$this->assertSame( 404, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( array( self::BASE . 'birds-1.jpg' ), $this->reached );
	}

	public function test_request_outside_an_import_is_untouched() {
		$response = $this->download( self::BASE . 'birds-1.jpg' );

		$this->assertSame( 404, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( array( self::BASE . 'birds-1.jpg' ), $this->reached );
		$this->assertSame( '', file_get_contents( $this->tmp ) );
	}
}
