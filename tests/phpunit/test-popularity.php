<?php
/**
 * Tests for popularity read from the site's stats plugin.
 *
 * @package Undergrowth
 */

use Automattic\Jetpack\Connection\Manager;
use Automattic\Jetpack\Modules;
use Automattic\Jetpack\Stats\WPCOM_Stats;

/**
 * Popularity.
 */
class Test_Popularity extends WP_UnitTestCase {

	use Plants_Posts;

	public function set_up() {
		parent::set_up();

		Modules::$active           = array();
		Manager::$connected        = false;
		WPCOM_Stats::$top_posts    = array();
		WPCOM_Stats::$top_requests = array();

		// Koko Analytics and Post Views Counter stand in by constant and
		// function, which outlive a test once loaded. Each test that wants
		// them says so through only_sources().
		$this->only_sources( array( 'jetpack' ) );

		$this->start_planting();
		undergrowth_register_stage_meta();
	}

	/**
	 * Keeps only some of the built-in sources.
	 *
	 * @param string[] $slugs Sources to keep.
	 */
	private function only_sources( array $slugs ): void {
		remove_all_filters( 'undergrowth_popularity_sources' );
		add_filter(
			'undergrowth_popularity_sources',
			static function ( $sources ) use ( $slugs ) {
				return array_intersect_key( $sources, array_flip( $slugs ) );
			}
		);
	}

	/**
	 * Turns Jetpack Stats on, as a connected site with the module active.
	 */
	private function jetpack_on(): void {
		Modules::$active    = array( 'stats' );
		Manager::$connected = true;
	}

	/**
	 * Returns a date some days before today in the site's time zone.
	 *
	 * @param int    $days   Days ago.
	 * @param string $format Date format.
	 * @return string
	 */
	private function ago( int $days, string $format = 'Y-m-d' ): string {
		return current_datetime()->modify( "-{$days} days" )->format( $format );
	}

	/**
	 * Supplies views per post through the filter, as another source would.
	 *
	 * @param array<int, float> $views Views keyed by post ID.
	 */
	private function views( array $views ): void {
		add_filter(
			'undergrowth_post_popularity',
			static function ( $value, $post_id ) use ( $views ) {
				return $views[ $post_id ] ?? $value;
			},
			10,
			2
		);
	}

	/**
	 * Stores a post last changed long ago, as the block's own tests do.
	 *
	 * @param string  $title  Post title.
	 * @param float[] $vector Vector.
	 * @return int Post ID.
	 */
	private function old( string $title, array $vector ): int {
		return $this->seed( $title, $vector, array( 'post_date' => '2001-01-01 00:00:00' ) );
	}

	public function test_without_a_stats_plugin_nothing_is_read_and_the_list_is_unchanged() {
		$source = $this->old( 'Oca', array( 1, 0, 0 ) );
		$close  = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$far    = $this->old( 'Mashua', array( 1, 0.3, 0 ) );
		$before = undergrowth_more_like_this( $source, 2 );

		update_option( 'undergrowth_popularity', array( 'views' => array( $far => 1000.0 ) ) );
		$this->assertNull( undergrowth_popularity_source() );
		$this->assertSame(
			array(
				$close => null,
				$far   => null,
			),
			undergrowth_posts_popularity( array( $close, $far ) )
		);

		undergrowth_read_popularity();

		$this->assertFalse( get_option( 'undergrowth_popularity' ) );
		$this->assertSame( array( $close, $far ), $before );
		$this->assertSame( $before, undergrowth_more_like_this( $source, 2 ) );
		$this->assertSame( array(), WPCOM_Stats::$top_requests );
	}

	public function test_jetpack_is_the_source_once_stats_are_on_and_connected() {
		Modules::$active = array( 'stats' );
		$this->assertNull( undergrowth_popularity_source() );

		Manager::$connected = true;
		$this->assertSame( 'jetpack', undergrowth_popularity_source() );
		$this->assertSame( 'Jetpack Stats', undergrowth_popularity_source_label() );
	}

