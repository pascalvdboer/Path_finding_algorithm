<?php
/**
 * Minimal WordPress stubs for the security tests (no database, no network, no WordPress).
 * State lives in $GLOBALS['bps_sec']; tests reset it with bps_sec_reset().
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'BPS_TESTING' ) || define( 'BPS_TESTING', true );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'KB_IN_BYTES' ) || define( 'KB_IN_BYTES', 1024 );
defined( 'MB_IN_BYTES' ) || define( 'MB_IN_BYTES', 1048576 );
defined( 'GB_IN_BYTES' ) || define( 'GB_IN_BYTES', 1073741824 );

$GLOBALS['bps_sec'] = array();

/** Reset all in-memory WordPress state. */
function bps_sec_reset(): void {
	$GLOBALS['bps_sec'] = array(
		'options'    => array(),
		'transients' => array(),
		'cache'      => array(),
		'ext_cache'  => false,
		'filters'    => array(),
		'actions'    => array(),
		'posts'      => array(),
		'meta'       => array(),
		'user'       => 0,
		'auth_user'  => 0,
		'locale'     => 'nl_BE',
		'home'       => 'https://shop.example.test',
		'site'       => 'https://shop.example.test',
		'upload_dir' => sys_get_temp_dir() . '/bps-sec-uploads-' . getmypid(),
		'max_upload' => 0,
	);
}
bps_sec_reset();

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
function is_wp_error( $v ) { return $v instanceof WP_Error; }

/** Small REST request double (headers are case-insensitive, like WP_REST_Request). */
class WP_REST_Request implements ArrayAccess {
	private $route;
	private $params;
	private $headers = array();
	private $files   = array();
	public function __construct( string $method = 'POST', string $route = '', array $params = array() ) {
		$this->route  = $route;
		$this->params = $params;
	}
	public function set_header( string $k, $v ): void { $this->headers[ strtolower( str_replace( '-', '_', $k ) ) ] = $v; }
	public function get_header( $k ) {
		$k = strtolower( str_replace( '-', '_', $k ) );
		return $this->headers[ $k ] ?? null;
	}
	public function get_route() { return $this->route; }
	public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	public function set_param( $k, $v ): void { $this->params[ $k ] = $v; }
	public function get_json_params() { return $this->params; }
	public function get_body_params() { return $this->params; }
	public function get_file_params() { return $this->files; }
	public function set_file_params( array $f ): void { $this->files = $f; }
	#[\ReturnTypeWillChange]
	public function offsetExists( $o ) { return isset( $this->params[ $o ] ); }
	#[\ReturnTypeWillChange]
	public function offsetGet( $o ) { return $this->params[ $o ] ?? null; }
	#[\ReturnTypeWillChange]
	public function offsetSet( $o, $v ) { $this->params[ $o ] = $v; }
	#[\ReturnTypeWillChange]
	public function offsetUnset( $o ) { unset( $this->params[ $o ] ); }
}
class WP_REST_Response {
	public $data;
	public $status;
	public $headers = array();
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function header( $k, $v ) { $this->headers[ $k ] = $v; }
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
}
class WP_REST_Server {
	const READABLE  = 'GET';
	const CREATABLE = 'POST';
}

