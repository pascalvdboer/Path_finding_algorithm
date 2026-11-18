<?php
/**
 * Commerce tests (rounds, packs, coupons, services, checkout, fulfilment, withdrawal, admin sanitizing).
 * No WordPress, no database, no network: WP stubs + in-memory WooCommerce fakes.
 *
 * Run: php tests/services/run-services-php.php   (also through `npm test`, tests/services/services-php.test.js)
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

require __DIR__ . '/php/wp-stubs.php';
require __DIR__ . '/php/wc-fakes.php';

$base = dirname( __DIR__, 2 ) . '/plugin/borduur-patch-studio/includes/services/';
foreach ( array( 'config', 'text', 'owner', 'credits-store', 'credits-store-memory', 'credits', 'email-gate', 'products', 'order-credit', 'services', 'service-status', 'checkout', 'fulfilment', 'withdrawal', 'admin-sections', 'rest', 'module' ) as $f ) {
	require_once $base . 'class-' . $f . '.php';
}

use BorduurPatchStudio\Services\Admin_Sections;
use BorduurPatchStudio\Services\Checkout;
use BorduurPatchStudio\Services\Config;
use BorduurPatchStudio\Services\Credits;
use BorduurPatchStudio\Services\Credits_Store_Memory;
use BorduurPatchStudio\Services\Email_Gate;
use BorduurPatchStudio\Services\Fulfilment;
use BorduurPatchStudio\Services\Module;
use BorduurPatchStudio\Services\Order_Credit;
use BorduurPatchStudio\Services\Owner;
use BorduurPatchStudio\Services\Products;
use BorduurPatchStudio\Services\Rest;
use BorduurPatchStudio\Services\Service_Status;
use BorduurPatchStudio\Services\Services;
use BorduurPatchStudio\Services\Text;
use BorduurPatchStudio\Services\Withdrawal;

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
function section( string $s ): void {
	echo "· $s\n";
}

Owner::$standalone = true;
Owner::$salt       = 'unit-salt';
Config::set_override( array( 'mode' => 'live' ) );

// Fixed product map: packs 101/102, services 201.. ; patch product 500.
$map = array(
	'pack:ai_pack_10'          => 101,
	'pack:ai_pack_25'          => 102,
	'service:d1_finish'        => 201,
	'service:d2_redraw'        => 202,
	'service:d2b_redraw_xl'    => 203,
	'service:d3_file'          => 204,
	'service:d3b_file_addon'   => 205,
	'service:d6_sample'        => 206,
	'service:d7_photo_test'    => 207,
);
function reset_all( array $map ): void {
	bps_svc_reset();
	bps_wc_reset();
	update_option( Products::OPTION, $map );
	update_option( 'bps_product_id', 500 );
	update_option( 'admin_email', 'atelier@shop.test' );
}
reset_all( $map );

$now   = strtotime( '2026-11-18 10:00:00 UTC' );
$clock = function () use ( &$now ) {
	return $now;
};
function fresh( callable $clock ): Credits {
	$c = new Credits( new Credits_Store_Memory(), $clock );
	Credits::set_instance( $c );
	Fulfilment::$credits = $c;
	return $c;
}

/* ------------------------------------------------------------------ */
section( 'Config: pakketprijzen en btw' );
$packs = Config::packs();
eq( count( $packs ), 2, '2 pakketten' );
eq( $packs[0]['priceIncl'], 4.95, 'ai_pack_10 = € 4,95 incl.' );
eq( $packs[1]['priceIncl'], 9.95, 'ai_pack_25 = € 9,95 incl.' );
eq( Config::round_half_up( 230.175, 2 ), 230.18, 'half-up op de cent' );
eq( Config::excl( 4.95 ), 4.0909, 'excl met 4 decimalen' );
Config::set_override( array( 'mode' => 'live', 'packs' => array( array( 'id' => 'ai_pack_10', 'rounds' => 10, 'priceExcl' => 0, 'enabled' => true ) ) ) );
eq( count( Config::packs() ), 0, 'pakket zonder prijs is niet koopbaar' );
Config::set_override( array( 'mode' => 'live' ) );

/* ------------------------------------------------------------------ */
section( 'Credits: toekennen, verbruiken, terugboeken' );
$c    = fresh( $clock );
$anon = Owner::for_anon( str_repeat( 'a', 32 ) );
$mail = Owner::for_email( 'Klant@Example.be ' );
eq( $mail, Owner::for_email( 'klant@example.be' ), 'e-mailsleutel genormaliseerd' );
ok( Owner::valid( $anon ) && 66 === strlen( $anon ), 'eigenaarssleutel 66 tekens' );
eq( $c->grant_free_anon( $anon, 'ip1' ), 1, '1 gratis anonieme ronde' );
eq( $c->grant_free_anon( $anon, 'ip1' ), 0, 'niet twee keer voor dezelfde browser' );
eq( $c->grant_free_anon( Owner::for_anon( str_repeat( 'b', 32 ) ), 'ip1' ), 1, '2de browser zelfde IP' );
eq( $c->grant_free_anon( Owner::for_anon( str_repeat( 'c', 32 ) ), 'ip1' ), 1, '3de browser zelfde IP' );
eq( $c->grant_free_anon( Owner::for_anon( str_repeat( 'd', 32 ) ), 'ip1' ), 0, 'max 3 per IP per dag' );
$b = $c->balance( $anon );
eq( array( $b['free'], $b['paid'], $b['total'] ), array( 1, 0, 1 ), 'saldo anoniem' );

