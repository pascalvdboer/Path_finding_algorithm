<?php
/**
 * Storage contract for round lots and the append-only ledger (SPEC-AI-DIENSTEN §3.2).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by Credits_Store_Wpdb (production) and Credits_Store_Memory (tests, demo).
 * All dates are UTC strings 'Y-m-d H:i:s'. A lot row has the columns of {prefix}bps_ai_lots.
 */
interface Credits_Store {

	/**
	 * Insert a lot, return its id.
	 *
	 * @param array $lot Columns.
	 */
	public function insert_lot( array $lot ): int;

	/**
	 * One lot or null.
	 *
	 * @param int $id Lot id.
	 */
	public function get_lot( int $id ): ?array;

	/**
	 * Lots of an owner (newest last), optionally limited to statuses.
	 *
	 * @param string        $owner    Owner key.
	 * @param string[]|null $statuses Statuses or null for all.
	 */
	public function lots_for_owner( string $owner, ?array $statuses = null ): array;

	/**
	 * Lots linked to an order.
	 *
	 * @param int $order_id Order id.
	 */
	public function lots_for_order( int $order_id ): array;

	/**
	 * Lot for (order_item_id, source), or for (order_id, source) when $order_item_id = 0.
	 *
	 * @param string $source        Source.
	 * @param int    $order_id      Order id.
	 * @param int    $order_item_id Order item id (0 = by order).
	 */
	public function find_lot( string $source, int $order_id, int $order_item_id ): ?array;

	/**
	 * Atomically use $n rounds of a lot: succeeds only when the lot is active, not expired and has $n unused.
	 *
	 * @param int    $lot_id Lot id.
	 * @param int    $n      Rounds.
	 * @param string $now    Now (UTC).
	 */
	public function try_consume( int $lot_id, int $n, string $now ): bool;

	/**
	 * Give back $n used rounds of a lot (never below 0).
	 *
	 * @param int $lot_id Lot id.
	 * @param int $n      Rounds.
	 */
	public function release( int $lot_id, int $n ): bool;

	/**
	 * Update columns of a lot. With $only_status the update only happens when the current status matches (atomic).
	 *
	 * @param int         $lot_id      Lot id.
	 * @param array       $fields      Columns.
	 * @param string|null $only_status Required current status.
	 */
	public function update_lot( int $lot_id, array $fields, ?string $only_status = null ): bool;

	/**
	 * Active lots whose expires_at <= $now.
	 *
	 * @param string $now   Now.
	 * @param int    $limit Max rows.
	 */
	public function expired_lots( string $now, int $limit = 500 ): array;

	/**
	 * Append a ledger row, return its id.
	 *
	 * @param array $row Columns.
	 */
	public function add_ledger( array $row ): int;

	/**
	 * Ledger rows matching all given column values (newest last).
	 *
	 * @param array $where Column => value.
	 * @param int   $limit Max rows.
	 */
	public function ledger_where( array $where, int $limit = 100 ): array;

	/**
	 * Run a callback inside a transaction where supported.
	 *
	 * @param callable $fn Callback.
	 * @return mixed
	 */
	public function transaction( callable $fn );

	/**
	 * Read a short-lived counter (rate limits).
	 *
	 * @param string $key Key.
	 */
	public function counter_get( string $key ): int;

	/**
	 * Increment a counter that resets after $ttl seconds (fixed window), return the new value.
	 *
	 * @param string $key Key.
	 * @param int    $ttl Seconds.
	 */
	public function counter_incr( string $key, int $ttl ): int;

	/**
	 * Privacy erasure only: replace an owner key in lots and ledger (the one allowed ledger UPDATE). Returns rows changed.
	 *
	 * @param string $from Owner key.
	 * @param string $to   Anonymised key ('x:' + hash).
	 */
	public function anonymize_owner( string $from, string $to ): int;
}
