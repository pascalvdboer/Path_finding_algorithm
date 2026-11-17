<?php
/**
 * Plugin bootstrap: components, activation, text domain, assets and cache compatibility.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio;

defined( 'ABSPATH' ) || exit;

/**
 * Central bootstrap (SPEC §4.1). Every component exposes `public static function register(): void`
 * and optionally `activate()` / `deactivate()`.
 */
final class Plugin {

	/** Components in boot order. Missing classes are skipped silently (other builders may not be done yet). */
	const COMPONENTS = array(
		'BorduurPatchStudio\Settings',
		'BorduurPatchStudio\Admin\Settings_Page',
		'BorduurPatchStudio\Woo\Integration',
		'BorduurPatchStudio\Rest\Routes',
		'BorduurPatchStudio\Frontend\Shortcode',
		'BorduurPatchStudio\Admin\Order_Production',
	);

	/** Components that work without WooCommerce. */
	const CORE_COMPONENTS = array(
		'BorduurPatchStudio\Settings',
		'BorduurPatchStudio\Admin\Settings_Page',
	);

	/** Transient that triggers the "you're ready" notice after activation. */
	const ACTIVATED_TRANSIENT = 'bps_just_activated';

	/** @var bool */
	private static $booted = false;

	/**
	 * Boot on plugins_loaded (prio 20).
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		foreach ( self::components() as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'register' ) ) {
				$class::register();
			}
		}

		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 0 );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'script_loader_tag' ), 10, 2 );

		// WP Rocket compatibility (06 §9).
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'rocket_delay_js_exclusions' ) );
		add_filter( 'rocket_rucss_external_exclusions', array( __CLASS__, 'rocket_rucss_external_exclusions' ) );
		add_filter( 'rocket_rucss_safelist', array( __CLASS__, 'rocket_rucss_safelist' ) );
		add_filter( 'rocket_exclude_js', array( __CLASS__, 'rocket_exclude_js' ) );

		// The config is printed inside cached HTML: purge page caches after a settings change.
		add_action( 'bps_settings_updated', array( __CLASS__, 'purge_page_cache' ) );

		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BPS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Components to register, filtered; without WooCommerce only the core components.
	 *
	 * @return string[]
	 */
	public static function components(): array {
		$list = (array) apply_filters( 'bps_components', self::COMPONENTS );
		if ( ! self::woocommerce_active() ) {
			$list = array_values( array_intersect( $list, self::CORE_COMPONENTS ) );
		}
		return array_values( array_unique( array_filter( $list, 'is_string' ) ) );
	}

	/**
	 * Whether WooCommerce is loaded.
	 */
	public static function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
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
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
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
				$class::activate();
			}
		}
		do_action( 'bps_activate' );

		set_transient( self::ACTIVATED_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Deactivation hook.
	 *
	 * @param bool $network_wide Network deactivation.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_site();
				restore_current_blog();
			}
			return;
		}
		self::deactivate_site();
	}

	/**
	 * Per-site deactivation. Data is kept; only scheduled work is stopped.
	 */
	private static function deactivate_site(): void {
		foreach ( self::COMPONENTS as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'deactivate' ) ) {
				$class::deactivate();
			}
		}
		// Safety net: never leave our cron running when the plugin is off.
		wp_clear_scheduled_hook( 'bps_cleanup' );
		do_action( 'bps_deactivate' );
		self::purge_page_cache();
	}

	/**
	 * Light upgrade routine for future schema changes.
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( 'bps_db_version', 0 );
		if ( $installed >= BPS_DB_VERSION ) {
			return;
		}
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, array(), '', true );
		}
		update_option( 'bps_db_version', BPS_DB_VERSION, false );
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
	 * Register script/style handles.
	 */
	public static function register_assets(): void {
		if ( ! wp_script_is( 'bps-app', 'registered' ) ) {
			wp_register_script(
				'bps-app',
				self::asset_url( 'assets/dist/patch-studio.js' ),
				array(),
				self::asset_version( 'assets/dist/patch-studio.js' ),
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

	/**
	 * Purge full-page caches (the public config lives in cached HTML).
	 */
	public static function purge_page_cache(): void {
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
	 * Admin notices: WooCommerce missing, freshly activated.
	 */
	public static function admin_notices(): void {
		if ( ! current_user_can( 'activate_plugins' ) && ! current_user_can( self::capability() ) ) {
			return;
		}
		if ( ! self::woocommerce_active() ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Patch Studio heeft WooCommerce nodig.', 'borduur-patch-studio' ) . '</strong> ';
			echo esc_html__( 'Activeer WooCommerce om patches te laten bestellen. De instellingen kun je intussen al bekijken.', 'borduur-patch-studio' ) . '</p></div>';
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
