<?php
/**
 * WooCommerce integration entry point (component listed in Plugin::COMPONENTS).
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
	 * WooCommerce loaded?
	 */
	private static function woo_ready(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}
}
