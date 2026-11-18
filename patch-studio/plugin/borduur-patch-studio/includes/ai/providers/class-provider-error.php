<?php
/**
 * Provider failure with a normalised code.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Codes: policy|rate_limit|timeout|auth|bad_request|unavailable|unsupported|unknown (SPEC-AI-DIENSTEN §6.1).
 * The message is for the admin log only (already redacted by the provider, never shown to customers).
 */
class Provider_Error extends \RuntimeException {

	const CODES = array( 'policy', 'rate_limit', 'timeout', 'auth', 'bad_request', 'unavailable', 'unsupported', 'unknown' );

	/** @var string */
	private $error_code;

	/** @var string Offending request parameter, e.g. 'background' (OpenAI 400 on transparency). */
	private $param;

	/** @var int HTTP status if any. */
	private $http;

	/**
	 * @param string $code    Normalised code.
	 * @param string $message Log message (no key, no customer data).
	 * @param string $param   Offending parameter.
	 * @param int    $http    HTTP status.
	 */
	public function __construct( string $code, string $message = '', string $param = '', int $http = 0 ) {
		$this->error_code = in_array( $code, self::CODES, true ) ? $code : 'unknown';
		$this->param      = $param;
		$this->http       = $http;
		parent::__construct( '' !== $message ? $message : $this->error_code );
	}

	/**
	 * Normalised code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Offending parameter ('' if unknown).
	 */
	public function param(): string {
		return $this->param;
	}

	/**
	 * HTTP status (0 = none).
	 */
	public function http_status(): int {
		return $this->http;
	}

	/**
	 * Worth one retry with the fallback provider. Policy refusals never are (would bypass our own filter).
	 */
	public function retryable(): bool {
		return in_array( $this->error_code, array( 'rate_limit', 'timeout', 'unavailable' ), true );
	}

	/**
	 * Content-policy refusal.
	 */
	public function is_policy(): bool {
		return 'policy' === $this->error_code;
	}
}
