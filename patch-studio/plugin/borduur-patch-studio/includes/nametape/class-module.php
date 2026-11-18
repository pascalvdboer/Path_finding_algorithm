<?php
/**
 * Name tapes: module entry (SPEC-EXTRA D10). Module `nametape` of the registry in Plugin::modules()
 * (switch `modules.nametape.enabled`, default on). Without WooCommerce it does nothing.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Nametape;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the name-tape classes.
 */
final class Module {

	/** @var bool */
	private static $registered = false;

	/** @var bool */
	private static $registered_always = false;

	/**
	 * Is WooCommerce there?
	 */
	private static function woo(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	/**
	 * Hooks that stay when the module is off in the registry: the order tools for orders that already contain name
	 * tapes (CSV, print sheet, preview cleanup handler), the privacy hooks, and an empty shortcode so a page with
	 * [patch_studio_nametape] never shows the raw tag. No cron is scheduled from here.
	 */
	public static function register_always(): void {
		if ( self::$registered_always || ! self::woo() ) {
			return;
		}
		self::$registered_always = true;
		Order_Tools::register();
		if ( class_exists( __NAMESPACE__ . '\\Privacy' ) && method_exists( __NAMESPACE__ . '\\Privacy', 'register' ) ) {
			Privacy::register();
		}
		if ( ! shortcode_exists( Shortcode::TAG ) ) {
			add_shortcode( Shortcode::TAG, '__return_empty_string' );
		}
	}

	/**
	 * Hooks when the module is on. Cart/order/admin parts always run (existing cart lines and orders keep working
	 * when the name-tape mode itself is switched off in its own settings); the shortcode and REST routes only when
	 * that mode is enabled.
	 */
	public static function register(): void {
		if ( self::$registered || ! self::woo() ) {
			return;
		}
		self::$registered = true;
		self::register_always();

		Cart::register();
		Product::register();
		if ( is_admin() ) {
			Admin::register();
		}
		if ( Config::enabled() ) {
			Shortcode::register();
			Rest::register();
		} else {
			// Shortcode on a page while switched off: render nothing instead of the raw tag.
			add_shortcode( Shortcode::TAG, '__return_empty_string' );
		}

		add_filter( 'bps_public_config', array( __CLASS__, 'public_config' ), 20, 2 );
		add_action( 'init', array( Order_Tools::class, 'schedule' ), 30 );
		add_action( 'bps_nametape_settings_updated', array( __CLASS__, 'settings_updated' ) );
	}

	/**
	 * Studio button data (§1.9): extras.nametape = {enabled, pageUrl}.
	 *
	 * @param mixed  $config Public config.
	 * @param string $locale Locale.
	 */
	public static function public_config( $config, $locale = '' ) {
		if ( ! is_array( $config ) ) {
			return $config;
		}
		$url                            = Config::enabled() ? Config::page_url() : '';
		$config['extras']               = isset( $config['extras'] ) && is_array( $config['extras'] ) ? $config['extras'] : array();
		$config['extras']['nametape']   = array(
			'enabled' => Config::enabled() && '' !== $url,
			'pageUrl' => $url,
		);
		return $config;
	}

	/**
	 * Activation (plugin activation with the module on, or switching it on): product + cron.
	 */
	public static function activate(): void {
		try {
			if ( self::woo() && Config::enabled() ) {
				Product::ensure();
			}
			Order_Tools::schedule();
		} catch ( \Throwable $e ) {
			\BorduurPatchStudio\Logger::error( 'nametape_activate_failed', array( 'exception' => $e ) );
		}
	}

	/**
	 * Deactivation: stop the cron (data stays).
	 */
	public static function deactivate(): void {
		Order_Tools::unschedule();
	}

	/**
	 * Data owned by the module (merged into Plugin::owned_data(), also during uninstall).
	 *
	 * @return array<string, string[]>
	 */
	public static function owned_data(): array {
		return array(
			'options'      => array( Config::OPTION, Config::PRODUCT_OPTION, Config::PAGE_OPTION ),
			'cron_hooks'   => array( Order_Tools::CRON ),
			'product_meta' => array( Product::META_FLAG ),
		);
	}

	/**
	 * Settings saved: forget the cached config.
	 */
	public static function settings_updated(): void {
		Config::flush();
	}
}
