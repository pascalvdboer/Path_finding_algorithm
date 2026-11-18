<?php
/**
 * Core lifecycle tests (P02): Logger, module registry, owned data + uninstall, upgrades, WC version check, licence
 * client, Site Health / WC status report, targeted cache purge, language bundle choice.
 * No WordPress, no database, no network: tests/core/wp-stubs.php.
 *
 * Run: php -d upload_max_filesize=2M -d post_max_size=8M tests/core/run-core-php.php
 * (also through `npm test`: tests/core/core-php.test.js)
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

require __DIR__ . '/wp-stubs.php';

$root   = dirname( __DIR__, 2 );
$plugin = $root . '/plugin/borduur-patch-studio/';
define( 'BPS_DIR', $plugin );
define( 'BPS_FILE', $plugin . 'borduur-patch-studio.php' );
define( 'BPS_URL', 'https://shop.test/wp-content/plugins/borduur-patch-studio/' );
define( 'BPS_VERSION', '1.0.0' );
define( 'BPS_DB_VERSION', 1 );
define( 'BPS_UPDATE_URL', 'https://borduurservicebelgie.be/' );
define( 'BPS_OPENAI_API_KEY', 'sk-unitTestKey1234567890abcdef' );

require_once $plugin . 'includes/class-plugin.php';
spl_autoload_register( array( \BorduurPatchStudio\Plugin::class, 'autoload' ) );

use BorduurPatchStudio\Admin\System_Status;
use BorduurPatchStudio\Licensing\Client;
use BorduurPatchStudio\Licensing\Driver;
use BorduurPatchStudio\Logger;
use BorduurPatchStudio\Plugin;
use BorduurPatchStudio\Settings;
use BorduurPatchStudio\Woo\Integration;

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
function settings_override( array $o ): void {
	$GLOBALS['bps_t']['options']['bps_settings'] = $o;
	Settings::flush();
	Logger::flush();
}

/* ======================================================================= */
section( 'Logger: drempel, redactie, schrijvers' );
$lines = array();
Logger::set_writer(
	function ( $level, $line, $ctx ) use ( &$lines ) {
		$lines[] = array( $level, $line, $ctx );
	}
);
settings_override( array() );
eq( Logger::threshold(), 'error', 'standaarddrempel = error' );
eq( Logger::info( 'not_written' ), false, 'info wordt niet geschreven bij logLevel=error' );
eq( Logger::warning( 'not_written' ), false, 'warning ook niet' );
eq( Logger::error( 'price_failed', array( 'design_ref' => 'P-1234' ) ), true, 'error wordt geschreven' );
eq( count( $lines ), 1, 'precies 1 regel' );
ok( 0 === strpos( $lines[0][1], 'price_failed {' ) && false !== strpos( $lines[0][1], 'P-1234' ), 'event + context in de regel' );

$lines = array();
Logger::error(
	'redact_test',
	array(
		'api_key'   => 'sk-abcdefghijklmnop',
		'email'     => 'Klant@Example.be',
		'ip'        => '81.82.83.84',
		'message'   => 'Fout met sleutel sk-ABCDEFGHIJKLMNOPQRST voor jan@example.be vanaf 10.0.0.7 Bearer abcdefghijkl123',
		'exception' => new RuntimeException( 'kapot voor piet@example.be' ),
		'nested'    => array( 'password' => 'geheim', 'n' => 3 ),
	)
);
$line = $lines[0][1];
ok( ! preg_match( '/sk-[A-Za-z0-9]{10,}/', $line ), 'geen API-sleutel in de log' );
ok( false === stripos( $line, 'example.be' ), 'geen e-mailadres in de log' );
ok( false === strpos( $line, '81.82.83.84' ) && false === strpos( $line, '10.0.0.7' ), 'geen IP-adres in de log' );
ok( false === strpos( $line, 'geheim' ) && false === strpos( $line, 'abcdefghijkl123' ), 'wachtwoord en bearer-token weg' );
$ctx = $lines[0][2];
eq( $ctx['api_key'], '[redacted]', 'api_key → [redacted]' );
eq( $ctx['email'], 'h:' . Logger::hash( 'klant@example.be' ), 'e-mail gehasht (genormaliseerd)' );
eq( $ctx['exception']['class'], 'RuntimeException', 'exception als klasse + boodschap' );
eq( $ctx['nested']['n'], 3, 'gewone waarden blijven' );

settings_override( array( 'admin' => array( 'logLevel' => 'debug' ) ) );
$lines = array();
eq( Logger::threshold(), 'debug', 'admin.logLevel=debug gelezen' );
eq( Logger::info( 'now_written' ), true, 'info geschreven bij debug' );
settings_override( array( 'admin' => array( 'logLevel' => 'onzin' ) ) );
eq( Logger::threshold(), 'error', 'ongeldig niveau → error' );
settings_override( array() );

