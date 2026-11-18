<?php
/**
 * API keys: wp-config constant (wins) or encrypted option bps_ai_secrets. Never exposed anywhere.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Key storage (SPEC-AI-DIENSTEN A7, §12).
 *
 * - Constant `BPS_OPENAI_API_KEY` / `BPS_RECRAFT_API_KEY` in wp-config.php wins and locks the admin field.
 * - Otherwise `sodium_crypto_secretbox` with a key derived from `wp_salt('auth')`, stored in the non-autoloaded option
 *   `bps_ai_secrets` as base64(nonce . ciphertext) with prefix 's1:'.
 * - Without sodium (very rare on PHP ≥ 7.2): stored as WordPress itself stores secrets (plain, prefix 'p:') and the
 *   admin page shows a warning.
 * Keys never go to public config, REST, JS, HTML, logs, exports or error messages: use redact() on every message
 * that could contain one.
 */
final class Secrets {

	const OPTION = 'bps_ai_secrets';

	/** Provider id => wp-config constant. */
	const CONSTANTS = array(
		'openai'  => 'BPS_OPENAI_API_KEY',
		'recraft' => 'BPS_RECRAFT_API_KEY',
	);

	/** @var array<string,string> Decrypted cache per request. */
	private static $cache = array();

	/**
	 * The key for a provider, or '' if none.
	 *
	 * @param string $id Provider id.
	 */
	public static function get( string $id ): string {
		if ( isset( self::$cache[ $id ] ) ) {
			return self::$cache[ $id ];
		}
		$const = self::CONSTANTS[ $id ] ?? 'BPS_' . strtoupper( preg_replace( '/[^a-z0-9]/i', '', $id ) ) . '_API_KEY';
		if ( defined( $const ) && is_string( constant( $const ) ) && '' !== trim( (string) constant( $const ) ) ) {
			self::$cache[ $id ] = trim( (string) constant( $const ) );
			return self::$cache[ $id ];
		}
		$all = self::stored();
		$raw = isset( $all[ $id ] ) && is_string( $all[ $id ] ) ? $all[ $id ] : '';
		self::$cache[ $id ] = '' === $raw ? '' : self::decrypt( $raw );
		return self::$cache[ $id ];
	}

	/**
	 * A key exists.
	 *
	 * @param string $id Provider id.
	 */
	public static function has( string $id ): bool {
		return '' !== self::get( $id );
	}

	/**
	 * Where the key comes from: constant|option|none.
	 *
	 * @param string $id Provider id.
	 */
	public static function source( string $id ): string {
		$const = self::CONSTANTS[ $id ] ?? '';
		if ( '' !== $const && defined( $const ) && '' !== trim( (string) constant( $const ) ) ) {
			return 'constant';
		}
		return self::has( $id ) ? 'option' : 'none';
	}

	/**
	 * Last 4 characters for "ingesteld · eindigt op …abcd" ('' if none).
	 *
	 * @param string $id Provider id.
	 */
	public static function last4( string $id ): string {
		$k = self::get( $id );
		return strlen( $k ) >= 8 ? substr( $k, -4 ) : ( '' === $k ? '' : '••••' );
	}

	/**
	 * Store a key (admin). Refused when a constant is defined.
	 *
	 * @param string $id  Provider id.
	 * @param string $key Plain key.
	 */
	public static function set( string $id, string $key ): bool {
		$key = trim( preg_replace( '/[\x00-\x1F\x7F\s]+/', '', $key ) );
		if ( 'constant' === self::source( $id ) || '' === $key || strlen( $key ) > 512 ) {
			return false;
		}
		$all        = self::stored();
		$all[ $id ] = self::encrypt( $key );
		update_option( self::OPTION, $all, false );
		self::$cache = array();
		return true;
	}

	/**
	 * Remove a stored key (admin "Wissen").
	 *
	 * @param string $id Provider id.
	 */
	public static function clear( string $id ): void {
		$all = self::stored();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		self::$cache = array();
	}

	/**
	 * Encryption available.
	 */
	public static function can_encrypt(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_generichash' );
	}

	/**
	 * Remove every known key and anything that looks like one from a message.
	 *
	 * @param string $text Text.
	 */
	public static function redact( string $text ): string {
		foreach ( array_keys( self::CONSTANTS ) as $id ) {
			$k = self::get( $id );
			if ( strlen( $k ) >= 6 ) {
				$text = str_replace( $k, '[redacted]', $text );
			}
		}
		$text = (string) preg_replace( '/\bsk-[A-Za-z0-9_\-]{8,}/', '[redacted]', $text );
		$text = (string) preg_replace( '/(Bearer|Key|Token)\s+[A-Za-z0-9_\-\.=]{8,}/i', '$1 [redacted]', $text );
		$text = (string) preg_replace( '/[\w.+-]+@[\w-]+(\.[\w-]+)+/', '[email]', $text );
		return mb_substr( $text, 0, 500 );
	}

	/**
	 * Reset the request cache (tests).
	 */
	public static function flush(): void {
		self::$cache = array();
	}

	/**
	 * Stored option.
	 */
	private static function stored(): array {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * 32-byte key derived from the auth salt.
	 */
	private static function box_key(): string {
		return sodium_crypto_generichash( wp_salt( 'auth' ) . 'bps-ai-secrets', '', 32 );
	}

	/**
	 * @param string $plain Plain key.
	 */
	private static function encrypt( string $plain ): string {
		if ( ! self::can_encrypt() ) {
			return 'p:' . $plain;
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::box_key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @param string $stored Stored value.
	 */
	private static function decrypt( string $stored ): string {
		if ( 0 === strpos( $stored, 'p:' ) ) {
			return substr( $stored, 2 );
		}
		if ( 0 !== strpos( $stored, 's1:' ) || ! self::can_encrypt() ) {
			return '';
		}
		$bin = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $bin || strlen( $bin ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::box_key() );
		// A changed salt makes old keys unreadable: treat as "not set" so the admin re-enters it.
		return false === $plain ? '' : $plain;
	}
}
