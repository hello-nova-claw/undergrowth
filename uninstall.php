<?php
/**
 * Removes the plugin's table, options and scheduled events.
 *
 * @package Undergrowth
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/schema.php';

undergrowth_uninstall();
