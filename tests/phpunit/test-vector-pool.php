<?php
/**
 * Tests for holding comparable vectors in bounded memory.
 *
 * @package Undergrowth
 */

/**
 * The pool of vectors a refresh compares against.
 */
class Test_Vector_Pool extends WP_UnitTestCase {

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
	}

	/**
	 * Writes a vector row straight to the table, with no post behind it.
	 *
	 * @param int     $post_id Post ID to store it under.
	 * @param float[] $vector  The vector.
	 */
	private function store( int $post_id, array $vector ): void {
		undergrowth_save_vector(
			$post_id,
			$vector,
			array(
				'provider'     => 'test-provider',
				'model'        => 'test-model',
				'language'     => 'en_US',
				'content_hash' => str_repeat( '0', 64 ),
			)
		);
	}

	public function test_packed_vector_unpacks_to_the_same_floats() {
		$vector = array( 1.0, -0.5, 0.25, 0.0, 3.0 );

		$this->assertSame( $vector, undergrowth_unpack_vector( undergrowth_pack_vector( $vector ) ) );
	}

	public function test_packed_vector_takes_four_bytes_a_dimension() {
		$this->assertSame( 4 * 1536, strlen( undergrowth_pack_vector( array_fill( 0, 1536, 0.5 ) ) ) );
	}

	public function test_unpacking_nothing_gives_an_empty_vector() {
		$this->assertSame( array(), undergrowth_unpack_vector( '' ) );
	}

	public function test_comparable_vectors_are_held_packed() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.5, 0.0 ) );

		$pool = undergrowth_comparable_vectors( $oca );

		$this->assertIsString( $pool[ $oca ] );
		$this->assertSame( array( 1.0, 0.5, 0.0 ), undergrowth_unpack_vector( $pool[ $oca ] ) );
	}

	public function test_comparable_vectors_are_read_in_bounded_chunks() {
		$count = 2 * UNDERGROWTH_VECTOR_CHUNK + 1;

		for ( $i = 1; $i <= $count; $i++ ) {
			$this->store( 100000 + $i, array( 1.0, (float) $i ) );
		}

		$selects = array();
		$record  = static function ( $query ) use ( &$selects ) {
			if ( false !== strpos( $query, 'undergrowth_vectors' ) && 0 === strpos( ltrim( $query ), 'SELECT post_id, embedding' ) ) {
				$selects[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $record );

		$pool = undergrowth_comparable_vectors( 100001 );

		remove_filter( 'query', $record );
		$this->assertCount( $count, $pool );
		$this->assertGreaterThanOrEqual( 3, count( $selects ) );

		foreach ( $selects as $select ) {
			$this->assertStringContainsString( 'LIMIT ' . UNDERGROWTH_VECTOR_CHUNK, $select );
		}
	}

	public function test_comparable_vectors_leave_no_rows_behind_in_wpdb() {
		global $wpdb;

		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		undergrowth_comparable_vectors( $oca );

		$this->assertEmpty( $wpdb->last_result );
	}

	public function test_pool_of_large_vectors_stays_compact() {
		$dimensions = 1536;
		$count      = 200;

		mt_srand( 26 );

		for ( $i = 1; $i <= $count; $i++ ) {
			$vector = array();

			for ( $d = 0; $d < $dimensions; $d++ ) {
				$vector[] = mt_rand() / mt_getrandmax();
			}

			$this->store( 200000 + $i, $vector );
		}

		gc_collect_cycles();
		$before = memory_get_usage();
		$pool   = undergrowth_comparable_vectors( 200001 );
		$held   = memory_get_usage() - $before;

		$this->assertCount( $count, $pool );
		// Decoded PHP float arrays would take about 40 KB each, 8 MB in all.
		$this->assertLessThan( 2 * 1048576, $held );
	}

	public function test_invalid_stored_vectors_stay_out_of_the_pool() {
		global $wpdb;

		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->store( 300001, array( 1.0, 0.0, 0.0 ) );
		$wpdb->update( undergrowth_vectors_table(), array( 'embedding' => '"not a vector"' ), array( 'post_id' => 300001 ) );

		$pool = undergrowth_comparable_vectors( $oca );

		$this->assertArrayHasKey( $oca, $pool );
		$this->assertArrayNotHasKey( 300001, $pool );
	}

	public function test_vector_too_large_for_single_precision_stays_out_of_the_pool() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->store( 300002, array( 1e39, 0.0, 0.0 ) );

		$this->assertSame( '', undergrowth_pack_vector( array( 1e39, 0.0 ) ) );
		$this->assertArrayNotHasKey( 300002, undergrowth_comparable_vectors( $oca ) );
	}

	public function test_proposals_match_a_ranking_by_the_stored_vectors() {
		add_filter(
			'undergrowth_min_link_similarity',
			static function () {
				return -1.0;
			}
		);

		mt_srand( 26 );

		$vectors = array();

		for ( $i = 0; $i < 12; $i++ ) {
			$vector = array();

			for ( $d = 0; $d < 16; $d++ ) {
				$vector[] = mt_rand() / mt_getrandmax() * 2 - 1;
			}

			$vectors[ $this->plant( 'Bed ' . $i, $vector ) ] = $vector;
		}

		foreach ( $vectors as $source_id => $source ) {
			$expected = array();

			foreach ( $vectors as $target_id => $target ) {
				if ( $target_id !== $source_id ) {
					$expected[ $target_id ] = undergrowth_cosine( $source, $target );
				}
			}

			arsort( $expected );
			undergrowth_refresh_proposals( $source_id );

			$this->assertSame( array_slice( array_keys( $expected ), 0, UNDERGROWTH_MAX_PROPOSALS ), $this->proposed( $source_id ) );
		}
	}
}
