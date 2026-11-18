<?php
/**
 * REST API patch-studio/v1 (SPEC §6.2).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Rest;

use BorduurPatchStudio\I18n;
use BorduurPatchStudio\Logger;
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
 *                                      (token also accepted in header X-BPS-Design-Token, preferred)
 *   GET  /designs/{id}/original      → file (signed, 1 h; used by originalUrl)
 *   POST /cart                       → {ok, cartUrl, cartCount, cartItemKey, quote[, cloned, designId, token, ref]}
 *   POST /quotes                     → {ok, ref}
 *   POST /designs/{id}/email         → {ok}
 */
final class Routes {

	const NS = 'patch-studio/v1';

	/** Header that may carry the design token instead of ?token= (keeps it out of URLs and logs). */
	const DESIGN_TOKEN_HEADER = 'x-bps-design-token';

	/** Default max size of the browser-made preview PNG (1200 px; a photo-like canvas stays far below this). */
	const PREVIEW_MAX_BYTES = 8388608;

	/** @var bool Locale switched for this request. */
	private static $switched = false;

	/** @var bool The switch went through I18n (contract C9) and must be undone with I18n::restore(). */
	private static $switched_i18n = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		Guard::register();
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
						'required' => false, // Or header X-BPS-Design-Token.
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
		if ( '' === $locale ) {
			return $result;
		}
		$lang = Config::lang( $locale );
		if ( $lang === Config::lang() ) {
			return $result;
		}
		if ( is_callable( array( I18n::class, 'switch_lang' ) ) ) {
			// Contract C9: picks the best installed fr_* locale and always loads our own catalogue.
			self::$switched      = (bool) I18n::switch_lang( $lang );
			self::$switched_i18n = self::$switched;
		} elseif ( 'fr' === $lang ) {
			// Fallback until I18n (P11) is present.
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
			if ( self::$switched_i18n ) {
				I18n::restore();
			} else {
				restore_previous_locale();
			}
			self::$switched      = false;
			self::$switched_i18n = false;
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

	/**
	 * Log without personal data (contract C1). Falls back to error_log with WP_DEBUG_LOG until Logger exists.
	 *
	 * @param string $level   error|warning|info|debug.
	 * @param string $event   Event name.
	 * @param array  $context Context (no e-mail addresses, names or IPs).
	 */
	private static function log( string $level, string $event, array $context = array() ): void {
		if ( is_callable( array( Logger::class, $level ) ) ) {
			call_user_func( array( Logger::class, $level ), $event, $context );
			return;
		}
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'borduur-patch-studio [' . $level . '] ' . $event . ' ' . wp_json_encode( $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/**
	 * 413 with the customer-facing upload limit.
	 */
	private static function too_big(): WP_Error {
		return self::error(
			'bps_too_big',
			/* translators: %s: max size in MB */
			sprintf( __( 'Je bestand is groter dan %s MB. Stuur het gerust via WeTransfer of per e-mail, dan bekijken we het samen.', 'borduur-patch-studio' ), Config::num( Uploads::max_bytes() / MB_IN_BYTES, 0 ) ),
			413
		);
	}

	/**
	 * Request body larger than post_max_size? PHP then silently empties $_POST and $_FILES, which would
	 * otherwise surface as a misleading "design missing" error.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	private static function body_too_large( WP_REST_Request $r ): bool {
		$limit = (int) wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		if ( $limit <= 0 ) {
			return false; // 0 = unlimited.
		}
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : (int) $r->get_header( 'content_length' );
		return $length > $limit;
	}

	/**
	 * Room to store an original of $bytes? Uses Uploads::check_storage() (contract C3: quota + free disk).
	 * Until that exists, a temporary guard checks only the free disk space (filter bps_min_free_disk_bytes).
	 *
	 * @param int $bytes Size of the new original.
	 * @return true|WP_Error
	 */
	private static function check_storage( int $bytes ) {
		if ( is_callable( array( Uploads::class, 'check_storage' ) ) ) {
			$ok = call_user_func( array( Uploads::class, 'check_storage' ), $bytes );
		} else {
			$ok   = true;
			$min  = (int) apply_filters( 'bps_min_free_disk_bytes', GB_IN_BYTES );
			$dir  = (string) ( wp_upload_dir( null, false )['basedir'] ?? '' );
			$free = ( '' !== $dir && is_dir( $dir ) && function_exists( 'disk_free_space' ) ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $free && (float) $free - $bytes < $min ) {
				$ok = new WP_Error( 'bps_storage', 'low disk space', array( 'status' => 507 ) );
			}
		}
		if ( true === $ok ) {
			return true;
		}
		self::log(
			'error',
			'design_storage_refused',
			array(
				'bytes'  => $bytes,
				'reason' => is_wp_error( $ok ) ? $ok->get_error_code() : 'unknown',
			)
		);
		return self::error(
			'bps_storage',
			__( 'We kunnen je bestand nu even niet bewaren. Stuur het gerust per e-mail of via WeTransfer, dan bekijken we het samen.', 'borduur-patch-studio' ),
			507
		);
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
			self::log(
				'error',
				'rest_quote_failed',
				array(
					'technique' => $tech,
					'error'     => $e->getMessage(),
				)
			);
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
		// Body over post_max_size: PHP dropped every field, so answer "too big" before anything else.
		if ( self::body_too_large( $r ) ) {
			return self::too_big();
		}
		if ( '' !== trim( (string) $r->get_param( 'website' ) ) ) {
			return self::error( 'bps_invalid_design', __( 'We konden je ontwerp niet bewaren. Laad de pagina opnieuw en probeer het nog eens.', 'borduur-patch-studio' ) );
		}
		$raw     = $r->get_param( 'design' ); // JSON string (multipart) or object (JSON body).
		$payload = Payload::validate( $raw );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$files = $r->get_file_params();
		if ( empty( $files['preview'] ) || ! is_array( $files['preview'] ) ) {
			return self::error( 'bps_invalid_design', __( 'Het voorbeeld van je patch ontbreekt. Probeer het nog eens.', 'borduur-patch-studio' ) );
		}
		$preview_max  = (int) apply_filters( 'bps_preview_max_bytes', self::PREVIEW_MAX_BYTES );
		$preview_err  = (int) ( $files['preview']['error'] ?? UPLOAD_ERR_OK );
		$preview_size = (int) ( $files['preview']['size'] ?? 0 );
		if ( UPLOAD_ERR_INI_SIZE === $preview_err || UPLOAD_ERR_FORM_SIZE === $preview_err || $preview_size > $preview_max ) {
			return self::error( 'bps_too_big', __( 'Het voorbeeld van je patch is te groot om te bewaren. Laad de pagina opnieuw en probeer het nog eens.', 'borduur-patch-studio' ), 413 );
		}

		$original = null;
		if ( ! empty( $files['original'] ) && is_array( $files['original'] ) && UPLOAD_ERR_NO_FILE !== (int) ( $files['original']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			// Size first: friendlier than a generic error after a long upload.
			$bytes = (int) ( $files['original']['size'] ?? 0 );
			if ( $bytes > Uploads::max_bytes() ) {
				return self::too_big();
			}
			$room = self::check_storage( $bytes );
			if ( is_wp_error( $room ) ) {
				return $room;
			}
			$original = Uploads::store_original( $files['original'] );
			if ( is_wp_error( $original ) ) {
				return $original;
			}
		}

		$preview = Uploads::store_preview( $files['preview'] );
		if ( is_wp_error( $preview ) ) {
			if ( $original ) {
				Uploads::delete( $original['path'] );
			}
			return $preview;
		}

		// klant-account-2: the design belongs to the logged-in customer (token + Origin were checked already).
		Guard::restore_logged_in_user();
		$id = Designs::create( $payload, $original, $preview, self::page_url( $r ) );
		if ( is_wp_error( $id ) ) {
			self::log( 'error', 'design_create_failed', array( 'code' => $id->get_error_code() ) );
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
		if ( $client <= 0 ) {
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
		if ( $diff > 0.01 ) {
			// The server price is used; this only flags a JS/PHP engine drift.
			self::log(
				'info',
				'price_mismatch',
				array(
					'design'    => $id,
					'technique' => $payload['technique'],
					'sizeCm'    => $payload['sizeCm'],
					'qty'       => $payload['qty'],
					'client'    => round( $client, 2 ),
					'server'    => round( (float) $server['totalIncl'], 2 ),
				)
			);
		}
	}

	/**
	 * GET /designs/{id} with header X-BPS-Design-Token (preferred) or ?token=.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function get_design( WP_REST_Request $r ) {
		$id    = (int) $r['id'];
		$token = trim( (string) $r->get_header( self::DESIGN_TOKEN_HEADER ) );
		if ( '' === $token ) {
			$token = (string) $r->get_param( 'token' );
		}
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

		// Before the clone and the cart session: both belong to the logged-in customer (klant-account-2).
		Guard::restore_logged_in_user();

		// security-4: an ordered design is never put in a second order as is (that would overwrite the order
		// link and proof state of the first order). Re-ordering works on a fresh copy with its own ref and token.
		$cloned = false;
		if ( 'ordered' === ( $design['status'] ?? '' ) || ! empty( $design['order_id'] ) ) {
			if ( is_callable( array( Designs::class, 'clone' ) ) ) {
				$new = call_user_func( array( Designs::class, 'clone' ), $id, array( 'parent_id' => $id ) );
				if ( is_wp_error( $new ) ) {
					self::log(
						'error',
						'cart_clone_failed',
						array(
							'design' => $id,
							'code'   => $new->get_error_code(),
						)
					);
					return self::error( 'bps_store', __( 'We konden je ontwerp niet opnieuw klaarzetten. Probeer het nog eens of contacteer ons.', 'borduur-patch-studio' ), 500 );
				}
				$id     = (int) $new;
				$design = Designs::get( $id );
				if ( ! $design || empty( $design['payload'] ) ) {
					self::log( 'error', 'cart_clone_unreadable', array( 'design' => $id ) );
					return self::error( 'bps_store', __( 'We konden je ontwerp niet opnieuw klaarzetten. Probeer het nog eens of contacteer ons.', 'borduur-patch-studio' ), 500 );
				}
				$cloned = true;
			} else {
				// Temporary until Designs::clone() (contract C3, P06) exists: keep the old behaviour, but flag it.
				self::log( 'warning', 'cart_ordered_design_without_clone', array( 'design' => $id ) );
			}
		}

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

		$out = array(
			'ok'          => true,
			'cartUrl'     => wc_get_cart_url(),
			'cartCount'   => (int) WC()->cart->get_cart_contents_count(),
			'cartItemKey' => $res['key'],
			'quote'       => $res['quote'],
		);
		if ( $cloned ) {
			// The UI must continue with the copy (see docs/handoff/premium-ui-needs.md, security-4).
			$out['cloned']   = true;
			$out['designId'] = $id;
			$out['token']    = Designs::token_for( $id );
			$out['ref']      = (string) $design['ref'];
		}
		return new WP_REST_Response( $out, 200 );
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

		$name   = Payload::str( $b['name'] ?? '', 100 );
		$email  = sanitize_email( (string) ( $b['email'] ?? '' ) );
		$qty    = Payload::int( $b['qty'] ?? null );
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
		// No consent checkbox: a quote request is processed on art. 6.1.b GDPR (steps at the customer's request
		// before a contract). A 'consent' field that still comes along is ignored.
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
			self::log( 'error', 'design_email_failed', array( 'design' => $id ) );
			return self::error( 'bps_mail', __( 'Je ontwerp is bewaard, maar de e-mail kon niet verstuurd worden. Probeer het later nog eens of contacteer ons.', 'borduur-patch-studio' ), 500 );
		}

		/**
		 * The design was mailed to the customer (contract C4; e.g. the reminder scheduler listens here).
		 *
		 * @param int             $id    Design id.
		 * @param string          $email Recipient.
		 * @param WP_REST_Request $r     Request.
		 */
		do_action( 'bps_design_emailed', $id, $email, $r );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
