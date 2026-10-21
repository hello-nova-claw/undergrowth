<?php
/**
 * The link suggestions screen: where the author sees proposals and acts on them.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'undergrowth_add_suggestions_page' );
add_action( 'admin_post_undergrowth_veto', 'undergrowth_handle_veto' );
add_action( 'admin_post_undergrowth_accept', 'undergrowth_handle_accept' );
add_action( 'admin_post_undergrowth_toggle', 'undergrowth_handle_toggle' );
add_action( 'admin_post_undergrowth_pin', 'undergrowth_handle_pin' );
add_action( 'admin_post_undergrowth_unpin', 'undergrowth_handle_unpin' );
add_action( 'admin_post_undergrowth_dismiss_resurfacing', 'undergrowth_handle_dismiss_resurfacing' );

/**
 * Adds Posts > Link suggestions.
 */
function undergrowth_add_suggestions_page(): void {
	add_posts_page(
		__( 'Link suggestions', 'undergrowth' ),
		__( 'Link suggestions', 'undergrowth' ),
		'edit_others_posts',
		'undergrowth-links',
		'undergrowth_render_suggestions_page'
	);
}

/**
 * Loads the stages table class.
 *
 * Like the proposals table, it extends a wp-admin class, so it is only
 * loaded when the screen is drawn.
 */
function undergrowth_load_stages_table(): void {
	require_once __DIR__ . '/class-undergrowth-stages-list-table.php';
}

/**
 * Loads the proposals table class.
 *
 * It extends core's WP_List_Table, which belongs to wp-admin, so it is only
 * loaded when the screen is drawn rather than on every request.
 */
function undergrowth_load_list_table(): void {
	require_once __DIR__ . '/class-undergrowth-links-list-table.php';
}

/**
 * Returns the address of the link suggestions screen.
 *
 * @param string $notice Notice to show on arrival, or an empty string.
 * @param string $tab    'stages' for the growth stages tab, or an empty string for the suggestions.
 * @return string Admin URL.
 */
function undergrowth_suggestions_url( string $notice = '', string $tab = '' ): string {
	// The screen is registered under plain edit.php; adding post_type=post
	// makes WordPress look for it under a different parent and refuse it.
	$url = admin_url( 'edit.php?page=undergrowth-links' );

	if ( '' !== $tab ) {
		$url = add_query_arg( 'tab', $tab, $url );
	}

	return '' === $notice ? $url : add_query_arg( 'undergrowth_notice', $notice, $url );
}

/**
 * Vetoes the link posted from a row's form, then returns to the screen.
 */
function undergrowth_handle_veto(): void {
	$source = isset( $_POST['source'] ) ? absint( wp_unslash( $_POST['source'] ) ) : 0;
	$target = isset( $_POST['target'] ) ? absint( wp_unslash( $_POST['target'] ) ) : 0;

	check_admin_referer( 'undergrowth_veto_' . $source . '_' . $target );

	if ( ! current_user_can( 'edit_post', $source ) ) {
		wp_die( esc_html__( 'You cannot change links in this post.', 'undergrowth' ), '', array( 'response' => 403 ) );
	}

	if ( undergrowth_veto_link( $source, $target ) ) {
		$notice = 'vetoed';
	} elseif ( 'accepted' === undergrowth_link_state( $source, $target ) ) {
		$notice = 'unchanged';
	} else {
		$notice = 'gone';
	}

	wp_safe_redirect( undergrowth_suggestions_url( $notice ) );
	exit;
}

/**
 * Accepts the link posted from a row's form, then returns to the screen.
 *
 * After a change, the address carries the new revision so the notice can
 * link straight to what changed.
 */
function undergrowth_handle_accept(): void {
	$source = isset( $_POST['source'] ) ? absint( wp_unslash( $_POST['source'] ) ) : 0;
	$target = isset( $_POST['target'] ) ? absint( wp_unslash( $_POST['target'] ) ) : 0;

	check_admin_referer( 'undergrowth_accept_' . $source . '_' . $target );

	if ( ! current_user_can( 'edit_post', $source ) ) {
		wp_die( esc_html__( 'You cannot change links in this post.', 'undergrowth' ), '', array( 'response' => 403 ) );
	}

	$result = undergrowth_accept_link( $source, $target );
	$url    = undergrowth_suggestions_url( $result );

	if ( 'accepted' === $result ) {
		$revisions = wp_get_post_revisions( $source, array( 'posts_per_page' => 1 ) );

		if ( array() !== $revisions ) {
			$url = add_query_arg( 'revision', (int) key( $revisions ), $url );
		}
	}

	wp_safe_redirect( $url );
	exit;
}

