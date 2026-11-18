<?php
/**
 * Bridge to the rounds ledger of the commerce builder (Services\Credits).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * The AI module never touches lots/ledger itself. It talks to whatever object commerce hands over through the
 * filter `bps_ai_credits` (or `Services\Credits::instance()`), duck-typed on the methods of SPEC-AI-DIENSTEN §3.2:
 *   balance( $owner ): array, reserve( $owner, $n, $job_id, $reason ): ?int, refund( $job_id, $reason ): bool,
 *   grant( $owner, $source, $rounds, $meta ): mixed, merge( $from, $to ), expire().
 *
 * Without commerce:
 *   - mode `test` (only administrators see the studio): unlimited test rounds, clearly labelled, nothing is sold;
 *   - otherwise: zero balance, so live generation answers 402 and nobody gets free AI by accident.
 */
final class Credits_Gateway {

	/** @var Credits_Gateway|null */
	private static $instance = null;

	/** @var object|string|null Backend object (or class name with static methods). */
	private $backend;

	/** @var string commerce|test|none */
	private $kind;

	/**
	 * @param object|string|null $backend Backend.
	 * @param string             $kind    Kind.
	 */
	public function __construct( $backend, string $kind ) {
		$this->backend = $backend;
		$this->kind    = $kind;
	}

	/**
	 * Resolved gateway (per request).
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			$backend = apply_filters( 'bps_ai_credits', null );
			if ( ! is_object( $backend ) && class_exists( '\BorduurPatchStudio\Services\Credits' ) && method_exists( '\BorduurPatchStudio\Services\Credits', 'instance' ) ) {
				$backend = \BorduurPatchStudio\Services\Credits::instance();
			}
			if ( is_object( $backend ) && method_exists( $backend, 'reserve' ) && method_exists( $backend, 'balance' ) ) {
				self::$instance = new self( $backend, 'commerce' );
			} elseif ( 'test' === AI_Settings::mode() ) {
				self::$instance = new self( null, 'test' );
			} else {
				self::$instance = new self( null, 'none' );
			}
		}
		return self::$instance;
	}

	/**
	 * Replace the gateway (tests) or reset (null).
	 *
	 * @param Credits_Gateway|null $gw Gateway.
	 */
	public static function set( ?self $gw ): void {
		self::$instance = $gw;
	}

	/**
	 * commerce|test|none.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Balance in the shape of StatusResponse.balance.
	 *
	 * @param string $owner Owner key.
	 * @return array{free:int, paid:int, paidLocked:int, total:int, nextExpiry:?string, lockedUntil:?string}
	 */
	public function balance( string $owner ): array {
		$empty = array(
			'free'        => 0,
			'paid'        => 0,
			'paidLocked'  => 0,
			'total'       => 0,
			'nextExpiry'  => null,
			'lockedUntil' => null,
			'expiring'    => null,
		);
		if ( 'test' === $this->kind ) {
			return array_merge( $empty, array( 'free' => 99, 'total' => 99 ) );
		}
		if ( 'commerce' !== $this->kind || '' === $owner ) {
			return $empty;
		}
		$b = $this->call( 'balance', $owner );
		if ( ! is_array( $b ) ) {
			return $empty;
		}
		$out = array_merge( $empty, array_intersect_key( $b, $empty ) );
		foreach ( array( 'free', 'paid', 'paidLocked', 'total' ) as $k ) {
			$out[ $k ] = max( 0, (int) $out[ $k ] );
		}
		$e               = $out['expiring'];
		$out['expiring'] = is_array( $e ) && (int) ( $e['rounds'] ?? 0 ) > 0 && ! empty( $e['at'] ) ? array( 'rounds' => (int) $e['rounds'], 'at' => (string) $e['at'] ) : null;
		return $out;
	}

	/**
	 * Reserve rounds for a job. Returns the lot id (0 in test mode) or null when there are not enough rounds.
	 *
	 * @param string $owner  Owner key.
	 * @param int    $n      Rounds.
	 * @param string $job_id Job id.
	 * @param string $reason generate|refine.
	 */
	public function reserve( string $owner, int $n, string $job_id, string $reason ): ?int {
		if ( 'test' === $this->kind ) {
			return 0;
		}
		if ( 'commerce' !== $this->kind ) {
			return null;
		}
		$lot = $this->call( 'reserve', $owner, $n, $job_id, $reason );
		return ( null === $lot || false === $lot ) ? null : (int) $lot;
	}

