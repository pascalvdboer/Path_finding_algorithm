<?php
/**
 * Calculated delivery estimate (concurrentie-features-7, contract C5): business days with Belgian public holidays
 * and the shop's closing periods. Only when the owner configured every value: never an invented date.
 *
 * Pure: no WordPress functions, so it is loadable standalone with define( 'BPS_TESTING', true ).
 *
 * Settings (leadTime.*, PLAN.md §4, written by P08; read with safe fallbacks):
 *   productionDays: { <technique>: int | [min,max] | null }   null / missing = not configured
 *   proofDays:      int | [min,max] | null                     days for the digital proof round
 *   shippingDays:   int | [min,max] | null                     one value for Belgium
 *   closures:       [ { from: 'YYYY-MM-DD', to: 'YYYY-MM-DD' } ] closing periods (inclusive)
 *   holidays:       bool (default true)                        skip Belgian public holidays
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BPS_TESTING' ) ) {
	exit;
}

/**
 * Lead time → {from, to} ISO dates or null.
 */
final class Lead_Time {

	/** Safety cap: never loop longer than this many calendar days (misconfigured closures). */
	const MAX_SCAN_DAYS = 800;

	/**
	 * Estimate for one technique.
	 *
	 * Counting starts on the first business day after $now (the order day itself never counts). Proof, production
	 * and shipping are business days; the result is the business day on which the parcel is expected.
	 *
	 * @param array                   $config    Full config (uses $config['leadTime']).
	 * @param string                  $technique Technique id.
	 * @param \DateTimeImmutable|null $now       Order moment (default: now in the site timezone).
	 * @return array{from:string,to:string,businessDays:array{from:int,to:int}}|null
	 */
	public static function estimate( array $config, string $technique, ?\DateTimeImmutable $now = null ): ?array {
		$lt = isset( $config['leadTime'] ) && is_array( $config['leadTime'] ) ? $config['leadTime'] : array();
		if ( array_key_exists( 'enabled', $lt ) && ! $lt['enabled'] ) {
			return null;
		}
		$prod_all = isset( $lt['productionDays'] ) && is_array( $lt['productionDays'] ) ? $lt['productionDays'] : array();
		$prod     = self::range( $prod_all[ $technique ] ?? null );
		$proof    = self::range( $lt['proofDays'] ?? null );
		$ship     = self::range( $lt['shippingDays'] ?? null );
		if ( null === $prod || null === $proof || null === $ship ) {
			return null;
		}
		$min = $prod[0] + $proof[0] + $ship[0];
		$max = $prod[1] + $proof[1] + $ship[1];
		if ( $min < 1 ) {
			return null;
		}
		$now      = $now ?? self::now();
		$closures = self::closures( $lt['closures'] ?? array() );
		$holidays = ! array_key_exists( 'holidays', $lt ) || false !== $lt['holidays'];
		$from     = self::add_business_days( $now, $min, $closures, $holidays );
		$to       = self::add_business_days( $now, $max, $closures, $holidays );
		if ( null === $from || null === $to ) {
			return null;
		}
		return array(
			'from'         => $from->format( 'Y-m-d' ),
			'to'           => $to->format( 'Y-m-d' ),
			'businessDays' => array(
				'from' => $min,
				'to'   => $max,
			),
		);
	}

	/**
	 * Estimate for several techniques (an order): the latest from/to wins. Null as soon as one is unknown.
	 *
	 * @param array                   $config     Config.
	 * @param string[]                $techniques Technique ids.
	 * @param \DateTimeImmutable|null $now        Order moment.
	 */
	public static function estimate_many( array $config, array $techniques, ?\DateTimeImmutable $now = null ): ?array {
		$techniques = array_values( array_unique( array_filter( array_map( 'strval', $techniques ) ) ) );
		if ( ! $techniques ) {
			return null;
		}
		$out = null;
		foreach ( $techniques as $t ) {
			$e = self::estimate( $config, $t, $now );
			if ( null === $e ) {
				return null;
			}
			if ( null === $out ) {
				$out = $e;
				continue;
			}
			$out['from']                 = max( $out['from'], $e['from'] );
			$out['to']                   = max( $out['to'], $e['to'] );
			$out['businessDays']['from'] = max( $out['businessDays']['from'], $e['businessDays']['from'] );
			$out['businessDays']['to']   = max( $out['businessDays']['to'], $e['businessDays']['to'] );
		}
		return $out;
	}

	/**
	 * Add N business days, starting after $start.
	 *
	 * @param \DateTimeImmutable $start    Start (not counted).
	 * @param int                $days     Business days.
	 * @param array              $closures [[from, to], …] as Y-m-d strings.
	 * @param bool               $holidays Skip Belgian public holidays.
	 */
	public static function add_business_days( \DateTimeImmutable $start, int $days, array $closures = array(), bool $holidays = true ): ?\DateTimeImmutable {
		$d    = $start->setTime( 12, 0 );
		$left = max( 0, $days );
		$scan = 0;
		while ( $left > 0 ) {
			$d = $d->modify( '+1 day' );
			if ( ++$scan > self::MAX_SCAN_DAYS ) {
				return null;
			}
			if ( self::is_business_day( $d, $closures, $holidays ) ) {
				--$left;
			}
		}
		return $d;
	}

