<?php
/**
 * Privacy and retention tests (premium P03): Woo\Privacy (export/erase, paginated, account link), Woo\Cleanup
 * (buckets incl. quotes and account designs, expiry with a small preview), Order_Lifecycle, User_Lifecycle and the
 * name-tape privacy (exporter, eraser, retention, blood-group consent, folder protection).
 *
 * Run: php tests/privacy/run-privacy-php.php   (exit code 1 on any failure; last line "N/N ok")
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

use BorduurPatchStudio\Logger;
use BorduurPatchStudio\Uploads;
use BorduurPatchStudio\Woo\Cleanup;
use BorduurPatchStudio\Woo\Designs;
use BorduurPatchStudio\Woo\Order_Lifecycle;
use BorduurPatchStudio\Woo\Privacy;
use BorduurPatchStudio\Woo\Quotes;
use BorduurPatchStudio\Woo\User_Lifecycle;
use BorduurPatchStudio\Nametape\Privacy as NT_Privacy;
use BorduurPatchStudio\Nametape\Order_Tools as NT_Order_Tools;
use BorduurPatchStudio\Nametape\Rest as NT_Rest;
use BorduurPatchStudio\Nametape\Settings_Schema as NT_Schema;
use BorduurPatchStudio\Nametape\Shortcode as NT_Shortcode;
use BorduurPatchStudio\Nametape\Config as NT_Config;

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
function t( string $name, callable $fn ): void {
	global $failures;
	fresh();
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

/** Reset static "registered" guards and caches of the classes under test. */
function reset_static( string $class, string $prop, $value ): void {
	if ( ! class_exists( $class ) ) {
		return;
	}
	$r = new ReflectionProperty( $class, $prop );
	$r->setAccessible( true );
	$r->setValue( null, $value );
}

function fresh(): void {
	bps_p_reset();
	Logger::$events = array();
	reset_static( Order_Lifecycle::class, 'registered', false );
	reset_static( User_Lifecycle::class, 'registered', false );
	reset_static( NT_Privacy::class, 'registered', false );
	NT_Config::flush();
	Designs::register();
	Privacy::register();
	NT_Privacy::register();
	Uploads::ensure_dirs();
}

function ago( int $days ): string {
	return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
}

/** A noisy PNG (does not compress well, so the small preview has to work for its 30 kB). */
function png_file( string $path, int $w = 1200, int $h = 800 ): string {
	$im = imagecreatetruecolor( $w, $h );
	mt_srand( 42 );
	for ( $y = 0; $y < $h; $y += 4 ) {
		for ( $x = 0; $x < $w; $x += 4 ) {
			imagefilledrectangle( $im, $x, $y, $x + 3, $y + 3, imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) );
		}
	}
	imagepng( $im, $path );
	imagedestroy( $im );
	return $path;
}

/**
 * A design post with meta (no REST, no files unless asked).
 *
 * @param array $o status, email, author, files, created, opened, ordered, order_id, item_id, texts, notes, title, reminder.
 */
function design( array $o = array() ): int {
	$id  = wp_insert_post( array( 'post_type' => Designs::CPT, 'post_title' => 'PS-2026-' . str_pad( (string) $GLOBALS['bps_p']['next_id'], 4, '0', STR_PAD_LEFT ), 'post_author' => (int) ( $o['author'] ?? 0 ) ) );
	$set = static fn( $k, $v ) => update_post_meta( $id, $k, $v );
	$set( '_bps_status', $o['status'] ?? 'draft' );
	$set( '_bps_created_gmt', $o['created'] ?? gmdate( 'Y-m-d H:i:s' ) );
	$set( '_bps_last_opened_gmt', $o['opened'] ?? gmdate( 'Y-m-d H:i:s' ) );
	$set( '_bps_payload', wp_slash( json_encode( array( 'technique' => 'embroidered', 'sizeCm' => 8, 'texts' => $o['texts'] ?? array( array( 'text' => 'FC Kalmthout' ) ), 'notes' => $o['notes'] ?? 'Bel me op 0475 12 34 56' ), JSON_UNESCAPED_UNICODE ) ) );
	$set( '_bps_proof', array( 'status' => 'approved', 'round' => 1, 'history' => array( array( 'at' => '2026-01-01T10:00:00Z', 'action' => 'changes', 'by' => 'customer', 'note' => 'Graag mijn naam Jan groter', 'round' => 1 ) ) ) );
	$set( '_bps_page_url', 'https://shop.test/studio/' );
	if ( ! empty( $o['email'] ) ) {
		$set( '_bps_email', $o['email'] );
		$set( '_bps_email_sent_to', $o['email'] );
	}
	foreach ( array( 'title' => '_bps_title', 'reminder' => '_bps_reminder', 'ordered' => '_bps_ordered_gmt', 'order_id' => '_bps_order_id', 'item_id' => '_bps_order_item_id', 'quote_id' => '_bps_quote_id' ) as $k => $meta ) {
		if ( isset( $o[ $k ] ) ) {
			$set( $meta, $o[ $k ] );
		}
	}
	if ( ! empty( $o['files'] ) ) {
		$orig = Uploads::private_dir() . bin2hex( random_bytes( 16 ) ) . '.png';
		file_put_contents( $orig, 'ORIGINAL' );
		$prev = png_file( Uploads::preview_dir() . bin2hex( random_bytes( 16 ) ) . '.png' );
		$set( '_bps_original', array( 'path' => $orig, 'name' => 'logo.png', 'ext' => 'png', 'bytes' => 8 ) );
		$set( '_bps_preview', array( 'path' => $prev, 'url' => Uploads::preview_url_for( $prev ) ) );
	} else {
		$set( '_bps_original', '' );
		$set( '_bps_preview', '' );
	}
	return $id;
}

