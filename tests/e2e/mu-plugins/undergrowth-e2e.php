<?php
/**
 * Test-only provider for end-to-end runs: deterministic vectors, embedded at once.
 *
 * Loaded into the wp-env tests site only, never shipped.
 *
 * @package Undergrowth
 */

// PHPUnit runs in this same site and expects no provider. Checking for the
// test suite rather than the command line keeps the provider working in
// WordPress Playground, where every request runs under the CLI SAPI.
if ( function_exists( 'tests_add_filter' ) ) {
	return;
}

add_filter(
	'undergrowth_embed_provider',
	static function ( $vector, $text ) {
		$text = strtolower( (string) $text );

		// Counting a few fixture words gives posts that share words similar vectors.
		return array(
			(float) substr_count( $text, 'oca' ),
			(float) substr_count( $text, 'yacon' ),
			(float) substr_count( $text, 'syrup' ),
			(float) substr_count( $text, 'ledger' ),
			1.0,
		);
	},
	10,
	2
);

add_filter(
	'undergrowth_embed_identity',
	static function () {
		return array(
			'provider' => 'e2e',
			'model'    => 'word-counts',
		);
	}
);

// Cron does not run reliably during a test, so posts are embedded on save.
add_action(
	'wp_after_insert_post',
	static function ( $post_id ) {
		if ( function_exists( 'undergrowth_embed_post' ) ) {
			undergrowth_embed_post( (int) $post_id );
		}
	},
	20
);

