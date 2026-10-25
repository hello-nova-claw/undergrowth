<?php
/**
 * A stand-in for Jetpack's connection manager, loaded only when Jetpack is
 * not installed.
 *
 * Full Jetpack and the standalone Jetpack Stats plugin both ship this class
 * in their connection package. This one answers from a property a test sets.
 * PHPStan reads it for the signatures too.
 *
 * Mirrors is_connected() as of automattic/jetpack-connection v9.13.0.
 *
 * @package Undergrowth
 */

namespace Automattic\Jetpack\Connection;

/**
 * The part of Jetpack's connection manager the threshold meter uses.
 */
class Manager {

	/**
	 * Whether the site is connected to WordPress.com.
	 *
	 * @var bool
	 */
	public static $connected = false;

	/**
	 * Tells whether the site is connected to WordPress.com.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return self::$connected;
	}
}
