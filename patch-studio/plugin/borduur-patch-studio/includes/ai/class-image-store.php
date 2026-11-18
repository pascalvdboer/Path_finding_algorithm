<?php
/**
 * Private storage of generated images, AI marking (PNG iTXt + XMP, SVG metadata) and signed URLs.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §3.3 and §4.2 (`GET /ai/image/{id}/{index}?exp=&sig=`).
 *
 * Files live in `uploads/patch-studio/ai/{yyyy}/{mm}/{job}-{index}.png|svg`, the directory is closed with index.php
 * and .htaccess (nginx: see the admin status block). File names contain no customer data. The untouched provider PNG
 * (with its C2PA manifest) is kept as `{job}-{index}.orig.png` next to the marked copy.
 */
final class Image_Store {

	const SUBDIR  = 'patch-studio/ai';
	const MAX_BYTES = 20 * 1024 * 1024;
	const URL_TTL = HOUR_IN_SECONDS;

	/** @var string|null Base dir override (tests). */
	public static $base_override = null;

	/**
	 * Absolute base directory with trailing slash.
	 */
	public static function base_dir(): string {
		if ( null !== self::$base_override ) {
			return rtrim( self::$base_override, '/' ) . '/';
		}
		$up = wp_upload_dir( null, false );
		return rtrim( (string) $up['basedir'], '/' ) . '/' . self::SUBDIR . '/';
	}

