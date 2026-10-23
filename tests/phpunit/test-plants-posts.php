<?php
/**
 * Tests for the test helper that plants posts with known vectors.
 *
 * @package Undergrowth
 */

/**
 * The helper itself.
 */
class Test_Plants_Posts extends WP_UnitTestCase {

	use Plants_Posts;

	public function tear_down() {
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	public function test_planting_starts_with_no_per_post_files() {
		$root = undergrowth_memory_root();
		wp_mkdir_p( $root . '/posts' );
		file_put_contents( $root . '/posts/1.txt', "Left by an earlier test.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A stray file, as an earlier test would leave one.

		$this->start_planting();

		$this->assertFileDoesNotExist( $root . '/posts/1.txt' );
	}
}
