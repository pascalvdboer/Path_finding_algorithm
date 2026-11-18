<?php
/**
 * P06 designs-account: unit tests of the design domain without WordPress (tests/woo/wp-stubs.php):
 * clone (C3), preview variants + preview_url fallback, storage ceiling + counter (security-3), revise in place vs.
 * copy (klant-account-3), DST/PES/EXP/JEF sniffing + DST header (concurrentie-features-8), reminder scheduling,
 * one-mail guarantee, cancellation and unsubscribe (klant-account-1), account helpers (klant-account-2) and the
 * studio config block. The REST/WooCommerce integration runs against a real test site: tests/woo/run-woo-wp.php.
 *
 * Run: php tests/woo/run-woo-php.php   (also through `npm test`: tests/woo/woo-php.test.js)
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

define( 'BPS_TESTING', true );
require __DIR__ . '/wp-stubs.php';

$root   = dirname( __DIR__, 2 );
$plugin = $root . '/plugin/borduur-patch-studio/';
define( 'BPS_DIR', $plugin );
define( 'BPS_FILE', $plugin . 'borduur-patch-studio.php' );
define( 'BPS_URL', 'https://shop.test/wp-content/plugins/borduur-patch-studio/' );
define( 'BPS_VERSION', '1.0.0' );
if ( ! defined( 'GB_IN_BYTES' ) ) {
	define( 'GB_IN_BYTES', 1073741824 );
}
if ( ! defined( 'UPLOAD_ERR_OK' ) ) {
	define( 'UPLOAD_ERR_OK', 0 );
}

require_once $plugin . 'includes/class-plugin.php';
spl_autoload_register( array( \BorduurPatchStudio\Plugin::class, 'autoload' ) );

use BorduurPatchStudio\Emb_File;
use BorduurPatchStudio\Logger;
use BorduurPatchStudio\Settings;
use BorduurPatchStudio\Uploads;
use BorduurPatchStudio\Woo\Account;
use BorduurPatchStudio\Woo\Config;
use BorduurPatchStudio\Woo\Designs;
use BorduurPatchStudio\Woo\Reminder;

/* ---------------------------------------------------------------------------
 * Harness
 * ------------------------------------------------------------------------- */
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
function settings( array $o ): void {
	$GLOBALS['bps_t']['options']['bps_settings'] = $o;
	Settings::flush();
	Logger::flush();
	Config::reset_cache();
}
$GLOBALS['logs'] = array();
Logger::set_writer(
	static function ( $level, $line ) {
		$GLOBALS['logs'][] = $level . ' ' . $line;
	}
);

/** Minimal REST request (Reminder::requested reads it). */
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $p;
		public function __construct( array $p ) {
			$this->p = $p;
		}
		public function get_json_params() {
			return $this->p;
		}
		public function get_param( $k ) {
			return $this->p[ $k ] ?? null;
		}
	}
}

/** Fake WooCommerce mailer with a recording reminder e-mail. */
class BPS_Fake_Mail {
	public $sent = array();
	public $fail = false;
	public function trigger( $id, $email = '', $locale = '', $unsub = '' ) {
		if ( $this->fail ) {
			return false;
		}
		$this->sent[] = compact( 'id', 'email', 'locale', 'unsub' );
		return true;
	}
}
class BPS_Fake_Mailer {
	public $emails;
	public function __construct() {
		$this->emails = array( 'BPS_Email_Design_Reminder' => new BPS_Fake_Mail() );
	}
	public function get_emails() {
		return $this->emails;
	}
}
class BPS_Fake_WC {
	public $m;
	public function mailer() {
		return $this->m ?? ( $this->m = new BPS_Fake_Mailer() );
	}
}
function WC() {
	return $GLOBALS['wc_fake'] ?? ( $GLOBALS['wc_fake'] = new BPS_Fake_WC() );
}
function mail_spy(): BPS_Fake_Mail {
	return WC()->mailer()->emails['BPS_Email_Design_Reminder'];
}

/** Fixed clock through the bps_now filter. */
$GLOBALS['clock'] = 1795000000; // 2026-11-18-ish.
add_filter( 'bps_now', static fn() => $GLOBALS['clock'] );

