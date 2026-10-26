<?php
/**
 * Tests that uninstall and deactivation leave nothing behind.
 *
 * @package Undergrowth
 */

/**
 * Builds the inventory from the source, so a new option, meta key,
 * transient or event that uninstall forgets fails here without anyone
 * having to remember to add it.
 */
class Test_Uninstall extends WP_UnitTestCase {

	/**
	 * Every name the source matches, across src/.
	 *
	 * @param string $pattern Regular expression whose first group is the name.
	 * @return string[] Names, sorted and without repeats.
	 */
	private function scan( string $pattern ): array {
		$names = array();

		foreach ( glob( dirname( __DIR__, 2 ) . '/src/*.php' ) as $file ) {
			preg_match_all( $pattern, (string) file_get_contents( $file ), $matches );
			$names = array_merge( $names, $matches[1] );
		}

		// A name ending in an underscore is a prefix the code completes.
		$names = array_filter(
			array_unique( $names ),
			static function ( string $name ): bool {
				return '_' !== substr( $name, -1 );
			}
		);
		sort( $names );

		return $names;
	}

	/**
	 * Option names the plugin reads or writes.
	 *
	 * @return string[]
	 */
	private function options(): array {
		return array_values( array_unique( array_merge( $this->scan( "/(?:get|update|add|delete)_option\(\s*'(undergrowth_[a-z0-9_.]+)'/" ), undergrowth_discord_options() ) ) );
	}

	/**
	 * Post meta keys the plugin reads or writes.
	 *
	 * @return string[]
	 */
	private function post_meta_keys(): array {
		return array_values(
			array_unique(
				array_merge(
					$this->scan( "/(?:get|update|add|delete)_post_meta\(\s*[^,;]+,\s*'(undergrowth_[a-z0-9_]+)'/" ),
					$this->scan( "/(?:metadata_exists|get_metadata|update_metadata|add_metadata|delete_metadata)\(\s*'post',\s*[^,;]+,\s*'(undergrowth_[a-z0-9_]+)'/" ),
					$this->scan( "/register_post_meta\(\s*[^,;]+,\s*'(undergrowth_[a-z0-9_]+)'/" )
				)
			)
		);
	}

	/**
	 * User meta keys the plugin reads or writes.
	 *
	 * @return string[]
	 */
	private function user_meta_keys(): array {
		return array_values(
			array_unique(
				array_merge(
					$this->scan( "/(?:get|update|add|delete)_user_meta\(\s*[^,;]+,\s*'(undergrowth_[a-z0-9_]+)'/" ),
					$this->scan( "/(?:get_metadata|update_metadata|add_metadata|delete_metadata)\(\s*'user',\s*[^,;]+,\s*'(undergrowth_[a-z0-9_]+)'/" )
				)
			)
		);
	}

	/**
	 * Transient names the plugin reads or writes.
	 *
	 * @return string[]
	 */
	private function transients(): array {
		return $this->scan( "/(?:get|set|delete)_transient\(\s*'(undergrowth_[a-z0-9_]+)'/" );
	}

	/**
	 * Hooks the plugin schedules, once or on a recurrence.
	 *
	 * @return string[]
	 */
	private function events(): array {
		return array_values(
			array_unique(
				array_merge(
					$this->scan( "/wp_schedule_single_event\(\s*[^,;]+,\s*'(undergrowth_[a-z0-9_]+)'/" ),
					$this->scan( "/wp_schedule_event\(\s*[^,;]+,\s*'[^']+',\s*'(undergrowth_[a-z0-9_]+)'/" )
				)
			)
		);
	}

