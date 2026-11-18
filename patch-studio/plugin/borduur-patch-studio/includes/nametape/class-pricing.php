<?php
/**
 * Name tapes: server price (SPEC-EXTRA §1.7). Pure class, no WordPress. Twin of assets/src/nametape/pricing.js,
 * verified with tests/nametape/price-vectors.json in Node and PHP.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Nametape;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BPS_TESTING' ) ) {
	exit;
}

/**
 * One list = one cart line; line price = Σ pieces × discounted unit price.
 */
final class Pricing {

	const LABELS = array(
		'nl' => array(
			'main'         => '{n} × naamstrook {format}',
			'blood'        => '{n} × bloedgroepstrook {format}',
			'shipping'     => 'Verzending (schatting)',
			'shippingFree' => 'Verzending: gratis',
			'custom'       => '{w} × {h} cm',
		),
		'fr' => array(
			'main'         => '{n} × bande nominative {format}',
			'blood'        => '{n} × bande groupe sanguin {format}',
			'shipping'     => 'Livraison (estimation)',
			'shippingFree' => 'Livraison : gratuite',
			'custom'       => '{w} × {h} cm',
		),
	);

	/**
	 * Round to 2 decimals (half up).
	 *
	 * @param float $x Value.
	 */
	private static function r2( float $x ): float {
		return Fit::round_half_up( $x, 2 );
	}

	/**
	 * Round to 4 decimals (half up).
	 *
	 * @param float $x Value.
	 */
	private static function r4( float $x ): float {
		return Fit::round_half_up( $x, 4 );
	}

	/**
	 * 'nl' | 'fr'.
	 *
	 * @param mixed $locale Locale.
	 */
	public static function lang( $locale ): string {
		return 0 === stripos( (string) $locale, 'fr' ) ? 'fr' : 'nl';
	}

	/**
	 * Label from {nl, fr} or string.
	 *
	 * @param mixed  $obj  Label.
	 * @param string $lang Language.
	 */
	public static function label( $obj, string $lang ): string {
		if ( is_array( $obj ) ) {
			return (string) ( $obj[ $lang ] ?? ( $obj['nl'] ?? '' ) );
		}
		return is_scalar( $obj ) ? (string) $obj : '';
	}

	/**
	 * Fill {placeholders}.
	 *
	 * @param string $s Template.
	 * @param array  $p Params.
	 */
	private static function fill( string $s, array $p ): string {
		return (string) preg_replace_callback(
			'/\{(\w+)\}/',
			static fn( $m ) => array_key_exists( $m[1], $p ) ? (string) $p[ $m[1] ] : $m[0],
			$s
		);
	}

	/**
	 * Millimetres as "2,5" cm text.
	 *
	 * @param float $mm Millimetres.
	 */
	private static function cm( float $mm ): string {
		$v = round( $mm ) / 10;
		$s = (string) ( floor( $v ) === $v ? (int) $v : $v );
		return str_replace( '.', ',', $s );
	}

	/**
	 * Row quantity clamped to 1..99.
	 *
	 * @param mixed $q Quantity.
	 */
	public static function row_qty( $q ): int {
		if ( ! is_numeric( $q ) ) {
			return 1;
		}
		$n = (int) floor( (float) $q );
		return min( 99, max( 1, $n ) );
	}

	/**
	 * Highest step with min ≤ qty.
	 *
	 * @param int   $qty   Quantity.
	 * @param array $tiers {steps:[{min,discount}]}.
	 * @return array{min:int,discount:float}
	 */
	public static function tier_for( int $qty, array $tiers ): array {
		$best  = array(
			'min'      => 1,
			'discount' => 0.0,
		);
		$found = false;
		foreach ( (array) ( $tiers['steps'] ?? array() ) as $s ) {
			$min = (int) ( $s['min'] ?? 0 );
			$d   = (float) ( $s['discount'] ?? 0 );
			if ( $min <= $qty && ( ! $found || $min >= $best['min'] ) ) {
				$best  = array(
					'min'      => $min,
					'discount' => $d,
				);
				$found = true;
			}
		}
		return $best;
	}