/** Temp files. */
$GLOBALS['tmp'] = sys_get_temp_dir() . '/bps-woo-p06-' . getmypid();
@mkdir( $GLOBALS['tmp'], 0777, true );
function tmpfile_with( string $content, string $name ): array {
	$path = $GLOBALS['tmp'] . '/' . uniqid( 'f', true ) . '-' . $name;
	file_put_contents( $path, $content );
	return array(
		'name'     => $name,
		'tmp_name' => $path,
		'size'     => strlen( $content ),
		'error'    => 0,
	);
}
/** A patch-like PNG (shapes, gradient, transparent corners). */
function patch_png( int $w, int $h ): string {
	$im = imagecreatetruecolor( $w, $h );
	imagealphablending( $im, false );
	imagesavealpha( $im, true );
	imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 0, 0, 0, 127 ) );
	imagealphablending( $im, true );
	for ( $y = 0; $y < $h; $y += 4 ) {
		imagefilledrectangle( $im, (int) ( $w * 0.1 ), $y, (int) ( $w * 0.9 ), $y + 3, imagecolorallocate( $im, 20 + ( $y % 200 ), 80, 160 ) );
	}
	imagefilledellipse( $im, (int) ( $w / 2 ), (int) ( $h / 2 ), (int) ( $w * 0.6 ), (int) ( $h * 0.6 ), imagecolorallocate( $im, 230, 40, 40 ) );
	imagefilledellipse( $im, (int) ( $w / 2 ), (int) ( $h / 2 ), (int) ( $w * 0.3 ), (int) ( $h * 0.3 ), imagecolorallocate( $im, 255, 255, 255 ) );
	ob_start();
	imagepng( $im );
	imagedestroy( $im );
	return (string) ob_get_clean();
}
/** DST with a known header. */
function dst_bytes( array $v ): string {
	$h  = str_pad( 'LA:' . str_pad( $v['label'], 16 ), 19 ) . "\r";
	$h .= 'ST:' . str_pad( (string) $v['st'], 7, ' ', STR_PAD_LEFT ) . "\r";
	$h .= 'CO:' . str_pad( (string) $v['co'], 3, ' ', STR_PAD_LEFT ) . "\r";
	$h .= '+X:' . str_pad( (string) $v['px'], 5, ' ', STR_PAD_LEFT ) . "\r";
	$h .= '-X:' . str_pad( (string) $v['mx'], 5, ' ', STR_PAD_LEFT ) . "\r";
	$h .= '+Y:' . str_pad( (string) $v['py'], 5, ' ', STR_PAD_LEFT ) . "\r";
	$h .= '-Y:' . str_pad( (string) $v['my'], 5, ' ', STR_PAD_LEFT ) . "\r";
	$h .= "AX:+    0\rAY:+    0\rMX:+    0\rMY:+    0\rPD:******\r\x1a";
	$h  = str_pad( $h, 512, ' ' );
	return $h . str_repeat( "\x01\x01\x03", 20 ) . "\x00\x00\xf3";
}
function private_files(): array {
	$out = array();
	$dir = Uploads::private_dir();
	if ( ! is_dir( $dir ) ) {
		return $out;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( ! in_array( $f->getFilename(), array( '.htaccess', 'index.php', 'web.config' ), true ) ) {
			$out[] = $f->getPathname();
		}
	}
	sort( $out );
	return $out;
}
function new_design( array $payload_extra = array(), ?array $original = null, ?string $png = null ) {
	$preview = Uploads::store_preview( tmpfile_with( $png ?? patch_png( 800, 800 ), 'preview.png' ) );
	$payload = array_merge(
		array(
			'v'         => 1,
			'technique' => 'embroidered',
			'shape'     => 'circle',
			'sizeCm'    => 8,
			'qty'       => 25,
			'options'   => array(),
			'texts'     => array( array( 'text' => 'Club' ) ),
			'locale'    => 'nl-BE',
		),
		$payload_extra
	);
	return Designs::create( $payload, $original, $preview, 'https://shop.test/patch-studio/' );
}

settings( array() );
$GLOBALS['bps_t']['options']['admin_email'] = 'admin@shop.test';
Designs::register(); // Hooks of the design domain (Emb_File, Account, Reminder, routes) on the stub hook table.

/* ---------------------------------------------------------------------------
 * 1. Embroidery files (concurrentie-features-8)
 * ------------------------------------------------------------------------- */
section( 'DST/PES/EXP/JEF: sniff, header, refusals' );
$dst = dst_bytes(
	array(
		'label' => 'CLUBLOGO',
		'st'    => 12345,
		'co'    => 4,
		'px'    => 523,
		'mx'    => 477,
		'py'    => 300,
		'my'    => 250,
	)
);
$f   = tmpfile_with( $dst, 'logo.dst' );
eq( Uploads::sniff( $f['tmp_name'] ), 'dst', 'DST recognised by its header' );
$hdr = Emb_File::parse_dst( $f['tmp_name'] );
eq( $hdr['stitches'] ?? null, 12345, 'DST stitches (ST)' );
eq( $hdr['colorChanges'] ?? null, 4, 'DST colour changes (CO)' );
eq( $hdr['widthMm'] ?? null, 100.0, 'DST width = (+X + -X) / 10 mm' );
eq( $hdr['heightMm'] ?? null, 55.0, 'DST height = (+Y + -Y) / 10 mm' );
eq( $hdr['label'] ?? null, 'CLUBLOGO', 'DST label' );
eq( Emb_File::parse_dst_header( 'LA:x' ), null, 'truncated DST header → null' );

