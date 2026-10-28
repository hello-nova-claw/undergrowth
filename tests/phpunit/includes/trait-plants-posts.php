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
	 * Hooks the test provider and its identity, and removes any per-post
	 * files an earlier test left.
	 *
	 * Rolled-back tests reuse post IDs, so a file left behind would be read
	 * as the new post's.
	 */
	private function start_planting(): void {
		$this->vectors = array();

		undergrowth_delete_memory_folder();

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
	 * Content for a planted post.
	 *
	 * Every planted post shares each sentence's opening word with every
	 * other, and each sentence opens differently, so a post's suggestions
	 * can sit on words of their own: two never share the same words of one
	 * sentence.
	 *
	 * @param string $title Post title.
	 * @return string
	 */
	private static function plant_content( string $title ): string {
		return 'Notes about ' . $title . '. Seedlings near ' . $title . '. Baskets beside ' . $title . '. Mulch around ' . $title . '.';
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
					'post_content' => self::plant_content( $title ),
					'post_status'  => 'publish',
				),
				$args
			)
		);

		undergrowth_embed_post( $post_id );

		return $post_id;
	}

	/**
	 * Publishes a post and stores a known vector for it, firing nothing.
	 *
	 * Unlike plant(), no embed runs, so no proposals are refreshed, no file
	 * is written and nothing is queued for Discord. Use it wherever those
	 * are not under test: planting many posts stays linear.
	 *
	 * @param string               $title  Post title, also the vector's key; empty for the factory's own.
	 * @param float[]              $vector Vector to store.
	 * @param array<string, mixed> $args   Other post fields.
	 * @param array<string, mixed> $meta   Provider, model, language or content hash to store instead.
	 * @return int Post ID.
	 */
	private function seed( string $title, array $vector, array $args = array(), array $meta = array() ): int {
		$fields = array(
			'post_content' => self::plant_content( '' === $title ? 'a post' : $title ),
			'post_status'  => 'publish',
		);

		if ( '' !== $title ) {
			$fields['post_title']    = $title;
			$this->vectors[ $title ] = $vector;
		}

		// Publishing would queue an embed; the vector is stored here instead.
		$priority = has_action( 'wp_after_insert_post', 'undergrowth_queue_embed' );

		if ( false !== $priority ) {
			remove_action( 'wp_after_insert_post', 'undergrowth_queue_embed', $priority );
		}

		$post_id = self::factory()->post->create( array_merge( $fields, $args ) );

		if ( false !== $priority ) {
			add_action( 'wp_after_insert_post', 'undergrowth_queue_embed', $priority, 2 );
		}

		$post     = get_post( $post_id );
		$identity = undergrowth_embed_identity();

		// As an embed would store it: under the site's provider and model,
		// or the test provider's when none is set.
		if ( ! undergrowth_identity_is_complete( $identity ) ) {
			$identity = $this->identity();
		}

		undergrowth_save_vector(
			$post_id,
			$vector,
			array_merge(
				array(
					'provider'     => $identity['provider'],
					'model'        => $identity['model'],
					'language'     => undergrowth_post_language( $post ),
					'content_hash' => hash( 'sha256', undergrowth_post_text( $post ) ),
				),
				$meta
			)
		);

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
