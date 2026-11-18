<?php
/**
 * Cross-sell: de WordPress-klassen (Module, Cart, Service, Products) tegen een minimale WordPress/WooCommerce-stub.
 *   php tests/crosssell/run-wp.php
 * Controleert: zonder WooCommerce geen hooks en geen notices; mandprijs = kaartprijs (zelfde pool); borduurkaart
 * en PVC-mal enkel wanneer nodig en nooit dubbel; directe toevoeging van eigen producten geweigerd; mandtekst.
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable
error_reporting( E_ALL );
set_error_handler(
	static function ( $no, $str, $file, $line ) {
		fwrite( STDERR, "PHP-melding: $str ($file:$line)\n" );
		exit( 1 );
	}
);
define( 'ABSPATH', __DIR__ . '/' );
define( 'BPS_TESTING', true );
$plugin = dirname( __DIR__, 2 ) . '/plugin/borduur-patch-studio/';
define( 'BPS_DIR', $plugin );
define( 'BPS_URL', 'https://example.test/wp-content/plugins/borduur-patch-studio/' );

/* ---------- WordPress-stubs ---------- */
$GLOBALS['hooks']   = array();
$GLOBALS['notices'] = array();
$GLOBALS['options'] = array();
$GLOBALS['cfg_filter'] = null;
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][] = array( $h, $p ); return true; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][] = array( $h, $p ); return true; }
function apply_filters( $h, $v, ...$args ) { return ( 'bps_crosssell_config' === $h && $GLOBALS['cfg_filter'] ) ? ( $GLOBALS['cfg_filter'] )( $v ) : $v; }
function do_action( ...$a ) {}
function is_admin() { return false; }
function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function determine_locale() { return 'nl_BE'; }
function wp_date( $f ) { return gmdate( $f ); }
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $s ) ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function taxonomy_exists( $t ) { return false; }
function get_post_meta( $id, $k, $single = false ) { return '_bps_cs_product' === $k && $id >= 90000 ? 'ours' : ''; }
function wc_add_notice( $m, $t = 'success' ) { $GLOBALS['notices'][] = array( $t, $m ); }
function wc_get_price_excluding_tax( $p, $args ) { return (float) $args['price']; }

/* ---------- WooCommerce-stubs ---------- */
class WC_Product {
	public $id; public $price; public $name; public $type; public $children = array(); public $attrs = array(); public $stock = true;
	public function __construct( $id, $price, $name, $type = 'simple' ) { $this->id = $id; $this->price = $price; $this->name = $name; $this->type = $type; }
	public function get_status() { return 'publish'; }
	public function get_name() { return $this->name; }
	public function get_price( $ctx = 'view' ) { return $this->price; }
	public function set_price( $p ) { $this->price = $p; }
	public function is_purchasable() { return true; }
	public function is_in_stock() { return $this->stock; }
	public function is_type( $t ) { return $t === $this->type; }
	public function get_children() { return $this->children; }
	public function get_attributes() { return $this->attrs; }
}
class WC_Cart_Fees_Stub {
	public $fees = array();
	public function add_fee( $f ) { if ( isset( $this->fees[ $f['id'] ] ) ) { return new stdClass(); } $this->fees[ $f['id'] ] = $f; return $f; }
}
class WC_Cart {
	public $contents = array(); public $fees;
	public function __construct() { $this->fees = new WC_Cart_Fees_Stub(); }
	public function get_cart() { return $this->contents; }
	public function fees_api() { return $this->fees; }
	public function remove_cart_item( $k ) { unset( $this->contents[ $k ] ); return true; }
}

$samples = json_decode( file_get_contents( __DIR__ . '/samples.json' ), true );
$GLOBALS['products'] = array();
foreach ( $samples['catalog'] as $id => $p ) {
	$wp = new WC_Product( (int) $id, $p['priceExcl'], $p['name'], empty( $p['variations'] ) ? 'simple' : 'variable' );
	foreach ( $p['variations'] ?? array() as $v ) {
		$wv        = new WC_Product( $v['id'], $v['priceExcl'], $p['name'] . ' - ' . $v['colorName'], 'variation' );
		$wv->attrs = array_filter( array( 'pa_kleur' => $v['colorSlug'], 'pa_maat' => $v['size'] ? strtolower( $v['size'] ) : null ) );
		$wv->stock = $v['inStock'] ?? true;
		$wp->children[]                      = $v['id'];
		$GLOBALS['products'][ (int) $v['id'] ] = $wv;
	}
	$GLOBALS['products'][ (int) $id ] = $wp;
}
function wc_get_product( $id ) { return isset( $GLOBALS['products'][ (int) $id ] ) ? clone $GLOBALS['products'][ (int) $id ] : null; }

