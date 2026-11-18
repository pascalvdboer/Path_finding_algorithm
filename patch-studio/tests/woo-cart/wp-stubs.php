<?php
/**
 * Minimal WordPress stubs (copied from tests/core/wp-stubs.php for the P04 woo-cart tests): hooks, options, transients, cron, posts, a fake
 * $wpdb that understands the queries of uninstall.php, HTTP with a pre_http_request spy. No database, no network.
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/bps-woocart-wp/' );
}
foreach ( array(
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
	'WEEK_IN_SECONDS'   => 604800,
	'MB_IN_BYTES'       => 1048576,
	'KB_IN_BYTES'       => 1024,
	'ARRAY_A'           => 'ARRAY_A',
) as $k => $v ) {
	if ( ! defined( $k ) ) {
		define( $k, $v );
	}
}

$GLOBALS['bps_t'] = array(
	'hooks'     => array(),
	'did'       => array(),
	'options'   => array(),
	'autoload'  => array(),
	'cron'      => array(),
	'posts'     => array(),
	'meta'      => array(),
	'http'      => array(),
	'is_admin'  => false,
	'locale'    => 'nl_BE',
	'shortcode' => array(),
	'uploads'   => sys_get_temp_dir() . '/bps-woocart-uploads-' . getmypid(),
	'next_id'   => 100,
);

/* Hooks ---------------------------------------------------------------- */
function _bps_cb_key( $cb ) {
	if ( is_string( $cb ) ) {
		return $cb;
	}
	if ( is_array( $cb ) ) {
		return ( is_object( $cb[0] ) ? spl_object_hash( $cb[0] ) : $cb[0] ) . '::' . $cb[1];
	}
	return spl_object_hash( (object) $cb );
}
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['bps_t']['hooks'][ $hook ][ $prio ][ _bps_cb_key( $cb ) ] = array( $cb, $args );
	return true;
}
function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	return add_filter( $hook, $cb, $prio, $args );
}
function remove_all_filters( $hook ) {
	unset( $GLOBALS['bps_t']['hooks'][ $hook ] );
}
function has_filter( $hook, $cb = false ) {
	if ( empty( $GLOBALS['bps_t']['hooks'][ $hook ] ) ) {
		return false;
	}
	if ( false === $cb ) {
		return true;
	}
	foreach ( $GLOBALS['bps_t']['hooks'][ $hook ] as $prio => $list ) {
		if ( isset( $list[ _bps_cb_key( $cb ) ] ) ) {
			return $prio;
		}
	}
	return false;
}
function has_action( $hook, $cb = false ) {
	return has_filter( $hook, $cb );
}
function apply_filters( $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['bps_t']['hooks'][ $hook ] ) ) {
		return $value;
	}
	$h = $GLOBALS['bps_t']['hooks'][ $hook ];
	ksort( $h );
	foreach ( $h as $list ) {
		foreach ( $list as $item ) {
			$value = call_user_func_array( $item[0], array_slice( array_merge( array( $value ), $args ), 0, max( 1, (int) $item[1] ) ) );
		}
	}
	return $value;
}
function do_action( $hook, ...$args ) {
	$GLOBALS['bps_t']['did'][ $hook ] = ( $GLOBALS['bps_t']['did'][ $hook ] ?? 0 ) + 1;
	$GLOBALS['bps_t']['did_args'][ $hook ][] = $args;
	if ( empty( $GLOBALS['bps_t']['hooks'][ $hook ] ) ) {
		return;
	}
	$h = $GLOBALS['bps_t']['hooks'][ $hook ];
	ksort( $h );
	foreach ( $h as $list ) {
		foreach ( $list as $item ) {
			call_user_func_array( $item[0], array_slice( $args, 0, (int) $item[1] ) );
		}
	}
}
function did_action( $hook ) {
	return $GLOBALS['bps_t']['did'][ $hook ] ?? 0;
}
function count_hooks( string $prefix = '' ): int {
	$n = 0;
	foreach ( $GLOBALS['bps_t']['hooks'] as $hook => $prios ) {
		if ( '' !== $prefix && 0 !== strpos( $hook, $prefix ) ) {
			continue;
		}
		foreach ( $prios as $list ) {
			$n += count( $list );
		}
	}
	return $n;
}

