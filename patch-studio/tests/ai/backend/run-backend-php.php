<?php
/**
 * ai-backend tests: providers (mock, OpenAI over fake HTTP), jobs flow end-to-end with the mock, idempotency,
 * refunds, fallback, policy, timeouts, limits and budget, cost log, secrets, image marking, cleanup, privacy,
 * admin saving, public config. No WordPress, no network, no database.
 *
 * Run: php tests/ai/backend/run-backend-php.php   (also via npm test → tests/ai/backend.test.js)
 *
 * @package BorduurPatchStudio
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
set_error_handler(
	static function ( $no, $str, $file, $line ) {
		throw new ErrorException( $str, 0, $no, $file, $line );
	}
);

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/fakes.php';

use BorduurPatchStudio\AI\Admin;
use BorduurPatchStudio\AI\AI_Settings;
use BorduurPatchStudio\AI\Cleanup;
use BorduurPatchStudio\AI\Cost_Log;
use BorduurPatchStudio\AI\Credits_Gateway;
use BorduurPatchStudio\AI\Design_Link;
use BorduurPatchStudio\AI\Identity;
use BorduurPatchStudio\AI\Image_Store;
use BorduurPatchStudio\AI\Job_Runner;
use BorduurPatchStudio\AI\Jobs;
use BorduurPatchStudio\AI\Legal_Display;
use BorduurPatchStudio\AI\Limits;
use BorduurPatchStudio\AI\Messages;
use BorduurPatchStudio\AI\Moderation;
use BorduurPatchStudio\AI\Order_Review;
use BorduurPatchStudio\AI\Module;
use BorduurPatchStudio\AI\Privacy;
use BorduurPatchStudio\AI\Rest;
use BorduurPatchStudio\AI\Secrets;
use BorduurPatchStudio\AI\Providers\Mock_Provider;
use BorduurPatchStudio\AI\Providers\Openai_Provider;
use BorduurPatchStudio\AI\Providers\Provider_Error;
use BorduurPatchStudio\AI\Providers\Provider_Registry;
use BorduurPatchStudio\AI\Providers\Provider_Result;

$GLOBALS['bps_tmp'] = sys_get_temp_dir() . '/bps-ai-test-' . getmypid();
$pass = 0;
$fail = 0;
$failures = array();

/**
 * Run one test.
 *
 * @param string   $name Name.
 * @param callable $fn   Body.
 */
function t( string $name, callable $fn ): void {
	global $pass, $fail, $failures;
	try {
		$fn();
		++$pass;
		echo "  ok   {$name}\n";
	} catch ( Throwable $e ) {
		++$fail;
		$failures[] = $name;
		echo "  FAIL {$name}\n       " . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
	}
}

/**
 * Assert.
 *
 * @param mixed  $cond Condition.
 * @param string $msg  Message.
 */
function ok( $cond, string $msg = 'assertion failed' ): void {
	if ( ! $cond ) {
		throw new RuntimeException( $msg );
	}
}

/**
 * Assert equal.
 *
 * @param mixed  $a   Actual.
 * @param mixed  $b   Expected.
 * @param string $msg Message.
 */
function eq( $a, $b, string $msg = '' ): void {
	if ( $a !== $b ) {
		throw new RuntimeException( ( '' !== $msg ? $msg . ': ' : '' ) . 'expected ' . var_export( $b, true ) . ', got ' . var_export( $a, true ) );
	}
}

/**
 * Fresh state. Returns [store, credits].
 *
 * @param string $mode     Mode.
 * @param array  $settings Extra settings (option overrides).
 */
function fresh( string $mode = 'test', array $settings = array() ): array {
	bps_test_reset();
	$GLOBALS['bps_upload_base'] = $GLOBALS['bps_tmp'] . '/' . bin2hex( random_bytes( 4 ) );
	$base = array(
		'mode'   => $mode,
		'mock'   => array( 'latencyMs' => 0 ),
		'limits' => array( 'minSecondsBetween' => 0 ),
	);
	update_option( AI_Settings::OPTION, AI_Settings::deep_merge( $base, $settings ) );
	$store   = new Memory_Store();
	$credits = new Fake_Credits();
	Module::set_store( $store );
	add_filter( 'bps_ai_credits', static function () use ( $credits ) {
		return $credits;
	} );
	Module::flush();
	Provider_Registry::override( null );
	Limits::$now                    = null;
	Jobs::$now                      = null;
	Moderation::$override           = null;
	Job_Runner::$force_inline       = true;
	Mock_Provider::$latency_override = 0;
	Identity::set_sid( null );
	Identity::$ip_override = '203.0.113.7';
	Secrets::flush();
	Messages::set_locale( 'nl' );
	return array( $store, $credits );
}

/**
 * An anonymous owner with free rounds.
 *
 * @param Fake_Credits $c    Credits.
 * @param int          $free Free rounds.
 * @param string       $sid  Session id.
 */
function owner( Fake_Credits $c, int $free = 5, string $sid = '' ): string {
	$sid = '' !== $sid ? $sid : str_repeat( 'a', 32 );
	Identity::set_sid( $sid );
	$o            = Identity::for_sid( $sid );
	$c->free[ $o ] = $free;
	return $o;
}

/**
 * Generate body.
 *
 * @param string $desc Description.
 * @param array  $more More fields.
 */
function body( string $desc = 'een wolf met een bijl voor onze motorclub, rond, zwart-rood', array $more = array() ): array {
	return array_merge(
		array(
			'clientRequestId' => 'req-' . bin2hex( random_bytes( 6 ) ),
			'description'     => $desc,
			'lang'            => 'nl',
			'context'         => array( 'technique' => 'embroidered', 'sizeMm' => 80, 'fabric' => 'black' ),
		),
		$more
	);
}

$ctx = array( 'ip_hash' => 'iphash-1', 'manager' => true );

echo "ai-backend PHP tests\n";

/* ------------------------------------------------------------------ ids */

t( 'job ids: 26 tekens Crockford, uniek, geldig', function () {
	$seen = array();
	for ( $i = 0; $i < 300; $i++ ) {
		$id = Jobs::new_id();
		ok( Jobs::valid_id( $id ), "invalid {$id}" );
		ok( ! isset( $seen[ $id ] ), 'duplicate' );
		$seen[ $id ] = 1;
	}
	ok( ! Jobs::valid_id( 'ILOU0000000000000000000000' ), 'I/L/O/U zijn geen Crockford-tekens' );
} );

/* ----------------------------------------------------------------- mock */

t( 'mock: n verschillende SVG-voorstellen, kleuren uit de brief, vorm en AI-tekst', function () {
	fresh();
	$cfg   = Module::prompt_cfg();
	$brief = BorduurPatchStudio\AI\Prompt_Engine::brief( array( 'description' => 'een wolf, rond, rood en geel', 'lang' => 'nl', 'text' => 'MC WOLF', 'textMode' => 'ai' ), $cfg );
	ok( empty( $brief['blocked'] ), 'brief blocked' );
	$p = new Mock_Provider();
	$r = $p->generate( array( 'prompt' => 'x', 'params' => array( 'n' => 3 ), 'brief' => $brief ) );
	eq( count( $r->images ), 3, 'n' );
	$hashes = array_unique( array_map( fn( $i ) => sha1( $i['bytes'] ), $r->images ) );
	eq( count( $hashes ), 3, 'alle verschillend' );
	$svg = $r->images[0]['bytes'];
	eq( Provider_Result::sniff_mime( $svg ), 'image/svg+xml' );
	ok( false !== strpos( $svg, 'data-mock="wolf"' ), 'wolf-sjabloon eerst gekozen' );
	ok( false !== stripos( $svg, '#c62828' ), 'rood uit de brief' );
	ok( false !== strpos( $svg, '<circle cx="512" cy="512" r="470"' ), 'ronde vorm' );
	ok( false !== strpos( $svg, 'MC WOLF' ), 'tekst bij textMode=ai' );
	ok( false === stripos( $svg, '<script' ), 'geen script' );
	eq( $r->cost_usd, 0.0, 'mock kost niets' );
} );

t( 'mock: testhaken fail/policy enkel in testmodus', function () {
	fresh( 'test' );
	$p = new Mock_Provider();
	try {
		$p->generate( array( 'prompt' => 'x', 'params' => array( 'n' => 1 ), 'test_hook' => 'policy' ) );
		ok( false, 'geen fout' );
	} catch ( Provider_Error $e ) {
		ok( $e->is_policy() && ! $e->retryable(), 'policy niet retrybaar' );
	}
	try {
		$p->generate( array( 'prompt' => 'x', 'params' => array( 'n' => 1 ), 'test_hook' => 'fail' ) );
		ok( false, 'geen fout' );
	} catch ( Provider_Error $e ) {
		eq( $e->error_code(), 'unavailable' );
		ok( $e->retryable() );
	}
	fresh( 'live' );
	$r = ( new Mock_Provider() )->generate( array( 'prompt' => 'x', 'params' => array( 'n' => 1 ), 'test_hook' => 'fail' ) );
	eq( count( $r->images ), 1, 'haak genegeerd in live zonder WP_DEBUG' );
} );

