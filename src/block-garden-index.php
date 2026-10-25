<?php
/**
 * The garden index block: the author's posts by Map group, each with its
 * growth stage, so readers can wander the garden by topic, not by date.
 *
 * Groups are named as the Map names them, after a post's own title. Nothing
 * a model wrote is shown.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'undergrowth_register_garden_index_block' );

/**
 * Registers the block from its block.json file.
 *
 * The editor script exists only once built. An unbuilt checkout registers
 * from the source folder without it, so the front end still renders.
 */
function undergrowth_register_garden_index_block(): void {
	$folder = dirname( UNDERGROWTH_FILE ) . '/build/blocks/garden-index';
	$args   = array(
		'render_callback' => 'undergrowth_render_garden_index_block',
		'style_handles'   => array( 'undergrowth-garden-index' ),
	);

	// Each stage's full label is for screen readers only, and a classic
	// theme may drop the rule that hides .screen-reader-text.
	wp_register_style(
		'undergrowth-garden-index',
		plugins_url( 'assets/garden-index.css', UNDERGROWTH_FILE ),
		array(),
		UNDERGROWTH_VERSION
	);

	if ( ! file_exists( $folder . '/block.json' ) ) {
		$folder                        = dirname( UNDERGROWTH_FILE ) . '/src/blocks/garden-index';
		$args['editor_script_handles'] = array();
	}

	register_block_type( $folder, $args );
}

/**
 * Stores the Map's groups for the index, at the end of each cluster pass.
 *
 * Finding the groups measures every cluster's vectors, too much for a page
 * view. Which posts readers may see is checked when the block renders, so a
 * post composted or unpublished since leaves the index at once.
 */
function undergrowth_build_garden_index(): void {
	$groups = array();

	foreach ( undergrowth_cluster_map() as $cluster ) {
		$groups[] = array(
			'leader'  => $cluster['leader'],
			'members' => $cluster['members'],
		);
	}

	update_option( 'undergrowth_garden_index', array( 'groups' => $groups ), false );
}

/**
 * Returns the posts readers may see, by group, in Map order, then those in
 * no group, newest first.
 *
 * A post published since the last cluster pass is in no group yet.
 *
 * @param string $stage Only posts at this stage, or an empty string for all.
 * @return array<int, array{name: string, posts: int[]}> Groups with posts to show. The posts in no
 *         group come last, with an empty name.
 */
function undergrowth_garden_index_groups( string $stage ): array {
	$cached  = get_option( 'undergrowth_garden_index', array() );
	$lists   = array();
	$grouped = array();

	foreach ( is_array( $cached ) ? (array) ( $cached['groups'] ?? array() ) : array() as $group ) {
		$members  = array_map( 'intval', (array) ( $group['members'] ?? array() ) );
		$lists[]  = $members;
		$grouped += array_fill_keys( $members, true );
	}

	$apart = array_map( 'intval', array_keys( array_diff_key( undergrowth_garden_posts(), $grouped ) ) );

	// Every post and its meta in two queries, however many the index holds.
	$visible = array_fill_keys( undergrowth_filter_garden_visible( array_merge( $apart, ...$lists ) ), true );
	$keep    = static function ( array $post_ids ) use ( $visible ): array {
		return array_values(
			array_filter(
				$post_ids,
				static function ( int $post_id ) use ( $visible ): bool {
					return isset( $visible[ $post_id ] );
				}
			)
		);
	};
	$staged  = static function ( array $post_ids ) use ( $stage ): array {
		return '' === $stage ? $post_ids : array_values(
			array_filter(
				$post_ids,
				static function ( int $post_id ) use ( $stage ): bool {
					return undergrowth_get_stage( $post_id ) === $stage;
				}
			)
		);
	};
	$groups  = array();

	foreach ( $lists as $members ) {
		$members = $keep( $members );
		$shown   = $staged( $members );

		if ( array() === $shown ) {
			continue;
		}

		// Named after its first post readers may see, as the Map names a
		// group after its first post, so a hidden title is never shown. A
		// stage filter keeps the group's name.
		$groups[] = array(
			'name'  => undergrowth_plain_line( undergrowth_post_title( $members[0] ) ),
			'posts' => $shown,
		);
	}

	$apart = $staged( $keep( $apart ) );

	if ( array() !== $apart ) {
		$groups[] = array(
			'name'  => '',
			'posts' => $apart,
		);
	}

	return $groups;
}

/**
 * Renders the garden index.
 *
 * @param array<string, mixed> $attributes Block attributes.
 * @return string Index markup, or an empty string when there is nothing to show.
 */
function undergrowth_render_garden_index_block( $attributes ): string {
	$sections = '';

	foreach ( undergrowth_garden_index_groups( (string) $attributes['stage'] ) as $group ) {
		$items = '';

		foreach ( $group['posts'] as $post_id ) {
			$stage = undergrowth_get_stage( $post_id );
			$name  = undergrowth_stage_label( $stage );

			$items .= sprintf(
				'<li><a href="%1$s">%2$s</a> <span class="wp-block-undergrowth-garden-index-stage is-stage-%3$s"><span class="screen-reader-text">%4$s</span><span aria-hidden="true">%5$s</span></span></li>',
				esc_url( (string) get_permalink( $post_id ) ),
				esc_html( undergrowth_plain_line( undergrowth_post_title( $post_id ) ) ),
				esc_attr( $stage ),
				esc_html(
					sprintf(
						/* translators: %s: growth stage name, such as Sprout. */
						__( 'Growth stage: %s', 'undergrowth' ),
						$name
					)
				),
				esc_html( $name )
			);
		}

		$sections .= sprintf(
			'<h2 class="wp-block-heading">%1$s</h2><ul>%2$s</ul>',
			esc_html( '' === $group['name'] ? __( 'Other posts', 'undergrowth' ) : $group['name'] ),
			$items
		);
	}

	if ( '' === $sections ) {
		return '';
	}

	return sprintf( '<div %1$s>%2$s</div>', get_block_wrapper_attributes(), $sections );
}
