<?php
/**
 * WP-CLI commands, loaded only when WP-CLI runs.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints how the site's posts score against each other, and the thresholds in use.
 *
 * The monthly cluster cycle measures the scores. To measure them now, run
 * `wp cron event run undergrowth_cluster_cycle` first.
 *
 * ## EXAMPLES
 *
 *     wp undergrowth scores
 */
function undergrowth_cli_scores(): void {
	foreach ( undergrowth_scores_report() as $line ) {
		WP_CLI::log( $line );
	}
}

WP_CLI::add_command( 'undergrowth scores', 'undergrowth_cli_scores' );
