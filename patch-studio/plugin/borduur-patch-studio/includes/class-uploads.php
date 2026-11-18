<?php
/**
 * Private storage for customer files and public, re-encoded previews.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio;

use BorduurPatchStudio\Woo\Config;
use BorduurPatchStudio\Woo\Svg_Sanitizer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Layout (SPEC §6.1, research 06 §7):
 *   uploads/patch-studio/private/YYYY/MM/<32 chars>.<ext>   originals, denied by .htaccess, unguessable names
 *   uploads/patch-studio/previews/<32 chars>.png            public PNG re-encoded server side (EXIF/polyglots stripped)
 *   uploads/patch-studio/previews/<32 chars>-300.webp       light variants for cart / mini-cart / Store API (prestatie-1)
 *   uploads/patch-studio/previews/<32 chars>-600.webp       2x variant
 *   uploads/patch-studio/previews/<32 chars>-600.jpg        e-mail variant on white (mail clients and webp do not mix)
 *
 * Storage ceiling (security-3): Uploads::check_storage() refuses a new original (507 bps_storage) when the disk is
 * almost full or when the originals of draft/carted designs together exceed woo.maxDraftStorageMb. The byte counter
 * is a non-autoloaded option, kept up to date by Woo\Designs (store, delete, status upgrade).
 */
final class Uploads {

	const DIR          = 'patch-studio';
	const PREVIEW_MAX  = 1200;
	const PREVIEW_MIN  = 600;
	const MAX_PIXELS   = 16777216; // decompression-bomb guard for previews (4096 x 4096; the UI sends 1200 px).
	const SELFTEST_KEY = 'bps_uploads_selftest';

	/** Non-autoloaded option with the bytes of originals of draft/carted designs (security-3). */
	const STORAGE_OPTION = 'bps_draft_storage_bytes';

	/** Transient that limits the "storage full" admin mail to one per day. */
	const STORAGE_ALERT = 'bps_storage_alert';

	/** Default ceiling for originals of not (yet) ordered designs, in MB (woo.maxDraftStorageMb). */
	const DEFAULT_DRAFT_STORAGE_MB = 2048;

	/** Light preview variants: key => [longest side, format]. 'mail' is always JPEG on white. */
	const VARIANTS = array(
		'cart'   => array( 300, 'webp' ),
		'cart2x' => array( 600, 'webp' ),
		'mail'   => array( 600, 'jpg' ),
	);

	/** Existing embroidery machine files (concurrentie-features-8): stored privately, max 5 MB. */
	const EMB_TYPES     = array( 'dst', 'pes', 'exp', 'jef' );
	const EMB_MAX_BYTES = 5242880;

	/** Sniffed type → MIME (what we tell the admin download / the browser). */
	const TYPES = array(
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'webp' => 'image/webp',
		'gif'  => 'image/gif',
		'svg'  => 'image/svg+xml',
		'pdf'  => 'application/pdf',
		'ai'   => 'application/pdf',
		'eps'  => 'application/postscript',
		'psd'  => 'image/vnd.adobe.photoshop',
		'dst'  => 'application/octet-stream',
		'pes'  => 'application/octet-stream',
		'exp'  => 'application/octet-stream',
		'jef'  => 'application/octet-stream',
	);

	/** Types the browser can display (used for the temporary "restore design" URL). */
	const BROWSER_TYPES = array( 'png', 'jpg', 'webp', 'svg', 'gif' );

