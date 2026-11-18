<?php
/**
 * Minimal WordPress + WooCommerce stubs for the privacy/retention tests (no database, no network).
 * State lives in $GLOBALS['bps_p']; tests reset it with bps_p_reset().
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

namespace {

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'BPS_TESTING' ) || define( 'BPS_TESTING', true );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'MB_IN_BYTES' ) || define( 'MB_IN_BYTES', 1048576 );
defined( 'BPS_DIR' ) || define( 'BPS_DIR', dirname( __DIR__, 3 ) . '/plugin/borduur-patch-studio/' );
defined( 'BPS_URL' ) || define( 'BPS_URL', 'https://shop.test/wp-content/plugins/borduur-patch-studio/' );
defined( 'BPS_VERSION' ) || define( 'BPS_VERSION', 'test' );

$GLOBALS['bps_p'] = array();

/** Reset all in-memory state. */
function bps_p_reset(): void {
	$up = $GLOBALS['bps_p']['upload_dir'] ?? ( sys_get_temp_dir() . '/bps-privacy-' . getmypid() . '-' . bin2hex( random_bytes( 3 ) ) );
	$GLOBALS['bps_p'] = array(
		'options'    => array(),
		'transients' => array(),
		'filters'    => array(),
		'posts'      => array(),
		'meta'       => array(),
		'next_id'    => 100,
		'users'      => array(),
		'orders'     => array(),
		'next_item'  => 5000,
		'locale'     => 'nl_BE',
		'upload_dir' => $up,
		'policy'     => array(),
		'cron'       => array(),
	);
	@mkdir( $up, 0777, true );
}
bps_p_reset();

/* ----------------------------------------------------------------- errors */

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

/* ----------------------------------------------------------------- hooks */

function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['bps_p']['filters'][ $hook ][ $prio ][] = array( $cb, $args );
	return true;
}
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { return add_filter( $hook, $cb, $prio, $args ); }
function remove_all_filters( $hook ) { unset( $GLOBALS['bps_p']['filters'][ $hook ] ); }
function has_filter( $hook ) { return ! empty( $GLOBALS['bps_p']['filters'][ $hook ] ); }
function apply_filters( $hook, $value, ...$rest ) {
	$list = $GLOBALS['bps_p']['filters'][ $hook ] ?? array();
	ksort( $list );
	foreach ( $list as $cbs ) {
		foreach ( $cbs as $c ) {
			$value = call_user_func_array( $c[0], array_slice( array_merge( array( $value ), $rest ), 0, max( 1, (int) $c[1] ) ) );
		}
	}
	return $value;
}
function do_action( $hook, ...$args ) {
	$list = $GLOBALS['bps_p']['filters'][ $hook ] ?? array();
	ksort( $list );
	foreach ( $list as $cbs ) {
		foreach ( $cbs as $c ) {
			call_user_func_array( $c[0], array_slice( $args, 0, max( 0, (int) $c[1] ) ) );
		}
	}
}
function did_action( $hook ) { return 0; }

