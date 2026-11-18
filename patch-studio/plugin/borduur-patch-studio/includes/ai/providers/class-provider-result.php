<?php
/**
 * Value object returned by providers.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Result of a provider call (SPEC-AI-DIENSTEN §6.1).
 */
final class Provider_Result {

	/** @var array<int, array{bytes:string, mime:string, w:int, h:int}> */
	public $images = array();

	/** @var array{tokens_in:?int, tokens_out:?int, images:int} */
	public $usage = array(
		'tokens_in'  => null,
		'tokens_out' => null,
		'images'     => 0,
	);

	/** @var float */
	public $cost_usd = 0.0;

	/** @var bool Cost is an estimate (no usage returned). */
	public $estimated = false;

	/** @var int */
	public $duration_ms = 0;

	/** @var string */
	public $model = '';

	/** @var string Provider id that produced the result (set by the runner on fallback). */
	public $provider = '';

	/**
	 * @param array  $images    Images.
	 * @param array  $usage     Usage.
	 * @param float  $cost_usd  Cost.
	 * @param bool   $estimated Estimated cost.
	 * @param int    $duration  Duration in ms.
	 * @param string $model     Model id.
	 */
	public function __construct( array $images = array(), array $usage = array(), float $cost_usd = 0.0, bool $estimated = false, int $duration = 0, string $model = '' ) {
		foreach ( $images as $img ) {
			if ( ! is_array( $img ) || ! isset( $img['bytes'] ) || ! is_string( $img['bytes'] ) || '' === $img['bytes'] ) {
				continue;
			}
			$this->images[] = array(
				'bytes' => $img['bytes'],
				'mime'  => (string) ( $img['mime'] ?? 'image/png' ),
				'w'     => (int) ( $img['w'] ?? 0 ),
				'h'     => (int) ( $img['h'] ?? 0 ),
			);
		}
		$this->usage       = array(
			'tokens_in'  => isset( $usage['tokens_in'] ) ? (int) $usage['tokens_in'] : null,
			'tokens_out' => isset( $usage['tokens_out'] ) ? (int) $usage['tokens_out'] : null,
			'images'     => (int) ( $usage['images'] ?? count( $this->images ) ),
		);
		$this->cost_usd    = max( 0.0, $cost_usd );
		$this->estimated   = $estimated;
		$this->duration_ms = max( 0, $duration );
		$this->model       = $model;
	}

	/**
	 * Width/height of PNG bytes (IHDR), or [0,0].
	 *
	 * @param string $bytes PNG bytes.
	 * @return int[]
	 */
	public static function png_size( string $bytes ): array {
		if ( strlen( $bytes ) < 24 || "\x89PNG\r\n\x1a\n" !== substr( $bytes, 0, 8 ) ) {
			return array( 0, 0 );
		}
		$wh = unpack( 'Nw/Nh', substr( $bytes, 16, 8 ) );
		return array( (int) $wh['w'], (int) $wh['h'] );
	}

	/**
	 * Sniff the mime type of image bytes (never trust the provider's claim).
	 *
	 * @param string $bytes Bytes.
	 */
	public static function sniff_mime( string $bytes ): string {
		if ( "\x89PNG\r\n\x1a\n" === substr( $bytes, 0, 8 ) ) {
			return 'image/png';
		}
		if ( 'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'image/webp';
		}
		if ( "\xFF\xD8\xFF" === substr( $bytes, 0, 3 ) ) {
			return 'image/jpeg';
		}
		$head = ltrim( substr( $bytes, 0, 512 ) );
		if ( 0 === strpos( $head, '<?xml' ) || 0 === strpos( $head, '<svg' ) || false !== strpos( $head, '<svg' ) ) {
			return 'image/svg+xml';
		}
		return 'application/octet-stream';
	}
}
