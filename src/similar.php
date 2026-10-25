<?php
/**
 * "More like this": the posts closest in meaning to a post, for readers.
 *
 * The list starts from the stored vectors, then lifts Evergreen and Budding
 * posts, sinks Seedlings and gives recently updated posts a lift that fades.
 * One slot may go to a close post that few others link to, so the block does
 * not only send readers where they already go. Placing the block is the
 * author's approval of the list, and the author can hide any post from one
 * post's list for good.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_SIMILAR_CACHED     = 100;
const UNDERGROWTH_SIMILAR_MAX_SHOWN  = 6;
const UNDERGROWTH_SIMILAR_STAGES     = array(
	'evergreen' => 1.15,
	'budding'   => 1.1,
	'sprout'    => 1.0,
	'seedling'  => 0.9,
);
const UNDERGROWTH_SIMILAR_FRESH_LIFT = 0.1;
const UNDERGROWTH_SIMILAR_FRESH_HALF = 180;
const UNDERGROWTH_SIMILAR_POPULAR    = 0.15;

add_action( 'undergrowth_post_embedded', 'undergrowth_forget_similar' );
add_action( 'undergrowth_post_left_index', 'undergrowth_forget_similar' );
add_action( 'undergrowth_stage_changed', 'undergrowth_forget_similar' );
add_action( 'undergrowth_measure_similar', 'undergrowth_remeasure_similar' );
add_action( 'rest_api_init', 'undergrowth_register_similar_routes' );

/**
 * Marks every cached list as stale.
 *
 * A new or changed vector, or a new stage, can change any post's list, so
 * one counter covers them all, and each list is measured again in cron once it is next shown.
 */
function undergrowth_forget_similar(): void {
	update_option( 'undergrowth_similar_generation', (int) get_option( 'undergrowth_similar_generation', 0 ) + 1, false );
}

/**
 * Returns the posts closest in meaning to a post, closest first.
 *
 * Only vectors from the same provider, model and language are compared, and
 * only posts at or above the link threshold count. Who may see each post is
 * decided when the list is shown, not here.
 *
 * Measuring reads every vector, so a page view never does it twice. A list
 * kept from before a vector changed is still shown, and measured again in
 * cron. Only a post that was never measured is measured on the spot.
 *
 * @param int $post_id Post ID.
 * @return array<int, float> Similarity keyed by post ID, highest first.
 */
function undergrowth_similar_scores( int $post_id ): array {
	$cached = get_post_meta( $post_id, 'undergrowth_similar', true );

	if ( is_array( $cached ) && is_array( $cached['scores'] ?? null ) ) {
		if ( ( $cached['generation'] ?? null ) !== (int) get_option( 'undergrowth_similar_generation', 0 ) && false === wp_next_scheduled( 'undergrowth_measure_similar', array( $post_id ) ) ) {
			wp_schedule_single_event( time(), 'undergrowth_measure_similar', array( $post_id ) );
		}

		return $cached['scores'];
	}

	return undergrowth_measure_similar( $post_id );
}

/**
 * Measures how close every comparable post is to a post, and keeps the
 * closest.
 *
 * @param int $post_id Post ID.
 * @return array<int, float> Similarity keyed by post ID, highest first.
 */
function undergrowth_measure_similar( int $post_id ): array {
	$generation = (int) get_option( 'undergrowth_similar_generation', 0 );
	$pool       = undergrowth_comparable_vectors( $post_id );
	$scores     = array();

	if ( isset( $pool[ $post_id ] ) ) {
		$source  = undergrowth_unpack_vector( $pool[ $post_id ] );
		$minimum = undergrowth_min_link_similarity();

		unset( $pool[ $post_id ] );

		foreach ( $pool as $target_id => $packed ) {
			$vector = undergrowth_unpack_vector( $packed );

			if ( ! undergrowth_vectors_comparable( $source, $vector ) ) {
				continue;
			}

			$score = undergrowth_cosine( $source, $vector );

			if ( $score >= $minimum ) {
				$scores[ (int) $target_id ] = $score;
			}
		}
	}

	// Ties go to the lower ID, so the same vectors always give the same list.
	uksort(
		$scores,
		static function ( $a, $b ) use ( $scores ) {
			$order = $scores[ $b ] <=> $scores[ $a ];

			return 0 === $order ? $a <=> $b : $order;
		}
	);

	// Posts readers may not see, or the author hid, are left out first, so
	// they never take the place of one that can be shown. Each change to
	// either measures the list again.
	$hidden = array_flip( undergrowth_similar_hidden( $post_id ) );
	$scores = array_intersect_key( $scores, array_flip( undergrowth_filter_garden_visible( array_keys( array_diff_key( $scores, $hidden ) ) ) ) );

	// Every candidate is weighed here, so a post far down by meaning alone
	// that rises on its stage, freshness or views is kept. The closest by
	// meaning are kept too, for the quiet slot. The bound keeps one post's
	// list from holding the whole archive. Stage changes and each day's
	// views measure it again, and freshness fades too slowly to reorder
	// much before the next measure.
	$weighted = undergrowth_similar_rank( $scores, undergrowth_posts_popularity( array_keys( $scores ) ) );

	$keep   = array_slice( array_keys( $weighted ), 0, UNDERGROWTH_SIMILAR_CACHED );
	$keep   = array_merge( $keep, array_slice( array_keys( $scores ), 0, UNDERGROWTH_SIMILAR_CACHED ) );
	$scores = array_intersect_key( $scores, array_flip( $keep ) );

	update_post_meta(
		$post_id,
		'undergrowth_similar',
		array(
			'generation' => $generation,
			'scores'     => $scores,
		)
	);

	return $scores;
}

