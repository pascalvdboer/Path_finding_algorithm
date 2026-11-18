<?php
/**
 * Name tapes: merged configuration (config/nametape.json + option bps_nametape_settings) and the public page config.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Nametape;

use BorduurPatchStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-EXTRA §1.4, §1.9, D9. Own option on top of an own JSON file; the main Settings stay untouched.
 */
final class Config {

	const OPTION         = 'bps_nametape_settings';
	const PRODUCT_OPTION = 'bps_nametape_product_id';
	const PAGE_OPTION    = 'bps_nametape_page_id';
	const PRIVATE_KEYS   = array( 'woo', '_doc' );

	/** @var array|null */
	private static $cache = null;
	/** @var array|null */
	private static $defaults = null;
	/** @var array|null */
	private static $metrics = null;

	/**
	 * Read a JSON file from config/.
	 *
	 * @param string $name File name.
	 */
	private static function read_json( string $name ): array {
		$file = ( defined( 'BPS_DIR' ) ? BPS_DIR : dirname( __DIR__, 2 ) . '/' ) . 'config/' . $name;
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Defaults from config/nametape.json.
	 */
	public static function defaults(): array {
		if ( null === self::$defaults ) {
			self::$defaults = self::read_json( 'nametape.json' );
		}
		return self::$defaults;
	}

	/**
	 * Deep merge (same rules as Settings::deep_merge: lists replaced).
	 *
	 * @param array $base Base.
	 * @param array $over Overrides.
	 */
	public static function merge( array $base, array $over ): array {
		if ( class_exists( Settings::class ) && method_exists( Settings::class, 'deep_merge' ) ) {
			return Settings::deep_merge( $base, $over );
		}
		foreach ( $over as $k => $v ) {
			$is_assoc     = static fn( $x ) => is_array( $x ) && array() !== $x && ! array_is_list( $x );
			$base[ $k ]   = ( isset( $base[ $k ] ) && $is_assoc( $base[ $k ] ) && $is_assoc( $v ) ) ? self::merge( $base[ $k ], $v ) : $v;
		}
		return $base;
	}

	/**
	 * Stored overrides.
	 */
	public static function overrides(): array {
		$o = get_option( self::OPTION, array() );
		return is_array( $o ) ? $o : array();
	}

	/**
	 * Merged config (filter bps_nametape_config).
	 */
	public static function get(): array {
		if ( null === self::$cache ) {
			$cfg = self::merge( self::defaults(), self::overrides() );
			$cfg = apply_filters( 'bps_nametape_config', $cfg );
			self::$cache = is_array( $cfg ) ? $cfg : self::defaults();
		}
		return self::$cache;
	}

	/**
	 * Forget the cache (after saving).
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Dotted path in the merged config.
	 *
	 * @param string $dot     Path.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function path( string $dot, $default = null ) {
		$v = self::get();
		foreach ( explode( '.', $dot ) as $seg ) {
			if ( ! is_array( $v ) || ! array_key_exists( $seg, $v ) ) {
				return $default;
			}
			$v = $v[ $seg ];
		}
		return $v;
	}

	/**
	 * Is the module switched on?
	 */
	public static function enabled(): bool {
		return false !== self::path( 'enabled', true );
	}

	/**
	 * Font metrics (config/nametape-metrics.json).
	 */
	public static function metrics(): array {
		if ( null === self::$metrics ) {
			self::$metrics = self::read_json( 'nametape-metrics.json' );
		}
		return self::$metrics;
	}

	/**
	 * Main Patch Studio config (Settings), falling back to defaults.json.
	 */
	public static function main(): array {
		if ( class_exists( Settings::class ) && method_exists( Settings::class, 'get' ) ) {
			$c = Settings::get();
			if ( is_array( $c ) && $c ) {
				return $c;
			}
		}
		return self::read_json( 'defaults.json' );
	}

	/**
	 * VAT rate.
	 */
	public static function vat_rate(): float {
		$m = self::main();
		return isset( $m['vat']['rate'] ) ? (float) $m['vat']['rate'] : 0.21;
	}

	/**
	 * Tier definition (main config) with our pooling switch.
	 */
	public static function tiers(): array {
		$m = self::main();
		$t = isset( $m['tiers'] ) && is_array( $m['tiers'] ) ? $m['tiers'] : array( 'steps' => array() );
		$t['pooledWithPatches'] = false !== self::path( 'tiers.pooledWithPatches', true );
		return $t;
	}

	/**
	 * Shipping estimate config (main config).
	 */
	public static function shipping(): ?array {
		$m = self::main();
		return isset( $m['shipping'] ) && is_array( $m['shipping'] ) ? $m['shipping'] : null;
	}

	/**
	 * 'nl-BE' | 'fr-BE'.
	 *
	 * @param string|null $locale Locale.
	 */
	public static function public_locale( ?string $locale = null ): string {
		if ( null === $locale || '' === $locale ) {
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : 'nl_BE';
		}
		return 0 === stripos( str_replace( '_', '-', $locale ), 'fr' ) ? 'fr-BE' : 'nl-BE';
	}

	/**
	 * 'nl' | 'fr'.
	 *
	 * @param string|null $locale Locale.
	 */
	public static function lang( ?string $locale = null ): string {
		return 'fr-BE' === self::public_locale( $locale ) ? 'fr' : 'nl';
	}

	/**
	 * Label from {nl, fr}.
	 *
	 * @param mixed       $obj    Label.
	 * @param string|null $locale Locale.
	 */
	public static function label( $obj, ?string $locale = null ): string {
		return Pricing::label( $obj, self::lang( $locale ) );
	}

	/**
	 * URL of the name-tape page (page option, woo.pageId, else '').
	 */
	public static function page_url(): string {
		$ids = array( (int) self::path( 'woo.pageId', 0 ), (int) get_option( self::PAGE_OPTION, 0 ) );
		foreach ( $ids as $id ) {
			if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
				$url = get_permalink( $id );
				if ( $url ) {
					return (string) $url;
				}
			}
		}
		return '';
	}

