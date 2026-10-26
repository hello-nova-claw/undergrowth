<?php
/**
 * A stand-in for Koko Analytics, loaded only by the tests that need it.
 *
 * Koko marks itself with a version constant, and from 2.0.13 keeps daily
 * views in {prefix}koko_analytics_post_stats(date, path_id, post_id,
 * visitors, pageviews), one row per address a post was reached by. The test
 * creates the table itself.
 *
 * @package Undergrowth
 */

if ( ! defined( 'KOKO_ANALYTICS_VERSION' ) ) {
	define( 'KOKO_ANALYTICS_VERSION', '2.5.3' );
}
