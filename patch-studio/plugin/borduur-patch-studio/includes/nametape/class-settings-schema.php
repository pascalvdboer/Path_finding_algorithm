<?php
/**
 * Name tapes: sanitising the admin form and computing the stored overrides (SPEC-EXTRA §1.12). Pure class, no WordPress:
 * the admin page (Admin) passes the raw POST array, gets back a full, safe config and the minimal diff to store.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Nametape;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BPS_TESTING' ) ) {
	exit;
}

/**
 * Which keys the owner may change, with bounds.
 */
final class Settings_Schema {

	/** Lists with per-entry toggles that must keep at least one usable entry. */
	const NEED_ONE = array( 'formats', 'fabrics', 'letterColors', 'fonts', 'borders', 'backings' );

	/** Name-tape retention after completion (days): default, bounds. */
	const RETENTION_DEFAULT = 180;
	const RETENTION_MIN     = 30;
	const RETENTION_MAX     = 3650;

	/**
	 * Bounded float with 2 decimals.
	 *
	 * @param mixed $v   Raw.
	 * @param float $min Min.
	 * @param float $max Max.
	 * @param float $def Default when not numeric.
	 */
	public static function money( $v, float $min, float $max, float $def ): float {
		$s = is_string( $v ) ? str_replace( array( ' ', '€' ), '', str_replace( ',', '.', $v ) ) : $v;
		if ( ! is_numeric( $s ) ) {
			return $def;
		}
		return round( max( $min, min( $max, (float) $s ) ), 2 );
	}

	/**
	 * Bounded int.
	 *
	 * @param mixed $v   Raw.
	 * @param int   $min Min.
	 * @param int   $max Max.
	 * @param int   $def Default.
	 */
	public static function int( $v, int $min, int $max, int $def ): int {
		if ( ! is_numeric( $v ) ) {
			return $def;
		}
		return max( $min, min( $max, (int) $v ) );
	}

	/**
	 * Checkbox value.
	 *
	 * @param array  $a   Array.
	 * @param string $key Key.
	 */
	private static function on( array $a, string $key ): bool {
		return isset( $a[ $key ] ) && in_array( (string) $a[ $key ], array( '1', 'on', 'yes', 'true' ), true );
	}

	/**
	 * Plain one-line text (no tags, no control chars), max length in code points.
	 *
	 * @param mixed $v   Raw.
	 * @param int   $max Max length.
	 */
	public static function text( $v, int $max = 200 ): string {
		$s = is_scalar( $v ) ? (string) $v : '';
		$s = preg_replace( '/<[^>]*>/', '', $s ) ?? '';
		$s = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $s ) ?? '';
		$s = trim( preg_replace( '/\s+/u', ' ', $s ) ?? '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max, 'UTF-8' ) : substr( $s, 0, $max );
	}

