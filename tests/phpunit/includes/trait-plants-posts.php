<?php
/**
 * Test helper: publishes posts whose vectors are known in advance.
 *
 * @package Undergrowth
 */

/**
 * Publishes posts with hand-made vectors through the provider filter.
 *
 * A test class using this calls start_planting() from set_up().
 */
trait Plants_Posts {

	/**
	 * Vector the test provider returns for each post title.
	 *
	 * @var array<string, float[]>
	 */
	private $vectors = array();

	/**
	 * Hooks the test provider and its identity.
	 */
	private function start_planting(): void {
		$this->vectors = array();

		add_filter( 'undergrowth_embed_provider', array( $this, 'provider' ), 10, 2 );
		add_filter( 'undergrowth_embed_identity', array( $this, 'identity' ) );
	}

	/**
	 * Test provider: returns the vector registered for the post's title.
	 *
	 * @param float[]|null $vector Vector from an earlier callback.
	 * @param string       $text   Title and body of the post.
	 * @return float[]|null
	 */
	public function provider( $vector, $text ) {
		$title = strtok( $text, "\n" );

		return $this->vectors[ $title ] ?? null;
	}

	/**
	 * Test provider identity.
	 *
	 * @return array{provider: string, model: string}
	 */
	public function identity() {
		return array(
			'provider' => 'test-provider',
			'model'    => 'test-model',
		);
	}

	/**
	 * Publishes a post with a known vector and embeds it.
	 *
	 * @param string               $title  Post title, also the vector's key.
	 * @param float[]              $vector Vector the provider returns.
	 * @param array<string, mixed> $args   Other post fields.
	 * @return int Post ID.
	 */
	private function plant( string $title, array $vector, array $args = array() ): int {
		$this->vectors[ $title ] = $vector;

		$post_id = self::factory()->post->create(
			array_merge(
				array(
					'post_title'   => $title,
					'post_content' => 'Notes about ' . $title . '.',
					'post_status'  => 'publish',
				),
				$args
			)
		);

		undergrowth_embed_post( $post_id );

		return $post_id;
	}

	/**
	 * Returns a post's proposed targets, best first.
	 *
	 * @param int $source_id Source post ID.
	 * @return int[]
	 */
	private function proposed( int $source_id ): array {
		global $wpdb;

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT target_id FROM %i WHERE source_id = %d AND state = %s ORDER BY score DESC',
					undergrowth_links_table(),
					$source_id,
					'proposed'
				)
			)
		);
	}
}
