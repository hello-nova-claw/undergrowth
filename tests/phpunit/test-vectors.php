<?php
/**
 * Tests for storing and reading vectors.
 *
 * @package Undergrowth
 */

/**
 * Vector storage.
 */
class Test_Vectors extends WP_UnitTestCase {

	/**
	 * Metadata for a vector made by a test model.
	 *
	 * @param array<string, string> $overrides Fields to change.
	 * @return array{provider: string, model: string, language: string, content_hash: string}
	 */
	private function meta( array $overrides = array() ): array {
		return array_merge(
			array(
				'provider'     => 'test-provider',
				'model'        => 'test-model-v1',
				'language'     => 'en_US',
				'content_hash' => str_repeat( 'a', 64 ),
			),
			$overrides
		);
	}

	public function test_saved_vector_reads_back_with_its_metadata() {
		$post_id = self::factory()->post->create();

		$this->assertTrue( undergrowth_save_vector( $post_id, array( 0.25, -1.5, 3.0 ), $this->meta() ) );

		$row = undergrowth_get_vector( $post_id );
		$this->assertSame( $post_id, $row['post_id'] );
		$this->assertSame( array( 0.25, -1.5, 3.0 ), $row['vector'] );
		$this->assertSame( 3, $row['dimensions'] );
		$this->assertSame( 'test-provider', $row['provider'] );
		$this->assertSame( 'test-model-v1', $row['model'] );
		$this->assertSame( 'en_US', $row['language'] );
		$this->assertSame( str_repeat( 'a', 64 ), $row['content_hash'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['embedded_at'] );
	}

	public function test_missing_vector_reads_as_null() {
		$this->assertNull( undergrowth_get_vector( PHP_INT_MAX ) );
	}

	public function test_vector_space_reads_back_without_the_vector() {
		$post_id = self::factory()->post->create();
		undergrowth_save_vector( $post_id, array( 0.25, -1.5, 3.0 ), $this->meta() );

		$this->assertSame(
			array(
				'provider' => 'test-provider',
				'model'    => 'test-model-v1',
				'language' => 'en_US',
			),
			undergrowth_get_vector_space( $post_id )
		);
	}

	public function test_missing_vector_space_reads_as_null() {
		$this->assertNull( undergrowth_get_vector_space( PHP_INT_MAX ) );
	}

	public function test_saving_again_replaces_the_post_row() {
		global $wpdb;

		$post_id = self::factory()->post->create();
		undergrowth_save_vector( $post_id, array( 1.0, 2.0 ), $this->meta() );
		undergrowth_save_vector( $post_id, array( 3.0, 4.0, 5.0 ), $this->meta( array( 'model' => 'test-model-v2' ) ) );

		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id = %d', undergrowth_vectors_table(), $post_id ) );

		$this->assertSame( 1, $count );
		$this->assertSame( 'test-model-v2', undergrowth_get_vector( $post_id )['model'] );
		$this->assertSame( array( 3.0, 4.0, 5.0 ), undergrowth_get_vector( $post_id )['vector'] );
	}

	public function test_vector_without_provider_is_refused() {
		$post_id = self::factory()->post->create();

		$this->assertFalse( undergrowth_save_vector( $post_id, array( 1.0 ), $this->meta( array( 'provider' => '' ) ) ) );
		$this->assertNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_vector_without_model_is_refused() {
		$post_id = self::factory()->post->create();

		$this->assertFalse( undergrowth_save_vector( $post_id, array( 1.0 ), $this->meta( array( 'model' => '' ) ) ) );
		$this->assertNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_vector_without_language_is_refused() {
		$post_id = self::factory()->post->create();

		$this->assertFalse( undergrowth_save_vector( $post_id, array( 1.0 ), $this->meta( array( 'language' => '' ) ) ) );
		$this->assertNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_empty_vector_is_refused() {
		$post_id = self::factory()->post->create();

		$this->assertFalse( undergrowth_save_vector( $post_id, array(), $this->meta() ) );
	}

	/**
	 * Vectors that cannot be stored faithfully.
	 *
	 * @return array<string, array{0: array<mixed>}>
	 */
	public function data_invalid_vectors(): array {
		return array(
			'not a number'   => array( array( 1.0, NAN ) ),
			'infinity'       => array( array( INF ) ),
			'numeric string' => array( array( 1.0, '0.5' ) ),
			'keyed array'    => array( array( 'a' => 1.0 ) ),
		);
	}

	/**
	 * Saving is refused rather than writing an empty or altered embedding.
	 *
	 * @dataProvider data_invalid_vectors
	 *
	 * @param array<mixed> $vector Vector to save.
	 */
	public function test_invalid_vector_is_refused( array $vector ) {
		$post_id = self::factory()->post->create();

		$this->assertFalse( undergrowth_save_vector( $post_id, $vector, $this->meta() ) );
		$this->assertNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_deleted_vector_is_gone() {
		$post_id = self::factory()->post->create();
		undergrowth_save_vector( $post_id, array( 1.0 ), $this->meta() );

		undergrowth_delete_vector( $post_id );

		$this->assertNull( undergrowth_get_vector( $post_id ) );
	}

	public function test_stored_embedding_decodes_to_floats() {
		$this->assertSame( array( 0.5, 1.0, -2.0 ), undergrowth_decode_embedding( '[0.5,1,-2]' ) );
	}

	public function test_stored_embedding_that_is_not_a_vector_decodes_to_nothing() {
		$this->assertSame( array(), undergrowth_decode_embedding( '{"a":1}' ) );
		$this->assertSame( array(), undergrowth_decode_embedding( '["x"]' ) );
		$this->assertSame( array(), undergrowth_decode_embedding( 'not json' ) );
	}

	public function test_vectors_share_a_space_only_with_the_same_provider_model_and_language() {
		$row = array(
			'provider' => 'openai',
			'model'    => 'text-embedding-3-small',
			'language' => 'en_US',
		);

		$this->assertSame( undergrowth_vector_space( $row ), undergrowth_vector_space( $row ) );

		foreach ( array( 'provider', 'model', 'language' ) as $field ) {
			$other           = $row;
			$other[ $field ] = 'other';
			$this->assertNotSame( undergrowth_vector_space( $row ), undergrowth_vector_space( $other ), $field );
		}
	}
}
