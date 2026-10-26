<?php
/**
 * The garden filters block: topic links and stage and type selects that
 * narrow the garden feed.
 *
 * It works as plain links and a GET form, so a filtered garden has a URL a
 * reader can share. With JavaScript, the Interactivity API router fetches
 * the filtered page and swaps the feed and the filters in place.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'undergrowth_register_garden_filters_block' );

/**
 * Registers the block from its block.json file.
 *
 * The editor script and the view module exist only once built. An unbuilt
 * checkout registers from the source folder without them, so the front end
 * still renders and the form still works.
 */
function undergrowth_register_garden_filters_block(): void {
	$folder = dirname( UNDERGROWTH_FILE ) . '/build/blocks/garden-filters';
	$args   = array(
		'render_callback' => 'undergrowth_render_garden_filters_block',
		'style_handles'   => array( 'undergrowth-garden-filters' ),
	);

	wp_register_style(
		'undergrowth-garden-filters',
		plugins_url( 'assets/garden-filters.css', UNDERGROWTH_FILE ),
		array(),
		UNDERGROWTH_VERSION
	);

	if ( ! file_exists( $folder . '/block.json' ) ) {
		$folder                         = dirname( UNDERGROWTH_FILE ) . '/src/blocks/garden-filters';
		$args['editor_script_handles']  = array();
		$args['view_script_module_ids'] = array();
	}

	register_block_type( $folder, $args );
}

/**
 * Finds the topics, stages and types the visible garden holds.
 *
 * Only these are offered, so no choice leads to an empty garden by itself
 * and nothing hidden from readers is named.
 *
 * @param int[]  $post_ids       Every post readers may see.
 * @param string $topic_taxonomy Taxonomy whose terms are the topics.
 * @param string $type_taxonomy  Taxonomy that names each post's type.
 * @return array{topics: array<string, string>, stages: array<string, string>, types: array<string, string>} Names keyed by slug, sorted by name, stages in growing order.
 */
function undergrowth_garden_filters_choices( array $post_ids, string $topic_taxonomy, string $type_taxonomy ): array {
	$names = static function ( string $taxonomy ) use ( $post_ids ): array {
		$terms = wp_get_object_terms( $post_ids, $taxonomy, array( 'orderby' => 'name' ) );
		$names = array();

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$names[ $term->slug ] = undergrowth_plain_line( $term->name );
		}

		return $names;
	};

	$stages = array();

	foreach ( $post_ids as $post_id ) {
		$stages[ undergrowth_get_stage( $post_id ) ] = true;
	}

	$types = array();

	if ( 'post_format' === $type_taxonomy ) {
		foreach ( $names( 'post_format' ) as $slug => $name ) {
			$format           = str_replace( 'post-format-', '', $slug );
			$types[ $format ] = get_post_format_string( $format );
		}

		// Posts with no format are Standard, worth choosing only beside
		// another type.
		if ( array() !== $types && count( $post_ids ) > count( wp_get_object_terms( $post_ids, 'post_format', array( 'fields' => 'all_with_object_id' ) ) ) ) {
			$types['standard'] = get_post_format_string( 'standard' );
		}

		asort( $types );
	} else {
		$types = $names( $type_taxonomy );
	}

	$shown = array();

	foreach ( array( 'seedling', 'sprout', 'budding', 'evergreen' ) as $stage ) {
		if ( isset( $stages[ $stage ] ) ) {
			$shown[ $stage ] = undergrowth_stage_label( $stage );
		}
	}

	return array(
		'topics' => $names( $topic_taxonomy ),
		'stages' => $shown,
		'types'  => $types,
	);
}

/**
 * Renders a select for one filter, with its label.
 *
 * @param string                $name    Query arg the select sets.
 * @param string                $label   Visible label.
 * @param string                $all     Label of the choice that sets no filter.
 * @param array<string, string> $choices Names keyed by value.
 * @param string                $current The value chosen now.
 * @return string Markup.
 */
function undergrowth_garden_filters_select( string $name, string $label, string $all, array $choices, string $current ): string {
	$id      = wp_unique_id( 'undergrowth-garden-filters-' . $name . '-' );
	$options = sprintf( '<option value="">%s</option>', esc_html( $all ) );

	foreach ( $choices as $value => $choice ) {
		$options .= sprintf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( (string) $value ),
			(string) $value === $current ? ' selected' : '',
			esc_html( $choice )
		);
	}

	return sprintf(
		'<p class="wp-block-undergrowth-garden-filters-field"><label for="%1$s">%2$s</label><select id="%1$s" name="%3$s">%4$s</select></p>',
		esc_attr( $id ),
		esc_html( $label ),
		esc_attr( $name ),
		$options
	);
}

