<?php
/**
 * Rate limits, budget (day/month/hard cap with reservations), per-owner limits, abuse pause, alert mails.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §4.1 (rate buckets), §8 (abuse pause), §9 (budget). Counters live in transients keyed by HMACs,
 * never by raw IP or e-mail. Money is in micro-euro internally.
 */
final class Limits {

	const RESERVATIONS = 'bps_ai_reservations';
	const ALERTS       = 'bps_ai_alerts_sent';
	const REFUSALS     = 'bps_ai_refusal_counts';

	/** @var int|null Clock override (tests). */
	public static $now = null;

	/**
	 * Current unix time.
	 */
	public static function now(): int {
		return null !== self::$now ? self::$now : time();
	}

	/* ---------------------------------------------------------------------
	 * Rate buckets per IP hash
	 * ------------------------------------------------------------------- */

	/**
	 * Fixed-window rate limit. Buckets: ai_write (30/h), ai_poll (120/min), ai_email (5/h).
	 *
	 * @param string $bucket  Bucket.
	 * @param string $ip_hash IP hash.
	 * @return true|WP_Error
	 */
	public static function rate( string $bucket, string $ip_hash ) {
		switch ( $bucket ) {
			case 'ai_poll':
				$max    = (int) AI_Settings::limit( 'pollPerMinute' );
				$window = MINUTE_IN_SECONDS;
				break;
			case 'ai_email':
				$max    = (int) AI_Settings::limit( 'emailPerHour' );
				$window = HOUR_IN_SECONDS;
				break;
			default:
				$bucket = 'ai_write';
				$max    = (int) AI_Settings::limit( 'writePerHour' );
				$window = HOUR_IN_SECONDS;
		}
		$max = (int) apply_filters( 'bps_ai_rate_limit', $max, $bucket );
		if ( $max <= 0 ) {
			return true;
		}
		$slot = (int) floor( self::now() / $window );
		$key  = 'bps_ai_rl_' . substr( hash( 'sha256', $ip_hash . '|' . $bucket . '|' . $slot ), 0, 32 );
		$hits = (int) get_transient( $key );
		if ( $hits >= $max ) {
			$retry = ( $slot + 1 ) * $window - self::now();
			return Messages::error( 'bps_ai_slow_down', 429, array( 'retryAfter' => max( 1, $retry ) ) );
		}
		set_transient( $key, $hits + 1, $window + 60 );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Admission of a new round
	 * ------------------------------------------------------------------- */

	/**
	 * Check every limit for a new round and reserve its estimated cost. Call release() when the job ends
	 * (or right away when the round does not start after all).
	 *
	 * @param string $owner   Owner key.
	 * @param string $ip_hash IP hash.
	 * @param bool   $paid    The round will be paid (free rounds are paused first when the budget runs low).
	 * @param string $job_id  Job id (reservation key).
	 * @param float  $est_eur Estimated cost in euro.
	 * @return true|WP_Error
	 */
	public static function admit( string $owner, string $ip_hash, bool $paid, string $job_id, float $est_eur ) {
		$budget = self::budget();
		if ( $budget['hardCapped'] ) {
			return Messages::error( 'bps_ai_budget', 503, array( 'freeOnly' => false ) );
		}
		if ( ! $paid && $budget['freePaused'] ) {
			return Messages::error( 'bps_ai_budget', 503, array( 'freeOnly' => true ) );
		}
		$okey = self::hkey( $owner );
		$day  = 'bps_ai_day_' . $okey . '_' . self::local_day();
		if ( (int) get_transient( $day ) >= (int) AI_Settings::limit( 'perOwnerPerDay' ) ) {
			return Messages::error( 'bps_ai_daily_limit', 429 );
		}
		$gap = (int) AI_Settings::limit( 'minSecondsBetween' );
		if ( $gap > 0 ) {
			foreach ( array( 'bps_ai_last_o_' . $okey, 'bps_ai_last_i_' . self::hkey( $ip_hash ) ) as $k ) {
				$last = (int) get_transient( $k );
				if ( $last > 0 && self::now() - $last < $gap ) {
					return Messages::error( 'bps_ai_slow_down', 429, array( 'retryAfter' => $gap - ( self::now() - $last ) ) );
				}
			}
		}
		if ( Module::store()->job_count_status( 'running' ) + Module::store()->job_count_status( 'queued' ) >= 4 * (int) AI_Settings::limit( 'globalConcurrent' ) ) {
			// Queue far longer than the provider can work off within the job timeout: fail fast and honest.
			return Messages::error( 'bps_ai_budget', 503, array( 'freeOnly' => false, 'busy' => true ) );
		}
		// Budget incl. this reservation must stay under the hard cap.
		$est_micro = (int) round( max( 0.0, $est_eur ) * 1000000 );
		$hard      = (int) round( AI_Settings::limit( 'monthlyHardCapEur' ) * 1000000 );
		if ( $hard > 0 && (int) round( $budget['month'] * 1000000 ) + $budget['reservedMicro'] + $est_micro > $hard ) {
			return Messages::error( 'bps_ai_budget', 503, array( 'freeOnly' => false ) );
		}

		self::reserve( $job_id, $est_micro );
		set_transient( $day, (int) get_transient( $day ) + 1, DAY_IN_SECONDS + HOUR_IN_SECONDS );
		set_transient( 'bps_ai_last_o_' . $okey, self::now(), max( 60, $gap * 2 ) );
		set_transient( 'bps_ai_last_i_' . self::hkey( $ip_hash ), self::now(), max( 60, $gap * 2 ) );
		return true;
	}

	/**
	 * The round did not happen (no rounds, blocked after admission, failed): undo the day counter and the reservation.
	 *
	 * @param string $owner  Owner key.
	 * @param string $job_id Job id.
	 */
	public static function undo( string $owner, string $job_id ): void {
		self::release( $job_id );
		$day = 'bps_ai_day_' . self::hkey( $owner ) . '_' . self::local_day();
		$n   = (int) get_transient( $day );
		if ( $n > 0 ) {
			set_transient( $day, $n - 1, DAY_IN_SECONDS + HOUR_IN_SECONDS );
		}
		delete_transient( 'bps_ai_last_o_' . self::hkey( $owner ) );
	}

	/**
	 * Drop the cost reservation of a job (the real cost is in the cost log by now).
	 *
	 * @param string $job_id Job id.
	 */
	public static function release( string $job_id ): void {
		$all = self::reservations();
		if ( isset( $all[ $job_id ] ) ) {
			unset( $all[ $job_id ] );
			set_transient( self::RESERVATIONS, $all, DAY_IN_SECONDS );
		}
		self::maybe_alert();
	}

	/**
	 * Budget state.
	 *
	 * @return array{day:float, month:float, reservedMicro:int, freePaused:bool, hardCapped:bool, monthPct:int}
	 */
	public static function budget(): array {
		$t        = Cost_Log::totals();
		$reserved = array_sum( array_map( 'intval', array_column( self::reservations(), 'micro' ) ) );
		$res_eur  = $reserved / 1000000;
		$daily    = AI_Settings::limit( 'dailyBudgetEur' );
		$monthly  = AI_Settings::limit( 'monthlyBudgetEur' );
		$hard     = AI_Settings::limit( 'monthlyHardCapEur' );
		return array(
			'day'           => (float) $t['day'],
			'month'         => (float) $t['month'],
			'reservedMicro' => $reserved,
			'freePaused'    => ( $daily > 0 && $t['day'] + $res_eur >= $daily ) || ( $monthly > 0 && $t['month'] + $res_eur >= $monthly ),
			'hardCapped'    => $hard > 0 && $t['month'] >= $hard,
			'monthPct'      => $monthly > 0 ? (int) floor( 100 * $t['month'] / $monthly ) : 0,
		);
	}

	/**
	 * Alert mail at alertAtPercent and at 100 % of the monthly budget (max once per threshold per month).
	 * Only numbers, never customer data.
	 */
	public static function maybe_alert(): void {
		$monthly = AI_Settings::limit( 'monthlyBudgetEur' );
		if ( $monthly <= 0 ) {
			return;
		}
		$t     = Cost_Log::totals();
		$pct   = 100 * $t['month'] / $monthly;
		$month = wp_date( 'Y-m' );
		$sent  = get_option( self::ALERTS, array() );
		$sent  = is_array( $sent ) && ( $sent['month'] ?? '' ) === $month ? $sent : array(
			'month' => $month,
			'sent'  => array(),
		);
		$todo  = array();
		foreach ( array( (int) AI_Settings::limit( 'alertAtPercent' ), 100 ) as $th ) {
			if ( $pct >= $th && ! in_array( $th, (array) $sent['sent'], true ) ) {
				$todo[] = $th;
			}
		}
		if ( empty( $todo ) ) {
			return;
		}
		$th              = max( $todo );
		$sent['sent']    = array_values( array_unique( array_merge( (array) $sent['sent'], $todo ) ) );
		update_option( self::ALERTS, $sent, false );
		$to = (string) AI_Settings::path( 'alerts.email', '' );
		$to = is_email( $to ) ? $to : (string) get_option( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$subject = sprintf(
			/* translators: %d: percentage */
			__( 'Patch Studio AI: %d %% van het maandbudget gebruikt', 'borduur-patch-studio' ),
			$th
		);
		$body  = sprintf(
			/* translators: 1: spent this month, 2: monthly budget, 3: spent today, 4: hard cap */
			__( "Deze maand: € %1\$s van € %2\$s.\nVandaag: € %3\$s.\nHard plafond: € %4\$s (daarboven pauzeert de AI-studio volledig).\n\nVanaf 100 %% worden gratis rondes gepauzeerd; betaalde rondes werken door tot het harde plafond.", 'borduur-patch-studio' ),
			number_format_i18n( $t['month'], 2 ),
			number_format_i18n( $monthly, 2 ),
			number_format_i18n( $t['day'], 2 ),
			number_format_i18n( AI_Settings::limit( 'monthlyHardCapEur' ), 2 )
		);
		$body .= "\n\n" . ( class_exists( '\BorduurPatchStudio\Plugin' ) ? admin_url( 'admin.php?page=' . Admin::SLUG . '&tab=limits' ) : '' );
		wp_mail( $to, $subject, $body );
	}

	/* ---------------------------------------------------------------------
	 * Abuse pause (SPEC §8)
	 * ------------------------------------------------------------------- */

	/**
	 * Count a refusal (local filter, moderation or provider policy) and pause when the hourly threshold is reached.
	 *
	 * @param string $owner   Owner key.
	 * @param string $ip_hash IP hash.
	 * @param string $code    Category code.
	 */
	public static function record_refusal( string $owner, string $ip_hash, string $code ): void {
		$legal  = Module::legal();
		$max    = (int) ( $legal['defaults']['abusePauseRefusalsPerHour'] ?? 5 );
		$minute = (int) ( $legal['defaults']['abusePauseMinutes'] ?? 60 );
		$slot   = (int) floor( self::now() / HOUR_IN_SECONDS );
		foreach ( array( 'o' => $owner, 'i' => $ip_hash ) as $t => $id ) {
			if ( '' === $id ) {
				continue;
			}
			$k = 'bps_ai_ref_' . $t . '_' . self::hkey( $id ) . '_' . $slot;
			$n = (int) get_transient( $k ) + 1;
			set_transient( $k, $n, HOUR_IN_SECONDS + 60 );
			if ( $max > 0 && $n >= $max ) {
				set_transient( 'bps_ai_pause_' . $t . '_' . self::hkey( $id ), self::now() + $minute * MINUTE_IN_SECONDS, $minute * MINUTE_IN_SECONDS );
			}
		}
		// Per-category counts per day for the admin (no text, no ids).
		$counts = get_option( self::REFUSALS, array() );
		$counts = is_array( $counts ) ? $counts : array();
		$day    = wp_date( 'Y-m-d' );
		$code   = preg_replace( '/[^a-z_]/', '', strtolower( $code ) );
		$code   = '' === $code ? 'other' : $code;
		$counts[ $day ][ $code ] = (int) ( $counts[ $day ][ $code ] ?? 0 ) + 1;
		ksort( $counts );
		$counts = array_slice( $counts, -60, null, true );
		update_option( self::REFUSALS, $counts, false );
	}

	/**
	 * A job was cancelled while the AI was already working on it. The round goes back (SPEC §1.1 rule 6), but the
	 * provider call is already paid: without a cap, "generate → cancel" would turn one round into unlimited paid
	 * calls. After `runningCancelsPerDay` such cancels per owner or IP (line) on one day, the studio pauses for
	 * that visitor until the day window ends (the same honest 423 "pause" the abuse rule uses).
	 *
	 * @param string $owner   Owner key.
	 * @param string $ip_hash IP hash.
	 */
	public static function record_running_cancel( string $owner, string $ip_hash ): void {
		$max = (int) AI_Settings::limit( 'runningCancelsPerDay' );
		if ( $max <= 0 ) {
			return;
		}
		$day = self::local_day();
		foreach ( array( 'o' => $owner, 'i' => $ip_hash ) as $t => $id ) {
			if ( '' === $id ) {
				continue;
			}
			$k = 'bps_ai_rc_' . $t . '_' . self::hkey( $id ) . '_' . $day;
			$n = (int) get_transient( $k ) + 1;
			set_transient( $k, $n, DAY_IN_SECONDS + HOUR_IN_SECONDS );
			if ( $n >= $max ) {
				$pause = 12 * HOUR_IN_SECONDS;
				set_transient( 'bps_ai_pause_' . $t . '_' . self::hkey( $id ), self::now() + $pause, $pause );
			}
		}
	}

	/**
	 * Pause end (unix) for this owner or IP, or 0.
	 *
	 * @param string $owner   Owner key.
	 * @param string $ip_hash IP hash.
	 */
	public static function paused_until( string $owner, string $ip_hash ): int {
		$until = 0;
		foreach ( array( 'o' => $owner, 'i' => $ip_hash ) as $t => $id ) {
			if ( '' === $id ) {
				continue;
			}
			$v = (int) get_transient( 'bps_ai_pause_' . $t . '_' . self::hkey( $id ) );
			if ( $v > self::now() ) {
				$until = max( $until, $v );
			}
		}
		return $until;
	}

	/**
	 * Refusals per category over the last $days days.
	 *
	 * @param int $days Days.
	 * @return array<string,int>
	 */
	public static function refusal_totals( int $days = 30 ): array {
		$counts = get_option( self::REFUSALS, array() );
		$out    = array();
		$since  = wp_date( 'Y-m-d', self::now() - $days * DAY_IN_SECONDS );
		foreach ( is_array( $counts ) ? $counts : array() as $day => $per ) {
			if ( (string) $day < $since || ! is_array( $per ) ) {
				continue;
			}
			foreach ( $per as $code => $n ) {
				$out[ $code ] = ( $out[ $code ] ?? 0 ) + (int) $n;
			}
		}
		arsort( $out );
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * @param string $job_id Job id.
	 * @param int    $micro  Micro-euro.
	 */
	private static function reserve( string $job_id, int $micro ): void {
		$all            = self::reservations();
		$all[ $job_id ] = array(
			'micro' => $micro,
			'exp'   => self::now() + 15 * MINUTE_IN_SECONDS,
		);
		set_transient( self::RESERVATIONS, $all, DAY_IN_SECONDS );
	}

	/**
	 * Live reservations (expired ones dropped: a crashed job must not block the budget forever).
	 *
	 * @return array<string, array{micro:int, exp:int}>
	 */
	private static function reservations(): array {
		$all = get_transient( self::RESERVATIONS );
		$all = is_array( $all ) ? $all : array();
		$now = self::now();
		return array_filter(
			$all,
			static function ( $r ) use ( $now ) {
				return is_array( $r ) && (int) ( $r['exp'] ?? 0 ) > $now;
			}
		);
	}

	/**
	 * Short HMAC-ish key for transient names.
	 *
	 * @param string $id Identifier (already a hash or owner key).
	 */
	private static function hkey( string $id ): string {
		return substr( hash( 'sha256', $id ), 0, 24 );
	}

	/**
	 * Local day stamp.
	 */
	private static function local_day(): string {
		return function_exists( 'wp_date' ) ? (string) wp_date( 'Ymd', self::now() ) : gmdate( 'Ymd', self::now() );
	}
}