/* Options / transients --------------------------------------------------- */
function get_option( $k, $default = false ) {
	return array_key_exists( $k, $GLOBALS['bps_t']['options'] ) ? $GLOBALS['bps_t']['options'][ $k ] : $default;
}
function add_option( $k, $v = '', $dep = '', $autoload = 'yes' ) {
	if ( array_key_exists( $k, $GLOBALS['bps_t']['options'] ) ) {
		return false;
	}
	$GLOBALS['bps_t']['options'][ $k ]  = $v;
	$GLOBALS['bps_t']['autoload'][ $k ] = ! in_array( $autoload, array( false, 'no', 'off' ), true );
	do_action( 'add_option_' . $k, $k, $v );
	return true;
}
function update_option( $k, $v, $autoload = null ) {
	if ( ! array_key_exists( $k, $GLOBALS['bps_t']['options'] ) ) {
		return add_option( $k, $v, '', null === $autoload ? 'yes' : $autoload );
	}
	$GLOBALS['bps_t']['options'][ $k ] = $v;
	if ( null !== $autoload ) {
		$GLOBALS['bps_t']['autoload'][ $k ] = ! in_array( $autoload, array( false, 'no', 'off' ), true );
	}
	do_action( 'update_option_' . $k, null, $v, $k );
	return true;
}
function delete_option( $k ) {
	$had = array_key_exists( $k, $GLOBALS['bps_t']['options'] );
	unset( $GLOBALS['bps_t']['options'][ $k ], $GLOBALS['bps_t']['autoload'][ $k ] );
	return $had;
}
function set_transient( $k, $v, $exp = 0 ) {
	$GLOBALS['bps_t']['options'][ '_transient_' . $k ] = $v;
	if ( $exp ) {
		$GLOBALS['bps_t']['options'][ '_transient_timeout_' . $k ] = time() + (int) $exp;
	}
	return true;
}
function get_transient( $k ) {
	$t = $GLOBALS['bps_t']['options'][ '_transient_timeout_' . $k ] ?? 0;
	if ( $t && $t < time() ) {
		return false;
	}
	return $GLOBALS['bps_t']['options'][ '_transient_' . $k ] ?? false;
}
function delete_transient( $k ) {
	unset( $GLOBALS['bps_t']['options'][ '_transient_' . $k ], $GLOBALS['bps_t']['options'][ '_transient_timeout_' . $k ] );
	return true;
}
function wp_cache_flush() {
	return true;
}
function clean_post_cache( $id ) {}

/* Cron -------------------------------------------------------------------- */
function wp_schedule_event( $ts, $rec, $hook, $args = array() ) {
	$GLOBALS['bps_t']['cron'][ (int) $ts ][ $hook ][ md5( serialize( $args ) ) ] = array( 'schedule' => $rec, 'args' => $args );
	return true;
}
function wp_schedule_single_event( $ts, $hook, $args = array() ) {
	return wp_schedule_event( $ts, false, $hook, $args );
}
function wp_next_scheduled( $hook, $args = array() ) {
	$key = md5( serialize( $args ) );
	ksort( $GLOBALS['bps_t']['cron'] );
	foreach ( $GLOBALS['bps_t']['cron'] as $ts => $hooks ) {
		if ( isset( $hooks[ $hook ][ $key ] ) ) {
			return $ts;
		}
	}
	return false;
}
function wp_unschedule_hook( $hook ) {
	$n = 0;
	foreach ( $GLOBALS['bps_t']['cron'] as $ts => $hooks ) {
		if ( isset( $hooks[ $hook ] ) ) {
			$n += count( $hooks[ $hook ] );
			unset( $GLOBALS['bps_t']['cron'][ $ts ][ $hook ] );
		}
		if ( empty( $GLOBALS['bps_t']['cron'][ $ts ] ) ) {
			unset( $GLOBALS['bps_t']['cron'][ $ts ] );
		}
	}
	return $n;
}
function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$key = md5( serialize( $args ) );
	foreach ( $GLOBALS['bps_t']['cron'] as $ts => $hooks ) {
		unset( $GLOBALS['bps_t']['cron'][ $ts ][ $hook ][ $key ] );
		if ( empty( $GLOBALS['bps_t']['cron'][ $ts ][ $hook ] ) ) {
			unset( $GLOBALS['bps_t']['cron'][ $ts ][ $hook ] );
		}
		if ( empty( $GLOBALS['bps_t']['cron'][ $ts ] ) ) {
			unset( $GLOBALS['bps_t']['cron'][ $ts ] );
		}
	}
	return 1;
}
function wp_unschedule_event( $ts, $hook, $args = array() ) {
	unset( $GLOBALS['bps_t']['cron'][ $ts ][ $hook ][ md5( serialize( $args ) ) ] );
	return true;
}
function _get_cron_array() {
	return $GLOBALS['bps_t']['cron'];
}
function cron_hooks(): array {
	$out = array();
	foreach ( $GLOBALS['bps_t']['cron'] as $hooks ) {
		$out = array_merge( $out, array_keys( $hooks ) );
	}
	return array_values( array_unique( $out ) );
}

