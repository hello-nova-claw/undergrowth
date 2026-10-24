<?php
/**
 * Tests for the Site Health test.
 *
 * @package Undergrowth
 */

/**
 * Site Health says when the backfill has stalled.
 */
class Test_Site_Health extends WP_UnitTestCase {

	const KEY = 'sk-test-0123456789abcd';

	public function set_up() {
		parent::set_up();

		update_option( 'undergrowth_openai_api_key', self::KEY );
		delete_option( 'undergrowth_backfill_progress' );
	}

	/**
	 * Records the backfill's progress as a run that long ago would have.
	 *
	 * @param int $indexed Posts read then.
	 * @param int $total   Posts that could be read then.
	 * @param int $ago     Seconds since.
	 */
	private function set_progress( int $indexed, int $total, int $ago ): void {
		update_option(
			'undergrowth_backfill_progress',
			array(
				'time'    => time() - $ago,
				'indexed' => $indexed,
				'total'   => $total,
			),
			false
		);
	}

	/**
	 * Runs the test as Site Health would.
	 *
	 * @return array<string, mixed>
	 */
	private function run_test(): array {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );

		$this->assertArrayHasKey( 'undergrowth_backfill', $tests['direct'] );

		return call_user_func( $tests['direct']['undergrowth_backfill']['test'] );
	}

	public function test_test_is_registered_as_a_direct_test() {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );

		$this->assertSame( 'undergrowth_site_health_backfill', $tests['direct']['undergrowth_backfill']['test'] );
		$this->assertNotEmpty( $tests['direct']['undergrowth_backfill']['label'] );
	}

	public function test_test_is_left_out_without_a_provider() {
		delete_option( 'undergrowth_openai_api_key' );

		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );

		$this->assertArrayNotHasKey( 'undergrowth_backfill', $tests['direct'] );
	}

	public function test_stalled_backfill_is_recommended_not_critical() {
		self::factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );
		$this->set_progress( 0, 2, 25 * HOUR_IN_SECONDS );

		$result = $this->run_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'undergrowth_backfill', $result['test'] );
		$this->assertStringContainsString( 'No post has been read for a day', $result['description'] );
		$this->assertStringContainsString( 'DISABLE_WP_CRON', $result['description'] );
		$this->assertStringContainsString( 'href="https://developer.wordpress.org/plugins/cron/', $result['actions'] );
	}

	public function test_backfill_that_is_keeping_up_is_good() {
		self::factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );
		$this->set_progress( 0, 2, 23 * HOUR_IN_SECONDS );

		$result = $this->run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringNotContainsString( 'No post has been read for a day', $result['description'] );
	}

	public function test_test_is_left_out_once_every_post_is_read() {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		undergrowth_save_vector(
			$id,
			array( 1.0 ),
			array(
				'provider'     => 'openai',
				'model'        => UNDERGROWTH_OPENAI_MODEL,
				'language'     => 'en_US',
				'content_hash' => 'x',
			)
		);
		$this->set_progress( 1, 1, 30 * DAY_IN_SECONDS );

		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );

		$this->assertArrayNotHasKey( 'undergrowth_backfill', $tests['direct'] );
	}
}
