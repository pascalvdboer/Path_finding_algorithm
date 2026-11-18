<?php
/**
 * P04 woo-cart-pricing: prices incl./excl. VAT, mini-cart pricing on cart load, pricing errors without € 0, reorders,
 * one setting for the reorder fee, rights record, custom-made notices, shipping from the WooCommerce zone, VAT health
 * check, product add-ons exclusion, lead time, name variants, plurals.
 * No WordPress, no database, no network: tests/woo-cart/wp-stubs.php + wc-fakes.php and a fake Designs (contract C3).
 *
 * Run: php tests/woo-cart/run-woo-cart-php.php   (also through `npm test`: tests/woo-cart/woo-cart-php.test.js)
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

error_reporting( E_ALL );
set_error_handler(
	static function ( $no, $str, $file, $line ) {
		fwrite( STDERR, "PHP notice: $str ($file:$line)\n" );
		exit( 1 );
	}
);

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/wc-fakes.php';
require __DIR__ . '/fake-designs.php';

$root   = dirname( __DIR__, 2 );
$plugin = $root . '/plugin/borduur-patch-studio/';
define( 'BPS_DIR', $plugin );
define( 'BPS_FILE', $plugin . 'borduur-patch-studio.php' );
define( 'BPS_URL', 'https://shop.test/wp-content/plugins/borduur-patch-studio/' );
define( 'BPS_VERSION', '1.0.0' );

require_once $plugin . 'includes/class-plugin.php';
spl_autoload_register( array( \BorduurPatchStudio\Plugin::class, 'autoload' ) );

use BorduurPatchStudio\Lead_Time;
use BorduurPatchStudio\Logger;
use BorduurPatchStudio\Nametape\Cart as NtCart;
use BorduurPatchStudio\Pricing;
use BorduurPatchStudio\Settings;
use BorduurPatchStudio\Woo\Cart;
use BorduurPatchStudio\Woo\Config;
use BorduurPatchStudio\Woo\Designs;
use BorduurPatchStudio\Woo\Order;
use BorduurPatchStudio\Woo\Order_Again;
use BorduurPatchStudio\Woo\Product;
use BorduurPatchStudio\Woo\Variant_Names;

$GLOBALS['fails'] = 0;
$GLOBALS['count'] = 0;
function ok( $cond, string $msg ): void {
	++$GLOBALS['count'];
	if ( ! $cond ) {
		++$GLOBALS['fails'];
		fwrite( STDERR, "FAIL: $msg\n" );
	}
}
function eq( $a, $b, string $msg ): void {
	ok( $a === $b, $msg . ' (got ' . var_export( $a, true ) . ', want ' . var_export( $b, true ) . ')' );
}
function near( $a, $b, string $msg, float $eps = 0.00001 ): void {
	ok( abs( (float) $a - (float) $b ) <= $eps, $msg . ' (got ' . var_export( $a, true ) . ', want ' . var_export( $b, true ) . ')' );
}
function section( string $s ): void {
	echo "· $s\n";
}
function settings( array $o ): void {
	$GLOBALS['bps_t']['options']['bps_settings'] = $o;
	Settings::flush();
	Logger::flush();
	Config::reset_cache();
}
function fresh_cart(): WC_Cart {
	$GLOBALS['wc_main']->cart = new WC_Cart();
	$GLOBALS['wc_t']['notices'] = array();
	return $GLOBALS['wc_main']->cart;
}
function defaults_for( string $tech ): array {
	return Pricing::default_options( Settings::get(), $tech );
}
/** Cart line as Cart::add_design() builds it. */
function patch_line( WC_Cart $cart, string $key, array $bps, int $qty ): void {
	$cart->cart_contents[ $key ] = array(
		'key'        => $key,
		'product_id' => 500,
		'quantity'   => $qty,
		'data'       => clone wc_get_product( 500 ),
		'bps'        => $bps + array(
			'v'           => 1,
			'design_id'   => 0,
			'ref'         => 'PS-2026-0001',
			'technique'   => 'embroidered',
			'size_cm'     => 8.0,
			'width_mm'    => 80,
			'height_mm'   => 80,
			'shape'       => 'round',
			'options'     => defaults_for( (string) ( $bps['technique'] ?? 'embroidered' ) ),
			'texts'       => array(),
			'preview_url' => 'https://shop.test/wp-content/uploads/patch-studio/previews/a.png',
			'locale'      => 'nl-BE',
		),
	);
}

$GLOBALS['bps_t']['options']['bps_product_id'] = 500;
$GLOBALS['wc_t']['products'][500]              = new WC_Product( 500 );
$log = array();
Logger::set_writer(
	function ( $level, $line, $ctx ) use ( &$log ) {
		$log[] = array( $level, $line, $ctx );
	}
);
settings( array() );
Cart::register();
Order::register();
Product::register();

/* ======================================================================= */
section( 'woo-compat-1: Config::woo_unit_price incl./excl. btw' );
$p = wc_get_product( 500 );
$GLOBALS['wc_t']['prices_include_tax'] = false;
near( Config::woo_unit_price( 4.94, $p ), 4.94, 'prijzen excl. btw: bedrag ongewijzigd' );
$GLOBALS['wc_t']['prices_include_tax'] = true;
near( Config::woo_unit_price( 4.94, $p ), 5.9774, 'prijzen incl. btw: × 1,21 zonder tussentijdse afronding' );
$GLOBALS['wc_t']['base_rates']['reduced-rate'] = array( 2 => array( 'rate' => 6.0, 'compound' => 'no' ) );
$p6            = new WC_Product( 501 );
$p6->tax_class = 'reduced-rate';
near( Config::woo_unit_price( 10.0, $p6 ), 10.6, 'belastingklasse van het product telt (6 %)' );
$p0             = new WC_Product( 502 );
$p0->tax_status = 'none';
near( Config::woo_unit_price( 10.0, $p0 ), 10.0, 'niet-belastbaar product: geen opslag' );
$GLOBALS['wc_t']['prices_include_tax'] = false;

