<?php
/**
 * Commerce configuration: ai-pricing.json + ai-services.json, deep-merged with the option bps_ai_settings.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only access to round/pack/service settings (SPEC-AI-DIENSTEN §12, §15).
 *
 * Layout of the option `bps_ai_settings` (shared with ai-backend, which owns the provider keys):
 *   top level  = overrides for config/ai-pricing.json (mode, free, packs, orderCredit, limits, ...)
 *   'services' = overrides per service id for config/ai-services.json `services`
 *   'offerRules' = overrides for config/ai-services.json `offerRules`
 * Lists (packs) are replaced as a whole, associative arrays are merged key by key.
 */
final class Config {

	const OPTION = 'bps_ai_settings';

	/** @var array|null */
	private static $pricing = null;

	/** @var array|null */
	private static $services = null;

	/** @var array|null Test override of the stored option. */
	private static $override = null;

	/**
	 * Merged pricing config.
	 */
	public static function pricing(): array {
		if ( null === self::$pricing ) {
			$base = self::read_json( 'ai-pricing.json' );
			$opt  = self::option();
			unset( $opt['services'], $opt['offerRules'] );
			self::$pricing = self::deep_merge( $base, $opt );
		}
		return self::$pricing;
	}

	/**
	 * Merged services config ({offerRules, statuses, services}).
	 */
	public static function services_config(): array {
		if ( null === self::$services ) {
			$base = self::read_json( 'ai-services.json' );
			$opt  = self::option();
			$over = array();
			if ( isset( $opt['services'] ) && is_array( $opt['services'] ) ) {
				$over['services'] = array_intersect_key( $opt['services'], (array) ( $base['services'] ?? array() ) );
			}
			if ( isset( $opt['offerRules'] ) && is_array( $opt['offerRules'] ) ) {
				$over['offerRules'] = $opt['offerRules'];
			}
			self::$services = self::deep_merge( $base, $over );
		}
		return self::$services;
	}

	/**
	 * Value by dotted path in the pricing config.
	 *
	 * @param string $dot     Path like 'orderCredit.percent'.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get( string $dot, $default = null ) {
		$v = self::pricing();
		foreach ( explode( '.', $dot ) as $part ) {
			if ( ! is_array( $v ) || ! array_key_exists( $part, $v ) ) {
				return $default;
			}
			$v = $v[ $part ];
		}
		return $v;
	}

	/**
	 * Module mode: off|test|live.
	 */
	public static function mode(): string {
		$m = (string) self::get( 'mode', 'off' );
		return in_array( $m, array( 'off', 'test', 'live' ), true ) ? $m : 'off';
	}

	/**
	 * VAT rate used to show incl. prices (Woo still computes the real tax at checkout).
	 */
	public static function vat_rate(): float {
		$r = (float) self::get( 'vatRate', 0.21 );
		return ( $r >= 0 && $r < 1 ) ? $r : 0.21;
	}

	/**
	 * Price incl. VAT from an excl. price, half-up on the cent.
	 *
	 * @param float $excl Excl. price.
	 */
	public static function incl( float $excl ): float {
		return self::round_half_up( $excl * ( 1 + self::vat_rate() ), 2 );
	}

	/**
	 * Price excl. VAT (4 decimals) from an incl. price.
	 *
	 * @param float $incl Incl. price.
	 */
	public static function excl( float $incl ): float {
		return self::round_half_up( $incl / ( 1 + self::vat_rate() ), 4 );
	}

	/**
	 * Packs with computed priceIncl.
	 *
	 * @param bool $enabled_only Only enabled packs.
	 * @return array<int, array{id:string, rounds:int, priceExcl:float, priceIncl:float, enabled:bool, label:array}>
	 */
	public static function packs( bool $enabled_only = true ): array {
		$out = array();
		foreach ( (array) self::get( 'packs', array() ) as $p ) {
			if ( ! is_array( $p ) || empty( $p['id'] ) || ! preg_match( '/^[a-z0-9_]{1,32}$/', (string) $p['id'] ) ) {
				continue;
			}
			$pack = array(
				'id'        => (string) $p['id'],
				'rounds'    => max( 1, (int) ( $p['rounds'] ?? 0 ) ),
				'priceExcl' => max( 0.0, (float) ( $p['priceExcl'] ?? 0 ) ),
				'enabled'   => ! empty( $p['enabled'] ),
				'label'     => is_array( $p['label'] ?? null ) ? $p['label'] : array(),
			);
			$pack['priceIncl'] = self::incl( $pack['priceExcl'] );
			if ( $enabled_only && ( ! $pack['enabled'] || $pack['priceIncl'] <= 0 ) ) {
				continue;
			}
			$out[] = $pack;
		}
		return $out;
	}

