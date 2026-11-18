<?php
/**
 * Proof status in My account (atelier-flow-13): under each patch line on view-order and on the thank-you page
 * (never in e-mails) the status in plain language, a thumbnail and "Proef bekijken" while the proof waits for the
 * customer. WooCommerce protects view-order by order owner; the thank-you page by the order key.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Proof;

defined( 'ABSPATH' ) || exit;

/**
 * Customer-facing proof status.
 */
final class Account {

	/** My account orders column id. */
	const COLUMN = 'bps-proof';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_item_meta_end', array( __CLASS__, 'item_meta_end' ), 20, 4 );
		add_filter( 'woocommerce_my_account_my_orders_columns', array( __CLASS__, 'orders_columns' ) );
		add_action( 'woocommerce_my_account_my_orders_column_' . self::COLUMN, array( __CLASS__, 'orders_cell' ) );
	}

	/**
	 * Customer label for a status.
	 *
	 * @param string $status Proof status.
	 */
	public static function label( string $status ): string {
		switch ( $status ) {
			case 'sent':
				return __( 'Wacht op jouw goedkeuring', 'borduur-patch-studio' );
			case 'approved':
				return __( 'Goedgekeurd', 'borduur-patch-studio' );
			case 'changes_requested':
				return __( 'Aanpassing gevraagd: je krijgt een nieuwe proef', 'borduur-patch-studio' );
			case 'cancelled':
				return __( 'Geannuleerd', 'borduur-patch-studio' );
			default:
				return __( 'We maken je digitale proef', 'borduur-patch-studio' );
		}
	}

	/**
	 * May the current visitor see the proof of this order on this page?
	 *
	 * @param mixed $order Order.
	 */
	public static function can_view( $order ): bool {
		if ( ! $order instanceof \WC_Order || doing_action( 'woocommerce_email_order_details' ) || did_action( 'woocommerce_email_header' ) > did_action( 'woocommerce_email_footer' ) ) {
			return false;
		}
		$view_order = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' );
		$received   = function_exists( 'is_order_received_page' ) && is_order_received_page();
		if ( ! $view_order && ! $received ) {
			return false;
		}
		$uid = get_current_user_id();
		if ( $uid > 0 && (int) $order->get_customer_id() === $uid ) {
			return true;
		}
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- order key check like WooCommerce.
		return $received && '' !== $key && hash_equals( (string) $order->get_order_key(), $key );
	}

	/**
	 * Output under a line item.
	 *
	 * @param int   $item_id Item id.
	 * @param mixed $item    Item.
	 * @param mixed $order   Order.
	 * @param bool  $plain   Plain text (e-mails).
	 */
	public static function item_meta_end( $item_id, $item = null, $order = null, $plain = false ): void {
		if ( $plain || ! $item instanceof \WC_Order_Item_Product || ( (int) $item->get_meta( '_bps_design_id' ) <= 0 && '' === (string) $item->get_meta( '_bps_payload' ) ) ) {
			return;
		}
		if ( ! self::can_view( $order ) ) {
			return;
		}
		echo self::html( $order, $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
	}

	/**
	 * Markup for one line.
	 *
	 * @param \WC_Order              $order Order.
	 * @param \WC_Order_Item_Product $item  Line.
	 */
	public static function html( $order, $item ): string {
		$p      = Proof_Service::get( $item );
		$status = '' !== Proof_Service::blocked_status( $order ) ? 'cancelled' : $p['status'];
		$thumb  = '' !== $p['image_url'] && 'none' !== $status ? $p['image_url'] : '';
		if ( '' !== $thumb && $p['attachment_id'] > 0 && function_exists( 'wp_get_attachment_image_src' ) ) {
			$src   = wp_get_attachment_image_src( $p['attachment_id'], 'thumbnail' );
			$thumb = is_array( $src ) && ! empty( $src[0] ) ? (string) $src[0] : $thumb;
		}
		$out = '<div class="bps-account-proof" style="display:flex;gap:10px;align-items:center;margin:8px 0 0;flex-wrap:wrap">';
		if ( '' !== $thumb ) {
			$out .= '<img src="' . esc_url( $thumb ) . '" alt="" width="56" height="56" loading="lazy" style="width:56px;height:56px;object-fit:contain;border:1px solid #E3EBF2;border-radius:6px;background:#fff">';
		}
		$out .= '<span><strong>' . esc_html__( 'Digitale proef', 'borduur-patch-studio' ) . ':</strong> <span class="bps-account-proof__status bps-account-proof__status--' . esc_attr( $status ) . '">' . esc_html( self::label( $status ) ) . '</span></span>';
		if ( 'sent' === $status ) {
			$out .= ' <a class="button bps-account-proof__btn" href="' . esc_url( Proof_Service::url( $order, $item ) ) . '" rel="nofollow noreferrer" style="min-height:44px;display:inline-flex;align-items:center">' . esc_html__( 'Proef bekijken', 'borduur-patch-studio' ) . '</a>';
		}
		return $out . '</div>';
	}

	/**
	 * Column "Proef" in the My account orders list (after the status).
	 *
	 * @param array $cols Columns.
	 */
	public static function orders_columns( $cols ) {
		$cols = is_array( $cols ) ? $cols : array();
		$out  = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'order-status' === $k ) {
				$out[ self::COLUMN ] = __( 'Proef', 'borduur-patch-studio' );
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Proef', 'borduur-patch-studio' );
		}
		return $out;
	}

	/**
	 * Cell: short notice when a proof waits.
	 *
	 * @param mixed $order Order.
	 */
	public static function orders_cell( $order ): void {
		if ( $order instanceof \WC_Order && '1' === (string) $order->get_meta( Proof_Service::OPEN_META ) && '' === Proof_Service::blocked_status( $order ) ) {
			echo '<strong>' . esc_html__( 'Proef wacht op jou', 'borduur-patch-studio' ) . '</strong>';
		}
	}
}
