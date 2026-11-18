<?php
/**
 * Reordering patch lines (woo-compat-3, cart part of klant-account-5): WooCommerce "Order again" and a helper for the
 * one-click reorder route. Every reorder gets a NEW design record (contract C3: Designs::clone), so the old order
 * stays linked to the old design and the production sheet can say "reorder, reuse the existing embroidery file".
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

use BorduurPatchStudio\Logger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Flow of WC_Cart_Session::populate_cart_from_order():
 * 1. woocommerce_order_again_cart_item_data → we rebuild `bps` from the order line meta and mark it with a
 *    one-time token (no design is created yet: the validation may still refuse the line);
 * 2. woocommerce_add_to_cart_validation → Product::block_direct_add() lets the line pass only with that token;
 * 3. woocommerce_add_order_again_cart_item → the line is accepted: now the design is cloned;
 * 4. woocommerce_ordered_again → lines whose clone failed are removed with a clear notice.
 * The price is always the current server price (Cart::set_prices on cart load / calculate_totals).
 */
final class Order_Again {

	/** @var bool True while WooCommerce rebuilds a cart from an order. */
	public static $adding = false;

	/** @var array<string,bool> One-time tokens of lines rebuilt in this request. */
	private static $tokens = array();

	/** @var bool */
	private static $registered = false;

	/**
	 * Hooks (called from Woo\Cart::register()).
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_filter( 'woocommerce_order_again_cart_item_data', array( __CLASS__, 'cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_add_order_again_cart_item', array( __CLASS__, 'finalize' ), 10, 2 );
		add_action( 'woocommerce_ordered_again', array( __CLASS__, 'remove_failed' ), 10, 3 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'my_orders_action' ), 10, 2 );
	}

	/**
	 * Step 1: rebuild the cart data of a patch line from the order line.
	 *
	 * @param mixed $data  Cart item data.
	 * @param mixed $item  Order line.
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public static function cart_item_data( $data, $item, $order = null ) {
		if ( ! $item instanceof \WC_Order_Item_Product || '' === (string) $item->get_meta( '_bps_technique_id' ) ) {
			return $data;
		}
		$bps = self::bps_from_item( $item, $order instanceof \WC_Order ? $order : $item->get_order() );
		if ( null === $bps ) {
			return $data;
		}
		$token                  = bin2hex( random_bytes( 8 ) );
		self::$tokens[ $token ] = true;
		self::$adding           = true;
		$bps['rebuild']         = $token;
		$data                   = is_array( $data ) ? $data : array();
		$data['bps']            = $bps;
		return $data;
	}

	/**
	 * Did step 1 build this cart data in this request? (Product::block_direct_add)
	 *
	 * @param array $data Cart item data.
	 */
	public static function is_rebuilt( array $data ): bool {
		$token = isset( $data['bps']['rebuild'] ) ? (string) $data['bps']['rebuild'] : '';
		return '' !== $token && isset( self::$tokens[ $token ] );
	}