section( 'woo-compat-1/-2: 10 × geborduurd 8 cm, subtotaal 49,40 in beide modi, regular = price, geen actieprijs' );
$unit = Pricing::cart_unit_excl( array( 'technique' => 'embroidered', 'size_cm' => 8, 'options' => defaults_for( 'embroidered' ) ), 10, 0, Settings::get() );
near( $unit, 4.94, 'engine: 4,94 excl. per stuk bij 10 stuks' );
$subs = array();
foreach ( array( false, true ) as $incl ) {
	$GLOBALS['wc_t']['prices_include_tax'] = $incl;
	$cart                                  = fresh_cart();
	patch_line( $cart, 'k1', array( 'design_id' => 11, 'ref' => 'PS-2026-0011' ), 10 );
	do_action( 'woocommerce_cart_loaded_from_session', $cart ); // Mini-cart / fragments: only the session load.
	$prod = $cart->cart_contents['k1']['data'];
	near( (float) $prod->get_price(), $incl ? 5.9774 : 4.94, 'prijs gezet bij het laden van de mand (' . ( $incl ? 'incl' : 'excl' ) . ')' );
	ok( (string) $prod->get_regular_price() === (string) $prod->get_price(), 'regular_price = price (' . ( $incl ? 'incl' : 'excl' ) . ')' );
	eq( $prod->get_sale_price(), '', 'geen sale_price (' . ( $incl ? 'incl' : 'excl' ) . ')' );
	near( Cart::unit_price( 'k1' ), 4.94, '_bps_unit_excl-bron blijft excl. btw (' . ( $incl ? 'incl' : 'excl' ) . ')' );
	$subs[] = $cart->subtotal_excl();
	$item          = new WC_Order_Item_Product( 900 + (int) $incl );
	$item->qty     = 10;
	Order::line_item_meta( $item, 'k1', $cart->cart_contents['k1'], new WC_Order( 77 ) );
	near( $item->get_meta( '_bps_unit_excl' ), 4.94, 'orderlijn _bps_unit_excl = 4,94 (' . ( $incl ? 'incl' : 'excl' ) . ')' );
}
near( $subs[0], 49.40, 'subtotaal excl. = 49,40 (prijzen excl. btw)', 0.01 );
near( $subs[1], 49.40, 'subtotaal excl. = 49,40 (prijzen incl. btw)', 0.01 );
$GLOBALS['wc_t']['prices_include_tax'] = false;
$h = $GLOBALS['bps_t']['hooks']['woocommerce_cart_loaded_from_session'] ?? array();
ok( isset( $h[20] ) && isset( $h[30] ) && has_action( 'woocommerce_cart_loaded_from_session', array( Cart::class, 'set_prices' ) ) === 30, 'set_prices op cart_loaded_from_session prio 30, na drop_orphans (20)' );

section( 'php-kwaliteit-4: prijsmotor gooit een exception → geen € 0, vlag, log met ref, specifieke melding' );
$cart = fresh_cart();
patch_line( $cart, 'k2', array( 'design_id' => 12, 'ref' => 'PS-2026-0042' ), 10 );
$cart->cart_contents['k2']['bps_unit_excl'] = 4.94; // Last good price from an earlier request (session).
$thrower = static function () {
	throw new RuntimeException( 'engine exploded' );
};
add_filter( 'bps_cart_unit_excl', $thrower, 10, 4 );
$log = array();
$cart->calculate_totals();
$prod = $cart->cart_contents['k2']['data'];
ok( (float) $prod->get_price() > 0, 'lijn heeft geen prijs 0 (vorige prijs behouden: ' . $prod->get_price() . ')' );
ok( ! empty( $cart->cart_contents['k2']['bps_price_error'] ), 'lijn gemarkeerd met bps_price_error' );
eq( Cart::price_errors(), array( 'k2' => 'PS-2026-0042' ), 'fout onthouden met ref' );
$hit = array_values( array_filter( $log, static fn( $l ) => false !== strpos( $l[1], 'cart_price_failed' ) ) );
ok( 1 === count( $hit ) && false !== strpos( $hit[0][1], 'PS-2026-0042' ) && false !== strpos( $hit[0][1], 'embroidered' ) && false !== strpos( $hit[0][1], 'engine exploded' ), 'Logger::error met ref, techniek en boodschap: ' . ( $hit[0][1] ?? '-' ) );
ok( false !== strpos( $hit[0][1] ?? '', '"qty":10' ), 'log bevat het aantal' );
Designs::$records[12] = array( 'id' => 12, 'ref' => 'PS-2026-0042', 'payload' => array( 'technique' => 'embroidered' ), 'preview_url' => '' );
Cart::check_cart_items();
$n = wc_get_notices( 'error' );
ok( 1 === count( $n ) && false !== strpos( $n[0]['notice'], 'We konden de prijs van ontwerp PS-2026-0042 niet berekenen' ), 'error-notice met de ontwerpreferentie: ' . ( $n[0]['notice'] ?? '-' ) );
$cart2 = fresh_cart();
patch_line( $cart2, 'k3', array( 'design_id' => 13, 'ref' => 'PS-2026-0043' ), 10 );
$cart2->calculate_totals();
ok( '0' === (string) $cart2->cart_contents['k3']['data']->get_price() || 0.0 === (float) $cart2->cart_contents['k3']['data']->get_price(), 'zonder vorige prijs wordt niets gezet (product blijft op zijn opgeslagen prijs)' );
ok( false === in_array( 0.0, array_map( 'floatval', array( Cart::unit_price( 'k3' ) ?? -1 ) ), true ), 'geen unit_price 0 voor de ordermeta' );
remove_all_filters( 'bps_cart_unit_excl' );
$cart2->calculate_totals();
near( (float) $cart2->cart_contents['k3']['data']->get_price(), 4.94, 'na herstel van de motor: prijs opnieuw gezet' );
ok( empty( $cart2->cart_contents['k3']['bps_price_error'] ) && ! isset( Cart::price_errors()['k3'] ), 'vlag weer weg' );

