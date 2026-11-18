<?php
/**
 * OpenAI image provider (gpt-image-2, configurable gpt-image-2.5-flare) + moderation API.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI\Providers;

use BorduurPatchStudio\AI\AI_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §6.2 and docs/ai/01-apis.md.
 *   POST /v1/images/generations (JSON) and /v1/images/edits (multipart), b64_json answers.
 *   moderation "auto", no `user` field unless a safety id is passed (ai-legal defaults.sendSafetyIdentifier),
 *   no `input_fidelity` (400 on gpt-image-2). Cost from `usage` (token prices in ai-providers.json), else estimate.
 */
final class Openai_Provider extends Http_Provider {

	public function id(): string {
		return 'openai';
	}

	public function capabilities(): array {
		return array(
			'transparent_bg' => true,
			'vector_output'  => false,
			'edit'           => true,
			'edit_mask'      => true,
			'text_quality'   => 4,
			'max_n'          => 4,
		);
	}

	/**
	 * Model from the request, else settings; only ids from the allowed list.
	 *
	 * @param string $model Requested model.
	 */
	private function model( string $model ): string {
		$cfg     = $this->cfg();
		$allowed = (array) ( $cfg['models'] ?? array( 'gpt-image-2' ) );
		if ( '' !== $model && in_array( $model, $allowed, true ) ) {
			return $model;
		}
		$m = (string) ( $cfg['model'] ?? 'gpt-image-2' );
		return in_array( $m, $allowed, true ) ? $m : (string) reset( $allowed );
	}

	/**
	 * Request body shared by generations and edits.
	 *
	 * @param array $req Request.
	 */
	private function base_body( array $req ): array {
		$cfg    = $this->cfg();
		$params = (array) ( $req['params'] ?? array() );
		$q      = (string) ( $params['quality'] ?? $cfg['quality'] ?? 'medium' );
		$body   = array(
			'model'         => $this->model( (string) ( $req['model'] ?? '' ) ),
			'prompt'        => (string) $req['prompt'],
			'n'             => max( 1, min( 4, (int) ( $params['n'] ?? 1 ) ) ),
			'size'          => in_array( $params['size'] ?? '', array( '1024x1024', '1536x1024', '1024x1536', 'auto' ), true ) ? $params['size'] : (string) ( $cfg['size'] ?? '1024x1024' ),
			'quality'       => in_array( $q, array( 'low', 'medium', 'high', 'auto' ), true ) ? $q : 'medium',
			'background'    => in_array( $params['background'] ?? '', array( 'transparent', 'opaque', 'auto' ), true ) ? $params['background'] : (string) ( $cfg['background'] ?? 'transparent' ),
			'output_format' => 'png',
		);
		if ( ! empty( $req['safety_id'] ) && is_string( $req['safety_id'] ) ) {
			$body['user'] = substr( $req['safety_id'], 0, 64 );
		}
		return $body;
	}

	public function generate( array $req ): Provider_Result {
		$body               = $this->base_body( $req );
		$body['moderation'] = 'low' === ( $this->cfg()['moderation'] ?? 'auto' ) ? 'low' : 'auto';
		list( $json, $ms )  = $this->post_json( '/images/generations', $body );
		return $this->result( $json, $ms, $body );
	}

	public function edit( array $req ): Provider_Result {
		$path = (string) ( $req['image_path'] ?? '' );
		if ( '' === $path || ! is_readable( $path ) ) {
			throw new Provider_Error( 'bad_request', 'openai: edit source missing.' );
		}
		$body  = $this->base_body( $req );
		$files = array( 'image[]' => array( $path, 'image/png', 'design.png' ) );
		if ( ! empty( $req['mask_path'] ) && is_readable( (string) $req['mask_path'] ) ) {
			$files['mask'] = array( (string) $req['mask_path'], 'image/png', 'mask.png' );
		}
		list( $json, $ms ) = $this->post_multipart( '/images/edits', $body, $files );
		return $this->result( $json, $ms, $body );
	}