Logger::set_writer( null );
eq( Logger::error( 'no_writer' ), false, 'zonder WooCommerce en zonder WP_DEBUG_LOG: niets geschreven (geen error_log)' );
if ( ! function_exists( 'wc_get_logger' ) ) {
	class BPS_T_WC_Logger {
		public $lines = array();
		public function log( $level, $message, $context = array() ) {
			$this->lines[] = array( $level, $message, $context );
		}
	}
	$GLOBALS['bps_wc_logger'] = new BPS_T_WC_Logger();
	function wc_get_logger() {
		return $GLOBALS['bps_wc_logger'];
	}
}
eq( Logger::error( 'uploads_selftest_failed', array( 'reason' => 'x' ) ), true, 'met WooCommerce: via wc_get_logger' );
eq( $GLOBALS['bps_wc_logger']->lines[0][2]['source'] ?? '', 'borduur-patch-studio', 'source = borduur-patch-studio' );
eq( $GLOBALS['bps_wc_logger']->lines[0][0], 'error', 'niveau doorgegeven' );

/* ======================================================================= */
section( 'Moduleregistry' );
$mods = Plugin::modules();
eq( array_keys( $mods ), array( 'ai', 'nametape', 'crosssell', 'services' ), '4 modules' );
eq( $mods['ai']['default'], false, 'ai standaard uit' );
eq( $mods['nametape']['default'] && $mods['crosssell']['default'] && $mods['services']['default'], true, 'andere standaard aan' );
eq( $mods['services']['requires'], array( 'ai' ), 'services vereist ai' );
eq( Plugin::module_enabled( 'nametape' ), true, 'nametape aan zonder instelling' );
eq( Plugin::module_enabled( 'services' ), false, 'services effectief uit zolang ai uit staat' );
ok( '' !== Plugin::module_label( 'ai' ) && '' !== Plugin::module_description( 'services' ), 'labels en beschrijvingen' );

// Test doubles for the modules (the real modules need WooCommerce and a database).
class BPS_T_Mod {
	public static $log = array();
	public static function hit( string $what ): void {
		static::$log[] = static::class . '::' . $what;
	}
}
foreach ( array( 'Ai', 'Nt', 'Cs', 'Svc' ) as $n ) {
	eval( "class BPS_T_Mod_$n extends BPS_T_Mod {
		public static function register(): void { self::hit('register'); }
		public static function register_always(): void { self::hit('register_always'); }
		public static function activate(): void { self::hit('activate'); }
		public static function deactivate(): void { self::hit('deactivate'); }
	}" );
}
add_filter(
	'bps_modules',
	function ( $m ) {
		$m['ai']['class']        = 'BPS_T_Mod_Ai';
		$m['nametape']['class']  = 'BPS_T_Mod_Nt';
		$m['crosssell']['class'] = 'BPS_T_Mod_Cs';
		$m['services']['class']  = 'BPS_T_Mod_Svc';
		return $m;
	}
);

// WooCommerce present (version adjustable).
$GLOBALS['fake_wc'] = (object) array( 'version' => '9.4.0' );
function WC() {
	return $GLOBALS['fake_wc'];
}
eq( Plugin::woocommerce_supported(), true, 'WC 9.4 ondersteund' );

// Boot with a minimal component list.
add_filter(
	'bps_components',
	function () {
		return array( 'BorduurPatchStudio\Settings', 'BorduurPatchStudio\Does_Not_Exist' );
	}
);
wp_schedule_event( time() + 60, 'daily', 'bps_ai_cleanup' ); // A leftover cron of the disabled module.
BPS_T_Mod::$log = array();
Plugin::boot();
ok( in_array( 'BPS_T_Mod_Ai::register_always', BPS_T_Mod::$log, true ), 'ai: register_always ook als uit' );
ok( ! in_array( 'BPS_T_Mod_Ai::register', BPS_T_Mod::$log, true ), 'ai: register NIET als uit (geen routes/cron/assets)' );
ok( in_array( 'BPS_T_Mod_Nt::register', BPS_T_Mod::$log, true ), 'nametape geregistreerd' );
ok( ! in_array( 'BPS_T_Mod_Svc::register', BPS_T_Mod::$log, true ), 'services niet geregistreerd (vereist ai)' );
ok( in_array( 'BorduurPatchStudio\Does_Not_Exist', Plugin::missing_components(), true ), 'ontbrekende component gemeld (niet stil overgeslagen)' );
ob_start();
Plugin::admin_notices();
$notice = ob_get_clean();
ok( false !== strpos( $notice, 'Does_Not_Exist' ), 'admin-notice noemt de ontbrekende component' );
Plugin::enforce_disabled_modules();
eq( wp_next_scheduled( 'bps_ai_cleanup' ), false, 'cron van de uitgeschakelde AI-module opgeruimd' );