/* ----------------------------------------------------------------- options, i18n, escaping */

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['bps_p']['options'] ) ? $GLOBALS['bps_p']['options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['bps_p']['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['bps_p']['options'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['bps_p']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['bps_p']['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['bps_p']['transients'][ $k ] ); return true; }
function __( $t, $d = '' ) { return $t; }
function _x( $t, $c, $d = '' ) { return $t; }
function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; }
function esc_html__( $t, $d = '' ) { return esc_html( $t ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return (string) $u; }
function esc_url_raw( $u ) { return (string) $u; }
function wp_kses_post( $h ) { return (string) $h; }
function get_locale() { return $GLOBALS['bps_p']['locale']; }
function determine_locale() { return $GLOBALS['bps_p']['locale']; }
function get_user_locale() { return $GLOBALS['bps_p']['locale']; }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function sanitize_email( $e ) { $e = trim( (string) $e ); return filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : ''; }
function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_file_name( $n ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $n ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $v, $flags = 0, $depth = 512 ) { return json_encode( $v, $flags, $depth ); }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?? time() ); }
function wp_timezone() { return new DateTimeZone( 'Europe/Brussels' ); }
function wp_salt( $s = 'auth' ) { return 'test-salt-' . $s; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function set_url_scheme( $u ) { return $u; }
function home_url( $p = '' ) { return 'https://shop.test' . $p; }
function site_url( $p = '' ) { return 'https://shop.test' . $p; }
function admin_url( $p = '' ) { return 'https://shop.test/wp-admin/' . $p; }
function rest_url( $p = '' ) { return 'https://shop.test/wp-json/' . $p; }
function add_query_arg( $args, $url = '' ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args ); }
function is_admin() { return false; }
function is_user_logged_in() { return false; }
function get_current_user_id() { return 0; }
function current_user_can( $c ) { return false; }
function wp_validate_auth_cookie( $c = '', $s = '' ) { return false; }
function shortcode_exists( $t ) { return false; }
function add_shortcode( $t, $cb ) {}
function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['bps_p']['cron'][ $hook . json_encode( $args ) ] ?? false; }
function wp_schedule_event( $ts, $rec, $hook, $args = array() ) { $GLOBALS['bps_p']['cron'][ $hook . json_encode( $args ) ] = $ts; return true; }
function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['bps_p']['cron'][ $hook . json_encode( $args ) ] = $ts; return true; }
function wp_clear_scheduled_hook( $hook, $args = array() ) { unset( $GLOBALS['bps_p']['cron'][ $hook . json_encode( $args ) ] ); return 1; }
function wp_add_privacy_policy_content( $name, $html ) { $GLOBALS['bps_p']['policy'][ $name ] = $html; }

/* ----------------------------------------------------------------- uploads / files */

function wp_upload_dir( $time = null, $create = true ) {
	return array(
		'basedir' => $GLOBALS['bps_p']['upload_dir'],
		'baseurl' => 'https://shop.test/wp-content/uploads',
		'error'   => false,
	);
}
function wp_mkdir_p( $d ) { return is_dir( $d ) || @mkdir( $d, 0777, true ); }
function wp_delete_file( $f ) { if ( is_file( $f ) ) { @unlink( $f ); } }
function wp_delete_file_from_directory( $f, $dir ) {
	$rf = realpath( $f );
	$rd = realpath( $dir );
	if ( ! $rf || ! $rd || 0 !== strpos( $rf, $rd ) ) {
		return false;
	}
	return @unlink( $rf );
}
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_raise_memory_limit( $c = '' ) { return true; }

/* ----------------------------------------------------------------- users */

class WP_User {
	public $ID;
	public $user_email;
	public function __construct( $id, $email ) { $this->ID = $id; $this->user_email = $email; }
}
function bps_p_add_user( int $id, string $email ): WP_User {
	$u = new WP_User( $id, $email );
	$GLOBALS['bps_p']['users'][ $id ] = $u;
	return $u;
}
function get_user_by( $field, $value ) {
	foreach ( $GLOBALS['bps_p']['users'] as $u ) {
		if ( 'email' === $field && strtolower( $u->user_email ) === strtolower( (string) $value ) ) {
			return $u;
		}
		if ( 'id' === $field && (int) $u->ID === (int) $value ) {
			return $u;
		}
	}
	return false;
}
function get_userdata( $id ) { return get_user_by( 'id', $id ); }
/** wp_delete_user: fires delete_user, then deletes the user (posts of delete_with_user=false types stay). */
function wp_delete_user( $id, $reassign = null ) {
	do_action( 'delete_user', $id, $reassign, get_user_by( 'id', $id ) );
	unset( $GLOBALS['bps_p']['users'][ $id ] );
	return true;
}

/* ----------------------------------------------------------------- posts + meta */

