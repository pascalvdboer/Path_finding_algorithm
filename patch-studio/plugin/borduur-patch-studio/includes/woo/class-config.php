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
 * Read-only helpers. All data comes from Settings::get() (SPEC §4.2): Settings is the only reader of
 * config/defaults.json for the core config (php-kwaliteit-7).
 */
final class Config {

	/** @var array|null Memoised WooCommerce shipping zone reading (one per request). */
	private static $zone = null;

	/**
	 * Forget request memos (settings saved, tests).
	 */
	public static function reset_cache(): void {
		self::$zone = null;
	}

	/**
	 * Full (private) config.
	 */
	public static function get(): array {
		return (array) Settings::get();
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
		return (int) Settings::product_id();
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

	/* ---------------------------------------------------------------------
	 * WooCommerce prices and taxes (woo-compat-1, contract C5)
	 * ------------------------------------------------------------------- */

	/**
	 * The price WooCommerce expects in set_price() for an amount excl. VAT.
	 *
	 * Our engine always works excl. VAT. When the shop enters prices incl. VAT (woocommerce_prices_include_tax),
	 * WooCommerce treats set_price() as incl. VAT and subtracts the base-location rate of the product's tax class,
	 * so we add exactly that rate here. No rounding to 2 decimals: WooCommerce rounds at the line/total level, so
	 * the net amount it derives back is the original $excl (10 × 4,94 → subtotal 49,40 in both modes).
	 *
	 * @param float       $excl    Unit price excl. VAT.
	 * @param \WC_Product $product Product of the cart line (its tax class decides the rate).
	 */
	public static function woo_unit_price( float $excl, $product = null ): float {
		if ( ! function_exists( 'wc_prices_include_tax' ) || ! wc_prices_include_tax() ) {
			return $excl;
		}
		if ( $product instanceof \WC_Product && 'taxable' !== $product->get_tax_status() ) {
			return $excl;
		}
		$class = $product instanceof \WC_Product ? (string) $product->get_tax_class( 'unfiltered' ) : '';
		return $excl * ( 1 + self::base_tax_rate( $class ) );
	}

	/**
	 * Sum of the WooCommerce base-location rates of a tax class, as a fraction (21 % → 0.21). Compound rates are
	 * applied on top, like WC_Tax::calc_inclusive_tax does.
	 *
	 * @param string $tax_class Tax class ('' = standard).
	 */
	public static function base_tax_rate( string $tax_class = '' ): float {
		if ( ! class_exists( '\WC_Tax' ) || ( function_exists( 'wc_tax_enabled' ) && ! wc_tax_enabled() ) ) {
			return 0.0;
		}
		$rates    = (array) \WC_Tax::get_base_tax_rates( $tax_class );
		$regular  = 0.0;
		$compound = array();
		foreach ( $rates as $r ) {
			$pct = (float) ( is_array( $r ) ? ( $r['rate'] ?? 0 ) : 0 );
			if ( is_array( $r ) && isset( $r['compound'] ) && ( 'yes' === $r['compound'] || true === $r['compound'] ) ) {
				$compound[] = $pct / 100;
			} else {
				$regular += $pct / 100;
			}
		}
		$factor = 1 + $regular;
		foreach ( $compound as $c ) {
			$factor *= 1 + $c;
		}
		return $factor - 1;
	}

	/* ---------------------------------------------------------------------
	 * Shipping estimate from the WooCommerce zone (woo-compat-6)
	 * ------------------------------------------------------------------- */

	/**
	 * Shipping values of the WooCommerce zone that matches a Belgian address.
	 *
	 * Free shipping: min_amount when the method requires 'min_amount' or 'either'. Woo compares that threshold with
	 * the subtotal as displayed in the cart (incl. VAT when the cart shows prices incl. VAT, else excl.): our engine
	 * compares incl. VAT, so an excl. threshold is converted with the config VAT rate. Flat rate: only a purely numeric
	 * cost (Woo shipping costs are excl. VAT); a formula such as "[qty]*2" makes the zone ambiguous.
	 *
	 * @return array{readable:bool,flat:?float,freeFromInclVat:?float,hasFree:bool,reasons:string[]}
	 */
	public static function shipping_zone(): array {
		if ( null !== self::$zone ) {
			return self::$zone;
		}
		$out = array(
			'readable'        => false,
			'flat'            => null,
			'freeFromInclVat' => null,
			'hasFree'         => false,
			'reasons'         => array(),
		);
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			$out['reasons'][] = 'no_wc';
			return $out;
		}
		$country = (string) apply_filters( 'bps_shipping_estimate_country', 'BE' );
		try {
			$zone = \WC_Shipping_Zones::get_zone_matching_package(
				array(
					'destination' => array(
						'country'  => $country,
						'state'    => '',
						'postcode' => '',
						'city'     => '',
						'address'  => '',
					),
				)
			);
		} catch ( \Throwable $e ) {
			\BorduurPatchStudio\Logger::warning( 'shipping_zone_unreadable', array( 'error' => $e->getMessage() ) );
			$out['reasons'][] = 'error';
			return $out;
		}
		$methods = is_object( $zone ) && method_exists( $zone, 'get_shipping_methods' ) ? (array) $zone->get_shipping_methods( true ) : array();
		$flats   = array();
		$frees   = array();
		foreach ( $methods as $m ) {
			$id = is_object( $m ) && isset( $m->id ) ? (string) $m->id : '';
			if ( 'free_shipping' === $id ) {
				$req = (string) $m->get_option( 'requires', '' );
				if ( in_array( $req, array( 'min_amount', 'either' ), true ) ) {
					$min = $m->get_option( 'min_amount', '' );
					if ( is_numeric( $min ) ) {
						$frees[] = (float) $min;
					}
				} elseif ( '' === $req ) {
					$frees[] = 0.0;
				} else {
					$out['reasons'][] = 'free_requires_coupon';
				}
			} elseif ( 'flat_rate' === $id ) {
				$cost = trim( str_replace( ',', '.', (string) $m->get_option( 'cost', '' ) ) );
				if ( '' === $cost ) {
					$flats[] = 0.0;
				} elseif ( is_numeric( $cost ) ) {
					$flats[] = (float) $cost;
				} else {
					$out['reasons'][] = 'flat_formula';
				}
			}
		}
		if ( count( $flats ) > 1 ) {
			$out['reasons'][] = 'flat_multiple';
		}
		if ( count( $frees ) > 1 ) {
			$out['reasons'][] = 'free_multiple';
		}
		if ( ! $flats && ! in_array( 'flat_formula', $out['reasons'], true ) ) {
			$out['reasons'][] = 'flat_missing';
		}
		if ( ! $out['reasons'] ) {
			$out['readable'] = true;
			$out['flat']     = $flats[0];
			if ( $frees ) {
				$min = $frees[0];
				if ( 'excl' === (string) get_option( 'woocommerce_tax_display_cart', 'excl' ) && ( ! function_exists( 'wc_tax_enabled' ) || wc_tax_enabled() ) ) {
					$min = $min * ( 1 + (float) self::path( 'vat.rate', 0.21 ) );
				}
				$out['hasFree']         = true;
				$out['freeFromInclVat'] = round( $min, 2 );
			}
		}
		self::$zone = $out;
		return $out;
	}