	/**
	 * Give the rounds of a job back (idempotent on the commerce side).
	 *
	 * @param string $job_id Job id.
	 * @param string $reason job_failed|job_blocked|job_cancelled.
	 */
	public function refund( string $job_id, string $reason ): bool {
		if ( 'commerce' !== $this->kind ) {
			return 'test' === $this->kind;
		}
		return (bool) $this->call( 'refund', $job_id, $reason );
	}

	/**
	 * Free anonymous round on the first status call: once per owner and max `anonPerIpPerDay` new grants per IP hash.
	 *
	 * @param string $owner   Anonymous owner key.
	 * @param string $ip_hash IP hash.
	 * @return int Rounds granted now.
	 */
	public function grant_free_anon( string $owner, string $ip_hash ): int {
		if ( 'commerce' !== $this->kind || 'anon' !== Identity::kind( $owner ) ) {
			return 0;
		}
		if ( method_exists( $this->backend, 'grant_free_anon' ) ) {
			// Commerce owns the once-per-owner and per-IP rules (Credits::grant_free_anon).
			$res = $this->call( 'grant_free_anon', $owner, $ip_hash );
			return is_numeric( $res ) ? max( 0, (int) $res ) : 0;
		}
		$rounds = (int) AI_Settings::path( 'free.anonymous', 1 );
		if ( $rounds <= 0 ) {
			return 0;
		}
		$store = Module::store();
		$row   = $store->owner_get( $owner );
		if ( $row && ! empty( $row['anon_granted_at'] ) ) {
			return 0;
		}
		$max = (int) AI_Settings::limit( 'anonPerIpPerDay' );
		$key = 'bps_ai_anon_' . substr( hash( 'sha256', $ip_hash . '|' . gmdate( 'Ymd' ) ), 0, 32 );
		$cnt = (int) get_transient( $key );
		if ( $max <= 0 || $cnt >= $max ) {
			return 0;
		}
		// Mark first (a concurrent second call then sees the flag), then grant.
		$store->owner_upsert(
			array(
				'owner_key'       => $owner,
				'kind'            => 'anon',
				'anon_granted_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		set_transient( $key, $cnt + 1, DAY_IN_SECONDS + HOUR_IN_SECONDS );
		$res = $this->call( 'grant', $owner, 'free_anon', $rounds, array( 'ip_hash' => $ip_hash ) );
		return false === $res || null === $res ? 0 : $rounds;
	}

	/**
	 * Move lots of one owner to another.
	 *
	 * @param string $from From key.
	 * @param string $to   To key.
	 */
	public function merge( string $from, string $to ): void {
		if ( 'commerce' === $this->kind && method_exists( $this->backend, 'merge' ) ) {
			$this->call( 'merge', $from, $to );
		}
	}

	/**
	 * Expire lots (daily cleanup), when commerce supports it.
	 */
	public function expire(): void {
		if ( 'commerce' === $this->kind && method_exists( $this->backend, 'expire' ) ) {
			$this->call( 'expire' );
		}
	}

	/**
	 * Optional pass-through for the privacy exporter/eraser (commerce decides what to export).
	 *
	 * @param string $method   export_owner|erase_owner.
	 * @param array  $args     Args.
	 * @return mixed|null
	 */
	public function optional( string $method, array $args ) {
		if ( 'commerce' === $this->kind && method_exists( $this->backend, $method ) ) {
			return $this->call( $method, ...$args );
		}
		return null;
	}

	/**
	 * Call a backend method; a failing backend never breaks the studio (logged, treated as "no").
	 *
	 * @param string $method Method.
	 * @param mixed  ...$args Args.
	 * @return mixed
	 */
	private function call( string $method, ...$args ) {
		if ( ! method_exists( $this->backend, $method ) ) {
			return null;
		}
		try {
			return $this->backend->$method( ...$args );
		} catch ( \Throwable $e ) {
			Module::log( 'credits', 'Credits::' . $method . ' failed: ' . get_class( $e ) . ' ' . $e->getMessage() );
			return null;
		}
	}
}
