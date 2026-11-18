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
 * - stale jobs → failed + refund; lots expire (commerce, through the gateway).
 * The ledger and the cost log are never touched here (bookkeeping; the cost log holds no personal data).
 */
final class Cleanup {

	const BATCH = 200;

	/**
	 * Run everything; returns counts for the admin ("wis nu verlopen gegevens").
	 *
	 * @return array{timedOut:int, jobs:int, files:int, refused:int, ips:int}
	 */
	public static function run(): array {
		$out = array(
			'timedOut' => 0,
			'jobs'     => 0,
			'files'    => 0,
			'refused'  => 0,
			'ips'      => 0,
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

			$out['ips'] = $store->owner_clear_ip( Jobs::dt( -30 * DAY_IN_SECONDS ) );
			Credits_Gateway::instance()->expire();
			self::prune_empty_dirs();
		} catch ( \Throwable $e ) {
			Module::log( 'cleanup', get_class( $e ) . ': ' . $e->getMessage() );
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
