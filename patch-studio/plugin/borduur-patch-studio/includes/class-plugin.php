<?php
/**
 * Plugin bootstrap: autoloader, components, module registry, owned data, activation, upgrades, assets and
 * cache compatibility.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio;

defined( 'ABSPATH' ) || exit;

/**
 * Central bootstrap (SPEC §4.1, contract C2). Every component exposes `public static function register(): void`
 * (or `boot()`) and optionally `activate()` / `deactivate()`.
 *
 * Modules (AI, name tapes, cross-sell, services) are switched on/off per shop through `modules.{id}.enabled`.
 * A module that is off hooks nothing except `register_always()` (privacy exporters/erasers and the handlers that
 * clean up its data), so routes, cron events and assets of a disabled module never load.
 */
final class Plugin {

	/** Components in boot order. A missing class is logged and shown as an admin notice (never skipped silently). */
	const COMPONENTS = array(
		'BorduurPatchStudio\I18n',
		'BorduurPatchStudio\Settings',
		'BorduurPatchStudio\Admin\Settings_Page',
		'BorduurPatchStudio\Woo\Integration',
		'BorduurPatchStudio\Rest\Routes',
		'BorduurPatchStudio\Frontend\Shortcode',
		'BorduurPatchStudio\Admin\Order_Production',
		'BorduurPatchStudio\Admin\System_Status',
		'BorduurPatchStudio\Licensing\Client',
	);

	/** Components that work without (a supported) WooCommerce. */
	const CORE_COMPONENTS = array(
		'BorduurPatchStudio\I18n',
		'BorduurPatchStudio\Settings',
		'BorduurPatchStudio\Admin\Settings_Page',
		'BorduurPatchStudio\Admin\System_Status',
		'BorduurPatchStudio\Licensing\Client',
	);

	/**
	 * Helper classes of other packages that are registered when they exist (no notice when absent): they are
	 * optional building blocks, not features.
	 */
	const OPTIONAL_COMPONENTS = array(
		'BorduurPatchStudio\Admin\Menu',
		'BorduurPatchStudio\Frontend\Studio_Pages',
	);

	/** Transient that triggers the "you're ready" notice after activation. */
	const ACTIVATED_TRANSIENT = 'bps_just_activated';

	/** Stored plugin version (autoload off). */
	const VERSION_OPTION = 'bps_version';

	/** Upgrade lock (add_option is atomic: only one request runs the steps). */
	const UPGRADE_LOCK = 'bps_upgrade_lock';

	/** Last applied module state: id => bool. Used to call activate()/deactivate() on a change. */
	const MODULES_STATE = 'bps_modules_state';

	/** Lock older than this is considered stale (crashed request). */
	const LOCK_TTL = 600;

	/** @var bool */
	private static $booted = false;

	/** @var array<string,bool> Classes whose register() ran in this request. */
	private static $registered = array();

	/** @var string[] Component classes that could not be loaded. */
	private static $missing = array();

	/* ---------------------------------------------------------------------
	 * Autoloader
	 * ------------------------------------------------------------------- */

