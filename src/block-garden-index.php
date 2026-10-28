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
add_action( 'save_post', 'undergrowth_note_garden_feed_page', 10, 2 );
add_action( 'deleted_post', 'undergrowth_forget_garden_feed_page' );

// Posts each group of the index shows, nearest its centre first. The rest
// are a link away, in the Garden feed filtered to the group.
const UNDERGROWTH_GARDEN_INDEX_SHOWN = 20;

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
 * Lists the Map's groups stored for the index, members nearest the centre
 * first.
 *
 * @return array<int, int[]> Member post IDs keyed by leader ID, in Map order.
 */
function undergrowth_garden_index_lists(): array {
	$cached = get_option( 'undergrowth_garden_index', array() );
	$lists  = array();

	foreach ( is_array( $cached ) ? (array) ( $cached['groups'] ?? array() ) : array() as $group ) {
		$lists[ (int) ( $group['leader'] ?? 0 ) ] = array_map( 'intval', (array) ( $group['members'] ?? array() ) );
	}

	return $lists;
}

/**
 * Lists the posts of one group, as the garden index and its "See all" link
 * name it.
 *
 * @param string $group A leader's ID, or "other" for the posts in no group.
 * @return array<int, true> Post IDs, keyed, in no order; none for an unknown group.
 */
function undergrowth_garden_group_posts( string $group ): array {
	$lists = undergrowth_garden_index_lists();

	if ( 'other' === $group ) {
		return array_fill_keys( array_keys( array_diff_key( undergrowth_garden_posts(), array_fill_keys( array_merge( array(), ...array_values( $lists ) ), true ) ) ), true );
	}

	return array_fill_keys( $lists[ (int) $group ] ?? array(), true );
}

/**
 * Finds the post a group is named after: its first post readers may see,
 * as the Map names a group after its first post.
 *
 * @param string $group A leader's ID.
 * @return int Post ID, or 0 when readers may see none.
 */
function undergrowth_garden_group_name_post( string $group ): int {
	$visible = undergrowth_filter_garden_visible( undergrowth_garden_index_lists()[ (int) $group ] ?? array(), false );

	return $visible[0] ?? 0;
}

/**
 * Returns the posts readers may see, by group, in Map order, then those in
 * no group, newest first. Each group shows at most
 * UNDERGROWTH_GARDEN_INDEX_SHOWN posts, nearest its centre first, and says
 * how many it holds.
 *
 * A post published since the last cluster pass is in no group yet. Only the
 * columns a title and a link need are read, for the posts shown, so a
 * garden of thousands never sits in memory with its text.
 *
 * @param string $stage Only posts at this stage, or an empty string for all.
 * @return array<int, array{name: string, group: string, posts: WP_Post[], total: int}> Groups with
 *         posts to show: the name, empty for the posts in no group, which come last; the group as the
 *         Garden feed's group filter names it; the posts shown; and how many the group holds.
 */