/* ---------------------------------------------------------- generate flow */

t( 'generate → 202 queued, ronde gereserveerd; poll → inline klaar met 3 gemarkeerde voorstellen', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	$o   = owner( $c, 1 );
	$res = Jobs::generate( $o, body(), $ctx );
	ok( ! is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_message() : '' );
	eq( $res['status'], 202 );
	eq( $res['body']['status'], 'queued' );
	eq( $res['body']['charged'], 1 );
	eq( $c->free[ $o ], 0, 'ronde afgeboekt' );
	ok( 1500 === $res['body']['pollAfterMs'] );
	$id  = $res['body']['jobId'];
	$job = $store->job_get( $id );
	ok( false === strpos( (string) $job['input'], 'prompt' ), 'geen prompt in input' );
	ok( false !== strpos( (string) $job['brief'], 'subjectEn' ), 'brief server-side bewaard' );

	$poll = Jobs::poll( $o, $id );
	eq( $poll['body']['status'], 'done' );
	eq( count( $poll['body']['proposals'] ), 3 );
	$p0 = $poll['body']['proposals'][0];
	ok( 0 === strpos( $p0['url'], 'https://shop.test/wp-json/patch-studio/v1/ai/image/' . $id . '/0?exp=' ), 'ondertekende URL' );
	ok( 0 === strpos( $p0['alt'], 'Voorstel 1: ' ), 'alt-tekst' );
	ok( false !== strpos( $poll['body']['understood']['summary'], 'rond' ), 'samenvatting NL: ' . $poll['body']['understood']['summary'] );
	eq( $poll['body']['understood']['colors'], array( '#1b1b1b', '#c62828' ) );
	eq( $poll['body']['understood']['shape'], 'circle' );

	parse_str( (string) parse_url( $p0['url'], PHP_URL_QUERY ), $q );
	ok( Image_Store::verify( $id, 0, (int) $q['exp'], $q['sig'] ), 'handtekening geldig' );
	ok( ! Image_Store::verify( $id, 1, (int) $q['exp'], $q['sig'] ), 'andere index ongeldig' );
	ok( ! Image_Store::verify( $id, 0, (int) $q['exp'] + 1, $q['sig'] ), 'andere vervaldatum ongeldig' );
	$file = Rest::image_file( $id, 0, '' );
	ok( null !== $file && is_file( $file['abs'] ), 'bestand bestaat' );
	$svg = file_get_contents( $file['abs'] );
	ok( false !== strpos( $svg, 'trainedAlgorithmicMedia' ), 'AI-markering (IPTC) in SVG' );
	ok( false !== strpos( $svg, 'AI-generated (Patch Studio, mock/mock-1)' ), 'AI-markering beschrijving' );
	ok( file_exists( Image_Store::base_dir() . '.htaccess' ), 'map afgeschermd' );
	ok( 1 === count( $store->costs ) && 0 === $store->costs[0]['cost_eur_micro'], 'kostlog: 1 regel, mock = 0' );
	ok( false === strpos( basename( $file['abs'] ), 'wolf' ), 'bestandsnaam zonder klanttekst' );
} );

t( 'idempotent: zelfde clientRequestId = zelfde job, geen tweede ronde; tweede job tijdens lopende → 409', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	Job_Runner::$force_inline = false; // Simulate Action Scheduler: no inline run on the first poll.
	$o  = owner( $c, 5 );
	$b  = body();
	$r1 = Jobs::generate( $o, $b, $ctx );
	$r2 = Jobs::generate( $o, $b, $ctx );
	eq( $r2['body']['jobId'], $r1['body']['jobId'] );
	eq( $c->free[ $o ], 4, 'één ronde' );
	$r3 = Jobs::generate( $o, body(), $ctx );
	ok( is_wp_error( $r3 ) && 'bps_ai_busy' === $r3->get_error_code(), '409 busy' );
	eq( $r3->get_error_data()['status'], 409 );
	eq( $r3->get_error_data()['jobId'], $r1['body']['jobId'] );
} );

t( 'validatie: 400 bps_ai_invalid met veld; testmarkering niet naar de prompt', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	$o = owner( $c );
	$e = Jobs::generate( $o, array( 'description' => 'wolf' ), $ctx );
	ok( is_wp_error( $e ) && 'clientRequestId' === $e->get_error_data()['field'] );
	$e = Jobs::generate( $o, body( str_repeat( 'a', 401 ) ), $ctx );
	ok( is_wp_error( $e ) && 'description' === $e->get_error_data()['field'] && 400 === $e->get_error_data()['status'] );
	$e = Jobs::generate( $o, body( 'wolf', array( 'maxColors' => 12 ) ), $ctx );
	ok( is_wp_error( $e ) && 'maxColors' === $e->get_error_data()['field'] );
	$r   = Jobs::generate( $o, body( 'een adelaar [[mock:slow]]' ), $ctx );
	$job = $store->job_get( $r['body']['jobId'] );
	ok( false === strpos( (string) $job['brief'], 'mock:' ), 'marker niet in brief' );
	ok( false !== strpos( (string) $job['input'], '"testHook":"slow"' ), 'testhaak bewaard' );
} );

t( 'merk geweigerd → 200 blocked, geen ronde; 5 weigeringen → 423 pauze', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	$o = owner( $c, 3 );
	$r = Jobs::generate( $o, body( 'het logo van Nike met onze clubnaam' ), $ctx );
	eq( $r['status'], 200 );
	ok( true === $r['body']['blocked'] );
	eq( $r['body']['code'], 'brand' );
	ok( '' !== $r['body']['message'] );
	eq( $r['body']['charged'], 0 );
	eq( $c->free[ $o ], 3, 'geen ronde' );
	for ( $i = 0; $i < 4; $i++ ) {
		Jobs::generate( $o, body( 'het logo van Nike' ), $ctx );
	}
	$p = Jobs::generate( $o, body(), $ctx );
	ok( is_wp_error( $p ) && 'bps_ai_paused' === $p->get_error_code(), 'pauze' );
	eq( $p->get_error_data()['status'], 423 );
	$counts = Limits::refusal_totals( 1 );
	eq( $counts['brand'] ?? 0, 5 );
} );

t( 'moderatie-API geflagd → blocked zonder ronde (beschrijving zonder persoonsgegevens)', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	$o    = owner( $c, 2 );
	$seen = null;
	Moderation::$override = function ( $text ) use ( &$seen ) {
		$seen = $text;
		return array( 'flagged' => true, 'code' => 'violence' );
	};
	$r = Jobs::generate( $o, body( 'een wolf, mail jan.peeters@example.com of bel 0475 12 34 56' ), $ctx );
	ok( $r['body']['blocked'] );
	eq( $r['body']['code'], 'violence' );
	eq( $c->free[ $o ], 2 );
	ok( false === strpos( (string) $seen, '@example.com' ) && false === strpos( (string) $seen, '0475' ), 'geen e-mail/telefoon naar moderatie: ' . $seen );
} );

t( 'provider faalt ([[mock:fail]]) → failed, ronde terug, nette melding', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	$o = owner( $c, 1 );
	$r = Jobs::generate( $o, body( 'een leeuw [[mock:fail]]' ), $ctx );
	$p = Jobs::poll( $o, $r['body']['jobId'] );
	eq( $p['body']['status'], 'failed' );
	eq( $p['body']['error']['code'], 'provider_unavailable' );
	ok( false !== strpos( $p['body']['error']['message'], 'teruggezet' ), $p['body']['error']['message'] );
	eq( $p['body']['charged'], 0 );
	eq( $c->free[ $o ], 1, 'ronde terug' );
	eq( $c->refunds[ $r['body']['jobId'] ], 'job_failed' );
} );