$lot1 = $c->reserve( $anon, 1, 'JOB000000000000000000000A1', 'generate' );
ok( null !== $lot1, 'reserveren lukt' );
eq( $c->reserve( $anon, 1, 'JOB000000000000000000000A1' ), $lot1, 'zelfde job = zelfde reservering (dubbel tikken)' );
eq( $c->balance( $anon )['total'], 0, 'saldo 0 na verbruik' );
eq( $c->reserve( $anon, 1, 'JOB000000000000000000000A2' ), null, 'geen rondes meer → null (402)' );
ok( $c->refund( 'JOB000000000000000000000A1', 'job_failed' ), 'terugboeken na fout' );
ok( ! $c->refund( 'JOB000000000000000000000A1', 'job_failed' ), 'terugboeken is idempotent' );
eq( $c->balance( $anon )['total'], 1, 'ronde terug' );

// Spending order: free before paid, earliest expiry first.
$user = Owner::for_user( 7 );
$pack = $c->grant( $user, 'pack', 10, array( 'order_id' => 1, 'order_item_id' => 11, 'pack_id' => 'ai_pack_10', 'price_incl' => 4.95, 'consent_at' => '2026-11-18 10:00:00' ) );
eq( $c->grant( $user, 'pack', 10, array( 'order_id' => 1, 'order_item_id' => 11 ) ), $pack, 'toekennen idempotent per orderregel' );
$free = $c->grant( $user, 'free_order', 5, array( 'order_id' => 2 ) );
eq( $c->reserve( $user, 1, 'JOB000000000000000000000B1' ), $free, 'gratis rondes eerst' );
eq( $c->balance( $user )['paid'], 10, 'betaalde rondes onaangeroerd' );

// Locked paid lot without consent.
$lock = $c->grant( Owner::for_user( 8 ), 'pack', 10, array( 'order_id' => 3, 'order_item_id' => 31, 'price_incl' => 4.95 ) );
$b8   = $c->balance( Owner::for_user( 8 ) );
eq( array( $b8['paid'], $b8['paidLocked'] ), array( 0, 10 ), 'zonder toestemming: 10 vergrendeld' );
eq( $c->reserve( Owner::for_user( 8 ), 1, 'JOB000000000000000000000C1' ), null, 'vergrendelde rondes niet bruikbaar' );
eq( $c->consent( Owner::for_user( 8 ) ), 1, 'toestemming achteraf ontgrendelt' );
eq( $c->balance( Owner::for_user( 8 ) )['paid'], 10, 'nu bruikbaar' );
$now += 15 * DAY_IN_SECONDS;
$lock2 = $c->grant( Owner::for_user( 9 ), 'pack', 10, array( 'order_id' => 4, 'order_item_id' => 41, 'price_incl' => 4.95 ) );
$now  += 15 * DAY_IN_SECONDS;
eq( $c->balance( Owner::for_user( 9 ) )['paid'], 10, 'na 14 dagen vanzelf bruikbaar' );

// Withdrawal amount.
$c->reserve( Owner::for_user( 9 ), 1, 'JOB000000000000000000000D1' );
$c->reserve( Owner::for_user( 9 ), 1, 'JOB000000000000000000000D2' );
$c->reserve( Owner::for_user( 9 ), 1, 'JOB000000000000000000000D3' );
eq( Credits::refund_amount( $c->store()->get_lot( $lock2 ) )['amount'], 3.47, '7/10 van € 4,95 = € 3,47 (half-up)' );

// Expiry.
$c2 = fresh( $clock );
$c2->grant( $anon, 'free_anon', 1 );
$now += 31 * DAY_IN_SECONDS;
eq( $c2->balance( $anon )['total'], 0, 'anonieme ronde vervalt na 30 dagen' );
eq( $c2->expire(), 1, 'expire() sluit de kavel' );
eq( $c2->store()->ledger_where( array( 'kind' => 'expire' ), 5 )[0]['delta'], -1, 'grootboek: verval' );

