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
add_filter( 'block_editor_settings_all', 'undergrowth_garden_feed_editor_settings' );

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
 * Tells the editor which post types are indexed, so the block settings
 * offer only the type taxonomies the feed accepts.
 *
 * @param array<string, mixed> $settings Block editor settings.
 * @return array<string, mixed> The settings with the indexed post types.
 */
function undergrowth_garden_feed_editor_settings( $settings ): array {
	$settings['undergrowthIndexedPostTypes'] = undergrowth_indexed_post_types();

	return $settings;
}

/**
 * Reads the filters a reader chose from the query args.
 *
 * They come from a public URL anyone can write, so each is only ever
 * matched against known stages and term slugs. Composting is never a
 * filter, since readers are never shown it.
 *
 * @return array{stage: string, type: string, topic: string, group: string} Each filter, or an empty string for none.
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
		// A Map group, by its leader's ID, or "other" for the posts in none,
		// as the garden index links to them.
		'group' => $read( 'group' ),
	);
}

/**
 * Says whether any filter narrows the garden.
 *
 * The count in the garden filters and the feed both ask this, so they
 * always agree. Only the empty string means "all": a slug of "0" is a
 * filter too, which array_filter() without a callback would drop.
 *
 * @param array{stage: string, type: string, topic: string, group: string} $filters From undergrowth_garden_feed_filters().
 * @return bool True when at least one filter is set.
 */
function undergrowth_garden_feed_filtered( array $filters ): bool {
	foreach ( $filters as $value ) {
		if ( '' !== $value ) {
			return true;
		}
	}

	return false;
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
 * @param string                                                           $order    One of tended, newest or stage.
 * @param string                                                           $taxonomy Taxonomy that names each post's type.
 * @param array{stage: string, type: string, topic: string, group: string} $filters  From undergrowth_garden_feed_filters().
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

	// Only the page a feed shows is loaded, by the feed itself.
	$post_ids = undergrowth_filter_garden_visible( array_map( 'intval', $post_ids ), false );

	if ( '' !== $filters['group'] ) {
		$in       = undergrowth_garden_group_posts( $filters['group'] );
		$post_ids = array_values(
			array_filter(
				$post_ids,
				static function ( int $post_id ) use ( $in ): bool {
					return isset( $in[ $post_id ] );
				}
			)
		);
	}

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
 * @param bool                 $images     Whether to show its featured image, above the title.
 * @param string               $taxonomy   Taxonomy that names each post's type.
 * @param array<string, mixed> $attributes Block attributes.
 * @return string List item markup.
 */
function undergrowth_garden_feed_item( int $post_id, bool $images, string $taxonomy, array $attributes ): string {
	$post  = get_post( $post_id );
	$stage = undergrowth_get_stage( $post_id );

	// The title names the entry, so the image says nothing more to a
	// screen reader and is left decorative. The stage's glyph sits in the
	// link to take its colour, and is left out of the link's name too;
	// the stage's name follows the link, for screen readers only.
	$picture = $images && has_post_thumbnail( $post )
		? get_the_post_thumbnail(
			$post,
			'large',
			array(
				'alt'   => '',
				'class' => 'wp-block-undergrowth-garden-feed-image',
			)
		)
		: '';
	$item    = sprintf(
		'<li class="wp-block-undergrowth-garden-feed-item%1$s">%2$s<a class="wp-block-undergrowth-garden-feed-title" href="%3$s"><span class="wp-block-undergrowth-garden-feed-stage is-stage-%4$s">%5$s</span><span>%6$s</span></a><span class="screen-reader-text">%7$s</span>',
		'' === $picture ? '' : ' has-image',
		$picture,
		esc_url( (string) get_permalink( $post ) ),
		esc_attr( $stage ),
		undergrowth_stage_icon( $stage ),
		esc_html( undergrowth_plain_line( undergrowth_post_title( $post_id ) ) ),
		esc_html(
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
		if ( ! undergrowth_garden_feed_filtered( $filters ) || array() === undergrowth_garden_feed_posts( 'tended', $taxonomy, array_fill_keys( array_keys( $filters ), '' ) ) ) {
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
	$shown   = array_slice( $post_ids, ( $current - 1 ) * $per_page, $per_page );

	_prime_post_caches( $shown, false, false );

	// A block saved before the setting was renamed still says showFeatured.
	$images = isset( $attributes['showFeatured'] ) ? ! empty( $attributes['showFeatured'] ) : ! empty( $attributes['showImages'] );

	foreach ( $shown as $post_id ) {
		$items .= undergrowth_garden_feed_item( $post_id, $images, $taxonomy, $attributes );
	}

	$group = '' === $filters['group'] ? '' : undergrowth_garden_feed_group_note( $filters['group'] );

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
		'<div %1$s>%2$s<ul class="wp-block-undergrowth-garden-feed-list">%3$s</ul>%4$s</div>',
		undergrowth_garden_feed_wrapper_attributes(),
		$group,
		$items,
		$nav
	);
}

/**
 * Says which group the feed shows, as the garden index names it, with a
 * link back to the whole garden.
 *
 * @param string $group A leader's ID, or "other".
 * @return string Markup.
 */
function undergrowth_garden_feed_group_note( string $group ): string {
	$name = 'other' === $group
		? esc_html__( 'Posts not in a group', 'undergrowth' )
		: sprintf(
			/* translators: %s: title of the post the group is named after. */
			esc_html__( 'In the group around “%s”', 'undergrowth' ),
			'<bdi>' . esc_html( undergrowth_plain_line( undergrowth_post_title( undergrowth_garden_group_name_post( $group ) ) ) ) . '</bdi>'
		);

	return sprintf(
		// A whole page load, not in place: in place, the link would remove
		// itself from under the reader's focus.
		'<p class="wp-block-undergrowth-garden-feed-group">%1$s <a href="%2$s">%3$s</a></p>',
		$name,
		esc_url( remove_query_arg( array( 'group', 'garden-page' ) ) ),
		esc_html__( 'Show the whole garden', 'undergrowth' )
	);
}
