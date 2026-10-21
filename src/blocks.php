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
	$folder = dirname( UNDERGROWTH_FILE ) . '/build/blocks/growth-stage';
	$args   = array( 'render_callback' => 'undergrowth_render_growth_stage_block' );

	if ( ! file_exists( $folder . '/block.json' ) ) {
		$folder                        = dirname( UNDERGROWTH_FILE ) . '/src/blocks/growth-stage';
		$args['editor_script_handles'] = array();
	}

	register_block_type( $folder, $args );
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
	$post = get_post( (int) ( $block->context['postId'] ?? 0 ) );

	// Only posts in the garden have a stage. A draft, a private post or one
	// behind a password would otherwise show the Seedling default, and in a
	// template or a Query Loop the password form would not hide it. A post
	// type added to the index need not be public, hence the read check.
	if (
		! $post instanceof WP_Post
		|| ! undergrowth_is_indexable( $post )
		|| ( ! is_post_publicly_viewable( $post ) && ! current_user_can( 'read_post', $post->ID ) )
	) {
		return '';
	}

	$stage = undergrowth_get_stage( $post->ID );

	if ( 'composting' === $stage ) {
		return '';
	}

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
