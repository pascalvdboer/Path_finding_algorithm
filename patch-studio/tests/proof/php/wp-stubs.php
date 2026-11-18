<?php
/**
 * Minimal WordPress + WooCommerce stubs for the proof workflow tests (premium P05). No database, no network.
 * State lives in $GLOBALS['bps_t']; every test starts with bps_t_reset().
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

namespace {

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'BPS_TESTING' ) || define( 'BPS_TESTING', true );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'BPS_DIR' ) || define( 'BPS_DIR', dirname( __DIR__, 3 ) . '/plugin/borduur-patch-studio/' );
defined( 'BPS_URL' ) || define( 'BPS_URL', 'https://shop.test/wp-content/plugins/borduur-patch-studio/' );
defined( 'BPS_FILE' ) || define( 'BPS_FILE', BPS_DIR . 'borduur-patch-studio.php' );
defined( 'BPS_VERSION' ) || define( 'BPS_VERSION', '9.9.9' );

$GLOBALS['bps_t'] = array();

/** Reset all in-memory state. */
function bps_t_reset(): void {
	$up = $GLOBALS['bps_t']['upload_dir'] ?? ( sys_get_temp_dir() . '/bps-proof-' . getmypid() . '-' . bin2hex( random_bytes( 3 ) ) );
	$GLOBALS['bps_t'] = array(
		'options'       => array( 'admin_email' => 'winkel@example.test' ),
		'filters'       => array(),
		'actions_done'  => array(),
		'doing'         => array(),
		'posts'         => array(),
		'meta'          => array(),
		'next_id'       => 100,
		'orders'        => array(),
		'next_item'     => 5000,
		'locale'        => 'nl_BE',
		'core_langs'    => array( 'nl_BE' ),
		'switched'      => array(),
		'textdomain'    => array(),
		'upload_dir'    => $up,
		'attachments'   => array(),
		'mails'         => array(),
		'mail_disabled' => array(),
		'settings'      => array(),
		'user'          => 0,
		'caps'          => array(),
		'cron'          => array(),
		'status'        => 200,
		'endpoint'      => '',
		'received'      => false,
		'emails'        => null,
	);
	@mkdir( $up, 0777, true );
	\BorduurPatchStudio\Logger::$events = array();
}

/* ----------------------------------------------------------------- errors */

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
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

/* ----------------------------------------------------------------- hooks */

function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['bps_t']['filters'][ $hook ][ $prio ][] = array( $cb, (int) $args );
	return true;
}
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { return add_filter( $hook, $cb, $prio, $args ); }
function remove_all_filters( $hook ) { unset( $GLOBALS['bps_t']['filters'][ $hook ] ); }
function apply_filters( $hook, $value, ...$rest ) {
	$all = $GLOBALS['bps_t']['filters'][ $hook ] ?? array();
	ksort( $all );
	foreach ( $all as $cbs ) {
		foreach ( $cbs as $cb ) {
			$value = call_user_func_array( $cb[0], array_slice( array_merge( array( $value ), $rest ), 0, max( 1, $cb[1] ) ) );
		}
	}
	return $value;
}
function do_action( $hook, ...$args ) {
	$GLOBALS['bps_t']['actions_done'][ $hook ][] = $args;
	$GLOBALS['bps_t']['doing'][]                 = $hook;
	$all = $GLOBALS['bps_t']['filters'][ $hook ] ?? array();
	ksort( $all );
	foreach ( $all as $cbs ) {
		foreach ( $cbs as $cb ) {
			call_user_func_array( $cb[0], array_slice( $args, 0, $cb[1] ) );
		}
	}
	array_pop( $GLOBALS['bps_t']['doing'] );
}
function did_action( $hook ) { return count( $GLOBALS['bps_t']['actions_done'][ $hook ] ?? array() ); }
function doing_action( $hook ) { return in_array( $hook, $GLOBALS['bps_t']['doing'], true ); }

