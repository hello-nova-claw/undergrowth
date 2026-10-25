<?php
/**
 * A stand-in for Post Views Counter, loaded only by the tests that need it.
 *
 * The plugin keeps counts in {prefix}post_views(id, type, period, count),
 * with type 0 for a day and period as Ymd. The test creates the table.
 *
 * Mirrors pvc_get_post_views() as of Post Views Counter 1.8.1.
 *
 * @package Undergrowth
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

if ( ! class_exists( 'Post_Views_Counter' ) ) {
	/**
	 * The plugin's main class, checked to tell it is active.
	 */
	class Post_Views_Counter {}
}

if ( ! function_exists( 'pvc_get_post_views' ) ) {
	/**
	 * Returns a post's views; never called by Undergrowth.
	 *
	 * @param int|int[]            $post_id Post ID.
	 * @param string               $period  Period.
	 * @param array<string, mixed> $args    Arguments.
	 * @return int
	 */
	function pvc_get_post_views( $post_id = 0, $period = 'total', $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Matches the plugin's signature.
		return 0;
	}
}
