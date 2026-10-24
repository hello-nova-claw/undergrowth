<?php
/**
 * Says in Site Health when the backfill has stalled, and when visitors can
 * read the per-post files.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'site_status_tests', 'undergrowth_add_site_health_tests' );

/**
 * Adds the per-post folder test to Site Health once the folder exists, and
 * the backfill test while there is a provider to read with and posts are
 * left to read.
 *
 * @param array $tests Site Health tests, by type.
 * @return array The tests, with the backfill test among the direct ones.
 *
 * @phpstan-param array<string, array<string, mixed>> $tests
 * @phpstan-return array<string, array<string, mixed>>
 */
function undergrowth_add_site_health_tests( array $tests ): array {
	$root = undergrowth_memory_root();

	if ( '' !== $root && is_dir( $root . '/posts' ) ) {
		$tests['direct']['undergrowth_memory_folder'] = array(
			'label' => __( 'Undergrowth’s per-post files are private', 'undergrowth' ),
			'test'  => 'undergrowth_site_health_memory_folder',
		);
	}

	if ( ! undergrowth_has_embed_provider() ) {
		return $tests;
	}

	$counts = undergrowth_index_counts();

	if ( $counts['indexed'] >= $counts['total'] ) {
		return $tests;
	}

	$tests['direct']['undergrowth_backfill'] = array(
		'label' => __( 'Undergrowth reads your posts', 'undergrowth' ),
		'test'  => 'undergrowth_site_health_backfill',
	);

	return $tests;
}

/**
 * Says that posts are waiting and nothing has been read for a day.
 *
 * @return string Plain text, one sentence.
 */
function undergrowth_backfill_stalled_text(): string {
	return sprintf(
		/* translators: %1$s: DISABLE_WP_CRON, the name of a setting in wp-config.php. */
		__( 'No post has been read for a day, which usually means WP-Cron is not running: it runs only when someone visits the site, and not at all when %1$s is set and no server cron job calls it.', 'undergrowth' ),
		'DISABLE_WP_CRON'
	);
}

/**
 * Tests whether the backfill is keeping up, for Site Health.
 *
 * A stall is recommended, not critical: the site works, it only waits.
 *
 * @return array Site Health result.
 *
 * @phpstan-return array<string, mixed>
 */
function undergrowth_site_health_backfill(): array {
	$result = array(
		'label'       => __( 'Undergrowth is reading your posts', 'undergrowth' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Performance', 'undergrowth' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html__( 'Undergrowth reads your published posts in the background, with WP-Cron.', 'undergrowth' ) . '</p>',
		'actions'     => '',
		'test'        => 'undergrowth_backfill',
	);

	if ( ! undergrowth_backfill_stalled() ) {
		return $result;
	}

	$result['label']       = __( 'Undergrowth has not read a post for a day', 'undergrowth' );
	$result['status']      = 'recommended';
	$result['description'] = '<p>' . esc_html( undergrowth_backfill_stalled_text() ) . '</p>';
	$result['actions']     = sprintf(
		'<p><a href="%1$s">%2$s</a></p>',
		esc_url( __( 'https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/', 'undergrowth' ) ),
		esc_html__( 'How to run WP-Cron from a server cron job', 'undergrowth' )
	);

	return $result;
}

/**
 * Tests whether visitors can read the per-post files, for Site Health.
 *
 * Each run checks again. When no answer comes, the last result stands.
 *
 * @return array Site Health result.
 *
 * @phpstan-return array<string, mixed>
 */
function undergrowth_site_health_memory_folder(): array {
	$exposed = undergrowth_check_memory_folder() ?? undergrowth_memory_exposed();
	$result  = array(
		'label'       => __( 'Undergrowth’s per-post files cannot be read from the web', 'undergrowth' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Security', 'undergrowth' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html__( 'Undergrowth keeps a plain-text file for each post in the uploads folder, and this server refuses visitors who ask for one.', 'undergrowth' ) . '</p>',
		'actions'     => '',
		'test'        => 'undergrowth_memory_folder',
	);

	if ( ! $exposed ) {
		return $result;
	}

	$rules = '';

	foreach ( undergrowth_memory_deny_rules() as $server => $rule ) {
		$rules .= sprintf( '<p>%1$s</p><pre><code>%2$s</code></pre>', esc_html( $server ), esc_html( $rule ) );
	}

	$result['label']       = __( 'Undergrowth’s per-post files can be read from the web', 'undergrowth' );
	$result['status']      = 'recommended';
	$result['description'] = '<p>' . esc_html( undergrowth_memory_exposed_text() ) . '</p>' . $rules;
	$result['actions']     = sprintf(
		'<p><a href="%1$s">%2$s</a></p>',
		esc_url( UNDERGROWTH_MEMORY_DOCS ),
		esc_html__( 'How to keep the per-post files private', 'undergrowth' )
	);

	return $result;
}
