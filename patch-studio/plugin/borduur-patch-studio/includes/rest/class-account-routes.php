<?php
/**
 * REST routes of the design domain beyond create/cart (P06): revise, save to account, reorder, claim, the account
 * list/rename/delete and the reminder unsubscribe link.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Rest;

use BorduurPatchStudio\Logger;
use BorduurPatchStudio\Uploads;
use BorduurPatchStudio\Woo\Account;
use BorduurPatchStudio\Woo\Cart;
use BorduurPatchStudio\Woo\Designs;
use BorduurPatchStudio\Woo\Payload;
use BorduurPatchStudio\Woo\Reminder;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes (namespace patch-studio/v1; Routes::post_dispatch adds no-store headers and the locale switch):
 *   POST   /designs/{id}/revise      (Guard::public_write + design token; multipart design, preview, original?)
 *                                     → {designId, ref, token, revision, previewUrl, cloned}
 *   POST   /designs/{id}/save        (Guard::public_write + design token, logged in) → {ok, designId, ref, title, accountUrl}
 *   POST   /designs/{id}/reorder     (Guard::public_write; owner, order customer or design token)
 *                                     → {ok, cartUrl, cartCount, cartItemKey, quote, designId, ref, token}
 *   POST   /account/claim            (logged in via X-WP-Nonce; {designId, token} or {designs:[…]}) → {ok, claimed[]}
 *   GET    /account/designs          (logged in) → {designs[], total, pages, page}
 *   PATCH  /account/designs/{id}     (logged in, owner) {title} → {ok, title}
 *   DELETE /account/designs/{id}     (logged in, owner) → {ok, result: deleted|hidden}
 *   GET    /reminder/unsubscribe?d=&s=  (HMAC) → 200 page (NL + FR); POST = one-click (RFC 8058)
 *
 * Account routes: no nonce (or a guest) → 401; a design of someone else or an unknown id → 404 (never 403, so ids
 * cannot be probed); account.enabled = false → 404.
 */
final class Account_Routes {

	const NS = 'patch-studio/v1';

