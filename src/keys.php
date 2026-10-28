<?php
/**
 * Reading the API keys, from wp-config.php or from the saved settings.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns an API key defined as a constant, usually in wp-config.php, which
 * keeps it out of the database and its backups.
 *
 * @param string $constant Name of the constant.
 * @return string The key, or an empty string when the constant is not a
 *                non-empty string.
 */
function undergrowth_key_from_config( string $constant ): string {
	$key = defined( $constant ) ? constant( $constant ) : '';

	return is_string( $key ) ? trim( $key ) : '';
}

/**
 * Returns an API key saved on the settings page.
 *
 * @param string $option Option name.
 * @return string The key, or an empty string when none is saved.
 */
function undergrowth_saved_key( string $option ): string {
	$key = get_option( $option, '' );

	return is_string( $key ) ? $key : '';
}