/* i18n / escaping / sanitizing ------------------------------------------------------- */
function __( $s, $d = null ) { return $s; }
function _x( $s, $c, $d = null ) { return $s; }
function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url_raw( $s ) { return (string) $s; }
function esc_url( $s ) { return (string) $s; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function wp_slash( $v ) { return is_string( $v ) ? addslashes( $v ) : $v; }
function wp_check_invalid_utf8( $s ) { return mb_check_encoding( (string) $s, 'UTF-8' ) ? (string) $s : ''; }
function _bps_sanitize_text( $str, bool $keep_newlines ) {
	$str = wp_check_invalid_utf8( (string) $str );
	$str = strip_tags( $str );
	if ( ! $keep_newlines ) {
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	}
	$str = preg_replace( '/%[a-f0-9]{2}/i', '', $str );
	return trim( (string) $str );
}
function sanitize_text_field( $s ) { return _bps_sanitize_text( $s, false ); }
function sanitize_textarea_field( $s ) { return _bps_sanitize_text( $s, true ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_email( $s ) { return filter_var( trim( (string) $s ), FILTER_SANITIZE_EMAIL ); }
function is_email( $s ) { return false !== filter_var( (string) $s, FILTER_VALIDATE_EMAIL ) ? $s : false; }
function sanitize_hex_color( $c ) { return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $c ) ? $c : null; }
function sanitize_file_name( $s ) { return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $s ); }
function wp_basename( $p ) { return basename( (string) $p ); }
function wp_kses_post( $s ) { return strip_tags( (string) $s, '<a><strong><em><br><p>' ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
function absint( $v ) { return abs( (int) $v ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function wp_convert_hr_to_bytes( $v ) {
	$v     = strtolower( trim( (string) $v ) );
	$bytes = (int) $v;
	if ( false !== strpos( $v, 'g' ) ) {
		$bytes *= GB_IN_BYTES;
	} elseif ( false !== strpos( $v, 'm' ) ) {
		$bytes *= MB_IN_BYTES;
	} elseif ( false !== strpos( $v, 'k' ) ) {
		$bytes *= KB_IN_BYTES;
	}
	return min( $bytes, PHP_INT_MAX );
}

/* Hooks ----------------------------------------------------------------------------- */
function add_filter( $hook, $cb, $prio = 10, $n = 1 ) { $GLOBALS['bps_sec']['filters'][ $hook ][] = $cb; return true; }
function add_action( $hook, $cb, $prio = 10, $n = 1 ) { return add_filter( $hook, $cb, $prio, $n ); }
function remove_all_filters( $hook ) { unset( $GLOBALS['bps_sec']['filters'][ $hook ] ); return true; }
function has_filter( $hook, $cb = false ) { return ! empty( $GLOBALS['bps_sec']['filters'][ $hook ] ); }
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['bps_sec']['filters'][ $hook ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function do_action( $hook, ...$args ) {
	$GLOBALS['bps_sec']['actions'][] = array( $hook, $args );
	foreach ( $GLOBALS['bps_sec']['filters'][ $hook ] ?? array() as $cb ) {
		$cb( ...$args );
	}
}
function did_action( $hook ) {
	$n = 0;
	foreach ( $GLOBALS['bps_sec']['actions'] as $a ) {
		$n += $a[0] === $hook ? 1 : 0;
	}
	return $n;
}

/* Options, transients, object cache -------------------------------------------------- */
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['bps_sec']['options'] ) ? $GLOBALS['bps_sec']['options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['bps_sec']['options'][ $k ] = $v; return true; }
function add_option( $k, $v = '', $dep = '', $autoload = null ) {
	if ( array_key_exists( $k, $GLOBALS['bps_sec']['options'] ) ) {
		return false;
	}
	$GLOBALS['bps_sec']['options'][ $k ] = $v;
	return true;
}
function delete_option( $k ) { unset( $GLOBALS['bps_sec']['options'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['bps_sec']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['bps_sec']['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['bps_sec']['transients'][ $k ] ); return true; }
function wp_using_ext_object_cache( $using = null ) { return (bool) $GLOBALS['bps_sec']['ext_cache']; }
function wp_cache_add( $k, $v, $g = '', $ttl = 0 ) {
	if ( isset( $GLOBALS['bps_sec']['cache'][ $g ][ $k ] ) ) {
		return false;
	}
	$GLOBALS['bps_sec']['cache'][ $g ][ $k ] = $v;
	return true;
}
function wp_cache_incr( $k, $n = 1, $g = '' ) {
	if ( ! isset( $GLOBALS['bps_sec']['cache'][ $g ][ $k ] ) ) {
		return false;
	}
	$GLOBALS['bps_sec']['cache'][ $g ][ $k ] = (int) $GLOBALS['bps_sec']['cache'][ $g ][ $k ] + $n;
	return $GLOBALS['bps_sec']['cache'][ $g ][ $k ];
}
function wp_cache_get( $k, $g = '' ) { return $GLOBALS['bps_sec']['cache'][ $g ][ $k ] ?? false; }
function wp_cache_set( $k, $v, $g = '', $ttl = 0 ) { $GLOBALS['bps_sec']['cache'][ $g ][ $k ] = $v; return true; }
function wp_cache_delete( $k, $g = '' ) { unset( $GLOBALS['bps_sec']['cache'][ $g ][ $k ] ); return true; }

/* Security helpers ------------------------------------------------------------------- */
function wp_salt( $scheme = 'auth' ) { return 'unit-salt-' . $scheme; }
function wp_create_nonce( $action = -1 ) { return substr( hash_hmac( 'sha256', $action . '|' . $GLOBALS['bps_sec']['user'], wp_salt( 'nonce' ) ), 0, 10 ); }
function wp_verify_nonce( $nonce, $action = -1 ) { return hash_equals( wp_create_nonce( $action ), (string) $nonce ) ? 1 : false; }
function is_user_logged_in() { return $GLOBALS['bps_sec']['user'] > 0; }
function get_current_user_id() { return (int) $GLOBALS['bps_sec']['user']; }
function wp_set_current_user( $id ) { $GLOBALS['bps_sec']['user'] = (int) $id; return (object) array( 'ID' => (int) $id ); }
function wp_validate_auth_cookie( $cookie = '', $scheme = '' ) { return $GLOBALS['bps_sec']['auth_user'] > 0 ? $GLOBALS['bps_sec']['auth_user'] : false; }
function current_user_can( $cap ) { return false; }

/* URLs, locale ---------------------------------------------------------------------- */
function home_url( $path = '' ) { return rtrim( $GLOBALS['bps_sec']['home'], '/' ) . ( '' !== $path ? '/' . ltrim( (string) $path, '/' ) : '' ); }
function site_url( $path = '' ) { return rtrim( $GLOBALS['bps_sec']['site'], '/' ) . ( '' !== $path ? '/' . ltrim( (string) $path, '/' ) : '' ); }
function admin_url( $path = '' ) { return site_url( 'wp-admin/' . ltrim( (string) $path, '/' ) ); }
function set_url_scheme( $url ) { return $url; }
function determine_locale() { return $GLOBALS['bps_sec']['locale']; }
function get_locale() { return $GLOBALS['bps_sec']['locale']; }
function switch_to_locale( $l ) { $GLOBALS['bps_sec']['locale'] = $l; return true; }
function restore_previous_locale() { $GLOBALS['bps_sec']['locale'] = 'nl_BE'; return true; }

/* Uploads ---------------------------------------------------------------------------- */
function wp_upload_dir( $time = null, $create = true ) {
	return array(
		'basedir' => $GLOBALS['bps_sec']['upload_dir'],
		'baseurl' => 'https://shop.example.test/wp-content/uploads',
	);
}
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || @mkdir( $dir, 0777, true ); }
function wp_max_upload_size() { return (int) $GLOBALS['bps_sec']['max_upload']; }
function wp_is_writable( $p ) { return is_writable( $p ); }
function wp_delete_file( $f ) { @unlink( $f ); }
function wp_delete_file_from_directory( $f, $dir ) { return @unlink( $f ); }

/* Posts / meta ------------------------------------------------------------------------ */
function get_post( $id ) { return $GLOBALS['bps_sec']['posts'][ (int) $id ] ?? null; }
function get_post_meta( $id, $k = '', $single = false ) { return $GLOBALS['bps_sec']['meta'][ (int) $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['bps_sec']['meta'][ (int) $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['bps_sec']['meta'][ (int) $id ][ $k ] ); return true; }
function get_post_field( $f, $id ) { $p = get_post( $id ); return $p ? ( $p->$f ?? '' ) : ''; }
/** Register a fake post. */
function bps_sec_post( int $id, string $type, array $meta = array(), string $title = '' ): void {
	$GLOBALS['bps_sec']['posts'][ $id ] = (object) array(
		'ID'         => $id,
		'post_type'  => $type,
		'post_title' => $title,
	);
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
}