$GLOBALS['bps_t']['options']['bps_modules_state'] = Plugin::module_states();
$r = Plugin::set_module_enabled( 'services', true );
ok( is_wp_error( $r ) && 'bps_module_requires' === $r->get_error_code(), 'services aanzetten zonder ai → WP_Error' );
BPS_T_Mod::$log = array();
$r = Plugin::set_module_enabled( 'ai', true );
eq( $r, true, 'ai aanzetten lukt' );
ok( in_array( 'BPS_T_Mod_Ai::activate', BPS_T_Mod::$log, true ), 'aanzetten roept activate() van ai aan' );
ok( in_array( 'BPS_T_Mod_Svc::activate', BPS_T_Mod::$log, true ), 'services (vereiste nu vervuld) ook geactiveerd' );
eq( count( array_keys( BPS_T_Mod::$log, 'BPS_T_Mod_Ai::activate', true ) ), 1, 'activate precies 1 keer' );
eq( Plugin::module_enabled( 'ai' ), true, 'ai staat aan' );
eq( Settings::get_path( 'modules.ai.enabled' ), true, 'modules.ai.enabled opgeslagen in bps_settings' );
BPS_T_Mod::$log = array();
Plugin::set_module_enabled( 'ai', false );
ok( in_array( 'BPS_T_Mod_Ai::deactivate', BPS_T_Mod::$log, true ) && in_array( 'BPS_T_Mod_Svc::deactivate', BPS_T_Mod::$log, true ), 'uitzetten: deactivate() van ai en van services' );
ok( ! is_wp_error( Plugin::set_module_enabled( 'nametape', false ) ) && ! Plugin::module_enabled( 'nametape' ), 'nametape uit' );
Plugin::set_module_enabled( 'nametape', true );

/* ======================================================================= */
section( 'Deactivatie: alle modules en alle bps_-crons' );
wp_schedule_event( time() + 60, 'daily', 'bps_cleanup' );
wp_schedule_event( time() + 60, 'daily', 'bps_whatever' );
wp_schedule_event( time() + 60, 'daily', 'other_plugin_hook' );
BPS_T_Mod::$log = array();
Plugin::deactivate();
foreach ( array( 'Ai', 'Nt', 'Cs', 'Svc' ) as $n ) {
	ok( in_array( "BPS_T_Mod_$n::deactivate", BPS_T_Mod::$log, true ), "deactivate() van $n (ook als de module uit staat)" );
}
eq( cron_hooks(), array( 'other_plugin_hook' ), 'geen enkele bps_-cron meer, andere crons blijven' );

/* ======================================================================= */
section( 'owned_data: één lijst die alle opties dekt' );
$owned = Plugin::owned_data();
foreach ( array( 'options', 'always_options', 'option_prefixes', 'always_option_patterns', 'tables', 'cron_hooks', 'as_groups', 'post_types', 'transient_designs', 'product_meta', 'page_meta', 'upload_dirs' ) as $k ) {
	ok( isset( $owned[ $k ] ) && is_array( $owned[ $k ] ), "owned_data[$k]" );
}
$listed = array_merge( $owned['options'], $owned['always_options'] );
foreach ( array( 'bps_ai_secrets', 'bps_ai_log', 'bps_license', 'bps_product_lock', 'bps_svc_products_lock' ) as $o ) {
	ok( in_array( $o, $owned['always_options'], true ), "$o wordt ALTIJD verwijderd" );
}
ok( ! in_array( 'bps_settings', $owned['always_options'], true ), 'bps_settings blijft zonder volledige wis' );
foreach ( array( 'bps_ai_cleanup', 'bps_svc_daily', 'bps_nt_cleanup', 'bps_cleanup' ) as $h ) {
	ok( in_array( $h, $owned['cron_hooks'], true ), "cron $h in de lijst" );
}
eq( $owned['tables'], array( 'bps_ai_owners', 'bps_ai_jobs', 'bps_ai_costs', 'bps_ai_lots', 'bps_ai_ledger' ), '5 AI-/credittabellen' );
ok( in_array( 'bps-ai', $owned['as_groups'], true ), 'AS-groep bps-ai' );
ok( in_array( '_bps_created_page', $owned['page_meta'], true ), 'pagina-meta _bps_created_page' );

// Every option name the code uses (literal or via a const) is listed explicitly.
$used = array();
$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin . 'includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( 'php' !== $f->getExtension() ) {
		continue;
	}
	$src = file_get_contents( $f->getPathname() );
	preg_match_all( "/(?:get|update|add|delete)_option\(\s*'(bps_[a-z0-9_]+)'/", $src, $m1 );
	preg_match_all( "/const\s+(?:OPTION|[A-Z_]*_OPTION|[A-Z_]*_OPT|LOG_OPTION|ALERTS|REFUSALS|NOTICE|MODULES_STATE|UPGRADE_LOCK)\s*=\s*'(bps_[a-z0-9_]+)'/", $src, $m2 );
	$used = array_merge( $used, $m1[1], $m2[1] );
}
$used = array_values( array_unique( $used ) );
ok( count( $used ) >= 20, 'optienamen in de code gevonden (' . count( $used ) . ')' );
foreach ( $used as $o ) {
	ok( in_array( $o, $listed, true ), "optie $o staat in owned_data (options of always_options)" );
}
add_filter(
	'bps_owned_data',
	function ( $d ) {
		$d['options'][] = 'bps_extra_from_filter';
		return $d;
	}
);
ok( in_array( 'bps_extra_from_filter', Plugin::owned_data()['options'], true ), 'filter bps_owned_data breidt uit' );

