<?php
/**
 * Permission callbacks, token, rate limits and client IP for the public REST routes.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Rest;

use BorduurPatchStudio\Help_Line;
use BorduurPatchStudio\Woo\Config;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Pages are cached (WP Rocket/Cloudflare), so no nonce lives in the HTML: the UI fetches a token from
 * GET /token right before its first write. For guests the token is mainly anti-bot/anti-CSRF (custom
 * header → cross-site requests need a CORS preflight that WordPress does not grant for X-BPS-Token);
 * real protection comes from the HMAC design token, rate limits and honeypots. Since 18-11-2026 the
 * write routes also refuse a foreign Origin/Referer (bps_bad_origin) as defence in depth.
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
	 * Hooks (status check for the settings page, contract C7).
	 */
	public static function register(): void {
		add_filter( 'bps_status_checks', array( __CLASS__, 'status_checks' ) );
	}

	/**
	 * Permission for public write routes: token + same-site origin + rate limit (bucket chosen per route).
	 * Every public write route of every module goes through here (contract C4), so the Origin check covers all.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return true|WP_Error
	 */
	public static function public_write( WP_REST_Request $r ) {
		return self::once( $r, array( __CLASS__, 'check_write' ) );
	}

	/**
	 * The actual write checks (see public_write()).
	 *
	 * @param WP_REST_Request $r Request.
	 * @return true|WP_Error
	 */
	public static function check_write( WP_REST_Request $r ) {
		if ( ! self::token_ok( $r ) ) {
			return new WP_Error(
				'bps_bad_token',
				__( 'Je sessie is verlopen. We proberen het meteen opnieuw.', 'borduur-patch-studio' ),
				array( 'status' => 403 )
			);
		}
		if ( ! self::origin_ok( $r ) ) {
			return new WP_Error(
				'bps_bad_origin',
				__( 'Dit verzoek kwam niet van onze website en werd daarom geweigerd. Herlaad de pagina en probeer het opnieuw.', 'borduur-patch-studio' ),
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
		return self::once(
			$r,
			static function ( WP_REST_Request $req ) {
				return self::rate_limit( self::bucket_for( $req ) );
			}
		);
	}

	/**
	 * Run a permission check once per request object. WordPress calls permission callbacks again after the
	 * dispatch (rest_send_allow_header() builds the Allow header with them); without this every request would
	 * count twice against the rate limit (a limit of 5 quotes per hour really allowed 3).
	 *
	 * @param WP_REST_Request $r     Request.
	 * @param callable        $check Check returning true|WP_Error.
	 * @return true|WP_Error
	 */
	private static function once( WP_REST_Request $r, callable $check ) {
		static $memo = null;
		if ( null === $memo ) {
			$memo = new \SplObjectStorage();
		}
		if ( ! $memo->contains( $r ) ) {
			$memo[ $r ] = $check( $r );
		}
		return $memo[ $r ];
	}

	/**
	 * Defence in depth against cross-site writes: a browser always sends Origin on a cross-origin POST, so when
	 * it is present its host must be ours (home_url/site_url or filter bps_allowed_origins). Without Origin, a
	 * Referer (when sent) must match too. Requests with neither (server-to-server, privacy proxies) pass: they
	 * cannot carry the visitor's cookies cross-site.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function origin_ok( WP_REST_Request $r ): bool {
		$origin = trim( (string) $r->get_header( 'origin' ) );
		if ( '' !== $origin ) {
			return self::url_allowed( $origin );
		}
		$referer = trim( (string) $r->get_header( 'referer' ) );
		return '' === $referer || self::url_allowed( $referer );
	}

	/**
	 * Is the host (and explicit port) of a URL one of ours?
	 *
	 * @param string $url Origin or Referer value.
	 */
	public static function url_allowed( string $url ): bool {
		$want = self::url_key( $url );
		if ( null === $want ) {
			return false; // "null" origin (sandboxed frame, file://) or garbage.
		}
		foreach ( self::allowed_origins() as $allowed ) {
			$have = self::url_key( (string) $allowed );
			if ( null === $have || $have['host'] !== $want['host'] ) {
				continue;
			}
			// Port only matters when both sides name one explicitly (http/https on the same host both pass).
			if ( null === $have['port'] || null === $want['port'] || $have['port'] === $want['port'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Allowed origins: home_url() and site_url(), extendable (e.g. a separate shop domain).
	 *
	 * @return string[]
	 */
	public static function allowed_origins(): array {
		$list = array( home_url( '/' ), site_url( '/' ) );
		/**
		 * Filter the origins allowed to call the public write routes (full URLs or bare hosts).
		 *
		 * @param string[] $list Origins.
		 */
		$list = apply_filters( 'bps_allowed_origins', $list );
		return array_values( array_filter( array_map( 'strval', is_array( $list ) ? $list : array() ) ) );
	}

	/**
	 * Lowercase host + explicit port of a URL or bare host; null when unparsable.
	 *
	 * @param string $url URL.
	 * @return array{host:string, port:int|null}|null
	 */
	private static function url_key( string $url ): ?array {
		$url = trim( $url );
		if ( '' === $url || 'null' === strtolower( $url ) ) {
			return null;
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'http://' . $url;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		return array(
			'host' => strtolower( rtrim( (string) $parts['host'], '.' ) ),
			'port' => isset( $parts['port'] ) ? (int) $parts['port'] : null,
		);
	}

	/**
	 * Logged-in customers: REST runs as user 0 without X-WP-Nonce. Restore the user from the logged_in cookie so
	 * the WooCommerce cart session and new designs belong to the customer. Only call this from a route whose
	 * permission callback is public_write(): token + Origin check have run by then, so it is not a CSRF vector.
	 */
	public static function restore_logged_in_user(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		$uid = (int) wp_validate_auth_cookie( '', 'logged_in' );
		if ( $uid > 0 ) {
			wp_set_current_user( $uid );
		}
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
	 * Fixed-window rate limit per IP hash (no raw IP is stored). IPv6 is counted per /64 (one customer line),
	 * IPv4 per address. With a persistent object cache the counter is atomic (wp_cache_add + wp_cache_incr);
	 * otherwise a transient is used (a few extra requests can pass in a race, which is acceptable here).
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
		$key  = 'bps_rl_' . substr( hash_hmac( 'sha256', self::rate_ip( self::client_ip() ) . '|' . $bucket . '|' . $slot, wp_salt( 'nonce' ) ), 0, 32 );
		$hits = self::hit( $key, $window + 60 );
		if ( $hits > $max ) {
			$wait = 'quote' === $bucket ? __( 'een minuut', 'borduur-patch-studio' ) : __( 'een uur', 'borduur-patch-studio' );
			return new WP_Error(
				'bps_rate',
				/* translators: %s: "een minuut" or "een uur" */
				sprintf( __( 'Even geduld: je stuurde veel verzoeken na elkaar. Probeer het binnen %s opnieuw.', 'borduur-patch-studio' ), $wait ) . ' ' . Help_Line::text( determine_locale() ),
				array( 'status' => 429 )
			);
		}
		return true;
	}

	/**
	 * Count one hit and return the new total for this window.
	 *
	 * @param string $key Counter key.
	 * @param int    $ttl Lifetime in seconds.
	 */
	private static function hit( string $key, int $ttl ): int {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'bps_rate', $ttl );
			$n = wp_cache_incr( $key, 1, 'bps_rate' );
			if ( false !== $n ) {
				return (int) $n;
			}
		}
		$hits = (int) get_transient( $key ) + 1;
		set_transient( $key, $hits, $ttl );
		return $hits;
	}

	/**
	 * Rate-limit identity of an IP: IPv4 as is, IPv6 reduced to its network prefix (default /64, filter
	 * bps_rate_limit_ipv6_prefix, 16–128). IPv4-mapped IPv6 (::ffff:a.b.c.d) counts as the IPv4 address.
	 *
	 * @param string $ip IP address.
	 */
	public static function rate_ip( string $ip ): string {
		$bin = '' !== $ip ? @inet_pton( $ip ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin ) {
			return $ip;
		}
		if ( 4 === strlen( $bin ) ) {
			return (string) inet_ntop( $bin );
		}
		if ( 0 === strncmp( $bin, str_repeat( "\0", 10 ) . "\xff\xff", 12 ) ) {
			return (string) inet_ntop( substr( $bin, 12 ) );
		}
		$bits  = max( 16, min( 128, (int) apply_filters( 'bps_rate_limit_ipv6_prefix', 64 ) ) );
		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;
		$net   = substr( $bin, 0, $bytes );
		if ( $rest > 0 ) {
			$net .= chr( ord( $bin[ $bytes ] ) & ( ( 0xFF << ( 8 - $rest ) ) & 0xFF ) );
		}
		$net = str_pad( $net, 16, "\0" );
		return (string) inet_ntop( $net ) . '/' . $bits;
	}

	/**
	 * Settings status block (filter bps_status_checks, contract C7): warn when CF-Connecting-IP is trusted
	 * unconditionally while this request does not come from Cloudflare (then anyone can spoof their IP).
	 *
	 * @param mixed $checks Existing checks.
	 * @return array
	 */
	public static function status_checks( $checks ): array {
		$checks = is_array( $checks ) ? $checks : array();
		$check  = self::cloudflare_check();
		if ( null !== $check ) {
			$checks[] = $check;
		}
		return $checks;
	}

	/**
	 * The trustCloudflareIp warning, or null when not applicable.
	 *
	 * @return array|null
	 */
	public static function cloudflare_check(): ?array {
		$mode = Config::path( 'woo.trustCloudflareIp', 'auto' );
		if ( true !== $mode && 'yes' !== $mode ) {
			return null;
		}
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( self::ip_in_ranges( $remote, self::CF_RANGES ) ) {
			return null;
		}
		$msg = __( 'Het IP-adres van Cloudflare wordt altijd vertrouwd, maar deze site lijkt niet achter Cloudflare te staan. Bezoekers kunnen dan hun IP-adres vervalsen en de limieten omzeilen. Zet "Cloudflare-IP vertrouwen" op "automatisch".', 'borduur-patch-studio' );
		return array(
			'id'      => 'bps_trust_cloudflare_ip',
			'label'   => __( 'Cloudflare-IP', 'borduur-patch-studio' ),
			'status'  => 'warn',
			'ok'      => false,
			'message' => $msg,
			'detail'  => $msg,
		);
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
