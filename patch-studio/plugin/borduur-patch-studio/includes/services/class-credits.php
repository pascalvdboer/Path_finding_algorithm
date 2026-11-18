<?php
/**
 * Design rounds: lots + append-only ledger (SPEC-AI-DIENSTEN §0 A9, §3.2). Pure domain logic over a Credits_Store.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Balance is always computed from lots, never stored. One job takes all its rounds from one lot.
 * Spending order: free_anon, free_email, free_order, goodwill, admin, pack; earliest expiry first.
 *
 * Used by ai-backend: Credits::instance()->balance(), ->reserve(), ->refund(), ->grant_free_anon(), ->merge().
 */
final class Credits {

	/** Spending priority per source (lower first). */
	const SOURCE_ORDER = array(
		'free_anon'  => 0,
		'free_email' => 1,
		'free_order' => 2,
		'goodwill'   => 3,
		'admin'      => 4,
		'pack'       => 5,
	);

	/** Ledger reasons accepted for spend/refund. */
	const SPEND_REASONS  = array( 'generate', 'refine' );
	const REFUND_REASONS = array( 'job_failed', 'job_blocked', 'job_cancelled', 'service_stop', 'manual' );

	/** @var Credits|null */
	private static $instance = null;

	/** @var Credits_Store */
	private $store;

	/** @var callable|null Returns a unix timestamp. */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Credits_Store $store Store.
	 * @param callable|null $clock Clock (tests), returns a unix timestamp.
	 */
	public function __construct( Credits_Store $store, ?callable $clock = null ) {
		$this->store = $store;
		$this->clock = $clock;
	}

	/**
	 * Shared production instance (wpdb store).
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self( new Credits_Store_Wpdb() );
		}
		return self::$instance;
	}

	/**
	 * Replace the shared instance (tests, demo). Null resets.
	 *
	 * @param Credits|null $credits Instance.
	 */
	public static function set_instance( ?self $credits ): void {
		self::$instance = $credits;
	}

	/**
	 * The store.
	 */
	public function store(): Credits_Store {
		return $this->store;
	}

	/**
	 * Current unix time.
	 */
	public function ts(): int {
		return $this->clock ? (int) call_user_func( $this->clock ) : time();
	}

	/**
	 * Now as UTC 'Y-m-d H:i:s'.
	 */
	public function now(): string {
		return gmdate( 'Y-m-d H:i:s', $this->ts() );
	}

	/**
	 * DB datetime → ISO 8601 (UTC) for REST.
	 *
	 * @param string|null $dt DB datetime.
	 */
	public static function iso( ?string $dt ): ?string {
		if ( null === $dt || '' === $dt ) {
			return null;
		}
		return str_replace( ' ', 'T', $dt ) . 'Z';
	}

