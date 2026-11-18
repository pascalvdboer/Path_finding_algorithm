<?php
/**
 * Security regression tests (P01): SVG sanitizer, Settings limits, Guard (IP/CIDR, IPv6 /64 rate key, Origin
 * check, rate limit), design tokens, upload sniffing/checks, payload validation, help line, REST error paths.
 * No WordPress, no database, no network: tests/security/php/wp-stubs.php + the plugin classes.
 *
 * Run: php tests/security/run-security-php.php      (also through `npm test`: tests/security/security-php.test.js)
 *      php tests/security/run-security-php.php --child=no-testing   (internal: upload check without BPS_TESTING)
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

$child = '';
foreach ( $argv as $a ) {
	if ( 0 === strpos( $a, '--child=' ) ) {
		$child = substr( $a, 8 );
	}
}
if ( 'no-testing' === $child ) {
	define( 'BPS_TESTING', false );
}

require __DIR__ . '/php/wp-stubs.php';

define( 'BPS_SEC_PLUGIN', dirname( __DIR__, 2 ) . '/plugin/borduur-patch-studio/' );
defined( 'BPS_DIR' ) || define( 'BPS_DIR', BPS_SEC_PLUGIN );

// Same autoloader convention as the plugin bootstrap.
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'BorduurPatchStudio\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class, strlen( $prefix ) ) );
		$name  = array_pop( $parts );
		$dir   = '';
		foreach ( $parts as $part ) {
			$dir .= str_replace( '_', '-', strtolower( $part ) ) . '/';
		}
		$file = BPS_SEC_PLUGIN . 'includes/' . $dir . 'class-' . str_replace( '_', '-', strtolower( $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

use BorduurPatchStudio\Help_Line;
use BorduurPatchStudio\Rest\Guard;
use BorduurPatchStudio\Rest\Routes;
use BorduurPatchStudio\Settings;
use BorduurPatchStudio\Uploads;
use BorduurPatchStudio\Woo\Designs;
use BorduurPatchStudio\Woo\Payload;
use BorduurPatchStudio\Woo\Svg_Sanitizer;

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
function err_code( $v ): string {
	return $v instanceof WP_Error ? (string) $v->get_error_code() : '(no error)';
}
function err_status( $v ): int {
	return $v instanceof WP_Error ? (int) ( $v->get_error_data()['status'] ?? 0 ) : 0;
}
/** Fresh WP state + config cache. */
function fresh(): void {
	bps_sec_reset();
	Settings::flush();
	$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
	unset( $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['CONTENT_LENGTH'] );
}
/** Override config values through the bps_config filter (like a site would). */
function with_config( array $over ): void {
	if ( array() === $over ) {
		Settings::flush();
		return;
	}
	add_filter(
		'bps_config',
		static function ( $c ) use ( $over ) {
			return Settings::deep_merge( $c, $over );
		}
	);
	Settings::flush();
}
function tmpdir( string $name ): string {
	$d = sys_get_temp_dir() . '/bps-sec-' . getmypid() . '-' . $name;
	if ( ! is_dir( $d ) ) {
		mkdir( $d, 0777, true );
	}
	return $d;
}
function rrmdir( string $d ): void {
	if ( ! is_dir( $d ) ) {
		return;
	}
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $d );
}
function count_files( string $d ): int {
	if ( ! is_dir( $d ) ) {
		return 0;
	}
	$n = 0;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		$n += $f->isFile() && ! in_array( $f->getFilename(), array( '.htaccess', 'index.php', 'web.config' ), true ) ? 1 : 0;
	}
	return $n;
}
/** Call a private static method. */
function call_private( string $class, string $method, ...$args ) {
	$m = new ReflectionMethod( $class, $method );
	$m->setAccessible( true );
	return $m->invoke( null, ...$args );
}

/* ======================================================================================
 * Child process: check_upload refuses a file that was not uploaded over HTTP (no BPS_TESTING)
 * ==================================================================================== */
if ( 'no-testing' === $child ) {
	$f = tempnam( sys_get_temp_dir(), 'bps' );
	file_put_contents( $f, "\x89PNG\r\n\x1a\nxxxx" );
	$res = call_private( Uploads::class, 'check_upload', array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $f, 'size' => 12 ) );
	unlink( $f );
	echo 'CHILD ' . err_code( $res ) . ' ' . err_status( $res ) . "\n";
	exit( 0 );
}

/* ======================================================================================
 * 1. SVG sanitizer
 * ==================================================================================== */
section( 'SVG-sanitizer: XSS- en externe-bronvectoren (svg-vectors.json)' );
fresh();
$vectors = json_decode( (string) file_get_contents( __DIR__ . '/svg-vectors.json' ), true );
ok( is_array( $vectors ) && count( $vectors['vectors'] ) >= 30, 'svg-vectors.json has >= 30 vectors' );
$security6 = 0;
foreach ( $vectors['vectors'] as $vec ) {
	$out = Svg_Sanitizer::sanitize( $vec['svg'] );
	if ( false === $out ) {
		ok( ! empty( $vec['rejectOk'] ), "svg [{$vec['name']}]: refused although a cleaned result was expected" );
		continue;
	}
	$low = strtolower( $out );
	foreach ( $vec['mustNotContain'] as $bad ) {
		ok( false === strpos( $low, strtolower( $bad ) ), "svg [{$vec['name']}]: output still contains '" . $bad . "' → " . substr( $out, 0, 400 ) );
	}
	foreach ( $vec['mustContain'] ?? array() as $good ) {
		ok( false !== strpos( $out, $good ), "svg [{$vec['name']}]: legitimate content '" . $good . "' was lost → " . substr( $out, 0, 400 ) );
	}
	// The cleaned file must itself be well-formed XML (it is offered for download to the digitiser).
	$d = new DOMDocument();
	ok( @$d->loadXML( $out, LIBXML_NONET ), "svg [{$vec['name']}]: output is well-formed XML" );
	if ( 'security-6' === ( $vec['finding'] ?? '' ) ) {
		++$security6;
	}
}
ok( $security6 >= 6, 'security-6: the 6 CSS-escape/string-URL vectors are present (' . $security6 . ')' );