$pes = '#PES0001' . pack( 'V', 40 ) . str_repeat( "\0", 60 );
eq( Uploads::sniff( tmpfile_with( $pes, 'a.pes' )['tmp_name'] ), 'pes', 'PES recognised (#PES + version + PEC offset)' );
$jef = pack( 'VV', 116, 20 ) . '20261118120000' . "\0\0" . pack( 'VV', 2, 100 ) . str_repeat( "\0", 200 );
eq( Uploads::sniff( tmpfile_with( $jef, 'a.jef' )['tmp_name'] ), 'jef', 'JEF recognised (offset + 14-digit date)' );
$exp = "\x0a\x0a\x80\x01\x00\x00\x05\xfb\x80\x80";
eq( Uploads::sniff( tmpfile_with( $exp, 'a.exp' )['tmp_name'], 'exp' ), 'exp', 'EXP recognised with .exp and a valid stitch stream' );
eq( Uploads::sniff( tmpfile_with( $exp, 'a.bin' )['tmp_name'] ), null, 'EXP bytes without the .exp hint are not guessed' );
eq( Uploads::sniff( tmpfile_with( "\x0a\x0a\x80\x33", 'b.exp' )['tmp_name'], 'exp' ), null, 'EXP with an invalid command byte refused' );

$stored = Uploads::store_original( tmpfile_with( $dst, 'logo.dst' ) );
ok( is_array( $stored ) && 'dst' === $stored['ext'] && Uploads::is_ours( $stored['path'], 'private' ), 'DST stored in the private dir' );
$png_as_pes = Uploads::store_original( tmpfile_with( patch_png( 50, 50 ), 'logo.pes' ) );
ok( is_wp_error( $png_as_pes ) && 415 === $png_as_pes->get_error_data()['status'] && false !== strpos( $png_as_pes->get_error_message(), 'geen geldig PES' ), 'renamed PNG with .pes refused (415, clear message)' );
$big = Uploads::store_original( tmpfile_with( substr( $dst, 0, 512 ) . str_repeat( "\x01\x01\x03", 1800000 ), 'big.dst' ) );
ok( is_wp_error( $big ) && 413 === $big->get_error_data()['status'], 'embroidery file > 5 MB refused (413)' );

$did = new_design( array(), $stored );
$rec = Designs::get( (int) $did );
eq( $rec['payload']['embFile']['stitches'] ?? null, 12345, 'embFile in the design payload' );
eq( get_post_meta( (int) $did, Emb_File::META, true )['widthMm'] ?? null, 100.0, 'embFile meta on the design' );
$sections = apply_filters( 'bps_fiche_sections', array(), $rec, null );
ok( isset( $sections['emb_file'] ) && 5 === count( $sections['emb_file']['rows'] ), 'fiche section "Borduurbestand van de klant" with type, stitches, colours, size, note' );
$values = array_column( $sections['emb_file']['rows'] ?? array(), 'value' );
ok( in_array( '12.345', $values, true ) && in_array( '4', $values, true ) && in_array( '100,0 × 55,0 mm', $values, true ), 'fiche shows stitches, colour changes, width × height', );
$fiche = apply_filters( 'bps_fiche_data', array( 'design' => array( 'id' => (int) $did ), 'sections' => array() ), null, null );
eq( $fiche['embFile']['colorChanges'] ?? null, 4, 'bps_fiche_data (C6) carries embFile' );

/* ---------------------------------------------------------------------------
 * 2. Preview variants (prestatie-1)
 * ------------------------------------------------------------------------- */
section( 'Preview variants, preview_url, delete' );
$t0 = microtime( true );
$p  = Uploads::store_preview( tmpfile_with( patch_png( 1000, 800 ), 'p.png' ) );
$ms = ( microtime( true ) - $t0 ) * 1000;
ok( is_array( $p ) && is_file( $p['path'] ), 'PNG master stored' );
$info = getimagesize( $p['path'] );
eq( array( $info[0], $info[1] ), array( 1000, 800 ), 'source ≤ 1200 px is not resampled' );
eq( array_keys( $p['sizes'] ), array( 'cart', 'cart2x', 'mail' ), 'three variants' );
ok( preg_match( '/-300\.webp$/', $p['sizes']['cart']['path'] ) && 300 === $p['sizes']['cart']['w'] && 240 === $p['sizes']['cart']['h'], 'cart = 300 px webp' );
ok( preg_match( '/-600\.webp$/', $p['sizes']['cart2x']['path'] ) && 600 === $p['sizes']['cart2x']['w'], 'cart2x = 600 px webp' );
ok( preg_match( '/-600\.jpg$/', $p['sizes']['mail']['path'] ) && 'image/jpeg' === $p['sizes']['mail']['mime'], 'mail = 600 px jpg' );
ok( filesize( $p['sizes']['cart']['path'] ) <= 40960 && filesize( $p['sizes']['cart2x']['path'] ) <= 40960, 'cart variants ≤ 40 kB (' . filesize( $p['sizes']['cart']['path'] ) . ' / ' . filesize( $p['sizes']['cart2x']['path'] ) . ' B)' );
ok( filesize( $p['sizes']['mail']['path'] ) <= 102400, 'mail variant ≤ 100 kB (' . filesize( $p['sizes']['mail']['path'] ) . ' B)' );
$jpg = imagecreatefromjpeg( $p['sizes']['mail']['path'] );
$rgb = imagecolorat( $jpg, 0, 0 );
ok( ( ( $rgb >> 16 ) & 0xFF ) > 245 && ( $rgb & 0xFF ) > 245, 'mail JPEG has a white background where the PNG is transparent' );
echo '  store_preview with variants: ' . round( $ms ) . " ms\n";
ok( $ms < 1500, 'store_preview incl. variants stays fast (' . round( $ms ) . ' ms)' );