/** Link a design to a (new) order line and mark it ordered. */
function ordered_design( string $email, string $order_status, array $o = array() ): array {
	$order = bps_p_order( $email, array( array() ), $order_status, (int) ( $o['customer'] ?? 0 ) );
	$item  = current( $order->items );
	$id    = design( array_merge( array( 'status' => 'ordered', 'email' => $email, 'order_id' => $order->id, 'item_id' => $item->id, 'ordered' => gmdate( 'Y-m-d H:i:s' ) ), $o ) );
	$item->meta['_bps_design_id']   = $id;
	$item->meta['_bps_preview_url'] = (string) ( get_post_meta( $id, '_bps_preview', true )['url'] ?? '' );
	return array( $id, $order, $item );
}

function quote( string $email, string $date_gmt, array $extra = array() ): int {
	$id = wp_insert_post( array( 'post_type' => Quotes::CPT, 'post_title' => 'OF-' . $GLOBALS['bps_p']['next_id'], 'post_date_gmt' => $date_gmt, 'post_author' => (int) ( $extra['author'] ?? 0 ) ) );
	foreach ( array( '_bps_name' => 'Jan Peeters', '_bps_email' => $email, '_bps_phone' => '0475 12 34 56', '_bps_company' => 'FC Test', '_bps_vat' => 'BE0123456789', '_bps_message' => 'Graag 50 stuks', '_bps_qty' => 50, '_bps_created_gmt' => $date_gmt ) as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	foreach ( $extra['meta'] ?? array() as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	return $id;
}

function export_all( callable $exporter, string $email ): array {
	$items = array();
	$pages = 0;
	for ( $page = 1; $page < 1000; $page++ ) {
		$r = $exporter( $email, $page );
		++$pages;
		$items = array_merge( $items, $r['data'] );
		if ( $r['done'] ) {
			break;
		}
	}
	return array( $items, $pages );
}

function settings( array $s ): void {
	$GLOBALS['bps_p']['settings'] = $s;
}

echo "privacy/retention PHP tests\n";

/* ============================================================ php-kwaliteit-9 */

t( 'php-9: 250 bestellingen + 520 offertes: export over meerdere pagina\'s, eraser done=false tot de laatste, daarna niets meer gekoppeld', function () {
	$email = 'groot@example.test';
	for ( $i = 0; $i < 250; $i++ ) {
		ordered_design( $email, 'completed' );
	}
	for ( $i = 0; $i < 520; $i++ ) {
		quote( $email, ago( 10 ) );
	}
	list( $items, $pages ) = export_all( array( Privacy::class, 'export' ), $email );
	$d = count( array_filter( $items, static fn( $x ) => 'bps-designs' === $x['group_id'] ) );
	$q = count( array_filter( $items, static fn( $x ) => 'bps-quotes' === $x['group_id'] ) );
	eq( $d, 250, 'alle ontwerpen' );
	eq( $q, 520, 'alle offertes' );
	eq( $pages, (int) ceil( 770 / Privacy::PAGE ), 'pagina\'s' );
	ok( count( array_unique( array_column( $items, 'item_id' ) ) ) === 770, 'geen dubbels' );

	$calls = 0;
	for ( $page = 1; $page < 100; $page++ ) {
		$r = Privacy::erase( $email, $page );
		++$calls;
		if ( $r['done'] ) {
			break;
		}
		ok( $r['items_removed'], 'pagina ' . $page . ' wist iets' );
	}
	ok( $calls >= 16, 'eraser liep over meerdere pagina\'s: ' . $calls );
	eq( count( Designs::query( array( array( 'key' => '_bps_email', 'value' => $email ) ), -1 ) ), 0, 'geen ontwerpen meer met dit e-mailadres' );
	$left = new WP_Query( array( 'post_type' => Quotes::CPT, 'fields' => 'ids', 'posts_per_page' => -1, 'meta_query' => array( array( 'key' => '_bps_email', 'value' => $email ) ) ) );
	eq( count( $left->posts ), 0, 'geen offertes meer met dit e-mailadres' );
	$anon = new WP_Query( array( 'post_type' => Quotes::CPT, 'fields' => 'ids', 'posts_per_page' => -1, 'meta_query' => array( array( 'key' => '_bps_anonymised_gmt', 'compare' => 'EXISTS' ) ) ) );
	eq( count( $anon->posts ), 520, 'alle offertes geanonimiseerd' );
	$again = Privacy::erase( $email, $calls + 1 );
	ok( $again['done'] && ! $again['items_removed'], 'nog eens wissen: niets meer te doen' );
	list( $after ) = export_all( array( Privacy::class, 'export' ), $email );
	foreach ( $after as $x ) {
		ok( false === strpos( json_encode( $x ), $email ), 'export na wissen bevat het e-mailadres niet meer' );
	}
} );

/* ============================================================ security-9(c) */

t( 'security-9c: eraser wist patchtekst en notes van het ontwerp bij een afgeronde bestelling', function () {
	list( $id ) = ordered_design( 'klant@example.test', 'completed', array( 'texts' => array( array( 'text' => 'Jan Peeters FC' ), array( 'text' => '' ) ), 'notes' => 'Bel me op 0475 12 34 56', 'files' => true ) );
	$r = Privacy::erase( 'klant@example.test', 1 );
	ok( $r['items_removed'] && $r['done'] );
	$raw = (string) get_post_meta( $id, '_bps_payload', true );
	ok( false === strpos( $raw, 'Jan Peeters' ), 'patchtekst weg: ' . $raw );
	ok( false === strpos( $raw, '0475' ), 'notes weg' );
	$p = json_decode( $raw, true );
	eq( $p['texts'][0]['text'], '[gewist]' );
	eq( $p['texts'][1]['text'], '', 'lege tekst blijft leeg' );
	eq( $p['notes'], '[gewist]' );
	ok( '' === get_post_meta( $id, '_bps_email', true ) && '' === get_post_meta( $id, '_bps_title', true ) );
	ok( '' !== (string) get_post_meta( $id, '_bps_erased_gmt', true ) );
	eq( get_post_meta( $id, '_bps_original', true ), '', 'origineel weg' );
	eq( get_post_meta( $id, '_bps_preview', true ), '', 'bij een wisverzoek ook geen voorbeeld' );
} );

t( 'eraser: lopende bestelling bewaard met melding; pending > 14 dagen telt als afgerond (gdpr-5)', function () {
	list( $run ) = ordered_design( 'loopt@example.test', 'processing', array( 'files' => true ) );
	list( $old, $order ) = ordered_design( 'loopt@example.test', 'pending', array( 'files' => true ) );
	$order->created = time() - 20 * DAY_IN_SECONDS;
	list( $fresh_pending, $o2 ) = ordered_design( 'loopt@example.test', 'pending' );
	$o2->created = time() - 3 * DAY_IN_SECONDS;
	$orig = get_post_meta( $old, '_bps_original', true )['path'];
	$r    = Privacy::erase( 'loopt@example.test', 1 );
	ok( $r['items_removed'], 'pending van 20 dagen: items_removed' );
	ok( $r['items_retained'] && 2 === count( $r['messages'] ), 'lopend + verse pending bewaard: ' . json_encode( $r['messages'] ) );
	ok( ! file_exists( $orig ), 'origineel van de oude pending weg' );
	ok( is_array( get_post_meta( $run, '_bps_original', true ) ), 'lopende bestelling: origineel blijft' );
	ok( Order_Lifecycle::is_finished( $order ) && ! Order_Lifecycle::is_finished( $o2 ) );
	$o2->status = 'on-hold';
	ok( ! Order_Lifecycle::is_finished( $o2 ), 'on-hold blijft lopend' );
} );

/* ============================================================ gdpr-juridisch-2 */

t( 'gdpr-2: Cleanup::run anonimiseert offertes ouder dan quoteDays zonder bestelling; recente en bestelde blijven', function () {
	$old     = quote( 'oud@example.test', ago( 400 ) );
	$recent  = quote( 'nieuw@example.test', ago( 1 ) );
	$ordered = quote( 'besteld@example.test', ago( 400 ), array( 'meta' => array( '_bps_order_id' => 77 ) ) );
	$counts  = Cleanup::run();
	eq( $counts['quotes'], 1, 'teller quotes' );
	foreach ( Quotes::CONTACT_META as $k ) {
		eq( get_post_meta( $old, $k, true ), '', 'oud: ' . $k . ' weg' );
		ok( '' !== (string) get_post_meta( $recent, $k, true ), 'recent: ' . $k . ' blijft' );
		ok( '' !== (string) get_post_meta( $ordered, $k, true ), 'besteld: ' . $k . ' blijft' );
	}
	ok( '' !== (string) get_post_meta( $old, '_bps_anonymised_gmt', true ), '_bps_anonymised_gmt gezet' );
	eq( (int) get_post_meta( $old, '_bps_qty', true ), 50, 'aantal (geen persoonsgegeven) blijft' );
	eq( Cleanup::run()['quotes'], 0, 'tweede run: niets meer' );
	settings( array( 'retention' => array( 'quoteDays' => 30 ) ) );
	$mid = quote( 'mid@example.test', ago( 31 ) );
	eq( Cleanup::run()['quotes'], 1, 'quoteDays instelbaar' );
	eq( get_post_meta( $mid, '_bps_email', true ), '' );
} );

t( 'gdpr-2: Privacy::erase gebruikt dezelfde anonimisering (en haalt de accountkoppeling weg)', function () {
	bps_p_add_user( 12, 'acc@example.test' );
	$a = quote( 'acc@example.test', ago( 2 ) );
	$b = quote( 'ander@example.test', ago( 2 ), array( 'author' => 12 ) );
	$r = Privacy::erase( 'acc@example.test', 1 );
	ok( $r['done'] && $r['items_removed'] );
	foreach ( array( $a, $b ) as $q ) {
		eq( get_post_meta( $q, '_bps_name', true ), '', 'naam weg' );
		ok( '' !== (string) get_post_meta( $q, '_bps_anonymised_gmt', true ) );
		eq( (int) get_post( $q )->post_author, 0 );
	}
	$src = file_get_contents( BPS_DIR . 'includes/woo/class-privacy.php' );
	ok( false !== strpos( $src, 'Cleanup::anonymise_quote' ) && false === strpos( $src, 'Quotes::CONTACT_META as' ), 'geen eigen kopie van de anonimisering in de eraser' );
} );

/* ============================================================ klant-account-8 */

t( 'klant-account-8: gast-saved na 91 d weg; account-saved na 91 d behouden, na 731 d weg; drafts op orphanDays', function () {
	$guest   = design( array( 'status' => 'saved', 'opened' => ago( 91 ), 'files' => true ) );
	$acc91   = design( array( 'status' => 'saved', 'opened' => ago( 91 ), 'author' => 7 ) );
	$acc731  = design( array( 'status' => 'saved', 'opened' => ago( 731 ), 'author' => 7, 'files' => true ) );
	$draft   = design( array( 'status' => 'draft', 'created' => ago( 31 ), 'author' => 7 ) );
	$draftok = design( array( 'status' => 'draft', 'created' => ago( 5 ) ) );
	$orig    = get_post_meta( $acc731, '_bps_original', true )['path'];
	$c       = Cleanup::run();
	ok( null === get_post( $guest ), 'gast 91 d weg' );
	ok( null !== get_post( $acc91 ), 'account 91 d blijft' );
	ok( null === get_post( $acc731 ), 'account 731 d weg' );
	ok( ! file_exists( $orig ), 'bestanden mee weg' );
	ok( null === get_post( $draft ) && null !== get_post( $draftok ), 'drafts op orphanDays, ook met account' );
	eq( $c['saved'], 2 );
	settings( array( 'retention' => array( 'accountSavedDays' => 60 ) ) );
	Cleanup::run();
	ok( null === get_post( $acc91 ), 'accountSavedDays instelbaar' );
} );

t( 'klant-account-8: besteld na orderYears: origineel weg, voorbeeld <= 300 px en <= 30 kB blijft, orderregel verwijst ernaar', function () {
	list( $id, $order, $item ) = ordered_design( 'oud@example.test', 'completed', array( 'files' => true, 'ordered' => gmdate( 'Y-m-d H:i:s', strtotime( '-4 years' ) ) ) );
	$orig = get_post_meta( $id, '_bps_original', true )['path'];
	$prev = get_post_meta( $id, '_bps_preview', true )['path'];
	ok( filesize( $prev ) > 30720, 'testbeeld is groter dan 30 kB (' . filesize( $prev ) . ')' );
	$c = Cleanup::run();
	eq( $c['ordered'], 1 );
	ok( ! file_exists( $orig ), 'origineel weg' );
	ok( ! file_exists( $prev ), 'groot voorbeeld weg' );
	$small = get_post_meta( $id, '_bps_preview', true );
	ok( is_array( $small ) && is_file( $small['path'] ), 'klein voorbeeld bestaat' );
	ok( filesize( $small['path'] ) <= 30720, 'klein voorbeeld <= 30 kB: ' . filesize( $small['path'] ) );
	$info = getimagesize( $small['path'] );
	ok( max( $info[0], $info[1] ) <= 300 && IMAGETYPE_PNG === $info[2], 'max 300 px PNG' );
	eq( $item->meta['_bps_preview_url'], $small['url'], 'orderregel verwijst naar het kleine voorbeeld' );
	eq( Uploads::preview_path_from_url( $small['url'] ), $small['path'], 'URL valt in de publieke previews-map (PNG wordt geserveerd)' );
	ok( false !== strpos( (string) $item->meta['bps_file_removed'], 'bewaartermijn' ) );
	ok( Uploads::is_ours( $small['path'], 'previews' ) );
} );

t( 'klant-account-8: keepPreviewOnExpiry=false wist ook het voorbeeld; privacytekst toont de ingestelde termijnen', function () {
	settings( array( 'retention' => array( 'keepPreviewOnExpiry' => false, 'savedDays' => 45, 'accountSavedDays' => 500, 'quoteDays' => 200, 'orderYears' => 4, 'orphanDays' => 20 ) ) );
	list( $id, , $item ) = ordered_design( 'x@example.test', 'completed', array( 'files' => true, 'ordered' => gmdate( 'Y-m-d H:i:s', strtotime( '-5 years' ) ) ) );
	Cleanup::run();
	eq( get_post_meta( $id, '_bps_preview', true ), '' );
	eq( $item->meta['_bps_preview_url'], '' );
	$html = Privacy::policy_html();
	foreach ( array( '20 dagen', '45 dagen', '500 dagen', '200 dagen', '4 jaar' ) as $w ) {
		ok( false !== strpos( $html, $w ), 'beleidstekst mist ' . $w );
	}
	ok( false === strpos( $html, 'klein voorbeeldbeeld' ), 'zonder keepPreview geen belofte over een voorbeeld' );
} );

/* ============================================================ gdpr-juridisch-6 */

t( 'gdpr-6: expire_ordered wist e-mail, sent-to, pagina-URL, notes en proefnotities', function () {
	list( $id ) = ordered_design( 'p@example.test', 'completed', array( 'files' => true ) );
	Cleanup::expire_ordered( $id );
	foreach ( array( '_bps_email', '_bps_email_sent_to', '_bps_page_url' ) as $k ) {
		eq( get_post_meta( $id, $k, true ), '', $k );
	}
	$p = json_decode( (string) get_post_meta( $id, '_bps_payload', true ), true );
	eq( $p['notes'], '' );
	eq( $p['texts'][0]['text'], 'FC Kalmthout', 'patchtekst blijft bij de cron (productiedossier)' );
	foreach ( get_post_meta( $id, '_bps_proof', true )['history'] as $h ) {
		eq( $h['note'], '', 'history.note leeg' );
	}
	eq( get_post_meta( $id, '_bps_proof', true )['status'], 'approved', 'proefstatus blijft' );
} );

/* ============================================================ gdpr-juridisch-5 */

t( 'gdpr-5: failed/cancelled zet het ontwerp op carted (nieuwe klok); Cleanup na +31 d verwijdert het', function () {
	list( $id, $order ) = ordered_design( 'f@example.test', 'pending', array( 'files' => true, 'created' => ago( 60 ) ) );
	$order->status = 'failed';
	do_action( 'woocommerce_order_status_failed', $order->id, $order );
	eq( get_post_meta( $id, '_bps_status', true ), 'carted' );
	ok( abs( strtotime( get_post_meta( $id, '_bps_created_gmt', true ) . ' UTC' ) - time() ) < 5, 'created_gmt = nu' );
	eq( get_post_meta( $id, '_bps_order_id', true ), '' );
	eq( Cleanup::run()['orphan'], 0, 'vandaag nog niet' );
	update_post_meta( $id, '_bps_created_gmt', ago( 31 ) ); // Clock + 31 days.
	$orig = get_post_meta( $id, '_bps_original', true )['path'];
	eq( Cleanup::run()['orphan'], 1 );
	ok( null === get_post( $id ) && ! file_exists( $orig ), 'ontwerp en bestand weg' );

	list( $other, $o2 ) = ordered_design( 'f@example.test', 'processing' );
	list( $mine, $o3 ) = ordered_design( 'f@example.test', 'processing' );
	$o2->items[ key( $o2->items ) ]->meta['_bps_design_id'] = $mine; // A cancelled order pointing to a design another order owns.
	do_action( 'woocommerce_order_status_cancelled', $o2->id );
	eq( get_post_meta( $mine, '_bps_status', true ), 'ordered', 'ontwerp van een andere bestelling blijft besteld' );
	do_action( 'woocommerce_order_status_cancelled', $o3->id );
	eq( get_post_meta( $mine, '_bps_status', true ), 'carted' );
} );

t( 'gdpr-5: WC-anonimisering en verwijderen van een bestelling ruimen het origineel en het e-mailadres op', function () {
	list( $a, $oa ) = ordered_design( 'w@example.test', 'completed', array( 'files' => true ) );
	list( $b, $ob ) = ordered_design( 'w@example.test', 'cancelled', array( 'files' => true ) );
	$pa = get_post_meta( $a, '_bps_original', true )['path'];
	$pb = get_post_meta( $b, '_bps_original', true )['path'];
	do_action( 'woocommerce_privacy_remove_order_personal_data', $oa );
	ok( ! file_exists( $pa ) && '' === get_post_meta( $a, '_bps_email', true ), 'anonimiseren: origineel en e-mail weg' );
	do_action( 'woocommerce_before_delete_order', $ob->id, $ob );
	ok( ! file_exists( $pb ) && '' === get_post_meta( $b, '_bps_email', true ), 'verwijderen: origineel en e-mail weg' );
} );

/* ============================================================ klant-account-12 */

t( 'klant-account-12: export via accountkoppeling (zonder _bps_email), titel en herinnering mee; proefgeschiedenis', function () {
	bps_p_add_user( 7, 'u7@example.test' );
	$s1 = design( array( 'status' => 'saved', 'author' => 7, 'title' => 'Logo voetbalclub', 'reminder' => array( 'at' => '2026-12-01', 'sent' => false ) ) );
	$s2 = design( array( 'status' => 'saved', 'author' => 7 ) );
	design( array( 'status' => 'saved', 'author' => 8 ) );
	list( $items ) = export_all( array( Privacy::class, 'export' ), 'u7@example.test' );
	$ids = array_column( $items, 'item_id' );
	eq( count( $ids ), 2, 'beide accountontwerpen' );
	ok( in_array( 'bps-design-' . $s1, $ids, true ) && in_array( 'bps-design-' . $s2, $ids, true ) );
	$all = json_encode( $items, JSON_UNESCAPED_UNICODE );
	ok( false !== strpos( $all, 'Logo voetbalclub' ), 'titel in export' );
	ok( false !== strpos( $all, '2026-12-01' ), 'herinnering in export' );
	ok( false !== strpos( $all, 'Proefgeschiedenis' ) && false !== strpos( $all, 'Graag mijn naam Jan groter' ), 'proefgeschiedenis (zolang Proof_Service er niet is)' );
} );

t( 'klant-account-12: gebruiker verwijderen: bewaarde ontwerpen + bestanden weg, besteld ontwerp blijft (author 0, geen e-mail/titel) en gekoppeld', function () {
	bps_p_add_user( 7, 'u7@example.test' );
	$s1 = design( array( 'status' => 'saved', 'author' => 7, 'files' => true, 'title' => 'A' ) );
	$s2 = design( array( 'status' => 'draft', 'author' => 7, 'files' => true ) );
	$f1 = get_post_meta( $s1, '_bps_original', true )['path'];
	$f2 = get_post_meta( $s2, '_bps_preview', true )['path'];
	list( $ord, $order, $item ) = ordered_design( 'u7@example.test', 'completed', array( 'author' => 7, 'title' => 'Besteld logo', 'reminder' => '2026-12-01', 'customer' => 7 ) );
	$qid = design( array( 'status' => 'quoted', 'author' => 7, 'email' => 'u7@example.test', 'quote_id' => 999 ) );
	wp_delete_user( 7 );
	$left = new WP_Query( array( 'post_type' => Designs::CPT, 'fields' => 'ids', 'posts_per_page' => -1, 'author__in' => array( 7 ) ) );
	eq( count( $left->posts ), 0, 'geen ontwerpen meer met author 7' );
	ok( null === get_post( $s1 ) && null === get_post( $s2 ), 'niet-bestelde ontwerpen weg' );
	ok( ! file_exists( $f1 ) && ! file_exists( $f2 ), 'hun bestanden weg' );
	ok( null !== get_post( $ord ), 'besteld ontwerp blijft' );
	eq( (int) get_post( $ord )->post_author, 0 );
	foreach ( array( '_bps_email', '_bps_title', '_bps_reminder' ) as $k ) {
		eq( get_post_meta( $ord, $k, true ), '', $k );
	}
	eq( (int) $item->meta['_bps_design_id'], $ord, 'order-item blijft gekoppeld' );
	eq( (int) get_post_meta( $ord, '_bps_order_id', true ), $order->id );
	ok( null !== get_post( $qid ) && 0 === (int) get_post( $qid )->post_author, 'offerte-ontwerp blijft, zonder account' );
} );

/* ============================================================ gdpr-juridisch-1 (name tapes) */

function nt_line( string $name, string $blood, string $file = '' ): array {
	$nt = array(
		'v'            => 1,
		'locale'       => 'nl-BE',
		'format'       => '10x2.5',
		'rows'         => array( array( 'n' => $name, 'l2' => 'Afdeling 3', 'b' => $blood, 'q' => 2 ) ),
		'org_note'     => 'Brandweer Zone Noord',
		'review_words' => array( 'PEETERS' ),
		'preview_url'  => '' !== $file ? 'https://shop.test/wp-content/uploads/patch-studio/nametape/' . $file : '',
		'preview_file' => $file,
		'bloodConsent' => array( 'v' => 'nt-blood-1', 'at' => '2026-11-18T10:00:00Z', 'text_sha1' => sha1( NT_Privacy::BLOOD_CONSENT_TEXT['nl'] ) ),
	);
	return array(
		'_bps_nt_batch'       => json_encode( $nt ),
		'bps_nt_blood'        => 'op eigen strook (2×)',
		'_bps_nt_preview'     => $file,
		'_bps_rights_consent' => json_encode( array( 'v' => '2026-1', 'at' => '2026-11-18T10:00:00Z' ) ),
	);
}

function nt_png(): string {
	$dir = $GLOBALS['bps_p']['upload_dir'] . '/' . NT_Rest::PREVIEW_DIR;
	@mkdir( $dir, 0777, true );
	$name = bin2hex( random_bytes( 16 ) ) . '.png';
	png_file( $dir . '/' . $name, 200, 60 );
	return $name;
}

t( 'gdpr-1: exporter geeft 1 item met naam en bloedgroep; eraser bij completed wist naam, bloedgroep en PNG', function () {
	$file  = nt_png();
	$order = bps_p_order( 'nt@example.test', array( nt_line( 'Jan Peeters', 'O+', $file ) ), 'completed' );
	$item  = current( $order->items );
	list( $items ) = export_all( array( NT_Privacy::class, 'export' ), 'nt@example.test' );
	eq( count( $items ), 1, '1 item' );
	$txt = json_encode( $items, JSON_UNESCAPED_UNICODE );
	ok( false !== strpos( $txt, 'Jan Peeters' ) && false !== strpos( $txt, 'O+' ), 'naam en bloedgroep in export' );
	ok( false !== strpos( $txt, 'Toestemming bloedgroep' ), 'toestemming in export' );
	$path = NT_Privacy::preview_path( $file );
	ok( is_file( $path ) );
	$r = NT_Privacy::erase( 'nt@example.test', 1 );
	ok( $r['items_removed'] && ! $r['items_retained'] && $r['done'] );
	$batch = (string) $item->meta['_bps_nt_batch'];
	ok( false === strpos( $batch, 'Jan Peeters' ) && false === strpos( $batch, 'O+' ), 'batch zonder naam/bloedgroep: ' . $batch );
	ok( false === strpos( $batch, 'Brandweer' ) && false === strpos( $batch, 'PEETERS' ) && false === strpos( $batch, 'Afdeling' ), 'organisatie, nakijkwoorden en regel 2 weg' );
	ok( ! isset( $item->meta['bps_nt_blood'] ) && ! isset( $item->meta['_bps_nt_preview'] ), 'bps_nt_blood en _bps_nt_preview weg' );
	ok( ! file_exists( $path ), 'PNG weg' );
	eq( json_decode( $batch, true )['rows'][0]['q'], 2, 'aantal blijft (boekhouding)' );
	$again = NT_Privacy::erase( 'nt@example.test', 1 );
	ok( ! $again['items_removed'], 'tweede keer: niets' );
} );

t( 'gdpr-1: lopende bestelling: items_retained met melding, niets gewist; registratie van exporter/eraser', function () {
	$order = bps_p_order( 'nt@example.test', array( nt_line( 'Jan Peeters', 'O+' ) ), 'processing' );
	$r     = NT_Privacy::erase( 'nt@example.test', 1 );
	ok( ! $r['items_removed'] && $r['items_retained'] && 1 === count( $r['messages'] ) );
	ok( false !== strpos( current( $order->items )->meta['_bps_nt_batch'], 'Jan Peeters' ) );
	ok( isset( apply_filters( 'wp_privacy_personal_data_erasers', array() )['borduur-patch-studio-nametape'] ) );
	ok( isset( apply_filters( 'wp_privacy_personal_data_exporters', array() )['borduur-patch-studio-nametape'] ) );
	ok( isset( apply_filters( 'wp_privacy_personal_data_erasers', array() )['borduur-patch-studio'] ) );
	$many = array();
	for ( $i = 0; $i < 25; $i++ ) {
		$many[] = bps_p_order( 'veel@example.test', array( nt_line( 'Naam ' . $i, '' ) ), 'completed' );
	}
	$r1 = NT_Privacy::erase( 'veel@example.test', 1 );
	ok( ! $r1['done'], 'gepagineerd: pagina 1 niet klaar' );
	$r2 = NT_Privacy::erase( 'veel@example.test', 2 );
	ok( $r2['done'] );
	foreach ( $many as $o ) {
		ok( false === strpos( current( $o->items )->meta['_bps_nt_batch'], 'Naam ' ), 'alle 25 gewist' );
	}
} );

t( 'gdpr-1: cron na daysAfterComplete anonimiseert dezelfde velden; recente bestelling blijft; map krijgt .htaccess', function () {
	$file = nt_png();
	$old  = bps_p_order( 'nt@example.test', array( nt_line( 'Jan Peeters', 'O+', $file ) ), 'completed' );
	$old->completed = time() - 181 * DAY_IN_SECONDS;
	$new  = bps_p_order( 'nt@example.test', array( nt_line( 'Piet Janssens', 'A-' ) ), 'completed' );
	$new->completed = time() - 10 * DAY_IN_SECONDS;
	$run  = bps_p_order( 'nt@example.test', array( nt_line( 'Lopend', 'B+' ) ), 'processing' );
	$run->created   = time() - 400 * DAY_IN_SECONDS;
	$run->modified  = $run->created;
	add_filter( 'bps_nt_retention_candidates', static function ( $pre, $after ) {
		return array_values( array_filter( array_keys( $GLOBALS['bps_p']['orders'] ), static fn( $id ) => $id > $after ) );
	}, 10, 2 );
	NT_Order_Tools::cleanup();
	$b = (string) current( $old->items )->meta['_bps_nt_batch'];
	ok( false === strpos( $b, 'Jan Peeters' ) && false === strpos( $b, 'O+' ), 'oude bestelling geanonimiseerd' );
	ok( ! isset( current( $old->items )->meta['bps_nt_blood'] ) && ! file_exists( NT_Privacy::preview_path( $file ) ) );
	ok( false !== strpos( current( $new->items )->meta['_bps_nt_batch'], 'Piet Janssens' ), 'recent blijft' );
	ok( false !== strpos( current( $run->items )->meta['_bps_nt_batch'], 'Lopend' ), 'lopend blijft' );
	$ht = $GLOBALS['bps_p']['upload_dir'] . '/' . NT_Rest::PREVIEW_DIR . '/.htaccess';
	ok( is_file( $ht ) && false !== strpos( file_get_contents( $ht ), 'noindex' ) && false !== strpos( file_get_contents( $ht ), '-Indexes' ), '.htaccess met noindex' );
	ok( is_file( dirname( $ht ) . '/index.php' ) );
	update_option( NT_Config::OPTION, array( 'retention' => array( 'daysAfterComplete' => 5 ) ) );
	NT_Config::flush();
	eq( NT_Privacy::retention_days(), 5 );
	NT_Order_Tools::cleanup();
	ok( false === strpos( current( $new->items )->meta['_bps_nt_batch'], 'Piet Janssens' ), 'instelbare termijn' );
	ok( in_array( NT_Privacy::ANON_META, NT_Order_Tools::hidden_meta( array() ), true ), 'markering verborgen in het beheer' );
} );

t( 'gdpr-1: instellingenschema begrenst retention.daysAfterComplete (standaard 180)', function () {
	$cur = array( 'formats' => array(), 'fabrics' => array(), 'letterColors' => array(), 'fonts' => array(), 'borders' => array(), 'backings' => array() );
	$res = NT_Schema::sanitize( array( 'retention' => array( 'daysAfterComplete' => '9999' ) ), $cur, $cur );
	eq( $res['config']['retention']['daysAfterComplete'], 3650 );
	$res = NT_Schema::sanitize( array( 'retention' => array( 'daysAfterComplete' => '3' ) ), $cur, $cur );
	eq( $res['config']['retention']['daysAfterComplete'], 30 );
	$res = NT_Schema::sanitize( array( 'retention' => array( 'daysAfterComplete' => 'x' ) ), $cur, $cur );
	eq( $res['config']['retention']['daysAfterComplete'], 180 );
	$res = NT_Schema::sanitize( array(), $cur + array( 'retention' => array( 'daysAfterComplete' => 90 ) ), $cur );
	eq( $res['config']['retention']['daysAfterComplete'], 90, 'zonder veld blijft de waarde' );
	eq( NT_Privacy::retention_days(), 180, 'standaard 180' );
} );

/** A valid name-tape batch for the REST route. */
function nt_batch( ?string $blood, $consent = null ): array {
	$b = array(
		'v'             => 1,
		'locale'        => 'nl-BE',
		'format'        => '10x2.5',
		'fabric'        => 'black',
		'letter'        => 'white',
		'border'        => 'satin_letter',
		'font'          => 'block',
		'uppercase'     => true,
		'backing'       => 'hook',
		'loop'          => false,
		'bloodMode'     => null,
		'rows'          => array( array( 'name' => 'Jan Peeters', 'line2' => '', 'blood' => $blood, 'qty' => 1 ) ),
		'rightsConsent' => array( 'v' => 'x', 'accepted' => true ),
	);
	if ( null !== $consent ) {
		$b['bloodConsent'] = $consent;
	}
	return $b;
}

t( 'gdpr-1: POST /nametape/cart met bloedgroep zonder bloodConsent = 400; met vakje of zonder bloedgroep geen 400', function () {
	$req = new WP_REST_Request( 'POST' );
	$req->set_param( 'batch', json_encode( nt_batch( 'O+' ) ) );
	$res = NT_Rest::cart( $req );
	ok( is_wp_error( $res ) && 'bps_blood_consent_required' === $res->get_error_code(), 'zonder vakje: ' . ( is_wp_error( $res ) ? $res->get_error_code() : get_class( $res ) ) );
	eq( $res->get_error_data()['status'], 400 );
	foreach ( array( array( 'accepted' => false ), 'nee', array() ) as $bad ) {
		$req->set_param( 'batch', json_encode( nt_batch( 'O+', $bad ) ) );
		$r = NT_Rest::cart( $req );
		ok( is_wp_error( $r ) && 'bps_blood_consent_required' === $r->get_error_code(), 'ongeldige toestemming: ' . json_encode( $bad ) );
	}
	$req->set_param( 'batch', json_encode( nt_batch( 'O+', array( 'accepted' => true, 'v' => 'client-says-v9' ) ) ) );
	$r = NT_Rest::cart( $req );
	ok( ! is_wp_error( $r ) || 'bps_blood_consent_required' !== $r->get_error_code(), 'met vakje voorbij de controle' );
	$req->set_param( 'batch', json_encode( nt_batch( null ) ) );
	$r = NT_Rest::cart( $req );
	ok( ! is_wp_error( $r ) || 'bps_blood_consent_required' !== $r->get_error_code(), 'zonder bloedgroep geen vakje nodig' );
	$rec = NT_Privacy::consent_record( 'fr-BE' );
	eq( $rec['v'], NT_Privacy::BLOOD_CONSENT_VERSION, 'versie van de server, niet van de client' );
	eq( $rec['text_sha1'], sha1( NT_Privacy::BLOOD_CONSENT_TEXT['fr'] ) );
	eq( $rec['locale'], 'fr_BE' );
	ok( 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $rec['at'] ) );
	$src = file_get_contents( BPS_DIR . 'includes/nametape/class-rest.php' );
	ok( false !== strpos( $src, "\$check['batch']['bloodConsent'] = Privacy::consent_record(" ), 'record gaat onder batch[bloodConsent] naar Cart (P04)' );
	$pub = NT_Privacy::blood_consent_public( 'nl-BE' );
	eq( $pub['text'], NT_Privacy::BLOOD_CONSENT_TEXT['nl'] );
} );

/* ============================================================ productisering-4 (name tapes) */

t( 'productisering-4: naamstrook-hulpzin uit Help_Line, geen vaste "bel of WhatsApp" meer in noscript en messages.js', function () {
	$GLOBALS['bps_p']['help_line'] = 'Mail ons gerust op info@example.test.';
	eq( NT_Shortcode::help_line( 'nl-BE' ), 'Mail ons gerust op info@example.test.' );
	$GLOBALS['bps_p']['help_line'] = '';
	eq( NT_Shortcode::help_line( 'nl-BE' ), 'Neem gerust contact met ons op.' );
	ok( false === stripos( NT_Shortcode::help_line( 'fr-BE' ), 'whatsapp' ) );
	$php = file_get_contents( BPS_DIR . 'includes/nametape/class-shortcode.php' );
	$js  = file_get_contents( BPS_DIR . 'assets/src/nametape/messages.js' );
	$ui  = file_get_contents( BPS_DIR . 'assets/src/nametape/ui.js' );
	ok( false === stripos( $php, 'whatsapp' ), 'shortcode zonder vaste WhatsApp' );
	ok( false === stripos( $js, 'whatsapp' ), 'messages.js zonder vaste WhatsApp' );
	ok( false !== strpos( $ui, 'cfg.helpLine' ) && false !== strpos( $php, "\$cfg['helpLine']" ), 'hulpzin gaat via de config naar de JS' );
	ok( false !== strpos( $ui, 'data-act="bloodConsent"' ) && false === strpos( $ui, 'data-act="bloodConsent" checked' ), 'bloedgroepvakje bestaat en is niet vooraf aangevinkt' );
} );

/* ============================================================ gdpr-juridisch-12 (Woo text) */

t( 'gdpr-12: beleidstekst in je-vorm met naamstroken, bloedgroep, doorgifte naar de VS en offertes', function () {
	$html = Privacy::policy_html();
	$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES, 'UTF-8' );
	ok( ! preg_match( '/\b(u|uw|U|Uw)\b/u', $text ), 'geen u/uw' );
	foreach ( array( 'bloedgroep', 'Verenigde Staten', 'Data Privacy Framework', 'OpenAI', 'Recraft', 'Naamstroken', '180 dagen', 'contactgegevens', '730 dagen', '365 dagen', 'je laatste bestelling' ) as $w ) {
		ok( false !== strpos( $text, $w ), 'mist: ' . $w );
	}
	Privacy::policy_content();
	ok( isset( $GLOBALS['bps_p']['policy']['Patch Studio'] ) );
} );

/* ------------------------------------------------------------------ cleanup */

$dir = $GLOBALS['bps_p']['upload_dir'];
if ( is_dir( $dir ) && 0 === strpos( $dir, sys_get_temp_dir() ) ) {
	exec( 'rm -rf ' . escapeshellarg( $dir ) );
}

$total = $GLOBALS['checks'];
$ok    = $total - $GLOBALS['failed'];
echo "\n" . ( $failures ? 'Mislukt: ' . implode( ' | ', $failures ) . "\n" : '' );
echo "{$ok}/{$total} ok\n";
exit( $failures ? 1 : 0 );
