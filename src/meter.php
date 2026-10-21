<?php
/**
 * The threshold meter: page views in the last 30 days, from Jetpack Stats,
 * against the switch line for reader mode.
 *
 * The meter shows one number and nothing else. Reader mode is not built yet,
 * so crossing the line changes nothing, and the widget says so. Undergrowth
 * only asks Jetpack, which makes its own requests to WordPress.com.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

use Automattic\Jetpack\Stats\WPCOM_Stats;

/*
 * The visible half of the mixed switch line (handover section 4). The other
 * half, a check before each reader suggestion that its pages have enough
 * visits, comes with reader mode.
 */
if ( ! defined( 'UNDERGROWTH_METER_LINE' ) ) {
	define( 'UNDERGROWTH_METER_LINE', 1000 );
}

add_action( 'wp_dashboard_setup', 'undergrowth_add_meter_widget' );

/**
 * Returns the number of views in 30 days that marks the switch line.
 *
 * @return int At least 1.
 */
function undergrowth_meter_line(): int {
	/**
	 * Filters the number of page views in the last 30 days that the threshold
	 * meter counts towards.
	 *
	 * @param int $line Views in 30 days. Default UNDERGROWTH_METER_LINE.
	 */
	$default = undergrowth_meter_sanitize_line( (int) UNDERGROWTH_METER_LINE );
	$line    = (int) apply_filters( 'undergrowth_meter_line', $default );

	return $line < 1 ? $default : $line;
}

/**
 * Replaces a switch line below one view with 1,000.
 *
 * A site can define UNDERGROWTH_METER_LINE itself, and a line of zero would
 * show it crossed with no readers at all.
 *
 * @param int $line Views in 30 days.
 * @return int At least 1.
 */
function undergrowth_meter_sanitize_line( int $line ): int {
	return $line < 1 ? 1000 : $line;
}

/**
 * Tells whether Jetpack is connected and has its Stats module on.
 *
 * @return bool
 */
function undergrowth_meter_has_stats(): bool {
	return class_exists( 'Jetpack' )
		&& class_exists( WPCOM_Stats::class )
		&& Jetpack::is_module_active( 'stats' )
		&& Jetpack::is_connection_ready();
}

/**
 * Asks Jetpack Stats for the site's page views over the last 30 days.
 *
 * @return int|null The views, or null when Stats gave no usable answer.
 */
function undergrowth_meter_fetch_views(): ?int {
	$visits = ( new WPCOM_Stats() )->get_visits(
		array(
			'unit'     => 'day',
			'quantity' => 30,
		)
	);

	if ( ! is_array( $visits ) || ! isset( $visits['fields'], $visits['data'] ) || ! is_array( $visits['fields'] ) || ! is_array( $visits['data'] ) ) {
		return null;
	}

	$column = array_search( 'views', $visits['fields'], true );

	if ( false === $column ) {
		return null;
	}

	$views = 0;

	foreach ( $visits['data'] as $row ) {
		if ( is_array( $row ) && isset( $row[ $column ] ) ) {
			$views += (int) $row[ $column ];
		}
	}

	return $views;
}

/**
 * Reads the meter, asking Jetpack at most once a day.
 *
 * A missing Jetpack is not cached, so the meter appears as soon as Stats is
 * on. A failed answer is cached like a count, so Jetpack is not asked again
 * on every Dashboard load.
 *
 * @return array{state: string, views: int, line: int} State is 'no-stats',
 *         'no-answer', 'below' or 'crossed'.
 */
