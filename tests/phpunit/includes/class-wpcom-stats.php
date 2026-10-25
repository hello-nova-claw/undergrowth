<?php
/**
 * A stand-in for Jetpack's Stats client, loaded only when Jetpack is not
 * installed.
 *
 * Jetpack's own class asks WordPress.com; this one returns whatever a test
 * put in $visits and records each request. PHPStan reads it for the
 * signatures too.
 *
 * Mirrors get_visits() as of automattic/jetpack-stats v0.23.0.
 *
 * @package Undergrowth
 */

namespace Automattic\Jetpack\Stats;

/**
 * The part of Jetpack's Stats client the threshold meter uses.
 */
class WPCOM_Stats {

	/**
	 * The answer to every visits request.
	 *
	 * @var array<string, mixed>|\WP_Error
	 */
	public static $visits = array();

	/**
	 * Arguments of every visits request, oldest first.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static $requests = array();

	/**
	 * Returns the site's views and visitors per period.
	 *
	 * @param array<string, mixed> $args Query arguments, such as unit and quantity.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_visits( $args = array() ) {
		self::$requests[] = $args;

		return self::$visits;
	}
}