/* ======================================================================= */
section( 'Uninstall (aparte processen)' );
$php = escapeshellarg( PHP_BINARY );
foreach ( array( 'nowipe', 'wipe' ) as $mode ) {
	$out = shell_exec( $php . ' ' . escapeshellarg( __DIR__ . '/uninstall-scenario.php' ) . ' ' . $mode . ' 2>&1' );
	$res = json_decode( (string) $out, true );
	ok( is_array( $res ), "$mode: scenario gaf JSON" . ( is_array( $res ) ? '' : ' — ' . substr( (string) $out, 0, 400 ) ) );
	if ( ! is_array( $res ) ) {
		continue;
	}
	$bps_opts = array_values( array_filter( $res['options'], function ( $o ) {
		return 0 === strpos( $o, 'bps_' ) || 0 === strpos( $o, 'woocommerce_bps_' ) || false !== strpos( $o, 'transient_bps_' );
	} ) );
	$bps_cron = array_values( array_filter( $res['cron'], function ( $h ) {
		return 0 === strpos( $h, 'bps_' );
	} ) );
	eq( $bps_cron, array(), "$mode: 0 bps_-crons" );
	ok( in_array( array( '', 'bps-ai' ), $res['as'], true ), "$mode: AS-groep bps-ai leeggemaakt" );
	ok( ! in_array( 'bps_ai_secrets', $res['options'], true ) && ! in_array( 'bps_ai_log', $res['options'], true ) && ! in_array( 'bps_license', $res['options'], true ), "$mode: API-sleutel, AI-log en licentie weg" );
	ok( ! in_array( 'woocommerce_bps_proof_settings', $res['options'], true ), "$mode: woocommerce_bps_*_settings weg" );
	ok( in_array( 'woocommerce_bacs_settings', $res['options'], true ), "$mode: andere WC-opties blijven" );
	foreach ( array( 'draft', 'carted', 'saved', 'nostatus' ) as $s ) {
		ok( ! $res['designs'][ $s ]['post'] && ! $res['designs'][ $s ]['file'], "$mode: ontwerp $s + origineel weg" );
	}
	ok( $res['foreign'] && $res['owner_page'] && $res['foreign_file'], "$mode: niets van anderen verwijderd" );
	if ( 'nowipe' === $mode ) {
		eq( $res['designs']['ordered'], array( 'post' => true, 'file' => true ), 'nowipe: besteld ontwerp blijft' );
		eq( $res['designs']['quoted'], array( 'post' => true, 'file' => true ), 'nowipe: offerte-ontwerp blijft' );
		ok( in_array( 'bps_settings', $res['options'], true ), 'nowipe: bps_settings blijft' );
		ok( in_array( 'wp_bps_ai_ledger', $res['tables'], true ), 'nowipe: tabellen blijven' );
		ok( $res['patch'] && $res['quote'] && $res['own_page'], 'nowipe: product, offerte en pagina blijven' );
	} else {
		eq( $bps_opts, array(), 'wipe: 0 bps-opties' );
		eq( $res['tables'], array( 'wp_wc_orders' ), 'wipe: 0 bps-tabellen' );
		ok( ! $res['patch'] && ! $res['nt_product'], 'wipe: producten met plugin-meta weg' );
		ok( ! $res['own_page'], 'wipe: pagina met _bps_created_page weg' );
		ok( ! $res['uploads_dir'], 'wipe: uploads/patch-studio weg' );
		ok( ! $res['designs']['ordered']['post'] && ! $res['quote'], 'wipe: alle ontwerpen en offertes weg' );
	}
}