/**
 * Measures a post's list again in cron, after a vector changed.
 *
 * @param int $post_id Post ID.
 */
function undergrowth_remeasure_similar( int $post_id ): void {
	undergrowth_measure_similar( $post_id );
}

/**
 * Returns how much each growth stage lifts or sinks a post.
 *
 * @return array<string, float> Multiplier keyed by stage.
 */
function undergrowth_similar_stage_weights(): array {
	/**
	 * Filters how much each growth stage lifts or sinks a post in "More like this".
	 *
	 * @param array<string, float> $weights Multiplier keyed by stage. Default Evergreen 1.15, Budding 1.1, Sprout 1, Seedling 0.9.
	 */
	$weights = apply_filters( 'undergrowth_similar_stage_weights', UNDERGROWTH_SIMILAR_STAGES );

	return is_array( $weights ) ? array_map( 'floatval', array_filter( $weights, 'is_numeric' ) ) : UNDERGROWTH_SIMILAR_STAGES;
}

/**
 * Returns the lift a recently updated post gets, and how fast it fades.
 *
 * @return array{lift: float, half_life: float} The lift for a post updated today, and the days it takes to halve.
 */
function undergrowth_similar_freshness(): array {
	/**
	 * Filters the lift a recently updated post gets in "More like this".
	 *
	 * @param array{lift: float, half_life: float} $freshness The lift for a post updated today, default 0.1, and the days it takes to halve, default 180.
	 */
	$freshness = apply_filters(
		'undergrowth_similar_freshness',
		array(
			'lift'      => UNDERGROWTH_SIMILAR_FRESH_LIFT,
			'half_life' => UNDERGROWTH_SIMILAR_FRESH_HALF,
		)
	);

	return array(
		'lift'      => max( 0.0, (float) ( $freshness['lift'] ?? UNDERGROWTH_SIMILAR_FRESH_LIFT ) ),
		'half_life' => max( 1.0, (float) ( $freshness['half_life'] ?? UNDERGROWTH_SIMILAR_FRESH_HALF ) ),
	);
}

/**
 * Weighs a post's similarity by its growth stage and how recently it changed.
 *
 * @param WP_Post $post       Post to weigh.
 * @param float   $similarity Its similarity to the post the list is for.
 * @param int     $now        Unix time to measure age from.
 * @return float The weighted score.
 */
function undergrowth_similar_weight( WP_Post $post, float $similarity, int $now ): float {
	$stages    = undergrowth_similar_stage_weights();
	$freshness = undergrowth_similar_freshness();
	$changed   = strtotime( $post->post_modified_gmt . ' UTC' );
	$age       = false === $changed ? INF : max( 0, $now - $changed ) / DAY_IN_SECONDS;
	$fresh     = 1 + $freshness['lift'] * pow( 0.5, $age / $freshness['half_life'] );

	return $similarity * ( $stages[ undergrowth_get_stage( $post->ID ) ] ?? 1.0 ) * $fresh;
}

/**
 * Returns the most popularity can lift a post.
 *
 * The most viewed post on the list gets the whole lift, the others a share
 * in proportion to their views, so popularity can never carry a post past
 * one that is closer by more than this.
 *
 * @return float The lift for the most viewed post, from 0 to 1.
 */
function undergrowth_similar_popularity_cap(): float {
	/**
	 * Filters the most popularity can lift a post in "More like this".
	 *
	 * @param float $cap The lift for the most viewed post on the list, from 0 to 1. Default 0.15.
	 */
	return max( 0.0, min( 1.0, (float) apply_filters( 'undergrowth_similar_popularity_cap', UNDERGROWTH_SIMILAR_POPULAR ) ) );
}

/**
 * Weighs posts by their similarity, stage, freshness and recent views, and
 * orders them best first.
 *
 * The most viewed post gets the whole popularity lift, the others a share
 * in proportion to their views. A post deleted meanwhile is left out.
 *
 * @param array<int, float>      $scores Similarity keyed by post ID.
 * @param array<int, float|null> $views  Recent views keyed by post ID, null where not known.
 * @return array<int, float> Weighted scores keyed by post ID, best first, ties to the lower ID.
 */
