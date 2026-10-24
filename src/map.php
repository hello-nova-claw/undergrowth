<?php
/**
 * Map: naming each group from the author's own words, and accounting for
 * every post.
 *
 * A group is named after the title of its post nearest the middle, with a
 * few words or two-word phrases its posts share and the rest of the site
 * rarely uses. Every word is one the author wrote. No model is asked to name
 * anything, because Undergrowth never writes words for the author.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

// Most words shown under a group's name.
const UNDERGROWTH_LABEL_TERMS = 4;

/**
 * Finds the words and two-word phrases in some text that could name a group.
 *
 * A word needs three letters or more, no digit, and must not be on either
 * of the stop-word lists for the language (see anchor-words.php). A phrase
 * is two such words side by side, with no punctuation, line break or other
 * word between them. Unspaced scripts give their pairs of characters, as in
 * undergrowth_tokens(), and no phrases.
 *
 * @param string $text   Plain text.
 * @param string $locale Language of the text, such as en_US.
 * @return array<string, string> The spelling the author first used, keyed by its lowercase form, in reading order.
 */
function undergrowth_label_words( string $text, string $locale ): array {
	$unspaced = UNDERGROWTH_UNSPACED_SCRIPTS;
	$stop     = undergrowth_anchor_stopwords( $locale );
	$stop     = array_flip( array_merge( $stop['never'], $stop['named'] ) );
	$words    = array();

	foreach ( (array) preg_split( '/[^\p{L}\p{M}\p{N}\h]+/u', $text ) as $phrase ) {
		if ( ! preg_match_all( "/(?:(?=[\\p{L}\\p{M}\\p{N}])[{$unspaced}])+|(?:(?![{$unspaced}])[\\p{L}\\p{N}])+/u", $phrase, $matches ) ) {
			continue;
		}

		$previous = null;

		foreach ( $matches[0] as $run ) {
			$key = mb_strtolower( $run, 'UTF-8' );

			if ( preg_match( "/^[{$unspaced}]/u", $run ) ) {
				foreach ( undergrowth_tokens( $run ) as $pair ) {
					$words[ $pair ] = $words[ $pair ] ?? $pair;
				}

				$previous = null;
				continue;
			}

			if ( mb_strlen( $run, 'UTF-8' ) < 3 || preg_match( '/\p{N}/u', $run ) || isset( $stop[ $key ] ) ) {
				$previous = null;
				continue;
			}

			$words[ $key ] = $words[ $key ] ?? $run;

			if ( null !== $previous && $previous[0] !== $key ) {
				$pair           = $previous[0] . ' ' . $key;
				$words[ $pair ] = $words[ $pair ] ?? $previous[1] . ' ' . $run;
			}

			$previous = array( $key, $run );
		}
	}

	return $words;
}

/**
 * Chooses the words that most set a group apart from the rest of the site.
 *
 * A word must be in at least two of the group's posts, and in a share of
 * the group's posts at least twice its share of the other posts. Words in
 * many of the group's posts and few others rank highest. A phrase takes the
 * place of its own words when they are in no more of the group's posts than
 * it is, and no two chosen terms share a word.
 *
 * @param array<string, int> $group      Number of the group's posts using each word.
 * @param int                $group_size Number of posts in the group.
 * @param array<string, int> $site       Number of posts on the site using each word, the group's among them.
 * @param int                $site_size  Number of posts on the site, the group's among them.
 * @param int                $limit      Most words to choose.
 * @return string[] Lowercase words and phrases, most telling first.
 */