	/**
	 * PSR-4-like autoloader following the WordPress file naming convention (SPEC §4.1). Also used by uninstall.php.
	 *   BorduurPatchStudio\Woo\Order_Meta      -> includes/woo/class-order-meta.php
	 *   BorduurPatchStudio\Settings            -> includes/class-settings.php
	 *   BorduurPatchStudio\Admin\Settings_Page -> includes/admin/class-settings-page.php
	 *
	 * @param string $class Fully qualified class name.
	 */
	public static function autoload( string $class ): void {
		$prefix = 'BorduurPatchStudio\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class, strlen( $prefix ) ) );
		$name  = array_pop( $parts );
		if ( '' === $name || preg_match( '/[^A-Za-z0-9_]/', $name ) ) {
			return;
		}
		$dir = '';
		foreach ( $parts as $part ) {
			if ( preg_match( '/[^A-Za-z0-9_]/', $part ) ) {
				return;
			}
			$dir .= str_replace( '_', '-', strtolower( $part ) ) . '/';
		}
		$file = __DIR__ . '/' . $dir . 'class-' . str_replace( '_', '-', strtolower( $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}

	/* ---------------------------------------------------------------------
	 * Boot
	 * ------------------------------------------------------------------- */

	/**
	 * Boot on plugins_loaded (prio 20).
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		foreach ( self::components() as $class ) {
			self::register_component( $class, true );
		}
		foreach ( self::OPTIONAL_COMPONENTS as $class ) {
			if ( class_exists( $class ) ) {
				self::register_component( $class, false );
			}
		}
		self::register_modules();

		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 0 );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade_on_init' ), 5 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_plugin_update' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'script_loader_tag' ), 10, 2 );

		// WP Rocket compatibility (06 §9).
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'rocket_delay_js_exclusions' ) );
		add_filter( 'rocket_rucss_external_exclusions', array( __CLASS__, 'rocket_rucss_external_exclusions' ) );
		add_filter( 'rocket_rucss_safelist', array( __CLASS__, 'rocket_rucss_safelist' ) );
		add_filter( 'rocket_exclude_js', array( __CLASS__, 'rocket_exclude_js' ) );

		// Settings saved: new log level, module switches, purge the studio pages (the config lives in cached HTML).
		add_action( 'bps_settings_updated', array( __CLASS__, 'on_settings_updated' ), 50 );
		add_action( 'admin_init', array( __CLASS__, 'enforce_disabled_modules' ), 20 );

		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BPS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Register one component; a class that cannot be loaded is logged and reported (php-kwaliteit-7c).
	 *
	 * @param string $class    Class.
	 * @param bool   $required Report when missing.
	 */
	private static function register_component( string $class, bool $required ): void {
		if ( isset( self::$registered[ $class ] ) ) {
			return;
		}
		if ( ! class_exists( $class ) ) {
			if ( $required ) {
				self::$missing[] = $class;
				// Once a day per class: the admin notice repeats on every screen, the log does not need to.
				$flag = 'bps_missing_' . md5( $class );
				if ( ! get_transient( $flag ) ) {
					set_transient( $flag, 1, DAY_IN_SECONDS );
					Logger::error( 'component_missing', array( 'class' => $class ) );
				}
			}
			return;
		}
		try {
			if ( 'BorduurPatchStudio\\I18n' === $class && method_exists( $class, 'boot' ) ) {
				$class::boot(); // Contract C9: the i18n helper starts with boot().
			} elseif ( method_exists( $class, 'register' ) ) {
				$class::register();
			} elseif ( method_exists( $class, 'boot' ) ) {
				$class::boot();
			}
			self::$registered[ $class ] = true;
		} catch ( \Throwable $e ) {
			self::$missing[] = $class;
			Logger::critical(
				'component_boot_failed',
				array(
					'class'     => $class,
					'exception' => $e,
				)
			);
		}
	}

	/**
	 * Components to register, filtered (`bps_components` keeps working); without a supported WooCommerce only the
	 * core components.
	 *
	 * @return string[]
	 */
	public static function components(): array {
		$list = (array) apply_filters( 'bps_components', self::COMPONENTS );
		if ( ! self::woocommerce_supported() ) {
			$list = array_values( array_intersect( $list, self::CORE_COMPONENTS ) );
		}
		return array_values( array_unique( array_filter( $list, 'is_string' ) ) );
	}

	/**
	 * Whether WooCommerce is loaded (any version).
	 */
	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	/**
	 * Whether WooCommerce is loaded and at least the minimum version (Woo\Integration::MIN_WC).
	 */
	public static function woocommerce_supported(): bool {
		if ( ! self::woocommerce_active() ) {
			return false;
		}
		return ! class_exists( Woo\Integration::class ) || Woo\Integration::wc_version_ok();
	}

	/* ---------------------------------------------------------------------
	 * Module registry (productisering-10, contract C2)
	 * ------------------------------------------------------------------- */

	/**
	 * Registry of optional modules: id => {id, class, label, description, requires[], default, cron[], as_groups[]}.
	 * Labels are untranslated source strings (this runs before `init`); use module_label() for display.
	 * Filter `bps_modules` adds or changes modules.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function modules(): array {
		$modules = array(
			'ai'        => array(
				'class'       => 'BorduurPatchStudio\AI\Module',
				'label'       => 'AI-ontwerpstudio',
				'description' => 'Klanten beschrijven hun patch en krijgen ontwerpvoorstellen van een beeld-AI (betaalde rondes).',
				'requires'    => array(),
				'default'     => false,
				'cron'        => array( 'bps_ai_cleanup' ),
				'as_groups'   => array( 'bps-ai' ),
			),
			'nametape'  => array(
				'class'       => 'BorduurPatchStudio\Nametape\Module',
				'label'       => 'Naamstroken',
				'description' => 'Snelle modus voor naamstroken en kleine ID-patches op klittenband.',
				'requires'    => array(),
				'default'     => true,
				'cron'        => array( 'bps_nt_cleanup' ),
				'as_groups'   => array(),
			),
			'crosssell' => array(
				'class'       => 'BorduurPatchStudio\Crosssell\Module',
				'label'       => 'Zelfde ontwerp, meer producten',
				'description' => 'Toont na "In winkelmand" hetzelfde ontwerp op andere producten (pet, hoodie, sleutelhanger, kader).',
				'requires'    => array(),
				'default'     => true,
				'cron'        => array(),
				'as_groups'   => array(),
			),
			'services'  => array(
				'class'       => 'BorduurPatchStudio\Services\Module',
				'label'       => 'Diensten en ontwerprondes',
				'description' => 'Verkoopt diensten (hertekenen, digitaliseren, staal) en pakketten ontwerprondes voor de AI-studio.',
				'requires'    => array( 'ai' ),
				'default'     => true,
				'cron'        => array( 'bps_svc_daily' ),
				'as_groups'   => array(),
			),
		);
		$modules = apply_filters( 'bps_modules', $modules );
		$out     = array();
		foreach ( is_array( $modules ) ? $modules : array() as $id => $m ) {
			if ( ! is_array( $m ) || empty( $m['class'] ) || ! preg_match( '/^[a-z0-9_]+$/', (string) $id ) ) {
				continue;
			}
			$out[ (string) $id ] = array(
				'id'          => (string) $id,
				'class'       => (string) $m['class'],
				'label'       => (string) ( $m['label'] ?? $id ),
				'description' => (string) ( $m['description'] ?? '' ),
				'requires'    => array_values( array_filter( (array) ( $m['requires'] ?? array() ), 'is_string' ) ),
				'default'     => ! empty( $m['default'] ),
				'cron'        => array_values( array_filter( (array) ( $m['cron'] ?? array() ), 'is_string' ) ),
				'as_groups'   => array_values( array_filter( (array) ( $m['as_groups'] ?? array() ), 'is_string' ) ),
			);
		}
		return $out;
	}

	/**
	 * Translated label of a module (for the admin switch and the status report).
	 *
	 * @param string $id Module id.
	 */
	public static function module_label( string $id ): string {
		switch ( $id ) {
			case 'ai':
				return __( 'AI-ontwerpstudio', 'borduur-patch-studio' );
			case 'nametape':
				return __( 'Naamstroken', 'borduur-patch-studio' );
			case 'crosssell':
				return __( 'Zelfde ontwerp, meer producten', 'borduur-patch-studio' );
			case 'services':
				return __( 'Diensten en ontwerprondes', 'borduur-patch-studio' );
		}
		$modules = self::modules();
		return isset( $modules[ $id ] ) ? $modules[ $id ]['label'] : $id;
	}

	/**
	 * Translated one-line description of a module.
	 *
	 * @param string $id Module id.
	 */
	public static function module_description( string $id ): string {
		switch ( $id ) {
			case 'ai':
				return __( 'Klanten beschrijven hun patch en krijgen ontwerpvoorstellen van een beeld-AI (betaalde rondes).', 'borduur-patch-studio' );
			case 'nametape':
				return __( 'Snelle modus voor naamstroken en kleine ID-patches op klittenband.', 'borduur-patch-studio' );
			case 'crosssell':
				return __( 'Toont na "In winkelmand" hetzelfde ontwerp op andere producten (pet, hoodie, sleutelhanger, kader).', 'borduur-patch-studio' );
			case 'services':
				return __( 'Verkoopt diensten (hertekenen, digitaliseren, staal) en pakketten ontwerprondes voor de AI-studio. Vereist de AI-ontwerpstudio voor de rondes.', 'borduur-patch-studio' );
		}
		$modules = self::modules();
		return isset( $modules[ $id ] ) ? $modules[ $id ]['description'] : '';
	}

	/**
	 * The stored switch of a module (`modules.{id}.enabled`), without looking at requirements.
	 *
	 * @param string $id Module id.
	 */
	public static function module_switch( string $id ): bool {
		$modules = self::modules();
		if ( ! isset( $modules[ $id ] ) ) {
			return false;
		}
		$value = null;
		try {
			$value = Settings::get_path( 'modules.' . $id . '.enabled', null );
		} catch ( \Throwable $e ) {
			Logger::error(
				'module_setting_unreadable',
				array(
					'module'    => $id,
					'exception' => $e,
				)
			);
		}
		if ( null === $value ) {
			return $modules[ $id ]['default'];
		}
		return (bool) $value;
	}

	/**
	 * Whether a module is effectively on: its switch is on and every module it requires is on.
	 *
	 * @param string   $id   Module id.
	 * @param string[] $seen Recursion guard.
	 */
	public static function module_enabled( string $id, array $seen = array() ): bool {
		$modules = self::modules();
		if ( ! isset( $modules[ $id ] ) || in_array( $id, $seen, true ) || ! self::module_switch( $id ) ) {
			return false;
		}
		$seen[] = $id;
		foreach ( $modules[ $id ]['requires'] as $req ) {
			if ( ! self::module_enabled( $req, $seen ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Effective state of every module: id => bool.
	 *
	 * @return array<string,bool>
	 */
	public static function module_states(): array {
		$out = array();
		foreach ( array_keys( self::modules() ) as $id ) {
			$out[ $id ] = self::module_enabled( $id );
		}
		return $out;
	}

	/**
	 * Switch a module on or off (for the admin switch of P08 and WP-CLI). Stores `modules.{id}.enabled` and runs
	 * the module's activate() (tables, cron, products) or deactivate() (unschedule cron; data stays).
	 *
	 * @param string $id Module id.
	 * @param bool   $on New state.
	 * @return true|\WP_Error
	 */
	public static function set_module_enabled( string $id, bool $on ) {
		$modules = self::modules();
		if ( ! isset( $modules[ $id ] ) ) {
			return new \WP_Error( 'bps_module_unknown', __( 'Deze module bestaat niet.', 'borduur-patch-studio' ) );
		}
		if ( $on ) {
			foreach ( $modules[ $id ]['requires'] as $req ) {
				if ( ! self::module_enabled( $req ) ) {
					return new \WP_Error(
						'bps_module_requires',
						sprintf(
							/* translators: 1: module name, 2: required module name */
							__( '%1$s werkt pas als %2$s aan staat. Zet die eerst aan.', 'borduur-patch-studio' ),
							self::module_label( $id ),
							self::module_label( $req )
						)
					);
				}
			}
		}

		$defaults = Settings::defaults();
		if ( isset( $defaults['modules'][ $id ] ) && is_array( $defaults['modules'][ $id ] ) ) {
			// The setting is part of defaults.json: go through the validated settings API.
			Settings::update( array( 'modules' => array( $id => array( 'enabled' => $on ) ) ) );
		} else {
			// Not (yet) in defaults.json: store the private admin switch directly in the overrides.
			$stored                               = Settings::overrides();
			$stored['modules']                    = isset( $stored['modules'] ) && is_array( $stored['modules'] ) ? $stored['modules'] : array();
			$stored['modules'][ $id ]             = isset( $stored['modules'][ $id ] ) && is_array( $stored['modules'][ $id ] ) ? $stored['modules'][ $id ] : array();
			$stored['modules'][ $id ]['enabled'] = $on;
			update_option( Settings::OPTION, $stored, true );
			Settings::flush();
			do_action( 'bps_settings_updated', Settings::get() );
		}
		// on_settings_updated() already synced when the action fired in this request; sync again for callers
		// that run before boot() (WP-CLI, tests).
		self::sync_modules();
		return true;
	}

	/**
	 * Compare the effective module states with the last applied ones and run activate()/deactivate() for every
	 * module that changed. Returns the changes: id => bool.
	 *
	 * @return array<string,bool>
	 */
	public static function sync_modules(): array {
		$now  = self::module_states();
		$last = get_option( self::MODULES_STATE, null );
		if ( ! is_array( $last ) ) {
			// First run (fresh install or upgrade): take the current state as applied. Modules ensure their own
			// tables/cron/products on init, so nothing has to be activated here.
			update_option( self::MODULES_STATE, $now, false );
			return array();
		}
		$changed = array();
		foreach ( $now as $id => $on ) {
			$was = ! empty( $last[ $id ] );
			if ( $was === $on ) {
				continue;
			}
			$changed[ $id ] = $on;
			self::run_module_hook( $id, $on ? 'activate' : 'deactivate' );
		}
		if ( $changed || array_keys( $now ) !== array_keys( $last ) ) {
			update_option( self::MODULES_STATE, $now, false );
		}
		foreach ( $changed as $id => $on ) {
			Logger::info(
				'module_switched',
				array(
					'module'  => $id,
					'enabled' => $on,
				)
			);
			do_action( 'bps_module_switched', $id, $on );
		}
		return $changed;
	}

	/**
	 * Call activate()/deactivate() of one module, logging failures.
	 *
	 * @param string $id     Module id.
	 * @param string $method activate|deactivate.
	 */
	private static function run_module_hook( string $id, string $method ): void {
		$modules = self::modules();
		$class   = $modules[ $id ]['class'] ?? '';
		if ( '' === $class || ! class_exists( $class ) ) {
			Logger::error(
				'module_missing',
				array(
					'module' => $id,
					'class'  => $class,
				)
			);
			return;
		}
		if ( 'activate' === $method && ! self::woocommerce_supported() ) {
			return; // Module activation needs WooCommerce; it runs on the next switch or activation.
		}
		if ( ! method_exists( $class, $method ) ) {
			return;
		}
		try {
			$class::$method();
		} catch ( \Throwable $e ) {
			Logger::error(
				'module_' . $method . '_failed',
				array(
					'module'    => $id,
					'exception' => $e,
				)
			);
		}
	}

	/**
	 * Register modules: register_always() for every module, register() only for enabled ones.
	 */
	private static function register_modules(): void {
		$woo = self::woocommerce_supported();
		foreach ( self::modules() as $id => $m ) {
			$class = $m['class'];
			if ( ! class_exists( $class ) ) {
				self::$missing[] = $class;
				Logger::error(
					'module_missing',
					array(
						'module' => $id,
						'class'  => $class,
					)
				);
				continue;
			}
			try {
				if ( method_exists( $class, 'register_always' ) ) {
					$class::register_always();
				}
				if ( $woo && self::module_enabled( $id ) && ! isset( self::$registered[ $class ] ) && method_exists( $class, 'register' ) ) {
					$class::register();
					self::$registered[ $class ] = true;
				}
			} catch ( \Throwable $e ) {
				Logger::critical(
					'module_boot_failed',
					array(
						'module'    => $id,
						'exception' => $e,
					)
				);
			}
		}
	}

	/**
	 * Safety net on admin_init: a disabled module never keeps cron events or queued background actions.
	 */
	public static function enforce_disabled_modules(): void {
		foreach ( self::modules() as $id => $m ) {
			if ( self::module_enabled( $id ) || isset( self::$registered[ $m['class'] ] ) ) {
				continue;
			}
			foreach ( $m['cron'] as $hook ) {
				if ( function_exists( 'wp_unschedule_hook' ) && wp_next_scheduled( $hook ) ) {
					wp_unschedule_hook( $hook );
				}
			}
		}
	}

	/**
	 * Settings saved: new log level, apply module switches, purge the studio pages.
	 */
	public static function on_settings_updated(): void {
		Logger::flush();
		self::sync_modules();
		self::purge_page_cache( false );
	}

	/* ---------------------------------------------------------------------
	 * Owned data: the one list for uninstall and the status report (contract C2)
	 * ------------------------------------------------------------------- */

	/**
	 * Read a class constant without fataling when the class is missing.
	 *
	 * @param string $class    Class.
	 * @param string $constant Constant name.
	 * @param string $fallback Fallback.
	 */
	private static function c( string $class, string $constant, string $fallback ): string {
		if ( class_exists( $class ) && defined( $class . '::' . $constant ) ) {
			return (string) constant( $class . '::' . $constant );
		}
		return $fallback;
	}

	/**
	 * Everything the plugin stores outside orders. Read by uninstall.php (where no module is booted) and by the
	 * system status. Other classes add entries with a static `owned_data(): array` method (works in uninstall) or
	 * the filter `bps_owned_data` (runtime only).
	 *
	 * Keys:
	 * - options                 removed on "delete everything" (wipe)
	 * - always_options          removed on every uninstall (secrets, logs, locks, caches)
	 * - option_prefixes         wipe: every option starting with one of these
	 * - always_option_patterns  every uninstall: LIKE patterns (`%` wildcard, `_` literal)
	 * - tables                  wipe: tables without the $wpdb prefix
	 * - cron_hooks              every uninstall (plus any other event whose hook starts with `bps_`)
	 * - as_groups               every uninstall: Action Scheduler groups
	 * - post_types              wipe: our CPTs (with meta, through wp_delete_post)
	 * - transient_designs       every uninstall: design statuses that never became an order or a quote
	 * - product_meta            wipe: products created by the plugin carry one of these meta keys
	 * - page_meta               wipe: pages created by the plugin carry this meta key
	 * - upload_dirs             wipe: folders inside wp-content/uploads
	 *
	 * @return array<string, array<int|string, mixed>>
	 */
	public static function owned_data(): array {
		$ai_module = 'BorduurPatchStudio\AI\Module';
		$data      = array(
			'options'                => array(
				self::c( 'BorduurPatchStudio\Settings', 'OPTION', 'bps_settings' ),
				'bps_db_version',
				self::VERSION_OPTION,
				self::MODULES_STATE,
				self::c( 'BorduurPatchStudio\Woo\Product', 'OPTION', 'bps_product_id' ),
				self::c( 'BorduurPatchStudio\Woo\Product', 'STUDIO_OPTION', 'bps_studio_page_id' ),
				'bps_ref_seq',
				'bps_quote_seq',
				'bps_studio_pages',
				'bps_studio_main_page',
				// AI module.
				self::c( 'BorduurPatchStudio\AI\AI_Settings', 'OPTION', 'bps_ai_settings' ),
				self::c( 'BorduurPatchStudio\AI\Schema', 'VERSION_OPTION', 'bps_ai_db_version' ),
				self::c( 'BorduurPatchStudio\AI\Limits', 'ALERTS', 'bps_ai_alerts_sent' ),
				self::c( 'BorduurPatchStudio\AI\Limits', 'REFUSALS', 'bps_ai_refusal_counts' ),
				// Services module.
				self::c( 'BorduurPatchStudio\Services\Module', 'VERSION_OPT', 'bps_svc_db_version' ),
				self::c( 'BorduurPatchStudio\Services\Products', 'OPTION', 'bps_svc_products' ),
				self::c( 'BorduurPatchStudio\Services\Products', 'NOTICE', 'bps_svc_products_notice' ),
				self::c( 'BorduurPatchStudio\Services\Withdrawal', 'REWRITE_OPT', 'bps_svc_rewrite_v' ),
				// Name tapes.
				self::c( 'BorduurPatchStudio\Nametape\Config', 'OPTION', 'bps_nametape_settings' ),
				self::c( 'BorduurPatchStudio\Nametape\Config', 'PRODUCT_OPTION', 'bps_nametape_product_id' ),
				self::c( 'BorduurPatchStudio\Nametape\Config', 'PAGE_OPTION', 'bps_nametape_page_id' ),
				// Cross-sell.
				self::c( 'BorduurPatchStudio\Crosssell\Config', 'OPTION', 'bps_crosssell_settings' ),
				self::c( 'BorduurPatchStudio\Crosssell\Stats', 'OPTION', 'bps_cs_stats' ),
			),
			'always_options'         => array(
				self::c( 'BorduurPatchStudio\AI\Secrets', 'OPTION', 'bps_ai_secrets' ),
				self::c( $ai_module, 'LOG_OPTION', 'bps_ai_log' ),
				self::c( 'BorduurPatchStudio\Licensing\Client', 'OPTION', 'bps_license' ),
				self::UPGRADE_LOCK,
				'bps_product_lock',
				'bps_svc_products_lock',
				self::c( 'BorduurPatchStudio\Uploads', 'SELFTEST_KEY', 'bps_uploads_selftest' ),
			),
			'option_prefixes'        => array( 'bps_' ),
			'always_option_patterns' => array(
				'woocommerce_bps_%_settings',
				'_transient_bps_%',
				'_transient_timeout_bps_%',
				'_site_transient_bps_%',
				'_site_transient_timeout_bps_%',
			),
			'tables'                 => array( 'bps_ai_owners', 'bps_ai_jobs', 'bps_ai_costs', 'bps_ai_lots', 'bps_ai_ledger' ),
			'cron_hooks'             => array(
				self::c( 'BorduurPatchStudio\Woo\Cleanup', 'HOOK', 'bps_cleanup' ),
				self::c( $ai_module, 'CRON', 'bps_ai_cleanup' ),
				self::c( 'BorduurPatchStudio\Services\Module', 'CRON', 'bps_svc_daily' ),
				self::c( 'BorduurPatchStudio\Nametape\Order_Tools', 'CRON', 'bps_nt_cleanup' ),
			),
			'as_groups'              => array( self::c( $ai_module, 'AS_GROUP', 'bps-ai' ) ),
			'post_types'             => array( 'bps_design', 'bps_quote' ),
			'transient_designs'      => array( 'draft', 'carted', 'saved' ),
			'product_meta'           => array(
				self::c( 'BorduurPatchStudio\Woo\Product', 'META_FLAG', '_bps_patch_product' ),
				self::c( 'BorduurPatchStudio\Nametape\Product', 'META_FLAG', '_bps_nametape_product' ),
				self::c( 'BorduurPatchStudio\Crosssell\Products', 'META', '_bps_cs_product' ),
				self::c( 'BorduurPatchStudio\Services\Products', 'PACK_META', '_bps_ai_pack' ),
				self::c( 'BorduurPatchStudio\Services\Products', 'SERVICE_META', '_bps_service' ),
			),
			'page_meta'              => array( '_bps_created_page' ),
			'upload_dirs'            => array( 'patch-studio' ),
		);

		// Modules list their cron hooks and AS groups in the registry too.
		foreach ( self::modules() as $m ) {
			$data['cron_hooks'] = array_merge( $data['cron_hooks'], $m['cron'] );
			$data['as_groups']  = array_merge( $data['as_groups'], $m['as_groups'] );
		}

		// Static contributions (these also work during uninstall, where no filter is hooked).
		$classes = array_merge( self::COMPONENTS, self::OPTIONAL_COMPONENTS, array_column( self::modules(), 'class' ) );
		foreach ( array_unique( $classes ) as $class ) {
			if ( $class !== __CLASS__ && class_exists( $class ) && method_exists( $class, 'owned_data' ) ) {
				try {
					$extra = $class::owned_data();
				} catch ( \Throwable $e ) {
					Logger::error(
						'owned_data_failed',
						array(
							'class'     => $class,
							'exception' => $e,
						)
					);
					$extra = array();
				}
				$data = self::merge_owned( $data, is_array( $extra ) ? $extra : array() );
			}
		}

		$filtered = apply_filters( 'bps_owned_data', $data );
		$data     = is_array( $filtered ) ? self::merge_owned( $data, $filtered, true ) : $data;
		foreach ( $data as $key => $list ) {
			$data[ $key ] = array_values( array_unique( array_filter( (array) $list, 'is_string' ) ) );
		}
		return $data;
	}

	/**
	 * Merge owned-data lists (only known list keys; strings only).
	 *
	 * @param array $base    Base.
	 * @param array $extra   Extra.
	 * @param bool  $replace The extra array is a full (filtered) copy: take its lists as they are.
	 */
	private static function merge_owned( array $base, array $extra, bool $replace = false ): array {
		foreach ( $extra as $key => $list ) {
			if ( ! is_string( $key ) || ! is_array( $list ) ) {
				continue;
			}
			$list         = array_values( array_filter( $list, 'is_string' ) );
			$base[ $key ] = $replace ? $list : array_merge( $base[ $key ] ?? array(), $list );
		}
		return $base;
	}

	/* ---------------------------------------------------------------------
	 * Activation / deactivation
	 * ------------------------------------------------------------------- */

	/**
	 * Activation hook. Supports network activation on multisite.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_site();
	}

	/**
	 * Per-site activation.
	 */
	private static function activate_site(): void {
		// Settings live as overrides only; an empty option means "use defaults".
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, array(), '', true );
		}
		update_option( 'bps_db_version', BPS_DB_VERSION, false );

		foreach ( self::components() as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'activate' ) ) {
				try {
					$class::activate();
				} catch ( \Throwable $e ) {
					Logger::error(
						'component_activate_failed',
						array(
							'class'     => $class,
							'exception' => $e,
						)
					);
				}
			}
		}
		$states = self::module_states();
		foreach ( $states as $id => $on ) {
			if ( $on ) {
				self::run_module_hook( $id, 'activate' );
			}
		}
		update_option( self::MODULES_STATE, $states, false );
		do_action( 'bps_activate' );

		self::maybe_upgrade();
		set_transient( self::ACTIVATED_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Deactivation hook.
	 *
	 * @param bool $network_wide Network deactivation.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_site();
				restore_current_blog();
			}
			return;
		}
		self::deactivate_site();
	}

	/**
	 * Per-site deactivation. Data is kept; only scheduled work is stopped (components AND every module, on or off).
	 */
	private static function deactivate_site(): void {
		foreach ( array_unique( array_merge( self::COMPONENTS, (array) apply_filters( 'bps_components', self::COMPONENTS ) ) ) as $class ) {
			if ( is_string( $class ) && class_exists( $class ) && method_exists( $class, 'deactivate' ) ) {
				try {
					$class::deactivate();
				} catch ( \Throwable $e ) {
					Logger::error(
						'component_deactivate_failed',
						array(
							'class'     => $class,
							'exception' => $e,
						)
					);
				}
			}
		}
		foreach ( array_keys( self::modules() ) as $id ) {
			self::run_module_hook( $id, 'deactivate' );
		}
		// Safety net: never leave any of our cron events running when the plugin is off.
		self::clear_cron();
		do_action( 'bps_deactivate' );
		self::purge_page_cache( true );
	}

	/**
	 * Unschedule every cron event of the plugin (listed hooks plus any hook starting with `bps_`).
	 */
	public static function clear_cron(): void {
		$hooks = self::owned_data()['cron_hooks'];
		if ( function_exists( '_get_cron_array' ) ) {
			foreach ( (array) _get_cron_array() as $events ) {
				foreach ( array_keys( (array) $events ) as $hook ) {
					if ( is_string( $hook ) && 0 === strpos( $hook, 'bps_' ) ) {
						$hooks[] = $hook;
					}
				}
			}
		}
		foreach ( array_unique( $hooks ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/* ---------------------------------------------------------------------
	 * Versions and upgrades (productisering-7)
	 * ------------------------------------------------------------------- */

	/**
	 * Ordered upgrade steps: version => callable. Each step must be idempotent. Filter `bps_upgrade_steps`.
	 *
	 * @return array<string, callable>
	 */
	public static function upgrade_steps(): array {
		$steps = apply_filters(
			'bps_upgrade_steps',
			array(
				'1.0.0' => array( __CLASS__, 'upgrade_1_0_0' ),
			)
		);
		$steps = is_array( $steps ) ? array_filter( $steps, 'is_callable' ) : array();
		uksort( $steps, 'version_compare' );
		return $steps;
	}

	/**
	 * Step 1.0.0: settings option present, AI log moved to the central Logger (drop the option ring buffer),
	 * module state recorded.
	 */
	public static function upgrade_1_0_0(): void {
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, array(), '', true );
		}
		delete_option( self::c( 'BorduurPatchStudio\AI\Module', 'LOG_OPTION', 'bps_ai_log' ) );
		if ( ! is_array( get_option( self::MODULES_STATE, null ) ) ) {
			update_option( self::MODULES_STATE, self::module_states(), false );
		}
	}

	/**
	 * Run upgrades on init, but only in requests where a little extra work is fine (admin, cron, WP-CLI): the
	 * front end of a shop never pays for the non-autoloaded version read. WordPress auto-updates run in cron.
	 */
	public static function maybe_upgrade_on_init(): void {
		$cli = defined( 'WP_CLI' ) && WP_CLI;
		if ( is_admin() || wp_doing_cron() || $cli ) {
			self::maybe_upgrade();
		}
	}

	/**
	 * Right after this plugin was updated through the WordPress updater.
	 *
	 * @param mixed $upgrader Upgrader.
	 * @param mixed $extra    Hook extra.
	 */
	public static function after_plugin_update( $upgrader, $extra ): void {
		unset( $upgrader );
		if ( ! is_array( $extra ) || 'plugin' !== ( $extra['type'] ?? '' ) ) {
			return;
		}
		$plugins = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : array( $extra['plugin'] ?? '' );
		if ( in_array( plugin_basename( BPS_FILE ), $plugins, true ) ) {
			// The new code is not loaded yet in this request; make sure the next request upgrades.
			delete_option( self::UPGRADE_LOCK );
		}
	}

	/**
	 * Run every upgrade step newer than the stored version (bps_version), once, under an add_option lock, then
	 * store BPS_VERSION and fire `bps_upgraded( $from, $to )`. Returns whether steps ran.
	 */
	public static function maybe_upgrade(): bool {
		$to   = (string) BPS_VERSION;
		$from = (string) get_option( self::VERSION_OPTION, '0.0.0' );
		if ( '' === $from ) {
			$from = '0.0.0';
		}
		if ( version_compare( $from, $to, '>=' ) && (int) get_option( 'bps_db_version', 0 ) >= BPS_DB_VERSION ) {
			return false;
		}

		// Lock: add_option fails when the option exists (another request is upgrading).
		if ( ! add_option( self::UPGRADE_LOCK, time(), '', false ) ) {
			$since = (int) get_option( self::UPGRADE_LOCK, 0 );
			if ( $since > 0 && ( time() - $since ) < self::LOCK_TTL ) {
				return false;
			}
			delete_option( self::UPGRADE_LOCK ); // Stale lock of a crashed request.
			if ( ! add_option( self::UPGRADE_LOCK, time(), '', false ) ) {
				return false;
			}
		}

		$ran = false;
		try {
			foreach ( self::upgrade_steps() as $version => $step ) {
				if ( version_compare( (string) $version, $from, '<=' ) || version_compare( (string) $version, $to, '>' ) ) {
					continue;
				}
				call_user_func( $step, $from, $to );
				$ran = true;
				Logger::info(
					'upgrade_step_done',
					array(
						'step' => (string) $version,
						'from' => $from,
					)
				);
			}
			update_option( self::VERSION_OPTION, $to, false );
			update_option( 'bps_db_version', BPS_DB_VERSION, false );
		} catch ( \Throwable $e ) {
			Logger::critical(
				'upgrade_failed',
				array(
					'from'      => $from,
					'to'        => $to,
					'exception' => $e,
				)
			);
			delete_option( self::UPGRADE_LOCK );
			return false;
		}
		delete_option( self::UPGRADE_LOCK );
		if ( $from !== $to ) {
			do_action( 'bps_upgraded', $from, $to );
		}
		return $ran;
	}

	/* ---------------------------------------------------------------------
	 * i18n
	 * ------------------------------------------------------------------- */

	/**
	 * Load translations (on init, never earlier: WP 6.7 just-in-time notice).
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'borduur-patch-studio', false, dirname( plugin_basename( BPS_FILE ) ) . '/languages' );
	}

	/* ---------------------------------------------------------------------
	 * Assets (SPEC §4.3): core registers, shortcode/widget/production enqueue.
	 * ------------------------------------------------------------------- */

	/**
	 * Version string for an asset: plugin version + file mtime (cache busting through WP Rocket/CDN).
	 *
	 * @param string $relative Path relative to the plugin dir.
	 */
	public static function asset_version( string $relative ): string {
		$file = BPS_DIR . ltrim( $relative, '/' );
		$time = is_readable( $file ) ? (int) filemtime( $file ) : 0;
		return $time ? BPS_VERSION . '-' . $time : BPS_VERSION;
	}

	/**
	 * Public URL of a plugin asset.
	 *
	 * @param string $relative Path relative to the plugin dir.
	 */
	public static function asset_url( string $relative ): string {
		return BPS_URL . ltrim( $relative, '/' );
	}

	/**
	 * Studio bundle for a locale (prestatie-4): assets/dist/{nl|fr}/patch-studio.js holds only that language's
	 * texts. Falls back to the bilingual assets/dist/patch-studio.js when the language build is missing.
	 *
	 * @param string|null $locale Locale (null = current public locale).
	 */
	public static function app_bundle( ?string $locale = null ): string {
		$lang = 0 === strpos( Settings::public_locale( $locale ), 'fr' ) ? 'fr' : 'nl';
		/**
		 * Language of the studio bundle. Must match config.locale of the studio on the page: a page that renders the
		 * studio in another language than the request locale must return that language here (or 'all').
		 *
		 * @param string      $lang   nl|fr|all.
		 * @param string|null $locale Locale asked for.
		 */
		$lang = (string) apply_filters( 'bps_app_bundle_lang', $lang, $locale );
		if ( ! in_array( $lang, array( 'nl', 'fr' ), true ) ) {
			return 'assets/dist/patch-studio.js';
		}
		$file = 'assets/dist/' . $lang . '/patch-studio.js';
		return is_readable( BPS_DIR . $file ) ? $file : 'assets/dist/patch-studio.js';
	}

	/**
	 * Register script/style handles.
	 */
	public static function register_assets(): void {
		if ( ! wp_script_is( 'bps-app', 'registered' ) ) {
			$bundle = self::app_bundle();
			wp_register_script(
				'bps-app',
				self::asset_url( $bundle ),
				array(),
				self::asset_version( $bundle ),
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
		if ( ! wp_style_is( 'bps-style', 'registered' ) ) {
			wp_register_style(
				'bps-style',
				self::asset_url( 'assets/css/patch-studio.css' ),
				array(),
				self::asset_version( 'assets/css/patch-studio.css' )
			);
		}
		if ( ! wp_script_is( 'bps-admin', 'registered' ) ) {
			wp_register_script(
				'bps-admin',
				self::asset_url( 'assets/dist/patch-studio-admin.js' ),
				array(),
				self::asset_version( 'assets/dist/patch-studio-admin.js' ),
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
		if ( ! wp_style_is( 'bps-admin', 'registered' ) && is_readable( BPS_DIR . 'assets/dist/patch-studio-admin.css' ) ) {
			wp_register_style(
				'bps-admin',
				self::asset_url( 'assets/dist/patch-studio-admin.css' ),
				array(),
				self::asset_version( 'assets/dist/patch-studio-admin.css' )
			);
		}
	}

	/**
	 * Worker URL used by the UI (config.assets.workerUrl).
	 */
	public static function worker_url(): string {
		return add_query_arg( 'ver', rawurlencode( self::asset_version( 'assets/dist/patch-studio-worker.js' ) ), self::asset_url( 'assets/dist/patch-studio-worker.js' ) );
	}

	/**
	 * Keep our script away from optimisers that would delay it until the first interaction.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 */
	public static function script_loader_tag( $tag, $handle ) {
		if ( 'bps-app' !== $handle || ! is_string( $tag ) || false !== strpos( $tag, 'data-cfasync' ) ) {
			return $tag;
		}
		// Cloudflare Rocket Loader and Jetpack Boost "defer non-essential JS" must not touch it.
		return str_replace( '<script ', '<script data-cfasync="false" data-jetpack-boost="ignore" ', $tag );
	}

	/**
	 * @param mixed $ex Exclusions.
	 * @return array
	 */
	public static function rocket_delay_js_exclusions( $ex ): array {
		$ex   = is_array( $ex ) ? $ex : array();
		$ex[] = '/borduur-patch-studio/assets/';
		$ex[] = 'bps-app';
		return array_values( array_unique( $ex ) );
	}

	/**
	 * @param mixed $ex External CSS exclusions for Remove Unused CSS.
	 * @return array
	 */
	public static function rocket_rucss_external_exclusions( $ex ): array {
		$ex   = is_array( $ex ) ? $ex : array();
		$ex[] = '/borduur-patch-studio/assets/css/';
		return array_values( array_unique( $ex ) );
	}

	/**
	 * Our UI is built client-side, so RUCSS cannot see our selectors in the HTML.
	 *
	 * @param mixed $list Safelist.
	 * @return array
	 */
	public static function rocket_rucss_safelist( $list ): array {
		$list   = is_array( $list ) ? $list : array();
		$list[] = '.bps-(.*)';
		$list[] = '/borduur-patch-studio/assets/css/(.*).css';
		return array_values( array_unique( $list ) );
	}

	/**
	 * Exclude the worker from JS minify/combine (it is never loaded through a script tag).
	 *
	 * @param mixed $ex Exclusions.
	 * @return array
	 */
	public static function rocket_exclude_js( $ex ): array {
		$ex   = is_array( $ex ) ? $ex : array();
		$ex[] = '/borduur-patch-studio/assets/dist/patch-studio-worker.js';
		return array_values( array_unique( $ex ) );
	}

	/* ---------------------------------------------------------------------
	 * Page cache (prestatie-3)
	 * ------------------------------------------------------------------- */

	/**
	 * Pages that print the studio config (and so must be purged after a settings change).
	 *
	 * @return int[]
	 */
	public static function studio_page_ids(): array {
		$ids = array();
		if ( class_exists( 'BorduurPatchStudio\Frontend\Studio_Pages' ) && method_exists( 'BorduurPatchStudio\Frontend\Studio_Pages', 'ids' ) ) {
			try {
				$ids = (array) Frontend\Studio_Pages::ids();
			} catch ( \Throwable $e ) {
				Logger::error( 'studio_pages_failed', array( 'exception' => $e ) );
			}
		} else {
			$ids[] = (int) get_option( self::c( 'BorduurPatchStudio\Woo\Product', 'STUDIO_OPTION', 'bps_studio_page_id' ), 0 );
		}
		$ids[] = (int) get_option( self::c( 'BorduurPatchStudio\Nametape\Config', 'PAGE_OPTION', 'bps_nametape_page_id' ), 0 );
		$ids   = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		return array_values( array_filter( (array) apply_filters( 'bps_studio_page_ids', $ids ), 'is_int' ) );
	}

	/**
	 * Purge full-page caches. Targeted (only the studio pages) when they are known; the whole site only when no
	 * studio page is known, on deactivation, or when explicitly asked ($full, the "Volledige cache legen" button).
	 * Returns 'targeted' or 'full'.
	 *
	 * @param bool $full Purge the whole site.
	 */
	public static function purge_page_cache( bool $full = false ): string {
		$ids = $full ? array() : self::studio_page_ids();
		if ( $ids ) {
			foreach ( $ids as $id ) {
				if ( function_exists( 'rocket_clean_post' ) ) {
					rocket_clean_post( $id );
				}
				do_action( 'litespeed_purge_post', $id ); // LiteSpeed Cache (no-op when absent).
				if ( function_exists( 'w3tc_flush_post' ) ) {
					w3tc_flush_post( $id );
				}
				if ( function_exists( 'wpsc_delete_post_cache' ) ) { // WP Super Cache.
					wpsc_delete_post_cache( $id );
				}
				if ( function_exists( 'clean_post_cache' ) ) {
					clean_post_cache( $id );
				}
			}
			do_action( 'bps_purge_studio_cache', $ids );
			return 'targeted';
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) { // WP Super Cache.
			wp_cache_clear_cache();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) { // W3 Total Cache.
			w3tc_flush_all();
		}
		do_action( 'litespeed_purge_all' ); // LiteSpeed Cache (no-op when absent).
		do_action( 'bps_purge_studio_cache', array() );
		return 'full';
	}

	/* ---------------------------------------------------------------------
	 * Admin notices and links
	 * ------------------------------------------------------------------- */

	/**
	 * Capability needed to manage Patch Studio.
	 */
	public static function capability(): string {
		return self::woocommerce_active() ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * URL of the settings page.
	 *
	 * @param string $tab Optional tab.
	 */
	public static function settings_url( string $tab = '' ): string {
		$base = self::woocommerce_active() ? 'admin.php' : 'options-general.php';
		$args = array( 'page' => 'bps-settings' );
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, admin_url( $base ) );
	}

	/**
	 * Components that could not be loaded in this request (for the status report).
	 *
	 * @return string[]
	 */
	public static function missing_components(): array {
		return array_values( array_unique( self::$missing ) );
	}

	/**
	 * Admin notices: WooCommerce missing, broken component, freshly activated.
	 */
	public static function admin_notices(): void {
		if ( ! current_user_can( 'activate_plugins' ) && ! current_user_can( self::capability() ) ) {
			return;
		}
		if ( ! self::woocommerce_active() ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Patch Studio heeft WooCommerce nodig.', 'borduur-patch-studio' ) . '</strong> ';
			echo esc_html__( 'Activeer WooCommerce om patches te laten bestellen. De instellingen kan je intussen al bekijken.', 'borduur-patch-studio' ) . '</p></div>';
		} elseif ( ! self::woocommerce_supported() && class_exists( Woo\Integration::class ) ) {
			Woo\Integration::version_notice();
		}

		$missing = self::missing_components();
		if ( $missing ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Patch Studio is onvolledig geïnstalleerd.', 'borduur-patch-studio' ) . '</strong> ';
			echo esc_html(
				sprintf(
					/* translators: %s: list of PHP class names */
					__( 'Deze onderdelen konden niet geladen worden: %s. Installeer de volledige plugin-zip opnieuw. Details staan in WooCommerce > Status > Logs (bron borduur-patch-studio).', 'borduur-patch-studio' ),
					implode( ', ', $missing )
				)
			);
			echo '</p></div>';
		}

		if ( get_transient( self::ACTIVATED_TRANSIENT ) ) {
			delete_transient( self::ACTIVATED_TRANSIENT );
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && false !== strpos( (string) $screen->id, 'bps-settings' ) ) {
				return;
			}
			echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Patch Studio is klaar voor gebruik.', 'borduur-patch-studio' ) . '</strong> ';
			echo esc_html__( 'Alle prijzen en teksten staan al goed ingesteld. Je hoeft enkel nog de ontwerppagina te maken.', 'borduur-patch-studio' ) . ' ';
			echo '<a class="button button-primary" href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Naar Patch Studio', 'borduur-patch-studio' ) . '</a></p></div>';
		}
	}

	/**
	 * "Instellingen" link on the plugins screen.
	 *
	 * @param mixed $links Links.
	 * @return array
	 */
	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift( $links, '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Instellingen', 'borduur-patch-studio' ) . '</a>' );
		return $links;
	}
}