section( 'woo-compat-9: Product Add-Ons uitgesloten + echte Woo-notice gelogd bij een geweigerde add' );
$prod500 = wc_get_product( 500 );
Product::ensure();
eq( $prod500->get_meta( '_product_addons_exclude_global' ), '1', '_product_addons_exclude_global = 1 na ensure/repair' );
settings( array( 'admin' => array( 'logLevel' => 'warning' ) ) );
$cart             = fresh_cart();
$cart->refuse_add = true;
$log              = array();
$design           = array(
	'id'          => 21,
	'ref'         => 'PS-2026-0021',
	'preview_url' => 'https://shop.test/p.png',
	'payload'     => array( 'technique' => 'embroidered', 'sizeCm' => 8, 'qty' => 10, 'shape' => 'round', 'options' => defaults_for( 'embroidered' ), 'texts' => array(), 'locale' => 'nl-BE' ),
);
$res = Cart::add_design( $design, array( 'v' => 'x', 'accepted' => true ) );
ok( is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'Cadeauverpakking' ), 'klant krijgt de echte reden' );
$hit = array_values( array_filter( $log, static fn( $l ) => false !== strpos( $l[1], 'cart_add_failed' ) ) );
ok( 1 === count( $hit ) && false !== strpos( $hit[0][1], 'Cadeauverpakking' ), 'Woo-notice gelogd (warning): ' . ( $hit[0][1] ?? '-' ) );
ok( 'warning' === ( $hit[0][0] ?? '' ), 'logniveau warning' );
settings( array() );
$log = array();
Cart::add_design( $design, array( 'v' => 'x', 'accepted' => true ) );
ok( 0 === count( array_filter( $log, static fn( $l ) => false !== strpos( $l[1], 'cart_add_failed' ) ) ), 'standaard logLevel error: warning niet geschreven (C1-drempel)' );

section( 'gdpr-juridisch-8: versie van de rechtenverklaring van de server, client_v apart, FR-sha1 = messages.js' );
$cart   = fresh_cart();
$res    = Cart::add_design( $design, array( 'v' => 'bogus', 'accepted' => true ) );
$key    = $res['key'];
$rights = $cart->cart_contents[ $key ]['bps']['rights'];
eq( $rights['v'], (string) Settings::get()['legal']['rightsTextVersion'], 'cart: v = legal.rightsTextVersion' );
eq( $rights['client_v'] ?? null, 'bogus', 'cart: clientwaarde als client_v' );
$item      = new WC_Order_Item_Product( 910 );
$item->qty = 10;
Order::line_item_meta( $item, $key, $cart->cart_contents[ $key ], new WC_Order( 78 ) );
$rc = $item->get_meta( '_bps_rights_consent' );
eq( $rc['v'], (string) Settings::get()['legal']['rightsTextVersion'], 'order item: _bps_rights_consent.v = legal.rightsTextVersion' );
eq( $rc['client_v'] ?? null, 'bogus', 'order item: client_v bewaard' );
$msgs = file_get_contents( $plugin . 'assets/src/ui/messages.js' );
preg_match_all( "/'ui\\.s5\\.rights':\\s*'((?:[^'\\\\]|\\\\.)*)'/u", $msgs, $m );
ok( 2 === count( $m[1] ), 'NL en FR ui.s5.rights gevonden in messages.js' );
eq( sha1( stripcslashes( $m[1][1] ) ), sha1( Order::rights_text( 'fr_BE' ) ), 'sha1 FR-tekst = sha1(Order::rights_text(fr_BE))' );
eq( sha1( stripcslashes( $m[1][0] ) ), sha1( Order::rights_text( 'nl_BE' ) ), 'sha1 NL-tekst = sha1(Order::rights_text(nl_BE))' );
$ntm = file_get_contents( $plugin . 'assets/src/nametape/messages.js' );
preg_match_all( "/'nt\\.s3\\.rights':\\s*'((?:[^'\\\\]|\\\\.)*)'/u", $ntm, $m2 );
eq( NtCart::rights_text( 'nl-BE' ), stripcslashes( $m2[1][0] ?? '' ), 'naamstrook NL-tekst = nt.s3.rights' );
eq( NtCart::rights_text( 'fr-BE' ), stripcslashes( $m2[1][1] ?? '' ), 'naamstrook FR-tekst = nt.s3.rights' );
$ntr = NtCart::rights_record( array( 'v' => 'old' ), 'fr-BE' );
ok( sha1( NtCart::rights_text( 'fr-BE' ) ) === $ntr['text_sha1'] && '' !== $ntr['at'] && 'old' === ( $ntr['client_v'] ?? '' ), 'naamstrook bewaart text_sha1, at en client_v' );
eq( $ntr['v'], (string) Settings::get()['legal']['rightsTextVersion'], 'naamstrook: v van de server' );

