<?php
/**
 * Persistence contract for jobs, owners and the cost log.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Storage used by Jobs, Job_Runner, Identity, Cost_Log, Limits, Cleanup and Privacy.
 * Production: Store_Wpdb (tables from Schema). Tests: an in-memory implementation (tests/ai/backend/).
 *
 * Rows are plain arrays with the column names of SPEC-AI-DIENSTEN §3; JSON columns are stored as strings and decoded
 * by Jobs. All datetimes are UTC 'Y-m-d H:i:s'.
 */
interface Store {

	/* Jobs ------------------------------------------------------------- */

	/**
	 * @param array $row Full row.
	 */
	public function job_insert( array $row ): bool;

	/**
	 * @param string $id Job id.
	 */
	public function job_get( string $id ): ?array;

	/**
	 * Update fields; when $expect_status is given, only if the current status matches (atomic claim).
	 *
	 * @param string      $id            Job id.
	 * @param array       $fields        Column => value.
	 * @param string|null $expect_status Required current status.
	 * @return int Affected rows (0|1).
	 */
	public function job_update( string $id, array $fields, ?string $expect_status = null ): int;

	/**
	 * @param string $owner      Owner key.
	 * @param string $client_req Idempotency key.
	 */
	public function job_find_req( string $owner, string $client_req ): ?array;

	/**
	 * Latest queued/running job of an owner.
	 *
	 * @param string $owner Owner key.
	 */
	public function job_open( string $owner ): ?array;

	/**
	 * @param string $status Status.
	 */
	public function job_count_status( string $status ): int;

	/**
	 * Jobs with this status created/started before a time (for timeouts).
	 *
	 * @param string $status Status.
	 * @param string $column created_at|started_at.
	 * @param string $before Datetime.
	 * @param int    $limit  Max rows.
	 * @return array[]
	 */
	public function job_stale( string $status, string $column, string $before, int $limit ): array;

	/**
	 * Newest jobs of an owner since a time.
	 *
	 * @param string $owner Owner key.
	 * @param string $since Datetime.
	 * @param int    $limit Max rows.
	 * @return array[]
	 */
	public function job_list( string $owner, string $since, int $limit ): array;

	/**
	 * Jobs whose keep_until passed.
	 *
	 * @param string $now   Datetime.
	 * @param int    $limit Max rows.
	 * @return array[]
	 */
	public function job_expired( string $now, int $limit ): array;

	/**
	 * Blocked jobs older than $before that still hold an input description.
	 *
	 * @param string $before Datetime.
	 * @param int    $limit  Max rows.
	 * @return array[]
	 */
	public function job_blocked_with_input( string $before, int $limit ): array;

	/**
	 * @param string $id Job id.
	 */
	public function job_delete( string $id ): bool;

	/**
	 * Move all jobs from one owner key to another (merge).
	 *
	 * @param string $from From key.
	 * @param string $to   To key.
	 */
	public function job_reassign( string $from, string $to ): int;

	/**
	 * Counts for the admin key figures since a datetime.
	 *
	 * @param string $since Datetime.
	 * @return array{total:int, done:int, chosen:int, blocked:int, failed:int, refine:int}
	 */
	public function job_stats( string $since ): array;

	/* Owners ----------------------------------------------------------- */

	/**
	 * @param string $key Owner key.
	 */
	public function owner_get( string $key ): ?array;

	/**
	 * Insert if missing, otherwise update the given fields.
	 *
	 * @param array $row Row with owner_key.
	 */
	public function owner_upsert( array $row ): void;

	/**
	 * Owners by e-mail or user id (privacy export/eraser).
	 *
	 * @param string $email   E-mail.
	 * @param int    $user_id User id (0 = none).
	 * @return array[]
	 */
	public function owner_find( string $email, int $user_id ): array;

	/**
	 * Owners merged into this key.
	 *
	 * @param string $key Owner key.
	 * @return string[]
	 */
	public function owner_children( string $key ): array;

	/**
	 * Null ip_hash on rows created before a datetime.
	 *
	 * @param string $before Datetime.
	 */
	public function owner_clear_ip( string $before ): int;

	/* Cost log --------------------------------------------------------- */

	/**
	 * @param array $row Row.
	 */
	public function cost_insert( array $row ): bool;

	/**
	 * Sum of cost_eur_micro in [from, to).
	 *
	 * @param string $from Datetime UTC.
	 * @param string $to   Datetime UTC.
	 */
	public function cost_sum( string $from, string $to ): int;

	/**
	 * Rows in [from, to) for the CSV export, oldest first.
	 *
	 * @param string $from  Datetime.
	 * @param string $to    Datetime.
	 * @param int    $limit Max rows.
	 * @return array[]
	 */
	public function cost_rows( string $from, string $to, int $limit ): array;
}
