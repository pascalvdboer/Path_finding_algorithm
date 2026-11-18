<?php
/**
 * Mock provider: realistic example designs without network, key or cost (tests, demo, mode "test").
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI\Providers;

use BorduurPatchStudio\AI\AI_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §6.4. Picks own, flat, embroiderable SVG drawings from config/ai-mock/ by keywords in the English
 * brief subjects, colours them with the brief colours, frames them in the patch shape and (textMode=ai) sets the
 * text along the edge. Returns n different variants. The panel always says "Testmodus: dit zijn voorbeeldontwerpen,
 * geen echte AI" — the mock never pretends to be real AI.
 *
 * Test hooks (only when AI_Settings::test_hooks_allowed(); passed by Jobs as $req['test_hook']):
 * 'fail' → unavailable, 'policy' → policy, 'slow' → sleeps mock.slowMs, 'timeout' → timeout.
 */
final class Mock_Provider implements Provider {

	/** @var array|null */
	private static $cfg = null;

	/** @var int|null Latency override in ms (tests). */
	public static $latency_override = null;

	public function id(): string {
		return 'mock';
	}

	public function label(): string {
		return (string) AI_Settings::path( 'providers.mock.label', 'Voorbeeldontwerpen (test)' );
	}

	public function is_configured(): bool {
		return true;
	}

	public function capabilities(): array {
		return array(
			'transparent_bg' => true,
			'vector_output'  => true,
			'edit'           => true,
			'edit_mask'      => false,
			'text_quality'   => 5,
			'max_n'          => 4,
		);
	}

	public function estimate_usd( string $op, array $params ): float {
		return 0.0;
	}

