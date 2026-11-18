<?php
/**
 * WooCommerce integration entry point (component listed in Plugin::COMPONENTS). Checks the minimum WC version.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

use BorduurPatchStudio\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Registers all Woo sub-components. Cart model: own_product (SPEC D1).
 *
 * HPOS: every order read/write in this plugin goes through WC CRUD (wc_get_order, $order->get_items(),
 * $item->add_meta_data()/get_meta(), wc_get_orders()). Designs and quotes are our own CPTs, not orders.
 * Compatibility with custom_order_tables and cart_checkout_blocks is declared by core in the main plugin
 * file on before_woocommerce_init.
 */
final class Integration {

	/** Minimum WooCommerce version (also in the plugin header "WC requires at least"). */
	const MIN_WC = '8.8';

	/** Sub-components in registration order. */
	const PARTS = array(
		Designs::class,
		Product::class,
		Cart::class,
		Order::class,
		Emails::class,
		Quotes::class,
		Privacy::class,
		Cleanup::class,
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		if ( ! self::woo_ready() ) {
			if ( self::woo_loaded() ) {
				// WooCommerce is there but too old: say so instead of half-working.
				add_action( 'admin_notices', array( __CLASS__, 'version_notice' ) );
			}
			return;
		}
		foreach ( self::PARTS as $part ) {
			$part::register();
		}
	}

	/**
	 * Activation (called by Plugin::activate()).
	 */
	public static function activate(): void {
		if ( ! self::woo_ready() ) {
			return;
		}
		Designs::register_post_type();
		Quotes::register_post_type();
		Uploads::ensure_dirs();
		Product::activate();
		Cleanup::activate();
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate(): void {
		Cleanup::deactivate();
	}

	/**
	 * WooCommerce loaded (any version)?
	 */
	private static function woo_loaded(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	/**
	 * WooCommerce loaded and recent enough?
	 */
	private static function woo_ready(): bool {
		return self::woo_loaded() && self::wc_version_ok();
	}

	/**
	 * The running WooCommerce version ('' when unknown).
	 */
	public static function wc_version(): string {
		if ( defined( 'WC_VERSION' ) ) {
			return (string) WC_VERSION;
		}
		if ( function_exists( 'WC' ) ) {
			$wc = WC();
			if ( is_object( $wc ) && isset( $wc->version ) ) {
				return (string) $wc->version;
			}
		}
		return '';
	}

	/**
	 * Whether the running WooCommerce is at least MIN_WC. An unknown version is accepted (cannot be checked).
	 */
	public static function wc_version_ok(): bool {
		$version = self::wc_version();
		return '' === $version || version_compare( $version, self::MIN_WC, '>=' );
	}

	/**
	 * Admin notice for a too old WooCommerce.
	 */
	public static function version_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Patch Studio werkt niet met deze WooCommerce-versie.', 'borduur-patch-studio' ) . '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: 1: required WooCommerce version, 2: installed WooCommerce version */
				__( 'Patch Studio heeft WooCommerce %1$s of hoger nodig. Je gebruikt versie %2$s. Werk WooCommerce bij; tot dan staan de winkelfuncties van Patch Studio uit.', 'borduur-patch-studio' ),
				self::MIN_WC,
				self::wc_version()
			)
		);
		echo '</p></div>';
	}
}
