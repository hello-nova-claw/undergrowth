<?php
/**
 * Blocks that show what Undergrowth knows on the front end.
 *
 * Every block here is dynamic and prints only fixed, translatable text, so no
 * model output can ever reach a reader.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'undergrowth_register_blocks' );

/**
 * Registers the blocks from their block.json files.
 *
 * The editor script exists only once built. An unbuilt checkout registers
 * from the source folder without it, so the front end still renders.
 */
function undergrowth_register_blocks(): void {
	// The badge hides its own screen-reader label, since a classic theme may
	// drop the block library's styles and with them .screen-reader-text.
	$blocks = array(
		'growth-stage'   => 'undergrowth_render_growth_stage_block',
		'more-like-this' => 'undergrowth_render_more_like_this_block',
	);

	foreach ( $blocks as $name => $render ) {
		$folder = dirname( UNDERGROWTH_FILE ) . '/build/blocks/' . $name;
		$args   = array(
			'render_callback' => $render,
			'style_handles'   => array( 'undergrowth-' . $name ),
		);

		wp_register_style(
			'undergrowth-' . $name,
			plugins_url( 'assets/' . $name . '.css', UNDERGROWTH_FILE ),
			array(),
			UNDERGROWTH_VERSION
		);

		if ( ! file_exists( $folder . '/block.json' ) ) {
			$folder                        = dirname( UNDERGROWTH_FILE ) . '/src/blocks/' . $name;
			$args['editor_script_handles'] = array();
		}

		register_block_type( $folder, $args );
	}
}

/**
 * Renders the growth-stage badge for the post in block context.
 *
 * A composted post shows nothing. Composting is the author's private choice
 * to rest a post, and naming it to readers would publish that choice and
 * could read as abandonment, which is what the stage exists to avoid.
 *
 * @param array<string, mixed> $attributes Block attributes.
 * @param string               $content    Inner content, always empty.
 * @param WP_Block             $block      Block instance.
 * @return string Badge markup, or an empty string when there is nothing to show.
 */
function undergrowth_render_growth_stage_block( $attributes, $content, $block ): string {
	$post_id = (int) ( $block->context['postId'] ?? 0 );

	// Only posts readers may see in the garden have a badge. A draft, a
	// private post or one behind a password would otherwise show the
	// Seedling default, and in a template or a Query Loop the password form
	// would not hide it.
	if ( ! undergrowth_is_garden_visible( $post_id ) ) {
		return '';
	}

	$stage = undergrowth_get_stage( $post_id );

	$name = undergrowth_stage_label( $stage );

	// The visible word alone does not say what it is, so screen readers get
	// the whole label instead.
	return sprintf(
		'<p %1$s><span class="screen-reader-text">%2$s</span><span aria-hidden="true">%3$s</span></p>',
		get_block_wrapper_attributes( array( 'class' => 'is-stage-' . $stage ) ),
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

/**
 * Renders "More like this": the posts closest in meaning to the post in
 * block context.
 *
 * Placing the block is the author's approval of the list it shows. It holds
 * only what the author wrote: titles, excerpts they wrote themselves,
 * featured images and links. Nothing a model wrote reaches readers.
 *
 * @param array<string, mixed> $attributes Block attributes.
 * @param string               $content    Inner content, always empty.
 * @param WP_Block             $block      Block instance.
 * @return string List markup, or an empty string when there is nothing to show.
 */
function undergrowth_render_more_like_this_block( $attributes, $content, $block ): string {
	$post_ids = undergrowth_more_like_this( (int) ( $block->context['postId'] ?? 0 ), (int) ( $attributes['postsToShow'] ?? 3 ) );

	if ( array() === $post_ids ) {
		return '';
	}

	$image   = ! empty( $attributes['showImage'] );
	$excerpt = ! empty( $attributes['showExcerpt'] );
	$items   = '';

	foreach ( $post_ids as $id ) {
		$post = get_post( $id );
		$link = esc_url( (string) get_permalink( $post ) );

		// The title beside it names the link, so the image adds nothing for
		// a screen reader and is left out of the link's name.
		$picture = $image && has_post_thumbnail( $post ) ? get_the_post_thumbnail( $post, 'medium', array( 'alt' => '' ) ) : '';
		$items  .= '<li class="undergrowth-more-like-this-item">';
		$items  .= sprintf(
			'<a class="undergrowth-more-like-this-link" href="%1$s">%2$s<span class="undergrowth-more-like-this-title">%3$s</span></a>',
			$link,
			$picture,
			esc_html( get_the_title( $post ) )
		);

		// Only an excerpt the author wrote, never one cut from the post.
		if ( $excerpt && has_excerpt( $post ) ) {
			$items .= '<p class="undergrowth-more-like-this-excerpt">' . esc_html( wp_strip_all_tags( $post->post_excerpt ) ) . '</p>';
		}

		$items .= '</li>';
	}

	return sprintf(
		'<nav %1$s><ul class="undergrowth-more-like-this-list">%2$s</ul></nav>',
		get_block_wrapper_attributes( array( 'aria-label' => __( 'More like this', 'undergrowth' ) ) ),
		$items
	);
}