	/**
	 * Cart bps data from an order line (null when nothing usable is left).
	 *
	 * @param \WC_Order_Item_Product $item  Order line.
	 * @param \WC_Order|null         $order Order.
	 */
	public static function bps_from_item( \WC_Order_Item_Product $item, $order = null ): ?array {
		$old_id  = (int) $item->get_meta( '_bps_design_id' );
		$payload = self::payload_for( $item );
		if ( ! $payload ) {
			Logger::warning(
				'reorder_no_payload',
				array(
					'order'  => $order instanceof \WC_Order ? (int) $order->get_id() : 0,
					'item'   => (int) $item->get_id(),
					'design' => $old_id,
				)
			);
			return null;
		}
		$options = json_decode( (string) $item->get_meta( '_bps_options' ), true );
		$options = is_array( $options ) ? $options : (array) ( $payload['options'] ?? array() );
		$locale  = (string) ( $payload['locale'] ?? '' );
		$ref     = (string) $item->get_meta( 'bps_ref' );
		$consent = $item->get_meta( '_bps_rights_consent' );
		$consent = is_string( $consent ) ? json_decode( $consent, true ) : $consent;
		$bps     = array(
			'v'           => 1,
			'design_id'   => 0,
			'ref'         => $ref,
			'technique'   => (string) $item->get_meta( '_bps_technique_id' ),
			'size_cm'     => (float) $item->get_meta( '_bps_size_cm' ),
			'width_mm'    => (float) ( $payload['widthMm'] ?? 0 ),
			'height_mm'   => (float) ( $payload['heightMm'] ?? 0 ),
			'shape'       => (string) $item->get_meta( '_bps_shape_id' ),
			'options'     => array_values( array_map( 'strval', $options ) ),
			'texts'       => Cart::texts_brief( (array) ( $payload['texts'] ?? array() ) ),
			'preview_url' => (string) $item->get_meta( '_bps_preview_url' ),
			'locale'      => '' !== $locale ? $locale : 'nl-BE',
			// The customer confirmed the rights for this design with the original order; that record is carried over.
			'rights'      => array(
				'v'      => (string) Config::path( 'legal.rightsTextVersion', '' ),
				'at'     => is_array( $consent ) && ! empty( $consent['at'] ) ? (string) $consent['at'] : gmdate( 'Y-m-d\TH:i:s\Z' ),
				'locale' => Config::wp_locale( $locale ),
				'source' => 'reorder',
			),
			'reorder_of'  => Cart::reorder_ref(
				array(
					'design_id' => $old_id,
					'order_id'  => $order instanceof \WC_Order ? (int) $order->get_id() : (int) $item->get_order_id(),
					'ref'       => $ref,
				)
			),
		);
		$names = Variant_Names::names( $payload );
		if ( $names ) {
			$bps['variants'] = count( $names );
		}
		$bps['_payload'] = $payload; // Removed again in finalize(); needed to clone when the design is gone.
		return $bps;
	}

	/**
	 * Design payload of an order line: the order snapshot (_bps_payload), else the design record.
	 *
	 * @param \WC_Order_Item_Product $item Order line.
	 */
	public static function payload_for( \WC_Order_Item_Product $item ): array {
		$payload = json_decode( (string) $item->get_meta( '_bps_payload' ), true );
		if ( is_array( $payload ) && ! empty( $payload['technique'] ) ) {
			return $payload;
		}
		$rec = Designs::get( (int) $item->get_meta( '_bps_design_id' ) );
		return $rec && ! empty( $rec['payload'] ) ? (array) $rec['payload'] : array();
	}

	/**
	 * Step 3: the line passed validation; create the new design record.
	 *
	 * @param mixed  $cart_item Cart item.
	 * @param string $cart_id   Cart key.
	 * @return mixed
	 */
	public static function finalize( $cart_item, $cart_id = '' ) {
		if ( ! is_array( $cart_item ) || empty( $cart_item['bps']['rebuild'] ) ) {
			return $cart_item;
		}
		$bps     = $cart_item['bps'];
		$payload = (array) ( $bps['_payload'] ?? array() );
		unset( $bps['rebuild'], $bps['_payload'] );
		$new = self::clone_design( (int) $bps['reorder_of']['design_id'], (array) $bps['reorder_of'], $payload, 'carted' );
		if ( is_wp_error( $new ) ) {
			$bps['rebuild_failed'] = true;
		} else {
			$rec                = Designs::get( (int) $new );
			$bps['design_id']   = (int) $new;
			$bps['ref']         = $rec ? (string) $rec['ref'] : $bps['ref'];
			$bps['preview_url'] = $rec && '' !== (string) $rec['preview_url'] ? (string) $rec['preview_url'] : $bps['preview_url'];
		}
		$cart_item['bps'] = $bps;
		return $cart_item;
	}

