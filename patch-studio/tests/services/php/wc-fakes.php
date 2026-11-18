<?php
/**
 * Tiny in-memory WooCommerce fakes (orders, items, coupons) for the commerce tests. Only what our code calls.
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

$GLOBALS['bps_wc'] = array( 'orders' => array(), 'coupons' => array(), 'refunds' => array() );

class Bps_Meta {
	public $meta = array();
	public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function add_meta_data( $k, $v, $unique = false ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function save() { return 1; }
}

class WC_Order_Item_Product extends Bps_Meta {
	public $id; public $product_id; public $qty; public $total; public $tax; public $name;
	public function __construct( $id, $product_id, $qty, $total, $tax = 0.0, $name = 'Item' ) {
		$this->id = $id; $this->product_id = $product_id; $this->qty = $qty; $this->total = $total; $this->tax = $tax; $this->name = $name;
	}
	public function get_id() { return $this->id; }
	public function get_product_id() { return $this->product_id; }
	public function get_quantity() { return $this->qty; }
	public function get_total() { return $this->total; }
	public function get_total_tax() { return $this->tax; }
	public function get_name() { return $this->name; }
}

class Bps_Date { public $ts; public function __construct( $ts ) { $this->ts = $ts; } public function getTimestamp() { return $this->ts; } }

class WC_Order extends Bps_Meta {
	public $id; public $items = array(); public $customer = 0; public $email = ''; public $paid = true; public $notes = array(); public $created; public $first = 'An';
	public function __construct( $id ) { $this->id = $id; $this->created = time(); $GLOBALS['bps_wc']['orders'][ $id ] = $this; }
	public function get_id() { return $this->id; }
	public function get_order_number() { return (string) $this->id; }
	public function get_items( $type = 'line_item' ) { return 'line_item' === $type ? $this->items : array(); }
	public function get_item( $id ) { return $this->items[ $id ] ?? null; }
	public function get_customer_id() { return $this->customer; }
	public function get_billing_email() { return $this->email; }
	public function get_billing_first_name() { return $this->first; }
	public function is_paid() { return $this->paid; }
	public function get_date_paid() { return new Bps_Date( $this->created ); }
	public function get_date_created() { return new Bps_Date( $this->created ); }
	public function add_order_note( $n ) { $this->notes[] = $n; }
	public function get_view_order_url() { return 'https://shop.test/order/' . $this->id; }
	public function add_item( WC_Order_Item_Product $i ) { $this->items[ $i->id ] = $i; return $i; }
}

class WC_Coupon extends Bps_Meta {
	public $id = 0; public $code = ''; public $props = array( 'usage_count' => 0, 'usage_limit' => 0, 'amount' => 0, 'date_expires' => null );
	public function __construct( $code = '' ) {
		if ( is_int( $code ) ) {
			foreach ( $GLOBALS['bps_wc']['coupons'] as $c ) { if ( $c->id === $code ) { $this->copy( $c ); } }
		} elseif ( '' !== $code && isset( $GLOBALS['bps_wc']['coupons'][ strtolower( $code ) ] ) ) {
			$this->copy( $GLOBALS['bps_wc']['coupons'][ strtolower( $code ) ] );
		}
	}
	private function copy( $c ) { $this->id = $c->id; $this->code = $c->code; $this->props = $c->props; $this->meta = $c->meta; }
	public function __call( $m, $a ) {
		if ( 0 === strpos( $m, 'set_' ) ) { $this->props[ substr( $m, 4 ) ] = $a[0]; return; }
		if ( 0 === strpos( $m, 'get_' ) ) { return $this->props[ substr( $m, 4 ) ] ?? null; }
	}
	public function set_code( $c ) { $this->code = $c; }
	public function get_code() { return $this->code; }
	public function get_id() { return $this->id; }
	public function get_amount() { return $this->props['amount']; }
	public function get_usage_count() { return (int) $this->props['usage_count']; }
	public function get_usage_limit() { return (int) $this->props['usage_limit']; }
	public function get_date_expires() { return $this->props['date_expires'] ? new Bps_Date( $this->props['date_expires'] ) : null; }
	public function save() {
		if ( ! $this->id ) { $this->id = 1000 + count( $GLOBALS['bps_wc']['coupons'] ); }
		$GLOBALS['bps_wc']['coupons'][ strtolower( $this->code ) ] = $this;
		return $this->id;
	}
}

function wc_get_order( $id ) { return $GLOBALS['bps_wc']['orders'][ (int) $id ] ?? null; }
function wc_get_coupon_id_by_code( $code ) { $c = $GLOBALS['bps_wc']['coupons'][ strtolower( $code ) ] ?? null; return $c && empty( $c->props['trashed'] ) ? $c->id : 0; }
function wp_trash_post( $id ) { foreach ( $GLOBALS['bps_wc']['coupons'] as $c ) { if ( $c->id === (int) $id ) { $c->props['trashed'] = true; } } return true; }
function bps_wc_reset() { $GLOBALS['bps_wc'] = array( 'orders' => array(), 'coupons' => array(), 'refunds' => array() ); }
