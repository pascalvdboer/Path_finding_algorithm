<?php
/**
 * Pricing engine (PHP twin of assets/src/pricing/index.js). SPEC §6.3.
 *
 * ONE algorithm, implemented twice. The browser shows a live price with the JS engine; the cart, REST /quote,
 * e-mails and the admin sample table use this class. Both MUST return identical numbers (and labels) for
 * identical input + config: tests/pricing/vectors.json, tests/pricing/run-php.php and tests/pricing/parity.test.js
 * (thousands of random inputs, JS vs PHP, full quote compared) enforce this.
 * Change one → change the other in the same order of operations (float sums are order dependent).
 *
 * Pure: no WordPress functions, except Settings::get() when no config is passed and the optional
 * `bps_price_quote` filter. Loadable standalone with define( 'BPS_TESTING', true ).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BPS_TESTING' ) ) {
	exit;
}

/**
 * Price quotes for custom patches.
 */
final class Pricing {

	/** Labels used inside a quote — identical to LINE_LABELS in assets/src/pricing/index.js. */
	const LINE_LABELS = array(
		'nl' => array(
			'item'         => '{qty} × {technique} {size} cm',
			'shipping'     => 'Verzending (schatting)',
			'shippingFree' => 'Verzending: gratis',
		),
		'fr' => array(
			'item'         => '{qty} × {technique} {size} cm',
			'shipping'     => 'Livraison (estimation)',
			'shippingFree' => 'Livraison : gratuite',
		),
	);

	/** Error codes that make a price impossible (all amounts 0). */
	const BLOCKING = array( 'unknown_technique', 'quote_only', 'size_too_small', 'size_too_large' );

	/* ---------------------------------------------------------------------
	 * Public API (SPEC §6.3)
	 * ------------------------------------------------------------------- */