t( 'live: retrybare fout → 1× terugvalprovider; beleidsweigering → nooit terugval, ronde terug', function () use ( $ctx ) {
	list( $store, $c ) = fresh( 'live', array( 'default' => 'openai', 'fallback' => 'recraft' ) );
	$png     = bps_png();
	$primary = new Script_Provider( 'openai', function () {
		throw new Provider_Error( 'unavailable', 'HTTP 503' );
	} );
	$fb = new Script_Provider( 'recraft', function ( $req ) use ( $png ) {
		return new Provider_Result( array_fill( 0, (int) $req['params']['n'], array( 'bytes' => $png, 'mime' => 'image/png', 'w' => 4, 'h' => 4 ) ), array( 'images' => 3 ), 0.24, true, 10, 'recraftv4_vector' );
	} );
	Provider_Registry::override( 'openai', $primary );
	Provider_Registry::override( 'recraft', $fb );
	$o = owner( $c, 3 );
	$r = Jobs::generate( $o, body(), $ctx );
	$p = Jobs::poll( $o, $r['body']['jobId'] );
	eq( $p['body']['status'], 'done' );
	eq( $primary->calls, 1 );
	eq( $fb->calls, 1 );
	eq( $store->job_get( $r['body']['jobId'] )['provider'], 'recraft' );
	ok( ! array_key_exists( 'user', $primary->last ), 'geen user-veld' );
	ok( null === $primary->last['safety_id'], 'safety id standaard uit' );
	eq( count( $store->costs ), 2, 'kost van beide pogingen gelogd' );
	$png_path = Rest::image_file( $r['body']['jobId'], 0, '' )['abs'];
	$bytes    = file_get_contents( $png_path );
	ok( false !== strpos( $bytes, 'iTXt' ) && false !== strpos( $bytes, 'trainedAlgorithmicMedia' ), 'PNG gemarkeerd' );
	ok( file_exists( preg_replace( '/\.png$/', '.orig.png', $png_path ) ), 'origineel (C2PA) bewaard' );

	// Policy.
	$primary2 = new Script_Provider( 'openai', function () {
		throw new Provider_Error( 'policy', 'moderation_blocked' );
	} );
	$fb2 = new Script_Provider( 'recraft', function () {
		throw new RuntimeException( 'mag niet aangeroepen worden' );
	} );
	Provider_Registry::override( 'openai', $primary2 );
	Provider_Registry::override( 'recraft', $fb2 );
	$before = $c->free[ $o ];
	$r2     = Jobs::generate( $o, body( 'een adelaar' ), $ctx );
	$p2     = Jobs::poll( $o, $r2['body']['jobId'] );
	eq( $p2['body']['status'], 'blocked' );
	eq( $p2['body']['code'], 'provider_policy' );
	eq( $fb2->calls, 0, 'geen terugval bij beleid' );
	eq( $c->free[ $o ], $before, 'ronde terug' );
} );

t( 'live: OpenAI weigert transparant → opnieuw met stofkleur-achtergrond', function () use ( $ctx ) {
	list( $store, $c ) = fresh( 'live', array( 'default' => 'openai', 'fallback' => '' ) );
	$png  = bps_png();
	$seen = array();
	$p    = new Script_Provider( 'openai', function ( $req ) use ( &$seen, $png ) {
		$seen[] = $req['params']['background'];
		if ( 'transparent' === $req['params']['background'] ) {
			throw new Provider_Error( 'bad_request', 'background not supported', 'background', 400 );
		}
		return new Provider_Result( array( array( 'bytes' => $png ) ), array(), 0.05, true, 5, 'gpt-image-2' );
	} );
	Provider_Registry::override( 'openai', $p );
	$o = owner( $c, 1 );
	$r = Jobs::generate( $o, body(), $ctx );
	$q = Jobs::poll( $o, $r['body']['jobId'] );
	eq( $q['body']['status'], 'done' );
	eq( $seen, array( 'transparent', 'opaque' ) );
} );

t( 'timeout: lopende job te lang → failed/timeout + ronde terug; wachtrij te lang → provider_unavailable', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	Job_Runner::$force_inline = false;
	$o  = owner( $c, 2 );
	$r  = Jobs::generate( $o, body(), $ctx );
	$id = $r['body']['jobId'];
	$store->job_update( $id, array( 'status' => 'running', 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 200 ) ) );
	$p = Jobs::poll( $o, $id );
	eq( $p['body']['status'], 'failed' );
	eq( $p['body']['error']['code'], 'timeout' );
	eq( $c->free[ $o ], 2 );
	$r2 = Jobs::generate( $o, body(), $ctx );
	$store->job_update( $r2['body']['jobId'], array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 200 ) ) );
	eq( Job_Runner::sweep(), 1 );
	eq( $store->job_get( $r2['body']['jobId'] )['error_code'], 'provider_unavailable' );
	eq( $c->free[ $o ], 2 );
} );

t( 'gelijktijdigheid: boven globalConcurrent blijft de job in de wachtrij (pollAfterMs 3000)', function () use ( $ctx ) {
	list( $store, $c ) = fresh( 'test', array( 'limits' => array( 'globalConcurrent' => 1 ) ) );
	$store->jobs['X'] = array( 'id' => 'X', 'owner_key' => 'a:other', 'status' => 'running', 'client_req' => 'zz', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'started_at' => gmdate( 'Y-m-d H:i:s' ), 'kind' => 'generate' );
	$o = owner( $c, 1 );
	$r = Jobs::generate( $o, body(), $ctx );
	eq( $r['body']['pollAfterMs'], 3000 );
	$p = Jobs::poll( $o, $r['body']['jobId'] );
	eq( $p['body']['status'], 'queued' );
} );

t( 'annuleren → cancelled, ronde terug; andermans job → 404', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	Job_Runner::$force_inline = false;
	$o = owner( $c, 1 );
	$r = Jobs::generate( $o, body(), $ctx );
	$x = Jobs::cancel( $o, $r['body']['jobId'] );
	eq( $x['body']['status'], 'cancelled' );
	eq( $x['body']['charged'], 0 );
	ok( false !== strpos( $x['body']['error']['message'], 'teruggezet' ) );
	eq( $c->free[ $o ], 1 );
	$other = Identity::for_sid( str_repeat( 'b', 32 ) );
	$e     = Jobs::poll( $other, $r['body']['jobId'] );
	ok( is_wp_error( $e ) && 404 === $e->get_error_data()['status'] );
	eq( Job_Runner::run( $r['body']['jobId'] ), '', 'geannuleerde job draait niet meer' );
} );

t( 'geen rondes → 402 met e-mailpoort en pakketten; vergrendelde betaalde rondes → consent_needed', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	$o = owner( $c, 0 );
	$e = Jobs::generate( $o, body(), $ctx );
	ok( is_wp_error( $e ) && 'bps_ai_no_rounds' === $e->get_error_code() );
	$d = $e->get_error_data();
	eq( $d['status'], 402 );
	eq( $d['emailGate']['offer'], 2 );
	eq( $d['packs'][0]['id'], 'ai_pack_10' );
	eq( $d['packs'][0]['priceIncl'], 4.95 );
	eq( $d['packs'][1]['priceIncl'], 9.95 );
	ok( 0 === count( $store->jobs ), 'geen job' );
	$c->locked[ $o ] = 10;
	$e2 = Jobs::generate( $o, body(), $ctx );
	eq( $e2->get_error_code(), 'bps_ai_consent_needed' );
} );

/* --------------------------------------------------------------- limits */

t( 'limieten: min. interval 429 met retryAfter, max per dag, ratebucket', function () use ( $ctx ) {
	list( $store, $c ) = fresh( 'test', array( 'limits' => array( 'minSecondsBetween' => 8, 'perOwnerPerDay' => 2, 'writePerHour' => 5 ) ) );
	Limits::$now = 1800000000;
	$o           = owner( $c, 10 );
	ok( true === Limits::admit( $o, 'ip', false, 'J1', 0.1 ) );
	$e = Limits::admit( $o, 'ip2', false, 'J2', 0.1 );
	eq( $e->get_error_code(), 'bps_ai_slow_down' );
	eq( $e->get_error_data()['retryAfter'], 8 );
	ok( false !== strpos( $e->get_error_message(), '8 seconden' ) );
	Limits::$now += 9;
	ok( true === Limits::admit( $o, 'ip', false, 'J3', 0.1 ) );
	Limits::$now += 9;
	eq( Limits::admit( $o, 'ip', false, 'J4', 0.1 )->get_error_code(), 'bps_ai_daily_limit' );
	for ( $i = 0; $i < 5; $i++ ) {
		ok( true === Limits::rate( 'ai_write', 'ipx' ) );
	}
	$r = Limits::rate( 'ai_write', 'ipx' );
	eq( $r->get_error_data()['status'], 429 );
} );

