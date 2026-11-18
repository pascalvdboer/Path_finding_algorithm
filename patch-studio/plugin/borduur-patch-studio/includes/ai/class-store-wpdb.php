<?php
/**
 * Store implementation on $wpdb (tables from Schema).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables, names come from Schema::table().

/**
 * All SQL goes through $wpdb->prepare / $wpdb->insert / $wpdb->update.
 */
final class Store_Wpdb implements Store {

	const JOB_COLUMNS = array( 'id', 'owner_key', 'kind', 'parent_id', 'parent_index', 'status', 'client_req', 'input', 'brief', 'history', 'provider', 'model', 'n', 'lot_id', 'rounds', 'result', 'chosen_index', 'error_code', 'attempts', 'locale', 'created_at', 'started_at', 'finished_at', 'keep_until' );

	const OWNER_COLUMNS = array( 'owner_key', 'kind', 'user_id', 'email', 'email_verified_at', 'merged_into', 'ip_hash', 'anon_granted_at', 'created_at', 'last_seen_at' );

	/**
	 * @return \wpdb
	 */
	private function db() {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Keep only known columns.
	 *
	 * @param array    $row     Row.
	 * @param string[] $allowed Columns.
	 */
	private function only( array $row, array $allowed ): array {
		return array_intersect_key( $row, array_flip( $allowed ) );
	}

	/* Jobs ------------------------------------------------------------- */

	public function job_insert( array $row ): bool {
		return false !== $this->db()->insert( Schema::table( 'jobs' ), $this->only( $row, self::JOB_COLUMNS ) );
	}

	public function job_get( string $id ): ?array {
		$t   = Schema::table( 'jobs' );
		$row = $this->db()->get_row( $this->db()->prepare( "SELECT * FROM {$t} WHERE id = %s", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function job_update( string $id, array $fields, ?string $expect_status = null ): int {
		$fields = $this->only( $fields, self::JOB_COLUMNS );
		unset( $fields['id'] );
		if ( empty( $fields ) ) {
			return 0;
		}
		$where = array( 'id' => $id );
		if ( null !== $expect_status ) {
			$where['status'] = $expect_status;
		}
		$n = $this->db()->update( Schema::table( 'jobs' ), $fields, $where );
		return false === $n ? 0 : (int) $n;
	}

	public function job_find_req( string $owner, string $client_req ): ?array {
		$t   = Schema::table( 'jobs' );
		$row = $this->db()->get_row( $this->db()->prepare( "SELECT * FROM {$t} WHERE owner_key = %s AND client_req = %s LIMIT 1", $owner, $client_req ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function job_open( string $owner ): ?array {
		$t   = Schema::table( 'jobs' );
		$row = $this->db()->get_row( $this->db()->prepare( "SELECT * FROM {$t} WHERE owner_key = %s AND status IN ('queued','running') ORDER BY created_at DESC LIMIT 1", $owner ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function job_count_status( string $status ): int {
		$t = Schema::table( 'jobs' );
		return (int) $this->db()->get_var( $this->db()->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = %s", $status ) );
	}

	public function job_stale( string $status, string $column, string $before, int $limit ): array {
		$t      = Schema::table( 'jobs' );
		$column = 'started_at' === $column ? 'started_at' : 'created_at';
		$rows   = $this->db()->get_results( $this->db()->prepare( "SELECT * FROM {$t} WHERE status = %s AND {$column} < %s ORDER BY {$column} ASC LIMIT %d", $status, $before, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function job_list( string $owner, string $since, int $limit ): array {
		$t    = Schema::table( 'jobs' );
		$rows = $this->db()->get_results( $this->db()->prepare( "SELECT * FROM {$t} WHERE owner_key = %s AND created_at >= %s ORDER BY created_at DESC LIMIT %d", $owner, $since, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function job_expired( string $now, int $limit ): array {
		$t    = Schema::table( 'jobs' );
		$rows = $this->db()->get_results( $this->db()->prepare( "SELECT * FROM {$t} WHERE keep_until < %s AND status NOT IN ('queued','running') ORDER BY keep_until ASC LIMIT %d", $now, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function job_blocked_with_input( string $before, int $limit ): array {
		$t    = Schema::table( 'jobs' );
		$rows = $this->db()->get_results( $this->db()->prepare( "SELECT * FROM {$t} WHERE status = 'blocked' AND created_at < %s AND ( input LIKE %s OR input LIKE %s ) LIMIT %d", $before, '%"description":"%', '%"instruction":"%', $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function job_delete( string $id ): bool {
		return false !== $this->db()->delete( Schema::table( 'jobs' ), array( 'id' => $id ) );
	}

	public function job_reassign( string $from, string $to ): int {
		$t = Schema::table( 'jobs' );
		// IGNORE: an identical (owner, client_req) pair on both keys keeps the older row on the weaker key; it expires.
		$n = $this->db()->query( $this->db()->prepare( "UPDATE IGNORE {$t} SET owner_key = %s WHERE owner_key = %s", $to, $from ) );
		return false === $n ? 0 : (int) $n;
	}

	public function job_stats( string $since ): array {
		$t   = Schema::table( 'jobs' );
		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT COUNT(*) AS total,
					SUM(status = 'done') AS done,
					SUM(chosen_index IS NOT NULL) AS chosen,
					SUM(status = 'blocked') AS blocked,
					SUM(status = 'failed') AS failed,
					SUM(kind = 'refine') AS refine
				FROM {$t} WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);
		$out = array();
		foreach ( array( 'total', 'done', 'chosen', 'blocked', 'failed', 'refine' ) as $k ) {
			$out[ $k ] = (int) ( $row[ $k ] ?? 0 );
		}
		return $out;
	}

	/* Owners ----------------------------------------------------------- */

	public function owner_get( string $key ): ?array {
		$t   = Schema::table( 'owners' );
		$row = $this->db()->get_row( $this->db()->prepare( "SELECT * FROM {$t} WHERE owner_key = %s", $key ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function owner_upsert( array $row ): void {
		$row = $this->only( $row, self::OWNER_COLUMNS );
		if ( empty( $row['owner_key'] ) ) {
			return;
		}
		if ( null === $this->owner_get( (string) $row['owner_key'] ) ) {
			$now = gmdate( 'Y-m-d H:i:s' );
			$row = array_merge(
				array(
					'kind'         => 'anon',
					'created_at'   => $now,
					'last_seen_at' => $now,
				),
				$row
			);
			// Concurrent first requests: a duplicate key just means the row exists.
			$this->db()->suppress_errors( true );
			$ok = $this->db()->insert( Schema::table( 'owners' ), $row );
			$this->db()->suppress_errors( false );
			if ( false !== $ok ) {
				return;
			}
		}
		$key = (string) $row['owner_key'];
		unset( $row['owner_key'], $row['created_at'] );
		if ( ! empty( $row ) ) {
			$this->db()->update( Schema::table( 'owners' ), $row, array( 'owner_key' => $key ) );
		}
	}

	public function owner_find( string $email, int $user_id ): array {
		$t    = Schema::table( 'owners' );
		$rows = $this->db()->get_results( $this->db()->prepare( "SELECT * FROM {$t} WHERE ( email = %s AND email <> '' ) OR ( user_id = %d AND user_id > 0 )", $email, $user_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function owner_children( string $key ): array {
		$t = Schema::table( 'owners' );
		$v = $this->db()->get_col( $this->db()->prepare( "SELECT owner_key FROM {$t} WHERE merged_into = %s", $key ) );
		return is_array( $v ) ? array_map( 'strval', $v ) : array();
	}

	public function owner_clear_ip( string $before ): int {
		$t = Schema::table( 'owners' );
		$n = $this->db()->query( $this->db()->prepare( "UPDATE {$t} SET ip_hash = NULL WHERE ip_hash IS NOT NULL AND created_at < %s", $before ) );
		return false === $n ? 0 : (int) $n;
	}

	/* Costs ------------------------------------------------------------ */

	public function cost_insert( array $row ): bool {
		$cols = array( 'at', 'job_id', 'provider', 'model', 'op', 'images', 'tokens_in', 'tokens_out', 'cost_usd_micro', 'estimated', 'cost_eur_micro', 'duration_ms', 'outcome', 'paid' );
		return false !== $this->db()->insert( Schema::table( 'costs' ), $this->only( $row, $cols ) );
	}

	public function cost_sum( string $from, string $to ): int {
		$t = Schema::table( 'costs' );
		return (int) $this->db()->get_var( $this->db()->prepare( "SELECT COALESCE(SUM(cost_eur_micro),0) FROM {$t} WHERE at >= %s AND at < %s", $from, $to ) );
	}

	public function cost_rows( string $from, string $to, int $limit ): array {
		$t    = Schema::table( 'costs' );
		$rows = $this->db()->get_results( $this->db()->prepare( "SELECT * FROM {$t} WHERE at >= %s AND at < %s ORDER BY at ASC LIMIT %d", $from, $to, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
