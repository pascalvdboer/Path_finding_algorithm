<?php
/**
 * REST routes /ai/* of the AI studio (ai-backend part).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §4. Namespace `patch-studio/v1`.
 *
 * Protection per route:
 * - logged in: WordPress cookie + `X-WP-Nonce` (wp_rest); guest: `X-BPS-Token` from the existing `GET /token`
 *   (checked with `Rest\Guard::token_ok()`) plus the `bps_ai_sid` cookie (set on the first AI action);
 * - same-origin check on the Origin header (403 bps_bad_origin);
 * - own rate buckets per IP hash (`ai_write` 30/h, `ai_poll` 120/min);
 * - mode off → 403 bps_ai_off; mode test → only shop managers.
 * Images are served by signature only (`?exp=&sig=`): an <img> cannot send headers.
 * Every answer carries `Cache-Control: no-store` (images: private, 1 h).
 */
final class Rest {

	const NS = 'patch-studio/v1';

	const ID_RX = '(?P<id>[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26})';

	/**
	 * Register routes (rest_api_init).
	 */
	public static function register_routes(): void {
		$routes = array(
			array( '/ai/status', 'GET', 'status', 'ai_poll' ),
			array( '/ai/generate', 'POST', 'generate', 'ai_write' ),
			array( '/ai/jobs/' . self::ID_RX, 'GET', 'poll', 'ai_poll' ),
			array( '/ai/jobs/' . self::ID_RX . '/cancel', 'POST', 'cancel', 'ai_poll' ),
			array( '/ai/jobs/' . self::ID_RX . '/refine', 'POST', 'refine', 'ai_write' ),
			array( '/ai/jobs/' . self::ID_RX . '/choose', 'POST', 'choose', 'ai_poll' ),
			array( '/ai/history', 'GET', 'history', 'ai_poll' ),
		);
		foreach ( $routes as $r ) {
			list( $path, $method, $cb, $bucket ) = $r;
			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => $method,
					'callback'            => array( __CLASS__, $cb ),
					'permission_callback' => static function ( WP_REST_Request $req ) use ( $bucket ) {
						return Rest::permission( $req, $bucket );
					},
				)
			);
		}
		register_rest_route(
			self::NS,
			'/ai/image/' . self::ID_RX . '/(?P<index>\d{1,2})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'image' ),
				'permission_callback' => '__return_true', // The HMAC signature in the URL is the authorisation.
				'args'                => array(
					'exp' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'sig' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'v'   => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Shared permission check.
	 *
	 * @param WP_REST_Request $r      Request.
	 * @param string          $bucket Rate bucket.
	 * @return true|WP_Error
	 */
	public static function permission( WP_REST_Request $r, string $bucket ) {
		Messages::set_locale( self::lang( $r ) );
		if ( ! self::origin_ok( $r ) ) {
			return Messages::error( 'bps_bad_origin', 403 );
		}
		$logged_in = function_exists( 'is_user_logged_in' ) && is_user_logged_in();
		if ( ! $logged_in && ! self::token_ok( $r ) ) {
			return Messages::error( 'bps_bad_token', 401 );
		}
		$mode = AI_Settings::mode();
		if ( 'off' === $mode || ( 'test' === $mode && ! Module::is_manager() ) ) {
			return Messages::error( 'bps_ai_off', 403 );
		}
		return Limits::rate( $bucket, Identity::ip_hash() );
	}

	/**
	 * Guest token (existing guard) — the filter lets tests and headless setups plug in.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	private static function token_ok( WP_REST_Request $r ): bool {
		$ok = class_exists( '\BorduurPatchStudio\Rest\Guard' ) && \BorduurPatchStudio\Rest\Guard::token_ok( $r );
		return (bool) apply_filters( 'bps_ai_token_ok', $ok, $r );
	}

	/**
	 * Origin header absent (same-origin GET) or equal to the site host.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function origin_ok( WP_REST_Request $r ): bool {
		$origin = (string) $r->get_header( 'origin' );
		if ( '' === $origin || 'null' === $origin ) {
			return '' === $origin;
		}
		$host  = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		$hosts = array_filter(
			array(
				strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
				strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
			)
		);
		$hosts = (array) apply_filters( 'bps_ai_allowed_origins', $hosts );
		return '' !== $host && in_array( $host, $hosts, true );
	}

	/* ---------------------------------------------------------------------
	 * Callbacks
	 * ------------------------------------------------------------------- */

	/**
	 * GET /ai/status.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function status( WP_REST_Request $r ) {
		// A read never sets the bps_ai_sid cookie nor creates an owner row (gdpr-juridisch-11): the cookie is only set
		// on the first AI action (generate, e-mail verification, adding a pack).
		$owner = Identity::current( false );
		if ( '' !== $owner ) {
			Identity::touch( $owner );
		}
		$status = Jobs::status( $owner, self::ctx() );
		if ( '' === $owner ) {
			$status = self::with_first_use_rounds( $status );
		}
		return self::reply( 200, $status );
	}

	/**
	 * A visitor without cookie sees the free anonymous round(s) he would get on his first action (the round itself is
	 * granted in generate(), once per owner and within the per-IP limit of commerce).
	 *
	 * @param array $status Status from Jobs::status('').
	 */
	private static function with_first_use_rounds( array $status ): array {
		if ( 'commerce' !== Credits_Gateway::instance()->kind() || ! empty( $status['freePaused'] ) ) {
			return $status;
		}
		$free = max( 0, (int) AI_Settings::path( 'free.anonymous', 1 ) );
		if ( $free > 0 && isset( $status['balance'] ) && is_array( $status['balance'] ) && 0 === (int) ( $status['balance']['total'] ?? 0 ) ) {
			$status['balance']['free']  = $free;
			$status['balance']['total'] = $free;
		}
		$status['firstUse'] = true;
		return $status;
	}

	/**
	 * POST /ai/generate.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function generate( WP_REST_Request $r ) {
		$owner = Identity::current( true );
		Identity::touch( $owner );
		$ctx = self::ctx();
		if ( 'anon' === Identity::kind( $owner ) ) {
			// First AI action of this browser: grant the free anonymous round now (status() no longer creates owners).
			Credits_Gateway::instance()->grant_free_anon( $owner, (string) ( $ctx['ip_hash'] ?? '' ) );
		}
		return self::wrap( Jobs::generate( $owner, self::body( $r ), $ctx ) );
	}

	/**
	 * GET /ai/jobs/{id}.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function poll( WP_REST_Request $r ) {
		return self::wrap( Jobs::poll( Identity::current( false ), (string) $r['id'] ) );
	}

	/**
	 * POST /ai/jobs/{id}/cancel.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function cancel( WP_REST_Request $r ) {
		return self::wrap( Jobs::cancel( Identity::current( false ), (string) $r['id'], self::ctx() ) );
	}

	/**
	 * POST /ai/jobs/{id}/refine.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function refine( WP_REST_Request $r ) {
		return self::wrap( Jobs::refine( Identity::current( false ), (string) $r['id'], self::body( $r ), self::ctx() ) );
	}

	/**
	 * POST /ai/jobs/{id}/choose.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function choose( WP_REST_Request $r ) {
		return self::wrap( Jobs::choose( Identity::current( false ), (string) $r['id'], self::body( $r ) ) );
	}

	/**
	 * GET /ai/history.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function history( WP_REST_Request $r ) {
		unset( $r );
		return self::reply( 200, Jobs::history( Identity::current( false ) ) );
	}

	/**
	 * GET /ai/image/{id}/{index}?exp=&sig=[&v=svg] — streams the file and exits.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function image( WP_REST_Request $r ) {
		$id      = strtoupper( (string) $r['id'] );
		$index   = (int) $r['index'];
		$variant = 'svg' === (string) $r->get_param( 'v' ) ? 'svg' : '';
		if ( ! Image_Store::verify( $id, $index, (int) $r->get_param( 'exp' ), (string) $r->get_param( 'sig' ), $variant ) ) {
			return Messages::error( 'bps_ai_not_found', 404 );
		}
		$file = self::image_file( $id, $index, $variant );
		if ( null === $file ) {
			return Messages::error( 'bps_ai_not_found', 404 );
		}
		Image_Store::stream( $file['abs'], $file['mime'], 'ai-ontwerp-' . substr( $id, 0, 6 ) . '-' . ( $index + 1 ) . ( 'image/svg+xml' === $file['mime'] ? '.svg' : '.png' ) );
		return null; // Not reached (stream exits).
	}

	/**
	 * Resolve a stored image of a job.
	 *
	 * @param string $id      Job id.
	 * @param int    $index   Index.
	 * @param string $variant ''|'svg'.
	 * @return array{abs:string, mime:string}|null
	 */
	public static function image_file( string $id, int $index, string $variant ): ?array {
		$job = Module::store()->job_get( $id );
		if ( ! $job || ! in_array( $job['status'], array( 'done' ), true ) ) {
			return null;
		}
		$row = Jobs::proposal_row( $job, $index );
		if ( null === $row ) {
			return null;
		}
		$rel  = 'svg' === $variant ? (string) ( $row['vector'] ?? '' ) : (string) $row['file'];
		$abs  = '' === $rel ? '' : Image_Store::path( $rel );
		$mime = 'svg' === $variant || '.svg' === substr( $rel, -4 ) ? 'image/svg+xml' : 'image/png';
		return '' === $abs ? null : array(
			'abs'  => $abs,
			'mime' => $mime,
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * JSON body (or form params) as array.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	private static function body( WP_REST_Request $r ): array {
		$json = $r->get_json_params();
		return is_array( $json ) ? $json : (array) $r->get_body_params();
	}

	/**
	 * Context for Jobs.
	 */
	private static function ctx(): array {
		return array(
			'ip_hash' => Identity::ip_hash(),
			'manager' => Module::is_manager(),
		);
	}

	/**
	 * nl|fr from the body/query, else the site locale.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	private static function lang( WP_REST_Request $r ): string {
		$l = $r->get_param( 'lang' );
		if ( ! is_string( $l ) || '' === $l ) {
			$l = function_exists( 'determine_locale' ) ? determine_locale() : 'nl';
		}
		return 0 === strpos( strtolower( $l ), 'fr' ) ? 'fr' : 'nl';
	}

	/**
	 * Jobs result → response.
	 *
	 * @param array|WP_Error $res Result.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function wrap( $res ) {
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::reply( (int) $res['status'], (array) $res['body'] );
	}

	/**
	 * Response with no-store caching.
	 *
	 * @param int   $status Status.
	 * @param array $data   Data.
	 */
	private static function reply( int $status, array $data ): WP_REST_Response {
		$res = new WP_REST_Response( $data, $status );
		$res->header( 'Cache-Control', 'no-store, private' );
		$res->header( 'X-Robots-Tag', 'noindex' );
		return $res;
	}

	/**
	 * Errors from our routes also get no-store (rest_post_dispatch).
	 *
	 * @param mixed           $result  Response.
	 * @param mixed           $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function no_store( $result, $server, $request ) {
		unset( $server );
		if ( $result instanceof WP_REST_Response && $request instanceof WP_REST_Request && 0 === strpos( (string) $request->get_route(), '/' . self::NS . '/ai/' ) && false === strpos( (string) $request->get_route(), '/ai/image/' ) ) {
			$result->header( 'Cache-Control', 'no-store, private' );
		}
		return $result;
	}
}