	public function vectorize( string $image_path ): Provider_Result {
		$svg = is_readable( $image_path ) ? (string) file_get_contents( $image_path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( 'image/svg+xml' !== Provider_Result::sniff_mime( $svg ) ) {
			throw new Provider_Error( 'unsupported', 'mock: only SVG input.' );
		}
		return new Provider_Result( array( $this->image( self::strip_marking( $svg ) ) ), array( 'images' => 1 ), 0.0, false, 0, 'mock-vectorize' );
	}

	public function generate( array $req ): Provider_Result {
		$start = microtime( true );
		$this->hooks( $req );
		$params = (array) ( $req['params'] ?? array() );
		$n      = max( 1, min( 4, (int) ( $params['n'] ?? 3 ) ) );
		$brief  = isset( $req['brief'] ) && is_array( $req['brief'] ) ? $req['brief'] : array();
		$prompt = (string) ( $req['prompt'] ?? '' );
		$order  = $this->pick( $brief, $prompt );
		$colors = $this->palette( $brief, $prompt );
		$shape  = (string) ( $brief['shape'] ?? 'circle' );
		$text   = 'ai' === ( $brief['textMode'] ?? '' ) ? (string) ( $brief['text'] ?? '' ) : '';
		$fabric = $this->fabric_hex( (string) ( $brief['fabric'] ?? 'black' ) );

		$images = array();
		$seen   = array();
		for ( $i = 0, $try = 0; count( $images ) < $n && $try < $n * 4; $try++ ) {
			$tpl = $order[ $try % count( $order ) ];
			$rot = intdiv( $try, count( $order ) ) + $i;
			$svg = $this->compose( $tpl, $this->rotate( $colors, $rot ), $shape, $text, $fabric, $try );
			$sha = sha1( $svg );
			if ( isset( $seen[ $sha ] ) ) {
				continue;
			}
			$seen[ $sha ] = true;
			$images[]     = $this->image( $svg );
			++$i;
		}
		$this->sleep();
		return new Provider_Result( $images, array( 'images' => count( $images ) ), 0.0, false, (int) round( ( microtime( true ) - $start ) * 1000 ), 'mock-1' );
	}

	public function edit( array $req ): Provider_Result {
		$start = microtime( true );
		$this->hooks( $req );
		$path = (string) ( $req['image_path'] ?? '' );
		$src  = '' !== $path && is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( 'image/svg+xml' !== Provider_Result::sniff_mime( $src ) ) {
			throw new Provider_Error( 'bad_request', 'mock: edit source missing or not SVG.' );
		}
		$src    = self::strip_marking( $src );
		$n      = max( 1, min( 4, (int) ( $req['params']['n'] ?? 2 ) ) );
		$images = array();
		for ( $k = 0; $k < $n; $k++ ) {
			// Visible, predictable change: thicker outlines, and for the 2nd version a swapped colour order.
			$factor = 1.35 + 0.25 * $k;
			$svg    = (string) preg_replace_callback(
				'/stroke-width="([\d.]+)"/',
				static function ( $m ) use ( $factor ) {
					return 'stroke-width="' . round( (float) $m[1] * $factor, 1 ) . '"';
				},
				$src
			);
			if ( $k % 2 === 1 ) {
				$svg = $this->swap_first_colors( $svg );
			}
			$svg      = str_replace( '<svg ', '<svg data-mock-edit="' . ( $k + 1 ) . '" ', $svg );
			$images[] = $this->image( $svg );
		}
		$this->sleep();
		return new Provider_Result( $images, array( 'images' => count( $images ) ), 0.0, false, (int) round( ( microtime( true ) - $start ) * 1000 ), 'mock-1' );
	}

	/**
	 * Templates ordered: keyword matches first (score), then a deterministic order from crc32(prompt).
	 *
	 * @param array  $brief  Brief.
	 * @param string $prompt Prompt.
	 * @return array[] Templates.
	 */
	private function pick( array $brief, string $prompt ): array {
		$cfg  = self::cfg();
		$tpls = array_values( array_filter( (array) ( $cfg['templates'] ?? array() ), array( $this, 'template_ok' ) ) );
		if ( empty( $tpls ) ) {
			throw new Provider_Error( 'unavailable', 'mock: no templates found.' );
		}
		$hay = strtolower( (string) ( $brief['subjectEn'] ?? '' ) );
		foreach ( array( 'subjects', 'objects', 'groups' ) as $k ) {
			$hay .= ' ' . strtolower( implode( ' ', (array) ( $brief['understood'][ $k ] ?? array() ) ) );
		}
		if ( '' === trim( $hay ) && preg_match( '/^SUBJECT:\s*(.+)$/mi', $prompt, $m ) ) {
			$hay = strtolower( $m[1] );
		}
		$seed   = crc32( $prompt );
		$scored = array();
		foreach ( $tpls as $pos => $t ) {
			$score = 0;
			foreach ( (array) ( $t['keywords']['en'] ?? array() ) as $kw ) {
				if ( '' !== $kw && preg_match( '/\b' . preg_quote( strtolower( (string) $kw ), '/' ) . '\b/', $hay ) ) {
					++$score;
				}
			}
			$scored[] = array( $score, crc32( $seed . '|' . $t['id'] ), $pos, $t );
		}
		usort(
			$scored,
			static function ( $a, $b ) {
				return array( $b[0], $a[1], $a[2] ) <=> array( $a[0], $b[1], $b[2] );
			}
		);
		return array_column( $scored, 3 );
	}

	/**
	 * Template has an id and a readable file.
	 *
	 * @param mixed $t Template.
	 */
	public function template_ok( $t ): bool {
		return is_array( $t ) && ! empty( $t['id'] ) && ! empty( $t['file'] ) && 1 === preg_match( '/^[a-z0-9-]+\.svg$/', (string) $t['file'] ) && is_readable( self::dir() . $t['file'] );
	}

	/**
	 * Four colours [outline, main, accent, light] from the brief colours (names) or a deterministic palette.
	 *
	 * @param array  $brief  Brief.
	 * @param string $prompt Prompt.
	 * @return string[]
	 */
	private function palette( array $brief, string $prompt ): array {
		$cfg      = self::cfg();
		$palettes = array_values( (array) ( $cfg['palettes'] ?? array( array( '#1b1b1b', '#c62828', '#f7c600', '#ffffff' ) ) ) );
		$base     = $palettes[ crc32( $prompt ) % count( $palettes ) ];
		$hex      = array();
		foreach ( (array) ( $brief['colors'] ?? array() ) as $c ) {
			$h = $this->hex( (string) $c );
			if ( null !== $h && ! in_array( $h, $hex, true ) ) {
				$hex[] = $h;
			}
		}
		$outline = (string) $base[0];
		$hex     = array_values( array_filter( $hex, static function ( $h ) use ( $outline ) {
			return $h !== $outline;
		} ) );
		$main    = $hex[0] ?? (string) $base[1];
		$accent  = $hex[1] ?? (string) $base[2];
		$light   = $hex[2] ?? (string) $base[3];
		$max     = (int) ( $brief['maxColors'] ?? 4 );
		if ( $max > 0 && $max <= 2 ) {
			$accent = $main;
			$light  = $main;
		} elseif ( 3 === $max ) {
			$light = $accent;
		}
		return array( $outline, $main, $accent, $light );
	}

	/**
	 * Rotate main/accent/light for variant $k (outline stays).
	 *
	 * @param string[] $c Colours.
	 * @param int      $k Variant.
	 */
	private function rotate( array $c, int $k ): array {
		$rest = array_slice( $c, 1 );
		$k    = $k % count( $rest );
		$rest = array_merge( array_slice( $rest, $k ), array_slice( $rest, 0, $k ) );
		return array_merge( array( $c[0] ), $rest );
	}

	/**
	 * Hex for a colour name or hex.
	 *
	 * @param string $c Colour.
	 */
	private function hex( string $c ): ?string {
		$c = strtolower( trim( $c ) );
		if ( preg_match( '/^#[0-9a-f]{6}$/', $c ) ) {
			return $c;
		}
		$map = (array) ( self::cfg()['colorHex'] ?? array() );
		return isset( $map[ $c ] ) ? strtolower( (string) $map[ $c ] ) : null;
	}

	/**
	 * Fabric colour hex.
	 *
	 * @param string $fabric Fabric key.
	 */
	private function fabric_hex( string $fabric ): string {
		$map = (array) ( self::cfg()['fabricHex'] ?? array() );
		return isset( $map[ $fabric ] ) ? (string) $map[ $fabric ] : '#1b1b1b';
	}

	/**
	 * Build the final SVG.
	 *
	 * @param array    $tpl     Template.
	 * @param string[] $c       Colours.
	 * @param string   $shape   Shape id.
	 * @param string   $text    Text (textMode ai) or ''.
	 * @param string   $fabric  Fabric hex.
	 * @param int      $variant Variant number (frame thickness).
	 */
	private function compose( array $tpl, array $c, string $shape, string $text, string $fabric, int $variant ): string {
		$raw = (string) file_get_contents( self::dir() . $tpl['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$in  = preg_match( '/<svg\b[^>]*>(.*)<\/svg>/is', $raw, $m ) ? $m[1] : '';
		$sw  = 18 + 2 * ( $variant % 3 );
		$in  = strtr(
			$in,
			array(
				'{{c1}}' => $c[0],
				'{{c2}}' => $c[1],
				'{{c3}}' => $c[2],
				'{{c4}}' => $c[3],
				'{{sw}}' => (string) $sw,
			)
		);
		$in  = (string) preg_replace( '/\{\{[a-z0-9]+\}\}/', $c[1], $in );
		$border = $c[2] === $fabric ? $c[3] : $c[2];
		$bw     = 22 + 10 * ( $variant % 3 );
		$frame  = $this->frame( $shape, $fabric, $border, $bw );
		$scale  = in_array( $shape, array( 'oval', 'rect' ), true ) ? 0.74 : 0.92;
		$cy     = 470;
		if ( '' !== $text ) {
			$scale *= 0.82;
			$cy     = 430;
		}
		$motif = '<g transform="translate(512 ' . $cy . ') scale(' . $scale . ') translate(-512 -460)">' . $in . '</g>';
		$label = $this->text( $text, $shape, $this->luma( $fabric ) < 0.5 ? '#ffffff' : '#1b1b1b' );
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024" width="1024" height="1024" data-mock="' . htmlspecialchars( (string) $tpl['id'], ENT_XML1 ) . '">'
			. '<title>' . htmlspecialchars( 'Voorbeeldontwerp (test): ' . $tpl['id'], ENT_XML1 ) . '</title>'
			. $frame . $motif . $label . '</svg>';
	}

	/**
	 * Patch body in the chosen shape (fabric fill + border).
	 *
	 * @param string $shape  Shape.
	 * @param string $fill   Fill.
	 * @param string $border Border colour.
	 * @param int    $bw     Border width.
	 */
	private function frame( string $shape, string $fill, string $border, int $bw ): string {
		$a = ' fill="' . $fill . '" stroke="' . $border . '" stroke-width="' . $bw . '" stroke-linejoin="round"';
		switch ( $shape ) {
			case 'oval':
				return '<ellipse cx="512" cy="512" rx="480" ry="370"' . $a . '/>';
			case 'rect':
				return '<rect x="40" y="152" width="944" height="720" rx="10"' . $a . '/>';
			case 'rounded':
				return '<rect x="52" y="52" width="920" height="920" rx="130"' . $a . '/>';
			case 'shield':
				return '<path d="M512 40 L930 140 L912 560 C892 800 700 930 512 990 C324 930 132 800 112 560 L94 140 Z"' . $a . '/>';
			case 'contour':
				return '';
			default:
				return '<circle cx="512" cy="512" r="470"' . $a . '/>';
		}
	}

	/**
	 * Text along the bottom edge (circle) or as a straight line.
	 *
	 * @param string $text  Text.
	 * @param string $shape Shape.
	 * @param string $fill  Fill.
	 */
	private function text( string $text, string $shape, string $fill ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return '';
		}
		$t    = htmlspecialchars( mb_strtoupper( $text ), ENT_XML1 );
		$font = ' font-family="Arial, Helvetica, sans-serif" font-weight="700" font-size="' . ( mb_strlen( $text ) > 14 ? 56 : 72 ) . '" fill="' . $fill . '" letter-spacing="4"';
		if ( 'circle' === $shape ) {
			return '<defs><path id="bps-mock-arc" d="M 182 600 A 330 330 0 0 0 842 600"/></defs>'
				. '<text' . $font . ' text-anchor="middle"><textPath href="#bps-mock-arc" startOffset="50%">' . $t . '</textPath></text>';
		}
		return '<text x="512" y="880"' . $font . ' text-anchor="middle">' . $t . '</text>';
	}

	/**
	 * Relative luminance 0..1.
	 *
	 * @param string $hex Hex.
	 */
	private function luma( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		if ( 6 !== strlen( $hex ) ) {
			return 0.0;
		}
		list( $r, $g, $b ) = array_map( 'hexdec', str_split( $hex, 2 ) );
		return ( 0.2126 * $r + 0.7152 * $g + 0.0722 * $b ) / 255;
	}

	/**
	 * Swap the first two distinct fill colours.
	 *
	 * @param string $svg SVG.
	 */
	private function swap_first_colors( string $svg ): string {
		preg_match_all( '/fill="(#[0-9a-f]{6})"/i', $svg, $m );
		$u = array_values( array_unique( array_map( 'strtolower', $m[1] ) ) );
		if ( count( $u ) < 3 ) {
			return $svg;
		}
		// Keep the patch body (first fill), swap the next two.
		return strtr(
			$svg,
			array(
				$u[1] => $u[2],
				$u[2] => $u[1],
			)
		);
	}

	/**
	 * Image row; rasterised to PNG when mock.png is on and Imagick is present.
	 *
	 * @param string $svg SVG.
	 */
	private function image( string $svg ): array {
		if ( ! empty( AI_Settings::path( 'mock.png', false ) ) && class_exists( '\Imagick' ) ) {
			try {
				$im = new \Imagick();
				$im->setBackgroundColor( new \ImagickPixel( 'transparent' ) );
				$im->readImageBlob( $svg );
				$im->setImageFormat( 'png32' );
				$im->resizeImage( 1024, 1024, \Imagick::FILTER_LANCZOS, 1 );
				$png = $im->getImageBlob();
				$im->clear();
				return array(
					'bytes' => $png,
					'mime'  => 'image/png',
					'w'     => 1024,
					'h'     => 1024,
				);
			} catch ( \Throwable $e ) {
				unset( $e ); // Fall back to SVG.
			}
		}
		return array(
			'bytes' => $svg,
			'mime'  => 'image/svg+xml',
			'w'     => 1024,
			'h'     => 1024,
		);
	}

	/**
	 * Test hooks and failures.
	 *
	 * @param array $req Request.
	 * @throws Provider_Error On fail/policy/timeout hooks.
	 */
	private function hooks( array $req ): void {
		$hook = (string) ( $req['test_hook'] ?? '' );
		if ( '' === $hook || ! AI_Settings::test_hooks_allowed() ) {
			return;
		}
		switch ( $hook ) {
			case 'fail':
				throw new Provider_Error( 'unavailable', 'mock: [[mock:fail]]' );
			case 'policy':
				throw new Provider_Error( 'policy', 'mock: [[mock:policy]]' );
			case 'timeout':
				throw new Provider_Error( 'timeout', 'mock: [[mock:timeout]]' );
			case 'slow':
				usleep( 1000 * max( 0, min( 60000, (int) AI_Settings::path( 'mock.slowMs', 8000 ) ) ) );
				break;
		}
	}

	/**
	 * Realistic latency for the demo.
	 */
	private function sleep(): void {
		$ms = null !== self::$latency_override ? self::$latency_override : (int) AI_Settings::path( 'mock.latencyMs', 1200 );
		if ( $ms > 0 ) {
			usleep( 1000 * min( 10000, $ms ) );
		}
	}

	/**
	 * Remove our marking block so a re-marked edit does not carry the old one twice.
	 *
	 * @param string $svg SVG.
	 */
	public static function strip_marking( string $svg ): string {
		return (string) preg_replace( '#<metadata id="bps-ai-marking">.*?</metadata>(<desc>.*?</desc>)?#s', '', $svg );
	}

	/**
	 * Mock config.
	 */
	public static function cfg(): array {
		if ( null === self::$cfg ) {
			$cfg       = AI_Settings::read_json( 'ai-mock.json' );
			self::$cfg = $cfg;
		}
		return self::$cfg;
	}

	/**
	 * Template directory.
	 */
	public static function dir(): string {
		return AI_Settings::config_dir() . 'ai-mock/';
	}
}
