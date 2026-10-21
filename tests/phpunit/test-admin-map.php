<?php
/**
 * Tests for the Map tab on the link suggestions screen.
 *
 * @package Undergrowth
 */

/**
 * Map tab.
 */
class Test_Admin_Map extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		undergrowth_register_stage_meta();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	public function tear_down() {
		unset( $_GET['tab'] );

		parent::tear_down();
	}

	/**
	 * Renders the screen on a tab and returns its HTML.
	 *
	 * @param string $tab Tab to ask for.
	 * @return string
	 */
	private function render( string $tab = 'map' ): string {
		$_GET['tab'] = $tab;
		set_current_screen( 'posts_page_undergrowth-links' );
		ob_start();
		undergrowth_render_suggestions_page();

		return (string) ob_get_clean();
	}

	/**
	 * Publishes a post, stores its vector and puts it in a cluster.
	 *
	 * @param string  $title  Post title.
	 * @param float[] $vector Vector to store.
	 * @param int     $leader Leader of the cluster, or 0 for the post to lead.
	 * @return int Post ID.
	 */
	private function member( string $title, array $vector, int $leader = 0 ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

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

	/**
	 * Makes two clusters: a gap around Oca, and a dense one around Yacon.
	 *
	 * @return array<string, int> Post IDs keyed by title.
	 */
	private function plant_clusters(): array {
		$posts                 = array();
		$posts['Oca']          = $this->member( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$posts['Ulluco']       = $this->member( 'Ulluco', array( 0.0, 1.0, 0.0 ), $posts['Oca'] );
		$posts['Mashua']       = $this->member( 'Mashua', array( 0.0, 0.0, 1.0 ), $posts['Oca'] );
		$posts['Yacon']        = $this->member( 'Yacon', array( 0.0, 0.0, 1.0 ) );
		$posts['Yacon syrup']  = $this->member( 'Yacon syrup', array( 0.1, 0.0, 0.99 ), $posts['Yacon'] );
		$posts['Yacon leaves'] = $this->member( 'Yacon leaves', array( 0.0, 0.1, 0.99 ), $posts['Yacon'] );

		return $posts;
	}

	public function test_map_tab_is_missing_without_clusters() {
		$html = $this->render( '' );

		$this->assertStringNotContainsString( 'tab=map', $html );
	}

	public function test_asking_for_the_map_without_clusters_shows_the_suggestions() {
		$html = $this->render();

		$this->assertStringContainsString( 'name="undergrowth_suggest_links"', $html );
		$this->assertStringNotContainsString( 'undergrowth-cluster', $html );
	}

	public function test_map_tab_is_offered_once_there_is_a_cluster() {
		$this->plant_clusters();

		$this->assertMatchesRegularExpression( '/<a href="[^"]*tab=map[^"]*" class="nav-tab">Map<\/a>/', $this->render( '' ) );
		$this->assertMatchesRegularExpression( '/<a href="[^"]*tab=map[^"]*" class="nav-tab nav-tab-active" aria-current="page">Map<\/a>/', $this->render() );
	}

	public function test_each_cluster_is_a_heading_over_its_posts_with_their_stages() {
		$posts = $this->plant_clusters();
		undergrowth_set_stage( $posts['Yacon syrup'], 'budding' );

		$html = $this->render();

		$this->assertSame( 2, substr_count( $html, '<ul class="undergrowth-cluster"' ) );
		$this->assertStringContainsString( '<h2 id="undergrowth-cluster-' . $posts['Yacon'] . '">Cluster 2</h2>', $html );
		$this->assertStringContainsString( 'aria-labelledby="undergrowth-cluster-' . $posts['Yacon'] . '"', $html );
		$this->assertMatchesRegularExpression( '/<li><a href="[^"]*post\\.php\\?post=' . $posts['Yacon syrup'] . '&[^"]*action=edit">Yacon syrup<\\/a> \\(Budding\\)<\\/li>/', $html );

		foreach ( $posts as $title => $post_id ) {
			$this->assertStringContainsString( '>' . $title . '</a> (', $html );
		}
	}

	public function test_posts_are_listed_nearest_the_centre_first() {
		$this->plant_clusters();
		$html = $this->render();

		// Yacon sits nearest the middle; its two neighbours are equally far.
		$this->assertLessThan( strpos( $html, '>Yacon syrup<' ), strpos( $html, '>Yacon<' ) );
		$this->assertLessThan( strpos( $html, '>Yacon leaves<' ), strpos( $html, '>Yacon syrup<' ) );
	}

	public function test_gap_cluster_is_marked_in_its_heading_and_in_words() {
		$posts = $this->plant_clusters();
		$html  = $this->render();

		$this->assertStringContainsString( '<h2 id="undergrowth-cluster-' . $posts['Oca'] . '">Cluster 1, with a gap</h2>', $html );
		$this->assertSame( 1, substr_count( $html, 'class="undergrowth-gap"' ) );
		$this->assertMatchesRegularExpression( '/<p class="undergrowth-gap"><span class="dashicons dashicons-flag" aria-hidden="true"><\/span> [^<]+<\/p>/', $html );
	}

	public function test_gap_mark_names_no_post() {
		$this->plant_clusters();

		preg_match( '/<p class="undergrowth-gap">(.*?)<\/p>/', $this->render(), $gap );

		foreach ( array( 'Oca', 'Ulluco', 'Mashua', 'Yacon' ) as $title ) {
			$this->assertStringNotContainsString( $title, $gap[1] );
		}
	}

	public function test_composting_post_is_not_listed() {
		$posts = $this->plant_clusters();
		undergrowth_compost_post( $posts['Yacon leaves'] );

		$this->assertStringNotContainsString( 'Yacon leaves', $this->render() );
	}

	public function test_post_the_user_cannot_edit_is_listed_without_a_link() {
		$posts = $this->plant_clusters();
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) use ( $posts ) {
				return 'edit_post' === $cap && isset( $args[0] ) && $posts['Mashua'] === (int) $args[0] ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);

		$this->assertStringContainsString( '<li>Mashua (Seedling)</li>', $this->render() );
	}
}
