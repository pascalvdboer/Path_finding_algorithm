<?php
/**
 * Optional licence and update client (productisering-1, security-12).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Licensing;

use BorduurPatchStudio\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Off by default. Without a licence key it hooks nothing and makes zero outbound requests (no cron either).
 * With a key it answers WordPress' own update check for this plugin (`update_plugins_{host}` from the
 * "Update URI" header), fills "Details bekijken" (`plugins_api`) and verifies the Ed25519 signature of every
 * package before WordPress installs it (`upgrader_pre_download`). An expired or invalid licence only stops
 * updates; it never disables a shop feature.
 *
 * Requirements checklist: docs/premium/license-client-security.md.
 */
final class Client {

	/** Plugin slug on the update server. */
	const SLUG = 'borduur-patch-studio';

	/** Mirror of the "Update URI" plugin header (WordPress builds the filter name from its host). */
	const UPDATE_URI = 'https://borduurservicebelgie.be/';

	/** Licence data: key, status, expires, checked (autoload off; never in REST or the front end). */
	const OPTION = 'bps_license';

	/** Cached release info (12 h). */
	const CACHE = 'bps_license_update';

	/** Back-off state after a failed request. */
	const BACKOFF = 'bps_license_backoff';

	/** Cache lifetime of the release info. */
	const CACHE_TTL = 43200;

	/** Longest back-off after repeated failures. */
	const BACKOFF_MAX = 86400;

	/**
	 * Base64 Ed25519 public key (32 bytes) of the release signing key. Empty = no package is ever accepted
	 * (fail closed). Set it in the release that ships the client, or define BPS_UPDATE_PUBLIC_KEY in wp-config.php.
	 */
	const PUBLIC_KEY = '';

	/** @var Driver|null */
	private static $driver = null;

	/** @var callable|null Test downloader: fn( string $url ): string|WP_Error (path of the downloaded file). */
	private static $downloader = null;

