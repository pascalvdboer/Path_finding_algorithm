<?php
/**
 * Name tapes: module entry (SPEC-EXTRA D10). Registered via Plugin::COMPONENTS (handoff #1) or the `bps_components` filter.
 * Without WooCommerce it does nothing.
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

	/**
	 * Is WooCommerce there?
	 */
	private static function woo(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	/**
	 * Hooks. Cart/order/admin parts always run (existing cart lines and orders keep working when the module is switched
	 * off); the shortcode and REST routes only when the module is enabled.
	 */
	public static function register(): void {
		if ( self::$registered || ! self::woo() ) {
			return;
		}
		self::$registered = true;

		Cart::register();
		Product::register();
		Order_Tools::register();
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
		add_action( 'bps_activate', array( __CLASS__, 'activate' ) );
		add_action( 'bps_deactivate', array( __CLASS__, 'deactivate' ) );
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
	 * Activation: product + cron.
	 */
	public static function activate(): void {
		if ( self::woo() && Config::enabled() ) {
			Product::ensure();
		}
		Order_Tools::schedule();
	}

	/**
	 * Deactivation: stop the cron (data stays).
	 */
	public static function deactivate(): void {
		Order_Tools::unschedule();
	}

	/**
	 * Settings saved: forget the cached config.
	 */
	public static function settings_updated(): void {
		Config::flush();
	}
}
