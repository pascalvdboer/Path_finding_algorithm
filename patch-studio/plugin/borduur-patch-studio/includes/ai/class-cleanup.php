<?php
/**
 * Daily cleanup (WP-Cron `bps_ai_cleanup`): retention of jobs, images, refused prompts and IP hashes.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §13 and ai-legal.json retentionDays:
 * - jobs + images whose keep_until passed (guests: 30 d; chosen designs of accounts/e-mail: 365 d after last open;
 *   ordered designs: as long as orders);
 * - the description of refused jobs after refusedPromptLog (30 d) → null, only the code remains;
 * - ip_hash on owner rows after 30 d → null;
 * - owner rows (retentionDays.ownerRows, default 365 d since the last visit; anonymous rows anonymousOwnerRows, 30 d):
 *   deleted when nothing refers to them, otherwise only the e-mail is removed; rows with open jobs or rounds left are
 *   never touched (gdpr-juridisch-7);
 * - stale jobs → failed + refund; lots expire (commerce, through the gateway).
 * The ledger and the cost log are never touched here (bookkeeping; the cost log holds no personal data).
 */
final class Cleanup {

	const BATCH = 200;

	/** Days after which the IP fingerprint (HMAC) on owner rows is removed. */
	const IP_HASH_DAYS = 30;

	/**
	 * Run everything; returns counts for the admin ("wis nu verlopen gegevens").
	 *
	 * @return array{timedOut:int, jobs:int, files:int, refused:int, ips:int, owners:int, ownerEmails:int}
	 */
	public static function run(): array {
		$out = array(
			'timedOut'    => 0,
			'jobs'        => 0,
			'files'       => 0,
			'refused'     => 0,
			'ips'         => 0,
			'owners'      => 0,
			'ownerEmails' => 0,
		);
		$store = Module::store();
		try {
			$out['timedOut'] = Job_Runner::sweep();

			// Expired jobs and their images (in batches, max 10 batches per run).
			for ( $b = 0; $b < 10; $b++ ) {
				$rows = $store->job_expired( Jobs::dt(), self::BATCH );
				if ( empty( $rows ) ) {
					break;
				}
				foreach ( $rows as $job ) {
					$out['files'] += Image_Store::delete_files( Jobs::json( $job['result'] ?? null ) );
					if ( $store->job_delete( (string) $job['id'] ) ) {
						++$out['jobs'];
					}
				}
				if ( count( $rows ) < self::BATCH ) {
					break;
				}
			}

			// Refused prompts: keep only the code.
			$days = AI_Settings::retention( 'refusedPromptLog', 30 );
			foreach ( $store->job_blocked_with_input( Jobs::dt( -$days * DAY_IN_SECONDS ), 500 ) as $job ) {
				$in = Jobs::json( $job['input'] );
				foreach ( array( 'description', 'text', 'instruction' ) as $k ) {
					if ( array_key_exists( $k, $in ) ) {
						$in[ $k ] = null;
					}
				}
				$in['redacted'] = true;
				$out['refused'] += $store->job_update( (string) $job['id'], array( 'input' => wp_json_encode( $in ) ) );
			}

			$out['ips'] = $store->owner_clear_ip( Jobs::dt( -self::IP_HASH_DAYS * DAY_IN_SECONDS ) );
			Credits_Gateway::instance()->expire();

			// Owner rows: after expiring lots, so rounds that just ran out no longer keep a row alive.
			$known  = AI_Settings::retention( 'ownerRows', 365 );
			$anon   = AI_Settings::retention( 'anonymousOwnerRows', 30 );
			$purged = $store->owner_purge( Jobs::dt( -$known * DAY_IN_SECONDS ), Jobs::dt( -$anon * DAY_IN_SECONDS ), Jobs::dt(), self::BATCH * 5 );
			$out['owners']      = (int) ( $purged['deleted'] ?? 0 );
			$out['ownerEmails'] = (int) ( $purged['anonymised'] ?? 0 );
			self::prune_empty_dirs();
		} catch ( \Throwable $e ) {
			Module::log( 'cleanup', get_class( $e ) . ': ' . $e->getMessage() );
			if ( class_exists( '\BorduurPatchStudio\Logger' ) ) {
				\BorduurPatchStudio\Logger::error( 'ai_cleanup_failed', array( 'exception' => $e ) );
			}
		}
		do_action( 'bps_ai_cleanup_done', $out );
		return $out;
	}

	/**
	 * Remove empty month/year directories (keeps index.php and .htaccess at the base).
	 */
	private static function prune_empty_dirs(): void {
		$base = Image_Store::base_dir();
		foreach ( glob( $base . '[0-9][0-9][0-9][0-9]/[0-9][0-9]', GLOB_ONLYDIR ) ?: array() as $dir ) {
			$files = array_diff( (array) scandir( $dir ), array( '.', '..', 'index.php' ) );
			if ( empty( $files ) ) {
				@unlink( $dir . '/index.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}
}
