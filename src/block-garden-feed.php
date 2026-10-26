<?php
/**
 * The garden feed block: every post readers may see, newest tended first,
 * each with its growth stage, so the garden reads as a place that changes.
 *
 * Readers narrow it with the query args `stage`, `type` and `topic`, which
 * plain links and a form without JavaScript can set. Every word shown is the
 * author's own or fixed, translatable text. Nothing a model wrote is shown.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'undergrowth_register_garden_feed_block' );

/**
 * Registers the block from its block.json file.
 *
 * The editor script exists only once built. An unbuilt checkout registers
 * from the source folder without it, so the front end still renders.
 */
function undergrowth_register_garden_feed_block(): void {
	$folder = dirname( UNDERGROWTH_FILE ) . '/build/blocks/garden-feed';
	$args   = array(
		'render_callback' => 'undergrowth_render_garden_feed_block',
		'style_handles'   => array( 'undergrowth-garden-feed' ),
	);

	wp_register_style(
		'undergrowth-garden-feed',
		plugins_url( 'assets/garden-feed.css', UNDERGROWTH_FILE ),
		array(),
		UNDERGROWTH_VERSION
	);

	if ( ! file_exists( $folder . '/block.json' ) ) {
		$folder                        = dirname( UNDERGROWTH_FILE ) . '/src/blocks/garden-feed';
		$args['editor_script_handles'] = array();
	}

	register_block_type( $folder, $args );
}

/**
 * Reads the filters a reader chose from the query args.
 *
 * They come from a public URL anyone can write, so each is only ever
 * matched against known stages and term slugs. Composting is never a
 * filter, since readers are never shown it.
 *
 * @return array{stage: string, type: string, topic: string} Each filter, or an empty string for none.
 */
function undergrowth_garden_feed_filters(): array {
	$read = static function ( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters in a shareable URL.
		$value = isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : '';

		// Encoded the way term slugs are stored, so a slug outside Latin
		// letters matches.
		return sanitize_title( $value );
	};

	$stage = $read( 'stage' );

	return array(
		'stage' => in_array( $stage, UNDERGROWTH_STAGES, true ) && 'composting' !== $stage ? $stage : '',
		'type'  => $read( 'type' ),
		'topic' => $read( 'topic' ),
	);
}

/**
 * Picks the taxonomy whose terms name each post's type.
 *
 * @param string $source Taxonomy chosen in the block settings.
 * @return string The taxonomy, or post_format when the chosen one cannot
 *                hold posts readers may browse.
 */
function undergrowth_garden_feed_type_taxonomy( string $source ): string {
	$taxonomy = get_taxonomy( $source );

	if ( ! $taxonomy || ! is_taxonomy_viewable( $taxonomy ) || array() === array_intersect( (array) $taxonomy->object_type, undergrowth_indexed_post_types() ) ) {
		return 'post_format';
	}

	return $taxonomy->name;
}

/**
 * Finds every post readers may see that matches the filters, in the chosen
 * order.
 *
 * Every matching ID is loaded, as the garden index does, so the one
 * visibility rule decides what is listed and the count and pages agree
 * with it.
 *
 * @param string                                            $order    One of tended, newest or stage.
 * @param string                                            $taxonomy Taxonomy that names each post's type.
 * @param array{stage: string, type: string, topic: string} $filters  From undergrowth_garden_feed_filters().
 * @return int[] Post IDs.
 */
