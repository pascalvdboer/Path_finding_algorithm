<?php
/**
 * AI settings: code defaults < config/ai-providers.json < config/ai-pricing.json < option bps_ai_settings.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Read/write access to the AI module settings (SPEC-AI-DIENSTEN §9, §12, A14).
 *
 * The option `bps_ai_settings` is shared with the commerce builder (Services\Config): the top level holds overrides
 * for both ai-providers.json (default, fallback, providers, moderation, n, ...) and ai-pricing.json (mode, free,
 * packs, limits, alerts, usdToEur, ...); `services`/`offerRules` belong to commerce. Writers therefore always
 * read-modify-write their own keys and never replace the whole option. Secrets never live here (see Secrets).
 */
final class AI_Settings {

	const OPTION = 'bps_ai_settings';

	const MODES = array( 'off', 'test', 'live' );

	/** Defaults used when a JSON file is missing (other builders may not be done yet). */
	const DEFAULTS = array(
		'mode'             => 'off',
		'default'          => 'openai',
		'fallback'         => null,
		'vectorizer'       => null,
		'n'                => 3,
		'refineN'          => 2,
		'showProviderName' => true,
		'timeoutSec'       => 60,
		'providers'        => array(
			'mock' => array(
				'label'          => 'Voorbeeldontwerpen (test)',
				'estUsdPerImage' => 0,
			),
		),
		'moderation'       => array(
			'enabled'  => true,
			'provider' => 'openai',
			'model'    => 'omni-moderation-latest',
		),
		'mock'             => array(
			'latencyMs' => 1200,
			'slowMs'    => 8000,
			'png'       => false,
		),
		'free'             => array(
			'anonymous' => 1,
			'withEmail' => 2,
			'perOrder'  => 5,
		),
		'limits'           => array(
			'dailyBudgetEur'         => 10,
			'monthlyBudgetEur'       => 150,
			'monthlyHardCapEur'      => 250,
			'alertAtPercent'         => 80,
			'perOwnerPerDay'         => 20,
			'minSecondsBetween'      => 8,
			'anonPerIpPerDay'        => 3,
			'globalConcurrent'       => 4,
			'jobTimeoutSec'          => 90,
			'emailCodesPerIpPerHour' => 5,
			'writePerHour'           => 30,
			'pollPerMinute'          => 120,
			'emailPerHour'           => 5,
		),
		'alerts'           => array( 'email' => '' ),
		'usdToEur'         => 0.93,
		'usdToEurUpdated'  => '',
		'retentionDays'    => array(),
	);

	/** Admin-editable numeric bounds: path => [min, max, integer?]. */
	const BOUNDS = array(
		'n'                             => array( 2, 4, true ),
		'refineN'                       => array( 1, 2, true ),
		'timeoutSec'                    => array( 15, 120, true ),
		'limits.dailyBudgetEur'         => array( 0, 10000, false ),
		'limits.monthlyBudgetEur'       => array( 0, 100000, false ),
		'limits.monthlyHardCapEur'      => array( 0, 100000, false ),
		'limits.alertAtPercent'         => array( 10, 100, true ),
		'limits.perOwnerPerDay'         => array( 1, 500, true ),
		'limits.minSecondsBetween'      => array( 0, 600, true ),
		'limits.anonPerIpPerDay'        => array( 0, 100, true ),
		'limits.globalConcurrent'       => array( 1, 50, true ),
		'limits.jobTimeoutSec'          => array( 30, 600, true ),
		'limits.emailCodesPerIpPerHour' => array( 1, 100, true ),
		'limits.writePerHour'           => array( 5, 1000, true ),
		'limits.pollPerMinute'          => array( 30, 2000, true ),
		'usdToEur'                      => array( 0.5, 2, false ),
		'mock.latencyMs'                => array( 0, 10000, true ),
	);

	/** @var array|null Request cache. */
	private static $cache = null;

	/**
	 * Merged settings.
	 */
	public static function get(): array {
		if ( null === self::$cache ) {
			$merged = self::DEFAULTS;
			$merged = self::deep_merge( $merged, self::strip_doc( self::read_json( 'ai-providers.json' ) ) );
			$merged = self::deep_merge( $merged, self::strip_doc( self::read_json( 'ai-pricing.json' ) ) );
			$merged = self::deep_merge( $merged, self::option() );
			$merged = function_exists( 'apply_filters' ) ? apply_filters( 'bps_ai_settings', $merged ) : $merged;
			self::$cache = is_array( $merged ) ? $merged : self::DEFAULTS;
		}
		return self::$cache;
	}

	/**
	 * Value by dot path.
	 *
	 * @param string $dot     Path like 'limits.dailyBudgetEur'.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function path( string $dot, $default = null ) {
		$v = self::get();
		foreach ( explode( '.', $dot ) as $part ) {
			if ( ! is_array( $v ) || ! array_key_exists( $part, $v ) ) {
				return $default;
			}
			$v = $v[ $part ];
		}
		return $v;
	}

	/**
	 * Integer limit with a sane floor.
	 *
	 * @param string $key Key under limits.
	 */
	public static function limit( string $key ): float {
		$v = self::path( 'limits.' . $key, self::DEFAULTS['limits'][ $key ] ?? 0 );
		return is_numeric( $v ) ? (float) $v : (float) ( self::DEFAULTS['limits'][ $key ] ?? 0 );
	}

