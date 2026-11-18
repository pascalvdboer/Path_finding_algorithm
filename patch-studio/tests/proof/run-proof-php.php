<?php
/**
 * Proof workflow tests (premium P05): proof per order line, tokens (current / previous round, forged, other order,
 * removed design), A/B isolation of one design in two orders, migration of old links, approval record, reminders,
 * stopped orders (410), customer page rules, pay after proof / cancel until proof, whole-order proof, My account,
 * work order (visual, facts, steps, machine font, copy codes), plain-text mails, French mail fields, language
 * fallback and the split of Order_Production.
 *
 * Run: php tests/proof/run-proof-php.php   (exit code 1 on any failure; last line "N/N ok")
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

require __DIR__ . '/php/wp-stubs.php';

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'BorduurPatchStudio\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class, strlen( $prefix ) ) );
		$name  = array_pop( $parts );
		$dir   = '';
		foreach ( $parts as $p ) {
			$dir .= str_replace( '_', '-', strtolower( $p ) ) . '/';
		}
		$file = BPS_DIR . 'includes/' . $dir . 'class-' . str_replace( '_', '-', strtolower( $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

use BorduurPatchStudio\Admin\Order_Production;
use BorduurPatchStudio\Logger;
use BorduurPatchStudio\Proof\Account;
use BorduurPatchStudio\Proof\Lang;
use BorduurPatchStudio\Proof\Order_Proof;
use BorduurPatchStudio\Proof\Proof_Service;
use BorduurPatchStudio\Proof\Reminders;
use BorduurPatchStudio\Proof\Response_Page;
use BorduurPatchStudio\Proof\Spec;

$GLOBALS['checks'] = 0;
$GLOBALS['failed'] = 0;
$failures          = array();

function ok( $cond, string $msg = 'assertion failed' ): void {
	++$GLOBALS['checks'];
	if ( ! $cond ) {
		++$GLOBALS['failed'];
		throw new RuntimeException( $msg );
	}
}
function eq( $a, $b, string $msg = '' ): void {
	ok( $a === $b, ( '' !== $msg ? $msg . ': ' : '' ) . 'expected ' . var_export( $b, true ) . ', got ' . var_export( $a, true ) );
}
function has( string $hay, string $needle, string $msg = '' ): void {
	ok( false !== strpos( $hay, $needle ), ( '' !== $msg ? $msg . ': ' : '' ) . 'missing "' . $needle . '" in ' . substr( $hay, 0, 300 ) );
}
function t( string $name, callable $fn ): void {
	global $failures;
	bps_t_reset();
	Order_Production::register();
	try {
		$fn();
		$errors = array_filter( Logger::$events, static fn( $e ) => 'error' === $e[0] );
		ok( ! $errors, 'geen Logger-fouten: ' . json_encode( array_values( $errors ) ) );
		echo "  ok   {$name}\n";
	} catch ( Throwable $e ) {
		$failures[] = $name;
		if ( ! ( $e instanceof RuntimeException ) ) {
			++$GLOBALS['checks'];
			++$GLOBALS['failed'];
		}
		echo "  FAIL {$name}\n       " . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
	}
}

/* ----------------------------------------------------------------- fixtures */

/** Design post with a preview file. */
function design( string $locale = 'nl-BE', array $payload = array() ): int {
	$id   = ++$GLOBALS['bps_t']['next_id'];
	$file = $GLOBALS['bps_t']['upload_dir'] . '/patch-studio/previews/prev-' . $id . '.png';
	@mkdir( dirname( $file ), 0777, true );
	file_put_contents( $file, 'PNG-preview-' . $id );
	$GLOBALS['bps_t']['posts'][ $id ] = new WP_Post( $id, 'bps_design', 'PS-2026-' . str_pad( (string) $id, 4, '0', STR_PAD_LEFT ) );
	$p = array_replace_recursive(
		array(
			'locale'    => $locale,
			'technique' => 'embroidered',
			'sizeCm'    => 8,
			'widthMm'   => 80,
			'heightMm'  => 60,
			'shape'     => 'circle',
			'threads'   => array(
				array( 'code' => '1801', 'name' => 'Wit', 'hex' => '#ffffff', 'share' => 0.5 ),
				array( 'code' => '1747', 'name' => 'Rood', 'hex' => '#c8102e', 'share' => 0.5 ),
			),
			'texts'     => array(
				array( 'text' => "D'Hondt", 'font' => 'Oswald', 'heightMm' => 8, 'path' => 'straight', 'threadCode' => '1801' ),
			),
		),
		$payload
	);
	update_post_meta( $id, '_bps_payload', json_encode( $p ) );
	update_post_meta( $id, '_bps_preview', array( 'path' => $file, 'url' => 'https://shop.test/wp-content/uploads/patch-studio/previews/prev-' . $id . '.png' ) );
	update_post_meta( $id, '_bps_status', 'ordered' );
	return $id;
}
/** Item meta for a design. */
function line( int $design_id, array $extra = array() ): array {
	$rec = \BorduurPatchStudio\Woo\Designs::get( $design_id );
	return array_merge(
		array(
			'_bps_design_id'    => $design_id,
			'bps_ref'           => $rec ? $rec['ref'] : 'PS-GONE',
			'_bps_payload'      => json_encode( $rec ? $rec['payload'] : array( 'locale' => 'nl-BE' ) ),
			'_bps_technique_id' => 'embroidered',
			'_bps_size_cm'      => 8,
			'_bps_shape_id'     => 'circle',
		),
		$extra
	);
}
function first_item( WC_Order $o ): WC_Order_Item_Product {
	return reset( $o->items );
}
function token_of( string $url ): string {
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
	return (string) $q['t'];
}
function query_of( string $url ): array {
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
	return $q;
}
function mails( string $id = '' ): array {
	return array_values( array_filter( $GLOBALS['bps_t']['mails'], static fn( $m ) => '' === $id || $m['id'] === $id ) );
}
function approve( string $url, array $extra = array() ): array {
	$q = query_of( $url );
	return Response_Page::process( array(), array_merge( $q, array( 'a' => 'approve', 'bps_confirm' => '1' ), $extra ), true );
}
function iso_ago( int $days ): string {
	return gmdate( 'c', time() - $days * DAY_IN_SECONDS - 60 );
}

