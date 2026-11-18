<?php
/**
 * Cross-sell kaarten opbouwen (SPEC-EXTRA §2.1–2.6). PHP-tweeling van assets/src/crosssell/offers.js.
 *
 * Puur: de WooCommerce-gegevens komen als "catalogus" binnen (Products::catalog()), de patchprijs via een callable.
 * Zo kan run-php.php exact dezelfde kaarten opbouwen als de JS-tweeling.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Crosssell;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BPS_TESTING' ) ) {
	exit;
}

/**
 * Kaarten.
 */
final class Offers {

	/**
	 * Product koopbaar? Variabel: minstens één koopbare variatie met voorraad.
	 *
	 * @param mixed $p Catalogusproduct.
	 */
	public static function product_available( $p ): bool {
		if ( ! is_array( $p ) || ( array_key_exists( 'purchasable', $p ) && false === $p['purchasable'] ) ) {
			return false;
		}
		if ( ! empty( $p['variations'] ) && is_array( $p['variations'] ) ) {
			foreach ( $p['variations'] as $v ) {
				if ( is_array( $v ) && ( ! array_key_exists( 'inStock', $v ) || false !== $v['inStock'] ) && ( ! array_key_exists( 'purchasable', $v ) || false !== $v['purchasable'] ) ) {
					return true;
				}
			}
			return false;
		}
		return Fit::num( $p['priceExcl'] ?? null ) > 0;
	}

