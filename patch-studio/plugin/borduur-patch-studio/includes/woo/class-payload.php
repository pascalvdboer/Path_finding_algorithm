<?php
/**
 * Whitelist validation of the DesignPayload sent by the browser (SPEC §6.2, §7.4, types.js).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns untrusted JSON into a clean DesignPayload. Unknown keys are dropped, enums are checked
 * against the live config, numbers are bounded, strings are sanitised and length-limited.
 */
final class Payload {

	const STATUSES = array( 'ok', 'warn', 'bad' );
	const PATHS    = array( 'straight', 'arc_top', 'arc_bottom' );

	/**
	 * Validate.
	 *
	 * @param mixed $raw Decoded JSON (array) or JSON string.
	 * @return array|WP_Error Clean payload.
	 */
	public static function validate( $raw ) {
		if ( is_string( $raw ) ) {
			if ( strlen( $raw ) > 200000 ) {
				return self::err( __( 'Je ontwerp bevat te veel gegevens.', 'borduur-patch-studio' ) );
			}
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return self::err();
		}
		$c = Config::get();

		$technique = self::str( $raw['technique'] ?? '' );
		if ( ! in_array( $technique, Config::enabled_techniques(), true ) ) {
			return self::err( __( 'Deze techniek is niet beschikbaar. Kies een andere techniek.', 'borduur-patch-studio' ) );
		}
		$shapes = array_keys( (array) ( $c['shapes'] ?? array() ) );
		$shape  = self::str( $raw['shape'] ?? '' );
		if ( ! in_array( $shape, $shapes, true ) ) {
			return self::err( __( 'Deze vorm kennen we niet. Kies een andere vorm.', 'borduur-patch-studio' ) );
		}

		$size_min = (float) ( $c['sizes']['minCm'] ?? 1 );
		$tech     = Config::technique( $technique );
		if ( isset( $tech['minCm'] ) ) {
			$size_min = min( $size_min, (float) $tech['minCm'] );
		}
		$size_max = (float) max( $c['sizes']['maxQuoteCm'] ?? 30, $tech['maxCm'] ?? 0 );
		$size     = self::num( $raw['sizeCm'] ?? null );
		if ( null === $size || $size < 1 || $size > $size_max ) {
			return self::err( __( 'Deze maat kunnen we niet maken. Kies een maat tussen de grenzen van de tool.', 'borduur-patch-studio' ) );
		}
		$size = round( $size * 2 ) / 2; // 0,5 cm steps (sizes.stepCm).
		if ( $size < $size_min ) {
			$size = $size_min;
		}

		$qty_max = (int) ( $c['quantity']['max'] ?? 10000 );
		$qty     = self::int( $raw['qty'] ?? null );
		if ( null === $qty || $qty < 1 || $qty > max( 100000, $qty_max ) ) {
			return self::err( __( 'Kies een geldig aantal.', 'borduur-patch-studio' ) );
		}

		$known_options = array();
		foreach ( (array) ( $c['options'] ?? array() ) as $o ) {
			if ( isset( $o['id'] ) ) {
				$known_options[] = (string) $o['id'];
			}
		}
		$options = array();
		foreach ( array_slice( (array) ( $raw['options'] ?? array() ), 0, 40 ) as $id ) {
			$id = self::str( $id, 64 );
			if ( ! in_array( $id, $known_options, true ) ) {
				return self::err( __( 'Een gekozen afwerking bestaat niet meer. Kies je afwerking opnieuw.', 'borduur-patch-studio' ) );
			}
			if ( ! in_array( $id, $options, true ) ) {
				$options[] = $id;
			}
		}

		$width  = self::clamp( self::num( $raw['widthMm'] ?? null ) ?? $size * 10, 1, 1000 );
		$height = self::clamp( self::num( $raw['heightMm'] ?? null ) ?? $size * 10, 1, 1000 );

		$locale = Config::public_locale( self::str( $raw['locale'] ?? '', 10 ) );

		$out = array(
			'v'          => 1,
			'locale'     => $locale,
			'technique'  => $technique,
			'sizeCm'     => (float) $size,
			'widthMm'    => round( $width, 1 ),
			'heightMm'   => round( $height, 1 ),
			'shape'      => $shape,
			'qty'        => $qty,
			'options'    => $options,
			'fabric'     => self::fabric( $raw['fabric'] ?? null, $c ),
			'edgeColor'  => self::thread_ref( $raw['edgeColor'] ?? null ),
			'threads'    => self::threads( $raw['threads'] ?? array() ),
			'texts'      => self::texts( $raw['texts'] ?? array(), $c ),
			'art'        => self::art( $raw['art'] ?? array() ),
			'analysis'   => self::analysis( $raw['analysis'] ?? null ),
			'advice'     => self::advice( $raw['advice'] ?? array(), $technique ),
			'priceClient'=> array(
				'totalIncl' => round( (float) ( self::num( $raw['priceClient']['totalIncl'] ?? 0 ) ?? 0 ), 2 ),
				'unitExcl'  => round( (float) ( self::num( $raw['priceClient']['unitExcl'] ?? 0 ) ?? 0 ), 4 ),
			),
			'source'     => array(
				'fileName' => isset( $raw['source']['fileName'] ) && is_string( $raw['source']['fileName'] ) ? self::str( $raw['source']['fileName'], 160 ) : null,
				'fileType' => isset( $raw['source']['fileType'] ) && is_string( $raw['source']['fileType'] ) ? self::str( $raw['source']['fileType'], 100 ) : null,
			),
			'notes'      => self::text( $raw['notes'] ?? '', 2000 ),
		);
		if ( isset( $raw['rightsConsent'] ) && is_array( $raw['rightsConsent'] ) ) {
			$out['rightsConsent'] = array(
				'v'        => self::str( $raw['rightsConsent']['v'] ?? '', 20 ),
				'accepted' => true === ( $raw['rightsConsent']['accepted'] ?? false ),
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Sub-structures
	 * ------------------------------------------------------------------- */

	/**
	 * @param mixed $v Fabric.
	 * @param array $c Config.
	 */
	private static function fabric( $v, array $c ): array {
		$v   = is_array( $v ) ? $v : array();
		$id  = self::str( $v['id'] ?? 'auto', 40 );
		$ids = array( 'auto' );
		foreach ( array_merge( (array) ( $c['fabrics'] ?? array() ), (array) ( $c['leathers'] ?? array() ) ) as $f ) {
			if ( isset( $f['id'] ) ) {
				$ids[] = (string) $f['id'];
			}
		}
		if ( ! in_array( $id, $ids, true ) ) {
			$id = 'auto';
		}
		return array(
			'id'    => $id,
			'hex'   => self::hex( $v['hex'] ?? '' ),
			'label' => self::str( $v['label'] ?? '', 60 ),
		);
	}

	/**
	 * @param mixed $v Thread reference.
	 */
	private static function thread_ref( $v ): ?array {
		if ( ! is_array( $v ) ) {
			return null;
		}
		$code = self::code( $v['code'] ?? '' );
		if ( '' === $code ) {
			return null;
		}
		return array(
			'code' => $code,
			'hex'  => self::hex( $v['hex'] ?? '' ),
			'name' => self::str( $v['name'] ?? '', 60 ),
		);
	}

	/**
	 * @param mixed $list Threads.
	 */
	private static function threads( $list ): array {
		$out = array();
		foreach ( array_slice( is_array( $list ) ? $list : array(), 0, 40 ) as $t ) {
			$ref = self::thread_ref( $t );
			if ( ! $ref ) {
				continue;
			}
			$ref['share'] = round( self::clamp( self::num( $t['share'] ?? 0 ) ?? 0, 0, 1 ), 4 );
			$out[]        = $ref;
		}
		return $out;
	}

	/**
	 * @param mixed $list Text layers.
	 * @param array $c    Config.
	 */
	private static function texts( $list, array $c ): array {
		$max_layers = (int) ( $c['text']['maxLayers'] ?? 10 );
		$max_chars  = (int) ( $c['text']['maxChars'] ?? 60 );
		$fonts      = array();
		foreach ( (array) ( $c['fonts'] ?? array() ) as $f ) {
			if ( isset( $f['id'] ) ) {
				$fonts[] = (string) $f['id'];
			}
		}
		$out = array();
		foreach ( array_slice( is_array( $list ) ? $list : array(), 0, $max_layers ) as $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$text = self::str( $t['text'] ?? '', $max_chars );
			if ( '' === $text ) {
				continue;
			}
			$font = self::str( $t['font'] ?? '', 40 );
			if ( ! in_array( $font, $fonts, true ) ) {
				$font = $fonts[0] ?? 'Sora';
			}
			$path  = self::str( $t['path'] ?? 'straight', 12 );
			$code  = self::str( $t['threadCode'] ?? 'auto', 16 );
			$out[] = array(
				'text'       => $text,
				'font'       => $font,
				'heightMm'   => round( self::clamp( self::num( $t['heightMm'] ?? 6 ) ?? 6, 1, 100 ), 1 ),
				'threadCode' => 'auto' === $code ? 'auto' : self::code( $code ),
				'path'       => in_array( $path, self::PATHS, true ) ? $path : 'straight',
				// Placement chosen by the customer (drag/arrows/sliders); same limits as ui/state.js.
				'offsetMm'      => array(
					'x' => round( self::clamp( self::num( $t['offsetMm']['x'] ?? 0 ) ?? 0, -150, 150 ), 1 ),
					'y' => round( self::clamp( self::num( $t['offsetMm']['y'] ?? 0 ) ?? 0, -150, 150 ), 1 ),
				),
				'angleDeg'      => (int) round( self::clamp( self::num( $t['angleDeg'] ?? 0 ) ?? 0, -180, 180 ) ),
				'insetMm'       => round( self::clamp( self::num( $t['insetMm'] ?? 0 ) ?? 0, -5, 60 ), 1 ),
				'letterSpacing' => round( self::clamp( self::num( $t['letterSpacing'] ?? 0.05 ) ?? 0.05, -0.05, 0.5 ), 3 ),
			);
		}
		return $out;
	}

	/**
	 * @param mixed $v Art placement.
	 */
	private static function art( $v ): array {
		$v   = is_array( $v ) ? $v : array();
		$rot = (int) ( self::num( $v['rotation'] ?? 0 ) ?? 0 );
		return array(
			'scale'    => round( self::clamp( self::num( $v['scale'] ?? 1 ) ?? 1, 0.05, 5 ), 4 ),
			'offsetMm' => array(
				'x' => round( self::clamp( self::num( $v['offsetMm']['x'] ?? 0 ) ?? 0, -500, 500 ), 2 ),
				'y' => round( self::clamp( self::num( $v['offsetMm']['y'] ?? 0 ) ?? 0, -500, 500 ), 2 ),
			),
			'rotation' => in_array( $rot, array( 0, 90, 180, 270 ), true ) ? $rot : 0,
		);
	}

	/**
	 * @param mixed $v Analysis summary.
	 */
	private static function analysis( $v ): ?array {
		if ( ! is_array( $v ) ) {
			return null;
		}
		$st = null;
		if ( isset( $v['stitches'] ) && is_array( $v['stitches'] ) ) {
			$st = array(
				'min' => (int) self::clamp( self::num( $v['stitches']['min'] ?? 0 ) ?? 0, 0, 10000000 ),
				'max' => (int) self::clamp( self::num( $v['stitches']['max'] ?? 0 ) ?? 0, 0, 10000000 ),
			);
		}
		$warnings = array();
		foreach ( array_slice( (array) ( $v['warnings'] ?? array() ), 0, 20 ) as $w ) {
			$w = self::str( $w, 300 );
			if ( '' !== $w ) {
				$warnings[] = $w;
			}
		}
		$fine = self::num( $v['fineLineMm'] ?? null );
		$text = self::num( $v['minTextMm'] ?? null );
		return array(
			'kind'           => self::key( $v['kind'] ?? '' ),
			'hasSoftShading' => ! empty( $v['hasSoftShading'] ),
			'colors'         => (int) self::clamp( self::num( $v['colors'] ?? 0 ) ?? 0, 0, 1000 ),
			'fineLineMm'     => null === $fine ? null : round( self::clamp( $fine, 0, 1000 ), 2 ),
			'minTextMm'      => null === $text ? null : round( self::clamp( $text, 0, 1000 ), 2 ),
			'stitches'       => $st,
			'warnings'       => $warnings,
			'shape'          => isset( $v['shape'] ) && is_string( $v['shape'] ) ? self::key( $v['shape'] ) : null,
			'background'     => self::str( $v['background'] ?? '', 40 ),
		);
	}

	/**
	 * @param mixed  $v         Advice summary.
	 * @param string $technique Chosen technique.
	 */
	private static function advice( $v, string $technique ): array {
		$v        = is_array( $v ) ? $v : array();
		$enabled  = Config::enabled_techniques();
		$rec      = self::str( $v['recommended'] ?? '', 20 );
		$statuses = array();
		foreach ( (array) ( $v['statuses'] ?? array() ) as $t => $s ) {
			if ( in_array( (string) $t, $enabled, true ) && in_array( $s, self::STATUSES, true ) ) {
				$statuses[ (string) $t ] = $s;
			}
		}
		$chosen = self::str( $v['chosenStatus'] ?? '', 4 );
		return array(
			'recommended'  => in_array( $rec, $enabled, true ) ? $rec : $technique,
			'chosenStatus' => in_array( $chosen, self::STATUSES, true ) ? $chosen : 'ok',
			'statuses'     => $statuses,
			'headline'     => self::str( $v['headline'] ?? '', 200 ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Scalars
	 * ------------------------------------------------------------------- */

	/**
	 * Single-line string.
	 *
	 * @param mixed $v   Value.
	 * @param int   $max Max chars.
	 */
	public static function str( $v, int $max = 200 ): string {
		if ( ! is_scalar( $v ) ) {
			return '';
		}
		$s = sanitize_text_field( (string) $v );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/**
	 * Multi-line text.
	 *
	 * @param mixed $v   Value.
	 * @param int   $max Max chars.
	 */
	public static function text( $v, int $max = 2000 ): string {
		if ( ! is_scalar( $v ) ) {
			return '';
		}
		$s = sanitize_textarea_field( (string) $v );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/**
	 * Finite number or null.
	 *
	 * @param mixed $v Value.
	 */
	public static function num( $v ): ?float {
		if ( is_bool( $v ) || null === $v || '' === $v || ! is_numeric( $v ) ) {
			return null;
		}
		$f = (float) $v;
		return is_finite( $f ) ? $f : null;
	}

	/**
	 * Integer or null (accepts "10" and 10.0).
	 *
	 * @param mixed $v Value.
	 */
	public static function int( $v ): ?int {
		$f = self::num( $v );
		if ( null === $f || floor( $f ) !== $f ) {
			return null;
		}
		return (int) $f;
	}

	/**
	 * @param float $v   Value.
	 * @param float $min Min.
	 * @param float $max Max.
	 */
	private static function clamp( float $v, float $min, float $max ): float {
		return max( $min, min( $max, $v ) );
	}

	/**
	 * '#rrggbb' or ''.
	 *
	 * @param mixed $v Value.
	 */
	private static function hex( $v ): string {
		$v = is_string( $v ) ? strtolower( trim( $v ) ) : '';
		if ( preg_match( '/^#[0-9a-f]{3}$/', $v ) ) {
			$v = '#' . $v[1] . $v[1] . $v[2] . $v[2] . $v[3] . $v[3];
		}
		return preg_match( '/^#[0-9a-f]{6}$/', $v ) ? $v : '';
	}

	/**
	 * Thread code (alphanumeric, max 16).
	 *
	 * @param mixed $v Value.
	 */
	private static function code( $v ): string {
		$v = is_scalar( $v ) ? (string) $v : '';
		return substr( preg_replace( '/[^A-Za-z0-9_-]/', '', $v ), 0, 16 );
	}

	/**
	 * Machine key (a-z, 0-9, _ -).
	 *
	 * @param mixed $v Value.
	 */
	private static function key( $v ): string {
		$v = is_scalar( $v ) ? strtolower( (string) $v ) : '';
		return substr( preg_replace( '/[^a-z0-9_-]/', '', $v ), 0, 30 );
	}

	/**
	 * Error.
	 *
	 * @param string $msg Message.
	 */
	private static function err( string $msg = '' ): WP_Error {
		return new WP_Error(
			'bps_invalid_design',
			'' !== $msg ? $msg : __( 'We konden je ontwerp niet goed lezen. Laad de pagina opnieuw en probeer het nog eens.', 'borduur-patch-studio' ),
			array( 'status' => 400 )
		);
	}
}