	/**
	 * Review words from a textarea: one per line or comma separated, uppercased, unique, max 60 × 40 chars.
	 *
	 * @param mixed $v Raw.
	 * @return string[]
	 */
	public static function words( $v ): array {
		$parts = preg_split( '/[\r\n,;]+/u', is_scalar( $v ) ? (string) $v : '' ) ?: array();
		$out   = array();
		foreach ( $parts as $p ) {
			$p = self::text( $p, 40 );
			$p = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $p, 'UTF-8' ) : strtoupper( $p );
			if ( '' !== $p && ! in_array( $p, $out, true ) ) {
				$out[] = $p;
			}
			if ( count( $out ) >= 60 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Full sanitised config: defaults, current values and the posted form merged. Unknown keys are ignored.
	 *
	 * @param array $post     Posted `bps_nt` array (unslashed).
	 * @param array $current  Current merged config.
	 * @param array $defaults Defaults (nametape.json).
	 * @param array $shipping_classes Allowed shipping-class slugs.
	 * @return array{config:array,notices:string[]}
	 */
	public static function sanitize( array $post, array $current, array $defaults, array $shipping_classes = array() ): array {
		$c       = $current;
		$notices = array();

		$c['enabled']     = self::on( $post, 'enabled' );
		$c['irOnRequest'] = self::on( $post, 'irOnRequest' );
		$c['maxRows']     = self::int( $post['maxRows'] ?? null, 10, 500, (int) ( $current['maxRows'] ?? 250 ) );

		$c['text']['uppercaseDefault'] = self::on( (array) ( $post['text'] ?? array() ), 'uppercaseDefault' );

		$per_entry = array(
			'formats'      => array(
				'priceExcl' => array( 0.0, 100.0 ),
				'toggle'    => 'enabled',
			),
			'fabrics'      => array(
				'surchargeExcl' => array( -5.0, 20.0 ),
				'toggle'        => 'inStock',
			),
			'letterColors' => array( 'toggle' => 'enabled' ),
			'fonts'        => array( 'toggle' => 'enabled' ),
			'borders'      => array( 'toggle' => 'enabled' ),
			'backings'     => array(
				'deltaExcl' => array( -5.0, 20.0 ),
				'toggle'    => 'enabled',
			),
		);
		foreach ( $per_entry as $list => $fields ) {
			$in = isset( $post[ $list ] ) && is_array( $post[ $list ] ) ? $post[ $list ] : array();
			foreach ( (array) ( $c[ $list ] ?? array() ) as $i => $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['id'] ) ) {
					continue;
				}
				$row = isset( $in[ $entry['id'] ] ) && is_array( $in[ $entry['id'] ] ) ? $in[ $entry['id'] ] : array();
				foreach ( $fields as $f => $bounds ) {
					// Only touch a value when it really changes, so untouched lists stay equal to the defaults.
					if ( 'toggle' === $f ) {
						$val = self::on( $row, $bounds );
						if ( $val !== ( false !== ( $entry[ $bounds ] ?? true ) ) ) {
							$c[ $list ][ $i ][ $bounds ] = $val;
						}
						continue;
					}
					if ( array_key_exists( $f, $row ) ) {
						$val = self::money( $row[ $f ], $bounds[0], $bounds[1], (float) ( $entry[ $f ] ?? 0 ) );
						if ( abs( $val - (float) ( $entry[ $f ] ?? 0 ) ) > 0.0001 ) {
							$c[ $list ][ $i ][ $f ] = $val;
						}
					}
				}
				if ( 'letterColors' === $list && array_key_exists( 'threadCode', $row ) && self::text( $row['threadCode'], 40 ) !== (string) ( $entry['threadCode'] ?? '' ) ) {
					$c[ $list ][ $i ]['threadCode'] = self::text( $row['threadCode'], 40 );
				}
			}
			// Keep at least one usable entry (else the page cannot work): re-enable the default one.
			$toggle = $fields['toggle'];
			$usable = array_filter( (array) $c[ $list ], static fn( $e ) => is_array( $e ) && false !== ( $e[ $toggle ] ?? true ) );
			if ( ! $usable && ! empty( $c[ $list ] ) ) {
				$pick = 0;
				foreach ( $c[ $list ] as $i => $e ) {
					if ( ! empty( $e['default'] ) ) {
						$pick = $i;
						break;
					}
				}
				$c[ $list ][ $pick ][ $toggle ] = true;
				$notices[]                      = $list;
			}
		}

		$cs                           = (array) ( $post['customSize'] ?? array() );
		$c['customSize']['enabled']   = self::on( $cs, 'enabled' );
		$lc                           = (array) ( $post['loopCounterpart'] ?? array() );
		$c['loopCounterpart']['enabled']   = self::on( $lc, 'enabled' );
		$c['loopCounterpart']['priceExcl'] = self::money( $lc['priceExcl'] ?? null, 0.0, 20.0, (float) ( $current['loopCounterpart']['priceExcl'] ?? 1.0 ) );

		$c['tiers']['pooledWithPatches'] = self::on( (array) ( $post['tiers'] ?? array() ), 'pooledWithPatches' );

		$fee                              = (array) ( $post['smallOrderFee'] ?? array() );
		$c['smallOrderFee']['enabled']    = self::on( $fee, 'enabled' );
		$c['smallOrderFee']['belowQty']   = self::int( $fee['belowQty'] ?? null, 1, 100, (int) ( $current['smallOrderFee']['belowQty'] ?? 5 ) );
		$c['smallOrderFee']['amountExcl'] = self::money( $fee['amountExcl'] ?? null, 0.0, 100.0, (float) ( $current['smallOrderFee']['amountExcl'] ?? 7.5 ) );

		$blood                  = (array) ( $post['blood'] ?? array() );
		$c['blood']['notation'] = in_array( (string) ( $blood['notation'] ?? '' ), array( 'O POS', 'O+' ), true ) ? (string) $blood['notation'] : (string) ( $current['blood']['notation'] ?? 'O POS' );

		if ( array_key_exists( 'reviewWords', $post ) ) {
			$c['reviewWords'] = self::words( $post['reviewWords'] );
		}

		$pr                       = (array) ( $post['promises'] ?? array() );
		$c['promises']['proof']   = self::on( $pr, 'proof' ) ? true : '';
		$lead                     = (array) ( $pr['leadTime'] ?? array() );
		$lead_nl                  = self::text( $lead['nl'] ?? '', 160 );
		$lead_fr                  = self::text( $lead['fr'] ?? '', 160 );
		$c['promises']['leadTime'] = ( '' === $lead_nl && '' === $lead_fr ) ? '' : array(
			'nl' => $lead_nl,
			'fr' => '' !== $lead_fr ? $lead_fr : $lead_nl,
		);

		// Privacy (gdpr-juridisch-1): days after completion before names and blood groups are anonymised.
		// Only touched when the form sends the field, so older admin forms keep the stored value.
		if ( isset( $post['retention'] ) && is_array( $post['retention'] ) && array_key_exists( 'daysAfterComplete', $post['retention'] ) ) {
			$c['retention']                      = isset( $c['retention'] ) && is_array( $c['retention'] ) ? $c['retention'] : array();
			$c['retention']['daysAfterComplete'] = self::int( $post['retention']['daysAfterComplete'], self::RETENTION_MIN, self::RETENTION_MAX, (int) ( $current['retention']['daysAfterComplete'] ?? self::RETENTION_DEFAULT ) );
		}

		$woo  = (array) ( $post['woo'] ?? array() );
		$slug = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) ( $woo['shippingClassSlug'] ?? '' ) ) ?? '' );
		$c['woo']['shippingClassSlug'] = ( '' === $slug || in_array( $slug, $shipping_classes, true ) ) ? $slug : (string) ( $current['woo']['shippingClassSlug'] ?? '' );

		return array(
			'config'  => $c,
			'notices' => $notices,
		);
	}

	/**
	 * Is the array a list?
	 *
	 * @param mixed $v Value.
	 */
	private static function is_list( $v ): bool {
		return is_array( $v ) && ( array() === $v || array_is_list( $v ) );
	}

	/**
	 * Minimal overrides: only keys that differ from the defaults; lists are stored whole (deep_merge replaces lists).
	 *
	 * @param array $full     Full config.
	 * @param array $defaults Defaults.
	 */
	public static function diff( array $full, array $defaults ): array {
		$out = array();
		foreach ( $full as $k => $v ) {
			if ( ! array_key_exists( $k, $defaults ) ) {
				$out[ $k ] = $v;
				continue;
			}
			$d = $defaults[ $k ];
			if ( is_array( $v ) && is_array( $d ) && ! self::is_list( $v ) && ! self::is_list( $d ) ) {
				$sub = self::diff( $v, $d );
				if ( $sub ) {
					$out[ $k ] = $sub;
				}
				continue;
			}
			if ( self::canon( $v ) !== self::canon( $d ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Canonical JSON for comparison (ints and floats alike).
	 *
	 * @param mixed $v Value.
	 */
	private static function canon( $v ): string {
		$norm = static function ( $x ) use ( &$norm ) {
			if ( is_int( $x ) ) {
				return (float) $x;
			}
			if ( is_array( $x ) ) {
				if ( ! array_is_list( $x ) ) {
					ksort( $x );
				}
				return array_map( $norm, $x );
			}
			return $x;
		};
		return (string) json_encode( $norm( $v ), JSON_PRESERVE_ZERO_FRACTION ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}