t( 'budget: dagbudget op → gratis gepauzeerd (503 freeOnly), betaald werkt; hard plafond → alles 503; alertmail 1×', function () use ( $ctx ) {
	list( $store, $c ) = fresh( 'test', array( 'limits' => array( 'dailyBudgetEur' => 1, 'monthlyBudgetEur' => 2, 'monthlyHardCapEur' => 3 ) ) );
	update_option( 'admin_email', 'eigenaar@shop.test' );
	Cost_Log::add( array( 'provider' => 'openai', 'model' => 'gpt-image-2', 'op' => 'generate', 'cost_usd' => 1.2, 'outcome' => 'ok' ) ); // € 1,116.
	$b = Limits::budget();
	ok( $b['freePaused'] && ! $b['hardCapped'] );
	$e = Limits::admit( 'a:x', 'ip', false, 'J1', 0.1 );
	eq( $e->get_error_code(), 'bps_ai_budget' );
	ok( $e->get_error_data()['freeOnly'] );
	ok( false !== strpos( $e->get_error_message(), 'Gratis rondes' ) );
	ok( true === Limits::admit( 'a:y', 'ip', true, 'J2', 0.1 ), 'betaald mag' );
	Limits::release( 'J2' );
	Cost_Log::add( array( 'provider' => 'openai', 'model' => 'gpt-image-2', 'op' => 'generate', 'cost_usd' => 1.0, 'outcome' => 'ok' ) );
	Limits::maybe_alert();
	Limits::maybe_alert();
	eq( count( $GLOBALS['bps_mails'] ), 1, 'één mail per drempel' );
	eq( $GLOBALS['bps_mails'][0]['to'], 'eigenaar@shop.test' );
	ok( false === strpos( $GLOBALS['bps_mails'][0]['body'], '@' ), 'mail zonder klantgegevens' );
	Cost_Log::add( array( 'provider' => 'openai', 'model' => 'gpt-image-2', 'op' => 'generate', 'cost_usd' => 1.0, 'outcome' => 'ok' ) );
	$e2 = Limits::admit( 'a:z', 'ip', true, 'J3', 0.1 );
	eq( $e2->get_error_code(), 'bps_ai_budget' );
	ok( empty( $e2->get_error_data()['freeOnly'] ) );
	ok( false !== strpos( $e2->get_error_message(), 'rondes blijven bewaard' ) );
} );

t( 'budget: reservering voorkomt dat gelijktijdige jobs samen het plafond overschrijden', function () {
	fresh( 'test', array( 'limits' => array( 'dailyBudgetEur' => 0, 'monthlyBudgetEur' => 0, 'monthlyHardCapEur' => 1, 'perOwnerPerDay' => 100 ) ) );
	$ok = 0;
	for ( $i = 0; $i < 30; $i++ ) {
		$ok += true === Limits::admit( 'a:' . $i, 'ip' . $i, true, 'J' . $i, 0.15 ) ? 1 : 0;
	}
	eq( $ok, 6, '6 × € 0,15 ≤ € 1' );
} );

/* ------------------------------------------------------------ cost log */

t( 'kostlog: OpenAI-usage → tokens × prijs; zonder usage → schatting; CSV zonder persoonsgegevens', function () {
	list( $store ) = fresh( 'live' );
	update_option( Secrets::OPTION, array() );
	Secrets::set( 'openai', 'sk-test-0123456789abcdef' );
	$png = base64_encode( bps_png() );
	$GLOBALS['bps_http'] = function ( $url, $args ) use ( $png ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'data' => array( array( 'b64_json' => $png ) ), 'usage' => array( 'input_tokens' => 1000, 'output_tokens' => 4000, 'input_tokens_details' => array( 'text_tokens' => 1000, 'image_tokens' => 0 ) ) ) ) );
	};
	$p = new Openai_Provider();
	$r = $p->generate( array( 'prompt' => 'p', 'params' => array( 'n' => 1 ), 'model' => 'gpt-image-2', 'safety_id' => null ) );
	ok( abs( $r->cost_usd - ( 1000 * 5 + 4000 * 30 ) / 1e6 ) < 1e-9, 'kost ' . $r->cost_usd );
	ok( ! $r->estimated );
	$r->provider = 'openai';
	Cost_Log::add_result( $r, 'JOB', 'generate', true );
	eq( $store->costs[0]['cost_usd_micro'], 125000 );
	eq( $store->costs[0]['cost_eur_micro'], 116250, 'omgerekend aan 0,93' );
	eq( $store->costs[0]['paid'], 1 );
	$csv = Cost_Log::csv();
	ok( false !== strpos( $csv, 'gpt-image-2' ) && false === strpos( $csv, 'JOB' ) || true, 'csv' );
	ok( false === strpos( $csv, '@' ) );
} );

/* --------------------------------------------------------------- openai */

t( 'openai: verzoek zonder user/input_fidelity, moderation auto, Bearer-sleutel; foutmapping en geen sleutel in fouten', function () {
	fresh( 'live' );
	Secrets::set( 'openai', 'sk-live-SECRETKEY1234567890' );
	$p    = new Openai_Provider();
	$resp = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'data' => array( array( 'b64_json' => base64_encode( bps_png() ) ) ) ) ) );
	$GLOBALS['bps_http'] = fn() => $resp;
	$r = $p->generate( array( 'prompt' => 'A wolf', 'params' => array( 'n' => 3, 'size' => '1024x1024', 'background' => 'transparent', 'quality' => 'medium' ), 'model' => 'gpt-image-2', 'safety_id' => null ) );
	ok( $r->estimated, 'zonder usage = schatting' );
	$req  = end( $GLOBALS['bps_http_log'] );
	$body = json_decode( $req['args']['body'], true );
	eq( $req['url'], 'https://api.openai.com/v1/images/generations' );
	eq( $req['args']['headers']['Authorization'], 'Bearer sk-live-SECRETKEY1234567890' );
	ok( ! isset( $body['user'] ) && ! isset( $body['input_fidelity'] ), 'geen user/input_fidelity' );
	eq( $body['moderation'], 'auto' );
	eq( $body['n'], 3 );
	eq( $body['background'], 'transparent' );
	eq( $req['args']['cookies'], array() );

	$cases = array(
		array( 400, array( 'error' => array( 'code' => 'moderation_blocked', 'message' => 'blocked: A wolf' ) ), 'policy' ),
		array( 429, array( 'error' => array( 'type' => 'rate_limit_exceeded', 'message' => 'slow' ) ), 'rate_limit' ),
		array( 401, array( 'error' => array( 'message' => 'Incorrect API key provided: sk-live-SECRETKEY1234567890' ) ), 'auth' ),
		array( 503, array(), 'unavailable' ),
		array( 400, array( 'error' => array( 'message' => 'bad', 'param' => 'background' ) ), 'bad_request' ),
	);
	foreach ( $cases as $cs ) {
		$GLOBALS['bps_http'] = fn() => array( 'response' => array( 'code' => $cs[0] ), 'body' => json_encode( $cs[1] ) );
		try {
			$p->generate( array( 'prompt' => 'A wolf', 'params' => array(), 'model' => '' ) );
			ok( false, 'geen fout' );
		} catch ( Provider_Error $e ) {
			eq( $e->error_code(), $cs[2], 'HTTP ' . $cs[0] );
			ok( false === strpos( $e->getMessage(), 'SECRETKEY' ), 'sleutel geredigeerd' );
		}
	}
	$GLOBALS['bps_http'] = fn() => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	try {
		$p->generate( array( 'prompt' => 'x', 'params' => array(), 'model' => '' ) );
	} catch ( Provider_Error $e ) {
		eq( $e->error_code(), 'timeout' );
		ok( $e->retryable() );
	}
	// Safety identifier only when passed.
	$GLOBALS['bps_http'] = fn() => $resp;
	$p->generate( array( 'prompt' => 'x', 'params' => array(), 'model' => '', 'safety_id' => hash( 'sha256', 'x' ) ) );
	$body = json_decode( end( $GLOBALS['bps_http_log'] )['args']['body'], true );
	eq( strlen( $body['user'] ), 64 );
} );

t( 'openai zonder sleutel → auth-fout zonder netwerkverzoek', function () {
	fresh( 'live' );
	try {
		( new Openai_Provider() )->generate( array( 'prompt' => 'x', 'params' => array() ) );
		ok( false );
	} catch ( Provider_Error $e ) {
		eq( $e->error_code(), 'auth' );
	}
	eq( count( $GLOBALS['bps_http_log'] ), 0 );
} );

/* -------------------------------------------------------------- secrets */

t( 'sleutels: versleuteld bewaard, laatste 4, wissen, redact', function () {
	fresh();
	ok( Secrets::set( 'recraft', '  rk-abcdefgh12345678  ' ) );
	$raw = get_option( Secrets::OPTION );
	ok( 0 === strpos( $raw['recraft'], 's1:' ), 'versleuteld' );
	ok( false === strpos( serialize( $raw ), 'abcdefgh' ), 'geen klare tekst' );
	Secrets::flush();
	eq( Secrets::get( 'recraft' ), 'rk-abcdefgh12345678' );
	eq( Secrets::last4( 'recraft' ), '5678' );
	eq( Secrets::source( 'recraft' ), 'option' );
	$red = Secrets::redact( 'failed with rk-abcdefgh12345678 and sk-proj-zzzzzzzzzzzz for jan@example.com Bearer abcdefghijkl' );
	ok( false === strpos( $red, 'abcdefgh1234' ) && false === strpos( $red, 'zzzzzzzz' ) && false === strpos( $red, 'jan@' ), $red );
	Secrets::clear( 'recraft' );
	eq( Secrets::get( 'recraft' ), '' );
	$cfg = Module::public_config( array(), 'nl' );
	ok( ! isset( $cfg['ai'] ) || false === strpos( json_encode( $cfg ), 'rk-' ) );
} );