	/**
	 * Price row of the chosen format or custom size.
	 *
	 * @param array  $batch Batch.
	 * @param array  $cfg   Config.
	 * @param string $lang  Language.
	 * @return array{priceExcl?:float,label?:string,error?:string}
	 */
	public static function format_price( array $batch, array $cfg, string $lang = 'nl' ): array {
		if ( 'custom' === ( $batch['format'] ?? '' ) ) {
			$cs = (array) ( $cfg['customSize'] ?? array() );
			$c  = is_array( $batch['custom'] ?? null ) ? $batch['custom'] : array();
			$w  = (float) ( $c['w'] ?? 0 );
			$h  = (float) ( $c['h'] ?? 0 );
			$hs = array_map( 'floatval', (array) ( $cs['heights'] ?? array() ) );
			if ( empty( $cs['enabled'] ) || $w < (float) ( $cs['minW'] ?? 0 ) || $w > (float) ( $cs['maxW'] ?? 0 ) || ! in_array( $h, $hs, true ) ) {
				return array( 'error' => 'size_out_of_range' );
			}
			foreach ( (array) ( $cs['price'] ?? array() ) as $p ) {
				if ( $w <= (float) $p['maxW'] && $h <= (float) $p['maxH'] ) {
					return array(
						'priceExcl' => (float) $p['priceExcl'],
						'label'     => self::fill(
							self::LABELS[ $lang ]['custom'],
							array(
								'w' => self::cm( $w ),
								'h' => self::cm( $h ),
							)
						),
					);
				}
			}
			return array( 'error' => 'size_out_of_range' );
		}
		$f = Fit::find( $cfg, 'formats', (string) ( $batch['format'] ?? '' ) );
		if ( ! $f ) {
			return array( 'error' => 'unknown_format' );
		}
		$label = self::label( $f['label'] ?? null, $lang );
		return array(
			'priceExcl' => (float) $f['priceExcl'],
			'label'     => '' !== $label ? $label : (string) $f['id'],
		);
	}

	/**
	 * Empty (error) quote.
	 *
	 * @param array $errors Errors.
	 */
	private static function empty_quote( array $errors ): array {
		return array(
			'ok'                => false,
			'errors'            => $errors,
			'pieces'            => 0,
			'mainPieces'        => 0,
			'bloodPieces'       => 0,
			'tier'              => array(
				'min'      => 1,
				'discount' => 0.0,
			),
			'mainUnitExcl'      => 0.0,
			'bloodUnitExcl'     => 0.0,
			'unitIncl'          => 0.0,
			'lineExcl'          => 0.0,
			'lineTax'           => 0.0,
			'lineIncl'          => 0.0,
			'fees'              => array(),
			'feesIncl'          => 0.0,
			'shippingIncl'      => 0.0,
			'totalIncl'         => 0.0,
			'perPieceAllInIncl' => 0.0,
			'nextTier'          => null,
			'breakdown'         => array(),
		);
	}

