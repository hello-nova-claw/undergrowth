<?php
/**
 * Tests for the plugin's handlers on core's post-lifecycle hooks.
 *
 * @package Undergrowth
 */

/**
 * Revisions, autosaves and post types that are not indexed are skipped.
 */
class Test_Post_Lifecycle extends WP_UnitTestCase {

	/**
	 * Runs a callback and returns the queries it made on the plugin's tables.
	 *
	 * @param callable $callback The code under test.
	 * @return string[]
	 */
	private function plugin_table_queries( callable $callback ): array {
		$queries = array();
		$record  = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record, 1 );
		$callback();
		remove_filter( 'query', $record, 1 );

		$tables = preg_quote( undergrowth_vectors_table(), '/' ) . '|' . preg_quote( undergrowth_links_table(), '/' );

		return array_values( preg_grep( '/' . $tables . '/', $queries ) );
	}

	public function test_saving_and_pruning_a_revision_touches_no_plugin_table() {
		$post_id = self::factory()->post->create( array( 'post_content' => 'First draft.' ) );
		wp_save_post_revision( $post_id );

		// Change the post without core saving a revision, so the save under
		// test has something new to keep and an older revision to prune.
		remove_action( 'post_updated', 'wp_save_post_revision', 10 );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Second draft.',
			)
		);
		add_action( 'post_updated', 'wp_save_post_revision', 10, 1 );

		$keep_one = static function () {
			return 1;
		};
		add_filter( 'wp_revisions_to_keep', $keep_one );
		$deleted = did_action( 'deleted_post' );

		$queries = $this->plugin_table_queries(
			static function () use ( $post_id ) {
				wp_save_post_revision( $post_id );
			}
		);

		remove_filter( 'wp_revisions_to_keep', $keep_one );
		$this->assertGreaterThan( $deleted, did_action( 'deleted_post' ), 'No revision was pruned.' );
		$this->assertSame( array(), $queries );
	}

	public function test_saving_and_deleting_an_autosave_touches_no_plugin_table() {
		$post_id = self::factory()->post->create( array( 'post_content' => 'Saved.' ) );

		$queries = $this->plugin_table_queries(
			static function () use ( $post_id ) {
				$autosave_id = _wp_put_post_revision(
					array(
						'ID'           => $post_id,
						'post_title'   => 'Autosaved',
						'post_content' => 'Autosaved once.',
					),
					true
				);
				wp_update_post(
					array(
						'ID'           => $autosave_id,
						'post_content' => 'Autosaved twice.',
					)
				);
				wp_delete_post_revision( $autosave_id );
			}
		);

		$this->assertSame( array(), $queries );
	}

	public function test_saving_and_deleting_an_attachment_touches_no_plugin_table() {
		$queries = $this->plugin_table_queries(
			static function () {
				$attachment_id = self::factory()->attachment->create( array( 'post_title' => 'A photo' ) );
				wp_update_post(
					array(
						'ID'         => $attachment_id,
						'post_title' => 'A better photo',
					)
				);
				wp_delete_attachment( $attachment_id, true );
			}
		);

		$this->assertSame( array(), $queries );
	}

	/**
	 * Saving a page still checks for a vector, in case its type was indexed
	 * once, so only the deletion is recorded.
	 */
	public function test_deleting_a_page_touches_no_plugin_table() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$queries = $this->plugin_table_queries(
			static function () use ( $page_id ) {
				wp_delete_post( $page_id, true );
			}
		);

		$this->assertSame( array(), $queries );
	}

	public function test_deleting_an_indexed_post_still_clears_both_tables() {
		$post_id = self::factory()->post->create();

		$queries = $this->plugin_table_queries(
			static function () use ( $post_id ) {
				wp_delete_post( $post_id, true );
			}
		);

		$this->assertNotSame( array(), preg_grep( '/^\s*DELETE FROM `?' . preg_quote( undergrowth_vectors_table(), '/' ) . '/', $queries ) );
		$this->assertNotSame( array(), preg_grep( '/^\s*DELETE FROM `?' . preg_quote( undergrowth_links_table(), '/' ) . '/', $queries ) );
	}

	public function test_updating_an_indexed_post_still_rewrites_the_files_around_it() {
		$post_id = self::factory()->post->create();

		$queries = $this->plugin_table_queries(
			static function () use ( $post_id ) {
				wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => 'A new title',
					)
				);
			}
		);

		$this->assertNotSame( array(), preg_grep( '/SELECT DISTINCT source_id FROM `?' . preg_quote( undergrowth_links_table(), '/' ) . '/', $queries ) );
	}
}