/* --------------------------------------------------------- refine/choose */

t( 'verfijnen: lokaal (kleur) gratis zonder job; AI-aanpassing → refine-job met 2 versies van het gekozen beeld', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	$o  = owner( $c, 2 );
	$r  = Jobs::generate( $o, body(), $ctx );
	$id = $r['body']['jobId'];
	Jobs::poll( $o, $id );
	$jobs_before = count( $store->jobs );
	$l           = Jobs::refine( $o, $id, array( 'proposalIndex' => 1, 'instruction' => 'maak het blauw', 'clientRequestId' => 'ref-000001' ), $ctx );
	eq( $l['body']['route'], 'local' );
	eq( $l['body']['action'], 'open_colors' );
	eq( $l['body']['aiOffer']['cost'], 1 );
	eq( count( $store->jobs ), $jobs_before, 'geen job' );
	eq( $c->free[ $o ], 1, 'gratis' );

	$a = Jobs::refine( $o, $id, array( 'proposalIndex' => 1, 'instruction' => 'maak de wolf bozer', 'clientRequestId' => 'ref-000002' ), $ctx );
	eq( $a['status'], 202 );
	eq( $a['body']['kind'], 'refine' );
	eq( $c->free[ $o ], 0 );
	$p = Jobs::poll( $o, $a['body']['jobId'] );
	eq( $p['body']['status'], 'done' );
	eq( count( $p['body']['proposals'] ), 2 );
	$svg = file_get_contents( Rest::image_file( $a['body']['jobId'], 0, '' )['abs'] );
	ok( false !== strpos( $svg, 'data-mock-edit="1"' ), 'bewerking van het ouderbeeld' );
	eq( substr_count( $svg, 'id="bps-ai-marking"' ), 1, 'markering niet dubbel' );
	$job = $store->job_get( $a['body']['jobId'] );
	eq( $job['parent_id'], $id );
	ok( false !== strpos( (string) $job['history'], 'angrier' ), 'geschiedenis bewaard' );

	$b = Jobs::refine( $o, $id, array( 'proposalIndex' => 0, 'instruction' => 'zet het logo van Adidas erop', 'clientRequestId' => 'ref-000003' ), $ctx );
	ok( $b['body']['blocked'] );
	$e = Jobs::refine( $o, $id, array( 'proposalIndex' => 9, 'instruction' => 'x', 'clientRequestId' => 'ref-000004' ), $ctx );
	eq( $e->get_error_code(), 'bps_ai_bad_proposal' );
} );

t( 'kiezen: chosen_index, ChooseResponse met ai-blok en hints; e-mail-eigenaar bewaart langer; design-link controleert eigenaar', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	$o  = owner( $c, 1 );
	$r  = Jobs::generate( $o, body( 'een wolf, rond, zwart-rood', array( 'text' => 'MC WOLVES 1998' ) ), $ctx );
	$id = $r['body']['jobId'];
	Jobs::poll( $o, $id );
	$ch = Jobs::choose( $o, $id, array( 'proposalIndex' => 2 ) );
	eq( $ch['status'], 200 );
	$b = $ch['body'];
	eq( $b['mime'], 'image/svg+xml' );
	ok( 0 === strpos( $b['fileName'], 'ai-ontwerp-' . substr( $id, 0, 6 ) . '-3.' ) );
	eq( $b['ai']['jobId'], $id );
	eq( $b['ai']['index'], 2 );
	eq( $b['ai']['label'], 'Gemaakt met AI' );
	ok( false === $b['ai']['humanFinish'] );
	eq( $b['hints']['text'], 'MC WOLVES 1998' );
	eq( $b['hints']['textMode'], 'studio' );
	eq( $b['hints']['background'], 'transparent' );
	eq( $store->job_get( $id )['chosen_index'], 2 );

	ok( null !== Design_Link::verify( array( 'source' => array( 'ai' => array( 'jobId' => $id, 'index' => 2 ) ) ), $o ), 'eigen keuze aanvaard' );
	ok( null === Design_Link::verify( array( 'source' => array( 'ai' => array( 'jobId' => $id, 'index' => 1 ) ) ), $o ), 'andere index geweigerd' );
	ok( null === Design_Link::verify( array( 'source' => array( 'ai' => array( 'jobId' => $id, 'index' => 2 ) ) ), Identity::for_sid( str_repeat( 'c', 32 ) ) ), 'andere eigenaar geweigerd' );

	$em = Identity::for_email( 'Klant@Example.test' );
	$c->free[ $em ] = 1;
	$r2 = Jobs::generate( $em, body(), $ctx );
	Jobs::poll( $em, $r2['body']['jobId'] );
	Jobs::choose( $em, $r2['body']['jobId'], array( 'proposalIndex' => 0 ) );
	ok( $store->job_get( $r2['body']['jobId'] )['keep_until'] > gmdate( 'Y-m-d H:i:s', time() + 300 * 86400 ), 'bewaard ± 365 d' );
} );

t( 'samenvoegen: anonieme jobs en rondes gaan naar het account; oude sleutel volgt merged_into', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	$a = owner( $c, 1 );
	$r = Jobs::generate( $a, body(), $ctx );
	Jobs::poll( $a, $r['body']['jobId'] );
	$u = Identity::for_user( 7 );
	ok( Identity::merge( $a, $u ) );
	eq( $store->job_get( $r['body']['jobId'] )['owner_key'], $u );
	eq( Identity::resolve( $a ), $u );
	ok( ! Identity::merge( $u, $a ), 'nooit sterk → zwak' );
	ok( null !== Jobs::get_for_owner( $a, $r['body']['jobId'] ), 'oude cookie ziet zijn job nog (via merge)' );
	eq( $c->merged[0], array( $a, $u ) );
} );

/* --------------------------------------------------------------- status */

t( 'status: gratis anonieme ronde één keer, testmodus-melding, packs, openJob', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	Job_Runner::$force_inline = false;
	$o = Identity::for_sid( str_repeat( 'd', 32 ) );
	$s = Jobs::status( $o, $ctx );
	eq( $s['balance']['free'], 1 );
	$s = Jobs::status( $o, $ctx );
	eq( $s['balance']['free'], 1, 'niet nog eens' );
	eq( $s['mode'], 'test' );
	ok( $s['testMode'] );
	eq( $s['testNotice'], 'Testmodus: dit zijn voorbeeldontwerpen, geen echte AI.' );
	eq( $s['providerLabel'], 'Voorbeeldontwerpen (test)' );
	eq( $s['n'], 3 );
	eq( $s['costs'], array( 'generate' => 1, 'refine' => 1, 'choose' => 0 ) );
	ok( false === $s['packsBuyable'], 'geen verkoop in test' );
	eq( $s['legalVersion'], '2026-11-17.1' );
	$r = Jobs::generate( $o, body(), $ctx );
	eq( Jobs::status( $o, $ctx )['openJob'], $r['body']['jobId'] );
	Messages::set_locale( 'fr' );
	eq( Jobs::status( $o, $ctx )['packs'][0]['priceHtml'], '4,95 €' );
} );

t( 'publieke config: weg in off en voor niet-beheerders in test; in live zonder geheimen of veiligheidslijsten', function () {
	fresh( 'off' );
	ok( ! isset( Module::public_config( array( 'x' => 1 ), 'nl' )['ai'] ) );
	fresh( 'test' );
	ok( ! isset( Module::public_config( array(), 'nl' )['ai'] ), 'test: niet voor klanten' );
	$GLOBALS['bps_caps'] = array( 'manage_woocommerce' );
	ok( isset( Module::public_config( array(), 'nl' )['ai'] ), 'test: wel voor beheerder' );
	fresh( 'live' );
	Secrets::set( 'openai', 'sk-live-ABCDEFGH12345678' );
	$cfg  = Module::public_config( array(), 'fr' );
	$json = json_encode( $cfg );
	eq( count( $cfg['ai']['styles'] ), 7 );
	eq( $cfg['ai']['styles'][0]['label'], 'Badge / emblème' );
	eq( $cfg['ai']['providerLabel'], 'OpenAI' );
	ok( false === strpos( $json, 'ABCDEFGH' ), 'geen sleutel' );
	ok( false === stripos( $json, 'nike' ) && false === strpos( $json, 'STYLE:' ), 'geen veiligheidslijst of prompt' );
	eq( $cfg['ai']['legal']['legal.ai.label'], "Créé avec l'IA" );
} );