class WP_Post {
	public $ID;
	public $post_type;
	public $post_status = 'private';
	public $post_title  = '';
	public $post_author = 0;
	public $post_date_gmt;
	public function __construct( array $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } }
}
function wp_insert_post( $args, $wp_error = false ) {
	$id = ++$GLOBALS['bps_p']['next_id'];
	$GLOBALS['bps_p']['posts'][ $id ] = new WP_Post(
		array(
			'ID'            => $id,
			'post_type'     => $args['post_type'] ?? 'post',
			'post_status'   => $args['post_status'] ?? 'private',
			'post_title'    => $args['post_title'] ?? '',
			'post_author'   => (int) ( $args['post_author'] ?? 0 ),
			'post_date_gmt' => $args['post_date_gmt'] ?? gmdate( 'Y-m-d H:i:s' ),
		)
	);
	return $id;
}
function wp_update_post( $args ) {
	$id = (int) ( $args['ID'] ?? 0 );
	if ( ! isset( $GLOBALS['bps_p']['posts'][ $id ] ) ) {
		return 0;
	}
	foreach ( $args as $k => $v ) {
		if ( 'ID' !== $k ) {
			$GLOBALS['bps_p']['posts'][ $id ]->$k = 'post_author' === $k ? (int) $v : $v;
		}
	}
	return $id;
}
function get_post( $id = null ) { return $GLOBALS['bps_p']['posts'][ (int) $id ] ?? null; }
function get_post_type( $id = null ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
function get_post_status( $id = null ) { $p = get_post( $id ); return $p ? $p->post_status : false; }
function post_type_exists( $t ) { return true; }
function register_post_type( $t, $a = array() ) {}
function wp_delete_post( $id, $force = false ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['bps_p']['posts'][ $id ] ) ) {
		return false;
	}
	do_action( 'before_delete_post', $id );
	unset( $GLOBALS['bps_p']['posts'][ $id ], $GLOBALS['bps_p']['meta'][ $id ] );
	return true;
}
function get_post_meta( $id, $key = '', $single = false ) {
	$m = $GLOBALS['bps_p']['meta'][ (int) $id ] ?? array();
	if ( '' === $key ) {
		return $m;
	}
	if ( ! array_key_exists( $key, $m ) ) {
		return $single ? '' : array();
	}
	return $single ? $m[ $key ] : array( $m[ $key ] );
}
function update_post_meta( $id, $key, $value ) { $GLOBALS['bps_p']['meta'][ (int) $id ][ $key ] = wp_unslash( $value ); return true; }
function add_post_meta( $id, $key, $value, $unique = false ) { return update_post_meta( $id, $key, $value ); }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['bps_p']['meta'][ (int) $id ][ $key ] ); return true; }
function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['bps_p']['meta'][ (int) $id ] ?? array() ); }

/** Evaluate one meta_query clause (or nested group) against a post id. */
function bps_p_meta_match( int $id, array $q ): bool {
	$rel     = strtoupper( (string) ( $q['relation'] ?? 'AND' ) );
	$results = array();
	foreach ( $q as $k => $clause ) {
		if ( 'relation' === $k || ! is_array( $clause ) ) {
			continue;
		}
		if ( ! isset( $clause['key'] ) ) {
			$results[] = bps_p_meta_match( $id, $clause );
			continue;
		}
		$m       = $GLOBALS['bps_p']['meta'][ $id ] ?? array();
		$exists  = array_key_exists( $clause['key'], $m );
		$val     = $exists ? $m[ $clause['key'] ] : null;
		$compare = strtoupper( (string) ( $clause['compare'] ?? ( is_array( $clause['value'] ?? null ) ? 'IN' : '=' ) ) );
		switch ( $compare ) {
			case 'NOT EXISTS':
				$results[] = ! $exists;
				break;
			case 'EXISTS':
				$results[] = $exists;
				break;
			case 'IN':
				$results[] = $exists && in_array( (string) $val, array_map( 'strval', (array) $clause['value'] ), true );
				break;
			case '<':
				$results[] = $exists && (string) $val < (string) $clause['value'];
				break;
			case '>':
				$results[] = $exists && (string) $val > (string) $clause['value'];
				break;
			default:
				$results[] = $exists && is_scalar( $val ) && (string) $val === (string) $clause['value'];
		}
	}
	if ( ! $results ) {
		return true;
	}
	return 'OR' === $rel ? in_array( true, $results, true ) : ! in_array( false, $results, true );
}