/**
 * Saves the "Suggest links between my posts" choice, then returns to the screen.
 */
function undergrowth_handle_toggle(): void {
	check_admin_referer( 'undergrowth_toggle' );

	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'You cannot change this setting.', 'undergrowth' ), '', array( 'response' => 403 ) );
	}

	update_option( 'undergrowth_suggest_links', empty( $_POST['undergrowth_suggest_links'] ) ? '0' : '1' );

	wp_safe_redirect( undergrowth_suggestions_url( 'saved' ) );
	exit;
}

/**
 * Pins the stage posted from a row's form, then returns to the stages tab.
 */
function undergrowth_handle_pin(): void {
	$post_id = isset( $_POST['post'] ) ? absint( wp_unslash( $_POST['post'] ) ) : 0;
	$stage   = isset( $_POST['stage'] ) ? sanitize_key( wp_unslash( $_POST['stage'] ) ) : '';

	check_admin_referer( 'undergrowth_pin_' . $post_id );

	undergrowth_finish_stage_action( undergrowth_pin_stage( $post_id, $stage ), 'pinned' );
}

/**
 * Unpins the stage posted from a row's form, then returns to the stages tab.
 */
function undergrowth_handle_unpin(): void {
	$post_id = isset( $_POST['post'] ) ? absint( wp_unslash( $_POST['post'] ) ) : 0;

	check_admin_referer( 'undergrowth_unpin_' . $post_id );

	undergrowth_finish_stage_action( undergrowth_unpin_stage( $post_id ), 'unpinned' );
}

/**
 * Dismisses the resurfacing signal posted from its form, then returns to
 * the stages tab.
 */
function undergrowth_handle_dismiss_resurfacing(): void {
	$post_id = isset( $_POST['post'] ) ? absint( wp_unslash( $_POST['post'] ) ) : 0;

	check_admin_referer( 'undergrowth_dismiss_resurfacing_' . $post_id );

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( esc_html__( 'You cannot change this post.', 'undergrowth' ), '', array( 'response' => 403 ) );
	}

	wp_safe_redirect( undergrowth_suggestions_url( undergrowth_dismiss_resurfacing( $post_id ) ? 'resurfacing_dismissed' : 'resurfacing_gone', 'stages' ) );
	exit;
}

/**
 * Returns to the stages tab with the notice for a stage action's result.
 *
 * @param true|WP_Error $result  What the action returned.
 * @param string        $success Notice to show when it succeeded.
 */
function undergrowth_finish_stage_action( $result, string $success ): void {
	$notices = array(
		'undergrowth_not_indexed'   => 'gone',
		'undergrowth_unknown_stage' => 'stage_refused',
		'undergrowth_composting'    => 'stage_composting',
		'undergrowth_not_pinned'    => 'not_pinned',
		'undergrowth_not_saved'     => 'stage_not_saved',
		'undergrowth_pin_left'      => 'stage_pin_left',
		'undergrowth_pin_lost'      => 'stage_pin_lost',
	);

	if ( is_wp_error( $result ) && ! isset( $notices[ $result->get_error_code() ] ) ) {
		wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 403 ) );
	}

	wp_safe_redirect( undergrowth_suggestions_url( is_wp_error( $result ) ? $notices[ $result->get_error_code() ] : $success, 'stages' ) );
	exit;
}

/**
 * Prints the link suggestions screen, on the tab the address asks for.
 */
function undergrowth_render_suggestions_page(): void {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}

	$tabs = array(
		''       => __( 'Suggestions', 'undergrowth' ),
		'stages' => __( 'Growth stages', 'undergrowth' ),
	);

	// The map only appears once the monthly pass has made a cluster.
	if ( undergrowth_has_clusters() ) {
		$tabs['map'] = __( 'Map', 'undergrowth' );
	}

	// The tab only selects what to show; nothing is changed.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	$tab = isset( $tabs[ $tab ] ) ? $tab : '';
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Link suggestions screen', 'undergrowth' ); ?>">
			<?php
			foreach ( $tabs as $key => $label ) {
				printf(
					'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
					esc_url( undergrowth_suggestions_url( '', $key ) ),
					$key === $tab ? ' nav-tab-active' : '',
					$key === $tab ? ' aria-current="page"' : '',
					esc_html( $label )
				);
			}
			?>
		</nav>

		<?php
		undergrowth_render_suggestions_notice();
		// A post returned to the garden from its resurfacing signal comes back here.
		undergrowth_render_compost_notice();

		if ( 'stages' === $tab ) {
			undergrowth_render_stages_tab();
		} elseif ( 'map' === $tab ) {
			undergrowth_render_map_tab();
		} else {
			undergrowth_render_suggestions_tab();
		}
		?>
	</div>
	<?php
}