section( 'SVG-sanitizer: legitieme logo\'s blijven identiek (gouden uitvoer van vóór security-6)' );
$legit = glob( __DIR__ . '/fixtures/legit-*.svg' );
$legit = array_values( array_filter( $legit, static fn( $f ) => false === strpos( $f, '.expected.' ) ) );
ok( count( $legit ) >= 8, 'at least 8 legitimate SVG fixtures' );
foreach ( $legit as $f ) {
	$expected = __DIR__ . '/fixtures/' . basename( $f, '.svg' ) . '.expected.svg';
	$out      = Svg_Sanitizer::sanitize( (string) file_get_contents( $f ) );
	ok( is_string( $out ), basename( $f ) . ': sanitized' );
	eq( $out, (string) file_get_contents( $expected ), basename( $f ) . ': identical to the golden output' );
}
$grad = (string) Svg_Sanitizer::sanitize( (string) file_get_contents( __DIR__ . '/fixtures/legit-gradient-logo.svg' ) );
ok( false !== strpos( $grad, 'url(#g1)' ) && false !== strpos( $grad, 'clip-path="url(#clip)"' ), 'local url(#id) references survive' );
ok( false !== strpos( $grad, 'href="#arc"' ), 'local use/textPath href survives' );

section( 'SVG-sanitizer: clean_css() verwijdert elke backslash en elke string-URL-functie' );
foreach ( array(
	'fill:u\\rl(https://evil.example/x)',
	'@imp\\ort "https://evil.example/a.css";',
	'fill:\\000075rl(https://evil.example/x)',
	'background:\\69mage("https://evil.example/x")',
	'background:image-set("https://evil.example/x" 1x)',
	'background:cross-fade(url(https://evil.example/a), url(#ok))',
	'font:src("https://evil.example/f.woff")',
) as $css ) {
	$clean = Svg_Sanitizer::clean_css( $css );
	ok( false === strpos( $clean, '\\' ) && false === stripos( $clean, 'evil.example' ), 'clean_css(' . $css . ') → ' . $clean );
}
eq( Svg_Sanitizer::clean_css( '.a{fill:url(#grad)}' ), '.a{fill:url(#grad)}', 'clean_css keeps url(#id)' );
eq( Svg_Sanitizer::clean_css( 'fill:#fff;stroke-width:2' ), 'fill:#fff;stroke-width:2', 'clean_css keeps ordinary declarations' );
ok( false === Svg_Sanitizer::sanitize( '' ) && false === Svg_Sanitizer::sanitize( 'not xml' ), 'empty / non-XML input is refused' );
ok( false === Svg_Sanitizer::sanitize( '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat( ' ', Svg_Sanitizer::MAX_BYTES ) . '</svg>' ), 'SVG larger than MAX_BYTES is refused' );

/* ======================================================================================
 * 2. Settings LIMITS (clamping, wildcards, enums)
 * ==================================================================================== */
section( 'Settings: LIMITS en ENUMS bij kwade invoer' );
fresh();
Settings::update(
	array(
		'woo'        => array(
			'maxUploadMb'       => 99999,
			'rateLimits'        => array(
				'designsPerHour' => -50,
				'quotesPerHour'  => '1.000.000',
			),
			'trustCloudflareIp' => 'always-please',
		),
		'vat'        => array( 'rate' => 7 ),
		'techniques' => array(
			'embroidered' => array(
				'minCm' => 0,
				'maxCm' => 5000,
			),
		),
		'tiers'      => array( 'steps' => array( array( 'min' => 10, 'discount' => 5 ) ) ),
		'contact'    => array(
			'phone'    => '<script>alert(1)</script>0470',
			'whatsapp' => '+32 (0)470-11"><img src=x>',
			'email'    => 'not-an-email"><script>',
		),
	)
);
$c = Settings::get();
eq( $c['woo']['maxUploadMb'], 256, 'woo.maxUploadMb clamped to 256' );
eq( $c['woo']['rateLimits']['designsPerHour'], 1, 'woo.rateLimits.* wildcard clamps negatives to 1' );
ok( $c['woo']['rateLimits']['quotesPerHour'] <= 100000, 'woo.rateLimits.quotesPerHour ≤ 100000' );
eq( $c['woo']['trustCloudflareIp'], 'auto', 'unknown enum value for trustCloudflareIp is dropped (default stays)' );
ok( (float) $c['vat']['rate'] <= 1.0, 'vat.rate clamped to ≤ 1' );
ok( $c['techniques']['embroidered']['minCm'] >= 1, 'techniques.*.minCm wildcard clamps to ≥ 1' );
ok( $c['techniques']['embroidered']['maxCm'] <= 100, 'techniques.*.maxCm wildcard clamps to ≤ 100' );
ok( false === strpos( (string) $c['contact']['phone'], '<' ), 'contact.phone loses markup' );
ok( (bool) preg_match( '/^\d*$/', (string) $c['contact']['whatsapp'] ), 'contact.whatsapp keeps digits only' );
ok( false === strpos( (string) $c['contact']['email'], '<' ), 'invalid contact.email is not stored as markup' );
$discount = $c['tiers']['steps'][0]['discount'] ?? null;
ok( null === $discount || $discount <= 0.95, 'tiers.steps.*.discount clamped to ≤ 0.95' );

/* ======================================================================================
 * 3. Guard: CIDR, client IP, IPv6 /64 rate key, rate limit, Origin, status check
 * ==================================================================================== */
section( 'Guard: CIDR-matching IPv4/IPv6 en randgevallen' );
fresh();
ok( Guard::ip_in_ranges( '173.245.48.1', Guard::CF_RANGES ), 'v4 inside 173.245.48.0/20' );
ok( Guard::ip_in_ranges( '173.245.63.255', Guard::CF_RANGES ), 'v4 last address of /20' );
ok( ! Guard::ip_in_ranges( '173.245.64.0', Guard::CF_RANGES ), 'v4 first address after /20' );
ok( Guard::ip_in_ranges( '2606:4700::6810:85e5', Guard::CF_RANGES ), 'v6 inside 2606:4700::/32' );
ok( Guard::ip_in_ranges( '2a06:98c7:ffff::1', Guard::CF_RANGES ), 'v6 inside the odd-sized 2a06:98c0::/29' );
ok( ! Guard::ip_in_ranges( '2a06:98c8::1', Guard::CF_RANGES ), 'v6 just outside 2a06:98c0::/29' );
ok( ! Guard::ip_in_ranges( '2606:4701::1', Guard::CF_RANGES ), 'v6 outside 2606:4700::/32' );
ok( ! Guard::ip_in_ranges( 'not-an-ip', Guard::CF_RANGES ), 'garbage is never inside' );
ok( ! Guard::ip_in_ranges( '', Guard::CF_RANGES ), 'empty is never inside' );
ok( ! Guard::ip_in_ranges( '::ffff:173.245.48.1', array( '173.245.48.0/20' ) ), 'v4-mapped v6 does not match a v4 range (family differs)' );
ok( Guard::ip_in_ranges( '10.1.2.3', array( '10.0.0.0/8' ) ) && ! Guard::ip_in_ranges( '11.0.0.1', array( '10.0.0.0/8' ) ), 'generic /8 boundaries' );
ok( Guard::ip_in_ranges( '192.0.2.5', array( '192.0.2.5/32' ) ) && ! Guard::ip_in_ranges( '192.0.2.6', array( '192.0.2.5/32' ) ), '/32 matches exactly one address' );

section( 'Guard: client_ip vertrouwt CF-Connecting-IP alleen vanuit Cloudflare' );
$_SERVER['REMOTE_ADDR']           = '203.0.113.5';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '192.0.2.99';
eq( Guard::client_ip(), '203.0.113.5', "auto + REMOTE_ADDR outside Cloudflare → header ignored" );
$_SERVER['REMOTE_ADDR'] = '162.158.1.2';
eq( Guard::client_ip(), '192.0.2.99', 'auto + REMOTE_ADDR inside Cloudflare v4 → header used' );
$_SERVER['REMOTE_ADDR'] = '2400:cb00:1::1';
eq( Guard::client_ip(), '192.0.2.99', 'auto + REMOTE_ADDR inside Cloudflare v6 → header used' );
$_SERVER['HTTP_CF_CONNECTING_IP'] = '192.0.2.99, 10.0.0.1';
eq( Guard::client_ip(), '2400:cb00:1::1', 'malformed CF header is ignored even from Cloudflare' );
$_SERVER['REMOTE_ADDR']           = '203.0.113.5';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '192.0.2.99';
with_config( array( 'woo' => array( 'trustCloudflareIp' => 'no' ) ) );
$_SERVER['REMOTE_ADDR'] = '162.158.1.2';
eq( Guard::client_ip(), '162.158.1.2', "'no' → never trusted" );
fresh();
with_config( array( 'woo' => array( 'trustCloudflareIp' => 'yes' ) ) );
$_SERVER['REMOTE_ADDR']           = '203.0.113.5';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '192.0.2.99';
eq( Guard::client_ip(), '192.0.2.99', "'yes' → always trusted (status check warns, see below)" );

section( 'Guard: statuswaarschuwing trustCloudflareIp (security-2c, C7)' );
$checks = apply_filters( 'bps_status_checks', array() );
eq( $checks, array(), 'no checks before Guard::register()' );
Guard::register();
$checks = apply_filters( 'bps_status_checks', array() );
ok( 1 === count( $checks ) && 'warn' === $checks[0]['status'] && 'bps_trust_cloudflare_ip' === $checks[0]['id'], "trustCloudflareIp='yes' + REMOTE_ADDR 203.0.113.5 → warning" );
ok( isset( $checks[0]['label'], $checks[0]['message'], $checks[0]['detail'] ) && false === $checks[0]['ok'], 'check has id/label/status/message (+ ok/detail for the current status page)' );
fresh();
with_config( array( 'woo' => array( 'trustCloudflareIp' => true ) ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
ok( null !== Guard::cloudflare_check(), 'trustCloudflareIp=true (bool) also warns' );
$_SERVER['REMOTE_ADDR'] = '104.16.0.1';
ok( null === Guard::cloudflare_check(), 'no warning when REMOTE_ADDR is a Cloudflare edge' );
fresh();
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
ok( null === Guard::cloudflare_check(), "no warning with 'auto'" );

section( 'Guard: rate-limit-sleutel per IPv6 /64 (security-2a)' );
fresh();
eq( Guard::rate_ip( '2001:db8::1' ), Guard::rate_ip( '2001:db8::2' ), '2001:db8::1 and ::2 share one key' );
eq( Guard::rate_ip( '2001:db8::1' ), '2001:db8::/64', 'key is the /64 network' );
ok( Guard::rate_ip( '2001:db8:0:1::1' ) !== Guard::rate_ip( '2001:db8::1' ), '2001:db8:0:1::1 has its own key' );
eq( Guard::rate_ip( '2001:db8:0:0:ffff:ffff:ffff:ffff' ), '2001:db8::/64', 'last address of the /64 still shares the key' );
eq( Guard::rate_ip( '198.51.100.7' ), '198.51.100.7', 'IPv4 stays a /32' );
ok( Guard::rate_ip( '198.51.100.7' ) !== Guard::rate_ip( '198.51.100.8' ), 'neighbouring IPv4 addresses are separate' );
eq( Guard::rate_ip( '::ffff:198.51.100.7' ), '198.51.100.7', 'IPv4-mapped IPv6 counts as the IPv4 address' );
eq( Guard::rate_ip( '' ), '', 'empty REMOTE_ADDR stays empty (one shared bucket)' );
add_filter( 'bps_rate_limit_ipv6_prefix', static fn() => 48 );
eq( Guard::rate_ip( '2001:db8:1:2::1' ), '2001:db8:1::/48', 'filter bps_rate_limit_ipv6_prefix=48' );
remove_all_filters( 'bps_rate_limit_ipv6_prefix' );
add_filter( 'bps_rate_limit_ipv6_prefix', static fn() => 3 );
eq( Guard::rate_ip( '2001:db8::1' ), '2001::/16', 'prefix filter is clamped to ≥ 16' );

section( 'Guard: rate limit N toegestaan, N+1 geweigerd (transient én object-cache)' );
foreach ( array( false, true ) as $ext ) {
	fresh();
	$GLOBALS['bps_sec']['ext_cache'] = $ext;
	$label                           = $ext ? 'object cache' : 'transient';
	add_filter( 'bps_rate_limit', static fn( $max, $bucket ) => 'quotes' === $bucket ? 3 : $max, 10, 2 );
	$_SERVER['REMOTE_ADDR'] = '2001:db8::1';
	$res = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$res[] = Guard::rate_limit( 'quotes' );
	}
	ok( array( true, true, true ) === $res, "$label: 3 of 3 allowed" );
	$_SERVER['REMOTE_ADDR'] = '2001:db8::2';
	$fourth                 = Guard::rate_limit( 'quotes' );
	eq( err_code( $fourth ) . '/' . err_status( $fourth ), 'bps_rate/429', "$label: 2001:db8::2 shares the counter of ::1 → 429" );
	$_SERVER['REMOTE_ADDR'] = '2001:db8:0:1::1';
	ok( true === Guard::rate_limit( 'quotes' ), "$label: 2001:db8:0:1::1 has its own counter" );
	ok( true === Guard::rate_limit( 'designs' ), "$label: other bucket is separate" );
	$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
	Guard::rate_limit( 'quotes' );
	Guard::rate_limit( 'quotes' );
	Guard::rate_limit( 'quotes' );
	eq( err_code( Guard::rate_limit( 'quotes' ) ), 'bps_rate', "$label: IPv4 still limited per address" );
	$_SERVER['REMOTE_ADDR'] = '198.51.100.8';
	ok( true === Guard::rate_limit( 'quotes' ), "$label: neighbouring IPv4 not affected" );
	if ( $ext ) {
		ok( ! empty( $GLOBALS['bps_sec']['cache']['bps_rate'] ) && empty( $GLOBALS['bps_sec']['transients'] ), 'object cache: counters live in group bps_rate, no transients written' );
	} else {
		ok( ! empty( $GLOBALS['bps_sec']['transients'] ), 'transient: counters stored as transients' );
	}
}
fresh();
add_filter( 'bps_rate_limit', static fn() => 0 );
for ( $i = 0; $i < 50; $i++ ) {
	Guard::rate_limit( 'quotes' );
}
ok( true === Guard::rate_limit( 'quotes' ), 'limit 0 (filter) disables the rate limit' );

section( 'Guard: 429-tekst volgt de ingevulde contactkanalen (productisering-4)' );
$msg_for = static function ( array $contact, string $locale = 'nl_BE' ): string {
	fresh();
	with_config( $contact ? array( 'contact' => $contact ) : array() );
	$GLOBALS['bps_sec']['locale'] = $locale;
	add_filter( 'bps_rate_limit', static fn() => 1 );
	Guard::rate_limit( 'quotes' );
	$e = Guard::rate_limit( 'quotes' );
	return $e instanceof WP_Error ? $e->get_error_message() : '';
};
$m = $msg_for( array() );
ok( false !== stripos( $m, 'WhatsApp' ) && false !== strpos( $m, '0491 06 32 01' ), 'defaults: phone + WhatsApp mentioned with the number → ' . $m );
$m = $msg_for( array( 'whatsapp' => '' ) );
ok( false === stripos( $m, 'WhatsApp' ) && preg_match( '/\bbel\b/i', $m ), 'whatsapp empty → no "WhatsApp", still "bel"' );
$m = $msg_for( array( 'whatsapp' => '', 'phone' => '' ) );
ok( false === stripos( $m, 'WhatsApp' ) && ! preg_match( '/\bbel\b/i', $m ), 'phone + whatsapp empty → neither "bel" nor "WhatsApp"' );
ok( false !== strpos( $m, 'https://shop.example.test/offerte-aanvragen/' ), 'phone + whatsapp empty → points to the quote page (absolute URL)' );
$m = $msg_for( array( 'whatsapp' => '', 'phone' => '', 'email' => '' ) );
ok( false !== strpos( $m, 'https://shop.example.test/offerte-aanvragen/' ) && false === strpos( $m, '@' ), 'only quoteUrl → quote page, no e-mail' );
$m = $msg_for( array( 'whatsapp' => '', 'phone' => '', 'email' => '', 'quoteUrl' => '' ) );
ok( false !== strpos( $m, 'contact' ) && false === strpos( $m, 'http' ), 'nothing configured → neutral sentence without link' );
$m = $msg_for( array( 'phone' => '' ) );
ok( false !== strpos( $m, 'WhatsApp' ) && false !== strpos( $m, '+32491063201' ) && ! preg_match( '/\bbel\b/i', $m ), 'only WhatsApp → WhatsApp with number, no "bel"' );
fresh();
with_config( array( 'contact' => array( 'phone' => '' ) ) );
ok( false !== strpos( Help_Line::text( 'fr-BE' ), 'WhatsApp' ) && false !== strpos( Help_Line::text( 'fr_BE' ), "N'hésite" ), 'Help_Line FR text for fr-BE / fr_BE' );
$all = Help_Line::all();
ok( isset( $all['nl'], $all['fr'] ) && $all['nl'] !== $all['fr'], 'Help_Line::all() returns both languages' );
add_filter( 'bps_help_line', static fn( $t, $lang, $variant ) => "[$lang:$variant]", 10, 3 );
eq( Help_Line::text( 'nl' ), '[nl:wa]', 'filter bps_help_line receives language and variant' );
fresh();
with_config( array( 'contact' => array( 'quoteUrl' => 'javascript:alert(1)', 'phone' => '', 'whatsapp' => '', 'email' => '' ) ) );
ok( false === stripos( Help_Line::text( 'nl' ), 'javascript' ), 'a javascript: quoteUrl is never echoed' );

section( 'Guard: Origin/Referer-controle (security-8)' );
fresh();
$req = static function ( array $headers = array(), string $route = '/patch-studio/v1/cart' ): WP_REST_Request {
	$r = new WP_REST_Request( 'POST', $route );
	$r->set_header( 'x-bps-token', wp_create_nonce( Guard::NONCE_ACTION ) );
	foreach ( $headers as $k => $v ) {
		$r->set_header( $k, $v );
	}
	return $r;
};
ok( true === Guard::public_write( $req() ), 'valid token, no Origin/Referer → allowed (non-browser client)' );
ok( true === Guard::public_write( $req( array( 'origin' => 'https://shop.example.test' ) ) ), 'own Origin → allowed' );
ok( true === Guard::public_write( $req( array( 'origin' => 'https://SHOP.example.test' ) ) ), 'Origin host is case-insensitive' );
ok( true === Guard::public_write( $req( array( 'origin' => 'http://shop.example.test' ) ) ), 'own host over http → allowed (scheme not compared)' );
$e = Guard::public_write( $req( array( 'origin' => 'https://evil.example' ) ) );
eq( err_code( $e ) . '/' . err_status( $e ), 'bps_bad_origin/403', 'Origin https://evil.example → 403 bps_bad_origin' );
eq( err_code( Guard::public_write( $req( array( 'origin' => 'https://shop.example.test.evil.example' ) ) ) ), 'bps_bad_origin', 'suffix trick shop.example.test.evil.example refused' );
eq( err_code( Guard::public_write( $req( array( 'origin' => 'https://evilshop.example.test' ) ) ) ), 'bps_bad_origin', 'prefix trick evilshop.example.test refused' );
eq( err_code( Guard::public_write( $req( array( 'origin' => 'null' ) ) ) ), 'bps_bad_origin', 'Origin: null refused' );
eq( err_code( Guard::public_write( $req( array( 'referer' => 'https://evil.example/page' ) ) ) ), 'bps_bad_origin', 'no Origin + foreign Referer refused' );
ok( true === Guard::public_write( $req( array( 'referer' => 'https://shop.example.test/patch-studio/?x=1' ) ) ), 'no Origin + own Referer allowed' );
eq( err_code( Guard::public_write( $req( array( 'origin' => 'https://evil.example', 'referer' => 'https://shop.example.test/' ) ) ) ), 'bps_bad_origin', 'Origin wins over a matching Referer' );
$GLOBALS['bps_sec']['site'] = 'https://wp.example.test:8443';
ok( true === Guard::public_write( $req( array( 'origin' => 'https://wp.example.test:8443' ) ) ), 'site_url() host (with explicit port) allowed too' );
eq( err_code( Guard::public_write( $req( array( 'origin' => 'https://wp.example.test:9999' ) ) ) ), 'bps_bad_origin', 'same host, other explicit port refused' );
add_filter( 'bps_allowed_origins', static fn( $l ) => array_merge( $l, array( 'winkel.example.be' ) ) );
ok( true === Guard::public_write( $req( array( 'origin' => 'https://winkel.example.be' ) ) ), 'filter bps_allowed_origins adds a host' );
$bad = new WP_REST_Request( 'POST', '/patch-studio/v1/cart' );
$bad->set_header( 'origin', 'https://evil.example' );
eq( err_code( Guard::public_write( $bad ) ), 'bps_bad_token', 'token is checked first (no token → bps_bad_token)' );
$bad = new WP_REST_Request( 'POST', '/patch-studio/v1/cart' );
$bad->set_header( 'x-bps-token', 'abc' );
eq( err_code( Guard::public_write( $bad ) ), 'bps_bad_token', 'forged token → bps_bad_token' );

section( 'Guard: één verzoek telt één keer, ook als WordPress de permission_callback opnieuw aanroept' );
fresh();
add_filter( 'bps_rate_limit', static fn() => 2 );
$one = $req( array(), '/patch-studio/v1/quotes' );
ok( true === Guard::public_write( $one ) && true === Guard::public_write( $one ), 'same request checked twice (Allow header) → allowed both times' );
ok( true === Guard::public_write( $req( array(), '/patch-studio/v1/quotes' ) ), 'second real request still allowed (limit 2: the repeat did not count)' );
eq( err_code( Guard::public_write( $req( array(), '/patch-studio/v1/quotes' ) ) ), 'bps_rate', 'third real request → 429' );
$read = new WP_REST_Request( 'POST', '/patch-studio/v1/quote' );
remove_all_filters( 'bps_rate_limit' );
add_filter( 'bps_rate_limit', static fn() => 1 );
ok( true === Guard::public_read( $read ) && true === Guard::public_read( $read ), 'public_read: repeated check of one request counts once' );

section( 'Guard: ingelogde gebruiker pas herstellen na de controles (klant-account-2)' );
fresh();
$GLOBALS['bps_sec']['auth_user'] = 42;
Guard::restore_logged_in_user();
eq( get_current_user_id(), 42, 'restore_logged_in_user() picks the user from the logged_in cookie' );
fresh();
$GLOBALS['bps_sec']['auth_user'] = 42;
$r                               = new WP_REST_Request( 'POST', '/patch-studio/v1/cart' );
$r->set_header( 'x-bps-token', wp_create_nonce( Guard::NONCE_ACTION ) );
$r->set_header( 'origin', 'https://evil.example' );
Guard::public_write( $r );
eq( get_current_user_id(), 0, 'a refused (cross-origin) request never becomes the logged-in user' );

/* ======================================================================================
 * 4. Design tokens (Designs::verify_token)
 * ==================================================================================== */
section( 'Designs::verify_token: formaat, id, type, hash_equals' );
fresh();
bps_sec_post( 101, Designs::CPT, array( '_bps_created_gmt' => '2026-11-18 10:00:00' ), 'PS-2026-0001' );
bps_sec_post( 102, Designs::CPT, array( '_bps_created_gmt' => '2026-11-18 10:00:01' ), 'PS-2026-0002' );
bps_sec_post( 103, 'post', array( '_bps_created_gmt' => '2026-11-18 10:00:00' ) );
bps_sec_post( 104, Designs::CPT, array(), 'PS-2026-0004' );
$t101 = Designs::token_for( 101 );
ok( 1 === preg_match( '/^[a-f0-9]{64}$/', $t101 ), 'token is 64 lowercase hex chars' );
ok( Designs::verify_token( 101, $t101 ), 'valid token accepted' );
ok( ! Designs::verify_token( 102, $t101 ), 'token of design 101 refused for design 102' );
ok( ! Designs::verify_token( 101, strtoupper( $t101 ) ), 'uppercase variant refused (strict format)' );
ok( ! Designs::verify_token( 101, substr( $t101, 0, 63 ) ), '63 chars refused' );
ok( ! Designs::verify_token( 101, $t101 . 'a' ), '65 chars refused' );
ok( ! Designs::verify_token( 101, substr( $t101, 0, 63 ) . ( 'a' === $t101[63] ? 'b' : 'a' ) ), 'one changed char refused' );
ok( ! Designs::verify_token( 101, '' ), 'empty refused' );
ok( ! Designs::verify_token( 101, str_repeat( '0', 64 ) ), 'all zeros refused' );
ok( ! Designs::verify_token( 0, $t101 ) && ! Designs::verify_token( -101, $t101 ), 'id ≤ 0 refused' );
ok( ! Designs::verify_token( 103, hash_hmac( 'sha256', '103|2026-11-18 10:00:00', wp_salt( 'auth' ) ) ), 'other post type refused even with a valid HMAC' );
ok( ! Designs::verify_token( 104, hash_hmac( 'sha256', '104|', wp_salt( 'auth' ) ) ), 'design without created time refused' );
ok( ! Designs::verify_token( 999, $t101 ), 'deleted / unknown design refused' );
$src = (string) file_get_contents( BPS_SEC_PLUGIN . 'includes/woo/class-designs.php' );
preg_match( '/function verify_token\(.*?\n\t}/s', $src, $vt );
ok( isset( $vt[0] ) && false !== strpos( $vt[0], 'hash_equals(' ), 'verify_token() compares with hash_equals (constant time)' );
ok( isset( $vt[0] ) && ! preg_match( '/\$expected\s*[!=]==?\s*\$token|\$token\s*[!=]==?\s*\$expected|strcmp\(/', $vt[0] ), 'verify_token() has no plain ==/=== /strcmp token comparison' );
$routes_src = (string) file_get_contents( BPS_SEC_PLUGIN . 'includes/rest/class-routes.php' );
ok( 1 === preg_match( '/hash_equals\(\s*Designs::original_sig/', $routes_src ), 'signed original URL is checked with hash_equals' );

/* ======================================================================================
 * 5. Uploads::sniff and check_upload
 * ==================================================================================== */
section( 'Uploads::sniff op inhoud, nooit op extensie' );
fresh();
$dir   = tmpdir( 'sniff' );
$files = array(
	'png'      => array( "\x89PNG\r\n\x1a\n" . str_repeat( "\0", 20 ), 'png' ),
	'jpg'      => array( "\xFF\xD8\xFF\xE0" . str_repeat( "\0", 20 ), 'jpg' ),
	'webp'     => array( 'RIFF' . "\x10\0\0\0" . 'WEBPVP8 ', 'webp' ),
	'gif'      => array( 'GIF89a' . str_repeat( "\0", 10 ), 'gif' ),
	'pdf'      => array( "%PDF-1.7\n%âãÏÓ", 'pdf' ),
	'eps'      => array( "%!PS-Adobe-3.0 EPSF-3.0\n", 'eps' ),
	'psd'      => array( '8BPS' . str_repeat( "\0", 20 ), 'psd' ),
	'svg'      => array( '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>', 'svg' ),
	'svg-bom'  => array( "\xEF\xBB\xBF  <svg xmlns=\"http://www.w3.org/2000/svg\"/>", 'svg' ),
	'svg-cmt'  => array( '<!-- Generator: Illustrator --><svg xmlns="http://www.w3.org/2000/svg"/>', 'svg' ),
	'html.png' => array( '<html><script>alert(1)</script></html>', null ),
	'xml-nosvg' => array( '<?xml version="1.0"?><html/>', null ),
	'php.jpg'  => array( "<?php echo 'x';", null ),
	'text.svg' => array( 'hello world', null ),
	'empty'    => array( '', null ),
	'riff-avi' => array( 'RIFF' . "\x10\0\0\0" . 'AVI LIST', null ),
);
foreach ( $files as $name => $def ) {
	file_put_contents( "$dir/$name", $def[0] );
	eq( Uploads::sniff( "$dir/$name" ), $def[1], "sniff($name)" );
}
eq( Uploads::sniff( "$dir/does-not-exist" ), null, 'sniff(missing file) → null' );
eq( Uploads::sniff( dirname( __DIR__ ) . '/e2e/fixtures/logo-flat.png' ), 'png', 'sniff(e2e fixture logo-flat.png) → png' );

section( 'Uploads::check_upload voor elke UPLOAD_ERR_*-code' );
$ok_file = "$dir/png";
$codes   = array(
	'UPLOAD_ERR_INI_SIZE'   => array( UPLOAD_ERR_INI_SIZE, 'bps_too_big', 413 ),
	'UPLOAD_ERR_FORM_SIZE'  => array( UPLOAD_ERR_FORM_SIZE, 'bps_too_big', 413 ),
	'UPLOAD_ERR_PARTIAL'    => array( UPLOAD_ERR_PARTIAL, 'bps_upload', 400 ),
	'UPLOAD_ERR_NO_FILE'    => array( UPLOAD_ERR_NO_FILE, 'bps_upload', 400 ),
	'UPLOAD_ERR_NO_TMP_DIR' => array( UPLOAD_ERR_NO_TMP_DIR, 'bps_upload', 400 ),
	'UPLOAD_ERR_CANT_WRITE' => array( UPLOAD_ERR_CANT_WRITE, 'bps_upload', 400 ),
	'UPLOAD_ERR_EXTENSION'  => array( UPLOAD_ERR_EXTENSION, 'bps_upload', 400 ),
);
foreach ( $codes as $label => $def ) {
	$res = call_private( Uploads::class, 'check_upload', array( 'error' => $def[0], 'tmp_name' => $ok_file, 'size' => 10 ) );
	eq( err_code( $res ) . '/' . err_status( $res ), $def[1] . '/' . $def[2], "check_upload($label)" );
}
ok( true === call_private( Uploads::class, 'check_upload', array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $ok_file, 'size' => 10 ) ), 'UPLOAD_ERR_OK + existing file (BPS_TESTING) → ok' );
eq( err_code( call_private( Uploads::class, 'check_upload', array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => "$dir/nope", 'size' => 10 ) ) ), 'bps_upload', 'UPLOAD_ERR_OK + missing tmp file → bps_upload' );
eq( err_code( call_private( Uploads::class, 'check_upload', array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $dir, 'size' => 10 ) ) ), 'bps_upload', 'UPLOAD_ERR_OK + directory as tmp file → bps_upload' );
eq( err_code( call_private( Uploads::class, 'check_upload', array() ) ), 'bps_upload', 'no error key → treated as UPLOAD_ERR_NO_FILE' );
$child_out = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child=no-testing 2>&1' );
ok( false !== strpos( (string) $child_out, 'CHILD bps_upload 400' ), 'without BPS_TESTING a file that was not uploaded over HTTP is refused → ' . trim( (string) $child_out ) );
rrmdir( $dir );

