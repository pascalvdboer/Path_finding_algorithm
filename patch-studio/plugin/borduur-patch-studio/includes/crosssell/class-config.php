<?php
/**
 * Cross-sell configuratie: config/crosssell.json + beheerinstellingen (optie bps_crosssell_settings, enkel verschillen).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Crosssell;

use BorduurPatchStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Config-toegang.
 */
final class Config {

	const OPTION = 'bps_crosssell_settings';

	/** @var array|null */
	private static $defaults = null;

	/** @var array|null */
	private static $config = null;

	/**
	 * Standaardwaarden uit het JSON-bestand.
	 */
	public static function defaults(): array {
		if ( null === self::$defaults ) {
			$file           = ( defined( 'BPS_DIR' ) ? BPS_DIR : dirname( __DIR__, 2 ) . '/' ) . 'config/crosssell.json';
			$raw            = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			self::$defaults = is_array( $raw ) ? $raw : array( 'enabled' => false, 'items' => array(), 'rules' => array() );
		}
		return self::$defaults;
	}

	/**
	 * Opgeslagen verschillen.
	 */
	public static function overrides(): array {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Gemergde config (filter `bps_crosssell_config`).
	 */
	public static function get(): array {
		if ( null === self::$config ) {
			$cfg          = self::merge( self::defaults(), self::overrides() );
			$cfg          = apply_filters( 'bps_crosssell_config', $cfg );
			self::$config = is_array( $cfg ) ? $cfg : self::defaults();
		}
		return self::$config;
	}

	/**
	 * Cache leegmaken (na opslaan).
	 */
	public static function flush(): void {
		self::$config = null;
	}

	/**
	 * Hoofdconfig van de studio (vat, tiers, techniques, options, fees).
	 */
	public static function main(): array {
		if ( class_exists( Settings::class ) && method_exists( Settings::class, 'get' ) ) {
			return Settings::get();
		}
		$file = ( defined( 'BPS_DIR' ) ? BPS_DIR : dirname( __DIR__, 2 ) . '/' ) . 'config/defaults.json';
		$raw  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Item op id.
	 *
	 * @param string $id Item-id.
	 */
	public static function item( string $id ): ?array {
		foreach ( (array) ( self::get()['items'] ?? array() ) as $it ) {
			if ( is_array( $it ) && ( $it['id'] ?? null ) === $id ) {
				return $it;
			}
		}
		return null;
	}

	/**
	 * Staat de module aan?
	 */
	public static function enabled(): bool {
		return ! empty( self::get()['enabled'] );
	}

	/**
	 * Teksten-overrides ['nl' => [...], 'fr' => [...]].
	 */
	public static function strings(): array {
		$s = self::get()['strings'] ?? array();
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Locale van de bezoeker: 'fr-BE' bij Franse site/taal, anders 'nl-BE'.
	 *
	 * @param string|null $hint Locale uit een header of payload.
	 */
	public static function locale( ?string $hint = null ): string {
		$hint = (string) $hint;
		if ( '' === $hint && function_exists( 'determine_locale' ) ) {
			$hint = determine_locale();
		}
		return str_starts_with( strtolower( $hint ), 'fr' ) ? 'fr-BE' : 'nl-BE';
	}

	/**
	 * Overrides opslaan (enkel verschillen met de standaard). Lijsten vervangen, objecten recursief.
	 *
	 * @param array $full Volledige nieuwe config.
	 */
	public static function save( array $full ): void {
		$diff = self::diff( $full, self::defaults() );
		update_option( self::OPTION, $diff, false );
		self::flush();
		do_action( 'bps_crosssell_settings_updated', self::get() );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	/**
	 * Standaard herstellen.
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
		self::flush();
		do_action( 'bps_crosssell_settings_updated', self::get() );
	}

	/**
	 * Deep merge (zoals Settings::deep_merge: objecten recursief, lijsten en scalars vervangen).
	 *
	 * @param array $base Basis.
	 * @param array $over Over.
	 */
	public static function merge( array $base, array $over ): array {
		if ( class_exists( Settings::class ) && method_exists( Settings::class, 'deep_merge' ) ) {
			return Settings::deep_merge( $base, $over );
		}
		foreach ( $over as $k => $v ) {
			$base[ $k ] = ( isset( $base[ $k ] ) && self::is_obj( $base[ $k ] ) && self::is_obj( $v ) ) ? self::merge( $base[ $k ], $v ) : $v;
		}
		return $base;
	}

	/**
	 * Verschil met de basis (enkel wat anders is).
	 *
	 * @param array $value Waarde.
	 * @param array $base  Basis.
	 */
	public static function diff( array $value, array $base ): array {
		$out = array();
		foreach ( $value as $k => $v ) {
			if ( ! array_key_exists( $k, $base ) ) {
				$out[ $k ] = $v;
			} elseif ( self::is_obj( $v ) && self::is_obj( $base[ $k ] ) ) {
				$d = self::diff( $v, $base[ $k ] );
				if ( $d ) {
					$out[ $k ] = $d;
				}
			} elseif ( wp_json_encode( $v ) !== wp_json_encode( $base[ $k ] ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Associatieve array?
	 *
	 * @param mixed $v Waarde.
	 */
	private static function is_obj( $v ): bool {
		return is_array( $v ) && ( array() === $v || ! array_is_list( $v ) );
	}
}
