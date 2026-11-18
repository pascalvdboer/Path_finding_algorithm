<?php
/**
 * Central logger (contract C1): one place for every log line of the plugin.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the WooCommerce logger (WC > Status > Logs, source `borduur-patch-studio`).
 *
 *   Logger::error( 'price_failed', array( 'design_ref' => 'P-1234', 'exception' => $e ) );
 *
 * - Level threshold from the setting `admin.logLevel` (default `error`), filter `bps_log_level`.
 * - Context is made safe before it is written: secrets (API keys, bearer tokens, licence keys, nonces, passwords)
 *   are replaced by `[redacted]`, e-mail addresses and IP addresses are replaced by a short keyed hash, so a line
 *   can still be correlated ("same customer") without storing personal data. Strings are capped.
 * - Without WooCommerce it falls back to PHP's error_log, but only when WP_DEBUG_LOG is on (never on a live site
 *   that did not ask for a debug log).
 * - Never throws: a broken logger must not break checkout.
 */
final class Logger {

	/** WC log source (file name prefix in wp-content/uploads/wc-logs). */
	const SOURCE = 'borduur-patch-studio';

	/** PSR-3 / WC levels with their weight. */
	const LEVELS = array(
		'debug'     => 100,
		'info'      => 200,
		'notice'    => 250,
		'warning'   => 300,
		'error'     => 400,
		'critical'  => 500,
		'alert'     => 550,
		'emergency' => 600,
	);

	/** Default threshold. */
	const DEFAULT_LEVEL = 'error';

	/** Context keys whose value is always replaced. */
	const SECRET_KEYS = '/(pass(word)?|secret|token|api[_-]?key|licen[cs]e[_-]?key|^key$|authorization|nonce|cookie|signature)/i';

	/** Context keys that hold an e-mail address or an IP address (hashed). */
	const HASH_KEYS = '/(e-?mail|^ip$|ip_?addr|remote_addr|^ip_)/i';

	/** Max length of one string value in the context. */
	const MAX_STRING = 500;

	/** @var callable|null Test writer: fn( string $level, string $line, array $context ). */
	private static $writer = null;

	/** @var bool Re-entrancy guard (Settings may log while we read the threshold). */
	private static $busy = false;

	/** @var string|null Threshold cache for this request. */
	private static $threshold = null;

	/**
	 * @param string $event   Machine readable event name (snake_case), e.g. `uploads_selftest_failed`.
	 * @param array  $context Extra data (made safe before writing).
	 */
	public static function emergency( string $event, array $context = array() ): bool {
		return self::log( 'emergency', $event, $context );
	}

	/**
	 * @param string $event   Event.
	 * @param array  $context Context.
	 */
	public static function critical( string $event, array $context = array() ): bool {
		return self::log( 'critical', $event, $context );
	}

	/**
	 * @param string $event   Event.
	 * @param array  $context Context.
	 */
	public static function error( string $event, array $context = array() ): bool {
		return self::log( 'error', $event, $context );
	}

	/**
	 * @param string $event   Event.
	 * @param array  $context Context.
	 */
	public static function warning( string $event, array $context = array() ): bool {
		return self::log( 'warning', $event, $context );
	}

	/**
	 * @param string $event   Event.
	 * @param array  $context Context.
	 */
	public static function notice( string $event, array $context = array() ): bool {
		return self::log( 'notice', $event, $context );
	}

	/**
	 * @param string $event   Event.
	 * @param array  $context Context.
	 */
	public static function info( string $event, array $context = array() ): bool {
		return self::log( 'info', $event, $context );
	}

	/**
	 * @param string $event   Event.
	 * @param array  $context Context.
	 */
	public static function debug( string $event, array $context = array() ): bool {
		return self::log( 'debug', $event, $context );
	}