// Merge: anonymous → e-mail; the free anonymous round counts once.
$c3 = fresh( $clock );
$a1 = Owner::for_anon( str_repeat( 'e', 32 ) );
$a2 = Owner::for_anon( str_repeat( 'f', 32 ) );
$c3->grant( $a1, 'free_anon', 1 );
$c3->grant( $a2, 'free_anon', 1 );
eq( $c3->merge( $a1, $mail ), 1, '1 ronde samengevoegd' );
eq( $c3->merge( $a2, $mail ), 0, 'tweede anonieme gratis ronde telt niet opnieuw' );
eq( $c3->balance( $mail )['total'], 1, 'saldo e-mail = 1' );
eq( $c3->merge( $user, $mail ), 0, 'nooit van sterk naar zwak' );

/* ------------------------------------------------------------------ */
section( 'E-mailpoort: code, pogingen, +2 eenmalig' );
reset_all( $map );
$c4                     = fresh( $clock );
Email_Gate::$fixed_code = '123456';
$sent                   = array();
Email_Gate::$mailer     = function ( $to, $s, $body ) use ( &$sent ) {
	$sent[] = compact( 'to', 's', 'body' );
	return true;
};
$browser = Owner::for_anon( str_repeat( '1', 32 ) );
$c4->grant( $browser, 'free_anon', 1 );
eq( Email_Gate::request( 'geen-adres', 'ipx', 'nl', $c4 )->get_error_code(), 'bps_ai_invalid', 'ongeldig adres' );
eq( Email_Gate::request( 'jan@club.be', 'ipx', 'nl', $c4 ), array( 'sent' => true ), 'code verstuurd' );
ok( false !== strpos( $sent[0]['body'], '123456' ) && false === strpos( $sent[0]['body'], '{' ), 'mail bevat de code, geen open plaatshouders' );
eq( Email_Gate::verify( 'jan@club.be', '000000', $browser, 'nl', $c4 )->get_error_code(), 'bps_ai_code_wrong', 'foute code' );
$res = Email_Gate::verify( 'jan@club.be', '123456', $browser, 'nl', $c4 );
eq( $res['granted'], 2, '+2 rondes' );
eq( $res['balance']['total'], 3, '1 anoniem + 2 e-mail samengevoegd' );
Email_Gate::request( 'jan@club.be', 'ipx', 'nl', $c4 );
eq( Email_Gate::verify( 'JAN@club.be', '123456', '', 'nl', $c4 )['granted'], 0, 'tweede keer geen extra rondes' );
for ( $j = 0; $j < 3; $j++ ) {
	Email_Gate::request( 'x@club.be', 'ipy', 'fr', $c4 );
}
eq( Email_Gate::request( 'x@club.be', 'ipy', 'fr', $c4 )->get_error_code(), 'bps_ai_slow_down', 'max 3 codes per adres per uur' );
ok( false !== strpos( $sent[ count( $sent ) - 1 ]['body'], '123456' ) && $sent[ count( $sent ) - 1 ]['s'] !== $sent[0]['s'], 'FR-mail met eigen onderwerp' );

/* ------------------------------------------------------------------ */
section( 'Verrekening: couponbedrag en automatische keuze' );
eq( Order_Credit::amount( 4.95, 100, true, 0.21 ), 4.95, 'winkel incl. btw: 4,95' );
eq( Order_Credit::amount( 4.95, 100, false, 0.21 ), 4.0909, 'winkel excl. btw: 4,0909' );
eq( Order_Credit::amount( 9.95, 50, true, 0.21 ), 4.98, '50 %: 4,98 (half-up)' );
ok( preg_match( '/^AI-[0-9A-HJKMNP-TV-Z]{8}$/', Order_Credit::code() ) === 1, 'code AI- + 8 Crockford-tekens' );
$cands = array(
	array( 'code' => 'AI-AAAA', 'amount' => 4.95, 'minIncl' => 50, 'minQty' => 0, 'expires' => $now + 1000, 'used' => false ),
	array( 'code' => 'AI-BBBB', 'amount' => 9.95, 'minIncl' => 50, 'minQty' => 0, 'expires' => $now + 1000, 'used' => false ),
	array( 'code' => 'AI-CCCC', 'amount' => 20, 'minIncl' => 50, 'minQty' => 0, 'expires' => $now - 1, 'used' => false ),
	array( 'code' => 'AI-DDDD', 'amount' => 30, 'minIncl' => 50, 'minQty' => 0, 'expires' => 0, 'used' => true ),
	array( 'code' => 'STAAL-EEEE', 'amount' => 19.95, 'minIncl' => 0, 'minQty' => 25, 'expires' => 0, 'used' => false ),
);
eq( Order_Credit::choose( $cands, array( 'patchSubtotalIncl' => 49.99, 'maxPatchQty' => 10, 'now' => $now, 'applied' => array() ) ), null, 'onder het minimum: geen coupon' );
eq( Order_Credit::choose( $cands, array( 'patchSubtotalIncl' => 50, 'maxPatchQty' => 10, 'now' => $now, 'applied' => array() ) ), 'AI-BBBB', 'hoogste geldige, niet vervallen, niet gebruikt' );
eq( Order_Credit::choose( $cands, array( 'patchSubtotalIncl' => 80, 'maxPatchQty' => 30, 'now' => $now, 'applied' => array() ) ), 'STAAL-EEEE', 'staalkorting vanaf 25 stuks' );
eq( Order_Credit::choose( $cands, array( 'patchSubtotalIncl' => 80, 'maxPatchQty' => 30, 'now' => $now, 'applied' => array( 'ai-aaaa' ) ) ), null, 'nooit twee kortingscodes' );

