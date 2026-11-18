<?php
/**
 * Minimal WordPress/WooCommerce stubs for the commerce tests (no database, no network).
 * Everything is in memory; tests reset state with bps_svc_reset().
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'BPS_TESTING' ) || define( 'BPS_TESTING', true );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['bps_t'] = array(
	'options'    => array(),
	'transients' => array(),
	'actions'    => array(),
	'filters'    => array(),
	'mails'      => array(),
	'locale'     => 'nl_BE',
	'user'       => 0,
	'notices'    => array(),
);

function bps_svc_reset(): void {
	$GLOBALS['bps_t']['options']    = array();
	$GLOBALS['bps_t']['transients'] = array();
	$GLOBALS['bps_t']['actions']    = array();
	$GLOBALS['bps_t']['mails']      = array();
	$GLOBALS['bps_t']['user']       = 0;
	$GLOBALS['bps_t']['notices']    = array();
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['bps_t']['options'] ) ? $GLOBALS['bps_t']['options'][ $k ] : $d; }
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['bps_t']['options'][ $k ] = $v; return true; }
	function add_option( $k, $v = '', $dep = '', $autoload = null ) { if ( array_key_exists( $k, $GLOBALS['bps_t']['options'] ) ) { return false; } $GLOBALS['bps_t']['options'][ $k ] = $v; return true; }
	function delete_option( $k ) { unset( $GLOBALS['bps_t']['options'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['bps_t']['transients'][ $k ] ?? false; }
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['bps_t']['transients'][ $k ] = $v; return true; }
	function delete_transient( $k ) { unset( $GLOBALS['bps_t']['transients'][ $k ] ); return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['bps_t']['filters'][ $hook ] ?? array() as $cb ) {
			$value = $cb( $value, ...$args );
		}
		return $value;
	}
	function add_filter( $hook, $cb, $prio = 10, $n = 1 ) { $GLOBALS['bps_t']['filters'][ $hook ][] = $cb; return true; }
	function do_action( $hook, ...$args ) { $GLOBALS['bps_t']['actions'][] = array( $hook, $args ); }
	function add_action( $hook, $cb, $prio = 10, $n = 1 ) { return true; }
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false; }
}
if ( ! function_exists( 'wp_hash_password' ) ) {
	function wp_hash_password( $p ) { return password_hash( $p, PASSWORD_BCRYPT, array( 'cost' => 4 ) ); }
	function wp_check_password( $p, $h ) { return password_verify( $p, $h ); }
}
if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $body, $headers = '' ) { $GLOBALS['bps_t']['mails'][] = compact( 'to', 'subject', 'body' ); return true; }
}
if ( ! function_exists( 'determine_locale' ) ) {
	function determine_locale() { return $GLOBALS['bps_t']['locale']; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return $GLOBALS['bps_t']['user']; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
	function wp_unslash( $v ) { return $v; }
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_url( $s ) { return (string) $s; }
	function wp_kses_post( $s ) { return (string) $s; }
}
if ( ! function_exists( 'wc_prices_include_tax' ) ) {
	function wc_prices_include_tax() { return ! empty( $GLOBALS['bps_t']['prices_incl'] ); }
}
if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $msg, $type = 'success' ) { $GLOBALS['bps_t']['notices'][] = array( $type, $msg ); }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $fmt, $ts = null ) { return gmdate( $fmt, $ts ?? time() ); }
}