function undergrowth_garden_feed_posts( string $order, string $taxonomy, array $filters ): array {
	$post_types = array_values(
		array_filter(
			undergrowth_indexed_post_types(),
			static function ( string $post_type ): bool {
				return (bool) ( get_post_type_object( $post_type )->public ?? false );
			}
		)
	);

	if ( array() === $post_types ) {
		return array();
	}

	$terms = array( 'relation' => 'AND' );

	if ( '' !== $filters['topic'] ) {
		$terms[] = array(
			'relation' => 'OR',
			array(
				'taxonomy' => 'post_tag',
				'field'    => 'slug',
				'terms'    => $filters['topic'],
			),
			array(
				'taxonomy' => 'category',
				'field'    => 'slug',
				'terms'    => $filters['topic'],
			),
		);
	}

	if ( 'post_format' === $taxonomy && 'standard' === $filters['type'] ) {
		$terms[] = array(
			'taxonomy' => 'post_format',
			'operator' => 'NOT EXISTS',
		);
	} elseif ( '' !== $filters['type'] ) {
		$terms[] = array(
			'taxonomy' => $taxonomy,
			'field'    => 'slug',
			'terms'    => 'post_format' === $taxonomy ? 'post-format-' . $filters['type'] : $filters['type'],
		);
	}

	$post_ids = get_posts(
		array(
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => array(
				'newest' === $order ? 'date' : 'modified' => 'DESC',
				'ID'                                      => 'DESC',
			),
			'tax_query'              => $terms, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Only the filters a reader chose.
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		)
	);

	$post_ids = undergrowth_filter_garden_visible( array_map( 'intval', $post_ids ) );

	if ( '' !== $filters['stage'] ) {
		$post_ids = array_values(
			array_filter(
				$post_ids,
				static function ( int $post_id ) use ( $filters ): bool {
					return undergrowth_get_stage( $post_id ) === $filters['stage'];
				}
			)
		);
	}

	if ( 'stage' === $order ) {
		// Most grown first. The list is already newest tended first, and
		// each post's place in it breaks ties, since usort() is not stable
		// before PHP 8.
		$rank     = array_flip( array( 'evergreen', 'budding', 'sprout', 'seedling' ) );
		$place    = array_flip( $post_ids );
		$by_stage = static function ( int $a, int $b ) use ( $rank, $place ): int {
			return array( $rank[ undergrowth_get_stage( $a ) ] ?? 4, $place[ $a ] ) <=> array( $rank[ undergrowth_get_stage( $b ) ] ?? 4, $place[ $b ] );
		};
		usort( $post_ids, $by_stage );
	}

	return $post_ids;
}

/**
 * Names a post's type from the chosen taxonomy.
 *
 * A post with no format is Standard, the type of most posts on most sites,
 * so it carries no label rather than the same word on every line.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy that names each post's type.
 * @return string Type name, or an empty string for none.
 */
function undergrowth_garden_feed_type_label( int $post_id, string $taxonomy ): string {
	if ( 'post_format' === $taxonomy ) {
		$format = get_post_format( $post_id );

		return $format ? get_post_format_string( $format ) : '';
	}

	$terms = get_the_terms( $post_id, $taxonomy );

	if ( ! is_array( $terms ) ) {
		return '';
	}

	return implode(
		wp_get_list_item_separator(),
		array_map(
			static function ( WP_Term $term ): string {
				return undergrowth_plain_line( $term->name );
			},
			$terms
		)
	);
}

/**
 * Renders one post in the feed.
 *
 * @param int                  $post_id    Post ID.
 * @param bool                 $featured   Whether it leads the feed, with its featured image.
 * @param string               $taxonomy   Taxonomy that names each post's type.
 * @param array<string, mixed> $attributes Block attributes.
 * @return string List item markup.
 */
function undergrowth_garden_feed_item( int $post_id, bool $featured, string $taxonomy, array $attributes ): string {
	$post  = get_post( $post_id );
	$stage = undergrowth_get_stage( $post_id );

	// The title beside it names the link, so the image adds nothing for a
	// screen reader and is left out of the link's name.
	$picture = $featured && has_post_thumbnail( $post ) ? get_the_post_thumbnail( $post, 'large', array( 'alt' => '' ) ) : '';
	$item    = sprintf(
		'<li class="wp-block-undergrowth-garden-feed-item%1$s"><a class="wp-block-undergrowth-garden-feed-title" href="%2$s">%3$s<span>%4$s</span></a>',
		$featured ? ' is-featured' : '',
		esc_url( (string) get_permalink( $post ) ),
		$picture,
		esc_html( undergrowth_plain_line( undergrowth_post_title( $post_id ) ) )
	);

	$item .= sprintf(
		'<span class="wp-block-undergrowth-garden-feed-stage is-stage-%1$s">%2$s</span>',
		esc_attr( $stage ),
		undergrowth_stage_icon(
			$stage,
			sprintf(
				/* translators: %s: growth stage name, such as Sprout. */
				__( 'Growth stage: %s', 'undergrowth' ),
				undergrowth_stage_label( $stage )
			)
		)
	);

	// Only an excerpt the author wrote, never one cut from the post.
	if ( ! empty( $attributes['showExcerpt'] ) && has_excerpt( $post ) ) {
		$item .= '<p class="wp-block-undergrowth-garden-feed-excerpt">' . esc_html( undergrowth_plain_line( $post->post_excerpt ) ) . '</p>';
	}

	$type    = empty( $attributes['showType'] ) ? '' : undergrowth_garden_feed_type_label( $post_id, $taxonomy );
	$tended  = (int) get_post_modified_time( 'U', true, $post );
	$details = sprintf(
		'<time datetime="%1$s">%2$s</time>',
		esc_attr( (string) get_post_modified_time( 'c', true, $post ) ),
		esc_html(
			sprintf(
				/* translators: %s: how long ago, such as 3 weeks. */
				__( 'Tended %s ago', 'undergrowth' ),
				human_time_diff( $tended )
			)
		)
	);

	if ( '' !== $type ) {
		$details = '<span class="wp-block-undergrowth-garden-feed-type">' . esc_html( $type ) . '</span> ' . $details;
	}

	return $item . '<p class="wp-block-undergrowth-garden-feed-details">' . $details . '</p></li>';
}