	/**
	 * What the storage layer keeps outside the upload dir (contract C2; collected by Woo\Designs::owned_data()).
	 *
	 * @return array<string, string[]>
	 */
	public static function owned_data(): array {
		return array(
			'options'        => array( self::STORAGE_OPTION ),
			'always_options' => array( self::SELFTEST_KEY ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Paths
	 * ------------------------------------------------------------------- */

	/**
	 * Base dir with trailing slash.
	 */
	public static function base_dir(): string {
		$u = wp_upload_dir( null, false );
		return trailingslashit( $u['basedir'] ) . self::DIR . '/';
	}

	/**
	 * Base URL with trailing slash.
	 */
	public static function base_url(): string {
		$u = wp_upload_dir( null, false );
		return trailingslashit( set_url_scheme( $u['baseurl'] ) ) . self::DIR . '/';
	}

	/**
	 * Private dir (trailing slash).
	 */
	public static function private_dir(): string {
		return self::base_dir() . 'private/';
	}

	/**
	 * Preview dir (trailing slash).
	 */
	public static function preview_dir(): string {
		return self::base_dir() . 'previews/';
	}

	/**
	 * Public URL of a preview file path.
	 *
	 * @param string $path Absolute path inside the preview dir.
	 */
	public static function preview_url_for( string $path ): string {
		return self::base_url() . 'previews/' . rawurlencode( basename( $path ) );
	}

	/**
	 * Create directories and protection files (idempotent).
	 */
	public static function ensure_dirs(): bool {
		$private = self::private_dir();
		$preview = self::preview_dir();
		if ( ! wp_mkdir_p( $private ) || ! wp_mkdir_p( $preview ) ) {
			return false;
		}
		$deny = "# Patch Studio - private customer files, only reachable through the admin download.\n"
			. "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n"
			. "Options -Indexes\n";
		// Random, never reused file names: a preview never changes, so browsers and CDNs may keep it for a year.
		$prev = "# Patch Studio - public patch previews (PNG master + WEBP/JPG variants).\n"
			. "Options -Indexes -ExecCGI\n"
			. "<FilesMatch \"\\.(?!(png|webp|jpg)$)[^.]+$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n  <IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n  </IfModule>\n</FilesMatch>\n"
			. "<IfModule mod_mime.c>\n  AddType image/webp .webp\n</IfModule>\n"
			. "<IfModule mod_headers.c>\n  Header set X-Robots-Tag \"noindex, nofollow\"\n  Header set X-Content-Type-Options \"nosniff\"\n  <FilesMatch \"\\.(png|webp|jpg)$\">\n    Header set Cache-Control \"public, max-age=31536000, immutable\"\n  </FilesMatch>\n</IfModule>\n";
		$webconfig = '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>';
		self::write_if_changed( $private . '.htaccess', $deny );
		self::write_if_changed( $private . 'web.config', $webconfig );
		self::write_if_changed( $private . 'index.php', "<?php\n// Silence is golden.\n" );
		self::write_if_changed( $preview . '.htaccess', $prev );
		self::write_if_changed( $preview . 'index.php', "<?php\n// Silence is golden.\n" );
		self::write_if_changed( self::base_dir() . 'index.php', "<?php\n// Silence is golden.\n" );
		return true;
	}

	/**
	 * Write a small file if missing or different.
	 *
	 * @param string $file    Path.
	 * @param string $content Content.
	 */
	private static function write_if_changed( string $file, string $content ): void {
		if ( is_readable( $file ) && (string) file_get_contents( $file ) === $content ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return;
		}
		file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/* ---------------------------------------------------------------------
	 * Limits and type detection
	 * ------------------------------------------------------------------- */

	/**
	 * Max upload size in bytes: woo.maxUploadMb, capped by PHP/WP limits, filterable.
	 */
	public static function max_bytes(): int {
		$mb    = (float) Config::path( 'woo.maxUploadMb', 25 );
		$bytes = (int) round( max( 1, $mb ) * MB_IN_BYTES );
		$wp    = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
		if ( $wp > 0 ) {
			$bytes = min( $bytes, $wp );
		}
		return (int) apply_filters( 'bps_upload_max_bytes', $bytes );
	}

	/**
	 * Detect the file type from its content (never trust extension or browser MIME).
	 *
	 * Embroidery files: PES and DST have a real signature; JEF has a structural header (stitch offset + 14-digit
	 * date); EXP has no header at all, so it is only recognised when the customer's extension says .exp and the whole
	 * file is a valid EXP stitch stream.
	 *
	 * @param string $path File path.
	 * @param string $hint Extension of the customer's file name (only used for EXP).
	 * @return string|null png|jpg|webp|gif|svg|pdf|eps|psd|dst|pes|jef|exp
	 */
	public static function sniff( string $path, string $hint = '' ): ?string {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$h = (string) file_get_contents( $path, false, null, 0, 64 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( 0 === strpos( $h, "\x89PNG\r\n\x1a\n" ) ) {
			return 'png';
		}
		if ( 0 === strpos( $h, "\xFF\xD8\xFF" ) ) {
			return 'jpg';
		}
		if ( 0 === strpos( $h, 'RIFF' ) && 'WEBP' === substr( $h, 8, 4 ) ) {
			return 'webp';
		}
		if ( 0 === strpos( $h, 'GIF87a' ) || 0 === strpos( $h, 'GIF89a' ) ) {
			return 'gif';
		}
		if ( 0 === strpos( $h, '%PDF-' ) ) {
			return 'pdf';
		}
		if ( 0 === strpos( $h, '%!PS' ) || 0 === strpos( $h, "\xC5\xD0\xD3\xC6" ) ) {
			return 'eps';
		}
		if ( 0 === strpos( $h, '8BPS' ) ) {
			return 'psd';
		}
		$emb = self::sniff_embroidery( $path, $h, strtolower( $hint ) );
		if ( null !== $emb ) {
			return $emb;
		}
		$head = (string) file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$head = strtolower( ltrim( $head, "\xEF\xBB\xBF \t\r\n" ) );
		if ( ( 0 === strpos( $head, '<?xml' ) || 0 === strpos( $head, '<svg' ) || 0 === strpos( $head, '<!--' ) || 0 === strpos( $head, '<!doctype svg' ) ) && false !== strpos( $head, '<svg' ) ) {
			return 'svg';
		}
		return null;
	}

	/**
	 * Recognise machine embroidery files by their structure (never by extension alone).
	 *
	 * @param string $path File path.
	 * @param string $head First 64 bytes.
	 * @param string $hint Lower-case extension of the customer's file name.
	 */
	private static function sniff_embroidery( string $path, string $head, string $hint ): ?string {
		$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $size < 4 ) {
			return null;
		}
		// PES (Brother/Babylock): "#PES" + 4-digit version, then a 4-byte offset to the PEC block inside the file.
		if ( 0 === strpos( $head, '#PES' ) && strlen( $head ) >= 12 && ctype_digit( substr( $head, 4, 4 ) ) ) {
			$pec = unpack( 'V', substr( $head, 8, 4 ) );
			return ( is_array( $pec ) && $pec[1] > 0 && $pec[1] < $size ) ? 'pes' : null;
		}
		// DST (Tajima): 512-byte ASCII header "LA:…\rST:…\rCO:…\r+X:…" (Emb_File parses it).
		if ( 0 === strpos( $head, 'LA:' ) && $size >= 512 ) {
			$hdr = (string) file_get_contents( $path, false, null, 0, 512 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return null !== Emb_File::parse_dst_header( $hdr ) ? 'dst' : null;
		}
		// JEF (Janome): uint32 stitch offset, uint32 flags, then a 14-digit date "YYYYMMDDHHMMSS", colour count < 256.
		if ( strlen( $head ) >= 32 && ctype_digit( substr( $head, 8, 14 ) ) ) {
			$v = unpack( 'Voffset/Vflags', substr( $head, 0, 8 ) );
			$c = unpack( 'Vcolors', substr( $head, 24, 4 ) );
			$y = (int) substr( $head, 8, 4 );
			if ( is_array( $v ) && is_array( $c ) && $v['offset'] >= 116 && $v['offset'] < $size && $c['colors'] < 256 && $y >= 1980 && $y <= 2100 ) {
				return 'jef';
			}
			return null;
		}
		// EXP (Melco): no header. Only with the .exp extension and a valid stitch stream (pairs of signed bytes,
		// 0x80 introduces a command 0x01 colour change, 0x02 or 0x04 jump, 0x80 end).
		if ( 'exp' === $hint && 0 === $size % 2 ) {
			// The size limit is enforced by the caller with a clear message; scanning the first 5 MB is enough.
			$data = (string) file_get_contents( $path, false, null, 0, self::EMB_MAX_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$len  = strlen( $data );
			for ( $i = 0; $i < $len; $i += 2 ) {
				if ( "\x80" === $data[ $i ] && ! in_array( ord( $data[ $i + 1 ] ), array( 0x01, 0x02, 0x04, 0x80 ), true ) ) {
					return null;
				}
			}
			return 'exp';
		}
		return null;
	}

	/* ---------------------------------------------------------------------
	 * Storing
	 * ------------------------------------------------------------------- */

	/**
	 * Validate and store the customer's original file in the private dir.
	 *
	 * @param array $file One entry of $_FILES (name, tmp_name, size, error).
	 * @return array|WP_Error {path, name, ext, bytes}
	 */
	public static function store_original( array $file ) {
		$err = self::check_upload( $file );
		if ( is_wp_error( $err ) ) {
			return $err;
		}
		$tmp   = (string) $file['tmp_name'];
		$bytes = (int) filesize( $tmp );
		if ( $bytes > self::max_bytes() ) {
			return self::too_big();
		}
		$orig_name = self::clean_name( (string) ( $file['name'] ?? '' ) );
		$orig_ext  = strtolower( (string) pathinfo( $orig_name, PATHINFO_EXTENSION ) );
		$type      = self::sniff( $tmp, $orig_ext );
		if ( in_array( $orig_ext, self::EMB_TYPES, true ) && $type !== $orig_ext ) {
			// A renamed image or a damaged file: say so instead of storing something the machine cannot read.
			return new WP_Error(
				'bps_type',
				sprintf(
					/* translators: %s: file type, e.g. PES */
					__( 'Dit is geen geldig %s-borduurbestand. Bewaar het opnieuw vanuit je borduursoftware, of stuur het ons gewoon per e-mail.', 'borduur-patch-studio' ),
					strtoupper( $orig_ext )
				),
				array( 'status' => 415 )
			);
		}
		if ( null === $type ) {
			return new WP_Error(
				'bps_type',
				__( 'Dit bestandstype kunnen we niet lezen. Gebruik PNG, JPG, WEBP, SVG, PDF, AI, EPS, PSD of een borduurbestand (DST, PES, EXP, JEF).', 'borduur-patch-studio' ),
				array( 'status' => 415 )
			);
		}
		if ( in_array( $type, self::EMB_TYPES, true ) && $bytes > self::EMB_MAX_BYTES ) {
			return new WP_Error(
				'bps_too_big',
				__( 'Een borduurbestand mag maximaal 5 MB groot zijn. Stuur een groter bestand gerust per e-mail.', 'borduur-patch-studio' ),
				array( 'status' => 413 )
			);
		}
		// Keep the customer's .ai extension (PDF- or PostScript-based) so the digitiser opens it in Illustrator.
		$ext = ( 'ai' === $orig_ext && in_array( $type, array( 'pdf', 'eps' ), true ) ) ? 'ai' : $type;

		if ( ! self::ensure_dirs() ) {
			return self::store_failed();
		}
		$dir = self::private_dir() . gmdate( 'Y/m/' );
		if ( ! wp_mkdir_p( $dir ) ) {
			return self::store_failed();
		}
		$dest = $dir . self::random_name() . '.' . $ext;

		if ( 'svg' === $type ) {
			$clean = Svg_Sanitizer::sanitize( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $clean ) {
				return new WP_Error(
					'bps_type',
					__( 'Dit SVG-bestand kunnen we niet veilig openen. Bewaar het opnieuw als PNG of PDF, of stuur het ons gewoon per e-mail.', 'borduur-patch-studio' ),
					array( 'status' => 415 )
				);
			}
			if ( false === file_put_contents( $dest, $clean ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return self::store_failed();
			}
			$bytes = strlen( $clean );
		} elseif ( ! self::move( $tmp, $dest ) ) {
			return self::store_failed();
		}
		@chmod( $dest, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

		return array(
			'path'  => $dest,
			'name'  => '' !== $orig_name ? $orig_name : 'ontwerp.' . $ext,
			'ext'   => $ext,
			'bytes' => $bytes,
		);
	}

	/**
	 * Re-encode the browser-made preview PNG (max 1200 px) into the public preview dir, plus light variants
	 * (prestatie-1): -300.webp and -600.webp for cart / mini-cart / Store API and -600.jpg on white for e-mails.
	 * Without imagewebp the cart variants are JPEG on white too. The PNG stays the production master.
	 *
	 * @param array $file One entry of $_FILES.
	 * @return array|WP_Error {path, url, sizes: {cart|cart2x|mail: {path, url, w, h, mime}}}
	 */
	public static function store_preview( array $file ) {
		$err = self::check_upload( $file );
		if ( is_wp_error( $err ) ) {
			return $err;
		}
		$tmp = (string) $file['tmp_name'];
		if ( filesize( $tmp ) > self::preview_max_bytes() ) {
			return new WP_Error( 'bps_too_big', __( 'Het voorbeeld van je patch is te groot om te bewaren. Laad de pagina opnieuw en probeer het nog eens.', 'borduur-patch-studio' ), array( 'status' => 413 ) );
		}
		if ( 'png' !== self::sniff( $tmp ) ) {
			return self::bad_preview();
		}
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || empty( $info[0] ) || empty( $info[1] ) ) {
			return self::bad_preview();
		}
		$w = (int) $info[0];
		$h = (int) $info[1];
		if ( $w * $h > self::MAX_PIXELS || max( $w, $h ) < self::PREVIEW_MIN ) {
			return self::bad_preview();
		}
		if ( ! self::ensure_dirs() ) {
			return self::store_failed();
		}
		$base = self::preview_dir() . self::random_name();
		$dest = $base . '.png';
		$im   = self::reencode_png( $tmp, $dest, $w, $h );
		if ( false === $im ) {
			return self::store_failed();
		}
		@chmod( $dest, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		$sizes = self::make_variants( $im, $base );
		if ( is_object( $im ) || is_resource( $im ) ) {
			imagedestroy( $im );
		}
		return array(
			'path'  => $dest,
			'url'   => self::preview_url_for( $dest ),
			'sizes' => $sizes,
		);
	}

	/**
	 * Max size of the browser-made preview PNG (8 MB, filter bps_preview_max_bytes; security-3).
	 */
	public static function preview_max_bytes(): int {
		return max( 1, (int) apply_filters( 'bps_preview_max_bytes', 8 * MB_IN_BYTES ) );
	}

	/**
	 * Decode + (only when larger than 1200 px) scale + encode with GD, alpha kept; falls back to WP_Image_Editor.
	 * Returns the decoded GD image (for the variants), true when only WP_Image_Editor was available, false on error.
	 *
	 * @param string $src  Source.
	 * @param string $dest Destination.
	 * @param int    $w    Width.
	 * @param int    $h    Height.
	 * @return \GdImage|resource|bool
	 */
	private static function reencode_png( string $src, string $dest, int $w, int $h ) {
		$scale = min( 1, self::PREVIEW_MAX / max( $w, $h ) );
		$nw    = max( 1, (int) round( $w * $scale ) );
		$nh    = max( 1, (int) round( $h * $scale ) );

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
		if ( function_exists( 'imagecreatefrompng' ) ) {
			$im = @imagecreatefrompng( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $im ) {
				return false;
			}
			if ( $scale < 1 ) {
				$out = imagecreatetruecolor( $nw, $nh );
				imagealphablending( $out, false );
				imagesavealpha( $out, true );
				imagefill( $out, 0, 0, imagecolorallocatealpha( $out, 0, 0, 0, 127 ) );
				imagecopyresampled( $out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h );
				imagedestroy( $im );
				$im = $out;
			} else {
				// Already small enough: no resampling, only a fresh encode (drops metadata and appended data).
				if ( ! imageistruecolor( $im ) ) {
					imagepalettetotruecolor( $im );
				}
				imagealphablending( $im, false );
				imagesavealpha( $im, true );
			}
			$ok = imagepng( $im, $dest, 7 );
			if ( ! $ok || 'png' !== self::sniff( $dest ) ) {
				imagedestroy( $im );
				return false;
			}
			return $im;
		}
		$ed = wp_get_image_editor( $src );
		if ( is_wp_error( $ed ) ) {
			return false;
		}
		if ( $scale < 1 ) {
			$ed->resize( $nw, $nh, false );
		}
		$res = $ed->save( $dest, 'image/png' );
		return ! is_wp_error( $res ) && 'png' === self::sniff( $dest );
	}

	/**
	 * Write the light variants next to the PNG master. Never upscales; a failed variant is simply left out (callers
	 * fall back to the PNG through Designs::preview_url()).
	 *
	 * @param mixed  $im   Decoded master (GD image) or anything else (no GD: no variants).
	 * @param string $base Path without extension.
	 * @return array<string, array{path:string,url:string,w:int,h:int,mime:string}>
	 */
	private static function make_variants( $im, string $base ): array {
		if ( ! ( is_object( $im ) || is_resource( $im ) ) || ! function_exists( 'imagecreatetruecolor' ) ) {
			return array();
		}
		$webp = self::webp_supported();
		$w    = imagesx( $im );
		$h    = imagesy( $im );
		$out  = array();
		foreach ( self::VARIANTS as $key => $spec ) {
			list( $max, $format ) = $spec;
			if ( 'webp' === $format && ! $webp ) {
				$format = 'jpg';
			}
			$scale = min( 1, $max / max( 1, $w, $h ) );
			$nw    = max( 1, (int) round( $w * $scale ) );
			$nh    = max( 1, (int) round( $h * $scale ) );
			$dest  = $base . '-' . $max . '.' . $format;
			$can   = imagecreatetruecolor( $nw, $nh );
			if ( 'jpg' === $format ) {
				imagefill( $can, 0, 0, imagecolorallocate( $can, 255, 255, 255 ) );
				imagealphablending( $can, true );
			} else {
				imagealphablending( $can, false );
				imagesavealpha( $can, true );
				imagefill( $can, 0, 0, imagecolorallocatealpha( $can, 0, 0, 0, 127 ) );
			}
			if ( $nw === $w && $nh === $h ) {
				imagecopy( $can, $im, 0, 0, 0, 0, $w, $h );
			} else {
				imagecopyresampled( $can, $im, 0, 0, 0, 0, $nw, $nh, $w, $h );
			}
			$ok = 'jpg' === $format ? imagejpeg( $can, $dest, 82 ) : imagewebp( $can, $dest, 80 );
			imagedestroy( $can );
			if ( ! $ok || ! is_file( $dest ) ) {
				continue;
			}
			@chmod( $dest, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			$out[ $key ] = array(
				'path' => $dest,
				'url'  => self::preview_url_for( $dest ),
				'w'    => $nw,
				'h'    => $nh,
				'mime' => 'jpg' === $format ? 'image/jpeg' : 'image/webp',
			);
		}
		return $out;
	}

	/**
	 * GD can write WEBP (filter bps_preview_webp, e.g. to test the JPEG fallback).
	 */
	public static function webp_supported(): bool {
		return (bool) apply_filters( 'bps_preview_webp', function_exists( 'imagewebp' ) );
	}

	/* ---------------------------------------------------------------------
	 * Copies (Designs::clone, contract C3)
	 * ------------------------------------------------------------------- */

	/**
	 * Copy a stored original to a new unguessable name (a clone never shares files: cleanup of one design must not
	 * break the other).
	 *
	 * @param array $original {path, name, ext, bytes}.
	 * @return array|null New {path, name, ext, bytes} or null when the source is gone.
	 */
	public static function copy_original( array $original ): ?array {
		$src = (string) ( $original['path'] ?? '' );
		if ( '' === $src || ! is_readable( $src ) || ! self::is_ours( $src, 'private' ) ) {
			return null;
		}
		$ext = strtolower( (string) ( $original['ext'] ?? pathinfo( $src, PATHINFO_EXTENSION ) ) );
		if ( ! isset( self::TYPES[ $ext ] ) || ! self::ensure_dirs() ) {
			return null;
		}
		$dir = self::private_dir() . gmdate( 'Y/m/' );
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		$dest = $dir . self::random_name() . '.' . $ext;
		if ( ! @copy( $src, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return null;
		}
		@chmod( $dest, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return array(
			'path'  => $dest,
			'name'  => (string) ( $original['name'] ?? '' ),
			'ext'   => $ext,
			'bytes' => (int) filesize( $dest ),
		);
	}

	/**
	 * Copy a stored preview (PNG master and its variants) to a new random base name.
	 *
	 * @param array $preview {path, url, sizes?}.
	 * @return array|null New preview meta or null when the master is gone.
	 */
	public static function copy_preview( array $preview ): ?array {
		$src = (string) ( $preview['path'] ?? '' );
		if ( '' === $src || ! is_readable( $src ) || ! self::is_ours( $src, 'previews' ) ) {
			return null;
		}
		$ext  = strtolower( (string) pathinfo( $src, PATHINFO_EXTENSION ) );
		$base = self::preview_dir() . self::random_name();
		$dest = $base . '.' . ( 'png' === $ext ? 'png' : $ext );
		if ( ! @copy( $src, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return null;
		}
		@chmod( $dest, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		$out = array(
			'path' => $dest,
			'url'  => self::preview_url_for( $dest ),
		);
		if ( ! empty( $preview['small'] ) ) {
			$out['small'] = true;
		}
		$sizes = array();
		foreach ( (array) ( $preview['sizes'] ?? array() ) as $key => $v ) {
			$vsrc = is_array( $v ) ? (string) ( $v['path'] ?? '' ) : '';
			if ( '' === $vsrc || ! is_readable( $vsrc ) || ! self::is_ours( $vsrc, 'previews' ) ) {
				continue;
			}
			$suffix = preg_match( '/(-\d+\.(?:webp|jpg))$/', $vsrc, $m ) ? $m[1] : '-' . sanitize_key( (string) $key ) . '.' . pathinfo( $vsrc, PATHINFO_EXTENSION );
			$vdest  = $base . $suffix;
			if ( @copy( $vsrc, $vdest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				@chmod( $vdest, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				$sizes[ (string) $key ] = array_merge( $v, array(
					'path' => $vdest,
					'url'  => self::preview_url_for( $vdest ),
				) );
			}
		}
		if ( $sizes ) {
			$out['sizes'] = $sizes;
		}
		return $out;
	}

	/**
	 * Delete a preview master and all its variants.
	 *
	 * @param mixed $preview Preview meta {path, sizes?}.
	 */
	public static function delete_preview( $preview ): void {
		if ( ! is_array( $preview ) ) {
			return;
		}
		if ( ! empty( $preview['path'] ) ) {
			self::delete( (string) $preview['path'] );
		}
		foreach ( (array) ( $preview['sizes'] ?? array() ) as $v ) {
			if ( is_array( $v ) && ! empty( $v['path'] ) ) {
				self::delete( (string) $v['path'] );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Storage ceiling (security-3, contract C3)
	 * ------------------------------------------------------------------- */

	/**
	 * Room to store a new original of $bytes? Refuses (507 bps_storage) when the free disk space would drop below
	 * bps_min_free_disk_bytes (default 1 GB) or when originals of draft/carted designs would exceed
	 * woo.maxDraftStorageMb (default 2048). The admin gets at most one mail per day about it.
	 *
	 * @param int $bytes Size of the new original.
	 * @return true|WP_Error
	 */
	public static function check_storage( int $bytes ) {
		$bytes = max( 0, $bytes );
		$min   = (int) apply_filters( 'bps_min_free_disk_bytes', GB_IN_BYTES );
		$free  = self::free_disk_bytes();
		if ( null !== $free && $free - $bytes < $min ) {
			self::storage_alert( 'disk', $free );
			return self::storage_full();
		}
		$max_mb = (float) Config::path( 'woo.maxDraftStorageMb', self::DEFAULT_DRAFT_STORAGE_MB );
		if ( $max_mb > 0 ) {
			$max  = (int) round( $max_mb * MB_IN_BYTES );
			$used = self::storage_used();
			if ( $used + $bytes > $max ) {
				self::storage_alert( 'quota', $used );
				return self::storage_full();
			}
		}
		return true;
	}

	/**
	 * Free bytes on the uploads disk (filter bps_disk_free_space; null = unknown).
	 */
	public static function free_disk_bytes(): ?float {
		$dir  = (string) ( wp_upload_dir( null, false )['basedir'] ?? '' );
		$free = ( '' !== $dir && is_dir( $dir ) && function_exists( 'disk_free_space' ) ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$free = apply_filters( 'bps_disk_free_space', false === $free ? null : (float) $free );
		return is_numeric( $free ) ? (float) $free : null;
	}

	/**
	 * Bytes of originals of draft/carted designs (counter, never negative).
	 */
	public static function storage_used(): int {
		return max( 0, (int) get_option( self::STORAGE_OPTION, 0 ) );
	}

	/**
	 * Add (positive) or subtract (negative) bytes from the counter. Kept per design by Woo\Designs, so the counter
	 * can be rebuilt with storage_rebuild() if it ever drifts.
	 *
	 * @param int $delta Bytes.
	 */
	public static function storage_add( int $delta ): void {
		if ( 0 === $delta ) {
			return;
		}
		// update_option() adds a missing option with autoload off.
		update_option( self::STORAGE_OPTION, (string) max( 0, self::storage_used() + $delta ), false );
	}

	/**
	 * Set the counter to an exact value (rebuild after a migration or drift).
	 *
	 * @param int $bytes Bytes.
	 */
	public static function storage_set( int $bytes ): void {
		update_option( self::STORAGE_OPTION, (string) max( 0, $bytes ), false );
	}

	/**
	 * Tell the shop admin (at most once per day) that customer uploads are refused.
	 *
	 * @param string $reason disk|quota.
	 * @param float  $value  Free bytes (disk) or used bytes (quota).
	 */
	private static function storage_alert( string $reason, float $value ): void {
		Logger::error(
			'storage_refused',
			array(
				'reason' => $reason,
				'mb'     => round( $value / MB_IN_BYTES, 1 ),
			)
		);
		if ( get_transient( self::STORAGE_ALERT ) ) {
			return;
		}
		set_transient( self::STORAGE_ALERT, 1, DAY_IN_SECONDS );
		$to = (string) apply_filters( 'bps_storage_alert_email', get_option( 'admin_email' ) );
		if ( ! is_email( $to ) ) {
			return;
		}
		$subject = __( 'Patch Studio: klantbestanden worden geweigerd (opslag vol)', 'borduur-patch-studio' );
		$body    = 'disk' === $reason
			/* translators: %s: free space in MB */
			? sprintf( __( 'Er is nog maar %s MB vrij op de schijf van je website. Patch Studio weigert daarom nieuwe ontwerpbestanden van klanten; klanten krijgen de vraag hun bestand per e-mail te sturen. Maak ruimte vrij of vraag je hostingpartij om meer opslag.', 'borduur-patch-studio' ), Config::num( $value / MB_IN_BYTES, 0 ) )
			/* translators: %s: used space in MB */
			: sprintf( __( 'Niet-bestelde ontwerpbestanden nemen samen %s MB in, het ingestelde maximum (woo.maxDraftStorageMb) is bereikt. Patch Studio weigert daarom nieuwe ontwerpbestanden; klanten krijgen de vraag hun bestand per e-mail te sturen. Oude ontwerpen worden automatisch opgeruimd; je kan het maximum ook verhogen in de instellingen.', 'borduur-patch-studio' ), Config::num( $value / MB_IN_BYTES, 0 ) );
		wp_mail( $to, $subject, $body );
	}

	/**
	 * The friendly 507 for the customer.
	 */
	private static function storage_full(): WP_Error {
		return new WP_Error(
			'bps_storage',
			__( 'We kunnen je bestand nu even niet bewaren. Stuur het gerust per e-mail of via WeTransfer, dan bekijken we het samen.', 'borduur-patch-studio' ),
			array( 'status' => 507 )
		);
	}

	/* ---------------------------------------------------------------------
	 * Deleting and path safety
	 * ------------------------------------------------------------------- */

	/**
	 * True if a path lies inside our storage (prevents path traversal on delete/download).
	 *
	 * @param string $path    Path.
	 * @param string $subdir  'private' | 'previews' | '' (any).
	 */
	public static function is_ours( string $path, string $subdir = '' ): bool {
		$real = realpath( $path );
		$base = realpath( self::base_dir() . $subdir );
		if ( ! $real || ! $base ) {
			return false;
		}
		return 0 === strpos( $real, trailingslashit( $base ) );
	}

	/**
	 * Delete a stored file (only inside our dirs; never the protection files).
	 *
	 * @param string $path Path.
	 */
	public static function delete( string $path ): bool {
		if ( '' === $path || ! self::is_ours( $path ) ) {
			return false;
		}
		$b = basename( $path );
		if ( in_array( $b, array( '.htaccess', 'index.php', 'web.config' ), true ) ) {
			return false;
		}
		return wp_delete_file_from_directory( $path, self::base_dir() ) || ! file_exists( $path );
	}

	/**
	 * Absolute preview path from its public URL (only for our previews).
	 *
	 * @param string $url URL.
	 */
	public static function preview_path_from_url( string $url ): string {
		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( ! preg_match( '/^[A-Za-z0-9]{32}(?:-\d{2,4})?\.(?:png|webp|jpg)$/', $name ) ) {
			return '';
		}
		return self::preview_dir() . $name;
	}

	/* ---------------------------------------------------------------------
	 * Self test (SPEC §6.1: core shows it in tab Algemeen)
	 * ------------------------------------------------------------------- */

	/**
	 * Check that the private dir is writable and NOT publicly reachable.
	 *
	 * @param bool $force Skip the 12 h cache.
	 * @return array{ok:bool, message:string}
	 */
	public static function self_test( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::SELFTEST_KEY );
			if ( is_array( $cached ) && isset( $cached['ok'], $cached['message'] ) ) {
				return $cached;
			}
		}
		$result = self::run_self_test();
		set_transient( self::SELFTEST_KEY, $result, $result['ok'] ? 12 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Run the test.
	 *
	 * @return array{ok:bool, message:string}
	 */
	private static function run_self_test(): array {
		if ( ! self::ensure_dirs() || ! wp_is_writable( self::private_dir() ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'De uploadmap van Patch Studio is niet schrijfbaar. Vraag je hostingpartij om wp-content/uploads/patch-studio schrijfbaar te maken.', 'borduur-patch-studio' ),
			);
		}
		$name = 'bps-selftest-' . self::random_name( 12 ) . '.txt';
		$file = self::private_dir() . $name;
		file_put_contents( $file, 'bps' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$url  = self::base_url() . 'private/' . $name;
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		wp_delete_file( $file );

		if ( is_wp_error( $resp ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'Privéuploads: de server kon zichzelf niet bereiken om het te testen. De bestanden hebben onraadbare namen; controleer de beveiliging eventueel met je hostingpartij.', 'borduur-patch-studio' ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( 200 === $code && 'bps' === trim( $body ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Opgelet: privéuploads zijn publiek bereikbaar. Je server negeert .htaccess (bv. nginx). Voeg deze regel toe aan de serverconfiguratie: location ^~ /wp-content/uploads/patch-studio/private/ { deny all; return 404; }', 'borduur-patch-studio' ),
			);
		}
		return array(
			'ok'      => true,
			/* translators: %d: HTTP status code */
			'message' => sprintf( __( 'Privéuploads zijn afgeschermd (test gaf HTTP %d).', 'borduur-patch-studio' ), $code ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------- */

	/**
	 * Common upload checks.
	 *
	 * @param array $file $_FILES entry.
	 * @return true|WP_Error
	 */
	private static function check_upload( array $file ) {
		$code = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_INI_SIZE === $code || UPLOAD_ERR_FORM_SIZE === $code ) {
			return self::too_big();
		}
		$tmp = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( UPLOAD_ERR_OK !== $code || '' === $tmp || ! is_file( $tmp ) || ( ! is_uploaded_file( $tmp ) && ! self::testing() ) ) {
			return new WP_Error( 'bps_upload', __( 'Het uploaden is niet gelukt. Probeer het nog eens.', 'borduur-patch-studio' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Move an uploaded file.
	 *
	 * @param string $tmp  Temp path.
	 * @param string $dest Destination.
	 */
	private static function move( string $tmp, string $dest ): bool {
		if ( is_uploaded_file( $tmp ) ) {
			return @move_uploaded_file( $tmp, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return self::testing() && @copy( $tmp, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Test mode (CLI tests feed files that were not uploaded via HTTP).
	 */
	private static function testing(): bool {
		return defined( 'BPS_TESTING' ) && BPS_TESTING;
	}

	/**
	 * Unguessable file name.
	 *
	 * @param int $len Length.
	 */
	private static function random_name( int $len = 32 ): string {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$out   = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$out .= $chars[ random_int( 0, 61 ) ];
		}
		return $out;
	}

	/**
	 * Customer file name, safe for meta/Content-Disposition (never used as a path).
	 *
	 * @param string $name Name.
	 */
	public static function clean_name( string $name ): string {
		$name = wp_basename( str_replace( '\\', '/', $name ) );
		$name = sanitize_file_name( $name );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 120 ) : substr( $name, 0, 120 );
	}

	/**
	 * @return WP_Error
	 */
	private static function too_big(): WP_Error {
		return new WP_Error(
			'bps_too_big',
			/* translators: %s: max size in MB */
			sprintf( __( 'Je bestand is groter dan %s MB. Stuur het gerust via WeTransfer of per e-mail, dan bekijken we het samen.', 'borduur-patch-studio' ), Config::num( self::max_bytes() / MB_IN_BYTES, 0 ) ),
			array( 'status' => 413 )
		);
	}

	/**
	 * @return WP_Error
	 */
	private static function bad_preview(): WP_Error {
		return new WP_Error( 'bps_invalid_design', __( 'Het voorbeeld van je patch kwam niet goed door. Probeer het nog eens.', 'borduur-patch-studio' ), array( 'status' => 400 ) );
	}

	/**
	 * @return WP_Error
	 */
	private static function store_failed(): WP_Error {
		return new WP_Error( 'bps_store', __( 'Opslaan is niet gelukt door een probleem op onze server. Probeer het later nog eens of mail ons je ontwerp.', 'borduur-patch-studio' ), array( 'status' => 500 ) );
	}
}
