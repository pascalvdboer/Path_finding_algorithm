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

// --- Extra stubs for the order/account/admin classes (commerce tests). ---
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $p = '' ) { return 'https://shop.test' . $p; }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $k, $v = null, $url = '' ) {
		if ( is_array( $k ) ) { $url = (string) $v; $args = $k; } else { $args = array( $k => $v ); }
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
	}
	function remove_query_arg( $k, $url = '' ) { return (string) $url; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $v ) { return abs( (int) $v ); }
}
if ( ! function_exists( 'wc_format_decimal' ) ) {
	function wc_format_decimal( $v, $dp = 2 ) { return number_format( (float) $v, $dp, '.', '' ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
	function wpautop( $s ) { return '<p>' . $s . '</p>'; }
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() { return false; }
	function wp_doing_ajax() { return false; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) { return ! empty( $GLOBALS['bps_t']['can'] ); }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) { return 'https://shop.test/?p=' . (int) $id; }
}
if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
	function wc_get_account_endpoint_url( $e ) { return 'https://shop.test/mijn-account/' . $e . '/'; }
}
if ( ! function_exists( 'wc_terms_and_conditions_page_id' ) ) {
	function wc_terms_and_conditions_page_id() { return 0; }
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
	function esc_html__( $s, $d = null ) { return $s; }
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public $method; public $route; public $headers = array(); public $json = array(); public $params = array();
		public function __construct( $m = 'GET', $route = '' ) { $this->method = $m; $this->route = $route; }
		public function get_header( $k ) { return $this->headers[ strtolower( $k ) ] ?? null; }
		public function get_route() { return $this->route; }
		public function get_json_params() { return $this->json; }
		public function get_body_params() { return array(); }
		public function get_param( $k ) { return $this->params[ $k ] ?? ( $this->json[ $k ] ?? null ); }
	}
	class WP_REST_Response {
		public $data; public $status; public $headers = array();
		public function __construct( $d = null, $s = 200 ) { $this->data = $d; $this->status = $s; }
		public function header( $k, $v ) { $this->headers[ $k ] = $v; }
		public function get_status() { return $this->status; }
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() { return $GLOBALS['bps_t']['user'] > 0; }
}