spl_autoload_register(
	static function ( $c ) use ( $plugin ) {
		if ( 0 === strpos( $c, 'BorduurPatchStudio\\Crosssell\\' ) ) {
			$n = substr( $c, strlen( 'BorduurPatchStudio\\Crosssell\\' ) );
			require_once $plugin . 'includes/crosssell/class-' . str_replace( '_', '-', strtolower( $n ) ) . '.php';
		}
	}
);
require_once $plugin . 'includes/class-pricing.php';

use BorduurPatchStudio\Crosssell\Module;
use BorduurPatchStudio\Crosssell\Cart;
use BorduurPatchStudio\Crosssell\Config;
use BorduurPatchStudio\Crosssell\Offers;
use BorduurPatchStudio\Crosssell\Service;
use BorduurPatchStudio\Crosssell\Products;
use BorduurPatchStudio\Crosssell\Lines;

$fail   = 0;
$checks = 0;
$check  = static function ( bool $ok, string $what ) use ( &$fail, &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		++$fail;
		fwrite( STDERR, "FOUT: $what\n" );
	}
};

// 1. Zonder WooCommerce: geen enkele hook, geen notices.
Module::register();
$check( array() === $GLOBALS['hooks'], 'zonder WooCommerce registreert de module niets: ' . json_encode( $GLOBALS['hooks'] ) );

// 2. Met WooCommerce: hooks met de juiste prioriteiten.
class_alias( 'WC_Cart_Fees_Stub', 'WooCommerce' ); // pas nu: een gewone class-declaratie wordt vooraf gehesen.
$ref = new ReflectionProperty( Module::class, 'registered' );
$ref->setValue( null, false );
$GLOBALS['cfg_filter'] = static fn( $c ) => Offers::link_products( $c, $samples['productLinks'] );
Config::flush();
Module::register();
$hooks = $GLOBALS['hooks'];
$check( in_array( array( 'woocommerce_before_calculate_totals', 30 ), $hooks, true ), 'prijs op prio 30 (na woo 20)' );
$check( in_array( array( 'woocommerce_cart_calculate_fees', 30 ), $hooks, true ), 'fees op prio 30' );
$check( in_array( array( 'rest_api_init', 10 ), $hooks, true ), 'REST-routes' );
$check( ! in_array( 'woocommerce_review_order_before_submit', array_column( $hooks, 0 ), true ), 'nooit iets in de kassa' );

// 3. Mandprijs = kaartprijs (pet bij 25 patches van hetzelfde ontwerp).
$s      = $samples['designs'][0]; // flat25
$main   = Config::main();
$cfg    = Config::get();
$ids    = Products::configured_ids( $cfg );
$cat    = Products::catalog( $ids, $cfg );
$check( isset( $cat[36262] ) && count( $cat[36262]['variations'] ) > 0 && 'black' === $cat[36262]['variations'][0]['colorSlug'], 'catalogus uit WooCommerce-producten (kleurattribuut herkend)' );
$offers = Offers::build(
	array( 'payload' => $s['payload'], 'designId' => 7, 'ref' => 'PS-7' ),
	array( 'cfg' => $cfg, 'main' => $main, 'catalog' => $cat, 'patch_unit' => Service::patch_unit(), 'locale' => 'nl-BE', 'month' => 6, 'cart' => Lines::context( array( array( 'quantity' => 25, 'bps' => array( 'design_id' => 7, 'technique' => 'embroidered' ) ) ), 7, $main ) )
);
$capc = null;
foreach ( $offers['cards'] as $c ) {
	if ( 'cap' === $c['id'] ) {
		$capc = $c;
	}
}
$check( null !== $capc, 'petkaart aanwezig' );
$cart                 = new WC_Cart();
$vid                  = $capc['defaultVariationId'];
$cart->contents['p1'] = array( 'key' => 'p1', 'quantity' => 25, 'product_id' => 1, 'bps' => array( 'design_id' => 7, 'technique' => 'embroidered', 'ref' => 'PS-7' ) );
$cart->contents['c1'] = array(
	'key'          => 'c1',
	'quantity'     => 25,
	'product_id'   => $capc['defaultProductId'],
	'variation_id' => $vid,
	'data'         => wc_get_product( $vid ),
	'bps_cs'       => array( 'v' => 1, 'item' => 'cap', 'type' => 'patch_on_blank', 'design_id' => 7, 'ref' => 'PS-7', 'technique' => 'embroidered', 'patch_size_cm' => $capc['patchSizeCm'], 'placement' => 'front', 'options' => array(), 'design_options' => $s['payload']['options'], 'batch' => 'b1', 'locale' => 'nl-BE' ),
);
Cart::set_prices( $cart );
$check( abs( $cart->contents['c1']['data']->get_price() - $capc['unitExcl'] ) < 0.00005, 'mandprijs ' . $cart->contents['c1']['data']->get_price() . ' = kaartprijs ' . $capc['unitExcl'] );

