<?php
/**
 * Moderation layers 1 (local filter, via Prompt_Engine) and 2a (OpenAI moderation API on the cleaned text).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

use BorduurPatchStudio\AI\Providers\Openai_Provider;
use BorduurPatchStudio\AI\Providers\Provider_Error;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §8. Layer 1 runs inside Prompt_Engine::brief()/refine() (the prompt builder owns its lists);
 * this class adds the free OpenAI moderation call on the already cleaned text (never names/e-mails: those were removed
 * by Prompt_Engine::sanitize). A moderation outage never blocks a customer: the provider's own filter (layer 2b) and
 * the human check before the proof (layer 3) remain.
 */
final class Moderation {

	/** @var callable|null Test override: fn(string $text): array. */
	public static $override = null;

	/**
	 * Moderate cleaned text.
	 *
	 * @param string $text   Cleaned description (+ patch text).
	 * @param string $job_id Job id for the cost log ('' if none).
	 * @return array{flagged:bool, code:string, checked:bool}
	 */
	public static function check( string $text, string $job_id = '' ): array {
		$ok = array(
			'flagged' => false,
			'code'    => '',
			'checked' => false,
		);
		$text = trim( $text );
		if ( '' === $text || ! self::enabled() ) {
			return $ok;
		}
		if ( null !== self::$override ) {
			$res = call_user_func( self::$override, $text );
			return is_array( $res ) ? array_merge( $ok, $res, array( 'checked' => true ) ) : $ok;
		}
		$cfg   = (array) AI_Settings::path( 'moderation', array() );
		$start = microtime( true );
		try {
			$provider = new Openai_Provider();
			$result   = $provider->moderate( $text, (string) ( $cfg['model'] ?? 'omni-moderation-latest' ) );
		} catch ( Provider_Error $e ) {
			Module::log( 'moderation', $e->error_code() . ': ' . $e->getMessage() );
			Cost_Log::add(
				array(
					'job_id'      => $job_id,
					'provider'    => 'openai',
					'model'       => (string) ( $cfg['model'] ?? '' ),
					'op'          => 'moderate',
					'cost_usd'    => 0,
					'duration_ms' => (int) ( ( microtime( true ) - $start ) * 1000 ),
					'outcome'     => 'timeout' === $e->error_code() ? 'timeout' : 'error',
				)
			);
			return $ok;
		}
		Cost_Log::add(
			array(
				'job_id'      => $job_id,
				'provider'    => 'openai',
				'model'       => (string) ( $cfg['model'] ?? '' ),
				'op'          => 'moderate',
				'cost_usd'    => 0,
				'duration_ms' => (int) ( ( microtime( true ) - $start ) * 1000 ),
				'outcome'     => $result['flagged'] ? 'policy' : 'ok',
			)
		);
		if ( ! $result['flagged'] ) {
			return array_merge( $ok, array( 'checked' => true ) );
		}
		return array(
			'flagged' => true,
			'code'    => self::map_categories( (array) $result['categories'] ),
			'checked' => true,
		);
	}

	/**
	 * Moderation is on and possible (enabled in admin, live mode, OpenAI key present).
	 */
	public static function enabled(): bool {
		if ( null !== self::$override ) {
			return true;
		}
		$cfg = (array) AI_Settings::path( 'moderation', array() );
		return ! empty( $cfg['enabled'] ) && 'live' === AI_Settings::mode() && 'openai' === ( $cfg['provider'] ?? 'openai' ) && Secrets::has( 'openai' );
	}

	/**
	 * First flagged category → our refusal code (ai-prompts.json safety.messages keys).
	 *
	 * @param array $categories category => bool.
	 */
	public static function map_categories( array $categories ): string {
		$map = (array) AI_Settings::path( 'moderation.categoryMap', array() );
		foreach ( $categories as $cat => $flag ) {
			if ( $flag && isset( $map[ $cat ] ) && is_string( $map[ $cat ] ) ) {
				return $map[ $cat ];
			}
		}
		return 'moderation';
	}
}
