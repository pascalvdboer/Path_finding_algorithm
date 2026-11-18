<?php
/**
 * Cross-sell prijzen (SPEC-EXTRA §2.6). PHP-tweeling van assets/src/crosssell/pricing.js.
 *
 * Puur: de patchprijs komt via een callable `patch_unit( technique, size_cm, options, qty, pool )` (in WordPress:
 * \BorduurPatchStudio\Pricing::cart_unit_excl), zodat er één patchprijs-bron blijft. Afronding = shared/money.js.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Crosssell;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BPS_TESTING' ) ) {
	exit;
}

/**
 * Prijs per stuk voor een cross-sell-regel.
 */
final class Pricing {

	const PART_LABELS = array(
		'nl' => array(
			'blank'       => '{blank}',
			'patch'       => 'patch',
			'application' => 'aanbrengen',
			'frame'       => 'kader',
			'leather'     => 'lederen patch',
			'mold'        => 'PVC-mal sleutelhanger (eenmalig)',
		),
		'fr' => array(
			'blank'       => '{blank}',
			'patch'       => 'écusson',
			'application' => 'pose',
			'frame'       => 'cadre',
			'leather'     => 'écusson en cuir',
			'mold'        => 'Moule PVC porte-clés (unique)',
		),
	);

	/**
	 * Half-up afronding, identiek aan shared/money.js roundHalfUp().
	 *
	 * @param float $x Waarde.
	 * @param int   $d Decimalen.
	 */
	public static function round_half_up( float $x, int $d = 2 ): float {
		$s = $x < 0 ? -1 : 1;
		return $s * floor( (float) sprintf( '%.15g', abs( $x ) * 10 ** $d ) + 0.5 ) / 10 ** $d;
	}

	/**
	 * Staffel: hoogste stap met min ≤ qty.
	 *
	 * @param int   $qty  Aantal.
	 * @param array $main Hoofdconfig.
	 * @return array{min:int,discount:float}
	 */
	public static function tier_for( int $qty, array $main ): array {
		$steps = isset( $main['tiers']['steps'] ) && is_array( $main['tiers']['steps'] ) ? $main['tiers']['steps'] : array();
		$best  = array( 'min' => 1, 'discount' => 0.0 );
		$found = false;
		foreach ( $steps as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$min = Fit::int( $s['min'] ?? null );
			if ( $min <= $qty && ( ! $found || $min >= $best['min'] ) ) {
				$best  = array( 'min' => $min, 'discount' => Fit::num( $s['discount'] ?? null ) );
				$found = true;
			}
		}
		return $best;
	}

	/**
	 * Ontwerpopties met de achterkant vervangen door forceBacking.
	 *
	 * @param mixed  $design_options Opties van het ontwerp.
	 * @param array  $main           Hoofdconfig.
	 * @param string $force          Achterkant.
	 */
	public static function patch_options( $design_options, array $main, string $force = 'sew_on' ): array {
		$group_of = array();
		foreach ( ( isset( $main['options'] ) && is_array( $main['options'] ) ? $main['options'] : array() ) as $o ) {
			if ( is_array( $o ) && isset( $o['id'] ) ) {
				$group_of[ (string) $o['id'] ] = $o['group'] ?? null;
			}
		}
		$drop = array( 'backing', 'velcro_color', 'backing_extra' );
		$out  = array();
		foreach ( ( is_array( $design_options ) ? $design_options : array() ) as $id ) {
			if ( is_string( $id ) && ! in_array( $group_of[ $id ] ?? null, $drop, true ) ) {
				$out[] = $id;
			}
		}
		if ( '' !== $force && ! in_array( $force, $out, true ) ) {
			$out[] = $force;
		}
		return $out;
	}