function undergrowth_similar_rank( array $scores, array $views ): array {
	$known    = array_filter( $views, 'is_float' );
	$most     = array() === $known ? 0.0 : max( $known );
	$cap      = undergrowth_similar_popularity_cap();
	$now      = time();
	$weighted = array();

	_prime_post_caches( array_keys( $scores ), false, true );

	foreach ( $scores as $id => $score ) {
		$post = get_post( $id );

		if ( $post instanceof WP_Post ) {
			$lift            = $most > 0 ? 1 + $cap * ( $views[ $id ] ?? 0.0 ) / $most : 1.0;
			$weighted[ $id ] = undergrowth_similar_weight( $post, $score, $now ) * $lift;
		}
	}

	uksort(
		$weighted,
		static function ( $a, $b ) use ( $weighted ) {
			$order = $weighted[ $b ] <=> $weighted[ $a ];

			return 0 === $order ? $a <=> $b : $order;
		}
	);

	return $weighted;
}

/**
 * Counts the accepted links pointing to each of some posts.
 *
 * When a site's view counts cannot be read, this is how "under-read" is
 * told: a post few others link to is one readers rarely reach.
 *
 * @param int[] $post_ids Post IDs.
 * @return array<int, int> Accepted links in, keyed by post ID, zero for none.
 */
function undergrowth_similar_links_in( array $post_ids ): array {
	global $wpdb;

	$counts = array_fill_keys( $post_ids, 0 );

	if ( array() === $post_ids ) {
		return $counts;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The plugin's own table; there is one placeholder for each ID.
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT target_id, COUNT(*) AS links FROM %i WHERE state = %s AND target_id IN ({$placeholders}) GROUP BY target_id", undergrowth_links_table(), 'accepted', ...$post_ids ), ARRAY_A );

	foreach ( $rows as $row ) {
		$counts[ (int) $row['target_id'] ] = (int) $row['links'];
	}

	return $counts;
}

/**
 * Lists the posts the author hid from a post's "More like this".
 *
 * @param int $post_id Post ID.
 * @return int[] Hidden post IDs.
 */
function undergrowth_similar_hidden( int $post_id ): array {
	return array_values( array_unique( array_filter( array_map( 'absint', get_post_meta( $post_id, 'undergrowth_similar_hidden', false ) ) ) ) );
}

/**
 * Hides a post from another post's "More like this" for good, or shows it again.
 *
 * @param int  $post_id   Post whose list it is.
 * @param int  $target_id Post to hide or show.
 * @param bool $hide      True to hide, false to show again.
 * @return bool True when the post is hidden afterwards, or shown afterwards when showing.
 */
function undergrowth_set_similar_hidden( int $post_id, int $target_id, bool $hide ): bool {
	// Hiding a post names it on the author's list, so only a post the user
	// may read can be hidden.
	if ( $post_id === $target_id || ! get_post( $post_id ) instanceof WP_Post || ! current_user_can( 'edit_post', $post_id ) || ( $hide && ! current_user_can( 'read_post', $target_id ) ) ) {
		return false;
	}

	$hidden = in_array( $target_id, undergrowth_similar_hidden( $post_id ), true );

	if ( $hide && ! $hidden ) {
		add_post_meta( $post_id, 'undergrowth_similar_hidden', $target_id );
	} elseif ( ! $hide && $hidden ) {
		delete_post_meta( $post_id, 'undergrowth_similar_hidden', $target_id );
	}

	// The author is waiting on the list, so it is measured again at once.
	if ( $hide !== $hidden ) {
		undergrowth_measure_similar( $post_id );
	}

	return true;
}

/**
 * Returns the posts "More like this" shows for a post, in order.
 *
 * Posts readers may not see, and posts the author hid, are left out before
 * the list is weighed. Recent views, where a stats plugin gives them, lift
 * a post by up to undergrowth_similar_popularity_cap(). When there is room
 * for more than one post, the last slot goes to the close post with the
 * fewest recent views, or the fewest links in when views are not known, if
 * it did not make the list on its own.
 *
 * @param int $post_id Post the list is for.
 * @param int $count   How many posts to show, 1 to 6.
 * @return int[] Post IDs.
 */
function undergrowth_more_like_this( int $post_id, int $count ): array {
	$count = max( 1, min( UNDERGROWTH_SIMILAR_MAX_SHOWN, $count ) );

	if ( ! undergrowth_is_garden_visible( $post_id ) ) {
		return array();
	}

	$scores  = undergrowth_similar_scores( $post_id );
	$hidden  = array_flip( undergrowth_similar_hidden( $post_id ) );
	$visible = undergrowth_filter_garden_visible( array_keys( array_diff_key( $scores, $hidden ) ) );

	if ( array() === $visible ) {
		return array();
	}

	$views    = undergrowth_posts_popularity( $visible );
	$known    = array_filter( $views, 'is_float' );
	$weighted = undergrowth_similar_rank( array_intersect_key( $scores, array_flip( $visible ) ), $views );
	$visible  = array_keys( array_intersect_key( array_flip( $visible ), $weighted ) );

	$shown = array_slice( array_keys( $weighted ), 0, $count );

	if ( $count < 2 || count( $visible ) <= $count ) {
		return $shown;
	}

	// The under-read post is chosen among the closest in meaning alone, so
	// it is always close, however its stage or age weighs it.
	$close = array_slice( $visible, 0, $count * 2 );
	$links = array() === $known
		? undergrowth_similar_links_in( array_values( array_unique( array_merge( $close, $shown ) ) ) )
		: array_map( 'floatval', $views );
	$quiet = null;

	foreach ( $close as $id ) {
		if ( null === $quiet || $links[ $id ] < $links[ $quiet ] ) {
			$quiet = $id;
		}
	}

	// Only when every post on the list is better read or linked than it, so
	// a list that already holds a quiet post is left as it is.
	if ( null !== $quiet && $links[ $quiet ] < min( array_intersect_key( $links, array_flip( $shown ) ) ) ) {
		$shown[ $count - 1 ] = $quiet;
	}

	return $shown;
}

/**
 * Registers the routes the block's editor sidebar uses.
 */
function undergrowth_register_similar_routes(): void {
	register_rest_route(
		'undergrowth/v1',
		'/posts/(?P<id>\d+)/more-like-this',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'undergrowth_rest_get_similar',
			'permission_callback' => 'undergrowth_rest_can_edit_similar',
			'args'                => array(
				'count' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => UNDERGROWTH_SIMILAR_MAX_SHOWN,
					'default' => 3,
				),
			),
		)
	);

	register_rest_route(
		'undergrowth/v1',
		'/posts/(?P<id>\d+)/more-like-this/hidden/(?P<target>\d+)',
		array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'undergrowth_rest_hide_similar',
				'permission_callback' => 'undergrowth_rest_can_edit_similar',
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => 'undergrowth_rest_hide_similar',
				'permission_callback' => 'undergrowth_rest_can_edit_similar',
			),
		)
	);
}