section( 'woo-compat-3 / klant-account-5: opnieuw bestellen via WooCommerce' );
$order           = new WC_Order( 300 );
$order->customer = 5;
$oi              = new WC_Order_Item_Product( 301 );
$oi->qty         = 25;
$oi->order_id    = 300;
$oi->product_id  = 500;
$payload         = array( 'technique' => 'embroidered', 'sizeCm' => 8, 'widthMm' => 80, 'heightMm' => 70, 'qty' => 25, 'shape' => 'round', 'options' => defaults_for( 'embroidered' ), 'texts' => array( array( 'text' => 'IRON CRUSADERS', 'font' => 'Oswald', 'heightMm' => 6, 'path' => 'arc_top' ) ), 'locale' => 'nl-BE' );
$oi->meta        = array(
	'bps_ref'             => 'PS-2026-0100',
	'_bps_design_id'      => 100,
	'_bps_technique_id'   => 'embroidered',
	'_bps_size_cm'        => 8.0,
	'_bps_shape_id'       => 'round',
	'_bps_options'        => json_encode( defaults_for( 'embroidered' ) ),
	'_bps_payload'        => json_encode( $payload ),
	'_bps_preview_url'    => 'https://shop.test/old.png',
	'_bps_rights_consent' => array( 'v' => 'x', 'at' => '2026-10-01T10:00:00Z' ),
);
$order->items                         = array( $oi );
$GLOBALS['wc_t']['orders'][300]       = $order;
Designs::$records                     = array(); // Cleanup removed the old design and its files.
$data = Order_Again::cart_item_data( array(), $oi, $order );
ok( ! empty( $data['bps'] ) && 'embroidered' === $data['bps']['technique'] && 8.0 === $data['bps']['size_cm'] && 'round' === $data['bps']['shape'], 'bps opnieuw opgebouwd uit de orderlijn (techniek, maat, vorm)' );
eq( $data['bps']['texts'][0]['text'] ?? null, 'IRON CRUSADERS', 'tekst mee' );
eq( $data['bps']['reorder_of'], array( 'design_id' => 100, 'order_id' => 300, 'ref' => 'PS-2026-0100' ), 'reorder_of verwijst naar het oude ontwerp en de oude order' );
ok( true === Product::block_direct_add( true, 500, 25, 0, array(), $data ), 'validatie laat de herbouwde lijn door' );
$GLOBALS['wc_t']['notices'] = array();
ok( false === Product::block_direct_add( true, 500, 25, 0, array(), array() ), 'zonder bps blijft rechtstreeks toevoegen geweigerd' );
$forged = array( 'bps' => array( 'rebuild' => 'deadbeefdeadbeef', 'technique' => 'embroidered' ) );
ok( false === Product::block_direct_add( true, 500, 25, 0, array(), $forged ), 'een verzonnen token wordt geweigerd' );
$line = Order_Again::finalize( array_merge( $data, array( 'key' => 'r1', 'product_id' => 500, 'quantity' => 25, 'data' => clone wc_get_product( 500 ) ) ), 'r1' );
ok( ! isset( $line['bps']['rebuild'] ) && ! isset( $line['bps']['_payload'] ), 'token en payload-snapshot weg na finalize' );
ok( $line['bps']['design_id'] > 0 && 100 !== $line['bps']['design_id'], 'nieuw ontwerp-ID: ' . $line['bps']['design_id'] );
$call = end( Designs::$clones );
ok( 100 === $call['id'] && ! empty( $call['args']['from_payload'] ) && 300 === $call['args']['reorder_of']['order_id'], 'Designs::clone( 100, from_payload, reorder_of ) (C3) want het oude ontwerp is opgeruimd' );
$cart                         = fresh_cart();
$cart->cart_contents['r1']    = $line;
$cart->calculate_totals();
ok( (float) $line['data']->get_price() > 0 && isset( $cart->fees->fees['bps-fee-embroidery'] ), 'actuele serverprijs + fee "Borduurkaart en instelkosten" met reorderKeepsSetup=false' );
settings( array( 'promises' => array( 'reorderKeepsSetup' => true ) ) );
$cart->calculate_totals();
ok( ! isset( $cart->fees->fees['bps-fee-embroidery'] ), 'met reorderKeepsSetup=true geen instelkosten voor de herbestelde regel' );
patch_line( $cart, 'n1', array( 'design_id' => 55, 'ref' => 'PS-2026-0055' ), 10 );
$cart->calculate_totals();
ok( isset( $cart->fees->fees['bps-fee-embroidery'] ), 'een nieuw ontwerp ernaast betaalt de instelkosten wel' );
unset( $cart->cart_contents['n1'] );
$rows = Cart::item_data( array(), $cart->cart_contents['r1'] );
$txt  = implode( ' | ', array_map( static fn( $r ) => $r['key'] . ': ' . $r['value'], $rows ) );
ok( false !== strpos( $txt, 'Herbestelling van PS-2026-0100 (bestelling #300)' ) && false !== strpos( $txt, 'geen nieuwe instelkosten' ), 'eerlijke mandregel: ' . $txt );
settings( array() );
$oi2      = new WC_Order_Item_Product( 302 );
$oi2->qty = 25;
Order::line_item_meta( $oi2, 'r1', $cart->cart_contents['r1'], new WC_Order( 301 ) );
ok( false !== strpos( (string) $oi2->get_meta( 'bps_reorder' ), 'Herbestelling van PS-2026-0100 (bestelling #300): bestaand borduurbestand gebruiken' ), 'zichtbare ordermeta "Herbestelling van"' );
eq( $oi2->get_meta( '_bps_reorder_of' )['order_id'] ?? null, 300, 'verborgen _bps_reorder_of' );
ok( (int) $oi2->get_meta( '_bps_design_id' ) === $line['bps']['design_id'], 'nieuwe orderlijn verwijst naar het nieuwe ontwerp (de oude order blijft aan het oude gekoppeld)' );
$failcart = array( 'x' => array( 'bps' => array( 'rebuild_failed' => true, 'ref' => 'PS-9' ) ), 'y' => array( 'bps' => array( 'ref' => 'PS-10' ) ) );
Order_Again::remove_failed( 300, array(), $failcart );
ok( ! isset( $failcart['x'] ) && isset( $failcart['y'] ), 'lijn zonder nieuw ontwerp wordt verwijderd met een melding' );
$actions = Order_Again::my_orders_action( array(), $order );
ok( isset( $actions['order-again'] ) && false !== strpos( $actions['order-again']['url'], 'order_again=300' ), '"Opnieuw bestellen" bij voltooide orders met een patch' );