	/**
	 * Component entry (Plugin::COMPONENTS).
	 */
	public static function register(): void {
		if ( is_admin() ) {
			Admin::register();
		}
		if ( self::ui_enabled() ) {
			add_filter( 'bps_status_checks', array( __CLASS__, 'status_check' ) );
		}
		if ( ! self::has_key() ) {
			return; // Default: no hooks, no requests, no cron.
		}
		add_filter( 'update_plugins_' . self::update_hostname(), array( __CLASS__, 'update_plugins' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 10, 4 );
	}

	/* ---------------------------------------------------------------------
	 * Configuration
	 * ------------------------------------------------------------------- */

	/**
	 * Whether the licence screen is shown: constant BPS_LICENSING (for a shop that sells the plugin) or a stored key.
	 * The owner's own shop sees nothing by default.
	 */
	public static function ui_enabled(): bool {
		return ( defined( 'BPS_LICENSING' ) && BPS_LICENSING ) || self::has_key();
	}

	/**
	 * Update host as an HTTPS base URL with trailing slash ('' when the constant is not a valid HTTPS URL).
	 */
	public static function update_url(): string {
		$url = defined( 'BPS_UPDATE_URL' ) ? (string) BPS_UPDATE_URL : self::UPDATE_URI;
		$p   = wp_parse_url( $url );
		if ( ! is_array( $p ) || 'https' !== strtolower( (string) ( $p['scheme'] ?? '' ) ) || empty( $p['host'] ) ) {
			return '';
		}
		return trailingslashit( $url );
	}

	/**
	 * Host WordPress uses in `update_plugins_{host}` (from the Update URI header).
	 */
	public static function update_hostname(): string {
		return (string) wp_parse_url( self::UPDATE_URI, PHP_URL_HOST );
	}

	/**
	 * Plugin basename (borduur-patch-studio/borduur-patch-studio.php).
	 */
	public static function basename(): string {
		return defined( 'BPS_FILE' ) ? plugin_basename( BPS_FILE ) : self::SLUG . '/' . self::SLUG . '.php';
	}

	/**
	 * Raw public key (32 bytes) or ''.
	 */
	public static function public_key(): string {
		$b64 = defined( 'BPS_UPDATE_PUBLIC_KEY' ) ? (string) BPS_UPDATE_PUBLIC_KEY : self::PUBLIC_KEY;
		$raw = '' === $b64 ? false : base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$len = defined( 'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES' ) ? SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES : 32;
		return is_string( $raw ) && strlen( $raw ) === $len ? $raw : '';
	}

	/**
	 * Driver (one: the own WooCommerce store).
	 */
	public static function driver(): Driver {
		if ( null === self::$driver ) {
			self::$driver = new Woo_Store_Driver();
		}
		return self::$driver;
	}

	/**
	 * Replace the driver (tests) or reset (null).
	 *
	 * @param Driver|null $driver Driver.
	 */
	public static function set_driver( ?Driver $driver ): void {
		self::$driver = $driver;
	}

	/**
	 * Replace the package downloader (tests) or reset (null).
	 *
	 * @param callable|null $downloader fn( string $url ): string|WP_Error.
	 */
	public static function set_downloader( ?callable $downloader ): void {
		self::$downloader = $downloader;
	}

	/* ---------------------------------------------------------------------
	 * Licence data
	 * ------------------------------------------------------------------- */

	/**
	 * Stored licence data.
	 *
	 * @return array{key:string, status:string, expires:string, checked:int, message:string}
	 */
	public static function data(): array {
		$d = get_option( self::OPTION, array() );
		$d = is_array( $d ) ? $d : array();
		return array(
			'key'     => is_string( $d['key'] ?? null ) ? $d['key'] : '',
			'status'  => is_string( $d['status'] ?? null ) ? $d['status'] : '',
			'expires' => is_string( $d['expires'] ?? null ) ? $d['expires'] : '',
			'checked' => (int) ( $d['checked'] ?? 0 ),
			'message' => is_string( $d['message'] ?? null ) ? $d['message'] : '',
		);
	}

	/**
	 * Whether a key is stored.
	 */
	public static function has_key(): bool {
		return '' !== self::data()['key'];
	}

	/**
	 * Key for display: only the last 4 characters.
	 */
	public static function masked_key(): string {
		$key = self::data()['key'];
		return '' === $key ? '' : str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	/**
	 * Normalise a key ('' when it does not look like a licence key).
	 *
	 * @param string $key Raw input.
	 */
	public static function sanitize_key( string $key ): string {
		$key = trim( $key );
		return preg_match( '/^[A-Za-z0-9][A-Za-z0-9-]{7,63}$/', $key ) ? $key : '';
	}

	/**
	 * Store the licence data (autoload off).
	 *
	 * @param array $data Data.
	 */
	private static function store( array $data ): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $data, '', false );
		} else {
			update_option( self::OPTION, $data, false );
		}
	}

	/**
	 * Save and activate a key. The key is stored even when the server cannot be reached (status 'unchecked').
	 *
	 * @param string $raw Key as typed.
	 * @return array|\WP_Error Licence data.
	 */
	public static function save_key( string $raw ) {
		$key = self::sanitize_key( $raw );
		if ( '' === $key ) {
			return new \WP_Error( 'bps_license_format', __( 'Dit lijkt geen geldige licentiesleutel. Kopieer ze opnieuw uit je bestelling.', 'borduur-patch-studio' ) );
		}
		$data = array(
			'key'     => $key,
			'status'  => 'unchecked',
			'expires' => '',
			'checked' => time(),
			'message' => '',
		);
		$res  = self::safe_call( 'activate', array( $key, home_url( '/' ) ) );
		if ( is_array( $res ) ) {
			$data = array_merge( $data, self::status_fields( $res ) );
		}
		self::store( $data );
		self::forget_cache();
		return is_wp_error( $res ) ? $res : $data;
	}

	/**
	 * Release the key on the server (best effort) and remove it locally.
	 */
	public static function remove_key(): void {
		$key = self::data()['key'];
		if ( '' !== $key ) {
			self::safe_call( 'deactivate', array( $key, home_url( '/' ) ) );
		}
		delete_option( self::OPTION );
		self::forget_cache();
		delete_transient( self::BACKOFF );
	}

	/**
	 * Ask the server for the current status (button "Nu controleren"; never from cron).
	 *
	 * @return array|\WP_Error Licence data.
	 */
	public static function check_now() {
		$data = self::data();
		if ( '' === $data['key'] ) {
			return new \WP_Error( 'bps_license_none', __( 'Er is geen licentiesleutel opgeslagen.', 'borduur-patch-studio' ) );
		}
		$res = self::safe_call( 'check', array( $data['key'], home_url( '/' ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data            = array_merge( $data, self::status_fields( (array) $res ) );
		$data['checked'] = time();
		self::store( $data );
		self::forget_cache();
		return $data;
	}

	/**
	 * Only known, sanitised status fields from a server answer (data only).
	 *
	 * @param array $res Server answer.
	 */
	private static function status_fields( array $res ): array {
		$status  = strtolower( (string) ( $res['status'] ?? '' ) );
		$allowed = array( 'valid', 'expired', 'invalid', 'inactive', 'disabled', 'site_limit' );
		$expires = (string) ( $res['expires'] ?? '' );
		return array(
			'status'  => in_array( $status, $allowed, true ) ? $status : 'invalid',
			'expires' => preg_match( '/^\d{4}-\d{2}-\d{2}/', $expires ) ? substr( $expires, 0, 10 ) : '',
			'message' => sanitize_text_field( substr( (string) ( $res['message'] ?? '' ), 0, 200 ) ),
		);
	}

	/**
	 * Forget the cached release info.
	 */
	public static function forget_cache(): void {
		delete_transient( self::CACHE );
	}

	/* ---------------------------------------------------------------------
	 * Remote calls (with back-off)
	 * ------------------------------------------------------------------- */

	/**
	 * Call the driver, catching everything (a licence problem must never break wp-admin).
	 *
	 * @param string $method Driver method.
	 * @param array  $args   Arguments.
	 * @return mixed|\WP_Error
	 */
	private static function safe_call( string $method, array $args ) {
		try {
			$res = call_user_func_array( array( self::driver(), $method ), $args );
		} catch ( \Throwable $e ) {
			$res = new \WP_Error( 'bps_license_exception', $e->getMessage() );
		}
		if ( is_wp_error( $res ) ) {
			Logger::warning(
				'license_request_failed',
				array(
					'call'  => $method,
					'error' => $res,
				)
			);
		}
		return $res;
	}

	/**
	 * Latest release for this licence (cached 12 h; back-off after failures). Null = no update available.
	 *
	 * @return array{version:string, package:string, signature:string, tested:string, requires:string, requires_php:string, changelog:string}|null
	 */
	public static function update_info(): ?array {
		$key = self::data()['key'];
		if ( '' === $key ) {
			return null;
		}
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
		$backoff = get_transient( self::BACKOFF );
		if ( is_array( $backoff ) && (int) ( $backoff['until'] ?? 0 ) > time() ) {
			return null;
		}

		$res = self::safe_call( 'update_info', array( $key, home_url( '/' ), defined( 'BPS_VERSION' ) ? BPS_VERSION : '0.0.0' ) );
		if ( is_wp_error( $res ) ) {
			$n     = (int) ( is_array( $backoff ) ? ( $backoff['n'] ?? 0 ) : 0 ) + 1;
			$delay = (int) min( self::BACKOFF_MAX, HOUR_IN_SECONDS * ( 2 ** min( 5, $n - 1 ) ) );
			set_transient(
				self::BACKOFF,
				array(
					'n'     => $n,
					'until' => time() + $delay,
				),
				$delay
			);
			return null;
		}
		delete_transient( self::BACKOFF );
		$info = is_array( $res ) ? self::sanitize_release( $res ) : null;
		set_transient( self::CACHE, $info ? $info : array( 'version' => '' ), self::CACHE_TTL );
		return $info;
	}

	/**
	 * Validate a release answer. The server answer is data only: versions must look like versions, the package must
	 * be an HTTPS URL on the update host, the signature 64 bytes, the changelog goes through wp_kses_post.
	 *
	 * @param array $res Server answer.
	 */
	public static function sanitize_release( array $res ): ?array {
		$version = (string) ( $res['version'] ?? '' );
		$package = (string) ( $res['package'] ?? '' );
		$sig     = (string) ( $res['signature'] ?? '' );
		$verre   = '/^\d+\.\d+(\.\d+)?([.-][0-9A-Za-z.-]+)?$/';
		if ( ! preg_match( $verre, $version ) ) {
			return null;
		}
		$host = (string) wp_parse_url( self::update_url(), PHP_URL_HOST );
		$p    = wp_parse_url( $package );
		if ( '' === $host || ! is_array( $p ) || 'https' !== strtolower( (string) ( $p['scheme'] ?? '' ) ) || strtolower( (string) ( $p['host'] ?? '' ) ) !== strtolower( $host ) ) {
			return null;
		}
		$raw_sig = base64_decode( $sig, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$siglen  = defined( 'SODIUM_CRYPTO_SIGN_BYTES' ) ? SODIUM_CRYPTO_SIGN_BYTES : 64;
		if ( ! is_string( $raw_sig ) || strlen( $raw_sig ) !== $siglen ) {
			return null;
		}
		$ver = static function ( $v ) use ( $verre ): string {
			return is_string( $v ) && preg_match( $verre, $v ) ? $v : '';
		};
		return array(
			'version'      => $version,
			'package'      => esc_url_raw( $package ),
			'signature'    => $sig,
			'tested'       => $ver( $res['tested'] ?? '' ),
			'requires'     => $ver( $res['requires'] ?? '' ),
			'requires_php' => $ver( $res['requires_php'] ?? '' ),
			'changelog'    => wp_kses_post( substr( (string) ( $res['changelog'] ?? '' ), 0, 20000 ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * WordPress update hooks
	 * ------------------------------------------------------------------- */

	/**
	 * `update_plugins_{host}`: tell WordPress about the latest release of this plugin. WordPress compares the
	 * version itself and puts newer releases in the update_plugins transient.
	 *
	 * @param mixed  $update      Update data so far.
	 * @param array  $plugin_data Plugin headers.
	 * @param string $plugin_file Plugin basename.
	 * @param array  $locales     Locales.
	 * @return mixed
	 */
	public static function update_plugins( $update, $plugin_data, $plugin_file, $locales = array() ) {
		unset( $plugin_data, $locales );
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$info = self::update_info();
		if ( ! $info ) {
			return $update;
		}
		return array(
			'id'           => self::update_url() . '#' . self::SLUG,
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $info['version'],
			'url'          => self::update_url(),
			'package'      => $info['package'],
			'tested'       => $info['tested'],
			'requires'     => $info['requires'],
			'requires_php' => $info['requires_php'],
		);
	}

	/**
	 * `plugins_api`: "Details bekijken" for this plugin.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action API action.
	 * @param mixed  $args   Arguments.
	 * @return mixed
	 */
	public static function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$info = self::update_info();
		if ( ! $info ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Patch Studio',
			'slug'          => self::SLUG,
			'version'       => $info['version'],
			'homepage'      => self::update_url(),
			'requires'      => $info['requires'],
			'tested'        => $info['tested'],
			'requires_php'  => $info['requires_php'],
			'download_link' => $info['package'],
			'sections'      => array(
				'changelog' => '' !== $info['changelog'] ? $info['changelog'] : esc_html__( 'Geen wijzigingslog beschikbaar.', 'borduur-patch-studio' ),
			),
		);
	}

	/**
	 * `upgrader_pre_download`: download our package ourselves and only hand it to WordPress when its Ed25519
	 * signature is valid. Anything else is left to WordPress.
	 *
	 * @param mixed  $reply      False or a previous result.
	 * @param string $package    Package URL.
	 * @param mixed  $upgrader   Upgrader.
	 * @param array  $hook_extra Extra data (plugin basename).
	 * @return mixed Path of the verified zip, WP_Error, or $reply.
	 */
	public static function pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		unset( $upgrader );
		if ( false !== $reply || ! is_string( $package ) || '' === $package ) {
			return $reply;
		}
		$ours = ( is_array( $hook_extra ) && self::basename() === ( $hook_extra['plugin'] ?? '' ) )
			|| ( '' !== self::update_url() && 0 === strpos( $package, self::update_url() ) );
		if ( ! $ours ) {
			return $reply;
		}
		$info = get_transient( self::CACHE );
		if ( ! is_array( $info ) || empty( $info['package'] ) || $info['package'] !== $package ) {
			$info = self::update_info();
		}
		if ( ! is_array( $info ) || ( $info['package'] ?? '' ) !== $package ) {
			return new \WP_Error( 'bps_update_unknown_package', __( 'Dit updatepakket is niet bekend bij de licentieserver en wordt niet geïnstalleerd.', 'borduur-patch-studio' ) );
		}
		return self::verify_package( $package, (string) $info['signature'] );
	}

	/**
	 * Download a package and verify its detached Ed25519 signature. Returns the local file path or a WP_Error
	 * (the file is deleted when the signature does not match).
	 *
	 * @param string $url       Package URL.
	 * @param string $signature Base64 signature.
	 * @return string|\WP_Error
	 */
	public static function verify_package( string $url, string $signature ) {
		$public = self::public_key();
		if ( '' === $public || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return new \WP_Error( 'bps_update_unsigned', __( 'Updates kunnen niet gecontroleerd worden (geen publieke sleutel). Het pakket wordt niet geïnstalleerd.', 'borduur-patch-studio' ) );
		}
		$sig = base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! is_string( $sig ) || strlen( $sig ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
			return self::bad_signature( '' );
		}
		if ( null !== self::$downloader ) {
			$file = call_user_func( self::$downloader, $url );
		} else {
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$file = download_url( $url, 300 );
		}
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$bytes = is_string( $file ) && is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$ok    = false;
		if ( is_string( $bytes ) && '' !== $bytes ) {
			try {
				$ok = sodium_crypto_sign_verify_detached( $sig, $bytes, $public );
			} catch ( \Throwable $e ) {
				$ok = false; // Malformed key or signature: treated exactly like a wrong signature.
			}
		}
		if ( ! $ok ) {
			return self::bad_signature( is_string( $file ) ? $file : '' );
		}
		return $file;
	}

	/**
	 * Refuse a package with a wrong signature (and remove the downloaded file).
	 *
	 * @param string $file Downloaded file.
	 */
	private static function bad_signature( string $file ): \WP_Error {
		if ( '' !== $file && is_file( $file ) ) {
			wp_delete_file( $file );
		}
		Logger::error( 'update_bad_signature', array( 'host' => (string) wp_parse_url( self::update_url(), PHP_URL_HOST ) ) );
		return new \WP_Error( 'bps_update_bad_signature', __( 'Het updatepakket heeft geen geldige handtekening en wordt niet geïnstalleerd.', 'borduur-patch-studio' ) );
	}

	/**
	 * Status row for the Patch Studio status block (filter bps_status_checks of P08). Only when the licence screen
	 * is enabled. Never contains the key.
	 *
	 * @param mixed $checks Checks.
	 * @return array
	 */
	public static function status_check( $checks ): array {
		$checks = is_array( $checks ) ? $checks : array();
		$d      = self::data();
		if ( '' === $d['key'] ) {
			$checks[] = array( 'licensing', __( 'Licentie en updates', 'borduur-patch-studio' ), 'warn', __( 'Geen licentiesleutel: Patch Studio werkt volledig, maar je krijgt geen automatische updates.', 'borduur-patch-studio' ) );
		} elseif ( 'valid' === $d['status'] ) {
			$checks[] = array( 'licensing', __( 'Licentie en updates', 'borduur-patch-studio' ), 'ok', __( 'Licentie actief: updates komen automatisch.', 'borduur-patch-studio' ) );
		} else {
			$checks[] = array( 'licensing', __( 'Licentie en updates', 'borduur-patch-studio' ), 'warn', __( 'De licentie is niet actief. De winkel werkt gewoon verder; alleen updates staan stil.', 'borduur-patch-studio' ) );
		}
		return $checks;
	}

	/**
	 * Data owned by the licence client (merged into Plugin::owned_data(), also during uninstall).
	 *
	 * @return array<string, string[]>
	 */
	public static function owned_data(): array {
		return array(
			'always_options'         => array( self::OPTION ),
			'always_option_patterns' => array( '_transient_' . self::CACHE, '_transient_timeout_' . self::CACHE, '_transient_' . self::BACKOFF, '_transient_timeout_' . self::BACKOFF ),
		);
	}
}