function undergrowth_choose_label_terms( array $group, int $group_size, array $site, int $site_size, int $limit = UNDERGROWTH_LABEL_TERMS ): array {
	$rest_size = max( 0, $site_size - $group_size );
	$scores    = array();

	foreach ( $group as $term => $count ) {
		$term = (string) $term;
		$rest = max( 0, ( $site[ $term ] ?? $count ) - $count );

		if ( $count < 2 || $group_size < 1 || ( $rest_size > 0 && $count / $group_size < 2 * $rest / $rest_size ) ) {
			continue;
		}

		$scores[ $term ] = $count / $group_size * log( ( $rest_size + 1 ) / ( $rest + 0.5 ) );
	}

	foreach ( array_keys( $scores ) as $term ) {
		$parts = explode( ' ', (string) $term );

		if ( 2 !== count( $parts ) ) {
			continue;
		}

		foreach ( $parts as $part ) {
			if ( isset( $scores[ $part ] ) && $group[ $part ] <= $group[ $term ] ) {
				unset( $scores[ $part ] );
			}
		}
	}

	$chosen = array();
	$used   = array();

	foreach ( array_keys( undergrowth_heaviest_terms( $scores, 0 ) ) as $term ) {
		$parts = explode( ' ', (string) $term );

		if ( array() !== array_intersect_key( array_flip( $parts ), $used ) ) {
			continue;
		}

		$chosen[] = (string) $term;
		$used    += array_flip( $parts );

		if ( count( $chosen ) >= $limit ) {
			break;
		}
	}

	return $chosen;
}

/**
 * Lists the posts in the active garden: indexable and not composting.
 *
 * @return array<int, string> When each post was last read, as a UTC MySQL
 *                            date, or an empty string for a post not read
 *                            yet; keyed by post ID, newest first.
 */
function undergrowth_garden_posts(): array {
	global $wpdb;

	$indexable = undergrowth_indexable_sql( 'p.' );

	if ( null === $indexable ) {
		return array();
	}

	// A post's stage is its first stored row, as undergrowth_get_stage() reads it.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One query instead of loading every post; the condition's placeholders come with it.
	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			'SELECT p.ID, v.embedded_at FROM %i p LEFT JOIN %i m ON m.meta_id = ( SELECT MIN(f.meta_id) FROM %i f WHERE f.post_id = p.ID AND f.meta_key = %s ) LEFT JOIN %i v ON v.post_id = p.ID WHERE ' . $indexable[0] . ' AND ( m.meta_value IS NULL OR m.meta_value <> %s ) ORDER BY p.ID DESC',
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->postmeta,
			'undergrowth_stage',
			undergrowth_vectors_table(),
			...array_merge( $indexable[1], array( 'composting' ) )
		),
		ARRAY_A
	);
	// phpcs:enable

	$posts = array();

	foreach ( $rows as $row ) {
		$posts[ (int) $row['ID'] ] = (string) $row['embedded_at'];
	}

	return $posts;
}

/**
 * Marks the start of a cluster pass, and forgets last month's words.
 */
function undergrowth_start_cluster_labels(): void {
	update_option(
		'undergrowth_cluster_labels',
		array(
			'made'  => current_time( 'mysql', true ),
			'terms' => array(),
		),
		false
	);
}

/**
 * Works out and stores the words under each group's name, at the end of
 * the cluster pass, so the Map reads them without reading any post.
 *
 * Each group is read on the members its centre is measured on. Then every
 * post in the garden is read once more, a chunk at a time, to count how
 * many use each of the groups' candidate words.
 */
