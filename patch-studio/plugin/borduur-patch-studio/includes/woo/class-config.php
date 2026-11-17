<?php
/**
 * Thin accessor around Settings for the WooCommerce layer: config, labels and locale helpers.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

use BorduurPatchStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only helpers. All data comes from Settings::get() (SPEC §4.2); when the core Settings class is not
 * available (isolated tests) the bundled defaults are used so the Woo layer never fatals.
 */
final class Config {

	/** @var array|null Fallback cache when Settings is missing. */
	private static $fallback = null;

	/**
	 * Full (private) config.
	 */
	public static function get(): array {
		if ( class_exists( Settings::class ) && method_exists( Settings::class, 'get' ) ) {
			return (array) Settings::get();
		}
		if ( null === self::$fallback ) {
			$dir      = defined( 'BPS_DIR' ) ? BPS_DIR : dirname( __DIR__, 2 ) . '/';
			$defaults = json_decode( (string) file_get_contents( $dir . 'config/defaults.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$palette  = json_decode( (string) file_get_contents( $dir . 'config/thread-palette.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$defaults = is_array( $defaults ) ? $defaults : array();
			if ( is_array( $palette ) && isset( $palette['threads'] ) ) {
				$defaults['threads'] = $palette['threads'];
			}
			self::$fallback = (array) apply_filters( 'bps_config', $defaults );
		}
		return self::$fallback;
	}

	/**
	 * Dot-path lookup ('woo.maxUploadMb').
	 *
	 * @param string $dot     Path.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function path( string $dot, $default = null ) {
		$node = self::get();
		foreach ( explode( '.', $dot ) as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return $default;
			}
			$node = $node[ $part ];
		}
		return $node;
	}

	/**
	 * Technique definition or null.
	 *
	 * @param string $id Technique id.
	 */
	public static function technique( string $id ): ?array {
		$t = self::path( 'techniques.' . $id );
		return is_array( $t ) ? $t : null;
	}

	/**
	 * Enabled technique ids in configured order.
	 *
	 * @return string[]
	 */
	public static function enabled_techniques(): array {
		$c     = self::get();
		$order = isset( $c['techniqueOrder'] ) && is_array( $c['techniqueOrder'] ) ? $c['techniqueOrder'] : array_keys( (array) ( $c['techniques'] ?? array() ) );
		$out   = array();
		foreach ( $order as $id ) {
			if ( ! empty( $c['techniques'][ $id ]['enabled'] ) ) {
				$out[] = (string) $id;
			}
		}
		return $out;
	}

	/**
	 * Option definition by id.
	 *
	 * @param string $id Option id.
	 */
	public static function option( string $id ): ?array {
		foreach ( (array) self::path( 'options', array() ) as $opt ) {
			if ( is_array( $opt ) && isset( $opt['id'] ) && $opt['id'] === $id ) {
				return $opt;
			}
		}
		return null;
	}

	/**
	 * Price definition of an option for a technique (priceByTechnique override).
	 *
	 * @param array  $opt  Option.
	 * @param string $tech Technique id.
	 */
	public static function option_price( array $opt, string $tech ): array {
		if ( isset( $opt['priceByTechnique'][ $tech ] ) && is_array( $opt['priceByTechnique'][ $tech ] ) ) {
			return $opt['priceByTechnique'][ $tech ];
		}
		return isset( $opt['price'] ) && is_array( $opt['price'] ) ? $opt['price'] : array(
			'type'   => 'per_piece',
			'amount' => 0,
		);
	}

	/**
	 * Product id of the "Patch op maat" product (0 when not created yet).
	 */
	public static function product_id(): int {
		if ( class_exists( Settings::class ) && method_exists( Settings::class, 'product_id' ) ) {
			$id = (int) Settings::product_id();
			if ( $id > 0 ) {
				return $id;
			}
		}
		$id = (int) self::path( 'woo.productId', 0 );
		return $id > 0 ? $id : (int) get_option( 'bps_product_id', 0 );
	}

	/* ---------------------------------------------------------------------
	 * Locale helpers
	 * ------------------------------------------------------------------- */

	/**
	 * 'nl' or 'fr' for a locale-ish string ('fr-BE', 'fr_FR', 'nl_BE', null = current WP locale).
	 *
	 * @param string|null $locale Locale.
	 */
	public static function lang( ?string $locale = null ): string {
		if ( null === $locale || '' === $locale ) {
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		}
		return 0 === stripos( (string) $locale, 'fr' ) ? 'fr' : 'nl';
	}

	/**
	 * Public locale code ('nl-BE' | 'fr-BE').
	 *
	 * @param string|null $locale Locale.
	 */
	public static function public_locale( ?string $locale = null ): string {
		return 'fr' === self::lang( $locale ) ? 'fr-BE' : 'nl-BE';
	}

	/**
	 * WordPress locale for switch_to_locale() ('fr_BE' | 'nl_BE').
	 *
	 * @param string|null $locale Locale.
	 */
	public static function wp_locale( ?string $locale = null ): string {
		return 'fr' === self::lang( $locale ) ? 'fr_BE' : 'nl_BE';
	}

	/**
	 * Text from a {nl,fr} label.
	 *
	 * @param mixed       $obj    Label object or string.
	 * @param string|null $locale Locale.
	 */
	public static function label( $obj, ?string $locale = null ): string {
		if ( is_string( $obj ) ) {
			return $obj;
		}
		if ( ! is_array( $obj ) ) {
			return '';
		}
		$lang = self::lang( $locale );
		$alt  = 'fr' === $lang ? 'nl' : 'fr';
		return (string) ( ! empty( $obj[ $lang ] ) ? $obj[ $lang ] : ( $obj[ $alt ] ?? '' ) );
	}

	/**
	 * Technique label.
	 *
	 * @param string      $id     Technique id.
	 * @param string|null $locale Locale.
	 */
	public static function technique_label( string $id, ?string $locale = null ): string {
		$t = self::technique( $id );
		return $t ? self::label( $t['label'] ?? $id, $locale ) : $id;
	}

	/**
	 * Shape label.
	 *
	 * @param string      $id     Shape id.
	 * @param string|null $locale Locale.
	 */
	public static function shape_label( string $id, ?string $locale = null ): string {
		$s = self::path( 'shapes.' . $id );
		return is_array( $s ) ? self::label( $s['label'] ?? $id, $locale ) : $id;
	}

	/**
	 * Option label.
	 *
	 * @param string      $id     Option id.
	 * @param string|null $locale Locale.
	 */
	public static function option_label( string $id, ?string $locale = null ): string {
		$o = self::option( $id );
		return $o ? self::label( $o['label'] ?? $id, $locale ) : $id;
	}

	/**
	 * Locale-aware number formatting with a decimal comma ("8", "7,5").
	 *
	 * @param float $n        Number.
	 * @param int   $decimals Max decimals.
	 */
	public static function num( float $n, int $decimals = 1 ): string {
		$s = number_format( $n, $decimals, ',', '.' );
		if ( false !== strpos( $s, ',' ) ) {
			$s = rtrim( rtrim( $s, '0' ), ',' );
		}
		return $s;
	}

	/**
	 * Money for e-mails/notices: "€ 5,98" (nl) / "5,98 €" (fr). Uses WooCommerce formatting when possible.
	 *
	 * @param float       $amount Amount.
	 * @param string|null $locale Locale.
	 */
	public static function money( float $amount, ?string $locale = null ): string {
		$n = number_format( $amount, 2, ',', '.' );
		return 'fr' === self::lang( $locale ) ? $n . "\u{00A0}€" : "€\u{00A0}" . $n;
	}

	/**
	 * Size text "8 cm (80 × 80 mm)".
	 *
	 * @param float $size_cm   Longest side.
	 * @param float $width_mm  Width.
	 * @param float $height_mm Height.
	 */
	public static function size_text( float $size_cm, float $width_mm = 0, float $height_mm = 0 ): string {
		$s = self::num( $size_cm ) . ' cm';
		if ( $width_mm > 0 && $height_mm > 0 ) {
			$s .= ' (' . self::num( round( $width_mm ), 0 ) . " \u{00D7} " . self::num( round( $height_mm ), 0 ) . ' mm)';
		}
		return $s;
	}
}
