<?php
/**
 * Tests for the main plugin file.
 *
 * @package Undergrowth
 */

/**
 * Plugin header and bootstrap.
 */
class Test_Plugin extends WP_UnitTestCase {

	/**
	 * Reads the plugin header without translating it.
	 *
	 * @return array<string, string>
	 */
	private function plugin_data(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		return get_plugin_data( UNDERGROWTH_FILE, false, false );
	}

	public function test_header_declares_text_domain_matching_slug() {
		$this->assertSame( 'undergrowth', $this->plugin_data()['TextDomain'] );
	}

	public function test_header_requires_wordpress_6_9_and_php_7_4() {
		$data = $this->plugin_data();

		$this->assertSame( '6.9', $data['RequiresWP'] );
		$this->assertSame( '7.4', $data['RequiresPHP'] );
	}

	public function test_version_constant_matches_header() {
		$this->assertSame( UNDERGROWTH_VERSION, $this->plugin_data()['Version'] );
	}

	public function test_header_licence_is_gpl() {
		$header = get_file_data( UNDERGROWTH_FILE, array( 'License' => 'License' ) );

		$this->assertSame( 'GPL-2.0-or-later', $header['License'] );
	}

	public function test_readme_headers_match_plugin_header() {
		$data   = $this->plugin_data();
		$readme = get_file_data(
			dirname( UNDERGROWTH_FILE ) . '/readme.txt',
			array(
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
				'StableTag'   => 'Stable tag',
				'License'     => 'License',
			)
		);
		$header = get_file_data( UNDERGROWTH_FILE, array( 'License' => 'License' ) );

		$this->assertSame( $data['RequiresWP'], $readme['RequiresWP'] );
		$this->assertSame( $data['RequiresPHP'], $readme['RequiresPHP'] );
		$this->assertSame( $data['Version'], $readme['StableTag'] );
		$this->assertSame( $header['License'], $readme['License'] );
	}

	public function test_readme_short_description_matches_header() {
		$readme = file_get_contents( dirname( UNDERGROWTH_FILE ) . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file in a test.

		$this->assertStringContainsString( "\n\n" . $this->plugin_data()['Description'] . "\n\n", $readme );
	}
}
