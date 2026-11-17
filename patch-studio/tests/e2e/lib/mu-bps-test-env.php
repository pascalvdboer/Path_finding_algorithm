<?php
/**
 * Plugin Name: Patch Studio test environment helpers
 * Description: Only for the local e2e site. Catches outgoing mail into wp-content/bps-mails/*.json
 *              (php -S has no sendmail) and silences admin noise that gets in the way of tests.
 *
 * Installed as a must-use plugin by tests/e2e/start-wp.sh. Never ship this to production.
 */

defined( 'ABSPATH' ) || exit;

/*
 * Mail catcher: short-circuit wp_mail() and store every message as JSON so e2e
 * scenarios can assert on order/proof/quote mails without an SMTP server.
 */
add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) {
		$dir = WP_CONTENT_DIR . '/bps-mails';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$to   = is_array( $atts['to'] ) ? $atts['to'] : explode( ',', (string) $atts['to'] );
		$data = array(
			'time'        => gmdate( 'c' ),
			'to'          => array_map( 'trim', $to ),
			'subject'     => (string) $atts['subject'],
			'headers'     => $atts['headers'],
			'attachments' => $atts['attachments'],
			'message'     => (string) $atts['message'],
		);
		$name = sprintf( '%s-%s.json', gmdate( 'Ymd-His' ), substr( md5( wp_json_encode( $data ) . microtime() ), 0, 8 ) );
		file_put_contents( $dir . '/' . $name, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return true;
	},
	10,
	2
);

// No WooCommerce marketing/onboarding redirects or remote calls in the test site.
add_filter( 'woocommerce_prevent_automatic_wizard_redirect', '__return_true' );
add_filter( 'woocommerce_allow_marketplace_suggestions', '__return_false' );
add_filter( 'woocommerce_admin_features', static function ( $features ) {
	return array_values( array_diff( (array) $features, array( 'remote-inbox-notifications', 'remote-free-extensions', 'payment-gateway-suggestions', 'marketing', 'shipping-label-banner' ) ) );
} );
add_filter( 'pre_option_woocommerce_coming_soon', static function () {
	return 'no';
} );

// Keep the admin bar out of front-end screenshots unless explicitly wanted.
add_filter( 'show_admin_bar', static function ( $show ) {
	return isset( $_GET['bps_adminbar'] ) ? $show : false; // phpcs:ignore WordPress.Security.NonceVerification
} );
