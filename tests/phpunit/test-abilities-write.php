<?php
/**
 * Tests for the abilities that accept or veto a link.
 *
 * @package Undergrowth
 */

/**
 * The accept-link and veto-link abilities.
 */
class Test_Abilities_Write extends WP_UnitTestCase {

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
	 * Publishes Oca with a proposed link to Yacon.
	 *
	 * @return int[] Oca's ID, then Yacon's.
	 */
	private function pair(): array {
		$oca   = $this->plant( 'Oca', array( 1.0, 0.0, 0.0 ), array( 'post_content' => '<p>Oca grows in the Andes. Like yacon, it is a tuber.</p>' ) );
		$yacon = $this->plant( 'Yacon', array( 0.9, 0.1, 0.0 ), array( 'post_content' => '<p>Yacon is a sweet tuber. The yacon root stores well.</p>' ) );

		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );

		return array( $oca, $yacon );
	}

	/**
	 * Names of the write abilities.
	 *
	 * @return array<string, string[]>
	 */
	public function data_write_abilities() {
		return array(
			'accept-link' => array( 'undergrowth/accept-link' ),
			'veto-link'   => array( 'undergrowth/veto-link' ),
		);
	}

	/**
	 * Runs for each write ability.
	 *
	 * @dataProvider data_write_abilities
	 *
	 * @param string $name Ability name.
	 */
	public function test_write_ability_is_registered_with_schemas( $name ) {
		$ability = wp_get_ability( $name );

		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertSame( 'undergrowth', $ability->get_category() );
		$this->assertSame( array( 'source_id', 'target_id' ), $ability->get_input_schema()['required'] );
		$this->assertSame( 'object', $ability->get_output_schema()['type'] );
		$this->assertFalse( $ability->get_meta_item( 'annotations' )['readonly'] );
		$this->assertFalse( $ability->get_meta_item( 'annotations' )['destructive'] );
	}

	/**
	 * Runs for each write ability.
	 *
	 * @dataProvider data_write_abilities
	 *
	 * @param string $name Ability name.
	 */
	public function test_write_ability_is_never_public( $name ) {
		$ability = wp_get_ability( $name );

		$this->assertFalse( $ability->get_meta_item( 'public' ) );
		$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ) );
		$this->assertSame( array( 'public' => false ), $ability->get_meta_item( 'mcp' ) );
	}

	/**
	 * Runs for each write ability.
	 *
	 * @dataProvider data_write_abilities
	 *
	 * @param string $name Ability name.
	 */
	public function test_write_ability_cannot_be_run_through_rest_even_by_an_administrator( $name ) {
		list( $oca, $yacon ) = $this->pair();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/' . $name . '/run' );
		$request->set_body_params(
			array(
				'input' => array(
					'source_id' => $oca,
					'target_id' => $yacon,
				),
			)
		);

		$this->assertSame( 404, rest_do_request( $request )->get_status() );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
	}

	/**
	 * Runs for each write ability.
	 *
	 * @dataProvider data_write_abilities
	 *
	 * @param string $name Ability name.
	 */
	public function test_write_ability_cannot_be_run_through_rest_logged_out( $name ) {
		list( $oca, $yacon ) = $this->pair();

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/' . $name . '/run' );
		$request->set_body_params(
			array(
				'input' => array(
					'source_id' => $oca,
					'target_id' => $yacon,
				),
			)
		);

		$this->assertSame( 404, rest_do_request( $request )->get_status() );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
	}

	/**
	 * Runs for each write ability.
	 *
	 * @dataProvider data_write_abilities
	 *
	 * @param string $name Ability name.
	 */
	public function test_write_ability_is_not_listed_through_rest( $name ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotContains( $name, array_column( $response->get_data(), 'name' ) );
	}

	/**
	 * Runs for each write ability.
	 *
	 * @dataProvider data_write_abilities
	 *
	 * @param string $name Ability name.
	 */
	public function test_write_ability_refuses_a_user_who_cannot_edit_the_source_post( $name ) {
		list( $oca, $yacon ) = $this->pair();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$result = $this->run_ability(
			$name,
			array(
				'source_id' => $oca,
				'target_id' => $yacon,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
	}

	/**
	 * Runs for each write ability.
	 *
	 * @dataProvider data_write_abilities
	 *
	 * @param string $name Ability name.
	 */
	public function test_write_ability_refuses_input_without_a_target( $name ) {
		list( $oca ) = $this->pair();

		$this->assertWPError( $this->run_ability( $name, array( 'source_id' => $oca ) ) );
	}

	public function test_accept_link_links_a_word_in_the_source_post() {
		list( $oca, $yacon ) = $this->pair();

		$result = $this->run_ability(
			'undergrowth/accept-link',
			array(
				'source_id' => $oca,
				'target_id' => $yacon,
			)
		);

		$this->assertSame(
			array(
				'source_id' => $oca,
				'target_id' => $yacon,
				'state'     => 'accepted',
			),
			$result
		);
		$this->assertSame( 'accepted', undergrowth_link_state( $oca, $yacon ) );
		$this->assertStringContainsString( '<a href="' . get_permalink( $yacon ) . '">', get_post( $oca )->post_content );
	}

	public function test_accept_link_says_why_nothing_changed() {
		list( $oca, $yacon ) = $this->pair();

		undergrowth_veto_link( $oca, $yacon );

		$result = $this->run_ability(
			'undergrowth/accept-link',
			array(
				'source_id' => $oca,
				'target_id' => $yacon,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_not_proposed', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_accept_link_says_the_post_could_not_be_saved() {
		list( $oca, $yacon ) = $this->pair();
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$result = $this->run_ability(
			'undergrowth/accept-link',
			array(
				'source_id' => $oca,
				'target_id' => $yacon,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_not_saved', $result->get_error_code() );
		$this->assertStringContainsString( 'could not be saved', $result->get_error_message() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 'proposed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_veto_link_stores_a_veto() {
		list( $oca, $yacon ) = $this->pair();

		$result = $this->run_ability(
			'undergrowth/veto-link',
			array(
				'source_id' => $oca,
				'target_id' => $yacon,
			)
		);

		$this->assertSame(
			array(
				'source_id' => $oca,
				'target_id' => $yacon,
				'state'     => 'vetoed',
			),
			$result
		);
		$this->assertSame( 'vetoed', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_veto_link_leaves_an_accepted_link_alone() {
		list( $oca, $yacon ) = $this->pair();

		undergrowth_accept_link( $oca, $yacon );

		$result = $this->run_ability(
			'undergrowth/veto-link',
			array(
				'source_id' => $oca,
				'target_id' => $yacon,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_unchanged', $result->get_error_code() );
		$this->assertSame( 'accepted', undergrowth_link_state( $oca, $yacon ) );
	}

	public function test_veto_link_refuses_a_target_that_does_not_exist() {
		list( $oca ) = $this->pair();

		$result = $this->run_ability(
			'undergrowth/veto-link',
			array(
				'source_id' => $oca,
				'target_id' => PHP_INT_MAX,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'undergrowth_gone', $result->get_error_code() );
	}
}
