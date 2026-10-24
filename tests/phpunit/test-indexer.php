<?php
/**
 * Tests for embedding posts on save.
 *
 * @package Undergrowth
 */

/**
 * Indexing published posts.
 */
class Test_Indexer extends WP_UnitTestCase {

	/**
	 * Texts the test provider was asked to embed.
	 *
	 * @var string[]
	 */
	private $embedded = array();

	/**
	 * Model name the test provider reports.
	 *
	 * @var string
	 */
	private $model = 'test-model-v1';

	public function set_up() {
		parent::set_up();

		$this->embedded = array();
		$this->model    = 'test-model-v1';

		add_filter( 'undergrowth_embed_provider', array( $this, 'provider' ), 10, 2 );
		add_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );
	}

	/**
	 * Test provider: records the text and returns a fixed vector.
	 *
	 * @param float[]|null $vector Vector from an earlier callback.
	 * @param string       $text   Text to embed.
	 * @return float[]
	 */
	public function provider( $vector, $text ) {
		$this->embedded[] = $text;

		return array( 0.1, 0.2, 0.3 );
	}

	/**
	 * Test provider identity.
	 *
	 * @return array{provider: string, model: string}
	 */
	public function identity() {
		return array(
			'provider' => 'test-provider',
			'model'    => $this->model,
		);
	}

	/**
	 * Creates a published post.
	 *
	 * @param array<string, mixed> $args Post fields.
	 * @return int Post ID.
	 */
	private function publish( array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_title'   => 'Storing oca',
					'post_content' => '<!-- wp:paragraph --><p>Oca keeps <strong>well</strong> in a cool shed.</p><!-- /wp:paragraph -->',
					'post_status'  => 'publish',
				),
				$args
			)
		);
	}

	public function test_garden_query_finds_the_indexable_posts_as_ids() {
		$published = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'attachment',
			)
		);

		$query = new WP_Query( undergrowth_garden_query_args() );

		$this->assertSame( array( $published ), array_map( 'intval', $query->posts ) );
	}

	public function test_garden_query_arguments_can_be_replaced() {
		$args = undergrowth_garden_query_args(
			array(
				'post_type'     => 'page',
				'no_found_rows' => false,
			)
		);

		$this->assertSame( 'page', $args['post_type'] );
		$this->assertFalse( $args['no_found_rows'] );
		$this->assertFalse( $args['has_password'] );
		$this->assertSame( 'ids', $args['fields'] );
	}

	public function test_post_text_decodes_entities() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Seasoning',
				'post_content' => '<!-- wp:paragraph --><p>Salt &amp; pepper&nbsp;mix, it&#8217;s simple.</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertSame( "Seasoning\n\nSalt & pepper mix, it\u{2019}s simple.", undergrowth_post_text( get_post( $post_id ) ) );
	}

	public function test_entities_are_not_counted_as_words() {
		$post_id = self::factory()->post->create( array( 'post_content' => '<p>Salt &amp; pepper&nbsp;mix, it&#8217;s simple.</p>' ) );

		$counts = undergrowth_post_token_counts( get_post( $post_id ) );

		$this->assertArrayNotHasKey( 'amp', $counts );
		$this->assertArrayNotHasKey( 'nbsp', $counts );
		$this->assertArrayNotHasKey( '8217', $counts );
	}

	public function test_publishing_schedules_an_embed() {
		$post_id = $this->publish();

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_embed_post', array( $post_id ) ) );
	}

	public function test_publishing_does_not_embed_during_the_save_request() {
		$this->publish();

		$this->assertSame( array(), $this->embedded );
	}

	public function test_draft_is_not_scheduled() {
		$post_id = $this->publish( array( 'post_status' => 'draft' ) );

		$this->assertFalse( wp_next_scheduled( 'undergrowth_embed_post', array( $post_id ) ) );
	}

	public function test_page_is_not_indexed_by_default() {
		$post_id = $this->publish( array( 'post_type' => 'page' ) );

		$this->assertFalse( wp_next_scheduled( 'undergrowth_embed_post', array( $post_id ) ) );
	}

	public function test_post_types_are_filterable() {
		add_filter(
			'undergrowth_post_types',
			static function () {
				return array( 'post', 'page' );
			}
		);

		$post_id = $this->publish( array( 'post_type' => 'page' ) );

		$this->assertNotFalse( wp_next_scheduled( 'undergrowth_embed_post', array( $post_id ) ) );
	}

	public function test_embed_stores_vector_with_provider_model_and_language() {
		$post_id = $this->publish();

		undergrowth_embed_post( $post_id );

		$row = undergrowth_get_vector( $post_id );
		$this->assertSame( array( 0.1, 0.2, 0.3 ), $row['vector'] );
		$this->assertSame( 'test-provider', $row['provider'] );
		$this->assertSame( 'test-model-v1', $row['model'] );
		$this->assertSame( get_locale(), $row['language'] );
		$this->assertSame( 3, $row['dimensions'] );
	}

	public function test_embedded_text_is_title_and_body_without_markup() {
		$post_id = $this->publish();

		undergrowth_embed_post( $post_id );

		$this->assertSame( array( "Storing oca\n\nOca keeps well in a cool shed." ), $this->embedded );
	}

	public function test_shortcodes_are_not_embedded() {
		$post_id = $this->publish( array( 'post_content' => 'Before [gallery ids="1,2"] after' ) );

		undergrowth_embed_post( $post_id );

		$this->assertStringNotContainsString( 'gallery', $this->embedded[0] );
	}

	public function test_unchanged_content_is_not_embedded_again() {
		$post_id = $this->publish();

		undergrowth_embed_post( $post_id );
		undergrowth_embed_post( $post_id );

		$this->assertCount( 1, $this->embedded );
	}

	public function test_changed_content_is_embedded_again() {
		$post_id = $this->publish();
		undergrowth_embed_post( $post_id );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Oca also freezes, once blanched.',
			)
		);
		undergrowth_embed_post( $post_id );

		$this->assertCount( 2, $this->embedded );
	}

	public function test_changed_model_is_embedded_again() {
		$post_id = $this->publish();
		undergrowth_embed_post( $post_id );

		$this->model = 'test-model-v2';
		undergrowth_embed_post( $post_id );

		$this->assertCount( 2, $this->embedded );
		$this->assertSame( 'test-model-v2', undergrowth_get_vector( $post_id )['model'] );
	}

	public function test_failed_embed_after_a_model_change_keeps_the_old_vector() {
		$post_id = $this->publish();
		undergrowth_embed_post( $post_id );

		$this->model = 'test-model-v2';
		remove_filter( 'undergrowth_embed_provider', array( $this, 'provider' ), 10 );
		undergrowth_embed_post( $post_id );

		$this->assertSame( 'test-model-v1', undergrowth_get_vector( $post_id )['model'] );
	}

	public function test_failed_embed_of_changed_content_removes_the_old_vector() {
		$post_id = $this->publish();
		undergrowth_embed_post( $post_id );

		remove_filter( 'undergrowth_embed_provider', array( $this, 'provider' ), 10 );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Oca now lives in sand.',
			)
		);
		undergrowth_embed_post( $post_id );

		$this->assertNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_language_is_filterable_for_multilingual_sites() {
		add_filter(
			'undergrowth_post_language',
			static function () {
				return 'de_DE';
			}
		);
		$post_id = $this->publish();

		undergrowth_embed_post( $post_id );

		$this->assertSame( 'de_DE', undergrowth_get_vector( $post_id )['language'] );
	}

	public function test_language_is_cut_to_fit_its_column() {
		add_filter(
			'undergrowth_post_language',
			static function () {
				return 'en_GB-x-a-very-long-variant';
			}
		);
		$post_id = $this->publish();

		undergrowth_embed_post( $post_id );
		undergrowth_embed_post( $post_id );

		$this->assertSame( 20, strlen( undergrowth_get_vector( $post_id )['language'] ) );
		$this->assertCount( 1, $this->embedded );
	}

	public function test_long_languages_that_start_alike_stay_apart() {
		$this->assertNotSame( undergrowth_fit_language( 'en_GB-x-a-very-long-variant' ), undergrowth_fit_language( 'en_GB-x-a-very-long-other-one' ) );
		$this->assertSame( 'en_GB', undergrowth_fit_language( 'en_GB' ) );
	}

	public function test_changed_language_is_embedded_again() {
		$post_id = $this->publish();
		undergrowth_embed_post( $post_id );

		add_filter(
			'undergrowth_post_language',
			static function () {
				return 'de_DE';
			}
		);
		undergrowth_embed_post( $post_id );

		$this->assertCount( 2, $this->embedded );
	}

	public function test_provider_without_identity_stores_nothing() {
		remove_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );
		$post_id = $this->publish();

		undergrowth_embed_post( $post_id );

		$this->assertSame( array(), $this->embedded );
		$this->assertNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_empty_provider_vector_stores_nothing() {
		remove_filter( 'undergrowth_embed_provider', array( $this, 'provider' ), 10 );
		$post_id = $this->publish();

		undergrowth_embed_post( $post_id );

		$this->assertNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_password_protected_post_is_not_embedded() {
		$post_id = $this->publish( array( 'post_password' => 'secret' ) );

		undergrowth_embed_post( $post_id );

		$this->assertSame( array(), $this->embedded );
		$this->assertNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_adding_a_password_removes_the_vector() {
		$post_id = $this->publish();
		undergrowth_embed_post( $post_id );

		wp_update_post(
			array(
				'ID'            => $post_id,
				'post_password' => 'secret',
			)
		);

		$this->assertNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_unpublishing_removes_vector_and_pending_embed() {
		$post_id = $this->publish();
		undergrowth_embed_post( $post_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$this->assertNull( undergrowth_get_vector( $post_id ) );
		$this->assertFalse( wp_next_scheduled( 'undergrowth_embed_post', array( $post_id ) ) );
	}

	public function test_trashing_removes_vector() {
		$post_id = $this->publish();
		undergrowth_embed_post( $post_id );

		wp_trash_post( $post_id );

		$this->assertNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_deleting_removes_vector() {
		$post_id = $this->publish();
		undergrowth_embed_post( $post_id );

		wp_delete_post( $post_id, true );

		$this->assertNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_embed_for_a_post_unpublished_since_scheduling_stores_nothing() {
		$post_id = $this->publish();
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );

		undergrowth_embed_post( $post_id );

		$this->assertNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_updating_a_post_keeps_its_cluster_signal_and_vector() {
		$post_id = $this->publish();
		undergrowth_embed_post( $post_id );
		update_post_meta( $post_id, 'undergrowth_cluster', $post_id );
		update_post_meta( $post_id, 'undergrowth_resurfacing', 7 );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Oca keeps better in dry sand.',
			)
		);

		$this->assertNotEmpty( wp_get_post_revisions( $post_id ) );
		$this->assertSame( $post_id, (int) get_post_meta( $post_id, 'undergrowth_cluster', true ) );
		$this->assertSame( 7, undergrowth_resurfacing_signal( $post_id ) );
		$this->assertNotNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_deleting_a_revision_keeps_its_post_in_the_index() {
		$post_id = $this->publish();
		undergrowth_embed_post( $post_id );
		update_post_meta( $post_id, 'undergrowth_cluster', $post_id );
		update_post_meta( $post_id, 'undergrowth_resurfacing', 7 );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Oca keeps better in dry sand.',
			)
		);

		wp_delete_post_revision( key( wp_get_post_revisions( $post_id ) ) );

		$this->assertSame( $post_id, (int) get_post_meta( $post_id, 'undergrowth_cluster', true ) );
		$this->assertSame( 7, undergrowth_resurfacing_signal( $post_id ) );
		$this->assertNotNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_autosave_keeps_the_cluster_of_its_post() {
		$post_id = $this->publish();
		update_post_meta( $post_id, 'undergrowth_cluster', $post_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$autosave = wp_create_post_autosave(
			array(
				'post_ID'      => $post_id,
				'post_type'    => 'post',
				'post_title'   => 'Storing oca',
				'post_content' => 'A draft of a change.',
			)
		);

		$this->assertIsInt( $autosave );
		$this->assertGreaterThan( 0, $autosave );

		$this->assertSame( $post_id, (int) get_post_meta( $post_id, 'undergrowth_cluster', true ) );
	}

	public function test_saving_a_post_type_that_is_never_indexed_announces_nothing() {
		$left = array();
		add_action(
			'undergrowth_post_left_index',
			static function ( $post_id ) use ( &$left ) {
				$left[] = $post_id;
			}
		);

		self::factory()->post->create( array( 'post_type' => 'wp_block' ) );

		$this->assertSame( array(), $left );
	}

	public function test_post_moved_to_a_type_that_is_not_indexed_leaves_the_index() {
		$post_id = $this->publish();
		undergrowth_embed_post( $post_id );

		wp_update_post(
			array(
				'ID'        => $post_id,
				'post_type' => 'page',
			)
		);

		$this->assertNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_post_moved_to_a_type_that_is_not_indexed_before_its_embed_is_not_embedded() {
		$post_id = $this->publish();

		wp_update_post(
			array(
				'ID'        => $post_id,
				'post_type' => 'page',
			)
		);

		$this->assertFalse( wp_next_scheduled( 'undergrowth_embed_post', array( $post_id ) ) );
	}

	public function test_embed_for_a_missing_post_does_nothing() {
		undergrowth_embed_post( PHP_INT_MAX );

		$this->assertSame( array(), $this->embedded );
	}

	public function test_indexable_sql_picks_the_posts_undergrowth_is_indexable_picks() {
		global $wpdb;

		$post_ids = array(
			self::factory()->post->create(),
			self::factory()->post->create( array( 'post_status' => 'draft' ) ),
			self::factory()->post->create( array( 'post_password' => 'secret' ) ),
			self::factory()->post->create( array( 'post_type' => 'page' ) ),
		);

		list( $where, $args ) = undergrowth_indexable_sql( 'p.' );

		$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
		$picked       = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM %i p WHERE p.ID IN ({$placeholders}) AND {$where} ORDER BY p.ID", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders built above.
					array_merge( array( $wpdb->posts ), $post_ids, $args )
				)
			)
		);

		$expected = array_values(
			array_filter(
				$post_ids,
				static function ( int $post_id ): bool {
					return undergrowth_is_indexable( get_post( $post_id ) );
				}
			)
		);
		$this->assertSame( array( $post_ids[0] ), $expected );
		$this->assertSame( $expected, $picked );
	}

	public function test_indexable_sql_is_null_with_no_post_types() {
		add_filter( 'undergrowth_post_types', '__return_empty_array' );

		$this->assertNull( undergrowth_indexable_sql( 'p.' ) );
	}
}
