<?php
/**
 * Jobs: validation, idempotency, admission, rounds, job rows and the public JSON shapes (JobResponse & co).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §3.3, §4.3, §5. Transport-free: Rest passes the owner key, the decoded body and a small context
 * ({ip_hash, manager}); every method returns ['status' => int, 'body' => array] or a WP_Error in the spec error shape.
 * That keeps the whole flow testable without a REST server (tests/ai/backend/).
 *
 * Privacy: the job stores only the cleaned description (Prompt_Engine::sanitize) and choice fields; the brief and the
 * prompt never leave the server; providers get no names, e-mails, ids or IPs.
 */
final class Jobs {

	const ALPHABET    = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford base32.
	const STYLES      = array( 'auto', 'badge', 'mascot', 'vintage', 'minimal', 'military', 'club', 'typographic' );
	const SHAPES      = array( 'auto', 'circle', 'oval', 'rect', 'rounded', 'shield', 'contour' );
	const TEXT_MODES  = array( 'auto', 'studio', 'ai', 'none' );
	const TECHNIQUES  = array( 'embroidered', 'woven', 'printed', 'pvc', 'chenille', 'leather' );
	const OPEN        = array( 'queued', 'running' );
	const TEST_MARKER = '/\[\[mock:(fail|policy|slow|timeout)\]\]/i';

	const SHAPE_LABELS = array(
		'circle'  => array( 'nl' => 'rond', 'fr' => 'rond' ),
		'oval'    => array( 'nl' => 'ovaal', 'fr' => 'ovale' ),
		'rect'    => array( 'nl' => 'rechthoekig', 'fr' => 'rectangulaire' ),
		'rounded' => array( 'nl' => 'afgeronde hoeken', 'fr' => 'coins arrondis' ),
		'shield'  => array( 'nl' => 'schild', 'fr' => 'blason' ),
		'contour' => array( 'nl' => 'op contour', 'fr' => 'à la forme' ),
	);

	/** @var int|null Clock override (tests). */
	public static $now = null;

	/**
	 * Unix time.
	 */
	public static function now(): int {
		return null !== self::$now ? self::$now : time();
	}

	/**
	 * UTC datetime string.
	 *
	 * @param int $offset Seconds from now.
	 */
	public static function dt( int $offset = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', self::now() + $offset );
	}

	/**
	 * Random, unguessable 26-char Crockford id (130 bits of randomness).
	 */
	public static function new_id(): string {
		$bytes = random_bytes( 17 );
		$bits  = '';
		foreach ( str_split( $bytes ) as $b ) {
			$bits .= str_pad( decbin( ord( $b ) ), 8, '0', STR_PAD_LEFT );
		}
		$id = '';
		for ( $i = 0; $i < 26; $i++ ) {
			$id .= self::ALPHABET[ bindec( substr( $bits, $i * 5, 5 ) ) ];
		}
		return $id;
	}