	/**
	 * Product-ID's van een item (frame: de maat die de pastoets koos).
	 *
	 * @param array      $item Item.
	 * @param array|null $fit  Fit-resultaat.
	 * @return int[]
	 */
	public static function item_product_ids( array $item, ?array $fit ): array {
		if ( 'frame' === ( $item['type'] ?? '' ) ) {
			foreach ( ( isset( $item['sizes'] ) && is_array( $item['sizes'] ) ? $item['sizes'] : array() ) as $s ) {
				if ( is_array( $s ) && (string) ( $s['id'] ?? '' ) === (string) ( $fit['frameSize'] ?? '' ) ) {
					$pid = (int) Fit::num( $s['productId'] ?? null );
					return $pid > 0 ? array( $pid ) : array();
				}
			}
			return array();
		}
		$ids = array();
		foreach ( ( isset( $item['wcProductIds'] ) && is_array( $item['wcProductIds'] ) ? $item['wcProductIds'] : array() ) as $id ) {
			$id = (int) Fit::num( $id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		if ( ! $ids && Fit::num( $item['defaultProductId'] ?? null ) > 0 ) {
			$ids[] = (int) Fit::num( $item['defaultProductId'] );
		}
		return $ids;
	}

	/**
	 * Pricing-context voor het paneel.
	 *
	 * @param array  $cfg       Cross-sell-config.
	 * @param array  $main      Hoofdconfig.
	 * @param string $technique Techniek van het ontwerp.
	 */
	public static function pricing_context( array $cfg, array $main, string $technique ): array {
		$techs = array();
		foreach ( array( $technique, 'pvc' ) as $id ) {
			$tc = $main['techniques'][ $id ] ?? null;
			if ( is_array( $tc ) ) {
				$techs[ $id ] = array(
					'tiers'  => ! empty( $tc['tiers'] ),
					'minQty' => ! empty( $tc['minQty'] ) ? $tc['minQty'] : 1,
					'mode'   => $tc['mode'] ?? null,
				);
			}
		}
		return array(
			'vat'         => array( 'rate' => Fit::num( $main['vat']['rate'] ?? null ) ),
			'tiers'       => $main['tiers'] ?? array(
				'pooled' => true,
				'steps'  => array(),
			),
			'techniques'  => (object) $techs,
			'application' => isset( $cfg['application'] ) && is_array( $cfg['application'] ) ? $cfg['application'] : array(),
		);
	}

	/**
	 * Getal als tekst zoals de JS cmText(): 6 → "6", 7.5 → "7,5".
	 *
	 * @param mixed $n Getal.
	 */
	public static function cm_text( $n ): string {
		$v = round( Fit::num( $n ) * 100 ) / 100;
		$s = rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' );
		return str_replace( '.', ',', '' === $s ? '0' : $s );
	}

	/**
	 * Prijs van een kaart voor een selectie (twin van cardPrice()).
	 *
	 * @param array  $card    Kaart.
	 * @param array  $sel     variationId, productId, qty, options, placement.
	 * @param array  $pricing pricing_context().
	 * @param string $locale  Locale.
	 */
	public static function card_price( array $card, array $sel, array $pricing, string $locale = 'nl-BE' ): array {
		$p    = $card['price'] ?? array();
		$v    = null;
		$prod = null;
		foreach ( $card['variations'] ?? array() as $x ) {
			if ( isset( $sel['variationId'] ) && $x['id'] === $sel['variationId'] ) {
				$v = $x;
				break;
			}
		}
		$want = ! empty( $sel['productId'] ) ? $sel['productId'] : ( $v ? $v['productId'] : $card['defaultProductId'] );
		foreach ( $card['products'] ?? array() as $x ) {
			if ( $x['id'] === $want ) {
				$prod = $x;
				break;
			}
		}
		$placement = ! empty( $sel['placement'] ) ? $sel['placement'] : $card['placement'];
		$use_alt   = ! empty( $card['alt'] ) && $placement === $card['alt']['placement'];
		$ladder    = $use_alt ? ( $p['altPatchLadder'] ?? null ?: $p['patchLadder'] ) : $p['patchLadder'];
		$main      = $pricing;
		if ( is_object( $main['techniques'] ) ) {
			$main['techniques'] = (array) $main['techniques'];
		}
		return Pricing::item(
			$card['item'],
			array(
				'qty'           => $sel['qty'] ?? 0,
				'poolQty'       => $p['pool'] ?? 0,
				'blankExcl'     => $v ? $v['priceExcl'] : ( $prod ? $prod['priceExcl'] : 0 ),
				'productExcl'   => $prod ? $prod['priceExcl'] : ( $p['productExcl'] ?? 0 ),
				'options'       => $sel['options'] ?? array(),
				'placement'     => $placement,
				'technique'     => $p['technique'] ?? '',
				'patchSizeCm'   => $use_alt ? $card['alt']['patchSizeCm'] : $card['patchSizeCm'],
				'designOptions' => array(),
				'moldCovered'   => ! empty( $p['moldCovered'] ),
				'moldCharged'   => ! empty( $p['moldCharged'] ),
			),
			array(
				'cfg'        => array( 'application' => $pricing['application'] ),
				'main'       => $main,
				'patch_unit' => self::ladder_unit( is_array( $ladder ) ? $ladder : array() ),
				'locale'     => $locale,
			)
		);
	}

	/**
	 * Patchprijs uit een ladder.
	 *
	 * @param array $ladder [{qty, excl}].
	 */
	public static function ladder_unit( array $ladder ): callable {
		usort( $ladder, static fn( $a, $b ) => Fit::num( $a['qty'] ?? null ) <=> Fit::num( $b['qty'] ?? null ) );
		return static function ( $t, $s, $o, $qty ) use ( $ladder ) {
			$v = 0.0;
			foreach ( $ladder as $r ) {
				if ( Fit::num( $r['qty'] ?? null ) <= $qty ) {
					$v = Fit::num( $r['excl'] ?? null );
				}
			}
			return $v;
		};
	}

	/**
	 * Bouw de offers (twin van buildOffers()).
	 *
	 * @param array $design ['payload','previewUrl','designId','ref'].
	 * @param array $env    cfg, main, catalog, cart, patch_unit, locale, month, stats, overrides, mode.
	 */
	public static function build( array $design, array $env ): array {
		$cfg     = $env['cfg'];
		$main    = $env['main'];
		$catalog = isset( $env['catalog'] ) && is_array( $env['catalog'] ) ? $env['catalog'] : array();
		$locale  = (string) ( ( $env['locale'] ?? '' ) ?: 'nl-BE' );
		$l       = Messages::lang( $locale );
		$ov      = isset( $env['overrides'] ) && is_array( $env['overrides'] ) ? $env['overrides'] : array();
		$cart    = array_merge(
			array(
				'qtyOfDesign'    => null,
				'poolQty'        => 0,
				'feeGroups'      => array(),
				'pvcMoldCovered' => false,
				'pvcMoldCharged' => false,
			),
			isset( $env['cart'] ) && is_array( $env['cart'] ) ? $env['cart'] : array()
		);
		$d        = Fit::design_from_payload( $design['payload'] ?? array() );
		$cart_qty = null === $cart['qtyOfDesign'] ? $d['qty'] : (int) $cart['qtyOfDesign'];
		$tech     = isset( $main['techniques'][ $d['technique'] ] ) && is_array( $main['techniques'][ $d['technique'] ] ) ? $main['techniques'][ $d['technique'] ] : null;
		$pooled   = ! empty( $main['tiers']['pooled'] ) && $tech && ! empty( $tech['tiers'] );
		$month    = ! empty( $env['month'] ) ? (int) $env['month'] : (int) gmdate( 'n' );

		$fits     = array();
		$products = array();
		foreach ( ( isset( $cfg['items'] ) && is_array( $cfg['items'] ) ? $cfg['items'] : array() ) as $item ) {
			if ( ! is_array( $item ) || ( array_key_exists( 'enabled', $item ) && false === $item['enabled'] ) ) {
				continue;
			}
			$f = Fit::item( $item, $d, array( 'tech' => $tech, 'cartQty' => $cart_qty ) );
			if ( 'hide' === $f['fit'] ) {
				continue;
			}
			$ids = array_values( array_filter( self::item_product_ids( $item, $f ), static fn( $id ) => self::product_available( $catalog[ $id ] ?? null ) ) );
			if ( ! $ids ) {
				continue;
			}
			$fits[ $item['id'] ]     = $f;
			$products[ $item['id'] ] = $ids;
		}
		$chosen  = Rules::pick(
			$fits,
			array(
				'qty'       => $cart_qty,
				'technique' => $d['technique'],
				'kind'      => $d['kind'],
				'month'     => $month,
				'mode'      => (string) ( $env['mode'] ?? 'patch' ),
			),
			$cfg,
			isset( $env['stats'] ) && is_array( $env['stats'] ) ? $env['stats'] : array()
		);
		$pricing = self::pricing_context( $cfg, $main, $d['technique'] );
		$cards   = array();
		foreach ( $chosen['ids'] as $id ) {
			$item = null;
			foreach ( $cfg['items'] as $i ) {
				if ( is_array( $i ) && ( $i['id'] ?? null ) === $id ) {
					$item = $i;
					break;
				}
			}
			$card = self::card( $item, $fits[ $id ], $products[ $id ], compact( 'd', 'cfg', 'main', 'catalog', 'cart', 'cart_qty', 'pooled', 'pricing', 'locale', 'l', 'ov' ) + array( 'patch_unit' => $env['patch_unit'] ?? null ) );
			if ( $card ) {
				$cards[] = $card;
			}
		}
		$fee_group = $tech && ! empty( $tech['feeGroup'] ) ? (string) $tech['feeGroup'] : '';
		$has_patch = false;
		foreach ( $cards as $c ) {
			if ( in_array( $c['item']['type'], array( 'patch_on_blank', 'frame' ), true ) ) {
				$has_patch = true;
			}
		}
		$fee_note = 'order' === ( $main['fees']['scope'] ?? null ) && '' !== $fee_group && in_array( $fee_group, (array) $cart['feeGroups'], true ) && $has_patch
			? Messages::t( 'cs.feeNote', $l, array(), $ov ) : null;
		return array(
			'added'         => Messages::t( 'cs.added', $l, array(), $ov ),
			'heading'       => Messages::t( 'cs.heading', $l, array(), $ov ),
			'feeNote'       => $fee_note,
			'previewUrl'    => (string) ( $design['previewUrl'] ?? '' ),
			'designId'      => (int) ( $design['designId'] ?? 0 ),
			'ref'           => (string) ( $design['ref'] ?? '' ),
			'rule'          => $chosen['rule'],
			'pricing'       => $pricing,
			'edgeHex'       => $d['edgeHex'],
			'defaultColors' => isset( $cfg['defaultColors'] ) && is_array( $cfg['defaultColors'] ) ? $cfg['defaultColors'] : array(),
			'neutralHex'    => (string) ( $cfg['neutralHex'] ?? '#8a8d91' ),
			'cards'         => $cards,
		);
	}

	/**
	 * Eén kaart (twin van buildCard()).
	 *
	 * @param array $item        Item.
	 * @param array $fit         Fit.
	 * @param int[] $product_ids Koopbare producten.
	 * @param array $x           Context.
	 */
	private static function card( array $item, array $fit, array $product_ids, array $x ): ?array {
		$d        = $x['d'];
		$cfg      = $x['cfg'];
		$main     = $x['main'];
		$catalog  = $x['catalog'];
		$cart     = $x['cart'];
		$l        = $x['l'];
		$ov       = $x['ov'];
		$type     = (string) ( $item['type'] ?? '' );
		$products = array();
		foreach ( $product_ids as $id ) {
			$products[] = array(
				'id'        => $id,
				'name'      => (string) ( $catalog[ $id ]['name'] ?? '' ),
				'priceExcl' => Fit::num( $catalog[ $id ]['priceExcl'] ?? null ),
			);
		}
		$def_pid    = in_array( (int) Fit::num( $item['defaultProductId'] ?? null ), $product_ids, true ) ? (int) Fit::num( $item['defaultProductId'] ) : $product_ids[0];
		$color_map  = isset( $cfg['colorMap'] ) && is_array( $cfg['colorMap'] ) ? $cfg['colorMap'] : array();
		$variations = array();
		foreach ( $product_ids as $pid ) {
			foreach ( ( isset( $catalog[ $pid ]['variations'] ) && is_array( $catalog[ $pid ]['variations'] ) ? $catalog[ $pid ]['variations'] : array() ) as $v ) {
				if ( ! is_array( $v ) || ( array_key_exists( 'purchasable', $v ) && false === $v['purchasable'] ) ) {
					continue;
				}
				$slug         = (string) ( $v['colorSlug'] ?? '' );
				$variations[] = array(
					'id'        => (int) Fit::num( $v['id'] ?? null ),
					'productId' => $pid,
					'colorSlug' => $slug,
					'colorName' => (string) ( ( $v['colorName'] ?? '' ) ?: $slug ),
					'colorHex'  => isset( $color_map[ $slug ] ) && '' !== $color_map[ $slug ] ? (string) $color_map[ $slug ] : null,
					'size'      => ! empty( $v['size'] ) ? (string) $v['size'] : null,
					'inStock'   => ! array_key_exists( 'inStock', $v ) || false !== $v['inStock'],
					'priceExcl' => Fit::num( $v['priceExcl'] ?? null ),
				);
			}
		}
		$own     = array_values( array_filter( $variations, static fn( $v ) => $v['productId'] === $def_pid ) );
		$def_vid = $own ? Fit::pick_default_variation( $own, $d['edgeHex'], isset( $cfg['defaultColors'] ) && is_array( $cfg['defaultColors'] ) ? $cfg['defaultColors'] : array() ) : null;
		$qty     = Fit::default_qty( $item, $x['cart_qty'] );
		$uses    = 'patch_on_blank' === $type || 'frame' === $type;
		$pool    = $uses && $x['pooled'] ? max( 0, (int) Fit::num( $cart['poolQty'] ) ) : 0;
		$opts    = Pricing::patch_options( $d['options'], $main, (string) ( $cfg['application']['forceBacking'] ?? 'sew_on' ) );
		$pu      = $x['patch_unit'];
		$ladder  = static function ( $size_cm ) use ( $uses, $main, $pool, $x, $d, $opts, $pu ): array {
			if ( ! $uses || ! is_callable( $pu ) ) {
				return array();
			}
			$out = array();
			foreach ( Pricing::ladder_points( $main, $pool, (bool) $x['pooled'] ) as $q ) {
				$out[] = array(
					'qty'  => $q,
					'excl' => Fit::num( call_user_func( $pu, $d['technique'], Fit::num( $size_cm ), $opts, $q, $pool ) ),
				);
			}
			return $out;
		};
		$shared   = 'pvc' === $type && true === ( $item['sharedMold'] ?? false ) && ! empty( $cart['pvcMoldCovered'] );
		$size_cfg = null;
		if ( 'frame' === $type ) {
			foreach ( $item['sizes'] ?? array() as $s ) {
				if ( (string) ( $s['id'] ?? '' ) === (string) $fit['frameSize'] ) {
					$size_cfg = $s;
				}
			}
		}
		$plate = isset( $item['plate'] ) && is_array( $item['plate'] ) ? $item['plate'] : null;
		$card  = array(
			'id'                 => (string) $item['id'],
			'kind'               => $type,
			'title'              => $size_cfg ? Pricing::lbl( $size_cfg['label'] ?? null, $l ) : Pricing::lbl( $item['label'] ?? null, $l ),
			'why'                => Pricing::lbl( $item['why'] ?? null, $l ),
			'fit'                => $fit['fit'],
			'adjustNote'         => self::adjust_note( $item, $fit, $l, $ov ),
			'patchSizeCm'        => $fit['patchSizeCm'],
			'placement'          => $fit['placement'],
			'alt'                => $fit['alt'],
			'frameSize'          => $fit['frameSize'],
			'minQty'             => 'pvc' === $type ? Fit::num( $item['minQty'] ?? null, 1 ) : 1,
			'qtyStep'            => 'pvc' === $type ? Fit::num( $item['qtyStep'] ?? null, 1 ) : 1,
			'defaultQty'         => $qty,
			'needsSize'          => ! empty( $item['requiresSize'] ),
			'needsPlate'         => 'frame' === $type,
			'plate'              => 'frame' === $type ? array(
				'lines'    => Fit::num( $plate['lines'] ?? null, 2 ),
				'maxChars' => Fit::num( $plate['maxChars'] ?? null, 28 ),
			) : null,
			'options'            => array(),
			'products'           => $products,
			'variations'         => $variations,
			'defaultProductId'   => $def_pid,
			'defaultVariationId' => $def_vid,
			'mockup'             => array(
				'kind'         => self::mockup_kind( $item, $def_pid, $fit['placement'] ),
				'placement'    => $fit['placement'],
				'scaleRefMm'   => Fit::num( $size_cfg ? ( $size_cfg['scaleRefMm'] ?? null ) : ( $item['scaleRefMm'] ?? null ), 230 ),
				'mono'         => 'laser' === $type || 'laser_patch_on_blank' === $type || ! empty( $item['mono'] ),
				'engraveHex'   => (string) ( ( $cfg['engraveHex'] ?? '' ) ?: '#5a3a22' ),
				'plateHex'     => $plate ? ( $plate['hex'] ?? null ) : null,
				'plateTextHex' => $plate ? ( $plate['textHex'] ?? null ) : null,
				'byProduct'    => (object) ( isset( $item['mockupByProduct'] ) && is_array( $item['mockupByProduct'] ) ? $item['mockupByProduct'] : array() ),
			),
			'leadTime'           => ! empty( $item['leadTime'] ) ? Pricing::lbl( $item['leadTime'], $l ) : null,
			'note'               => null,
			'item'               => array(
				'id'          => $item['id'],
				'type'        => $type,
				'zone'        => $item['zone'] ?? null,
				'label'       => $item['label'] ?? null,
				'blankLabel'  => $item['blankLabel'] ?? null,
				'options'     => $item['options'] ?? array(),
				'patchExcl'   => $item['patchExcl'] ?? null,
				'minQty'      => $item['minQty'] ?? null,
				'qtyStep'     => $item['qtyStep'] ?? null,
				'moldFeeExcl' => $item['moldFeeExcl'] ?? null,
				'moldLabel'   => $item['moldLabel'] ?? null,
			),
			'price'              => array(
				'technique'      => $d['technique'],
				'pool'           => $pool,
				'patchLadder'    => $ladder( $fit['patchSizeCm'] ),
				'altPatchLadder' => $fit['alt'] ? $ladder( $fit['alt']['patchSizeCm'] ) : null,
				'productExcl'    => $products ? $products[0]['priceExcl'] : 0,
				'moldCovered'    => $shared,
				'moldCharged'    => ! empty( $cart['pvcMoldCharged'] ),
			),
		);
		if ( 'pvc' === $type ) {
			$notes = array( Messages::t( 'cs.pvc.min', $l, array( 'n' => self::cm_text( $card['minQty'] ) ), $ov ) );
			if ( $card['leadTime'] ) {
				$notes[] = Messages::t( 'cs.pvc.lead', $l, array( 'lead' => $card['leadTime'] ), $ov );
			}
			$card['note'] = implode( ' ', $notes );
		}
		$q = self::card_price(
			$card,
			array(
				'variationId' => $def_vid,
				'productId'   => $def_pid,
				'qty'         => $qty,
				'options'     => array(),
			),
			$x['pricing'],
			$x['locale']
		);
		if ( ! $q['ok'] ) {
			return null;
		}
		$card['unitExcl']  = $q['unitExcl'];
		$card['unitIncl']  = $q['unitIncl'];
		$card['breakdown'] = array_map(
			static fn( $p ) => array(
				'key'   => $p['key'],
				'label' => $p['label'],
				'incl'  => $p['incl'],
			),
			$q['parts']
		);
		$card['fees']      = $q['fees'];
		if ( 'pvc' === $type && $q['fees'] ) {
			$card['note'] .= ' ' . Messages::t( 'cs.pvc.mold', $l, array( 'price' => Messages::money( $q['fees'][0]['incl'], $x['locale'] ) ), $ov );
		}
		if ( 'pvc' === $type && $shared ) {
			$card['note'] .= ' ' . Messages::t( 'cs.pvc.moldShared', $l, array(), $ov );
		}
		$vat             = Fit::num( $main['vat']['rate'] ?? null );
		$card['options'] = array();
		foreach ( ( isset( $item['options'] ) && is_array( $item['options'] ) ? $item['options'] : array() ) as $o ) {
			if ( is_array( $o ) ) {
				$card['options'][] = array(
					'id'    => $o['id'] ?? '',
					'label' => Pricing::lbl( $o['label'] ?? null, $l ),
					'incl'  => Pricing::round_half_up( Fit::num( $o['priceExcl'] ?? null ) * ( 1 + $vat ), 2 ),
				);
			}
		}
		return $card;
	}

	/**
	 * Aanpassingstekst.
	 *
	 * @param array  $item Item.
	 * @param array  $fit  Fit.
	 * @param string $l    Taal.
	 * @param array  $ov   Overrides.
	 */
	private static function adjust_note( array $item, array $fit, string $l, array $ov ): ?string {
		switch ( $fit['note'] ?? null ) {
			case 'size':
				return Messages::t( 'beanie' === $item['id'] ? 'cs.adjust.size.beanie' : 'cs.adjust.size.cap', $l, array( 'cm' => self::cm_text( $fit['patchSizeCm'] ) ), $ov );
			case 'back':
				$chest = Fit::num( $item['fit']['chestMaxCm'] ?? null, 10 );
				$s     = Messages::t( 'cs.adjust.back', $l, array( 'chest' => self::cm_text( $chest ) ), $ov );
				if ( $fit['alt'] ) {
					$s .= ' ' . Messages::t( 'cs.adjust.backAlt', $l, array( 'cm' => self::cm_text( $fit['alt']['patchSizeCm'] ) ), $ov );
				}
				return $s;
			case 'simplify':
				return Messages::t( 'cs.adjust.simplify', $l, array(), $ov );
			case 'photo':
				return Messages::t( 'cs.leather.photo', $l, array(), $ov );
			case 'gradient':
				return Messages::t( 'cs.leather.gradient', $l, array(), $ov );
		}
		return null;
	}

	/**
	 * Mockup-soort.
	 *
	 * @param array       $item       Item.
	 * @param int         $product_id Product.
	 * @param string|null $placement  Plaats.
	 */
	private static function mockup_kind( array $item, int $product_id, ?string $placement ): string {
		if ( 'hoodie' === $item['id'] && 'back' === $placement ) {
			return (string) ( $item['mockupBack'] ?? 'hoodie_back' );
		}
		$map = isset( $item['mockupByProduct'] ) && is_array( $item['mockupByProduct'] ) ? $item['mockupByProduct'] : array();
		return (string) ( $map[ (string) $product_id ] ?? ( $item['mockup'] ?? 'cap_trucker' ) );
	}

	/**
	 * Koppel product-ID's aan items (twin van linkProducts()).
	 *
	 * @param array $cfg   Config.
	 * @param array $links item => int[] | [sizeId => int].
	 */
	public static function link_products( array $cfg, array $links ): array {
		foreach ( $cfg['items'] as &$it ) {
			$l = $links[ $it['id'] ] ?? null;
			if ( ! $l ) {
				continue;
			}
			if ( 'frame' === ( $it['type'] ?? '' ) && ! array_is_list( $l ) ) {
				foreach ( $it['sizes'] as &$s ) {
					if ( ! empty( $l[ $s['id'] ] ) ) {
						$s['productId'] = (int) $l[ $s['id'] ];
					}
				}
				unset( $s );
			} elseif ( array_is_list( $l ) ) {
				$it['wcProductIds']     = array_map( 'intval', $l );
				$it['defaultProductId'] = (int) ( $l[0] ?? 0 );
			}
		}
		unset( $it );
		return $cfg;
	}
}
