<?php
/**
 * The garden count block, and the Garden page pattern that sets it above
 * the author's introduction, the filters and the feed.
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
	$args   = array(
		'render_callback' => 'undergrowth_render_garden_count_block',
		'style_handles'   => array( 'undergrowth-garden-count' ),
	);

	wp_register_style(
		'undergrowth-garden-count',
		plugins_url( 'assets/garden-count.css', UNDERGROWTH_FILE ),
		array(),
		UNDERGROWTH_VERSION
	);

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
 * The page's own title is its heading, so the pattern adds none. The intro
 * is only a placeholder the editor shows, so readers see none of it until
 * the author writes their own. Colours and spacing come from the theme.
 */
function undergrowth_register_garden_page_pattern(): void {
	register_block_pattern_category(
		'undergrowth',
		array(
			'label'       => __( 'Undergrowth', 'undergrowth' ),
			'description' => __( 'Pages for your garden, made of Undergrowth blocks.', 'undergrowth' ),
		)
	);

	// The page is built only from core layout blocks and the theme's own
	// spacing presets: the count as one quiet line above the intro, a rule,
	// then the filters and the feed, all at the theme's wide width.
	$content = '<!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"var:preset|spacing|50"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide"><!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"default"}} -->
<div class="wp-block-group"><!-- wp:undergrowth/garden-count {"className":"is-style-quiet"} /-->

<!-- wp:paragraph %1$s -->
<p class="has-large-font-size"></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:separator {"align":"wide","className":"is-style-wide"} -->
<hr class="wp-block-separator alignwide has-alpha-channel-opacity is-style-wide"/>
<!-- /wp:separator -->

<!-- wp:undergrowth/garden-filters {"align":"wide"} /-->

<!-- wp:undergrowth/garden-feed {"showFeatured":true,"align":"wide"} /--></div>
<!-- /wp:group -->';

	register_block_pattern(
		'undergrowth/garden-page',
		array(
			'title'       => __( 'Garden page', 'undergrowth' ),
			'description' => __( 'A garden page under its own title: the number of posts, your own introduction, the filters and the garden feed.', 'undergrowth' ),
			'categories'  => array( 'undergrowth' ),
			'keywords'    => array( __( 'garden', 'undergrowth' ), __( 'digital garden', 'undergrowth' ) ),
			'blockTypes'  => array( 'core/post-content' ),
			'postTypes'   => array( 'page' ),
			'content'     => sprintf(
				$content,
				// The placeholder lives in the block's comment, so a translation
				// is encoded the way the editor would save it. The intro takes
				// the theme's large size, as a page's opening line does.
				serialize_block_attributes(
					array(
						'placeholder' => __( 'Write a few words about your garden: what grows here, and how to wander it.', 'undergrowth' ),
						'fontSize'    => 'large',
					)
				)
			),
		)
	);
}