/**
 * Renders the garden filters.
 *
 * @param array<string, mixed> $attributes Block attributes.
 * @return string Filters markup, or an empty string when the garden is empty.
 */
function undergrowth_render_garden_filters_block( $attributes ): string {
	$type_taxonomy = undergrowth_garden_feed_type_taxonomy( (string) $attributes['typeSource'] );
	$filters       = undergrowth_garden_feed_filters();
	$garden        = undergrowth_garden_feed_posts( 'tended', $type_taxonomy, array_fill_keys( array_keys( $filters ), '' ) );

	if ( array() === $garden ) {
		return '';
	}

	$choices = undergrowth_garden_filters_choices( $garden, (string) $attributes['topicSource'], $type_taxonomy );
	$matches = array() === array_filter( $filters ) ? $garden : undergrowth_garden_feed_posts( 'tended', $type_taxonomy, $filters );
	$html    = '';

	// Each link keeps the other filters and starts again at the first page.
	$base = remove_query_arg( array( 'topic', 'garden-page' ) );

	if ( ! empty( $attributes['showTopics'] ) && array() !== $choices['topics'] ) {
		$links = array( '' => __( 'All topics', 'undergrowth' ) ) + $choices['topics'];
		$items = '';

		foreach ( $links as $slug => $name ) {
			$items .= sprintf(
				'<li><a href="%1$s"%2$s data-wp-on--click="actions.navigate">%3$s</a></li>',
				esc_url( '' === $slug ? $base : add_query_arg( 'topic', (string) $slug, $base ) ),
				(string) $slug === $filters['topic'] ? ' aria-current="true"' : '',
				esc_html( $name )
			);
		}

		$html .= sprintf(
			'<nav class="wp-block-undergrowth-garden-filters-topics" aria-label="%1$s"><ul>%2$s</ul></nav>',
			esc_attr__( 'Topics', 'undergrowth' ),
			$items
		);
	}

	$fields = '';

	if ( ! empty( $attributes['showStage'] ) && array() !== $choices['stages'] ) {
		$fields .= undergrowth_garden_filters_select( 'stage', __( 'Growth stage', 'undergrowth' ), __( 'All stages', 'undergrowth' ), $choices['stages'], $filters['stage'] );
	}

	if ( ! empty( $attributes['showType'] ) && array() !== $choices['types'] ) {
		$fields .= undergrowth_garden_filters_select( 'type', __( 'Type', 'undergrowth' ), __( 'All types', 'undergrowth' ), $choices['types'], $filters['type'] );
	}

	if ( '' !== $fields ) {
		// A GET form replaces the query string, so the page's own query args,
		// such as page_id, and the chosen topic go with it as hidden fields.
		$hidden = '';
		$keep   = array_diff_key( $_GET, array_flip( array( 'stage', 'type', 'topic', 'garden-page' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Passed back to the same page, escaped.

		if ( '' !== $filters['topic'] ) {
			$keep['topic'] = $filters['topic'];
		}

		foreach ( $keep as $name => $value ) {
			if ( is_string( $value ) ) {
				$hidden .= sprintf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( (string) $name ), esc_attr( wp_unslash( $value ) ) );
			}
		}

		$html .= sprintf(
			'<form class="wp-block-undergrowth-garden-filters-form" method="get" action="%1$s" data-wp-on--submit="actions.filter" data-wp-on--change="actions.filter">%2$s%3$s<p class="wp-block-undergrowth-garden-filters-submit"><button type="submit" class="wp-element-button">%4$s</button></p></form>',
			esc_url( (string) strtok( $base, '?' ) ),
			$hidden,
			$fields,
			esc_html__( 'Show', 'undergrowth' )
		);
	}

	// Read out when the feed changes in place, so a screen reader user
	// knows the filter took effect.
	$html .= sprintf(
		'<p class="wp-block-undergrowth-garden-filters-count" role="status">%s</p>',
		esc_html(
			sprintf(
				/* translators: %s: number of posts. */
				_n( '%s post', '%s posts', count( $matches ), 'undergrowth' ),
				number_format_i18n( count( $matches ) )
			)
		)
	);

	return sprintf(
		'<div %1$s>%2$s</div>',
		get_block_wrapper_attributes(
			array(
				'data-wp-interactive'   => 'undergrowth/garden',
				'data-wp-router-region' => 'undergrowth/garden-filters',
			)
		),
		$html
	);
}
