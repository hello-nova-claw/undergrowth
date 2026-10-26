<?php
/**
 * Tests for the Help tabs on Posts > Undergrowth and on the Posts list.
 *
 * @package Undergrowth
 */

/**
 * The words Undergrowth uses are explained where core explains things.
 */
class Test_Help_Tabs extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	public function tear_down() {
		set_current_screen( 'front' );
		unset( $GLOBALS['typenow'] );

		parent::tear_down();
	}

	/**
	 * Builds a screen's help tabs as its load hook does.
	 *
	 * @param string $hook   Load hook suffix.
	 * @param string $screen Screen ID.
	 * @return array<string, string> Text of each tab, keyed by ID.
	 */
	private function tabs( string $hook, string $screen ): array {
		set_current_screen( $screen );
		$current = get_current_screen();
		$current->remove_help_tabs();
		do_action( 'load-' . $hook ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Core's hook name.

		return array_map(
			static function ( $tab ) {
				return wp_strip_all_tags( $tab['content'] );
			},
			$current->get_help_tabs()
		);
	}

	public function test_the_undergrowth_screen_explains_each_tab_and_compost() {
		$tabs = $this->tabs( 'posts_page_undergrowth-links', 'posts_page_undergrowth-links' );

		$this->assertSame(
			array( 'undergrowth-overview', 'undergrowth-suggestions', 'undergrowth-orphans', 'undergrowth-stages', 'undergrowth-map', 'undergrowth-compost' ),
			array_keys( $tabs )
		);
	}

	public function test_suggestions_help_explains_accept_and_veto() {
		$text = $this->tabs( 'posts_page_undergrowth-links', 'posts_page_undergrowth-links' )['undergrowth-suggestions'];

		$this->assertStringContainsString( 'highlighted word', $text );
		$this->assertStringContainsString( 'revision', $text );
		$this->assertStringContainsString( 'Veto', $text );
		$this->assertStringContainsString( 'never suggested again', $text );
	}

	public function test_stages_help_explains_each_stage() {
		$text = $this->tabs( 'posts_page_undergrowth-links', 'posts_page_undergrowth-links' )['undergrowth-stages'];

		foreach ( array( 'Seedling', 'Sprout', 'Budding', 'Evergreen', 'Pin' ) as $word ) {
			$this->assertStringContainsString( $word, $text );
		}

		$this->assertStringContainsString( 'once a month', $text );
	}

	public function test_map_help_explains_a_group_and_a_gap() {
		$text = $this->tabs( 'posts_page_undergrowth-links', 'posts_page_undergrowth-links' )['undergrowth-map'];

		$this->assertStringContainsString( 'group', $text );
		$this->assertStringContainsString( 'with a gap', $text );
	}

	public function test_map_help_says_how_groups_are_named_and_why_a_post_is_in_none() {
		$text = $this->tabs( 'posts_page_undergrowth-links', 'posts_page_undergrowth-links' )['undergrowth-map'];

		$this->assertStringContainsString( 'named after the title of its post nearest the middle', $text );
		$this->assertStringContainsString( 'not in a group yet', $text );
		$this->assertStringContainsString( 'written by a model', $text );
	}

	public function test_overview_explains_the_garden() {
		$text = $this->tabs( 'posts_page_undergrowth-links', 'posts_page_undergrowth-links' )['undergrowth-overview'];

		$this->assertStringContainsString( 'garden', $text );
	}

	public function test_the_posts_list_explains_compost() {
		$GLOBALS['typenow'] = 'post';

		$tabs = $this->tabs( 'edit.php', 'edit-post' );

		$this->assertArrayHasKey( 'undergrowth-compost', $tabs );
		$this->assertStringContainsString( 'stays published', $tabs['undergrowth-compost'] );
		$this->assertStringContainsString( 'Nothing is deleted', $tabs['undergrowth-compost'] );
	}

	public function test_lists_outside_the_garden_get_no_compost_help() {
		$GLOBALS['typenow'] = 'attachment';

		$this->assertArrayNotHasKey( 'undergrowth-compost', $this->tabs( 'edit.php', 'edit-attachment' ) );
	}

	public function test_help_never_counts_days_or_nudges_a_post_back() {
		$tabs = $this->tabs( 'posts_page_undergrowth-links', 'posts_page_undergrowth-links' );

		foreach ( $tabs as $id => $text ) {
			$this->assertDoesNotMatchRegularExpression( '/\b(days?|weeks?|streak|ago|since|soon|should)\b/i', $text, $id );
		}
	}

	public function test_help_tab_takes_a_priority_and_escapes_its_paragraphs() {
		set_current_screen( 'dashboard' );
		$screen = get_current_screen();
		$screen->remove_help_tabs();

		undergrowth_add_help_tab( 'undergrowth-test', 'Test', array( 'One & two.', 'Three.' ), 20 );

		$tab = $screen->get_help_tab( 'undergrowth-test' );
		$this->assertSame( 20, $tab['priority'] );
		$this->assertSame( '<p>One &amp; two.</p><p>Three.</p>', $tab['content'] );
	}
}