	/**
	 * Step 4: drop lines whose design could not be recreated (they would have no production data).
	 *
	 * @param int   $order_id Order id.
	 * @param array $items    Order items.
	 * @param array $cart     Cart (by reference).
	 */
	public static function remove_failed( $order_id, $items = array(), &$cart = array() ): void {
		self::$adding = false;
		if ( ! is_array( $cart ) ) {
			return;
		}
		foreach ( $cart as $key => $line ) {
			if ( ! empty( $line['bps']['rebuild_failed'] ) ) {
				unset( $cart[ $key ] );
				wc_add_notice(
					esc_html(
						sprintf(
							/* translators: %s: design reference */
							__( 'We konden ontwerp %s niet opnieuw klaarzetten. Neem contact met ons op, dan helpen we je verder.', 'borduur-patch-studio' ),
							(string) ( $line['bps']['ref'] ?? '' )
						)
					),
					'error'
				);
			}
		}
	}

	/**
	 * New design record for a reorder (contract C3). Falls back to Designs::create() as long as P06's clone() is not
	 * available, with the same reorder meta.
	 *
	 * @param int    $old_id  Original design id (may no longer exist).
	 * @param array  $reorder {design_id, order_id, ref}.
	 * @param array  $payload Payload snapshot (used when the design or its files are gone).
	 * @param string $status  Status of the copy.
	 * @return int|WP_Error
	 */
	public static function clone_design( int $old_id, array $reorder, array $payload, string $status = 'carted' ) {
		$old    = $old_id > 0 ? Designs::get( $old_id ) : null;
		$args   = array(
			'parent_id'  => $old_id,
			'reorder_of' => array(
				'design_id' => $old_id,
				'order_id'  => (int) ( $reorder['order_id'] ?? 0 ),
			),
			'status'     => $status,
		);
		$source = $old && ! empty( $old['payload'] ) ? (array) $old['payload'] : $payload;
		if ( ! $old || empty( $old['preview_url'] ) ) {
			$args['from_payload'] = $source;
		}
		try {
			// Designs::clone() is contract C3 (P06); the fallback keeps reorders working until it lands.
			if ( method_exists( Designs::class, 'clone' ) ) {
				$new = Designs::clone( $old_id, $args );
			} elseif ( $source ) {
				$preview = $old && is_array( get_post_meta( $old_id, '_bps_preview', true ) ) ? (array) get_post_meta( $old_id, '_bps_preview', true ) : array();
				// Never share the file path: deleting the copy must not delete the original's preview.
				$new = Designs::create(
					$source,
					null,
					array(
						'url'  => (string) ( $preview['url'] ?? '' ),
						'path' => '',
					)
				);
				if ( ! is_wp_error( $new ) ) {
					update_post_meta( (int) $new, '_bps_parent_id', $old_id );
					update_post_meta( (int) $new, '_bps_reorder_of', $args['reorder_of'] );
					Designs::set_status( (int) $new, $status );
				}
			} else {
				$new = new WP_Error( 'bps_reorder', 'no payload' );
			}
		} catch ( \Throwable $e ) {
			$new = new WP_Error( 'bps_reorder', $e->getMessage() ); // Logged below (reorder_clone_failed).
		}
		if ( is_wp_error( $new ) || (int) $new <= 0 ) {
			Logger::error(
				'reorder_clone_failed',
				array(
					'design' => $old_id,
					'order'  => (int) ( $reorder['order_id'] ?? 0 ),
					'ref'    => (string) ( $reorder['ref'] ?? '' ),
					'error'  => is_wp_error( $new ) ? $new->get_error_code() . ': ' . $new->get_error_message() : 'invalid id',
				)
			);
			return is_wp_error( $new ) ? $new : new WP_Error( 'bps_reorder', 'invalid id' );
		}
		return (int) $new;
	}