	/**
	 * Moderation API (free). Text is the cleaned description, never personal data.
	 *
	 * @param string $text  Text.
	 * @param string $model Model.
	 * @return array{flagged:bool, categories:array}
	 * @throws Provider_Error On failure.
	 */
	public function moderate( string $text, string $model = 'omni-moderation-latest' ): array {
		list( $json ) = $this->post_json(
			'/moderations',
			array(
				'model' => '' !== $model ? $model : 'omni-moderation-latest',
				'input' => mb_substr( $text, 0, 2000 ),
			),
			10
		);
		$r = $json['results'][0] ?? array();
		return array(
			'flagged'    => ! empty( $r['flagged'] ),
			'categories' => isset( $r['categories'] ) && is_array( $r['categories'] ) ? $r['categories'] : array(),
		);
	}

	/**
	 * Cheap connection test: model metadata (free, no image).
	 *
	 * @return array{ok:bool, model:string, ms:int}
	 * @throws Provider_Error On failure.
	 */
	public function test_connection(): array {
		$model             = $this->model( '' );
		list( $json, $ms ) = $this->get_json( '/models/' . rawurlencode( $model ), 15 );
		return array(
			'ok'    => isset( $json['id'] ),
			'model' => $model,
			'ms'    => $ms,
		);
	}

	public function estimate_usd( string $op, array $params ): float {
		$pricing = (array) ( $this->cfg()['pricing'] ?? array() );
		$per     = 'low' === ( $params['quality'] ?? '' ) ? (float) ( $pricing['estUsdPerImageLow'] ?? 0.01 ) : (float) ( $pricing['estUsdPerImage'] ?? 0.05 );
		$n       = max( 1, (int) ( $params['n'] ?? 1 ) );
		return round( $per * $n * ( 'edit' === $op ? 1.3 : 1.0 ), 4 );
	}

	/**
	 * Build a result from an images answer.
	 *
	 * @param array $json Decoded answer.
	 * @param int   $ms   Duration.
	 * @param array $body Request body (for estimates).
	 * @throws Provider_Error When no image came back.
	 */
	private function result( array $json, int $ms, array $body ): Provider_Result {
		$images = array();
		foreach ( (array) ( $json['data'] ?? array() ) as $item ) {
			$b64 = is_array( $item ) ? (string) ( $item['b64_json'] ?? '' ) : '';
			if ( '' === $b64 ) {
				continue;
			}
			$bytes = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $bytes || 'image/png' !== Provider_Result::sniff_mime( $bytes ) ) {
				continue;
			}
			list( $w, $h ) = Provider_Result::png_size( $bytes );
			$images[]      = array(
				'bytes' => $bytes,
				'mime'  => 'image/png',
				'w'     => $w,
				'h'     => $h,
			);
		}
		if ( empty( $images ) ) {
			throw new Provider_Error( 'unknown', 'openai: answer without images.' );
		}
		$usage   = isset( $json['usage'] ) && is_array( $json['usage'] ) ? $json['usage'] : array();
		$in      = isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : null;
		$out     = isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : null;
		$details = isset( $usage['input_tokens_details'] ) && is_array( $usage['input_tokens_details'] ) ? $usage['input_tokens_details'] : array();
		$prices  = (array) ( $this->cfg()['pricing']['usdPerMTokens'] ?? array() );
		if ( null !== $out ) {
			$text_in  = isset( $details['text_tokens'] ) ? (int) $details['text_tokens'] : (int) $in;
			$image_in = isset( $details['image_tokens'] ) ? (int) $details['image_tokens'] : 0;
			$cost     = ( $text_in * (float) ( $prices['textIn'] ?? 5 ) + $image_in * (float) ( $prices['imageIn'] ?? 8 ) + $out * (float) ( $prices['imageOut'] ?? 30 ) ) / 1000000;
			$est      = false;
		} else {
			$cost = $this->estimate_usd( 'generate', array( 'n' => count( $images ), 'quality' => $body['quality'] ?? 'medium' ) );
			$est  = true;
		}
		return new Provider_Result(
			$images,
			array(
				'tokens_in'  => $in,
				'tokens_out' => $out,
				'images'     => count( $images ),
			),
			$cost,
			$est,
			$ms,
			(string) $body['model']
		);
	}

	/**
	 * Configured quality (admin "laag/medium").
	 */
	public static function quality(): string {
		$q = (string) AI_Settings::path( 'providers.openai.quality', 'medium' );
		return in_array( $q, array( 'low', 'medium' ), true ) ? $q : 'medium';
	}
}
