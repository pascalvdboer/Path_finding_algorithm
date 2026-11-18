<?php
/**
 * Order lifecycle for retention: release designs of cancelled/failed orders, clean up when WooCommerce anonymises
 * or deletes an order (premium P03, gdpr-juridisch-5).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * - woocommerce_order_status_cancelled / _failed: designs that were marked "ordered" by this order go back to
 *   "carted" with a fresh _bps_created_gmt, so the orphan period (retention.orphanDays) starts now. Paying the order
 *   later marks them ordered again (Order::mark_ordered_by_id on payment_complete).
 * - woocommerce_privacy_remove_order_personal_data / woocommerce_before_delete_order: the designs of that order
 *   expire right away (original removed, personal data wiped, see Cleanup::expire_ordered).
 * - is_finished(): one definition of "this order no longer needs the files" for the privacy erasers.
 */
final class Order_Lifecycle {

	/** Statuses after which an order is final. */
	const FINAL_STATUSES = array( 'completed', 'cancelled', 'refunded', 'failed' );

	/** A pending (unpaid) order older than this many days counts as finished. */
	const PENDING_DAYS = 14;

	/** @var bool */
	private static $registered = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'release_designs' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'release_designs' ), 20, 1 );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( __CLASS__, 'expire_order_designs' ), 20, 1 );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'expire_order_designs' ), 20, 1 );
	}

	/**
	 * Has the order reached a state in which its design files are no longer needed to fulfil it?
	 * Final statuses, or a pending order older than PENDING_DAYS (filter `bps_pending_order_days`).
	 *
	 * @param mixed $order Order.
	 */
	public static function is_finished( $order ): bool {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) ) {
			return true; // Order gone: nothing left to fulfil.
		}
		$status = (string) $order->get_status();
		if ( in_array( $status, self::FINAL_STATUSES, true ) ) {
			return true;
		}
		if ( 'pending' === $status ) {
			$days    = max( 1, (int) apply_filters( 'bps_pending_order_days', self::PENDING_DAYS ) );
			$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
			$ts      = $created ? (int) $created->getTimestamp() : 0;
			return $ts > 0 && $ts < time() - $days * DAY_IN_SECONDS;
		}
		return false;
	}

	/**
	 * Design ids on the lines of an order.
	 *
	 * @param mixed $order Order.
	 * @return int[]
	 */
	public static function design_ids( $order ): array {
		$ids = array();
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return $ids;
		}
		foreach ( $order->get_items() as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_meta' ) ) {
				$id = (int) $item->get_meta( '_bps_design_id' );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Order object from an id or an object.
	 *
	 * @param mixed $order Order or id.
	 * @return \WC_Order|null
	 */
	private static function order( $order ) {
		if ( is_object( $order ) && method_exists( $order, 'get_items' ) ) {
			return $order;
		}
		$id = is_numeric( $order ) ? (int) $order : 0;
		$o  = $id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
		return is_object( $o ) ? $o : null;
	}

	/**
	 * Cancelled / failed: designs linked to THIS order go back to "carted" with a new creation time.
	 *
	 * @param mixed $order_id Order id (or order).
	 */
	public static function release_designs( $order_id ): void {
		try {
			$order = self::order( $order_id );
			if ( ! $order ) {
				return;
			}
			$oid = (int) $order->get_id();
			foreach ( self::design_ids( $order ) as $design_id ) {
				if ( 'ordered' !== get_post_meta( $design_id, '_bps_status', true ) || (int) get_post_meta( $design_id, '_bps_order_id', true ) !== $oid ) {
					continue; // Never touch a design that a later (other) order owns.
				}
				$now = gmdate( 'Y-m-d H:i:s' );
				// Direct meta write: Designs::set_status() never downgrades by design.
				update_post_meta( $design_id, '_bps_status', 'carted' );
				update_post_meta( $design_id, '_bps_status_changed_gmt', $now );
				update_post_meta( $design_id, '_bps_created_gmt', $now );
				update_post_meta( $design_id, '_bps_order_released_gmt', $now );
				delete_post_meta( $design_id, '_bps_order_id' );
				delete_post_meta( $design_id, '_bps_order_item_id' );
				delete_post_meta( $design_id, '_bps_ordered_gmt' );
				do_action( 'bps_design_released', $design_id, $order );
			}
		} catch ( \Throwable $e ) {
			self::log( 'order_release_failed', array( 'exception' => $e ) );
		}
	}

	/**
	 * WooCommerce anonymises or deletes an order: expire its designs now and drop the e-mail.
	 *
	 * @param mixed $order Order or id.
	 */
	public static function expire_order_designs( $order ): void {
		try {
			$order = self::order( $order );
			if ( ! $order ) {
				return;
			}
			$oid = (int) $order->get_id();
			foreach ( self::design_ids( $order ) as $design_id ) {
				$linked = (int) get_post_meta( $design_id, '_bps_order_id', true );
				if ( $linked > 0 && $linked !== $oid ) {
					continue; // The design belongs to a later order now.
				}
				if ( ! get_post_meta( $design_id, '_bps_files_removed_gmt', true ) || get_post_meta( $design_id, '_bps_email', true ) ) {
					Cleanup::expire_ordered( $design_id );
				}
				delete_post_meta( $design_id, '_bps_email' );
			}
		} catch ( \Throwable $e ) {
			self::log( 'order_expire_failed', array( 'exception' => $e ) );
		}
	}

	/**
	 * Log through the plugin Logger (contract C1) when available.
	 *
	 * @param string $event   Event.
	 * @param array  $context Context.
	 */
	private static function log( string $event, array $context = array() ): void {
		if ( class_exists( '\BorduurPatchStudio\Logger' ) ) {
			\BorduurPatchStudio\Logger::error( $event, $context );
		}
	}
}