/* ------------------------------------------------------------------ */
section( 'Diensten: slimme regels (gedeelde vectoren)' );
$vec     = json_decode( file_get_contents( __DIR__ . '/offer-vectors.json' ), true );
$catalog = Services::catalog( 'nl' );
foreach ( $vec['vectors'] as $v ) {
	$offer = Services::evaluate( $v['signals'], $catalog, $v['dismissed'] ?? array() );
	eq( $offer ? $offer['serviceId'] : null, $v['expect'], 'vector: ' . $v['name'] );
	if ( $offer ) {
		ok( false === $offer['preselected'] && '' !== $offer['decline'], 'nooit vooraf gekozen, altijd "Nee, dank je": ' . $v['name'] );
		ok( false === strpos( $offer['cta'] . $offer['reason'], '{' ), 'geen open plaatshouders: ' . $v['name'] );
	}
}
Config::set_override( array( 'mode' => 'live', 'services' => array( 'd4_rush' => array( 'enabled' => true ) ) ) );
$catalog = Services::catalog( 'nl' );
foreach ( $vec['rushEnabled'] as $v ) {
	$offer = Services::evaluate( $v['signals'], $catalog, array() );
	eq( $offer ? $offer['serviceId'] : null, $v['expect'], 'spoed: ' . $v['name'] );
	if ( isset( $v['priceIncl'] ) && $offer ) {
		eq( $offer['priceIncl'], (float) $v['priceIncl'], 'spoedprijs: ' . $v['name'] );
	}
}
Config::set_override( array( 'mode' => 'live' ) );
eq( Services::rush_amount( 300, 25, 25 ), 75.0, 'spoed 25 % van € 300' );
eq( Services::rush_amount( 40, 25, 25 ), 25.0, 'spoed minimum € 25' );
$d7 = Config::service( 'd7_photo_test' );
eq( Services::line_price( $d7, 0, 30, true ), 0.0, 'fototest gratis vanaf 25 stuks' );
eq( Services::line_price( $d7, 0, 10, true ), $d7['priceIncl'], 'fototest betalend onder 25 stuks' );
eq( Services::add_working_days( '2026-11-20 10:00:00', 3 ), '2026-11-25', '3 werkdagen vanaf vrijdag = woensdag' );
foreach ( Services::catalog( 'fr' ) as $def ) {
	ok( '' !== $def['label'] && false !== strpos( $def['priceHtml'], '€' ), 'FR-catalogus: ' . $def['id'] );
}
ok( ! in_array( 'd5_extra_proof', array_column( $catalog, 'id' ), true ), 'D5 standaard uit (niet in catalogus)' );

/* ------------------------------------------------------------------ */
section( 'Kassa: toestemmingen' );
$rec = Checkout::pack_record( true, false, 'fr_BE', '2026-11-18 10:00:00' );
eq( array( $rec['start'], $rec['ack'], $rec['locale'] ), array( true, false, 'fr' ), 'record per vakje + taal' );
eq( $rec['v'], Text::legal_version(), 'tekstversie bewaard' );
eq( strlen( $rec['text_sha1'] ), 40, 'hash van de getoonde tekst' );
ok( ! Checkout::pack_consented( $rec ), 'één vakje is geen toestemming' );
ok( Checkout::pack_consented( Checkout::pack_record( true, true, 'nl', '2026-11-18 10:00:00' ) ), 'twee vakjes = toestemming' );
eq( Checkout::needs( array( 101, 500 ) ), array( 'pack' => true, 'svcConsent' => false ), 'pakket in de mand' );
eq( Checkout::needs( array( 202 ) ), array( 'pack' => false, 'svcConsent' => true ), 'dienst met toestemming' );
eq( Checkout::needs( array( 206 ) ), array( 'pack' => false, 'svcConsent' => false ), 'fysiek staal: geen toestemming nodig' );
ok( '' !== Text::legal( 'legal.ai.pack_consent_start', 'nl' ) && '' !== Text::legal( 'legal.svc.consent', 'fr' ), 'juridische teksten gevonden' );

