<?php
/**
 * Order paid → rounds, credit coupons, free rounds per patch order, service start. Order refunded/cancelled → lots
 * withdrawn, coupons out of use, services cancelled (SPEC-AI-DIENSTEN §11.3).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent per order item (flag `_bps_ai_fulfilled`): payment_complete, processing and completed may all fire.
 */
final class Fulfilment {

	const ITEM_FLAG  = '_bps_ai_fulfilled';
	const ORDER_FLAG = '_bps_ai_free_order';

	/** @var Credits|null Tests: credits instance. */
	public static $credits = null;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'fulfil' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'fulfil' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'fulfil' ), 20 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'cancel_all' ), 20 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'cancel_all' ), 20 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refund' ), 20, 2 );
	}

	/**
	 * Credits in use.
	 */
	private static function credits(): Credits {
		return self::$credits ? self::$credits : Credits::instance();
	}

	/**
	 * Is an order item a patch line (made with the studio)?
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 */
	public static function is_patch_item( $item ): bool {
		if ( ! method_exists( $item, 'get_product_id' ) ) {
			return false;
		}
		if ( $item->get_meta( '_bps_design_id' ) ) {
			return true;
		}
		return in_array( (int) $item->get_product_id(), Order_Credit::patch_product_ids(), true );
	}

	/**
	 * Paid price of an item incl. VAT.
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 */
	public static function paid_incl( $item ): float {
		return Config::round_half_up( (float) $item->get_total() + (float) $item->get_total_tax(), 2 );
	}

	/**
	 * Order paid (or moved to processing/completed).
	 *
	 * @param int $order_id Order id.
	 * @return array{packs:int, services:int, freeOrder:bool} What happened now (tests, logs).
	 */
	public static function fulfil( $order_id ): array {
		$done  = array(
			'packs'     => 0,
			'services'  => 0,
			'freeOrder' => false,
		);
		$order = is_object( $order_id ) ? $order_id : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null );
		if ( ! $order || ( method_exists( $order, 'is_paid' ) && ! $order->is_paid() ) ) {
			return $done;
		}
		$owner   = Owner::for_order( $order );
		$credits = self::credits();
		$record  = $order->get_meta( Checkout::PACK_META );
		$consent = Checkout::pack_consented( $record ) ? (string) $record['at'] : null;
		$patch   = false;

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( self::is_patch_item( $item ) ) {
				// Only a patch line that was actually paid for earns free rounds (a 100 % coupon order does not).
				$patch = $patch || self::paid_incl( $item ) > 0;
				continue;
			}
			if ( $item->get_meta( self::ITEM_FLAG ) ) {
				continue;
			}
			$what = Products::lookup( (int) $item->get_product_id() );
			if ( ! $what ) {
				continue;
			}
			if ( 'pack' === $what['type'] ) {
				if ( self::fulfil_pack( $order, $item, (int) $item_id, $what['id'], $owner, $consent, $credits ) ) {
					++$done['packs'];
				}
				continue;
			}
			// Services.
			if ( 'd6_sample' === $what['id'] ) {
				Order_Credit::create( $order, $item, 'sample', self::paid_incl( $item ) );
			}
			Service_Status::start( $order, $item, true );
			$item->update_meta_data( self::ITEM_FLAG, gmdate( 'Y-m-d H:i:s' ) );
			$item->save();
			++$done['services'];
		}

		// Free rounds per paid patch order, once per order.
		if ( $patch && Owner::valid( $owner ) && ! $order->get_meta( self::ORDER_FLAG ) ) {
			$lot = $credits->grant_free_order( $owner, (int) $order->get_id() );
			if ( $lot ) {
				$order->update_meta_data( self::ORDER_FLAG, $lot );
				$order->save();
				$done['freeOrder'] = true;
			}
		}

		// The buyer's anonymous browser rounds flow into the order owner (after the grants, so nothing is lost).
		$hint = (string) $order->get_meta( Checkout::OWNER_HINT );
		if ( '' !== $hint && Owner::valid( $owner ) && 'a' === Owner::kind( $hint ) && $hint !== $owner ) {
			Owner::merge_into( $hint, $owner, $credits );
			$order->delete_meta_data( Checkout::OWNER_HINT );
			$order->save();
		}
		if ( Owner::valid( $owner ) && 'e' === Owner::kind( $owner ) ) {
			// The customer paid with this address: treat it as verified (no AI data is ever sent with it).
			Owner::remember( $owner, null, (string) $order->get_billing_email(), true );
		}
		return $done;
	}

	/**
	 * One pack line: lot + item meta + credit coupon.
	 *
	 * @param \WC_Order              $order   Order.
	 * @param \WC_Order_Item_Product $item    Item.
	 * @param int                    $item_id Item id.
	 * @param string                 $pack_id Pack id.
	 * @param string                 $owner   Owner key.
	 * @param string|null            $consent Consent time or null.
	 * @param Credits                $credits Credits.
	 */
	private static function fulfil_pack( $order, $item, int $item_id, string $pack_id, string $owner, ?string $consent, Credits $credits ): bool {
		$pack = Config::pack( $pack_id );
		if ( ! $pack || ! Owner::valid( $owner ) ) {
			if ( method_exists( $order, 'add_order_note' ) ) {
				$order->add_order_note( 'Patch Studio: ontwerprondes konden niet toegekend worden (pakket of klant onbekend). Ken ze manueel toe.' );
			}
			return false;
		}
		$qty    = max( 1, (int) $item->get_quantity() );
		$rounds = $pack['rounds'] * $qty;
		$paid   = self::paid_incl( $item );
		$lot    = $credits->grant(
			$owner,
			'pack',
			$rounds,
			array(
				'order_id'      => (int) $order->get_id(),
				'order_item_id' => $item_id,
				'pack_id'       => $pack_id,
				'price_incl'    => $paid,
				'consent_at'    => $consent,
			)
		);
		if ( ! $lot ) {
			return false;
		}
		$row  = $credits->store()->get_lot( $lot );
		$code = Order_Credit::create( $order, $item, 'pack', $paid );
		$item->update_meta_data(
			'_bps_ai_pack',
			array(
				'packId'    => $pack_id,
				'rounds'    => $rounds,
				'lotId'     => $lot,
				'ownerKey'  => $owner,
				'expiresAt' => $row ? (string) $row['expires_at'] : '',
			)
		);
		if ( '' !== $code ) {
			$item->update_meta_data( '_bps_ai_coupon', $code );
		}
		$item->update_meta_data( self::ITEM_FLAG, gmdate( 'Y-m-d H:i:s' ) );
		$item->save();
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note(
				sprintf(
					'Patch Studio: %d ontwerprondes toegekend%s%s.',
					$rounds,
					$consent ? '' : ' (bruikbaar na 14 dagen of na toestemming)',
					'' !== $code ? ', kortingscode ' . $code : ''
				)
			);
		}
		return true;
	}

	/**
	 * Whole order cancelled or refunded: withdraw all pack lots, coupons out of use, services cancelled.
	 *
	 * @param int $order_id Order id.
	 * @return int Lots withdrawn.
	 */
	public static function cancel_all( $order_id ): int {
		$order = is_object( $order_id ) ? $order_id : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null );
		if ( ! $order ) {
			return 0;
		}
		$n = 0;
		// The free rounds this order earned go too (otherwise order + cancel = free AI rounds, again and again).
		$free_lot = (int) $order->get_meta( self::ORDER_FLAG );
		if ( $free_lot > 0 ) {
			self::credits()->withdraw( $free_lot, 'manual', 'refunded' );
		}
		foreach ( $order->get_items() as $item ) {
			if ( self::end_pack_item( $item ) ) {
				++$n;
			}
			$info = Service_Status::of_item( $item );
			if ( $info && '' !== $info['status'] && ! in_array( $info['status'], Service_Status::FINAL, true ) ) {
				Service_Status::set( $order, $item, 'cancelled', '', true );
			}
			if ( $info && 'd6_sample' === $info['id'] ) {
				Order_Credit::expire( (string) $item->get_meta( '_bps_ai_coupon' ) );
			}
		}
		return $n;
	}

	/**
	 * Partial refund: only pack lines that were part of this refund end their lot.
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 * @return int Lots withdrawn.
	 */
	public static function on_refund( $order_id, $refund_id ): int {
		$order  = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$refund = function_exists( 'wc_get_order' ) ? wc_get_order( $refund_id ) : null;
		if ( ! $order || ! $refund ) {
			return 0;
		}
		$refunded_items = array();
		foreach ( $refund->get_items() as $ritem ) {
			$orig = (int) $ritem->get_meta( '_refunded_item_id' );
			if ( $orig > 0 ) {
				$refunded_items[ $orig ] = true;
			}
		}
		$n = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( isset( $refunded_items[ (int) $item_id ] ) && self::end_pack_item( $item ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * End the lot of a pack item (status refunded) and take its coupon out of use. Returns true when a lot ended now.
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 */
	public static function end_pack_item( $item ): bool {
		$meta = $item->get_meta( '_bps_ai_pack' );
		if ( ! is_array( $meta ) || empty( $meta['lotId'] ) ) {
			return false;
		}
		$res = self::credits()->withdraw( (int) $meta['lotId'], 'manual', 'refunded' );
		Order_Credit::expire( (string) $item->get_meta( '_bps_ai_coupon' ) );
		return null !== $res;
	}
}
