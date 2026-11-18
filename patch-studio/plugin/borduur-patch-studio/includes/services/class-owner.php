<?php
/**
 * Owner keys for rounds (SPEC-AI-DIENSTEN §3.1). Never a raw id or e-mail address.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Derives owner keys exactly as AI\Identity does, so commerce works before (and without) ai-backend.
 * When AI\Identity exists, `current()` asks it first.
 */
final class Owner {

	const COOKIE = 'bps_ai_sid';

	/** @var string|null Test override for the salt. */
	public static $salt = null;

	/** @var string|null Test override for the current owner. */
	public static $current = null;

	/** @var bool Tests: ignore ai-backend classes even when they are loadable. */
	public static $standalone = false;

	/**
	 * Key for a WordPress user.
	 *
	 * @param int $user_id User id.
	 */
	public static function for_user( int $user_id ): string {
		return 'u:' . hash_hmac( 'sha256', 'u|' . $user_id, self::salt() );
	}

	/**
	 * Key for an e-mail address (normalised: trim + lowercase).
	 *
	 * @param string $email E-mail.
	 */
	public static function for_email( string $email ): string {
		return 'e:' . hash_hmac( 'sha256', 'e|' . self::normalize_email( $email ), self::salt() );
	}

	/**
	 * Key for an anonymous browser id (cookie bps_ai_sid, 32 hex).
	 *
	 * @param string $sid Session id.
	 */
	public static function for_anon( string $sid ): string {
		return 'a:' . hash_hmac( 'sha256', 'a|' . $sid, self::salt() );
	}