/* ------------------------------------------------------------------ */
section( 'Dienststatus' );
$i = Service_Status::initial( false, true, '2026-11-18 10:00:00', 3, 14 );
eq( array( $i['status'], $i['startAfter'] ), array( 'waiting', '2026-12-02' ), 'zonder toestemming: start na 14 dagen' );
eq( $i['readyBy'], '2026-12-07', 'klaar 3 werkdagen na de start' );
$i = Service_Status::initial( true, true, '2026-11-18 10:00:00', 3, 14 );
eq( array( $i['status'], $i['readyBy'] ), array( 'queued', '2026-11-23' ), 'met toestemming: meteen ingepland' );
eq( Service_Status::initial( false, false, '2026-11-18 10:00:00', 10 )['status'], 'queued', 'staal: geen wachttijd' );
ok( Service_Status::allowed( 'queued', 'in_progress' ) && Service_Status::allowed( 'in_progress', 'proof_sent' ), 'normale stappen' );
ok( ! Service_Status::allowed( 'delivered', 'in_progress' ) && Service_Status::allowed( 'delivered', 'queued' ), 'na levering enkel heropenen' );
ok( ! Service_Status::allowed( 'queued', 'bogus' ), 'onbekende status geweigerd' );
foreach ( Service_Status::STATUSES as $st ) {
	foreach ( array( 'nl', 'fr' ) as $lang ) {
		$m = Service_Status::mail_text( $st, array( 'service' => 'X', 'nr' => '12', 'date' => '01-12-2026', 'name' => 'An', 'note' => '', 'startAfter' => '2026-12-02' ), $lang );
		ok( $m && false === strpos( $m['subject'] . $m['body'], '{' ), "mail $st/$lang zonder open plaatshouders" );
	}
}

/* ------------------------------------------------------------------ */
section( 'Fulfilment: betaald → rondes, coupon, gratis rondes, diensten' );
reset_all( $map );
$c5                     = fresh( $clock );
$mails                  = array();
Service_Status::$mailer = function ( $to, $s, $b ) use ( &$mails ) {
	$mails[] = compact( 'to', 's', 'b' );
	return true;
};
$GLOBALS['bps_t']['prices_incl'] = true;
$o           = new WC_Order( 900 );
$o->email    = 'club@example.be';
$o->customer = 0;
$o->add_item( new WC_Order_Item_Product( 1, 101, 1, 4.09, 0.86, 'Pakket 10' ) );
$p           = $o->add_item( new WC_Order_Item_Product( 2, 500, 50, 200, 42, 'Patch' ) );
$p->update_meta_data( '_bps_design_id', 55 );
$svc = $o->add_item( new WC_Order_Item_Product( 3, 202, 1, 20.62, 4.33, 'Hertekenen' ) );
$svc->update_meta_data( '_bps_service', array( 'id' => 'd2_redraw', 'designRef' => 'PS-1', 'leadDays' => 3 ) );
$stl = $o->add_item( new WC_Order_Item_Product( 4, 206, 1, 16.49, 3.46, 'Staal' ) );
$stl->update_meta_data( '_bps_service', array( 'id' => 'd6_sample', 'designRef' => 'PS-1', 'leadDays' => 10 ) );
$hint = Owner::for_anon( str_repeat( '9', 32 ) );
$c5->grant( $hint, 'free_anon', 1 );
Checkout::apply( $o, true, true, false );
$o->update_meta_data( Checkout::OWNER_HINT, $hint );
ok( '' !== (string) $svc->get_meta( '_bps_svc_start_after' ), 'dienstvakje leeg → startdatum +14 d' );
$done  = Fulfilment::fulfil( $o );
$owner = Owner::for_email( 'club@example.be' );
eq( $done, array( 'packs' => 1, 'services' => 2, 'freeOrder' => true ), 'pakket, 2 diensten, gratis rondes' );
$bal = $c5->balance( $owner );
eq( array( $bal['paid'], $bal['free'] ), array( 10, 6 ), '10 betaald + 5 per bestelling + 1 anonieme (samengevoegd)' );
$code = (string) $o->items[1]->get_meta( '_bps_ai_coupon' );
ok( 1 === preg_match( '/^AI-/', $code ), 'kortingscode aangemaakt' );
$cp = new WC_Coupon( $code );
eq( (float) $cp->get_amount(), 4.95, 'coupon = 100 % van € 4,95' );
eq( $cp->get_minimum_amount(), '50', 'minimum € 50' );
eq( $cp->get_email_restrictions(), array( 'club@example.be' ), 'enkel voor dit e-mailadres' );
eq( $cp->get_product_ids(), array( 500 ), 'enkel op patchproducten' );
ok( in_array( 101, $cp->get_excluded_product_ids(), true ), 'niet op pakketten zelf' );
ok( 1 === preg_match( '/^STAAL-/', (string) $stl->get_meta( '_bps_ai_coupon' ) ), 'staal: terugbetaling als korting' );
eq( (string) $svc->get_meta( Service_Status::STATUS_META ), 'waiting', 'hertekenen wacht (geen toestemming)' );
eq( (string) $stl->get_meta( Service_Status::STATUS_META ), 'queued', 'staal meteen ingepland' );
eq( count( $mails ), 2, '2 dienstmails' );
eq( Fulfilment::fulfil( $o ), array( 'packs' => 0, 'services' => 0, 'freeOrder' => false ), 'tweede keer: niets dubbel' );
eq( $c5->balance( $owner )['paid'], 10, 'geen dubbele rondes' );
$lotmeta = $o->items[1]->get_meta( '_bps_ai_pack' );
eq( $lotmeta['ownerKey'], $owner, 'ordermeta pakket met eigenaar' );
ok( false === strpos( Checkout::pack_email_text( $o ), '{' ), 'pakketmail zonder open plaatshouders' );
ok( false !== strpos( Checkout::pack_email_text( $o ), 'bps_wd=' ), 'gast krijgt een ondertekende herroeplink' );