function undergrowth_label_clusters(): void {
	global $wpdb;

	$groups     = array();
	$candidates = array();

	foreach ( undergrowth_cluster_leaders() as $leader ) {
		$members = undergrowth_centre_members( $leader );
		$counts  = array();
		$spelled = array();
		$locale  = '';

		foreach ( $members as $post_id ) {
			$post = undergrowth_uncached_post( $post_id );

			if ( null === $post ) {
				continue;
			}

			$locale = '' === $locale ? undergrowth_post_language( $post ) : $locale;

			foreach ( undergrowth_label_words( undergrowth_post_text( $post ), $locale ) as $term => $spelling ) {
				$counts[ $term ]               = ( $counts[ $term ] ?? 0 ) + 1;
				$spelled[ $term ][ $spelling ] = ( $spelled[ $term ][ $spelling ] ?? 0 ) + 1;
			}
		}

		$counts = array_filter(
			$counts,
			static function ( $count ) {
				return $count >= 2;
			}
		);

		$groups[ $leader ] = array(
			'size'    => count( $members ),
			'counts'  => $counts,
			'spelled' => array_intersect_key( $spelled, $counts ),
		);
		$candidates       += array_fill_keys( array_keys( $counts ), 0 );
	}

	$garden = array_keys( undergrowth_garden_posts() );

	foreach ( array_chunk( $garden, UNDERGROWTH_POST_CHUNK ) as $chunk ) {
		$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Caching every post is what this avoids; there is one placeholder for each ID in the chunk.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_title, post_content FROM %i WHERE ID IN (' . $placeholders . ')', $wpdb->posts, ...$chunk ) );

		foreach ( $rows as $row ) {
			// No stop words are dropped here, so every candidate is counted.
			foreach ( array_keys( array_intersect_key( undergrowth_label_words( undergrowth_post_text( new WP_Post( $row ) ), '' ), $candidates ) ) as $term ) {
				++$candidates[ $term ];
			}
		}

		unset( $rows );
		$wpdb->flush();
	}

	$terms = array();

	foreach ( $groups as $leader => $group ) {
		// Two more than are shown, for any the group's name already holds.
		foreach ( undergrowth_choose_label_terms( $group['counts'], $group['size'], $candidates, count( $garden ), UNDERGROWTH_LABEL_TERMS + 2 ) as $term ) {
			$spellings = $group['spelled'][ $term ];
			arsort( $spellings );
			$terms[ $leader ][] = (string) array_key_first( $spellings );
		}
	}

	$labels          = (array) get_option( 'undergrowth_cluster_labels', array() );
	$labels['made']  = $labels['made'] ?? current_time( 'mysql', true );
	$labels['terms'] = $terms;

	update_option( 'undergrowth_cluster_labels', $labels, false );
}

/**
 * Returns the stored words for a group, leaving out any its name holds.
 *
 * @param int    $leader Leader ID labelling the group.
 * @param string $name   Title the group is named after, or an empty string.
 * @return string[] At most UNDERGROWTH_LABEL_TERMS words or phrases, as the author spelled them.
 */
function undergrowth_cluster_terms( int $leader, string $name = '' ): array {
	$labels = get_option( 'undergrowth_cluster_labels', array() );
	$terms  = is_array( $labels ) && isset( $labels['terms'][ $leader ] ) ? array_map( 'strval', (array) $labels['terms'][ $leader ] ) : array();
	$named  = undergrowth_label_words( $name, '' );
	$shown  = array();

	foreach ( $terms as $term ) {
		if ( ! isset( $named[ mb_strtolower( $term, 'UTF-8' ) ] ) ) {
			$shown[] = $term;
		}
	}

	return array_slice( $shown, 0, UNDERGROWTH_LABEL_TERMS );
}

/**
 * Accounts for every post in the garden: in a group on the map, not in a
 * group yet, or composting.
 *
 * A post not in a group was either not read yet, read since the groups
 * were last found, or read in time but not close enough to other posts.
 *
 * @param array<int, array{leader: int, members: int[], gap: bool}> $map Result of undergrowth_cluster_map().
 * @return array{grouped: int, ungrouped: array<int, string>, composting: int} Number of posts
 *         in a group; each other post's reason, 'unread', 'new' or 'apart', keyed by post ID,
 *         newest first; and the number of composting posts.
 */
function undergrowth_map_accounting( array $map ): array {
	$grouped = array();

	foreach ( $map as $cluster ) {
		$grouped += array_fill_keys( $cluster['members'], true );
	}

	$labels    = get_option( 'undergrowth_cluster_labels', array() );
	$made      = is_array( $labels ) && isset( $labels['made'] ) ? (string) $labels['made'] : '';
	$ungrouped = array();

	foreach ( undergrowth_garden_posts() as $post_id => $read ) {
		if ( isset( $grouped[ $post_id ] ) ) {
			continue;
		}

		if ( '' === $read ) {
			$ungrouped[ $post_id ] = 'unread';
		} elseif ( '' !== $made && $read > $made ) {
			$ungrouped[ $post_id ] = 'new';
		} else {
			$ungrouped[ $post_id ] = 'apart';
		}
	}

	return array(
		'grouped'    => count( $grouped ),
		'ungrouped'  => $ungrouped,
		'composting' => undergrowth_count_stages()['composting'],
	);
}