/* ----------------------------------------------------------------- tests */

echo "Proof workflow (P05)\n";

t( 'php-kwaliteit-3: declare_emails weg, e-mails apart, ID\'s en opties ongewijzigd, Order_Production < 1200 regels', function () {
	$src = file_get_contents( BPS_DIR . 'includes/admin/class-order-production.php' );
	ok( false === strpos( $src, 'function declare_emails' ), 'declare_emails bestaat niet meer' );
	ok( false === strpos( $src, 'class Proof_Email' ), 'geen klasse in de functie' );
	ok( substr_count( $src, "\n" ) < 1200, 'class-order-production.php < 1200 regels (' . substr_count( $src, "\n" ) . ')' );
	ok( ! defined( Order_Production::class . '::FR' ) && ! method_exists( Order_Production::class, 'l' ), 'const FR en l() zijn weg (i18n-a11y-5)' );
	$emails = apply_filters( 'woocommerce_email_classes', array() );
	eq( $emails['BPS_Email_Proof']->id, 'bps_proof' );
	eq( $emails['BPS_Email_Proof_Response']->id, 'bps_proof_response' );
	eq( $emails['BPS_Email_Proof_Reminder']->id, 'bps_proof_reminder' );
	eq( $emails['BPS_Email_Proof_Order']->id, 'bps_proof_order' );
	ok( $emails['BPS_Email_Proof'] instanceof \BorduurPatchStudio\Woo\Emails\Base, 'Proof erft van Emails\\Base' );
	$owned = Order_Production::owned_data();
	ok( in_array( 'bps_proof_followup', $owned['cron_hooks'], true ), 'cronhook in owned_data' );
	ok( in_array( 'bps-proof', $owned['as_groups'], true ), 'AS-groep in owned_data' );
	$f = apply_filters( 'bps_owned_data', array( 'cron_hooks' => array( 'x' ) ) );
	ok( in_array( 'bps_proof_followup', $f['cron_hooks'], true ) && in_array( 'x', $f['cron_hooks'], true ), 'filter bps_owned_data voegt toe' );
} );

t( 'tokens: huidige ronde, vorige ronde, vervalst, ander order, verwijderd ontwerp', function () {
	$d = design();
	$o = bps_t_order( array( line( $d ) ) );
	$i = first_item( $o );
	ok( ! is_wp_error( Proof_Service::send( $o, $i, 'ronde 1' ) ), 'ronde 1 verstuurd' );
	$u1 = Proof_Service::url( $o, $i );
	eq( Proof_Service::check_token( $o, $i, token_of( $u1 ) ), 'current' );
	eq( token_of( $u1 ), hash_hmac( 'sha256', 'proof|' . $o->id . '|' . $i->id . '|1', wp_salt( 'auth' ) ), 'HMAC over proof|order|item|round' );
	ok( isset( query_of( $u1 )['item'] ), 'URL heeft item=' );
	Proof_Service::send( $o, $i, 'ronde 2' );
	eq( Proof_Service::check_token( $o, $i, token_of( $u1 ) ), 'superseded', 'ronde 1 na ronde 2' );
	eq( Response_Page::process( query_of( $u1 ), array(), false )['args']['view'], 'superseded' );
	eq( Proof_Service::check_token( $o, $i, str_repeat( 'a', 64 ) ), 'invalid', 'vervalst' );
	$r = Response_Page::process( array_merge( query_of( $u1 ), array( 't' => str_repeat( '0', 64 ) ) ), array(), false );
	eq( $r['status'], 403, 'vervalst → 403' );
	ok( null === $r['args']['order'] && null === $r['args']['design'], 'vervalst toont niets' );
	$o2 = bps_t_order( array( line( $d ) ) );
	$q  = query_of( Proof_Service::url( $o, $i ) );
	$q['o'] = $o2->id;
	eq( Response_Page::process( $q, array(), false )['status'], 403, 'token van order A op order B' );
	// Removed design: the line-based link keeps working, sending a new proof is refused.
	unset( $GLOBALS['bps_t']['posts'][ $d ] );
	$r = Response_Page::process( query_of( Proof_Service::url( $o, $i ) ), array(), false );
	eq( $r['args']['view'], 'confirm', 'link werkt na opruimen ontwerp' );
	eq( Proof_Service::send( $o, $i )->get_error_code(), 'bps_no_design' );
} );

t( 'atelier-flow-1: hetzelfde ontwerp in bestelling A en B, proeven gescheiden', function () {
	$d = design();
	$a = bps_t_order( array( line( $d ) ) );
	$ia = first_item( $a );
	Proof_Service::send( $a, $ia, 'A' );
	$ua = Proof_Service::url( $a, $ia );
	approve( $ua );
	eq( Proof_Service::get( $ia )['status'], 'approved', 'A goedgekeurd' );
	$before = serialize( $ia->meta );
	$b  = bps_t_order( array( line( $d ) ) );
	$ib = first_item( $b );
	eq( Proof_Service::get( $ib )['status'], 'none', 'B begint op "Nog geen proef verstuurd"' );
	has( Order_Production::proof_block_html( Order_Production::fiche( $b, $ib ) ), 'Nog geen proef verstuurd' );
	Proof_Service::send( $b, $ib, 'B' );
	$ub = Proof_Service::url( $b, $ib );
	eq( approve( $ub )['args']['view'], 'done', 'link van B werkt' );
	eq( serialize( $ia->meta ), $before, 'proefdata van A byte-identiek' );
	eq( Response_Page::process( query_of( $ua ), array(), false )['args']['view'], 'answered', 'link van A: al goedgekeurd' );
	eq( get_post_meta( $d, '_bps_proof', true ), '', 'niets meer op het ontwerp geschreven (Designs::update_proof niet gebruikt)' );
} );