	/**
	 * Live pricing config with the WooCommerce zone as override (filter bps_pricing_live_config). The plugin config
	 * stays the fallback when the zone cannot be read unambiguously.
	 *
	 * @param mixed $config Config.
	 * @return array
	 */
	public static function apply_shipping_zone( $config ): array {
		$config = is_array( $config ) ? $config : array();
		if ( false === (bool) self::path( 'shipping.useWooZone', true ) ) {
			return $config;
		}
		$zone = self::shipping_zone();
		if ( ! $zone['readable'] ) {
			return $config;
		}
		$ship                    = isset( $config['shipping'] ) && is_array( $config['shipping'] ) ? $config['shipping'] : array();
		$ship['flat']            = (float) $zone['flat'];
		$ship['freeFromInclVat'] = $zone['hasFree'] ? $zone['freeFromInclVat'] : null;
		$ship['_source']         = 'woo_zone';
		$config['shipping']      = $ship;
		return $config;
	}

	/* ---------------------------------------------------------------------
	 * Status checks (filter bps_status_checks, contract C7)
	 * ------------------------------------------------------------------- */

	/**
	 * VAT and shipping health checks for the settings status block.
	 *
	 * @param mixed $checks Existing checks.
	 * @return array
	 */
	public static function status_checks( $checks ): array {
		$checks   = is_array( $checks ) ? $checks : array();
		$checks[] = self::vat_check();
		$checks[] = self::shipping_check();
		return $checks;
	}

