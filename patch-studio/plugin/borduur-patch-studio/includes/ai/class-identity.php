<?php
/**
 * Owner identity (account / verified e-mail / anonymous cookie) as an HMAC key, never a raw id.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §3.1. Owner key = 2-char prefix + 64 hex:
 *   'u:' account, 'e:' verified e-mail, 'a:' anonymous (cookie bps_ai_sid), 'x:' anonymised (privacy eraser).
 *
 * The cookie is strictly necessary for a service the visitor asks for, so no consent banner is needed; it is listed
 * in the privacy text (04 §6.7). It is only set on the first AI action (POST /ai/generate, e-mail verification,
 * adding a pack). Reads such as GET /ai/status and dismissing a service offer never call current( true ).
 * Owner rows expire after retentionDays.ownerRows / anonymousOwnerRows (Cleanup, gdpr-juridisch-7).
 */
final class Identity {

	const COOKIE     = 'bps_ai_sid';
	const COOKIE_TTL = 30 * DAY_IN_SECONDS;

	/** @var string|null Session id override (tests) or the id created during this request. */
	private static $sid = null;

	/** @var string|null Client IP override (tests). */
	public static $ip_override = null;

	/**
	 * Owner key of the current visitor, following merges. '' for a guest without cookie when $create is false.
	 *
	 * @param bool $create Create the anonymous cookie when missing.
	 */
	public static function current( bool $create = false ): string {
		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $uid > 0 ) {
			return self::for_user( $uid );
		}
		$sid = self::sid( $create );
		if ( '' === $sid ) {
			return '';
		}
		return self::resolve( self::for_sid( $sid ) );
	}

	/**
	 * @param int $user_id User id.
	 */
	public static function for_user( int $user_id ): string {
		return 'u:' . hash_hmac( 'sha256', 'u|' . $user_id, wp_salt( 'auth' ) );
	}

	/**
	 * @param string $email E-mail.
	 */
	public static function for_email( string $email ): string {
		return 'e:' . hash_hmac( 'sha256', 'e|' . strtolower( trim( $email ) ), wp_salt( 'auth' ) );
	}

	/**
	 * @param string $sid 32-hex session id.
	 */
	public static function for_sid( string $sid ): string {
		return 'a:' . hash_hmac( 'sha256', 'a|' . $sid, wp_salt( 'auth' ) );
	}

	/**
	 * Kind of a key: user|email|anon|erased|unknown.
	 *
	 * @param string $key Owner key.
	 */
	public static function kind( string $key ): string {
		switch ( substr( $key, 0, 2 ) ) {
			case 'u:':
				return 'user';
			case 'e:':
				return 'email';
			case 'a:':
				return 'anon';
			case 'x:':
				return 'erased';
		}
		return 'unknown';
	}

	/**
	 * Account or verified e-mail (designs are kept longer, SPEC §5 "Kiezen").
	 *
	 * @param string $key Owner key.
	 */
	public static function is_strong( string $key ): bool {
		return in_array( self::kind( $key ), array( 'user', 'email' ), true );
	}

	/**
	 * Valid owner-key format.
	 *
	 * @param string $key Key.
	 */
	public static function valid( string $key ): bool {
		return 1 === preg_match( '/^[ueax]:[0-9a-f]{64}$/', $key );
	}

	/**
	 * Follow merged_into (max 4 hops; anon → e-mail → account).
	 *
	 * @param string $key Owner key.
	 */
	public static function resolve( string $key ): string {
		$store = Module::store();
		for ( $i = 0; $i < 4; $i++ ) {
			$row = $store->owner_get( $key );
			if ( ! $row || empty( $row['merged_into'] ) || ! self::valid( (string) $row['merged_into'] ) ) {
				break;
			}
			$key = (string) $row['merged_into'];
		}
		return $key;
	}

	/**
	 * Anonymous session id from the cookie; creates and sets one when asked.
	 *
	 * @param bool $create Create when missing.
	 */
	public static function sid( bool $create = false ): string {
		if ( null !== self::$sid ) {
			return self::$sid;
		}
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( 1 === preg_match( '/^[0-9a-f]{32}$/', $raw ) ) {
			self::$sid = $raw;
			return $raw;
		}
		if ( ! $create ) {
			return '';
		}
		self::$sid = bin2hex( random_bytes( 16 ) );
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				self::$sid,
				array(
					'expires'  => time() + self::COOKIE_TTL,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => function_exists( 'is_ssl' ) ? is_ssl() : true,
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		return self::$sid;
	}

	/**
	 * Force a session id (tests) or reset (null).
	 *
	 * @param string|null $sid Sid.
	 */
	public static function set_sid( ?string $sid ): void {
		self::$sid = $sid;
	}

	/**
	 * HMAC of the client IP (never the raw IP).
	 */
	public static function ip_hash(): string {
		$ip = null !== self::$ip_override ? self::$ip_override : self::client_ip();
		return hash_hmac( 'sha256', self::ip_bucket( $ip ), wp_salt( 'nonce' ) );
	}

	/**
	 * Rate/abuse bucket of an IP: IPv4 as is, IPv6 reduced to its /64 prefix.
	 *
	 * One home or phone line gets a whole IPv6 /64 and can rotate through billions of addresses; hashing the full
	 * address would let one visitor collect a free round (and fresh rate buckets) per address.
	 *
	 * @param string $ip Client IP.
	 */
	public static function ip_bucket( string $ip ): string {
		$ip = trim( $ip );
		if ( false === strpos( $ip, ':' ) ) {
			return $ip;
		}
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin || 16 !== strlen( $bin ) ) {
			return $ip;
		}
		if ( 0 === strncmp( $bin, str_repeat( "\0", 10 ) . "\xff\xff", 12 ) ) {
			return (string) inet_ntop( substr( $bin, 12 ) ); // IPv4-mapped IPv6.
		}
		return (string) inet_ntop( substr( $bin, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	/**
	 * Client IP via the existing guard (Cloudflare-aware) when available.
	 */
	public static function client_ip(): string {
		if ( class_exists( '\BorduurPatchStudio\Rest\Guard' ) ) {
			return (string) \BorduurPatchStudio\Rest\Guard::client_ip();
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Create/update the owners row (last_seen_at; ip_hash only for anonymous owners).
	 *
	 * @param string $key Owner key.
	 */
	public static function touch( string $key ): void {
		if ( ! self::valid( $key ) ) {
			return;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array(
			'owner_key'    => $key,
			'kind'         => self::kind( $key ),
			'last_seen_at' => $now,
		);
		if ( 'anon' === $row['kind'] ) {
			$row['ip_hash'] = self::ip_hash();
		}
		if ( 'user' === $row['kind'] && function_exists( 'get_current_user_id' ) ) {
			$uid = (int) get_current_user_id();
			if ( $uid > 0 && self::for_user( $uid ) === $key ) {
				$row['user_id'] = $uid;
				$user           = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
				if ( $user && ! empty( $user->user_email ) ) {
					$row['email'] = (string) $user->user_email;
				}
			}
		}
		Module::store()->owner_upsert( $row );
	}

	/**
	 * Merge a weaker key into a stronger one (anon → e-mail → account): rounds (commerce), jobs, owner link.
	 * Safe to call more than once.
	 *
	 * @param string $from Weaker key.
	 * @param string $to   Stronger key.
	 */
	public static function merge( string $from, string $to ): bool {
		if ( ! self::valid( $from ) || ! self::valid( $to ) || $from === $to ) {
			return false;
		}
		$rank = array(
			'anon'  => 1,
			'email' => 2,
			'user'  => 3,
		);
		if ( ( $rank[ self::kind( $from ) ] ?? 0 ) > ( $rank[ self::kind( $to ) ] ?? 0 ) ) {
			return false;
		}
		$store = Module::store();
		$row   = $store->owner_get( $from );
		if ( $row && ! empty( $row['merged_into'] ) ) {
			return false; // Already merged.
		}
		Credits_Gateway::instance()->merge( $from, $to );
		$store->job_reassign( $from, $to );
		$store->owner_upsert(
			array(
				'owner_key'   => $from,
				'kind'        => self::kind( $from ),
				'merged_into' => $to,
			)
		);
		$store->owner_upsert(
			array(
				'owner_key' => $to,
				'kind'      => self::kind( $to ),
			)
		);
		do_action( 'bps_ai_owner_merged', $from, $to );
		return true;
	}

	/**
	 * Hook wp_login: anonymous work of this browser moves to the account.
	 *
	 * @param string $user_login Login.
	 * @param mixed  $user       WP_User.
	 */
	public static function on_login( $user_login, $user ): void {
		unset( $user_login );
		if ( ! is_object( $user ) || empty( $user->ID ) ) {
			return;
		}
		$sid = self::sid( false );
		if ( '' === $sid ) {
			return;
		}
		$from = self::resolve( self::for_sid( $sid ) );
		$to   = self::for_user( (int) $user->ID );
		if ( $from !== $to && 'user' !== self::kind( $from ) ) {
			self::merge( $from, $to );
		}
	}
}
