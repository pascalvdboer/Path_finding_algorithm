<?php
/**
 * Image-provider contract (SPEC-AI-DIENSTEN §6.1).
 *
 * The file is named class-provider.php so the plugin autoloader finds the interface.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Every image provider (mock, OpenAI, Recraft, later others via the `bps_ai_providers` filter) implements this.
 *
 * Providers never see customer data: the job runner only passes the English prompt built from the cleaned
 * description, API parameters, the model id and (optionally) an HMAC safety id. Raw provider error messages
 * may contain the prompt and are never shown to customers (Provider_Error carries a redacted message for the log).
 */
interface Provider {

	/**
	 * Stable id: 'mock'|'openai'|'recraft'|...
	 */
	public function id(): string;

	/**
	 * Customer-visible name (AI Act transparency), e.g. 'OpenAI'.
	 */
	public function label(): string;

	/**
	 * A key is available (mock: always true).
	 */
	public function is_configured(): bool;

	/**
	 * Feature flags.
	 *
	 * @return array{transparent_bg:bool, vector_output:bool, edit:bool, edit_mask:bool, text_quality:int, max_n:int}
	 */
	public function capabilities(): array;

	/**
	 * Generate images.
	 *
	 * @param array $req {prompt:string, params:array, model:string, safety_id:?string, brief?:array, test_hook?:string}.
	 * @throws Provider_Error On any failure.
	 */
	public function generate( array $req ): Provider_Result;

	/**
	 * Edit an existing image (refine).
	 *
	 * @param array $req {prompt:string, params:array, model:string, image_path:string, mask_path:?string, safety_id:?string}.
	 * @throws Provider_Error On any failure ('unsupported' when the provider cannot edit).
	 */
	public function edit( array $req ): Provider_Result;

	/**
	 * Raster → SVG. Optional; others throw Provider_Error 'unsupported'.
	 *
	 * @param string $image_path Absolute path of a PNG we generated.
	 * @throws Provider_Error On any failure.
	 */
	public function vectorize( string $image_path ): Provider_Result;

	/**
	 * Cost estimate in USD for budget reservation.
	 *
	 * @param string $op     generate|edit|vectorize.
	 * @param array  $params Params (n, quality, ...).
	 */
	public function estimate_usd( string $op, array $params ): float;
}