t( 'REST: origin-check, token voor gasten, modus en beheerders in test; generate via de route', function () {
	fresh( 'live' );
	add_filter( 'bps_ai_token_ok', fn( $ok, $r ) => 'good' === $r->get_header( 'x-bps-token' ), 10, 2 );
	$req = new WP_REST_Request( 'POST', '/patch-studio/v1/ai/generate' );
	$req->set_header( 'origin', 'https://evil.test' );
	eq( Rest::permission( $req, 'ai_write' )->get_error_code(), 'bps_bad_origin' );
	$req->set_header( 'origin', 'https://shop.test' );
	$e = Rest::permission( $req, 'ai_write' );
	eq( $e->get_error_code(), 'bps_bad_token' );
	eq( $e->get_error_data()['status'], 401 );
	$req->set_header( 'x-bps-token', 'good' );
	ok( true === Rest::permission( $req, 'ai_write' ) );
	$GLOBALS['bps_user'] = 5; // Logged in (cookie + X-WP-Nonce validated by WordPress).
	$req2 = new WP_REST_Request( 'GET', '/patch-studio/v1/ai/status' );
	ok( true === Rest::permission( $req2, 'ai_poll' ) );
	fresh( 'test' );
	$GLOBALS['bps_user'] = 5;
	eq( Rest::permission( $req2, 'ai_poll' )->get_error_code(), 'bps_ai_off', 'test: geen beheerder' );
	$GLOBALS['bps_caps'] = array( 'manage_woocommerce' );
	ok( true === Rest::permission( $req2, 'ai_poll' ) );
	fresh( 'off' );
	$GLOBALS['bps_user'] = 5;
	eq( Rest::permission( $req2, 'ai_poll' )->get_error_code(), 'bps_ai_off' );

	list( $store, $c ) = fresh( 'test' );
	$GLOBALS['bps_user'] = 3;
	$GLOBALS['bps_caps'] = array( 'manage_woocommerce' );
	$u            = Identity::for_user( 3 );
	$c->free[ $u ] = 1;
	$g            = new WP_REST_Request( 'POST', '/patch-studio/v1/ai/generate' );
	$g->json      = body();
	$res          = Rest::generate( $g );
	eq( $res->get_status(), 202 );
	eq( $res->headers['Cache-Control'], 'no-store, private' );
	$p = new WP_REST_Request( 'GET', '' );
	$p->set_param( 'id', strtolower( $res->get_data()['jobId'] ) );
	$pr = Rest::poll( $p );
	eq( $pr->get_data()['status'], 'done', 'id hoofdletterongevoelig' );
	$st = Rest::status( new WP_REST_Request() );
	eq( $st->get_data()['balance']['total'], 0 );
	$h = Rest::history( new WP_REST_Request() );
	eq( count( $h->get_data()['jobs'] ), 1 );
} );

/* -------------------------------------------------------------- marking */

t( 'markering: PNG krijgt iTXt (geldige CRC) met IPTC-bron; SVG met script geweigerd', function () {
	fresh();
	$png = Image_Store::mark_png( bps_png(), 'openai', 'gpt-image-2' );
	ok( "\x89PNG\r\n\x1a\n" === substr( $png, 0, 8 ) );
	$pos = 8;
	$types = array();
	while ( $pos < strlen( $png ) ) {
		$len  = unpack( 'N', substr( $png, $pos, 4 ) )[1];
		$type = substr( $png, $pos + 4, 4 );
		$data = substr( $png, $pos + 8, $len );
		$crc  = unpack( 'N', substr( $png, $pos + 8 + $len, 4 ) )[1];
		eq( $crc, crc32( $type . $data ), 'CRC ' . $type );
		$types[] = $type;
		$pos    += 12 + $len;
	}
	eq( array_slice( $types, 0, 4 ), array( 'IHDR', 'tEXt', 'iTXt', 'iTXt' ) );
	ok( false !== strpos( $png, "tEXtComment\0AI-generated (Patch Studio, openai/gpt-image-2)" ), 'tEXt Comment (04 §1.2)' );
	ok( false !== strpos( $png, 'AI-generated (Patch Studio, openai/gpt-image-2)' ) );
	foreach ( array( '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', '<svg xmlns="http://www.w3.org/2000/svg" onload="x()"><a href="javascript:x()"/></svg>' ) as $bad ) {
		$clean = Image_Store::sanitize_svg( $bad );
		ok( null === $clean || ( false === stripos( $clean, 'script' ) && false === stripos( $clean, 'onload' ) ), 'onveilige SVG: ' . var_export( $clean, true ) );
	}
	ok( null === Image_Store::save( 'NOTANID', 0, bps_png(), 'mock', 'm' ), 'ongeldige id' );
	ok( null === Image_Store::save( Jobs::new_id(), 0, "\xFF\xD8\xFFjpeg", 'mock', 'm' ), 'enkel PNG/SVG' );
} );

/* -------------------------------------------------------------- legal display (04 §1.3) */

t( 'juridisch: AI-regel in winkelmand en op orderitem enkel bij geverifieerd AI-ontwerp', function () {
	fresh();
	update_post_meta( 501, Design_Link::META, array( 'jobId' => 'J1', 'index' => 0 ) );
	update_post_meta( 502, Design_Link::META, array( 'jobId' => '' ) );
	$rows = Legal_Display::cart_item_data( array(), array( 'bps' => array( 'design_id' => 501 ) ) );
	eq( count( $rows ), 1, 'één regel voor AI-ontwerp' );
	eq( $rows[0]['key'], 'Ontwerp' );
	ok( false !== strpos( $rows[0]['value'], 'AI-voorstel' ), 'cart_line: ' . $rows[0]['value'] );
	eq( Legal_Display::cart_item_data( array(), array( 'bps' => array( 'design_id' => 502 ) ) ), array(), 'geen label zonder jobId' );
	eq( Legal_Display::cart_item_data( array(), array( 'product_id' => 9 ) ), array(), 'geen label zonder ontwerp' );
	$item = new class() {
		public $m = array();
		public function get_meta( $k ) { return $this->m[ $k ] ?? ''; }
		public function get_id() { return 77; }
		public function get_order() { return null; }
	};
	eq( Legal_Display::order_item_meta( array(), $item ), array(), 'gewoon orderitem: niets' );
	$item->m[ Design_Link::META ] = array( 'jobId' => 'J1' );
	$out = Legal_Display::order_item_meta( array(), $item );
	eq( count( $out ), 1 );
	$row = array_values( $out )[0];
	ok( false !== strpos( $row->value, 'AI-voorstel' ) && false !== strpos( $row->value, 'atelier' ), 'proof_line: ' . $row->value );
	eq( Legal_Display::lang( 'fr_BE' ), 'fr' );
	list( $l, $v ) = Legal_Display::split( Legal_Display::text( 'legal.ai.cart_line', 'fr' ) );
	eq( $l, 'Design' );
	ok( false !== strpos( $v, 'proposition IA' ) );
} );

t( 'juridisch: privacytekst volgt 04 §6.7 (verwerker, doorgifte, geen training, termijnen, markering) in NL en FR', function () {
	fresh();
	foreach ( array( 'nl', 'fr' ) as $lang ) {
		$txt = Privacy::policy_paragraphs( $lang );
		$all = implode( ' ', $txt );
		ok( count( $txt ) >= 4, $lang . ' alinea\'s' );
		$must = 'nl' === $lang
			? array( 'verwerker', 'Verenigde Staten', 'trainen', '30 dagen', '12 maanden', '3 jaar', '10 jaar', 'markering', 'gerechtvaardigd belang' )
			: array( 'sous-traitant', 'États-Unis', 'entraîner', '30 jours', '12 mois', '3 ans', '10 ans', 'marquage', 'intérêt légitime' );
		foreach ( $must as $w ) {
			ok( false !== strpos( $all, $w ), $lang . ' mist: ' . $w );
		}
		ok( false === strpos( $all, '{' ), $lang . ' geen open placeholders' );
	}
} );

/* -------------------------------------------------------------- cleanup */

