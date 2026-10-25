<?php
/**
 * Popularity: recent views per post, read from the stats plugin a site
 * already runs.
 *
 * Undergrowth counts no views itself. Page caches hide most views from PHP,
 * a counting endpoint can be spammed, and counting honestly needs personal
 * data. Jetpack Stats, Koko Analytics and Post Views Counter already count,
 * so once a day, in cron, the first one found is asked for each post's views
 * over the last 30 days. Recent days count more, and old views fade, so
 * "popular" means popular now. With none of them, popularity is left out.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

use Automattic\Jetpack\Stats\WPCOM_Stats;

const UNDERGROWTH_POPULARITY_DAYS      = 30;
const UNDERGROWTH_POPULARITY_HALF_LIFE = 7;
const UNDERGROWTH_POPULARITY_TOP_POSTS = 500;

add_action( 'init', 'undergrowth_schedule_popularity' );
add_action( 'undergrowth_read_popularity', 'undergrowth_read_popularity' );

/**
 * Schedules the daily read of the stats plugin.
 */
function undergrowth_schedule_popularity(): void {
	if ( false === wp_next_scheduled( 'undergrowth_read_popularity' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'undergrowth_read_popularity' );
	}
}

/**
 * Lists the stats plugins popularity can be read from, first choice first.
 *
 * @return array<string, array{label: string, active: callable(): bool, read: callable(int): array<int, array<string, int>>}>
 *         Sources keyed by slug. Each reads daily views per post ID, keyed
 *         by date as Y-m-d, for the last given number of days.
 */
function undergrowth_popularity_sources(): array {
	$sources = array(
		'jetpack' => array(
			'label'  => __( 'Jetpack Stats', 'undergrowth' ),
			'active' => 'undergrowth_meter_has_stats',
			'read'   => 'undergrowth_read_jetpack_views',
		),
		'koko'    => array(
			'label'  => __( 'Koko Analytics', 'undergrowth' ),
			'active' => 'undergrowth_koko_is_active',
			'read'   => 'undergrowth_read_koko_views',
		),
		'pvc'     => array(
			'label'  => __( 'Post Views Counter', 'undergrowth' ),
			'active' => 'undergrowth_pvc_is_active',
			'read'   => 'undergrowth_read_pvc_views',
		),
	);

	/**
	 * Filters the stats plugins popularity can be read from, first choice first.
	 *
	 * The first source whose `active` callback returns true is read once a
	 * day. Its `read` callback receives a number of days and returns daily
	 * views keyed by post ID, then by date as Y-m-d.
	 *
	 * @param array<string, array{label: string, active: callable, read: callable}> $sources Sources keyed by slug. Default Jetpack Stats, Koko Analytics and Post Views Counter.
	 */
	$sources = apply_filters( 'undergrowth_popularity_sources', $sources );

	return is_array( $sources ) ? array_filter(
		$sources,
		static function ( $source ): bool {
			return is_array( $source ) && is_string( $source['label'] ?? null ) && is_callable( $source['active'] ?? null ) && is_callable( $source['read'] ?? null );
		}
	) : array();
}

/**
 * Returns the slug of the stats plugin popularity is read from.
 *
 * @return string|null The first active source, or null when none is.
 */
function undergrowth_popularity_source(): ?string {
	foreach ( undergrowth_popularity_sources() as $slug => $source ) {
		if ( call_user_func( $source['active'] ) ) {
			return $slug;
		}
	}

	return null;
}

/**
 * Tells whether Koko Analytics is active, in a version that stores views by
 * post ID.
 *
 * @return bool
 */
function undergrowth_koko_is_active(): bool {
	return defined( 'KOKO_ANALYTICS_VERSION' ) && version_compare( (string) constant( 'KOKO_ANALYTICS_VERSION' ), '2.0.13', '>=' );
}

/**
 * Tells whether Post Views Counter is active.
 *
 * @return bool
 */
function undergrowth_pvc_is_active(): bool {
	return function_exists( 'pvc_get_post_views' ) && class_exists( 'Post_Views_Counter' );
}

