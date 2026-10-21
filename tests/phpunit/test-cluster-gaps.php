<?php
/**
 * Tests for finding gaps in clusters: a cluster whose centre has no post.
 *
 * @package Undergrowth
 */

/**
 * Cluster gaps.
 */
class Test_Cluster_Gaps extends WP_UnitTestCase {

	/**
	 * Publishes a post, stores its vector and puts it in a cluster.
	 *
	 * @param float[] $vector Vector to store.
	 * @param int     $leader Leader of the cluster, or 0 for the post to lead.
	 * @return int Post ID.
	 */
	private function member( array $vector, int $leader = 0 ): int {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		undergrowth_save_vector(
			$post_id,
			$vector,
			array(
				'provider'     => 'test-provider',
				'model'        => 'test-model',
				'language'     => 'en_US',
				'content_hash' => 'hash',
			)
		);
		update_post_meta( $post_id, 'undergrowth_cluster', 0 === $leader ? $post_id : $leader );

		return $post_id;
	}

	public function test_cluster_with_nothing_at_its_centre_is_a_gap() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$b = $this->member( array( 0.0, 1.0, 0.0 ), $a );
		$c = $this->member( array( 0.0, 0.0, 1.0 ), $a );

		$gaps = undergrowth_cluster_gaps();

		$this->assertCount( 1, $gaps );
		$this->assertSame( $a, $gaps[0]['leader'] );
		$this->assertSame( array( $a, $b, $c ), $gaps[0]['members'] );
		$this->assertEqualsWithDelta( 1 / sqrt( 3 ), $gaps[0]['centre'], 0.0001 );
	}

	public function test_cluster_with_a_post_at_its_centre_is_not_a_gap() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$this->member( array( 0.99, 0.1, 0.0 ), $a );
		$this->member( array( 0.99, 0.0, 0.1 ), $a );

		$this->assertSame( array(), undergrowth_cluster_gaps() );
	}

	public function test_members_come_nearest_the_centre_first() {
		add_filter(
			'undergrowth_gap_similarity',
			static function () {
				return 0.95;
			}
		);
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$b = $this->member( array( 0.0, 1.0, 0.0 ), $a );
		$c = $this->member( array( 0.0, 0.0, 1.0 ), $a );
		$d = $this->member( array( 1.0, 1.0, 0.0 ), $a );

		$this->assertSame( array( $d, $a, $b, $c ), undergrowth_cluster_gaps()[0]['members'] );
	}

	public function test_two_posts_are_not_a_cluster() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$this->member( array( 0.0, 1.0, 0.0 ), $a );

		$this->assertSame( array(), undergrowth_cluster_gaps() );
	}

	public function test_cluster_led_by_a_post_that_left_it_is_still_read() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$b = $this->member( array( 0.0, 1.0, 0.0 ), $a );
		$c = $this->member( array( 0.0, 0.0, 1.0 ), $a );
		$d = $this->member( array( 1.0, 0.0, 0.0 ), $a );
		delete_post_meta( $a, 'undergrowth_cluster' );

		$gaps = undergrowth_cluster_gaps();

		$this->assertSame( $a, $gaps[0]['leader'] );
		$this->assertEqualsCanonicalizing( array( $b, $c, $d ), $gaps[0]['members'] );
	}

	public function test_member_with_a_vector_of_another_length_is_left_out() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$b = $this->member( array( 0.0, 1.0, 0.0 ), $a );
		$c = $this->member( array( 0.0, 0.0, 1.0 ), $a );
		$this->member( array( 1.0, 0.0 ), $a );

		$this->assertSame( array( $a, $b, $c ), undergrowth_cluster_gaps()[0]['members'] );
	}

	public function test_too_few_comparable_vectors_make_no_gap() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$this->member( array( 0.0, 1.0 ), $a );
		$this->member( array( 0.0, 0.0 ), $a );

		$this->assertSame( array(), undergrowth_cluster_gaps() );
	}

	public function test_gap_similarity_that_is_not_a_number_falls_back_to_the_default() {
		add_filter(
			'undergrowth_gap_similarity',
			static function () {
				return 'close';
			}
		);

		$this->assertSame( UNDERGROWTH_GAP_SIMILARITY, undergrowth_gap_similarity() );
	}

	public function test_gaps_come_in_order_of_their_leaders() {
		$first = $this->member( array( 1.0, 0.0, 0.0 ) );
		$this->member( array( 0.0, 1.0, 0.0 ), $first );
		$this->member( array( 0.0, 0.0, 1.0 ), $first );
		$second = $this->member( array( -1.0, 0.0, 0.0 ) );
		$this->member( array( 0.0, -1.0, 0.0 ), $second );
		$this->member( array( 0.0, 0.0, -1.0 ), $second );

		$this->assertSame( array( $first, $second ), array_column( undergrowth_cluster_gaps(), 'leader' ) );
	}

	public function test_cluster_whose_vectors_cancel_out_has_no_centre_to_measure() {
		$a = $this->member( array( 1.0, 0.0, 0.0 ) );
		$this->member( array( -1.0, 0.0, 0.0 ), $a );
		$this->member( array( 0.0, 1.0, 0.0 ), $a );
		$this->member( array( 0.0, -1.0, 0.0 ), $a );

		$this->assertSame( array(), undergrowth_cluster_gaps() );
	}
}