	/**
	 * PriceInput → PriceQuote (camelCase keys, same as the JS engine).
	 *
	 * @param array      $input  PriceInput (+ locale).
	 * @param array|null $config Config; null → Settings::get().
	 */
	public static function quote( array $input, ?array $config = null ): array {
		$config = self::config( $config );
		$ladder = ! array_key_exists( 'withLadder', $input ) || false !== $input['withLadder'];
		$quote  = self::compute( $input, $config, $ladder );
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'bps_price_quote', $quote, $input );
			if ( is_array( $filtered ) ) {
				$quote = $filtered;
			}
		}
		return $quote;
	}

	/**
	 * Quotes for every enabled technique, each with its own default options and a quantity normalised to
	 * its minQty/qtyStep (twin of JS quoteAll()).
	 *
	 * @param array      $input  PriceInput.
	 * @param array|null $config Config.
	 */
	public static function quote_all( array $input, ?array $config = null ): array {
		$config = self::config( $config );
		$techs  = self::is_obj( $config['techniques'] ?? null ) ? $config['techniques'] : array();
		$order  = isset( $config['techniqueOrder'] ) && is_array( $config['techniqueOrder'] ) && ! self::is_obj( $config['techniqueOrder'] ) ? $config['techniqueOrder'] : array_keys( $techs );
		$in_qty = self::int( $input['qty'] ?? null );
		$out    = array();
		foreach ( $order as $id ) {
			$id = (string) $id;
			$t  = $techs[ $id ] ?? null;
			if ( ! self::is_obj( $t ) || empty( $t['enabled'] ) ) {
				continue;
			}
			$q                  = 'quote' === ( $t['mode'] ?? null ) ? $in_qty : self::normalize_qty( $t, $input['qty'] ?? null );
			$sub                = $input;
			$sub['technique']   = $id;
			$sub['qty']         = $q;
			$sub['options']     = self::default_options( $config, $id );
			$sub['withLadder']  = isset( $input['withLadder'] ) && true === $input['withLadder'];
			$r                  = self::quote( $sub, $config );
			$r['qtyAdjusted']   = $q !== $in_qty;
			$out[ $id ]         = $r;
		}
		return $out;
	}

	/**
	 * Half-up rounding robust against float noise (230.175 → 230.18). Identical to shared/money.js roundHalfUp().
	 *
	 * @param float $x Value.
	 * @param int   $d Decimals.
	 */
	public static function round_half_up( float $x, int $d = 2 ): float {
		$s = $x < 0 ? -1 : 1;
		return $s * floor( (float) sprintf( '%.15g', abs( $x ) * 10 ** $d ) + 0.5 ) / 10 ** $d;
	}

	/**
	 * Tier for a (pooled) quantity: step with the highest min ≤ $tier_qty, else min 1 / 0 %.
	 *
	 * @param int   $tier_qty Quantity incl. pool.
	 * @param array $config   Config.
	 * @return array{min:int,discount:float}
	 */
	public static function tier_for( int $tier_qty, array $config ): array {
		$best  = array( 'min' => 1, 'discount' => 0.0 );
		$found = false;
		foreach ( self::sorted_steps( $config ) as $s ) {
			if ( $s['min'] <= $tier_qty && ( ! $found || $s['min'] >= $best['min'] ) ) {
				$best  = $s;
				$found = true;
			}
		}
		return $best;
	}

	/**
	 * Unit price excl. VAT (round4) for a cart line — what WooCommerce gets as product price.
	 *
	 * @param array      $bps      Cart item data (technique, size_cm, options).
	 * @param int        $qty      Line quantity.
	 * @param int        $pool_qty Pieces of OTHER lines in the same tier pool.
	 * @param array|null $config   Config.
	 */
	public static function cart_unit_excl( array $bps, int $qty, int $pool_qty, ?array $config = null ): float {
		$config = self::config( $config );
		$q      = self::compute(
			array(
				'technique'  => $bps['technique'] ?? '',
				'sizeCm'     => $bps['size_cm'] ?? ( $bps['sizeCm'] ?? 0 ),
				'qty'        => $qty,
				'options'    => isset( $bps['options'] ) && is_array( $bps['options'] ) ? array_values( $bps['options'] ) : array(),
				'poolQty'    => $pool_qty,
				'withLadder' => false,
			),
			$config,
			false
		);
		return (float) $q['unitExcl'];
	}

	/**
	 * Smallest multiple of qtyStep that is ≥ max(qty, minQty).
	 *
	 * @param array $tech Technique config.
	 * @param mixed $qty  Quantity.
	 */
	public static function normalize_qty( array $tech, $qty ): int {
		$min  = max( 1, self::int( $tech['minQty'] ?? null ) ?: 1 );
		$step = max( 1, self::int( $tech['qtyStep'] ?? null ) ?: 1 );
		$q    = max( self::int( $qty ), $min );
		return (int) ( ceil( $q / $step ) * $step );
	}

	/**
	 * Default options (radio defaults per group) — same rules as shared/config.js defaultOptions().
	 *
	 * @param array  $config  Config.
	 * @param string $tech_id Technique id.
	 */
	public static function default_options( array $config, string $tech_id ): array {
		$tech = $config['techniques'][ $tech_id ] ?? array();
		$opts = array();
		foreach ( self::options_list( $config ) as $o ) {
			if ( self::applies_to( $o, $tech_id ) ) {
				$opts[] = $o;
			}
		}
		$out    = array();
		$groups = self::is_obj( $config['optionGroups'] ?? null ) ? $config['optionGroups'] : array();
		foreach ( $groups as $gid => $g ) {
			$gid = (string) $gid;
			if ( ! self::is_obj( $g ) || 'radio' !== ( $g['type'] ?? null ) ) {
				continue;
			}
			if ( self::non_empty_list( $g['showIfAny'] ?? null ) && ! array_intersect( $g['showIfAny'], $out ) ) {
				continue;
			}
			$in_group = array_values( array_filter( $opts, static fn( $o ) => ( $o['group'] ?? null ) === $gid ) );
			if ( ! $in_group ) {
				continue;
			}
			$pref = $tech['defaults'][ $gid ] ?? null;
			if ( ! $pref ) {
				$pref = null;
				foreach ( $in_group as $o ) {
					if ( ! empty( $o['default'] ) ) {
						$pref = $o['id'];
						break;
					}
				}
			}
			$ids = array_column( $in_group, 'id' );
			if ( $pref && in_array( $pref, $ids, true ) ) {
				$out[] = $pref;
			} elseif ( ! empty( $g['required'] ) ) {
				$out[] = $in_group[0]['id'];
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Core
	 * ------------------------------------------------------------------- */

	/**
	 * The algorithm (SPEC §6.3). Keep in lockstep with compute() in index.js.
	 *
	 * @param array $input       Input.
	 * @param array $config      Config.
	 * @param bool  $with_ladder Compute ladder + next tier.
	 */
	private static function compute( array $input, array $config, bool $with_ladder ): array {
		$locale  = ( isset( $input['locale'] ) && is_string( $input['locale'] ) && '' !== $input['locale'] ) ? $input['locale']
			: ( ( isset( $config['locale'] ) && is_string( $config['locale'] ) && '' !== $config['locale'] ) ? $config['locale'] : 'nl-BE' );
		$lang    = self::lang( $locale );
		$vat     = self::num( $config['vat']['rate'] ?? null );
		$tech_id = self::scalar_str( $input['technique'] ?? null ) ?? '';
		$size_cm = self::num( $input['sizeCm'] ?? null );
		$qty     = self::int( $input['qty'] ?? null );
		$pool    = max( 0, self::int( $input['poolQty'] ?? null ) );
		$other   = max( 0.0, self::num( $input['otherItemsInclVat'] ?? null ) );
		$charged = array();
		if ( isset( $input['feesAlreadyCharged'] ) && self::is_list( $input['feesAlreadyCharged'] ) ) {
			foreach ( $input['feesAlreadyCharged'] as $f ) {
				$f = self::scalar_str( $f );
				if ( null !== $f ) {
					$charged[] = $f;
				}
			}
		}
		$sizes = self::is_obj( $config['sizes'] ?? null ) ? $config['sizes'] : array();
		$techs = self::is_obj( $config['techniques'] ?? null ) ? $config['techniques'] : array();
		$tech  = ( '' !== $tech_id && self::is_obj( $techs[ $tech_id ] ?? null ) ) ? $techs[ $tech_id ] : null;

		$errors = array();
		$mode   = 'order';
		$priced = 0.0;
		$base   = 0.0;
		$usable = null !== $tech && false !== ( $tech['enabled'] ?? null );
		$quote  = $usable && 'quote' === ( $tech['mode'] ?? null );

		if ( ! $usable ) {
			$errors[] = self::err( 'unknown_technique', array( 'technique' => $tech_id ) );
		} elseif ( $quote ) {
			$mode     = 'quote';
			$errors[] = self::err( 'quote_only', array( 'technique' => $tech_id ) );
		} else {
			$min_cm = self::num( $tech['minCm'] ?? null, self::num( $sizes['minCm'] ?? null ) );
			$max_cm = self::num( $tech['maxOrderCm'] ?? null, self::num( $sizes['maxOrderCm'] ?? null ) );
			if ( ! ( $size_cm > 0 ) || $size_cm < $min_cm ) {
				$errors[] = self::err( 'size_too_small', array( 'min' => $min_cm ) );
			} elseif ( $size_cm > $max_cm ) {
				$mode     = 'quote';
				$errors[] = self::err( 'size_too_large', array( 'max' => $max_cm ) );
			} else {
				$list = array();
				foreach ( ( isset( $sizes['pricedCm'] ) && is_array( $sizes['pricedCm'] ) ) ? $sizes['pricedCm'] : array() as $v ) {
					$v = self::num( $v, NAN );
					if ( is_finite( $v ) ) {
						$list[] = $v;
					}
				}
				sort( $list );
				$hit = null;
				foreach ( $list as $v ) {
					if ( $v >= $size_cm ) {
						$hit = $v;
						break;
					}
				}
				$b = NAN;
				if ( null !== $hit && self::is_obj( $tech['basePrice'] ?? null ) ) {
					$key = self::num_key( $hit );
					$b   = self::num( $tech['basePrice'][ $key ] ?? null, NAN );
				}
				if ( null === $hit || ! is_finite( $b ) ) {
					$mode     = 'quote';
					$errors[] = self::err( 'quote_only', array( 'technique' => $tech_id ) );
				} else {
					$priced = $hit;
					$base   = $b;
				}
			}
		}

		$min_qty  = $tech ? max( 1, self::int( $tech['minQty'] ?? null ) ?: 1 ) : 1;
		$qty_step = $tech ? max( 1, self::int( $tech['qtyStep'] ?? null ) ?: 1 ) : 1;
		if ( $usable && ! $quote ) {
			if ( $qty < $min_qty ) {
				$errors[] = self::err( 'below_min_qty', array( 'min' => $min_qty ) );
			} elseif ( 0 !== $qty % $qty_step ) {
				$errors[] = self::err( 'qty_step', array( 'step' => $qty_step ) );
			}
		}

		$chosen = array();
		if ( $usable ) {
			list( $chosen, $opt_errors ) = self::normalize_options( $input['options'] ?? null, $tech_id, $config );
			if ( ! $quote ) {
				foreach ( $opt_errors as $e ) {
					$errors[] = $e;
				}
			}
		}

		$tiered  = $tech && ! empty( $tech['tiers'] );
		$pooled  = $tiered && ! empty( $config['tiers']['pooled'] );
		$blocked = $qty < 1;
		foreach ( $errors as $e ) {
			if ( in_array( $e['code'], self::BLOCKING, true ) ) {
				$blocked = true;
			}
		}

		$out = array(
			'ok'                  => false,
			'mode'                => $mode,
			'errors'              => $errors,
			'technique'           => $tech_id,
			'sizeCm'              => $size_cm,
			'pricedSizeCm'        => $priced,
			'qty'                 => $qty,
			'currency'            => ( isset( $config['currency'] ) && is_string( $config['currency'] ) && '' !== $config['currency'] ) ? $config['currency'] : 'EUR',
			'vatRate'             => $vat,
			'options'             => array_column( $chosen, 'id' ),
			'basePriceExcl'       => 0.0,
			'optionsPerPieceExcl' => 0.0,
			'optionsPercent'      => 0.0,
			'tier'                => array(
				'min'      => 1,
				'discount' => 0.0,
			),
			'tierQty'             => $qty,
			'pooled'              => $pooled,
			'unitExcl'            => 0.0,
			'unitIncl'            => 0.0,
			'lineExcl'            => 0.0,
			'lineTax'             => 0.0,
			'lineIncl'            => 0.0,
			'extras'              => array(),
			'fees'                => array(),
			'extrasIncl'          => 0.0,
			'feesIncl'            => 0.0,
			'subtotalExcl'        => 0.0,
			'subtotalIncl'        => 0.0,
			'shipping'            => self::empty_shipping(),
			'totalExcl'           => 0.0,
			'totalIncl'           => 0.0,
			'perPieceAllInExcl'   => 0.0,
			'perPieceAllInIncl'   => 0.0,
			'nextTier'            => null,
			'ladder'              => array(),
			'breakdown'           => array(),
		);
		if ( $blocked ) {
			return $out;
		}

		// 5. Options.
		$pp     = 0.0;
		$pct    = 0.0;
		$extras = array();
		foreach ( $chosen as $o ) {
			$p = self::option_price( $o, $tech_id );
			if ( 'per_piece' === $p['type'] ) {
				$pp += $p['amount'];
			} elseif ( 'percent' === $p['type'] ) {
				$pct += $p['amount'];
			} else {
				$extras[] = self::line( 'extra:' . $o['id'], self::label( $o['label'] ?? null, $locale ), $p['amount'], $vat, self::label( $o['help'] ?? null, $locale ) );
			}
		}

		// 6. Tier.
		$tier     = array(
			'min'      => 1,
			'discount' => 0.0,
		);
		$tier_qty = $qty;
		if ( $tiered ) {
			$tier_qty = $qty + ( $pooled ? $pool : 0 );
			$tier     = self::tier_for( $tier_qty, $config );
		}

		// 7. Line.
		$unit_excl = self::r4( ( $base + $pp ) * ( 1 + $pct ) * ( 1 - $tier['discount'] ) );
		$line_excl = self::r2( $unit_excl * $qty );
		$line_tax  = self::r2( $line_excl * $vat );
		$line_incl = self::r2( $line_excl + $line_tax );
		$unit_incl = self::r2( $line_incl / $qty );

		// 9. Fees.
		$fees   = array();
		$groups = ( isset( $config['fees']['groups'] ) && self::is_obj( $config['fees']['groups'] ) ) ? $config['fees']['groups'] : array();
		$fg     = self::scalar_str( $tech['feeGroup'] ?? null ) ?? '';
		if ( '' !== $fg && self::is_obj( $groups[ $fg ] ?? null ) && ! in_array( $fg, $charged, true ) ) {
			$amount = self::num( $groups[ $fg ]['amount'] ?? null );
			if ( $amount > 0 ) {
				$fees[] = self::line( 'fee:' . $fg, self::label( $groups[ $fg ]['label'] ?? null, $locale ), $amount, $vat, self::label( $groups[ $fg ]['help'] ?? null, $locale ) );
			}
		}

		// 10. Subtotal + shipping estimate.
		$sub_excl    = $line_excl;
		$sub_incl    = $line_incl;
		$extras_incl = 0.0;
		$fees_incl   = 0.0;
		foreach ( $extras as $l ) {
			$sub_excl    += $l['excl'];
			$sub_incl    += $l['incl'];
			$extras_incl += $l['incl'];
		}
		foreach ( $fees as $l ) {
			$sub_excl  += $l['excl'];
			$sub_incl  += $l['incl'];
			$fees_incl += $l['incl'];
		}
		$subtotal_excl = self::r2( $sub_excl );
		$subtotal_incl = self::r2( $sub_incl );

		$ship      = self::is_obj( $config['shipping'] ?? null ) ? $config['shipping'] : array();
		$articles  = self::r2( $line_incl + $other );
		$free_from = array_key_exists( 'freeFromInclVat', $ship ) && null !== $ship['freeFromInclVat'] ? self::num( $ship['freeFromInclVat'], NAN ) : null;
		$has_free  = null !== $free_from && is_finite( $free_from );
		if ( $has_free && $articles >= $free_from ) {
			$shipping = array(
				'excl'                    => 0.0,
				'tax'                     => 0.0,
				'incl'                    => 0.0,
				'free'                    => true,
				'remainingForFreeInclVat' => null,
			);
		} else {
			$flat     = self::r2( max( 0.0, self::num( $ship['flat'] ?? null ) ) );
			$tax      = self::r2( $flat * $vat );
			$shipping = array(
				'excl'                    => $flat,
				'tax'                     => $tax,
				'incl'                    => self::r2( $flat + $tax ),
				'free'                    => false,
				'remainingForFreeInclVat' => $has_free ? self::r2( $free_from - $articles ) : null,
			);
		}

		$labels    = self::LINE_LABELS[ $lang ];
		$item_line = array(
			'key'   => 'item',
			'label' => strtr(
				$labels['item'],
				array(
					'{qty}'       => (string) $qty,
					'{technique}' => self::label( $tech['label'] ?? null, $locale ),
					'{size}'      => self::format_size( $size_cm ),
				)
			),
			'excl'  => $line_excl,
			'tax'   => $line_tax,
			'incl'  => $line_incl,
		);
		$ship_line = array(
			'key'   => 'shipping',
			'label' => $shipping['free'] ? $labels['shippingFree'] : $labels['shipping'],
			'excl'  => $shipping['excl'],
			'tax'   => $shipping['tax'],
			'incl'  => $shipping['incl'],
		);

		$out['ok']                  = 'order' === $mode && 0 === count( $errors );
		$out['basePriceExcl']       = $base;
		$out['optionsPerPieceExcl'] = self::r4( $pp );
		$out['optionsPercent']      = self::r4( $pct );
		$out['tier']                = $tier;
		$out['tierQty']             = $tier_qty;
		$out['unitExcl']            = $unit_excl;
		$out['unitIncl']            = $unit_incl;
		$out['lineExcl']            = $line_excl;
		$out['lineTax']             = $line_tax;
		$out['lineIncl']            = $line_incl;
		$out['extras']              = $extras;
		$out['fees']                = $fees;
		$out['extrasIncl']          = self::r2( $extras_incl );
		$out['feesIncl']            = self::r2( $fees_incl );
		$out['subtotalExcl']        = $subtotal_excl;
		$out['subtotalIncl']        = $subtotal_incl;
		$out['shipping']            = $shipping;
		$out['totalExcl']           = self::r2( $subtotal_excl + $shipping['excl'] );
		$out['totalIncl']           = self::r2( $subtotal_incl + $shipping['incl'] );
		$out['perPieceAllInExcl']   = self::r2( $subtotal_excl / $qty );
		$out['perPieceAllInIncl']   = self::r2( $subtotal_incl / $qty );
		$out['breakdown']           = array_merge( array( $item_line ), $extras, $fees, array( $ship_line ) );

		// 12. Ladder + next tier.
		if ( $with_ladder && $tiered ) {
			$steps = self::sorted_steps( $config );
			$sub   = static function ( int $q ) use ( $input, $config ): array {
				$i               = $input;
				$i['qty']        = $q;
				$i['withLadder'] = false;
				return self::compute( $i, $config, false );
			};
			$seen  = array();
			foreach ( $steps as $s ) {
				if ( $s['min'] < $min_qty ) {
					continue;
				}
				$q = (int) ( ceil( max( $s['min'], $min_qty ) / $qty_step ) * $qty_step );
				if ( isset( $seen[ $q ] ) ) {
					continue;
				}
				$seen[ $q ]      = true;
				$r               = $sub( $q );
				$out['ladder'][] = array(
					'min'               => $q,
					'discount'          => $r['tier']['discount'],
					'unitIncl'          => $r['unitIncl'],
					'perPieceAllInIncl' => $r['perPieceAllInIncl'],
					'totalIncl'         => $r['totalIncl'],
					'current'           => $r['tier']['min'] === $tier['min'],
				);
			}
			$next = null;
			foreach ( $steps as $s ) {
				if ( $s['min'] > $tier_qty ) {
					$next = $s;
					break;
				}
			}
			if ( null !== $next ) {
				$nq = (int) ( ceil( max( $next['min'] - ( $pooled ? $pool : 0 ), $min_qty ) / $qty_step ) * $qty_step );
				if ( $nq > $qty ) {
					$r               = $sub( $nq );
					$out['nextTier'] = array(
						'min'                     => $next['min'],
						'qty'                     => $nq,
						'discount'                => $r['tier']['discount'],
						'unitIncl'                => $r['unitIncl'],
						'perPieceAllInIncl'       => $r['perPieceAllInIncl'],
						'totalIncl'               => $r['totalIncl'],
						'savingPerPieceIncl'      => self::r2( $unit_incl - $r['unitIncl'] ),
						'savingPerPieceAllInIncl' => self::r2( $out['perPieceAllInIncl'] - $r['perPieceAllInIncl'] ),
						'extraPieces'             => $nq - $qty,
						'extraCostIncl'           => self::r2( $r['totalIncl'] - $out['totalIncl'] ),
						'withinReach'             => $nq <= 2 * $qty,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Validate + normalise options (SPEC §6.3 step 4).
	 *
	 * @param mixed  $raw     Raw option ids.
	 * @param string $tech_id Technique.
	 * @param array  $config  Config.
	 * @return array{0:array,1:array} [chosen option arrays, errors]
	 */
	private static function normalize_options( $raw, string $tech_id, array $config ): array {
		$errors = array();
		$seen   = array();
		$valid  = array();
		foreach ( self::is_list( $raw ) ? $raw : array() as $r ) {
			$id = self::scalar_str( $r );
			if ( null === $id || '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$opt         = self::option_by_id( $config, $id );
			if ( null === $opt || ! self::applies_to( $opt, $tech_id ) ) {
				$errors[] = self::err( 'invalid_option', array( 'id' => $id ) );
				continue;
			}
			$valid[] = $opt;
		}
		$valid_ids = array_column( $valid, 'id' );
		$chosen    = array();
		foreach ( $valid as $o ) {
			if ( ! self::non_empty_list( $o['requiresAny'] ?? null ) || array_intersect( $o['requiresAny'], $valid_ids ) ) {
				$chosen[] = $o;
			}
		}
		$chosen_ids = array_column( $chosen, 'id' );
		$groups     = self::is_obj( $config['optionGroups'] ?? null ) ? $config['optionGroups'] : array();
		foreach ( $groups as $gid => $g ) {
			$gid = (string) $gid;
			if ( ! self::is_obj( $g ) || 'radio' !== ( $g['type'] ?? null ) ) {
				continue;
			}
			$n = 0;
			foreach ( $chosen as $o ) {
				if ( ( $o['group'] ?? null ) === $gid ) {
					++$n;
				}
			}
			if ( $n > 1 ) {
				$errors[] = self::err( 'option_conflict', array( 'group' => $gid ) );
			}
		}
		foreach ( $groups as $gid => $g ) {
			$gid = (string) $gid;
			if ( ! self::is_obj( $g ) || 'radio' !== ( $g['type'] ?? null ) || empty( $g['required'] ) ) {
				continue;
			}
			if ( self::non_empty_list( $g['showIfAny'] ?? null ) && ! array_intersect( $g['showIfAny'], $chosen_ids ) ) {
				continue;
			}
			$available = false;
			foreach ( self::options_list( $config ) as $o ) {
				if ( ( $o['group'] ?? null ) === $gid && self::applies_to( $o, $tech_id ) ) {
					$available = true;
					break;
				}
			}
			if ( ! $available ) {
				continue;
			}
			$has = false;
			foreach ( $chosen as $o ) {
				if ( ( $o['group'] ?? null ) === $gid ) {
					$has = true;
					break;
				}
			}
			if ( ! $has ) {
				$errors[] = self::err( 'missing_required_option', array( 'group' => $gid ) );
			}
		}
		return array( $chosen, $errors );
	}

	/* ---------------------------------------------------------------------
	 * Helpers (mirrors of the JS helpers)
	 * ------------------------------------------------------------------- */

	/**
	 * Resolve the config.
	 *
	 * @param array|null $config Config.
	 */
	private static function config( ?array $config ): array {
		if ( null !== $config ) {
			return $config;
		}
		if ( class_exists( Settings::class ) ) {
			return Settings::get();
		}
		$file = dirname( __DIR__ ) . '/config/defaults.json';
		$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Error entry. Params are never empty, so they JSON-encode as an object like in JS.
	 *
	 * @param string $code   Code.
	 * @param array  $params Params.
	 */
	private static function err( string $code, array $params ): array {
		return array(
			'code'   => $code,
			'params' => $params,
		);
	}

	/**
	 * Price line.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param float  $excl  Amount excl. VAT.
	 * @param float  $vat   VAT rate.
	 * @param string $help  Help text.
	 */
	private static function line( string $key, string $label, float $excl, float $vat, string $help ): array {
		$e   = self::r2( $excl );
		$tax = self::r2( $e * $vat );
		return array(
			'key'   => $key,
			'label' => $label,
			'excl'  => $e,
			'tax'   => $tax,
			'incl'  => self::r2( $e + $tax ),
			'help'  => $help,
		);
	}

	/** Shipping block for blocked quotes. */
	private static function empty_shipping(): array {
		return array(
			'excl'                    => 0.0,
			'tax'                     => 0.0,
			'incl'                    => 0.0,
			'free'                    => false,
			'remainingForFreeInclVat' => null,
		);
	}

	/**
	 * Tier steps sorted by min (stable), normalised.
	 *
	 * @param array $config Config.
	 */
	private static function sorted_steps( array $config ): array {
		$steps = array();
		$raw   = ( isset( $config['tiers']['steps'] ) && is_array( $config['tiers']['steps'] ) ) ? $config['tiers']['steps'] : array();
		foreach ( $raw as $s ) {
			if ( self::is_obj( $s ) ) {
				$steps[] = array(
					'min'      => self::int( $s['min'] ?? null ),
					'discount' => self::num( $s['discount'] ?? null ),
				);
			}
		}
		usort( $steps, static fn( $a, $b ) => $a['min'] <=> $b['min'] ); // Stable since PHP 8.0 (same as JS).
		return $steps;
	}

	/**
	 * Option list from the config.
	 *
	 * @param array $config Config.
	 */
	private static function options_list( array $config ): array {
		$out = array();
		foreach ( ( isset( $config['options'] ) && is_array( $config['options'] ) ) ? $config['options'] : array() as $o ) {
			if ( self::is_obj( $o ) ) {
				$out[] = $o;
			}
		}
		return $out;
	}

	/**
	 * Option by id.
	 *
	 * @param array  $config Config.
	 * @param string $id     Id.
	 */
	private static function option_by_id( array $config, string $id ): ?array {
		foreach ( self::options_list( $config ) as $o ) {
			if ( ( $o['id'] ?? null ) === $id ) {
				return $o;
			}
		}
		return null;
	}

	/**
	 * Option enabled and offered for the technique.
	 *
	 * @param array  $opt     Option.
	 * @param string $tech_id Technique.
	 */
	private static function applies_to( array $opt, string $tech_id ): bool {
		return false !== ( $opt['enabled'] ?? null ) && isset( $opt['techniques'] ) && self::is_list( $opt['techniques'] ) && in_array( $tech_id, $opt['techniques'], true );
	}

	/**
	 * Price of an option for a technique.
	 *
	 * @param array  $opt     Option.
	 * @param string $tech_id Technique.
	 * @return array{type:string,amount:float}
	 */
	private static function option_price( array $opt, string $tech_id ): array {
		$p = null;
		if ( self::is_obj( $opt['priceByTechnique'] ?? null ) && self::is_obj( $opt['priceByTechnique'][ $tech_id ] ?? null ) ) {
			$p = $opt['priceByTechnique'][ $tech_id ];
		} elseif ( self::is_obj( $opt['price'] ?? null ) ) {
			$p = $opt['price'];
		}
		if ( null === $p ) {
			return array(
				'type'   => 'per_piece',
				'amount' => 0.0,
			);
		}
		$type = $p['type'] ?? null;
		return array(
			'type'   => ( 'per_line' === $type || 'percent' === $type ) ? $type : 'per_piece',
			'amount' => self::num( $p['amount'] ?? null ),
		);
	}

	/**
	 * {nl,fr} label → text (same as JS label() / Settings::label()).
	 *
	 * @param mixed  $obj    Label.
	 * @param string $locale Locale.
	 */
	private static function label( $obj, string $locale ): string {
		if ( null === $obj ) {
			return '';
		}
		if ( is_string( $obj ) ) {
			return $obj;
		}
		if ( ! self::is_obj( $obj ) ) {
			return '';
		}
		$nl = isset( $obj['nl'] ) ? (string) $obj['nl'] : '';
		$fr = isset( $obj['fr'] ) ? (string) $obj['fr'] : '';
		return 'fr' === self::lang( $locale ) ? ( '' !== $fr ? $fr : $nl ) : ( '' !== $nl ? $nl : $fr );
	}

	/**
	 * 'fr…' → 'fr', else 'nl'.
	 *
	 * @param string $locale Locale.
	 */
	private static function lang( string $locale ): string {
		return 0 === strpos( strtolower( $locale ), 'fr' ) ? 'fr' : 'nl';
	}

	/**
	 * Size as shown in the item label: "7,5" / "8".
	 *
	 * @param float $size Size in cm.
	 */
	public static function format_size( float $size ): string {
		return str_replace( '.', ',', self::js_num_string( self::round_half_up( $size, 1 ) ) );
	}

	/**
	 * Number → string like JS String(n) for the ranges we use (integers without ".0").
	 *
	 * @param float $n Number.
	 */
	private static function js_num_string( float $n ): string {
		if ( floor( $n ) === $n && abs( $n ) < 1e15 ) {
			return (string) (int) $n;
		}
		return rtrim( rtrim( sprintf( '%.15g', $n ), '0' ), '.' );
	}

	/**
	 * Array key for a priced size, like JS String(n) (4 → "4", 7.5 → "7.5").
	 *
	 * @param float $n Size.
	 * @return int|string
	 */
	private static function num_key( float $n ) {
		return ( floor( $n ) === $n && abs( $n ) < 1e15 ) ? (int) $n : self::js_num_string( $n );
	}

	/**
	 * Finite number from a number or numeric string, else $default (JS num()).
	 *
	 * @param mixed $v       Value.
	 * @param float $default Default.
	 */
	private static function num( $v, float $default = 0.0 ): float {
		if ( is_int( $v ) || is_float( $v ) ) {
			$n = (float) $v;
		} elseif ( is_string( $v ) && '' !== trim( $v ) && is_numeric( trim( $v ) ) ) {
			$n = (float) trim( $v );
		} else {
			return $default;
		}
		return is_finite( $n ) ? $n : $default;
	}

	/**
	 * Truncated integer (JS Math.trunc(num(v))).
	 *
	 * @param mixed $v Value.
	 */
	private static function int( $v ): int {
		$n = self::num( $v );
		if ( $n >= PHP_INT_MAX || $n <= PHP_INT_MIN ) {
			return 0;
		}
		return (int) $n;
	}

	/**
	 * String or finite number → string; anything else → null (JS scalarStr()).
	 *
	 * @param mixed $v Value.
	 */
	private static function scalar_str( $v ): ?string {
		if ( is_string( $v ) ) {
			return $v;
		}
		if ( is_int( $v ) ) {
			return (string) $v;
		}
		if ( is_float( $v ) && is_finite( $v ) ) {
			return self::js_num_string( $v );
		}
		return null;
	}

	/**
	 * Associative array (JSON object).
	 *
	 * @param mixed $v Value.
	 */
	private static function is_obj( $v ): bool {
		return is_array( $v ) && ( array() === $v || ! array_is_list( $v ) );
	}

	/**
	 * List (JSON array). An empty array counts as both list and object, like json_decode gives it.
	 *
	 * @param mixed $v Value.
	 */
	private static function is_list( $v ): bool {
		return is_array( $v ) && array_is_list( $v );
	}

	/**
	 * Non-empty list.
	 *
	 * @param mixed $v Value.
	 */
	private static function non_empty_list( $v ): bool {
		return self::is_list( $v ) && count( $v ) > 0;
	}

	/**
	 * Round to 2 decimals.
	 *
	 * @param float $x Value.
	 */
	private static function r2( float $x ): float {
		return self::round_half_up( $x, 2 );
	}

	/**
	 * Round to 4 decimals.
	 *
	 * @param float $x Value.
	 */
	private static function r4( float $x ): float {
		return self::round_half_up( $x, 4 );
	}
}
