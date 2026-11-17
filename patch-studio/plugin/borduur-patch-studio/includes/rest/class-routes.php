<?php
/**
 * REST API patch-studio/v1 (SPEC §6.2).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Rest;

use BorduurPatchStudio\Pricing;
use BorduurPatchStudio\Uploads;
use BorduurPatchStudio\Woo\Cart;
use BorduurPatchStudio\Woo\Config;
use BorduurPatchStudio\Woo\Designs;
use BorduurPatchStudio\Woo\Emails;
use BorduurPatchStudio\Woo\Payload;
use BorduurPatchStudio\Woo\Quotes;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes:
 *   GET  /token                      → {token, expires}
 *   POST /quote                      → PriceQuote (server engine)
 *   POST /designs  (multipart)       → 201 {designId, ref, token, previewUrl}
 *   GET  /designs/{id}?token=        → {designId, ref, payload, previewUrl, originalUrl|null}
 *   GET  /designs/{id}/original      → file (signed, 1 h; used by originalUrl)
 *   POST /cart                       → {ok, cartUrl, cartCount, cartItemKey, quote}
 *   POST /quotes                     → {ok, ref}
 *   POST /designs/{id}/email         → {ok}
 */
final class Routes {

	const NS = 'patch-studio/v1';

	/** @var bool Locale switched for this request. */
	private static $switched = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'pre_dispatch' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'post_dispatch' ), 10, 3 );
	}

	/**
	 * Register routes.
	 */
	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/token',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'token' ),
			)
		);
		register_rest_route(
			self::NS,
			'/quote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( Guard::class, 'public_read' ),
				'callback'            => array( __CLASS__, 'quote' ),
			)
		);
		register_rest_route(
			self::NS,
			'/designs',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( Guard::class, 'public_write' ),
				'callback'            => array( __CLASS__, 'create_design' ),
			)
		);
		register_rest_route(
			self::NS,
			'/designs/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true', // HMAC token checked in the callback.
				'callback'            => array( __CLASS__, 'get_design' ),
				'args'                => array(
					'id'    => array(
						'type'     => 'integer',
						'required' => true,
					),
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/designs/(?P<id>\d+)/original',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true', // Signed URL checked in the callback.
				'callback'            => array( __CLASS__, 'original' ),
			)
		);
		register_rest_route(
			self::NS,
			'/designs/(?P<id>\d+)/email',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( Guard::class, 'public_write' ),
				'callback'            => array( __CLASS__, 'email_design' ),
			)
		);
		register_rest_route(
			self::NS,
			'/cart',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( Guard::class, 'public_write' ),
				'callback'            => array( __CLASS__, 'cart' ),
			)
		);
		register_rest_route(
			self::NS,
			'/quotes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( Guard::class, 'public_write' ),
				'callback'            => array( __CLASS__, 'request_quote' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Request plumbing
	 * ------------------------------------------------------------------- */

	/**
	 * Is this one of our routes?
	 *
	 * @param WP_REST_Request $r Request.
	 */
	private static function ours( $r ): bool {
		return $r instanceof WP_REST_Request && 0 === strpos( ltrim( (string) $r->get_route(), '/' ), self::NS );
	}

	/**
	 * Answer in the customer's language (also for permission errors).
	 *
	 * @param mixed           $result Result.
	 * @param WP_REST_Server  $server Server.
	 * @param WP_REST_Request $r      Request.
	 */
	public static function pre_dispatch( $result, $server, $r ) {
		if ( ! self::ours( $r ) ) {
			return $result;
		}
		$locale = (string) ( $r->get_header( 'x-bps-locale' ) ?? '' );
		if ( '' === $locale ) {
			$p      = $r->get_param( 'locale' );
			$locale = is_string( $p ) ? $p : '';
		}
		if ( '' === $locale ) {
			$design = $r->get_param( 'design' );
			if ( is_string( $design ) && preg_match( '/"locale"\s*:\s*"(fr|nl)/i', $design, $m ) ) {
				$locale = $m[1];
			}
		}
		if ( '' !== $locale && 'fr' === Config::lang( $locale ) && 'fr' !== Config::lang() ) {
			self::$switched = (bool) switch_to_locale( 'fr_BE' );
		}
		return $result;
	}

	/**
	 * No caching anywhere (Cloudflare "cache everything" rules included); restore locale.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $r        Request.
	 */
	public static function post_dispatch( $response, $server, $r ) {
		if ( ! self::ours( $r ) ) {
			return $response;
		}
		if ( $response instanceof WP_REST_Response ) {
			foreach ( wp_get_nocache_headers() as $name => $value ) {
				if ( $value ) {
					$response->header( $name, $value );
				}
			}
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
			$response->header( 'X-Robots-Tag', 'noindex' );
		}
		if ( self::$switched ) {
			restore_previous_locale();
			self::$switched = false;
		}
		return $response;
	}

	/**
	 * JSON body as array (JSON or form params).
	 *
	 * @param WP_REST_Request $r Request.
	 */
	private static function body( WP_REST_Request $r ): array {
		$json = $r->get_json_params();
		if ( is_array( $json ) && $json ) {
			return $json;
		}
		$body = $r->get_body_params();
		return is_array( $body ) ? $body : array();
	}

	/**
	 * Uniform error.
	 *
	 * @param string $code   Code.
	 * @param string $msg    Message.
	 * @param int    $status HTTP status.
	 */
	private static function error( string $code, string $msg, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	/* ---------------------------------------------------------------------
	 * Callbacks
	 * ------------------------------------------------------------------- */

	/**
	 * GET /token.
	 */
	public static function token(): WP_REST_Response {
		return new WP_REST_Response( Guard::create_token(), 200 );
	}

	/**
	 * POST /quote — server price with the PHP engine (same algorithm as the browser).
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function quote( WP_REST_Request $r ) {
		if ( ! class_exists( Pricing::class ) ) {
			return self::error( 'bps_unavailable', __( 'De prijsberekening is even niet beschikbaar. Probeer het straks opnieuw.', 'borduur-patch-studio' ), 503 );
		}
		$b    = self::body( $r );
		$tech = Payload::str( $b['technique'] ?? '', 20 );
		$size = Payload::num( $b['sizeCm'] ?? null );
		$qty  = Payload::int( $b['qty'] ?? null );
		if ( '' === $tech || null === $size || null === $qty || $qty < 1 || $qty > 1000000 || $size <= 0 || $size > 1000 ) {
			return self::error( 'bps_invalid', __( 'We konden geen prijs berekenen voor deze keuzes. Controleer de maat en het aantal.', 'borduur-patch-studio' ) );
		}
		$options = array();
		foreach ( array_slice( (array) ( $b['options'] ?? array() ), 0, 40 ) as $o ) {
			$o = Payload::str( $o, 64 );
			if ( '' !== $o ) {
				$options[] = $o;
			}
		}
		$fees = array();
		foreach ( array_slice( (array) ( $b['feesAlreadyCharged'] ?? array() ), 0, 10 ) as $f ) {
			$f = Payload::str( $f, 40 );
			if ( '' !== $f ) {
				$fees[] = $f;
			}
		}
		$input = array(
			'technique'          => $tech,
			'sizeCm'             => (float) $size,
			'qty'                => (int) $qty,
			'options'            => array_values( array_unique( $options ) ),
			'poolQty'            => max( 0, min( 1000000, (int) ( Payload::int( $b['poolQty'] ?? 0 ) ?? 0 ) ) ),
			'feesAlreadyCharged' => $fees,
			'otherItemsInclVat'  => max( 0, min( 10000000, (float) ( Payload::num( $b['otherItemsInclVat'] ?? 0 ) ?? 0 ) ) ),
			'withLadder'         => ! isset( $b['withLadder'] ) || false !== filter_var( $b['withLadder'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ),
			'locale'             => Config::public_locale( Payload::str( $b['locale'] ?? '', 10 ) ),
		);
		try {
			$quote = Pricing::quote( $input );
		} catch ( \Throwable $e ) {
			return self::error( 'bps_invalid', __( 'We konden geen prijs berekenen voor deze keuzes.', 'borduur-patch-studio' ) );
		}
		return new WP_REST_Response( $quote, 200 );
	}

	/**
	 * POST /designs (multipart: design JSON, original file?, preview PNG, website honeypot).
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function create_design( WP_REST_Request $r ) {
		if ( '' !== trim( (string) $r->get_param( 'website' ) ) ) {
			return self::error( 'bps_invalid_design', __( 'We konden je ontwerp niet bewaren. Laad de pagina opnieuw en probeer het nog eens.', 'borduur-patch-studio' ) );
		}
		$raw = $r->get_param( 'design' ); // JSON string (multipart) or object (JSON body).
		$payload = Payload::validate( $raw );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$files = $r->get_file_params();
		if ( empty( $files['preview'] ) || ! is_array( $files['preview'] ) ) {
			return self::error( 'bps_invalid_design', __( 'Het voorbeeld van je patch ontbreekt. Probeer het nog eens.', 'borduur-patch-studio' ) );
		}
		if ( ! empty( $files['original'] ) && is_array( $files['original'] ) && UPLOAD_ERR_NO_FILE !== (int) ( $files['original']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			// Size first: friendlier than a generic error after a long upload.
			if ( (int) ( $files['original']['size'] ?? 0 ) > Uploads::max_bytes() ) {
				return self::error(
					'bps_too_big',
					/* translators: %s: max size in MB */
					sprintf( __( 'Je bestand is groter dan %s MB. Stuur het gerust via WeTransfer of per e-mail, dan bekijken we het samen.', 'borduur-patch-studio' ), Config::num( Uploads::max_bytes() / MB_IN_BYTES, 0 ) ),
					413
				);
			}
			$original = Uploads::store_original( $files['original'] );
			if ( is_wp_error( $original ) ) {
				return $original;
			}
		} else {
			$original = null;
		}

		$preview = Uploads::store_preview( $files['preview'] );
		if ( is_wp_error( $preview ) ) {
			if ( $original ) {
				Uploads::delete( $original['path'] );
			}
			return $preview;
		}

		$id = Designs::create( $payload, $original, $preview, self::page_url( $r ) );
		if ( is_wp_error( $id ) ) {
			if ( $original ) {
				Uploads::delete( $original['path'] );
			}
			Uploads::delete( $preview['path'] );
			return $id;
		}

		self::log_price_mismatch( $id, $payload );

		return new WP_REST_Response(
			array(
				'designId'   => $id,
				'ref'        => (string) get_post_field( 'post_title', $id ),
				'token'      => Designs::token_for( $id ),
				'previewUrl' => $preview['url'],
			),
			201
		);
	}

	/**
	 * Studio page URL (same host only), without restore parameters.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	private static function page_url( WP_REST_Request $r ): string {
		$url = (string) $r->get_param( 'pageUrl' );
		if ( '' === $url ) {
			$url = (string) $r->get_header( 'referer' );
		}
		if ( '' === $url ) {
			return '';
		}
		$url = wp_validate_redirect( esc_url_raw( $url ), '' );
		if ( '' === $url ) {
			return '';
		}
		$url = remove_query_arg( array( 'bps_design', 'bps_token' ), $url );
		return (string) strtok( $url, '#' );
	}

	/**
	 * Log (without personal data) when the browser price differs from the server price.
	 *
	 * @param int   $id      Design id.
	 * @param array $payload Payload.
	 */
	private static function log_price_mismatch( int $id, array $payload ): void {
		$client = (float) ( $payload['priceClient']['totalIncl'] ?? 0 );
		if ( $client <= 0 || ! class_exists( Pricing::class ) ) {
			return;
		}
		$server = Cart::quote_for(
			array(
				'technique' => $payload['technique'],
				'size_cm'   => $payload['sizeCm'],
				'options'   => $payload['options'],
			),
			(int) $payload['qty'],
			0,
			array(),
			0,
			(string) $payload['locale']
		);
		if ( empty( $server['ok'] ) ) {
			return;
		}
		$diff = abs( (float) $server['totalIncl'] - $client );
		if ( $diff > 0.01 && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info(
				sprintf( 'Price mismatch for design %d (%s %s cm x %d): client %.2f, server %.2f. The server price is used.', $id, $payload['technique'], $payload['sizeCm'], $payload['qty'], $client, (float) $server['totalIncl'] ),
				array( 'source' => 'patch-studio' )
			);
		}
	}

	/**
	 * GET /designs/{id}?token=.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function get_design( WP_REST_Request $r ) {
		$id    = (int) $r['id'];
		$token = (string) $r->get_param( 'token' );
		if ( ! Designs::verify_token( $id, $token ) ) {
			return self::error( 'bps_forbidden', __( 'Deze link naar je ontwerp werkt niet (meer). Misschien is hij verlopen; je kan gewoon opnieuw beginnen.', 'borduur-patch-studio' ), 403 );
		}
		$d = Designs::get( $id );
		if ( ! $d ) {
			return self::error( 'bps_not_found', __( 'Dit ontwerp bestaat niet meer.', 'borduur-patch-studio' ), 404 );
		}
		Designs::touch( $id );
		return new WP_REST_Response(
			array(
				'designId'    => $id,
				'ref'         => $d['ref'],
				'payload'     => $d['payload'],
				'previewUrl'  => $d['preview_url'],
				'originalUrl' => Designs::signed_original_url( $id ),
			),
			200
		);
	}

	/**
	 * GET /designs/{id}/original?exp=&sig= — streams the original for the "restore" flow.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function original( WP_REST_Request $r ) {
		$id  = (int) $r['id'];
		$exp = (int) $r->get_param( 'exp' );
		$sig = (string) $r->get_param( 'sig' );
		if ( $exp < time() || ! preg_match( '/^[a-f0-9]{64}$/', $sig ) || ! hash_equals( Designs::original_sig( $id, $exp ), $sig ) ) {
			return self::error( 'bps_forbidden', __( 'Deze link is verlopen.', 'borduur-patch-studio' ), 403 );
		}
		$d = Designs::get( $id );
		if ( ! $d || ! $d['original'] || ! in_array( $d['original']['ext'], Uploads::BROWSER_TYPES, true ) || ! Uploads::is_ours( $d['original']['path'], 'private' ) ) {
			return self::error( 'bps_not_found', __( 'Dit bestand bestaat niet meer.', 'borduur-patch-studio' ), 404 );
		}
		Designs::stream_file( $d['original']['path'], 'ontwerp.' . $d['original']['ext'], Uploads::TYPES[ $d['original']['ext'] ] );
		return null; // Not reached.
	}

	/**
	 * POST /cart.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function cart( WP_REST_Request $r ) {
		$b     = self::body( $r );
		$id    = (int) ( Payload::int( $b['designId'] ?? 0 ) ?? 0 );
		$token = Payload::str( $b['token'] ?? '', 64 );
		if ( ! Designs::verify_token( $id, $token ) ) {
			return self::error( 'bps_invalid_design', __( 'We vonden je ontwerp niet terug. Laad de ontwerptool opnieuw en probeer het nog eens.', 'borduur-patch-studio' ) );
		}
		$rights = isset( $b['rightsConsent'] ) && is_array( $b['rightsConsent'] ) ? $b['rightsConsent'] : array();
		if ( true !== ( $rights['accepted'] ?? false ) && 'true' !== ( $rights['accepted'] ?? '' ) ) {
			return self::error( 'bps_rights_required', __( 'Vink aan dat je dit ontwerp mag laten maken. Zo weten we dat alles in orde is.', 'borduur-patch-studio' ) );
		}
		$design = Designs::get( $id );
		if ( ! $design || empty( $design['payload'] ) ) {
			return self::error( 'bps_invalid_design', __( 'We vonden je ontwerp niet terug. Laad de ontwerptool opnieuw en probeer het nog eens.', 'borduur-patch-studio' ) );
		}
		if ( ! function_exists( 'wc_load_cart' ) ) {
			return self::error( 'bps_unavailable', __( 'De winkelmand is even niet beschikbaar. Probeer het straks opnieuw.', 'borduur-patch-studio' ), 503 );
		}

		self::restore_logged_in_user();
		wc_load_cart();
		if ( ! WC()->cart ) {
			return self::error( 'bps_unavailable', __( 'De winkelmand is even niet beschikbaar. Probeer het straks opnieuw.', 'borduur-patch-studio' ), 503 );
		}
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		$res = Cart::add_design( $design, $rights );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		Designs::set_status( $id, 'carted' );
		Designs::touch( $id );

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'cartUrl'     => wc_get_cart_url(),
				'cartCount'   => (int) WC()->cart->get_cart_contents_count(),
				'cartItemKey' => $res['key'],
				'quote'       => $res['quote'],
			),
			200
		);
	}

	/**
	 * Logged-in customers: REST runs as user 0 without X-WP-Nonce, which would make WooCommerce drop
	 * their (user-bound) cart session. Restore the user from the logged_in cookie for the cart only.
	 * Safe against CSRF: the request needs our custom X-BPS-Token header (preflighted cross-site).
	 */
	private static function restore_logged_in_user(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		$uid = (int) wp_validate_auth_cookie( '', 'logged_in' );
		if ( $uid > 0 ) {
			wp_set_current_user( $uid );
		}
	}

	/**
	 * POST /quotes — quote request without payment.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function request_quote( WP_REST_Request $r ) {
		$b = self::body( $r );
		if ( '' !== trim( (string) ( $b['website'] ?? '' ) ) ) {
			return self::error( 'bps_invalid', __( 'Je aanvraag kon niet verstuurd worden. Probeer het nog eens of mail ons.', 'borduur-patch-studio' ) );
		}
		if ( isset( $b['elapsed'] ) && (int) $b['elapsed'] < 3000 ) {
			return self::error( 'bps_invalid', __( 'Dat ging wel heel snel. Kijk je gegevens even na en verstuur opnieuw.', 'borduur-patch-studio' ) );
		}

		$design = null;
		$id     = (int) ( Payload::int( $b['designId'] ?? 0 ) ?? 0 );
		if ( $id > 0 ) {
			if ( ! Designs::verify_token( $id, Payload::str( $b['token'] ?? '', 64 ) ) ) {
				return self::error( 'bps_invalid', __( 'We vonden je ontwerp niet terug. Laad de ontwerptool opnieuw en probeer het nog eens.', 'borduur-patch-studio' ) );
			}
			$design = Designs::get( $id );
		}

		$name  = Payload::str( $b['name'] ?? '', 100 );
		$email = sanitize_email( (string) ( $b['email'] ?? '' ) );
		$qty   = Payload::int( $b['qty'] ?? null );
		$fields = array();
		if ( '' === $name ) {
			$fields['name'] = __( 'Vul je naam in.', 'borduur-patch-studio' );
		}
		if ( ! is_email( $email ) ) {
			$fields['email'] = __( 'Vul een geldig e-mailadres in, dan kunnen we je prijs bezorgen.', 'borduur-patch-studio' );
		}
		if ( null === $qty || $qty < 1 || $qty > 1000000 ) {
			$fields['qty'] = __( 'Vul in hoeveel patches je nodig hebt.', 'borduur-patch-studio' );
		}
		if ( true !== ( $b['consent'] ?? false ) && 'true' !== ( $b['consent'] ?? '' ) ) {
			$fields['consent'] = __( 'Bevestig dat we je gegevens mogen gebruiken om je offerte te maken.', 'borduur-patch-studio' );
		}
		$rights = isset( $b['rightsConsent'] ) && is_array( $b['rightsConsent'] ) ? $b['rightsConsent'] : array();
		if ( $design && true !== ( $rights['accepted'] ?? false ) && 'true' !== ( $rights['accepted'] ?? '' ) ) {
			$fields['rightsConsent'] = __( 'Vink aan dat je dit ontwerp mag laten maken. Zo weten we dat alles in orde is.', 'borduur-patch-studio' );
		}
		if ( $fields ) {
			return new WP_Error( 'bps_invalid', reset( $fields ), array( 'status' => 400, 'fields' => $fields ) );
		}

		$technique = Payload::str( $b['technique'] ?? '', 20 );
		if ( ! in_array( $technique, Config::enabled_techniques(), true ) ) {
			$technique = $design ? (string) ( $design['payload']['technique'] ?? '' ) : '';
		}
		$deadline = Payload::str( $b['deadline'] ?? '', 10 );
		if ( '' !== $deadline ) {
			$dt       = \DateTime::createFromFormat( '!Y-m-d', $deadline );
			$deadline = $dt && $dt->format( 'Y-m-d' ) === $deadline ? $deadline : '';
		}
		$locale = Config::public_locale( Payload::str( $b['locale'] ?? ( $design['payload']['locale'] ?? '' ), 10 ) );

		$res = Quotes::create(
			array(
				'name'      => $name,
				'email'     => $email,
				'phone'     => Payload::str( $b['phone'] ?? '', 30 ),
				'company'   => Payload::str( $b['company'] ?? '', 100 ),
				'vat'       => Payload::str( $b['vat'] ?? '', 30 ),
				'qty'       => (int) $qty,
				'technique' => $technique,
				'deadline'  => $deadline,
				'message'   => Payload::text( $b['message'] ?? '', 2000 ),
				'locale'    => $locale,
				'rights'    => array( 'v' => Payload::str( $rights['v'] ?? '', 20 ) ),
			),
			$design
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return new WP_REST_Response(
			array(
				'ok'  => true,
				'ref' => $res['ref'],
			),
			200
		);
	}

	/**
	 * POST /designs/{id}/email — "Bewaar & mail mijn ontwerp".
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function email_design( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		$b  = self::body( $r );
		if ( ! Designs::verify_token( $id, Payload::str( $b['token'] ?? '', 64 ) ) ) {
			return self::error( 'bps_forbidden', __( 'We vonden je ontwerp niet terug. Laad de ontwerptool opnieuw en probeer het nog eens.', 'borduur-patch-studio' ), 403 );
		}
		$email = sanitize_email( (string) ( $b['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return self::error( 'bps_invalid', __( 'Vul een geldig e-mailadres in.', 'borduur-patch-studio' ) );
		}
		$design = Designs::get( $id );
		if ( ! $design ) {
			return self::error( 'bps_not_found', __( 'Dit ontwerp bestaat niet meer.', 'borduur-patch-studio' ), 404 );
		}
		$locale = Config::public_locale( Payload::str( $b['locale'] ?? ( $design['payload']['locale'] ?? '' ), 10 ) );

		// Anti-abuse: one design cannot be used to mail many different addresses (per-IP limit applies too).
		$sent_to = get_post_meta( $id, '_bps_email_sent_to', true );
		$sent_to = is_array( $sent_to ) ? $sent_to : array();
		$hash    = substr( hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'nonce' ) ), 0, 16 );
		if ( ! in_array( $hash, $sent_to, true ) ) {
			if ( count( $sent_to ) >= (int) apply_filters( 'bps_max_emails_per_design', 3 ) ) {
				return self::error( 'bps_rate', __( 'Dit ontwerp werd al naar meerdere adressen gemaild. Bewaar het opnieuw of neem contact met ons op.', 'borduur-patch-studio' ), 429 );
			}
			$sent_to[] = $hash;
			update_post_meta( $id, '_bps_email_sent_to', $sent_to );
		}

		update_post_meta( $id, '_bps_email', $email );
		Designs::set_status( $id, 'saved' );
		Designs::touch( $id );

		$mail = Emails::get( 'BPS_Email_Saved_Design' );
		$sent = $mail ? $mail->trigger( $id, $email, $locale ) : false;
		if ( ! $sent ) {
			return self::error( 'bps_mail', __( 'Je ontwerp is bewaard, maar de e-mail kon niet verstuurd worden. Probeer het later nog eens of contacteer ons.', 'borduur-patch-studio' ), 500 );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
