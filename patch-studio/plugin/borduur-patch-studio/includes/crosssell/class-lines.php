<?php
/**
 * Cross-sell winkelmandlogica zonder WordPress (SPEC-EXTRA §2.6–2.7): verzoek normaliseren tegen een server-kaart,
 * mandcontext (aantal van dit ontwerp, pool, betaalde fee-groepen, PVC-mal) en welke eenmalige kosten de module
 * zelf moet toevoegen. Puur, zodat run-php.php het standalone test; Cart roept het aan met WC()->cart->get_cart().
 *
 * Mandregels zijn gewone WooCommerce-arrays: ['quantity', 'product_id', 'variation_id', 'bps' => [...]?, 'bps_cs' => [...]?].
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Crosssell;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BPS_TESTING' ) ) {
	exit;
}

/**
 * Pure helpers voor gekoppelde mandregels.
 */
final class Lines {

	const MAX_LINES     = 20;
	const MAX_QTY       = 9990;
	const MAX_NAMES     = 250;
	const MAX_NAME_LEN  = 40;
	const PLACEMENTS    = array( 'front', 'chest', 'sleeve', 'back', 'cuff' );
	const PATCH_TYPES   = array( 'patch_on_blank', 'frame' );
	const FEE_ID_PREFIX = 'bps-fee-';
	const MOLD_FEE_ID   = 'bps-cs-pvc-mold';

	/**
	 * Verzoek van POST /crosssell/cart controleren en normaliseren tegen de (server-)kaart van dat item.
	 *
	 * @param array $body Body: lines[{productId, variationId?, qty}], options?, plate?, names?, placement?.
	 * @param array $card Kaart uit Offers::build() (zelfde item).
	 * @return array{ok:bool, errors:string[], lines:array, options:string[], plate:?array, names:?array, placement:string}
	 */
	public static function normalize( array $body, array $card ): array {
		$errors   = array();
		$products = array();
		foreach ( (array) ( $card['products'] ?? array() ) as $p ) {
			$products[ (int) $p['id'] ] = true;
		}
		$vars = array();
		foreach ( (array) ( $card['variations'] ?? array() ) as $v ) {
			$vars[ (int) $v['id'] ] = $v;
		}
		$raw   = isset( $body['lines'] ) && is_array( $body['lines'] ) ? array_values( $body['lines'] ) : array();
		$lines = array();
		if ( ! $raw || count( $raw ) > self::MAX_LINES ) {
			$errors[] = 'lines';
		}
		if ( empty( $card['needsSize'] ) && count( $raw ) > 1 ) {
			$errors[] = 'lines';
		}
		$seen = array();
		foreach ( $raw as $l ) {
			if ( ! is_array( $l ) ) {
				$errors[] = 'line';
				continue;
			}
			$pid = Fit::int( $l['productId'] ?? 0 );
			$vid = Fit::int( $l['variationId'] ?? 0 );
			$qty = Fit::int( $l['qty'] ?? 0 );
			if ( ! isset( $products[ $pid ] ) ) {
				$errors[] = 'product';
				continue;
			}
			$has_vars = false;
			foreach ( $vars as $v ) {
				if ( (int) $v['productId'] === $pid ) {
					$has_vars = true;
					break;
				}
			}
			if ( $has_vars ) {
				$v = $vars[ $vid ] ?? null;
				if ( ! $v || (int) $v['productId'] !== $pid ) {
					$errors[] = 'variation';
					continue;
				}
				if ( false === ( $v['inStock'] ?? true ) ) {
					$errors[] = 'stock';
					continue;
				}
			} else {
				$vid = 0;
			}
			if ( $qty < 1 || $qty > self::MAX_QTY ) {
				$errors[] = 'qty';
				continue;
			}
			$k = $pid . ':' . $vid;
			if ( isset( $seen[ $k ] ) ) {
				$errors[] = 'duplicate';
				continue;
			}
			$seen[ $k ] = true;
			$lines[]    = array(
				'productId'   => $pid,
				'variationId' => $vid,
				'qty'         => $qty,
			);
		}

		// PVC: minimum en stappen op het totaal (één regel).
		$total = array_sum( array_column( $lines, 'qty' ) );
		$min   = max( 1, (int) Fit::num( $card['minQty'] ?? 1, 1 ) );
		$step  = max( 1, (int) Fit::num( $card['qtyStep'] ?? 1, 1 ) );
		if ( $lines && ( $total < $min || 0 !== $total % $step ) ) {
			$errors[] = 'qty_rule';
		}

		// Opties: enkel die van het item, nooit iets vooraf aangevinkt (de klant stuurt ze expliciet).
		$allowed = array();
		foreach ( (array) ( $card['options'] ?? array() ) as $o ) {
			$allowed[ (string) ( $o['id'] ?? '' ) ] = true;
		}
		$options = array();
		foreach ( ( isset( $body['options'] ) && is_array( $body['options'] ) ? $body['options'] : array() ) as $o ) {
			$o = (string) $o;
			if ( isset( $allowed[ $o ] ) && ! in_array( $o, $options, true ) ) {
				$options[] = $o;
			} elseif ( '' !== $o && ! isset( $allowed[ $o ] ) ) {
				$errors[] = 'option';
			}
		}

		// Naam achterop: de lijst bepaalt het aantal.
		$names = null;
		if ( in_array( 'name_back', $options, true ) ) {
			$names = array();
			foreach ( ( isset( $body['names'] ) && is_array( $body['names'] ) ? $body['names'] : array() ) as $n ) {
				$n = self::clean_text( (string) $n, self::MAX_NAME_LEN );
				if ( '' !== $n ) {
					$names[] = $n;
				}
			}
			if ( ! $names || count( $names ) > self::MAX_NAMES ) {
				$errors[] = 'names';
			} elseif ( $lines && (int) $total !== count( $names ) ) {
				// Eén naam per stuk, ook verdeeld over maten.
				$errors[] = 'names_qty';
			}
		}

		// Plaatje (kader).
		$plate = null;
		if ( ! empty( $card['needsPlate'] ) ) {
			$max   = max( 1, (int) Fit::num( $card['plate']['maxChars'] ?? 28, 28 ) );
			$p     = isset( $body['plate'] ) && is_array( $body['plate'] ) ? $body['plate'] : array();
			$plate = array(
				'l1' => self::clean_text( (string) ( $p['l1'] ?? '' ), $max ),
				'l2' => self::clean_text( (string) ( $p['l2'] ?? '' ), $max ),
			);
		}

		// Plaats: de standaardplaats of het alternatief van de kaart (hoodie: borst/rug).
		$placement = (string) ( $card['placement'] ?? '' );
		$want      = isset( $body['placement'] ) ? (string) $body['placement'] : '';
		if ( '' !== $want && $want !== $placement ) {
			if ( ! empty( $card['alt'] ) && ( $card['alt']['placement'] ?? null ) === $want ) {
				$placement = $want;
			} else {
				$errors[] = 'placement';
			}
		}
		if ( '' !== $placement && ! in_array( $placement, self::PLACEMENTS, true ) ) {
			$errors[] = 'placement';
		}

		return array(
			'ok'        => ! $errors,
			'errors'    => array_values( array_unique( $errors ) ),
			'lines'     => $lines,
			'options'   => $options,
			'plate'     => $plate,
			'names'     => $names,
			'placement' => $placement,
		);
	}

