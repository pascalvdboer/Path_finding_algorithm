<?php
/**
 * Cross-sell pastoetsen (SPEC-EXTRA §2.2). PHP-tweeling van assets/src/crosssell/fit.js.
 *
 * Puur: geen WordPress. Laadbaar met define( 'BPS_TESTING', true ). Zelfde volgorde van bewerkingen als JS;
 * tests/crosssell/fit-vectors.json + run-php.php en de node-test bewaken dat beide hetzelfde antwoord geven.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Crosssell;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BPS_TESTING' ) ) {
	exit;
}

/**
 * Technische pastoets per drager.
 */
final class Fit {

	/** @var float[]|null sRGB → lineair, als float32 (zoals Float32Array in shared/color-math.js). */
	private static $lin = null;

	/* ---------------------------------------------------------------------
	 * Ontwerpinvoer
	 * ------------------------------------------------------------------- */

	/**
	 * DesignPayload → compacte ontwerpinvoer (twin van designFromPayload()).
	 *
	 * @param mixed $p DesignPayload (array).
	 */
	public static function design_from_payload( $p ): array {
		$p        = is_array( $p ) ? $p : array();
		$a        = isset( $p['analysis'] ) && self::is_obj( $p['analysis'] ) ? $p['analysis'] : array();
		$size_cm  = self::num( $p['sizeCm'] ?? null );
		$w        = self::num( $p['widthMm'] ?? null );
		$h        = self::num( $p['heightMm'] ?? null );
		if ( ! ( $w > 0 ) || ! ( $h > 0 ) ) {
			$w = $size_cm * 10;
			$h = $size_cm * 10;
		}
		$min_text = self::pos_or_null( $a['minTextMm'] ?? null );
		foreach ( ( isset( $p['texts'] ) && is_array( $p['texts'] ) ? $p['texts'] : array() ) as $t ) {
			$th = is_array( $t ) ? self::pos_or_null( $t['heightMm'] ?? null ) : null;
			if ( null !== $th && ( null === $min_text || $th < $min_text ) ) {
				$min_text = $th;
			}
		}
		$threads = array();
		foreach ( ( isset( $p['threads'] ) && is_array( $p['threads'] ) ? $p['threads'] : array() ) as $t ) {
			$hex = is_array( $t ) ? self::norm_hex( $t['hex'] ?? null ) : null;
			if ( null !== $hex ) {
				$threads[] = array(
					'hex'   => $hex,
					'share' => max( 0.0, min( 1.0, self::num( $t['share'] ?? null ) ) ),
				);
			}
		}
		$fabric_hex = isset( $p['fabric'] ) && is_array( $p['fabric'] ) ? self::norm_hex( $p['fabric']['hex'] ?? null ) : null;
		$edge_hex   = ( isset( $p['edgeColor'] ) && is_array( $p['edgeColor'] ) ? self::norm_hex( $p['edgeColor']['hex'] ?? null ) : null ) ?? $fabric_hex;
		$kind       = isset( $a['kind'] ) && is_string( $a['kind'] ) && '' !== $a['kind'] ? $a['kind'] : 'flat';
		$colors     = self::int( $a['colors'] ?? null );
		$options    = array();
		foreach ( ( isset( $p['options'] ) && is_array( $p['options'] ) ? $p['options'] : array() ) as $o ) {
			if ( is_string( $o ) ) {
				$options[] = $o;
			}
		}
		return array(
			'technique'  => isset( $p['technique'] ) && is_string( $p['technique'] ) ? $p['technique'] : '',
			'sizeCm'     => $size_cm,
			'widthMm'    => $w,
			'heightMm'   => $h,
			'shape'      => isset( $p['shape'] ) && is_string( $p['shape'] ) ? $p['shape'] : '',
			'qty'        => self::int( $p['qty'] ?? null ),
			'kind'       => $kind,
			'colors'     => $colors ? $colors : count( $threads ),
			'fineLineMm' => self::pos_or_null( $a['fineLineMm'] ?? null ),
			'minTextMm'  => $min_text,
			'threads'    => $threads,
			'fabricHex'  => $fabric_hex,
			'edgeHex'    => $edge_hex,
			'options'    => $options,
		);
	}