/**
 * Builds the feed's wrapper attributes.
 *
 * The feed is a region the Interactivity API router replaces, so the
 * garden filters can update it in place.
 *
 * @return string Attributes markup.
 */
function undergrowth_garden_feed_wrapper_attributes(): string {
	return get_block_wrapper_attributes(
		array(
			'data-wp-interactive'   => 'undergrowth/garden',
			'data-wp-router-region' => 'undergrowth/garden-feed',
		)
	);
}

/**
 * Renders the garden feed.
 *
 * @param array<string, mixed> $attributes Block attributes.
 * @return string Feed markup, or an empty string when the garden is empty.
 */
function undergrowth_render_garden_feed_block( $attributes ): string {
	$taxonomy = undergrowth_garden_feed_type_taxonomy( (string) $attributes['typeSource'] );
	$filters  = undergrowth_garden_feed_filters();
	$post_ids = undergrowth_garden_feed_posts( (string) $attributes['orderBy'], $taxonomy, $filters );

	if ( array() === $post_ids ) {
		// An empty garden shows readers nothing. A filter that matches
		// nothing says so, or the reader would think the page broken.
		if ( array() === array_filter( $filters ) || array() === undergrowth_garden_feed_posts( 'tended', $taxonomy, array_fill_keys( array_keys( $filters ), '' ) ) ) {
			return '';
		}

		return sprintf(
			'<div %1$s><p class="wp-block-undergrowth-garden-feed-none">%2$s</p></div>',
			undergrowth_garden_feed_wrapper_attributes(),
			esc_html__( 'Nothing in the garden matches.', 'undergrowth' )
		);
	}

	$per_page = max( 1, (int) $attributes['postsPerPage'] );
	$pages    = (int) ceil( count( $post_ids ) / $per_page );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A page number in a shareable URL.
	$current = isset( $_GET['garden-page'] ) && is_string( $_GET['garden-page'] ) ? absint( $_GET['garden-page'] ) : 1;
	$current = min( max( 1, $current ), $pages );
	$items   = '';

	foreach ( array_slice( $post_ids, ( $current - 1 ) * $per_page, $per_page ) as $index => $post_id ) {
		$items .= undergrowth_garden_feed_item( $post_id, ! empty( $attributes['showFeatured'] ) && 1 === $current && 0 === $index, $taxonomy, $attributes );
	}

	$nav = '';

	if ( $pages > 1 ) {
		// The filters stay in each page's link, since it is built from the
		// address the reader is on.
		$links = new WP_HTML_Tag_Processor(
			(string) paginate_links(
				array(
					'base'    => add_query_arg( 'garden-page', '%#%' ),
					'format'  => '',
					'current' => $current,
					'total'   => $pages,
				)
			)
		);

		// With the garden filters on the page, each page loads in place.
		while ( $links->next_tag( array( 'tag_name' => 'a' ) ) ) {
			$links->set_attribute( 'data-wp-on--click', 'actions.navigate' );
		}

		$nav = sprintf(
			'<nav class="wp-block-undergrowth-garden-feed-pages" aria-label="%1$s">%2$s</nav>',
			esc_attr__( 'Garden pages', 'undergrowth' ),
			$links->get_updated_html()
		);
	}

	return sprintf(
		'<div %1$s><ul class="wp-block-undergrowth-garden-feed-list">%2$s</ul>%3$s</div>',
		undergrowth_garden_feed_wrapper_attributes(),
		$items,
		$nav
	);
}