	/**
	 * Hooks (registered by Woo\Designs::register()).
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_html' ), 10, 4 );
	}

	/**
	 * Register routes.
	 */
	public static function routes(): void {
		$id_arg = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);
		register_rest_route(
			self::NS,
			'/designs/(?P<id>\d+)/revise',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( Guard::class, 'public_write' ),
				'callback'            => array( __CLASS__, 'revise' ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/designs/(?P<id>\d+)/save',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( Guard::class, 'public_write' ),
				'callback'            => array( __CLASS__, 'save' ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/designs/(?P<id>\d+)/reorder',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( Guard::class, 'public_write' ),
				'callback'            => array( __CLASS__, 'reorder' ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/account/claim',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'account_permission' ),
				'callback'            => array( __CLASS__, 'claim' ),
			)
		);
		register_rest_route(
			self::NS,
			'/account/designs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'account_permission' ),
				'callback'            => array( __CLASS__, 'list_designs' ),
			)
		);
		register_rest_route(
			self::NS,
			'/account/designs/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'permission_callback' => array( __CLASS__, 'account_permission' ),
					'callback'            => array( __CLASS__, 'rename' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => array( __CLASS__, 'account_permission' ),
					'callback'            => array( __CLASS__, 'delete' ),
					'args'                => $id_arg,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/reminder/unsubscribe',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'permission_callback' => '__return_true', // HMAC signature checked in the callback.
				'callback'            => array( __CLASS__, 'unsubscribe' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * JSON or form body.
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
	 * Design token from header X-BPS-Design-Token (preferred) or the body.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	private static function design_token( WP_REST_Request $r ): string {
		$token = trim( (string) $r->get_header( Routes::DESIGN_TOKEN_HEADER ) );
		if ( '' === $token ) {
			$b     = self::body( $r );
			$token = Payload::str( $b['token'] ?? $r->get_param( 'token' ) ?? '', 64 );
		}
		return $token;
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
	 * Same 404 for "unknown" and "not yours".
	 */
	private static function not_found(): WP_Error {
		return self::error( 'bps_not_found', __( 'We vonden dit ontwerp niet terug.', 'borduur-patch-studio' ), 404 );
	}

	/**
	 * Response that is never cached (Routes::post_dispatch adds the same for our namespace; explicit here too).
	 *
	 * @param array $data   Data.
	 * @param int   $status Status.
	 */
	private static function respond( array $data, int $status = 200 ): WP_REST_Response {
		$res = new WP_REST_Response( $data, $status );
		$res->header( 'Cache-Control', 'no-store, private' );
		return $res;
	}

	/**
	 * Permission for the account routes: account.enabled, then a logged-in user (cookie + X-WP-Nonce).
	 *
	 * @param WP_REST_Request $r Request.
	 * @return true|WP_Error
	 */
	public static function account_permission( WP_REST_Request $r ) {
		unset( $r );
		if ( ! Account::enabled() ) {
			return self::error( 'bps_not_found', __( 'Deze functie is niet beschikbaar.', 'borduur-patch-studio' ), 404 );
		}
		if ( ! is_user_logged_in() ) {
			return self::error( 'bps_login_required', __( 'Log in om je ontwerpen te bekijken.', 'borduur-patch-studio' ), 401 );
		}
		return true;
	}

	/**
	 * Does the current user own this design? (Unknown id, other post type or other owner: false.)
	 *
	 * @param int $id Design id.
	 */
	public static function owns_design( int $id ): bool {
		$uid = get_current_user_id();
		return $uid > 0 && null !== Designs::get( $id ) && Designs::owner( $id ) === $uid;
	}

	/* ---------------------------------------------------------------------
	 * POST /designs/{id}/revise (klant-account-3)
	 * ------------------------------------------------------------------- */

	/**
	 * Replace the payload/preview (and optionally the original) of a design after the customer changed it.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function revise( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! Designs::verify_token( $id, self::design_token( $r ) ) ) {
			return self::error( 'bps_forbidden', __( 'Deze link naar je ontwerp werkt niet (meer). Misschien is hij verlopen; je kan gewoon opnieuw beginnen.', 'borduur-patch-studio' ), 403 );
		}
		if ( '' !== trim( (string) $r->get_param( 'website' ) ) ) {
			return self::error( 'bps_invalid_design', __( 'We konden je ontwerp niet bewaren. Laad de pagina opnieuw en probeer het nog eens.', 'borduur-patch-studio' ) );
		}
		$payload = Payload::validate( $r->get_param( 'design' ) );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$files = $r->get_file_params();
		if ( empty( $files['preview'] ) || ! is_array( $files['preview'] ) ) {
			return self::error( 'bps_invalid_design', __( 'Het voorbeeld van je patch ontbreekt. Probeer het nog eens.', 'borduur-patch-studio' ) );
		}
		$preview_err = (int) ( $files['preview']['error'] ?? UPLOAD_ERR_OK );
		if ( UPLOAD_ERR_INI_SIZE === $preview_err || UPLOAD_ERR_FORM_SIZE === $preview_err || (int) ( $files['preview']['size'] ?? 0 ) > Uploads::preview_max_bytes() ) {
			return self::error( 'bps_too_big', __( 'Het voorbeeld van je patch is te groot om te bewaren. Laad de pagina opnieuw en probeer het nog eens.', 'borduur-patch-studio' ), 413 );
		}

		$original = null;
		if ( ! empty( $files['original'] ) && is_array( $files['original'] ) && UPLOAD_ERR_NO_FILE !== (int) ( $files['original']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$bytes = (int) ( $files['original']['size'] ?? 0 );
			$room  = Uploads::check_storage( $bytes );
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

		Guard::restore_logged_in_user();
		try {
			$res = Designs::revise( $id, $payload, $preview, $original );
		} catch ( \Throwable $e ) {
			Logger::error(
				'design_revise_exception',
				array(
					'design' => $id,
					'error'  => $e->getMessage(),
				)
			);
			$res = self::error( 'bps_store', __( 'Opslaan is niet gelukt. Probeer het nog eens.', 'borduur-patch-studio' ), 500 );
		}
		if ( is_wp_error( $res ) ) {
			if ( $original ) {
				Uploads::delete( $original['path'] );
			}
			Uploads::delete_preview( $preview );
			return $res;
		}
		$new = (int) $res['id'];
		self::log_price_mismatch( $new, $payload );
		return self::respond(
			array(
				'designId'   => $new,
				'ref'        => (string) get_post_field( 'post_title', $new ),
				'token'      => Designs::token_for( $new ),
				'revision'   => (int) $res['revision'],
				'previewUrl' => (string) $preview['url'],
				'cloned'     => (bool) $res['cloned'],
			)
		);
	}

	/**
	 * Log when the browser price differs from the server price (no personal data), like POST /designs.
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
		if ( ! empty( $server['ok'] ) && abs( (float) $server['totalIncl'] - $client ) > 0.01 ) {
			Logger::info(
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

	/* ---------------------------------------------------------------------
	 * POST /designs/{id}/save (logged in, no e-mail needed)
	 * ------------------------------------------------------------------- */

	/**
	 * Save a design to the customer's account (status saved + optional title). Guests use /designs/{id}/email.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function save( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! Designs::verify_token( $id, self::design_token( $r ) ) ) {
			return self::error( 'bps_forbidden', __( 'We vonden je ontwerp niet terug. Laad de ontwerptool opnieuw en probeer het nog eens.', 'borduur-patch-studio' ), 403 );
		}
		Guard::restore_logged_in_user();
		if ( ! Account::enabled() || ! is_user_logged_in() ) {
			return self::error( 'bps_login_required', __( 'Log in om je ontwerp in je account te bewaren, of laat het naar je e-mailadres sturen.', 'borduur-patch-studio' ), 401 );
		}
		$uid = get_current_user_id();
		if ( ! Designs::claim( $id, $uid ) ) {
			return self::not_found(); // Belongs to another account.
		}
		$b     = self::body( $r );
		$title = isset( $b['title'] ) ? Designs::set_title( $id, (string) $b['title'] ) : (string) get_post_meta( $id, '_bps_title', true );
		Designs::set_status( $id, 'saved' );
		Designs::touch( $id );
		$d = Designs::get( $id );
		return self::respond(
			array(
				'ok'         => true,
				'designId'   => $id,
				'ref'        => $d ? $d['ref'] : '',
				'status'     => $d ? $d['status'] : 'saved',
				'title'      => $title,
				'accountUrl' => Account::url(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * POST /designs/{id}/reorder (klant-account-5)
	 * ------------------------------------------------------------------- */

	/**
	 * One-click reorder of an ordered design: a fresh copy in the cart at the current server price.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function reorder( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		Guard::restore_logged_in_user();
		$uid       = get_current_user_id();
		$has_token = Designs::verify_token( $id, self::design_token( $r ) );
		$is_owner  = $uid > 0 && ( Designs::owner( $id ) === $uid || Account::is_order_customer( $id, $uid ) );
		if ( ! $has_token && ! $is_owner ) {
			return self::not_found();
		}
		$d = Designs::get( $id );
		if ( ! $d ) {
			return self::not_found();
		}
		if ( 'ordered' !== $d['status'] && empty( $d['order_id'] ) ) {
			return self::error( 'bps_not_ordered', __( 'Dit ontwerp werd nog niet besteld. Leg het gewoon in je winkelmand vanuit de ontwerptool.', 'borduur-patch-studio' ), 400 );
		}
		$b      = self::body( $r );
		$rights = isset( $b['rightsConsent'] ) && is_array( $b['rightsConsent'] ) ? $b['rightsConsent'] : array();
		$agreed = true === ( $rights['accepted'] ?? false ) || 'true' === ( $rights['accepted'] ?? '' );
		if ( ! $agreed && ! $is_owner ) {
			// The account owner confirmed the rights with the first order; a token holder confirms again.
			return self::error( 'bps_rights_required', __( 'Vink aan dat je dit ontwerp mag laten maken. Zo weten we dat alles in orde is.', 'borduur-patch-studio' ) );
		}
		$qty = 0;
		if ( isset( $b['qty'] ) && '' !== $b['qty'] ) {
			$qty = (int) ( Payload::int( $b['qty'] ) ?? 0 );
			if ( $qty < 1 || $qty > 1000000 ) {
				return self::error( 'bps_invalid', __( 'Vul in hoeveel patches je nodig hebt.', 'borduur-patch-studio' ) );
			}
		}
		$res = Account::reorder( $id, $qty, $agreed ? $rights : array( 'accepted' => true ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$new = (int) $res['designId'];
		return self::respond(
			array(
				'ok'          => true,
				'cartUrl'     => wc_get_cart_url(),
				'cartCount'   => (int) WC()->cart->get_cart_contents_count(),
				'cartItemKey' => (string) $res['key'],
				'quote'       => $res['quote'],
				'designId'    => $new,
				'ref'         => (string) $res['ref'],
				'token'       => Designs::token_for( $new ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Account routes
	 * ------------------------------------------------------------------- */

	/**
	 * POST /account/claim: link guest designs (proof of possession: the design token) to the logged-in customer.
	 * Never by e-mail address.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function claim( WP_REST_Request $r ) {
		$b     = self::body( $r );
		$pairs = array();
		if ( isset( $b['designs'] ) && is_array( $b['designs'] ) ) {
			$pairs = array_slice( $b['designs'], 0, 20 );
		} else {
			$pairs[] = $b;
		}
		$uid     = get_current_user_id();
		$claimed = array();
		foreach ( $pairs as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$id    = (int) ( Payload::int( $p['designId'] ?? 0 ) ?? 0 );
			$token = Payload::str( $p['token'] ?? '', 64 );
			if ( Designs::verify_token( $id, $token ) && Designs::claim( $id, $uid ) ) {
				$claimed[] = $id;
			}
		}
		if ( ! $claimed ) {
			return self::not_found();
		}
		return self::respond(
			array(
				'ok'      => true,
				'claimed' => $claimed,
			)
		);
	}

	/**
	 * GET /account/designs?page=.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function list_designs( WP_REST_Request $r ) {
		$page = max( 1, (int) $r->get_param( 'page' ) );
		$list = Account::designs_for( get_current_user_id(), $page );
		return self::respond(
			array(
				'designs' => $list['items'],
				'total'   => $list['total'],
				'pages'   => $list['pages'],
				'page'    => $list['page'],
			)
		);
	}

	/**
	 * PATCH /account/designs/{id} {title}.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function rename( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! self::owns_design( $id ) ) {
			return self::not_found();
		}
		$b = self::body( $r );
		if ( ! isset( $b['title'] ) || ! is_scalar( $b['title'] ) ) {
			return self::error( 'bps_invalid', __( 'Geef je ontwerp een naam.', 'borduur-patch-studio' ) );
		}
		return self::respond(
			array(
				'ok'    => true,
				'title' => Designs::set_title( $id, (string) $b['title'] ),
			)
		);
	}

	/**
	 * DELETE /account/designs/{id}: delete (draft/saved/carted) or hide (quoted/ordered).
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function delete( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! self::owns_design( $id ) ) {
			return self::not_found();
		}
		return self::respond(
			array(
				'ok'     => true,
				'result' => Designs::remove_for_customer( $id ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Reminder unsubscribe (klant-account-1)
	 * ------------------------------------------------------------------- */

	/** Confirmation texts in both languages (the link is opened without any language context). */
	const UNSUB_TEXTS = array(
		'nl' => array(
			'ok_title'  => 'Je bent afgemeld',
			'ok_text'   => 'Je krijgt geen herinneringen meer over je bewaarde patch-ontwerpen. Je ontwerp zelf blijft bewaard: met de link uit je eerste e-mail kan je er gewoon mee verder.',
			'bad_title' => 'Deze link werkt niet',
			'bad_text'  => 'Deze afmeldlink is ongeldig of te oud. Je krijgt hoe dan ook maximaal één herinnering per ontwerp. Wil je zeker zijn? Antwoord gewoon op onze e-mail.',
		),
		'fr' => array(
			'ok_title'  => 'Tu es désinscrit',
			'ok_text'   => 'Tu ne recevras plus de rappels au sujet de tes designs d’écussons enregistrés. Ton design reste enregistré : avec le lien de ton premier e-mail, tu peux continuer quand tu veux.',
			'bad_title' => 'Ce lien ne fonctionne pas',
			'bad_text'  => 'Ce lien de désinscription n’est pas valable ou trop ancien. De toute façon, tu reçois au maximum un rappel par design. Pour être sûr, réponds simplement à notre e-mail.',
		),
	);

	/**
	 * GET (link in the mail) or POST (one-click, List-Unsubscribe-Post) /reminder/unsubscribe?d=&s=.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function unsubscribe( WP_REST_Request $r ) {
		$id  = absint( $r->get_param( 'd' ) );
		$sig = (string) $r->get_param( 's' );
		$ok  = $id > 0 && Reminder::unsubscribe( $id, $sig );
		$key = $ok ? 'ok' : 'bad';
		$res = new WP_REST_Response(
			array(
				'ok'      => $ok,
				'message' => array(
					'nl' => self::UNSUB_TEXTS['nl'][ $key . '_text' ],
					'fr' => self::UNSUB_TEXTS['fr'][ $key . '_text' ],
				),
			),
			$ok ? 200 : 400
		);
		$res->header( 'Cache-Control', 'no-store, private' );
		$res->header( 'X-Robots-Tag', 'noindex' );
		return $res;
	}

	/**
	 * Serve the unsubscribe answer as a small bilingual HTML page to browsers (the JSON stays for API clients).
	 *
	 * @param bool             $served  Already served.
	 * @param mixed            $result  Response.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Server.
	 */
	public static function serve_html( $served, $result, $request, $server ) {
		unset( $server );
		if ( $served || ! $request instanceof WP_REST_Request || 'GET' !== $request->get_method() || '/' . self::NS . '/reminder/unsubscribe' !== $request->get_route() ) {
			return $served;
		}
		$accept = (string) $request->get_header( 'accept' );
		if ( false === stripos( $accept, 'text/html' ) ) {
			return $served;
		}
		$data = $result instanceof \WP_HTTP_Response ? $result->get_data() : array();
		$key  = ! empty( $data['ok'] ) ? 'ok' : 'bad';
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">';
		echo '<title>' . esc_html( self::UNSUB_TEXTS['nl'][ $key . '_title' ] . ' · ' . $site ) . '</title>';
		echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#F7FBFE;color:#0A1F44;margin:0;padding:16px}main{max-width:560px;margin:40px auto;background:#fff;border:1px solid #E3EBF2;border-radius:10px;padding:24px}h1{font-size:1.3em;margin:0 0 8px}p{line-height:1.55;margin:0 0 18px}hr{border:0;border-top:1px solid #E3EBF2;margin:20px 0}a{color:#0B74A6}</style></head><body><main>';
		foreach ( array( 'nl', 'fr' ) as $i => $lang ) {
			if ( $i > 0 ) {
				echo '<hr>';
			}
			echo '<section lang="' . esc_attr( $lang ) . '"><h1>' . esc_html( self::UNSUB_TEXTS[ $lang ][ $key . '_title' ] ) . '</h1><p>' . esc_html( self::UNSUB_TEXTS[ $lang ][ $key . '_text' ] ) . '</p></section>';
		}
		echo '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( $site ) . '</a></p></main></body></html>';
		return true;
	}
}
