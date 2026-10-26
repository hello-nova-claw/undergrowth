<?php
/**
 * Tests for the privacy policy text and the personal data tools.
 *
 * @package Undergrowth
 */

/**
 * Privacy policy guide, exporter and eraser.
 */
class Test_Privacy extends WP_UnitTestCase {

	const DISCORD_ID = '112233445566778899';

	public function test_policy_guide_has_a_section_for_undergrowth() {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'options-privacy' );

		// Core takes policy text only during admin_init, which would run
		// every other plugin's hooks too.
		$GLOBALS['wp_current_filter'][] = 'admin_init';
		undergrowth_add_privacy_policy_content();
		array_pop( $GLOBALS['wp_current_filter'] );
		set_current_screen( 'front' );

		$sections = wp_list_pluck( WP_Privacy_Policy_Content::get_suggested_policy_text(), 'policy_text', 'plugin_name' );

		$this->assertArrayHasKey( 'Undergrowth', $sections );
		$this->assertStringContainsString( 'OpenAI', $sections['Undergrowth'] );
		$this->assertStringContainsString( 'Anthropic', $sections['Undergrowth'] );
		$this->assertStringContainsString( 'approved comment', $sections['Undergrowth'] );
		$this->assertStringContainsString( 'Discord user ID', $sections['Undergrowth'] );
	}

	public function test_policy_text_is_added_on_admin_init() {
		$this->assertSame( 10, has_action( 'admin_init', 'undergrowth_add_privacy_policy_content' ) );
	}

	public function test_exporter_and_eraser_are_registered() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertSame( 'undergrowth_export_personal_data', $exporters['undergrowth']['callback'] );
		$this->assertSame( 'undergrowth_erase_personal_data', $erasers['undergrowth']['callback'] );
	}

	public function test_export_includes_a_linked_discord_user_id() {
		$user_id = self::factory()->user->create( array( 'user_email' => 'linked@example.org' ) );
		update_user_meta( $user_id, 'undergrowth_discord_user_id', self::DISCORD_ID );

		$export = undergrowth_export_personal_data( 'linked@example.org' );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'undergrowth', $export['data'][0]['group_id'] );
		$this->assertSame( 'undergrowth-' . $user_id, $export['data'][0]['item_id'] );
		$this->assertSame( self::DISCORD_ID, $export['data'][0]['data'][0]['value'] );
		$this->assertSame( 'Discord user ID', $export['data'][0]['data'][0]['name'] );
	}

	public function test_export_is_empty_without_a_linked_discord_user_id() {
		self::factory()->user->create( array( 'user_email' => 'unlinked@example.org' ) );

		$this->assertSame( array(), undergrowth_export_personal_data( 'unlinked@example.org' )['data'] );
		$this->assertSame( array(), undergrowth_export_personal_data( 'nobody@example.org' )['data'] );
	}

	public function test_erase_removes_a_linked_discord_user_id() {
		$user_id = self::factory()->user->create( array( 'user_email' => 'linked@example.org' ) );
		update_user_meta( $user_id, 'undergrowth_discord_user_id', self::DISCORD_ID );

		$result = undergrowth_erase_personal_data( 'linked@example.org' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( '', get_user_meta( $user_id, 'undergrowth_discord_user_id', true ) );
		$this->assertNull( undergrowth_discord_linked_user( self::DISCORD_ID ) );
	}

	public function test_erase_says_so_when_the_discord_user_id_could_not_be_removed() {
		$user_id = self::factory()->user->create( array( 'user_email' => 'linked@example.org' ) );
		update_user_meta( $user_id, 'undergrowth_discord_user_id', self::DISCORD_ID );
		add_filter( 'delete_user_metadata', '__return_false' );

		$result = undergrowth_erase_personal_data( 'linked@example.org' );

		remove_filter( 'delete_user_metadata', '__return_false' );
		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertCount( 1, $result['messages'] );
		$this->assertSame( self::DISCORD_ID, get_user_meta( $user_id, 'undergrowth_discord_user_id', true ) );
	}

	public function test_erase_removes_nothing_without_a_linked_discord_user_id() {
		self::factory()->user->create( array( 'user_email' => 'unlinked@example.org' ) );

		$this->assertFalse( undergrowth_erase_personal_data( 'unlinked@example.org' )['items_removed'] );
		$this->assertFalse( undergrowth_erase_personal_data( 'nobody@example.org' )['items_removed'] );
	}

	public function test_export_includes_the_last_prompt_studio_visit() {
		$user_id = self::factory()->user->create( array( 'user_email' => 'visitor@example.org' ) );
		update_user_meta( $user_id, 'undergrowth_studio_seen', '1700000000' );

		$export = undergrowth_export_personal_data( 'visitor@example.org' );

		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'Last opened the Prompt studio', $export['data'][0]['data'][0]['name'] );
		$this->assertSame( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), 1700000000 ), $export['data'][0]['data'][0]['value'] );
	}

	public function test_erase_removes_the_last_prompt_studio_visit_with_the_discord_user_id() {
		$user_id = self::factory()->user->create( array( 'user_email' => 'linked@example.org' ) );
		update_user_meta( $user_id, 'undergrowth_discord_user_id', self::DISCORD_ID );
		update_user_meta( $user_id, 'undergrowth_studio_seen', '1700000000' );

		$result = undergrowth_erase_personal_data( 'linked@example.org' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( '', get_user_meta( $user_id, 'undergrowth_studio_seen', true ) );
		$this->assertSame( '', get_user_meta( $user_id, 'undergrowth_discord_user_id', true ) );
	}

	public function test_core_tools_export_and_erase_through_undergrowth() {
		$user_id = self::factory()->user->create( array( 'user_email' => 'linked@example.org' ) );
		update_user_meta( $user_id, 'undergrowth_discord_user_id', self::DISCORD_ID );

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$export    = call_user_func( $exporters['undergrowth']['callback'], 'linked@example.org', 1 );

		$this->assertSame( self::DISCORD_ID, $export['data'][0]['data'][0]['value'] );

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		call_user_func( $erasers['undergrowth']['callback'], 'linked@example.org', 1 );

		$this->assertSame( '', get_user_meta( $user_id, 'undergrowth_discord_user_id', true ) );
	}
}
