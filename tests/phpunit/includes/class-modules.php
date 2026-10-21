<?php
/**
 * A stand-in for Jetpack's module status class, loaded only when Jetpack is
 * not installed.
 *
 * Full Jetpack and the standalone Jetpack Stats plugin both ship this class
 * in their status package. This one answers from a property a test sets.
 * PHPStan reads it for the signatures too.
 *
 * @package Undergrowth
 */

namespace Automattic\Jetpack;

/**
 * The part of Jetpack's module status the threshold meter uses.
 */
class Modules {

	/**
	 * Slugs of the modules that are on.
	 *
	 * @var string[]
	 */
	public static $active = array();

	/**
	 * Tells whether a module is on.
	 *
	 * @param string $module Module slug.
	 * @return bool
	 */
	public function is_active( $module ) {
		return in_array( $module, self::$active, true );
	}
}