section( 'klant-account-5: reorder_design() helper voor de REST-route (qty 50, nieuwe ref, prijs = Pricing)' );
Designs::$records[100] = array( 'id' => 100, 'ref' => 'PS-2026-0100', 'payload' => $payload, 'preview_url' => 'https://shop.test/old.png', 'order_id' => 300 );
$GLOBALS['bps_t']['meta'][100]['_bps_order_item_id'] = 301;
$GLOBALS['wc_t']['order_items'][301]                 = $oi;
$cart = fresh_cart();
$res  = Order_Again::reorder_design( 100, 50, array( 'v' => 'x', 'accepted' => true ) );
ok( is_array( $res ) && 1 === count( $cart->cart_contents ), '1 mandregel' );
$l = reset( $cart->cart_contents );
eq( $l['quantity'], 50, 'aantal 50' );
ok( $res['ref'] !== 'PS-2026-0100' && $l['bps']['design_id'] === $res['designId'], 'nieuwe ref ' . $res['ref'] );
near( Cart::unit_price( $l['key'] ), Pricing::cart_unit_excl( $l['bps'], 50, 0, Settings::get() ), 'prijs = Pricing' );
eq( $l['bps']['reorder_of']['order_id'], 300, 'reorder_of = order X' );

section( 'C5: locked_unit_excl alleen server-side; atelierprijs gehonoreerd' );
$cart = fresh_cart();
$res  = Cart::add_design( $design, array(), array( 'locked_unit_excl' => 3.5 ) );
$l    = $cart->cart_contents[ $res['key'] ];
near( (float) $l['data']->get_price(), 3.5, 'atelierprijs als prijs' );
$oi3      = new WC_Order_Item_Product( 303 );
$oi3->qty = 10;
Order::write_line_meta( $oi3, $l['bps'] );
near( $oi3->get_meta( '_bps_unit_excl' ), 3.5, 'write_line_meta: _bps_unit_excl = locked_unit_excl' );
eq( $oi3->get_meta( '_bps_price_locked' ), 1, 'gemarkeerd als vaste prijs' );

section( 'klant-account-2: mark_ordered koppelt een anoniem ontwerp aan de orderklant' );
Designs::$records[400]             = array( 'id' => 400, 'ref' => 'PS-2026-0400', 'payload' => array(), 'preview_url' => '' );
$GLOBALS['wc_t']['posts'][400]    = array( 'ID' => 400, 'post_author' => 0, 'post_type' => 'bps_design' );
$GLOBALS['wc_t']['posts'][401]    = array( 'ID' => 401, 'post_author' => 9, 'post_type' => 'bps_design' );
Designs::$records[401]             = array( 'id' => 401, 'ref' => 'PS-2026-0401', 'payload' => array(), 'preview_url' => '' );
$o                                 = new WC_Order( 500 );
$o->customer                       = 5;
$a                                 = new WC_Order_Item_Product( 501 );
$a->meta['_bps_design_id']         = 400;
$b                                 = new WC_Order_Item_Product( 502 );
$b->meta['_bps_design_id']         = 401;
$o->items                          = array( $a, $b );
Order::mark_ordered( $o );
eq( $GLOBALS['wc_t']['posts'][400]['post_author'], 5, 'post_author 0 → orderklant 5' );
eq( $GLOBALS['wc_t']['posts'][401]['post_author'], 9, 'bestaande auteur nooit overschreven' );

section( 'gdpr-juridisch-4: maatwerk ook voor alleen naamstroken of cross-sell' );
$cart                        = fresh_cart();
$cart->cart_contents['nt']   = array( 'bps_nt' => array( 'v' => 1 ), 'quantity' => 1 );
ok( Cart::has_custom_made() && ! Cart::has_design(), 'mand met alleen een naamstrook: has_custom_made' );
ob_start();
Cart::checkout_notice();
$html = ob_get_clean();
ok( false !== strpos( $html, 'bps-legal-note' ) && false !== strpos( $html, 'herroepingsrecht' ) && false === strpos( $html, 'digitale proef' ), 'klassieke kassa toont .bps-legal-note zonder proefbelofte' );
ok( false !== strpos( Cart::block_checkout_notice( '<div>checkout</div>' ), 'bps-legal-note' ), 'blokkassa toont .bps-legal-note' );
$cart->cart_contents = array( 'cs' => array( 'bps_cs' => array( 'item' => 'cap' ), 'quantity' => 1 ) );
ok( Cart::has_custom_made(), 'mand met alleen cross-sell: has_custom_made' );
$o2               = new WC_Order( 600 );
$i1               = new WC_Order_Item_Product( 601 );
$i1->meta['_bps_nt_schema'] = 1;
$o2->items        = array( $i1 );
ok( Order::has_custom_made( $o2 ) && ! Order::has_design( $o2 ), 'order met alleen een naamstrook: Order::has_custom_made' );
$o3        = new WC_Order( 700 );
$i2        = new WC_Order_Item_Product( 701 );
$i2->meta['_bps_cs'] = '{"item":"cap"}';
$o3->items = array( $i2 );
ok( Order::has_custom_made( $o3 ), 'order met alleen cross-sell: Order::has_custom_made' );

section( 'woo-compat-13: mini-cart hoogstens 2 metaregels; mand toont alles' );
$cart = fresh_cart();
patch_line( $cart, 'm1', array( 'design_id' => 30, 'ref' => 'PS-2026-0030', 'texts' => array( array( 'text' => 'CLUB', 'font' => 'Oswald', 'heightMm' => 6, 'path' => 'straight' ) ) ), 10 );
$full = Cart::item_data( array(), $cart->cart_contents['m1'] );
ok( count( $full ) >= 6 && 'Maatwerk' === end( $full )['key'], 'volledige mand: alle regels incl. Maatwerk (' . count( $full ) . ')' );
do_action( 'woocommerce_before_mini_cart' );
$mini = Cart::item_data( array(), $cart->cart_contents['m1'] );
do_action( 'woocommerce_after_mini_cart' );
eq( count( $mini ), 2, 'mini-cart: 2 regels' );
eq( $mini[0]['value'], 'PS-2026-0030', 'regel 1 = ref' );
ok( false !== strpos( $mini[1]['value'], '8 cm' ) && false !== strpos( $mini[1]['value'], ' · ' ), 'regel 2 = techniek · maat · vorm: ' . $mini[1]['value'] );
eq( count( Cart::item_data( array(), $cart->cart_contents['m1'] ) ), count( $full ), 'na de mini-cart weer alles' );

