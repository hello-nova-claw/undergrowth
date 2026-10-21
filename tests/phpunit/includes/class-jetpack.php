<?php
/**
 * A stand-in for the Jetpack plugin's main class, loaded only when Jetpack is
 * not installed.
 *
 * It has just the two methods the threshold meter asks, and answers from
 * properties a test sets. PHPStan reads it for the signatures too.
 *
 * @package Undergrowth
 */

/**
 * The parts of Jetpack the threshold meter uses.
 */
class Jetpack {

	/**
	 * Slugs of the modules that are on.
	 *
	 * @var string[]
	 */
	public static $active_modules = array();

	/**
	 * Whether the site is connected to WordPress.com.
	 *
	 * @var bool
	 */
	public static $connected = false;

	/**
	 * Tells whether a module is on.
	 *
	 * @param string $module Module slug.
	 * @return bool
	 */
	public static function is_module_active( $module ) {
		return in_array( $module, self::$active_modules, true );
	}

	/**
	 * Tells whether the site is connected to WordPress.com.
	 *
	 * @return bool
	 */
	public static function is_connection_ready() {
		return self::$connected;
	}
}
