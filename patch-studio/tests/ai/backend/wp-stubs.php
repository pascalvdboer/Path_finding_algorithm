<?php
/**
 * Minimal WordPress stubs for the ai-backend PHP tests (no WordPress, no network, no database).
 *
 * Options/transients live in arrays, hooks are a tiny registry, HTTP goes through $GLOBALS['bps_http'] (a callable
 * that receives (url, args) and returns a response array or WP_Error). Everything is reset by bps_test_reset().
 *
 * @package BorduurPatchStudio
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'BPS_TESTING', true );
define( 'BPS_DIR', dirname( __DIR__, 3 ) . '/plugin/borduur-patch-studio/' );
define( 'BPS_URL', 'https://shop.test/wp-content/plugins/borduur-patch-studio/' );
define( 'BPS_VERSION', '1.0.0-test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['bps_options']    = array();
$GLOBALS['bps_transients'] = array();
$GLOBALS['bps_hooks']      = array();
$GLOBALS['bps_mails']      = array();
$GLOBALS['bps_http']       = null;
$GLOBALS['bps_http_log']   = array();
$GLOBALS['bps_user']       = 0;
$GLOBALS['bps_caps']       = array();
$GLOBALS['bps_meta']       = array();

/**
 * Reset global state between tests.
 */
function bps_test_reset(): void {
	$GLOBALS['bps_options']    = array();
	$GLOBALS['bps_transients'] = array();
	$GLOBALS['bps_hooks']      = array();
	$GLOBALS['bps_mails']      = array();
	$GLOBALS['bps_http']       = null;
	$GLOBALS['bps_http_log']   = array();
	$GLOBALS['bps_user']       = 0;
	$GLOBALS['bps_caps']       = array();
	$GLOBALS['bps_meta']       = array();
}

class WP_Error {
	public $errors = array();
	public $error_data = array();
	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( '' !== $code ) {
			$this->errors[ $code ][] = $message;
			$this->error_data[ $code ] = $data;
		}
	}
	public function get_error_code() {
		$c = array_keys( $this->errors );
		return $c[0] ?? '';
	}
	public function get_error_message( $code = '' ) {
		$code = '' === $code ? $this->get_error_code() : $code;
		return $this->errors[ $code ][0] ?? '';
	}
	public function get_error_data( $code = '' ) {
		$code = '' === $code ? $this->get_error_code() : $code;
		return $this->error_data[ $code ] ?? null;
	}
}

function is_wp_error( $v ): bool {
	return $v instanceof WP_Error;
}

/* Hooks ---------------------------------------------------------------- */

function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['bps_hooks'][ $tag ][ $prio ][] = array( $cb, $args );
	return true;
}
function add_action( $tag, $cb, $prio = 10, $args = 1 ) {
	return add_filter( $tag, $cb, $prio, $args );
}
function remove_all_filters( $tag ) {
	unset( $GLOBALS['bps_hooks'][ $tag ] );
}
function apply_filters( $tag, $value, ...$rest ) {
	if ( empty( $GLOBALS['bps_hooks'][ $tag ] ) ) {
		return $value;
	}
	ksort( $GLOBALS['bps_hooks'][ $tag ] );
	foreach ( $GLOBALS['bps_hooks'][ $tag ] as $cbs ) {
		foreach ( $cbs as $c ) {
			$value = call_user_func_array( $c[0], array_slice( array_merge( array( $value ), $rest ), 0, max( 1, (int) $c[1] ) ) );
		}
	}
	return $value;
}
function do_action( $tag, ...$args ) {
	$GLOBALS['bps_actions'][ $tag ] = ( $GLOBALS['bps_actions'][ $tag ] ?? 0 ) + 1;
	if ( empty( $GLOBALS['bps_hooks'][ $tag ] ) ) {
		return;
	}
	foreach ( $GLOBALS['bps_hooks'][ $tag ] as $cbs ) {
		foreach ( $cbs as $c ) {
			call_user_func_array( $c[0], array_slice( $args, 0, max( 0, (int) $c[1] ) ) );
		}
	}
}

/* Options & transients -------------------------------------------------- */

function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['bps_options'] ) ? $GLOBALS['bps_options'][ $k ] : $d;
}
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['bps_options'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['bps_options'][ $k ] );
	return true;
}
function get_transient( $k ) {
	return $GLOBALS['bps_transients'][ $k ] ?? false;
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['bps_transients'][ $k ] = $v;
	return true;
}
function delete_transient( $k ) {
	unset( $GLOBALS['bps_transients'][ $k ] );
	return true;
}
function get_post_meta( $id, $k, $single = false ) {
	return $GLOBALS['bps_meta'][ $id ][ $k ] ?? '';
}
function update_post_meta( $id, $k, $v ) {
	$GLOBALS['bps_meta'][ $id ][ $k ] = $v;
	return true;
}

/* Users ------------------------------------------------------------------ */

function get_current_user_id() {
	return (int) $GLOBALS['bps_user'];
}
function is_user_logged_in() {
	return $GLOBALS['bps_user'] > 0;
}
function current_user_can( $cap ) {
	return in_array( $cap, $GLOBALS['bps_caps'], true );
}
function get_userdata( $id ) {
	return (object) array( 'ID' => $id, 'user_email' => 'user' . $id . '@example.test' );
}
function get_user_by( $field, $value ) {
	if ( 'email' === $field && preg_match( '/^user(\d+)@example\.test$/', (string) $value, $m ) ) {
		return (object) array( 'ID' => (int) $m[1], 'user_email' => $value );
	}
	return false;
}