t( 'atelier-flow-1: oude link (design-formaat) werkt vóór en na de migratie; nieuwe ronde vervangt hem', function () {
	$d = design();
	$o = bps_t_order( array( line( $d ) ) );
	$i = first_item( $o );
	update_post_meta( $d, '_bps_order_id', $o->id );
	update_post_meta( $d, '_bps_order_item_id', $i->id );
	update_post_meta( $d, '_bps_proof', array( 'status' => 'sent', 'round' => 2, 'order_id' => $o->id, 'item_id' => $i->id, 'sent_at' => iso_ago( 1 ), 'image_url' => 'https://shop.test/old.png', 'history' => array( array( 'action' => 'sent', 'round' => 1 ), array( 'action' => 'sent', 'round' => 2 ) ) ) );
	$old = array( 'bps_proof' => $d, 'o' => $o->id, 't' => Proof_Service::legacy_token( $d, $o->id, 2 ), 'a' => 'approve' );
	eq( Response_Page::process( $old, array(), false )['args']['view'], 'confirm', 'vóór migratie (fallback)' );
	$older = array_merge( $old, array( 't' => Proof_Service::legacy_token( $d, $o->id, 1 ) ) );
	eq( Response_Page::process( $older, array(), false )['args']['view'], 'superseded', 'oude ronde 1' );
	eq( Proof_Service::migrate(), 1, 'migratie kopieert 1 proef' );
	eq( Proof_Service::migrate(), 0, 'migratie draait maar één keer' );
	ok( is_array( $i->meta['_bps_proof'] ) && 2 === $i->meta['_bps_proof']['legacy_max_round'], 'itemmeta met legacy_max_round' );
	eq( Response_Page::process( $old, array(), false )['args']['view'], 'confirm', 'na migratie' );
	$r = Response_Page::process( array(), array_merge( $old, array( 'bps_confirm' => '1' ) ), true );
	eq( $r['args']['view'], 'done', 'goedkeuren via oude link' );
	eq( Proof_Service::get( $i )['status'], 'approved' );
	Proof_Service::send( $o, $i, 'ronde 3' );
	eq( Response_Page::process( $old, array(), false )['args']['view'], 'superseded', 'oude link na nieuwe ronde' );
	$forged = array_merge( $old, array( 't' => Proof_Service::legacy_token( $d, $o->id, 3 ) ) );
	eq( Response_Page::process( $forged, array(), false )['status'], 403, 'oud formaat voor een nieuwe ronde wordt geweigerd' );
	// Second order of the same design: legacy proof does not leak.
	$b = bps_t_order( array( line( $d ) ) );
	eq( Proof_Service::get( first_item( $b ) )['status'], 'none' );
} );

t( 'atelier-flow-3: dossier na 2 rondes + goedkeuring, IP-hash, UA, tijdlijn, export', function () {
	$d  = design( 'fr-BE' );
	$o  = bps_t_order( array( line( $d ) ) );
	$i  = first_item( $o );
	$a1 = bps_t_attachment( 'PNG-ronde-1' );
	$a2 = bps_t_attachment( 'PNG-ronde-2' );
	Proof_Service::send( $o, $i, 'r1', $a1 );
	Proof_Service::send( $o, $i, 'r2', $a2 );
	$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
	$_SERVER['HTTP_USER_AGENT'] = str_repeat( 'Mozilla/5.0 ', 40 );
	approve( Proof_Service::url( $o, $i ) );
	$h = Proof_Service::get( $i )['history'];
	eq( count( $h ), 3, '3 items' );
	foreach ( array( 0 => 'PNG-ronde-1', 1 => 'PNG-ronde-2' ) as $k => $bytes ) {
		eq( $h[ $k ]['action'], 'sent' );
		ok( '' !== $h[ $k ]['image_url'], 'image_url' );
		eq( $h[ $k ]['image_sha256'], hash( 'sha256', $bytes ), 'sha256 van het bestand' );
		ok( count( $h[ $k ]['spec_snapshot'] ) >= 4, 'spec_snapshot niet leeg' );
	}
	ok( $h[0]['image_url'] !== $h[1]['image_url'], 'twee verschillende beelden' );
	eq( $h[2]['action'], 'approved' );
	ok( 1 === preg_match( '/^[0-9a-f]{64}$/', $h[2]['ip_hash'] ) && false === strpos( $h[2]['ip_hash'], '203.0.113.7' ), 'ip_hash 64 hex' );
	ok( $h[2]['ip_hash'] !== hash( 'sha256', '203.0.113.7' ), 'HMAC met eigen sleutel, geen kale hash' );
	ok( strlen( $h[2]['ua'] ) <= 200 && strlen( $h[2]['ua'] ) > 0, 'ua ≤ 200' );
	eq( $h[2]['confirmed'], true, 'vinkje bewaard' );
	$html = Order_Production::proof_block_html( Order_Production::fiche( $o, $i ) );
	eq( substr_count( $html, 'class="bps-timeline__thumb"' ), 2, '2 miniaturen' );
	has( $html, basename( get_attached_file( $a1 ) ) );
	has( $html, basename( get_attached_file( $a2 ) ) );
	$exp = Proof_Service::export_for_order( $o );
	eq( count( $exp ), 1 );
	eq( $exp[0]['group_id'], 'bps-proofs' );
	$text = json_encode( $exp[0]['data'], JSON_UNESCAPED_UNICODE );
	ok( false !== strpos( $text, 'ip_hash' ) && false !== strpos( $text, 'image_sha256' ), 'export bevat het dossier' );
	// Eraser: IP hash + UA always gone, note kept unless WC erases order data.
	$i->meta['_bps_proof']['history'][2]['note'] = 'Mijn naam';
	$res = Proof_Service::erase( 'klant@example.test' );
	ok( $res['items_removed'], 'eraser verwijdert' );
	eq( Proof_Service::get( $i )['history'][2]['ip_hash'], '' );
	eq( Proof_Service::get( $i )['history'][2]['ua'], '' );
	eq( Proof_Service::get( $i )['history'][2]['note'], 'Mijn naam' );
	unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
} );

