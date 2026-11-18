<?php
/**
 * In-memory Credits_Store for tests, the demo and dry runs. Same semantics as the wpdb store.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Non-persistent store.
 */
final class Credits_Store_Memory implements Credits_Store {

	/** @var array<int, array> */
	public $lots = array();

	/** @var array<int, array> */
	public $ledger = array();

	/** @var array<string, array{v:int, until:int}> */
	public $counters = array();

	/** @var int Fake time for counters (tests). */
	public $time = 0;

	/**
	 * {@inheritDoc}
	 *
	 * @param array $lot Columns.
	 */
	public function insert_lot( array $lot ): int {
		$id              = count( $this->lots ) + 1;
		$lot['id']       = $id;
		$lot             = array_merge(
			array(
				'rounds_used'   => 0,
				'order_id'      => null,
				'order_item_id' => null,
				'pack_id'       => null,
				'price_incl'    => null,
				'consent_at'    => null,
				'status'        => 'active',
			),
			$lot
		);
		$this->lots[ $id ] = $lot;
		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Lot id.
	 */
	public function get_lot( int $id ): ?array {
		return $this->lots[ $id ] ?? null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string        $owner    Owner.
	 * @param string[]|null $statuses Statuses.
	 */
	public function lots_for_owner( string $owner, ?array $statuses = null ): array {
		return array_values(
			array_filter(
				$this->lots,
				static fn( $l ) => $l['owner_key'] === $owner && ( null === $statuses || in_array( $l['status'], $statuses, true ) )
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $order_id Order id.
	 */
	public function lots_for_order( int $order_id ): array {
		return array_values( array_filter( $this->lots, static fn( $l ) => (int) $l['order_id'] === $order_id ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $source        Source.
	 * @param int    $order_id      Order.
	 * @param int    $order_item_id Item.
	 */
	public function find_lot( string $source, int $order_id, int $order_item_id ): ?array {
		foreach ( $this->lots as $l ) {
			if ( $l['source'] !== $source ) {
				continue;
			}
			if ( $order_item_id > 0 ? (int) $l['order_item_id'] === $order_item_id : (int) $l['order_id'] === $order_id ) {
				return $l;
			}
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $lot_id Lot.
	 * @param int    $n      Rounds.
	 * @param string $now    Now.
	 */
	public function try_consume( int $lot_id, int $n, string $now ): bool {
		$l = $this->lots[ $lot_id ] ?? null;
		if ( ! $l || 'active' !== $l['status'] || $l['expires_at'] <= $now || $l['usable_from'] > $now || ( $l['rounds_total'] - $l['rounds_used'] ) < $n ) {
			return false;
		}
		$this->lots[ $lot_id ]['rounds_used'] += $n;
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $lot_id Lot.
	 * @param int $n      Rounds.
	 */
	public function release( int $lot_id, int $n ): bool {
		if ( ! isset( $this->lots[ $lot_id ] ) ) {
			return false;
		}
		$this->lots[ $lot_id ]['rounds_used'] = max( 0, $this->lots[ $lot_id ]['rounds_used'] - $n );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int         $lot_id      Lot.
	 * @param array       $fields      Fields.
	 * @param string|null $only_status Status guard.
	 */
	public function update_lot( int $lot_id, array $fields, ?string $only_status = null ): bool {
		if ( ! isset( $this->lots[ $lot_id ] ) ) {
			return false;
		}
		if ( null !== $only_status && $this->lots[ $lot_id ]['status'] !== $only_status ) {
			return false;
		}
		unset( $fields['id'] );
		$this->lots[ $lot_id ] = array_merge( $this->lots[ $lot_id ], $fields );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $now   Now.
	 * @param int    $limit Limit.
	 */
	public function expired_lots( string $now, int $limit = 500 ): array {
		$out = array_values( array_filter( $this->lots, static fn( $l ) => 'active' === $l['status'] && $l['expires_at'] <= $now ) );
		return array_slice( $out, 0, $limit );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $row Row.
	 */
	public function add_ledger( array $row ): int {
		$id                  = count( $this->ledger ) + 1;
		$row['id']           = $id;
		$this->ledger[ $id ] = array_merge(
			array(
				'lot_id'   => null,
				'job_id'   => null,
				'order_id' => null,
				'actor'    => null,
			),
			$row
		);
		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $where Where.
	 * @param int   $limit Limit.
	 */
	public function ledger_where( array $where, int $limit = 100 ): array {
		$out = array();
		foreach ( $this->ledger as $row ) {
			foreach ( $where as $k => $v ) {
				if ( (string) ( $row[ $k ] ?? '' ) !== (string) $v ) {
					continue 2;
				}
			}
			$out[] = $row;
		}
		return array_slice( $out, -$limit );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param callable $fn Callback.
	 * @return mixed
	 */
	public function transaction( callable $fn ) {
		$snapshot = array( $this->lots, $this->ledger );
		try {
			return $fn();
		} catch ( \Throwable $e ) {
			list( $this->lots, $this->ledger ) = $snapshot;
			throw $e;
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Key.
	 */
	public function counter_get( string $key ): int {
		$c = $this->counters[ $key ] ?? null;
		return ( $c && $c['until'] > $this->time ) ? $c['v'] : 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Key.
	 * @param int    $ttl TTL.
	 */
	public function counter_incr( string $key, int $ttl ): int {
		$v                      = $this->counter_get( $key ) + 1;
		$until                  = ( isset( $this->counters[ $key ] ) && $this->counters[ $key ]['until'] > $this->time ) ? $this->counters[ $key ]['until'] : $this->time + $ttl;
		$this->counters[ $key ] = array(
			'v'     => $v,
			'until' => $until,
		);
		return $v;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 */
	public function anonymize_owner( string $from, string $to ): int {
		$n = 0;
		foreach ( $this->lots as $id => $l ) {
			if ( $l['owner_key'] === $from ) {
				$this->lots[ $id ]['owner_key'] = $to;
				++$n;
			}
		}
		foreach ( $this->ledger as $id => $r ) {
			if ( $r['owner_key'] === $from ) {
				$this->ledger[ $id ]['owner_key'] = $to;
				++$n;
			}
		}
		return $n;
	}
}