$p2 = Uploads::store_preview( tmpfile_with( patch_png( 2000, 1500 ), 'p.png' ) );
$i2 = getimagesize( $p2['path'] );
eq( array( $i2[0], $i2[1] ), array( 1200, 900 ), 'larger source scaled to 1200 px' );

add_filter( 'bps_preview_webp', '__return_false' );
function __return_false() {
	return false;
}
$p3 = Uploads::store_preview( tmpfile_with( patch_png( 800, 800 ), 'p.png' ) );
remove_all_filters( 'bps_preview_webp' );
ok( preg_match( '/-300\.jpg$/', $p3['sizes']['cart']['path'] ) && 'image/jpeg' === $p3['sizes']['cart']['mime'], 'without imagewebp: cart variant falls back to JPEG on white' );

$big_preview = tmpfile_with( str_repeat( 'x', 9 * 1048576 ), 'big.png' );
$r           = Uploads::store_preview( $big_preview );
ok( is_wp_error( $r ) && 413 === $r->get_error_data()['status'], 'preview > 8 MB → 413' );

$ht = (string) file_get_contents( Uploads::preview_dir() . '.htaccess' );
ok( false !== strpos( $ht, '(png|webp|jpg)' ) && false !== strpos( $ht, 'max-age=31536000, immutable' ), 'previews/.htaccess allows png|webp|jpg with an immutable one-year cache' );
ok( '' !== Uploads::preview_path_from_url( $p['sizes']['cart']['url'] ), 'preview_path_from_url accepts variant names' );

$vid = (int) new_design();
ok( preg_match( '/-300\.webp$/', Designs::preview_url( $vid, 'cart' ) ) && preg_match( '/-600\.webp$/', Designs::preview_url( $vid, 'cart2x' ) ) && preg_match( '/-600\.jpg$/', Designs::preview_url( $vid, 'mail' ) ), 'preview_url(cart|cart2x|mail) gives the variants' );
ok( preg_match( '/\.png$/', Designs::preview_url( $vid, 'full' ) ), 'preview_url(full) is the PNG' );
$old = bps_t_insert_post( 'bps_design', array( '_bps_preview' => array( 'path' => '/x/a.png', 'url' => 'https://shop.test/a.png' ) ) );
eq( Designs::preview_url( $old, 'cart' ), 'https://shop.test/a.png', 'older design without variants falls back to the PNG' );
eq( Designs::preview_url( 999999, 'cart' ), '', 'unknown design → empty string' );
$meta  = get_post_meta( $vid, '_bps_preview', true );
$files = array_merge( array( $meta['path'] ), array_column( $meta['sizes'], 'path' ) );
Designs::delete_files( $vid );
ok( 0 === count( array_filter( $files, 'file_exists' ) ), 'delete_files leaves no variant behind' );

/* ---------------------------------------------------------------------------
 * 3. Storage ceiling (security-3)
 * ------------------------------------------------------------------------- */
section( 'Storage ceiling and counter' );
Uploads::storage_set( 0 );
settings( array( 'woo' => array( 'maxDraftStorageMb' => 1 ) ) );
$pdf  = '%PDF-1.4' . str_repeat( 'A', 838860 - 8 ); // 0,8 MB.
$o1   = Uploads::store_original( tmpfile_with( $pdf, 'a.pdf' ) );
$room = Uploads::check_storage( strlen( $pdf ) );
eq( $room, true, 'first 0,8 MB original fits under 1 MB' );
$d1 = (int) new_design( array(), $o1 );
eq( Uploads::storage_used(), strlen( $pdf ), 'counter = bytes of the draft original' );
$before = private_files();
$GLOBALS['bps_t']['mails'] = array();
$room2  = Uploads::check_storage( strlen( $pdf ) );
ok( is_wp_error( $room2 ) && 'bps_storage' === $room2->get_error_code() && 507 === $room2->get_error_data()['status'], 'second original → 507 bps_storage' );
ok( false !== strpos( $room2->get_error_message(), 'per e-mail' ), 'friendly text: send it by e-mail' );
eq( private_files(), $before, 'no file added in private/ by the refusal' );
eq( count( $GLOBALS['bps_t']['mails'] ), 1, 'admin mail once' );
Uploads::check_storage( strlen( $pdf ) );
eq( count( $GLOBALS['bps_t']['mails'] ), 1, 'no second admin mail on the same day (transient)' );
Designs::set_status( $d1, 'carted' );
eq( Uploads::storage_used(), strlen( $pdf ), 'carted still counts' );
Designs::set_status( $d1, 'ordered' );
eq( Uploads::storage_used(), 0, 'ordering releases the bytes' );
Designs::set_status( $d1, 'ordered' );
eq( Uploads::storage_used(), 0, 'idempotent (no double subtraction)' );
$o2 = Uploads::store_original( tmpfile_with( $pdf, 'b.pdf' ) );
$d2 = (int) new_design( array(), $o2 );
eq( Uploads::storage_used(), strlen( $pdf ), 'second draft counted' );
wp_delete_post( $d2, true );
eq( Uploads::storage_used(), 0, 'deleting the design lowers the counter' );
ok( ! file_exists( $o2['path'] ), 'deleting the design removed its original' );
settings( array() );
add_filter( 'bps_min_free_disk_bytes', static fn() => PHP_INT_MAX );
$low = Uploads::check_storage( 10 );
ok( is_wp_error( $low ) && 507 === $low->get_error_data()['status'], 'simulated low disk space (filter) → 507' );
remove_all_filters( 'bps_min_free_disk_bytes' );
add_filter( 'bps_disk_free_space', static fn() => 1000.0 );
ok( is_wp_error( Uploads::check_storage( 10 ) ), 'bps_disk_free_space filter → 507' );
remove_all_filters( 'bps_disk_free_space' );
eq( Uploads::check_storage( 10 ), true, 'normal situation → true' );
eq( $GLOBALS['bps_t']['autoload'][ Uploads::STORAGE_OPTION ] ?? null, false, 'counter option is not autoloaded' );