class WP_Query {
	public $posts = array();
	public $query_vars;
	public function __construct( array $a ) {
		$this->query_vars = $a;
		$types            = (array) ( $a['post_type'] ?? 'post' );
		$out              = array();
		foreach ( $GLOBALS['bps_p']['posts'] as $id => $p ) {
			if ( ! in_array( $p->post_type, $types, true ) ) {
				continue;
			}
			if ( isset( $a['author__in'] ) && ! in_array( (int) $p->post_author, array_map( 'intval', $a['author__in'] ), true ) ) {
				continue;
			}
			if ( isset( $a['author__not_in'] ) && in_array( (int) $p->post_author, array_map( 'intval', $a['author__not_in'] ), true ) ) {
				continue;
			}
			if ( ! empty( $a['date_query'] ) ) {
				foreach ( $a['date_query'] as $dq ) {
					if ( is_array( $dq ) && isset( $dq['before'] ) && ! ( (string) $p->post_date_gmt < (string) $dq['before'] ) ) {
						continue 2;
					}
				}
			}
			if ( ! empty( $a['meta_query'] ) && ! bps_p_meta_match( (int) $id, $a['meta_query'] ) ) {
				continue;
			}
			$out[] = (int) $id;
		}
		sort( $out );
		if ( 'DESC' === strtoupper( (string) ( $a['order'] ?? 'ASC' ) ) ) {
			$out = array_reverse( $out );
		}
		$n = (int) ( $a['posts_per_page'] ?? 10 );
		if ( $n >= 0 ) {
			$out = array_slice( $out, 0, $n );
		}
		$GLOBALS['bps_p']['queries'][] = $a;
		$this->posts = ( 'ids' === ( $a['fields'] ?? '' ) ) ? $out : array_map( 'get_post', $out );
	}
}

/* ----------------------------------------------------------------- REST */

class WP_REST_Request {
	public $params = array();
	public $files  = array();
	public $body   = '';
	public $headers = array();
	public function __construct( $method = 'GET', $route = '' ) {}
	public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	public function set_param( $k, $v ) { $this->params[ $k ] = $v; }
	public function get_body() { return $this->body; }
	public function get_file_params() { return $this->files; }
	public function get_header( $k ) { return $this->headers[ strtolower( $k ) ] ?? null; }
}
class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
}

/* ----------------------------------------------------------------- WooCommerce */

class Bps_P_Date {
	private $ts;
	public function __construct( int $ts ) { $this->ts = $ts; }
	public function getTimestamp() { return $this->ts; }
	public function date( $f ) { return gmdate( $f, $this->ts ); }
	public function date_i18n( $f ) { return gmdate( $f, $this->ts ); }
}

