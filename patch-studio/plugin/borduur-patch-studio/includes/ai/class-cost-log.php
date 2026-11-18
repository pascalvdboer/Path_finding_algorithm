<?php
/**
 * Cost log: one row per provider call, no personal data.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

use BorduurPatchStudio\AI\Providers\Provider_Result;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §3.4 and §9. Sums are in micro-euro, per site-timezone day/month, cached for 60 s.
 */
final class Cost_Log {

	const CACHE = 'bps_ai_costsum';

	/**
	 * Log a call.
	 *
	 * @param array $row {job_id?, provider, model, op, images?, tokens_in?, tokens_out?, cost_usd, estimated?, duration_ms?, outcome, paid?}.
	 */
	public static function add( array $row ): void {
		$usd      = max( 0.0, (float) ( $row['cost_usd'] ?? 0 ) );
		$rate     = (float) AI_Settings::path( 'usdToEur', 0.93 );
		$rate     = $rate > 0 ? $rate : 0.93;
		$outcomes = array( 'ok', 'policy', 'error', 'timeout' );
		$ops      = array( 'generate', 'edit', 'vectorize', 'moderate', 'test' );
		Module::store()->cost_insert(
			array(
				'at'             => gmdate( 'Y-m-d H:i:s' ),
				'job_id'         => isset( $row['job_id'] ) ? substr( (string) $row['job_id'], 0, 26 ) : null,
				'provider'       => substr( (string) ( $row['provider'] ?? 'unknown' ), 0, 16 ),
				'model'          => substr( (string) ( $row['model'] ?? '' ), 0, 48 ),
				'op'             => in_array( $row['op'] ?? '', $ops, true ) ? $row['op'] : 'generate',
				'images'         => max( 0, min( 127, (int) ( $row['images'] ?? 0 ) ) ),
				'tokens_in'      => isset( $row['tokens_in'] ) ? (int) $row['tokens_in'] : null,
				'tokens_out'     => isset( $row['tokens_out'] ) ? (int) $row['tokens_out'] : null,
				'cost_usd_micro' => (int) round( $usd * 1000000 ),
				'estimated'      => empty( $row['estimated'] ) ? 0 : 1,
				'cost_eur_micro' => (int) round( $usd * $rate * 1000000 ),
				'duration_ms'    => max( 0, (int) ( $row['duration_ms'] ?? 0 ) ),
				'outcome'        => in_array( $row['outcome'] ?? '', $outcomes, true ) ? $row['outcome'] : 'error',
				'paid'           => empty( $row['paid'] ) ? 0 : 1,
			)
		);
		delete_transient( self::CACHE );
	}

	/**
	 * Log a provider result.
	 *
	 * @param Provider_Result $r      Result.
	 * @param string          $job_id Job id.
	 * @param string          $op     Operation.
	 * @param bool            $paid   Paid round.
	 */
	public static function add_result( Provider_Result $r, string $job_id, string $op, bool $paid ): void {
		self::add(
			array(
				'job_id'      => $job_id,
				'provider'    => $r->provider,
				'model'       => $r->model,
				'op'          => $op,
				'images'      => count( $r->images ),
				'tokens_in'   => $r->usage['tokens_in'],
				'tokens_out'  => $r->usage['tokens_out'],
				'cost_usd'    => $r->cost_usd,
				'estimated'   => $r->estimated,
				'duration_ms' => $r->duration_ms,
				'outcome'     => 'ok',
				'paid'        => $paid,
			)
		);
	}

	/**
	 * Spent today and this month (site timezone), in euro.
	 *
	 * @return array{day:float, month:float}
	 */
	public static function totals(): array {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) && isset( $cached['day'], $cached['month'] ) ) {
			return $cached;
		}
		list( $day_from, $day_to )     = self::range( 'day' );
		list( $month_from, $month_to ) = self::range( 'month' );
		$store                         = Module::store();
		$out                           = array(
			'day'   => $store->cost_sum( $day_from, $day_to ) / 1000000,
			'month' => $store->cost_sum( $month_from, $month_to ) / 1000000,
		);
		set_transient( self::CACHE, $out, MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * UTC bounds of the current site-timezone day or month.
	 *
	 * @param string $what day|month|last30.
	 * @return string[] [from, to)
	 */
	public static function range( string $what ): array {
		$tz  = self::timezone();
		$now = new \DateTimeImmutable( 'now', $tz );
		if ( 'month' === $what ) {
			$from = $now->modify( 'first day of this month' )->setTime( 0, 0 );
			$to   = $from->modify( '+1 month' );
		} elseif ( 'last30' === $what ) {
			$to   = $now->setTime( 0, 0 )->modify( '+1 day' );
			$from = $to->modify( '-30 days' );
		} else {
			$from = $now->setTime( 0, 0 );
			$to   = $from->modify( '+1 day' );
		}
		$utc = new \DateTimeZone( 'UTC' );
		return array( $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $to->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Site timezone.
	 */
	public static function timezone(): \DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}
		return new \DateTimeZone( 'Europe/Brussels' );
	}

	/**
	 * CSV of the last 12 months (no personal data: job ids are random and not linkable without the jobs table).
	 */
	public static function csv(): string {
		$tz   = self::timezone();
		$to   = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$from = gmdate( 'Y-m-d H:i:s', time() - 366 * DAY_IN_SECONDS );
		$rows = Module::store()->cost_rows( $from, $to, 50000 );
		$out  = fopen( 'php://temp', 'w+' );
		fputcsv( $out, array( 'datum', 'provider', 'model', 'bewerking', 'beelden', 'tokens_in', 'tokens_out', 'kost_usd', 'kost_eur', 'geschat', 'duur_ms', 'uitkomst', 'betaald' ), ';' );
		foreach ( $rows as $r ) {
			$local = ( new \DateTimeImmutable( (string) $r['at'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d H:i:s' );
			fputcsv(
				$out,
				array(
					$local,
					$r['provider'],
					$r['model'],
					$r['op'],
					(int) $r['images'],
					null === $r['tokens_in'] ? '' : (int) $r['tokens_in'],
					null === $r['tokens_out'] ? '' : (int) $r['tokens_out'],
					number_format( (int) $r['cost_usd_micro'] / 1000000, 6, ',', '' ),
					number_format( (int) $r['cost_eur_micro'] / 1000000, 6, ',', '' ),
					(int) $r['estimated'] ? 'ja' : 'nee',
					(int) $r['duration_ms'],
					$r['outcome'],
					(int) $r['paid'] ? 'ja' : 'nee',
				),
				';'
			);
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );
		return "\xEF\xBB\xBF" . $csv;
	}
}