	/**
	 * Config vat.rate versus the WooCommerce base rate of the standard class; VAT calculation switched off.
	 */
	public static function vat_check(): array {
		$label = __( 'Btw', 'borduur-patch-studio' );
		if ( function_exists( 'wc_tax_enabled' ) && ! wc_tax_enabled() ) {
			return self::check( 'bps_vat', $label, 'warn', __( 'Btw-berekening staat uit in WooCommerce (WooCommerce > Instellingen > Algemeen). Patch Studio toont prijzen incl. btw, maar de winkelmand rekent dan zonder btw.', 'borduur-patch-studio' ) );
		}
		$config = (float) self::path( 'vat.rate', 0.21 );
		$woo    = self::base_tax_rate( '' );
		if ( abs( $config - $woo ) > 0.00001 ) {
			return self::check(
				'bps_vat',
				$label,
				'warn',
				sprintf(
					/* translators: 1: VAT rate in the Patch Studio settings, 2: WooCommerce base rate */
					__( 'Het btw-tarief in Patch Studio (%1$s %%) verschilt van het WooCommerce-basistarief van de standaardklasse (%2$s %%). De studio toont dan andere prijzen dan de winkelmand. Zet beide gelijk.', 'borduur-patch-studio' ),
					self::num( $config * 100, 2 ),
					self::num( $woo * 100, 2 )
				)
			);
		}
		return self::check(
			'bps_vat',
			$label,
			'ok',
			function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax()
				? __( 'Btw-tarief klopt met WooCommerce. Je winkel voert prijzen in incl. btw: Patch Studio houdt daar rekening mee.', 'borduur-patch-studio' )
				: __( 'Btw-tarief klopt met WooCommerce.', 'borduur-patch-studio' )
		);
	}

	/**
	 * Shipping estimate: read from the Belgian WooCommerce zone, or a warning when we fall back to the plugin config.
	 */
	public static function shipping_check(): array {
		$label = __( 'Verzendschatting', 'borduur-patch-studio' );
		$zone  = self::shipping_zone();
		if ( $zone['readable'] ) {
			$msg = $zone['hasFree']
				? sprintf(
					/* translators: 1: flat shipping cost excl. VAT, 2: free shipping threshold incl. VAT */
					__( 'De studio volgt je WooCommerce-verzendzone voor België: %1$s excl. btw, gratis vanaf %2$s incl. btw.', 'borduur-patch-studio' ),
					self::money( (float) $zone['flat'] ),
					self::money( (float) $zone['freeFromInclVat'] )
				)
				: sprintf(
					/* translators: %s: flat shipping cost excl. VAT */
					__( 'De studio volgt je WooCommerce-verzendzone voor België: %s excl. btw, geen gratis verzending.', 'borduur-patch-studio' ),
					self::money( (float) $zone['flat'] )
				);
			return self::check( 'bps_shipping_zone', $label, 'ok', $msg );
		}
		return self::check( 'bps_shipping_zone', $label, 'warn', __( 'We konden de WooCommerce-verzendzone voor België niet eenduidig lezen (bv. een formule als [qty]*2, meerdere verzendmethodes of geen vast tarief). De studio gebruikt daarom de verzendkosten uit de Patch Studio-instellingen: controleer dat die gelijk zijn aan je verzendzone.', 'borduur-patch-studio' ) );
	}

	/**
	 * One status row in the C7 format.
	 *
	 * @param string $id      Id.
	 * @param string $label   Label.
	 * @param string $status  ok | warn | error.
	 * @param string $message Message.
	 */
	private static function check( string $id, string $label, string $status, string $message ): array {
		return array(
			'id'      => $id,
			'label'   => $label,
			'status'  => $status,
			'ok'      => 'ok' === $status,
			'message' => $message,
			'detail'  => $message,
		);
	}

	/**
	 * Read a (possibly not yet existing) setting with a fallback: keys of §4 in PLAN.md arrive with P08.
	 *
	 * @param string $dot     Path.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function setting( string $dot, $default = null ) {
		$v = self::path( $dot, null );
		return null === $v ? $default : $v;
	}
}