	/**
	 * Public config for the page (§1.9): merged nametape.json without woo, metrics, a few keys of the main config.
	 *
	 * @param string|null $locale Locale.
	 * @param array       $page   Page data (editKey, …).
	 */
	public static function public_config( ?string $locale = null, array $page = array() ): array {
		$cfg    = self::get();
		$locale = self::public_locale( $locale );
		foreach ( self::PRIVATE_KEYS as $k ) {
			unset( $cfg[ $k ] );
		}
		// Only what the customer may choose.
		$cfg['fabrics'] = array_values( array_filter( (array) ( $cfg['fabrics'] ?? array() ), static fn( $f ) => is_array( $f ) && ( ! isset( $f['inStock'] ) || false !== $f['inStock'] ) ) );
		$metrics        = self::metrics();
		$main           = self::main();
		$strings        = array();
		if ( isset( $cfg['strings'] ) && is_array( $cfg['strings'] ) ) {
			$strings = $cfg['strings'];
			unset( $cfg['strings'] );
		}

		$cfg['metrics']  = isset( $metrics['fonts'] ) ? $metrics['fonts'] : new \stdClass();
		$cfg['locale']   = $locale;
		$cfg['vat']      = array( 'rate' => self::vat_rate() );
		$cfg['tiers']    = self::tiers();
		$cfg['shipping'] = self::shipping();
		$cfg['legal']    = array( 'rightsTextVersion' => (string) ( $main['legal']['rightsTextVersion'] ?? '' ) );
		$cfg['rest']     = array(
			'root'    => esc_url_raw( rest_url( 'patch-studio/v1/' ) ),
			'cartUrl' => function_exists( 'wc_get_cart_url' ) ? esc_url_raw( wc_get_cart_url() ) : '',
		);
		$cfg['assets']   = array(
			'fontsUrl' => defined( 'BPS_URL' ) ? BPS_URL . 'assets/fonts/' : '',
			'version'  => defined( 'BPS_VERSION' ) ? BPS_VERSION : '',
		);
		$studio          = class_exists( '\BorduurPatchStudio\Woo\Product' ) && method_exists( '\BorduurPatchStudio\Woo\Product', 'studio_url' ) ? \BorduurPatchStudio\Woo\Product::studio_url() : '';
		$cfg['page']     = array_merge(
			array(
				'editKey'   => '',
				'studioUrl' => $studio && home_url( '/' ) !== $studio ? $studio : '',
			),
			$page
		);
		$cfg['strings']  = $strings ? $strings : new \stdClass();
		$public          = apply_filters( 'bps_nametape_public_config', $cfg, $locale );
		return is_array( $public ) ? $public : $cfg;
	}
}
