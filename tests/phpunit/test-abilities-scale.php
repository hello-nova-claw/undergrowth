<?php
/**
 * Tests for the read abilities at cluster and site scale.
 *
 * @package Undergrowth
 */

/**
 * Has, lacks, scent and stage for a cluster or the whole site.
 */
class Test_Abilities_Scale extends WP_UnitTestCase {

	use Plants_Posts;

	/**
	 * Editor who can read every post.
	 *
	 * @var int
	 */
	private $editor;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );
	}

	public function tear_down() {
		global $wp_rest_server;

		$wp_rest_server = null;
		undergrowth_delete_memory_folder();

		parent::tear_down();
	}

	/**
	 * Runs an ability as the current user.
	 *
	 * @param string $name  Ability name.
	 * @param mixed  $input Input.
	 * @return mixed Output, or WP_Error.
	 */
	private function run_ability( string $name, $input = null ) {
		return wp_get_ability( $name )->execute( $input );
	}

	/**
	 * Names of the read abilities.
	 *
	 * @return array<string, string[]>
	 */
	public function data_read_abilities() {
		return array(
			'has'   => array( 'undergrowth/has' ),
			'lacks' => array( 'undergrowth/lacks' ),
			'scent' => array( 'undergrowth/scent' ),
			'stage' => array( 'undergrowth/stage' ),
		);
	}

	/**
	 * Plants three posts about tubers in one cluster, and one about tax alone.
	 *
	 * Posts are written in full before they are clustered, since the test
	 * means to read the cluster as the monthly pass left it.
	 *
	 * @param array<string, array<string, mixed>> $args Other post fields, keyed by title.
	 * @return array<string, int> Post IDs keyed by title.
	 */
	private function plant_garden( array $args = array() ): array {
		$fields = array(
			'Oca'    => array( array( 1.0, 0.0, 0.0 ), '<p>Oca tubers sweeten after frost. Like yacon, oca is a tuber.</p>' ),
			'Yacon'  => array( array( 0.9, 0.1, 0.0 ), '<p>Yacon tubers make a sweet syrup. Like oca, yacon is a tuber.</p>' ),
			'Mashua' => array( array( 0.8, 0.2, 0.0 ), '<p>Mashua tubers taste of pepper. Like oca, mashua is a tuber.</p>' ),
			'Taxes'  => array( array( 0.0, 0.0, 1.0 ), '<p>Receipts and invoices, filed by March.</p>' ),
		);
		$posts  = array();

		foreach ( $fields as $title => list( $vector, $content ) ) {
			$posts[ $title ] = $this->plant( $title, $vector, ( $args[ $title ] ?? array() ) + array( 'post_content' => $content ) );
		}

		foreach ( array( 'Oca', 'Yacon', 'Mashua' ) as $title ) {
			update_post_meta( $posts[ $title ], 'undergrowth_cluster', $posts['Oca'] );
		}

		return $posts;
	}

	/**
	 * Runs for each read ability.
	 *
	 * @dataProvider data_read_abilities
	 *
	 * @param string $name Ability name.
	 */
	public function test_read_ability_answers_at_every_scale_with_one_shape( $name ) {
		$posts = $this->plant_garden();

		$post    = $this->run_ability( $name, array( 'post_id' => $posts['Oca'] ) );
		$cluster = $this->run_ability( $name, array( 'cluster' => $posts['Oca'] ) );
		$site    = $this->run_ability( $name );

		$this->assertSame( 'post', $post['scale'] );
		$this->assertSame( 'cluster', $cluster['scale'] );
		$this->assertSame( $posts['Oca'], $cluster['cluster'] );
		$this->assertSame( 'site', $site['scale'] );

		$shared = array_values( array_diff( array_keys( $post ), array( 'post_id', 'stage', 'pinned', 'changes' ) ) );

		$this->assertSame( $shared, array_values( array_diff( array_keys( $cluster ), array( 'cluster' ) ) ) );
		$this->assertSame( $shared, array_keys( $site ) );
	}

	/**
	 * Runs for each read ability.
	 *
	 * @dataProvider data_read_abilities
	 *
	 * @param string $name Ability name.
	 */
	public function test_read_ability_refuses_a_cluster_that_does_not_exist( $name ) {
		$posts = $this->plant_garden();

		$result = $this->run_ability( $name, array( 'cluster' => $posts['Taxes'] ) );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_no_cluster', $result->get_error_code() );
	}

	/**
	 * Runs for each read ability.
	 *
	 * @dataProvider data_read_abilities
	 *
	 * @param string $name Ability name.
	 */
	public function test_read_ability_refuses_the_site_to_a_subscriber( $name ) {
		$this->plant_garden();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertWPError( $this->run_ability( $name ) );
		$this->assertWPError( $this->run_ability( $name, array( 'cluster' => 1 ) ) );
	}

	public function test_has_for_a_cluster_lists_words_from_its_posts_and_their_links() {
		$syrup = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$posts = $this->plant_garden( array( 'Yacon' => array( 'post_content' => '<p>Yacon tubers make a sweet syrup, boiled as <a href="' . get_permalink( $syrup ) . '">I do</a>.</p>' ) ) );

		$has = $this->run_ability( 'undergrowth/has', array( 'cluster' => $posts['Oca'] ) );

		$this->assertContains( 'tubers', $has['words'] );
		$this->assertContains( 'frost', $has['words'] );
		$this->assertNotContains( 'receipts', $has['words'] );
		$this->assertSame( array( $syrup ), array_column( $has['links'], 'post_id' ) );
	}

	public function test_has_for_the_site_weighs_words_across_every_post() {
		$this->plant_garden();

		$has = $this->run_ability( 'undergrowth/has' );

		$this->assertContains( 'tubers', $has['words'] );
		$this->assertContains( 'receipts', $has['words'] );
		$this->assertLessThanOrEqual( UNDERGROWTH_DISTINCTIVE_TERMS, count( $has['words'] ) );
	}

	public function test_lacks_for_the_site_lists_every_open_suggestion_best_first_with_its_source() {
		global $wpdb;

		$this->plant_garden();

		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT source_id, target_id FROM %i WHERE state = %s ORDER BY score DESC, source_id ASC, target_id ASC', undergrowth_links_table(), 'proposed' ), ARRAY_A );
		$lacks = $this->run_ability( 'undergrowth/lacks' );

		$this->assertNotEmpty( $rows );
		$this->assertSame(
			array_map(
				static function ( $row ) {
					return array( (int) $row['source_id'], (int) $row['target_id'] );
				},
				$rows
			),
			array_map(
				static function ( $link ) {
					return array( $link['source_id'], $link['post_id'] );
				},
				$lacks['links']
			)
		);
	}

	public function test_lacks_for_a_cluster_lists_only_suggestions_from_its_posts() {
		$posts = $this->plant_garden();

		$lacks = $this->run_ability( 'undergrowth/lacks', array( 'cluster' => $posts['Oca'] ) );

		$this->assertNotEmpty( $lacks['links'] );
		$this->assertEmpty( array_diff( array_column( $lacks['links'], 'source_id' ), array( $posts['Oca'], $posts['Yacon'], $posts['Mashua'] ) ) );
	}

	public function test_scent_has_no_value_at_any_scale() {
		$posts = $this->plant_garden();

		$this->assertSame(
			array(
				'scale' => 'site',
				'mode'  => 'germination',
				'value' => null,
			),
			$this->run_ability( 'undergrowth/scent' )
		);
		$this->assertNull( $this->run_ability( 'undergrowth/scent', array( 'cluster' => $posts['Oca'] ) )['value'] );
	}

	public function test_stage_for_the_site_counts_posts_with_composting_apart() {
		$posts = $this->plant_garden();
		undergrowth_set_stage( $posts['Yacon'], 'budding' );
		undergrowth_compost_post( $posts['Taxes'] );

		$this->assertSame(
			array(
				'scale'  => 'site',
				'counts' => array(
					'seedling'   => 2,
					'sprout'     => 0,
					'budding'    => 1,
					'evergreen'  => 0,
					'composting' => 1,
				),
			),
			$this->run_ability( 'undergrowth/stage' )
		);
	}

	public function test_stage_for_a_cluster_counts_its_posts() {
		$posts = $this->plant_garden();
		undergrowth_set_stage( $posts['Mashua'], 'evergreen' );

		$this->assertSame(
			array(
				'seedling'   => 2,
				'sprout'     => 0,
				'budding'    => 0,
				'evergreen'  => 1,
				'composting' => 0,
			),
			$this->run_ability( 'undergrowth/stage', array( 'cluster' => $posts['Oca'] ) )['counts']
		);
	}

	public function test_author_sees_nothing_from_posts_they_cannot_edit() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$posts  = $this->plant_garden( array( 'Taxes' => array( 'post_author' => $author ) ) );
		// The author owns only the tax post; the tubers are the editor's.
		wp_set_current_user( $author );

		$has   = $this->run_ability( 'undergrowth/has' );
		$lacks = $this->run_ability( 'undergrowth/lacks' );
		$stage = $this->run_ability( 'undergrowth/stage' );

		$this->assertNotContains( 'tubers', $has['words'] );
		$this->assertContains( 'receipts', $has['words'] );
		$this->assertEmpty( array_diff( array_column( $lacks['links'], 'source_id' ), array( $posts['Taxes'] ) ) );
		$this->assertSame( 1, array_sum( $stage['counts'] ) );
		$this->assertSame( 'undergrowth_no_cluster', $this->run_ability( 'undergrowth/has', array( 'cluster' => $posts['Oca'] ) )->get_error_code() );
	}

	public function test_author_sees_only_their_own_posts_in_a_cluster() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$posts  = $this->plant_garden( array( 'Mashua' => array( 'post_author' => $author ) ) );
		wp_set_current_user( $author );

		$has   = $this->run_ability( 'undergrowth/has', array( 'cluster' => $posts['Oca'] ) );
		$stage = $this->run_ability( 'undergrowth/stage', array( 'cluster' => $posts['Oca'] ) );

		$this->assertContains( 'mashua', $has['words'] );
		$this->assertNotContains( 'yacon', $has['words'] );
		$this->assertSame( 1, array_sum( $stage['counts'] ) );
	}

	public function test_contributor_counts_no_published_post_at_site_scale() {
		$this->plant_garden();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->assertSame( array(), undergrowth_ability_site_posts() );
		$this->assertSame( 0, array_sum( $this->run_ability( 'undergrowth/stage' )['counts'] ) );
	}

	public function test_site_posts_leave_no_post_body_in_the_cache() {
		$posts = $this->plant_garden();

		foreach ( $posts as $post_id ) {
			clean_post_cache( $post_id );
		}

		$this->assertEqualsCanonicalizing( array_values( $posts ), undergrowth_ability_site_posts() );

		foreach ( $posts as $post_id ) {
			$this->assertFalse( wp_cache_get( $post_id, 'posts' ) );
		}
	}

	public function test_site_posts_for_an_author_are_only_their_own() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$posts  = $this->plant_garden( array( 'Taxes' => array( 'post_author' => $author ) ) );
		wp_set_current_user( $author );

		$this->assertSame( array( $posts['Taxes'] ), undergrowth_ability_site_posts() );
	}

	public function test_site_scale_runs_through_rest_without_input() {
		$this->plant_garden();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/undergrowth/stage/run' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'site', $response->get_data()['scale'] );
	}
}
