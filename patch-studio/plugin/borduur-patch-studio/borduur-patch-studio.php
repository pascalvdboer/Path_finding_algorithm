<?php
/**
 * Plugin Name:       Patch Studio – Borduurservice België
 * Plugin URI:        https://borduurservicebelgie.be/
 * Description:       Patches online ontwerpen met deskundig advies, live prijs en digitale proef.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * WC requires at least: 8.8
 * WC tested up to:   9.9
 * Author:            Borduurservice België
 * Text Domain:       borduur-patch-studio
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Source strings: nl_BE
 *
 * @package BorduurPatchStudio
 */

defined( 'ABSPATH' ) || exit;

define( 'BPS_VERSION', '1.0.0' );
define( 'BPS_DB_VERSION', 1 );
define( 'BPS_FILE', __FILE__ );
define( 'BPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'BPS_URL', plugin_dir_url( __FILE__ ) );
define( 'BPS_MIN_PHP', '8.1' );

/*
 * Refuse to run on an unsupported PHP version instead of fataling the site.
 */
if ( version_compare( PHP_VERSION, BPS_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					__( 'Patch Studio heeft PHP %1$s of hoger nodig. Je server draait PHP %2$s.', 'borduur-patch-studio' ),
					BPS_MIN_PHP,
					PHP_VERSION
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

/*
 * PSR-4-like autoloader following the WordPress file naming convention (SPEC §4.1):
 *   BorduurPatchStudio\Woo\Order_Meta     -> includes/woo/class-order-meta.php
 *   BorduurPatchStudio\Settings           -> includes/class-settings.php
 *   BorduurPatchStudio\Admin\Settings_Page -> includes/admin/class-settings-page.php
 */
spl_autoload_register(
	static function ( string $class ): void {
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
		$file = BPS_DIR . 'includes/' . $dir . 'class-' . str_replace( '_', '-', strtolower( $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/*
 * Declare compatibility with WooCommerce HPOS and cart/checkout blocks (06 §6c).
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BPS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BPS_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( \BorduurPatchStudio\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \BorduurPatchStudio\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \BorduurPatchStudio\Plugin::class, 'boot' ), 20 );