/* ---------------------------------------------------------------------------
 * 4. Clone (security-4, contract C3)
 * ------------------------------------------------------------------------- */
section( 'Designs::clone' );
wp_set_current_user( 0 );
$o   = Uploads::store_original( tmpfile_with( '%PDF-1.4 clone test', 'logo.pdf' ) );
$src = (int) new_design( array( 'qty' => 25 ), $o );
Designs::set_status( $src, 'ordered' );
update_post_meta( $src, '_bps_order_id', 501 );
update_post_meta( $src, '_bps_order_item_id', 601 );
Designs::update_proof( $src, array( 'status' => 'approved' ) );
update_post_meta( $src, '_bps_title', 'Clubpatch' );
$GLOBALS['clock'] += 5;
$c = Designs::clone( $src, array( 'parent_id' => $src ) );
ok( is_int( $c ) && $c > $src, 'clone returns a new id' );
$s = Designs::get( $src );
$k = Designs::get( (int) $c );
ok( $k['ref'] !== $s['ref'] && preg_match( '/^PS-\d{4}-\d{4}$/', $k['ref'] ), 'new ref (' . $k['ref'] . ')' );
ok( $k['created_gmt'] !== $s['created_gmt'], 'new created_gmt' );
ok( Designs::token_for( (int) $c ) !== Designs::token_for( $src ) && Designs::verify_token( (int) $c, Designs::token_for( (int) $c ) ), 'new valid token' );
ok( ! Designs::verify_token( (int) $c, Designs::token_for( $src ) ), 'old token does not open the clone' );
eq( $k['status'], 'draft', 'clone status draft' );
eq( $k['proof']['status'], 'none', 'clone proof none' );
eq( $k['order_id'], null, 'clone has no order link' );
eq( $k['parent_id'], $src, '_bps_parent_id' );
eq( $k['title'], 'Clubpatch', 'title copied' );
eq( $k['payload']['qty'], 25, 'payload copied' );
ok( $k['original']['path'] !== $s['original']['path'] && sha1_file( $k['original']['path'] ) === sha1_file( $s['original']['path'] ), 'original copied to a new random name (same content)' );
ok( $k['preview_url'] !== $s['preview_url'] && count( $k['preview_sizes'] ) === 3, 'preview + variants copied to new names' );
eq( $s['order_id'], 501, 'source keeps its order link' );
eq( $s['proof']['status'], 'approved', 'source keeps its proof status' );
wp_delete_post( (int) $c, true );
ok( is_file( $s['original']['path'] ) && is_file( get_post_meta( $src, '_bps_preview', true )['path'] ), 'deleting the clone leaves the source files intact' );
$c2 = Designs::clone(
	$src,
	array(
		'reorder_of' => array(
			'design_id' => $src,
			'order_id'  => 501,
		),
		'status'     => 'carted',
	)
);
eq( get_post_meta( (int) $c2, '_bps_reorder_of', true ), array( 'design_id' => $src, 'order_id' => 501 ), 'reorder_of stored' );
eq( Designs::get( (int) $c2 )['status'], 'carted', 'clone status carted when asked' );
$c3 = Designs::clone( 424242, array( 'from_payload' => array( 'technique' => 'embroidered', 'qty' => 50, 'sizeCm' => 8 ) ) );
ok( is_int( $c3 ) && 50 === Designs::get( $c3 )['payload']['qty'] && null === Designs::get( $c3 )['original'], 'from_payload: clone without source files' );
$c4 = Designs::clone( 424242 );
ok( is_wp_error( $c4 ) && 'bps_not_found' === $c4->get_error_code(), 'no source and no payload → bps_not_found' );
wp_set_current_user( 7 );
$c5 = Designs::clone( $src );
eq( Designs::owner( (int) $c5 ), 7, 'clone belongs to the current user' );
wp_set_current_user( 0 );