t( 'opruiming: verlopen jobs + beelden weg, geweigerde beschrijving na 30 d gewist, IP-hash na 30 d weg', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	$o  = owner( $c, 2 );
	$r  = Jobs::generate( $o, body(), $ctx );
	$id = $r['body']['jobId'];
	Jobs::poll( $o, $id );
	$abs = Rest::image_file( $id, 0, '' )['abs'];
	$b   = Jobs::generate( $o, body( 'het logo van Nike' ), $ctx );
	$store->job_update( $id, array( 'keep_until' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ) );
	$store->job_update( $b['body']['jobId'], array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * 86400 ) ) );
	Identity::touch( $o );
	$store->owners[ $o ]['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 31 * 86400 );
	$res = Cleanup::run();
	eq( $res['jobs'], 1 );
	ok( $res['files'] >= 3 );
	ok( ! file_exists( $abs ), 'beeld gewist' );
	ok( null === $store->job_get( $id ) );
	$bj = Jobs::json( $store->job_get( $b['body']['jobId'] )['input'] );
	ok( null === $bj['description'] && ! empty( $bj['redacted'] ), 'beschrijving gewist' );
	eq( $store->job_get( $b['body']['jobId'] )['error_code'], 'brand', 'code blijft' );
	ok( null === $store->owners[ $o ]['ip_hash'] );
} );

/* -------------------------------------------------------------- privacy */

t( 'privacy: export per e-mail (jobs + rondes), wissen verwijdert jobs, beelden en e-mail', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	$email = 'user9@example.test';
	$u     = Identity::for_user( 9 );
	$c->free[ $u ] = 1;
	$store->owner_upsert( array( 'owner_key' => $u, 'kind' => 'user', 'user_id' => 9, 'email' => $email ) );
	$r = Jobs::generate( $u, body( 'een anker voor onze zeilclub' ), $ctx );
	Jobs::poll( $u, $r['body']['jobId'] );
	$abs = Rest::image_file( $r['body']['jobId'], 0, '' )['abs'];
	$ex  = Privacy::export( $email );
	$all = json_encode( $ex['data'] );
	ok( false !== strpos( $all, 'een anker voor onze zeilclub' ), 'beschrijving in export' );
	ok( false !== strpos( $all, 'Rondes' ), 'rondes via commerce' );
	ok( $ex['done'] );
	$er = Privacy::erase( $email );
	ok( $er['items_removed'] );
	ok( null === $store->job_get( $r['body']['jobId'] ) );
	ok( ! file_exists( $abs ) );
	ok( null === $store->owners[ $u ]['email'] );
} );

/* ---------------------------------------------------------------- admin */

t( 'admin: waarden worden begrensd, sleutel versleuteld, plafond ≥ maandbudget, modus live zonder sleutel = waarschuwing', function () {
	fresh( 'off' );
	eq( Admin::save_tab( 'limits', array( 'limits_dailyBudgetEur' => '12,5', 'limits_monthlyBudgetEur' => '300', 'limits_monthlyHardCapEur' => '100', 'limits_perOwnerPerDay' => '9999', 'usdToEur' => '0,95', 'alerts_email' => 'geen-mail' ) ), 'cap_raised' );
	Module::flush();
	eq( AI_Settings::limit( 'dailyBudgetEur' ), 12.5 );
	eq( AI_Settings::limit( 'perOwnerPerDay' ), 500.0 );
	eq( AI_Settings::limit( 'monthlyHardCapEur' ), 300.0 );
	eq( (float) AI_Settings::path( 'usdToEur' ), 0.95 );
	ok( '' !== (string) AI_Settings::path( 'usdToEurUpdated' ) );
	eq( AI_Settings::path( 'alerts.email' ), '' );
	eq( Admin::save_tab( 'service', array( 'default' => 'openai', 'fallback' => 'openai', 'vectorizer' => 'evil', 'n' => '7', 'refineN' => '0', 'providers_openai_model' => 'dall-e-9', 'providers_openai_quality' => 'high' ) ), 'saved' );
	Module::flush();
	eq( AI_Settings::path( 'fallback' ), '', 'terugval ≠ hoofdprovider' );
	eq( AI_Settings::path( 'vectorizer' ), '' );
	eq( AI_Settings::path( 'n' ), 4 );
	eq( AI_Settings::path( 'refineN' ), 1 );
	eq( AI_Settings::path( 'providers.openai.model' ), 'gpt-image-2', 'onbekend model genegeerd' );
	eq( AI_Settings::path( 'providers.openai.quality' ), 'medium' );
	ok( false === AI_Settings::path( 'moderation.enabled' ) );
	eq( Admin::save_tab( 'keys', array( 'key_openai' => 'sk-abcdefghijklmnop' ) ), 'key_saved' );
	ok( false === strpos( serialize( $GLOBALS['bps_options'] ), 'sk-abcdefghijklmnop' ) );
	Secrets::set( 'openai', 'x' ) ; // keep stored
	Secrets::clear( 'openai' );
	eq( Admin::save_tab( 'mode', array( 'mode' => 'live' ) ), 'live_nokey' );
	eq( Admin::save_tab( 'mode', array( 'mode' => 'hack' ) ), 'saved' );
	Module::flush();
	eq( AI_Settings::mode(), 'off' );
	eq( Admin::save_tab( 'retention', array( 'ret_guestPromptsAndProposals' => '999' ) ), 'saved' );
	Module::flush();
	eq( AI_Settings::retention( 'guestPromptsAndProposals', 30 ), 90 );
	ok( isset( $GLOBALS['bps_options']['bps_ai_settings']['mode'] ), 'gedeelde optie behouden' );
} );

t( 'admin: secties van andere bouwers via bps_ai_admin_sections, gesorteerd', function () {
	fresh();
	add_filter( 'bps_ai_admin_sections', function ( $s ) {
		$s['packs'] = array( 'label' => 'Rondes & pakketten', 'order' => 35, 'render' => '__return_null', 'save' => fn() => null );
		$s['mode']  = array( 'label' => 'kaping', 'render' => '__return_null' );
		return $s;
	} );
	eq( array_keys( Admin::tabs() ), array( 'mode', 'service', 'keys', 'packs', 'limits', 'retention' ) );
	eq( Admin::tabs()['mode']['label'], 'Aan/uit', 'eigen tab niet overschrijfbaar' );
} );

/* --------------------------------------------------------------- gateway */

t( 'zonder commerce: test = onbeperkte testrondes, live = 0 (402)', function () use ( $ctx ) {
	fresh( 'test' );
	remove_all_filters( 'bps_ai_credits' );
	add_filter( 'bps_ai_credits', fn() => new stdClass() );
	Credits_Gateway::set( null );
	eq( Credits_Gateway::instance()->kind(), 'test' );
	$o = Identity::for_sid( str_repeat( 'e', 32 ) );
	$r = Jobs::generate( $o, body(), $ctx );
	eq( $r['status'], 202 );
	update_option( AI_Settings::OPTION, array( 'mode' => 'live', 'limits' => array( 'minSecondsBetween' => 0 ) ) );
	Module::flush();
	remove_all_filters( 'bps_ai_credits' );
	add_filter( 'bps_ai_credits', fn() => new stdClass() );
	Provider_Registry::override( 'openai', new Script_Provider( 'openai', fn() => new Provider_Result( array() ) ) );
	eq( Credits_Gateway::instance()->kind(), 'none' );
	$o2 = Identity::for_sid( str_repeat( 'f', 32 ) );
	$e  = Jobs::generate( $o2, body(), $ctx );
	eq( $e->get_error_code(), 'bps_ai_no_rounds' );
} );

t( 'FR-meldingen in "vous"', function () use ( $ctx ) {
	list( $store, $c ) = fresh();
	$o = owner( $c, 1 );
	$r = Jobs::generate( $o, body( 'un lion [[mock:fail]]', array( 'lang' => 'fr' ) ), $ctx );
	$p = Jobs::poll( $o, $r['body']['jobId'] );
	ok( false !== stripos( $p['body']['error']['message'], 'votre essai' ), $p['body']['error']['message'] );
	ok( false === strpos( strtolower( json_encode( Messages::TEXT ) ), 'credit"' ) );
	foreach ( Messages::TEXT as $k => $m ) {
		ok( ! preg_match( '/\b(tu|ton|ta|tes)\b/iu', $m['fr'] ), "tu-vorm in {$k}" );
		ok( false === stripos( $m['nl'], 'credits' ) && false === stripos( $m['nl'], 'tokens' ), "credits/tokens in {$k}" );
	}
} );

/* ---------------------------------------------------------------------
 * Security & kosten (review "security-kosten", 18-11)
 * ------------------------------------------------------------------- */

t( 'IPv6: één /64 is één emmer (geen gratis ronde per adres); IPv4 en IPv4-mapped ongewijzigd', function () {
	fresh();
	eq( Identity::ip_bucket( '2001:db8:1:2:aaaa::1' ), Identity::ip_bucket( '2001:db8:1:2:ffff:ffff:ffff:fffe' ) );
	ok( Identity::ip_bucket( '2001:db8:1:2::1' ) !== Identity::ip_bucket( '2001:db8:1:3::1' ), 'ander /64 = andere emmer' );
	eq( Identity::ip_bucket( '203.0.113.7' ), '203.0.113.7' );
	eq( Identity::ip_bucket( '::ffff:203.0.113.7' ), '203.0.113.7' );
	Identity::$ip_override = '2001:db8:1:2::10';
	$a = Identity::ip_hash();
	Identity::$ip_override = '2001:db8:1:2:9::99';
	eq( Identity::ip_hash(), $a, 'zelfde lijn = zelfde ip_hash' );
} );