t( 'atelier-flow-9: herinneringen (1 na 4 dagen, niets dezelfde dag, max 2, uit, goedgekeurd, geannuleerd)', function () {
	$d = design();
	$o = bps_t_order( array( line( $d ) ) );
	$i = first_item( $o );
	Proof_Service::send( $o, $i, '' );
	$i->meta['_bps_proof']['sent_at'] = iso_ago( 4 );
	$GLOBALS['bps_t']['mails']        = array();
	eq( Reminders::run(), 1, 'eerste run: 1 herinnering' );
	eq( count( mails( 'bps_proof_reminder' ) ), 1 );
	has( mails( 'bps_proof_reminder' )[0]['message'], 'item=' . $i->id, 'link van de huidige ronde' );
	eq( Reminders::run(), 0, 'tweede run dezelfde dag' );
	eq( Reminders::run( time() + 4 * DAY_IN_SECONDS ), 1, 'tweede herinnering na nog eens N dagen' );
	eq( Reminders::run( time() + 8 * DAY_IN_SECONDS ), 0, 'geen derde' );
	eq( count( Proof_Service::get( $i )['reminders_sent'] ), 2, 'reminders_sent[]' );
	// Disabled mail.
	$o2 = bps_t_order( array( line( design() ) ) );
	Proof_Service::send( $o2, first_item( $o2 ) );
	first_item( $o2 )->meta['_bps_proof']['sent_at'] = iso_ago( 5 );
	$GLOBALS['bps_t']['mail_disabled']               = array( 'bps_proof_reminder' );
	eq( Reminders::run(), 0, 'mail uit: niets' );
	$GLOBALS['bps_t']['mail_disabled'] = array();
	// Approved and cancelled orders: nothing.
	approve( Proof_Service::url( $o2, first_item( $o2 ) ) );
	$o3 = bps_t_order( array( line( design() ) ) );
	Proof_Service::send( $o3, first_item( $o3 ) );
	first_item( $o3 )->meta['_bps_proof']['sent_at'] = iso_ago( 5 );
	$o3->status = 'cancelled';
	eq( Reminders::run(), 0, 'goedgekeurd of geannuleerd: niets' );
	Reminders::schedule();
	ok( false !== wp_next_scheduled( 'bps_proof_followup' ), 'dagelijkse taak ingepland (WP-Cron zonder Action Scheduler)' );
} );

t( 'woo-compat-4: terugbetaald → 410 zonder record, notitie, badge, AJAX-weigering; on-hold mag; failed → betaald werkt', function () {
	$o = bps_t_order( array( line( design() ) ) );
	$i = first_item( $o );
	Proof_Service::send( $o, $i );
	$u = Proof_Service::url( $o, $i );
	$o->update_status( 'refunded' );
	$r = Response_Page::process( query_of( $u ), array(), false );
	eq( $r['status'], 410 );
	eq( $r['args']['view'], 'stopped' );
	$html = Order_Production::template_html( $r['template'], $r['args'] );
	has( $html, 'Deze bestelling is geannuleerd' );
	$r = approve( $u );
	eq( $r['status'], 410, 'POST → 410' );
	eq( Proof_Service::get( $i )['status'], 'cancelled', 'POST wijzigt niets (status cancelled door de orderhook)' );
	ok( 0 === count( array_filter( Proof_Service::get( $i )['history'], static fn( $h ) => 'approved' === $h['action'] ) ), 'geen goedkeuring geregistreerd' );
	ok( (bool) array_filter( $o->notes, static fn( $n ) => false !== strpos( $n, 'Proef/productie gestopt' ) ), 'ordernotitie' );
	has( Order_Production::list_cell_html( $o ), 'Geannuleerd', 'lijstkolom' );
	has( Order_Production::proof_block_html( Order_Production::fiche( $o, $i ) ), 'Geannuleerd', 'badge op de fiche' );
	eq( Proof_Service::send( $o, $i )->get_error_code(), 'bps_order_stopped', 'proef versturen → fout' );
	// On-hold (bank transfer) can get a proof.
	$h = bps_t_order( array( line( design() ) ), 'on-hold' );
	ok( ! is_wp_error( Proof_Service::send( $h, first_item( $h ) ) ), 'on-hold: proef mogelijk' );
	// Failed, then paid: works normally (failed is not a definitive stop).
	$f = bps_t_order( array( line( design() ) ), 'failed' );
	eq( Proof_Service::send( $f, first_item( $f ) )->get_error_code(), 'bps_order_stopped', 'failed: even geblokkeerd' );
	$f->update_status( 'processing' );
	ok( ! is_wp_error( Proof_Service::send( $f, first_item( $f ) ) ), 'na betaling werkt het' );
	eq( approve( Proof_Service::url( $f, first_item( $f ) ) )['args']['view'], 'done' );
	// Partial refund of one complete line.
	$p = bps_t_order( array( line( design() ), line( design() ) ), 'processing' );
	$lines = array_values( $p->items );
	$p->refunded[ $lines[0]->id ] = $lines[0]->quantity;
	do_action( 'woocommerce_order_refunded', $p->id, 99 );
	eq( Proof_Service::get( $lines[0] )['status'], 'cancelled', 'volledig terugbetaalde regel gestopt' );
	eq( Proof_Service::get( $lines[1] )['status'], 'none', 'andere regel loopt door' );
	// Reopened by mistake: previous status back.
	$c = bps_t_order( array( line( design() ) ), 'processing' );
	Proof_Service::send( $c, first_item( $c ) );
	$c->update_status( 'cancelled' );
	$c->update_status( 'processing' );
	eq( Proof_Service::get( first_item( $c ) )['status'], 'sent', 'heropend: status terug' );
} );

