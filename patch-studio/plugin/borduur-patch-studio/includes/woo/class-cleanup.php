<?php
/**
 * Daily retention cleanup (SPEC §6.1, research 08 §2.3).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * draft/carted  → removed after retention.orphanDays since creation
 * saved         → after retention.savedDays since the last opening of the link
 * quoted        → after retention.quoteDays since creation (quote post keeps the contact data)
 * ordered       → files removed after retention.orderYears since the last order; the order line
 *                 keeps a visible note "Ontwerpbestand verwijderd op {datum}".
 */
final class Cleanup {

	const HOOK  = 'bps_cleanup';
	const BATCH = 50;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
	}

	/**
	 * Activation.
	 */
	public static function activate(): void {
		self::schedule();
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::HOOK, array( 1 ) );
	}

	/**
	 * Schedule daily at ~03:17 site time if missing.
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		$tz    = wp_timezone();
		$first = new \DateTimeImmutable( 'tomorrow 03:17', $tz );
		wp_schedule_event( $first->getTimestamp(), 'daily', self::HOOK );
	}

	/**
	 * Run (also callable manually). Returns counts per bucket.
	 *
	 * @param int $continuation 1 when this is a follow-up batch.
	 * @return array<string,int>
	 */
	public static function run( $continuation = 0 ): array {
		$r      = (array) Config::path( 'retention', array() );
		$now    = time();
		$counts = array(
			'orphan'  => 0,
			'saved'   => 0,
			'quoted'  => 0,
			'ordered' => 0,
		);

		$orphan_days = max( 1, (int) ( $r['orphanDays'] ?? 30 ) );
		$saved_days  = max( 1, (int) ( $r['savedDays'] ?? 90 ) );
		$quote_days  = max( 1, (int) ( $r['quoteDays'] ?? 365 ) );
		$order_years = max( 1, (int) ( $r['orderYears'] ?? 3 ) );

		// Orphans: never ordered, quoted or saved.
		$ids = Designs::query(
			array(
				'relation' => 'AND',
				array(
					'key'     => '_bps_status',
					'value'   => array( 'draft', 'carted' ),
					'compare' => 'IN',
				),
				array(
					'key'     => '_bps_created_gmt',
					'value'   => gmdate( 'Y-m-d H:i:s', $now - $orphan_days * DAY_IN_SECONDS ),
					'compare' => '<',
					'type'    => 'DATETIME',
				),
			),
			self::BATCH
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true ); // Files go via Designs::on_delete_post.
			++$counts['orphan'];
		}

		// Saved designs: counted from the last opening.
		$ids = Designs::query(
			array(
				'relation' => 'AND',
				array(
					'key'   => '_bps_status',
					'value' => 'saved',
				),
				array(
					'key'     => '_bps_last_opened_gmt',
					'value'   => gmdate( 'Y-m-d H:i:s', $now - $saved_days * DAY_IN_SECONDS ),
					'compare' => '<',
					'type'    => 'DATETIME',
				),
			),
			self::BATCH
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
			++$counts['saved'];
		}

		// Quoted designs: files go, the quote post (lead) is kept with a pointer to the deleted design.
		$ids = Designs::query(
			array(
				'relation' => 'AND',
				array(
					'key'   => '_bps_status',
					'value' => 'quoted',
				),
				array(
					'key'     => '_bps_created_gmt',
					'value'   => gmdate( 'Y-m-d H:i:s', $now - $quote_days * DAY_IN_SECONDS ),
					'compare' => '<',
					'type'    => 'DATETIME',
				),
			),
			self::BATCH
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
			++$counts['quoted'];
		}

		// Ordered designs: only the files, after N years since the (last) order.
		$ids = Designs::query(
			array(
				'relation' => 'AND',
				array(
					'key'   => '_bps_status',
					'value' => 'ordered',
				),
				array(
					'key'     => '_bps_ordered_gmt',
					'value'   => gmdate( 'Y-m-d H:i:s', strtotime( '-' . $order_years . ' years', $now ) ),
					'compare' => '<',
					'type'    => 'DATETIME',
				),
				array(
					'key'     => '_bps_files_removed_gmt',
					'compare' => 'NOT EXISTS',
				),
			),
			self::BATCH
		);
		foreach ( $ids as $id ) {
			self::expire_ordered( $id );
			++$counts['ordered'];
		}

		do_action( 'bps_cleanup_done', $counts );
		// More left? Continue in a few minutes instead of one long request.
		if ( max( $counts ) >= self::BATCH && ! wp_next_scheduled( self::HOOK, array( 1 ) ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::HOOK, array( 1 ) );
		}
		return $counts;
	}

	/**
	 * Remove files of an ordered design and leave a note on the order line.
	 *
	 * @param int $id Design id.
	 */
	public static function expire_ordered( int $id ): void {
		Designs::delete_files( $id, false );
		$order_id = (int) get_post_meta( $id, '_bps_order_id', true );
		$item_id  = (int) get_post_meta( $id, '_bps_order_item_id', true );
		$order    = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$item = $order->get_item( $item_id );
		if ( $item instanceof \WC_Order_Item_Product ) {
			/* translators: %s: date */
			$item->update_meta_data( 'bps_file_removed', sprintf( __( 'Ontwerpbestand verwijderd op %s (bewaartermijn)', 'borduur-patch-studio' ), wp_date( 'd-m-Y' ) ) );
			$item->update_meta_data( '_bps_preview_url', '' );
			$item->save();
		}
	}
}
