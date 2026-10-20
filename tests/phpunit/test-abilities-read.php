<?php
/**
 * Tests for the abilities that read a post's fields.
 *
 * @package Undergrowth
 */

/**
 * The has, lacks, scent and stage abilities.
 */
class Test_Abilities_Read extends WP_UnitTestCase {

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		$this->start_planting();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
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
	private function run_ability( string $name, $input ) {
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

	public function test_category_is_registered() {
		$this->assertTrue( wp_has_ability_category( 'undergrowth' ) );
	}

	/**
	 * Runs for each read ability.
	 *
	 * @dataProvider data_read_abilities
	 *
	 * @param string $name Ability name.
	 */
	public function test_read_ability_is_registered_with_schemas( $name ) {
		$ability = wp_get_ability( $name );

		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertSame( 'undergrowth', $ability->get_category() );
		$this->assertSame( 'object', $ability->get_input_schema()['type'] );
		$this->assertSame( array( 'post_id' ), $ability->get_input_schema()['required'] );
		$this->assertSame( 'object', $ability->get_output_schema()['type'] );
		$this->assertTrue( $ability->get_meta_item( 'annotations' )['readonly'] );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ) );
	}

	/**
	 * Runs for each read ability.
	 *
	 * @dataProvider data_read_abilities
	 *
	 * @param string $name Ability name.
	 */
	public function test_read_ability_refuses_a_user_who_cannot_edit_the_post( $name ) {
		$post_id = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertWPError( $this->run_ability( $name, array( 'post_id' => $post_id ) ) );
	}

	/**
	 * Runs for each read ability.
	 *
	 * @dataProvider data_read_abilities
	 *
	 * @param string $name Ability name.
	 */
	public function test_read_ability_refuses_a_post_outside_the_index( $name ) {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$result = $this->run_ability( $name, array( 'post_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_not_indexed', $result->get_error_code() );
	}

	/**
	 * Runs for each read ability.
	 *
	 * @dataProvider data_read_abilities
	 *
	 * @param string $name Ability name.
	 */
	public function test_read_ability_refuses_input_without_a_post( $name ) {
		$this->assertWPError( $this->run_ability( $name, array() ) );
	}

	public function test_has_lists_the_posts_distinctive_words() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca tubers sweeten after frost. Tubers keep in sand.</p>' ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon roots keep in sand.</p>' ) );

		$has = $this->run_ability( 'undergrowth/has', array( 'post_id' => $oca ) );

		$this->assertSame( $oca, $has['post_id'] );
		$this->assertContains( 'tubers', $has['words'] );
		$this->assertNotContains( 'yacon', $has['words'] );
	}

	public function test_has_lists_published_posts_the_post_links_to() {
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$oca   = $this->plant(
			'Oca',
			array( 1.0, 0.0, 0.0 ),
			array( 'post_content' => '<p>See <a href="' . get_permalink( $yacon ) . '">yacon</a> and <a href="' . get_permalink( $draft ) . '">later</a>.</p>' )
		);

		$has = $this->run_ability( 'undergrowth/has', array( 'post_id' => $oca ) );

		$this->assertSame(
			array(
				array(
					'post_id' => $yacon,
					'title'   => 'Yacon',
					'url'     => get_permalink( $yacon ),
				),
			),
			$has['links']
		);
	}

	public function test_lacks_lists_open_proposals_with_the_sentence_from_the_post() {
		global $wpdb;

		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca grows in the Andes. Like yacon, it is a tuber.</p>' ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon is a sweet tuber.</p>' ) );

		$sentence = $wpdb->get_var( $wpdb->prepare( 'SELECT sentence FROM %i WHERE source_id = %d AND target_id = %d', undergrowth_links_table(), $oca, $yacon ) );
		$lacks    = $this->run_ability( 'undergrowth/lacks', array( 'post_id' => $oca ) );

		$this->assertSame( $oca, $lacks['post_id'] );
		$this->assertNotEmpty( $sentence );
		$this->assertSame(
			array(
				array(
					'post_id'  => $yacon,
					'title'    => 'Yacon',
					'url'      => get_permalink( $yacon ),
					'sentence' => $sentence,
				),
			),
			$lacks['links']
		);
	}

	public function test_lacks_lists_proposals_best_first() {
		$oca    = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$mashua = $this->plant( 'Mashua', array( 0.7, 0.3, 0.0 ) );
		$yacon  = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$lacks = $this->run_ability( 'undergrowth/lacks', array( 'post_id' => $oca ) );

		$this->assertSame( array( $yacon, $mashua ), array_column( $lacks['links'], 'post_id' ) );
	}

	public function test_lacks_leaves_out_vetoed_and_accepted_links() {
		global $wpdb;

		$oca    = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$yacon  = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );
		$mashua = $this->plant( 'Mashua', array( 0.8, 0.2, 0.0 ) );

		undergrowth_veto_link( $oca, $yacon );
		$wpdb->update(
			undergrowth_links_table(),
			array( 'state' => 'accepted' ),
			array(
				'source_id' => $oca,
				'target_id' => $mashua,
			)
		);

		$this->assertSame( array(), $this->run_ability( 'undergrowth/lacks', array( 'post_id' => $oca ) )['links'] );
	}

	public function test_lacks_never_carries_a_vector_or_a_score() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );
		$this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ) );

		$lacks = $this->run_ability( 'undergrowth/lacks', array( 'post_id' => $oca ) );

		$this->assertSame( array( 'post_id', 'title', 'url', 'sentence' ), array_keys( $lacks['links'][0] ) );
	}

	public function test_scent_has_no_value_in_germination_mode() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		$this->assertSame(
			array(
				'post_id' => $oca,
				'mode'    => 'germination',
				'value'   => null,
			),
			$this->run_ability( 'undergrowth/scent', array( 'post_id' => $oca ) )
		);
	}

	public function test_stage_reads_a_new_post_as_an_unpinned_seedling() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		$this->assertSame(
			array(
				'post_id' => $oca,
				'stage'   => 'seedling',
				'pinned'  => false,
				'changes' => array(),
			),
			$this->run_ability( 'undergrowth/stage', array( 'post_id' => $oca ) )
		);
	}

	public function test_stage_reads_a_pinned_stage_and_its_change() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		undergrowth_pin_stage( $oca, 'budding' );

		$stage = $this->run_ability( 'undergrowth/stage', array( 'post_id' => $oca ) );
		$log   = undergrowth_stage_log( $oca );

		$this->assertSame( 'budding', $stage['stage'] );
		$this->assertTrue( $stage['pinned'] );
		$this->assertSame(
			array(
				array(
					'date'   => gmdate( 'c', $log[0]['time'] ),
					'from'   => 'seedling',
					'to'     => 'budding',
					'reason' => 'pin',
				),
			),
			$stage['changes']
		);
	}

	public function test_stage_never_reads_composting_as_pinned() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		undergrowth_pin_stage( $oca, 'budding' );
		undergrowth_compost_post( $oca );

		$stage = $this->run_ability( 'undergrowth/stage', array( 'post_id' => $oca ) );

		$this->assertSame( 'composting', $stage['stage'] );
		$this->assertFalse( $stage['pinned'] );
	}

	public function test_read_ability_runs_through_rest_for_an_editor() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/undergrowth/scent/run' );
		$request->set_query_params( array( 'input' => array( 'post_id' => $oca ) ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $oca, $response->get_data()['post_id'] );
	}

	public function test_read_ability_refuses_a_logged_out_rest_request() {
		$oca = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ) );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/undergrowth/lacks/run' );
		$request->set_query_params( array( 'input' => array( 'post_id' => $oca ) ) );

		$this->assertSame( 401, rest_do_request( $request )->get_status() );
	}
}