/**
 * Asks Jetpack Stats for each post's views on each of the last days.
 *
 * Jetpack fetches them from WordPress.com, as it does for its own screens.
 * Only the most viewed posts come back, which are the ones that matter.
 *
 * @param int $days How many days back.
 * @return array<int, array<string, int>> Views keyed by post ID, then date.
 */
function undergrowth_read_jetpack_views( int $days ): array {
	$top = ( new WPCOM_Stats() )->get_top_posts(
		array(
			'period' => 'day',
			'num'    => $days,
			'max'    => UNDERGROWTH_POPULARITY_TOP_POSTS,
		)
	);

	if ( ! is_array( $top ) || ! isset( $top['days'] ) || ! is_array( $top['days'] ) ) {
		return array();
	}

	$views = array();

	foreach ( $top['days'] as $date => $day ) {
		foreach ( (array) ( $day['postviews'] ?? array() ) as $row ) {
			$id = (int) ( $row['id'] ?? 0 );

			if ( $id > 0 && is_string( $date ) ) {
				$views[ $id ][ $date ] = ( $views[ $id ][ $date ] ?? 0 ) + (int) ( $row['views'] ?? 0 );
			}
		}
	}

	return $views;
}

/**
 * Reads each post's views on each of the last days from Koko Analytics's
 * own table.
 *
 * One post can be reached by more than one address, and Koko keeps a row
 * for each, so the rows of a day are added up.
 *
 * @param int $days How many days back.
 * @return array<int, array<string, int>> Views keyed by post ID, then date.
 */
function undergrowth_read_koko_views( int $days ): array {
	global $wpdb;

	// Koko dates its rows in the site's time zone.
	$today = current_datetime();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Koko Analytics's table has no API for daily views per post; read once a day.
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT date, post_id, SUM(pageviews) AS views FROM %i WHERE post_id > 0 AND date BETWEEN %s AND %s GROUP BY date, post_id',
			$wpdb->prefix . 'koko_analytics_post_stats',
			$today->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' ),
			$today->format( 'Y-m-d' )
		),
		ARRAY_A
	);

	$views = array();

	foreach ( $rows as $row ) {
		$views[ (int) $row['post_id'] ][ (string) $row['date'] ] = (int) $row['views'];
	}

	return $views;
}

/**
 * Reads each post's views on each of the last days from Post Views
 * Counter's own table.
 *
 * Daily rows have type 0 and a period of Ymd. Newer versions count other
 * content in the same table, marked in a content column, where 0 is a post.
 *
 * @param int $days How many days back.
 * @return array<int, array<string, int>> Views keyed by post ID, then date.
 */
function undergrowth_read_pvc_views( int $days ): array {
	global $wpdb;

	$table = $wpdb->prefix . 'post_views';
	$today = current_datetime();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Post Views Counter's table; read once a day.
	$columns = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
	$from    = $today->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Ymd' );
	$to      = $today->format( 'Ymd' );

	if ( in_array( 'content', $columns, true ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Post Views Counter has no API for daily views per post; read once a day.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, period, count FROM %i WHERE type = 0 AND content = 0 AND period BETWEEN %s AND %s', $table, $from, $to ), ARRAY_A );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Post Views Counter has no API for daily views per post; read once a day.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, period, count FROM %i WHERE type = 0 AND period BETWEEN %s AND %s', $table, $from, $to ), ARRAY_A );
	}

	$views = array();

	foreach ( (array) $rows as $row ) {
		$date = DateTimeImmutable::createFromFormat( '!Ymd', (string) $row['period'] );

		if ( false !== $date && (int) $row['id'] > 0 ) {
			$views[ (int) $row['id'] ][ $date->format( 'Y-m-d' ) ] = (int) $row['count'];
		}
	}

	return $views;
}

/**
 * Returns how many days it takes a view to count half as much.
 *
 * @return float Days, at least one.
 */
