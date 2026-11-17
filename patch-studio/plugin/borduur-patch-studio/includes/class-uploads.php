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
 */
final class Uploads {

	const DIR          = 'patch-studio';
	const PREVIEW_MAX  = 1200;
	const PREVIEW_MIN  = 600;
	const MAX_PIXELS   = 16777216; // decompression-bomb guard for previews (4096 x 4096; the UI sends 1200 px).
	const SELFTEST_KEY = 'bps_uploads_selftest';

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
	);

	/** Types the browser can display (used for the temporary "restore design" URL). */
	const BROWSER_TYPES = array( 'png', 'jpg', 'webp', 'svg', 'gif' );

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
		$prev = "# Patch Studio - public patch previews (PNG only).\n"
			. "Options -Indexes -ExecCGI\n"
			. "<FilesMatch \"\\.(?!png$)[^.]+$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n  <IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n  </IfModule>\n</FilesMatch>\n"
			. "<IfModule mod_headers.c>\n  Header set X-Robots-Tag \"noindex, nofollow\"\n  Header set X-Content-Type-Options \"nosniff\"\n</IfModule>\n";
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
	 * @param string $path File path.
	 * @return string|null png|jpg|webp|gif|svg|pdf|eps|psd
	 */
	public static function sniff( string $path ): ?string {
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
		$head = (string) file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$head = strtolower( ltrim( $head, "\xEF\xBB\xBF \t\r\n" ) );
		if ( ( 0 === strpos( $head, '<?xml' ) || 0 === strpos( $head, '<svg' ) || 0 === strpos( $head, '<!--' ) || 0 === strpos( $head, '<!doctype svg' ) ) && false !== strpos( $head, '<svg' ) ) {
			return 'svg';
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
		$type = self::sniff( $tmp );
		if ( null === $type ) {
			return new WP_Error(
				'bps_type',
				__( 'Dit bestandstype kunnen we niet lezen. Gebruik PNG, JPG, WEBP, SVG, PDF, AI, EPS of PSD.', 'borduur-patch-studio' ),
				array( 'status' => 415 )
			);
		}
		$orig_name = self::clean_name( (string) ( $file['name'] ?? '' ) );
		$orig_ext  = strtolower( (string) pathinfo( $orig_name, PATHINFO_EXTENSION ) );
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
	 * Re-encode the browser-made preview PNG (max 1200 px) into the public preview dir.
	 *
	 * @param array $file One entry of $_FILES.
	 * @return array|WP_Error {path, url}
	 */
	public static function store_preview( array $file ) {
		$err = self::check_upload( $file );
		if ( is_wp_error( $err ) ) {
			return $err;
		}
		$tmp = (string) $file['tmp_name'];
		if ( filesize( $tmp ) > 20 * MB_IN_BYTES ) {
			return self::too_big();
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
		$dest = self::preview_dir() . self::random_name() . '.png';
		$ok   = self::reencode_png( $tmp, $dest, $w, $h );
		if ( ! $ok ) {
			return self::store_failed();
		}
		@chmod( $dest, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return array(
			'path' => $dest,
			'url'  => self::preview_url_for( $dest ),
		);
	}

	/**
	 * Decode + scale + encode with GD (alpha kept); falls back to WP_Image_Editor.
	 *
	 * @param string $src  Source.
	 * @param string $dest Destination.
	 * @param int    $w    Width.
	 * @param int    $h    Height.
	 */
	private static function reencode_png( string $src, string $dest, int $w, int $h ): bool {
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
			$out = imagecreatetruecolor( $nw, $nh );
			imagealphablending( $out, false );
			imagesavealpha( $out, true );
			imagefill( $out, 0, 0, imagecolorallocatealpha( $out, 0, 0, 0, 127 ) );
			imagecopyresampled( $out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h );
			$ok = imagepng( $out, $dest, 7 );
			imagedestroy( $im );
			imagedestroy( $out );
			return (bool) $ok && 'png' === self::sniff( $dest );
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
		if ( ! preg_match( '/^[A-Za-z0-9]{32}\.png$/', $name ) ) {
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