/* i18n / escaping --------------------------------------------------------- */
function __( $s, $d = null ) {
	return $s;
}
function _x( $s, $c, $d = null ) {
	return $s;
}
function _n( $a, $b, $n, $d = null ) {
	return 1 === (int) $n ? $a : $b;
}
function esc_html__( $s, $d = null ) {
	return htmlspecialchars( $s, ENT_QUOTES );
}
function esc_attr__( $s, $d = null ) {
	return htmlspecialchars( $s, ENT_QUOTES );
}
function esc_html_e( $s, $d = null ) {
	echo htmlspecialchars( $s, ENT_QUOTES );
}
function _e( $s, $d = null ) {
	echo $s;
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}
function esc_url( $s ) {
	return (string) $s;
}
function esc_url_raw( $s ) {
	return (string) $s;
}
function wp_kses_post( $s ) {
	return preg_replace( '#<(script|iframe|object)[^>]*>.*?</\1>#is', '', (string) $s );
}
function sanitize_text_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_key( $s ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
}
function wp_unslash( $s ) {
	return $s;
}
function wp_json_encode( $v, $flags = 0 ) {
	return json_encode( $v, $flags );
}
function determine_locale() {
	return $GLOBALS['bps_t']['locale'];
}
function get_locale() {
	return $GLOBALS['bps_t']['locale'];
}

/* URLs / misc ------------------------------------------------------------- */
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}
function trailingslashit( $s ) {
	return rtrim( (string) $s, '/\\' ) . '/';
}
function untrailingslashit( $s ) {
	return rtrim( (string) $s, '/\\' );
}
function home_url( $p = '' ) {
	return 'https://shop.test' . $p;
}
function site_url( $p = '' ) {
	return 'https://shop.test' . $p;
}
function admin_url( $p = '' ) {
	return 'https://shop.test/wp-admin/' . $p;
}
function rest_url( $p = '' ) {
	return 'https://shop.test/wp-json/' . $p;
}
function add_query_arg( ...$a ) {
	if ( is_array( $a[0] ) ) {
		$args = $a[0];
		$url  = (string) ( $a[1] ?? '' );
	} else {
		$args = array( $a[0] => $a[1] );
		$url  = (string) ( $a[2] ?? '' );
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}
function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}
function plugin_dir_path( $file ) {
	return trailingslashit( dirname( $file ) );
}
function plugin_dir_url( $file ) {
	return 'https://shop.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}
function plugins_url( $p = '', $f = '' ) {
	return 'https://shop.test/wp-content/plugins/' . ltrim( $p, '/' );
}
function is_admin() {
	return (bool) $GLOBALS['bps_t']['is_admin'];
}
function wp_doing_cron() {
	return false;
}
function wp_doing_ajax() {
	return false;
}
function is_multisite() {
	return false;
}
function current_user_can( $cap ) {
	return true;
}
function get_current_user_id() {
	return 1;
}
function wp_salt( $s = 'auth' ) {
	return 'unit-test-salt';
}
function size_format( $bytes, $d = 0 ) {
	$bytes = (int) $bytes;
	if ( $bytes >= MB_IN_BYTES ) {
		return round( $bytes / MB_IN_BYTES, $d ) . ' MB';
	}
	return round( $bytes / KB_IN_BYTES, $d ) . ' KB';
}
function wp_convert_hr_to_bytes( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$bytes = (int) $value;
	if ( false !== strpos( $value, 'g' ) ) {
		$bytes *= 1073741824;
	} elseif ( false !== strpos( $value, 'm' ) ) {
		$bytes *= MB_IN_BYTES;
	} elseif ( false !== strpos( $value, 'k' ) ) {
		$bytes *= KB_IN_BYTES;
	}
	return min( $bytes, PHP_INT_MAX );
}
function wp_max_upload_size() {
	return min( wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ), wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ) );
}
function wp_upload_dir( $time = null, $create = true ) {
	return array(
		'basedir' => $GLOBALS['bps_t']['uploads'],
		'baseurl' => 'https://shop.test/wp-content/uploads',
		'error'   => false,
	);
}
function wp_delete_file( $file ) {
	if ( is_file( $file ) ) {
		unlink( $file );
	}
}
function wp_delete_file_from_directory( $file, $dir ) {
	if ( 0 !== strpos( realpath( $file ) ?: '', realpath( $dir ) ?: '//' ) ) {
		return false;
	}
	wp_delete_file( $file );
	return true;
}
function wp_is_writable( $p ) {
	return is_writable( $p );
}
function wp_normalize_path( $p ) {
	return str_replace( '\\', '/', (string) $p );
}
function shortcode_exists( $tag ) {
	return isset( $GLOBALS['bps_t']['shortcode'][ $tag ] );
}
function add_shortcode( $tag, $cb ) {
	$GLOBALS['bps_t']['shortcode'][ $tag ] = $cb;
}
function wp_add_privacy_policy_content( $name, $text ) {
	$GLOBALS['bps_t']['privacy'][ $name ] = $text;
}

