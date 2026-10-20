<?php
/**
 * Tests for holding the word corpus in bounded memory.
 *
 * @package Undergrowth
 */

/**
 * The word counts a refresh uses to choose sentences.
 */
class Test_Corpus extends WP_UnitTestCase {

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
	}

	/**
	 * Builds post content of made-up words, most of them rare, like a long
	 * post on a real site.
	 *
	 * @param int $words      Number of words.
	 * @param int $vocabulary Number of different words to draw from.
	 * @return string Content with one paragraph of eight-word sentences.
	 */
	private function long_content( int $words, int $vocabulary = 4000 ): string {
		$sentences = array();
		$sentence  = array();

		for ( $i = 0; $i < $words; $i++ ) {
			// Low ranks come up often and high ranks rarely, as in real writing.
			$sentence[] = 'w' . base_convert( (string) (int) floor( $vocabulary ** ( mt_rand() / mt_getrandmax() ) ), 10, 36 ) . 'x';

			if ( 8 === count( $sentence ) ) {
				$sentences[] = ucfirst( implode( ' ', $sentence ) ) . '.';
				$sentence    = array();
			}
		}

		return '<p>' . implode( ' ', $sentences ) . '</p>';
	}

	public function test_corpus_counts_how_many_posts_use_each_word() {
		$oca   = self::factory()->post->create(
			array(
				'post_title'   => 'Oca',
				'post_content' => 'Oca likes frost.',
			)
		);
		$yacon = self::factory()->post->create(
			array(
				'post_title'   => 'Yacon',
				'post_content' => 'Yacon likes frost and oca.',
			)
		);

		$corpus = undergrowth_build_corpus( array( $oca, $yacon ) );

		$this->assertSame( 2, $corpus['documents'] );
		$this->assertSame( 2, $corpus['frequency']['oca'] );
		$this->assertSame( 2, $corpus['frequency']['frost'] );
		$this->assertSame( 1, $corpus['frequency']['yacon'] );
	}

	public function test_corpus_keeps_no_word_counts_for_each_post() {
		$oca = self::factory()->post->create( array( 'post_content' => 'Oca likes frost.' ) );

		$this->assertArrayNotHasKey( 'counts', undergrowth_build_corpus( array( $oca ) ) );
	}

	public function test_post_counts_its_words_not_every_occurrence() {
		$oca = self::factory()->post->create(
			array(
				'post_title'   => 'Oca',
				'post_content' => 'Oca oca oca.',
			)
		);

		$this->assertSame( array( 'oca' => 4 ), undergrowth_post_token_counts( get_post( $oca ) ) );
	}

	public function test_corpus_leaves_posts_out_of_the_object_cache() {
		$ids = self::factory()->post->create_many( 3 );

		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}

		undergrowth_build_corpus( $ids );

		foreach ( $ids as $id ) {
			$this->assertFalse( wp_cache_get( $id, 'posts' ) );
		}
	}

	public function test_corpus_reads_posts_in_bounded_chunks() {
		$ids     = self::factory()->post->create_many( 2 * UNDERGROWTH_POST_CHUNK + 1 );
		$selects = array();
		$record  = static function ( $query ) use ( &$selects ) {
			if ( 0 === strpos( ltrim( $query ), 'SELECT ID, post_title, post_content' ) ) {
				$selects[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $record );

		$corpus = undergrowth_build_corpus( $ids );

		remove_filter( 'query', $record );
		$this->assertSame( count( $ids ), $corpus['documents'] );
		$this->assertCount( 3, $selects );

		foreach ( $selects as $select ) {
			$this->assertLessThanOrEqual( UNDERGROWTH_POST_CHUNK, substr_count( $select, ',' ) - 1 );
		}
	}

	public function test_corpus_leaves_no_rows_behind_in_wpdb() {
		global $wpdb;

		undergrowth_build_corpus( self::factory()->post->create_many( 2 ) );

		$this->assertEmpty( $wpdb->last_result );
	}

	public function test_post_outside_the_corpus_has_no_distinctive_terms_even_when_it_exists() {
		$oca   = self::factory()->post->create( array( 'post_content' => 'Oca likes frost.' ) );
		$yacon = self::factory()->post->create( array( 'post_content' => 'Yacon likes frost.' ) );

		$this->assertSame( array(), undergrowth_distinctive_terms( $yacon, undergrowth_build_corpus( array( $oca ) ) ) );
	}

	public function test_distinctive_terms_weigh_a_posts_words_by_their_rarity() {
		$oca   = self::factory()->post->create(
			array(
				'post_title'   => 'Oca',
				'post_content' => 'Oca likes frost. Oca is sweet.',
			)
		);
		$yacon = self::factory()->post->create(
			array(
				'post_title'   => 'Yacon',
				'post_content' => 'Yacon likes frost.',
			)
		);

		$terms  = undergrowth_distinctive_terms( $oca, undergrowth_build_corpus( array( $oca, $yacon ) ) );
		$rare   = log( 3 / 1.5 ) ** 2;
		$common = log( 3 / 2.5 ) ** 2;

		$this->assertSame( array( 'oca', 'sweet', 'frost', 'likes' ), array_keys( $terms ) );
		$this->assertEqualsWithDelta( ( 1 + log( 3 ) ) * $rare, $terms['oca'], 1e-12 );
		$this->assertEqualsWithDelta( $rare, $terms['sweet'], 1e-12 );
		$this->assertEqualsWithDelta( $common, $terms['frost'], 1e-12 );
	}

	public function test_corpus_of_long_posts_stays_compact() {
		mt_srand( 26 );

		$ids = array();

		for ( $i = 0; $i < 100; $i++ ) {
			$ids[] = self::factory()->post->create( array( 'post_content' => $this->long_content( 1000 ) ) );
		}

		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}

		gc_collect_cycles();
		$before = memory_get_usage();
		$corpus = undergrowth_build_corpus( $ids );
		$held   = memory_get_usage() - $before;

		$this->assertSame( 100, $corpus['documents'] );
		// Every post's own word counts, and its content in the post cache,
		// would take several megabytes here.
		$this->assertLessThan( 1048576, $held );
	}

	public function test_refresh_proposes_the_same_sentences_as_counting_every_post() {
		global $wpdb;

		add_filter(
			'undergrowth_min_link_similarity',
			static function () {
				return -1.0;
			}
		);

		mt_srand( 26 );

		$ids = array();

		for ( $i = 0; $i < 16; $i++ ) {
			$vector = array();

			for ( $d = 0; $d < 16; $d++ ) {
				$vector[] = mt_rand() / mt_getrandmax() * 2 - 1;
			}

			$ids[] = $this->plant( 'Bed ' . $i, $vector, array( 'post_content' => $this->long_content( 120, 150 ) ) );
		}

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', undergrowth_links_table() ) );
		undergrowth_refresh_around( (int) end( $ids ) );

		$positions = array_flip( $ids );
		$rows      = array_map(
			static function ( $row ) use ( $positions ) {
				return array( $positions[ (int) $row[0] ], $positions[ (int) $row[1] ], (int) $row[2] );
			},
			(array) $wpdb->get_results(
				$wpdb->prepare( 'SELECT source_id, target_id, sentence_index FROM %i ORDER BY source_id, target_id', undergrowth_links_table() ),
				ARRAY_N
			)
		);

		$this->assertCount( 33, $rows );
		// Recorded from the corpus that kept every post's word counts.
		$this->assertSame( 'bf0d13fc64d8b50e98bb6b02751ebeaf', md5( (string) wp_json_encode( $rows ) ) );
	}

	public function test_refresh_reads_only_the_target_posts_it_needs() {
		add_filter(
			'undergrowth_min_link_similarity',
			static function () {
				return -1.0;
			}
		);

		mt_srand( 26 );

		$ids = array();

		for ( $i = 0; $i < 12; $i++ ) {
			$ids[] = $this->plant( 'Bed ' . $i, array( 1.0, $i / 12, 0.0 ), array( 'post_content' => $this->long_content( 120, 150 ) ) );
		}

		wp_cache_flush();
		undergrowth_refresh_proposals( $ids[0] );

		$cached = array_filter(
			$ids,
			static function ( $id ) {
				return false !== wp_cache_get( $id, 'posts' );
			}
		);

		$this->assertCount( UNDERGROWTH_MAX_PROPOSALS, $this->proposed( $ids[0] ) );
		// The source and the targets proposed, not every post that scored.
		$this->assertLessThanOrEqual( 1 + UNDERGROWTH_MAX_PROPOSALS, count( $cached ) );
	}

	public function test_refresh_with_no_fitting_sentence_leaves_target_posts_out_of_the_cache() {
		add_filter(
			'undergrowth_min_link_similarity',
			static function () {
				return -1.0;
			}
		);

		$source  = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Tubers like cool weather.</p>' ) );
		$targets = array();

		for ( $i = 0; $i < 6; $i++ ) {
			$targets[] = $this->plant( 'Yacon ' . $i, array( 1.0, $i / 6, 0.0 ), array( 'post_content' => '<p>Syrup is sweet.</p>' ) );
		}

		wp_cache_flush();
		undergrowth_refresh_proposals( $source );

		$this->assertSame( array(), $this->proposed( $source ) );

		foreach ( $targets as $target ) {
			$this->assertFalse( wp_cache_get( $target, 'posts' ) );
		}
	}

	public function test_refresh_checks_target_posts_in_bounded_chunks() {
		add_filter(
			'undergrowth_min_link_similarity',
			static function () {
				return -1.0;
			}
		);

		$source = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Tubers like cool weather.</p>' ) );

		for ( $i = 0; $i < UNDERGROWTH_POST_CHUNK + 1; $i++ ) {
			$this->plant( 'Yacon ' . $i, array( 1.0, $i / 200, 0.0 ), array( 'post_content' => '<p>Syrup is sweet.</p>' ) );
		}

		$selects = array();
		$record  = static function ( $query ) use ( &$selects ) {
			if ( 0 === strpos( ltrim( $query ), 'SELECT ID, post_status, post_password, post_type' ) ) {
				$selects[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $record );

		undergrowth_refresh_proposals( $source );

		remove_filter( 'query', $record );
		$this->assertCount( 2, $selects );
	}

	public function test_post_deleted_after_the_corpus_was_built_has_no_distinctive_terms() {
		$oca    = self::factory()->post->create( array( 'post_content' => 'Oca likes frost.' ) );
		$corpus = undergrowth_build_corpus( array( $oca ) );

		wp_delete_post( $oca, true );

		$this->assertSame( array(), undergrowth_distinctive_terms( $oca, $corpus ) );
	}

	public function test_no_posts_to_check_gives_no_open_targets() {
		$this->assertSame( array(), undergrowth_open_target_ids( array() ) );
	}

	public function test_ranking_stops_at_the_limit() {
		add_filter(
			'undergrowth_min_link_similarity',
			static function () {
				return -1.0;
			}
		);

		$source = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$best   = array();

		for ( $i = 0; $i < 4; $i++ ) {
			$best[] = $this->plant( 'Yacon ' . $i, array( 1.0, 0.1 * $i, 0.0 ) );
		}

		$this->assertSame( array_slice( $best, 0, 2 ), array_keys( undergrowth_rank_targets( $source, null, 2 ) ) );
	}
}