/* ======================================================================= */
section( 'Upgrades' );
$GLOBALS['bps_t']['options']['bps_version'] = '0.9.0';
$GLOBALS['bps_t']['options']['bps_ai_log']  = array( array( 'msg' => 'oud' ) );
$runs = 0;
add_filter(
	'bps_upgrade_steps',
	function ( $steps ) use ( &$runs ) {
		$steps['1.0.0'] = function () use ( &$runs ) {
			++$runs;
			delete_option( 'bps_ai_log' );
		};
		$steps['0.5.0'] = function () use ( &$runs ) {
			$runs += 100; // Older than the stored version: must never run.
		};
		$steps['9.9.9'] = function () use ( &$runs ) {
			$runs += 1000; // Newer than BPS_VERSION: must not run yet.
		};
		return $steps;
	}
);
$GLOBALS['bps_t']['did']['bps_upgraded'] = 0;
eq( Plugin::maybe_upgrade(), true, 'eerste maybe_upgrade draait stappen' );
eq( Plugin::maybe_upgrade(), false, 'tweede maybe_upgrade doet niets' );
eq( $runs, 1, 'stap 1.0.0 precies 1 keer, oudere en nieuwere niet' );
eq( get_option( 'bps_version' ), BPS_VERSION, 'bps_version === BPS_VERSION' );
eq( $GLOBALS['bps_t']['autoload']['bps_version'], false, 'bps_version autoload uit' );
eq( did_action( 'bps_upgraded' ), 1, 'bps_upgraded 1 keer' );
eq( end( $GLOBALS['bps_t']['did_args']['bps_upgraded'] ), array( '0.9.0', '1.0.0' ), 'bps_upgraded( from, to )' );
eq( get_option( 'bps_ai_log', 'weg' ), 'weg', 'bps_ai_log bestaat niet meer na de upgrade' );
eq( get_option( Plugin::UPGRADE_LOCK, 'vrij' ), 'vrij', 'lock vrijgegeven' );
// Lock held by another request.
$GLOBALS['bps_t']['options']['bps_version']      = '0.9.0';
$GLOBALS['bps_t']['options'][ Plugin::UPGRADE_LOCK ] = time();
$runs = 0;
eq( Plugin::maybe_upgrade(), false, 'lock van een ander verzoek: niets gedaan' );
eq( $runs, 0, 'geen stap onder een vreemde lock' );
$GLOBALS['bps_t']['options'][ Plugin::UPGRADE_LOCK ] = time() - 3600;
eq( Plugin::maybe_upgrade(), true, 'verlopen lock wordt overgenomen' );
eq( $runs, 1, 'stap draait na verlopen lock' );
remove_all_filters( 'bps_upgrade_steps' );
// The real default step removes the AI log.
$GLOBALS['bps_t']['options']['bps_version'] = '0.9.0';
$GLOBALS['bps_t']['options']['bps_ai_log']  = array( 1 );
Plugin::maybe_upgrade();
eq( get_option( 'bps_ai_log', 'weg' ), 'weg', 'standaardstap 1.0.0 verwijdert bps_ai_log' );

/* ======================================================================= */
section( 'WooCommerce-versie' );
$GLOBALS['fake_wc']->version = '8.7.0';
eq( Integration::wc_version_ok(), false, 'WC 8.7 te oud' );
eq( Plugin::woocommerce_supported(), false, 'Plugin: niet ondersteund' );
$before = count_hooks();
Integration::register();
eq( count_hooks() - $before, 1, 'Integration registreert niets behalve de admin-notice' );
ok( false !== has_action( 'admin_notices', array( Integration::class, 'version_notice' ) ), 'notice ingehaakt' );
ob_start();
Integration::version_notice();
$n = ob_get_clean();
ok( false !== strpos( $n, '8.8' ) && false !== strpos( $n, '8.7.0' ), 'notice noemt minimum en huidige versie' );
remove_all_filters( 'bps_components' );
$comps = Plugin::components();
ok( ! in_array( 'BorduurPatchStudio\Woo\Integration', $comps, true ) && in_array( 'BorduurPatchStudio\Settings', $comps, true ), 'met WC 8.7 enkel de kerncomponenten' );
$GLOBALS['fake_wc']->version = '11.3.0';
eq( Integration::wc_version_ok(), true, 'WC 11.3 ok' );
$header = file_get_contents( BPS_FILE );
ok( preg_match( '/WC tested up to:\s*11\.3/', $header ) && preg_match( '/Requires Plugins:\s*woocommerce/', $header ), 'header: WC tested up to 11.3 + Requires Plugins' );
eq( preg_match_all( '/^\s*\*\s*Update URI:/m', $header ), 1, "header: 'Update URI' precies één keer" );
ok( preg_match( '/Update URI:\s*' . preg_quote( Client::UPDATE_URI, '/' ) . '\s*$/m', $header ), 'Update URI = Client::UPDATE_URI' );
ok( preg_match( '/Plugin Name:\s*Patch Studio\s*$/m', $header ), 'neutrale pluginnaam' );
ok( preg_match( '/WC requires at least:\s*' . preg_quote( Integration::MIN_WC, '/' ) . '/', $header ), 'WC requires at least = Integration::MIN_WC' );