function undergrowth_meter_reading(): array {
	$line = undergrowth_meter_line();

	if ( ! undergrowth_meter_has_stats() ) {
		return array(
			'state' => 'no-stats',
			'views' => 0,
			'line'  => $line,
		);
	}

	$cached = get_transient( 'undergrowth_meter' );

	// The transient expires after a day, but a persistent object cache may
	// keep it longer, so its own time is checked too.
	if ( ! is_array( $cached ) || ! isset( $cached['time'] ) || ! array_key_exists( 'views', $cached ) || time() - (int) $cached['time'] >= DAY_IN_SECONDS ) {
		$cached = array(
			'views' => undergrowth_meter_fetch_views(),
			'time'  => time(),
		);
		set_transient( 'undergrowth_meter', $cached, DAY_IN_SECONDS );
	}

	if ( ! is_int( $cached['views'] ) ) {
		return array(
			'state' => 'no-answer',
			'views' => 0,
			'line'  => $line,
		);
	}

	return array(
		'state' => $cached['views'] >= $line ? 'crossed' : 'below',
		'views' => $cached['views'],
		'line'  => $line,
	);
}

/**
 * Tells whether the current user may see the meter.
 *
 * Without Jetpack Stats there is no count, so anyone who can see the Link
 * suggestions screen is told what the meter needs. With Stats on, the count
 * is Stats data, so it follows Jetpack's own setting for who may view it.
 *
 * @return bool
 */
function undergrowth_meter_user_can_see(): bool {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return false;
	}

	return ! undergrowth_meter_has_stats() || current_user_can( 'view_stats' );
}

/**
 * Adds the meter widget for users who may see it.
 */
function undergrowth_add_meter_widget(): void {
	if ( ! undergrowth_meter_user_can_see() ) {
		return;
	}

	wp_add_dashboard_widget( 'undergrowth_meter', __( 'Readers, last 30 days', 'undergrowth' ), 'undergrowth_render_meter_widget' );
}

/**
 * Prints the meter: one number against the line, or what it needs to show
 * one.
 */
function undergrowth_render_meter_widget(): void {
	if ( ! undergrowth_meter_user_can_see() ) {
		return;
	}

	$reading = undergrowth_meter_reading();
	$views   = number_format_i18n( $reading['views'] );
	$line    = number_format_i18n( $reading['line'] );
	$later   = __( 'When they arrive, each suggestion from reader data will also need enough visits on the pages it draws on, checked before it is shown.', 'undergrowth' );

	if ( 'no-stats' === $reading['state'] ) {
		echo '<p>' . esc_html__( 'This meter counts page views from Jetpack Stats. To see it, install Jetpack, connect it to WordPress.com and turn on Stats.', 'undergrowth' ) . '</p>';

		return;
	}

	if ( 'no-answer' === $reading['state'] ) {
		echo '<p>' . esc_html__( 'Jetpack Stats did not answer, so there is no count today. Undergrowth will ask again tomorrow.', 'undergrowth' ) . '</p>';

		return;
	}

	if ( 'crossed' === $reading['state'] ) {
		/* translators: 1: views in the last 30 days, 2: the switch line. Both are formatted numbers. */
		$status = sprintf( __( '%1$s views in the last 30 days. Your site has passed the line of %2$s.', 'undergrowth' ), $views, $line );
		$rules  = __( 'Reader features are not part of this version yet, so nothing changes for now.', 'undergrowth' );
	} else {
		/* translators: 1: views in the last 30 days, 2: the switch line. Both are formatted numbers. */
		$status = sprintf( __( '%1$s of %2$s views in the last 30 days.', 'undergrowth' ), $views, $line );
		/* translators: %s: the switch line, a formatted number of views. */
		$rules = sprintf( __( 'Reader features, which will suggest links from the paths readers take between your posts, wait for %s views in 30 days. They are not part of this version yet.', 'undergrowth' ), $line );
	}
	?>
	<p><strong><?php echo esc_html( $status ); ?></strong></p>
	<p class="description"><?php echo esc_html( $rules ); ?></p>
	<p class="description"><?php echo esc_html( $later ); ?></p>
	<p class="description"><?php esc_html_e( 'Counted from Jetpack Stats once a day.', 'undergrowth' ); ?></p>
	<?php
}
