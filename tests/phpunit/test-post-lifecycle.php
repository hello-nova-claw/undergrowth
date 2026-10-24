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

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
	}

	public function tear_down() {
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Counts the link rows to or from a post, in any state.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private function link_rows( int $post_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source_id = %d OR target_id = %d', undergrowth_links_table(), $post_id, $post_id ) );
	}

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

	public function test_deleting_an_indexed_post_still_clears_its_vector_and_proposals() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->assertSame( array( $yacon ), $this->proposed( $oca ) );

		wp_delete_post( $yacon, true );

		$this->assertNull( undergrowth_get_vector( $yacon ) );
		$this->assertSame( 0, $this->link_rows( $yacon ) );
	}

	/**
	 * A post indexed before its type was filtered out keeps nothing behind
	 * when it is deleted for good, without passing through the trash.
	 */
	public function test_deleting_a_post_whose_type_is_no_longer_indexed_still_clears_it() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->assertSame( array( $yacon ), $this->proposed( $oca ) );

		$pages_only = static function () {
			return array( 'page' );
		};
		add_filter( 'undergrowth_post_types', $pages_only );

		wp_delete_post( $yacon, true );

		remove_filter( 'undergrowth_post_types', $pages_only );
		$this->assertNull( undergrowth_get_vector( $yacon ) );
		$this->assertSame( 0, $this->link_rows( $yacon ) );
	}

	public function test_updating_an_indexed_post_still_rewrites_the_files_that_link_to_it() {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$this->assertSame( array( $yacon ), $this->proposed( $oca ) );
		undergrowth_delete_memory( $oca );
		$this->assertFileDoesNotExist( undergrowth_memory_path( $oca ) );

		// A new slug changes nothing that is embedded.
		wp_update_post(
			array(
				'ID'        => $yacon,
				'post_name' => 'yacon-root',
			)
		);

		$this->assertFileExists( undergrowth_memory_path( $oca ) );
	}
}
