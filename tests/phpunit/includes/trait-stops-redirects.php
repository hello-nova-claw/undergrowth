<?php
/**
 * Test helper: stops a redirect so a handler's exit never runs.
 *
 * @package Undergrowth
 */

/**
 * Turns a redirect into an exception that carries its location.
 *
 * A test class using this hooks the method in set_up():
 * add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) ).
 */
trait Stops_Redirects {

	/**
	 * Stops a redirect so the handler's exit never runs.
	 *
	 * @param string $location Redirect target.
	 * @throws Exception Always, carrying the location.
	 */
	public function stop_redirect( $location ) {
		throw new Exception( esc_url_raw( $location ) );
	}
}
