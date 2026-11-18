<?php
/**
 * Credits_Store on {prefix}bps_ai_lots and {prefix}bps_ai_ledger (SPEC-AI-DIENSTEN §3.2).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Production store. All SQL goes through $wpdb->prepare; consumption is a single conditional UPDATE.
 */
final class Credits_Store_Wpdb implements Credits_Store {

	/** Lot columns that may be written. */
	const LOT_COLUMNS = array( 'owner_key', 'source', 'rounds_total', 'rounds_used', 'order_id', 'order_item_id', 'pack_id', 'price_incl', 'consent_at', 'usable_from', 'expires_at', 'status', 'created_at' );

	/** Ledger columns that may be written. */
	const LEDGER_COLUMNS = array( 'owner_key', 'lot_id', 'delta', 'kind', 'reason', 'job_id', 'order_id', 'actor', 'created_at' );

	/**
	 * Table names.
	 */
	public static function tables(): array {
		global $wpdb;
		return array(
			'lots'   => $wpdb->prefix . 'bps_ai_lots',
			'ledger' => $wpdb->prefix . 'bps_ai_ledger',
		);
	}

	/**
	 * CREATE TABLE statements for dbDelta (identical to the spec; AI\Schema may run the same SQL).
	 */
	public static function schema_sql(): array {
		global $wpdb;
		$t       = self::tables();
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		return array(
			"CREATE TABLE {$t['lots']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_key CHAR(66) NOT NULL,
  source VARCHAR(16) NOT NULL,
  rounds_total INT NOT NULL,
  rounds_used INT NOT NULL DEFAULT 0,
  order_id BIGINT UNSIGNED NULL,
  order_item_id BIGINT UNSIGNED NULL,
  pack_id VARCHAR(32) NULL,
  price_incl DECIMAL(12,4) NULL,
  consent_at DATETIME NULL,
  usable_from DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY owner (owner_key,status,expires_at),
  KEY order_id (order_id)
) {$charset};",
			"CREATE TABLE {$t['ledger']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_key CHAR(66) NOT NULL,
  lot_id BIGINT UNSIGNED NULL,
  delta INT NOT NULL,
  kind VARCHAR(16) NOT NULL,
  reason VARCHAR(32) NOT NULL,
  job_id CHAR(26) NULL,
  order_id BIGINT UNSIGNED NULL,
  actor BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY owner (owner_key,created_at),
  KEY job (job_id),
  KEY lot (lot_id)
) {$charset};",
		);
	}

	/**
	 * Create/upgrade the tables.
	 */
	public static function install(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		foreach ( self::schema_sql() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Drop the tables (uninstall with "Alles verwijderen" only; uninstall.php calls this directly, no module boot).
	 */
	public static function uninstall(): void {
		global $wpdb;
		foreach ( self::tables() as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$t}" ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Tables of this store without the $wpdb prefix (for Plugin::owned_data()).
	 *
	 * @return string[]
	 */
	public static function table_names(): array {
		return array( 'bps_ai_lots', 'bps_ai_ledger' );
	}

	/**
	 * Cast a DB row to typed values.
	 *
	 * @param array|null $row Row.
	 */
	private static function lot_row( ?array $row ): ?array {
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'id', 'rounds_total', 'rounds_used' ) as $k ) {
			$row[ $k ] = (int) $row[ $k ];
		}
		foreach ( array( 'order_id', 'order_item_id' ) as $k ) {
			$row[ $k ] = null === $row[ $k ] ? null : (int) $row[ $k ];
		}
		$row['price_incl'] = null === $row['price_incl'] ? null : (float) $row['price_incl'];
		return $row;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $lot Columns.
	 */
	public function insert_lot( array $lot ): int {
		global $wpdb;
		$data = array_intersect_key( $lot, array_flip( self::LOT_COLUMNS ) );
		$ok   = $wpdb->insert( self::tables()['lots'], $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Lot id.
	 */
	public function get_lot( int $id ): ?array {
		global $wpdb;
		$t = self::tables()['lots'];
		return self::lot_row( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string        $owner    Owner.
	 * @param string[]|null $statuses Statuses.
	 */
	public function lots_for_owner( string $owner, ?array $statuses = null ): array {
		global $wpdb;
		$t   = self::tables()['lots'];
		$sql = $wpdb->prepare( "SELECT * FROM {$t} WHERE owner_key = %s", $owner ); // phpcs:ignore WordPress.DB
		if ( null !== $statuses && $statuses ) {
			$in   = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$sql .= $wpdb->prepare( " AND status IN ({$in})", ...array_values( $statuses ) ); // phpcs:ignore WordPress.DB
		}
		$rows = (array) $wpdb->get_results( $sql . ' ORDER BY id ASC LIMIT 500', ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'lot_row' ), $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $order_id Order id.
	 */
	public function lots_for_order( int $order_id ): array {
		global $wpdb;
		$t    = self::tables()['lots'];
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d ORDER BY id ASC", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'lot_row' ), $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $source        Source.
	 * @param int    $order_id      Order.
	 * @param int    $order_item_id Item.
	 */
	public function find_lot( string $source, int $order_id, int $order_item_id ): ?array {
		global $wpdb;
		$t = self::tables()['lots'];
		if ( $order_item_id > 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE source = %s AND order_item_id = %d LIMIT 1", $source, $order_item_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE source = %s AND order_id = %d LIMIT 1", $source, $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		return self::lot_row( $row );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $lot_id Lot.
	 * @param int    $n      Rounds.
	 * @param string $now    Now.
	 */
	public function try_consume( int $lot_id, int $n, string $now ): bool {
		global $wpdb;
		$t = self::tables()['lots'];
		$r = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$t} SET rounds_used = rounds_used + %d WHERE id = %d AND status = 'active' AND rounds_total - rounds_used >= %d AND expires_at > %s AND usable_from <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$n,
				$lot_id,
				$n,
				$now,
				$now
			)
		);
		return 1 === (int) $r;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $lot_id Lot.
	 * @param int $n      Rounds.
	 */
	public function release( int $lot_id, int $n ): bool {
		global $wpdb;
		$t = self::tables()['lots'];
		$r = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET rounds_used = GREATEST(0, rounds_used - %d) WHERE id = %d", $n, $lot_id ) ); // phpcs:ignore WordPress.DB
		return false !== $r;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int         $lot_id      Lot.
	 * @param array       $fields      Fields.
	 * @param string|null $only_status Status guard.
	 */
	public function update_lot( int $lot_id, array $fields, ?string $only_status = null ): bool {
		global $wpdb;
		$data = array_intersect_key( $fields, array_flip( self::LOT_COLUMNS ) );
		if ( ! $data ) {
			return false;
		}
		$where = array( 'id' => $lot_id );
		if ( null !== $only_status ) {
			$where['status'] = $only_status;
		}
		$r = $wpdb->update( self::tables()['lots'], $data, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null === $only_status ? false !== $r : 1 === (int) $r;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $now   Now.
	 * @param int    $limit Limit.
	 */
	public function expired_lots( string $now, int $limit = 500 ): array {
		global $wpdb;
		$t    = self::tables()['lots'];
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'active' AND expires_at <= %s ORDER BY id ASC LIMIT %d", $now, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'lot_row' ), $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $row Row.
	 */
	public function add_ledger( array $row ): int {
		global $wpdb;
		$data = array_intersect_key( $row, array_flip( self::LEDGER_COLUMNS ) );
		$ok   = $wpdb->insert( self::tables()['ledger'], $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $where Where.
	 * @param int   $limit Limit.
	 */
	public function ledger_where( array $where, int $limit = 100 ): array {
		global $wpdb;
		$t    = self::tables()['ledger'];
		$cond = array();
		$args = array();
		foreach ( $where as $k => $v ) {
			if ( ! in_array( $k, self::LEDGER_COLUMNS, true ) ) {
				continue;
			}
			$cond[] = "`{$k}` = %s";
			$args[] = (string) $v;
		}
		$sql  = "SELECT * FROM {$t}" . ( $cond ? ' WHERE ' . implode( ' AND ', $cond ) : '' ) . ' ORDER BY id DESC LIMIT %d';
		$args[] = $limit;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_reverse( $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param callable $fn Callback.
	 * @return mixed
	 */
	public function transaction( callable $fn ) {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB
		try {
			$out = $fn();
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
			return $out;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
			// Not logged here on purpose: the exception is rethrown and logged once by the caller
			// (AI\Credits_Gateway logs every failed Credits call).
			throw $e;
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Key.
	 */
	public function counter_get( string $key ): int {
		$v = get_transient( 'bps_svc_c_' . md5( $key ) );
		return is_array( $v ) && (int) $v['until'] > time() ? (int) $v['v'] : 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Key.
	 * @param int    $ttl TTL.
	 */
	public function counter_incr( string $key, int $ttl ): int {
		$name = 'bps_svc_c_' . md5( $key );
		$v    = get_transient( $name );
		if ( ! is_array( $v ) || (int) $v['until'] <= time() ) {
			$v = array(
				'v'     => 0,
				'until' => time() + $ttl,
			);
		}
		++$v['v'];
		set_transient( $name, $v, max( 1, (int) $v['until'] - time() ) );
		return (int) $v['v'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 */
	public function anonymize_owner( string $from, string $to ): int {
		global $wpdb;
		$n = 0;
		foreach ( self::tables() as $t ) {
			$n += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET owner_key = %s WHERE owner_key = %s", $to, $from ) ); // phpcs:ignore WordPress.DB
		}
		return $n;
	}
}