	/**
	 * Mon-Fri, not a public holiday, not in a closing period.
	 *
	 * @param \DateTimeImmutable $d        Day.
	 * @param array              $closures Closures.
	 * @param bool               $holidays Skip holidays.
	 */
	public static function is_business_day( \DateTimeImmutable $d, array $closures = array(), bool $holidays = true ): bool {
		if ( (int) $d->format( 'N' ) >= 6 ) {
			return false;
		}
		$ymd = $d->format( 'Y-m-d' );
		if ( $holidays && in_array( $ymd, self::holidays_be( (int) $d->format( 'Y' ) ), true ) ) {
			return false;
		}
		foreach ( $closures as $c ) {
			if ( $ymd >= $c[0] && $ymd <= $c[1] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Belgian legal public holidays of a year (fixed + Easter-dependent).
	 *
	 * @param int $year Year.
	 * @return string[] Y-m-d.
	 */
	public static function holidays_be( int $year ): array {
		static $memo = array();
		if ( isset( $memo[ $year ] ) ) {
			return $memo[ $year ];
		}
		$easter        = self::easter( $year );
		$list          = array(
			sprintf( '%04d-01-01', $year ),                         // Nieuwjaar.
			$easter->modify( '+1 day' )->format( 'Y-m-d' ),         // Paasmaandag.
			sprintf( '%04d-05-01', $year ),                         // Dag van de Arbeid.
			$easter->modify( '+39 days' )->format( 'Y-m-d' ),       // O.L.H. Hemelvaart.
			$easter->modify( '+50 days' )->format( 'Y-m-d' ),       // Pinkstermaandag.
			sprintf( '%04d-07-21', $year ),                         // Nationale feestdag.
			sprintf( '%04d-08-15', $year ),                         // O.L.V. Hemelvaart.
			sprintf( '%04d-11-01', $year ),                         // Allerheiligen.
			sprintf( '%04d-11-11', $year ),                         // Wapenstilstand.
			sprintf( '%04d-12-25', $year ),                         // Kerstmis.
		);
		$memo[ $year ] = $list;
		return $list;
	}

	/**
	 * Easter Sunday (Gregorian, anonymous algorithm; no calendar extension needed).
	 *
	 * @param int $year Year.
	 */
	public static function easter( int $year ): \DateTimeImmutable {
		$a     = $year % 19;
		$b     = intdiv( $year, 100 );
		$c     = $year % 100;
		$d     = intdiv( $b, 4 );
		$e     = $b % 4;
		$f     = intdiv( $b + 8, 25 );
		$g     = intdiv( $b - $f + 1, 3 );
		$h     = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i     = intdiv( $c, 4 );
		$k     = $c % 4;
		$l     = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m     = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$month = intdiv( $h + $l - 7 * $m + 114, 31 );
		$day   = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;
		return new \DateTimeImmutable( sprintf( '%04d-%02d-%02d 12:00:00', $year, $month, $day ), new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * [min, max] from int | [min, max] | {min, max}; null when not configured.
	 *
	 * @param mixed $v Value.
	 * @return array{0:int,1:int}|null
	 */
	private static function range( $v ): ?array {
		if ( null === $v || '' === $v || false === $v ) {
			return null;
		}
		if ( is_numeric( $v ) ) {
			$n = (int) $v;
			return $n >= 0 ? array( $n, $n ) : null;
		}
		if ( is_array( $v ) ) {
			$lo = $v['min'] ?? ( $v[0] ?? null );
			$hi = $v['max'] ?? ( $v[1] ?? $lo );
			if ( ! is_numeric( $lo ) || ! is_numeric( $hi ) ) {
				return null;
			}
			$lo = (int) $lo;
			$hi = (int) $hi;
			if ( $lo < 0 || $hi < $lo ) {
				return null;
			}
			return array( $lo, $hi );
		}
		return null;
	}

	/**
	 * Valid closing periods as [from, to] Y-m-d pairs.
	 *
	 * @param mixed $list Closures.
	 */
	private static function closures( $list ): array {
		$out = array();
		foreach ( is_array( $list ) ? $list : array() as $c ) {
			$from = is_array( $c ) ? (string) ( $c['from'] ?? '' ) : '';
			$to   = is_array( $c ) ? (string) ( $c['to'] ?? $from ) : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) && $to >= $from ) {
				$out[] = array( $from, $to );
			}
		}
		return $out;
	}

	/**
	 * Now in the site timezone.
	 */
	private static function now(): \DateTimeImmutable {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'Europe/Brussels' );
		return new \DateTimeImmutable( 'now', $tz );
	}

	/**
	 * Human date range for customers and the fiche: "di 24 nov – do 26 nov" / "mar. 24 nov. – jeu. 26 nov.".
	 *
	 * @param array  $estimate {from, to}.
	 * @param string $lang     nl | fr.
	 */
	public static function format( array $estimate, string $lang = 'nl' ): string {
		$days   = 'fr' === $lang ? array( 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.' ) : array( 'ma', 'di', 'wo', 'do', 'vr', 'za', 'zo' );
		$months = 'fr' === $lang
			? array( 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.' )
			: array( 'jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec' );
		$fmt    = static function ( string $ymd ) use ( $days, $months ): string {
			$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd );
			if ( ! $d ) {
				return $ymd;
			}
			return $days[ (int) $d->format( 'N' ) - 1 ] . ' ' . (int) $d->format( 'j' ) . ' ' . $months[ (int) $d->format( 'n' ) - 1 ];
		};
		$from   = (string) ( $estimate['from'] ?? '' );
		$to     = (string) ( $estimate['to'] ?? $from );
		return $from === $to ? $fmt( $from ) : $fmt( $from ) . ' – ' . $fmt( $to );
	}
}
