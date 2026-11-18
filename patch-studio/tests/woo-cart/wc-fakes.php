<?php
/**
 * In-memory WooCommerce fakes for the P04 woo-cart tests: products with price/regular/sale and tax class, a cart with
 * fees and sessions-like cart_contents, orders + order items with meta, base tax rates, shipping zones and notices.
 * Only what Woo\Cart, Woo\Order, Woo\Order_Again, Woo\Product, Woo\Config and the name-tape cart touch.
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

$GLOBALS['wc_t'] = array(
	'prices_include_tax' => false,
	'tax_enabled'        => true,
	'base_rates'         => array( '' => array( 1 => array( 'rate' => 21.0, 'label' => 'BTW', 'shipping' => 'yes', 'compound' => 'no' ) ) ),
	'notices'            => array(),
	'products'           => array(),
	'zone_methods'       => array(),
	'order_items'        => array(),
	'posts'              => array(),
	'updated_posts'      => array(),
	'tax_display_cart'   => 'incl',
);

function wc_prices_include_tax() {
	return (bool) $GLOBALS['wc_t']['prices_include_tax'];
}
function wc_tax_enabled() {
	return (bool) $GLOBALS['wc_t']['tax_enabled'];
}
function wc_add_notice( $m, $t = 'success' ) {
	$GLOBALS['wc_t']['notices'][] = array(
		'type'   => $t,
		'notice' => $m,
	);
}
function wc_get_notices( $t = '' ) {
	$out = array();
	foreach ( $GLOBALS['wc_t']['notices'] as $n ) {
		if ( '' === $t || $n['type'] === $t ) {
			$out[] = $n;
		}
	}
	return $out;
}
function wc_clear_notices() {
	$GLOBALS['wc_t']['notices'] = array();
}
function wc_get_product( $id ) {
	return $GLOBALS['wc_t']['products'][ (int) $id ] ?? null;
}
function wc_get_cart_url() {
	return 'https://shop.test/winkelmand/';
}
function wc_get_order( $id ) {
	return $GLOBALS['wc_t']['orders'][ (int) $id ] ?? false;
}
function wc_format_decimal( $v, $dp = false ) {
	return false === $dp ? (string) $v : number_format( (float) $v, (int) $dp, '.', '' );
}
function number_format_i18n( $n, $d = 0 ) {
	return number_format( (float) $n, (int) $d, ',', '.' );
}
function wp_date( $f, $ts = null ) {
	return gmdate( $f, null === $ts ? time() : (int) $ts );
}
function wp_strip_all_tags( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_email( $s ) {
	return trim( (string) $s );
}
function sanitize_title( $s ) {
	return strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $s ) );
}
function sanitize_file_name( $s ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $s );
}
function wp_slash( $v ) {
	return $v;
}
function absint( $v ) {
	return abs( (int) $v );
}
function wp_nonce_url( $url, $action = -1 ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . '_wpnonce=test-' . $action;
}
function wp_timezone() {
	return new DateTimeZone( 'Europe/Brussels' );
}
function get_post( $id ) {
	$id = (int) $id;
	return isset( $GLOBALS['wc_t']['posts'][ $id ] ) ? (object) $GLOBALS['wc_t']['posts'][ $id ] : null;
}
function wp_update_post( $arr, $wp_error = false ) {
	$id = (int) $arr['ID'];
	if ( ! isset( $GLOBALS['wc_t']['posts'][ $id ] ) ) {
		return $wp_error ? new WP_Error( 'invalid_post', 'no post' ) : 0;
	}
	$GLOBALS['wc_t']['posts'][ $id ]   = array_merge( $GLOBALS['wc_t']['posts'][ $id ], $arr );
	$GLOBALS['wc_t']['updated_posts'][] = $arr;
	return $id;
}
function get_posts( $args = array() ) {
	return array();
}
function get_permalink( $id ) {
	return 'https://shop.test/?p=' . (int) $id;
}
function get_post_status( $id ) {
	return 'publish';
}

/** Product. */
class WC_Product {
	public $id;
	public $price         = '0';
	public $regular_price = '0';
	public $sale_price    = '';
	public $tax_class     = '';
	public $tax_status    = 'taxable';
	public $meta          = array();
	public $status        = 'publish';
	public $saved         = 0;
	public $name          = 'Patch op maat';
	public $sold_individually = false;
	public function __construct( $id = 0 ) {
		$this->id = (int) $id;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_name() {
		return $this->name;
	}
	public function get_price( $ctx = 'view' ) {
		return $this->price;
	}
	public function set_price( $p ) {
		$this->price = $p;
	}
	public function get_regular_price( $ctx = 'view' ) {
		return $this->regular_price;
	}
	public function set_regular_price( $p ) {
		$this->regular_price = $p;
	}
	public function get_sale_price( $ctx = 'view' ) {
		return $this->sale_price;
	}
	public function set_sale_price( $p ) {
		$this->sale_price = $p;
	}
	public function get_tax_class( $ctx = 'view' ) {
		return $this->tax_class;
	}
	public function get_tax_status( $ctx = 'view' ) {
		return $this->tax_status;
	}
	public function get_meta( $k, $single = true ) {
		return $this->meta[ $k ] ?? '';
	}
	public function update_meta_data( $k, $v ) {
		$this->meta[ $k ] = $v;
	}
	public function get_status() {
		return $this->status;
	}
	public function set_status( $s ) {
		$this->status = $s;
	}
	public function get_sold_individually() {
		return $this->sold_individually;
	}
	public function set_sold_individually( $b ) {
		$this->sold_individually = (bool) $b;
	}
	public function save() {
		++$this->saved;
		return $this->id;
	}
}
class WC_Product_Simple extends WC_Product {}

class WC_Tax {
	public static function get_base_tax_rates( $class = '' ) {
		return $GLOBALS['wc_t']['base_rates'][ $class ] ?? array();
	}
}

/** Shipping zones. */
class BPS_Fake_Method {
	public $id;
	public $opts;
	public function __construct( $id, $opts ) {
		$this->id   = $id;
		$this->opts = $opts;
	}
	public function get_option( $k, $d = '' ) {
		return $this->opts[ $k ] ?? $d;
	}
}
class BPS_Fake_Zone {
	public function get_shipping_methods( $enabled_only = false ) {
		return $GLOBALS['wc_t']['zone_methods'];
	}
}
class WC_Shipping_Zones {
	public static function get_zone_matching_package( $package ) {
		return new BPS_Fake_Zone();
	}
}

/** Cart. */
class BPS_Fake_Fees {
	public $fees = array();
	public function add_fee( $f ) {
		if ( isset( $this->fees[ $f['id'] ] ) ) {
			return new WP_Error( 'fee_exists', 'dup' );
		}
		$this->fees[ $f['id'] ] = $f;
		return (object) $f;
	}
	public function get_fees() {
		return array_map( static fn( $f ) => (object) $f, $this->fees );
	}
}
class WC_Cart {
	public $cart_contents = array();
	public $fees;
	public $refuse_add    = false;
	public function __construct() {
		$this->fees = new BPS_Fake_Fees();
	}
	public function get_cart() {
		return $this->cart_contents;
	}
	public function get_cart_item( $k ) {
		return $this->cart_contents[ $k ] ?? array();
	}
	public function fees_api() {
		return $this->fees;
	}
	public function remove_cart_item( $k ) {
		unset( $this->cart_contents[ $k ] );
		return true;
	}
	public function calculate_totals() {
		$this->fees = new BPS_Fake_Fees();
		do_action( 'woocommerce_before_calculate_totals', $this );
		do_action( 'woocommerce_cart_calculate_fees', $this );
	}
	public function get_cart_contents_count() {
		return array_sum( array_column( $this->cart_contents, 'quantity' ) );
	}
	public function add_to_cart( $product_id, $qty = 1, $variation_id = 0, $variation = array(), $data = array() ) {
		if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $qty ) ) {
			return false;
		}
		if ( $this->refuse_add ) {
			wc_add_notice( 'Kies eerst een verplichte optie: Cadeauverpakking.', 'error' );
			return false;
		}
		$key                         = md5( $product_id . serialize( $data ) . count( $this->cart_contents ) );
		$this->cart_contents[ $key ] = array_merge(
			$data,
			array(
				'key'        => $key,
				'product_id' => $product_id,
				'quantity'   => $qty,
				'data'       => clone wc_get_product( $product_id ),
			)
		);
		return $key;
	}
	/**
	 * Mirror of WooCommerce's line subtotal for prices incl./excl. VAT (base location = customer location, BE):
	 * the net amount WooCommerce derives from set_price().
	 */
	public function subtotal_excl(): float {
		$sum = 0.0;
		foreach ( $this->cart_contents as $it ) {
			$p    = (float) $it['data']->get_price();
			$line = $p * (int) $it['quantity'];
			if ( wc_prices_include_tax() ) {
				$rate = \BorduurPatchStudio\Woo\Config::base_tax_rate( (string) $it['data']->get_tax_class() );
				$tax  = round( $line - $line / ( 1 + $rate ), 2 );
				$line = $line - $tax;
			}
			$sum += round( $line, 2 );
		}
		return round( $sum, 2 );
	}
}
class BPS_Fake_WC {
	public $cart;
	public $session = null;
}
$GLOBALS['wc_main'] = new BPS_Fake_WC();
function WC() {
	return $GLOBALS['wc_main'];
}
class WooCommerce {}

