<?php
/**
 * REST routes of the commerce module (SPEC-AI-DIENSTEN §4.2): /ai/credits*, /ai/email*, /ai/checkout, /services/*.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Logged in: WP cookie + X-WP-Nonce. Guests: X-BPS-Token (existing GET /token) + cookie bps_ai_sid.
 * Error shape: {code, message (nl/fr), data:{status}}. Never an owner key, e-mail or order data of others in answers.
 */
final class Rest {

	const NS = 'patch-studio/v1';

	/** WooCommerce session key for dismissed service offers (only when a session already exists). */
	const SESSION_DISMISSED = 'bps_svc_dismissed';

	/** @var Credits|null Tests. */
	public static $credits = null;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'catalog_cache' ), 99, 3 );
	}

	/**
	 * Credits in use.
	 */
	private static function ledger(): Credits {
		return self::$credits ? self::$credits : Credits::instance();
	}

	/**
	 * Register routes.
	 */
	public static function routes(): void {
		$write = array( __CLASS__, 'can_write' );
		$read  = array( __CLASS__, 'can_read' );
		$list  = array(
			'/ai/credits'         => array( 'GET', 'credits_get', $read ),
			'/ai/credits/consent' => array( 'POST', 'consent', $write ),
			'/ai/email'           => array( 'POST', 'email', $write ),
			'/ai/email/verify'    => array( 'POST', 'email_verify', $write ),
			'/ai/checkout'        => array( 'POST', 'checkout', $write ),
			'/services/catalog'   => array( 'GET', 'catalog', '__return_true' ),
			'/services/cart'      => array( 'POST', 'service_cart', $write ),
			'/services/dismiss'   => array( 'POST', 'dismiss', $write ),
		);
		foreach ( $list as $route => $def ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => $def[0],
					'callback'            => array( __CLASS__, $def[1] ),
					'permission_callback' => $def[2],
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Guards
	 * ------------------------------------------------------------------- */

	/**
	 * Same-site check: a foreign Origin header is refused (403 bps_bad_origin).
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function origin_ok( WP_REST_Request $r ): bool {
		$origin = (string) $r->get_header( 'origin' );
		if ( '' === $origin ) {
			return true;
		}
		$host  = wp_parse_url( $origin, PHP_URL_HOST );
		$home  = wp_parse_url( home_url(), PHP_URL_HOST );
		$extra = (array) apply_filters( 'bps_allowed_origins', array() );
		return is_string( $host ) && ( strtolower( $host ) === strtolower( (string) $home ) || in_array( $origin, $extra, true ) );
	}

	/**
	 * Authenticated as user (nonce checked by WP core) or guest with a valid studio token.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	private static function authed( WP_REST_Request $r ): bool {
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return true;
		}
		return class_exists( '\BorduurPatchStudio\Rest\Guard' ) && \BorduurPatchStudio\Rest\Guard::token_ok( $r );
	}

	/**
	 * Read permission.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return true|WP_Error
	 */
	public static function can_read( WP_REST_Request $r ) {
		self::locale( $r );
		if ( ! self::origin_ok( $r ) ) {
			return self::error( 'bps_bad_origin', 'err.origin', 403 );
		}
		if ( ! self::authed( $r ) ) {
			return self::error( 'bps_bad_token', 'err.session', 401 );
		}
		return self::rate( 'ai_poll' );
	}

	/**
	 * Write permission.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return true|WP_Error
	 */
	public static function can_write( WP_REST_Request $r ) {
		self::locale( $r );
		if ( ! self::origin_ok( $r ) ) {
			return self::error( 'bps_bad_origin', 'err.origin', 403 );
		}
		if ( ! self::authed( $r ) ) {
			return self::error( 'bps_bad_token', 'err.session', 401 );
		}
		$bucket = preg_match( '#/ai/email#', (string) $r->get_route() ) ? 'ai_email' : 'ai_write';
		return self::rate( $bucket );
	}

	/**
	 * Rate limit through AI\Limits when available, else our own fixed window per IP hash.
	 *
	 * @param string $bucket ai_write|ai_poll|ai_email.
	 * @return true|WP_Error
	 */
	private static function rate( string $bucket ) {
		$ip = Owner::ip_hash();
		if ( class_exists( '\BorduurPatchStudio\AI\Limits' ) && class_exists( '\BorduurPatchStudio\AI\AI_Settings' ) ) {
			return \BorduurPatchStudio\AI\Limits::rate( $bucket, $ip );
		}
		$conf   = array(
			'ai_poll'  => array( 120, MINUTE_IN_SECONDS ),
			'ai_email' => array( 5, HOUR_IN_SECONDS ),
			'ai_write' => array( 30, HOUR_IN_SECONDS ),
		);
		list( $max, $window ) = $conf[ $bucket ] ?? $conf['ai_write'];
		$max  = (int) apply_filters( 'bps_ai_rate_limit', $max, $bucket );
		$slot = (int) floor( time() / $window );
		$key  = 'bps_ai_rl_' . substr( hash( 'sha256', $ip . '|' . $bucket . '|' . $slot ), 0, 32 );
		$hits = (int) get_transient( $key );
		if ( $max > 0 && $hits >= $max ) {
			return new WP_Error( 'bps_ai_slow_down', Text::t( 'email.too_many', self::$lang ), array( 'status' => 429, 'retryAfter' => ( $slot + 1 ) * $window - time() ) );
		}
		set_transient( $key, $hits + 1, $window + 60 );
		return true;
	}

	/** @var string Request language. */
	private static $lang = 'nl';

	/**
	 * Pick the language from X-BPS-Locale / ?lang / body lang.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	private static function locale( WP_REST_Request $r ): string {
		$l = (string) $r->get_header( 'x-bps-locale' );
		if ( '' === $l ) {
			$p = $r->get_param( 'lang' );
			$l = is_string( $p ) ? $p : '';
		}
		self::$lang = Text::lang( '' !== $l ? $l : null );
		return self::$lang;
	}

	/**
	 * Error in the spec shape.
	 *
	 * @param string $code   Code.
	 * @param string $key    Text key.
	 * @param int    $status Status.
	 * @param array  $data   Extra data.
	 */
	private static function error( string $code, string $key, int $status, array $data = array() ): WP_Error {
		return new WP_Error( $code, Text::t( $key, self::$lang ), array_merge( array( 'status' => $status ), $data ) );
	}

	/**
	 * JSON body.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	private static function body( WP_REST_Request $r ): array {
		$j = $r->get_json_params();
		if ( is_array( $j ) && $j ) {
			return $j;
		}
		$b = $r->get_body_params();
		return is_array( $b ) ? $b : array();
	}

	/**
	 * No-store JSON answer.
	 *
	 * @param array $data   Data.
	 * @param int   $status Status.
	 */
	private static function ok( array $data, int $status = 200 ): WP_REST_Response {
		$res = new WP_REST_Response( $data, $status );
		$res->header( 'Cache-Control', 'no-store, private' );
		return $res;
	}

	/**
	 * Owner of the request (creates the anonymous AI cookie through AI\Identity when asked and available).
	 *
	 * @param bool $create Create.
	 */
	private static function owner( bool $create = false ): string {
		if ( $create && class_exists( '\BorduurPatchStudio\AI\Identity' ) && ! Owner::$standalone ) {
			$k = (string) \BorduurPatchStudio\AI\Identity::current( true );
			if ( Owner::valid( $k ) ) {
				return $k;
			}
		}
		return Owner::current();
	}

	/* ---------------------------------------------------------------------
	 * Rounds
	 * ------------------------------------------------------------------- */

	/**
	 * GET /ai/credits → {balance, lots:[{source,total,used,expiresAt,locked}]}.
	 */
	public static function credits_get(): WP_REST_Response {
		$owner = self::owner();
		$lots  = array();
		foreach ( self::ledger()->lots( $owner ) as $lot ) {
			$lots[] = array(
				'source'    => $lot['source'],
				'total'     => $lot['total'],
				'used'      => $lot['used'],
				'unused'    => $lot['unused'],
				'status'    => $lot['status'],
				'expiresAt' => $lot['expiresAt'],
				'locked'    => $lot['locked'],
			);
		}
		return self::ok(
			array(
				'balance' => self::ledger()->balance( $owner ),
				'lots'    => $lots,
			)
		);
	}

	/**
	 * POST /ai/credits/consent {start:true, ack:true} → unlock paid rounds now.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function consent( WP_REST_Request $r ) {
		$b = self::body( $r );
		if ( true !== ( $b['start'] ?? null ) || true !== ( $b['ack'] ?? null ) ) {
			return self::error( 'bps_ai_invalid', 'consent.both', 400 );
		}
		$owner = self::owner();
		if ( ! Owner::valid( $owner ) ) {
			return self::error( 'bps_ai_not_found', 'err.no_owner', 404 );
		}
		$credits = self::ledger();
		// Proof on the pack orders (04 §4.2): same record as at checkout, marked as given later.
		$orders = array();
		foreach ( $credits->store()->lots_for_owner( $owner, array( 'active' ) ) as $lot ) {
			if ( 'pack' === $lot['source'] && empty( $lot['consent_at'] ) && ! empty( $lot['order_id'] ) ) {
				$orders[ (int) $lot['order_id'] ] = true;
			}
		}
		$n = $credits->consent( $owner );
		if ( function_exists( 'wc_get_order' ) ) {
			foreach ( array_keys( $orders ) as $oid ) {
				$order = wc_get_order( $oid );
				if ( $order ) {
					$rec          = Checkout::pack_record( true, true, self::$lang, gmdate( 'Y-m-d H:i:s' ) );
					$rec['later'] = true;
					$order->update_meta_data( Checkout::PACK_META, $rec );
					$order->add_order_note( 'Patch Studio: klant gaf toestemming om meteen te starten (via de AI-studio).' );
					$order->save();
				}
			}
		}
		$balance = $credits->balance( $owner );
		return self::ok(
			array(
				'balance'  => $balance,
				'unlocked' => $n,
				'message'  => Text::t( 'consent.saved', self::$lang ),
			)
		);
	}

	/**
	 * POST /ai/email {email} → code by mail. Same answer for known and unknown addresses.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function email( WP_REST_Request $r ) {
		$b   = self::body( $r );
		$res = Email_Gate::request( (string) ( $b['email'] ?? '' ), Owner::ip_hash(), self::$lang, self::ledger() );
		return is_wp_error( $res ) ? $res : self::ok( $res );
	}

	/**
	 * POST /ai/email/verify {email, code} → {balance, granted}.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function email_verify( WP_REST_Request $r ) {
		$b   = self::body( $r );
		$res = Email_Gate::verify( (string) ( $b['email'] ?? '' ), (string) ( $b['code'] ?? '' ), self::owner( true ), self::$lang, self::ledger() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		// The browser now follows the stronger key (anonymous cookie → e-mail owner).
		$anon = Owner::current_anon();
		if ( '' !== $anon && $anon !== $res['owner'] ) {
			Owner::link( $anon, $res['owner'] );
		}
		return self::ok(
			array(
				'balance' => $res['balance'],
				'granted' => (int) $res['granted'],
			)
		);
	}

	/**
	 * POST /ai/checkout {packId} → pack in the cart, {checkoutUrl, packId}.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function checkout( WP_REST_Request $r ) {
		$b       = self::body( $r );
		$pack_id = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $b['packId'] ?? '' ) ) );
		$pack    = Config::pack( $pack_id );
		if ( ! $pack || ! $pack['enabled'] ) {
			return self::error( 'bps_ai_invalid', 'err.pack_unknown', 400 );
		}
		if ( ! Products::packs_buyable() ) {
			return self::error( 'bps_ai_off', 'err.not_buyable', 403 );
		}
		$pid = Products::product_id( 'pack', $pack_id );
		if ( $pid <= 0 || ! function_exists( 'wc_load_cart' ) ) {
			return self::error( 'bps_ai_no_woo', 'err.no_woo', 503 );
		}
		self::restore_user();
		wc_load_cart();
		if ( ! WC()->cart ) {
			return self::error( 'bps_ai_no_woo', 'err.no_woo', 503 );
		}
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		$cart = WC()->cart;
		// One pack per click: a second tap on the same pack does not add another one.
		$found = false;
		foreach ( $cart->get_cart() as $item ) {
			if ( (int) ( $item['product_id'] ?? 0 ) === $pid ) {
				$found = true;
				break;
			}
		}
		if ( ! $found && ! $cart->add_to_cart( $pid, 1 ) ) {
			return self::error( 'bps_ai_no_woo', 'err.no_woo', 503 );
		}
		$owner = self::owner( true );
		if ( WC()->session && 'a' === Owner::kind( $owner ) ) {
			WC()->session->set( Checkout::SESSION_OWNER, $owner );
		}
		return self::ok(
			array(
				'checkoutUrl' => wc_get_checkout_url(),
				'packId'      => $pack_id,
			)
		);
	}

	/**
	 * Logged-in customers without X-WP-Nonce (token-only calls): restore the user for the cart only.
	 */
	private static function restore_user(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		$uid = (int) wp_validate_auth_cookie( '', 'logged_in' );
		if ( $uid > 0 ) {
			wp_set_current_user( $uid );
		}
	}

	/* ---------------------------------------------------------------------
	 * Services
	 * ------------------------------------------------------------------- */

	/**
	 * GET /services/catalog (public, cacheable 5 min).
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function catalog( WP_REST_Request $r ): WP_REST_Response {
		$lang = self::locale( $r );
		$res  = new WP_REST_Response(
			array(
				'services'   => Services::catalog( $lang ),
				'offerRules' => Config::offer_rules(),
			),
			200
		);
		$res->header( 'Cache-Control', 'public, max-age=300' );
		return $res;
	}

	/**
	 * The plugin's generic no-store filter runs for every route in the namespace; restore caching for the catalogue.
	 *
	 * @param mixed           $response Response.
	 * @param mixed           $server   Server.
	 * @param WP_REST_Request $r        Request.
	 */
	public static function catalog_cache( $response, $server, $r ) {
		unset( $server );
		if ( $response instanceof WP_REST_Response && $r instanceof WP_REST_Request && '/' . self::NS . '/services/catalog' === (string) $r->get_route() && 200 === $response->get_status() ) {
			$response->header( 'Cache-Control', 'public, max-age=300' );
			$response->header( 'Pragma', '' );
			$response->header( 'Expires', '' );
		}
		return $response;
	}

	/**
	 * POST /services/cart {serviceId, designRef?} → {ok, cartUrl, cartCount, line}.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function service_cart( WP_REST_Request $r ) {
		$b   = self::body( $r );
		$id  = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $b['serviceId'] ?? '' ) ) );
		$ref = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $b['designRef'] ?? '' ) ), 0, 40 );
		if ( '' === $id ) {
			return self::error( 'bps_ai_invalid', 'err.invalid', 400 );
		}
		if ( ! function_exists( 'wc_load_cart' ) ) {
			return self::error( 'bps_ai_no_woo', 'err.no_woo', 503 );
		}
		self::restore_user();
		wc_load_cart();
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		$res = Services::add_to_cart( $id, $ref, self::$lang );
		return is_wp_error( $res ) ? $res : self::ok( $res );
	}

	/**
	 * POST /services/dismiss {serviceId, designRef} → remember "Nee, dank je" (existing owner and/or WC session; never
	 * a new cookie).
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function dismiss( WP_REST_Request $r ) {
		$b   = self::body( $r );
		$id  = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $b['serviceId'] ?? '' ) ) );
		$ref = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $b['designRef'] ?? '' ) ), 0, 40 );
		if ( '' === $id || ! Config::service( $id ) ) {
			return self::error( 'bps_ai_invalid', 'err.invalid', 400 );
		}
		$list = self::dismissed_list();
		$key  = $ref . '|' . $id;
		if ( ! in_array( $key, $list, true ) ) {
			$list[] = $key;
			self::store_dismissed( array_slice( $list, -50 ) );
		}
		return self::ok(
			array(
				'ok'      => true,
				'message' => Text::t( 'svc.dismissed', self::$lang ),
			)
		);
	}

	/**
	 * Dismissed "ref|serviceId" entries of this visitor.
	 *
	 * @return string[]
	 */
	public static function dismissed_list(): array {
		$out   = array();
		$owner = Owner::current();
		if ( '' !== $owner ) {
			$v   = get_transient( 'bps_svc_dis_' . substr( md5( $owner ), 0, 24 ) );
			$out = is_array( $v ) ? array_values( array_map( 'strval', $v ) ) : array();
		}
		$session = self::wc_session();
		if ( $session ) {
			$v   = $session->get( self::SESSION_DISMISSED );
			$out = array_values( array_unique( array_merge( $out, is_array( $v ) ? array_map( 'strval', $v ) : array() ) ) );
		}
		return $out;
	}

	/**
	 * The visitor's existing WooCommerce session (never starts one: that would set a cookie).
	 *
	 * @return object|null
	 */
	private static function wc_session() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$wc = WC();
		$s  = is_object( $wc ) && isset( $wc->session ) ? $wc->session : null;
		if ( ! is_object( $s ) || ! method_exists( $s, 'get' ) || ! method_exists( $s, 'set' ) ) {
			return null;
		}
		return ( ! method_exists( $s, 'has_session' ) || $s->has_session() ) ? $s : null;
	}

	/**
	 * Dismissed service ids for one design (for evaluate()).
	 *
	 * @param string $design_ref Design ref.
	 * @return string[]
	 */
	public static function dismissed_for( string $design_ref ): array {
		$out = array();
		foreach ( self::dismissed_list() as $entry ) {
			list( $ref, $id ) = array_pad( explode( '|', $entry, 2 ), 2, '' );
			if ( $ref === $design_ref ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Store the list: on the existing owner (30 days, like the browser id) and/or the existing WooCommerce session.
	 * "No thanks" is a preference, so it never creates the bps_ai_sid cookie or a session (gdpr-juridisch-11):
	 * without either, nothing is stored on the server and the client remembers it for its own session.
	 *
	 * @param string[] $list List.
	 */
	private static function store_dismissed( array $list ): void {
		$owner = self::owner( false );
		if ( '' !== $owner ) {
			set_transient( 'bps_svc_dis_' . substr( md5( $owner ), 0, 24 ), $list, 30 * DAY_IN_SECONDS );
		}
		$session = self::wc_session();
		if ( $session ) {
			$session->set( self::SESSION_DISMISSED, $list );
		}
	}
}
