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

	/**
	 * Publishes a post, stores its vector and puts it in a cluster.
	 *
	 * @param float[] $vector Vector to store.
	 * @param int     $leader Leader of the cluster, or 0 for the post to lead.
	 * @param string  $model  Model the vector came from.
	 * @return int Post ID.
	 */
	private function member( array $vector, int $leader = 0, string $model = 'test-model' ): int {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		undergrowth_save_vector(
			$post_id,
			$vector,
			array(
				'provider'     => 'test-provider',
				'model'        => $model,
				'language'     => 'en_US',
				'content_hash' => 'hash',
			)
		);
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
}