	public function test_reads_jetpack_daily_top_posts_and_fades_them() {
		$this->jetpack_on();
		WPCOM_Stats::$top_posts = array(
			'date'   => $this->ago( 0 ),
			'period' => 'day',
			'days'   => array(
				$this->ago( 0 ) => array(
					'postviews'   => array(
						array(
							'id'    => 11,
							'views' => 10,
						),
						array(
							'id'    => 0,
							'views' => 99,
						),
					),
					'total_views' => 109,
				),
				$this->ago( 7 ) => array(
					'postviews' => array(
						array(
							'id'    => 11,
							'views' => 8,
						),
						array(
							'id'    => 12,
							'views' => 6,
						),
					),
				),
			),
		);

		undergrowth_read_popularity();

		$kept = get_option( 'undergrowth_popularity' );

		$this->assertSame( 'jetpack', $kept['source'] );
		$this->assertEqualsWithDelta( 14.0, $kept['views'][11], 0.001 );
		$this->assertEqualsWithDelta( 3.0, $kept['views'][12], 0.001 );
		$this->assertArrayNotHasKey( 0, $kept['views'] );
		$this->assertSame(
			array(
				array(
					'period' => 'day',
					'num'    => 30,
					'max'    => 500,
				),
			),
			WPCOM_Stats::$top_requests
		);
		$this->assertEqualsWithDelta( 14.0, undergrowth_posts_popularity( array( 11 ) )[11], 0.001 );
		$this->assertSame( 0.0, undergrowth_posts_popularity( array( 13 ) )[13] );
	}

	public function test_a_failed_read_keeps_the_last_good_one() {
		$this->jetpack_on();
		$kept = array(
			'source' => 'jetpack',
			'read'   => 1,
			'views'  => array( 11 => 5.0 ),
		);
		update_option( 'undergrowth_popularity', $kept );
		$generation = (int) get_option( 'undergrowth_similar_generation', 0 );

		WPCOM_Stats::$top_posts = new WP_Error( 'stats', 'No.' );
		undergrowth_read_popularity();

		$this->assertSame( $kept, get_option( 'undergrowth_popularity' ) );
		$this->assertSame( $generation, (int) get_option( 'undergrowth_similar_generation', 0 ) );

		WPCOM_Stats::$top_posts = array( 'summary' => array() );
		undergrowth_read_popularity();

		$this->assertSame( $kept, get_option( 'undergrowth_popularity' ) );
	}

	public function test_a_missing_stats_table_reads_as_unknown() {
		$this->assertNull( undergrowth_read_koko_views( 30 ) );
		$this->assertNull( undergrowth_read_pvc_views( 30 ) );
	}

	public function test_views_from_another_source_are_not_known() {
		$this->jetpack_on();
		update_option(
			'undergrowth_popularity',
			array(
				'source' => 'koko',
				'read'   => 1,
				'views'  => array( 11 => 5.0 ),
			)
		);

		$this->assertSame( array( 11 => null ), undergrowth_posts_popularity( array( 11 ) ) );
	}