/* ---------------------------------------------------------------------------
 * 5. Revise (klant-account-3)
 * ------------------------------------------------------------------------- */
section( 'Designs::revise' );
$o    = Uploads::store_original( tmpfile_with( '%PDF-1.4 revise', 'r.pdf' ) );
$rid  = (int) new_design( array( 'qty' => 25 ), $o );
$tok  = Designs::token_for( $rid );
$sha  = sha1_file( $o['path'] );
$oldp = get_post_meta( $rid, '_bps_preview', true );
$newp = Uploads::store_preview( tmpfile_with( patch_png( 700, 700 ), 'n.png' ) );
$res  = Designs::revise( $rid, array_merge( Designs::get( $rid )['payload'], array( 'qty' => 50 ) ), $newp, null );
eq( $res['id'] ?? null, $rid, 'draft revised in place (same id)' );
eq( $res['cloned'] ?? null, false, 'not cloned' );
eq( Designs::token_for( $rid ), $tok, 'same token' );
$rr = Designs::get( $rid );
eq( $rr['payload']['qty'], 50, 'payload.qty = 50' );
ok( $rr['original']['path'] === $o['path'] && sha1_file( $rr['original']['path'] ) === $sha, 'original path and sha1 unchanged' );
ok( ! file_exists( $oldp['path'] ) && ! file_exists( $oldp['sizes']['cart']['path'] ), 'old preview and variants removed' );
eq( $rr['revision'], 2, '_bps_revision incremented' );
Designs::set_status( $rid, 'ordered' );
update_post_meta( $rid, '_bps_order_id', 777 );
$newp2 = Uploads::store_preview( tmpfile_with( patch_png( 700, 700 ), 'n2.png' ) );
$res2  = Designs::revise( $rid, array_merge( $rr['payload'], array( 'qty' => 100 ) ), $newp2, null );
ok( true === ( $res2['cloned'] ?? null ) && $res2['id'] !== $rid, 'ordered design → revision becomes a new design' );
eq( Designs::get( $res2['id'] )['parent_id'], $rid, 'new design has _bps_parent_id' );
eq( Designs::get( $res2['id'] )['proof']['status'], 'none', 'new design proof none' );
eq( Designs::get( $res2['id'] )['payload']['qty'], 100, 'new design carries the new payload' );
eq( Designs::get( $rid )['payload']['qty'], 50, 'ordered design payload unchanged' );
ok( Designs::get( $res2['id'] )['original']['path'] !== $o['path'] && sha1_file( Designs::get( $res2['id'] )['original']['path'] ) === $sha, 'original copied (not shared)' );

/* ---------------------------------------------------------------------------
 * 6. Account helpers (klant-account-2)
 * ------------------------------------------------------------------------- */
section( 'Account helpers and studio config' );
wp_set_current_user( 0 );
$aid = (int) new_design();
eq( Designs::owner( $aid ), 0, 'guest design has no owner' );
wp_set_current_user( 7 );
$own = (int) new_design();
eq( Designs::owner( $own ), 7, 'Designs::create: post_author = current user' );
ok( Designs::claim( $aid, 7 ), 'claim of a guest design' );
ok( ! Designs::claim( $aid, 8 ), 'a design of customer 7 can never be claimed by 8' );
eq( Designs::owner( $aid ), 7, 'owner unchanged after the refused claim' );
eq( Designs::set_title( $aid, "  Club <b>patch</b>\n2027 " ), 'Club patch 2027', 'title sanitised' );
eq( mb_strlen( Designs::set_title( $aid, str_repeat( 'é', 120 ) ) ), 80, 'title max 80 characters' );
Designs::set_status( $aid, 'saved' );
$apaths = get_post_meta( $aid, '_bps_preview', true );
eq( Designs::remove_for_customer( $aid ), 'deleted', 'saved design: deleted' );
ok( null === get_post( $aid ) && ! file_exists( $apaths['path'] ), 'post and files gone' );
Designs::set_status( $own, 'ordered' );
update_post_meta( $own, '_bps_order_id', 900 );
eq( Designs::remove_for_customer( $own ), 'hidden', 'ordered design: only hidden' );
ok( null !== get_post( $own ) && Designs::get( $own )['hidden'] && 900 === Designs::get( $own )['order_id'], 'ordered design and order link stay' );

