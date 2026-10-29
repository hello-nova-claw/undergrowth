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
add_action( 'init', 'undergrowth_register_block_styles' );

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
 * Registers the block styles authors can pick in place of the plain lists
 * and the display numeral.
 *
 * Each is a real alternative layout, drawn in the plugin's stylesheets from
 * the theme's own colours and spacing.
 */
function undergrowth_register_block_styles(): void {
	register_block_style(
		'undergrowth/more-like-this',
		array(
			'name'  => 'columns',
			'label' => _x( 'Columns', 'block style', 'undergrowth' ),
		)
	);

	register_block_style(
		'undergrowth/garden-index',
		array(
			'name'  => 'specimen',
			'label' => _x( 'Specimen', 'block style', 'undergrowth' ),
		)
	);

	register_block_style(
		'undergrowth/garden-count',
		array(
			'name'  => 'quiet',
			'label' => _x( 'Quiet', 'block style', 'undergrowth' ),
		)
	);
}

/**
 * Draws a growth stage's icon.
 *
 * The drawings are Undergrowth's own, in lines of the text colour, so they
 * follow the theme and every colour a block sets. Composting has none,
 * since readers are never shown it.
 *
 * @param string $stage One of UNDERGROWTH_STAGES.
 * @param string $label Accessible name, or an empty string when text beside
 *                      the icon already says the stage.
 * @return string SVG markup, or an empty string for a stage with no icon.
 */
function undergrowth_stage_icon( string $stage, string $label = '' ): string {
	$drawings = array(
		// Two seed leaves on a short stem.
		'seedling'  => '<path d="M5 20h14"/><path d="M12 20v-7"/><path d="M12 13c-3.6 0-5.6-2-5.6-5.2 3.6 0 5.6 2 5.6 5.2z"/><path d="M12 13c0-3.2 2-5.2 5.6-5.2 0 3.2-2 5.2-5.6 5.2z"/>',
		// A taller stem with a leaf on each side, still growing at the tip.
		'sprout'    => '<path d="M5 20h14"/><path d="M12 20V5"/><path d="M12 15.5c-3.2 0-5.2-1.8-5.2-4.6 3.2 0 5.2 1.8 5.2 4.6z"/><path d="M12 11.5c0-2.8 2-4.6 5.2-4.6 0 2.8-2 4.6-5.2 4.6z"/><path d="M12 5c.8-1 1.8-1.5 3-1.5"/>',
		// Leaves low on the stem and a closed bud on top.
		'budding'   => '<path d="M5 20h14"/><path d="M12 20v-9"/><path d="M12 17c-2.8 0-4.6-1.5-4.6-4 2.8 0 4.6 1.5 4.6 4z"/><path d="M12 15.5c0-2.5 1.8-4 4.6-4 0 2.5-1.8 4-4.6 4z"/><path d="M12 11c-2.2-1.4-2.8-4.4 0-7.5 2.8 3.1 2.2 6.1 0 7.5z"/>',
		// A conifer in three tiers on its trunk.
		'evergreen' => '<path d="M12 3 7.5 8.5H10L6 13.5h3L5 18.5h14l-4-5h3l-4-5h2.5z"/><path d="M12 18.5V21"/>',
	);

	if ( ! isset( $drawings[ $stage ] ) ) {
		return '';
	}

	return sprintf(
		'<svg class="undergrowth-stage-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" %1$s>%2$s</svg>',
		'' === $label ? 'aria-hidden="true" focusable="false"' : 'role="img" aria-label="' . esc_attr( $label ) . '"',
		$drawings[ $stage ]
	);
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
	// the whole label instead. The icon beside the word adds nothing for them.
	return sprintf(
		'<p %1$s>%2$s<span class="screen-reader-text">%3$s</span><span aria-hidden="true">%4$s</span></p>',
		get_block_wrapper_attributes( array( 'class' => 'is-stage-' . $stage ) ),
		empty( $attributes['showIcon'] ) ? '' : undergrowth_stage_icon( $stage ),
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
	$post_id = (int) ( $block->context['postId'] ?? 0 );

	// A list never measured is measured in cron, not while a reader waits.
	// Someone who can edit the post, such as the author in the editor's
	// preview, has it measured there and then, as the editor sidebar does.
	if ( ! current_user_can( 'edit_post', $post_id ) && ! undergrowth_similar_ready( $post_id ) ) {
		return '';
	}

	$post_ids = undergrowth_more_like_this( $post_id, (int) ( $attributes['postsToShow'] ?? 3 ) );

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

	$heading = trim( (string) ( $attributes['heading'] ?? '' ) );

	if ( '' === $heading ) {
		$heading = __( 'More like this', 'undergrowth' );
	}

	// The heading names the list, so readers who see it and readers who hear
	// it get the same name. A page may hold more than one list.
	$id = wp_unique_id( 'undergrowth-more-like-this-' );

	return sprintf(
		'<nav %1$s><h2 class="wp-block-heading" id="%2$s">%3$s</h2><ul class="undergrowth-more-like-this-list">%4$s</ul></nav>',
		get_block_wrapper_attributes( array( 'aria-labelledby' => $id ) ),
		esc_attr( $id ),
		esc_html( $heading ),
		$items
	);
}