/* ======================================================================================
 * 6. Payload validation
 * ==================================================================================== */
section( 'Payload::validate randgevallen' );
fresh();
$base = array(
	'technique' => 'embroidered',
	'shape'     => 'circle',
	'sizeCm'    => 8,
	'qty'       => 25,
	'locale'    => 'nl-BE',
);
$p = Payload::validate( $base );
ok( is_array( $p ) && 'embroidered' === $p['technique'] && 25 === $p['qty'], 'minimal valid payload accepted' );
ok( is_array( Payload::validate( wp_json_encode( $base ) ) ), 'JSON string accepted' );
$err = static function ( $raw ): string {
	$r = Payload::validate( $raw );
	return $r instanceof WP_Error ? $r->get_error_code() . '/' . ( $r->get_error_data()['status'] ?? 0 ) : 'accepted';
};
eq( $err( null ), 'bps_invalid_design/400', 'null refused' );
eq( $err( '{not json' ), 'bps_invalid_design/400', 'invalid JSON refused' );
eq( $err( '"just a string"' ), 'bps_invalid_design/400', 'JSON scalar refused' );
eq( $err( str_repeat( ' ', 200001 ) ), 'bps_invalid_design/400', 'string > 200 000 bytes refused before decoding' );
ok( false !== strpos( Payload::validate( str_repeat( ' ', 200001 ) )->get_error_message(), 'te veel' ), 'oversized payload says "te veel gegevens"' );
$deep = '{"technique":"embroidered","shape":"circle","sizeCm":8,"qty":1,"x":' . str_repeat( '[', 40 ) . str_repeat( ']', 40 ) . '}';
eq( $err( $deep ), 'bps_invalid_design/400', 'nesting deeper than 16 levels refused' );
eq( $err( array_diff_key( $base, array( 'technique' => 1 ) ) ), 'bps_invalid_design/400', 'missing technique refused' );
$unknown = Payload::validate( array_merge( $base, array( 'technique' => 'laser-hologram' ) ) );
ok( $unknown instanceof WP_Error && false !== strpos( $unknown->get_error_message(), 'techniek' ), 'unknown technique refused with a technique message' );
eq( $err( array_merge( $base, array( 'technique' => array( 'embroidered' ) ) ) ), 'bps_invalid_design/400', 'technique as array refused' );
eq( $err( array_merge( $base, array( 'shape' => 'pentagram' ) ) ), 'bps_invalid_design/400', 'unknown shape refused' );
eq( $err( array_diff_key( $base, array( 'sizeCm' => 1 ) ) ), 'bps_invalid_design/400', 'missing size refused' );
eq( $err( array_merge( $base, array( 'sizeCm' => 0 ) ) ), 'bps_invalid_design/400', 'size 0 refused' );
eq( $err( array_merge( $base, array( 'sizeCm' => 1000 ) ) ), 'bps_invalid_design/400', 'size 1000 cm refused' );
eq( $err( array_merge( $base, array( 'sizeCm' => 'NaN' ) ) ), 'bps_invalid_design/400', 'size NaN refused' );
eq( $err( array_merge( $base, array( 'sizeCm' => true ) ) ), 'bps_invalid_design/400', 'size true refused' );
eq( Payload::validate( array_merge( $base, array( 'sizeCm' => 7.3 ) ) )['sizeCm'], 7.5, 'size rounded to 0.5 cm' );
eq( $err( array_merge( $base, array( 'qty' => 0 ) ) ), 'bps_invalid_design/400', 'qty 0 refused' );
eq( $err( array_merge( $base, array( 'qty' => -5 ) ) ), 'bps_invalid_design/400', 'negative qty refused' );
eq( $err( array_merge( $base, array( 'qty' => 2.5 ) ) ), 'bps_invalid_design/400', 'fractional qty refused' );
eq( $err( array_merge( $base, array( 'qty' => 1e9 ) ) ), 'bps_invalid_design/400', 'qty 1e9 refused' );
eq( Payload::validate( array_merge( $base, array( 'qty' => '10' ) ) )['qty'], 10, 'qty "10" accepted as 10' );
eq( $err( array_merge( $base, array( 'options' => array( 'gold-plating' ) ) ) ), 'bps_invalid_design/400', 'unknown option refused' );
eq( Payload::validate( array_merge( $base, array( 'options' => array( 'merrow', 'merrow' ) ) ) )['options'], array( 'merrow' ), 'duplicate options collapsed' );
$p = Payload::validate(
	array_merge(
		$base,
		array(
			'texts'       => array(
				array(
					'text'     => '<script>alert(1)</script>' . str_repeat( 'A', 200 ),
					'font'     => 'Comic Sans',
					'offsetMm' => array( 'x' => 99999, 'y' => -99999 ),
					'angleDeg' => 720,
				),
				'not-an-object',
				array( 'text' => '' ),
			),
			'art'         => array( 'rotation' => 45, 'scale' => 1000 ),
			'fabric'      => array( 'id' => '"><img>', 'hex' => '#ABC' ),
			'edgeColor'   => array( 'code' => 'Ma<b>deira 1000!', 'hex' => 'red' ),
			'notes'       => str_repeat( 'n', 5000 ),
			'priceClient' => 'free',
			'analysis'    => 'nope',
			'evilKey'     => 'dropped',
		)
	)
);
ok( is_array( $p ), 'payload with hostile sub-structures still validates (cleaned)' );
eq( count( $p['texts'] ), 1, 'non-object and empty text layers dropped' );
ok( false === strpos( $p['texts'][0]['text'], '<' ) && mb_strlen( $p['texts'][0]['text'] ) <= 60, 'text layer: tags stripped, cut to text.maxChars' );
eq( $p['texts'][0]['font'], 'Sora', 'unknown font → first configured font' );
eq( $p['texts'][0]['offsetMm'], array( 'x' => 150.0, 'y' => -150.0 ), 'text offset clamped to ±150 mm' );
eq( $p['texts'][0]['angleDeg'], 180, 'angle clamped to 180' );
eq( $p['art']['rotation'], 0, 'art rotation 45 → 0 (only quarter turns)' );
eq( $p['art']['scale'], 5.0, 'art scale clamped to 5' );
eq( $p['fabric']['id'], 'auto', 'unknown fabric id → auto' );
eq( $p['fabric']['hex'], '#aabbcc', 'short hex normalised' );
eq( $p['edgeColor']['code'], 'Mabdeira1000', 'thread code keeps only [A-Za-z0-9_-]' );
eq( $p['edgeColor']['hex'], '', 'invalid hex emptied' );
eq( mb_strlen( $p['notes'] ), 2000, 'notes cut to 2000 chars' );
eq( $p['priceClient'], array( 'totalIncl' => 0.0, 'unitExcl' => 0.0 ), 'priceClient as string → zeros' );
eq( $p['analysis'], null, 'analysis as string → null' );
ok( ! array_key_exists( 'evilKey', $p ), 'unknown top-level keys dropped' );
add_filter( 'bps_payload_validate', static fn( $e, $payload ) => $payload['qty'] > 20 ? new WP_Error( 'bps_names', 'te veel namen', array( 'status' => 400 ) ) : $e, 10, 2 );
eq( $err( $base ), 'bps_names/400', 'filter bps_payload_validate can refuse a payload (C4)' );
eq( $err( array_merge( $base, array( 'qty' => 5 ) ) ), 'accepted', 'filter bps_payload_validate returning null accepts' );
add_filter( 'bps_payload_clean', static fn( $out, $raw ) => $out + array( 'names' => array_slice( (array) ( $raw['names'] ?? array() ), 0, 2 ) ), 10, 2 );
eq( Payload::validate( array_merge( $base, array( 'qty' => 5, 'names' => array( 'An', 'Bo', 'Cy' ) ) ) )['names'], array( 'An', 'Bo' ), 'filter bps_payload_clean can add validated module data' );

