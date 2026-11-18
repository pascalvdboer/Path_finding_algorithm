<?php
/**
 * Executes a queued job: atomic claim, provider call with one fallback, storage, cost log, refunds, timeouts.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

use BorduurPatchStudio\AI\Providers\Provider;
use BorduurPatchStudio\AI\Providers\Provider_Error;
use BorduurPatchStudio\AI\Providers\Provider_Registry;
use BorduurPatchStudio\AI\Providers\Provider_Result;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §5. Runs from Action Scheduler (`bps_ai_run_job`) or inline on the first poll when the job waited
 * longer than 3 s (or Action Scheduler is missing). The claim (`UPDATE … WHERE status='queued'`) guarantees that a job
 * runs only once, whoever gets there first.
 *
 * Rules: policy refusals never go to a fallback provider (that would bypass our own filter); every failure gives the
 * round back; raw provider messages go to the admin log only (redacted), never to the customer.
 */
final class Job_Runner {

	const INLINE_AFTER = 3;

	/** @var bool Force inline execution (tests, sites without Action Scheduler). */
	public static $force_inline = false;

	/**
	 * Queue a job with Action Scheduler when available.
	 *
	 * @param string $job_id Job id.
	 */
	public static function enqueue( string $job_id ): void {
		if ( self::$force_inline || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		as_enqueue_async_action( Module::RUN_ACTION, array( $job_id ), Module::AS_GROUP );
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param mixed $job_id Job id.
	 */
	public static function run_action( $job_id ): void {
		if ( is_string( $job_id ) && Jobs::valid_id( $job_id ) ) {
			self::run( $job_id );
		}
	}

	/**
	 * Poll found the job still queued: run it here?
	 *
	 * @param array $job Job row.
	 */
	public static function should_run_inline( array $job ): bool {
		if ( self::$force_inline || ! function_exists( 'as_enqueue_async_action' ) ) {
			return true;
		}
		$created = strtotime( (string) $job['created_at'] . ' UTC' );
		return $created && Jobs::now() - $created >= self::INLINE_AFTER;
	}

	/**
	 * Fail a job that waited or ran too long (with refund). Returns true when it was timed out now.
	 *
	 * @param array $job Job row.
	 */
	public static function check_timeout( array $job ): bool {
		$limit = max( 30, (int) AI_Settings::limit( 'jobTimeoutSec' ) );
		if ( 'running' === $job['status'] ) {
			$started = strtotime( (string) $job['started_at'] . ' UTC' );
			if ( $started && Jobs::now() - $started > $limit ) {
				return self::finish_failed( $job, 'timeout', 'running' );
			}
		} elseif ( 'queued' === $job['status'] ) {
			$created = strtotime( (string) $job['created_at'] . ' UTC' );
			if ( $created && Jobs::now() - $created > $limit ) {
				return self::finish_failed( $job, 'provider_unavailable', 'queued' );
			}
		}
		return false;
	}

	/**
	 * Time out every stale job (cron).
	 *
	 * @return int Jobs timed out.
	 */
	public static function sweep(): int {
		$limit = max( 30, (int) AI_Settings::limit( 'jobTimeoutSec' ) );
		$cut   = Jobs::dt( -$limit );
		$n     = 0;
		foreach ( array( 'running' => 'started_at', 'queued' => 'created_at' ) as $status => $col ) {
			foreach ( Module::store()->job_stale( $status, $col, $cut, 200 ) as $job ) {
				$n += self::check_timeout( $job ) ? 1 : 0;
			}
		}
		return $n;
	}

	/**
	 * Run a job. Safe to call concurrently and repeatedly.
	 *
	 * @param string $job_id Job id.
	 * @return string Final status ('' when nothing was done).
	 */
	public static function run( string $job_id ): string {
		$store = Module::store();
		$job   = $store->job_get( $job_id );
		if ( ! $job || 'queued' !== $job['status'] ) {
			return '';
		}
		if ( $store->job_count_status( 'running' ) >= max( 1, (int) AI_Settings::limit( 'globalConcurrent' ) ) ) {
			return ''; // Stays queued; the poll answers with pollAfterMs 3000 and the timeout guards the wait.
		}
		$claimed = $store->job_update(
			$job_id,
			array(
				'status'     => 'running',
				'started_at' => Jobs::dt(),
				'attempts'   => (int) $job['attempts'] + 1,
			),
			'queued'
		);
		if ( 1 !== $claimed ) {
			return '';
		}
		$job = (array) $store->job_get( $job_id );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( max( 60, (int) AI_Settings::limit( 'jobTimeoutSec' ) + 30 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		try {
			$status = self::execute( $job );
		} catch ( \Throwable $e ) {
			Module::log( 'runner', get_class( $e ) . ': ' . $e->getMessage() );
			self::finish_failed( $job, 'provider_error', 'running' );
			$status = 'failed';
		}
		Limits::release( $job_id );
		$final = $store->job_get( $job_id );
		if ( $final && 'done' === $final['status'] ) {
			do_action( 'bps_ai_job_done', $final );
		}
		return $status;
	}

	/**
	 * Provider call(s), storage and the final status.
	 *
	 * @param array $job Running job.
	 */
	private static function execute( array $job ): string {
		$brief   = Jobs::json( $job['brief'] );
		$input   = Jobs::json( $job['input'] );
		$refine  = 'refine' === $job['kind'];
		$paid    = ! empty( $input['paid'] );
		$primary = Provider_Registry::primary();
		$req     = self::request( $job, $brief, $input, $primary );
		if ( null === $req ) {
			return self::finish_failed( $job, 'storage', 'running' ) ? 'failed' : '';
		}

		$result = null;
		$used   = $primary;
		try {
			$result = self::call( $primary, $req, $refine, (string) $job['id'], $paid );
		} catch ( Provider_Error $e ) {
			if ( $e->is_policy() ) {
				return self::finish_policy( $job );
			}
			// OpenAI refuses transparency on some models: retry once with the fabric colour as background.
			if ( ! $refine && 'bad_request' === $e->error_code() && 'background' === $e->param() ) {
				$req2 = self::request( $job, self::rebrief( $input, 'fabric' ), $input, $primary );
				try {
					$result = $req2 ? self::call( $primary, $req2, false, (string) $job['id'], $paid ) : null;
				} catch ( Provider_Error $e2 ) {
					if ( $e2->is_policy() ) {
						return self::finish_policy( $job );
					}
					$e = $e2;
				}
			}
			if ( null === $result ) {
				$fallback = $e->retryable() || 'auth' === $e->error_code() ? Provider_Registry::fallback() : null;
				if ( $fallback && ( ! $refine || ! empty( $fallback->capabilities()['edit'] ) ) ) {
					$used = $fallback;
					try {
						$result = self::call( $fallback, self::request( $job, $brief, $input, $fallback ) ?? $req, $refine, (string) $job['id'], $paid );
					} catch ( Provider_Error $e3 ) {
						if ( $e3->is_policy() ) {
							return self::finish_policy( $job );
						}
						$e = $e3;
					}
				}
			}
			if ( null === $result ) {
				return self::finish_failed( $job, 'timeout' === $e->error_code() ? 'timeout' : ( in_array( $e->error_code(), array( 'auth', 'unavailable', 'rate_limit', 'unsupported' ), true ) ? 'provider_unavailable' : 'provider_error' ), 'running' ) ? 'failed' : '';
			}
		}

		// Store (marked) images.
		$rows = array();
		foreach ( $result->images as $i => $img ) {
			$saved = Image_Store::save( (string) $job['id'], (int) $i, (string) $img['bytes'], $used->id(), $result->model );
			if ( null !== $saved ) {
				$rows[] = $saved;
			}
			if ( count( $rows ) >= max( 1, (int) $job['n'] ) ) {
				break;
			}
		}
		if ( empty( $rows ) ) {
			Module::log( 'storage', 'No image could be stored for job ' . $job['id'] . ' (directory not writable or unsupported format).' );
			return self::finish_failed( $job, 'storage', 'running' ) ? 'failed' : '';
		}
		$done = Module::store()->job_update(
			(string) $job['id'],
			array(
				'status'      => 'done',
				'result'      => wp_json_encode( $rows ),
				'provider'    => $used->id(),
				'model'       => substr( $result->model, 0, 48 ),
				'finished_at' => Jobs::dt(),
			),
			'running'
		);
		if ( 1 !== $done ) {
			// Cancelled or timed out meanwhile (round already given back): drop the files.
			Image_Store::delete_files( $rows );
			return '';
		}
		return 'done';
	}

	/**
	 * Provider request for a job.
	 *
	 * @param array    $job      Job.
	 * @param array    $brief    Brief (generate) or brief with `_edit` (refine).
	 * @param array    $input    Stored input.
	 * @param Provider $provider Provider.
	 * @return array|null Null when the edit source is missing.
	 */
	private static function request( array $job, array $brief, array $input, Provider $provider ): ?array {
		$n     = max( 1, min( (int) ( $provider->capabilities()['max_n'] ?? 4 ), (int) $job['n'] ) );
		$hook  = AI_Settings::test_hooks_allowed() ? (string) ( $input['testHook'] ?? '' ) : '';
		$legal = Module::legal();
		$sid   = ! empty( $legal['defaults']['sendSafetyIdentifier'] ) ? hash_hmac( 'sha256', (string) $job['owner_key'], wp_salt( 'auth' ) ) : null;
		$base  = $brief;
		unset( $base['_edit'] );
		if ( 'refine' === $job['kind'] ) {
			$edit   = (array) ( $brief['_edit'] ?? array() );
			$parent = Module::store()->job_get( (string) $job['parent_id'] );
			$row    = $parent ? Jobs::proposal_row( $parent, (int) $job['parent_index'] ) : null;
			$path   = $row ? Image_Store::edit_source( (string) $row['file'] ) : '';
			if ( '' === $path ) {
				return null;
			}
			$params      = (array) ( $edit['params'] ?? array() );
			$params['n'] = $n;
			return array(
				'prompt'     => (string) ( $edit['prompt'] ?? '' ),
				'params'     => $params,
				'model'      => Provider_Registry::model( $provider ),
				'image_path' => $path,
				'mask_path'  => null,
				'safety_id'  => $sid,
				'brief'      => $base,
				'test_hook'  => $hook,
			);
		}
		$built             = Prompt_Engine::build( $base, Module::prompt_cfg() );
		$params            = (array) $built['params'];
		$params['n']       = $n;
		$params['quality'] = Providers\Openai_Provider::quality();
		return array(
			'prompt'    => (string) $built['prompt'],
			'params'    => $params,
			'model'     => Provider_Registry::model( $provider ),
			'safety_id' => $sid,
			'brief'     => $base,
			'test_hook' => $hook,
		);
	}

	/**
	 * Call a provider and log the cost (also for failures, with cost 0).
	 *
	 * @param Provider $p      Provider.
	 * @param array    $req    Request.
	 * @param bool     $edit   Edit instead of generate.
	 * @param string   $job_id Job id.
	 * @param bool     $paid   Paid round.
	 * @throws Provider_Error On failure (after logging).
	 */
	private static function call( Provider $p, array $req, bool $edit, string $job_id, bool $paid ): Provider_Result {
		$start = microtime( true );
		try {
			$r = $edit ? $p->edit( $req ) : $p->generate( $req );
		} catch ( Provider_Error $e ) {
			Module::log( 'provider', $p->id() . ' ' . $e->error_code() . ': ' . $e->getMessage() );
			Cost_Log::add(
				array(
					'job_id'      => $job_id,
					'provider'    => $p->id(),
					'model'       => (string) ( $req['model'] ?? '' ),
					'op'          => $edit ? 'edit' : 'generate',
					'cost_usd'    => 0,
					'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
					'outcome'     => $e->is_policy() ? 'policy' : ( 'timeout' === $e->error_code() ? 'timeout' : 'error' ),
					'paid'        => $paid,
				)
			);
			throw $e;
		}
		$r->provider = $p->id();
		if ( '' === $r->model ) {
			$r->model = (string) ( $req['model'] ?? '' );
		}
		if ( 0 === $r->duration_ms ) {
			$r->duration_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		}
		Cost_Log::add_result( $r, $job_id, $edit ? 'edit' : 'generate', $paid );
		return $r;
	}

	/**
	 * Brief again with another background (transparency fallback).
	 *
	 * @param array  $input      Stored input.
	 * @param string $background transparent|fabric.
	 */
	private static function rebrief( array $input, string $background ): array {
		$input['background']      = $background;
		$input['clientRequestId'] = 'x';
		$input['maxColors']       = $input['maxColors'] ?? null;
		$brief                    = Prompt_Engine::brief( Jobs::adapter_input( $input ), Module::prompt_cfg(), Module::studio_defaults() );
		return empty( $brief['blocked'] ) ? $brief : array();
	}

	/**
	 * Provider refused: status blocked, round back, refusal counted.
	 *
	 * @param array $job Job.
	 */
	private static function finish_policy( array $job ): string {
		$n = Module::store()->job_update(
			(string) $job['id'],
			array(
				'status'      => 'blocked',
				'error_code'  => 'provider_policy',
				'finished_at' => Jobs::dt(),
			),
			'running'
		);
		if ( 1 === $n ) {
			Jobs::refund( $job, 'job_blocked' );
			Limits::record_refusal( (string) $job['owner_key'], '', 'provider_policy' );
		}
		return 'blocked';
	}

	/**
	 * Mark failed (only from the expected status) and give the round back.
	 *
	 * @param array  $job    Job.
	 * @param string $code   provider_error|timeout|provider_unavailable|storage.
	 * @param string $expect Expected current status.
	 */
	public static function finish_failed( array $job, string $code, string $expect ): bool {
		$n = Module::store()->job_update(
			(string) $job['id'],
			array(
				'status'      => 'failed',
				'error_code'  => $code,
				'finished_at' => Jobs::dt(),
			),
			$expect
		);
		if ( 1 !== $n ) {
			return false;
		}
		Jobs::refund( $job, 'job_failed' );
		Limits::release( (string) $job['id'] );
		return true;
	}

}