	/**
	 * Normalised e-mail.
	 *
	 * @param string $email E-mail.
	 */
	public static function normalize_email( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Valid owner key?
	 *
	 * @param string $key Key.
	 */
	public static function valid( string $key ): bool {
		return 1 === preg_match( '/^[uea]:[0-9a-f]{64}$/', $key );
	}

	/**
	 * Kind letter: u (account) > e (e-mail) > a (anonymous).
	 *
	 * @param string $key Key.
	 */
	public static function kind( string $key ): string {
		return self::valid( $key ) ? $key[0] : '';
	}

	/**
	 * Strength for merging (higher wins).
	 *
	 * @param string $key Key.
	 */
	public static function strength( string $key ): int {
		$map = array(
			'a' => 1,
			'e' => 2,
			'u' => 3,
		);
		return $map[ self::kind( $key ) ] ?? 0;
	}

	/**
	 * Owner of the current request ('' when unknown: no account, no AI cookie yet).
	 */
	public static function current(): string {
		if ( null !== self::$current ) {
			return self::$current;
		}
		if ( self::ai_identity() ) {
			$k = (string) \BorduurPatchStudio\AI\Identity::current( false );
			if ( self::valid( $k ) ) {
				return $k;
			}
		}
		if ( function_exists( 'get_current_user_id' ) && get_current_user_id() > 0 ) {
			return self::for_user( (int) get_current_user_id() );
		}
		$sid = self::cookie_sid();
		return '' !== $sid ? self::resolve( self::for_anon( $sid ) ) : '';
	}

	/**
	 * Remember that a weaker key was merged into a stronger one (e.g. this browser confirmed an e-mail address).
	 * Stored as a 30-day transient (cookie lifetime) and, when the table exists, in bps_ai_owners.merged_into.
	 *
	 * @param string $from Weaker key.
	 * @param string $to   Stronger key.
	 */
	public static function link( string $from, string $to ): void {
		if ( ! self::valid( $from ) || ! self::valid( $to ) || $from === $to ) {
			return;
		}
		set_transient( 'bps_ai_link_' . md5( $from ), $to, 30 * DAY_IN_SECONDS );
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'update' ) && self::owners_table() ) {
			$wpdb->update( self::owners_table(), array( 'merged_into' => $to ), array( 'owner_key' => $from ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Follow merge links (max 3 hops) to the key that now holds the rounds.
	 *
	 * @param string $key Key.
	 */
	public static function resolve( string $key ): string {
		for ( $i = 0; $i < 3; $i++ ) {
			$next = get_transient( 'bps_ai_link_' . md5( $key ) );
			if ( ! is_string( $next ) || ! self::valid( $next ) || self::strength( $next ) < self::strength( $key ) ) {
				break;
			}
			$key = $next;
		}
		return $key;
	}

	/**
	 * Name of the owners table when it exists (owned by ai-backend), else ''.
	 */
	private static function owners_table(): string {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return '';
		}
		$table = $wpdb->prefix . 'bps_ai_owners';
		$cache = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ? $table : ''; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $cache;
	}

	/**
	 * The anonymous browser key, if the AI cookie exists (also for logged-in users: used for merging).
	 */
	public static function current_anon(): string {
		$sid = self::cookie_sid();
		return '' !== $sid ? self::for_anon( $sid ) : '';
	}

	/**
	 * Owner of a WooCommerce order: account, else billing e-mail.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function for_order( $order ): string {
		$uid = (int) $order->get_customer_id();
		if ( $uid > 0 ) {
			return self::for_user( $uid );
		}
		$email = (string) $order->get_billing_email();
		return is_email( $email ) ? self::for_email( $email ) : '';
	}

	/**
	 * Cookie value when well-formed.
	 */
	private static function cookie_sid(): string {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return preg_match( '/^[0-9a-f]{32}$/', $raw ) ? $raw : '';
	}

	/**
	 * Record an owner row (bps_ai_owners, owned by ai-backend) when that table exists. Never sent anywhere.
	 *
	 * @param string   $key      Owner key.
	 * @param int|null $user_id  User id.
	 * @param string   $email    E-mail ('' = none).
	 * @param bool     $verified E-mail verified now.
	 */
	public static function remember( string $key, ?int $user_id = null, string $email = '', bool $verified = false ): void {
		if ( ! self::valid( $key ) ) {
			return;
		}
		$kinds = array(
			'u' => 'user',
			'e' => 'email',
			'a' => 'anon',
		);
		if ( self::ai_store() ) {
			$row = array(
				'owner_key'    => $key,
				'kind'         => $kinds[ $key[0] ],
				'last_seen_at' => gmdate( 'Y-m-d H:i:s' ),
			);
			if ( $user_id ) {
				$row['user_id'] = $user_id;
			}
			if ( '' !== $email ) {
				$row['email'] = substr( self::normalize_email( $email ), 0, 190 );
			}
			if ( $verified ) {
				$row['email_verified_at'] = gmdate( 'Y-m-d H:i:s' );
			}
			\BorduurPatchStudio\AI\Module::store()->owner_upsert( $row );
			return;
		}
		global $wpdb;
		$table = self::owners_table();
		if ( '' === $table ) {
			return;
		}
		$now    = gmdate( 'Y-m-d H:i:s' );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT owner_key FROM {$table} WHERE owner_key = %s", $key ) ); // phpcs:ignore WordPress.DB
		$email  = '' !== $email ? substr( self::normalize_email( $email ), 0, 190 ) : '';
		if ( $exists ) {
			$set = array( 'last_seen_at' => $now );
			if ( '' !== $email ) {
				$set['email'] = $email;
			}
			if ( $verified ) {
				$set['email_verified_at'] = $now;
			}
			$wpdb->update( $table, $set, array( 'owner_key' => $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'owner_key'         => $key,
				'kind'              => $kinds[ $key[0] ],
				'user_id'           => $user_id ? $user_id : null,
				'email'             => '' !== $email ? $email : null,
				'email_verified_at' => $verified ? $now : null,
				'created_at'        => $now,
				'last_seen_at'      => $now,
			)
		);
	}

	/**
	 * Move rounds (and, with ai-backend, jobs) from a weaker key to a stronger one and remember the link.
	 *
	 * @param string  $from    Weaker key.
	 * @param string  $to      Stronger key.
	 * @param Credits $credits Credits.
	 */
	public static function merge_into( string $from, string $to, Credits $credits ): void {
		if ( ! self::valid( $from ) || ! self::valid( $to ) || $from === $to || self::strength( $from ) > self::strength( $to ) ) {
			return;
		}
		if ( self::ai_identity() && method_exists( '\BorduurPatchStudio\AI\Identity', 'merge' ) && self::ai_store() ) {
			// AI\Identity::merge() calls Credits::merge() through its gateway, moves jobs and sets merged_into.
			if ( \BorduurPatchStudio\AI\Identity::merge( $from, $to ) ) {
				return;
			}
		}
		$credits->merge( $from, $to );
		self::link( $from, $to );
	}

	/**
	 * HMAC of the client IP (same as AI\Identity::ip_hash()).
	 */
	public static function ip_hash(): string {
		if ( self::ai_identity() && method_exists( '\BorduurPatchStudio\AI\Identity', 'ip_hash' ) ) {
			return (string) \BorduurPatchStudio\AI\Identity::ip_hash();
		}
		if ( class_exists( '\BorduurPatchStudio\Rest\Guard' ) ) {
			$ip = (string) \BorduurPatchStudio\Rest\Guard::client_ip();
		} else {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		}
		return hash_hmac( 'sha256', $ip, function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : 'bps' );
	}

	/**
	 * Is ai-backend's Identity available?
	 */
	private static function ai_identity(): bool {
		return ! self::$standalone && class_exists( '\BorduurPatchStudio\AI\Identity' );
	}

	/**
	 * Is ai-backend's owner store available?
	 */
	private static function ai_store(): bool {
		return ! self::$standalone && class_exists( '\BorduurPatchStudio\AI\Module' ) && method_exists( '\BorduurPatchStudio\AI\Module', 'store' );
	}

	/**
	 * Secret for the HMAC.
	 */
	private static function salt(): string {
		if ( null !== self::$salt ) {
			return self::$salt;
		}
		return function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'bps-no-salt';
	}
}
