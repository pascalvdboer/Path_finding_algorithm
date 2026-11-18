<?php
/**
 * Cross-sell producten: catalogus uit WooCommerce (blanco's en laser/kader/PVC-producten), status per item en
 * "Maak ontbrekende producten aan" (verborgen simple-producten met meta _bps_cs_product).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Crosssell;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce-producten.
 */
final class Products {

	const META = '_bps_cs_product';

	/** @var array<int,array|null> */
	private static $cache = array();

	/**
	 * Alle product-ID's die de config gebruikt.
	 *
	 * @param array|null $cfg Config.
	 * @return int[]
	 */
	public static function configured_ids( ?array $cfg = null ): array {
		$cfg = $cfg ?? Config::get();
		$ids = array();
		foreach ( (array) ( $cfg['items'] ?? array() ) as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			foreach ( (array) ( $it['wcProductIds'] ?? array() ) as $id ) {
				$ids[] = (int) $id;
			}
			$ids[] = (int) ( $it['defaultProductId'] ?? 0 );
			foreach ( (array) ( $it['sizes'] ?? array() ) as $s ) {
				$ids[] = (int) ( is_array( $s ) ? ( $s['productId'] ?? 0 ) : 0 );
			}
		}
		return array_values( array_unique( array_filter( $ids, static fn( $i ) => $i > 0 ) ) );
	}

	/**
	 * Catalogus in het formaat van Offers::build(): id → {id, name, priceExcl, purchasable, variations[]}.
	 *
	 * @param int[]      $ids Product-ID's.
	 * @param array|null $cfg Config.
	 */
	public static function catalog( array $ids, ?array $cfg = null ): array {
		$cfg = $cfg ?? Config::get();
		$out = array();
		foreach ( $ids as $id ) {
			$p = self::product( (int) $id, $cfg );
			if ( $p ) {
				$out[ (int) $id ] = $p;
			}
		}
		return $out;
	}