/** Orders. */
class WC_Order_Item_Product {
	public $id;
	public $meta     = array();
	public $qty      = 1;
	public $order_id = 0;
	public $product_id = 0;
	public function __construct( $id = 0 ) {
		$this->id = (int) $id;
	}
	public function get_id() {
		return $this->id;
	}
	public function add_meta_data( $k, $v, $unique = false ) {
		$this->meta[ $k ] = $v;
	}
	public function update_meta_data( $k, $v ) {
		$this->meta[ $k ] = $v;
	}
	public function get_meta( $k, $single = true ) {
		return $this->meta[ $k ] ?? '';
	}
	public function get_quantity() {
		return $this->qty;
	}
	public function get_order_id() {
		return $this->order_id;
	}
	public function get_order() {
		return wc_get_order( $this->order_id );
	}
	public function get_product_id() {
		return $this->product_id;
	}
	public function get_name() {
		return 'Patch op maat';
	}
}
class WC_Order {
	public $id;
	public $items    = array();
	public $meta     = array();
	public $status   = 'completed';
	public $customer = 0;
	public $email    = 'klant@example.test';
	public function __construct( $id ) {
		$this->id = (int) $id;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_order_number() {
		return (string) $this->id;
	}
	public function get_items( $t = 'line_item' ) {
		return $this->items;
	}
	public function get_meta( $k, $single = true ) {
		return $this->meta[ $k ] ?? '';
	}
	public function update_meta_data( $k, $v ) {
		$this->meta[ $k ] = $v;
	}
	public function has_status( $s ) {
		return in_array( $this->status, (array) $s, true );
	}
	public function get_customer_id() {
		return $this->customer;
	}
	public function get_billing_email() {
		return $this->email;
	}
	public function save() {
		return $this->id;
	}
}
class WC_Order_Factory {
	public static function get_order_item( $id ) {
		return $GLOBALS['wc_t']['order_items'][ (int) $id ] ?? false;
	}
}
