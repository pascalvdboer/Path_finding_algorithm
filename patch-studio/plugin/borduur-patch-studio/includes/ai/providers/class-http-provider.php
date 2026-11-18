<?php
/**
 * Shared HTTP plumbing for remote providers (wp_remote_*), with error mapping and redaction.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI\Providers;

use BorduurPatchStudio\AI\AI_Settings;
use BorduurPatchStudio\AI\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Base class: keys come from Secrets only (server-side), requests carry no cookies, no customer IP, no user field.
 */
abstract class Http_Provider implements Provider {

	/**
	 * Provider settings from ai-providers.json + admin overrides.
	 */
	protected function cfg(): array {
		return (array) AI_Settings::path( 'providers.' . $this->id(), array() );
	}

	public function label(): string {
		return (string) ( $this->cfg()['label'] ?? ucfirst( $this->id() ) );
	}

	public function is_configured(): bool {
		return Secrets::has( $this->id() );
	}

	/**
	 * API base URL without trailing slash.
	 */
	protected function endpoint(): string {
		return rtrim( (string) ( $this->cfg()['endpoint'] ?? '' ), '/' );
	}

	/**
	 * Timeout in seconds.
	 */
	protected function timeout(): int {
		$t = (int) ( $this->cfg()['timeoutSec'] ?? AI_Settings::path( 'timeoutSec', 60 ) );
		return max( 5, min( 180, $t ) );
	}

	/**
	 * POST JSON and decode the JSON answer.
	 *
	 * @param string $path    Path after the endpoint.
	 * @param array  $body    Body.
	 * @param int    $timeout Timeout (0 = default).
	 * @return array{0:array, 1:int} Decoded body and duration in ms.
	 * @throws Provider_Error On failure.
	 */
	protected function post_json( string $path, array $body, int $timeout = 0 ): array {
		return $this->send(
			'POST',
			$path,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			),
			$timeout
		);
	}

	/**
	 * POST multipart/form-data.
	 *
	 * @param string $path    Path.
	 * @param array  $fields  name => scalar value (arrays become repeated "name[]" fields).
	 * @param array  $files   name => [abs path, mime, filename].
	 * @param int    $timeout Timeout.
	 * @return array{0:array, 1:int}
	 * @throws Provider_Error On failure.
	 */
	protected function post_multipart( string $path, array $fields, array $files, int $timeout = 0 ): array {
		$boundary = 'bps' . bin2hex( random_bytes( 12 ) );
		$body     = '';
		foreach ( $fields as $name => $value ) {
			if ( null === $value ) {
				continue;
			}
			$body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name . "\"\r\n\r\n" . ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value ) . "\r\n";
		}
		foreach ( $files as $name => $file ) {
			list( $abs, $mime, $filename ) = $file;
			$data = is_readable( $abs ) ? (string) file_get_contents( $abs ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( '' === $data ) {
				throw new Provider_Error( 'bad_request', 'Edit source image missing.' );
			}
			$body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name . '"; filename="' . $filename . "\"\r\nContent-Type: " . $mime . "\r\n\r\n" . $data . "\r\n";
		}
		$body .= '--' . $boundary . "--\r\n";
		return $this->send(
			'POST',
			$path,
			array(
				'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
				'body'    => $body,
			),
			$timeout
		);
	}

	/**
	 * GET JSON.
	 *
	 * @param string $path    Path.
	 * @param int    $timeout Timeout.
	 * @return array{0:array, 1:int}
	 * @throws Provider_Error On failure.
	 */
	protected function get_json( string $path, int $timeout = 0 ): array {
		return $this->send( 'GET', $path, array(), $timeout );
	}

	/**
	 * Perform a request.
	 *
	 * @param string $method  Method.
	 * @param string $path    Path.
	 * @param array  $args    wp_remote_request args.
	 * @param int    $timeout Timeout.
	 * @return array{0:array, 1:int}
	 * @throws Provider_Error On failure.
	 */
	protected function send( string $method, string $path, array $args, int $timeout = 0 ): array {
		$key = Secrets::get( $this->id() );
		if ( '' === $key ) {
			throw new Provider_Error( 'auth', 'No API key configured for ' . $this->id() . '.' );
		}
		$args['method']               = $method;
		$args['timeout']              = $timeout > 0 ? $timeout : $this->timeout();
		$args['redirection']          = 0;
		$args['headers']              = array_merge( (array) ( $args['headers'] ?? array() ), array( 'Authorization' => 'Bearer ' . $key ) );
		$args['user-agent']           = 'PatchStudio/' . ( defined( 'BPS_VERSION' ) ? BPS_VERSION : '1' );
		$args['cookies']              = array();
		$args['limit_response_size']  = 40 * 1024 * 1024;
		$start = microtime( true );
		$res   = wp_remote_request( $this->endpoint() . $path, $args );
		$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $res ) ) {
			$msg = Secrets::redact( $res->get_error_message() );
			$is_timeout = false !== stripos( $msg, 'timed out' ) || false !== stripos( $msg, 'timeout' ) || 'http_request_timeout' === $res->get_error_code();
			throw new Provider_Error( $is_timeout ? 'timeout' : 'unavailable', $this->id() . ': ' . $msg );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$json = json_decode( $raw, true );
		$json = is_array( $json ) ? $json : array();
		if ( $code >= 200 && $code < 300 ) {
			return array( $json, $ms );
		}
		throw $this->map_error( $code, $json );
	}

	/**
	 * Map an HTTP error answer to a Provider_Error. Subclasses refine (policy codes differ per provider).
	 *
	 * @param int   $code HTTP status.
	 * @param array $json Decoded body.
	 */
	protected function map_error( int $code, array $json ): Provider_Error {
		$err   = isset( $json['error'] ) && is_array( $json['error'] ) ? $json['error'] : $json;
		$type  = strtolower( (string) ( $err['code'] ?? $err['type'] ?? '' ) );
		$param = (string) ( $err['param'] ?? '' );
		$msg   = $this->id() . ' HTTP ' . $code . ( '' !== $type ? ' ' . $type : '' ) . ': ' . Secrets::redact( (string) ( $err['message'] ?? '' ) );
		if ( in_array( $type, array( 'moderation_blocked', 'content_policy_violation', 'content_filter', 'safety_violation' ), true ) ) {
			return new Provider_Error( 'policy', $msg, $param, $code );
		}
		if ( 429 === $code ) {
			return new Provider_Error( 'insufficient_quota' === $type ? 'auth' : 'rate_limit', $msg, $param, $code );
		}
		if ( 401 === $code || 403 === $code ) {
			return new Provider_Error( 'auth', $msg, $param, $code );
		}
		if ( 408 === $code || 504 === $code ) {
			return new Provider_Error( 'timeout', $msg, $param, $code );
		}
		if ( $code >= 500 ) {
			return new Provider_Error( 'unavailable', $msg, $param, $code );
		}
		if ( $code >= 400 ) {
			return new Provider_Error( 'bad_request', $msg, $param, $code );
		}
		return new Provider_Error( 'unknown', $msg, $param, $code );
	}

	public function vectorize( string $image_path ): Provider_Result {
		throw new Provider_Error( 'unsupported', $this->id() . ' cannot vectorize.' );
	}
}
