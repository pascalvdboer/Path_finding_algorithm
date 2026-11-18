<?php
/**
 * Cross-sell kaartkeuze (SPEC-EXTRA §2.1). PHP-tweeling van assets/src/crosssell/rules.js.
 * De volgorde volgt de bestelling en de pastoets, nooit de marge.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Crosssell;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BPS_TESTING' ) ) {
	exit;
}

/**
 * Pure kaartkeuze.
 */
final class Rules {

	/**
	 * Past een regel bij de bestelcontext?
	 *
	 * @param mixed $when Voorwaarden.
	 * @param array $c    ['qty','technique','kind','month','mode'].
	 */
	public static function matches( $when, array $c ): bool {
		if ( ! is_array( $when ) ) {
			return true;
		}
		if ( array_key_exists( 'qtyMin', $when ) && ! ( $c['qty'] >= self::num( $when['qtyMin'] ) ) ) {
			return false;
		}
		if ( array_key_exists( 'qtyMax', $when ) && ! ( $c['qty'] <= self::num( $when['qtyMax'] ) ) ) {
			return false;
		}
		if ( isset( $when['class'] ) && is_array( $when['class'] ) && ! in_array( $c['kind'], $when['class'], true ) ) {
			return false;
		}
		if ( isset( $when['technique'] ) && is_array( $when['technique'] ) && ! in_array( $c['technique'], $when['technique'], true ) ) {
			return false;
		}
		if ( isset( $when['mode'] ) && is_string( $when['mode'] ) && $when['mode'] !== ( '' !== $c['mode'] ? $c['mode'] : 'patch' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Kies de kaarten (twin van pick()).
	 *
	 * @param array $fits    item-id → Fit::item()-resultaat (enkel kandidaten met een koopbaar product).
	 * @param array $context ['qty','technique','kind','month','mode'].
	 * @param array $cfg     Cross-sell-config.
	 * @param array $stats   item-id → ['autoDisabled' => bool].
	 * @return array{ids:string[],rule:string|null}
	 */
	public static function pick( array $fits, array $context, array $cfg, array $stats = array() ): array {
		$items = array_values( array_filter( isset( $cfg['items'] ) && is_array( $cfg['items'] ) ? $cfg['items'] : array(), 'is_array' ) );
		$max   = max( 0, (int) floor( self::num( $cfg['maxCards'] ?? null, 3 ) ) );
		$c     = array(
			'qty'       => self::num( $context['qty'] ?? null ),
			'technique' => (string) ( $context['technique'] ?? '' ),
			'kind'      => (string) ( ( $context['kind'] ?? '' ) ?: 'flat' ),
			'month'     => (int) floor( self::num( $context['month'] ?? null, 1 ) ),
			'mode'      => (string) ( ( $context['mode'] ?? '' ) ?: 'patch' ),
		);
		$usable = static function ( string $id ) use ( $items, $fits, $stats ): bool {
			$it = null;
			foreach ( $items as $i ) {
				if ( ( $i['id'] ?? null ) === $id ) {
					$it = $i;
					break;
				}
			}
			if ( ! $it || ( array_key_exists( 'enabled', $it ) && false === $it['enabled'] ) ) {
				return false;
			}
			if ( isset( $stats[ $id ] ) && is_array( $stats[ $id ] ) && ! empty( $stats[ $id ]['autoDisabled'] ) ) {
				return false;
			}
			$f = $fits[ $id ] ?? null;
			return is_array( $f ) && in_array( $f['fit'] ?? '', array( 'ok', 'adjust' ), true );
		};

		$rule = null;
		foreach ( ( isset( $cfg['rules'] ) && is_array( $cfg['rules'] ) ? $cfg['rules'] : array() ) as $r ) {
			if ( is_array( $r ) && self::matches( $r['when'] ?? null, $c ) ) {
				$rule = $r;
				break;
			}
		}
		$order = $rule && isset( $rule['order'] ) && is_array( $rule['order'] ) ? array_map( 'strval', $rule['order'] ) : array();
		if ( $rule && isset( $rule['boost'] ) && is_array( $rule['boost'] ) && isset( $rule['boost']['months'] ) && is_array( $rule['boost']['months'] )
			&& in_array( $c['month'], array_map( 'intval', $rule['boost']['months'] ), true ) ) {
			$b = (string) ( $rule['boost']['item'] ?? '' );
			if ( in_array( $b, $order, true ) ) {
				$order = array_merge( array( $b ), array_values( array_filter( $order, static fn( $x ) => $x !== $b ) ) );
			}
		}
		$out = array();
		foreach ( $order as $id ) {
			if ( $usable( $id ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		foreach ( $items as $it ) {
			$id = (string) ( $it['id'] ?? '' );
			if ( '' !== $id && $usable( $id ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return array(
			'ids'  => array_slice( $out, 0, $max ),
			'rule' => $rule ? (string) ( $rule['id'] ?? '' ) : null,
		);
	}

	/**
	 * Getal.
	 *
	 * @param mixed $v Waarde.
	 * @param float $d Standaard.
	 */
	private static function num( $v, float $d = 0.0 ): float {
		return ( is_int( $v ) || is_float( $v ) ) && is_finite( (float) $v ) ? (float) $v : $d;
	}
}