/**
 * Prints the growth stages tab.
 */
function undergrowth_render_stages_tab(): void {
	undergrowth_render_resurfacing_signals();
	undergrowth_load_stages_table();
	$table = new Undergrowth_Stages_List_Table();
	$table->prepare_items();

	if ( ! $table->has_items() ) {
		if ( undergrowth_has_composting_posts() ) {
			echo '<p>' . esc_html__( 'Every published post is composting, so none has a growth stage to show here. Composting posts are marked on the Posts screen.', 'undergrowth' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'No published posts yet. Each post gets a growth stage once it is published.', 'undergrowth' ) . '</p>';
		}

		return;
	}

	echo '<p>' . esc_html__( 'Stages are measured once a month from the links you have accepted, and never lowered. Pin a stage to keep it as it is.', 'undergrowth' ) . '</p>';

	// The counts are the garden's health. Composting is counted on its own
	// line and never in a stage, so resting a post never reads as decline.
	$counts    = undergrowth_count_stages();
	$sentences = array();

	// Each sentence is its own element, translated whole.
	foreach ( array( 'seedling', 'sprout', 'budding', 'evergreen' ) as $stage ) {
		$sentences[] = '<span>' . esc_html( undergrowth_stage_count_sentence( $stage, $counts[ $stage ] ) ) . '</span>';
	}

	echo '<p class="undergrowth-stage-counts">' . implode( ' ', $sentences ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each sentence is escaped above.

	if ( $counts['composting'] > 0 ) {
		printf(
			'<p class="undergrowth-composting-count"><span>%1$s</span> <span>%2$s</span></p>',
			esc_html( undergrowth_stage_count_sentence( 'composting', $counts['composting'] ) ),
			esc_html__( 'Composting posts rest outside the garden, so they are not listed here. They are marked on the Posts screen, where you compost a post or return it to the garden.', 'undergrowth' )
		);
	}

	$table->display();
}

/**
 * Prints the map tab: each cluster as a heading over its posts.
 *
 * Clusters are numbered, never named, so nothing on the screen is written
 * by a model. A gap is marked in the heading, where a screen reader's list
 * of headings finds it, and said in words that name no post or topic.
 */
function undergrowth_render_map_tab(): void {
	echo '<p>' . esc_html__( 'Clusters are groups of your posts that are close in meaning, found once a month. Posts nearest the middle of each cluster come first.', 'undergrowth' ) . '</p>';

	foreach ( undergrowth_cluster_map() as $number => $cluster ) {
		$id    = 'undergrowth-cluster-' . $cluster['leader'];
		$label = number_format_i18n( $number + 1 );

		printf(
			'<h2 id="%1$s">%2$s</h2>',
			esc_attr( $id ),
			esc_html(
				$cluster['gap']
					/* translators: %1$s: number of the cluster on the screen. */
					? sprintf( __( 'Cluster %1$s, with a gap', 'undergrowth' ), $label )
					/* translators: %1$s: number of the cluster on the screen. */
					: sprintf( __( 'Cluster %1$s', 'undergrowth' ), $label )
			)
		);

		if ( $cluster['gap'] ) {
			printf(
				'<p class="undergrowth-gap"><span class="dashicons dashicons-flag" aria-hidden="true"></span> %s</p>',
				esc_html__( 'No post sits at the middle of this cluster. These posts circle something none of them is about yet.', 'undergrowth' )
			);
		}

		printf( '<ul class="undergrowth-cluster" aria-labelledby="%s">', esc_attr( $id ) );

		foreach ( $cluster['members'] as $post_id ) {
			printf(
				'<li>%s</li>',
				wp_kses(
					sprintf(
						/* translators: 1: post title, linked to its editor when you can edit it, 2: growth stage. */
						__( '%1$s (%2$s)', 'undergrowth' ),
						undergrowth_post_link( $post_id ),
						esc_html( undergrowth_stage_label( undergrowth_get_stage( $post_id ) ) )
					),
					array( 'a' => array( 'href' => array() ) )
				)
			);
		}

		echo '</ul>';
	}
}

/**
 * Prints one notice for each composting post found near a new cluster this
 * cycle, with a way to return it to the garden or leave it be.
 *
 * The cluster is named by its newest post, the one closest to what the
 * author is writing now. Nothing says how long the post has been resting.
 */
function undergrowth_render_resurfacing_signals(): void {
	$post_types = undergrowth_indexed_post_types();

	if ( array() === $post_types ) {
		return;
	}

	$query = new WP_Query(
		array(
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'has_password'           => false,
			'meta_key'               => 'undergrowth_resurfacing', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only composting posts near a new cluster have the key.
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			// Every signal is listed, since this is the only place it shows. There
			// is one per post, and only posts the author composted have one.
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'fields'                 => 'ids',
			'update_post_term_cache' => false,
		)
	);

	foreach ( array_map( 'intval', $query->posts ) as $post_id ) {
		$members = undergrowth_cluster_members( undergrowth_resurfacing_signal( $post_id ) );

		if ( array() === $members || 'composting' !== undergrowth_get_stage( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			continue;
		}

		$newest = max( $members );

		printf(
			'<div class="notice notice-info inline undergrowth-resurfacing"><p id="undergrowth-resurfacing-%3$d">%1$s</p><form method="post" action="%2$s"><input type="hidden" name="action" value="undergrowth_dismiss_resurfacing" /><input type="hidden" name="post" value="%3$d" />',
			wp_kses(
				sprintf(
					/* translators: 1: title of the composting post, 2: title of the newest post in the cluster it is close to. */
					__( 'Your composting post %1$s is near what you are writing now, in the cluster with %2$s.', 'undergrowth' ),
					undergrowth_post_link( $post_id ),
					undergrowth_post_link( $newest )
				),
				array( 'a' => array( 'href' => array() ) )
			),
			esc_url( admin_url( 'admin-post.php' ) ),
			(int) $post_id
		);
		wp_nonce_field( 'undergrowth_dismiss_resurfacing_' . $post_id );
		printf(
			'<p><a href="%1$s" class="button" aria-describedby="undergrowth-resurfacing-%2$d">%3$s</a> <button type="submit" class="button" aria-describedby="undergrowth-resurfacing-%2$d">%4$s</button></p></form></div>',
			esc_url( undergrowth_stage_row_url( 'return', $post_id ) ),
			(int) $post_id,
			esc_html__( 'Return to garden', 'undergrowth' ),
			esc_html__( 'Dismiss', 'undergrowth' )
		);
	}
}

/**
 * Builds a post's title, linked to its editor when the user can edit it.
 *
 * @param int $post_id Post ID.
 * @return string Escaped HTML.
 */
function undergrowth_post_link( int $post_id ): string {
	$title = esc_html( get_the_title( $post_id ) );
	$link  = get_edit_post_link( $post_id, 'raw' );

	return null === $link ? $title : sprintf( '<a href="%1$s">%2$s</a>', esc_url( $link ), $title );
}

/**
 * Tells whether any post in the garden's post types is composting.
 *
 * @return bool True when at least one published post is composting.
 */
function undergrowth_has_composting_posts(): bool {
	$post_types = undergrowth_indexed_post_types();

	if ( array() === $post_types ) {
		return false;
	}

	$query = new WP_Query(
		array(
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'has_password'           => false,
			'meta_key'               => 'undergrowth_stage', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One row, once per screen.
			'meta_value'             => 'composting', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One row, once per screen.
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'fields'                 => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	return array() !== $query->posts;
}

/**
 * Prints the suggestions tab: the toggle and the proposals.
 */
function undergrowth_render_suggestions_tab(): void {
	$identity = undergrowth_embed_identity();
	$enabled  = undergrowth_suggestions_enabled();

	// Without a provider the toggle would change nothing the owner can see.
	if ( '' === $identity['provider'] || '' === $identity['model'] ) {
		echo '<p>' . wp_kses(
			sprintf(
				/* translators: %s: address of the Undergrowth settings page. */
				__( 'Link suggestions need an OpenAI API key. <a href="%s">Add one in Settings</a> to start.', 'undergrowth' ),
				esc_url( admin_url( 'options-general.php?page=undergrowth' ) )
			),
			array( 'a' => array( 'href' => array() ) )
		) . '</p>';

		return;
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="undergrowth_toggle" />
		<?php wp_nonce_field( 'undergrowth_toggle' ); ?>
		<p>
			<label>
				<input type="checkbox" name="undergrowth_suggest_links" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Suggest links between my posts', 'undergrowth' ); ?>
			</label>
			<?php submit_button( __( 'Save', 'undergrowth' ), 'secondary', 'submit', false ); ?>
		</p>
	</form>

	<?php
	if ( ! $enabled ) {
		echo '<p>' . esc_html__( 'Suggestions are off. Existing suggestions and vetoes are kept, and nothing new is suggested until you turn them back on.', 'undergrowth' ) . '</p>';
	} else {
		undergrowth_load_list_table();
		$table = new Undergrowth_Links_List_Table();
		$table->prepare_items();

		if ( ! $table->has_items() ) {
			echo '<p>' . esc_html__( 'No link suggestions yet. They appear here once your published posts have been read, which happens a moment after each post is saved.', 'undergrowth' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Each suggestion is a link from one of your posts to another, at a sentence you wrote. Nothing changes in your posts until you accept a suggestion.', 'undergrowth' ) . '</p>';
			$table->display();
		}
	}
}

/**
 * Prints the notice for the action that brought the user back to the screen.
 *
 * The notice takes focus, so a keyboard or screen reader user hears the
 * result instead of landing at the top of the page.
 */
function undergrowth_render_suggestions_notice(): void {
	// The notice only selects which fixed message to show; nothing is changed.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$notice = isset( $_GET['undergrowth_notice'] ) ? sanitize_key( wp_unslash( $_GET['undergrowth_notice'] ) ) : '';

	$messages = array(
		'vetoed'                => __( 'Done. This link won’t be suggested again.', 'undergrowth' ),
		'saved'                 => __( 'Your choice is saved.', 'undergrowth' ),
		'unchanged'             => __( 'That link was already accepted, so it was left as it is.', 'undergrowth' ),
		'gone'                  => __( 'One of those posts no longer exists or is no longer published, so nothing was changed.', 'undergrowth' ),
		'accepted'              => __( 'Linked. One word in your sentence now links to the suggested post.', 'undergrowth' ),
		'edited'                => __( 'That sentence has changed since the link was suggested, so nothing was changed. You can add the link by hand.', 'undergrowth' ),
		'no_word'               => __( 'No word in that sentence could be linked safely, so nothing was changed. You can add the link by hand.', 'undergrowth' ),
		'not_proposed'          => __( 'That suggestion is no longer on the list, so nothing was changed.', 'undergrowth' ),
		'needs_admin'           => __( 'Saving this post would also remove content that only someone allowed to post unfiltered HTML can save, such as an embed, so nothing was changed. Someone with that permission can accept this link.', 'undergrowth' ),
		'pinned'                => __( 'Stage pinned. The monthly cycle will leave it as it is.', 'undergrowth' ),
		'unpinned'              => __( 'Stage unpinned and measured again from the links you have accepted.', 'undergrowth' ),
		'stage_refused'         => __( 'That stage cannot be pinned, so nothing was changed.', 'undergrowth' ),
		'stage_composting'      => __( 'A composting post cannot be pinned, so nothing was changed.', 'undergrowth' ),
		'not_pinned'            => __( 'That stage was not pinned, so nothing was changed.', 'undergrowth' ),
		'stage_not_saved'       => __( 'The stage could not be saved, so nothing was changed. Try again in a moment.', 'undergrowth' ),
		'stage_pin_left'        => __( 'The stage could not be saved, and the pin marker could not be removed. Unpin the post to clear it.', 'undergrowth' ),
		'stage_pin_lost'        => __( 'The stage could not be saved, and the pin marker could not be put back. Pin the stage again to keep it.', 'undergrowth' ),
		'resurfacing_dismissed' => __( 'Dismissed. The post stays composting.', 'undergrowth' ),
		'resurfacing_gone'      => __( 'That post has no signal to dismiss, so nothing was changed.', 'undergrowth' ),
	);

	if ( ! isset( $messages[ $notice ] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$revision = isset( $_GET['revision'] ) ? absint( wp_unslash( $_GET['revision'] ) ) : 0;
	$see      = '';

	if ( 'accepted' === $notice && $revision > 0 ) {
		$see = sprintf(
			' <a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'revision.php?revision=' . $revision ) ),
			esc_html__( 'See the change', 'undergrowth' )
		);
	}

	printf(
		'<div id="undergrowth-notice" class="notice %1$s" role="status" tabindex="-1"><p>%2$s%3$s</p></div>',
		in_array( $notice, array( 'vetoed', 'saved', 'accepted', 'pinned', 'unpinned', 'resurfacing_dismissed' ), true ) ? 'notice-success' : 'notice-warning',
		esc_html( $messages[ $notice ] ),
		wp_kses( $see, array( 'a' => array( 'href' => array() ) ) )
	);

	wp_print_inline_script_tag( "document.getElementById( 'undergrowth-notice' ).focus();" );
}