// Status change + cron-like release.
ok( Service_Status::set( $o, $svc, 'queued', 'We starten vroeger.' ), 'status handmatig naar ingepland' );
ok( false !== strpos( end( $mails )['b'], 'We starten vroeger.' ), 'bericht van het atelier in de mail' );
eq( Service_Status::set( $o, $svc, 'bogus' ), false, 'onbekende status wordt niet gezet' );
eq( Service_Status::set( $o, $svc, 'queued' ), false, 'zelfde status: geen mail, geen wijziging' );

/* ------------------------------------------------------------------ */
section( 'Herroeping' );
$tok = Withdrawal::token( 900, $now );
eq( Withdrawal::verify( $tok, $now ), 900, 'geldige link' );
eq( Withdrawal::verify( $tok, $now + 31 * DAY_IN_SECONDS ), 0, 'verlopen link' );
eq( Withdrawal::verify( '901' . substr( $tok, 3 ), $now ), 0, 'gewijzigde link' );
eq( Withdrawal::verify( 'rommel', $now ), 0, 'onzin' );

$refunds              = array();
Withdrawal::$refunder = function ( $order, $amount, $reason ) use ( &$refunds ) {
	$refunds[] = $amount;
	return true;
};
$wmails             = array();
Withdrawal::$mailer = function ( $to, $s, $b ) use ( &$wmails ) {
	$wmails[] = compact( 'to', 's', 'b' );
};
$c5->reserve( $owner, 1, 'JOB000000000000000000000W1' ); // free first
$items = Withdrawal::withdrawable_items( $o, $c5 );
eq( count( $items ), 1, 'pakket binnen 14 dagen herroepbaar' );
$r = Withdrawal::perform( $o, (int) $lotmeta['lotId'], $c5, 'nl' );
eq( array( $r['unused'], $r['amount'], $r['refunded'] ), array( 10, 4.95, true ), 'volledig ongebruikt: € 4,95 terug' );
eq( $refunds, array( 4.95 ), 'terugbetaling aangevraagd' );
eq( $c5->balance( $owner )['paid'], 0, 'betaalde rondes weg' );
eq( wc_get_coupon_id_by_code( $code ), 0, 'kortingscode uit gebruik' );
ok( 1 === count( $wmails ) && false === strpos( $wmails[0]['b'], '{' ), 'bevestigingsmail zonder plaatshouders' );
ok( is_wp_error( Withdrawal::perform( $o, (int) $lotmeta['lotId'], $c5, 'nl' ) ), 'niet twee keer' );

// Coupon already used → not withdrawable; failing gateway → admin mail.
reset_all( $map );
$c6           = fresh( $clock );
$o2           = new WC_Order( 901 );
$o2->email    = 'b@example.be';
$o2->customer = 12;
$o2->add_item( new WC_Order_Item_Product( 1, 102, 1, 8.22, 1.73, 'Pakket 25' ) );
Checkout::apply( $o2, true, true, false );
Fulfilment::fulfil( $o2 );
$m2 = $o2->items[1]->get_meta( '_bps_ai_pack' );
eq( Owner::for_user( 12 ), $m2['ownerKey'], 'account wint van e-mail' );
for ( $j = 0; $j < 5; $j++ ) {
	$c6->reserve( Owner::for_user( 12 ), 1, 'JOB0000000000000000000000' . $j );
}
Withdrawal::$refunder = function () {
	return new WP_Error( 'gw', 'Gateway kan niet automatisch terugbetalen' );
};
$wmails = array();
$r2     = Withdrawal::perform( $o2, (int) $m2['lotId'], $c6, 'fr' );
eq( array( $r2['unused'], $r2['amount'], $r2['refunded'] ), array( 20, 7.96, false ), '20/25 van € 9,95 = € 7,96; automatisch lukte niet' );
eq( count( $wmails ), 2, 'mail klant + taakmail beheerder' );
eq( $wmails[1]['to'], 'atelier@shop.test', 'taakmail naar beheerder' );
$o3           = new WC_Order( 902 );
$o3->email    = 'c@example.be';
$o3->add_item( new WC_Order_Item_Product( 1, 101, 1, 4.09, 0.86, 'Pakket 10' ) );
Fulfilment::fulfil( $o3 );
$cp3                           = new WC_Coupon( (string) $o3->items[1]->get_meta( '_bps_ai_coupon' ) );
$GLOBALS['bps_wc']['coupons'][ strtolower( $cp3->get_code() ) ]->props['usage_count'] = 1;
eq( Withdrawal::withdrawable_items( $o3, $c6 ), array(), 'korting al gebruikt → niet meer herroepbaar' );
$now += 20 * DAY_IN_SECONDS;
$o4        = new WC_Order( 903 );
$o4->email = 'd@example.be';
$o4->created = $now - 20 * DAY_IN_SECONDS;
$o4->add_item( new WC_Order_Item_Product( 1, 101, 1, 4.09, 0.86, 'Pakket 10' ) );
$c6->grant( Owner::for_email( 'd@example.be' ), 'pack', 10, array( 'order_id' => 903, 'order_item_id' => 1, 'price_incl' => 4.95 ) );
$lot4 = $c6->store()->find_lot( 'pack', 903, 1 );
$c6->store()->update_lot( (int) $lot4['id'], array( 'created_at' => gmdate( 'Y-m-d H:i:s', $now - 20 * DAY_IN_SECONDS ) ) );
ok( ! $c6->withdrawable( $c6->store()->get_lot( (int) $lot4['id'] ) ), 'na 14 dagen niet meer herroepbaar' );