/**
 * Lets only someone who can edit the post see or change its list.
 *
 * @param WP_REST_Request $request Request.
 * @return bool True when the user can edit the post.
 */
function undergrowth_rest_can_edit_similar( WP_REST_Request $request ): bool {
	$post_id = (int) $request['id'];

	return get_post( $post_id ) instanceof WP_Post && current_user_can( 'edit_post', $post_id );
}

/**
 * Describes posts for the editor sidebar: ID, title and link. A post the
 * user may not read is left out.
 *
 * @param int[] $post_ids Post IDs.
 * @return array<int, array{id: int, title: string, link: string}>
 */
function undergrowth_similar_summaries( array $post_ids ): array {
	_prime_post_caches( $post_ids, false, false );

	$summaries = array();

	foreach ( $post_ids as $id ) {
		$post = get_post( $id );

		if ( $post instanceof WP_Post && current_user_can( 'read_post', $id ) ) {
			$summaries[] = array(
				'id'    => $id,
				'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				'link'  => (string) get_permalink( $post ),
			);
		}
	}

	return $summaries;
}

/**
 * Returns the list readers see for a post, and the posts the author hid.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response The shown and hidden posts.
 */
function undergrowth_rest_get_similar( WP_REST_Request $request ): WP_REST_Response {
	$post_id = (int) $request['id'];

	return new WP_REST_Response(
		array(
			'shown'  => undergrowth_similar_summaries( undergrowth_more_like_this( $post_id, (int) $request['count'] ) ),
			'hidden' => undergrowth_similar_summaries( undergrowth_similar_hidden( $post_id ) ),
		)
	);
}

/**
 * Hides a post from a post's list, or shows it again.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error Whether the post is hidden now.
 */
function undergrowth_rest_hide_similar( WP_REST_Request $request ) {
	$post_id   = (int) $request['id'];
	$target_id = (int) $request['target'];
	$hide      = 'DELETE' !== $request->get_method();

	if ( ! get_post( $target_id ) instanceof WP_Post || ! undergrowth_set_similar_hidden( $post_id, $target_id, $hide ) ) {
		return new WP_Error( 'undergrowth_similar_gone', __( 'That post no longer exists, so nothing was changed.', 'undergrowth' ), array( 'status' => 404 ) );
	}

	return new WP_REST_Response( array( 'hidden' => $hide ) );
}