	/**
	 * Standaardaantal van een kaart (team ≥ 10 → hetzelfde aantal; anders 1; PVC: minQty in stappen).
	 *
	 * @param array $item     Item.
	 * @param mixed $cart_qty Patches van dit ontwerp in de mand.
	 */
	public static function default_qty( array $item, $cart_qty ): int {
		$q = self::int( $cart_qty );
		if ( 'pvc' === ( $item['type'] ?? '' ) ) {
			$min  = max( 1, self::int( $item['minQty'] ?? null ) ?: 1 );
			$step = max( 1, self::int( $item['qtyStep'] ?? null ) ?: 1 );
			return (int) ( ceil( max( $q, $min ) / $step ) * $step );
		}
		return $q >= 10 ? $q : 1;
	}

	/* ---------------------------------------------------------------------
	 * Pastoets
	 * ------------------------------------------------------------------- */

	/**
	 * Pastoets voor één item (twin van fitItem()).
	 *
	 * @param array $item Item uit crosssell.json.
	 * @param array $d    design_from_payload().
	 * @param array $ctx  ['tech' => technique config|null, 'cartQty' => int].
	 * @return array{fit:string,reason:string,patchSizeCm:float|null,placement:string|null,note:string|null,frameSize:string|null,alt:array|null}
	 */
	public static function item( array $item, array $d, array $ctx = array() ): array {
		$tech     = isset( $ctx['tech'] ) && self::is_obj( $ctx['tech'] ) ? $ctx['tech'] : null;
		$cart_qty = array_key_exists( 'cartQty', $ctx ) ? self::int( $ctx['cartQty'] ) : self::int( $d['qty'] ?? 0 );
		$f        = isset( $item['fit'] ) && is_array( $item['fit'] ) ? $item['fit'] : array();
		$w        = self::num( $d['widthMm'] ?? 0 );
		$h        = self::num( $d['heightMm'] ?? 0 );
		$longest  = max( $w, $h );
		$shortest = min( $w, $h );
		$aspect   = $shortest > 0 ? $longest / $shortest : 1.0;
		$land     = $w >= $h;
		$size     = self::num( $d['sizeCm'] ?? 0 );
		$type     = (string) ( $item['type'] ?? '' );
		$uses     = 'patch_on_blank' === $type || 'frame' === $type;

		if ( $uses ) {
			if ( ! $tech || ( array_key_exists( 'enabled', $tech ) && false === $tech['enabled'] ) || 'quote' === ( $tech['mode'] ?? null ) ) {
				return self::res( 'hide', 'technique' );
			}
			$t_min = max( 1, self::int( $tech['minQty'] ?? null ) ?: 1 );
			if ( $t_min > 1 && $cart_qty + self::default_qty( $item, $cart_qty ) < $t_min ) {
				return self::res( 'hide', 'min_qty' );
			}
			if ( ! ( $longest > 0 ) || ! ( $size > 0 ) ) {
				return self::res( 'hide', 'invalid' );
			}
		}

		switch ( (string) ( $item['id'] ?? '' ) ) {
			case 'cap':
				$max_h = self::num( $f['maxPatchHeightMm'] ?? null, 65 );
				$max_w = self::num( $f['maxPatchWidthMm'] ?? null, 110 );
				if ( $size > self::num( $f['maxSizeCm'] ?? null, 12 ) ) {
					return self::res( 'hide', 'too_large' );
				}
				if ( $h <= $max_h && $w <= $max_w ) {
					return self::res( 'ok', 'ok', array( 'patchSizeCm' => $size, 'placement' => (string) ( $item['placement'] ?? 'front' ) ) );
				}
				$target = $land && $aspect > self::num( $f['wideAspect'] ?? null, 1.6 ) ? self::num( $f['wideSizeCm'] ?? null, 10 ) : self::num( $f['suggestSizeCm'] ?? null, 6 );
				if ( $target >= $size || $target < self::num( $tech['minCm'] ?? null, 0 ) ) {
					return self::res( 'hide', 'too_large' );
				}
				$factor = ( $target * 10 ) / $longest;
				if ( $h * $factor > $max_h || $w * $factor > $max_w ) {
					return self::res( 'hide', 'too_large' );
				}
				if ( ! self::scale_ok( $d, $factor, $tech ) ) {
					return self::res( 'hide', 'too_fine' );
				}
				return self::res( 'adjust', 'size', array( 'patchSizeCm' => $target, 'placement' => (string) ( $item['placement'] ?? 'front' ), 'note' => 'size' ) );

			case 'beanie':
				$max_h = self::num( $f['maxPatchHeightMm'] ?? null, 55 );
				if ( $size > self::num( $f['maxSizeCm'] ?? null, 12 ) ) {
					return self::res( 'hide', 'too_large' );
				}
				$chosen = null;
				$factor = 1.0;
				$fit    = 'ok';
				if ( $h <= $max_h ) {
					$chosen = $size;
				} else {
					$list = isset( $f['sizesCm'] ) && is_array( $f['sizesCm'] ) ? $f['sizesCm'] : array( 6, 4 );
					foreach ( $list as $s ) {
						$sc = self::num( $s );
						if ( ! ( $sc > 0 ) || $sc >= $size || $sc < self::num( $tech['minCm'] ?? null, 0 ) ) {
							continue;
						}
						$fc = ( $sc * 10 ) / $longest;
						if ( $h * $fc <= $max_h && self::scale_ok( $d, $fc, $tech ) ) {
							$chosen = $sc;
							$factor = $fc;
							$fit    = 'adjust';
							break;
						}
					}
				}
				if ( null === $chosen ) {
					return self::res( 'hide', 'too_large' );
				}
				if ( $aspect > self::num( $f['maxAspect'] ?? null, 2.2 ) && null !== $d['minTextMm'] && $d['minTextMm'] * $factor < self::num( $f['minTextMmWide'] ?? null, 5 ) ) {
					return self::res( 'hide', 'wide_small_text' );
				}
				return self::res(
					$fit,
					'ok' === $fit ? 'ok' : 'size',
					array(
						'patchSizeCm' => $chosen,
						'placement'   => (string) ( $item['placement'] ?? 'cuff' ),
						'note'        => 'ok' === $fit ? null : 'size',
					)
				);

			case 'hoodie':
				$chest = self::num( $f['chestMaxCm'] ?? null, 10 );
				$back  = self::num( $f['backMaxCm'] ?? null, 12 );
				if ( $size <= $chest ) {
					return self::res( 'ok', 'ok', array( 'patchSizeCm' => $size, 'placement' => 'chest' ) );
				}
				if ( $size > $back ) {
					return self::res( 'hide', 'too_large' );
				}
				$fc  = $chest / $size;
				$alt = self::scale_ok( $d, $fc, $tech ) ? array( 'placement' => 'chest', 'patchSizeCm' => $chest ) : null;
				return self::res( 'adjust', 'back', array( 'patchSizeCm' => $size, 'placement' => 'back', 'note' => 'back', 'alt' => $alt ) );

			case 'pvc_keychain':
				if ( ! ( $cart_qty >= self::num( $item['showFromQty'] ?? null, 10 ) || 'pvc' === ( $d['technique'] ?? '' ) ) ) {
					return self::res( 'hide', 'qty' );
				}
				$classes = isset( $f['classes'] ) && is_array( $f['classes'] ) ? $f['classes'] : array( 'flat', 'illustration' );
				if ( ! in_array( $d['kind'], $classes, true ) ) {
					return self::res( 'hide', 'kind' );
				}
				if ( $d['colors'] > self::num( $f['hardMaxColors'] ?? null, 8 ) ) {
					return self::res( 'hide', 'colors' );
				}
				$factor = $longest > 0 ? self::num( $f['sizeMm'] ?? null, 50 ) / $longest : 1.0;
				$line   = null === $d['fineLineMm'] ? null : $d['fineLineMm'] * $factor;
				$text   = null === $d['minTextMm'] ? null : $d['minTextMm'] * $factor;
				if ( null !== $line && $line < self::num( $f['hardMinLineMm'] ?? null, 0.6 ) ) {
					return self::res( 'hide', 'too_fine' );
				}
				if ( null !== $text && $text < self::num( $f['hardMinTextMm'] ?? null, 3 ) ) {
					return self::res( 'hide', 'too_fine' );
				}
				$simplify = $d['colors'] > self::num( $f['maxColors'] ?? null, 6 )
					|| ( null !== $line && $line < self::num( $f['minLineMm'] ?? null, 1 ) )
					|| ( null !== $text && $text < self::num( $f['minTextMm'] ?? null, 4 ) );
				return $simplify ? self::res( 'adjust', 'simplify', array( 'note' => 'simplify' ) ) : self::res( 'ok', 'ok' );

			case 'leather_keychain':
			case 'leather_bagtag':
				return self::laser( $item, $d, $longest );

			case 'leather_patch_cap':
				$r = self::laser( $item, $d, $longest );
				if ( 'hide' !== $r['fit'] ) {
					$r['patchSizeCm'] = self::num( $item['patchSizeCm'] ?? null, 7 );
					$r['placement']   = (string) ( $item['placement'] ?? 'front' );
				}
				return $r;

			case 'frame':
				$sizes = array_values( array_filter( isset( $item['sizes'] ) && is_array( $item['sizes'] ) ? $item['sizes'] : array(), 'is_array' ) );
				usort( $sizes, static fn( $a, $b ) => self::num( $a['maxPatchCm'] ?? null ) <=> self::num( $b['maxPatchCm'] ?? null ) );
				foreach ( $sizes as $s ) {
					if ( $size <= self::num( $s['maxPatchCm'] ?? null ) ) {
						return self::res( 'ok', 'ok', array( 'patchSizeCm' => $size, 'frameSize' => (string) ( $s['id'] ?? '' ) ) );
					}
				}
				return self::res( 'hide', 'too_large' );
		}
		return self::res( 'hide', 'unknown_item' );
	}