function undergrowth_garden_index_groups( string $stage ): array {
	global $wpdb;

	$lists   = undergrowth_garden_index_lists();
	$grouped = array_fill_keys( array_merge( array(), ...array_values( $lists ) ), true );
	$apart   = array_map( 'intval', array_keys( array_diff_key( undergrowth_garden_posts(), $grouped ) ) );

	// Every post's status and meta in two queries, however many the index holds.
	$visible = array_fill_keys( undergrowth_filter_garden_visible( array_merge( $apart, array_keys( $grouped ) ), false ), true );
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

	foreach ( $lists as $leader => $members ) {
		$members = $keep( $members );
		$shown   = $staged( $members );

		if ( array() === $shown ) {
			continue;
		}

		// Named after its first post readers may see, as the Map names a
		// group after its first post, so a hidden title is never shown. A
		// stage filter keeps the group's name.
		$groups[] = array(
			'named' => $members[0],
			'group' => (string) $leader,
			'posts' => $shown,
		);
	}

	$apart = $staged( $keep( $apart ) );

	if ( array() !== $apart ) {
		// Newest first by the date readers see, not by ID, so an imported or
		// redated post sits where its date puts it.
		$placeholders = implode( ', ', array_fill( 0, count( $apart ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only the dates, past the post cache; there is one placeholder for each ID.
		$dates = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_date_gmt FROM %i WHERE ID IN (' . $placeholders . ')', $wpdb->posts, ...$apart ), OBJECT_K );

		usort(
			$apart,
			static function ( int $a, int $b ) use ( $dates ): int {
				$order = strcmp( (string) ( $dates[ $b ]->post_date_gmt ?? '' ), (string) ( $dates[ $a ]->post_date_gmt ?? '' ) );

				return 0 === $order ? $b <=> $a : $order;
			}
		);

		$groups[] = array(
			'named' => 0,
			'group' => 'other',
			'posts' => $apart,
		);
	}

	$shown = array();

	foreach ( $groups as $group ) {
		$shown = array_merge( $shown, array( $group['named'] ), array_slice( $group['posts'], 0, UNDERGROWTH_GARDEN_INDEX_SHOWN ) );
	}

	$posts = undergrowth_garden_index_posts( $shown );

	return array_map(
		static function ( array $group ) use ( $posts ): array {
			return array(
				'name'  => 0 === $group['named'] ? '' : undergrowth_plain_line( undergrowth_post_title( $posts[ $group['named'] ] ?? $group['named'] ) ),
				'group' => $group['group'],
				'posts' => array_values( array_filter( array_map( static fn( int $post_id ) => $posts[ $post_id ] ?? null, array_slice( $group['posts'], 0, UNDERGROWTH_GARDEN_INDEX_SHOWN ) ) ) ),
				'total' => count( $group['posts'] ),
			);
		},
		$groups
	);
}

/**
 * Reads the posts the index shows, with only the columns a title and a link
 * need, past the post cache. Posts already loaded are used as they are.
 *
 * @param int[] $post_ids Post IDs.
 * @return array<int, WP_Post> Posts keyed by ID.
 */
function undergrowth_garden_index_posts( array $post_ids ): array {
	global $wpdb;

	$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
	$posts    = array();
	$missing  = array();

	foreach ( $post_ids as $post_id ) {
		$cached = wp_cache_get( $post_id, 'posts' );

		if ( false === $cached ) {
			$missing[] = $post_id;
		} else {
			$posts[ $post_id ] = new WP_Post( $cached );
		}
	}

	if ( array() !== $missing ) {
		$placeholders = implode( ', ', array_fill( 0, count( $missing ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Caching every post shown is what this avoids; there is one placeholder for each ID.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_author, post_date, post_date_gmt, post_title, post_status, post_password, post_name, post_modified, post_modified_gmt, post_parent, post_type FROM %i WHERE ID IN (' . $placeholders . ')', $wpdb->posts, ...$missing ) );
		$wpdb->flush();

		foreach ( $rows as $row ) {
			// Marked raw, as the cache's own are, or get_post() would load
			// the whole post to sanitize it.
			$row->filter             = 'raw';
			$posts[ (int) $row->ID ] = new WP_Post( $row );
		}
	}

	// An address with the category in it reads each post's categories.
	if ( false !== strpos( (string) get_option( 'permalink_structure' ), '%category%' ) ) {
		update_object_term_cache( array_keys( $posts ), undergrowth_indexed_post_types() );
	}

	// In the order asked for.
	return array_replace( array_intersect_key( array_flip( $post_ids ), $posts ), $posts );
}

/**
 * Finds a published page or post that holds the Garden feed, so the index
 * can link each group to it.
 *
 * Remembered in an option, which a save keeps current, so the posts table
 * is searched only when none is known.
 *
 * @return string The page's address, or an empty string when there is none.
 */
function undergrowth_garden_feed_url(): string {
	global $wpdb;

	$page = get_option( 'undergrowth_garden_feed_page' );

	if ( false === $page ) {
		$types = array_values( get_post_types( array( 'public' => true ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Once, until a save changes it; there is one placeholder for each type.
		$page = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM %i WHERE post_status = 'publish' AND post_password = '' AND post_type IN (" . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ') AND post_content LIKE %s ORDER BY ID ASC LIMIT 1', $wpdb->posts, ...array_merge( $types, array( '%' . $wpdb->esc_like( '<!-- wp:undergrowth/garden-feed' ) . '%' ) ) ) );

		update_option( 'undergrowth_garden_feed_page', $page, false );
	}

	$page = (int) $page;

	return $page > 0 && is_post_publicly_viewable( $page ) ? (string) get_permalink( $page ) : '';
}

/**
 * Keeps the remembered Garden feed page current as posts are saved.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post that was saved.
 */
function undergrowth_note_garden_feed_page( $post_id, $post ): void {
	$page = get_option( 'undergrowth_garden_feed_page' );

	if ( 'publish' === $post->post_status && '' === $post->post_password && is_post_type_viewable( $post->post_type ) && has_block( 'undergrowth/garden-feed', $post ) ) {
		if ( false !== $page && (int) $page < 1 ) {
			update_option( 'undergrowth_garden_feed_page', (int) $post_id, false );
		}

		return;
	}

	// The page may have lost the feed or left the site: search again.
	if ( (int) $page === (int) $post_id ) {
		delete_option( 'undergrowth_garden_feed_page' );
	}
}

/**
 * Forgets the remembered Garden feed page when it is deleted.
 *
 * @param int $post_id Post ID.
 */
function undergrowth_forget_garden_feed_page( $post_id ): void {
	if ( (int) get_option( 'undergrowth_garden_feed_page' ) === (int) $post_id ) {
		delete_option( 'undergrowth_garden_feed_page' );
	}
}

/**
 * Renders the garden index.
 *
 * @param array<string, mixed> $attributes Block attributes.
 * @return string Index markup, or an empty string when there is nothing to show.
 */
function undergrowth_render_garden_index_block( $attributes ): string {
	$sections = '';
	$feed     = null;

	foreach ( undergrowth_garden_index_groups( (string) $attributes['stage'] ) as $group ) {
		$items = '';

		foreach ( $group['posts'] as $post ) {
			$stage = undergrowth_get_stage( $post->ID );
			$name  = undergrowth_stage_label( $stage );

			$items .= sprintf(
				'<li><a href="%1$s">%2$s</a> <span class="wp-block-undergrowth-garden-index-stage is-stage-%3$s"><span class="screen-reader-text">%4$s</span><span aria-hidden="true">%5$s</span></span></li>',
				esc_url( (string) get_permalink( $post ) ),
				esc_html( undergrowth_plain_line( undergrowth_post_title( $post ) ) ),
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

		$more = '';
		$rest = $group['total'] - count( $group['posts'] );

		if ( $rest > 0 ) {
			$feed = $feed ?? undergrowth_garden_feed_url();
			$more = '' === $feed
				? esc_html(
					sprintf(
						/* translators: %s: number of posts not shown. */
						_n( 'And %s more post in this group.', 'And %s more posts in this group.', $rest, 'undergrowth' ),
						number_format_i18n( $rest )
					)
				)
				: sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( add_query_arg( 'group', $group['group'], $feed ) ),
					esc_html(
						sprintf(
							/* translators: %s: number of posts in the group. */
							_n( 'See all %s post in this group', 'See all %s posts in this group', $group['total'], 'undergrowth' ),
							number_format_i18n( $group['total'] )
						)
					)
				);
			$more = '<p class="wp-block-undergrowth-garden-index-more">' . $more . '</p>';
		}

		$sections .= sprintf(
			'<h2 class="wp-block-heading">%1$s</h2><ul>%2$s</ul>%3$s',
			esc_html( '' === $group['name'] ? __( 'Other posts', 'undergrowth' ) : $group['name'] ),
			$items,
			$more
		);
	}

	if ( '' === $sections ) {
		return '';
	}

	return sprintf( '<div %1$s>%2$s</div>', get_block_wrapper_attributes(), $sections );
}
