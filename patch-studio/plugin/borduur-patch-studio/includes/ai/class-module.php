<?php
/**
 * AI module bootstrap: hooks, install, cron, public config, store, legal config and a redacted admin log.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §2, §16. Module `ai` of the registry in Plugin::modules() (switch `modules.ai.enabled`,
 * default off). register_always() runs even when the module is off (privacy exporters/erasers and the cleanup
 * handler); register() only when it is on. Activation/deactivation is driven by Plugin (activate/deactivate()).
 *
 * In mode `off` only the admin page, the status route (answers 403 bps_ai_off), cleanup and privacy run.
 */
final class Module {

	const CRON       = 'bps_ai_cleanup';
	const RUN_ACTION = 'bps_ai_run_job';
	const AS_GROUP   = 'bps-ai';
	const LOG_OPTION = 'bps_ai_log';
	const SCRIPT     = 'bps-ai';

	/** @var bool */
	private static $registered = false;

	/** @var bool */
	private static $registered_always = false;

	/** @var Store|null */
	private static $store = null;

	/** @var array|null */
	private static $legal = null;

	/** @var array|null */
	private static $prompt_cfg = null;

	/**
	 * Hooks that must exist even when the module is switched off: customers can still export/erase their AI data,
	 * and a cleanup event that is still queued removes expired data instead of failing.
	 */
	public static function register_always(): void {
		if ( self::$registered_always ) {
			return;
		}
		self::$registered_always = true;
		Privacy::register();
		Legal_Display::register();
		add_action( self::CRON, array( Cleanup::class, 'run' ) );
	}

	/**
	 * Register hooks (called once by Plugin::boot() when the module is on).
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		self::register_always();
		require_once __DIR__ . '/class-ai-prompt-engine.php';

		add_action( 'init', array( Schema::class, 'maybe_install' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_script' ) );
		add_action( 'init', array( __CLASS__, 'ensure_cron' ), 20 );
		add_action( 'rest_api_init', array( Rest::class, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( Rest::class, 'no_store' ), 10, 3 );
		add_action( self::RUN_ACTION, array( Job_Runner::class, 'run_action' ), 10, 1 );
		add_action( 'wp_login', array( Identity::class, 'on_login' ), 10, 2 );
		add_filter( 'bps_public_config', array( __CLASS__, 'public_config' ), 20, 2 );
		add_action( 'bps_design_created', array( Design_Link::class, 'on_design_created' ), 10, 2 );
		add_action( 'bps_design_ordered', array( Design_Link::class, 'on_design_ordered' ), 10, 3 );
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'rocket_exclusions' ) );
		Order_Review::register();
		if ( is_admin() ) {
			Admin::register();
		}
	}

	/**
	 * Module activation (plugin activation with the module on, or switching the module on): tables + cron.
	 */
	public static function activate(): void {
		Schema::install();
		self::ensure_cron();
	}