/* Errors ------------------------------------------------------------------ */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
}
function is_wp_error( $v ) {
	return $v instanceof WP_Error;
}

/* HTTP (spy) -------------------------------------------------------------- */
function _bps_http( $method, $url, $args ) {
	$GLOBALS['bps_t']['http'][] = array(
		'method' => $method,
		'url'    => $url,
		'args'   => $args,
	);
	$pre = apply_filters( 'pre_http_request', false, $args, $url );
	if ( false !== $pre ) {
		return $pre;
	}
	return new WP_Error( 'http_request_failed', 'No network in unit tests.' );
}
function wp_safe_remote_post( $url, $args = array() ) {
	return _bps_http( 'POST', $url, $args );
}
function wp_remote_post( $url, $args = array() ) {
	return _bps_http( 'POST', $url, $args );
}
function wp_safe_remote_get( $url, $args = array() ) {
	return _bps_http( 'GET', $url, $args );
}
function wp_remote_get( $url, $args = array() ) {
	return _bps_http( 'GET', $url, $args );
}
function wp_remote_retrieve_response_code( $r ) {
	return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0;
}
function wp_remote_retrieve_body( $r ) {
	return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : '';
}

/* Posts ------------------------------------------------------------------- */
function bps_t_insert_post( string $type, array $meta = array() ): int {
	$id                                 = ++$GLOBALS['bps_t']['next_id'];
	$GLOBALS['bps_t']['posts'][ $id ] = array(
		'ID'        => $id,
		'post_type' => $type,
	);
	$GLOBALS['bps_t']['meta'][ $id ]  = $meta;
	return $id;
}
function get_post_type( $id ) {
	return $GLOBALS['bps_t']['posts'][ (int) $id ]['post_type'] ?? false;
}
function get_post_meta( $id, $key = '', $single = false ) {
	$v = $GLOBALS['bps_t']['meta'][ (int) $id ][ $key ] ?? '';
	return $single ? $v : array( $v );
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['bps_t']['meta'][ (int) $id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['bps_t']['meta'][ (int) $id ][ $key ] );
	return true;
}
function wp_delete_post( $id, $force = false ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['bps_t']['posts'][ $id ] ) ) {
		return false;
	}
	do_action( 'before_delete_post', $id );
	$p = $GLOBALS['bps_t']['posts'][ $id ];
	unset( $GLOBALS['bps_t']['posts'][ $id ], $GLOBALS['bps_t']['meta'][ $id ] );
	return (object) $p;
}

/* Fake wpdb --------------------------------------------------------------- */
class BPS_Fake_WPDB {
	public $prefix   = 'wp_';
	public $options  = 'wp_options';
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $sitemeta = '';
	public $tables   = array();
	public $queries  = array();
	public $insert_id = 0;