t( 'atelier-flow-7: vinkje verplicht (server), volle resolutie, vorige versie, specs met font + Polyneon, security-11', function () {
	$GLOBALS['bps_t']['settings'] = array( 'threads' => array( array( 'code' => '1801', 'name' => array( 'nl' => 'Wit', 'fr' => 'Blanc' ), 'hex' => '#ffffff' ) ) );
	$o  = bps_t_order( array( line( design() ) ) );
	$i  = first_item( $o );
	$a1 = bps_t_attachment( 'one' );
	$a2 = bps_t_attachment( 'two' );
	Proof_Service::send( $o, $i, '', $a1 );
	Proof_Service::send( $o, $i, '', $a2 );
	$u = Proof_Service::url( $o, $i );
	$r = Response_Page::process( array(), array_merge( query_of( $u ), array( 'note' => '' ) ), true );
	eq( $r['args']['view'], 'confirm', 'zonder vinkje terug naar het formulier' );
	has( $r['args']['error'], 'spelling, de kleuren, de maat en het aantal' );
	eq( Proof_Service::get( $i )['status'], 'sent', 'geen status gezet' );
	$html = Order_Production::template_html( $r['template'], $r['args'] );
	has( $html, 'name="bps_confirm"' );
	has( $html, 'Ik heb de spelling, de kleuren, de maat en het aantal nagekeken' );
	has( $html, esc_url( wp_get_attachment_url( $a2 ) ), 'beeld linkt naar volle resolutie' );
	has( $html, 'Vorige versie bekijken' );
	has( $html, esc_url( wp_get_attachment_url( $a1 ) ) );
	has( $html, '<meta name="referrer" content="no-referrer">' );
	eq( substr_count( $html, '<main' ), 1, 'één main' );
	has( $html, 'Oswald (smal en sterk) · Polyneon 1801 Wit', 'tekstregel met font + code + naam' );
	has( $html, 'Digitale proef van patch ' . $i->meta['bps_ref'], 'alt met referentie' );
	$r = Response_Page::process( array(), array_merge( query_of( $u ), array( 'a' => 'changes', 'note' => '' ) ), true );
	has( $r['args']['error'], 'Laat ons even weten wat je wil aanpassen' );
	$r = Response_Page::process( array(), array_merge( query_of( $u ), array( 'a' => 'changes', 'note' => '<script>x</script>Rand blauw' ) ), true );
	eq( $r['args']['view'], 'done' );
	eq( Proof_Service::get( $i )['note'], 'xRand blauw', 'note direct gesanitized' );
	eq( Proof_Service::get( $i )['status'], 'changes_requested' );
	// i18n-a11y-14: with the theme the wrapper is a region with lang, no second <main>.
	add_filter( 'bps_proof_page_use_theme', '__return_true' );
	$fo = bps_t_order( array( line( design( 'fr-BE' ) ) ) );
	Proof_Service::send( $fo, first_item( $fo ) );
	$r = Response_Page::process( query_of( Proof_Service::url( $fo, first_item( $fo ) ) ), array(), false );
	ok( $r['args']['use_theme'], 'filter werkt' );
	$GLOBALS['bps_t']['theme'] = true;
	$html = Order_Production::template_html( $r['template'], $r['args'] );
	has( $html, 'role="region"' );
	has( $html, 'lang="fr-BE"' );
	eq( substr_count( $html, '<main' ), 1, 'hoogstens één main (die van het thema)' );
	ok( false === strpos( $html, '<main class="bps-proofpage"' ), 'onze wrapper is geen main binnen het thema' );
} );