	/**
	 * Create the directory tree and its protection files.
	 *
	 * @param string $dir Directory.
	 */
	public static function ensure_dir( string $dir ): bool {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$base = self::base_dir();
		foreach ( array( $base, $dir ) as $d ) {
			if ( ! file_exists( $d . 'index.php' ) ) {
				@file_put_contents( $d . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
			}
		}
		if ( ! file_exists( $base . '.htaccess' ) ) {
			@file_put_contents( $base . '.htaccess', "# Patch Studio AI: images are served only through signed REST URLs.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n" ); // phpcs:ignore
		}
		return is_dir( $dir ) && wp_is_writable( $dir );
	}

	/**
	 * Store one generated image (marked) and, for raster provider output, the original.
	 *
	 * @param string $job_id   Job id.
	 * @param int    $index    Proposal index.
	 * @param string $bytes    Image bytes.
	 * @param string $provider Provider id.
	 * @param string $model    Model id.
	 * @param string $variant  ''|'svg' (vectorised copy of a chosen proposal).
	 * @return array{index:int, file:string, mime:string, w:int, h:int, sha1:string, bytes:int}|null
	 */
	public static function save( string $job_id, int $index, string $bytes, string $provider, string $model, string $variant = '' ): ?array {
		if ( ! preg_match( '/^[0-9A-Z]{26}$/', $job_id ) || $index < 0 || $index > 15 || '' === $bytes || strlen( $bytes ) > self::MAX_BYTES ) {
			return null;
		}
		$mime = Providers\Provider_Result::sniff_mime( $bytes );
		if ( 'image/png' === $mime ) {
			$ext = 'png';
			list( $w, $h ) = Providers\Provider_Result::png_size( $bytes );
			$marked        = self::mark_png( $bytes, $provider, $model );
		} elseif ( 'image/svg+xml' === $mime ) {
			$ext    = 'svg';
			$clean  = self::sanitize_svg( $bytes );
			if ( null === $clean ) {
				return null;
			}
			list( $w, $h ) = self::svg_size( $clean );
			$marked        = self::mark_svg( $clean, $provider, $model );
		} else {
			return null; // Only PNG and SVG are accepted (WebP/JPEG are not requested from providers).
		}
		$rel = gmdate( 'Y' ) . '/' . gmdate( 'm' ) . '/';
		$dir = self::base_dir() . $rel;
		if ( ! self::ensure_dir( $dir ) ) {
			return null;
		}
		$name = $job_id . '-' . $index . ( 'svg' === $variant ? '.vector' : '' ) . '.' . $ext;
		if ( false === file_put_contents( $dir . $name, $marked, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return null;
		}
		$legal = Module::legal();
		if ( 'png' === $ext && 'mock' !== $provider && ! empty( $legal['marking']['keepProviderC2pa'] ) ) {
			file_put_contents( $dir . $job_id . '-' . $index . '.orig.png', $bytes, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return array(
			'index' => $index,
			'file'  => $rel . $name,
			'mime'  => $mime,
			'w'     => (int) $w,
			'h'     => (int) $h,
			'sha1'  => sha1( $marked ),
			'bytes' => strlen( $marked ),
		);
	}

	/**
	 * Absolute path of a stored relative file, or '' when invalid/missing.
	 *
	 * @param string $rel Relative path from save().
	 */
	public static function path( string $rel ): string {
		if ( ! preg_match( '#^\d{4}/\d{2}/[0-9A-Z]{26}-\d{1,2}(\.vector|\.orig)?\.(png|svg)$#', $rel ) ) {
			return '';
		}
		$abs = self::base_dir() . $rel;
		return is_file( $abs ) ? $abs : '';
	}

	/**
	 * Best source for an AI edit: the provider original if kept, else the marked file.
	 *
	 * @param string $rel Relative path.
	 */
	public static function edit_source( string $rel ): string {
		$orig = preg_replace( '/\.png$/', '.orig.png', $rel );
		$p    = is_string( $orig ) ? self::path( $orig ) : '';
		return '' !== $p ? $p : self::path( $rel );
	}

	/**
	 * Delete all files of a job.
	 *
	 * @param array $files Result rows ({file}) of the job.
	 */
	public static function delete_files( array $files ): int {
		$n = 0;
		foreach ( $files as $f ) {
			$rel = is_array( $f ) ? (string) ( $f['file'] ?? '' ) : (string) $f;
			foreach ( array( $rel, (string) preg_replace( '/\.(png|svg)$/', '.orig.png', $rel ), (string) preg_replace( '/\.(png|svg)$/', '.vector.svg', $rel ) ) as $candidate ) {
				$p = self::path( $candidate );
				if ( '' !== $p && @unlink( $p ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					++$n;
				}
			}
		}
		return $n;
	}

	/* ---------------------------------------------------------------------
	 * Signed URLs
	 * ------------------------------------------------------------------- */

	/**
	 * Signed image URL (1 h).
	 *
	 * @param string $job_id  Job id.
	 * @param int    $index   Index.
	 * @param string $variant ''|'svg'.
	 */
	public static function signed_url( string $job_id, int $index, string $variant = '' ): string {
		$exp  = ( (int) floor( time() / 300 ) * 300 ) + self::URL_TTL + 300; // Stable for 5 min → browser cache hits.
		$args = array(
			'exp' => $exp,
			'sig' => self::sig( $job_id, $index, $exp, $variant ),
		);
		if ( 'svg' === $variant ) {
			$args = array( 'v' => 'svg' ) + $args;
		}
		return add_query_arg( $args, rest_url( Rest::NS . '/ai/image/' . $job_id . '/' . $index ) );
	}

	/**
	 * Signature.
	 *
	 * @param string $job_id  Job id.
	 * @param int    $index   Index.
	 * @param int    $exp     Expiry.
	 * @param string $variant Variant.
	 */
	public static function sig( string $job_id, int $index, int $exp, string $variant = '' ): string {
		return hash_hmac( 'sha256', $job_id . '|' . $index . '|' . $exp . ( '' !== $variant ? '|' . $variant : '' ), wp_salt( 'auth' ) );
	}

	/**
	 * Verify a signature and its expiry.
	 *
	 * @param string $job_id  Job id.
	 * @param int    $index   Index.
	 * @param int    $exp     Expiry.
	 * @param string $sig     Signature.
	 * @param string $variant Variant.
	 */
	public static function verify( string $job_id, int $index, int $exp, string $sig, string $variant = '' ): bool {
		if ( $exp < time() || $exp > time() + self::URL_TTL + 600 ) {
			return false;
		}
		return hash_equals( self::sig( $job_id, $index, $exp, $variant ), $sig );
	}

	/* ---------------------------------------------------------------------
	 * AI marking (04 §1.2)
	 * ------------------------------------------------------------------- */

	/**
	 * Insert an iTXt "Description" chunk and an XMP packet (IPTC DigitalSourceType) right after IHDR.
	 * Bytes are returned unchanged when they are not a valid PNG.
	 *
	 * @param string $png      PNG bytes.
	 * @param string $provider Provider id.
	 * @param string $model    Model.
	 */
	public static function mark_png( string $png, string $provider, string $model ): string {
		if ( "\x89PNG\r\n\x1a\n" !== substr( $png, 0, 8 ) || strlen( $png ) < 33 || 'IHDR' !== substr( $png, 12, 4 ) ) {
			return $png;
		}
		$ihdr_len = unpack( 'N', substr( $png, 8, 4 ) )[1];
		$cut      = 8 + 12 + $ihdr_len;
		$comment  = self::comment( $provider, $model );
		$chunks   = self::itxt( 'Description', $comment ) . self::itxt( 'XML:com.adobe.xmp', self::xmp( self::source_type(), $comment ) );
		return substr( $png, 0, $cut ) . $chunks . substr( $png, $cut );
	}

	/**
	 * Add a <metadata> block with the same marking to an SVG.
	 *
	 * @param string $svg      SVG.
	 * @param string $provider Provider id.
	 * @param string $model    Model.
	 */
	public static function mark_svg( string $svg, string $provider, string $model ): string {
		if ( false !== strpos( $svg, 'bps-ai-marking' ) ) {
			return $svg;
		}
		$comment = self::comment( $provider, $model );
		$meta    = '<metadata id="bps-ai-marking">' . self::xmp( self::source_type(), $comment, false ) . '</metadata><desc>' . htmlspecialchars( $comment, ENT_XML1 ) . '</desc>';
		$out     = preg_replace( '/(<svg\b[^>]*>)/i', '$1' . $meta, $svg, 1 );
		return is_string( $out ) ? $out : $svg;
	}

	/**
	 * Marking comment from ai-legal.json.
	 *
	 * @param string $provider Provider id.
	 * @param string $model    Model.
	 */
	public static function comment( string $provider, string $model ): string {
		$legal = Module::legal();
		$tpl   = (string) ( $legal['marking']['pngTextComment'] ?? 'AI-generated (Patch Studio, {provider}/{model})' );
		return strtr(
			$tpl,
			array(
				'{provider}' => preg_replace( '/[^a-z0-9_.-]/i', '', $provider ),
				'{model}'    => preg_replace( '/[^a-z0-9_.-]/i', '', $model ),
			)
		);
	}

	/**
	 * IPTC digital source type URI.
	 */
	private static function source_type(): string {
		$legal = Module::legal();
		return (string) ( $legal['marking']['iptcDigitalSourceType'] ?? 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia' );
	}

	/**
	 * PNG iTXt chunk (uncompressed).
	 *
	 * @param string $keyword Keyword (Latin-1, 1–79 chars).
	 * @param string $text    UTF-8 text.
	 */
	private static function itxt( string $keyword, string $text ): string {
		$data = $keyword . "\0" . "\0" . "\0" . "\0" . "\0" . $text; // keyword, null, compression flag 0, method 0, empty lang, empty translated keyword.
		return pack( 'N', strlen( $data ) ) . 'iTXt' . $data . pack( 'N', crc32( 'iTXt' . $data ) );
	}

	/**
	 * Minimal XMP packet.
	 *
	 * @param string $source  Digital source type.
	 * @param string $comment Description.
	 * @param bool   $wrap    Add the xpacket wrapper.
	 */
	private static function xmp( string $source, string $comment, bool $wrap = true ): string {
		$x = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/" xmlns:dc="http://purl.org/dc/elements/1.1/">'
			. '<Iptc4xmpExt:DigitalSourceType>' . htmlspecialchars( $source, ENT_XML1 ) . '</Iptc4xmpExt:DigitalSourceType>'
			. '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">' . htmlspecialchars( $comment, ENT_XML1 ) . '</rdf:li></rdf:Alt></dc:description>'
			. '</rdf:Description></rdf:RDF></x:xmpmeta>';
		return $wrap ? '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>' . $x . '<?xpacket end="r"?>' : $x;
	}

	/* ---------------------------------------------------------------------
	 * SVG helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Sanitize SVG from a provider (existing Woo\Svg_Sanitizer when available, else a strict fallback).
	 *
	 * @param string $svg SVG.
	 */
	public static function sanitize_svg( string $svg ): ?string {
		if ( class_exists( '\BorduurPatchStudio\Woo\Svg_Sanitizer' ) && method_exists( '\BorduurPatchStudio\Woo\Svg_Sanitizer', 'sanitize' ) ) {
			$clean = \BorduurPatchStudio\Woo\Svg_Sanitizer::sanitize( $svg );
			if ( is_string( $clean ) && '' !== $clean ) {
				return $clean;
			}
			if ( is_wp_error( $clean ) || false === $clean ) {
				return null;
			}
		}
		if ( preg_match( '/<\s*(script|foreignObject|iframe|object|embed)\b|\bon[a-z]+\s*=|javascript:|<!ENTITY|xlink:href\s*=\s*["\'](?!#)|href\s*=\s*["\'](?!#)/i', $svg ) ) {
			return null;
		}
		$svg = preg_replace( '/<\?xml[^>]*\?>|<!DOCTYPE[^>]*>/i', '', $svg );
		return is_string( $svg ) && false !== stripos( $svg, '<svg' ) ? trim( $svg ) : null;
	}

	/**
	 * Width/height from the viewBox or width/height attributes.
	 *
	 * @param string $svg SVG.
	 * @return int[]
	 */
	public static function svg_size( string $svg ): array {
		if ( preg_match( '/viewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $svg, $m ) ) {
			return array( (int) round( (float) $m[1] ), (int) round( (float) $m[2] ) );
		}
		$w = preg_match( '/\bwidth\s*=\s*["\']([\d.]+)/i', $svg, $mw ) ? (int) $mw[1] : 1024;
		$h = preg_match( '/\bheight\s*=\s*["\']([\d.]+)/i', $svg, $mh ) ? (int) $mh[1] : 1024;
		return array( $w, $h );
	}

	/**
	 * Stream a stored file and exit (signed route).
	 *
	 * @param string $abs  Absolute path.
	 * @param string $mime Mime.
	 * @param string $name Download name.
	 */
	public static function stream( string $abs, string $mime, string $name ): void {
		nocache_headers();
		header_remove( 'Expires' );
		header_remove( 'Pragma' );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) filesize( $abs ) );
		header( 'Cache-Control: private, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: inline; filename="' . preg_replace( '/[^A-Za-z0-9._-]/', '', $name ) . '"' );
		header( 'Cross-Origin-Resource-Policy: same-origin' );
		if ( 'image/svg+xml' === $mime ) {
			header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:" );
		}
		readfile( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