	public function esc_like( $s ) {
		return addcslashes( (string) $s, '_%\\' );
	}
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[sdf]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? '';
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$sql
		);
	}
	public function get_charset_collate() {
		return '';
	}
	private static function like_to_regex( string $like ): string {
		$like = stripslashes( $like ); // Undo addslashes of prepare.
		$out  = '';
		$len  = strlen( $like );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $like[ $i ];
			if ( '\\' === $c && $i + 1 < $len ) {
				$out .= preg_quote( $like[ ++$i ], '/' );
			} elseif ( '%' === $c ) {
				$out .= '.*';
			} elseif ( '_' === $c ) {
				$out .= '.';
			} else {
				$out .= preg_quote( $c, '/' );
			}
		}
		return '/^' . $out . '$/s';
	}
	public function get_col( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( "/SELECT option_name FROM wp_options WHERE option_name LIKE '((?:[^'\\\\]|\\\\.)*)'/", $sql, $m ) ) {
			$re = self::like_to_regex( $m[1] );
			return array_values( array_filter( array_keys( $GLOBALS['bps_t']['options'] ), function ( $k ) use ( $re ) {
				return (bool) preg_match( $re, $k );
			} ) );
		}
		if ( preg_match( "/SELECT ID FROM wp_posts WHERE post_type = '([a-z_]+)' ORDER BY ID LIMIT (\d+)/", $sql, $m ) ) {
			$ids = array();
			foreach ( $GLOBALS['bps_t']['posts'] as $id => $p ) {
				if ( $p['post_type'] === $m[1] ) {
					$ids[] = $id;
				}
			}
			sort( $ids );
			return array_slice( $ids, 0, (int) $m[2] );
		}
		if ( preg_match( "/WHERE m.meta_key = '([^']+)' AND p.post_type (?:IN \(([^)]*)\)|= '([a-z_]+)')/", $sql, $m ) ) {
			$types = ! empty( $m[3] ) ? array( $m[3] ) : array_map( function ( $t ) {
				return trim( $t, " '" );
			}, explode( ',', $m[2] ) );
			$ids   = array();
			foreach ( $GLOBALS['bps_t']['posts'] as $id => $p ) {
				if ( in_array( $p['post_type'], $types, true ) && array_key_exists( $m[1], $GLOBALS['bps_t']['meta'][ $id ] ?? array() ) ) {
					$ids[] = $id;
				}
			}
			return $ids;
		}
		if ( preg_match( "/p.post_type = 'bps_design' AND \( m.meta_value IN \(([^)]*)\)( OR m.meta_value IS NULL)? \) ORDER BY p.ID LIMIT (\d+)/", $sql, $m ) ) {
			$statuses = array_map( function ( $t ) {
				return trim( $t, " '" );
			}, explode( ',', $m[1] ) );
			$ids      = array();
			foreach ( $GLOBALS['bps_t']['posts'] as $id => $p ) {
				if ( 'bps_design' !== $p['post_type'] ) {
					continue;
				}
				$st = $GLOBALS['bps_t']['meta'][ $id ]['_bps_status'] ?? null;
				if ( ( null === $st && ! empty( $m[2] ) ) || ( null !== $st && in_array( $st, $statuses, true ) ) ) {
					$ids[] = $id;
				}
			}
			sort( $ids );
			return array_slice( $ids, 0, (int) $m[3] );
		}
		throw new RuntimeException( 'Fake wpdb: unknown get_col query: ' . $sql );
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( '/DROP TABLE IF EXISTS `?([A-Za-z0-9_]+)`?/', $sql, $m ) ) {
			unset( $this->tables[ $m[1] ] );
			return true;
		}
		if ( preg_match( '/^(START TRANSACTION|COMMIT|ROLLBACK)/', $sql ) ) {
			return true;
		}
		throw new RuntimeException( 'Fake wpdb: unknown query: ' . $sql );
	}
	public function delete( $table, $where ) {
		return 1;
	}
	public function get_results( $sql, $output = null ) {
		return array();
	}
	public function get_var( $sql ) {
		return null;
	}
	public function get_row( $sql, $output = null ) {
		return null;
	}
}
$GLOBALS['wpdb'] = new BPS_Fake_WPDB();