class WC_Order_Item_Product {
	public $id;
	public $order_id;
	public $meta  = array();
	public $saves = 0;
	public function __construct( int $id, int $order_id ) { $this->id = $id; $this->order_id = $order_id; }
	public function get_id() { return $this->id; }
	public function get_order_id() { return $this->order_id; }
	public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function add_meta_data( $k, $v, $unique = false ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function save() { ++$this->saves; return $this->id; }
}

class WC_Order {
	public $id;
	public $status        = 'processing';
	public $billing_email = '';
	public $customer_id   = 0;
	public $created;
	public $completed     = 0;
	public $modified;
	public $items         = array();
	public $meta          = array();
	public function __construct( int $id ) { $this->id = $id; $this->created = time(); $this->modified = time(); }
	public function get_id() { return $this->id; }
	public function get_order_number() { return (string) $this->id; }
	public function get_status() { return $this->status; }
	public function get_billing_email() { return $this->billing_email; }
	public function get_customer_id() { return $this->customer_id; }
	public function get_items( $type = 'line_item' ) { return $this->items; }
	public function get_item( $id ) { return $this->items[ (int) $id ] ?? false; }
	public function get_date_created() { return new Bps_P_Date( $this->created ); }
	public function get_date_completed() { return $this->completed ? new Bps_P_Date( $this->completed ) : null; }
	public function get_date_modified() { return new Bps_P_Date( $this->modified ); }
	public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function save() { return $this->id; }
	public function get_billing_first_name() { return 'Jan'; }
	public function get_billing_last_name() { return 'Peeters'; }
	public function get_billing_company() { return ''; }
}

/** Create an order with lines; each line = item meta array. Returns the order. */
function bps_p_order( string $email, array $lines, string $status = 'processing', int $customer = 0 ): WC_Order {
	$id               = ++$GLOBALS['bps_p']['next_id'];
	$o                = new WC_Order( $id );
	$o->billing_email = $email;
	$o->status        = $status;
	$o->customer_id   = $customer;
	foreach ( $lines as $meta ) {
		$iid              = ++$GLOBALS['bps_p']['next_item'];
		$item             = new WC_Order_Item_Product( $iid, $id );
		$item->meta       = $meta;
		$o->items[ $iid ] = $item;
	}
	$GLOBALS['bps_p']['orders'][ $id ] = $o;
	return $o;
}
function wc_get_order( $id ) {
	if ( is_object( $id ) ) {
		return $id;
	}
	return $GLOBALS['bps_p']['orders'][ (int) $id ] ?? false;
}
function wc_get_orders( $args ) {
	$out = array();
	foreach ( $GLOBALS['bps_p']['orders'] as $id => $o ) {
		if ( isset( $args['billing_email'] ) && strtolower( $o->billing_email ) !== strtolower( (string) $args['billing_email'] ) ) {
			continue;
		}
		if ( isset( $args['customer_id'] ) && (int) $o->customer_id !== (int) $args['customer_id'] ) {
			continue;
		}
		$out[ $id ] = $o;
	}
	ksort( $out );
	$out   = array_values( $out );
	$limit = (int) ( $args['limit'] ?? 10 );
	$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
	if ( $limit > 0 ) {
		$out = array_slice( $out, ( $page - 1 ) * $limit, $limit );
	}
	$GLOBALS['bps_p']['order_queries'] = ( $GLOBALS['bps_p']['order_queries'] ?? 0 ) + 1;
	return $out;
}

} // namespace

/* ----------------------------------------------------------------- plugin doubles */

namespace BorduurPatchStudio {

	/** Logger double (contract C1): records events so tests can assert there were no errors. */
	final class Logger {
		public static $events = array();
		public static function error( string $event, array $context = array() ): bool {
			$e = $context['exception'] ?? null;
			self::$events[] = array( 'error', $event, $e instanceof \Throwable ? get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() : '' );
			return true;
		}
		public static function warning( string $event, array $context = array() ): bool { self::$events[] = array( 'warning', $event, '' ); return true; }
		public static function info( string $event, array $context = array() ): bool { return true; }
		public static function debug( string $event, array $context = array() ): bool { return true; }
		public static function critical( string $event, array $context = array() ): bool { return self::error( $event, $context ); }
	}

	/** Settings double: bundled defaults + $GLOBALS['bps_p']['settings'] overrides (deep merge). */
	final class Settings {
		public static function deep_merge( array $base, array $over ): array {
			foreach ( $over as $k => $v ) {
				$assoc      = static fn( $x ) => is_array( $x ) && array() !== $x && ! array_is_list( $x );
				$base[ $k ] = ( isset( $base[ $k ] ) && $assoc( $base[ $k ] ) && $assoc( $v ) ) ? self::deep_merge( $base[ $k ], $v ) : $v;
			}
			return $base;
		}
		public static function get(): array {
			static $defaults = null;
			if ( null === $defaults ) {
				$defaults = json_decode( (string) file_get_contents( BPS_DIR . 'config/defaults.json' ), true );
			}
			return self::deep_merge( $defaults, (array) ( $GLOBALS['bps_p']['settings'] ?? array() ) );
		}
	}

	/** Help_Line double (the real one belongs to P01 and needs the full Settings). */
	final class Help_Line {
		public static function text( string $locale = '' ): string {
			return (string) ( $GLOBALS['bps_p']['help_line'] ?? '' );
		}
	}
}