/* ======================================================================= */
section( 'Licentie: standaard uit, 0 requests' );
$GLOBALS['bps_t']['http']  = array();
$GLOBALS['bps_t']['is_admin'] = true;
$spy = 0;
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$spy ) {
		if ( 0 === strpos( $url, BPS_UPDATE_URL ) ) {
			++$spy;
		}
		return $pre;
	},
	10,
	3
);
eq( Client::has_key(), false, 'geen sleutel' );
eq( Client::ui_enabled(), false, 'licentiescherm verborgen' );
for ( $i = 0; $i < 10; $i++ ) {
	Client::register();
	do_action( 'admin_init' );
	do_action( 'admin_menu' );
	apply_filters( 'update_plugins_' . Client::update_hostname(), false, array(), Client::basename(), array() );
	apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => Client::SLUG ) );
}
do_action( 'wp_update_plugins' ); // One cron run.
eq( $spy, 0, '0 requests naar BPS_UPDATE_URL over 10 admin-pageloads en 1 cron-run' );
eq( has_filter( 'update_plugins_' . Client::update_hostname() ), false, 'geen update-filter zonder sleutel' );
eq( has_filter( 'upgrader_pre_download' ), false, 'geen pre_download-filter zonder sleutel' );
eq( array_filter( cron_hooks(), function ( $h ) {
	return false !== strpos( $h, 'licen' );
} ), array(), 'geen licentie-cron' );

section( 'Licentie: met sleutel en nep-driver' );
class BPS_T_Driver implements Driver {
	public $status = 'valid';
	public $release = null;
	public $calls = 0;
	public $fail = false;
	public function activate( string $key, string $site ) {
		++$this->calls;
		return array( 'status' => $this->status, 'expires' => '2027-11-18' );
	}
	public function deactivate( string $key, string $site ) {
		++$this->calls;
		return array( 'status' => 'inactive' );
	}
	public function check( string $key, string $site ) {
		++$this->calls;
		return array( 'status' => $this->status );
	}
	public function update_info( string $key, string $site, string $version ) {
		++$this->calls;
		if ( $this->fail ) {
			return new WP_Error( 'down', 'server down' );
		}
		return 'valid' === $this->status ? $this->release : null;
	}
}
$keys   = sodium_crypto_sign_keypair();
define( 'BPS_UPDATE_PUBLIC_KEY', base64_encode( sodium_crypto_sign_publickey( $keys ) ) );
$zip    = 'PK fake zip bytes ' . str_repeat( 'x', 2000 );
$sig    = base64_encode( sodium_crypto_sign_detached( $zip, sodium_crypto_sign_secretkey( $keys ) ) );
$driver = new BPS_T_Driver();
$driver->release = array(
	'version'   => '1.1.0',
	'package'   => 'https://borduurservicebelgie.be/downloads/borduur-patch-studio-1.1.0.zip',
	'signature' => $sig,
	'tested'    => '6.8',
	'changelog' => '<p>Nieuw</p><script>alert(1)</script>',
);
Client::set_driver( $driver );
ok( is_wp_error( Client::save_key( 'x' ) ), 'ongeldige sleutel geweigerd' );
$saved = Client::save_key( ' ABCD-1234-EFGH-5678 ' );
ok( is_array( $saved ) && 'valid' === $saved['status'], 'sleutel opgeslagen + geactiveerd' );
eq( $GLOBALS['bps_t']['autoload'][ Client::OPTION ], false, 'licentie-optie autoload uit' );
eq( Client::masked_key(), '••••••••5678', 'sleutel gemaskeerd' );
Client::register();
ok( false !== has_filter( 'update_plugins_borduurservicebelgie.be', array( Client::class, 'update_plugins' ) ), 'update_plugins_{host} ingehaakt met sleutel' );
// Emulate wp_update_plugins(): the filter result goes into the transient when the version is newer.
$transient           = (object) array( 'response' => array(), 'no_update' => array() );
$update              = apply_filters( 'update_plugins_borduurservicebelgie.be', false, array( 'Version' => BPS_VERSION ), Client::basename(), array() );
if ( is_array( $update ) && version_compare( $update['version'], BPS_VERSION, '>' ) ) {
	$transient->response[ Client::basename() ] = (object) $update;
}
ok( isset( $transient->response[ Client::basename() ] ), 'nep-driver zet een item in update_plugins->response' );
eq( $transient->response[ Client::basename() ]->version ?? '', '1.1.0', 'versie 1.1.0' );
$calls = $driver->calls;
Client::update_info();
eq( $driver->calls, $calls, 'release-info 12 u gecachet (geen nieuwe call)' );
$api = Client::plugins_api( false, 'plugin_information', (object) array( 'slug' => Client::SLUG ) );
ok( is_object( $api ) && false === strpos( $api->sections['changelog'], '<script' ), 'Details bekijken: changelog door wp_kses_post' );

eq( Client::sanitize_release( array_merge( $driver->release, array( 'package' => 'http://borduurservicebelgie.be/a.zip' ) ) ), null, 'http-pakket geweigerd' );
eq( Client::sanitize_release( array_merge( $driver->release, array( 'package' => 'https://evil.example/a.zip' ) ) ), null, 'pakket op andere host geweigerd' );
eq( Client::sanitize_release( array_merge( $driver->release, array( 'signature' => 'kort' ) ) ), null, 'handtekening van verkeerde lengte geweigerd' );

