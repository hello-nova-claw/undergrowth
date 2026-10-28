<?php
/**
 * Tests for listing every cluster with its posts, for the Map tab.
 *
 * @package Undergrowth
 */

/**
 * The cluster map.
 */
class Test_Cluster_Map extends WP_UnitTestCase {

	use Plants_Posts;

	/**
	 * Publishes a post, stores its vector and puts it in a cluster.
	 *
	 * @param float[] $vector Vector to store.
	 * @param int     $leader Leader of the cluster, or 0 for the post to lead.
	 * @param string  $model  Model the vector came from.
	 * @return int Post ID.
	 */
	private function member( array $vector, int $leader = 0, string $model = 'test-model' ): int {
		$post_id = $this->seed( '', $vector, array(), array( 'model' => $model ) );

		update_post_meta( $post_id, 'undergrowth_cluster', 0 === $leader ? $post_id : $leader );

		return $post_id;
	}

	public function test_no_clusters_make_an_empty_map() {
		$this->assertSame( array(), undergrowth_cluster_map() );
		$this->assertFalse( undergrowth_has_clusters() );
	}

	public function test_two_posts_are_not_a_cluster_on_the_map() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$this->member( array( 0.9, 0.1, 0.0 ), $a );

		$this->assertSame( array(), undergrowth_cluster_map() );
		$this->assertFalse( undergrowth_has_clusters() );
	}

	public function test_each_cluster_lists_its_posts_nearest_the_centre_first_and_flags_a_gap() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$b = $this->member( array( 0.0, 1.0, 0.0 ), $a );
		$c = $this->member( array( 0.0, 0.0, 1.0 ), $a );
		$d = $this->member( array( 0.0, 0.0, 1.0 ) );
		$e = $this->member( array( 0.1, 0.0, 0.99 ), $d );
		$f = $this->member( array( 0.0, 0.1, 0.99 ), $d );
		$g = $this->member( array( 0.05, 0.05, 0.99 ), $d );

		$this->assertTrue( undergrowth_has_clusters() );
		$this->assertSame(
			array(
				array(
					'leader'  => $a,
					'members' => array( $a, $b, $c ),
					'gap'     => true,
				),
				array(
					'leader'  => $d,
					'members' => array( $g, $d, $e, $f ),
					'gap'     => false,
				),
			),
			undergrowth_cluster_map()
		);
	}

	public function test_gaps_on_the_map_are_the_gaps_the_prompts_use() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$this->member( array( 0.0, 1.0, 0.0 ), $a );
		$this->member( array( 0.0, 0.0, 1.0 ), $a );
		$d = $this->member( array( 1.0, 0.0, 0.0 ) );
		$this->member( array( 0.99, 0.1, 0.0 ), $d );
		$this->member( array( 0.99, 0.0, 0.1 ), $d );

		$flagged = array();

		foreach ( undergrowth_cluster_map() as $cluster ) {
			if ( $cluster['gap'] ) {
				$flagged[] = $cluster['leader'];
			}
		}

		$this->assertSame( array_column( undergrowth_cluster_gaps(), 'leader' ), $flagged );
	}

	public function test_composting_and_unpublished_posts_are_left_off_the_map() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$b = $this->member( array( 0.9, 0.1, 0.0 ), $a );
		$c = $this->member( array( 0.9, 0.0, 0.1 ), $a );
		$d = $this->member( array( 0.95, 0.05, 0.0 ), $a );
		// Stored straight away, as if the cluster key had been left behind.
		update_post_meta( $b, 'undergrowth_stage', 'composting' );
		self::factory()->post->update_object( $c, array( 'post_status' => 'draft' ) );
		update_post_meta( $c, 'undergrowth_cluster', $a );

		$members = undergrowth_cluster_map()[0]['members'];

		$this->assertEqualsCanonicalizing( array( $a, $d ), $members );
	}

	public function test_post_left_off_the_map_does_not_fill_a_gap() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$this->member( array( 0.0, 1.0, 0.0 ), $a );
		$this->member( array( 0.0, 0.0, 1.0 ), $a );
		$d = $this->member( array( 1.0, 1.0, 1.0 ), $a );
		// Stored straight away, as if the cluster key had been left behind.
		update_post_meta( $d, 'undergrowth_stage', 'composting' );

		$this->assertTrue( undergrowth_cluster_map()[0]['gap'] );
		$this->assertSame( array( $a ), array_column( undergrowth_cluster_gaps(), 'leader' ) );
	}

	public function test_member_that_cannot_be_measured_comes_last() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$b = $this->member( array( 0.0, 1.0, 0.0 ), $a );
		$c = $this->member( array( 1.0, 1.0, 0.0 ), $a );
		$d = $this->member( array( 1.0, 0.0 ), $a );

		$this->assertSame( array( $c, $a, $b, $d ), undergrowth_cluster_map()[0]['members'] );
	}

	public function test_cluster_with_too_few_comparable_vectors_is_listed_without_a_gap() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$b = $this->member( array( 0.0, 1.0 ), $a );
		$c = $this->member( array( 0.0, 0.0 ), $a );

		$this->assertSame(
			array(
				array(
					'leader'  => $a,
					'members' => array( $a, $b, $c ),
					'gap'     => false,
				),
			),
			undergrowth_cluster_map()
		);
	}

	public function test_cluster_with_every_post_composted_is_left_off_the_map() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$b = $this->member( array( 0.9, 0.1, 0.0 ), $a );
		$c = $this->member( array( 0.9, 0.0, 0.1 ), $a );

		foreach ( array( $a, $b, $c ) as $post_id ) {
			update_post_meta( $post_id, 'undergrowth_stage', 'composting' );
		}

		$this->assertSame( array(), undergrowth_cluster_map() );
	}

	/**
	 * Builds a cluster of a given size and counts the queries the map takes.
	 *
	 * @param int $size Posts in the cluster.
	 * @return int Queries.
	 */
	private function map_queries( int $size ): int {
		$leader = $this->member( array( 1.0, 0.0, 0.0 ) );

		for ( $i = 1; $i < $size; $i++ ) {
			$this->member( array( 1.0, 0.01 * $i, 0.0 ), $leader );
		}

		wp_cache_flush();
		undergrowth_indexed_post_types();
		$before = get_num_queries();
		undergrowth_cluster_map();

		return get_num_queries() - $before;
	}

	public function test_the_map_takes_as_many_queries_for_a_large_cluster_as_for_a_small_one() {
		$small = $this->map_queries( 3 );

		foreach ( undergrowth_cluster_leaders() as $leader ) {
			foreach ( undergrowth_cluster_posts( $leader ) as $post_id ) {
				delete_post_meta( $post_id, 'undergrowth_cluster' );
			}
		}

		$this->assertSame( $small, $this->map_queries( 12 ) );
	}

	public function test_the_map_loads_none_of_the_clustered_posts() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$b = $this->member( array( 0.9, 0.1, 0.0 ), $a );
		$c = $this->member( array( 0.9, 0.0, 0.1 ), $a );
		wp_cache_flush();

		$this->assertCount( 1, undergrowth_cluster_map() );

		foreach ( array( $a, $b, $c ) as $post_id ) {
			$this->assertFalse( wp_cache_get( $post_id, 'posts' ) );
		}
	}

	public function test_the_garden_index_loads_none_of_the_clustered_posts() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$b = $this->member( array( 0.9, 0.1, 0.0 ), $a );
		$c = $this->member( array( 0.9, 0.0, 0.1 ), $a );
		wp_cache_flush();

		undergrowth_build_garden_index();

		foreach ( array( $a, $b, $c ) as $post_id ) {
			$this->assertFalse( wp_cache_get( $post_id, 'posts' ) );
		}
	}

	public function test_a_member_counts_as_composting_only_by_its_first_stage_row() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$b = $this->member( array( 0.9, 0.1, 0.0 ), $a );
		$c = $this->member( array( 0.9, 0.0, 0.1 ), $a );

		// undergrowth_get_stage() reads the first row, so both stay in the garden.
		add_post_meta( $b, 'undergrowth_stage', 'seedling' );
		add_post_meta( $b, 'undergrowth_stage', 'composting' );
		update_post_meta( $c, 'undergrowth_stage', 'Composting' );

		$this->assertSame( 'seedling', undergrowth_get_stage( $b ) );
		$this->assertSame( 'seedling', undergrowth_get_stage( $c ) );
		$this->assertSame( array( $a, $b, $c ), undergrowth_clusterable_posts( array( $a, $b, $c ) ) );
	}

	public function test_only_the_stage_key_itself_counts() {
		global $wpdb;

		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$b = $this->member( array( 0.9, 0.1, 0.0 ), $a );

		// Stored straight, as another plugin might: core reads keys by case.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $b,
				'meta_key'   => 'UNDERGROWTH_STAGE', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A test fixture.
				'meta_value' => 'seedling', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- A test fixture.
			)
		);
		add_post_meta( $b, 'undergrowth_stage', 'composting' );
		wp_cache_delete( $b, 'post_meta' );

		$this->assertSame( 'composting', undergrowth_get_stage( $b ) );
		$this->assertSame( array( $a ), undergrowth_clusterable_posts( array( $a, $b ) ) );
	}
}