	/**
	 * Module/plugin deactivation: stop the cron and queued background jobs (data stays).
	 */
	public static function deactivate(): void {
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( self::CRON );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::AS_GROUP );
		}
	}

	/**
	 * Daily cleanup at 03:40 site time (outside the busy morning hours).
	 */
	public static function ensure_cron(): void {
		if ( wp_next_scheduled( self::CRON ) ) {
			return;
		}
		$tz   = Cost_Log::timezone();
		$next = new \DateTimeImmutable( 'tomorrow 03:40', $tz );
		wp_schedule_event( $next->getTimestamp(), 'daily', self::CRON );
	}

	/**
	 * Full removal of the module's data (uninstall.php with "Alles verwijderen"; also usable from WP-CLI).
	 * uninstall.php works from Plugin::owned_data() as well, so this stays in sync with that list.
	 */
	public static function uninstall(): void {
		self::deactivate();
		Schema::drop();
		foreach ( array( AI_Settings::OPTION, Secrets::OPTION, Limits::ALERTS, Limits::REFUSALS, self::LOG_OPTION, 'bps_svc_products' ) as $opt ) {
			delete_option( $opt );
		}
		delete_transient( Limits::RESERVATIONS );
		delete_transient( Cost_Log::CACHE );
		$base = Image_Store::base_dir();
		if ( is_dir( $base ) ) {
			self::rrmdir( $base );
		}
	}

	/**
	 * Register (never enqueue) the lazily loaded front-end bundle.
	 */
	public static function register_script(): void {
		$rel = 'assets/dist/patch-studio-ai.js';
		$ver = defined( 'BPS_DIR' ) && is_readable( BPS_DIR . $rel ) ? (string) filemtime( BPS_DIR . $rel ) : ( defined( 'BPS_VERSION' ) ? BPS_VERSION : '1' );
		wp_register_script( self::SCRIPT, self::bundle_url(), array(), $ver, array( 'in_footer' => true ) );
	}

	/**
	 * URL of the AI bundle.
	 */
	public static function bundle_url(): string {
		return ( defined( 'BPS_URL' ) ? BPS_URL : plugins_url( '/', dirname( __DIR__ ) ) ) . 'assets/dist/patch-studio-ai.js';
	}

	/**
	 * WP Rocket must not delay the lazily injected bundle.
	 *
	 * @param mixed $list Exclusions.
	 */
	public static function rocket_exclusions( $list ): array {
		$list   = is_array( $list ) ? $list : array();
		$list[] = 'patch-studio-ai.js';
		return $list;
	}

	/**
	 * `config.ai` for the studio (filter bps_public_config). Absent in mode off, and in mode test for non-admins.
	 * Never contains keys, prompts, safety lists or English fragments.
	 *
	 * @param mixed  $config Public config.
	 * @param string $locale Locale.
	 * @return mixed
	 */
	public static function public_config( $config, $locale = 'nl' ) {
		if ( ! is_array( $config ) || ! self::visible() ) {
			return $config;
		}
		$lang = 0 === strpos( strtolower( (string) $locale ), 'fr' ) ? 'fr' : 'nl';
		$cfg  = self::prompt_cfg();
		$lim  = (array) ( $cfg['limits'] ?? array() );

		$styles = array();
		foreach ( (array) ( $cfg['styles'] ?? array() ) as $id => $st ) {
			if ( ! is_array( $st ) || '_' === substr( (string) $id, 0, 1 ) ) {
				continue;
			}
			$styles[] = array(
				'id'    => (string) $id,
				'label' => (string) ( $st['label'][ $lang ] ?? $st['label']['nl'] ?? $id ),
				'hint'  => (string) ( $st['hint'][ $lang ] ?? $st['hint']['nl'] ?? '' ),
			);
		}
		$legal   = self::legal();
		$strings = array();
		foreach ( (array) ( $legal['strings'] ?? array() ) as $key => $s ) {
			if ( is_array( $s ) && 0 === strpos( (string) $key, 'legal.ai.' ) && isset( $s[ $lang ] ) ) {
				$strings[ $key ] = (string) $s[ $lang ];
			}
		}
		$provider       = Providers\Provider_Registry::primary();
		$config['ai']   = array(
			'mode'          => AI_Settings::mode(),
			'bundleUrl'     => self::bundle_url(),
			'bundleVer'     => defined( 'BPS_VERSION' ) ? BPS_VERSION : '1',
			'providerLabel' => $provider->label(),
			'testMode'      => 'mock' === $provider->id(),
			'n'             => (int) AI_Settings::clamp( 'n', AI_Settings::path( 'n', 3 ) ),
			'refineN'       => (int) AI_Settings::clamp( 'refineN', AI_Settings::path( 'refineN', 2 ) ),
			'styles'        => $styles,
			'limits'        => array(
				'descriptionMaxChars' => (int) ( $lim['descriptionMaxChars'] ?? 400 ),
				'refineMaxChars'      => (int) ( $lim['refineMaxChars'] ?? 200 ),
				'textMaxChars'        => 60,
			),
			'legal'         => $strings,
			'legalVersion'  => (string) ( $legal['version'] ?? '' ),
			'prefetch'      => 'live' === AI_Settings::mode(),
			'restPath'      => Rest::NS . '/ai',
		);
		return $config;
	}

	/**
	 * The AI studio is visible to the current visitor.
	 */
	public static function visible(): bool {
		$mode = AI_Settings::mode();
		if ( 'live' === $mode ) {
			return true;
		}
		return 'test' === $mode && self::is_manager();
	}

	/**
	 * Current user may manage the shop (test mode, admin page).
	 */
	public static function is_manager(): bool {
		$cap = class_exists( '\BorduurPatchStudio\Plugin' ) && method_exists( '\BorduurPatchStudio\Plugin', 'capability' ) ? (string) \BorduurPatchStudio\Plugin::capability() : 'manage_woocommerce';
		return function_exists( 'current_user_can' ) && ( current_user_can( $cap ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) );
	}

	/**
	 * Persistence (wpdb in production; replaceable in tests).
	 */
	public static function store(): Store {
		if ( null === self::$store ) {
			self::$store = new Store_Wpdb();
		}
		return self::$store;
	}

	/**
	 * Replace the store (tests) or reset (null).
	 *
	 * @param Store|null $store Store.
	 */
	public static function set_store( ?Store $store ): void {
		self::$store = $store;
	}

	/**
	 * config/ai-legal.json (cached).
	 */
	public static function legal(): array {
		if ( null === self::$legal ) {
			self::$legal = AI_Settings::read_json( 'ai-legal.json' );
		}
		return self::$legal;
	}

	/**
	 * config/ai-prompts.json via the prompt engine (cached).
	 */
	public static function prompt_cfg(): array {
		if ( null === self::$prompt_cfg ) {
			if ( ! class_exists( Prompt_Engine::class, false ) ) {
				require_once __DIR__ . '/class-ai-prompt-engine.php';
			}
			self::$prompt_cfg = Prompt_Engine::load_config( AI_Settings::config_dir() . 'ai-prompts.json' );
		}
		return self::$prompt_cfg;
	}

	/**
	 * Studio defaults (config/defaults.json merged with the shop settings) for the brief.
	 */
	public static function studio_defaults(): array {
		if ( class_exists( '\BorduurPatchStudio\Settings' ) && method_exists( '\BorduurPatchStudio\Settings', 'get' ) ) {
			$s = \BorduurPatchStudio\Settings::get();
			if ( is_array( $s ) ) {
				return $s;
			}
		}
		return AI_Settings::read_json( 'defaults.json' );
	}

	/**
	 * Log a problem of the AI module through the central Logger (WC > Status > Logs, source borduur-patch-studio).
	 * Every caller reports a failure, so the level is `error` (visible with the default threshold). The message is redacted by Secrets::redact() and
	 * the Logger (keys, e-mail addresses, IPs). Replaces the former option ring buffer `bps_ai_log`.
	 *
	 * @param string $topic   Topic (provider, credits, moderation, ...).
	 * @param string $message Message.
	 */
	public static function log( string $topic, string $message ): void {
		$topic = substr( (string) preg_replace( '/[^a-z_]/', '', strtolower( $topic ) ), 0, 20 );
		\BorduurPatchStudio\Logger::error(
			'ai_' . ( '' === $topic ? 'general' : $topic ),
			array( 'message' => Secrets::redact( $message ) )
		);
	}

	/**
	 * Recent log lines, newest first. The log now lives in WC > Status > Logs; this only returns lines left in
	 * the legacy option until the 1.0.0 upgrade step removes it.
	 *
	 * @param int $n Lines.
	 */
	public static function recent_log( int $n = 10 ): array {
		$log = get_option( self::LOG_OPTION, array() );
		return array_slice( array_reverse( is_array( $log ) ? $log : array() ), 0, $n );
	}

	/**
	 * Data owned by the module (merged into Plugin::owned_data(), also during uninstall).
	 *
	 * @return array<string, string[]>
	 */
	public static function owned_data(): array {
		return array(
			'always_options' => array( Secrets::OPTION, self::LOG_OPTION ),
			'options'        => array( AI_Settings::OPTION, Schema::VERSION_OPTION, Limits::ALERTS, Limits::REFUSALS ),
			'tables'         => array( 'bps_ai_owners', 'bps_ai_jobs', 'bps_ai_costs', 'bps_ai_lots', 'bps_ai_ledger' ),
			'cron_hooks'     => array( self::CRON ),
			'as_groups'      => array( self::AS_GROUP ),
		);
	}

	/**
	 * Reset caches (tests, after settings writes).
	 */
	public static function flush(): void {
		self::$legal      = null;
		self::$prompt_cfg = null;
		AI_Settings::flush();
		Credits_Gateway::set( null );
	}

	/**
	 * Remove a directory tree (uninstall only, inside uploads/patch-studio/ai).
	 *
	 * @param string $dir Directory.
	 */
	private static function rrmdir( string $dir ): void {
		if ( false === strpos( wp_normalize_path( $dir ), '/' . Image_Store::SUBDIR ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : wp_delete_file( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