t( 'atelier-flow-11: betalen na goedkeuring, annuleren vóór goedkeuring, ONBETAALD op de werkbon', function () {
	$GLOBALS['bps_t']['settings'] = array( 'promises' => array( 'payAfterProof' => true, 'cancelUntilProof' => true ) );
	$o = bps_t_order( array( line( design() ) ), 'pending' );
	$i = first_item( $o );
	Proof_Service::send( $o, $i );
	$u = Proof_Service::url( $o, $i );
	$r = Response_Page::process( query_of( $u ), array(), false );
	ok( '' !== $r['args']['cancel_url'], 'annuleerlink vóór goedkeuring' );
	$r = approve( $u );
	eq( $r['args']['pay_url'], $o->get_checkout_payment_url(), 'knop naar get_checkout_payment_url()' );
	has( Order_Production::template_html( $r['template'], $r['args'] ), 'Nu betalen' );
	eq( $r['args']['cancel_url'], '', 'na goedkeuring geen annuleerlink' );
	$c = Response_Page::process( array(), array_merge( query_of( Proof_Service::url( $o, $i, 'cancel' ) ) ), true );
	eq( $o->status, 'pending', 'POST annuleren na goedkeuring geweigerd' );
	ok( '' !== $c['args']['error'], 'foutmelding' );
	$GLOBALS['bps_t']['settings'] = array( 'promises' => array( 'payAfterProof' => false, 'cancelUntilProof' => true ) );
	eq( Response_Page::process( query_of( $u ), array(), false )['args']['pay_url'], '', 'instelling uit: geen knop' );
	// Cancel an unpaid order before approval.
	$u2 = bps_t_order( array( line( design() ) ), 'on-hold' );
	Proof_Service::send( $u2, first_item( $u2 ) );
	$cu = Proof_Service::url( $u2, first_item( $u2 ), 'cancel' );
	eq( Response_Page::process( query_of( $cu ), array(), false )['args']['view'], 'cancel_confirm', 'GET vraagt bevestiging' );
	eq( $u2->status, 'on-hold', 'GET verandert niets' );
	eq( Response_Page::process( array(), query_of( $cu ), true )['args']['view'], 'cancel_done' );
	eq( $u2->status, 'cancelled', 'onbetaald → cancelled' );
	ok( (bool) array_filter( $u2->notes, static fn( $n ) => false !== strpos( $n, 'proefpagina' ) ), 'notitie' );
	eq( Proof_Service::get( first_item( $u2 ) )['status'], 'cancelled' );
	// Paid order: a request with note + shop mail, no automatic cancellation.
	$p = bps_t_order( array( line( design() ) ), 'processing' );
	Proof_Service::send( $p, first_item( $p ) );
	$GLOBALS['bps_t']['mails'] = array();
	eq( Response_Page::process( array(), query_of( Proof_Service::url( $p, first_item( $p ), 'cancel' ) ), true )['args']['view'], 'cancel_requested' );
	eq( $p->status, 'processing', 'betaald: niet automatisch geannuleerd' );
	eq( count( mails( 'bps_proof_response' ) ), 1, 'winkelmail' );
	// Work order of an on-hold order shows ONBETAALD.
	$w = bps_t_order( array( line( design() ) ), 'on-hold' );
	$html = Order_Production::render_print( array( array( $w->id, first_item( $w )->id ) ) );
	has( $html, 'ONBETAALD' );
	$GLOBALS['bps_t']['settings'] = array();
} );

t( 'atelier-flow-10: proef voor hele bestelling (1 mail, alles goedkeuren, gemengd, losse proef blijft werken)', function () {
	$o = bps_t_order( array( line( design() ), line( design() ), line( design() ) ) );
	$GLOBALS['bps_t']['mails'] = array();
	$res = Order_Proof::send( $o, 'Hallo' );
	eq( $res['lines'], 3 );
	eq( count( $GLOBALS['bps_t']['mails'] ), 1, '1 mail' );
	$url = Order_Proof::url( $o );
	has( $GLOBALS['bps_t']['mails'][0]['message'], 'bps_proof_order' );
	$q = query_of( $url );
	$r = Order_Proof::process( $q, array(), false );
	eq( $r['args']['view'], 'confirm' );
	eq( count( $r['args']['cards'] ), 3 );
	$html = Order_Production::template_html( $r['template'], $r['args'] );
	has( $html, 'Alles goedkeuren' );
	$notes = count( $o->notes );
	$GLOBALS['bps_t']['mails'] = array();
	$r = Order_Proof::process( array(), array_merge( $q, array( 'approve_all' => '1' ) ), true );
	ok( '' !== $r['args']['error'], 'zonder vinkje: fout' );
	$r = Order_Proof::process( array(), array_merge( $q, array( 'approve_all' => '1', 'bps_confirm' => '1' ) ), true );
	eq( $r['args']['view'], 'done' );
	foreach ( $o->items as $it ) {
		eq( Proof_Service::get( $it )['status'], 'approved' );
	}
	eq( count( $o->notes ) - $notes, 1, '1 ordernotitie' );
	eq( count( mails( 'bps_proof_response' ) ), 1, '1 winkelmail' );
	eq( count( $GLOBALS['bps_t']['actions_done']['bps_proof_response'] ?? array() ), 3, 'bps_proof_response per regel' );
	// Mixed answer.
	$m  = bps_t_order( array( line( design() ), line( design() ), line( design() ) ) );
	Order_Proof::send( $m );
	$ids = array_keys( $m->items );
	$q   = query_of( Order_Proof::url( $m ) );
	$r   = Order_Proof::process( array(), array_merge( $q, array( 'bps_confirm' => '1', 'choice' => array( $ids[0] => 'approve', $ids[1] => 'approve', $ids[2] => 'changes' ), 'notes' => array( $ids[2] => '' ) ) ), true );
	has( $r['args']['error'], 'aanpassen', 'aanpassing zonder opmerking' );
	$r = Order_Proof::process( array(), array_merge( $q, array( 'bps_confirm' => '1', 'choice' => array( $ids[0] => 'approve', $ids[1] => 'approve', $ids[2] => 'changes' ), 'notes' => array( $ids[2] => 'Kleiner' ) ) ), true );
	eq( $r['args']['view'], 'done' );
	eq( Proof_Service::get( $m->items[ $ids[0] ] )['status'], 'approved' );
	eq( Proof_Service::get( $m->items[ $ids[1] ] )['status'], 'approved' );
	eq( Proof_Service::get( $m->items[ $ids[2] ] )['status'], 'changes_requested' );
	eq( Proof_Service::get( $m->items[ $ids[2] ] )['note'], 'Kleiner' );
	ok( isset( Proof_Service::get( $m->items[ $ids[0] ] )['history'][0]['order_proof'] ), 'history-item per regel' );
	// The single-line proof keeps working for the line that needs changes.
	ok( ! is_wp_error( Proof_Service::send( $m, $m->items[ $ids[2] ], 'ronde 2' ) ), 'losse proef' );
	eq( approve( Proof_Service::url( $m, $m->items[ $ids[2] ] ) )['args']['view'], 'done' );
	eq( Order_Proof::process( $q, array(), false )['args']['view'], 'answered', 'paginalink toont nu de status' );
	// Forged token.
	eq( Order_Proof::process( array_merge( $q, array( 't' => str_repeat( 'b', 64 ) ) ), array(), false )['status'], 403 );
} );

