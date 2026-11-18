<?php
/**
 * Name tapes REST routes (SPEC-EXTRA §1.9), namespace patch-studio/v1.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Nametape;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST /nametape/quote · POST /nametape/cart (multipart) · GET /nametape/cart/{key}.
 */
final class Rest {

	const NS           = 'patch-studio/v1';
	const MAX_JSON     = 262144;
	const MAX_PREVIEW  = 307200;
	const PREVIEW_DIR  = 'patch-studio/nametape';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Routes.
	 */
	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/nametape/quote',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'quote' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);
		register_rest_route(
			self::NS,
			'/nametape/cart',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cart' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			)
		);
		register_rest_route(
			self::NS,
			'/nametape/cart/(?P<key>[a-f0-9]{32})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_line' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'args'                => array(
					'key' => array(
						'type'    => 'string',
						'pattern' => '^[a-f0-9]{32}$',
					),
				),
			)
		);
	}

	/**
	 * Public read permission (rate limit via Guard when available).
	 *
	 * @param WP_REST_Request $r Request.
	 * @return true|WP_Error
	 */
	public static function can_read( WP_REST_Request $r ) {
		if ( class_exists( '\BorduurPatchStudio\Rest\Guard' ) ) {
			return \BorduurPatchStudio\Rest\Guard::public_read( $r );
		}
		return true;
	}

	/**
	 * Public write permission: X-BPS-Token + rate limit (Guard), fallback nonce check.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return true|WP_Error
	 */
	public static function can_write( WP_REST_Request $r ) {
		if ( class_exists( '\BorduurPatchStudio\Rest\Guard' ) ) {
			return \BorduurPatchStudio\Rest\Guard::public_write( $r );
		}
		$token = (string) $r->get_header( 'x-bps-token' );
		if ( '' !== $token && false !== wp_verify_nonce( $token, 'bps_public' ) ) {
			return true;
		}
		return new WP_Error( 'bps_bad_token', __( 'Je sessie is verlopen. We proberen het meteen opnieuw.', 'borduur-patch-studio' ), array( 'status' => 403 ) );
	}

	/**
	 * Uniform error.
	 *
	 * @param string $code   Code.
	 * @param string $msg    Message.
	 * @param int    $status Status.
	 * @param array  $data   Extra data.
	 */
	private static function error( string $code, string $msg, int $status = 400, array $data = array() ): WP_Error {
		return new WP_Error( $code, $msg, array_merge( array( 'status' => $status ), $data ) );
	}

	/**
	 * Batch from the request: JSON body {batch} or multipart field "batch" (JSON string).
	 *
	 * @param WP_REST_Request $r Request.
	 * @return array|WP_Error
	 */
	private static function batch_param( WP_REST_Request $r ) {
		$b = $r->get_param( 'batch' );
		if ( is_string( $b ) ) {
			if ( strlen( $b ) > self::MAX_JSON ) {
				return self::error( 'bps_too_large', __( 'Je lijst is te groot om in één keer te versturen. Vraag gerust een offerte.', 'borduur-patch-studio' ), 413 );
			}
			$raw = $b;
			$b   = json_decode( $raw, true );
			if ( ! is_array( $b ) ) {
				$b = json_decode( wp_unslash( $raw ), true );
			}
		}
		if ( ! is_array( $b ) ) {
			return self::error( 'bps_nt_invalid', __( 'We konden je lijst niet lezen. Laad de pagina opnieuw en probeer het nog eens.', 'borduur-patch-studio' ) );
		}
		return $b;
	}

	/**
	 * Customer-friendly message for validator errors.
	 *
	 * @param array $codes Error codes.
	 */
	private static function invalid_message( array $codes ): string {
		if ( in_array( 'row_errors', $codes, true ) ) {
			return __( 'Niet alle namen passen op de gekozen strook. Kies een van de oplossingen bij de rode namen.', 'borduur-patch-studio' );
		}
		if ( in_array( 'too_many_rows', $codes, true ) || in_array( 'too_many_pieces', $codes, true ) ) {
			return __( 'Zoveel stroken? Vraag een offerte, dan maken we het samen in orde.', 'borduur-patch-studio' );
		}
		if ( in_array( 'no_rows', $codes, true ) ) {
			return __( 'Typ minstens één naam.', 'borduur-patch-studio' );
		}
		return __( 'Een van je keuzes is niet (meer) beschikbaar. Laad de pagina opnieuw en kies opnieuw.', 'borduur-patch-studio' );
	}

	/**
	 * Load the WooCommerce cart inside a REST request (only when a session exists, unless $create).
	 *
	 * @param bool $create Start a session when there is none.
	 */
	private static function load_cart( bool $create ): bool {
		if ( ! function_exists( 'wc_load_cart' ) || ! function_exists( 'WC' ) ) {
			return false;
		}
		if ( ! $create ) {
			$has = false;
			foreach ( array_keys( $_COOKIE ) as $c ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( 0 === strpos( (string) $c, 'wp_woocommerce_session_' ) ) {
					$has = true;
					break;
				}
			}
			if ( ! $has && ! is_user_logged_in() && ! wp_validate_auth_cookie( '', 'logged_in' ) ) {
				return false;
			}
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
	 * POST /nametape/quote.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function quote( WP_REST_Request $r ) {
		$raw = self::batch_param( $r );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$check = Validator::validate( $raw, Config::get(), Config::metrics(), false );
		if ( ! $check['ok'] ) {
			$codes = array_map( static fn( $e ) => $e['code'], $check['errors'] );
			return self::error( 'bps_nt_invalid', self::invalid_message( $codes ), 400, array( 'errors' => $check['errors'] ) );
		}
		$b       = $check['batch'];
		$items   = array();
		$replace = (string) $r->get_param( 'replace' );
		if ( self::load_cart( false ) ) {
			$items = WC()->cart->get_cart();
		}
		$q = Pricing::quote( $b, Cart::context( $items, preg_match( '/^[a-f0-9]{32}$/', $replace ) ? $replace : '', $b['locale'] ), Config::get() );
		$q = (array) apply_filters( 'bps_nametape_quote', $q, $b );
		$rows = array();
		foreach ( $check['rows'] as $row ) {
			$rows[] = array(
				'index'    => $row['index'],
				'fit'      => $row['fit'],
				'bloodFit' => $row['bloodFit'],
				'review'   => (bool) $row['review'],
			);
		}
		return new WP_REST_Response(
			array(
				'quote' => $q,
				'rows'  => $rows,
			),
			200
		);
	}

	/**
	 * POST /nametape/cart (multipart: batch, preview, replace, website).
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cart( WP_REST_Request $r ) {
		if ( '' !== trim( (string) $r->get_param( 'website' ) ) ) {
			return self::error( 'bps_nt_invalid', __( 'Dat lukte niet. Probeer het nog eens.', 'borduur-patch-studio' ) );
		}
		$raw = self::batch_param( $r );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$rights = isset( $raw['rightsConsent'] ) && is_array( $raw['rightsConsent'] ) ? $raw['rightsConsent'] : array();
		if ( true !== ( $rights['accepted'] ?? false ) && 'true' !== ( $rights['accepted'] ?? '' ) ) {
			return self::error( 'bps_rights_required', __( 'Vink aan dat je deze namen en teksten mag laten borduren.', 'borduur-patch-studio' ) );
		}
		$check = Validator::validate( $raw, Config::get(), Config::metrics(), true );
		if ( ! $check['ok'] ) {
			$codes = array_map( static fn( $e ) => $e['code'], $check['errors'] );
			return self::error(
				'bps_nt_invalid',
				self::invalid_message( $codes ),
				400,
				array(
					'errors' => $check['errors'],
					'rows'   => $check['badRows'],
				)
			);
		}

		$files   = $r->get_file_params();
		$preview = array();
		if ( ! empty( $files['preview'] ) && is_array( $files['preview'] ) ) {
			if ( (int) ( $files['preview']['size'] ?? 0 ) > self::MAX_PREVIEW ) {
				return self::error( 'bps_too_large', __( 'Het voorbeeldbeeld is te groot. Probeer het nog eens.', 'borduur-patch-studio' ), 413 );
			}
			$preview = self::store_preview( $files['preview'] );
		}

		if ( ! self::load_cart( true ) ) {
			return self::error( 'bps_unavailable', __( 'De winkelmand is even niet beschikbaar. Probeer het straks opnieuw.', 'borduur-patch-studio' ), 503 );
		}
		$replace = (string) $r->get_param( 'replace' );
		$replace = preg_match( '/^[a-f0-9]{32}$/', $replace ) ? $replace : '';
		$res     = Cart::add_batch( $check, $rights, $preview, $replace );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return new WP_REST_Response(
			array(
				'ok'          => true,
				'cartUrl'     => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
				'cartCount'   => (int) WC()->cart->get_cart_contents_count(),
				'cartItemKey' => $res['key'],
				'quote'       => $res['quote'],
			),
			200
		);
	}

	/**
	 * GET /nametape/cart/{key}: list of a line in the customer's own cart (for "Namen wijzigen").
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_line( WP_REST_Request $r ) {
		$key = (string) $r->get_param( 'key' );
		if ( ! self::load_cart( false ) ) {
			return self::error( 'bps_not_found', __( 'Deze lijst staat niet (meer) in je winkelmand.', 'borduur-patch-studio' ), 404 );
		}
		$items = WC()->cart->get_cart();
		if ( empty( $items[ $key ][ Cart::KEY ] ) ) {
			return self::error( 'bps_not_found', __( 'Deze lijst staat niet (meer) in je winkelmand.', 'borduur-patch-studio' ), 404 );
		}
		return new WP_REST_Response( array( 'batch' => Cart::batch_from_data( $items[ $key ][ Cart::KEY ] ) ), 200 );
	}

	/**
	 * Uploads dir for previews (created with an index.php).
	 *
	 * @return array{dir:string,url:string}|null
	 */
	public static function preview_dir(): ?array {
		$up = wp_upload_dir( null, false );
		if ( ! empty( $up['error'] ) ) {
			return null;
		}
		$dir = trailingslashit( $up['basedir'] ) . self::PREVIEW_DIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return array(
			'dir' => $dir,
			'url' => trailingslashit( $up['baseurl'] ) . self::PREVIEW_DIR,
		);
	}

	/**
	 * Re-encode the client PNG with GD (strips anything that is not pixels) and store it under a random name.
	 *
	 * @param array $file $_FILES entry.
	 * @return array{url:string,file:string}|array{}
	 */
	public static function store_preview( array $file ): array {
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp || ! is_readable( $tmp ) || ! function_exists( 'imagecreatefrompng' ) ) {
			return array();
		}
		if ( function_exists( 'is_uploaded_file' ) && ! is_uploaded_file( $tmp ) && ! apply_filters( 'bps_nt_allow_local_preview', false, $tmp ) ) {
			return array();
		}
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || IMAGETYPE_PNG !== ( $info[2] ?? 0 ) || $info[0] > 2400 || $info[1] > 1600 || $info[0] < 50 ) {
			return array();
		}
		$img = @imagecreatefrompng( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $img ) {
			return array();
		}
		imagesavealpha( $img, true );
		$dir = self::preview_dir();
		if ( ! $dir ) {
			imagedestroy( $img );
			return array();
		}
		$name = bin2hex( random_bytes( 16 ) ) . '.png';
		$ok   = imagepng( $img, $dir['dir'] . '/' . $name, 9 );
		imagedestroy( $img );
		if ( ! $ok ) {
			return array();
		}
		return array(
			'url'  => $dir['url'] . '/' . $name,
			'file' => $name,
		);
	}
}
