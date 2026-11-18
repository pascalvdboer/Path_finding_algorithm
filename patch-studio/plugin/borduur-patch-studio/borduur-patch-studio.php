<?php
/**
 * Plugin Name:       Patch Studio
 * Description:       Patches online ontwerpen met deskundig advies, live prijs en digitale proef.
 * Version:           2.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * WC requires at least: 8.8
 * WC tested up to:   11.3
 * Requires Plugins:  woocommerce
 * Author:            Borduurservice België
 * Update URI:        https://borduurservicebelgie.be/
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

define( 'BPS_VERSION', '2.0.0' );
define( 'BPS_DB_VERSION', 1 );
define( 'BPS_FILE', __FILE__ );
define( 'BPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'BPS_URL', plugin_dir_url( __FILE__ ) );
define( 'BPS_MIN_PHP', '8.1' );

/*
 * Optional licence/update client (docs/premium/license-client-security.md). The update host is a constant, never a
 * setting; it may be overridden in wp-config.php (HTTPS only). Off until a licence key is saved.
 */
if ( ! defined( 'BPS_UPDATE_URL' ) ) {
	define( 'BPS_UPDATE_URL', 'https://borduurservicebelgie.be/' );
}

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
 * Autoloader following the WordPress file naming convention (SPEC §4.1); it lives in Plugin::autoload() so
 * uninstall.php can use the very same loader.
 */
require_once BPS_DIR . 'includes/class-plugin.php';
spl_autoload_register( array( \BorduurPatchStudio\Plugin::class, 'autoload' ) );

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