t( 'atelier-flow-13: Mijn account toont status, miniatuur en "Proef bekijken"; niet in mails of voor anderen', function () {
	$o           = bps_t_order( array( line( design() ) ) );
	$o->customer = 7;
	$i           = first_item( $o );
	Proof_Service::send( $o, $i, '', bps_t_attachment( 'x' ) );
	$GLOBALS['bps_t']['user']     = 7;
	$GLOBALS['bps_t']['endpoint'] = 'view-order';
	ob_start();
	do_action( 'woocommerce_order_item_meta_end', $i->id, $i, $o, false );
	$html = ob_get_clean();
	has( $html, 'Wacht op jouw goedkeuring' );
	has( $html, 'Proef bekijken' );
	has( $html, esc_url( Proof_Service::url( $o, $i ) ) );
	has( $html, '<img' );
	$GLOBALS['bps_t']['user'] = 8;
	ob_start();
	do_action( 'woocommerce_order_item_meta_end', $i->id, $i, $o, false );
	eq( ob_get_clean(), '', 'andere klant ziet niets' );
	$GLOBALS['bps_t']['user'] = 7;
	ob_start();
	do_action( 'woocommerce_order_item_meta_end', $i->id, $i, $o, true );
	eq( ob_get_clean(), '', 'niet in plain-mails' );
	$GLOBALS['bps_t']['doing'][] = 'woocommerce_email_order_details';
	ob_start();
	do_action( 'woocommerce_order_item_meta_end', $i->id, $i, $o, false );
	eq( ob_get_clean(), '', 'niet in HTML-mails' );
	array_pop( $GLOBALS['bps_t']['doing'] );
	approve( Proof_Service::url( $o, $i ) );
	$html = Account::html( $o, $i );
	has( $html, 'Goedgekeurd' );
	ok( false === strpos( $html, 'Proef bekijken' ), 'na goedkeuring geen knop' );
	ob_start();
	Account::orders_cell( $o );
	eq( ob_get_clean(), '', 'orderlijst: niets meer open' );
} );

t( 'atelier-flow-2/14 + concurrentie-features-5: werkbon met goedgekeurde proef, feitenrij, stappen, machinefont, codes', function () {
	$GLOBALS['bps_t']['settings'] = array( 'fonts' => array( 1 => array( 'machineFont' => 'Block Condensed' ) ) );
	$d = design();
	$o = bps_t_order( array( line( $d, array( 'qty' => 1 ) ) ) );
	$o->items[ 9999 ] = new WC_Order_Item_Product( 9999, $o->id );
	$o->items[ 9999 ]->meta['_bps_service'] = array( 'id' => 'd4_rush', 'linkedRef' => first_item( $o )->meta['bps_ref'] );
	$i = first_item( $o );
	// No proof: preview, no banner.
	$html = Order_Production::render_print( array( array( $o->id, $i->id ) ) );
	ok( false === strpos( $html, 'NOG NIET GOEDGEKEURD' ), 'zonder proef geen banner' );
	has( $html, 'Voorbeeld uit de ontwerptool' );
	has( $html, 'Vast tarief', 'verzending' );
	has( $html, 'SPOED' );
	has( $html, '0470 12 34 56', 'telefoon' );
	has( $html, 'ONBETAALD' );
	has( $html, 'Oswald (smal en sterk) → machinefont: Block Condensed' );
	has( Order_Production::template_html( 'admin/fiche-card.php', array( 'fiche' => Order_Production::fiche( $o, $i ), 'order' => $o, 'can_proof' => false, 'print_url' => '#' ) ), 'Madeira Polyneon 1801 Wit', 'codes kopiëren met merk en naam' );
	eq( Order_Production::fiche( $o, $i )['sections']['specs']['rows'][3]['value'], '1 stuk', '1 stuk (i18n-a11y-6)' );
	$i->quantity = 2;
	eq( Order_Production::fiche( $o, $i )['sections']['specs']['rows'][3]['value'], '2 stuks' );
	// Proof sent, not approved: banner + last proof.
	$a = bps_t_attachment( 'approved-art' );
	Proof_Service::send( $o, $i, '', $a );
	$html = Order_Production::render_print( array( array( $o->id, $i->id ) ) );
	has( $html, 'NOG NIET GOEDGEKEURD' );
	// Approved: image in .visual__img and the label.
	approve( Proof_Service::url( $o, $i ) );
	$html = Order_Production::render_print( array( array( $o->id, $i->id ) ) );
	ok( 1 === preg_match( '/<img class="visual__img" src="' . preg_quote( esc_url( wp_get_attachment_url( $a ) ), '/' ) . '"/', $html ), 'beeld van de goedgekeurde ronde in .visual__img' );
	has( $html, 'Goedgekeurde proef' );
	ok( false === strpos( $html, 'NOG NIET GOEDGEKEURD' ), 'geen banner na goedkeuring' );
	// Production status from P10 through bps_fiche_data.
	add_filter( 'bps_fiche_data', static function ( $f ) { $f['production']['status'] = 'in_production'; return $f; } );
	$steps = Order_Production::work_steps( Order_Production::fiche( $o, $i ) );
	$done  = array_column( $steps, 'done', 'label' );
	ok( $done['Gedigitaliseerd'] && $done['Proef goedgekeurd'] && ! $done['Geproduceerd'], 'in_production vinkt Gedigitaliseerd en Proef goedgekeurd aan' );
	$GLOBALS['bps_t']['settings'] = array();
	eq( Spec::font_atelier( 'Oswald' ), 'Oswald (smal en sterk)', 'leeg machinefont: enkel webfont' );
	// Bulk print across orders + C6 hooks.
	$o2 = bps_t_order( array( line( design() ) ) );
	$seen = 0;
	add_action( 'bps_fiche_after_specs', static function () use ( &$seen ) { ++$seen; }, 10, 2 );
	$html = Order_Production::render_print( array( array( $o->id, $i->id ), array( $o2->id, first_item( $o2 )->id ) ) );
	eq( substr_count( $html, '<section class="page"' ), 2, '2 werkbonnen' );
	eq( $seen, 2, 'bps_fiche_after_specs per werkbon' );
} );