// Signature check in upgrader_pre_download.
$tmpdir = sys_get_temp_dir() . '/bps-core-dl-' . getmypid();
@mkdir( $tmpdir );
$served = $zip;
Client::set_downloader(
	function ( $url ) use ( &$served, $tmpdir ) {
		$f = $tmpdir . '/pkg-' . mt_rand() . '.zip';
		file_put_contents( $f, $served );
		return $f;
	}
);
$res = Client::pre_download( false, $driver->release['package'], null, array( 'plugin' => Client::basename() ) );
ok( is_string( $res ) && is_file( $res ), 'geldige handtekening → pad naar de zip' );
$served = $zip . 'gewijzigd';
$res2   = Client::pre_download( false, $driver->release['package'], null, array( 'plugin' => Client::basename() ) );
ok( is_wp_error( $res2 ) && 'bps_update_bad_signature' === $res2->get_error_code(), 'gewijzigde zip → WP_Error' );
eq( count( glob( $tmpdir . '/pkg-*.zip' ) ), 1, 'ongeldige download meteen gewist' );
$res3 = Client::pre_download( false, 'https://borduurservicebelgie.be/other.zip', null, array( 'plugin' => Client::basename() ) );
ok( is_wp_error( $res3 ), 'onbekend pakket-URL → WP_Error' );
eq( Client::pre_download( false, 'https://downloads.wordpress.org/plugin/x.zip', null, array( 'plugin' => 'x/x.php' ) ), false, 'pakketten van anderen ongemoeid' );
array_map( 'unlink', glob( $tmpdir . '/*' ) );
rmdir( $tmpdir );

// Expired licence: no updates, nothing else changes.
$driver->status = 'expired';
Client::check_now();
eq( Client::data()['status'], 'expired', 'status verlopen opgeslagen' );
eq( Client::update_info(), null, 'verlopen licentie: geen update' );
eq( Plugin::module_enabled( 'nametape' ), true, 'verlopen licentie raakt geen winkelfuncties' );
// Back-off.
$driver->status = 'valid';
$driver->fail   = true;
Client::forget_cache();
delete_transient( Client::BACKOFF );
Client::update_info();
$calls = $driver->calls;
Client::update_info();
eq( $driver->calls, $calls, 'backoff na een fout: geen nieuwe call' );
ok( is_array( get_transient( Client::BACKOFF ) ), 'backoff-transient gezet' );

// Real driver: HTTPS, sslverify, timeout 10, minimal data.
Client::set_driver( null );
delete_transient( Client::BACKOFF );
Client::forget_cache();
$GLOBALS['bps_t']['http'] = array();
Client::update_info();
$req = end( $GLOBALS['bps_t']['http'] );
ok( is_array( $req ) && 0 === strpos( $req['url'], 'https://borduurservicebelgie.be/wp-json/bps-license/v1/update' ), 'WC-store-driver: HTTPS-endpoint op de update-host' );
eq( $req['args']['sslverify'] ?? null, true, 'sslverify true' );
eq( $req['args']['timeout'] ?? null, 10, 'timeout 10 s' );
eq( array_keys( $req['args']['body'] ?? array() ), array( 'key', 'site', 'slug', 'version' ), 'enkel sleutel, site, slug, versie' );
$checks = Client::status_check( array() );
ok( false === strpos( wp_json_encode( $checks ), 'ABCD-1234' ), 'statusrij bevat de sleutel niet' );
Client::remove_key();
eq( Client::has_key(), false, 'sleutel verwijderd' );
$GLOBALS['bps_t']['is_admin'] = false;

/* ======================================================================= */
section( 'Site Health en WC-statusrapport' );
settings_override( array( 'woo' => array( 'maxUploadMb' => 25 ) ) );
$data = System_Status::data();
foreach ( array( 'version', 'db_version', 'ai_mode', 'ai_key', 'modules', 'wp_cron_disabled', 'upload_max_filesize', 'gd', 'imagick' ) as $k ) {
	ok( isset( $data[ $k ] ), "statusveld $k" );
}
eq( $data['ai_key']['value'], 'ja', 'API-sleutel: ja (via constante)' );
ob_start();
System_Status::wc_report();
$report = ob_get_clean();
ok( false !== strpos( $report, 'data-export-label="Patch Studio"' ), 'WC-rapport: sectie Patch Studio (### Patch Studio ###)' );
ok( false !== strpos( $report, 'API-sleutel' ), 'WC-rapport: API-sleutel-regel' );
ok( ! preg_match( '/sk-[A-Za-z0-9]{10,}/', $report . wp_json_encode( $data ) ), 'rapport bevat geen sk-sleutel' );
$info = System_Status::debug_information( array() );
ok( isset( $info['borduur-patch-studio']['fields']['version'] ), 'Site Health > Info: sectie Patch Studio' );
$tests = System_Status::site_status_tests( array() );
eq( array_keys( $tests['direct'] ), array( 'bps_uploads_protected', 'bps_cron_running', 'bps_upload_limit' ), '3 Site Health-tests' );
$limit = System_Status::test_upload_limit();
if ( wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ) < 25 * MB_IN_BYTES ) {
	eq( $limit['status'], 'recommended', 'upload_max_filesize ' . ini_get( 'upload_max_filesize' ) . ' < 25 MB → aanbeveling' );
} else {
	eq( $limit['status'], 'good', 'uploadlimiet volstaat' );
}
$cron = System_Status::test_cron_running();
ok( in_array( $cron['status'], array( 'good', 'recommended' ), true ), 'crontest geeft een status' );
set_transient( 'bps_uploads_selftest', array( 'ok' => false, 'message' => 'publiek' ) );
eq( System_Status::test_uploads_protected()['status'], 'critical', 'onbeschermde uploads → kritiek' );