/* Misc ------------------------------------------------------------------ */

function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}
function wp_json_encode( $v, $flags = 0 ) {
	return json_encode( $v, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}
function wp_strip_all_tags( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_text_field( $s ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) );
}
function sanitize_key( $s ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
}
function sanitize_email( $s ) {
	return filter_var( trim( (string) $s ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $s ) : '';
}
function is_email( $s ) {
	return false !== filter_var( (string) $s, FILTER_VALIDATE_EMAIL ) ? $s : false;
}
function wp_unslash( $v ) {
	return $v;
}
function absint( $v ) {
	return abs( (int) $v );
}
function __( $s, $d = '' ) {
	return $s;
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_html__( $s, $d = '' ) {
	return esc_html( $s );
}
function esc_url( $s ) {
	return (string) $s;
}
function number_format_i18n( $n, $d = 0 ) {
	return number_format( (float) $n, $d, ',', '.' );
}
function wp_date( $format, $ts = null ) {
	$dt = new DateTimeImmutable( '@' . ( null === $ts ? time() : (int) $ts ) );
	return $dt->setTimezone( new DateTimeZone( 'Europe/Brussels' ) )->format( $format );
}
function wp_timezone() {
	return new DateTimeZone( 'Europe/Brussels' );
}
function wp_mail( $to, $subject, $body ) {
	$GLOBALS['bps_mails'][] = compact( 'to', 'subject', 'body' );
	return true;
}
function admin_url( $p = '' ) {
	return 'https://shop.test/wp-admin/' . $p;
}
function home_url( $p = '' ) {
	return 'https://shop.test/' . ltrim( $p, '/' );
}
function site_url( $p = '' ) {
	return home_url( $p );
}
function rest_url( $p = '' ) {
	return 'https://shop.test/wp-json/' . ltrim( $p, '/' );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function add_query_arg( $args, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}
function wp_upload_dir( $time = null, $create = true ) {
	return array( 'basedir' => $GLOBALS['bps_upload_base'], 'baseurl' => 'https://shop.test/wp-content/uploads' );
}
function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}
function wp_is_writable( $p ) {
	return is_writable( $p );
}
function wp_normalize_path( $p ) {
	return str_replace( '\\', '/', $p );
}
function wp_next_scheduled( $hook ) {
	return false;
}
function wp_verify_nonce( $n, $a = -1 ) {
	return false;
}
function __return_null() {
	return null;
}
function determine_locale() {
	return 'nl_BE';
}

/* HTTP ------------------------------------------------------------------ */

function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['bps_http_log'][] = array( 'url' => $url, 'args' => $args );
	$h = $GLOBALS['bps_http'];
	return is_callable( $h ) ? $h( $url, $args ) : new WP_Error( 'http_request_failed', 'no network in tests' );
}
function wp_safe_remote_get( $url, $args = array() ) {
	return wp_remote_request( $url, $args + array( 'method' => 'GET' ) );
}
function wp_remote_retrieve_response_code( $r ) {
	return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0;
}
function wp_remote_retrieve_body( $r ) {
	return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : '';
}

/* Autoloader for the plugin's AI classes -------------------------------- */

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'BorduurPatchStudio\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class, strlen( $prefix ) ) );
		$name  = array_pop( $parts );
		$dir   = '';
		foreach ( $parts as $p ) {
			$dir .= str_replace( '_', '-', strtolower( $p ) ) . '/';
		}
		$file = BPS_DIR . 'includes/' . $dir . 'class-' . str_replace( '_', '-', strtolower( $name ) ) . '.php';
		if ( 'Prompt_Engine' === $name ) {
			$file = BPS_DIR . 'includes/ai/class-ai-prompt-engine.php';
		}
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/* REST ------------------------------------------------------------------ */

class WP_REST_Request implements ArrayAccess {
	public $headers = array();
	public $params  = array();
	public $json    = null;
	public $route   = '';
	public function __construct( $method = 'GET', $route = '' ) {
		$this->route = $route;
	}
	public function get_header( $k ) {
		return $this->headers[ strtolower( $k ) ] ?? null;
	}
	public function set_header( $k, $v ) {
		$this->headers[ strtolower( $k ) ] = $v;
	}
	public function get_param( $k ) {
		return $this->params[ $k ] ?? ( is_array( $this->json ) ? ( $this->json[ $k ] ?? null ) : null );
	}
	public function set_param( $k, $v ) {
		$this->params[ $k ] = $v;
	}
	public function get_json_params() {
		return $this->json;
	}
	public function get_body_params() {
		return array();
	}
	public function get_route() {
		return $this->route;
	}
	public function offsetExists( $o ): bool {
		return isset( $this->params[ $o ] );
	}
	#[\ReturnTypeWillChange]
	public function offsetGet( $o ) {
		return $this->params[ $o ] ?? null;
	}
	public function offsetSet( $o, $v ): void {
		$this->params[ $o ] = $v;
	}
	public function offsetUnset( $o ): void {
		unset( $this->params[ $o ] );
	}
}

class WP_REST_Response {
	public $data;
	public $status;
	public $headers = array();
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function header( $k, $v ) {
		$this->headers[ $k ] = $v;
	}
	public function get_data() {
		return $this->data;
	}
	public function get_status() {
		return $this->status;
	}
}