	/**
	 * One-click reorder of a design that was ordered before (cart part of klant-account-5). The caller (REST route)
	 * checks ownership or the token first. Clones the design and puts the copy in the cart at the current price.
	 *
	 * @param int   $design_id Ordered design.
	 * @param int   $qty       Quantity (0 = same as before; with a name list always the number of names).
	 * @param array $rights    Client rights consent {v, accepted}.
	 * @return array|WP_Error {key, quote, designId, ref}
	 */
	public static function reorder_design( int $design_id, int $qty = 0, array $rights = array() ) {
		$item  = self::order_item_for( $design_id );
		$old   = Designs::get( $design_id );
		$order = $item ? $item->get_order() : null;
		if ( ! $old && ! $item ) {
			return new WP_Error( 'bps_not_found', __( 'We vonden dit ontwerp niet terug.', 'borduur-patch-studio' ), array( 'status' => 404 ) );
		}
		$payload = $item ? self::payload_for( $item ) : (array) ( $old['payload'] ?? array() );
		$ref     = $old ? (string) $old['ref'] : (string) $item->get_meta( 'bps_ref' );
		$reorder = array(
			'design_id' => $design_id,
			'order_id'  => $order ? (int) $order->get_id() : (int) ( $old['order_id'] ?? 0 ),
			'ref'       => $ref,
		);
		$new     = self::clone_design( $design_id, $reorder, $payload, 'draft' );
		if ( is_wp_error( $new ) ) {
			return new WP_Error( 'bps_store', __( 'We konden je ontwerp niet opnieuw klaarzetten. Probeer het nog eens of contacteer ons.', 'borduur-patch-studio' ), array( 'status' => 500 ) );
		}
		$design = Designs::get( (int) $new );
		if ( ! $design || empty( $design['payload'] ) ) {
			return new WP_Error( 'bps_store', __( 'We konden je ontwerp niet opnieuw klaarzetten. Probeer het nog eens of contacteer ons.', 'borduur-patch-studio' ), array( 'status' => 500 ) );
		}
		$names = Variant_Names::names( $design['payload'] );
		if ( $names ) {
			$design['payload']['qty'] = count( $names );
		} elseif ( $qty > 0 ) {
			$design['payload']['qty'] = $qty;
		}
		$res = Cart::add_design( $design, $rights, array( 'reorder_of' => $reorder ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		Designs::set_status( (int) $new, 'carted' );
		return $res + array(
			'designId' => (int) $new,
			'ref'      => (string) $design['ref'],
		);
	}

	/**
	 * The order line that ordered a design.
	 *
	 * @param int $design_id Design id.
	 */
	public static function order_item_for( int $design_id ): ?\WC_Order_Item_Product {
		$item_id = (int) get_post_meta( $design_id, '_bps_order_item_id', true );
		if ( $item_id <= 0 || ! class_exists( '\WC_Order_Factory' ) ) {
			return null;
		}
		$item = \WC_Order_Factory::get_order_item( $item_id );
		return $item instanceof \WC_Order_Item_Product && (int) $item->get_meta( '_bps_design_id' ) === $design_id ? $item : null;
	}

	/**
	 * "Opnieuw bestellen" in My account > Orders for completed orders with a patch (WooCommerce's own order-again URL).
	 *
	 * @param mixed $actions Actions.
	 * @param mixed $order   Order.
	 * @return mixed
	 */
	public static function my_orders_action( $actions, $order ) {
		if ( ! is_array( $actions ) || ! $order instanceof \WC_Order || isset( $actions['order-again'] ) ) {
			return $actions;
		}
		$statuses = (array) apply_filters( 'woocommerce_valid_order_statuses_for_order_again', array( 'completed' ) );
		if ( ! $order->has_status( $statuses ) || ! Order::has_design( $order ) ) {
			return $actions;
		}
		$actions['order-again'] = array(
			'url'  => wp_nonce_url( add_query_arg( 'order_again', $order->get_id(), wc_get_cart_url() ), 'woocommerce-order_again' ),
			'name' => __( 'Opnieuw bestellen', 'borduur-patch-studio' ),
		);
		return $actions;
	}
}