eq( Account::slug(), 'mijn-ontwerpen', 'default slug (nl)' );
settings( array( 'account' => array( 'slug' => array( 'nl' => 'Mijn Patches!', 'fr' => 'mes-ecussons' ) ) ) );
eq( Account::slug(), 'mijn-patches', 'slug from account.slug, sanitised' );
settings( array() );
wp_set_current_user( 0 );
$cfg = Account::public_config( array( 'features' => array( 'textLayers' => true ) ), 'nl-BE' );
ok( false === $cfg['account']['loggedIn'] && ! isset( $cfg['account']['nonce'] ), 'guest: no nonce in the studio config' );
eq( $cfg['features']['reminder'], true, 'features.reminder true by default' );
eq( $cfg['reminder']['delayDays'], 3, 'reminder.delayDays 3 in the config' );
wp_set_current_user( 7 );
$cfg = Account::public_config( array(), 'nl-BE' );
ok( true === $cfg['account']['loggedIn'] && '' !== ( $cfg['account']['nonce'] ?? '' ), 'logged in: X-WP-Nonce in the studio config' );
settings( array( 'account' => array( 'enabled' => false ), 'reminder' => array( 'enabled' => false ) ) );
$cfg = Account::public_config( array(), 'nl-BE' );
ok( false === $cfg['account']['enabled'] && false === $cfg['features']['account'] && false === $cfg['features']['reminder'], 'account.enabled / reminder.enabled false reach the studio config' );
eq( Account::url(), '', 'Account::url() empty when the account page is off' );
settings( array() );
wp_set_current_user( 0 );

/* ---------------------------------------------------------------------------
 * 7. Reminder (klant-account-1)
 * ------------------------------------------------------------------------- */
section( 'Reminder: consent, one action, one mail, cancel, unsubscribe' );
function saved_design( string $email ): int {
	$id = (int) new_design();
	update_post_meta( $id, '_bps_email', $email );
	Designs::set_status( $id, 'saved' );
	Designs::touch( $id );
	return $id;
}
$GLOBALS['bps_t']['as'] = array();
$r1 = saved_design( 'klant@example.test' );
do_action( 'bps_design_emailed', $r1, 'klant@example.test', new WP_REST_Request( array( 'reminder' => true, 'locale' => 'fr-BE' ) ) );
$m = Reminder::get( $r1 );
ok( is_array( $m ) && gmdate( 'Y-m-d H:i:s', $GLOBALS['clock'] ) === $m['consent_at'], 'consent_at stored (fixed clock)' );
ok( 'fr-BE' === $m['consent_locale'] && '' !== $m['consent_text_version'] && null === $m['sent_gmt'] && null === $m['cancelled'], 'consent locale/text version, not sent, not cancelled' );
ok( 32 === strlen( $m['email_hash'] ) && false === strpos( wp_json_encode( $m ), 'klant@' ), 'only a keyed hash of the address in the reminder meta' );
$as = array_values( array_filter( $GLOBALS['bps_t']['as'], static fn( $a ) => Reminder::HOOK === $a['hook'] && array( $r1 ) === $a['args'] ) );
eq( count( $as ), 1, 'exactly 1 scheduled action' );
eq( $as[0]['ts'] ?? 0, $GLOBALS['clock'] + 3 * DAY_IN_SECONDS, 'action at +delayDays (3)' );
eq( $as[0]['group'] ?? '', 'bps-reminder', 'Action Scheduler group bps-reminder' );
$early = Reminder::run( $r1 );
eq( $early, 'early', 'run before the due time does not send' );
eq( count( mail_spy()->sent ), 0, 'nothing sent early' );
$GLOBALS['clock'] += 3 * DAY_IN_SECONDS;
eq( Reminder::run( $r1 ), 'sent', 'run at the due time sends' );
eq( count( mail_spy()->sent ), 1, '1 mail' );
ok( false !== strpos( mail_spy()->sent[0]['unsub'], 'reminder/unsubscribe' ) && false !== strpos( mail_spy()->sent[0]['unsub'], 's=' ), 'mail gets the signed unsubscribe link' );
eq( Reminder::run( $r1 ), 'already_sent', 'second run' );
eq( count( mail_spy()->sent ), 1, 'still 1 mail (at most one)' );
do_action( 'bps_design_emailed', $r1, 'klant@example.test', new WP_REST_Request( array( 'reminder' => true ) ) );
eq( Reminder::run( $r1 ), 'already_sent', 'saving again never schedules a second reminder' );

$r2 = saved_design( 'b@example.test' );
Reminder::schedule( $r2, 'b@example.test', 'nl-BE' );
Designs::set_status( $r2, 'carted' );
$GLOBALS['clock'] += 3 * DAY_IN_SECONDS;
eq( Reminder::run( $r2 ), 'cancelled', 'carted meanwhile (status stays "saved" by rank) → not sent' );
eq( Reminder::get( $r2 )['cancelled']['reason'] ?? '', 'status', 'cancelled set with reason status' );
$r3 = saved_design( 'c@example.test' );
Reminder::schedule( $r3, 'c@example.test' );
Designs::set_status( $r3, 'ordered' );
$GLOBALS['clock'] += 3 * DAY_IN_SECONDS;
eq( Reminder::run( $r3 ), 'cancelled', 'ordered meanwhile → not sent' );
eq( Reminder::get( $r3 )['cancelled']['reason'] ?? '', 'status', 'ordered: cancelled with reason status' );
$r3b = saved_design( 'c2@example.test' );
Reminder::schedule( $r3b, 'c2@example.test' );
update_post_meta( $r3b, '_bps_status', 'quoted' ); // Written behind our back (no set_status): the handler still checks.
$GLOBALS['clock'] += 3 * DAY_IN_SECONDS;
eq( Reminder::run( $r3b ), 'status', 'status changed without set_status → handler refuses and cancels' );
$r4 = saved_design( 'd@example.test' );
Reminder::schedule( $r4, 'd@example.test' );
$GLOBALS['clock'] += HOUR_IN_SECONDS;
Designs::touch( $r4 );
$GLOBALS['clock'] += 3 * DAY_IN_SECONDS;
eq( Reminder::run( $r4 ), 'opened', 'opened again after the consent → not sent' );
eq( count( mail_spy()->sent ), 1, 'no extra mails for carted/ordered/opened' );