	/**
	 * Valid job id format.
	 *
	 * @param string $id Id.
	 */
	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $id );
	}

	/* =====================================================================
	 * Generate
	 * =================================================================== */

	/**
	 * POST /ai/generate.
	 *
	 * @param string $owner Owner key.
	 * @param array  $body  Decoded JSON body.
	 * @param array  $ctx   {ip_hash:string, manager:bool}.
	 * @return array{status:int, body:array}|WP_Error
	 */
	public static function generate( string $owner, array $body, array $ctx ) {
		$in = self::validate_generate( $body );
		if ( is_wp_error( $in ) ) {
			return $in;
		}
		Messages::set_locale( $in['lang'] );
		$store = Module::store();

		// 1. Idempotency: the same clientRequestId returns the same job (double tap, retry after a network error).
		$same = $store->job_find_req( $owner, $in['clientRequestId'] );
		if ( $same ) {
			return array(
				'status' => in_array( $same['status'], self::OPEN, true ) ? 202 : 200,
				'body'   => self::response( $same, $owner ),
			);
		}
		$pre = self::preflight( $owner, $ctx );
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}

		// 2. Brief (local safety + privacy filter, prompt team) — a refusal never costs a round.
		$cfg     = Module::prompt_cfg();
		$clean   = Prompt_Engine::sanitize( $in['description'], $cfg );
		$stored  = self::stored_input( $in, (string) $clean['text'] );
		$brief   = Prompt_Engine::brief( self::adapter_input( $in ), $cfg, Module::studio_defaults() );
		$job_id  = self::new_id();
		if ( ! empty( $brief['blocked'] ) ) {
			return self::blocked( $owner, $job_id, 'generate', $stored, (string) $brief['code'], (string) ( $brief['message'] ?? '' ), $ctx, $in['clientRequestId'] );
		}

		// 3. Moderation API on the cleaned text (layer 2a, free).
		$mod = Moderation::check( trim( (string) $clean['text'] . ( 'ai' === $brief['textMode'] ? ' ' . $brief['text'] : '' ) ), $job_id );
		if ( $mod['flagged'] ) {
			return self::blocked( $owner, $job_id, 'generate', $stored, $mod['code'], '', $ctx, $in['clientRequestId'] );
		}

		$n = (int) AI_Settings::clamp( 'n', AI_Settings::path( 'n', 3 ) );
		return self::admit_and_queue(
			$owner,
			$ctx,
			array(
				'id'         => $job_id,
				'kind'       => 'generate',
				'client_req' => $in['clientRequestId'],
				'input'      => $stored,
				'brief'      => $brief,
				'history'    => array(),
				'n'          => $n,
				'locale'     => $in['lang'],
			)
		);
	}

	/**
	 * Validate and normalise the generate body (400 bps_ai_invalid with the offending field).
	 *
	 * @param array $b Body.
	 * @return array|WP_Error
	 */
	public static function validate_generate( array $b ) {
		$lim  = (array) ( Module::prompt_cfg()['limits'] ?? array() );
		$max  = (int) ( $lim['descriptionMaxChars'] ?? 400 );
		$req  = self::client_req( $b['clientRequestId'] ?? '' );
		$lang = 'fr' === ( $b['lang'] ?? 'nl' ) ? 'fr' : 'nl';
		Messages::set_locale( $lang );
		if ( '' === $req ) {
			return Messages::error( 'bps_ai_invalid', 400, array( 'field' => 'clientRequestId' ) );
		}
		$desc = self::clean_text( $b['description'] ?? '' );
		$text = self::clean_text( $b['text'] ?? '' );
		if ( mb_strlen( $desc ) < 3 && '' === $text ) {
			return Messages::error( 'bps_ai_invalid', 400, array( 'field' => 'description' ) );
		}
		if ( mb_strlen( $desc ) > $max ) {
			return Messages::error( 'bps_ai_invalid', 400, array( 'field' => 'description', 'max' => $max ) );
		}
		if ( mb_strlen( $text ) > 60 ) {
			return Messages::error( 'bps_ai_invalid', 400, array( 'field' => 'text', 'max' => 60 ) );
		}
		$max_colors = null;
		if ( isset( $b['maxColors'] ) && null !== $b['maxColors'] && '' !== $b['maxColors'] ) {
			if ( ! is_numeric( $b['maxColors'] ) || (int) $b['maxColors'] < 1 || (int) $b['maxColors'] > 8 ) {
				return Messages::error( 'bps_ai_invalid', 400, array( 'field' => 'maxColors' ) );
			}
			$max_colors = (int) $b['maxColors'];
		}
		$ctx = isset( $b['context'] ) && is_array( $b['context'] ) ? $b['context'] : array();
		$hook = '';
		if ( preg_match( self::TEST_MARKER, $desc, $m ) ) {
			$hook = AI_Settings::test_hooks_allowed() ? strtolower( $m[1] ) : '';
			$desc = trim( (string) preg_replace( self::TEST_MARKER, '', $desc ) );
			if ( mb_strlen( $desc ) < 3 && '' === $text ) {
				$desc = 'wolf';
			}
		}
		return array(
			'clientRequestId' => $req,
			'description'     => $desc,
			'text'            => $text,
			'lang'            => $lang,
			'style'           => self::enum( $b['style'] ?? 'auto', self::STYLES, 'auto' ),
			'shape'           => self::enum( $b['shape'] ?? 'auto', self::SHAPES, 'auto' ),
			'maxColors'       => $max_colors,
			'textMode'        => self::enum( $b['textMode'] ?? 'auto', self::TEXT_MODES, 'auto' ),
			'context'         => array(
				'technique' => self::enum( $ctx['technique'] ?? 'embroidered', self::TECHNIQUES, 'embroidered' ),
				'sizeMm'    => isset( $ctx['sizeMm'] ) && is_numeric( $ctx['sizeMm'] ) ? max( 25, min( 300, (int) $ctx['sizeMm'] ) ) : 80,
				'fabric'    => preg_replace( '/[^a-z_]/', '', strtolower( (string) ( $ctx['fabric'] ?? 'black' ) ) ),
				'edge'      => preg_replace( '/[^a-z_]/', '', strtolower( (string) ( $ctx['edge'] ?? '' ) ) ),
			),
			'testHook'        => $hook,
		);
	}

	/**
	 * Rest/studio input → Prompt_Engine::brief() input (prompt team's Prompt_Adapter when present).
	 *
	 * @param array $in Validated input.
	 */
	public static function adapter_input( array $in ): array {
		if ( class_exists( __NAMESPACE__ . '\\Prompt_Adapter' ) && method_exists( __NAMESPACE__ . '\\Prompt_Adapter', 'input' ) ) {
			$out = Prompt_Adapter::input( $in, (array) ( $in['context'] ?? array() ) );
			if ( is_array( $out ) && isset( $out['description'] ) ) {
				return $out;
			}
		}
		$ctx = (array) ( $in['context'] ?? array() );
		$out = array(
			'description' => (string) $in['description'],
			'lang'        => (string) $in['lang'],
			'style'       => (string) $in['style'],
			'shape'       => (string) $in['shape'],
			'technique'   => (string) ( $ctx['technique'] ?? 'embroidered' ),
			'text'        => (string) $in['text'],
			'textMode'    => (string) $in['textMode'],
			'background'  => (string) ( $in['background'] ?? 'transparent' ),
			'fabric'      => (string) ( $ctx['fabric'] ?? 'black' ),
			'sizeMm'      => (int) ( $ctx['sizeMm'] ?? 80 ),
		);
		if ( null !== $in['maxColors'] ) {
			$out['maxColors'] = (int) $in['maxColors'];
		}
		if ( '' !== (string) ( $ctx['edge'] ?? '' ) ) {
			$out['edge'] = (string) $ctx['edge'];
		}
		return $out;
	}

	/* =====================================================================
	 * Refine
	 * =================================================================== */

	/**
	 * POST /ai/jobs/{id}/refine.
	 *
	 * @param string $owner Owner key.
	 * @param string $id    Parent job id.
	 * @param array  $body  Body {proposalIndex, instruction, forceAi, clientRequestId}.
	 * @param array  $ctx   Context.
	 * @return array{status:int, body:array}|WP_Error
	 */
	public static function refine( string $owner, string $id, array $body, array $ctx ) {
		$parent = self::get_for_owner( $owner, $id );
		if ( ! $parent ) {
			return Messages::error( 'bps_ai_not_found', 404 );
		}
		Messages::set_locale( (string) $parent['locale'] );
		if ( 'done' !== $parent['status'] ) {
			return Messages::error( 'bps_ai_not_ready', 409 );
		}
		$index = isset( $body['proposalIndex'] ) && is_numeric( $body['proposalIndex'] ) ? (int) $body['proposalIndex'] : -1;
		if ( null === self::proposal_row( $parent, $index ) ) {
			return Messages::error( 'bps_ai_bad_proposal', 400 );
		}
		$cfg   = Module::prompt_cfg();
		$max   = (int) ( $cfg['limits']['refineMaxChars'] ?? 200 );
		$instr = self::clean_text( $body['instruction'] ?? '' );
		$req   = self::client_req( $body['clientRequestId'] ?? '' );
		if ( '' === $instr || mb_strlen( $instr ) > $max || '' === $req ) {
			return Messages::error( 'bps_ai_invalid', 400, array( 'field' => '' === $req ? 'clientRequestId' : 'instruction' ) );
		}
		$store = Module::store();
		$same  = $store->job_find_req( $owner, $req );
		if ( $same ) {
			return array(
				'status' => in_array( $same['status'], self::OPEN, true ) ? 202 : 200,
				'body'   => self::response( $same, $owner ),
			);
		}
		$hook = '';
		if ( preg_match( self::TEST_MARKER, $instr, $m ) ) {
			$hook  = AI_Settings::test_hooks_allowed() ? strtolower( $m[1] ) : '';
			$instr = trim( (string) preg_replace( self::TEST_MARKER, '', $instr ) );
			$instr = '' === $instr ? 'maak de lijnen dikker' : $instr;
		}
		$brief   = self::base_brief( $parent );
		$history = self::json( $parent['history'] ?? null );
		$res     = Prompt_Engine::refine( $brief, $instr, $cfg, $history, ! empty( $body['forceAi'] ) );
		$clean   = (string) Prompt_Engine::sanitize( $instr, $cfg )['text'];

		if ( 'local' === ( $res['route'] ?? '' ) ) {
			return array(
				'status' => 200,
				'body'   => array(
					'route'   => 'local',
					'action'  => (string) ( $res['action'] ?? 'open_colors' ),
					'message' => (string) ( $res['message'] ?? '' ),
					'colors'  => isset( $res['colors'] ) ? self::colors_hex( (array) $res['colors'] ) : null,
					'text'    => isset( $res['text'] ) ? (string) $res['text'] : null,
					'shape'   => isset( $res['shape'] ) ? (string) $res['shape'] : null,
					'aiOffer' => array( 'cost' => self::cost( 'refine' ) ),
				),
			);
		}
		$job_id = self::new_id();
		$input  = array(
			'instruction'   => $clean,
			'proposalIndex' => $index,
			'forceAi'       => ! empty( $body['forceAi'] ),
			'lang'          => (string) $parent['locale'],
			'testHook'      => $hook,
		);
		if ( 'ai_edit' !== ( $res['route'] ?? '' ) ) {
			return self::blocked( $owner, $job_id, 'refine', $input, (string) ( $res['code'] ?? 'empty' ), (string) ( $res['message'] ?? '' ), $ctx, $req, $parent );
		}
		$mod = Moderation::check( $clean, $job_id );
		if ( $mod['flagged'] ) {
			return self::blocked( $owner, $job_id, 'refine', $input, $mod['code'], '', $ctx, $req, $parent );
		}
		$pre = self::preflight( $owner, $ctx );
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}
		$brief['_edit'] = array(
			'prompt' => (string) $res['prompt'],
			'params' => (array) $res['params'],
		);
		return self::admit_and_queue(
			$owner,
			$ctx,
			array(
				'id'           => $job_id,
				'kind'         => 'refine',
				'parent_id'    => (string) $parent['id'],
				'parent_index' => $index,
				'client_req'   => $req,
				'input'        => $input,
				'brief'        => $brief,
				'history'      => (array) ( $res['history'] ?? array() ),
				'n'            => (int) AI_Settings::clamp( 'refineN', AI_Settings::path( 'refineN', 2 ) ),
				'locale'       => (string) $parent['locale'],
			)
		);
	}

	/* =====================================================================
	 * Choose, cancel, poll
	 * =================================================================== */

	/**
	 * POST /ai/jobs/{id}/choose ("Gebruik dit ontwerp", free).
	 *
	 * @param string $owner Owner key.
	 * @param string $id    Job id.
	 * @param array  $body  {proposalIndex}.
	 * @return array{status:int, body:array}|WP_Error
	 */
	public static function choose( string $owner, string $id, array $body ) {
		$job = self::get_for_owner( $owner, $id );
		if ( ! $job ) {
			return Messages::error( 'bps_ai_not_found', 404 );
		}
		Messages::set_locale( (string) $job['locale'] );
		if ( 'done' !== $job['status'] ) {
			return Messages::error( 'bps_ai_not_ready', 409 );
		}
		$index = isset( $body['proposalIndex'] ) && is_numeric( $body['proposalIndex'] ) ? (int) $body['proposalIndex'] : -1;
		$row   = self::proposal_row( $job, $index );
		if ( null === $row ) {
			return Messages::error( 'bps_ai_bad_proposal', 400 );
		}
		$fields = array( 'chosen_index' => $index );
		if ( Identity::is_strong( Identity::resolve( $owner ) ) ) {
			$days                 = AI_Settings::retention( 'accountDesignsAfterLastOpen', 365 );
			$fields['keep_until'] = self::dt( $days * DAY_IN_SECONDS );
		}
		Module::store()->job_update( (string) $job['id'], $fields );
		$job = array_merge( $job, $fields );

		$svg_url = self::maybe_vectorize( $job, $index, $row );
		$brief   = self::base_brief( $job );
		$und     = self::understood( $brief, (string) $job['locale'] );
		$legal   = Module::legal();
		$ext     = 'image/svg+xml' === $row['mime'] ? 'svg' : 'png';
		do_action( 'bps_ai_design_chosen', $job, $index );
		return array(
			'status' => 200,
			'body'   => array(
				'imageUrl' => Image_Store::signed_url( (string) $job['id'], $index ),
				'mime'     => (string) $row['mime'],
				'fileName' => 'ai-ontwerp-' . substr( (string) $job['id'], 0, 6 ) . '-' . ( $index + 1 ) . '.' . $ext,
				'svgUrl'   => $svg_url,
				'ai'       => array(
					'jobId'        => (string) $job['id'],
					'index'        => $index,
					'provider'     => (string) $job['provider'],
					'model'        => (string) $job['model'],
					'label'        => (string) ( $legal['strings']['legal.ai.label'][ 'fr' === Messages::$locale ? 'fr' : 'nl' ] ?? 'Gemaakt met AI' ),
					'humanFinish'  => false,
					'legalVersion' => (string) ( $legal['version'] ?? '' ),
				),
				'hints'    => array(
					'shape'      => $und['shape'],
					'maxColors'  => $und['maxColors'],
					'colors'     => $und['colors'],
					'textMode'   => $und['textMode'],
					'text'       => $und['text'],
					'placement'  => $und['placement'],
					'background' => 'transparent',
				),
			),
		);
	}

	/**
	 * Optional vectorisation after choosing (Recraft, 15 s, free for the customer, cost logged). Failure → PNG only.
	 *
	 * @param array $job   Job.
	 * @param int   $index Index.
	 * @param array $row   Result row.
	 * @return string|null Signed SVG URL.
	 */
	private static function maybe_vectorize( array $job, int $index, array $row ): ?string {
		$result = self::json( $job['result'] ?? null );
		if ( ! empty( $result[ $index ]['vector'] ) ) {
			return Image_Store::signed_url( (string) $job['id'], $index, 'svg' );
		}
		if ( 'image/png' !== $row['mime'] ) {
			return null;
		}
		$vec = Providers\Provider_Registry::vectorizer();
		$abs = Image_Store::path( (string) $row['file'] );
		if ( ! $vec || '' === $abs ) {
			return null;
		}
		$start = microtime( true );
		try {
			$r           = $vec->vectorize( $abs );
			$r->provider = $vec->id();
			Cost_Log::add_result( $r, (string) $job['id'], 'vectorize', false );
			$saved = isset( $r->images[0] ) ? Image_Store::save( (string) $job['id'], $index, $r->images[0]['bytes'], $vec->id(), $r->model, 'svg' ) : null;
			if ( null === $saved ) {
				return null;
			}
			foreach ( $result as $k => $res_row ) {
				if ( (int) ( $res_row['index'] ?? -1 ) === $index ) {
					$result[ $k ]['vector'] = $saved['file'];
				}
			}
			Module::store()->job_update( (string) $job['id'], array( 'result' => wp_json_encode( $result ) ) );
			return Image_Store::signed_url( (string) $job['id'], $index, 'svg' );
		} catch ( Providers\Provider_Error $e ) {
			Module::log( 'provider', 'vectorize ' . $e->error_code() . ': ' . $e->getMessage() );
			Cost_Log::add(
				array(
					'job_id'      => (string) $job['id'],
					'provider'    => $vec->id(),
					'model'       => 'vectorize',
					'op'          => 'vectorize',
					'cost_usd'    => 0,
					'duration_ms' => (int) ( ( microtime( true ) - $start ) * 1000 ),
					'outcome'     => 'timeout' === $e->error_code() ? 'timeout' : 'error',
				)
			);
			return null;
		}
	}

	/**
	 * POST /ai/jobs/{id}/cancel. A cancelled job always gives its round back (also while it is running: the customer
	 * gets nothing for it, so it costs nothing — SPEC §1.1 rule 6).
	 *
	 * @param string $owner Owner key.
	 * @param string $id    Job id.
	 * @return array{status:int, body:array}|WP_Error
	 */
	public static function cancel( string $owner, string $id ) {
		$job = self::get_for_owner( $owner, $id );
		if ( ! $job ) {
			return Messages::error( 'bps_ai_not_found', 404 );
		}
		Messages::set_locale( (string) $job['locale'] );
		if ( in_array( $job['status'], self::OPEN, true ) ) {
			$n = Module::store()->job_update(
				(string) $job['id'],
				array(
					'status'      => 'cancelled',
					'error_code'  => 'cancelled',
					'finished_at' => self::dt(),
				),
				(string) $job['status']
			);
			if ( 1 === $n ) {
				self::refund( $job, 'job_cancelled' );
				Limits::release( (string) $job['id'] );
			}
			$job = Module::store()->job_get( (string) $job['id'] );
		}
		return array(
			'status' => 200,
			'body'   => self::response( (array) $job, $owner ),
		);
	}

	/**
	 * GET /ai/jobs/{id}: handles timeouts and the inline fallback (no Action Scheduler, or job waiting > 3 s).
	 *
	 * @param string $owner Owner key.
	 * @param string $id    Job id.
	 * @return array{status:int, body:array}|WP_Error
	 */
	public static function poll( string $owner, string $id ) {
		$job = self::get_for_owner( $owner, $id );
		if ( ! $job ) {
			return Messages::error( 'bps_ai_not_found', 404 );
		}
		Messages::set_locale( (string) $job['locale'] );
		if ( in_array( $job['status'], self::OPEN, true ) ) {
			Job_Runner::check_timeout( $job );
			$job = (array) Module::store()->job_get( (string) $job['id'] );
			if ( 'queued' === $job['status'] && Job_Runner::should_run_inline( $job ) ) {
				Job_Runner::run( (string) $job['id'] );
				$job = (array) Module::store()->job_get( (string) $job['id'] );
			}
		}
		return array(
			'status' => 200,
			'body'   => self::response( $job, $owner ),
		);
	}

	/**
	 * GET /ai/history: last 20 jobs of the last 30 days (done ones with proposals, blocked without text).
	 *
	 * @param string $owner Owner key.
	 */
	public static function history( string $owner ): array {
		$days = AI_Settings::retention( 'guestPromptsAndProposals', 30 );
		$rows = '' === $owner ? array() : Module::store()->job_list( $owner, self::dt( -$days * DAY_IN_SECONDS ), 20 );
		$out  = array();
		foreach ( $rows as $job ) {
			if ( 'done' !== $job['status'] ) {
				continue;
			}
			$r     = self::response( $job, $owner, false );
			$out[] = array(
				'jobId'       => $r['jobId'],
				'kind'        => $r['kind'],
				'status'      => $r['status'],
				'createdAt'   => self::iso( (string) $job['created_at'] ),
				'chosenIndex' => null === $job['chosen_index'] ? null : (int) $job['chosen_index'],
				'proposals'   => $r['proposals'],
				'understood'  => $r['understood'],
			);
		}
		return array( 'jobs' => $out );
	}

	/**
	 * GET /ai/status payload (StatusResponse). Grants the free anonymous round on the first call.
	 *
	 * @param string $owner Owner key.
	 * @param array  $ctx   Context.
	 */
	public static function status( string $owner, array $ctx ): array {
		$gw = Credits_Gateway::instance();
		if ( 'anon' === Identity::kind( $owner ) ) {
			$gw->grant_free_anon( $owner, (string) ( $ctx['ip_hash'] ?? '' ) );
		}
		$provider = Providers\Provider_Registry::primary();
		$budget   = Limits::budget();
		$paused   = Limits::paused_until( $owner, (string) ( $ctx['ip_hash'] ?? '' ) );
		$open     = Module::store()->job_open( $owner );
		$legal    = Module::legal();
		$lim      = (array) ( Module::prompt_cfg()['limits'] ?? array() );
		$credit   = (array) AI_Settings::path( 'orderCredit', array() );
		$test     = 'mock' === $provider->id();
		$email    = Identity::is_strong( $owner );
		if ( ! $email ) {
			$had   = $gw->optional( 'had_free', array( $owner, 'free_email' ) );
			$email = true === $had;
		}
		$status = array(
			'mode'          => AI_Settings::mode(),
			'providerLabel' => $provider->label(),
			'testMode'      => $test,
			'testNotice'    => $test ? Messages::text( 'test_mode' ) : null,
			'n'             => (int) AI_Settings::clamp( 'n', AI_Settings::path( 'n', 3 ) ),
			'refineN'       => (int) AI_Settings::clamp( 'refineN', AI_Settings::path( 'refineN', 2 ) ),
			'costs'         => array(
				'generate' => self::cost( 'generate' ),
				'refine'   => self::cost( 'refine' ),
				'choose'   => 0,
			),
			'balance'       => $gw->balance( $owner ),
			'freePaused'    => (bool) $budget['freePaused'],
			'emailGate'     => array(
				'offer' => (int) AI_Settings::path( 'free.withEmail', 2 ),
				'done'  => $email,
			),
			'packs'         => self::packs(),
			'orderCredit'   => array(
				'percent'        => (int) ( $credit['percent'] ?? 100 ),
				'minArticleIncl' => (float) ( $credit['minArticleIncl'] ?? 50 ),
				'validMonths'    => (int) ( $credit['validMonths'] ?? 12 ),
			),
			'packsBuyable'  => 'live' === AI_Settings::mode() && 'commerce' === $gw->kind() && function_exists( 'WC' ),
			'limits'        => array(
				'descriptionMaxChars' => (int) ( $lim['descriptionMaxChars'] ?? 400 ),
				'refineMaxChars'      => (int) ( $lim['refineMaxChars'] ?? 200 ),
				'textMaxChars'        => 60,
			),
			'pausedUntil'   => $paused > 0 ? gmdate( 'c', $paused ) : null,
			'legalVersion'  => (string) ( $legal['version'] ?? '' ),
			'openJob'       => $open ? (string) $open['id'] : null,
		);
		return (array) apply_filters( 'bps_ai_status', $status, $owner );
	}

	/* =====================================================================
	 * Shared steps
	 * =================================================================== */

	/**
	 * Abuse pause and "one open job per owner".
	 *
	 * @param string $owner Owner key.
	 * @param array  $ctx   Context.
	 * @return true|WP_Error
	 */
	private static function preflight( string $owner, array $ctx ) {
		$until = Limits::paused_until( $owner, (string) ( $ctx['ip_hash'] ?? '' ) );
		if ( $until > 0 ) {
			return Messages::error( 'bps_ai_paused', 423, array( 'pausedUntil' => gmdate( 'c', $until ) ) );
		}
		$open = Module::store()->job_open( $owner );
		if ( $open ) {
			Job_Runner::check_timeout( $open );
			$open = Module::store()->job_open( $owner );
		}
		if ( $open ) {
			return Messages::error( 'bps_ai_busy', 409, array( 'jobId' => (string) $open['id'] ) );
		}
		return true;
	}

	/**
	 * Budget/limits, reserve rounds, insert the job, enqueue it.
	 *
	 * @param string $owner Owner key.
	 * @param array  $ctx   Context.
	 * @param array  $j     Job draft.
	 * @return array{status:int, body:array}|WP_Error
	 */
	private static function admit_and_queue( string $owner, array $ctx, array $j ) {
		$gw       = Credits_Gateway::instance();
		$kind     = (string) $j['kind'];
		$rounds   = self::cost( $kind );
		$balance  = $gw->balance( $owner );
		$paid     = $balance['free'] < $rounds;
		$provider = Providers\Provider_Registry::primary();
		$params   = array(
			'n'       => (int) $j['n'],
			'quality' => Providers\Openai_Provider::quality(),
		);
		$est_eur  = $provider->estimate_usd( 'refine' === $kind ? 'edit' : 'generate', $params ) * (float) AI_Settings::path( 'usdToEur', 0.93 );
		$ip       = (string) ( $ctx['ip_hash'] ?? '' );

		$ok = Limits::admit( $owner, $ip, $paid, (string) $j['id'], $est_eur );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$lot = $rounds > 0 ? $gw->reserve( $owner, $rounds, (string) $j['id'], $kind ) : 0;
		if ( null === $lot ) {
			Limits::undo( $owner, (string) $j['id'] );
			$fresh = $gw->balance( $owner );
			if ( $fresh['total'] <= 0 && $fresh['paidLocked'] > 0 ) {
				return Messages::error( 'bps_ai_consent_needed', 402, array( 'balance' => $fresh ) );
			}
			return Messages::error(
				'bps_ai_no_rounds',
				402,
				array(
					'balance'   => $fresh,
					'emailGate' => array(
						'offer' => (int) AI_Settings::path( 'free.withEmail', 2 ),
						'done'  => Identity::is_strong( $owner ),
					),
					'packs'     => self::packs(),
				)
			);
		}
		$days               = AI_Settings::retention( 'guestPromptsAndProposals', 30 );
		$j['input']['paid'] = $paid;
		$row                = array(
			'id'           => (string) $j['id'],
			'owner_key'    => $owner,
			'kind'         => $kind,
			'parent_id'    => $j['parent_id'] ?? null,
			'parent_index' => $j['parent_index'] ?? null,
			'status'       => 'queued',
			'client_req'   => (string) $j['client_req'],
			'input'        => wp_json_encode( $j['input'] ),
			'brief'        => wp_json_encode( $j['brief'] ),
			'history'      => wp_json_encode( array_values( (array) $j['history'] ) ),
			'provider'     => $provider->id(),
			'model'        => Providers\Provider_Registry::model( $provider ),
			'n'            => (int) $j['n'],
			'lot_id'       => $lot > 0 ? (int) $lot : null,
			'rounds'       => $rounds,
			'result'       => null,
			'chosen_index' => null,
			'error_code'   => null,
			'attempts'     => 0,
			'locale'       => (string) $j['locale'],
			'created_at'   => self::dt(),
			'started_at'   => null,
			'finished_at'  => null,
			'keep_until'   => self::dt( $days * DAY_IN_SECONDS ),
		);
		if ( ! Module::store()->job_insert( $row ) ) {
			// Lost a race on (owner, clientRequestId) or the database failed: give everything back.
			if ( $rounds > 0 ) {
				$gw->refund( (string) $j['id'], 'job_failed' );
			}
			Limits::undo( $owner, (string) $j['id'] );
			$same = Module::store()->job_find_req( $owner, (string) $j['client_req'] );
			if ( $same ) {
				return array(
					'status' => 202,
					'body'   => self::response( $same, $owner ),
				);
			}
			return Messages::error( 'bps_ai_budget', 503, array( 'freeOnly' => false ) );
		}
		Job_Runner::enqueue( (string) $j['id'] );
		do_action( 'bps_ai_job_created', $row );
		return array(
			'status' => 202,
			'body'   => self::response( $row, $owner ),
		);
	}

	/**
	 * Store a refused job (no round), count the refusal, answer 200 blocked.
	 *
	 * @param string     $owner   Owner key.
	 * @param string     $job_id  Job id.
	 * @param string     $kind    generate|refine.
	 * @param array      $input   Stored input.
	 * @param string     $code    Refusal code.
	 * @param string     $message Message from the prompt engine ('' = derive).
	 * @param array      $ctx     Context.
	 * @param string     $req     Client request id.
	 * @param array|null $parent  Parent job (refine).
	 * @return array{status:int, body:array}
	 */
	private static function blocked( string $owner, string $job_id, string $kind, array $input, string $code, string $message, array $ctx, string $req, ?array $parent = null ): array {
		$code = '' === $code ? 'moderation' : substr( preg_replace( '/[^a-z_]/', '', strtolower( $code ) ), 0, 32 );
		Limits::record_refusal( $owner, (string) ( $ctx['ip_hash'] ?? '' ), $code );
		$days = AI_Settings::retention( 'guestPromptsAndProposals', 30 );
		$row  = array(
			'id'           => $job_id,
			'owner_key'    => $owner,
			'kind'         => $kind,
			'parent_id'    => $parent ? (string) $parent['id'] : null,
			'parent_index' => $parent ? (int) ( $input['proposalIndex'] ?? 0 ) : null,
			'status'       => 'blocked',
			'client_req'   => $req,
			'input'        => wp_json_encode( $input ),
			'brief'        => null,
			'history'      => null,
			'provider'     => null,
			'model'        => null,
			'n'            => 0,
			'lot_id'       => null,
			'rounds'       => 0,
			'result'       => null,
			'chosen_index' => null,
			'error_code'   => $code,
			'attempts'     => 0,
			'locale'       => (string) ( $input['lang'] ?? Messages::$locale ),
			'created_at'   => self::dt(),
			'started_at'   => null,
			'finished_at'  => self::dt(),
			'keep_until'   => self::dt( $days * DAY_IN_SECONDS ),
		);
		Module::store()->job_insert( $row );
		$body = self::response( $row, $owner );
		if ( '' !== $message ) {
			$body['error']['message'] = $message;
			$body['message']          = $message;
		}
		return array(
			'status' => 200,
			'body'   => $body,
		);
	}

	/**
	 * Give the rounds of a job back (idempotent) and record that on the job.
	 *
	 * @param array  $job    Job row.
	 * @param string $reason job_failed|job_blocked|job_cancelled.
	 */
	public static function refund( array $job, string $reason ): void {
		if ( (int) ( $job['rounds'] ?? 0 ) <= 0 ) {
			return;
		}
		Credits_Gateway::instance()->refund( (string) $job['id'], $reason );
		Module::store()->job_update( (string) $job['id'], array( 'rounds' => 0 ) );
	}

	/* =====================================================================
	 * Public shapes
	 * =================================================================== */

	/**
	 * JobResponse (SPEC §4.3).
	 *
	 * @param array  $job         Job row.
	 * @param string $owner       Owner key.
	 * @param bool   $with_balance Include the balance (one Credits call).
	 */
	public static function response( array $job, string $owner, bool $with_balance = true ): array {
		$status = (string) $job['status'];
		$lang   = 'fr' === ( $job['locale'] ?? 'nl' ) ? 'fr' : 'nl';
		$prev   = Messages::$locale;
		Messages::set_locale( $lang );
		$brief  = self::base_brief( $job );
		$und    = empty( $brief ) ? null : self::understood( $brief, $lang );

		$proposals = array();
		if ( 'done' === $status ) {
			foreach ( self::json( $job['result'] ?? null ) as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['index'] ) ) {
					continue;
				}
				$i           = (int) $row['index'];
				$proposals[] = array(
					'index' => $i,
					'url'   => Image_Store::signed_url( (string) $job['id'], $i ),
					'mime'  => (string) ( $row['mime'] ?? 'image/png' ),
					'w'     => (int) ( $row['w'] ?? 0 ),
					'h'     => (int) ( $row['h'] ?? 0 ),
					'alt'   => sprintf( 'fr' === $lang ? 'Proposition %d : %s' : 'Voorstel %d: %s', $i + 1, $und ? $und['summary'] : '' ),
				);
			}
		}
		$error = null;
		if ( in_array( $status, array( 'failed', 'blocked', 'cancelled' ), true ) ) {
			$code  = (string) ( $job['error_code'] ?? '' );
			$error = array(
				'code'    => '' === $code ? 'provider_error' : $code,
				'message' => self::error_message( $status, $code, $lang, (int) ( $job['rounds'] ?? 0 ) ),
			);
		}
		$poll = 0;
		if ( 'queued' === $status ) {
			$poll = Module::store()->job_count_status( 'running' ) >= (int) AI_Settings::limit( 'globalConcurrent' ) ? 3000 : 1500;
		} elseif ( 'running' === $status ) {
			$started = strtotime( (string) ( $job['started_at'] ?? '' ) . ' UTC' );
			$poll    = $started && self::now() - $started > 10 ? 3000 : 1500;
		}
		$out = array(
			'jobId'      => (string) $job['id'],
			'kind'       => (string) $job['kind'],
			'status'     => $status,
			'blocked'    => 'blocked' === $status,
			'pollAfterMs' => $poll,
			'proposals'  => $proposals,
			'understood' => $und,
			'charged'    => (int) ( $job['rounds'] ?? 0 ),
			'error'      => $error,
		);
		if ( 'blocked' === $status ) {
			$out['code']    = $error['code'];
			$out['message'] = $error['message'];
		}
		if ( $with_balance ) {
			$b              = Credits_Gateway::instance()->balance( $owner );
			$out['balance'] = array(
				'free'       => $b['free'],
				'paid'       => $b['paid'],
				'paidLocked' => $b['paidLocked'],
				'total'      => $b['total'],
			);
		}
		Messages::$locale = $prev;
		return $out;
	}

	/**
	 * Customer message for a failed/blocked/cancelled job.
	 *
	 * @param string $status  Status.
	 * @param string $code    Error code.
	 * @param string $lang    nl|fr.
	 * @param int    $charged Rounds still charged.
	 */
	private static function error_message( string $status, string $code, string $lang, int $charged ): string {
		if ( 'cancelled' === $status ) {
			return Messages::text( $charged > 0 ? 'cancelled' : 'cancelled_refunded' );
		}
		if ( 'blocked' === $status ) {
			if ( in_array( $code, array( 'provider_policy', 'moderation' ), true ) ) {
				return Messages::text( $code );
			}
			$cfg = Module::prompt_cfg();
			if ( isset( $cfg['safety']['messages'][ $code ] ) ) {
				return Prompt_Engine::message( $code, $lang, $cfg );
			}
			return Messages::text( 'moderation' );
		}
		return Messages::text( in_array( $code, array( 'timeout', 'provider_unavailable', 'storage' ), true ) ? $code : 'provider_error' );
	}

	/**
	 * "Zo begrepen we je" (Prompt_Adapter of the prompt team when present; never the prompt).
	 *
	 * @param array  $brief Brief.
	 * @param string $lang  nl|fr.
	 * @return array{summary:string, style:string, shape:string, colors:string[], maxColors:int, textMode:string, text:string, placement:string, warnings:string[]}
	 */
	public static function understood( array $brief, string $lang ): array {
		if ( class_exists( __NAMESPACE__ . '\\Prompt_Adapter' ) && method_exists( __NAMESPACE__ . '\\Prompt_Adapter', 'understood' ) ) {
			$u = Prompt_Adapter::understood( $brief, $lang );
			if ( is_array( $u ) && isset( $u['summary'] ) ) {
				return $u;
			}
		}
		$cfg   = Module::prompt_cfg();
		$lang  = 'fr' === $lang ? 'fr' : 'nl';
		$style = (string) ( $brief['style'] ?? 'badge' );
		$shape = (string) ( $brief['shape'] ?? 'circle' );
		$parts = array();
		$what  = trim( (string) ( $brief['original'] ?? '' ) );
		if ( '' === $what ) {
			$what = trim( (string) ( $brief['subjectEn'] ?? '' ) );
		}
		if ( '' !== $what ) {
			$parts[] = mb_strlen( $what ) > 60 ? rtrim( mb_substr( $what, 0, 57 ) ) . '…' : $what;
		}
		$parts[] = self::SHAPE_LABELS[ $shape ][ $lang ] ?? $shape;
		$max     = (int) ( $brief['maxColors'] ?? 0 );
		if ( $max > 0 ) {
			$parts[] = 'fr' === $lang ? sprintf( 'max. %d couleurs', $max ) : sprintf( 'max. %d kleuren', $max );
		}
		$label   = (string) ( $cfg['styles'][ $style ]['label'][ $lang ] ?? $style );
		$parts[] = ( 'fr' === $lang ? 'style ' : 'stijl ' ) . mb_strtolower( $label );
		$text    = (string) ( $brief['text'] ?? '' );
		return array(
			'summary'   => implode( ' · ', $parts ),
			'style'     => $style,
			'shape'     => $shape,
			'colors'    => self::colors_hex( (array) ( $brief['colors'] ?? array() ) ),
			'maxColors' => $max,
			'textMode'  => (string) ( $brief['textMode'] ?? 'none' ),
			'text'      => $text,
			'placement' => (string) ( $brief['placement'] ?? 'below' ),
			'warnings'  => array_values( array_map( 'strval', (array) ( $brief['warnings'] ?? array() ) ) ),
		);
	}

	/**
	 * English colour names of a brief → hex (mock colour table; unknown names dropped).
	 *
	 * @param array $names Names or hex values.
	 * @return string[]
	 */
	public static function colors_hex( array $names ): array {
		$map = (array) ( Providers\Mock_Provider::cfg()['colorHex'] ?? array() );
		$out = array();
		foreach ( $names as $n ) {
			$n = strtolower( trim( (string) $n ) );
			$h = preg_match( '/^#[0-9a-f]{6}$/', $n ) ? $n : ( isset( $map[ $n ] ) ? strtolower( (string) $map[ $n ] ) : '' );
			if ( '' !== $h && ! in_array( $h, $out, true ) ) {
				$out[] = $h;
			}
		}
		return $out;
	}

	/**
	 * Pack offers for StatusResponse / 402 (prices from ai-pricing.json, excl. VAT → incl., half-up).
	 */
	public static function packs(): array {
		$vat  = (float) AI_Settings::path( 'vatRate', 0.21 );
		$lang = 'fr' === Messages::$locale ? 'fr' : 'nl';
		$out  = array();
		foreach ( (array) AI_Settings::path( 'packs', array() ) as $p ) {
			if ( ! is_array( $p ) || empty( $p['id'] ) || ( isset( $p['enabled'] ) && ! $p['enabled'] ) ) {
				continue;
			}
			$incl  = round( (float) ( $p['priceExcl'] ?? 0 ) * ( 1 + $vat ) + 1e-9, 2 );
			$out[] = array(
				'id'        => (string) $p['id'],
				'rounds'    => (int) ( $p['rounds'] ?? 0 ),
				'priceIncl' => $incl,
				'priceHtml' => 'fr' === $lang ? number_format( $incl, 2, ',', ' ' ) . ' €' : '€ ' . number_format( $incl, 2, ',', '.' ),
			);
		}
		return (array) apply_filters( 'bps_ai_packs', $out );
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	/**
	 * Job of this owner (following merges) or null (never reveals other owners' jobs: 404).
	 *
	 * @param string $owner Owner key.
	 * @param string $id    Job id.
	 */
	public static function get_for_owner( string $owner, string $id ): ?array {
		$id = strtoupper( $id );
		if ( '' === $owner || ! self::valid_id( $id ) ) {
			return null;
		}
		$job = Module::store()->job_get( $id );
		if ( ! $job ) {
			return null;
		}
		if ( hash_equals( (string) $job['owner_key'], $owner ) || Identity::resolve( (string) $job['owner_key'] ) === Identity::resolve( $owner ) ) {
			return $job;
		}
		return null;
	}

	/**
	 * Result row of a proposal, or null.
	 *
	 * @param array $job   Job.
	 * @param int   $index Index.
	 */
	public static function proposal_row( array $job, int $index ): ?array {
		if ( $index < 0 ) {
			return null;
		}
		foreach ( self::json( $job['result'] ?? null ) as $row ) {
			if ( is_array( $row ) && (int) ( $row['index'] ?? -1 ) === $index && ! empty( $row['file'] ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Brief of a job without the refine edit block.
	 *
	 * @param array $job Job.
	 */
	public static function base_brief( array $job ): array {
		$b = self::json( $job['brief'] ?? null );
		unset( $b['_edit'] );
		return $b;
	}

	/**
	 * Decode a JSON column (array or empty array).
	 *
	 * @param mixed $v Value.
	 */
	public static function json( $v ): array {
		if ( is_array( $v ) ) {
			return $v;
		}
		$d = is_string( $v ) && '' !== $v ? json_decode( $v, true ) : null;
		return is_array( $d ) ? $d : array();
	}

	/**
	 * Rounds an operation costs.
	 *
	 * @param string $op generate|refine.
	 */
	public static function cost( string $op ): int {
		return max( 0, min( 5, (int) AI_Settings::path( 'costs.' . $op, 1 ) ) );
	}

	/**
	 * Input as stored on the job (cleaned description only).
	 *
	 * @param array  $in    Validated input.
	 * @param string $clean Cleaned description.
	 */
	private static function stored_input( array $in, string $clean ): array {
		$out                = $in;
		$out['description'] = $clean;
		unset( $out['clientRequestId'] );
		if ( '' === $out['testHook'] ) {
			unset( $out['testHook'] );
		}
		return $out;
	}

	/**
	 * Single-line text: strip tags/control chars, collapse whitespace.
	 *
	 * @param mixed $v Value.
	 */
	private static function clean_text( $v ): string {
		if ( ! is_string( $v ) && ! is_numeric( $v ) ) {
			return '';
		}
		$v = wp_strip_all_tags( (string) $v );
		$v = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $v );
		return trim( (string) preg_replace( '/\s+/u', ' ', $v ) );
	}

	/**
	 * Client request id (uuid-like, 8–36 chars) or ''.
	 *
	 * @param mixed $v Value.
	 */
	private static function client_req( $v ): string {
		$v = is_string( $v ) ? trim( $v ) : '';
		return 1 === preg_match( '/^[A-Za-z0-9-]{8,36}$/', $v ) ? strtolower( $v ) : '';
	}

	/**
	 * Enum value or default.
	 *
	 * @param mixed    $v       Value.
	 * @param string[] $allowed Allowed.
	 * @param string   $default Default.
	 */
	private static function enum( $v, array $allowed, string $default ): string {
		$v = is_string( $v ) ? strtolower( trim( $v ) ) : '';
		return in_array( $v, $allowed, true ) ? $v : $default;
	}

	/**
	 * UTC datetime → ISO 8601.
	 *
	 * @param string $dt Datetime.
	 */
	private static function iso( string $dt ): string {
		$t = strtotime( $dt . ' UTC' );
		return $t ? gmdate( 'c', $t ) : '';
	}
}