	/**
	 * Prijs per stuk (twin van priceItem()).
	 *
	 * @param array $item  Item uit crosssell.json.
	 * @param array $input qty, poolQty, blankExcl, productExcl, options, placement, technique, patchSizeCm, designOptions, moldCovered, moldCharged.
	 * @param array $ctx   cfg, main, patch_unit (callable), locale.
	 */
	public static function item( array $item, array $input, array $ctx ): array {
		$cfg    = isset( $ctx['cfg'] ) && is_array( $ctx['cfg'] ) ? $ctx['cfg'] : array();
		$main   = isset( $ctx['main'] ) && is_array( $ctx['main'] ) ? $ctx['main'] : array();
		$l      = str_starts_with( strtolower( (string) ( $ctx['locale'] ?? 'nl' ) ), 'fr' ) ? 'fr' : 'nl';
		$vat    = Fit::num( $main['vat']['rate'] ?? null );
		$qty    = Fit::int( $input['qty'] ?? null );
		$pool   = max( 0, Fit::int( $input['poolQty'] ?? null ) );
		$errors = array();
		$parts  = array();
		$fees   = array();
		$app    = isset( $cfg['application'] ) && is_array( $cfg['application'] ) ? $cfg['application'] : array();
		$labels = self::PART_LABELS[ $l ];
		$part   = static function ( string $key, string $label, float $excl ) use ( &$parts, $vat ): void {
			$parts[] = array(
				'key'   => $key,
				'label' => $label,
				'excl'  => $excl,
				'incl'  => self::round_half_up( $excl * ( 1 + $vat ), 2 ),
			);
		};
		$unit     = 0.0;
		$discount = 0.0;
		if ( $qty < 1 ) {
			$errors[] = 'qty';
		}
		$type = (string) ( $item['type'] ?? '' );

		if ( 'patch_on_blank' === $type || 'frame' === $type ) {
			$tid  = (string) ( $input['technique'] ?? '' );
			$tech = isset( $main['techniques'][ $tid ] ) && is_array( $main['techniques'][ $tid ] ) ? $main['techniques'][ $tid ] : null;
			if ( ! $tech ) {
				$errors[] = 'technique';
			}
			$opts  = self::patch_options( $input['designOptions'] ?? array(), $main, (string) ( $app['forceBacking'] ?? 'sew_on' ) );
			$patch = 0.0;
			if ( $tech && isset( $ctx['patch_unit'] ) && is_callable( $ctx['patch_unit'] ) ) {
				$patch = Fit::num( call_user_func( $ctx['patch_unit'], $tid, Fit::num( $input['patchSizeCm'] ?? null ), $opts, $qty, $pool ) );
			}
			if ( ! ( $patch > 0 ) ) {
				$errors[] = 'patch_price';
			}
			if ( 'patch_on_blank' === $type ) {
				$blank = Fit::num( $input['blankExcl'] ?? null );
				if ( ! ( $blank > 0 ) ) {
					$errors[] = 'no_price';
				}
				$zone   = 'back' === ( $input['placement'] ?? null ) ? 'garment_back' : (string) ( $item['zone'] ?? 'cap' );
				$pooled = ! empty( $main['tiers']['pooled'] );
				if ( 'patch' === ( $app['tiers'] ?? null ) && $tech && ! empty( $tech['tiers'] ) ) {
					$discount = self::tier_for( $qty + ( $pooled ? $pool : 0 ), $main )['discount'];
				}
				$app_unit = self::round_half_up( Fit::num( $app[ $zone ] ?? null ) * ( 1 - $discount ), 4 );
				$unit     = self::round_half_up( $blank + $patch + $app_unit, 4 );
				$part( 'blank', self::lbl( $item['blankLabel'] ?? null, $l ) ?: $labels['blank'], $blank );
				$part( 'patch', $labels['patch'], $patch );
				$part( 'application', $labels['application'], $app_unit );
			} else {
				$product = Fit::num( $input['productExcl'] ?? null );
				if ( ! ( $product > 0 ) ) {
					$errors[] = 'no_price';
				}
				$unit = self::round_half_up( $product + $patch, 4 );
				$part( 'frame', $labels['frame'], $product );
				$part( 'patch', $labels['patch'], $patch );
			}
		} elseif ( 'laser_patch_on_blank' === $type ) {
			$blank = Fit::num( $input['blankExcl'] ?? null );
			if ( ! ( $blank > 0 ) ) {
				$errors[] = 'no_price';
			}
			$discount = self::tier_for( $qty, $main )['discount'];
			$leather  = self::round_half_up( Fit::num( $item['patchExcl'] ?? null ) * ( 1 - $discount ), 4 );
			$app_unit = self::round_half_up( Fit::num( $app[ (string) ( $item['zone'] ?? 'cap' ) ] ?? null ) * ( 1 - $discount ), 4 );
			$unit     = self::round_half_up( $blank + $leather + $app_unit, 4 );
			$part( 'blank', self::lbl( $item['blankLabel'] ?? null, $l ) ?: $labels['blank'], $blank );
			$part( 'leather', $labels['leather'], $leather );
			$part( 'application', $labels['application'], $app_unit );
		} elseif ( 'laser' === $type ) {
			$product = Fit::num( $input['productExcl'] ?? null );
			if ( ! ( $product > 0 ) ) {
				$errors[] = 'no_price';
			}
			$extra  = 0.0;
			$chosen = isset( $input['options'] ) && is_array( $input['options'] ) ? $input['options'] : array();
			foreach ( ( isset( $item['options'] ) && is_array( $item['options'] ) ? $item['options'] : array() ) as $o ) {
				if ( is_array( $o ) && in_array( $o['id'] ?? null, $chosen, true ) ) {
					$extra += Fit::num( $o['priceExcl'] ?? null );
				}
			}
			$discount = self::tier_for( $qty, $main )['discount'];
			$unit     = self::round_half_up( ( $product + $extra ) * ( 1 - $discount ), 4 );
			$part( 'product', self::lbl( $item['label'] ?? null, $l ), $unit );
		} elseif ( 'pvc' === $type ) {
			$product = Fit::num( $input['productExcl'] ?? null );
			if ( ! ( $product > 0 ) ) {
				$errors[] = 'no_price';
			}
			$min  = max( 1, Fit::int( $item['minQty'] ?? null ) ?: 1 );
			$step = max( 1, Fit::int( $item['qtyStep'] ?? null ) ?: 1 );
			if ( $qty > 0 && $qty < $min ) {
				$errors[] = 'below_min_qty';
			} elseif ( $qty > 0 && 0 !== $qty % $step ) {
				$errors[] = 'qty_step';
			}
			$tech     = isset( $main['techniques']['pvc'] ) && is_array( $main['techniques']['pvc'] ) ? $main['techniques']['pvc'] : null;
			$discount = ! $tech || ! empty( $tech['tiers'] ) ? self::tier_for( $qty, $main )['discount'] : 0.0;
			$unit     = self::round_half_up( $product * ( 1 - $discount ), 4 );
			$part( 'product', self::lbl( $item['label'] ?? null, $l ), $unit );
			$mold = Fit::num( $item['moldFeeExcl'] ?? null );
			if ( $mold > 0 && empty( $input['moldCovered'] ) && empty( $input['moldCharged'] ) ) {
				$fees[] = array(
					'key'   => 'fee:pvc_keychain_mold',
					'label' => self::lbl( $item['moldLabel'] ?? null, $l ) ?: $labels['mold'],
					'excl'  => $mold,
					'incl'  => self::round_half_up( $mold * ( 1 + $vat ), 2 ),
				);
			}
		} else {
			$errors[] = 'type';
		}

		$ok = ! $errors;
		if ( ! $ok ) {
			$unit = 0.0;
		}
		$line_excl = self::round_half_up( $unit * $qty, 2 );
		$line_tax  = self::round_half_up( $line_excl * $vat, 2 );
		return array(
			'ok'           => $ok,
			'errors'       => $errors,
			'qty'          => $qty,
			'unitExcl'     => $unit,
			'unitIncl'     => self::round_half_up( $unit * ( 1 + $vat ), 2 ),
			'lineExcl'     => $line_excl,
			'lineTax'      => $line_tax,
			'lineIncl'     => self::round_half_up( $line_excl + $line_tax, 2 ),
			'tierDiscount' => $discount,
			'parts'        => $ok ? $parts : array(),
			'fees'         => $ok ? $fees : array(),
		);
	}

	/**
	 * Aantallen waarop de patchprijs van staffel wisselt (twin van ladderPoints()).
	 *
	 * @param array $main   Hoofdconfig.
	 * @param int   $pool   Pool.
	 * @param bool  $pooled Gepoold.
	 * @return int[]
	 */
	public static function ladder_points( array $main, int $pool, bool $pooled ): array {
		$pts = array( 1 => true );
		foreach ( ( isset( $main['tiers']['steps'] ) && is_array( $main['tiers']['steps'] ) ? $main['tiers']['steps'] : array() ) as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$q = Fit::int( $s['min'] ?? null ) - ( $pooled ? max( 0, $pool ) : 0 );
			if ( $q > 1 ) {
				$pts[ $q ] = true;
			}
		}
		$out = array_keys( $pts );
		sort( $out );
		return $out;
	}

	/**
	 * Label uit {nl,fr}.
	 *
	 * @param mixed  $o Label.
	 * @param string $l Taal.
	 */
	public static function lbl( $o, string $l ): string {
		if ( is_array( $o ) ) {
			return (string) ( ( $o[ $l ] ?? '' ) ?: ( $o['nl'] ?? '' ) );
		}
		return is_string( $o ) ? $o : '';
	}
}