	/**
	 * Write one line when the level passes the threshold. Returns whether something was written.
	 *
	 * @param string $level   Level (see LEVELS).
	 * @param string $event   Event name.
	 * @param array  $context Context.
	 */
	public static function log( string $level, string $event, array $context = array() ): bool {
		$level = strtolower( $level );
		if ( ! isset( self::LEVELS[ $level ] ) ) {
			$level = 'error';
		}
		if ( self::$busy ) {
			return false;
		}
		self::$busy = true;
		try {
			if ( ! self::should_log( $level ) ) {
				return false;
			}
			$event   = substr( (string) preg_replace( '/[^a-z0-9_.:-]/', '_', strtolower( $event ) ), 0, 80 );
			$context = self::sanitize_context( $context );
			$json    = $context ? self::json( $context ) : '';
			$line    = '' === $json ? $event : $event . ' ' . $json;
			return self::write( $level, $line, $context );
		} catch ( \Throwable $e ) {
			// Deliberately silent: logging must never break the request that tried to log.
			return false;
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * Whether a level passes the configured threshold.
	 *
	 * @param string $level Level.
	 */
	public static function should_log( string $level ): bool {
		$weight = self::LEVELS[ strtolower( $level ) ] ?? self::LEVELS['error'];
		return $weight >= self::LEVELS[ self::threshold() ];
	}

	/**
	 * Current threshold: setting admin.logLevel → filter bps_log_level → `error`.
	 */
	public static function threshold(): string {
		if ( null !== self::$threshold ) {
			return self::$threshold;
		}
		$level = self::DEFAULT_LEVEL;
		if ( class_exists( Settings::class ) ) {
			try {
				$from = Settings::get_path( 'admin.logLevel', self::DEFAULT_LEVEL );
				if ( is_string( $from ) && '' !== $from ) {
					$level = $from;
				}
			} catch ( \Throwable $e ) {
				$level = self::DEFAULT_LEVEL; // Settings unreadable: keep the safe default.
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$level = apply_filters( 'bps_log_level', $level );
		}
		$level           = is_string( $level ) ? strtolower( $level ) : self::DEFAULT_LEVEL;
		self::$threshold = isset( self::LEVELS[ $level ] ) ? $level : self::DEFAULT_LEVEL;
		return self::$threshold;
	}

	/**
	 * Forget the cached threshold (after a settings save, in tests).
	 */
	public static function flush(): void {
		self::$threshold = null;
	}

	/**
	 * Replace the writer (tests). Null restores the default (WC logger / error_log).
	 *
	 * @param callable|null $writer fn( string $level, string $line, array $context ).
	 */
	public static function set_writer( ?callable $writer ): void {
		self::$writer = $writer;
	}

	/**
	 * URL of WC > Status > Logs filtered on our source (for the status page).
	 */
	public static function logs_url(): string {
		if ( ! function_exists( 'admin_url' ) ) {
			return '';
		}
		return add_query_arg(
			array(
				'page'   => 'wc-status',
				'tab'    => 'logs',
				'source' => self::SOURCE,
			),
			admin_url( 'admin.php' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Making context safe
	 * ------------------------------------------------------------------- */

	/**
	 * Make a context array safe to write (public for tests and for System_Status).
	 *
	 * @param array $context Context.
	 * @param int   $depth   Recursion depth.
	 */
	public static function sanitize_context( array $context, int $depth = 0 ): array {
		$out = array();
		foreach ( $context as $key => $value ) {
			$k = (string) $key;
			if ( $value instanceof \Throwable ) {
				$out[ $k ] = array(
					'class'   => get_class( $value ),
					'message' => self::redact( $value->getMessage() ),
					'code'    => $value->getCode(),
					'at'      => basename( $value->getFile() ) . ':' . $value->getLine(),
				);
				continue;
			}
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $value ) ) {
				$out[ $k ] = array(
					'wp_error' => $value->get_error_code(),
					'message'  => self::redact( (string) $value->get_error_message() ),
				);
				continue;
			}
			if ( preg_match( self::SECRET_KEYS, $k ) ) {
				$out[ $k ] = '[redacted]';
				continue;
			}
			if ( is_scalar( $value ) && preg_match( self::HASH_KEYS, $k ) ) {
				$out[ $k ] = '' === (string) $value ? '' : 'h:' . self::hash( (string) $value );
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $k ] = $depth >= 4 ? '[array]' : self::sanitize_context( $value, $depth + 1 );
			} elseif ( is_string( $value ) ) {
				$out[ $k ] = self::redact( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$out[ $k ] = $value;
			} elseif ( is_object( $value ) ) {
				$out[ $k ] = '[' . get_class( $value ) . ']';
			} else {
				$out[ $k ] = '[' . gettype( $value ) . ']';
			}
		}
		return $out;
	}

	/**
	 * Redact secrets and hash personal data inside free text.
	 *
	 * @param string $text Text.
	 */
	public static function redact( string $text ): string {
		$text = (string) preg_replace( '/\bsk-[A-Za-z0-9_\-]{8,}/', '[redacted]', $text );
		$text = (string) preg_replace( '/\b(Bearer|Basic|Key|Token)\s+[A-Za-z0-9_\-\.=+\/]{8,}/i', '$1 [redacted]', $text );
		$text = (string) preg_replace_callback(
			'/[\w.+-]+@[\w-]+(\.[\w-]+)+/',
			static function ( array $m ): string {
				return 'email:' . self::hash( $m[0] );
			},
			$text
		);
		$text = (string) preg_replace_callback(
			'/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
			static function ( array $m ): string {
				return 'ip:' . self::hash( $m[0] );
			},
			$text
		);
		$text = (string) preg_replace_callback(
			'/\b(?:[0-9a-f]{1,4}:){4,7}[0-9a-f]{1,4}\b/i',
			static function ( array $m ): string {
				return 'ip:' . self::hash( $m[0] );
			},
			$text
		);
		// Stored provider keys (exact values) when the AI module is installed.
		if ( class_exists( AI\Secrets::class ) && method_exists( AI\Secrets::class, 'redact' ) && function_exists( 'get_option' ) ) {
			try {
				$text = (string) AI\Secrets::redact( $text );
			} catch ( \Throwable $e ) {
				unset( $e ); // Secrets unreadable: the pattern based redaction above already ran.
			}
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, self::MAX_STRING );
		}
		return substr( $text, 0, self::MAX_STRING );
	}

	/**
	 * Short keyed hash (12 hex chars) of a normalised value. Same input = same hash on this site.
	 *
	 * @param string $value Value.
	 */
	public static function hash( string $value ): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'bps-log';
		return substr( hash_hmac( 'sha256', strtolower( trim( $value ) ), 'bps-log|' . $salt ), 0, 12 );
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------- */

	/**
	 * JSON for the context (unicode kept, slashes not escaped).
	 *
	 * @param array $context Context.
	 */
	private static function json( array $context ): string {
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
		$json  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $context, $flags ) : json_encode( $context, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return is_string( $json ) ? $json : '';
	}

	/**
	 * Hand the line to the writer.
	 *
	 * @param string $level   Level.
	 * @param string $line    Line.
	 * @param array  $context Safe context.
	 */
	private static function write( string $level, string $line, array $context ): bool {
		if ( null !== self::$writer ) {
			call_user_func( self::$writer, $level, $line, $context );
			return true;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			if ( is_object( $logger ) && method_exists( $logger, 'log' ) ) {
				$logger->log( $level, $line, array( 'source' => self::SOURCE ) );
				return true;
			}
		}
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[' . self::SOURCE . '] ' . strtoupper( $level ) . ' ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return true;
		}
		return false;
	}
}