	/**
	 * Module mode.
	 */
	public static function mode(): string {
		$m = (string) self::path( 'mode', 'off' );
		return in_array( $m, self::MODES, true ) ? $m : 'off';
	}

	/**
	 * Debug hooks (mock test markers) allowed.
	 */
	public static function test_hooks_allowed(): bool {
		return 'test' === self::mode() || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	/**
	 * Retention in days for a key of ai-legal.json retentionDays (admin override wins, within bounds).
	 *
	 * @param string $key      e.g. guestPromptsAndProposals.
	 * @param int    $fallback Fallback.
	 */
	public static function retention( string $key, int $fallback ): int {
		$over = self::path( 'retentionDays.' . $key );
		if ( is_numeric( $over ) && (int) $over > 0 ) {
			return (int) $over;
		}
		$legal = Module::legal();
		$v     = $legal['retentionDays'][ $key ] ?? null;
		return is_numeric( $v ) && (int) $v > 0 ? (int) $v : $fallback;
	}

	/**
	 * Raw option (overrides only).
	 */
	public static function option(): array {
		$v = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Write a set of dot-path values into the option, keeping every other key (also those of commerce).
	 *
	 * @param array $values dot path => value. A null value removes the override (back to default).
	 */
	public static function update( array $values ): void {
		$opt = self::option();
		foreach ( $values as $dot => $value ) {
			self::set_path( $opt, (string) $dot, $value );
		}
		update_option( self::OPTION, $opt, false );
		self::flush();
		do_action( 'bps_ai_settings_updated', self::get() );
	}

	/**
	 * Drop overrides of the given top-level keys or dot paths ("Standaard terugzetten").
	 *
	 * @param string[] $paths Paths.
	 */
	public static function reset( array $paths ): void {
		$values = array();
		foreach ( $paths as $p ) {
			$values[ $p ] = null;
		}
		self::update( $values );
	}

	/**
	 * Clamp a numeric admin value to its bounds.
	 *
	 * @param string $dot   Path in BOUNDS.
	 * @param mixed  $value Raw value (comma decimals allowed).
	 * @return int|float|null Null if not numeric.
	 */
	public static function clamp( string $dot, $value ) {
		if ( is_string( $value ) ) {
			$value = str_replace( array( ' ', ',' ), array( '', '.' ), $value );
		}
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		list( $min, $max, $int ) = self::BOUNDS[ $dot ] ?? array( PHP_INT_MIN, PHP_INT_MAX, false );
		$v                       = max( (float) $min, min( (float) $max, (float) $value ) );
		return $int ? (int) round( $v ) : round( $v, 4 );
	}

	/**
	 * Reset the request cache (after a write, or in tests).
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Read a config JSON (empty array if missing/invalid).
	 *
	 * @param string $file File name in config/.
	 */
	public static function read_json( string $file ): array {
		$path = self::config_dir() . $file;
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Absolute config dir with trailing slash.
	 */
	public static function config_dir(): string {
		return ( defined( 'BPS_DIR' ) ? BPS_DIR : dirname( __DIR__, 2 ) . '/' ) . 'config/';
	}

	/**
	 * Deep merge: associative arrays merge key by key, lists and scalars are replaced.
	 *
	 * @param array $base Base.
	 * @param array $over Overrides.
	 */
	public static function deep_merge( array $base, array $over ): array {
		foreach ( $over as $k => $v ) {
			if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && ! self::is_list( $v ) && ! self::is_list( $base[ $k ] ) ) {
				$base[ $k ] = self::deep_merge( $base[ $k ], $v );
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}

	/**
	 * Remove "_doc"-style keys recursively.
	 *
	 * @param array $a Array.
	 */
	public static function strip_doc( array $a ): array {
		foreach ( $a as $k => $v ) {
			if ( is_string( $k ) && '' !== $k && '_' === $k[0] ) {
				unset( $a[ $k ] );
			} elseif ( is_array( $v ) ) {
				$a[ $k ] = self::strip_doc( $v );
			}
		}
		return $a;
	}

	/**
	 * @param array $a Array.
	 */
	private static function is_list( array $a ): bool {
		return array() === $a ? false : array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	/**
	 * Set/unset a dot path in an array.
	 *
	 * @param array  $arr   Array (by reference).
	 * @param string $dot   Path.
	 * @param mixed  $value Value; null unsets.
	 */
	private static function set_path( array &$arr, string $dot, $value ): void {
		$parts = explode( '.', $dot );
		$last  = array_pop( $parts );
		$ref   = &$arr;
		foreach ( $parts as $p ) {
			if ( ! isset( $ref[ $p ] ) || ! is_array( $ref[ $p ] ) ) {
				if ( null === $value ) {
					return;
				}
				$ref[ $p ] = array();
			}
			$ref = &$ref[ $p ];
		}
		if ( null === $value ) {
			unset( $ref[ $last ] );
		} else {
			$ref[ $last ] = $value;
		}
	}
}
