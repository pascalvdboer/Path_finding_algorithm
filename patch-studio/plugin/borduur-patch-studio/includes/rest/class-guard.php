<?php
/**
 * Permission callbacks, token, rate limits and client IP for the public REST routes.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Rest;

use BorduurPatchStudio\Woo\Config;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Pages are cached (WP Rocket/Cloudflare), so no nonce lives in the HTML: the UI fetches a token from
 * GET /token right before its first write. For guests the token is mainly anti-bot/anti-CSRF (custom
 * header → cross-site requests need a CORS preflight that WordPress does not grant for X-BPS-Token);
 * real protection comes from the HMAC design token, rate limits and honeypots.
 */
final class Guard {

	const NONCE_ACTION = 'bps_public';
	const HEADER       = 'x-bps-token';

	/** Cloudflare edge ranges (https://www.cloudflare.com/ips/), used for trustCloudflareIp = 'auto'. */
	const CF_RANGES = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
		'190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	);

	/**
	 * Fresh token for the UI.
	 */
	public static function create_token(): array {
		return array(
			'token'   => wp_create_nonce( self::NONCE_ACTION ),
			// WordPress nonces live 12–24 h; announce the safe lower bound.
			'expires' => time() + (int) floor( apply_filters( 'nonce_life', DAY_IN_SECONDS, self::NONCE_ACTION ) / 2 ),
		);
	}

	/**
	 * Token header valid?
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function token_ok( WP_REST_Request $r ): bool {
		$token = (string) $r->get_header( self::HEADER );
		return '' !== $token && false !== wp_verify_nonce( $token, self::NONCE_ACTION );
	}

	/**
	 * Permission for public write routes: token + rate limit (bucket chosen per route).
	 *
	 * @param WP_REST_Request $r Request.
	 * @return true|WP_Error
	 */
	public static function public_write( WP_REST_Request $r ) {
		if ( ! self::token_ok( $r ) ) {
			return new WP_Error(
				'bps_bad_token',
				__( 'Je sessie is verlopen. We proberen het meteen opnieuw.', 'borduur-patch-studio' ),
				array( 'status' => 403 )
			);
		}
		return self::rate_limit( self::bucket_for( $r ) );
	}

	/**
	 * Permission for public read-like routes (price quote): rate limit only.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return true|WP_Error
	 */
	public static function public_read( WP_REST_Request $r ) {
		return self::rate_limit( self::bucket_for( $r ) );
	}

	/**
	 * Map a route to its rate-limit bucket.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	private static function bucket_for( WP_REST_Request $r ): string {
		$route = (string) $r->get_route();
		if ( preg_match( '#/quotes$#', $route ) ) {
			return 'quotes';
		}
		if ( preg_match( '#/email$#', $route ) ) {
			return 'emails';
		}
		if ( preg_match( '#/quote$#', $route ) ) {
			return 'quote';
		}
		if ( preg_match( '#/cart$#', $route ) ) {
			return 'cart';
		}
		return 'designs';
	}

	/**
	 * Fixed-window rate limit per IP hash (no raw IP is stored).
	 *
	 * @param string $bucket designs|quotes|emails|quote|cart.
	 * @return true|WP_Error
	 */
	public static function rate_limit( string $bucket ) {
		$limits = (array) Config::path( 'woo.rateLimits', array() );
		switch ( $bucket ) {
			case 'quotes':
				$max    = (int) ( $limits['quotesPerHour'] ?? 5 );
				$window = HOUR_IN_SECONDS;
				break;
			case 'emails':
				$max    = (int) ( $limits['emailsPerHour'] ?? 5 );
				$window = HOUR_IN_SECONDS;
				break;
			case 'quote':
				$max    = (int) ( $limits['quotePerMinute'] ?? 120 );
				$window = MINUTE_IN_SECONDS;
				break;
			case 'cart':
				$max    = max( 30, (int) ( $limits['designsPerHour'] ?? 30 ) * 2 );
				$window = HOUR_IN_SECONDS;
				break;
			default:
				$max    = (int) ( $limits['designsPerHour'] ?? 30 );
				$window = HOUR_IN_SECONDS;
		}
		$max = (int) apply_filters( 'bps_rate_limit', $max, $bucket );
		if ( $max <= 0 ) {
			return true;
		}
		$slot = (int) floor( time() / $window );
		$key  = 'bps_rl_' . substr( hash_hmac( 'sha256', self::client_ip() . '|' . $bucket . '|' . $slot, wp_salt( 'nonce' ) ), 0, 32 );
		$hits = (int) get_transient( $key );
		if ( $hits >= $max ) {
			$wait = 'quote' === $bucket ? __( 'een minuut', 'borduur-patch-studio' ) : __( 'een uur', 'borduur-patch-studio' );
			return new WP_Error(
				'bps_rate',
				/* translators: %s: "een minuut" or "een uur" */
				sprintf( __( 'Even geduld: je stuurde veel verzoeken na elkaar. Probeer het binnen %s opnieuw, of bel of WhatsApp ons gerust.', 'borduur-patch-studio' ), $wait ),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $hits + 1, $window + 60 );
		return true;
	}

	/**
	 * Client IP. CF-Connecting-IP is only trusted when the request really comes from Cloudflare
	 * (woo.trustCloudflareIp: 'auto' = REMOTE_ADDR in Cloudflare ranges, true = always, false = never).
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$cf     = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : '';
		$mode   = Config::path( 'woo.trustCloudflareIp', 'auto' );
		if ( '' !== $cf && filter_var( $cf, FILTER_VALIDATE_IP ) ) {
			if ( true === $mode || 'yes' === $mode || ( 'auto' === $mode && self::ip_in_ranges( $remote, self::CF_RANGES ) ) ) {
				return $cf;
			}
		}
		return $remote;
	}

	/**
	 * IPv4/IPv6 CIDR match.
	 *
	 * @param string   $ip     IP.
	 * @param string[] $ranges CIDR ranges.
	 */
	public static function ip_in_ranges( string $ip, array $ranges ): bool {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin ) {
			return false;
		}
		foreach ( $ranges as $cidr ) {
			list( $net, $bits ) = explode( '/', $cidr );
			$net_bin            = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $net_bin || strlen( $net_bin ) !== strlen( $bin ) ) {
				continue;
			}
			$bits  = (int) $bits;
			$bytes = intdiv( $bits, 8 );
			$rest  = $bits % 8;
			if ( substr( $bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
				continue;
			}
			if ( 0 === $rest ) {
				return true;
			}
			$mask = chr( ( 0xFF << ( 8 - $rest ) ) & 0xFF );
			if ( ( $bin[ $bytes ] & $mask ) === ( $net_bin[ $bytes ] & $mask ) ) {
				return true;
			}
		}
		return false;
	}
}