	/**
	 * Eén catalogusproduct (null als het niet bestaat of niet gepubliceerd is).
	 *
	 * @param int   $id  Product-ID.
	 * @param array $cfg Config.
	 */
	public static function product( int $id, array $cfg ): ?array {
		if ( array_key_exists( $id, self::$cache ) ) {
			return self::$cache[ $id ];
		}
		self::$cache[ $id ] = null;
		if ( $id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $id );
		if ( ! $product || ! in_array( $product->get_status(), array( 'publish', 'private' ), true ) ) {
			return null;
		}
		$entry = array(
			'id'          => $id,
			'name'        => wp_strip_all_tags( $product->get_name() ),
			'priceExcl'   => self::price_excl( $product ),
			'purchasable' => $product->is_purchasable() && $product->is_in_stock(),
			'variations'  => array(),
		);
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v || 'publish' !== $v->get_status() ) {
					continue;
				}
				$attrs                 = (array) $v->get_attributes();
				$color_key             = self::attribute_key( $attrs, (string) ( $cfg['colorAttribute'] ?? '' ), (array) ( $cfg['colorAttributeCandidates'] ?? array() ) );
				$size_key              = self::attribute_key( $attrs, (string) ( $cfg['sizeAttribute'] ?? '' ), (array) ( $cfg['sizeAttributeCandidates'] ?? array() ) );
				$color                 = null !== $color_key ? (string) $attrs[ $color_key ] : '';
				$size                  = null !== $size_key ? (string) $attrs[ $size_key ] : '';
				$entry['variations'][] = array(
					'id'          => (int) $vid,
					'colorSlug'   => sanitize_title( $color ),
					'colorName'   => self::term_name( (string) $color_key, $color ),
					'size'        => '' !== $size ? self::term_name( (string) $size_key, $size ) : null,
					'priceExcl'   => self::price_excl( $v ),
					'inStock'     => $v->is_in_stock(),
					'purchasable' => $v->is_purchasable(),
					'attributes'  => $attrs,
				);
			}
		}
		self::$cache[ $id ] = $entry;
		return $entry;
	}

	/**
	 * Prijs excl. btw zoals de shop hem toont (zonder onze set_price: verse productinstantie).
	 *
	 * @param \WC_Product $p Product.
	 */
	public static function price_excl( $p ): float {
		if ( ! $p instanceof \WC_Product ) {
			return 0.0;
		}
		$price = $p->get_price( 'edit' );
		if ( '' === $price || null === $price ) {
			return 0.0;
		}
		return (float) wc_get_price_excluding_tax(
			$p,
			array(
				'qty'   => 1,
				'price' => (float) $price,
			)
		);
	}

	/**
	 * Verse blanco-prijs excl. btw van een product/variatie (voor de mand; los van gewijzigde instanties).
	 *
	 * @param int $id Product- of variatie-ID.
	 */
	public static function fresh_price_excl( int $id ): float {
		static $prices = array();
		if ( ! isset( $prices[ $id ] ) ) {
			$p             = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			$prices[ $id ] = $p ? self::price_excl( $p ) : 0.0;
		}
		return $prices[ $id ];
	}

	/**
	 * Attribuutsleutel kiezen: ingesteld, anders de eerste kandidaat die bestaat.
	 *
	 * @param array    $attrs      Variatie-attributen (sleutel zonder 'attribute_').
	 * @param string   $configured Ingesteld attribuut.
	 * @param string[] $candidates Kandidaten.
	 */
	private static function attribute_key( array $attrs, string $configured, array $candidates ): ?string {
		$list = '' !== $configured ? array( $configured ) : $candidates;
		foreach ( $list as $c ) {
			$c = strtolower( (string) $c );
			foreach ( array_keys( $attrs ) as $k ) {
				if ( strtolower( (string) $k ) === $c || strtolower( (string) $k ) === 'pa_' . $c ) {
					return (string) $k;
				}
			}
		}
		return null;
	}

	/**
	 * Leesbare naam van een attribuutwaarde.
	 *
	 * @param string $taxonomy Attribuut (pa_* = taxonomie).
	 * @param string $value    Slug of tekst.
	 */
	private static function term_name( string $taxonomy, string $value ): string {
		if ( str_starts_with( $taxonomy, 'pa_' ) && taxonomy_exists( $taxonomy ) ) {
			$term = get_term_by( 'slug', $value, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				return (string) $term->name;
			}
		}
		return $value;
	}

	/**
	 * Is dit een door de cross-sell aangemaakt product (laser/kader/PVC)?
	 *
	 * @param int $id Product-ID.
	 */
	public static function is_ours( int $id ): bool {
		return $id > 0 && '' !== (string) get_post_meta( $id, self::META, true );
	}

	/**
	 * Status per item voor de beheerpagina: ok | product_missing | no_purchasable.
	 *
	 * @param array|null $cfg Config.
	 * @return array<string,string>
	 */
	public static function status( ?array $cfg = null ): array {
		$cfg = $cfg ?? Config::get();
		$out = array();
		foreach ( (array) ( $cfg['items'] ?? array() ) as $it ) {
			if ( ! is_array( $it ) || empty( $it['id'] ) ) {
				continue;
			}
			$ids = array();
			if ( 'frame' === ( $it['type'] ?? '' ) ) {
				foreach ( (array) ( $it['sizes'] ?? array() ) as $s ) {
					$ids[] = (int) ( $s['productId'] ?? 0 );
				}
			} else {
				$ids = Offers::item_product_ids( $it, null );
			}
			$ids = array_filter( $ids );
			if ( ! $ids ) {
				$out[ $it['id'] ] = 'product_missing';
				continue;
			}
			$any_exists = false;
			$any_ok     = false;
			foreach ( $ids as $id ) {
				$p = self::product( (int) $id, $cfg );
				if ( $p ) {
					$any_exists = true;
					if ( Offers::product_available( $p ) ) {
						$any_ok = true;
					}
				}
			}
			$out[ $it['id'] ] = $any_ok ? 'ok' : ( $any_exists ? 'no_purchasable' : 'product_missing' );
		}
		return $out;
	}

	/**
	 * Maak ontbrekende verborgen producten aan (laser, kader, PVC) en koppel ze in de instellingen. Idempotent.
	 *
	 * @return array<string,int> sleutel → product-ID
	 */
	public static function create_missing(): array {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return array();
		}
		$cfg     = Config::get();
		$created = array();
		foreach ( $cfg['items'] as &$it ) {
			$type = (string) ( $it['type'] ?? '' );
			if ( 'frame' === $type ) {
				foreach ( $it['sizes'] as &$s ) {
					if ( (int) ( $s['productId'] ?? 0 ) > 0 && self::product( (int) $s['productId'], $cfg ) ) {
						continue;
					}
					$key            = 'frame_' . $s['id'];
					$s['productId'] = self::ensure_product( $key, Pricing::lbl( $s['label'] ?? $key, 'nl' ), (float) ( $s['createPriceExcl'] ?? 0 ) );
					$created[ $key ] = $s['productId'];
				}
				unset( $s );
			} elseif ( in_array( $type, array( 'laser', 'pvc' ), true ) ) {
				$ids = Offers::item_product_ids( $it, null );
				if ( $ids && self::product( (int) $ids[0], $cfg ) ) {
					continue;
				}
				$id                     = self::ensure_product( (string) $it['id'], Pricing::lbl( $it['label'] ?? $it['id'], 'nl' ), (float) ( $it['createPriceExcl'] ?? 0 ) );
				$it['wcProductIds']     = $id > 0 ? array( $id ) : array();
				$it['defaultProductId'] = $id;
				$created[ $it['id'] ]   = $id;
			}
		}
		unset( $it );
		if ( $created ) {
			self::$cache = array();
			Config::save( $cfg );
		}
		return $created;
	}

	/**
	 * Bestaand product met deze sleutel teruggeven of een nieuw verborgen simple-product maken.
	 *
	 * @param string $key        _bps_cs_product-waarde.
	 * @param string $name       Productnaam.
	 * @param float  $price_excl Prijs excl. btw.
	 */
	private static function ensure_product( string $key, string $name, float $price_excl ): int {
		$found = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( $found ) {
			return (int) $found[0];
		}
		$price = function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ? Pricing::round_half_up( $price_excl * 1.21, 2 ) : $price_excl;
		$p     = new \WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( 'publish' );
		$p->set_catalog_visibility( 'hidden' );
		$p->set_regular_price( wc_format_decimal( $price, 2 ) );
		$p->set_tax_status( 'taxable' );
		$p->set_virtual( false );
		$p->set_sold_individually( false );
		$p->set_manage_stock( false );
		$p->set_stock_status( 'instock' );
		$p->set_short_description( __( 'Wordt enkel samen met een eigen Patch Studio-ontwerp verkocht.', 'borduur-patch-studio' ) );
		$p->update_meta_data( self::META, $key );
		return (int) $p->save();
	}
}
