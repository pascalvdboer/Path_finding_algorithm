<?php
/**
 * Cross-sell in WordPress: ontwerp ophalen, mandcontext, kaarten bouwen met de echte WooCommerce-prijzen
 * en de echte patchprijs-engine (Pricing::cart_unit_excl). Eén plek die REST, mand en mandregel gebruiken.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Crosssell;

use BorduurPatchStudio\Pricing as MainPricing;
use BorduurPatchStudio\Woo\Designs;

defined( 'ABSPATH' ) || exit;

/**
 * Kaarten voor een ontwerp.
 */
final class Service {

	/** @var array<string,array> Kaarten per ontwerp en taal binnen één verzoek. */
	private static $memo = array();

	/**
	 * Opgeslagen ontwerp (DesignRecord) of null.
	 *
	 * @param int $id Ontwerp-ID.
	 */
	public static function design( int $id ): ?array {
		if ( $id <= 0 || ! class_exists( Designs::class ) ) {
			return null;
		}
		$rec = Designs::get( $id );
		return $rec && ! empty( $rec['payload'] ) ? $rec : null;
	}

	/**
	 * Klopt het ontwerp-token?
	 *
	 * @param int    $id    Ontwerp-ID.
	 * @param string $token Token.
	 */
	public static function token_ok( int $id, string $token ): bool {
		return class_exists( Designs::class ) && Designs::verify_token( $id, $token );
	}

	/**
	 * Mandregels van de huidige sessie (leeg zonder mand).
	 */
	public static function cart_items(): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return array();
		}
		return (array) WC()->cart->get_cart();
	}

	/**
	 * Staat dit ontwerp (als Patch Studio-regel) in de eigen mand?
	 *
	 * @param int $id Ontwerp-ID.
	 */
	public static function in_cart( int $id ): bool {
		foreach ( self::cart_items() as $it ) {
			if ( (int) ( $it['bps']['design_id'] ?? 0 ) === $id || (int) ( $it['bps_cs']['design_id'] ?? 0 ) === $id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fee-groepen die een oud WooCommerce-product al aanrekent (zelfde regel als Woo\Cart: Conditional Extra Fees).
	 *
	 * @param array $items Mandregels.
	 * @param array $main  Hoofdconfig.
	 * @return string[]
	 */
	public static function legacy_groups( array $items, array $main ): array {
		if ( isset( $main['woo']['skipFeeIfLegacyInCart'] ) && ! $main['woo']['skipFeeIfLegacyInCart'] ) {
			return array();
		}
		$legacy = array();
		foreach ( (array) ( $main['techniques'] ?? array() ) as $t ) {
			if ( ! empty( $t['legacy']['productId'] ) && ! empty( $t['feeGroup'] ) ) {
				$legacy[ (int) $t['legacy']['productId'] ] = (string) $t['feeGroup'];
			}
		}
		$out = array();
		foreach ( $items as $it ) {
			$pid = (int) ( $it['product_id'] ?? 0 );
			if ( empty( $it['bps'] ) && empty( $it['bps_cs'] ) && isset( $legacy[ $pid ] ) ) {
				$out[] = $legacy[ $pid ];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Patchprijs uit de hoofdprijsengine: zelfde bron als de gewone patchregels.
	 */
	public static function patch_unit(): callable {
		return static function ( $technique, $size_cm, $options, $qty, $pool ) {
			if ( ! class_exists( MainPricing::class ) ) {
				return 0.0;
			}
			try {
				return (float) MainPricing::cart_unit_excl(
					array(
						'technique' => (string) $technique,
						'size_cm'   => (float) $size_cm,
						'options'   => array_values( (array) $options ),
					),
					max( 1, (int) $qty ),
					max( 0, (int) $pool )
				);
			} catch ( \Throwable $e ) {
				return 0.0;
			}
		};
	}

	/**
	 * Kaarten voor een ontwerp, met de mand van deze sessie als context.
	 *
	 * @param array  $rec    DesignRecord.
	 * @param string $locale Locale.
	 * @param bool   $all    Alle passende items (niet beperkt tot maxCards): voor de controle bij toevoegen.
	 */
	public static function offers( array $rec, string $locale, bool $all = false ): array {
		$memo_key = (int) $rec['id'] . '|' . $locale . '|' . ( $all ? 1 : 0 );
		if ( isset( self::$memo[ $memo_key ] ) ) {
			return self::$memo[ $memo_key ];
		}
		$cfg = Config::get();
		if ( $all ) {
			$cfg['maxCards'] = 99;
		}
		$main  = Config::main();
		$items = self::cart_items();
		$cart  = Lines::context( $items, (int) $rec['id'], $main, self::legacy_groups( $items, $main ) );
		$env   = array(
			'cfg'        => $cfg,
			'main'       => $main,
			'catalog'    => Products::catalog( Products::configured_ids( $cfg ), $cfg ),
			'cart'       => $cart,
			'patch_unit' => self::patch_unit(),
			'locale'     => $locale,
			'month'      => (int) ( function_exists( 'wp_date' ) ? wp_date( 'n' ) : gmdate( 'n' ) ),
			'stats'      => Stats::flags( $cfg ),
			'overrides'  => Config::strings(),
			'mode'       => 'patch',
		);
		$offers = Offers::build(
			array(
				'payload'    => (array) $rec['payload'],
				'previewUrl' => (string) ( $rec['preview_url'] ?? '' ),
				'designId'   => (int) $rec['id'],
				'ref'        => (string) ( $rec['ref'] ?? '' ),
			),
			$env
		);
		/**
		 * Kaarten aanpassen (volgorde nooit op marge, SPEC-EXTRA §2.1).
		 *
		 * @param array $cards   Kaarten.
		 * @param array $rec     DesignRecord.
		 * @param array $context Mandcontext.
		 */
		$cards           = apply_filters( 'bps_crosssell_cards', $offers['cards'], $rec, $cart );
		$offers['cards'] = is_array( $cards ) ? array_values( $cards ) : array();
		self::$memo[ $memo_key ] = $offers;
		return $offers;
	}

	/**
	 * Cache leegmaken (na een mandwijziging binnen hetzelfde verzoek).
	 */
	public static function flush(): void {
		self::$memo = array();
	}

	/**
	 * Kaart van één item (uit alle passende items) of null.
	 *
	 * @param array  $rec    DesignRecord.
	 * @param string $item   Item-id.
	 * @param string $locale Locale.
	 */
	public static function card( array $rec, string $item, string $locale ): ?array {
		foreach ( self::offers( $rec, $locale, true )['cards'] as $c ) {
			if ( ( $c['id'] ?? null ) === $item ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Publieke vorm van het antwoord (zonder interne velden die de UI niet nodig heeft).
	 *
	 * @param array $offers Offers::build().
	 */
	public static function public_offers( array $offers ): array {
		unset( $offers['rule'] );
		return $offers;
	}
}