/* ======================================================================= */
section( 'Gerichte cache-purge' );
$calls = array();
function rocket_clean_post( $id ) {
	$GLOBALS['purge'][] = 'rocket_clean_post:' . $id;
}
function rocket_clean_domain() {
	$GLOBALS['purge'][] = 'rocket_clean_domain';
}
function wp_cache_clear_cache() {
	$GLOBALS['purge'][] = 'wp_cache_clear_cache';
}
function w3tc_flush_all() {
	$GLOBALS['purge'][] = 'w3tc_flush_all';
}
function w3tc_flush_post( $id ) {
	$GLOBALS['purge'][] = 'w3tc_flush_post:' . $id;
}
function wpsc_delete_post_cache( $id ) {
	$GLOBALS['purge'][] = 'wpsc_delete_post_cache:' . $id;
}
add_action( 'litespeed_purge_all', function () {
	$GLOBALS['purge'][] = 'litespeed_purge_all';
} );
add_action( 'litespeed_purge_post', function ( $id ) {
	$GLOBALS['purge'][] = 'litespeed_purge_post:' . $id;
} );
$full_calls = array( 'rocket_clean_domain', 'wp_cache_clear_cache', 'w3tc_flush_all', 'litespeed_purge_all' );
add_filter( 'bps_studio_page_ids', function () {
	return array( 12, 34 );
} );
$GLOBALS['purge'] = array();
do_action( 'bps_settings_updated', Settings::get() );
eq( array_values( array_intersect( $GLOBALS['purge'], $full_calls ) ), array(), 'instellingen opslaan met studiolijst: 0 volledige purges' );
ok( in_array( 'rocket_clean_post:12', $GLOBALS['purge'], true ) && in_array( 'rocket_clean_post:34', $GLOBALS['purge'], true ), 'rocket_clean_post per studiopagina' );
ok( in_array( 'litespeed_purge_post:12', $GLOBALS['purge'], true ) && in_array( 'w3tc_flush_post:34', $GLOBALS['purge'], true ) && in_array( 'wpsc_delete_post_cache:12', $GLOBALS['purge'], true ), 'LiteSpeed, W3TC en WP Super Cache per pagina' );
eq( did_action( 'bps_purge_studio_cache' ) > 0, true, 'bps_purge_studio_cache gevuurd' );
remove_all_filters( 'bps_studio_page_ids' );
add_filter( 'bps_studio_page_ids', function () {
	return array();
} );
$GLOBALS['purge'] = array();
eq( Plugin::purge_page_cache(), 'full', 'zonder studiolijst: volledige purge' );
eq( array_values( array_intersect( $GLOBALS['purge'], $full_calls ) ), $full_calls, 'alle volledige purges' );
remove_all_filters( 'bps_studio_page_ids' );
add_filter( 'bps_studio_page_ids', function () {
	return array( 12 );
} );
$GLOBALS['purge'] = array();
eq( Plugin::purge_page_cache( true ), 'full', 'expliciete knop: volledige purge' );
remove_all_filters( 'bps_studio_page_ids' );

/* ======================================================================= */
section( 'Taalbundel per locale' );
$nl = Plugin::app_bundle( 'nl_BE' );
$fr = Plugin::app_bundle( 'fr_BE' );
if ( is_readable( $plugin . 'assets/dist/nl/patch-studio.js' ) ) {
	eq( $nl, 'assets/dist/nl/patch-studio.js', 'nl_BE → NL-bundel' );
	eq( $fr, 'assets/dist/fr/patch-studio.js', 'fr_BE → FR-bundel' );
	eq( Plugin::app_bundle( 'fr_FR' ), 'assets/dist/fr/patch-studio.js', 'fr_FR → FR-bundel' );
} else {
	eq( $nl, 'assets/dist/patch-studio.js', 'zonder taalbuild: tweetalige bundel' );
}
add_filter( 'bps_app_bundle_lang', function () {
	return 'all';
} );
eq( Plugin::app_bundle( 'fr_BE' ), 'assets/dist/patch-studio.js', "filter 'all' → tweetalige bundel" );

/* ======================================================================= */
echo "\n" . ( $GLOBALS['count'] - $GLOBALS['fails'] ) . '/' . $GLOBALS['count'] . " ok\n";
exit( $GLOBALS['fails'] ? 1 : 0 );