	/**
	 * Vrije tekst (plaatje, namen): enkel één regel, geen tags of stuurtekens, max. lengte in tekens.
	 *
	 * @param string $s   Tekst.
	 * @param int    $max Max. tekens.
	 */
	public static function clean_text( string $s, int $max ): string {
		$s = preg_replace( '/<[^>]*>/u', '', $s ) ?? '';
		$s = preg_replace( '/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $s ) ?? '';
		$s = trim( preg_replace( '/\s+/u', ' ', $s ) ?? '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/**
	 * Valt een techniek in de gedeelde staffelpool?
	 *
	 * @param string $technique Techniek.
	 * @param array  $main      Hoofdconfig.
	 */
	public static function in_pool( string $technique, array $main ): bool {
		return ! empty( $main['techniques'][ $technique ]['tiers'] ) && ( ! isset( $main['tiers']['pooled'] ) || ! empty( $main['tiers']['pooled'] ) );
	}

	/**
	 * Draagt deze cross-sell-regel een (geborduurde/gedrukte) patch?
	 *
	 * @param array $cs bps_cs-data.
	 */
	public static function carries_patch( array $cs ): bool {
		return in_array( (string) ( $cs['type'] ?? '' ), self::PATCH_TYPES, true );
	}

	/**
	 * Patches in de pool: Patch Studio-regels + patches op cross-sell-dragers (zolang de handoff-filter
	 * `bps_cart_pool_qty` er niet is, zien woo's eigen regels die laatste niet: gedocumenteerde beperking).
	 *
	 * @param array $items Mandregels.
	 * @param array $main  Hoofdconfig.
	 */
	public static function pool_qty( array $items, array $main ): int {
		$pool = 0;
		foreach ( $items as $it ) {
			$q = max( 0, (int) ( $it['quantity'] ?? 0 ) );
			if ( ! empty( $it['bps'] ) && self::in_pool( (string) ( $it['bps']['technique'] ?? '' ), $main ) ) {
				$pool += $q;
			} elseif ( ! empty( $it['bps_cs'] ) && self::carries_patch( $it['bps_cs'] ) && self::in_pool( (string) ( $it['bps_cs']['technique'] ?? '' ), $main ) ) {
				$pool += $q;
			}
		}
		return $pool;
	}

	/**
	 * Mandcontext voor Offers::build() voor één ontwerp.
	 *
	 * @param array $items       Mandregels.
	 * @param int   $design_id   Ontwerp.
	 * @param array $main        Hoofdconfig.
	 * @param array $skip_groups Fee-groepen die een oud product al aanrekent (Conditional Extra Fees).
	 * @return array{qtyOfDesign:?int, poolQty:int, feeGroups:string[], pvcMoldCovered:bool, pvcMoldCharged:bool}
	 */
	public static function context( array $items, int $design_id, array $main, array $skip_groups = array() ): array {
		$qty     = null;
		$groups  = $skip_groups;
		$covered = false;
		$charged = false;
		foreach ( $items as $it ) {
			$q = max( 0, (int) ( $it['quantity'] ?? 0 ) );
			if ( ! empty( $it['bps'] ) ) {
				$b = $it['bps'];
				$g = (string) ( $main['techniques'][ (string) ( $b['technique'] ?? '' ) ]['feeGroup'] ?? '' );
				if ( '' !== $g ) {
					$groups[] = $g;
				}
				if ( (int) ( $b['design_id'] ?? 0 ) === $design_id ) {
					$qty = (int) $qty + $q;
					if ( 'pvc' === ( $b['technique'] ?? '' ) ) {
						$covered = true;
					}
				}
			} elseif ( ! empty( $it['bps_cs'] ) && 'pvc' === ( $it['bps_cs']['type'] ?? '' ) ) {
				$charged = true;
			}
		}
		return array(
			'qtyOfDesign'    => $qty,
			'poolQty'        => self::pool_qty( $items, $main ),
			'feeGroups'      => array_values( array_unique( $groups ) ),
			'pvcMoldCovered' => $covered,
			'pvcMoldCharged' => $charged,
		);
	}

	/**
	 * Laatste Patch Studio-ontwerp in de mand (voor ?source=cart en de mandregel).
	 *
	 * @param array $items Mandregels.
	 */
	public static function last_design_id( array $items ): int {
		$id = 0;
		foreach ( $items as $it ) {
			if ( ! empty( $it['bps']['design_id'] ) ) {
				$id = (int) $it['bps']['design_id'];
			}
		}
		return $id;
	}

	/**
	 * Eenmalige kosten die de cross-sell-module zelf toevoegt (SPEC-EXTRA §2.6):
	 *  - de borduurkaart/instelkost van een techniek als de mand geen Patch Studio-regel van die groep meer heeft maar
	 *    wel een cross-sell met patch (zelfde fee-id als woo → WooCommerce weigert een dubbele, dus nooit twee keer);
	 *  - de PVC-mal voor sleutelhangers, één keer per bestelling, 0 bij een gedeelde mal (enkel met sharedMold).
	 *
	 * @param array  $items       Mandregels.
	 * @param array  $main        Hoofdconfig.
	 * @param array  $cfg         Cross-sell-config.
	 * @param array  $skip_groups Groepen die een oud product al aanrekent.
	 * @param string $lang        nl|fr.
	 * @return array<int,array{id:string,label:string,excl:float}>
	 */
	public static function fees( array $items, array $main, array $cfg, array $skip_groups, string $lang ): array {
		$scope   = 'design' === ( $main['fees']['scope'] ?? 'order' ) ? 'design' : 'order';
		$have    = array();
		$have_ds = array();
		foreach ( $items as $it ) {
			if ( ! empty( $it['bps'] ) ) {
				$g = (string) ( $main['techniques'][ (string) ( $it['bps']['technique'] ?? '' ) ]['feeGroup'] ?? '' );
				if ( '' !== $g ) {
					$have[ $g ]                                                 = true;
					$have_ds[ $g . '-' . (int) ( $it['bps']['design_id'] ?? 0 ) ] = true;
				}
			}
		}
		$out  = array();
		$done = array();
		$pvc  = null;
		foreach ( $items as $it ) {
			$cs = $it['bps_cs'] ?? null;
			if ( ! is_array( $cs ) ) {
				continue;
			}
			if ( self::carries_patch( $cs ) ) {
				$g   = (string) ( $main['techniques'][ (string) ( $cs['technique'] ?? '' ) ]['feeGroup'] ?? '' );
				$def = '' !== $g ? ( $main['fees']['groups'][ $g ] ?? null ) : null;
				if ( ! is_array( $def ) || in_array( $g, $skip_groups, true ) ) {
					continue;
				}
				$did = (int) ( $cs['design_id'] ?? 0 );
				if ( 'design' === $scope ) {
					$key = $g . '-' . $did;
					if ( isset( $have_ds[ $key ] ) || isset( $done[ $key ] ) ) {
						continue;
					}
					$done[ $key ] = true;
					$out[]        = array(
						'id'    => self::FEE_ID_PREFIX . $key,
						'label' => Pricing::lbl( $def['label'] ?? $g, $lang ) . ' (' . (string) ( $cs['ref'] ?? '' ) . ')',
						'excl'  => Fit::num( $def['amount'] ?? 0 ),
					);
				} elseif ( ! isset( $have[ $g ] ) && ! isset( $done[ $g ] ) ) {
					$done[ $g ] = true;
					$out[]      = array(
						'id'    => self::FEE_ID_PREFIX . $g,
						'label' => Pricing::lbl( $def['label'] ?? $g, $lang ),
						'excl'  => Fit::num( $def['amount'] ?? 0 ),
					);
				}
			} elseif ( 'pvc' === ( $cs['type'] ?? '' ) && null === $pvc ) {
				$pvc = $cs;
			}
		}
		if ( $pvc ) {
			$item = null;
			foreach ( (array) ( $cfg['items'] ?? array() ) as $i ) {
				if ( is_array( $i ) && ( $i['id'] ?? null ) === ( $pvc['item'] ?? null ) ) {
					$item = $i;
				}
			}
			$mold   = Fit::num( $item['moldFeeExcl'] ?? 0 );
			$shared = false;
			if ( true === ( $item['sharedMold'] ?? false ) ) {
				foreach ( $items as $it ) {
					if ( ! empty( $it['bps'] ) && 'pvc' === ( $it['bps']['technique'] ?? '' ) && (int) ( $it['bps']['design_id'] ?? 0 ) === (int) ( $pvc['design_id'] ?? -1 ) ) {
						$shared = true;
					}
				}
			}
			if ( $mold > 0 && ! $shared ) {
				$out[] = array(
					'id'    => self::MOLD_FEE_ID,
					'label' => Pricing::lbl( $item['moldLabel'] ?? null, $lang ) ?: Pricing::PART_LABELS[ 'fr' === $lang ? 'fr' : 'nl' ]['mold'],
					'excl'  => $mold,
				);
			}
		}
		return array_values( array_filter( $out, static fn( $f ) => $f['excl'] > 0 ) );
	}

	/**
	 * Korte tekst voor mand en bestelling: "Met patch PS-2026-0042 (6 cm, geborduurd), op het voorpaneel genaaid".
	 *
	 * @param array  $cs        bps_cs-data.
	 * @param string $tech_name Naam van de techniek (kleine letters, "geborduurd").
	 * @param string $lang      nl|fr.
	 */
	public static function patch_text( array $cs, string $tech_name, string $lang ): string {
		$type = (string) ( $cs['type'] ?? '' );
		if ( ! self::carries_patch( $cs ) && 'laser_patch_on_blank' !== $type ) {
			return '';
		}
		$fr    = 'fr' === $lang;
		$where = array(
			'front'  => $fr ? 'cousu sur le panneau avant' : 'op het voorpaneel genaaid',
			'cuff'   => $fr ? 'cousu sur le revers' : 'op de omslag genaaid',
			'chest'  => $fr ? 'cousu sur la poitrine' : 'op de borst genaaid',
			'back'   => $fr ? 'cousu dans le dos' : 'op de rug genaaid',
			'sleeve' => $fr ? 'cousu sur la manche' : 'op de mouw genaaid',
		);
		$size  = Offers::cm_text( $cs['patch_size_cm'] ?? 0 );
		$ref   = (string) ( $cs['ref'] ?? '' );
		if ( 'frame' === $type ) {
			return $fr ? sprintf( 'Avec écusson %1$s (%2$s cm, %3$s), encadré', $ref, $size, $tech_name ) : sprintf( 'Met patch %1$s (%2$s cm, %3$s), ingekaderd', $ref, $size, $tech_name );
		}
		$w = $where[ (string) ( $cs['placement'] ?? 'front' ) ] ?? $where['front'];
		return $fr ? sprintf( 'Avec écusson %1$s (%2$s cm, %3$s), %4$s', $ref, $size, $tech_name, $w ) : sprintf( 'Met patch %1$s (%2$s cm, %3$s), %4$s', $ref, $size, $tech_name, $w );
	}
}