/* ----------------------------------------------------------------- options, i18n, escaping */

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['bps_t']['options'] ) ? $GLOBALS['bps_t']['options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['bps_t']['options'][ $k ] = $v; return true; }
function add_option( $k, $v = '', $dep = '', $autoload = null ) {
	if ( array_key_exists( $k, $GLOBALS['bps_t']['options'] ) ) {
		return false;
	}
	$GLOBALS['bps_t']['options'][ $k ] = $v;
	return true;
}
function delete_option( $k ) { unset( $GLOBALS['bps_t']['options'][ $k ] ); return true; }

/** Tiny gettext double: strings registered in $GLOBALS['bps_t']['textdomain'] (msgid => msgstr) are translated. */
function bps_t_tr( $t ) { return $GLOBALS['bps_t']['textdomain'][ $t ] ?? $t; }
function __( $t, $d = '' ) { return bps_t_tr( $t ); }
function _x( $t, $c, $d = '' ) { return bps_t_tr( $t ); }
function _n( $s, $p, $n, $d = '' ) { return bps_t_tr( 1 === (int) $n ? $s : $p ); }
function esc_html__( $t, $d = '' ) { return esc_html( __( $t ) ); }
function esc_attr__( $t, $d = '' ) { return esc_attr( __( $t ) ); }
function esc_html_e( $t, $d = '' ) { echo esc_html( __( $t ) ); }
function esc_attr_e( $t, $d = '' ) { echo esc_attr( __( $t ) ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return str_replace( '&', '&#038;', (string) $u ); }
function esc_url_raw( $u ) { return (string) $u; }
function wp_kses_post( $h ) { return (string) $h; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wptexturize( $s ) { return (string) $s; }
function wpautop( $s ) { return '<p>' . $s . '</p>'; }
function nl2br_( $s ) { return nl2br( $s ); }
function checked( $a, $b, $echo = true ) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d, ',', '.' ); }
function size_format( $b, $d = 0 ) { return $b . ' B'; }
function wp_specialchars_decode( $s, $q = ENT_NOQUOTES ) { return htmlspecialchars_decode( (string) $s, ENT_QUOTES ); }
function get_bloginfo( $k = '' ) { return 'Borduurservice Test'; }
function get_locale() { return $GLOBALS['bps_t']['switched'] ? end( $GLOBALS['bps_t']['switched'] ) : $GLOBALS['bps_t']['locale']; }
function determine_locale() { return get_locale(); }
function get_user_locale() { return get_locale(); }
function switch_to_locale( $l ) {
	if ( ! in_array( $l, $GLOBALS['bps_t']['core_langs'], true ) || get_locale() === $l ) {
		return false;
	}
	$GLOBALS['bps_t']['switched'][] = $l;
	return true;
}
function restore_previous_locale() { array_pop( $GLOBALS['bps_t']['switched'] ); return true; }
function unload_textdomain( $d ) { $GLOBALS['bps_t']['textdomain'] = array(); $GLOBALS['bps_t']['td_log'][] = 'unload'; return true; }
function load_textdomain( $d, $mofile ) {
	$GLOBALS['bps_t']['td_log'][] = 'load:' . basename( $mofile );
	$GLOBALS['bps_t']['textdomain'] = $GLOBALS['bps_t']['fr_catalogue'] ?? array();
	return true;
}
function load_plugin_textdomain( $d, $dep = false, $rel = '' ) { $GLOBALS['bps_t']['td_log'][] = 'plugin'; return true; }
function plugin_basename( $f ) { return 'borduur-patch-studio/borduur-patch-studio.php'; }

function sanitize_email( $e ) { $e = trim( (string) $e ); return filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : ''; }
function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function wp_json_encode( $v, $flags = 0, $depth = 512 ) { return json_encode( $v, $flags, $depth ); }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?? time() ); }
function wp_salt( $s = 'auth' ) { return 'test-salt-' . $s; }
function wp_generate_password( $len = 12, $sp = true, $esp = false ) { return substr( str_repeat( bin2hex( random_bytes( 32 ) ), 2 ), 0, $len ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function home_url( $p = '' ) { return 'https://shop.test' . $p; }
function admin_url( $p = '' ) { return 'https://shop.test/wp-admin/' . $p; }
function add_query_arg( $args, $url = '' ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_nonce_url( $u, $a = -1 ) { return add_query_arg( array( '_wpnonce' => 'nonce-' . $a ), $u ); }
function wp_create_nonce( $a = -1 ) { return 'nonce-' . $a; }
function status_header( $c ) { $GLOBALS['bps_t']['status'] = (int) $c; }
function nocache_headers() {}
function wc_format_datetime( $d, $f = '' ) { return gmdate( 'd-m-Y', $d->getTimestamp() ); }
function wp_upload_dir( $time = null, $create = true ) {
	$d = $GLOBALS['bps_t']['upload_dir'];
	return array( 'basedir' => $d, 'baseurl' => 'https://shop.test/wp-content/uploads', 'path' => $d, 'url' => 'https://shop.test/wp-content/uploads' );
}

/* ----------------------------------------------------------------- users */

class WP_User {
	public $ID;
	public $display_name;
	public function __construct( int $id, string $name = '' ) { $this->ID = $id; $this->display_name = $name; }
	public function exists() { return $this->ID > 0; }
}
function wp_get_current_user() { return new WP_User( (int) $GLOBALS['bps_t']['user'], $GLOBALS['bps_t']['user'] ? 'Atelier Ann' : '' ); }
function get_current_user_id() { return (int) $GLOBALS['bps_t']['user']; }
function current_user_can( $c ) { return in_array( $c, $GLOBALS['bps_t']['caps'], true ); }
function is_admin() { return false; }

/* ----------------------------------------------------------------- posts / attachments */

class WP_Post {
	public $ID;
	public $post_type;
	public $post_title;
	public function __construct( $id, $type, $title ) { $this->ID = $id; $this->post_type = $type; $this->post_title = $title; }
}
function get_post( $id = null ) { return $GLOBALS['bps_t']['posts'][ (int) $id ] ?? null; }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['bps_t']['meta'][ (int) $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['bps_t']['meta'][ (int) $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['bps_t']['meta'][ (int) $id ][ $key ] ); return true; }
function get_posts( $args ) {
	$out = array();
	foreach ( $GLOBALS['bps_t']['posts'] as $id => $p ) {
		if ( $p->post_type === ( $args['post_type'] ?? '' ) && ( empty( $args['meta_key'] ) || isset( $GLOBALS['bps_t']['meta'][ $id ][ $args['meta_key'] ] ) ) ) {
			$out[] = $id;
		}
	}
	sort( $out );
	$per = (int) ( $args['posts_per_page'] ?? 10 );
	return array_slice( $out, ( max( 1, (int) ( $args['paged'] ?? 1 ) ) - 1 ) * $per, $per );
}
/** Register an image attachment backed by a real file (so the SHA-256 can be computed). */
function bps_t_attachment( string $bytes ): int {
	$id   = ++$GLOBALS['bps_t']['next_id'];
	$file = $GLOBALS['bps_t']['upload_dir'] . '/proof-' . $id . '.png';
	file_put_contents( $file, $bytes );
	$GLOBALS['bps_t']['attachments'][ $id ] = $file;
	return $id;
}
function wp_attachment_is_image( $id ) { return isset( $GLOBALS['bps_t']['attachments'][ (int) $id ] ); }
function get_attached_file( $id ) { return $GLOBALS['bps_t']['attachments'][ (int) $id ] ?? ''; }
function wp_get_attachment_url( $id ) { return isset( $GLOBALS['bps_t']['attachments'][ (int) $id ] ) ? 'https://shop.test/wp-content/uploads/' . basename( $GLOBALS['bps_t']['attachments'][ (int) $id ] ) : false; }
function wp_get_attachment_image_src( $id, $size = 'thumbnail' ) {
	$u = wp_get_attachment_url( $id );
	return $u ? array( str_replace( '.png', '-' . $size . '.png', $u ), 100, 100, true ) : false;
}

/* ----------------------------------------------------------------- cron */

function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['bps_t']['cron'][ $hook ] ?? false; }
function wp_schedule_event( $ts, $rec, $hook, $args = array() ) { $GLOBALS['bps_t']['cron'][ $hook ] = $ts; return true; }
function wp_clear_scheduled_hook( $hook, $args = array() ) { unset( $GLOBALS['bps_t']['cron'][ $hook ] ); return 1; }

/* ----------------------------------------------------------------- WooCommerce */

class Bps_T_Date {
	private $ts;
	public function __construct( int $ts ) { $this->ts = $ts; }
	public function getTimestamp() { return $this->ts; }
}

class WC_Order_Item_Product {
	public $id;
	public $order_id;
	public $name     = 'Patch op maat';
	public $quantity = 10;
	public $meta     = array();
	public $saves    = 0;
	public function __construct( int $id = 0, int $order_id = 0 ) { $this->id = $id; $this->order_id = $order_id; }
	public function get_id() { return $this->id; }
	public function get_order_id() { return $this->order_id; }
	public function get_order() { return wc_get_order( $this->order_id ); }
	public function get_name() { return $this->name; }
	public function get_quantity() { return $this->quantity; }
	public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function add_meta_data( $k, $v, $unique = false ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function save() { ++$this->saves; return $this->id; }
}

class WC_Order {
	public $id;
	public $status   = 'on-hold';
	public $items    = array();
	public $meta     = array();
	public $notes    = array();
	public $total    = 121.0;
	public $refunded = array();
	public $email    = 'klant@example.test';
	public $customer = 0;
	public $shipping = 'Vast tarief';
	public $payment  = 'Overschrijving';
	public $phone    = '0470 12 34 56';
	public function __construct( int $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_order_number() { return (string) $this->id; }
	public function get_order_key() { return 'wc_order_key' . $this->id; }
	public function get_status() { return $this->status; }
	public function has_status( $s ) { return in_array( $this->status, (array) $s, true ); }
	public function get_items( $type = 'line_item' ) { return 'line_item' === $type ? $this->items : array(); }
	public function get_item( $id ) { return $this->items[ (int) $id ] ?? false; }
	public function get_meta( $k, $single = true ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	public function save() { return $this->id; }
	public function add_order_note( $text, $customer = 0, $by_user = false ) { $this->notes[] = (string) $text; return count( $this->notes ); }
	public function is_paid() { return in_array( $this->status, array( 'processing', 'completed' ), true ); }
	public function needs_payment() { return in_array( $this->status, array( 'pending', 'failed' ), true ) && $this->total > 0; }
	public function get_checkout_payment_url() { return 'https://shop.test/afrekenen/order-pay/' . $this->id . '/?pay_for_order=true&key=' . $this->get_order_key(); }
	public function update_status( $to, $note = '' ) {
		$from         = $this->status;
		$this->status = $to;
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
		do_action( 'woocommerce_order_status_' . $to, $this->id, $this );
		do_action( 'woocommerce_order_status_changed', $this->id, $from, $to, $this );
		return true;
	}
	public function get_qty_refunded_for_item( $item_id ) { return -1 * (int) ( $this->refunded[ (int) $item_id ] ?? 0 ); }
	public function get_billing_first_name() { return 'Jan'; }
	public function get_billing_last_name() { return "D'Hondt"; }
	public function get_formatted_billing_full_name() { return "Jan D'Hondt"; }
	public function get_billing_company() { return ''; }
	public function get_billing_email() { return $this->email; }
	public function get_billing_phone() { return $this->phone; }
	public function get_customer_id() { return $this->customer; }
	public function get_shipping_method() { return $this->shipping; }
	public function get_payment_method_title() { return $this->payment; }
	public function get_edit_order_url() { return 'https://shop.test/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $this->id; }
	public function get_date_created() { return new Bps_T_Date( time() ); }
}

/** Create an order with patch lines; every line = item meta array (+ optional 'qty'). */
function bps_t_order( array $lines, string $status = 'on-hold' ): WC_Order {
	$id        = ++$GLOBALS['bps_t']['next_id'];
	$o         = new WC_Order( $id );
	$o->status = $status;
	foreach ( $lines as $meta ) {
		$iid      = ++$GLOBALS['bps_t']['next_item'];
		$item     = new WC_Order_Item_Product( $iid, $id );
		if ( isset( $meta['qty'] ) ) {
			$item->quantity = (int) $meta['qty'];
			unset( $meta['qty'] );
		}
		$item->meta       = $meta;
		$o->items[ $iid ] = $item;
	}
	$GLOBALS['bps_t']['orders'][ $id ] = $o;
	return $o;
}
function wc_get_order( $id ) {
	if ( $id instanceof WC_Order ) {
		return $id;
	}
	return $GLOBALS['bps_t']['orders'][ (int) $id ] ?? false;
}
function wc_get_orders( $args ) {
	$out = array();
	foreach ( $GLOBALS['bps_t']['orders'] as $id => $o ) {
		if ( isset( $args['billing_email'] ) && strtolower( $o->email ) !== strtolower( (string) $args['billing_email'] ) ) {
			continue;
		}
		$ok = true;
		foreach ( (array) ( $args['meta_query'] ?? array() ) as $mq ) {
			if ( is_array( $mq ) && (string) $o->get_meta( $mq['key'] ) !== (string) $mq['value'] ) {
				$ok = false;
			}
		}
		if ( $ok ) {
			$out[] = $o;
		}
	}
	$limit = (int) ( $args['limit'] ?? 10 );
	$page  = max( 1, (int) ( $args['paged'] ?? 1 ) );
	return $limit > 0 ? array_slice( $out, ( $page - 1 ) * $limit, $limit ) : $out;
}
function wc_get_order_statuses() {
	return array( 'wc-pending' => 'Pending', 'wc-processing' => 'Processing', 'wc-on-hold' => 'On hold', 'wc-completed' => 'Completed', 'wc-cancelled' => 'Cancelled', 'wc-refunded' => 'Refunded', 'wc-failed' => 'Failed' );
}
function is_wc_endpoint_url( $e = '' ) { return $GLOBALS['bps_t']['endpoint'] === $e; }
function is_order_received_page() { return (bool) $GLOBALS['bps_t']['received']; }

/** wc_get_template_html: include the template with its variables. */
function wc_get_template_html( $template, $args = array(), $path = '', $base = '' ) {
	ob_start();
	( static function ( $__f, $__a ) {
		extract( $__a );
		include $__f;
	} )( rtrim( $base, '/' ) . '/' . $template, $args );
	return (string) ob_get_clean();
}

/** WC_Email double: enough of the real API for Woo\Emails\Base and our e-mails. */
class WC_Email {
	public $id;
	public $title;
	public $description;
	public $template_html;
	public $template_plain;
	public $template_base;
	public $customer_email = false;
	public $recipient      = '';
	public $object;
	public $placeholders   = array();
	public $form_fields    = array();
	public $settings       = array();
	public $email_type     = 'html';
	public function __construct() {
		$this->placeholders = array_merge( array( '{site_title}' => 'Borduurservice Test' ), $this->placeholders );
		$this->init_form_fields();
		$this->settings = (array) get_option( 'woocommerce_' . $this->id . '_settings', array() );
	}
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'            => array( 'type' => 'checkbox' ),
			'subject'            => array( 'type' => 'text' ),
			'heading'            => array( 'type' => 'text' ),
			'additional_content' => array( 'type' => 'textarea' ),
			'email_type'         => array( 'type' => 'select' ),
		);
	}
	public function get_option( $k, $empty = null ) {
		$v = $this->settings[ $k ] ?? '';
		return ( '' === $v && null !== $empty ) ? $empty : $v;
	}
	public function is_enabled() { return ! in_array( $this->id, $GLOBALS['bps_t']['mail_disabled'], true ); }
	public function is_customer_email() { return $this->customer_email; }
	public function get_recipient() { return $this->recipient; }
	public function format_string( $s ) { return strtr( (string) $s, $this->placeholders ); }
	public function get_subject() { return $this->format_string( $this->get_option( 'subject', $this->get_default_subject() ) ); }
	public function get_heading() { return $this->format_string( $this->get_option( 'heading', $this->get_default_heading() ) ); }
	public function get_default_additional_content() { return ''; }
	public function get_additional_content() { return $this->format_string( $this->get_option( 'additional_content', $this->get_default_additional_content() ) ); }
	public function get_email_type() { return $this->email_type; }
	public function get_content() { return 'plain' === $this->email_type ? $this->get_content_plain() : $this->get_content_html(); }
	public function get_headers() { return ''; }
	public function get_attachments() { return array(); }
	public function setup_locale() {}
	public function restore_locale() {}
	public function send( $to, $subject, $message, $headers, $attachments ) {
		$GLOBALS['bps_t']['mails'][] = array( 'id' => $this->id, 'to' => $to, 'subject' => $subject, 'message' => $message, 'plain' => $this->get_content_plain() );
		return true;
	}
}

class Bps_T_Mailer {
	public function get_emails() {
		if ( null === $GLOBALS['bps_t']['emails'] ) {
			$GLOBALS['bps_t']['emails'] = apply_filters( 'woocommerce_email_classes', array() );
		}
		return $GLOBALS['bps_t']['emails'];
	}
}
class Bps_T_WC {
	public function mailer() { return new Bps_T_Mailer(); }
}
function WC() { return new Bps_T_WC(); }

} // namespace

/* ----------------------------------------------------------------- plugin doubles */

namespace BorduurPatchStudio {

	/** Logger double (contract C1). */
	final class Logger {
		public static $events = array();
		public static function error( string $event, array $context = array() ): bool {
			$e              = $context['exception'] ?? null;
			self::$events[] = array( 'error', $event, $e instanceof \Throwable ? $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() : '' );
			return true;
		}
		public static function warning( string $event, array $context = array() ): bool { return true; }
		public static function info( string $event, array $context = array() ): bool { return true; }
		public static function critical( string $event, array $context = array() ): bool { return self::error( $event, $context ); }
	}

	/** Settings double: bundled defaults + $GLOBALS['bps_t']['settings'] overrides (P08 owns the real class). */
	final class Settings {
		public static function get(): array {
			static $defaults = null;
			if ( null === $defaults ) {
				$defaults = json_decode( (string) file_get_contents( BPS_DIR . 'config/defaults.json' ), true );
			}
			return array_replace_recursive( $defaults, (array) $GLOBALS['bps_t']['settings'] );
		}
		public static function get_path( string $dot, $default = null ) {
			$node = self::get();
			foreach ( explode( '.', $dot ) as $part ) {
				if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
					return $default;
				}
				$node = $node[ $part ];
			}
			return $node;
		}
	}
}

namespace BorduurPatchStudio\Woo {

	/** Designs double (P06 owns the real class): enough of get() for the production sheet and the proof. */
	final class Designs {
		const CPT = 'bps_design';
		public static function get( int $id ): ?array {
			$post = get_post( $id );
			if ( ! $post || self::CPT !== $post->post_type ) {
				return null;
			}
			$m = $GLOBALS['bps_t']['meta'][ $id ] ?? array();
			return array(
				'id'            => $id,
				'ref'           => $post->post_title,
				'status'        => (string) ( $m['_bps_status'] ?? 'ordered' ),
				'created_gmt'   => '2026-11-01 10:00:00',
				'payload'       => (array) json_decode( (string) ( $m['_bps_payload'] ?? '{}' ), true ),
				'original'      => null,
				'preview_url'   => (string) ( $m['_bps_preview']['url'] ?? '' ),
				'order_id'      => (int) ( $m['_bps_order_id'] ?? 0 ),
				'order_item_id' => (int) ( $m['_bps_order_item_id'] ?? 0 ),
				'email'         => null,
				'proof'         => is_array( $m['_bps_proof'] ?? null ) ? $m['_bps_proof'] : array( 'status' => 'none', 'history' => array() ),
			);
		}
		public static function original_download_url( int $id ): string { return 'https://shop.test/wp-admin/admin-post.php?action=bps_download&design=' . $id; }
		public static function preview_url( int $id, string $size = 'full' ): string {
			$u = (string) ( $GLOBALS['bps_t']['meta'][ $id ]['_bps_preview']['url'] ?? '' );
			return 'mail' === $size && '' !== $u ? str_replace( '.png', '-600.jpg', $u ) : $u;
		}
	}
}

namespace BorduurPatchStudio\Woo {

	/** Woo\Config double (Base::setup_locale only). */
	final class Config {
		public static function wp_locale( ?string $locale = null ): string {
			return ( null !== $locale && 0 === stripos( $locale, 'fr' ) ) ? 'fr_BE' : 'nl_BE';
		}
	}
}

namespace {
	class Bps_T_Scripts {
		public $registered = array();
	}
	function wp_scripts() { static $s = null; return $s ?? ( $s = new Bps_T_Scripts() ); }
	function wp_script_is( $h, $l = 'enqueued' ) { return true; }
	function wp_style_is( $h, $l = 'enqueued' ) { return true; }
	function wp_register_script( ...$a ) { return true; }
	function wp_register_style( ...$a ) { return true; }
	function plugin_dir_url( $f ) { return BPS_URL; }
}

namespace {
	function __return_true() { return true; }
	function wp_robots_no_robots( $r = array() ) { return $r; }
	function get_header() { echo '<!doctype html><html lang="nl-BE"><body><main id="theme-main-is-not-ours">'; }
	function get_footer() { echo '</main></body></html>'; }
}
