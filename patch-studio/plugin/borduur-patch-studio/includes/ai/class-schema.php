<?php
/**
 * Database tables of the AI module (dbDelta, own db version).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Tables `{prefix}bps_ai_owners`, `bps_ai_jobs`, `bps_ai_costs` (ai-backend) and `bps_ai_lots`, `bps_ai_ledger`
 * (data of commerce, created here so there is exactly one place with one db version: SPEC-AI-DIENSTEN §3, A14).
 * The SQL follows the spec column for column; `bps_ai_owners.anon_granted_at` is an addition of ai-backend that
 * makes the free anonymous round idempotent per owner (see docs/handoff/ai-services-needs.md).
 */
final class Schema {

	const VERSION        = 1;
	const VERSION_OPTION = 'bps_ai_db_version';

	/**
	 * Table name with prefix.
	 *
	 * @param string $name owners|jobs|costs|lots|ledger.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'bps_ai_' . $name;
	}

	/**
	 * Install/upgrade when the stored version is older. Cheap check, run on init.
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Create/upgrade all tables.
	 */
	public static function install(): void {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		foreach ( self::sql( $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * CREATE TABLE statements in dbDelta format (two spaces before PRIMARY KEY, one key per line).
	 *
	 * @param string $collate Charset/collation clause.
	 * @return string[]
	 */
	public static function sql( string $collate ): array {
		$o = self::table( 'owners' );
		$j = self::table( 'jobs' );
		$c = self::table( 'costs' );
		$l = self::table( 'lots' );
		$g = self::table( 'ledger' );
		return array(
			"CREATE TABLE {$o} (
  owner_key CHAR(66) NOT NULL,
  kind VARCHAR(8) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  email VARCHAR(190) NULL,
  email_verified_at DATETIME NULL,
  merged_into CHAR(66) NULL,
  ip_hash CHAR(64) NULL,
  anon_granted_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY  (owner_key),
  KEY user_id (user_id),
  KEY email (email(64)),
  KEY merged_into (merged_into),
  KEY ip_hash (ip_hash, created_at)
) {$collate};",
			"CREATE TABLE {$j} (
  id CHAR(26) NOT NULL,
  owner_key CHAR(66) NOT NULL,
  kind VARCHAR(8) NOT NULL,
  parent_id CHAR(26) NULL,
  parent_index TINYINT NULL,
  status VARCHAR(10) NOT NULL,
  client_req CHAR(36) NULL,
  input LONGTEXT NOT NULL,
  brief LONGTEXT NULL,
  history LONGTEXT NULL,
  provider VARCHAR(16) NULL,
  model VARCHAR(48) NULL,
  n TINYINT NOT NULL,
  lot_id BIGINT UNSIGNED NULL,
  rounds TINYINT NOT NULL DEFAULT 0,
  result LONGTEXT NULL,
  chosen_index TINYINT NULL,
  error_code VARCHAR(32) NULL,
  attempts TINYINT NOT NULL DEFAULT 0,
  locale VARCHAR(5) NOT NULL,
  created_at DATETIME NOT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  keep_until DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY owner_req (owner_key, client_req),
  KEY owner (owner_key, created_at),
  KEY status (status, created_at),
  KEY keep_until (keep_until)
) {$collate};",
			"CREATE TABLE {$c} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  at DATETIME NOT NULL,
  job_id CHAR(26) NULL,
  provider VARCHAR(16) NOT NULL,
  model VARCHAR(48) NOT NULL,
  op VARCHAR(12) NOT NULL,
  images TINYINT NOT NULL DEFAULT 0,
  tokens_in INT NULL,
  tokens_out INT NULL,
  cost_usd_micro BIGINT NOT NULL,
  estimated TINYINT(1) NOT NULL DEFAULT 0,
  cost_eur_micro BIGINT NOT NULL,
  duration_ms INT NOT NULL,
  outcome VARCHAR(12) NOT NULL,
  paid TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY at (at),
  KEY job (job_id)
) {$collate};",
			"CREATE TABLE {$l} (
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
  KEY owner (owner_key, status, expires_at),
  KEY order_id (order_id)
) {$collate};",
			"CREATE TABLE {$g} (
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
  KEY owner (owner_key, created_at),
  KEY job (job_id),
  KEY lot (lot_id)
) {$collate};",
		);
	}

	/**
	 * Drop all module tables (uninstall only).
	 */
	public static function drop(): void {
		global $wpdb;
		foreach ( array( 'owners', 'jobs', 'costs', 'lots', 'ledger' ) as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		delete_option( self::VERSION_OPTION );
	}
}