	public function test_reads_koko_analytics_by_post_adding_up_each_address() {
		global $wpdb;

		require_once __DIR__ . '/includes/koko-analytics.php';
		$this->only_sources( array( 'koko' ) );

		$table = $wpdb->prefix . 'koko_analytics_post_stats';
		$wpdb->query( "CREATE TABLE {$table} ( date DATE NOT NULL, path_id INT UNSIGNED NOT NULL, post_id INT UNSIGNED NOT NULL DEFAULT 0, visitors INT UNSIGNED NOT NULL, pageviews INT UNSIGNED NOT NULL, PRIMARY KEY (date, path_id) )" ); // phpcs:ignore WordPress.DB

		foreach ( array(
			array( $this->ago( 0 ), 1, 21, 4 ),
			array( $this->ago( 0 ), 2, 21, 6 ),
			array( $this->ago( 3 ), 1, 21, 5 ),
			array( $this->ago( 0 ), 3, 0, 50 ),
			array( $this->ago( 40 ), 1, 21, 70 ),
			array( $this->ago( 1 ), 4, 22, 2 ),
		) as list( $date, $path, $post, $views ) ) {
			$wpdb->insert(
				$table,
				array(
					'date'      => $date,
					'path_id'   => $path,
					'post_id'   => $post,
					'visitors'  => 1,
					'pageviews' => $views,
				)
			);
		}

		$this->assertTrue( undergrowth_koko_is_active() );
		$this->assertSame( 'koko', undergrowth_popularity_source() );
		$this->assertSame( 'Koko Analytics', undergrowth_popularity_source_label() );

		$views = undergrowth_read_koko_views( 30 );
		ksort( $views );

		$this->assertSame(
			array(
				21 => array(
					$this->ago( 0 ) => 10,
					$this->ago( 3 ) => 5,
				),
				22 => array( $this->ago( 1 ) => 2 ),
			),
			array_map(
				static function ( $days ) {
					krsort( $days );
					return $days;
				},
				$views
			)
		);

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Reads Post Views Counter's daily rows, with or without its newer
	 * content column.
	 *
	 * @dataProvider data_pvc_tables
	 *
	 * @param bool $content Whether the table has a content column.
	 */
	public function test_reads_post_views_counter_daily_rows( bool $content ) {
		global $wpdb;

		require_once __DIR__ . '/includes/post-views-counter.php';
		$this->only_sources( array( 'pvc' ) );

		$table = $wpdb->prefix . 'post_views';
		$extra = $content ? ', content INT NOT NULL DEFAULT 0' : '';
		$wpdb->query( "CREATE TABLE {$table} ( id BIGINT UNSIGNED NOT NULL, type TINYINT UNSIGNED NOT NULL, period VARCHAR(8) NOT NULL, count BIGINT UNSIGNED NOT NULL{$extra}, PRIMARY KEY (type, period, id) )" ); // phpcs:ignore WordPress.DB

		$rows = array(
			array( 31, 0, $this->ago( 0, 'Ymd' ), 9 ),
			array( 31, 0, $this->ago( 2, 'Ymd' ), 3 ),
			array( 31, 4, 'total', 500 ),
			array( 31, 2, $this->ago( 0, 'Ym' ), 80 ),
			array( 32, 0, $this->ago( 45, 'Ymd' ), 40 ),
		);

		foreach ( $rows as list( $id, $type, $period, $count ) ) {
			$wpdb->insert( $table, compact( 'id', 'type', 'period', 'count' ) );
		}

		if ( $content ) {
			$wpdb->insert(
				$table,
				array(
					'id'      => 33,
					'type'    => 0,
					'period'  => $this->ago( 0, 'Ymd' ),
					'count'   => 7,
					'content' => 1,
				)
			);
		}

		$this->assertTrue( undergrowth_pvc_is_active() );
		$this->assertSame( 'pvc', undergrowth_popularity_source() );

		$views = undergrowth_read_pvc_views( 30 );
		krsort( $views[31] );

		$this->assertSame(
			array(
				31 => array(
					$this->ago( 0 ) => 9,
					$this->ago( 2 ) => 3,
				),
			),
			$views
		);

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Both shapes of Post Views Counter's table.
	 *
	 * @return array<string, bool[]>
	 */
	public function data_pvc_tables(): array {
		return array(
			'without content column' => array( false ),
			'with content column'    => array( true ),
		);
	}

	public function test_the_first_active_source_wins() {
		$this->only_sources( array( 'jetpack', 'koko', 'pvc' ) );
		add_filter(
			'undergrowth_popularity_sources',
			static function ( $sources ) {
				$sources['koko']['active'] = '__return_true';
				$sources['pvc']['active']  = '__return_true';

				return $sources;
			},
			20
		);

		$this->assertSame( 'koko', undergrowth_popularity_source() );

		$this->jetpack_on();
		$this->assertSame( 'jetpack', undergrowth_popularity_source() );
	}

	public function test_a_source_without_its_callbacks_is_ignored() {
		add_filter(
			'undergrowth_popularity_sources',
			static function () {
				return array(
					'broken' => array( 'label' => 'Broken' ),
					'custom' => array(
						'label'  => 'Custom',
						'active' => '__return_true',
						'read'   => static function () {
							return array( 5 => array( current_datetime()->format( 'Y-m-d' ) => 4 ) );
						},
					),
				);
			},
			20
		);

		$this->assertSame( 'custom', undergrowth_popularity_source() );

		undergrowth_read_popularity();

		$this->assertSame( 4.0, undergrowth_posts_popularity( array( 5 ) )[5] );
	}

	public function test_views_fade_by_half_each_half_life() {
		$faded = undergrowth_fade_views(
			array(
				1 => array(
					'2026-10-25' => 8,
					'2026-10-18' => 8,
					'2026-10-11' => 8,
				),
				2 => array( '2026-10-26' => 50 ),
				3 => array( '2026-10-25' => 0 ),
				4 => array( '2026-10-25' => -5 ),
			),
			'2026-10-25'
		);

		$this->assertEqualsWithDelta( 14.0, $faded[1], 0.001 );
		$this->assertSame( array( 1 ), array_keys( $faded ) );

		add_filter(
			'undergrowth_popularity_half_life',
			static function () {
				return 14;
			}
		);

		$this->assertEqualsWithDelta(
			8 + 8 / M_SQRT2 + 4,
			undergrowth_fade_views(
				array(
					1 => array(
						'2026-10-25' => 8,
						'2026-10-18' => 8,
						'2026-10-11' => 8,
					),
				),
				'2026-10-25'
			)[1],
			0.001
		);
	}

	public function test_another_source_can_supply_views_through_the_filter() {
		$this->views(
			array(
				7 => 12,
				8 => -3,
				9 => 'many',
			)
		);

		$this->assertSame(
			array(
				7  => 12.0,
				8  => 0.0,
				9  => null,
				10 => null,
			),
			undergrowth_posts_popularity( array( 7, 8, 9, 10 ) )
		);
	}

	public function test_popularity_lifts_a_post_past_a_slightly_closer_one() {
		$source  = $this->old( 'Oca', array( 1, 0, 0 ) );
		$closer  = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$popular = $this->old( 'Mashua', array( 1, 0.2, 0 ) );

		$this->assertSame( array( $closer, $popular ), undergrowth_more_like_this( $source, 6 ) );

		$this->views(
			array(
				$closer  => 1,
				$popular => 500,
			)
		);

		$this->assertSame( array( $popular, $closer ), undergrowth_more_like_this( $source, 6 ) );
	}

	public function test_popularity_never_lifts_a_post_past_one_much_closer() {
		$source  = $this->old( 'Oca', array( 1, 0, 0 ) );
		$closer  = $this->old( 'Yacon', array( 1, 0, 0 ) );
		$popular = $this->old( 'Mashua', array( 1, 0.6, 0 ) );

		// 0.86 × 1.15 stays below 1, however many views it has.
		$this->views(
			array(
				$closer  => 0,
				$popular => 1000000,
			)
		);

		$this->assertSame( array( $closer, $popular ), undergrowth_more_like_this( $source, 6 ) );

		add_filter(
			'undergrowth_similar_popularity_cap',
			static function () {
				return 0.5;
			}
		);

		$this->assertSame( array( $popular, $closer ), undergrowth_more_like_this( $source, 6 ) );
	}

	public function test_the_quiet_slot_goes_to_the_least_viewed_post_once_views_are_known() {
		global $wpdb;

		$source = $this->old( 'Oca', array( 1, 0, 0 ) );
		$first  = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$second = $this->old( 'Mashua', array( 1, 0.12, 0 ) );
		$third  = $this->old( 'Syrup', array( 1, 0.14, 0 ) );
		$fourth = $this->old( 'Ledger', array( 1, 0.16, 0 ) );

		// By links, the fourth would be the quiet one.
		foreach ( array( $first, $second, $third ) as $target ) {
			$wpdb->insert(
				undergrowth_links_table(),
				array(
					'source_id'  => $source,
					'target_id'  => $target,
					'state'      => 'accepted',
					'score'      => 0.5,
					'sentence'   => '',
					'created_at' => '2026-01-01 00:00:00',
					'updated_at' => '2026-01-01 00:00:00',
				)
			);
		}

		$this->assertSame( array( $first, $fourth ), undergrowth_more_like_this( $source, 2 ) );

		$this->views(
			array(
				$first  => 30,
				$second => 20,
				$third  => 1,
				$fourth => 25,
			)
		);

		$this->assertSame( array( $first, $third ), undergrowth_more_like_this( $source, 2 ) );
	}

	public function test_the_quiet_slot_falls_back_to_links_when_any_view_count_is_unknown() {
		global $wpdb;

		$source = $this->old( 'Oca', array( 1, 0, 0 ) );
		$first  = $this->old( 'Yacon', array( 1, 0.1, 0 ) );
		$second = $this->old( 'Mashua', array( 1, 0.12, 0 ) );
		$third  = $this->old( 'Syrup', array( 1, 0.14, 0 ) );
		$fourth = $this->old( 'Ledger', array( 1, 0.16, 0 ) );

		foreach ( array( $first, $second, $third ) as $target ) {
			$wpdb->insert(
				undergrowth_links_table(),
				array(
					'source_id'  => $source,
					'target_id'  => $target,
					'state'      => 'accepted',
					'score'      => 0.5,
					'sentence'   => '',
					'created_at' => '2026-01-01 00:00:00',
					'updated_at' => '2026-01-01 00:00:00',
				)
			);
		}

		// The third has views and the fourth none known, so links decide.
		$this->views(
			array(
				$first  => 30,
				$second => 20,
				$third  => 1,
			)
		);

		$this->assertSame( array( $first, $fourth ), undergrowth_more_like_this( $source, 2 ) );
	}

	public function test_settings_say_which_source_is_read() {
		ob_start();
		undergrowth_render_popularity_field();
		$none = ob_get_clean();

		$this->jetpack_on();
		ob_start();
		undergrowth_render_popularity_field();
		$jetpack = ob_get_clean();

		$this->assertStringContainsString( 'No stats plugin found', $none );
		$this->assertStringContainsString( 'from Jetpack Stats once a day', $jetpack );
		$this->assertStringContainsString( 'Jetpack asks WordPress.com', $jetpack );
		$this->assertStringNotContainsString( 'nothing leaves', $jetpack );

		$this->only_sources( array( 'pvc' ) );
		add_filter(
			'undergrowth_popularity_sources',
			static function ( $sources ) {
				$sources['pvc']['active'] = '__return_true';

				return $sources;
			},
			20
		);
		ob_start();
		undergrowth_render_popularity_field();
		$pvc = ob_get_clean();

		$this->assertStringContainsString( 'from Post Views Counter once a day', $pvc );
		$this->assertStringContainsString( 'nothing leaves your site', $pvc );
	}

	public function test_a_daily_read_leaves_the_kept_lists_alone() {
		$generation = (int) get_option( 'undergrowth_similar_generation', 0 );

		// Each view weighs the kept list with the day's views, so no list
		// needs measuring again.
		$this->jetpack_on();
		WPCOM_Stats::$top_posts = array( 'days' => array() );
		undergrowth_read_popularity();

		Manager::$connected = false;
		undergrowth_read_popularity();

		$this->assertSame( $generation, (int) get_option( 'undergrowth_similar_generation', 0 ) );
	}

	public function test_the_daily_read_is_scheduled() {
		wp_clear_scheduled_hook( 'undergrowth_read_popularity' );

		undergrowth_schedule_popularity();

		$this->assertSame( 'daily', wp_get_schedule( 'undergrowth_read_popularity' ) );
		$this->assertSame( 10, has_action( 'undergrowth_read_popularity', 'undergrowth_read_popularity' ) );
	}
}