	/**
	 * Grootste ΔL* tussen de hoofdkleuren (stof + garens met share ≥ $min_share). null = te weinig gegevens.
	 *
	 * @param array $d         Ontwerp.
	 * @param float $min_share Minimum aandeel.
	 */
	public static function max_delta_l( array $d, float $min_share ): ?float {
		$hexes = array();
		if ( ! empty( $d['fabricHex'] ) ) {
			$hexes[] = $d['fabricHex'];
		}
		foreach ( $d['threads'] ?? array() as $t ) {
			if ( $t['share'] >= $min_share && ! in_array( $t['hex'], $hexes, true ) ) {
				$hexes[] = $t['hex'];
			}
		}
		if ( count( $hexes ) < 2 ) {
			return null;
		}
		$ls   = array_map( static fn( $x ) => self::hex_to_lab( $x )[0], $hexes );
		$best = 0.0;
		$n    = count( $ls );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$dl = abs( $ls[ $i ] - $ls[ $j ] );
				if ( $dl > $best ) {
					$best = $dl;
				}
			}
		}
		return $best;
	}

	/**
	 * Standaardvariatie (§2.5): voorraad, beperkt tot defaultColors, hoogste ΔE2000 met de randkleur.
	 *
	 * @param array       $variations     [{id, colorSlug, colorHex, inStock}].
	 * @param string|null $edge_hex       Randkleur.
	 * @param array       $default_colors Toegelaten standaardkleuren (slugs).
	 */
	public static function pick_default_variation( array $variations, ?string $edge_hex, array $default_colors ): ?int {
		$in_stock = array_values( array_filter( $variations, static fn( $v ) => is_array( $v ) && ( ! array_key_exists( 'inStock', $v ) || false !== $v['inStock'] ) ) );
		if ( ! $in_stock ) {
			return null;
		}
		$pref = array_values( array_filter( $in_stock, static fn( $v ) => in_array( (string) ( $v['colorSlug'] ?? '' ), $default_colors, true ) ) );
		$pool = $pref ? $pref : $in_stock;
		$edge = self::norm_hex( $edge_hex );
		if ( null === $edge ) {
			return (int) $pool[0]['id'];
		}
		$edge_lab = self::hex_to_lab( $edge );
		$best     = $pool[0];
		$best_d   = -1.0;
		foreach ( $pool as $v ) {
			$hx = self::norm_hex( $v['colorHex'] ?? null );
			if ( null === $hx ) {
				continue;
			}
			$de = self::delta_e2000( self::hex_to_lab( $hx ), $edge_lab );
			if ( $de > $best_d + 1e-9 ) {
				$best_d = $de;
				$best   = $v;
			}
		}
		return (int) $best['id'];
	}

	/* ---------------------------------------------------------------------
	 * Kleurwiskunde (tweeling van shared/color-math.js)
	 * ------------------------------------------------------------------- */

	/**
	 * '#rrggbb' → [L, a, b] (D65).
	 *
	 * @param string $hex Hex.
	 */
	public static function hex_to_lab( string $hex ): array {
		if ( null === self::$lin ) {
			self::$lin = array();
			for ( $i = 0; $i < 256; $i++ ) {
				$c               = $i / 255;
				$v               = $c <= 0.04045 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
				self::$lin[ $i ] = unpack( 'g', pack( 'g', $v ) )[1];
			}
		}
		$h = ltrim( trim( $hex ), '#' );
		if ( 3 === strlen( $h ) ) {
			$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		$r  = self::$lin[ hexdec( substr( $h, 0, 2 ) ) & 255 ];
		$g  = self::$lin[ hexdec( substr( $h, 2, 2 ) ) & 255 ];
		$b  = self::$lin[ hexdec( substr( $h, 4, 2 ) ) & 255 ];
		$x  = ( 0.4124564 * $r + 0.3575761 * $g + 0.1804375 * $b ) / 0.95047;
		$y  = 0.2126729 * $r + 0.7151522 * $g + 0.072175 * $b;
		$z  = ( 0.0193339 * $r + 0.119192 * $g + 0.9503041 * $b ) / 1.08883;
		$fx = self::f_lab( $x );
		$fy = self::f_lab( $y );
		$fz = self::f_lab( $z );
		return array( 116 * $fy - 16, 500 * ( $fx - $fy ), 200 * ( $fy - $fz ) );
	}

	/**
	 * CIEDE2000 (Sharma et al. 2005), kL = kC = kH = 1.
	 *
	 * @param array $p Lab.
	 * @param array $q Lab.
	 */
	public static function delta_e2000( array $p, array $q ): float {
		list( $l1, $a1, $b1 ) = $p;
		list( $l2, $a2, $b2 ) = $q;
		$d2r  = M_PI / 180;
		$r2d  = 180 / M_PI;
		$c1   = hypot( $a1, $b1 );
		$c2   = hypot( $a2, $b2 );
		$cb   = ( $c1 + $c2 ) / 2;
		$g    = 0.5 * ( 1 - sqrt( $cb ** 7 / ( $cb ** 7 + 25 ** 7 ) ) );
		$a1p  = ( 1 + $g ) * $a1;
		$a2p  = ( 1 + $g ) * $a2;
		$c1p  = hypot( $a1p, $b1 );
		$c2p  = hypot( $a2p, $b2 );
		$hf   = static function ( $b, $a ) use ( $r2d ) {
			if ( 0.0 === (float) $b && 0.0 === (float) $a ) {
				return 0.0;
			}
			$v = atan2( $b, $a ) * $r2d;
			return $v < 0 ? $v + 360 : $v;
		};
		$h1p  = $hf( $b1, $a1p );
		$h2p  = $hf( $b2, $a2p );
		$dlp  = $l2 - $l1;
		$dcp  = $c2p - $c1p;
		$dhp  = 0.0;
		if ( 0.0 !== $c1p * $c2p ) {
			$dhp = $h2p - $h1p;
			if ( $dhp > 180 ) {
				$dhp -= 360;
			} elseif ( $dhp < -180 ) {
				$dhp += 360;
			}
		}
		$dh_p = 2 * sqrt( $c1p * $c2p ) * sin( ( $dhp * $d2r ) / 2 );
		$lbp  = ( $l1 + $l2 ) / 2;
		$cbp  = ( $c1p + $c2p ) / 2;
		$hbp  = $h1p + $h2p;
		if ( 0.0 !== $c1p * $c2p ) {
			if ( abs( $h1p - $h2p ) > 180 ) {
				$hbp = $h1p + $h2p < 360 ? $hbp + 360 : $hbp - 360;
			}
			$hbp /= 2;
		}
		$t   = 1 - 0.17 * cos( ( $hbp - 30 ) * $d2r ) + 0.24 * cos( 2 * $hbp * $d2r )
			+ 0.32 * cos( ( 3 * $hbp + 6 ) * $d2r ) - 0.2 * cos( ( 4 * $hbp - 63 ) * $d2r );
		$dth = 30 * exp( -( ( ( $hbp - 275 ) / 25 ) ** 2 ) );
		$rc  = 2 * sqrt( $cbp ** 7 / ( $cbp ** 7 + 25 ** 7 ) );
		$sl  = 1 + ( 0.015 * ( $lbp - 50 ) ** 2 ) / sqrt( 20 + ( $lbp - 50 ) ** 2 );
		$sc  = 1 + 0.045 * $cbp;
		$sh  = 1 + 0.015 * $cbp * $t;
		$rt  = -sin( 2 * $dth * $d2r ) * $rc;
		return sqrt( ( $dlp / $sl ) ** 2 + ( $dcp / $sc ) ** 2 + ( $dh_p / $sh ) ** 2 + $rt * ( $dcp / $sc ) * ( $dh_p / $sh ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Laser-pastoets (leder).
	 *
	 * @param array $item    Item.
	 * @param array $d       Ontwerp.
	 * @param float $longest Langste zijde (mm).
	 */
	private static function laser( array $item, array $d, float $longest ): array {
		$f = isset( $item['fit'] ) && is_array( $item['fit'] ) ? $item['fit'] : array();
		if ( 'photo' === $d['kind'] || 'gradient' === $d['kind'] ) {
			return self::res( 'adjust', $d['kind'], array( 'note' => $d['kind'] ) );
		}
		$dl = self::max_delta_l( $d, self::num( $f['minShare'] ?? null, 0.1 ) );
		if ( null !== $dl && $dl < self::num( $f['minDeltaL'] ?? null, 15 ) ) {
			return self::res( 'hide', 'contrast' );
		}
		$factor = $longest > 0 ? self::num( $f['sizeMm'] ?? null, 60 ) / $longest : 1.0;
		if ( null !== $d['fineLineMm'] && $d['fineLineMm'] * $factor < self::num( $f['minLineMm'] ?? null, 0.5 ) ) {
			return self::res( 'hide', 'too_fine' );
		}
		if ( null !== $d['minTextMm'] && $d['minTextMm'] * $factor < self::num( $f['minTextMm'] ?? null, 2.5 ) ) {
			return self::res( 'hide', 'too_fine' );
		}
		return self::res( 'ok', 'ok' );
	}

	/**
	 * Na schalen nog maakbaar volgens de harde grenzen van de techniek?
	 *
	 * @param array      $d      Ontwerp.
	 * @param float      $factor Schaalfactor.
	 * @param array|null $tech   Techniek.
	 */
	private static function scale_ok( array $d, float $factor, ?array $tech ): bool {
		$r    = is_array( $tech ) && isset( $tech['rules'] ) && is_array( $tech['rules'] ) ? $tech['rules'] : array();
		$text = isset( $r['textCapsMm'] ) && is_array( $r['textCapsMm'] ) ? self::num( $r['textCapsMm']['hard'] ?? null ) : 0.0;
		$line = isset( $r['lineMm'] ) && is_array( $r['lineMm'] ) ? self::num( $r['lineMm']['hard'] ?? null ) : 0.0;
		if ( null !== $d['minTextMm'] && $d['minTextMm'] * $factor < $text ) {
			return false;
		}
		if ( null !== $d['fineLineMm'] && $d['fineLineMm'] * $factor < $line ) {
			return false;
		}
		return true;
	}

	/**
	 * Resultaat-array.
	 *
	 * @param string $fit    ok|adjust|hide.
	 * @param string $reason Reden.
	 * @param array  $extra  Extra velden.
	 */
	private static function res( string $fit, string $reason, array $extra = array() ): array {
		return array_merge(
			array(
				'fit'         => $fit,
				'reason'      => $reason,
				'patchSizeCm' => null,
				'placement'   => null,
				'note'        => null,
				'frameSize'   => null,
				'alt'         => null,
			),
			$extra
		);
	}

	/**
	 * Lab f(t).
	 *
	 * @param float $t T.
	 */
	private static function f_lab( float $t ): float {
		return $t > 216 / 24389 ? ( $t < 0 ? -( ( -$t ) ** ( 1 / 3 ) ) : $t ** ( 1 / 3 ) ) : ( ( 24389 / 27 ) * $t + 16 ) / 116;
	}

	/**
	 * Hex normaliseren naar '#rrggbb' (kleine letters) of null.
	 *
	 * @param mixed $h Hex.
	 */
	public static function norm_hex( $h ): ?string {
		if ( ! is_string( $h ) || ! preg_match( '/^#?[0-9a-f]{6}$/i', trim( $h ) ) ) {
			return null;
		}
		return '#' . strtolower( ltrim( trim( $h ), '#' ) );
	}

	/**
	 * Getal (zoals JS num()).
	 *
	 * @param mixed $v Waarde.
	 * @param float $d Standaard.
	 */
	public static function num( $v, float $d = 0.0 ): float {
		if ( is_int( $v ) || is_float( $v ) ) {
			return is_finite( (float) $v ) ? (float) $v : $d;
		}
		if ( is_string( $v ) && '' !== trim( $v ) && is_numeric( trim( $v ) ) ) {
			return (float) trim( $v );
		}
		return $d;
	}

	/**
	 * Positief getal of null.
	 *
	 * @param mixed $v Waarde.
	 */
	private static function pos_or_null( $v ): ?float {
		$n = self::num( $v, NAN );
		return ! is_nan( $n ) && $n > 0 ? $n : null;
	}

	/**
	 * Geheel getal ≥ 0 (floor).
	 *
	 * @param mixed $v Waarde.
	 */
	public static function int( $v ): int {
		$n = self::num( $v );
		return $n > 0 ? (int) floor( $n ) : 0;
	}

	/**
	 * Associatieve array?
	 *
	 * @param mixed $v Waarde.
	 */
	private static function is_obj( $v ): bool {
		return is_array( $v ) && ( array() === $v || ! array_is_list( $v ) );
	}
}