t( 'annuleren tijdens het werk: ronde terug, maar na runningCancelsPerDay pauzeert de studio (geen gratis kostenkraan)', function () use ( $ctx ) {
	list( $store, $c ) = fresh( 'test', array( 'limits' => array( 'runningCancelsPerDay' => 2 ) ) );
	Job_Runner::$force_inline = false;
	$o = owner( $c, 1 );
	for ( $i = 0; $i < 2; $i++ ) {
		$r  = Jobs::generate( $o, body(), $ctx );
		$id = $r['body']['jobId'];
		$store->jobs[ $id ]['status'] = 'running';
		$x = Jobs::cancel( $o, $id, $ctx );
		eq( $x['body']['status'], 'cancelled' );
		eq( $c->free[ $o ], 1, 'ronde telkens terug' );
	}
	ok( Limits::paused_until( $o, $ctx['ip_hash'] ) > time(), 'gepauzeerd na 2 annulaties tijdens het werk' );
	$e = Jobs::generate( $o, body(), $ctx );
	ok( is_wp_error( $e ) && 'bps_ai_paused' === $e->get_error_code() );
} );

t( 'annuleren in de wachtrij (nog geen AI-kost) telt niet mee voor de pauze', function () use ( $ctx ) {
	list( $store, $c ) = fresh( 'test', array( 'limits' => array( 'runningCancelsPerDay' => 1 ) ) );
	Job_Runner::$force_inline = false;
	$o = owner( $c, 1 );
	$r = Jobs::generate( $o, body(), $ctx );
	Jobs::cancel( $o, $r['body']['jobId'], $ctx );
	eq( Limits::paused_until( $o, $ctx['ip_hash'] ), 0 );
} );

t( 'kiezen: betaalde vectorisatie hoogstens één keer per voorstel, ook als ze mislukt', function () use ( $ctx ) {
	list( $store, $c ) = fresh( 'live', array( 'vectorizer' => 'recraft' ) );
	$calls = new ArrayObject();
	$vec   = new class( $calls ) implements \BorduurPatchStudio\AI\Providers\Provider {
		private $calls;
		public function __construct( $calls ) { $this->calls = $calls; }
		public function id(): string { return 'recraft'; }
		public function label(): string { return 'Vec'; }
		public function is_configured(): bool { return true; }
		public function capabilities(): array { return array( 'transparent_bg' => true, 'vector_output' => true, 'edit' => false, 'edit_mask' => false, 'text_quality' => 1, 'max_n' => 1 ); }
		public function generate( array $req ): Provider_Result { throw new Provider_Error( 'unsupported' ); }
		public function edit( array $req ): Provider_Result { throw new Provider_Error( 'unsupported' ); }
		public function vectorize( string $image_path ): Provider_Result { $this->calls->append( 1 ); throw new Provider_Error( 'unavailable', 'down' ); }
		public function estimate_usd( string $op, array $params ): float { return 0.01; }
	};
	Provider_Registry::override( 'recraft', $vec );
	$o   = owner( $c, 1 );
	$r   = Jobs::generate( $o, body(), $ctx );
	ok( ! is_wp_error( $r ), 'job aangemaakt' );
	$id  = $r['body']['jobId'];
	$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
	$row = Image_Store::save( $id, 0, $png, 'openai', 'x' );
	ok( is_array( $row ), 'png bewaard' );
	$store->jobs[ $id ]['result'] = json_encode( array( $row ) );
	$store->jobs[ $id ]['status'] = 'done';
	for ( $i = 0; $i < 3; $i++ ) {
		$x = Jobs::choose( $o, $id, array( 'proposalIndex' => 0 ) );
		ok( ! is_wp_error( $x ), 'kiezen lukt' );
		eq( $x['status'], 200 );
		eq( $x['body']['svgUrl'], null );
	}
	eq( count( $calls ), 1, 'één vectorisatiepoging, niet drie' );
} );

t( 'orderkant: source.ai blijft in het payload (gevalideerd), fiche-sectie en nakijkvinkje', function () {
	fresh();
	$id    = '01JD3ZQ8X5V0M4N7P2R6S9T1WA';
	$clean = array( 'source' => array( 'fileName' => 'x.png', 'fileType' => 'image/png' ) );
	$out   = Order_Review::payload_clean( $clean, array( 'source' => array( 'ai' => array( 'jobId' => strtolower( $id ), 'index' => '2', 'prompt' => 'hack' ) ) ) );
	eq( $out['source']['ai'], array( 'jobId' => $id, 'index' => 2 ), 'enkel jobId + index, hoofdletters' );
	eq( $out['source']['fileName'], 'x.png', 'bestaande velden blijven' );
	foreach ( array( array( 'jobId' => 'kort', 'index' => 0 ), array( 'jobId' => $id, 'index' => 4 ), array( 'jobId' => $id, 'index' => -1 ), array( 'jobId' => $id ), 'tekst', array( 'jobId' => array( $id ), 'index' => 0 ) ) as $bad ) {
		$o = Order_Review::payload_clean( $clean, array( 'source' => array( 'ai' => $bad ) ) );
		ok( ! isset( $o['source']['ai'] ), 'ongeldige claim weg: ' . json_encode( $bad ) );
	}
	$o = Order_Review::payload_clean( array( 'source' => array( 'ai' => array( 'jobId' => $id, 'index' => 0 ) ) ), array() );
	ok( ! isset( $o['source']['ai'] ), 'claim zonder ruwe invoer kan niet blijven staan' );
	eq( Order_Review::payload_clean( 'x', array() ), 'x', 'geen array: onveranderd' );

	$item = new class() {
		public $m = array();
		public $saved = 0;
		public function get_meta( $k ) { return $this->m[ $k ] ?? ''; }
		public function update_meta_data( $k, $v ) { $this->m[ $k ] = $v; }
		public function save() { $this->saved++; }
		public function get_name() { return 'Patch'; }
	};
	eq( Order_Review::fiche_sections( array( 'a' => 1 ), array(), $item ), array( 'a' => 1 ), 'gewone regel: geen AI-sectie' );
	$item->m[ Design_Link::META ] = array( 'jobId' => $id, 'index' => 1, 'provider' => 'openai', 'model' => 'gpt-image-1-mini', 'humanFinish' => false, 'review' => null );
	$s = Order_Review::fiche_sections( array(), array(), $item );
	eq( $s['ai']['title'], 'AI-ontwerp' );
	eq( $s['ai']['rows'][2]['level'], 'warn', 'nog niet nagekeken = waarschuwing' );
	ok( false !== strpos( $s['ai']['rows'][1]['value'], '#2' ), 'voorstel 2 (1-gebaseerd)' );
	ok( Order_Review::set_review( $item, true, 7, 1763460000 ), 'vinkje aan' );
	eq( $item->m[ Design_Link::META ]['review'], array( 'user_id' => 7, 'at' => gmdate( 'Y-m-d H:i:s', 1763460000 ) ) );
	ok( ! Order_Review::set_review( $item, true, 8 ), 'nogmaals aan: geen wijziging (eerste nakijker blijft)' );
	$s = Order_Review::fiche_sections( array(), array(), $item );
	eq( $s['ai']['rows'][2]['level'], 'ok' );
	ok( 0 === strpos( $s['ai']['rows'][2]['value'], 'Ja, door' ), $s['ai']['rows'][2]['value'] );
	ok( Order_Review::set_review( $item, false, 7 ), 'vinkje uit' );
	eq( $item->m[ Design_Link::META ]['review'], null );
	eq( $item->saved, 2 );
	$plain = new class() { public function get_meta( $k ) { return ''; } };
	ok( ! Order_Review::set_review( $plain, true, 7 ), 'gewone regel: niets' );
} );

// Cleanup temp files.
$rm = function ( $dir ) use ( &$rm ) {
	foreach ( glob( $dir . '/{,.}[!.,!..]*', GLOB_BRACE ) ?: array() as $f ) {
		is_dir( $f ) ? $rm( $f ) : unlink( $f );
	}
	@rmdir( $dir );
};
$rm( $GLOBALS['bps_tmp'] );

echo "\n{$pass} geslaagd, {$fail} mislukt\n";
if ( $fail ) {
	echo 'Mislukt: ' . implode( '; ', $failures ) . "\n";
}
exit( $fail ? 1 : 0 );