t( 'i18n-a11y-2: plain-text proefmails houden apostrof en aanhalingstekens (nl en fr)', function () {
	foreach ( array( 'nl-BE', 'fr-BE' ) as $loc ) {
		$GLOBALS['bps_t']['mails'] = array();
		$o = bps_t_order( array( line( design( $loc ) ) ) );
		$i = first_item( $o );
		Proof_Service::send( $o, $i, "l'écusson \"rond\" & co" );
		$plain = mails( 'bps_proof' )[0]['plain'];
		has( $plain, "l'écusson \"rond\" & co", $loc . ' bericht letterlijk' );
		has( $plain, "D'Hondt", $loc . ' tekst letterlijk' );
		ok( false === strpos( $plain, '&#' ) && false === strpos( $plain, '&amp;' ), $loc . ' geen entiteiten' );
		approve( Proof_Service::url( $o, $i ), array( 'note' => "l'écusson \"rond\" & co" ) );
		$shop = mails( 'bps_proof_response' )[0]['plain'];
		has( $shop, "Jan D'Hondt" );
		has( $shop, "l'écusson \"rond\" & co" );
		ok( false === strpos( $shop, '&#' ) && false === strpos( $shop, '&amp;' ), 'winkelmail zonder entiteiten' );
	}
} );

t( 'i18n-a11y-7 + -5: Frans onderwerp per veld, vertaalde standaard; Franse catalogus ook zonder core-pakket', function () {
	$GLOBALS['bps_t']['fr_catalogue'] = array( 'Je digitale proef voor patch {ref} staat klaar' => 'Votre épreuve numérique pour l’écusson {ref} est prête' );
	update_option( 'woocommerce_bps_proof_settings', array( 'subject' => 'Eigen NL-onderwerp {ref}' ) );
	$fr = bps_t_order( array( line( design( 'fr-BE' ) ) ) );
	Proof_Service::send( $fr, first_item( $fr ) );
	$m = mails( 'bps_proof' )[0];
	has( $m['subject'], 'Votre épreuve numérique', 'zonder FR-onderwerp: vertaalde standaard, niet het NL-maatwerk' );
	ok( in_array( 'load:borduur-patch-studio-fr_BE.mo', $GLOBALS['bps_t']['td_log'], true ), 'zonder fr_BE-core-pakket: eigen catalogus geladen' );
	$GLOBALS['bps_t']['emails'] = null;
	update_option( 'woocommerce_bps_proof_settings', array( 'subject' => 'Eigen NL-onderwerp {ref}', 'subject_fr' => 'Sujet FR {ref}' ) );
	$GLOBALS['bps_t']['mails'] = array();
	Proof_Service::send( $fr, first_item( $fr ) );
	has( mails( 'bps_proof' )[0]['subject'], 'Sujet FR', 'eigen FR-onderwerp' );
	$nl = bps_t_order( array( line( design( 'nl-BE' ) ) ) );
	$GLOBALS['bps_t']['mails'] = array();
	Proof_Service::send( $nl, first_item( $nl ) );
	has( mails( 'bps_proof' )[0]['subject'], 'Eigen NL-onderwerp', 'NL-klant krijgt het NL-maatwerk' );
	$fields = mails() ? ( apply_filters( 'woocommerce_email_classes', array() )['BPS_Email_Proof']->form_fields ) : array();
	ok( isset( $fields['subject_fr'], $fields['heading_fr'], $fields['additional_content_fr'] ), 'velden subject_fr, heading_fr, additional_content_fr' );
	// With the core language pack: switch_to_locale is used instead.
	$GLOBALS['bps_t']['core_langs'] = array( 'nl_BE', 'fr_BE' );
	$GLOBALS['bps_t']['td_log']     = array();
	Lang::switch_to( 'fr' );
	eq( get_locale(), 'fr_BE' );
	Lang::restore();
	eq( get_locale(), 'nl_BE' );
	update_option( 'woocommerce_bps_proof_settings', array() );
} );

t( 'C6: acties bps_proof_sent en bps_proof_response met order, item en ronde', function () {
	$o = bps_t_order( array( line( design() ) ) );
	$i = first_item( $o );
	Proof_Service::send( $o, $i );
	eq( $GLOBALS['bps_t']['actions_done']['bps_proof_sent'][0], array( $o->id, $i->id, 1 ) );
	approve( Proof_Service::url( $o, $i ) );
	eq( $GLOBALS['bps_t']['actions_done']['bps_proof_response'][0], array( $o->id, $i->id, 'approved', 1 ) );
	eq( Proof_Service::get( $i )['status'], 'approved' );
	eq( $i->meta['_bps_proof_status'], 'approved', 'mirror voor de lijstkolom' );
	eq( (string) $o->get_meta( '_bps_proof_open' ), '', 'orderflag gewist' );
	$f = Order_Production::fiche( $o, $i );
	eq( $f['proof']['status'], 'approved', 'fiche leest de itemproef' );
} );

$total = $GLOBALS['checks'];
$ok    = $total - $GLOBALS['failed'];
echo "\n" . ( $failures ? 'FAILED: ' . implode( ', ', $failures ) . "\n" : '' );
echo "{$ok}/{$total} ok\n";
exit( $failures ? 1 : 0 );