section( 'prestatie-1 (cart): previewvarianten via Designs::preview_url, terugval op de PNG' );
Designs::$sizes[30] = array( 'cart' => 'https://shop.test/p-300.webp', 'cart2x' => 'https://shop.test/p-600.webp' );
$img = Cart::thumbnail( '', $cart->cart_contents['m1'] );
ok( false !== strpos( $img, 'p-300.webp' ) && false !== strpos( $img, 'p-600.webp 2x' ) && false === strpos( $img, '.png' ), 'thumbnail = 300 webp + srcset 2x: ' . $img );
$api = Cart::store_api_images( array(), $cart->cart_contents['m1'] );
ok( 'https://shop.test/p-300.webp' === $api[0]->thumbnail && false !== strpos( $api[0]->srcset, '600w' ), 'Store API-afbeelding = varianten' );
unset( Designs::$sizes[30] );
$img = Cart::thumbnail( '', $cart->cart_contents['m1'] );
ok( false !== strpos( $img, 'previews/a.png' ) && false === strpos( $img, 'srcset' ), 'oud ontwerp zonder varianten: PNG' );

section( 'woo-compat-6: verzendschatting uit de Woo-zone BE, config als terugval' );
$GLOBALS['wc_t']['zone_methods'] = array(
	new BPS_Fake_Method( 'flat_rate', array( 'cost' => '6.95' ) ),
	new BPS_Fake_Method( 'free_shipping', array( 'requires' => 'min_amount', 'min_amount' => '200' ) ),
);
update_option( 'woocommerce_tax_display_cart', 'incl' );
Config::reset_cache();
$q = Pricing::quote( array( 'technique' => 'embroidered', 'sizeCm' => 8, 'qty' => 10, 'options' => defaults_for( 'embroidered' ), 'locale' => 'nl-BE' ) );
near( $q['shipping']['remainingForFreeInclVat'], 200 - $q['lineIncl'], '/quote gebruikt de Woo-drempel 200', 0.011 );
near( $q['shipping']['excl'], 6.95, 'vast tarief uit de zone' );
$chk = Config::shipping_check();
eq( $chk['status'], 'ok', 'geen adminmelding als de zone eenduidig is' );
$GLOBALS['wc_t']['zone_methods'] = array( new BPS_Fake_Method( 'flat_rate', array( 'cost' => '[qty]*2' ) ) );
Config::reset_cache();
$cfg = Settings::get();
$q   = Pricing::quote( array( 'technique' => 'embroidered', 'sizeCm' => 8, 'qty' => 10, 'options' => defaults_for( 'embroidered' ), 'locale' => 'nl-BE' ) );
near( $q['shipping']['excl'], (float) $cfg['shipping']['flat'], 'formule [qty]*2 → terugval op de config' );
eq( Config::shipping_check()['status'], 'warn', 'adminmelding bij een formule' );
update_option( 'woocommerce_tax_display_cart', 'excl' );
$GLOBALS['wc_t']['zone_methods'] = array(
	new BPS_Fake_Method( 'flat_rate', array( 'cost' => '5' ) ),
	new BPS_Fake_Method( 'free_shipping', array( 'requires' => 'either', 'min_amount' => '100' ) ),
);
Config::reset_cache();
near( Config::shipping_zone()['freeFromInclVat'], 121.0, 'drempel excl. (mand toont excl.) → omgezet naar incl.' );
$GLOBALS['wc_t']['zone_methods'] = array();
Config::reset_cache();

section( 'woo-compat-1: gezondheidscheck btw' );
settings( array( 'vat' => array( 'rate' => 0.20 ) ) );
$chk = Config::vat_check();
ok( 'warn' === $chk['status'] && false !== strpos( $chk['message'], '20' ) && false !== strpos( $chk['message'], '21' ), 'vat.rate 0,20 vs Woo 21 % → waarschuwing: ' . $chk['message'] );
settings( array() );
eq( Config::vat_check()['status'], 'ok', 'gelijk tarief → ok' );
$GLOBALS['wc_t']['tax_enabled'] = false;
eq( Config::vat_check()['status'], 'warn', 'btw-berekening uit → waarschuwing' );
$GLOBALS['wc_t']['tax_enabled'] = true;
$all = apply_filters( 'bps_status_checks', array() );
ok( in_array( 'bps_vat', array_column( $all, 'id' ), true ) && in_array( 'bps_shipping_zone', array_column( $all, 'id' ), true ), 'beide checks via bps_status_checks (C7)' );