$r5 = saved_design( 'e@example.test' );
Reminder::schedule( $r5, 'e@example.test' );
$url = Reminder::unsubscribe_url( $r5 );
parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
ok( ! Reminder::unsubscribe( $r5, str_repeat( 'a', 64 ) ), 'wrong signature refused' );
ok( Reminder::unsubscribe( (int) $q['d'], (string) $q['s'] ), 'valid unsubscribe link accepted' );
eq( Reminder::get( $r5 )['cancelled']['reason'] ?? '', 'unsubscribed', 'this design\'s reminder cancelled' );
$r6 = saved_design( 'E@example.test' );
Reminder::schedule( $r6, 'E@example.test' );
$GLOBALS['clock'] += 3 * DAY_IN_SECONDS;
eq( Reminder::run( $r6 ), 'unsubscribed', 'a later reminder to the same address (case-insensitive) is not sent' );
eq( $GLOBALS['bps_t']['autoload'][ Reminder::OPTOUT ] ?? null, false, 'opt-out list not autoloaded' );

$r7 = saved_design( 'f@example.test' );
$GLOBALS['bps_t']['as'] = array();
do_action( 'bps_design_emailed', $r7, 'f@example.test', new WP_REST_Request( array( 'locale' => 'nl-BE' ) ) );
ok( null === Reminder::get( $r7 ) && 0 === count( $GLOBALS['bps_t']['as'] ), 'without reminder:true: no consent, no action' );
do_action( 'bps_design_emailed', $r7, 'f@example.test', new WP_REST_Request( array( 'reminder' => 'true' ) ) );
eq( count( $GLOBALS['bps_t']['as'] ), 1, 'reminder:"true" (form post) also counts' );
do_action( 'bps_design_emailed', $r7, 'f@example.test', new WP_REST_Request( array( 'reminder' => false ) ) );
ok( 0 === count( $GLOBALS['bps_t']['as'] ) && 'withdrawn' === ( Reminder::get( $r7 )['cancelled']['reason'] ?? '' ), 'saving again without the tick withdraws the open reminder' );
settings( array( 'reminder' => array( 'enabled' => false ) ) );
$r8 = saved_design( 'g@example.test' );
do_action( 'bps_design_emailed', $r8, 'g@example.test', new WP_REST_Request( array( 'reminder' => true ) ) );
ok( null === Reminder::get( $r8 ) && 0 === count( $GLOBALS['bps_t']['as'] ), 'reminder.enabled=false: no action' );
settings( array() );
$r9 = saved_design( 'h@example.test' );
Reminder::schedule( $r9, 'h@example.test' );
update_post_meta( $r9, '_bps_email', 'other@example.test' );
$GLOBALS['clock'] += 3 * DAY_IN_SECONDS;
eq( Reminder::run( $r9 ), 'email_changed', 'address changed since the consent → not sent' );
$r10 = saved_design( 'i@example.test' );
Reminder::schedule( $r10, 'i@example.test' );
mail_spy()->fail = true;
$GLOBALS['clock'] += 3 * DAY_IN_SECONDS;
eq( Reminder::run( $r10 ), 'failed', 'mail failure is reported' );
mail_spy()->fail = false;
eq( Reminder::run( $r10 ), 'already_sent', 'a failed attempt is never retried (one attempt agreed)' );
$owned = \BorduurPatchStudio\Plugin::owned_data();
ok( in_array( Reminder::HOOK, $owned['cron_hooks'], true ) && in_array( Reminder::GROUP, $owned['as_groups'], true ), 'cron hook and AS group listed in bps_owned_data' );
$static = Designs::owned_data();
ok( ! array_diff( array( 'bps_draft_storage_bytes', 'bps_account_rewrite', 'bps_reminder_optout' ), $static['options'] ), 'Designs::owned_data() (static, works in uninstall) lists every P06 option' );

/* ---------------------------------------------------------------------------
 * Done
 * ------------------------------------------------------------------------- */
foreach ( $GLOBALS['logs'] as $l ) {
	if ( 0 === strpos( $l, 'critical' ) ) {
		fwrite( STDERR, "log: $l\n" );
	}
}
exec( 'rm -rf ' . escapeshellarg( $GLOBALS['tmp'] ) . ' ' . escapeshellarg( $GLOBALS['bps_t']['uploads'] ) );
$n = $GLOBALS['count'];
$f = $GLOBALS['fails'];
echo ( 0 === $f ? "\n$n/$n ok\n" : "\n$f of $n FAILED\n" );
exit( 0 === $f ? 0 : 1 );