function undergrowth_popularity_half_life(): float {
	/**
	 * Filters how many days it takes a view to count half as much towards popularity.
	 *
	 * @param float $days Half-life in days. Default 7.
	 */
	return max( 1.0, (float) apply_filters( 'undergrowth_popularity_half_life', UNDERGROWTH_POPULARITY_HALF_LIFE ) );
}

/**
 * Adds up each post's daily views, a view counting half as much for every
 * half-life since its day.
 *
 * @param array<int, array<string, int>> $views Views keyed by post ID, then date as Y-m-d.
 * @param string                         $today Today's date as Y-m-d.
 * @return array<int, float> Faded views keyed by post ID, above zero only.
 */
function undergrowth_fade_views( array $views, string $today ): array {
	$half_life = undergrowth_popularity_half_life();
	$now       = strtotime( $today . ' UTC' );
	$faded     = array();

	foreach ( $views as $post_id => $days ) {
		$total = 0.0;

		foreach ( $days as $date => $count ) {
			$day = strtotime( $date . ' UTC' );

			if ( false !== $day && false !== $now && $day <= $now ) {
				$total += max( 0, (int) $count ) * pow( 0.5, ( $now - $day ) / DAY_IN_SECONDS / $half_life );
			}
		}

		if ( $total > 0 ) {
			$faded[ (int) $post_id ] = $total;
		}
	}

	return $faded;
}

/**
 * Reads recent views from the active stats plugin and keeps them, faded,
 * until the next day's read.
 *
 * With no stats plugin, anything kept from one is forgotten, so popularity
 * stops counting as soon as the plugin is gone. Either way, "More like
 * this" measures its lists again.
 */
function undergrowth_read_popularity(): void {
	$slug = undergrowth_popularity_source();

	if ( null === $slug ) {
		if ( delete_option( 'undergrowth_popularity' ) ) {
			undergrowth_forget_similar();
		}

		return;
	}

	$views = call_user_func( undergrowth_popularity_sources()[ $slug ]['read'], UNDERGROWTH_POPULARITY_DAYS );
	$views = is_array( $views ) ? $views : array();

	update_option(
		'undergrowth_popularity',
		array(
			'source' => $slug,
			'read'   => time(),
			'views'  => undergrowth_fade_views( $views, current_datetime()->format( 'Y-m-d' ) ),
		),
		false
	);

	// The kept lists were weighed with yesterday's views.
	undergrowth_forget_similar();
}

/**
 * Returns the recent, faded views of some posts.
 *
 * @param int[] $post_ids Post IDs.
 * @return array<int, float|null> Views keyed by post ID, or null where they are not known.
 */
function undergrowth_posts_popularity( array $post_ids ): array {
	$kept  = get_option( 'undergrowth_popularity' );
	$known = is_array( $kept ) && is_array( $kept['views'] ?? null ) && null !== undergrowth_popularity_source();
	$views = array();

	foreach ( $post_ids as $post_id ) {
		$value = $known ? (float) ( $kept['views'][ $post_id ] ?? 0 ) : null;

		/**
		 * Filters a post's recent views, which "More like this" weighs.
		 *
		 * Return a number to supply views from another source, or null when
		 * they are not known. A post with no views in the stats plugin has 0.
		 *
		 * @param float|null $views   Recent views, faded with age, or null. Default from Jetpack Stats, Koko Analytics or Post Views Counter, whichever is active.
		 * @param int        $post_id Post ID.
		 */
		$value = apply_filters( 'undergrowth_post_popularity', $value, (int) $post_id );

		$views[ (int) $post_id ] = is_numeric( $value ) ? max( 0.0, (float) $value ) : null;
	}

	return $views;
}

/**
 * Returns the name of the stats plugin popularity comes from.
 *
 * @return string|null The plugin's name, or null when none is active.
 */
function undergrowth_popularity_source_label(): ?string {
	$slug = undergrowth_popularity_source();

	return null === $slug ? null : undergrowth_popularity_sources()[ $slug ]['label'];
}
