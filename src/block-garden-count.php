<?php
/**
 * The garden count block, and the Garden page pattern that sets it beside a
 * heading, the filters and the feed.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'undergrowth_register_garden_count_block' );
add_action( 'init', 'undergrowth_register_garden_page_pattern' );

/**
 * Registers the block from its block.json file.
 *
 * The editor script exists only once built. An unbuilt checkout registers
 * from the source folder without it, so the front end still renders.
 */
function undergrowth_register_garden_count_block(): void {
	$folder = dirname( UNDERGROWTH_FILE ) . '/build/blocks/garden-count';
	$args   = array( 'render_callback' => 'undergrowth_render_garden_count_block' );

	if ( ! file_exists( $folder . '/block.json' ) ) {
		$folder                        = dirname( UNDERGROWTH_FILE ) . '/src/blocks/garden-count';
		$args['editor_script_handles'] = array();
	}

	register_block_type( $folder, $args );
}

/**
 * Renders the number of posts readers may see in the garden.
 *
 * It counts the whole garden, not the filtered feed: the filters announce
 * their own count.
 *
 * @param array<string, mixed> $attributes Block attributes.
 * @return string Count markup, or an empty string when the garden is empty.
 */
function undergrowth_render_garden_count_block( $attributes ): string {
	$count = count( undergrowth_garden_feed_posts( 'tended', 'post_format', array_fill_keys( array( 'stage', 'type', 'topic', 'group' ), '' ) ) );

	if ( 0 === $count ) {
		return '';
	}

	$label = '';

	if ( ! empty( $attributes['showLabel'] ) ) {
		$label = ' <span class="wp-block-undergrowth-garden-count-label">' . esc_html(
			/* translators: Follows the number of posts, which is shown on its own. */
			_n( 'post in the garden', 'posts in the garden', $count, 'undergrowth' )
		) . '</span>';
	}

	return sprintf(
		'<p %1$s><span class="wp-block-undergrowth-garden-count-number">%2$s</span>%3$s</p>',
		get_block_wrapper_attributes(),
		esc_html( number_format_i18n( $count ) ),
		$label
	);
}

/**
 * Registers the Garden page pattern, in an Undergrowth category.
 *
 * The heading is the author's to change, and the intro is only a
 * placeholder the editor shows, so readers see none of it until the author
 * writes their own. Colours and spacing come from the theme.
 */
function undergrowth_register_garden_page_pattern(): void {
	register_block_pattern_category(
		'undergrowth',
		array(
			'label'       => __( 'Undergrowth', 'undergrowth' ),
			'description' => __( 'Pages for your garden, made of Undergrowth blocks.', 'undergrowth' ),
		)
	);

	$content = '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide"><!-- wp:columns {"verticalAlignment":"bottom"} -->
<div class="wp-block-columns are-vertically-aligned-bottom"><!-- wp:column {"verticalAlignment":"bottom"} -->
<div class="wp-block-column is-vertically-aligned-bottom"><!-- wp:heading -->
<h2 class="wp-block-heading">%1$s</h2>
<!-- /wp:heading -->

<!-- wp:paragraph %2$s -->
<p></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"bottom"} -->
<div class="wp-block-column is-vertically-aligned-bottom"><!-- wp:undergrowth/garden-count /--></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:undergrowth/garden-filters /-->

<!-- wp:undergrowth/garden-feed {"showFeatured":true} /--></div>
<!-- /wp:group -->';

	register_block_pattern(
		'undergrowth/garden-page',
		array(
			'title'       => __( 'Garden page', 'undergrowth' ),
			'description' => __( 'A garden page: a heading, your own introduction, the number of posts, the filters and the garden feed.', 'undergrowth' ),
			'categories'  => array( 'undergrowth' ),
			'keywords'    => array( __( 'garden', 'undergrowth' ), __( 'digital garden', 'undergrowth' ) ),
			'blockTypes'  => array( 'core/post-content' ),
			'postTypes'   => array( 'page' ),
			'content'     => sprintf(
				$content,
				esc_html__( 'The Garden', 'undergrowth' ),
				// The placeholder lives in the block's comment, so a translation
				// is encoded the way the editor would save it.
				serialize_block_attributes( array( 'placeholder' => __( 'Write a few words about your garden: what grows here, and how to wander it.', 'undergrowth' ) ) )
			),
		)
	);
}