/* ======================================================================================
 * 7. REST error paths (Routes) without WordPress
 * ==================================================================================== */
section( 'Routes: foutpaden van POST /designs en GET /designs/{id}' );
fresh();
$up = $GLOBALS['bps_sec']['upload_dir'];
rrmdir( $up );
mkdir( $up, 0777, true );
$design_json = wp_json_encode( $base );
$post_max    = (int) wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
if ( $post_max > 0 ) {
	$_SERVER['CONTENT_LENGTH'] = (string) ( $post_max + 1 );
	$r                         = new WP_REST_Request( 'POST', '/patch-studio/v1/designs' );
	$res                       = Routes::create_design( $r );
	eq( err_code( $res ) . '/' . err_status( $res ), 'bps_too_big/413', 'body > post_max_size → 413 bps_too_big (not a design error)' );
	ok( false !== strpos( $res->get_error_message(), 'groter dan 25 MB' ), 'too-big message names the limit: ' . $res->get_error_message() );
	unset( $_SERVER['CONTENT_LENGTH'] );
} else {
	ok( true, 'post_max_size unlimited in this CLI: body check skipped' );
	ok( true, 'post_max_size unlimited in this CLI: message check skipped' );
}
$r = new WP_REST_Request( 'POST', '/patch-studio/v1/designs', array( 'design' => $design_json ) );
$r->set_file_params( array( 'preview' => array( 'name' => 'p.png', 'tmp_name' => '/nonexistent', 'size' => 9 * MB_IN_BYTES, 'error' => UPLOAD_ERR_OK ) ) );
$res = Routes::create_design( $r );
eq( err_code( $res ) . '/' . err_status( $res ), 'bps_too_big/413', 'preview > 8 MB → 413' );
$r->set_file_params( array( 'preview' => array( 'name' => 'p.png', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE ) ) );
eq( err_code( Routes::create_design( $r ) ), 'bps_too_big', 'preview UPLOAD_ERR_INI_SIZE → 413' );
$r->set_file_params( array() );
eq( err_code( Routes::create_design( $r ) ), 'bps_invalid_design', 'missing preview → bps_invalid_design' );
$orig = tempnam( sys_get_temp_dir(), 'bpso' );
file_put_contents( $orig, str_repeat( 'x', 1000 ) );
$r->set_file_params(
	array(
		'preview'  => array( 'name' => 'p.png', 'tmp_name' => $orig, 'size' => 1000, 'error' => UPLOAD_ERR_OK ),
		'original' => array( 'name' => 'logo.pdf', 'tmp_name' => $orig, 'size' => 1000, 'error' => UPLOAD_ERR_OK ),
	)
);
add_filter( 'bps_min_free_disk_bytes', static fn() => PHP_INT_MAX );
$before = count_files( $up );
$res    = Routes::create_design( $r );
if ( is_callable( array( Uploads::class, 'check_storage' ) ) ) {
	// P06's quota lives in Uploads::check_storage(); it must still map to 507.
	ok( in_array( err_code( $res ), array( 'bps_storage', 'bps_type' ), true ), 'storage check via Uploads::check_storage → ' . err_code( $res ) );
} else {
	eq( err_code( $res ) . '/' . err_status( $res ), 'bps_storage/507', 'simulated low disk space (filter) → 507 bps_storage' );
	ok( false !== strpos( $res->get_error_message(), 'e-mail' ), '507 text offers the e-mail alternative' );
}
eq( count_files( $up ), $before, 'no file added in uploads after a refused original' );
remove_all_filters( 'bps_min_free_disk_bytes' );
$r->set_file_params( array( 'original' => array( 'name' => 'big.pdf', 'tmp_name' => $orig, 'size' => 26 * MB_IN_BYTES, 'error' => UPLOAD_ERR_OK ), 'preview' => array( 'name' => 'p.png', 'tmp_name' => $orig, 'size' => 1000, 'error' => UPLOAD_ERR_OK ) ) );
eq( err_code( Routes::create_design( $r ) ), 'bps_too_big', 'original > 25 MB → 413 before storing' );
unlink( $orig );
$r = new WP_REST_Request( 'POST', '/patch-studio/v1/designs', array( 'website' => 'http://spam.example', 'design' => $design_json ) );
eq( err_code( Routes::create_design( $r ) ), 'bps_invalid_design', 'honeypot filled → refused' );
rrmdir( $up );