	/**
	 * Schedules every event, with and without arguments.
	 */
	private function schedule_every_event(): void {
		foreach ( $this->events() as $hook ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, $hook );
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, $hook, array( 1 ) );
		}
	}

	/**
	 * Hooks among the plugin's events that are still scheduled.
	 *
	 * @return string[]
	 */
	private function scheduled_events(): array {
		$left = array();

		foreach ( _get_cron_array() as $hooks ) {
			$left = array_merge( $left, array_intersect( array_keys( $hooks ), $this->events() ) );
		}

		return array_values( array_unique( $left ) );
	}

	public function test_the_scan_finds_what_the_plugin_stores() {
		$this->assertContains( 'undergrowth_openai_api_key', $this->options() );
		$this->assertContains( 'undergrowth_discord_bot_token', $this->options() );
		$this->assertContains( 'undergrowth_backfill.lock', $this->options() );
		$this->assertContains( 'undergrowth_stage', $this->post_meta_keys() );
		$this->assertContains( 'undergrowth_stage_pinned', $this->post_meta_keys() );
		$this->assertContains( 'undergrowth_discord_user_id', $this->user_meta_keys() );
		$this->assertContains( 'undergrowth_meter', $this->transients() );
		$this->assertContains( 'undergrowth_embed_post', $this->events() );
		$this->assertContains( 'undergrowth_prompt_batch', $this->events() );
		$this->assertNotContains( 'undergrowth_monthly', $this->events() );
	}

	public function test_every_post_meta_key_is_kept_out_of_the_custom_fields_box() {
		$keys  = undergrowth_protected_meta_keys();
		$found = $this->post_meta_keys();
		sort( $keys );
		sort( $found );

		$this->assertSame( $found, $keys );

		foreach ( $keys as $key ) {
			$this->assertTrue( is_protected_meta( $key, 'post' ), $key );
		}
	}

	public function test_uninstall_deletes_every_option() {
		foreach ( $this->options() as $option ) {
			update_option( $option, 'x', false );
		}

		undergrowth_uninstall();

		$left = array_filter(
			$this->options(),
			static function ( string $option ): bool {
				return false !== get_option( $option );
			}
		);
		$this->assertSame( array(), array_values( $left ) );
	}

	public function test_uninstall_deletes_stage_locks_left_by_requests_that_died() {
		$post_id = self::factory()->post->create();
		update_option( "undergrowth_stage_{$post_id}.lock", time(), false );
		update_option( 'undergrowth_stagecoach', 'x', false );

		undergrowth_uninstall();

		$this->assertFalse( get_option( "undergrowth_stage_{$post_id}.lock" ) );
		$this->assertSame( 'x', get_option( 'undergrowth_stagecoach' ) );
	}

	public function test_uninstall_deletes_every_post_meta_key() {
		$post_id = self::factory()->post->create();

		foreach ( $this->post_meta_keys() as $key ) {
			add_post_meta( $post_id, $key, 'x' );
		}

		undergrowth_uninstall();

		$left = array_filter(
			$this->post_meta_keys(),
			static function ( string $key ) use ( $post_id ): bool {
				return metadata_exists( 'post', $post_id, $key );
			}
		);
		$this->assertSame( array(), array_values( $left ) );
	}

	public function test_uninstall_deletes_every_user_meta_key() {
		$user_id = self::factory()->user->create();

		foreach ( $this->user_meta_keys() as $key ) {
			add_user_meta( $user_id, $key, 'x' );
		}

		undergrowth_uninstall();

		$left = array_filter(
			$this->user_meta_keys(),
			static function ( string $key ) use ( $user_id ): bool {
				return metadata_exists( 'user', $user_id, $key );
			}
		);
		$this->assertSame( array(), array_values( $left ) );
	}

	public function test_uninstall_deletes_every_transient() {
		foreach ( $this->transients() as $transient ) {
			set_transient( $transient, 'x', HOUR_IN_SECONDS );
		}

		undergrowth_uninstall();

		$left = array_filter(
			$this->transients(),
			static function ( string $transient ): bool {
				return false !== get_transient( $transient );
			}
		);
		$this->assertSame( array(), array_values( $left ) );
	}

	public function test_uninstall_unschedules_every_event() {
		$this->schedule_every_event();

		undergrowth_uninstall();

		$this->assertSame( array(), $this->scheduled_events() );
	}

	public function test_deactivation_unschedules_every_event() {
		$this->schedule_every_event();

		do_action( 'deactivate_' . plugin_basename( UNDERGROWTH_FILE ), false );

		$this->assertSame( array(), $this->scheduled_events() );
	}

	/**
	 * Each site keeps its own cron queue, and network deactivation runs once.
	 *
	 * @group ms-required
	 */
	public function test_network_deactivation_unschedules_every_event_on_every_site() {
		$site_ids = array_merge( array( get_current_blog_id() ), self::factory()->blog->create_many( 2 ) );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			$this->schedule_every_event();
			restore_current_blog();
		}

		do_action( 'deactivate_' . plugin_basename( UNDERGROWTH_FILE ), true );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			$this->assertSame( array(), $this->scheduled_events(), "Site {$site_id}" );
			restore_current_blog();
		}
	}

	/**
	 * The files uninstall.php requires, as paths from the plugin folder.
	 *
	 * @return string[]
	 */
	private function uninstall_requires(): array {
		preg_match_all( "#^require_once __DIR__ \\. '/([^']+)';#m", (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' ), $matches );

		return $matches[1];
	}

	/**
	 * Removes every hook callback the plugin added, as on a site where it is
	 * inactive.
	 *
	 * @return int How many callbacks were removed.
	 */
	private function remove_plugin_hooks(): int {
		global $wp_filter;

		$src     = realpath( dirname( __DIR__, 2 ) ) . '/';
		$removed = 0;

		foreach ( $wp_filter as $hook => $filter ) {
			foreach ( $filter->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];

					if ( ! ( $function instanceof Closure ) && ! ( is_string( $function ) && 0 === strpos( $function, 'undergrowth_' ) ) ) {
						continue;
					}

					$file = ( new ReflectionFunction( $function ) )->getFileName();

					// Closures the test suite added are left alone.
					if ( 0 === strpos( (string) realpath( (string) $file ), $src ) && false === strpos( (string) $file, '/tests/' ) ) {
						remove_filter( $hook, $function, $priority );
						++$removed;
					}
				}
			}
		}

		return $removed;
	}

	public function test_uninstall_file_deletes_every_option() {
		foreach ( $this->options() as $option ) {
			update_option( $option, 'x', false );
		}

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( UNDERGROWTH_FILE ) );
		}

		// WordPress runs uninstall.php with the plugin inactive, so none of its
		// hooks are there. The suite puts every hook back after the test.
		$removed = $this->remove_plugin_hooks();
		$this->assertGreaterThan( 0, $removed );

		// The plugin is already loaded, so the file's require_once lines add
		// nothing; a scope of its own keeps its variables out of this one.
		( static function () {
			require dirname( __DIR__, 2 ) . '/uninstall.php';
		} )();

		$left = array_filter(
			$this->options(),
			static function ( string $option ): bool {
				return false !== get_option( $option );
			}
		);
		$this->assertSame( array(), array_values( $left ) );
	}

	/**
	 * WordPress runs uninstall.php without loading the plugin, so every
	 * plugin function uninstall calls, and every constant those functions
	 * read, must come from a file uninstall.php requires.
	 *
	 * The test suite has loaded the whole plugin, so removing a require_once
	 * would not fail the test above; this walks the calls instead.
	 */
	public function test_uninstall_file_requires_everything_uninstall_calls() {
		$root     = dirname( __DIR__, 2 );
		$required = array_map(
			static function ( string $path ) use ( $root ): string {
				return realpath( $root . '/' . $path );
			},
			$this->uninstall_requires()
		);
		$this->assertNotSame( array(), $required );

		$sources = array();
		foreach ( $required as $file ) {
			$sources[] = (string) file_get_contents( $file );
		}
		$required_source = implode( "\n", $sources );

		$seen    = array();
		$pending = array( 'undergrowth_uninstall' );
		$missing = array();

		while ( array() !== $pending ) {
			$name = array_pop( $pending );

			if ( isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;

			$function = new ReflectionFunction( $name );

			if ( ! in_array( realpath( (string) $function->getFileName() ), $required, true ) ) {
				$missing[] = $name . '() from ' . str_replace( $root . '/', '', (string) $function->getFileName() );
				continue;
			}

			$lines = array_slice( file( (string) $function->getFileName() ), $function->getStartLine(), $function->getEndLine() - $function->getStartLine() );
			$body  = implode( '', $lines );

			preg_match_all( '/\b(undergrowth_[a-z0-9_]+)\s*\(/', $body, $calls );
			foreach ( $calls[1] as $called ) {
				if ( function_exists( $called ) ) {
					$pending[] = $called;
				}
			}

			preg_match_all( '/\b(UNDERGROWTH_[A-Z0-9_]+)\b/', $body, $constants );
			foreach ( array_unique( $constants[1] ) as $constant ) {
				if ( ! preg_match( '/\b(?:const ' . $constant . '\b|define\(\s*\'' . $constant . '\')/', $required_source ) ) {
					$missing[] = $constant . ', read by ' . $name . '()';
				}
			}
		}

		$this->assertContains( 'undergrowth_uninstall_site', array_keys( $seen ) );
		$this->assertSame( array(), array_values( array_unique( $missing ) ) );
	}
}