section( 'concurrentie-features-7: leverdatum (Lead_Time)' );
$lt = array(
	'leadTime' => array(
		'productionDays' => array( 'embroidered' => 5 ),
		'proofDays'      => 1,
		'shippingDays'   => 1,
		'closures'       => array( array( 'from' => '2026-12-24', 'to' => '2027-01-03' ) ),
	),
);
$fri = new DateTimeImmutable( '2026-11-20 15:00:00', new DateTimeZone( 'Europe/Brussels' ) ); // Friday.
$e   = Lead_Time::estimate( $lt, 'embroidered', $fri );
eq( $e['from'], '2026-12-01', 'vrijdag + 7 werkdagen: weekend overgeslagen → di 1 dec' );
$e = Lead_Time::estimate( $lt, 'embroidered', new DateTimeImmutable( '2026-12-21 10:00:00' ) );
eq( $e['from'], '2027-01-08', 'vlak voor Kerst met sluitingsweek: start na 3 jan → vr 8 jan' );
$e = Lead_Time::estimate( array( 'leadTime' => array( 'productionDays' => array( 'embroidered' => 1 ), 'proofDays' => 0, 'shippingDays' => 0 ) ), 'embroidered', new DateTimeImmutable( '2026-04-30 10:00:00' ) );
eq( $e['from'], '2026-05-04', '1 mei is een feestdag (do 30 apr + 1 → ma 4 mei)' );
$e = Lead_Time::estimate( array( 'leadTime' => array( 'productionDays' => array( 'embroidered' => 1 ), 'proofDays' => 0, 'shippingDays' => 0 ) ), 'embroidered', new DateTimeImmutable( '2026-07-20 10:00:00' ) );
eq( $e['from'], '2026-07-22', '21 juli is een feestdag' );
eq( Lead_Time::easter( 2027 )->format( 'Y-m-d' ), '2027-03-28', 'Pasen 2027' );
ok( in_array( '2027-05-17', Lead_Time::holidays_be( 2027 ), true ) && in_array( '2027-05-06', Lead_Time::holidays_be( 2027 ), true ), 'Pinkstermaandag en Hemelvaart 2027' );
eq( Lead_Time::estimate( array(), 'embroidered' ), null, 'lege instellingen → null (geen verzonnen datum)' );
eq( Lead_Time::estimate( array( 'leadTime' => array( 'productionDays' => array( 'embroidered' => null ), 'proofDays' => 1, 'shippingDays' => 1 ) ), 'embroidered' ), null, 'productionDays null → null' );
$e = Lead_Time::estimate( array( 'leadTime' => array( 'productionDays' => array( 'embroidered' => array( 5, 8 ) ), 'proofDays' => 1, 'shippingDays' => array( 1, 2 ) ) ), 'embroidered', $fri );
ok( $e['from'] < $e['to'] && 7 === $e['businessDays']['from'] && 11 === $e['businessDays']['to'], 'bereik [min,max] → from < to' );
$q = Pricing::quote( array( 'technique' => 'embroidered', 'sizeCm' => 8, 'qty' => 10, 'locale' => 'nl-BE' ) );
ok( array_key_exists( 'estimate', $q ) && null === $q['estimate'], '/quote (live): estimate === null zonder instellingen' );
settings( $lt );
$q = Pricing::quote( array( 'technique' => 'embroidered', 'sizeCm' => 8, 'qty' => 10, 'locale' => 'nl-BE' ) );
ok( is_array( $q['estimate'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $q['estimate']['from'] ), '/quote (live): estimate {from,to} met instellingen' );
$qt = Pricing::quote( array( 'technique' => 'embroidered', 'sizeCm' => 8, 'qty' => 10, 'locale' => 'nl-BE' ), Settings::get() );
ok( ! array_key_exists( 'estimate', $qt ), 'met expliciete config (pariteit JS/PHP) geen estimate-sleutel' );
$cart = fresh_cart();
patch_line( $cart, 'lt', array( 'design_id' => 31, 'ref' => 'PS-31' ), 10 );
$order = new WC_Order( 800 );
Order::promise_dates( $order );
ok( '' !== $order->get_meta( '_bps_promised_from' ) && $order->get_meta( '_bps_promised_to' ) >= $order->get_meta( '_bps_promised_from' ), 'order krijgt _bps_promised_from/_to' );
$GLOBALS['wc_t']['orders'][800] = $order;
$fi           = new WC_Order_Item_Product( 801 );
$fi->order_id = 800;
$sec          = apply_filters( 'bps_fiche_sections', array(), array(), $fi );
ok( isset( $sec['delivery'] ) && false !== strpos( $sec['delivery']['rows'][0]['value'], ' ' ), 'fiche toont de beloofde levering: ' . ( $sec['delivery']['rows'][0]['value'] ?? '-' ) );
$fd = apply_filters( 'bps_fiche_data', array( 'sections' => array() ), $order, $fi );
ok( isset( $fd['promised']['from'] ) && isset( $fd['sections']['delivery'] ), 'bps_fiche_data (C6) krijgt promised + sectie' );
settings( array() );
$order2 = new WC_Order( 801 );
Order::promise_dates( $order2 );
eq( $order2->get_meta( '_bps_promised_from' ), '', 'zonder instellingen geen beloofde datum op de order' );

section( 'concurrentie-features-9: namenvarianten' );
$names = array();
for ( $i = 1; $i <= 12; $i++ ) {
	$names[] = 'Speler ' . $i;
}
$raw   = array( 'texts' => array( array( 'text' => 'Speler 1', 'variable' => true, 'values' => array_merge( array( '  ', '' ), $names ) ) ) );
$clean = Variant_Names::clean( array( 'qty' => 12, 'texts' => array( array( 'text' => 'Speler 1' ) ) ), $raw );
eq( count( $clean['texts'][0]['values'] ), 12, 'lege regels weg, 12 namen' );
eq( Variant_Names::validate( null, $clean, $raw ), null, '12 namen en qty 12 → geldig' );
$bad = Variant_Names::validate( null, array( 'qty' => 10 ) + $clean, $raw );
ok( is_wp_error( $bad ) && 'bps_variant_invalid' === $bad->get_error_code() && 'qty_mismatch' === $bad->get_error_data()['reason'], 'qty ≠ aantal namen → geweigerd' );
$many  = array_map( static fn( $i ) => 'Naam ' . $i, range( 1, 501 ) );
$c501  = Variant_Names::clean( array( 'qty' => 501, 'texts' => array( array( 'text' => 'Naam 1' ) ) ), array( 'texts' => array( array( 'text' => 'Naam 1', 'variable' => true, 'values' => $many ) ) ) );
$e501  = Variant_Names::validate( null, $c501, array() );
ok( is_wp_error( $e501 ) && 'bps_variant_invalid' === $e501->get_error_code() && 501 === $e501->get_error_data()['lines'][0]['line'], 'een 501e naam → bps_variant_invalid' );
$cbad = Variant_Names::clean( array( 'qty' => 3, 'texts' => array( array( 'text' => 'Jan' ) ) ), array( 'texts' => array( array( 'text' => 'Jan', 'variable' => true, 'values' => array( 'Jan', 'Pi€t', 'Jo☺' ) ) ) ) );
$ebad = Variant_Names::validate( null, $cbad, array() );
ok( is_wp_error( $ebad ) && array( 2, 3 ) === array_column( $ebad->get_error_data()['lines'], 'line' ), 'ongeldige tekens per regelnummer gemeld (2, 3): ' . ( is_wp_error( $ebad ) ? $ebad->get_error_message() : '-' ) );
$two = Variant_Names::validate( null, array( 'qty' => 1, 'texts' => array( array( 'text' => 'a', 'variable' => true, 'values' => array( 'a' ) ), array( 'text' => 'b', 'variable' => true, 'values' => array( 'b' ) ) ) ), array() );
ok( is_wp_error( $two ), 'hoogstens één variabele tekstlaag' );
settings( array( 'fees' => array( 'perVariantName' => 1.5 ) ) );
$base = Pricing::quote( array( 'technique' => 'embroidered', 'sizeCm' => 8, 'qty' => 12, 'options' => defaults_for( 'embroidered' ), 'locale' => 'nl-BE' ), Settings::get() );
$var  = Pricing::quote( array( 'technique' => 'embroidered', 'sizeCm' => 8, 'qty' => 12, 'variantCount' => 12, 'options' => defaults_for( 'embroidered' ), 'locale' => 'nl-BE' ), Settings::get() );
near( $var['unitExcl'] - $base['unitExcl'], 1.5, '12 namen: + perVariantName per stuk' );
near( $var['lineExcl'] - $base['lineExcl'], 18.0, '12 × perVariantName op de lijn', 0.011 );
eq( count( $var['fees'] ), 1, '1× opzetkosten' );
eq( $var['tierQty'], 12, 'staffel op het totaal (12)' );
$fi2                              = new WC_Order_Item_Product( 901 );
$fi2->meta['_bps_variant_names'] = json_encode( $names );
$sec                              = Variant_Names::fiche_sections( array(), array(), $fi2 );
eq( count( $sec['variant_names']['rows'] ), 12, 'fiche: 12 genummerde regels' );
eq( $sec['variant_names']['rows'][11], array( 'label' => '12', 'value' => 'Speler 12', 'mono' => true ), 'regel 12' );
$csv = Variant_Names::csv( array( 'Jan', '=cmd', 'Piet "P"' ) );
ok( false !== strpos( $csv, "2;\"'=cmd\"" ) && false !== strpos( $csv, '"Piet ""P"""' ), 'CSV: formule-injectie geneutraliseerd en quotes ge-escaped' );
settings( array() );

section( 'i18n-a11y-6: meervouden in de mandmeldingen' );
$err = Cart::quote_error( array( 'ok' => false, 'technique' => 'embroidered', 'errors' => array( array( 'code' => 'below_min_qty', 'params' => array( 'min' => 1 ) ) ) ) );
ok( false !== strpos( $err->get_error_message(), 'vanaf 1 stuk.' ), '1 stuk: ' . $err->get_error_message() );
$err = Cart::quote_error( array( 'ok' => false, 'technique' => 'pvc', 'errors' => array( array( 'code' => 'below_min_qty', 'params' => array( 'min' => 50 ) ) ) ) );
ok( false !== strpos( $err->get_error_message(), 'vanaf 50 stuks.' ), '50 stuks: ' . $err->get_error_message() );

section( 'Naamstroken: bloodConsent van P03 bewaard; geen € 0' );
$batch = array(
	'locale'       => 'nl-BE',
	'format'       => 'f1',
	'custom'       => null,
	'fabric'       => 'black',
	'letter'       => 'white',
	'border'       => 'none',
	'borderColor'  => null,
	'font'         => 'f',
	'uppercase'    => true,
	'backing'      => 'hook',
	'loop'         => false,
	'bloodMode'    => 'separate',
	'rows'         => array( array( 'name' => 'Jan', 'line2' => '', 'blood' => array( 'group' => 'O', 'rh' => '+' ), 'qty' => 1 ) ),
	'orgNote'      => '',
	'bloodConsent' => array( 'v' => '1', 'at' => '2026-11-18T10:00:00Z', 'text_sha1' => 'abc' ),
);
$d = NtCart::data_from_batch( $batch, array( 'v' => 'x' ), '', '', array(), 2 );
eq( $d['bloodConsent']['text_sha1'] ?? null, 'abc', 'bloodConsent mee in de mandregel' );
unset( $batch['bloodConsent'] );
ok( ! isset( NtCart::data_from_batch( $batch, array(), '', '', array(), 1 )['bloodConsent'] ), 'zonder toestemming geen sleutel' );

section( 'php-kwaliteit-7: geen defaults.json-fallbacks of class_exists op eigen klassen' );
$src = file_get_contents( $plugin . 'includes/woo/class-config.php' ) . file_get_contents( $plugin . 'includes/class-pricing.php' );
ok( false === strpos( $src, 'defaults.json' ), 'defaults.json niet meer gelezen in Woo\\Config / Pricing' );
foreach ( array( 'woo/class-cart.php', 'woo/class-product.php', 'woo/class-order.php', 'woo/class-config.php', 'class-pricing.php', 'nametape/class-cart.php', 'crosssell/class-cart.php', 'crosssell/class-service.php' ) as $f ) {
	ok( ! preg_match( '/class_exists\(\s*(Settings|Pricing|Designs|MainPricing|WooConfig)::class/', file_get_contents( $plugin . 'includes/' . $f ) ), "geen class_exists op eigen klassen in $f" );
}

echo "\n" . ( $GLOBALS['count'] - $GLOBALS['fails'] ) . '/' . $GLOBALS['count'] . " ok\n";
exit( $GLOBALS['fails'] ? 1 : 0 );