// Order cancelled → lot ends, coupon out.
reset_all( $map );
$c7        = fresh( $clock );
$o5        = new WC_Order( 904 );
$o5->email = 'e@example.be';
$o5->add_item( new WC_Order_Item_Product( 1, 101, 2, 8.18, 1.72, 'Pakket 10' ) );
Fulfilment::fulfil( $o5 );
eq( $c7->balance( Owner::for_email( 'e@example.be' ) )['paidLocked'], 20, '2× pakket = 20 rondes (vergrendeld zonder toestemming)' );
eq( Fulfilment::cancel_all( $o5 ), 1, 'annulering beëindigt de kavel' );
eq( $c7->balance( Owner::for_email( 'e@example.be' ) )['paidLocked'], 0, 'rondes weg na annulering' );
eq( wc_get_coupon_id_by_code( (string) $o5->items[1]->get_meta( '_bps_ai_coupon' ) ), 0, 'coupon weg na annulering' );

// Unpaid order → nothing.
$o6       = new WC_Order( 905 );
$o6->paid = false;
$o6->email = 'f@example.be';
$o6->add_item( new WC_Order_Item_Product( 1, 101, 1, 4.09, 0.86 ) );
eq( Fulfilment::fulfil( $o6 )['packs'], 0, 'onbetaald: geen rondes' );

/* ------------------------------------------------------------------ */
section( 'REST: origin, toestemming, catalogus' );
$req = new WP_REST_Request( 'POST', '/patch-studio/v1/ai/credits/consent' );
ok( Rest::origin_ok( $req ), 'zonder Origin-header: ok (zelfde site, curl)' );
$req->headers['origin'] = 'https://shop.test';
ok( Rest::origin_ok( $req ), 'eigen site ok' );
$req->headers['origin'] = 'https://evil.example';
ok( ! Rest::origin_ok( $req ), 'vreemde site geweigerd' );
eq( Rest::can_write( $req )->get_error_code(), 'bps_bad_origin', '403 bps_bad_origin' );
$req->headers = array();
eq( Rest::can_write( $req )->get_error_code(), 'bps_bad_token', 'gast zonder token: 401' );
$req->json = array( 'start' => true, 'ack' => false );
eq( Rest::consent( $req )->get_error_code(), 'bps_ai_invalid', 'toestemming vraagt beide vakjes' );
reset_all( $map );
$c8            = fresh( $clock );
Rest::$credits = $c8;
Owner::$current = Owner::for_user( 77 );
$c8->grant( Owner::$current, 'pack', 10, array( 'price_incl' => 4.95 ) );
eq( $c8->balance( Owner::$current )['paidLocked'], 10, 'vooraf vergrendeld' );
$req->json = array( 'start' => true, 'ack' => true );
$resp      = Rest::consent( $req );
eq( $resp->data['balance']['paid'], 10, 'na toestemming bruikbaar' );
$cat = Rest::catalog( new WP_REST_Request( 'GET', '/patch-studio/v1/services/catalog' ) );
eq( $cat->headers['Cache-Control'], 'public, max-age=300', 'catalogus 5 min cachebaar' );
ok( count( $cat->data['services'] ) >= 5, 'catalogus met diensten' );
$cr = Rest::credits_get();
ok( ! preg_match( '/[uea]:[0-9a-f]{64}/', json_encode( $cr->data ) ), 'geen eigenaarssleutel in het antwoord' );
Owner::$current = null;

