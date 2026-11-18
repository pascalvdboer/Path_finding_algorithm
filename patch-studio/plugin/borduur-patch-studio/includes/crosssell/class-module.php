<?php
/**
 * Cross-sell module (SPEC-EXTRA §2): "zelfde ontwerp, meer producten" na "In winkelmand".
 * Component `BorduurPatchStudio\Crosssell\Module` (opname in Plugin::COMPONENTS = handoff). Zonder WooCommerce doet
 * de module niets (geen notices); de beheerpagina blijft bereikbaar zodat de eigenaar ze kan instellen.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Crosssell;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap.
 */
final class Module {

	const HANDLE = 'bps-crosssell';
	const JS     = 'assets/dist/patch-studio-crosssell.js';
	const CSS    = 'assets/src/crosssell/crosssell.css';

	/** @var bool */
	private static $registered = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		if ( is_admin() ) {
			Admin::register();
		}
		if ( ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) ) {
			return;
		}
		add_action( 'bps_crosssell_settings_updated', array( Products::class, 'flush' ) );
		add_filter( 'bps_public_config', array( __CLASS__, 'public_config' ), 20, 2 );
		Stats::register();
		Rest::register();
		if ( ! Config::enabled() ) {
			return;
		}
		Cart::register();
		Cart_Prompt::register();
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'maybe_enqueue_with_studio' ), 1 );
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'rocket_js' ) );
		add_filter( 'rocket_exclude_js', array( __CLASS__, 'rocket_js' ) );
		add_filter( 'rocket_rucss_external_exclusions', array( __CLASS__, 'rocket_css' ) );
	}

	/**
	 * Activatie: niets aan te maken (producten maakt de eigenaar met één knop aan).
	 */
	public static function activate(): void {}

	/**
	 * Deactivatie: geen cron of tijdelijke gegevens.
	 */
	public static function deactivate(): void {}

	/**
	 * Studio-config: laat de UI weten dat cross-sell aan staat (SPEC-EXTRA handoff 10).
	 *
	 * @param array  $config Publieke config.
	 * @param string $locale Locale.
	 */
	public static function public_config( $config, $locale = '' ) {
		if ( ! is_array( $config ) ) {
			return $config;
		}
		$extras              = isset( $config['extras'] ) && is_array( $config['extras'] ) ? $config['extras'] : array();
		$extras['crosssell'] = array(
			'enabled'           => Config::enabled() && self::bundle_exists(),
			'useStudioRenderer' => ! empty( Config::get()['useStudioRenderer'] ),
		);
		$config['extras']    = $extras;
		return $config;
	}

	/**
	 * Is de gebouwde bundel er (build-entry = handoff)?
	 */
	public static function bundle_exists(): bool {
		return is_readable( self::dir() . self::JS );
	}

	/**
	 * Plugin-map.
	 */
	private static function dir(): string {
		return defined( 'BPS_DIR' ) ? BPS_DIR : dirname( __DIR__, 2 ) . '/';
	}

	/**
	 * Plugin-URL.
	 */
	private static function url(): string {
		return defined( 'BPS_URL' ) ? BPS_URL : plugin_dir_url( dirname( __DIR__ ) );
	}

	/**
	 * Script en stijl laden (idempotent) met de config als inline script.
	 */
	public static function enqueue(): void {
		if ( ! Config::enabled() || ! self::bundle_exists() || wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}
		$ver = defined( 'BPS_VERSION' ) ? BPS_VERSION : '1.0.0';
		wp_enqueue_style( self::HANDLE, self::url() . self::CSS, array(), $ver . '-' . (int) @filemtime( self::dir() . self::CSS ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_script( self::HANDLE, self::url() . self::JS, array(), $ver . '-' . (int) @filemtime( self::dir() . self::JS ), array( 'in_footer' => true ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_add_inline_script( self::HANDLE, 'window.BPSCrossSellConfig = ' . wp_json_encode( self::client_config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE ) . ';', 'before' );
	}

	/**
	 * Config voor de browser (geen nonce, geen persoonsgegevens → veilig in gecachte pagina's).
	 */
	public static function client_config(): array {
		$cfg = Config::get();
		return array(
			'enabled'       => Config::enabled(),
			'locale'        => Config::locale(),
			'rest'          => array( 'root' => esc_url_raw( rest_url( Rest::NS . '/' ) ) ),
			'cartUrl'       => function_exists( 'wc_get_cart_url' ) ? esc_url_raw( wc_get_cart_url() ) : '',
			'strings'       => Config::strings(),
			'openTimeoutMs' => max( 1000, min( 8000, (int) ( $cfg['openTimeoutMs'] ?? 4000 ) ) ),
		);
	}

	/**
	 * Mee laden zodra de studio (bps-app) op de pagina staat.
	 */
	public static function maybe_enqueue_with_studio(): void {
		if ( wp_script_is( 'bps-app', 'enqueued' ) || wp_script_is( 'bps-app', 'done' ) ) {
			self::enqueue();
		}
	}

	/**
	 * WP Rocket: niet uitstellen/combineren (het paneel moet meteen na "In winkelmand" klaar staan).
	 *
	 * @param mixed $list Lijst.
	 */
	public static function rocket_js( $list ): array {
		$list   = is_array( $list ) ? $list : array();
		$list[] = 'patch-studio-crosssell';
		$list[] = 'BPSCrossSellConfig';
		return $list;
	}

	/**
	 * WP Rocket RUCSS: de stijl is enkel nodig zodra het paneel opent.
	 *
	 * @param mixed $list Lijst.
	 */
	public static function rocket_css( $list ): array {
		$list   = is_array( $list ) ? $list : array();
		$list[] = '/borduur-patch-studio/assets/src/crosssell/crosssell.css';
		return $list;
	}
}
