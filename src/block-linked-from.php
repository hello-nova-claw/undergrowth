<?php
/**
 * The "Linked from" block: which of the author's posts link to this one.
 *
 * It is built only from links the author accepted, so it shows nothing the
 * author has not already approved, and only the author's own titles.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'undergrowth_register_linked_from_block' );

/**
 * Registers the block from its block.json file.
 *
 * The editor script exists only once built. An unbuilt checkout registers
 * from the source folder without it, so the front end still renders.
 */
function undergrowth_register_linked_from_block(): void {
	$folder = dirname( UNDERGROWTH_FILE ) . '/build/blocks/linked-from';
	$args   = array(
		'render_callback' => 'undergrowth_render_linked_from_block',
		'style_handles'   => array( 'undergrowth-linked-from' ),
	);

	wp_register_style(
		'undergrowth-linked-from',
		plugins_url( 'assets/linked-from.css', UNDERGROWTH_FILE ),
		array(),
		UNDERGROWTH_VERSION
	);

	if ( ! file_exists( $folder . '/block.json' ) ) {
		$folder                        = dirname( UNDERGROWTH_FILE ) . '/src/blocks/linked-from';
		$args['editor_script_handles'] = array();
	}

	register_block_type( $folder, $args );
}

/**
 * Returns the posts readers may see that hold an accepted link to a post,
 * newest first.
 *
 * Only the row says the author accepted the link. If they later took the
 * link out of their post by hand, the row stays, as it does for stages.
 *
 * @param int $post_id Post the links point to.
 * @param int $limit   Most posts to return.
 * @return WP_Post[] Linking posts.
 */
function undergrowth_linked_from_posts( int $post_id, int $limit ): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; states change on every accept.
	$source_ids = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT source_id FROM %i WHERE target_id = %d AND state = %s AND source_id <> %d',
			undergrowth_links_table(),
			$post_id,
			'accepted',
			$post_id
		)
	);

	$posts = array_filter(
		array_map( 'get_post', undergrowth_filter_garden_visible( array_map( 'intval', (array) $source_ids ) ) ),
		static function ( $post ): bool {
			return $post instanceof WP_Post;
		}
	);

	usort(
		$posts,
		static function ( WP_Post $a, WP_Post $b ): int {
			return array( $b->post_date_gmt, $b->ID ) <=> array( $a->post_date_gmt, $a->ID );
		}
	);

	return array_slice( $posts, 0, $limit );
}

/**
 * Renders the list of posts that link to the post in block context.
 *
 * The post itself must be one readers may see, as must every post listed,
 * so a composted or private post never shows up as a source.
 *
 * @param array<string, mixed> $attributes Block attributes.
 * @param string               $content    Inner content, always empty.
 * @param WP_Block             $block      Block instance.
 * @return string List markup, or an empty string when there is nothing to show.
 */
function undergrowth_render_linked_from_block( $attributes, $content, $block ): string {
	$post_id = (int) ( $block->context['postId'] ?? 0 );

	// With no post in context, get_post() would fall back to the global
	// post, such as the first post of an archive.
	if ( 0 === $post_id || ! undergrowth_is_garden_visible( $post_id ) ) {
		return '';
	}

	// WordPress replaces a number outside block.json's range with the
	// default before this runs.
	$posts = undergrowth_linked_from_posts( $post_id, (int) $attributes['maxItems'] );

	if ( array() === $posts ) {
		return '';
	}

	$heading = trim( (string) ( $attributes['heading'] ?? '' ) );

	if ( '' === $heading ) {
		$heading = __( 'Linked from', 'undergrowth' );
	}

	$items = '';

	foreach ( $posts as $post ) {
		$title = wp_strip_all_tags( get_the_title( $post ) );

		$items .= sprintf(
			'<li><a href="%1$s">%2$s</a></li>',
			esc_url( (string) get_permalink( $post ) ),
			esc_html( '' === $title ? __( '(no title)', 'undergrowth' ) : $title )
		);
	}

	return sprintf(
		'<div %1$s><h2 class="wp-block-heading">%2$s</h2><ul>%3$s</ul></div>',
		get_block_wrapper_attributes(),
		esc_html( $heading ),
		$items
	);
}
