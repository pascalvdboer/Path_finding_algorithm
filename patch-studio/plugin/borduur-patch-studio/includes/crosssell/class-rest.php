<?php
/**
 * Cross-sell REST (SPEC-EXTRA §2.8), namespace patch-studio/v1:
 *   GET  /crosssell/offers?designId=  (header X-BPS-Design-Token of ?token=)  ·  ?source=cart (eigen WC-sessie)
 *   POST /crosssell/cart   { designId, token | source:'cart', item, lines[], options?, plate?, names?, placement? }
 *   POST /crosssell/undo   { key }
 *   POST /crosssell/event  { item, kind: 'shown'|'declined' }
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Crosssell;

use BorduurPatchStudio\Rest\Guard;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Routes.
 */
final class Rest {

	const NS       = 'patch-studio/v1';
	const MAX_BODY = 65536;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Routes registreren.
	 */
	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/crosssell/offers',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'offers' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);
		register_rest_route(
			self::NS,
			'/crosssell/cart',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cart' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			)
		);
		register_rest_route(
			self::NS,
			'/crosssell/undo',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'undo' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			)
		);
		register_rest_route(
			self::NS,
			'/crosssell/event',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'event' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);
	}

	/**
	 * Leesrechten: rate limit (Guard).
	 *
	 * @param WP_REST_Request $r Verzoek.
	 * @return true|WP_Error
	 */
	public static function can_read( WP_REST_Request $r ) {
		if ( ! Config::enabled() ) {
			return new WP_Error( 'bps_cs_disabled', 'Cross-sell staat uit.', array( 'status' => 404 ) );
		}
		return class_exists( Guard::class ) ? Guard::public_read( $r ) : true;
	}

	/**
	 * Schrijfrechten: token + rate limit (Guard).
	 *
	 * @param WP_REST_Request $r Verzoek.
	 * @return true|WP_Error
	 */
	public static function can_write( WP_REST_Request $r ) {
		if ( ! Config::enabled() ) {
			return new WP_Error( 'bps_cs_disabled', 'Cross-sell staat uit.', array( 'status' => 404 ) );
		}
		if ( ! class_exists( Guard::class ) ) {
			return new WP_Error( 'bps_forbidden', 'Niet beschikbaar.', array( 'status' => 403 ) );
		}
		return Guard::public_write( $r );
	}

	/**
	 * Locale van het verzoek.
	 *
	 * @param WP_REST_Request $r Verzoek.
	 */
	private static function locale( WP_REST_Request $r ): string {
		return Config::locale( (string) $r->get_header( 'x-bps-locale' ) );
	}

	/**
	 * JSON-body (begrensd).
	 *
	 * @param WP_REST_Request $r Verzoek.
	 */
	private static function body( WP_REST_Request $r ): ?array {
		if ( strlen( (string) $r->get_body() ) > self::MAX_BODY ) {
			return null;
		}
		$b = $r->get_json_params();
		return is_array( $b ) ? $b : null;
	}

	/**
	 * WooCommerce-mand laden in een REST-verzoek.
	 *
	 * @param bool $create Sessie starten als er geen is.
	 */
	private static function load_cart( bool $create ): bool {
		if ( ! function_exists( 'wc_load_cart' ) || ! function_exists( 'WC' ) ) {
			return false;
		}
		if ( ! is_user_logged_in() ) {
			$uid = (int) wp_validate_auth_cookie( '', 'logged_in' );
			if ( $uid > 0 ) {
				wp_set_current_user( $uid );
			}
		}
		wc_load_cart();
		if ( ! WC()->cart ) {
			return false;
		}
		if ( $create && WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		return true;
	}

	/**
	 * Ontwerp uit het verzoek: token (header of veld) of source=cart (laatste ontwerp in de eigen mand).
	 *
	 * @param WP_REST_Request $r    Verzoek.
	 * @param array           $body Body (POST) of query (GET).
	 * @return array|WP_Error DesignRecord.
	 */
	private static function resolve_design( WP_REST_Request $r, array $body ) {
		$locale = self::locale( $r );
		$source = (string) ( $body['source'] ?? '' );
		$id     = (int) ( $body['designId'] ?? 0 );
		if ( 'cart' === $source ) {
			$items = Service::cart_items();
			$id    = $id > 0 && Service::in_cart( $id ) ? $id : Lines::last_design_id( $items );
			if ( $id <= 0 ) {
				return new WP_Error( 'bps_cs_no_design', Messages::t( 'cs.err.generic', $locale ), array( 'status' => 404 ) );
			}
		} else {
			$token = (string) $r->get_header( 'x-bps-design-token' );
			if ( '' === $token ) {
				$token = (string) ( $body['token'] ?? '' );
			}
			if ( ! Service::token_ok( $id, $token ) ) {
				return new WP_Error( 'bps_forbidden', Messages::t( 'cs.err.generic', $locale ), array( 'status' => 403 ) );
			}
		}
		$rec = Service::design( $id );
		if ( ! $rec ) {
			return new WP_Error( 'bps_cs_no_design', Messages::t( 'cs.err.generic', $locale ), array( 'status' => 404 ) );
		}
		return $rec;
	}

	/**
	 * GET /crosssell/offers.
	 *
	 * @param WP_REST_Request $r Verzoek.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function offers( WP_REST_Request $r ) {
		$q = array(
			'designId' => absint( $r->get_param( 'designId' ) ),
			'token'    => sanitize_text_field( (string) $r->get_param( 'token' ) ),
			'source'   => 'cart' === $r->get_param( 'source' ) ? 'cart' : '',
		);
		self::load_cart( false );
		$rec = self::resolve_design( $r, $q );
		if ( is_wp_error( $rec ) ) {
			return $rec;
		}
		$res = new WP_REST_Response( Service::public_offers( Service::offers( $rec, self::locale( $r ) ) ), 200 );
		$res->header( 'Cache-Control', 'no-store, private' );
		return $res;
	}

	/**
	 * POST /crosssell/cart.
	 *
	 * @param WP_REST_Request $r Verzoek.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cart( WP_REST_Request $r ) {
		$locale = self::locale( $r );
		$b      = self::body( $r );
		if ( null === $b || ! is_string( $b['item'] ?? null ) || ! preg_match( '/^[a-z0-9_]{1,40}$/', $b['item'] ) ) {
			return new WP_Error( 'bps_cs_invalid', Messages::t( 'cs.err.generic', $locale ), array( 'status' => 400 ) );
		}
		if ( ! self::load_cart( true ) ) {
			return new WP_Error( 'bps_unavailable', Messages::t( 'cs.err.generic', $locale ), array( 'status' => 503 ) );
		}
		$b['source']   = 'cart' === ( $b['source'] ?? '' ) ? 'cart' : '';
		$b['designId'] = absint( $b['designId'] ?? 0 );
		$rec           = self::resolve_design( $r, $b );
		if ( is_wp_error( $rec ) ) {
			return $rec;
		}
		$res = Cart::add( $rec, $b['item'], $b, $locale );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		WC()->cart->calculate_totals();
		Service::flush();
		$out = new WP_REST_Response(
			array(
				'ok'        => true,
				'cartCount' => (int) WC()->cart->get_cart_contents_count(),
				'cartUrl'   => esc_url_raw( wc_get_cart_url() ),
				'keys'      => $res['keys'],
				'cards'     => Service::offers( $rec, $locale )['cards'],
			),
			200
		);
		$out->header( 'Cache-Control', 'no-store, private' );
		return $out;
	}

	/**
	 * POST /crosssell/undo.
	 *
	 * @param WP_REST_Request $r Verzoek.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function undo( WP_REST_Request $r ) {
		$b   = self::body( $r );
		$key = is_array( $b ) ? (string) ( $b['key'] ?? '' ) : '';
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) || ! self::load_cart( false ) || ! Cart::undo( $key ) ) {
			return new WP_Error( 'bps_cs_not_found', Messages::t( 'cs.err.generic', self::locale( $r ) ), array( 'status' => 404 ) );
		}
		WC()->cart->calculate_totals();
		return new WP_REST_Response(
			array(
				'ok'        => true,
				'cartCount' => (int) WC()->cart->get_cart_contents_count(),
			),
			200
		);
	}

	/**
	 * POST /crosssell/event (tellers zonder persoonsgegevens; "declined" onthoudt dat ook in de WC-sessie).
	 *
	 * @param WP_REST_Request $r Verzoek.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function event( WP_REST_Request $r ) {
		$b    = self::body( $r );
		$item = is_array( $b ) ? (string) ( $b['item'] ?? '' ) : '';
		$kind = is_array( $b ) ? (string) ( $b['kind'] ?? '' ) : '';
		if ( ! in_array( $kind, array( 'shown', 'declined' ), true ) || ! Config::item( $item ) ) {
			return new WP_Error( 'bps_cs_invalid', 'Ongeldig.', array( 'status' => 400 ) );
		}
		Stats::bump( $item, $kind );
		if ( 'declined' === $kind && self::load_cart( false ) && WC()->session ) {
			WC()->session->set( 'bps_cs_declined', 1 );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
