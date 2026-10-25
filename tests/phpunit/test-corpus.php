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

	public function test_two_letter_hebrew_word_counts_in_the_corpus() {
		// "גן" is a garden.
		$garden = self::factory()->post->create(
			array(
				'post_title'   => 'שתילה',
				'post_content' => 'שתלתי אוקה, גן קטן.',
			)
		);
		$roots  = self::factory()->post->create(
			array(
				'post_title'   => 'שורשים',
				'post_content' => 'האוקה גדלה, גן ירוק.',
			)
		);

		$corpus = undergrowth_build_corpus( array( $garden, $roots ) );

		$this->assertSame( 2, $corpus['frequency']['גן'] );
	}

	/**
	 * Checks which short words count as tokens.
	 *
	 * @dataProvider data_short_words
	 *
	 * @param string   $text   Text to break into words.
	 * @param string[] $tokens Words it gives.
	 */
	public function test_short_words_count_outside_latin_and_cyrillic( string $text, array $tokens ) {
		$this->assertSame( $tokens, undergrowth_tokens( $text ) );
	}

	/**
	 * Text with two-letter words, and the words it gives.
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public function data_short_words(): array {
		return array(
			'English'  => array( 'an ox ate it', array( 'ate' ) ),
			'Cyrillic' => array( 'он ел суп', array( 'суп' ) ),
			'numbers'  => array( 'in 12 or 365 days', array( '365', 'days' ) ),
			'Hebrew'   => array( 'גן ירק', array( 'גן', 'ירק' ) ),
			'Arabic'   => array( 'من بستان', array( 'من', 'بستان' ) ),
			'Korean'   => array( '감자 밭', array( '감자' ) ),
		);
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

	public function test_corpus_is_the_same_whatever_the_chunk_size() {
		global $wpdb;

		$ids     = self::factory()->post->create_many( 5, array( 'post_content' => 'Oca and yacon grow in the cool hills.' ) );
		$queries = $wpdb->num_queries;
		$full    = undergrowth_build_corpus( $ids );
		$once    = $wpdb->num_queries - $queries;

		add_filter(
			'undergrowth_post_chunk_size',
			static function () {
				return 2;
			}
		);
		$queries = $wpdb->num_queries;
		$chunked = undergrowth_build_corpus( $ids );

		$this->assertSame( 5, $full['documents'] );
		$this->assertSame( $full, $chunked );
		$this->assertSame( $once + 2, $wpdb->num_queries - $queries );
	}

	public function test_chunks_keep_keys_and_order_and_split_at_the_size() {
		$this->assertSame(
			array( array( 7 => 'a', 3 => 'b' ), array( 9 => 'c' ) ),
			iterator_to_array( undergrowth_chunks( array( 7 => 'a', 3 => 'b', 9 => 'c' ), 2, true ), false )
		);
		$this->assertSame(
			array( array( 'a', 'b' ), array( 'c' ) ),
			iterator_to_array( undergrowth_chunks( array( 7 => 'a', 3 => 'b', 9 => 'c' ), 2 ), false )
		);
		$this->assertSame( array(), iterator_to_array( undergrowth_chunks( array(), 2 ), false ) );
	}

	public function test_chunks_are_built_one_at_a_time() {
		$read  = 0;
		$items = ( static function () use ( &$read ) {
			for ( $i = 0; $i < 100000; $i++ ) {
				++$read;
				yield $i;
			}
		} )();

		foreach ( undergrowth_chunks( $items, 1 ) as $chunk ) {
			$this->assertSame( array( 0 ), $chunk );
			break;
		}

		$this->assertSame( 1, $read );
	}

	public function test_chunk_sizes_follow_their_filters_and_stay_positive() {
		$this->assertSame( UNDERGROWTH_POST_CHUNK, undergrowth_post_chunk_size() );
		$this->assertSame( UNDERGROWTH_VECTOR_CHUNK, undergrowth_vector_chunk_size() );

		add_filter( 'undergrowth_post_chunk_size', '__return_zero' );
		add_filter(
			'undergrowth_vector_chunk_size',
			static function () {
				return 7;
			}
		);

		$this->assertSame( 1, undergrowth_post_chunk_size() );
		$this->assertSame( 7, undergrowth_vector_chunk_size() );
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

	public function test_anchor_terms_are_only_words_the_text_uses() {
		$oca   = self::factory()->post->create(
			array(
				'post_title'   => 'Oca',
				'post_content' => 'Oca likes frost.',
			)
		);
		$yacon = self::factory()->post->create(
			array(
				'post_title'   => 'Yacon',
				'post_content' => 'Yacon likes frost and sweet syrup.',
			)
		);

		$terms = undergrowth_anchor_terms( $yacon, 'Oca likes frost.', undergrowth_build_corpus( array( $oca, $yacon ) ) );

		$this->assertSame( array( 'frost', 'likes' ), array_keys( $terms ) );
	}

	public function test_anchor_terms_weigh_how_often_the_target_uses_a_word() {
		$oca    = self::factory()->post->create(
			array(
				'post_title'   => 'Oca',
				'post_content' => 'Tubers and frost and syrup.',
			)
		);
		$yacon  = self::factory()->post->create(
			array(
				'post_title'   => 'Yacon',
				'post_content' => 'Tubers tubers tubers. Frost frost. Syrup.',
			)
		);
		$corpus = undergrowth_build_corpus( array( $oca, $yacon ) );

		$terms  = undergrowth_anchor_terms( $yacon, get_post( $oca )->post_content, $corpus );
		$rarity = log( 3 / 2.5 ) ** 2;

		$this->assertSame( array( 'tubers', 'frost', 'syrup' ), array_keys( $terms ) );
		$this->assertEqualsWithDelta( 3 * $rarity, $terms['tubers'], 1e-12 );
		$this->assertEqualsWithDelta( 2 * $rarity, $terms['frost'], 1e-12 );
		$this->assertEqualsWithDelta( 0.25 * $rarity, $terms['syrup'], 1e-12 );
	}

	public function test_word_the_target_is_about_outranks_a_rarer_incidental_one() {
		$oca    = self::factory()->post->create( array( 'post_content' => 'Tubers grow in the vicinity.' ) );
		$yacon  = self::factory()->post->create( array( 'post_content' => str_repeat( 'Tubers store well. ', 6 ) . 'The vicinity matters.' ) );
		$other  = self::factory()->post->create( array( 'post_content' => 'Tubers again.' ) );
		$corpus = undergrowth_build_corpus( array( $oca, $yacon, $other ) );

		$rarest = undergrowth_distinctive_terms( $yacon, $corpus, 0 );

		$this->assertGreaterThan( $rarest['tubers'], $rarest['vicinity'] );
		$this->assertSame( 'tubers', array_key_first( undergrowth_anchor_terms( $yacon, get_post( $oca )->post_content, $corpus ) ) );
	}

	public function test_title_word_outranks_every_other_word() {
		$oca    = self::factory()->post->create( array( 'post_content' => 'Yacon grows in the vicinity, and frost frost frost.' ) );
		$yacon  = self::factory()->post->create(
			array(
				'post_title'   => 'Yacon',
				'post_content' => 'The vicinity of water. The vicinity of frost frost frost frost.',
			)
		);
		$others = self::factory()->post->create_many( 3, array( 'post_content' => 'Frost again.' ) );
		$corpus = undergrowth_build_corpus( array_merge( array( $oca, $yacon ), $others ) );

		$this->assertSame( 'yacon', array_key_first( undergrowth_anchor_terms( $yacon, get_post( $oca )->post_content, $corpus ) ) );
	}

	public function test_title_word_used_in_exactly_half_the_posts_gets_no_preference() {
		$oca    = self::factory()->post->create( array( 'post_content' => 'Yacon and frost frost frost.' ) );
		$yacon  = self::factory()->post->create(
			array(
				'post_title'   => 'Yacon',
				'post_content' => 'Frost frost frost.',
			)
		);
		$others = self::factory()->post->create_many( 2, array( 'post_content' => 'Snow.' ) );
		$corpus = undergrowth_build_corpus( array_merge( array( $oca, $yacon ), $others ) );

		$this->assertSame( array( 'frost', 'yacon' ), array_keys( undergrowth_anchor_terms( $yacon, get_post( $oca )->post_content, $corpus ) ) );
	}

	public function test_title_word_used_in_half_the_posts_gets_no_preference() {
		$oca    = self::factory()->post->create(
			array(
				'post_title'   => 'Oca notes',
				'post_content' => 'Notes on tubers: they like frost.',
			)
		);
		$yacon  = self::factory()->post->create(
			array(
				'post_title'   => 'Yacon notes',
				'post_content' => 'Tubers tubers tubers like frost.',
			)
		);
		$corpus = undergrowth_build_corpus( array( $oca, $yacon ) );

		$this->assertSame( array( 'tubers', 'frost', 'notes' ), array_keys( undergrowth_anchor_terms( $yacon, get_post( $oca )->post_content, $corpus ) ) );
	}

	public function test_text_sharing_no_word_with_the_target_has_no_anchor_terms() {
		$oca = self::factory()->post->create( array( 'post_content' => 'Oca likes frost.' ) );

		$this->assertSame( array(), undergrowth_anchor_terms( $oca, 'Yacon syrup.', undergrowth_build_corpus( array( $oca ) ) ) );
	}

	public function test_post_outside_the_corpus_has_no_anchor_terms() {
		$oca = self::factory()->post->create( array( 'post_content' => 'Oca likes frost.' ) );

		$this->assertSame( array(), undergrowth_anchor_terms( PHP_INT_MAX, 'Oca likes frost.', undergrowth_build_corpus( array( $oca ) ) ) );
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
				// The sentence's place among the post's sentences, as the
				// sentence_index column recorded it before #339 dropped it.
				$index = -1;

				foreach ( undergrowth_split_sentences( get_post( (int) $row[0] )->post_content ) as $i => $sentence ) {
					if ( $row[2] === $sentence['text'] && ! $sentence['linked'] && ! $sentence['quoted'] ) {
						$index = $i;
						break;
					}
				}

				return array( $positions[ (int) $row[0] ], $positions[ (int) $row[1] ], $index );
			},
			(array) $wpdb->get_results(
				$wpdb->prepare( 'SELECT source_id, target_id, sentence FROM %i ORDER BY source_id, target_id', undergrowth_links_table() ),
				ARRAY_N
			)
		);

		$this->assertCount( 33, $rows );
		// Recorded from the corpus that kept every post's word counts, and
		// recorded again when anchor words began to follow what the target
		// is about (#172), when one post's proposals began to prefer
		// different sentences (#173), and when weak anchor words stopped
		// counting (#172 again).
		$this->assertSame( 'f1d010f08a4fb2031bbc0fe9885723bc', md5( (string) wp_json_encode( $rows ) ) );
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

	public function test_open_targets_are_the_same_whatever_the_chunk_size() {
		global $wpdb;

		add_filter(
			'undergrowth_min_link_similarity',
			static function () {
				return -1.0;
			}
		);

		$source = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->plant( 'Yacon ' . $i, array( 1.0, $i / 10, 0.0 ) );
		}

		$draft = $this->plant( 'Mashua', array( 1.0, 0.05, 0.0 ) );
		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'draft',
			)
		);

		$queries = $wpdb->num_queries;
		$full    = undergrowth_test_rank_targets( $source, null, 0 );
		$once    = $wpdb->num_queries - $queries;

		add_filter(
			'undergrowth_post_chunk_size',
			static function () {
				return 1;
			}
		);
		$queries = $wpdb->num_queries;
		$chunked = undergrowth_test_rank_targets( $source, null, 0 );

		$this->assertCount( 5, $full );
		$this->assertSame( $full, $chunked );
		$this->assertGreaterThan( $once, $wpdb->num_queries - $queries );
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

		$this->assertSame( array_slice( $best, 0, 2 ), array_keys( undergrowth_test_rank_targets( $source, null, 2 ) ) );
	}

	public function test_reused_corpus_is_counted_once_for_the_same_posts() {
		global $wpdb;

		$ids   = array(
			self::factory()->post->create( array( 'post_content' => 'Oca tubers.' ) ),
			self::factory()->post->create( array( 'post_content' => 'Yacon roots.' ) ),
		);
		$first = undergrowth_reusable_corpus( $ids );
		$count = $wpdb->num_queries;

		$this->assertSame( $first, undergrowth_reusable_corpus( array_reverse( $ids ) ) );
		$this->assertSame( $count, $wpdb->num_queries );
		$this->assertSame( undergrowth_build_corpus( $ids ), $first );
	}

	public function test_reused_corpus_is_counted_again_for_other_posts() {
		$oca   = self::factory()->post->create( array( 'post_content' => 'Oca tubers.' ) );
		$yacon = self::factory()->post->create( array( 'post_content' => 'Yacon roots.' ) );

		undergrowth_reusable_corpus( array( $oca, $yacon ) );

		$this->assertSame( undergrowth_build_corpus( array( $oca ) ), undergrowth_reusable_corpus( array( $oca ) ) );
	}

	public function test_reused_corpus_is_counted_again_after_a_post_changes() {
		$oca   = self::factory()->post->create( array( 'post_content' => 'Oca tubers.' ) );
		$yacon = self::factory()->post->create( array( 'post_content' => 'Yacon roots.' ) );

		undergrowth_reusable_corpus( array( $oca, $yacon ) );
		wp_update_post(
			array(
				'ID'           => $yacon,
				'post_content' => 'Mashua leaves.',
			)
		);

		$this->assertArrayHasKey( 'mashua', undergrowth_reusable_corpus( array( $oca, $yacon ) )['frequency'] );
	}

	public function test_reused_corpus_is_counted_again_on_another_site() {
		global $wpdb;

		$ids = array( self::factory()->post->create( array( 'post_content' => 'Oca tubers.' ) ) );

		undergrowth_reusable_corpus( $ids );
		// Another site's posts table can hold the same IDs.
		do_action( 'switch_blog', 2, 1, 'switch' );
		$count = $wpdb->num_queries;
		undergrowth_reusable_corpus( $ids );

		$this->assertGreaterThan( $count, $wpdb->num_queries );
	}
}