// 4. Fees: patchregel aanwezig → geen extra borduurkaart; enkel dragers → één keer, nooit dubbel.
Cart::add_fees( $cart );
$check( array() === $cart->fees->fees, 'geen extra borduurkaart naast de patchregel' );
unset( $cart->contents['p1'] );
Cart::add_fees( $cart );
Cart::add_fees( $cart );
$check( array( 'bps-fee-embroidery' ) === array_keys( $cart->fees->fees ) && 45.0 === $cart->fees->fees['bps-fee-embroidery']['amount'], 'patchregel verwijderd → één borduurkaart, nooit dubbel' );
$cart2                 = new WC_Cart();
$cart2->contents['k1'] = array( 'key' => 'k1', 'quantity' => 50, 'product_id' => 90004, 'data' => wc_get_product( 90004 ), 'bps_cs' => array( 'item' => 'pvc_keychain', 'type' => 'pvc', 'design_id' => 7, 'technique' => 'pvc', 'batch' => 'b2', 'options' => array() ) );
Cart::add_fees( $cart2 );
$check( array( 'bps-cs-pvc-mold' ) === array_keys( $cart2->fees->fees ) && 80.0 === $cart2->fees->fees['bps-cs-pvc-mold']['amount'], 'PVC-sleutelhanger → mal één keer' );
Cart::set_prices( $cart2 );
$pp = (float) $cart2->contents['k1']['data']->get_price();
$check( $pp > 0 && $pp <= 4.2, 'PVC-prijs per stuk met PVC-staffel: ' . $pp );

// 5. Directe toevoeging van eigen producten geweigerd; gewone producten niet.
$check( false === Cart::block_direct( true, 90001 ) && 'error' === ( $GLOBALS['notices'][0][0] ?? '' ), 'lederen sleutelhanger zonder ontwerp geweigerd' );
$check( true === Cart::block_direct( true, 36262 ), 'gewone pet blijft los koopbaar' );

// 6. Weergave in de mand.
$rows = Cart::rows( $cart->contents['c1']['bps_cs'], 'nl' );
$check( array( 'Ontwerp', 'PS-7' ) === $rows[0] && 'Patch' === $rows[1][0] && str_starts_with( $rows[1][1], 'Met patch PS-7 (6 cm, ' ), 'mandregel: ' . $rows[1][1] );
$data = Cart::item_data( array(), $cart->contents['c1'] );
$last = end( $data );
$check( 'Prijs per stuk' === $last['key'] && str_contains( $last['value'], 'patch €' ) && str_contains( $last['value'], 'aanbrengen' ), 'prijsopbouw: ' . $last['value'] );

// 7. Publieke config: extras.crosssell zonder gebouwde bundel = uit (de studio roept dan niets aan).
$pc = Module::public_config( array( 'locale' => 'nl-BE' ) );
$check( isset( $pc['extras']['crosssell'] ) && $pc['extras']['crosssell']['enabled'] === Module::bundle_exists(), 'extras.crosssell.enabled volgt de bundel' );

echo $fail ? "crosssell WP: $fail van $checks controles mislukt\n" : "crosssell WP: alle $checks controles in orde\n";
exit( $fail ? 1 : 0 );