	/**
	 * One pack by id (enabled or not).
	 *
	 * @param string $id Pack id.
	 */
	public static function pack( string $id ): ?array {
		foreach ( self::packs( false ) as $p ) {
			if ( $p['id'] === $id ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * All services (merged) with computed priceIncl / minIncl, in priority order.
	 *
	 * @param bool $enabled_only Only enabled services.
	 * @return array<string, array>
	 */
	public static function services( bool $enabled_only = false ): array {
		$out = array();
		foreach ( (array) ( self::services_config()['services'] ?? array() ) as $id => $s ) {
			if ( ! is_array( $s ) || ! preg_match( '/^[a-z0-9_]{1,32}$/', (string) $id ) ) {
				continue;
			}
			$s['id']        = (string) $id;
			$s['enabled']   = ! empty( $s['enabled'] );
			$s['kind']      = 'line_option' === ( $s['kind'] ?? '' ) ? 'line_option' : 'product';
			$s['priceIncl'] = self::incl( (float) ( $s['priceExcl'] ?? 0 ) );
			if ( isset( $s['minExcl'] ) ) {
				$s['minIncl'] = self::incl( (float) $s['minExcl'] );
			}
			if ( isset( $s['priceExclLarge'] ) ) {
				$s['priceInclLarge'] = self::incl( (float) $s['priceExclLarge'] );
			}
			if ( $enabled_only && ! $s['enabled'] ) {
				continue;
			}
			$out[ (string) $id ] = $s;
		}
		return $out;
	}

	/**
	 * One service by id.
	 *
	 * @param string $id Service id.
	 */
	public static function service( string $id ): ?array {
		$all = self::services( false );
		return $all[ $id ] ?? null;
	}

	/**
	 * Offer rules.
	 */
	public static function offer_rules(): array {
		return array_merge(
			array(
				'maxVisible'       => 1,
				'neverPreselected' => true,
				'rememberDismiss'  => true,
			),
			(array) ( self::services_config()['offerRules'] ?? array() )
		);
	}

	/**
	 * Store overrides (used by the admin sections). Clears the cache.
	 *
	 * @param array $patch Partial settings to merge into the option.
	 */
	public static function save( array $patch ): void {
		$current = self::option();
		$next    = self::deep_merge( $current, $patch );
		if ( null !== self::$override ) {
			self::$override = $next;
		} else {
			update_option( self::OPTION, $next, false );
		}
		self::flush();
		do_action( 'bps_ai_settings_saved', $next );
	}

	/**
	 * Remove override keys (reset a section to its defaults).
	 *
	 * @param string[] $keys Top-level keys.
	 */
	public static function reset( array $keys ): void {
		$current = self::option();
		foreach ( $keys as $k ) {
			unset( $current[ $k ] );
		}
		if ( null !== self::$override ) {
			self::$override = $current;
		} else {
			update_option( self::OPTION, $current, false );
		}
		self::flush();
		do_action( 'bps_ai_settings_saved', $current );
	}

	/**
	 * Clear caches.
	 */
	public static function flush(): void {
		self::$pricing  = null;
		self::$services = null;
	}

	/**
	 * Tests: use this array instead of the stored option (null = back to the real option).
	 *
	 * @param array|null $settings Settings.
	 */
	public static function set_override( ?array $settings ): void {
		self::$override = $settings;
		self::flush();
	}

	/**
	 * Raw stored overrides.
	 */
	public static function option(): array {
		if ( null !== self::$override ) {
			return self::$override;
		}
		$v = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Recursive merge: associative arrays merge, lists and scalars replace.
	 *
	 * @param array $base  Base.
	 * @param array $over  Overrides.
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
	 * Is this a list (0..n-1 keys)? Empty arrays count as associative so they never wipe a map.
	 *
	 * @param array $a Array.
	 */
	private static function is_list( array $a ): bool {
		return array() !== $a && array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	/**
	 * Half-up rounding that is stable for binary floats (230.175 → 230.18).
	 *
	 * @param float $v        Value.
	 * @param int   $decimals Decimals.
	 */
	public static function round_half_up( float $v, int $decimals = 2 ): float {
		$m = 10 ** $decimals;
		$x = abs( $v ) * $m;
		$r = floor( $x + 0.5 + 1e-9 );
		return ( $v < 0 ? -1 : 1 ) * $r / $m;
	}

	/**
	 * Read a config JSON file of the plugin.
	 *
	 * @param string $file File name in config/.
	 */
	private static function read_json( string $file ): array {
		$path = dirname( __DIR__, 2 ) . '/config/' . $file;
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $data ) ? $data : array();
	}
}