/* ------------------------------------------------------------------ */
section( 'Beheer: invoer begrenzen' );
$r = Admin_Sections::sanitize_rounds(
	array(
		'free_anonymous' => '99',
		'free_withEmail' => '2',
		'free_perOrder'  => '-3',
		'validityMonths' => '6',
		'credit_percent' => '75',
		'credit_min'     => '49,5',
		'credit_months'  => '12',
		'emailVerify'    => '1',
		'packs'          => array(
			array( 'id' => 'ai_pack_10', 'enabled' => '1', 'rounds' => '10', 'priceExcl' => '4,0909' ),
			array( 'id' => 'ai_pack_10', 'enabled' => '1', 'rounds' => '99', 'priceExcl' => '1' ),
			array( 'id' => 'ai_pack_25', 'enabled' => '1', 'rounds' => '25', 'priceExcl' => '0' ),
		),
	)
);
eq( $r['patch']['free'], array( 'anonymous' => 5, 'withEmail' => 2, 'perOrder' => 0 ), 'gratis rondes begrensd' );
eq( $r['patch']['validityMonths'], 12, 'geldigheid minimaal 12 maanden' );
eq( $r['patch']['orderCredit']['percent'], 100, 'percentage enkel 0/50/100' );
eq( $r['patch']['orderCredit']['minArticleIncl'], 49.5, 'komma als decimaalteken' );
eq( count( $r['patch']['packs'] ), 2, 'dubbel pakket-id genegeerd' );
eq( $r['patch']['packs'][1]['enabled'], false, 'pakket zonder prijs gaat uit' );
eq( count( $r['errors'] ), 1, 'melding voor pakket zonder prijs' );
$s = Admin_Sections::sanitize_services(
	array(
		'd2_redraw'      => array( 'enabled' => '1', 'priceExcl' => '25', 'leadDays' => '999', 'trigger_minSidePxBelow' => '250', 'label_nl' => '', 'label_fr' => 'X' ),
		'd3b_file_addon' => array( 'enabled' => '1', 'showInShop' => '1' ),
		'hack'           => array( 'enabled' => '1' ),
	),
	Config::services( false )
);
eq( $s['patch']['services']['d2_redraw']['leadDays'], 60, 'levertijd max 60' );
eq( $s['patch']['services']['d2_redraw']['triggers']['minSidePxBelow'], 250, 'drempel aanpasbaar' );
eq( $s['patch']['services']['d2_redraw']['label']['nl'], 'Laat ons je logo hertekenen', 'naam verplicht in beide talen' );
eq( $s['patch']['services']['d3b_file_addon']['showInShop'], false, 'regeloptie nooit los in de winkel' );
ok( ! isset( $s['patch']['services']['hack'] ), 'onbekende dienst genegeerd' );
Config::save( $s['patch'] );
eq( Config::service( 'd2_redraw' )['priceIncl'], 30.25, 'nieuwe prijs incl. btw' );
Config::set_override( array( 'mode' => 'live' ) );

/* ------------------------------------------------------------------ */
section( 'Publieke config en eerlijke teksten' );
$pc = Module::public_config( array( 'x' => 1 ), 'fr_BE' );
ok( isset( $pc['services']['catalog'] ) && 1 === $pc['x'], 'config.services toegevoegd' );
ok( ! preg_match( '/priceExcl|bps_ai_secrets|owner/i', json_encode( $pc ) ), 'geen interne prijzen of sleutels in de publieke config' );
$forbidden = '/\b(credits?|tokens?|beperkt aanbod|nog maar|populairst|offre limitée|plus que|le plus populaire)\b|★|aftel/iu';
$texts     = array();
foreach ( ( new ReflectionClass( Text::class ) )->getConstant( 'STRINGS' ) as $k => $row ) {
	ok( ! empty( $row['nl'] ) && ! empty( $row['fr'] ), "tekst $k in NL en FR" );
	$texts[] = $row['nl'] . ' ' . $row['fr'];
}
$cfgdir = dirname( __DIR__, 2 ) . '/plugin/borduur-patch-studio/config/';
foreach ( array( 'ai-services.json', 'ai-pricing.json' ) as $f ) {
	$j = json_decode( file_get_contents( $cfgdir . $f ), true );
	unset( $j['_doc'] );
	$texts[] = json_encode( $j, JSON_UNESCAPED_UNICODE );
}
foreach ( $texts as $t ) {
	ok( ! preg_match( $forbidden, $t ), 'eerlijke tekst: ' . mb_substr( $t, 0, 60 ) );
}
foreach ( Config::services( false ) as $id => $svc ) {
	foreach ( array( 'label', 'short' ) as $k ) {
		ok( ! empty( $svc[ $k ]['nl'] ) && ! empty( $svc[ $k ]['fr'] ), "$id.$k NL+FR" );
	}
}

echo "\n" . ( $GLOBALS['count'] - $GLOBALS['fails'] ) . '/' . $GLOBALS['count'] . " ok\n";
exit( $GLOBALS['fails'] ? 1 : 0 );