	/**
	 * NametapeQuote.
	 *
	 * @param array $batch NametapeBatch.
	 * @param array $ctx   {patchPoolQty, otherNametapePieces, otherItemsInclVat, vatRate, tiers, shipping, locale}.
	 * @param array $cfg   Merged name-tape config.
	 */
	public static function quote( array $batch, array $ctx, array $cfg ): array {
		$lang   = self::lang( $ctx['locale'] ?? ( $batch['locale'] ?? 'nl' ) );
		$labels = self::LABELS[ $lang ];
		$vat    = (float) ( $ctx['vatRate'] ?? 0 );
		$errors = array();

		$fp = self::format_price( $batch, $cfg, $lang );
		if ( isset( $fp['error'] ) ) {
			$errors[] = array( 'code' => $fp['error'] );
		}
		$fabric = Fit::find( $cfg, 'fabrics', (string) ( $batch['fabric'] ?? '' ) );
		if ( ! $fabric ) {
			$errors[] = array( 'code' => 'unknown_fabric' );
		}
		$backing = Fit::find( $cfg, 'backings', (string) ( $batch['backing'] ?? '' ) );
		if ( ! $backing ) {
			$errors[] = array( 'code' => 'unknown_backing' );
		}
		if ( $errors ) {
			return self::empty_quote( $errors );
		}

		$lc      = (array) ( $cfg['loopCounterpart'] ?? array() );
		$only    = isset( $lc['onlyWith'] ) ? (array) $lc['onlyWith'] : array( 'hook' );
		$loop_on = ! empty( $batch['loop'] ) && ! empty( $lc['enabled'] ) && in_array( (string) $batch['backing'], $only, true );
		$deltas  = (float) ( $fabric['surchargeExcl'] ?? 0 ) + (float) ( $backing['deltaExcl'] ?? 0 ) + ( $loop_on ? (float) ( $lc['priceExcl'] ?? 0 ) : 0.0 );

		$rows        = is_array( $batch['rows'] ?? null ) ? $batch['rows'] : array();
		$main_unit   = (float) $fp['priceExcl'] + $deltas;
		$main_pieces = 0;
		$blood_pcs   = 0;
		foreach ( $rows as $r ) {
			$q            = self::row_qty( $r['qty'] ?? 1 );
			$main_pieces += $q;
			if ( 'separate' === ( $batch['bloodMode'] ?? null ) && ! empty( $r['blood']['group'] ) ) {
				$blood_pcs += $q;
			}
		}
		$bf         = Fit::find( $cfg, 'formats', (string) ( $cfg['blood']['separateFormat'] ?? '5x2.5' ) );
		$blood_unit = ( $bf ? (float) $bf['priceExcl'] : 0.0 ) + $deltas;

		$pieces   = $main_pieces + $blood_pcs;
		$other    = max( 0, (int) floor( (float) ( $ctx['otherNametapePieces'] ?? 0 ) ) );
		$pooled   = ! isset( $cfg['tiers']['pooledWithPatches'] ) || false !== $cfg['tiers']['pooledWithPatches'];
		$pool     = $pooled ? max( 0, (int) floor( (float) ( $ctx['patchPoolQty'] ?? 0 ) ) ) : 0;
		$tier_qty = $pieces + $other + $pool;
		$tiers    = is_array( $ctx['tiers'] ?? null ) ? $ctx['tiers'] : array();
		$tier     = self::tier_for( $tier_qty, $tiers );
		$d        = (float) $tier['discount'];

		$main_unit_excl  = self::r4( $main_unit * ( 1 - $d ) );
		$blood_unit_excl = self::r4( $blood_unit * ( 1 - $d ) );
		$main_line_excl  = self::r2( $main_unit_excl * $main_pieces );
		$blood_line_excl = self::r2( $blood_unit_excl * $blood_pcs );
		$line_excl       = self::r2( $main_line_excl + $blood_line_excl );
		$line_tax        = self::r2( $line_excl * $vat );
		$line_incl       = self::r2( $line_excl + $line_tax );
		$unit_incl       = self::r2( $main_unit_excl * ( 1 + $vat ) );

		$fees = array();
		$sf   = (array) ( $cfg['smallOrderFee'] ?? array() );
		if ( ! empty( $sf['enabled'] ) && $pieces > 0 && $pieces + $other < (int) ( $sf['belowQty'] ?? 0 ) ) {
			$excl   = self::r2( (float) ( $sf['amountExcl'] ?? 0 ) );
			$tax    = self::r2( $excl * $vat );
			$fees[] = array(
				'key'   => 'fee:nametape_setup',
				'label' => self::label( $sf['label'] ?? '', $lang ),
				'excl'  => $excl,
				'tax'   => $tax,
				'incl'  => self::r2( $excl + $tax ),
			);
		}
		$fees_incl = 0.0;
		foreach ( $fees as $f ) {
			$fees_incl += $f['incl'];
		}
		$fees_incl = self::r2( $fees_incl );

		$ship_incl = 0.0;
		$ship_free = false;
		$ship      = is_array( $ctx['shipping'] ?? null ) ? $ctx['shipping'] : null;
		if ( $ship && $pieces > 0 ) {
			$articles  = self::r2( $line_incl + (float) ( $ctx['otherItemsInclVat'] ?? 0 ) );
			$free_from = $ship['freeFromInclVat'] ?? null;
			if ( null !== $free_from && is_numeric( $free_from ) && $articles >= (float) $free_from ) {
				$ship_free = true;
			} else {
				$flat      = self::r2( max( 0.0, (float) ( $ship['flat'] ?? 0 ) ) );
				$ship_incl = self::r2( $flat + self::r2( $flat * $vat ) );
			}
		}
		$total = self::r2( $line_incl + $fees_incl + $ship_incl );

		$next  = null;
		$steps = array();
		foreach ( (array) ( $tiers['steps'] ?? array() ) as $s ) {
			$steps[] = array(
				'min'      => (int) ( $s['min'] ?? 0 ),
				'discount' => (float) ( $s['discount'] ?? 0 ),
			);
		}
		usort( $steps, static fn( $a, $b ) => $a['min'] <=> $b['min'] );
		foreach ( $steps as $s ) {
			if ( $s['min'] > $tier_qty && $pieces > 0 ) {
				$next = array(
					'min'      => $s['min'],
					'needed'   => $s['min'] - $tier_qty,
					'discount' => $s['discount'],
					'unitIncl' => self::r2( self::r4( $main_unit * ( 1 - $s['discount'] ) ) * ( 1 + $vat ) ),
				);
				break;
			}
		}

		$breakdown = array();
		if ( $main_pieces ) {
			$breakdown[] = array(
				'key'      => 'main',
				'label'    => self::fill(
					$labels['main'],
					array(
						'n'      => $main_pieces,
						'format' => $fp['label'],
					)
				),
				'qty'      => $main_pieces,
				'unitIncl' => $unit_incl,
				'incl'     => self::r2( $main_line_excl * ( 1 + $vat ) ),
			);
		}
		if ( $blood_pcs ) {
			$breakdown[] = array(
				'key'      => 'blood',
				'label'    => self::fill(
					$labels['blood'],
					array(
						'n'      => $blood_pcs,
						'format' => $bf ? self::label( $bf['label'] ?? '', $lang ) : '',
					)
				),
				'qty'      => $blood_pcs,
				'unitIncl' => self::r2( $blood_unit_excl * ( 1 + $vat ) ),
				'incl'     => self::r2( $blood_line_excl * ( 1 + $vat ) ),
			);
		}
		foreach ( $fees as $f ) {
			$breakdown[] = array(
				'key'   => $f['key'],
				'label' => $f['label'],
				'incl'  => $f['incl'],
			);
		}
		if ( $ship && $pieces > 0 ) {
			$breakdown[] = array(
				'key'   => 'shipping',
				'label' => $ship_free ? $labels['shippingFree'] : $labels['shipping'],
				'incl'  => $ship_incl,
			);
		}

		return array(
			'ok'                => $pieces > 0,
			'errors'            => $pieces > 0 ? array() : array( array( 'code' => 'no_rows' ) ),
			'pieces'            => $pieces,
			'mainPieces'        => $main_pieces,
			'bloodPieces'       => $blood_pcs,
			'tier'              => $tier,
			'mainUnitExcl'      => $main_unit_excl,
			'bloodUnitExcl'     => $blood_unit_excl,
			'unitIncl'          => $unit_incl,
			'lineExcl'          => $line_excl,
			'lineTax'           => $line_tax,
			'lineIncl'          => $line_incl,
			'fees'              => $fees,
			'feesIncl'          => $fees_incl,
			'shippingIncl'      => $ship_incl,
			'totalIncl'         => $total,
			'perPieceAllInIncl' => $pieces > 0 ? self::r2( $total / $pieces ) : 0.0,
			'nextTier'          => $next,
			'breakdown'         => $breakdown,
		);
	}
}