	/**
	 * UTC datetime shifted by a modifier ('+24 months', '+30 days').
	 *
	 * @param string $modifier Modifier.
	 * @param int    $from     Base timestamp (0 = now).
	 */
	public function shift( string $modifier, int $from = 0 ): string {
		$d = ( new \DateTimeImmutable( '@' . ( $from ? $from : $this->ts() ) ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		return $d->modify( $modifier )->format( 'Y-m-d H:i:s' );
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------- */

	/**
	 * Is a lot locked (paid, no consent yet, before usable_from)?
	 *
	 * @param array  $lot Lot.
	 * @param string $now Now.
	 */
	public static function is_locked( array $lot, string $now ): bool {
		return 'pack' === $lot['source'] && empty( $lot['consent_at'] ) && (string) $lot['usable_from'] > $now;
	}

	/**
	 * Balance of an owner.
	 *
	 * @param string $owner Owner key.
	 * `expiring` = paid rounds that expire within 30 days ({rounds, at}) for legal.ai.expiry_notice (04 §8); free
	 * rounds are left out (short-lived on purpose, no reminder needed).
	 *
	 * @return array{free:int, paid:int, paidLocked:int, total:int, nextExpiry:?string, lockedUntil:?string, expiring:?array}
	 */
	public function balance( string $owner ): array {
		$out = array(
			'free'        => 0,
			'paid'        => 0,
			'paidLocked'  => 0,
			'total'       => 0,
			'nextExpiry'  => null,
			'lockedUntil' => null,
			'expiring'    => null,
		);
		if ( '' === $owner ) {
			return $out;
		}
		$now  = $this->now();
		$soon = $this->shift( '+30 days' );
		$next = null;
		$lock = null;
		$exp  = null;
		$expn = 0;
		foreach ( $this->store->lots_for_owner( $owner, array( 'active' ) ) as $lot ) {
			$unused = (int) $lot['rounds_total'] - (int) $lot['rounds_used'];
			if ( $unused <= 0 || (string) $lot['expires_at'] <= $now ) {
				continue;
			}
			if ( self::is_locked( $lot, $now ) ) {
				$out['paidLocked'] += $unused;
				$lock               = null === $lock || $lot['usable_from'] < $lock ? (string) $lot['usable_from'] : $lock;
				continue;
			}
			if ( 'pack' === $lot['source'] ) {
				$out['paid'] += $unused;
				if ( (string) $lot['expires_at'] <= $soon ) {
					$expn += $unused;
					$exp   = null === $exp || $lot['expires_at'] < $exp ? (string) $lot['expires_at'] : $exp;
				}
			} else {
				$out['free'] += $unused;
			}
			$next = null === $next || $lot['expires_at'] < $next ? (string) $lot['expires_at'] : $next;
		}
		$out['total']       = $out['free'] + $out['paid'];
		$out['nextExpiry']  = self::iso( $next );
		$out['lockedUntil'] = self::iso( $lock );
		$out['expiring']    = $expn > 0 ? array( 'rounds' => $expn, 'at' => self::iso( $exp ) ) : null;
		return $out;
	}

	/**
	 * Lots of an owner for display (REST /ai/credits, Mijn account). No owner key, no order data except the order id.
	 *
	 * @param string $owner Owner key.
	 */
	public function lots( string $owner ): array {
		$now = $this->now();
		$out = array();
		foreach ( $this->store->lots_for_owner( $owner ) as $lot ) {
			if ( 'merged' === $lot['status'] ) {
				continue;
			}
			$status = $lot['status'];
			if ( 'active' === $status && (string) $lot['expires_at'] <= $now ) {
				$status = 'expired';
			}
			$out[] = array(
				'id'         => (int) $lot['id'],
				'source'     => (string) $lot['source'],
				'total'      => (int) $lot['rounds_total'],
				'used'       => (int) $lot['rounds_used'],
				'unused'     => 'active' === $status ? max( 0, (int) $lot['rounds_total'] - (int) $lot['rounds_used'] ) : 0,
				'status'     => $status,
				'packId'     => $lot['pack_id'] ? (string) $lot['pack_id'] : null,
				'orderId'    => $lot['order_id'] ? (int) $lot['order_id'] : null,
				'createdAt'  => self::iso( (string) $lot['created_at'] ),
				'expiresAt'  => self::iso( (string) $lot['expires_at'] ),
				'locked'     => 'active' === $status && self::is_locked( $lot, $now ),
				'usableFrom' => self::iso( (string) $lot['usable_from'] ),
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Spending
	 * ------------------------------------------------------------------- */

	/**
	 * Reserve $n rounds for a job from one lot. Idempotent per job id.
	 *
	 * @param string $owner  Owner key.
	 * @param int    $n      Rounds (>= 1).
	 * @param string $job_id Job id (26 chars).
	 * @param string $reason generate|refine.
	 * @param array  $opts   {paidOnly?:bool} paidOnly = free rounds are paused (daily budget, §9).
	 * @return int|null Lot id or null when there are not enough usable rounds.
	 */
	public function reserve( string $owner, int $n, string $job_id, string $reason = 'generate', array $opts = array() ): ?int {
		if ( '' === $owner || $n < 1 || '' === $job_id ) {
			return null;
		}
		$reason = in_array( $reason, self::SPEND_REASONS, true ) ? $reason : 'generate';

		// Same job asked twice (double tap, retry): return the existing reservation.
		$spent = $this->store->ledger_where(
			array(
				'job_id' => $job_id,
				'kind'   => 'spend',
			),
			1
		);
		if ( $spent && ! $this->store->ledger_where( array( 'job_id' => $job_id, 'kind' => 'refund' ), 1 ) ) {
			return (int) $spent[0]['lot_id'];
		}

		$now        = $this->now();
		$candidates = array();
		foreach ( $this->store->lots_for_owner( $owner, array( 'active' ) ) as $lot ) {
			if ( ( (int) $lot['rounds_total'] - (int) $lot['rounds_used'] ) < $n || (string) $lot['expires_at'] <= $now || (string) $lot['usable_from'] > $now ) {
				continue;
			}
			if ( ! empty( $opts['paidOnly'] ) && 'pack' !== $lot['source'] ) {
				continue;
			}
			$candidates[] = $lot;
		}
		usort(
			$candidates,
			static function ( $a, $b ) {
				$oa = self::SOURCE_ORDER[ $a['source'] ] ?? 9;
				$ob = self::SOURCE_ORDER[ $b['source'] ] ?? 9;
				if ( $oa !== $ob ) {
					return $oa <=> $ob;
				}
				if ( $a['expires_at'] !== $b['expires_at'] ) {
					return strcmp( (string) $a['expires_at'], (string) $b['expires_at'] );
				}
				return (int) $a['id'] <=> (int) $b['id'];
			}
		);
		foreach ( $candidates as $lot ) {
			if ( $this->store->try_consume( (int) $lot['id'], $n, $now ) ) {
				$this->store->add_ledger(
					array(
						'owner_key'  => $owner,
						'lot_id'     => (int) $lot['id'],
						'delta'      => -$n,
						'kind'       => 'spend',
						'reason'     => $reason,
						'job_id'     => $job_id,
						'created_at' => $now,
					)
				);
				return (int) $lot['id'];
			}
		}
		return null;
	}

	/**
	 * Give back the rounds of a job (failure, refusal, cancel). Idempotent.
	 *
	 * @param string $job_id Job id.
	 * @param string $reason job_failed|job_blocked|job_cancelled|service_stop|manual.
	 * @return bool True when rounds were given back now.
	 */
	public function refund( string $job_id, string $reason = 'job_failed' ): bool {
		if ( '' === $job_id ) {
			return false;
		}
		$reason = in_array( $reason, self::REFUND_REASONS, true ) ? $reason : 'job_failed';
		return (bool) $this->store->transaction(
			function () use ( $job_id, $reason ) {
				if ( $this->store->ledger_where( array( 'job_id' => $job_id, 'kind' => 'refund' ), 1 ) ) {
					return false;
				}
				$spent = $this->store->ledger_where( array( 'job_id' => $job_id, 'kind' => 'spend' ), 1 );
				if ( ! $spent ) {
					return false;
				}
				$row = $spent[0];
				$n   = abs( (int) $row['delta'] );
				$this->store->release( (int) $row['lot_id'], $n );
				$this->store->add_ledger(
					array(
						'owner_key'  => (string) $row['owner_key'],
						'lot_id'     => (int) $row['lot_id'],
						'delta'      => $n,
						'kind'       => 'refund',
						'reason'     => $reason,
						'job_id'     => $job_id,
						'created_at' => $this->now(),
					)
				);
				return true;
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Granting
	 * ------------------------------------------------------------------- */

	/**
	 * Default expiry for a source.
	 *
	 * @param string $source Source.
	 */
	public function default_expiry( string $source ): string {
		if ( 'pack' === $source ) {
			$months = max( (int) Config::get( 'validityMinMonths', 12 ), (int) Config::get( 'validityMonths', 24 ) );
			return $this->shift( '+' . $months . ' months' );
		}
		if ( 'free_anon' === $source ) {
			return $this->shift( '+' . max( 1, (int) Config::get( 'freeAnonValidDays', 30 ) ) . ' days' );
		}
		return $this->shift( '+' . max( 1, (int) Config::get( 'freeValidMonths', 12 ) ) . ' months' );
	}

	/**
	 * Grant rounds as a new lot. Idempotent on (order_item_id, source), or (order_id, source) without item.
	 *
	 * @param string $owner  Owner key.
	 * @param string $source free_anon|free_email|free_order|pack|admin|goodwill.
	 * @param int    $rounds Rounds (>= 1).
	 * @param array  $meta   {order_id, order_item_id, pack_id, price_incl, consent_at, usable_from, expires_at, actor, reason}.
	 * @return int|null Lot id (existing one when already granted) or null.
	 */
	public function grant( string $owner, string $source, int $rounds, array $meta = array() ): ?int {
		if ( ! Owner::valid( $owner ) || $rounds < 1 || ! isset( self::SOURCE_ORDER[ $source ] ) ) {
			return null;
		}
		$order_id = (int) ( $meta['order_id'] ?? 0 );
		$item_id  = (int) ( $meta['order_item_id'] ?? 0 );
		if ( $order_id > 0 || $item_id > 0 ) {
			$existing = $this->store->find_lot( $source, $order_id, $item_id );
			if ( $existing ) {
				return (int) $existing['id'];
			}
		}
		$now        = $this->now();
		$consent_at = $meta['consent_at'] ?? null;
		if ( 'pack' === $source ) {
			$usable = $consent_at ? $now : ( $meta['usable_from'] ?? $this->shift( '+' . max( 0, (int) Config::get( 'refundUnusedDays', 14 ) ) . ' days' ) );
		} else {
			$usable = $meta['usable_from'] ?? $now;
		}
		$lot    = array(
			'owner_key'     => $owner,
			'source'        => $source,
			'rounds_total'  => $rounds,
			'rounds_used'   => 0,
			'order_id'      => $order_id > 0 ? $order_id : null,
			'order_item_id' => $item_id > 0 ? $item_id : null,
			'pack_id'       => isset( $meta['pack_id'] ) ? substr( (string) $meta['pack_id'], 0, 32 ) : null,
			'price_incl'    => isset( $meta['price_incl'] ) ? round( (float) $meta['price_incl'], 4 ) : null,
			'consent_at'    => $consent_at,
			'usable_from'   => $usable,
			'expires_at'    => $meta['expires_at'] ?? $this->default_expiry( $source ),
			'status'        => 'active',
			'created_at'    => $now,
		);
		$reasons = array(
			'pack'       => 'pack_paid',
			'admin'      => 'manual',
			'goodwill'   => 'manual',
			'free_anon'  => 'free_anon',
			'free_email' => 'free_email',
			'free_order' => 'free_order',
		);
		$id = (int) $this->store->transaction(
			function () use ( $lot, $owner, $rounds, $source, $order_id, $meta, $reasons, $now ) {
				$id = $this->store->insert_lot( $lot );
				if ( $id > 0 ) {
					$this->store->add_ledger(
						array(
							'owner_key'  => $owner,
							'lot_id'     => $id,
							'delta'      => $rounds,
							'kind'       => in_array( $source, array( 'admin', 'goodwill' ), true ) ? 'admin' : 'grant',
							'reason'     => (string) ( $meta['reason'] ?? $reasons[ $source ] ),
							'order_id'   => $order_id > 0 ? $order_id : null,
							'actor'      => isset( $meta['actor'] ) ? (int) $meta['actor'] : null,
							'created_at' => $now,
						)
					);
				}
				return $id;
			}
		);
		if ( $id > 0 && function_exists( 'do_action' ) ) {
			$lot['id'] = $id;
			do_action( 'bps_ai_rounds_granted', $owner, $lot );
		}
		return $id > 0 ? $id : null;
	}

	/**
	 * Was a free grant of this kind ever given to (or merged into) this owner key? The ledger is permanent, lots can move.
	 *
	 * @param string $owner  Owner key.
	 * @param string $reason free_anon|free_email.
	 */
	public function had_free( string $owner, string $reason ): bool {
		foreach ( $this->store->lots_for_owner( $owner ) as $lot ) {
			if ( $lot['source'] === $reason ) {
				return true;
			}
		}
		return (bool) $this->store->ledger_where(
			array(
				'owner_key' => $owner,
				'kind'      => 'grant',
				'reason'    => $reason,
			),
			1
		);
	}

	/**
	 * Free anonymous round(s): once per owner and max limits.anonPerIpPerDay new grants per IP hash per 24 h.
	 *
	 * @param string $owner   Owner key.
	 * @param string $ip_hash HMAC of the IP ('' = unknown).
	 * @return int Rounds granted now (0 = not granted). AI\Credits_Gateway reads this as a number of rounds.
	 */
	public function grant_free_anon( string $owner, string $ip_hash = '' ): int {
		$n = (int) Config::get( 'free.anonymous', 1 );
		if ( $n < 1 || ! Owner::valid( $owner ) || $this->had_free( $owner, 'free_anon' ) ) {
			return 0;
		}
		$limit = (int) Config::get( 'limits.anonPerIpPerDay', 3 );
		if ( '' !== $ip_hash ) {
			if ( $limit > 0 && $this->store->counter_get( 'anon_ip|' . $ip_hash ) >= $limit ) {
				return 0;
			}
			$this->store->counter_incr( 'anon_ip|' . $ip_hash, DAY_IN_SECONDS );
		}
		return $this->grant( $owner, 'free_anon', $n ) ? $n : 0;
	}

	/**
	 * Free rounds for a verified e-mail address: once per address, ever.
	 *
	 * @param string $email_owner 'e:' owner key of the verified address.
	 * @return int|null Lot id or null.
	 */
	public function grant_free_email( string $email_owner ): ?int {
		$n = (int) Config::get( 'free.withEmail', 2 );
		if ( $n < 1 || 'e' !== Owner::kind( $email_owner ) || $this->had_free( $email_owner, 'free_email' ) ) {
			return null;
		}
		return $this->grant( $email_owner, 'free_email', $n );
	}

	/**
	 * Free rounds for a paid patch order (once per order).
	 *
	 * @param string $owner    Owner key.
	 * @param int    $order_id Order id.
	 */
	public function grant_free_order( string $owner, int $order_id ): ?int {
		$n = (int) Config::get( 'free.perOrder', 5 );
		if ( $n < 1 || $order_id < 1 ) {
			return null;
		}
		return $this->grant( $owner, 'free_order', $n, array( 'order_id' => $order_id ) );
	}

	/**
	 * Unlock paid lots without consent (customer ticks both boxes later, legal.ai.pack_consent_later).
	 *
	 * @param string $owner Owner key.
	 * @return int Number of lots unlocked.
	 */
	public function consent( string $owner ): int {
		$now = $this->now();
		$n   = 0;
		foreach ( $this->store->lots_for_owner( $owner, array( 'active' ) ) as $lot ) {
			if ( 'pack' === $lot['source'] && empty( $lot['consent_at'] ) ) {
				$fields = array( 'consent_at' => $now );
				if ( (string) $lot['usable_from'] > $now ) {
					$fields['usable_from'] = $now;
				}
				if ( $this->store->update_lot( (int) $lot['id'], $fields ) ) {
					++$n;
				}
			}
		}
		return $n;
	}

	/* ---------------------------------------------------------------------
	 * Ending lots
	 * ------------------------------------------------------------------- */

	/**
	 * What a withdrawal of a lot would give back (no changes).
	 *
	 * @param int $lot_id Lot id.
	 * @return array{unused:int, total:int, amount:float}|null
	 */
	public function withdraw_preview( int $lot_id ): ?array {
		$lot = $this->store->get_lot( $lot_id );
		if ( ! $lot || 'active' !== $lot['status'] ) {
			return null;
		}
		return self::refund_amount( $lot );
	}

	/**
	 * Refund for the unused part: price_incl × unused / total, half-up on the cent.
	 *
	 * @param array $lot Lot.
	 * @return array{unused:int, total:int, amount:float}
	 */
	public static function refund_amount( array $lot ): array {
		$total  = max( 1, (int) $lot['rounds_total'] );
		$unused = max( 0, $total - (int) $lot['rounds_used'] );
		$price  = (float) ( $lot['price_incl'] ?? 0 );
		return array(
			'unused' => $unused,
			'total'  => $total,
			'amount' => Config::round_half_up( $price * $unused / $total, 2 ),
		);
	}

	/**
	 * Within the voluntary refund window (refundUnusedDays after purchase)?
	 *
	 * @param array $lot Lot.
	 */
	public function withdrawable( array $lot ): bool {
		if ( 'pack' !== $lot['source'] || 'active' !== $lot['status'] || ! Config::get( 'refundUnused', true ) ) {
			return false;
		}
		$days = max( 0, (int) Config::get( 'refundUnusedDays', 14 ) );
		return $this->ts() <= strtotime( $lot['created_at'] . ' UTC' ) + $days * DAY_IN_SECONDS
			&& ( (int) $lot['rounds_total'] - (int) $lot['rounds_used'] ) > 0;
	}

	/**
	 * Withdraw a lot: unused part to 0, status withdrawn (or refunded for an order refund).
	 * Atomic: the status flips first, so no round can be spent while we compute the amount.
	 *
	 * @param int    $lot_id Lot id.
	 * @param string $reason withdrawal|service_stop|manual.
	 * @param string $status withdrawn|refunded.
	 * @return array{unused:int, total:int, amount:float}|null
	 */
	public function withdraw( int $lot_id, string $reason = 'withdrawal', string $status = 'withdrawn' ): ?array {
		$status = 'refunded' === $status ? 'refunded' : 'withdrawn';
		if ( ! $this->store->update_lot( $lot_id, array( 'status' => $status ), 'active' ) ) {
			return null;
		}
		$lot = $this->store->get_lot( $lot_id );
		$res = self::refund_amount( $lot );
		if ( $res['unused'] > 0 ) {
			$this->store->add_ledger(
				array(
					'owner_key'  => (string) $lot['owner_key'],
					'lot_id'     => $lot_id,
					'delta'      => -$res['unused'],
					'kind'       => 'withdraw',
					'reason'     => in_array( $reason, array( 'withdrawal', 'service_stop', 'manual' ), true ) ? $reason : 'withdrawal',
					'order_id'   => $lot['order_id'] ? (int) $lot['order_id'] : null,
					'created_at' => $this->now(),
				)
			);
		}
		return $res;
	}

	/**
	 * Expire lots past expires_at (cron). Returns the number of lots expired.
	 */
	public function expire(): int {
		$now = $this->now();
		$n   = 0;
		foreach ( $this->store->expired_lots( $now ) as $lot ) {
			if ( ! $this->store->update_lot( (int) $lot['id'], array( 'status' => 'expired' ), 'active' ) ) {
				continue;
			}
			++$n;
			$fresh  = $this->store->get_lot( (int) $lot['id'] );
			$unused = max( 0, (int) $fresh['rounds_total'] - (int) $fresh['rounds_used'] );
			if ( $unused > 0 ) {
				$this->store->add_ledger(
					array(
						'owner_key'  => (string) $fresh['owner_key'],
						'lot_id'     => (int) $fresh['id'],
						'delta'      => -$unused,
						'kind'       => 'expire',
						'reason'     => 'expiry',
						'created_at' => $now,
					)
				);
			}
		}
		return $n;
	}

	/**
	 * Move lots from a weaker owner key to a stronger one (anonymous → e-mail → account).
	 * A free anonymous round counts only once: if the target already had one, the source's free_anon lot is closed.
	 *
	 * @param string $from Weaker key.
	 * @param string $to   Stronger (or equal-strength) key.
	 * @return int Rounds moved.
	 */
	public function merge( string $from, string $to ): int {
		if ( $from === $to || ! Owner::valid( $from ) || ! Owner::valid( $to ) || Owner::strength( $from ) > Owner::strength( $to ) ) {
			return 0;
		}
		return (int) $this->store->transaction(
			function () use ( $from, $to ) {
				$now          = $this->now();
				$moved        = 0;
				$target_anon  = $this->had_free( $to, 'free_anon' );
				foreach ( $this->store->lots_for_owner( $from ) as $lot ) {
					if ( 'merged' === $lot['status'] ) {
						continue;
					}
					$unused = 'active' === $lot['status'] ? max( 0, (int) $lot['rounds_total'] - (int) $lot['rounds_used'] ) : 0;
					if ( 'free_anon' === $lot['source'] && $target_anon ) {
						$this->store->update_lot( (int) $lot['id'], array( 'status' => 'merged' ) );
						if ( $unused > 0 ) {
							$this->ledger_move( $from, (int) $lot['id'], -$unused, 'merge_out', $now );
						}
						continue;
					}
					$this->store->update_lot( (int) $lot['id'], array( 'owner_key' => $to ) );
					if ( $unused > 0 ) {
						$this->ledger_move( $from, (int) $lot['id'], -$unused, 'merge_out', $now );
						$this->ledger_move( $to, (int) $lot['id'], $unused, 'merge_in', $now );
						$moved += $unused;
					}
				}
				return $moved;
			}
		);
	}

	/**
	 * Ledger row for a merge.
	 *
	 * @param string $owner  Owner.
	 * @param int    $lot_id Lot.
	 * @param int    $delta  Delta.
	 * @param string $kind   merge_in|merge_out.
	 * @param string $now    Now.
	 */
	private function ledger_move( string $owner, int $lot_id, int $delta, string $kind, string $now ): void {
		$this->store->add_ledger(
			array(
				'owner_key'  => $owner,
				'lot_id'     => $lot_id,
				'delta'      => $delta,
				'kind'       => $kind,
				'reason'     => 'manual',
				'created_at' => $now,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Privacy (called by AI\Privacy through the gateway: export_owner / erase_owner)
	 * ------------------------------------------------------------------- */

	/**
	 * Personal-data export rows for an owner: lots (what, how many, until when). No owner key.
	 *
	 * @param string $owner Owner key.
	 * @return array<int, array{name:string, value:string}>[]
	 */
	public function export_owner( string $owner ): array {
		$out = array();
		foreach ( $this->lots( $owner ) as $lot ) {
			$out[] = array(
				array( 'name' => 'Soort', 'value' => $lot['source'] ),
				array( 'name' => 'Rondes', 'value' => $lot['used'] . ' / ' . $lot['total'] ),
				array( 'name' => 'Status', 'value' => $lot['status'] ),
				array( 'name' => 'Aangemaakt', 'value' => (string) $lot['createdAt'] ),
				array( 'name' => 'Geldig tot', 'value' => (string) $lot['expiresAt'] ),
				array( 'name' => 'Bestelling', 'value' => $lot['orderId'] ? (string) $lot['orderId'] : '' ),
			);
		}
		return $out;
	}

	/**
	 * Erase an owner: lots and ledger stay (bookkeeping, 10 years) but the key is anonymised, unless rounds are
	 * still open (then the customer keeps them; we report "retained").
	 *
	 * @param string $owner Owner key.
	 * @return array{removed:bool, retained:bool}
	 */
	public function erase_owner( string $owner ): array {
		if ( ! Owner::valid( $owner ) ) {
			return array( 'removed' => false, 'retained' => false );
		}
		$b = $this->balance( $owner );
		if ( $b['total'] + $b['paidLocked'] > 0 ) {
			return array( 'removed' => false, 'retained' => true );
		}
		$anon = 'x:' . hash( 'sha256', 'erased|' . $owner . '|' . $this->ts() );
		$n    = $this->store->anonymize_owner( $owner, $anon );
		return array( 'removed' => $n > 0, 'retained' => false );
	}
}
