<?php
/**
 * Converting vectors stored as JSON, as versions before 8 of the schema
 * stored them, to packed floats, in cron batches.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

const UNDERGROWTH_PACK_BATCH = 200;

add_action( 'undergrowth_pack_vectors', 'undergrowth_run_pack_vectors' );
add_action( 'undergrowth_backfill_check', 'undergrowth_queue_pack_vectors' );

/**
 * Returns how many rows one conversion batch converts.
 *
 * @return int Rows per batch, at least one.
 */
function undergrowth_pack_batch_size(): int {
	/**
	 * Filters how many vectors stored as JSON one cron batch converts.
	 *
	 * @param int $size Rows per batch. Default 200.
	 */
	return max( 1, (int) apply_filters( 'undergrowth_pack_batch_size', UNDERGROWTH_PACK_BATCH ) );
}

/**
 * Tells whether any vector is still stored as JSON.
 *
 * @return bool
 */
function undergrowth_has_json_vectors(): bool {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every embed.
	return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE packed IS NULL LIMIT 1', undergrowth_vectors_table() ) );
}

/**
 * Queues the conversion while rows stored as JSON are left and none is
 * queued.
 *
 * Runs after a schema upgrade, and from the hourly check, which starts a
 * conversion again if a batch died before it queued the next.
 */
function undergrowth_queue_pack_vectors(): void {
	if ( false === wp_next_scheduled( 'undergrowth_pack_vectors' ) && undergrowth_has_json_vectors() ) {
		wp_schedule_single_event( time(), 'undergrowth_pack_vectors' );
	}
}

/**
 * Converts one batch of rows stored as JSON, then queues the next.
 *
 * Batches run one at a time, under a lock. Each row is converted on its
 * own, by its ID, so a batch cut short, or run twice, leaves every row
 * either as it was or converted. The provider, model, language, content
 * hash and time read stay as they were. A row whose JSON cannot be packed,
 * because it is not a vector or holds a value too large for single
 * precision, keeps its JSON and is marked with an empty packed value. It
 * then reads as it did before, and is not tried again.
 */
function undergrowth_run_pack_vectors(): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	// A lock left by a batch that died expires by the time the hourly check
	// would queue it again. A batch that finds it taken tries in a minute.
	if ( ! WP_Upgrader::create_lock( 'undergrowth_pack_vectors', UNDERGROWTH_PASS_STALL ) ) {
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'undergrowth_pack_vectors' );
		return;
	}

	try {
		$size  = undergrowth_pack_batch_size();
		$table = undergrowth_vectors_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; rows change on every embed.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, embedding FROM %i WHERE packed IS NULL ORDER BY id LIMIT %d', $table, $size ), ARRAY_A );

		$wpdb->flush();
		$fetched   = count( $rows );
		$converted = 0;

		while ( array() !== $rows ) {
			$row    = array_shift( $rows );
			$packed = undergrowth_pack_vector( undergrowth_decode_embedding( (string) $row['embedding'] ) );
			$data   = '' === $packed ? array( 'packed' => '' ) : array(
				'packed'    => $packed,
				'embedding' => '',
			);

			// A save meanwhile replaces the row with a packed one of a new
			// ID, which this leaves alone. update() checks each value against
			// its column, so the bytes reach a binary column untouched, where
			// a query of its own would be checked as text as a whole.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Converting the plugin's own table.
			$converted += (int) $wpdb->update(
				$table,
				$data,
				array( 'id' => (int) $row['id'] ),
				array_fill( 0, count( $data ), '%s' ),
				array( '%d' )
			);
		}

		// A full batch that converted nothing would only fail again at once,
		// so the hourly check tries it later instead.
		if ( $size === $fetched && $converted > 0 ) {
			wp_schedule_single_event( time(), 'undergrowth_pack_vectors' );
		}
	} finally {
		WP_Upgrader::release_lock( 'undergrowth_pack_vectors' );
	}
}
