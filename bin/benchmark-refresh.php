<?php
/**
 * Measures the memory and time one proposal refresh takes.
 *
 * Seeds N published posts, each with a random unit vector, then times
 * undergrowth_refresh_around() for the last post. Destroys the site's posts
 * and the plugin's tables, so run it only against a throwaway site, and it
 * refuses to start without the word "wipe" as its third argument:
 *
 *     wp eval-file bin/benchmark-refresh.php 1000 1536 wipe
 *
 * The other arguments are the number of posts and the number of
 * dimensions. The fingerprint is a hash of the proposals
 * table, so two runs on the same seed can be compared across code changes.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions.rand_mt_rand, WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand, WordPress.Security.EscapeOutput.OutputNotEscaped -- A command-line benchmark against a throwaway site.

/**
 * Builds a random vector of unit length.
 *
 * @param int $dimensions Length of the vector.
 * @return float[] The vector.
 */
function undergrowth_benchmark_unit_vector( int $dimensions ): array {
	$vector = array();

	for ( $i = 0; $i < $dimensions; $i++ ) {
		$vector[] = mt_rand() / mt_getrandmax() * 2 - 1;
	}

	$norm = sqrt(
		array_sum(
			array_map(
				static function ( $value ) {
					return $value * $value;
				},
				$vector
			)
		)
	);

	return array_map(
		static function ( $value ) use ( $norm ) {
			return $value / $norm;
		},
		$vector
	);
}

if ( 'wipe' !== ( $args[2] ?? '' ) ) {
	fwrite( STDERR, 'This deletes every post on the site. Pass "wipe" as the third argument to confirm.' . PHP_EOL );
	exit( 1 );
}

// Without a reset, the peak would include seeding the posts, not just the refresh.
if ( ! function_exists( 'memory_reset_peak_usage' ) ) {
	fwrite( STDERR, 'The benchmark needs PHP 8.2 or later to measure the refresh on its own.' . PHP_EOL );
	exit( 1 );
}

// With suggestions off the refresh returns at once and would measure nothing.
if ( ! undergrowth_suggestions_enabled() ) {
	fwrite( STDERR, 'Link suggestions are switched off, so there is no refresh to measure.' . PHP_EOL );
	exit( 1 );
}

global $wpdb;

$undergrowth_posts      = max( 2, (int) $args[0] );
$undergrowth_dimensions = max( 1, (int) $args[1] );
$undergrowth_words      = array( 'oca', 'yacon', 'mashua', 'ulluco', 'achira', 'arracacha', 'maca', 'mauka', 'jicama', 'tuber', 'frost', 'mulch', 'harvest', 'seed', 'compost', 'shade', 'terrace', 'flower' );

mt_srand( 26 );

// Every new post would otherwise be queued for embedding with a provider.
remove_all_actions( 'save_post' );
remove_all_actions( 'transition_post_status' );
remove_all_actions( 'undergrowth_post_embedded' );

// Random vectors in many dimensions are nearly orthogonal, so no pair would
// clear the usual minimum and nothing would be proposed.
add_filter(
	'undergrowth_min_link_similarity',
	static function () {
		return -1.0;
	}
);

$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', undergrowth_vectors_table() ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', undergrowth_links_table() ) );

foreach ( get_posts(
	array(
		'post_type'   => 'any',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
) as $undergrowth_old ) {
	wp_delete_post( (int) $undergrowth_old, true );
}

$undergrowth_ids = array();

for ( $undergrowth_i = 0; $undergrowth_i < $undergrowth_posts; $undergrowth_i++ ) {
	$undergrowth_sentences = array();

	for ( $undergrowth_s = 0; $undergrowth_s < 6; $undergrowth_s++ ) {
		$undergrowth_sentence = array();

		for ( $undergrowth_w = 0; $undergrowth_w < 8; $undergrowth_w++ ) {
			$undergrowth_sentence[] = $undergrowth_words[ mt_rand( 0, count( $undergrowth_words ) - 1 ) ];
		}

		$undergrowth_sentences[] = ucfirst( implode( ' ', $undergrowth_sentence ) ) . '.';
	}

	$undergrowth_id = wp_insert_post(
		array(
			'post_title'   => 'Bed ' . $undergrowth_i,
			'post_content' => '<!-- wp:paragraph --><p>' . implode( ' ', $undergrowth_sentences ) . '</p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
		)
	);

	undergrowth_save_vector(
		$undergrowth_id,
		undergrowth_benchmark_unit_vector( $undergrowth_dimensions ),
		array(
			'provider'     => 'benchmark',
			'model'        => 'random',
			'language'     => 'en_US',
			'content_hash' => str_repeat( '0', 64 ),
		)
	);

	$undergrowth_ids[] = $undergrowth_id;
}

// Start from what WordPress itself holds, with nothing cached from seeding.
wp_cache_flush();
$wpdb->flush();
gc_collect_cycles();

memory_reset_peak_usage();

$undergrowth_baseline = memory_get_usage();
$undergrowth_started  = microtime( true );

undergrowth_refresh_around( (int) end( $undergrowth_ids ) );

$undergrowth_seconds = microtime( true ) - $undergrowth_started;
$undergrowth_peak    = memory_get_peak_usage() - $undergrowth_baseline;

$undergrowth_rows = $wpdb->get_results(
	$wpdb->prepare( 'SELECT source_id, target_id, state, score, sentence_index FROM %i ORDER BY source_id, target_id', undergrowth_links_table() ),
	ARRAY_N
);

// Post IDs depend on what the site held before, so rows name posts by the
// order they were seeded in. Scores are rounded to survive rounding noise.
$undergrowth_positions   = array_flip( $undergrowth_ids );
$undergrowth_fingerprint = md5(
	(string) wp_json_encode(
		array_map(
			static function ( $row ) use ( $undergrowth_positions ) {
				$row[0] = $undergrowth_positions[ (int) $row[0] ] ?? -1;
				$row[1] = $undergrowth_positions[ (int) $row[1] ] ?? -1;
				$row[3] = round( (float) $row[3], 6 );
				return $row;
			},
			(array) $undergrowth_rows
		)
	)
);

printf(
	'posts=%d dimensions=%d peak_above_baseline=%.1fMB seconds=%.3f proposals=%d fingerprint=%s%s',
	$undergrowth_posts,
	$undergrowth_dimensions,
	$undergrowth_peak / 1048576,
	$undergrowth_seconds,
	count( (array) $undergrowth_rows ),
	$undergrowth_fingerprint,
	PHP_EOL
);
