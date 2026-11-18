<?php
/**
 * Recraft: fallback generator with native SVG output, and vectorizer for a chosen raster proposal.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §6.3, docs/ai/01-apis.md "Fallback". Only active with a key. Endpoints and prices are marked
 * `verified:false` in ai-providers.json until the owner checks them (the admin shows a warning).
 *   POST {endpoint}/images/generations  (OpenAI-compatible JSON: prompt, style, model, n, size, response_format)
 *   POST {endpoint}/images/vectorize     (multipart "file")
 * Remote image URLs in an answer are only fetched from Recraft hosts (no SSRF via a crafted answer).
 */
final class Recraft_Provider extends Http_Provider {

	const URL_HOSTS = '/(^|\.)recraft\.ai$/i';

	public function id(): string {
		return 'recraft';
	}

	public function capabilities(): array {
		return array(
			'transparent_bg' => true,
			'vector_output'  => true,
			'edit'           => false,
			'edit_mask'      => false,
			'text_quality'   => 3,
			'max_n'          => 4,
		);
	}

	public function generate( array $req ): Provider_Result {
		$cfg    = $this->cfg();
		$params = (array) ( $req['params'] ?? array() );
		$max    = (int) ( $cfg['promptMaxChars'] ?? 1000 );
		$body   = array(
			'prompt'          => mb_substr( (string) $req['prompt'], 0, max( 200, $max ) ),
			'style'           => (string) ( $cfg['style'] ?? 'vector_illustration' ),
			'model'           => (string) ( $cfg['model'] ?? 'recraftv4_vector' ),
			'n'               => max( 1, min( 4, (int) ( $params['n'] ?? 1 ) ) ),
			'size'            => '1024x1024',
			'response_format' => 'b64_json',
		);
		list( $json, $ms ) = $this->post_json( '/images/generations', $body );
		$images            = array();
		foreach ( (array) ( $json['data'] ?? array() ) as $item ) {
			$bytes = $this->bytes_from( is_array( $item ) ? $item : array() );
			if ( '' !== $bytes ) {
				$images[] = $this->image( $bytes );
			}
		}
		if ( empty( $images ) ) {
			throw new Provider_Error( 'unknown', 'recraft: answer without images.' );
		}
		$per = (float) ( $cfg['estUsdPerImage'] ?? 0.08 );
		return new Provider_Result( $images, array( 'images' => count( $images ) ), $per * count( $images ), true, $ms, $body['model'] );
	}

	public function edit( array $req ): Provider_Result {
		throw new Provider_Error( 'unsupported', 'recraft: edit is not wired (refine uses the primary provider).' );
	}

	public function vectorize( string $image_path ): Provider_Result {
		if ( ! is_readable( $image_path ) ) {
			throw new Provider_Error( 'bad_request', 'recraft: vectorize source missing.' );
		}
		list( $json, $ms ) = $this->post_multipart( '/images/vectorize', array( 'response_format' => 'b64_json' ), array( 'file' => array( $image_path, 'image/png', 'design.png' ) ), 15 );
		$item              = isset( $json['image'] ) && is_array( $json['image'] ) ? $json['image'] : (array) ( $json['data'][0] ?? array() );
		$bytes             = $this->bytes_from( $item );
		if ( '' === $bytes || 'image/svg+xml' !== Provider_Result::sniff_mime( $bytes ) ) {
			throw new Provider_Error( 'unknown', 'recraft: vectorize returned no SVG.' );
		}
		return new Provider_Result( array( $this->image( $bytes ) ), array( 'images' => 1 ), (float) ( $this->cfg()['vectorizeUsd'] ?? 0.01 ), true, $ms, 'vectorize' );
	}

	public function estimate_usd( string $op, array $params ): float {
		$cfg = $this->cfg();
		if ( 'vectorize' === $op ) {
			return (float) ( $cfg['vectorizeUsd'] ?? 0.01 );
		}
		return round( (float) ( $cfg['estUsdPerImage'] ?? 0.08 ) * max( 1, (int) ( $params['n'] ?? 1 ) ), 4 );
	}

	/**
	 * Image bytes from an answer item (b64_json or a Recraft-hosted URL).
	 *
	 * @param array $item Item.
	 */
	private function bytes_from( array $item ): string {
		if ( ! empty( $item['b64_json'] ) && is_string( $item['b64_json'] ) ) {
			$b = base64_decode( $item['b64_json'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			return false === $b ? '' : $b;
		}
		$url  = isset( $item['url'] ) && is_string( $item['url'] ) ? $item['url'] : '';
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $url || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! preg_match( self::URL_HOSTS, $host ) ) {
			return '';
		}
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 1,
				'limit_response_size' => 20 * 1024 * 1024,
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return '';
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	/**
	 * Normalise bytes into an image row.
	 *
	 * @param string $bytes Bytes.
	 */
	private function image( string $bytes ): array {
		$mime = Provider_Result::sniff_mime( $bytes );
		if ( 'image/png' === $mime ) {
			list( $w, $h ) = Provider_Result::png_size( $bytes );
		} else {
			$w = 1024;
			$h = 1024;
			if ( preg_match( '/viewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $bytes, $m ) ) {
				$w = (int) round( (float) $m[1] );
				$h = (int) round( (float) $m[2] );
			}
		}
		return array(
			'bytes' => $bytes,
			'mime'  => $mime,
			'w'     => $w,
			'h'     => $h,
		);
	}
}