fresh();
bps_sec_post( 201, Designs::CPT, array( '_bps_created_gmt' => '2026-11-18 11:00:00', '_bps_payload' => wp_json_encode( $base ), '_bps_status' => 'draft' ), 'PS-2026-0201' );
bps_sec_post( 202, Designs::CPT, array( '_bps_created_gmt' => '2026-11-18 11:00:05' ), 'PS-2026-0202' );
$get = static function ( int $id, ?string $header, ?string $query ) {
	$r = new WP_REST_Request( 'GET', '/patch-studio/v1/designs/' . $id, array( 'id' => $id ) );
	if ( null !== $header ) {
		$r->set_header( 'X-BPS-Design-Token', $header );
	}
	if ( null !== $query ) {
		$r->set_param( 'token', $query );
	}
	return Routes::get_design( $r );
};
$res = $get( 201, Designs::token_for( 201 ), null );
ok( $res instanceof WP_REST_Response && 200 === $res->status && 'PS-2026-0201' === $res->data['ref'], 'GET /designs/{id} with header X-BPS-Design-Token → 200' );
$res = $get( 201, null, Designs::token_for( 201 ) );
ok( $res instanceof WP_REST_Response && 200 === $res->status, 'GET /designs/{id}?token= still works' );
eq( err_code( $get( 201, str_repeat( 'a', 64 ), null ) ) . '/' . err_status( $get( 201, str_repeat( 'a', 64 ), null ) ), 'bps_forbidden/403', 'wrong header token → 403 bps_forbidden' );
eq( err_code( $get( 201, Designs::token_for( 202 ), null ) ), 'bps_forbidden', 'token of another design → 403' );
eq( err_code( $get( 201, null, null ) ), 'bps_forbidden', 'no token at all → 403' );
eq( err_code( $get( 201, 'garbage', Designs::token_for( 201 ) ) ), 'bps_forbidden', 'a (wrong) header token wins over ?token=' );

/* ======================================================================================
 * Summary
 * ==================================================================================== */
$total = $GLOBALS['count'];
$fails = $GLOBALS['fails'];
echo ( $total - $fails ) . "/$total ok\n";
exit( $fails ? 1 : 0 );
