<?php
/**
 * Settings: loads config/defaults.json + thread palette, merges stored overrides, validates and saves.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of configuration (SPEC §4.2, §8).
 *
 * Storage model: option `bps_settings` holds ONLY the differences from the defaults, so plugin updates
 * that improve default values reach every site automatically, and "Herstel standaard" is a simple unset.
 */
final class Settings {

	const OPTION = 'bps_settings';

	/** Keys never sent to the browser (mirror of PRIVATE_KEYS in assets/src/shared/config.js). */
	const PRIVATE_KEYS = array( 'woo', 'retention', 'security', 'admin' );

	/** Keys that are never stored as overrides (derived data). */
	const NON_STORABLE = array( 'threads', 'schema', 'version' );

	/** Internal marker for "drop this value". */
	const SKIP = "\0bps-skip\0";

	/** @var array|null */
	private static $defaults = null;

	/** @var array|null */
	private static $config = null;

	/** @var array<int, array{type:string, message:string}> Notices produced by the last update(). */
	private static $notices = array();

	/**
	 * Numeric limits by config path ('*' = one path segment). First match wins.
	 *
	 * @var array<string, array{0:float, 1:float}>
	 */
	const LIMITS = array(
		'techniques.*.basePrice.*'  => array( 0, 10000 ),
		'techniques.*.minQty'       => array( 1, 100000 ),
		'techniques.*.qtyStep'      => array( 1, 1000 ),
		'techniques.*.minCm'        => array( 1, 50 ),
		'techniques.*.maxOrderCm'   => array( 0, 50 ),
		'techniques.*.maxCm'        => array( 1, 100 ),
		'techniques.*.rules.*'      => array( 0, 1000 ),
		'techniques.*.rules.*.*'    => array( 0, 1000 ),
		'tiers.steps.*.min'         => array( 1, 1000000 ),
		'tiers.steps.*.discount'    => array( 0, 0.95 ),
		'fees.groups.*.amount'      => array( 0, 100000 ),
		'options.*.price.amount'    => array( -1, 10000 ),
		'options.*.priceByTechnique.*.amount' => array( -1, 10000 ),
		'shipping.*'                => array( 0, 100000 ),
		'vat.rate'                  => array( 0, 1 ),
		'retention.orderYears'      => array( 1, 30 ),
		'retention.*'               => array( 1, 3650 ),
		'woo.maxUploadMb'           => array( 1, 256 ),
		'woo.rateLimits.*'          => array( 1, 100000 ),
		'promises.proofDays'        => array( 1, 60 ),
		'quantity.*'                => array( 1, 1000000 ),
		'sizes.*'                   => array( 0.5, 100 ),
		'text.*'                    => array( 1, 200 ),
		'specials.*.*'              => array( 0, 1000 ),
	);

	/**
	 * Allowed values for enumerated strings.
	 *
	 * @var array<string, string[]>
	 */
	const ENUMS = array(
		'techniques.*.mode'      => array( 'order', 'quote' ),
		'fees.scope'             => array( 'order', 'design' ),
		'woo.cartMode'           => array( 'own_product', 'existing_products' ),
		'woo.productVisibility'  => array( 'hidden', 'visible', 'catalog', 'search' ),
		'woo.trustCloudflareIp'  => array( 'auto', 'yes', 'no' ),
		'options.*.price.type'   => array( 'per_piece', 'per_line', 'percent' ),
		'options.*.priceByTechnique.*.type' => array( 'per_piece', 'per_line', 'percent' ),
		'specials.*.availability' => array( 'on_request', 'available', 'hidden' ),
	);

	/* ---------------------------------------------------------------------
	 * Lifecycle
	 * ------------------------------------------------------------------- */

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $prefix ) {
			add_action( $prefix . self::OPTION, array( __CLASS__, 'flush' ) );
		}
		add_action( 'switch_blog', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Drop the per-request cache (defaults stay: they come from files).
	 */
	public static function flush(): void {
		self::$config = null;
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------- */

	/**
	 * Defaults = config/defaults.json + ['threads' => thread-palette.json threads]. Cached per request.
	 */
	public static function defaults(): array {
		if ( null !== self::$defaults ) {
			return self::$defaults;
		}
		$dir      = defined( 'BPS_DIR' ) ? BPS_DIR : dirname( __DIR__ ) . '/';
		$defaults = self::read_json( $dir . 'config/defaults.json' );
		$palette  = self::read_json( $dir . 'config/thread-palette.json' );

		$defaults['threads'] = isset( $palette['threads'] ) && is_array( $palette['threads'] ) ? array_values( $palette['threads'] ) : array();

		// Private admin-only settings (stripped from the public config).
		$defaults['admin'] = self::deep_merge(
			array( 'deleteDataOnUninstall' => false ),
			isset( $defaults['admin'] ) && is_array( $defaults['admin'] ) ? $defaults['admin'] : array()
		);

		self::$defaults = $defaults;
		return self::$defaults;
	}

	/**
	 * Full merged config (defaults + overrides), filtered through `bps_config`. Cached per request.
	 */
	public static function get(): array {
		if ( null === self::$config ) {
			$config       = self::deep_merge( self::defaults(), self::overrides() );
			$config       = apply_filters( 'bps_config', $config );
			self::$config = is_array( $config ) ? $config : self::defaults();
		}
		return self::$config;
	}

	/**
	 * Stored overrides (raw option, always an array).
	 */
	public static function overrides(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Read a value by dot path, e.g. 'techniques.embroidered.basePrice.8'. Numeric segments index lists.
	 *
	 * @param string $dot     Dot path.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get_path( string $dot, $default = null ) {
		return self::array_get( self::get(), $dot, $default );
	}

	/**
	 * WooCommerce product ID of the hidden "Patch op maat" product.
	 */
	public static function product_id(): int {
		$id = self::get_path( 'woo.productId' );
		if ( is_numeric( $id ) && (int) $id > 0 ) {
			return (int) $id;
		}
		return (int) get_option( 'bps_product_id', 0 );
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------- */

	/**
	 * Validate and store settings. `$overrides` may be partial; it is merged into the current settings.
	 * Only differences with the defaults are stored. Fires `bps_settings_updated` with the new config.
	 *
	 * @param array $overrides Partial config in the shape of defaults.json.
	 */
	public static function update( array $overrides ): void {
		self::$notices = array();
		$defaults      = self::defaults();

		$clean = self::sanitize_value( $overrides, $defaults, '' );
		$clean = is_array( $clean ) ? $clean : array();

		$full = self::deep_merge( self::deep_merge( $defaults, self::overrides() ), $clean );
		$full = self::enforce( $full );

		$diff = self::diff( $full, $defaults );
		foreach ( self::NON_STORABLE as $key ) {
			unset( $diff[ $key ] );
		}

		// Always autoload: the config is needed on every page that shows the tool.
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $diff, '', true );
		} else {
			update_option( self::OPTION, $diff, true );
		}
		self::flush();
		do_action( 'bps_settings_updated', self::get() );
	}

	/**
	 * Restore defaults. Null = everything; otherwise a top-level key or a dot path ('techniques.pvc.basePrice').
	 * Path segments may be '*' to match every key at that level.
	 *
	 * @param string|null $top_key Key or dot path.
	 */
	public static function reset( ?string $top_key = null ): void {
		self::reset_paths( null === $top_key ? array() : array( $top_key ), null === $top_key );
	}

	/**
	 * Restore defaults for several paths at once (one save, one cache purge).
	 *
	 * @param string[] $paths Dot paths with optional '*' segments.
	 * @param bool     $all   Reset everything.
	 */
	public static function reset_paths( array $paths, bool $all = false ): void {
		$overrides = $all ? array() : self::overrides();
		foreach ( $paths as $path ) {
			$path = trim( (string) $path, '. ' );
			if ( '' !== $path ) {
				$overrides = self::unset_path( $overrides, explode( '.', $path ) );
			}
		}
		update_option( self::OPTION, $overrides, true );
		self::flush();
		do_action( 'bps_settings_updated', self::get() );
	}

	/**
	 * Notices (warnings/info) produced by the last update(), e.g. a technique switched back to quote mode.
	 *
	 * @return array<int, array{type:string, message:string}>
	 */
	public static function last_notices(): array {
		return self::$notices;
	}

	/* ---------------------------------------------------------------------
	 * Public config for the browser (SPEC §4.2 / shared/config.js buildPublicConfig)
	 * ------------------------------------------------------------------- */

	/**
	 * @param string|null $locale WP or BCP-47 locale; null = determine_locale().
	 * @param array       $page   Shortcode attributes (camelCase or the Dutch shortcode names).
	 */
	public static function public_config( ?string $locale = null, array $page = array() ): array {
		$config = self::get();
		$locale = self::public_locale( $locale );

		// Threads: palette minus disabled minus out of stock.
		$off     = array_map( 'strval', isset( $config['threadsDisabled'] ) && is_array( $config['threadsDisabled'] ) ? $config['threadsDisabled'] : array() );
		$threads = array();
		foreach ( isset( $config['threads'] ) && is_array( $config['threads'] ) ? $config['threads'] : array() as $thread ) {
			if ( ! is_array( $thread ) || ! isset( $thread['code'] ) ) {
				continue;
			}
			if ( in_array( (string) $thread['code'], $off, true ) || ( array_key_exists( 'stock', $thread ) && false === $thread['stock'] ) ) {
				continue;
			}
			$threads[] = $thread;
		}
		$config['threads'] = $threads;

		$strings = self::public_strings( $config, $locale );
		foreach ( self::PRIVATE_KEYS as $key ) {
			unset( $config[ $key ] );
		}
		unset( $config['strings'] );
		if ( isset( $config['techniques'] ) && is_array( $config['techniques'] ) ) {
			foreach ( $config['techniques'] as $id => $tech ) {
				if ( is_array( $tech ) ) {
					unset( $config['techniques'][ $id ]['legacy'] );
				}
			}
		}

		$config['locale']  = $locale;
		$config['rest']    = array(
			'root'     => esc_url_raw( rest_url( 'patch-studio/v1/' ) ),
			'storeApi' => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
			'cartUrl'  => function_exists( 'wc_get_cart_url' ) ? esc_url_raw( wc_get_cart_url() ) : '',
		);
		$config['assets']  = array(
			'workerUrl' => class_exists( Plugin::class ) ? Plugin::worker_url() : '',
			'fontsUrl'  => defined( 'BPS_URL' ) ? BPS_URL . 'assets/fonts/' : '',
			'version'   => defined( 'BPS_VERSION' ) ? BPS_VERSION : '',
		);
		$config['strings'] = empty( $strings ) ? new \stdClass() : $strings;
		$config['page']    = self::normalize_page( $page, $config );

		$public = apply_filters( 'bps_public_config', $config, $locale );
		return is_array( $public ) ? $public : $config;
	}

	/**
	 * Map any locale to the two supported public locales.
	 *
	 * @param string|null $locale Locale.
	 */
	public static function public_locale( ?string $locale = null ): string {
		if ( null === $locale || '' === $locale ) {
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : 'nl_BE';
		}
		$locale = str_replace( '_', '-', (string) $locale );
		return 0 === stripos( $locale, 'fr' ) ? 'fr-BE' : 'nl-BE';
	}

	/**
	 * String overrides for the JS catalogues. Accepts a flat map or {nl:{…}, fr:{…}}.
	 *
	 * @param array  $config Config.
	 * @param string $locale Public locale.
	 */
	private static function public_strings( array $config, string $locale ): array {
		$raw = isset( $config['strings'] ) && is_array( $config['strings'] ) ? $config['strings'] : array();
		$out = array();
		$lng = 0 === strpos( $locale, 'fr' ) ? 'fr' : 'nl';
		foreach ( $raw as $key => $value ) {
			if ( ( 'nl' === $key || 'fr' === $key ) && is_array( $value ) ) {
				continue;
			}
			if ( is_scalar( $value ) ) {
				$out[ (string) $key ] = (string) $value;
			}
		}
		if ( isset( $raw[ $lng ] ) && is_array( $raw[ $lng ] ) ) {
			foreach ( $raw[ $lng ] as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$out[ (string) $key ] = (string) $value;
				}
			}
		}
		return $out;
	}

	/**
	 * Normalise shortcode/widget attributes into config.page.
	 *
	 * @param array $page   Attributes.
	 * @param array $config Config (for validation).
	 * @return array{technique:string, sizeCm:float|null, qty:int|null, shape:string, compact:bool}
	 */
	private static function normalize_page( array $page, array $config ): array {
		$pick = static function ( array $keys ) use ( $page ) {
			foreach ( $keys as $k ) {
				if ( isset( $page[ $k ] ) && '' !== $page[ $k ] && null !== $page[ $k ] ) {
					return $page[ $k ];
				}
			}
			return null;
		};

		$tech = (string) $pick( array( 'technique', 'techniek' ) );
		if ( '' !== $tech && empty( $config['techniques'][ $tech ]['enabled'] ) ) {
			$tech = '';
		}

		$size = $pick( array( 'sizeCm', 'maat', 'size' ) );
		$size = is_numeric( str_replace( ',', '.', (string) $size ) ) ? (float) str_replace( ',', '.', (string) $size ) : null;
		if ( null !== $size ) {
			$min  = (float) ( $config['sizes']['minCm'] ?? 1 );
			$max  = (float) ( $config['sizes']['maxQuoteCm'] ?? 30 );
			$size = ( $size >= $min && $size <= $max ) ? $size : null;
		}

		$qty = $pick( array( 'qty', 'aantal' ) );
		$qty = is_numeric( $qty ) && (int) $qty > 0 ? min( (int) $qty, (int) ( $config['quantity']['max'] ?? 10000 ) ) : null;

		$shape = (string) $pick( array( 'shape', 'vorm' ) );
		if ( 'auto' !== $shape && ! isset( $config['shapes'][ $shape ] ) ) {
			$shape = 'auto';
		}

		$compact = $pick( array( 'compact' ) );
		$compact = is_bool( $compact ) ? $compact : in_array( strtolower( (string) $compact ), array( '1', 'yes', 'ja', 'true', 'on' ), true );

		return array(
			'technique' => $tech,
			'sizeCm'    => $size,
			'qty'       => $qty,
			'shape'     => $shape,
			'compact'   => $compact,
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers (public: also used by the admin page and tests)
	 * ------------------------------------------------------------------- */

	/**
	 * Deep merge: associative arrays recursively; lists and scalars are REPLACED (identical to JS deepMerge).
	 *
	 * @param array $base Base.
	 * @param array $over Overrides.
	 */
	public static function deep_merge( array $base, array $over ): array {
		foreach ( $over as $key => $value ) {
			if ( isset( $base[ $key ] ) && self::is_assoc( $base[ $key ] ) && self::is_assoc( $value ) ) {
				$base[ $key ] = self::deep_merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/**
	 * Whether a value is a non-empty associative array (JS object).
	 *
	 * @param mixed $value Value.
	 */
	public static function is_assoc( $value ): bool {
		return is_array( $value ) && array() !== $value && ! array_is_list( $value );
	}

	/**
	 * Recursive difference: what in $value differs from $base. Lists are compared as a whole.
	 *
	 * @param array $value Full config.
	 * @param array $base  Defaults.
	 */
	public static function diff( array $value, array $base ): array {
		$out = array();
		foreach ( $value as $key => $v ) {
			if ( ! array_key_exists( $key, $base ) ) {
				$out[ $key ] = $v;
				continue;
			}
			$b = $base[ $key ];
			if ( self::is_assoc( $v ) && self::is_assoc( $b ) ) {
				$sub = self::diff( $v, $b );
				if ( array() !== $sub ) {
					$out[ $key ] = $sub;
				}
			} elseif ( ! self::same( $v, $b ) ) {
				$out[ $key ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Structural equality with numeric tolerance (5.2 === 5.20, 45 === 45.0).
	 *
	 * @param mixed $a A.
	 * @param mixed $b B.
	 */
	public static function same( $a, $b ): bool {
		if ( ( is_int( $a ) || is_float( $a ) ) && ( is_int( $b ) || is_float( $b ) ) ) {
			return abs( (float) $a - (float) $b ) < 1e-9;
		}
		if ( is_array( $a ) && is_array( $b ) ) {
			if ( count( $a ) !== count( $b ) ) {
				return false;
			}
			// Lists are ordered; objects are not (key order is irrelevant in JSON).
			if ( array_is_list( $a ) !== array_is_list( $b ) || ( array_is_list( $a ) && array_keys( $a ) !== array_keys( $b ) ) ) {
				return false;
			}
			foreach ( $a as $k => $v ) {
				if ( ! array_key_exists( $k, $b ) || ! self::same( $v, $b[ $k ] ) ) {
					return false;
				}
			}
			return true;
		}
		return $a === $b;
	}

	/**
	 * Read a nested value by dot path.
	 *
	 * @param array  $data    Data.
	 * @param string $dot     Path.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function array_get( array $data, string $dot, $default = null ) {
		if ( '' === $dot ) {
			return $data;
		}
		$node = $data;
		foreach ( explode( '.', $dot ) as $seg ) {
			if ( is_array( $node ) && array_key_exists( $seg, $node ) ) {
				$node = $node[ $seg ];
			} elseif ( is_array( $node ) && is_numeric( $seg ) && array_key_exists( (int) $seg, $node ) ) {
				$node = $node[ (int) $seg ];
			} else {
				return $default;
			}
		}
		return $node;
	}

	/**
	 * Default options (radio defaults per group) for a technique — PHP twin of shared/config.js defaultOptions().
	 *
	 * @param array  $config Config.
	 * @param string $tech   Technique id.
	 * @return string[]
	 */
	public static function default_options( array $config, string $tech ): array {
		$t      = $config['techniques'][ $tech ] ?? array();
		$out    = array();
		$groups = isset( $config['optionGroups'] ) && is_array( $config['optionGroups'] ) ? $config['optionGroups'] : array();
		$opts   = array();
		foreach ( (array) ( $config['options'] ?? array() ) as $o ) {
			if ( is_array( $o ) && ( $o['enabled'] ?? true ) !== false && in_array( $tech, (array) ( $o['techniques'] ?? array() ), true ) ) {
				$opts[] = $o;
			}
		}
		foreach ( $groups as $gid => $g ) {
			if ( ! is_array( $g ) || 'radio' !== ( $g['type'] ?? '' ) ) {
				continue;
			}
			if ( ! empty( $g['showIfAny'] ) && ! array_intersect( (array) $g['showIfAny'], $out ) ) {
				continue;
			}
			$in_group = array_values( array_filter( $opts, static fn( $o ) => ( $o['group'] ?? '' ) === $gid ) );
			if ( ! $in_group ) {
				continue;
			}
			$ids  = array_column( $in_group, 'id' );
			$pref = $t['defaults'][ $gid ] ?? null;
			if ( ! $pref ) {
				foreach ( $in_group as $o ) {
					if ( ! empty( $o['default'] ) ) {
						$pref = $o['id'];
						break;
					}
				}
			}
			if ( $pref && in_array( $pref, $ids, true ) ) {
				$out[] = $pref;
			} elseif ( ! empty( $g['required'] ) ) {
				$out[] = $ids[0];
			}
		}
		return $out;
	}

	/**
	 * Text from a {nl,fr} label by locale.
	 *
	 * @param mixed  $obj    Label.
	 * @param string $locale Locale.
	 */
	public static function label( $obj, string $locale = 'nl' ): string {
		if ( is_string( $obj ) ) {
			return $obj;
		}
		if ( ! is_array( $obj ) ) {
			return '';
		}
		$nl = (string) ( $obj['nl'] ?? '' );
		$fr = (string) ( $obj['fr'] ?? '' );
		if ( 0 === stripos( $locale, 'fr' ) ) {
			return '' !== $fr ? $fr : $nl;
		}
		return '' !== $nl ? $nl : $fr;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------- */

	/**
	 * Read and decode a JSON file; empty array on failure.
	 *
	 * @param string $file Path.
	 */
	private static function read_json( string $file ): array {
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( 'Patch Studio: invalid JSON in ' . $file . ': ' . json_last_error_msg() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return array();
		}
		return $data;
	}

	/**
	 * Remove a path (with '*' wildcards) from an overrides array; prunes empty parents.
	 *
	 * @param array    $data Overrides.
	 * @param string[] $segs Segments.
	 */
	private static function unset_path( array $data, array $segs ): array {
		$seg = array_shift( $segs );
		$keys = '*' === $seg ? array_keys( $data ) : ( array_key_exists( $seg, $data ) ? array( $seg ) : array() );
		foreach ( $keys as $key ) {
			if ( array() === $segs ) {
				unset( $data[ $key ] );
			} elseif ( is_array( $data[ $key ] ) && ! array_is_list( $data[ $key ] ) ) {
				$data[ $key ] = self::unset_path( $data[ $key ], $segs );
				if ( array() === $data[ $key ] ) {
					unset( $data[ $key ] );
				}
			} elseif ( is_array( $data[ $key ] ) ) {
				// A list is stored as a whole; resetting part of it resets the list.
				unset( $data[ $key ] );
			}
		}
		return $data;
	}

	/**
	 * Whether a concrete path matches a pattern with '*' segments.
	 *
	 * @param string $path    Path.
	 * @param string $pattern Pattern.
	 */
	private static function path_matches( string $path, string $pattern ): bool {
		$p = explode( '.', $path );
		$q = explode( '.', $pattern );
		if ( count( $p ) !== count( $q ) ) {
			return false;
		}
		foreach ( $q as $i => $seg ) {
			if ( '*' !== $seg && (string) $seg !== (string) $p[ $i ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Find the first rule for a path in a pattern map.
	 *
	 * @param string $path Path.
	 * @param array  $map  Pattern => rule.
	 * @return mixed|null
	 */
	private static function rule_for( string $path, array $map ) {
		foreach ( $map as $pattern => $rule ) {
			if ( self::path_matches( $path, $pattern ) ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Sanitize a submitted value against the shape of the default value.
	 * Unknown keys are dropped, types are coerced, numbers clamped, enums checked.
	 *
	 * @param mixed  $value   Submitted.
	 * @param mixed  $default Default with the same path.
	 * @param string $path    Dot path (list items use their index).
	 * @return mixed Clean value or self::SKIP.
	 */
	private static function sanitize_value( $value, $default, string $path ) {
		$leaf = '' === $path ? '' : substr( (string) strrchr( '.' . $path, '.' ), 1 );

		// Free-form string map for JS string overrides.
		if ( 'strings' === $path ) {
			return is_array( $value ) ? self::sanitize_free( $value, $path ) : self::SKIP;
		}

		if ( self::is_assoc( $default ) ) {
			if ( ! is_array( $value ) ) {
				return self::SKIP;
			}
			$out = array();
			foreach ( $value as $key => $v ) {
				$key = (string) $key;
				if ( '' === $key ) {
					continue;
				}
				if ( '_' === $key[0] ) {
					// Notes are documentation: never editable, but keep the shipped text (list items are stored whole).
					if ( array_key_exists( $key, $default ) ) {
						$out[ $key ] = $default[ $key ];
					}
					continue;
				}
				if ( '' === $path && in_array( $key, self::NON_STORABLE, true ) ) {
					continue;
				}
				if ( '' === $path && 'strings' === $key ) {
					$clean = self::sanitize_value( $v, array(), 'strings' );
				} elseif ( array_key_exists( $key, $default ) ) {
					$clean = self::sanitize_value( $v, $default[ $key ], '' === $path ? $key : $path . '.' . $key );
				} elseif ( array_key_exists( (int) $key, $default ) && is_numeric( $key ) ) {
					$clean = self::sanitize_value( $v, $default[ (int) $key ], '' === $path ? $key : $path . '.' . $key );
				} else {
					continue;
				}
				if ( self::SKIP !== $clean ) {
					$out[ $key ] = $clean;
				}
			}
			return $out;
		}

		if ( is_array( $default ) ) { // List (possibly empty).
			if ( ! is_array( $value ) ) {
				return self::SKIP;
			}
			$template = null;
			foreach ( $default as $item ) {
				$template = $item;
				break;
			}
			$by_id = array();
			foreach ( $default as $item ) {
				if ( is_array( $item ) && isset( $item['id'] ) ) {
					$by_id[ (string) $item['id'] ] = $item;
				}
			}
			$out = array();
			foreach ( array_values( $value ) as $i => $item ) {
				if ( self::is_assoc( $template ) ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$tpl = isset( $item['id'] ) && isset( $by_id[ (string) $item['id'] ] ) ? $by_id[ (string) $item['id'] ] : $template;
					// New items may define every key the template has; keep fields the template lacks out.
					$clean = self::sanitize_value( $item, $tpl, $path . '.' . $i );
				} elseif ( null === $template ) {
					$clean = is_scalar( $item ) ? sanitize_text_field( (string) $item ) : self::SKIP;
				} else {
					$clean = self::sanitize_value( $item, $template, $path . '.' . $i );
				}
				if ( self::SKIP !== $clean && '' !== $clean ) {
					$out[] = $clean;
				}
			}
			return $out;
		}

		if ( null === $default ) {
			return self::sanitize_loose( $value, $path, $leaf );
		}

		if ( is_bool( $default ) ) {
			return self::to_bool( $value );
		}

		if ( is_int( $default ) || is_float( $default ) ) {
			$num = self::to_number( $value );
			if ( null === $num ) {
				return self::SKIP;
			}
			return self::clamp( $num, $path );
		}

		if ( is_string( $default ) ) {
			if ( ! is_scalar( $value ) ) {
				return self::SKIP;
			}
			return self::sanitize_string( (string) $value, $path, $leaf );
		}

		return self::SKIP;
	}

	/**
	 * Values whose default is null (e.g. proofDays, basePrice of quote-only techniques, colors.max).
	 *
	 * @param mixed  $value Value.
	 * @param string $path  Path.
	 * @param string $leaf  Last segment.
	 * @return mixed
	 */
	private static function sanitize_loose( $value, string $path, string $leaf ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$clean = self::sanitize_loose( $v, $path . '.' . $k, (string) $k );
				if ( self::SKIP !== $clean && null !== $clean ) {
					$out[ $k ] = $clean;
				}
			}
			return $out;
		}
		$num = self::to_number( $value );
		if ( null !== $num ) {
			return self::clamp( $num, $path );
		}
		return is_scalar( $value ) ? self::sanitize_string( (string) $value, $path, $leaf ) : self::SKIP;
	}

	/**
	 * Free-form nested string map (JS string overrides).
	 *
	 * @param array  $value Value.
	 * @param string $path  Path.
	 */
	private static function sanitize_free( array $value, string $path ): array {
		$out = array();
		foreach ( $value as $k => $v ) {
			$k = preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $k );
			if ( '' === $k ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$out[ $k ] = self::sanitize_free( $v, $path . '.' . $k );
			} elseif ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
				$out[ $k ] = sanitize_textarea_field( (string) $v );
			}
		}
		return $out;
	}

	/**
	 * Strings: enums, URLs, colors, e-mail, phone, plain text.
	 *
	 * @param string $value Value.
	 * @param string $path  Path.
	 * @param string $leaf  Last segment.
	 * @return string|string
	 */
	private static function sanitize_string( string $value, string $path, string $leaf ) {
		$enum = self::rule_for( $path, self::ENUMS );
		if ( is_array( $enum ) ) {
			$value = trim( $value );
			return in_array( $value, $enum, true ) ? $value : self::SKIP;
		}
		if ( preg_match( '/Url$/', $leaf ) ) {
			$value = trim( $value );
			return '' === $value ? '' : esc_url_raw( $value );
		}
		if ( in_array( $leaf, array( 'hex', 'engrave', 'garmentHex', 'swatch' ), true ) ) {
			$hex = sanitize_hex_color( trim( $value ) );
			return $hex ? strtolower( $hex ) : self::SKIP;
		}
		if ( 'email' === $leaf || 'quoteRecipient' === $leaf ) {
			$value = trim( $value );
			if ( '' === $value ) {
				return '';
			}
			$email = sanitize_email( $value );
			return is_email( $email ) ? $email : self::SKIP;
		}
		if ( 'whatsapp' === $leaf ) {
			return preg_replace( '/\D+/', '', $value );
		}
		if ( 'id' === $leaf ) {
			return sanitize_key( $value );
		}
		if ( preg_match( '/(^|\.)(testimonial|deadline)\.text\.(nl|fr)$/', $path ) ) {
			return sanitize_textarea_field( $value );
		}
		return sanitize_text_field( $value );
	}

	/**
	 * @param mixed $value Value.
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on', 'ja' ), true );
		}
		return (bool) $value;
	}

	/**
	 * Parse numbers, accepting a Belgian decimal comma ("5,20") and "€".
	 *
	 * @param mixed $value Value.
	 * @return int|float|null
	 */
	private static function to_number( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return is_finite( (float) $value ) ? $value : null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$s = str_replace( array( '€', ' ', "\xC2\xA0", '%' ), '', trim( $value ) );
		if ( preg_match( '/^-?\d{1,3}(\.\d{3})+,\d+$/', $s ) ) {
			$s = str_replace( '.', '', $s ); // 1.234,56
		}
		$s = str_replace( ',', '.', $s );
		if ( '' === $s || ! is_numeric( $s ) ) {
			return null;
		}
		$f = (float) $s;
		return ( floor( $f ) === $f && abs( $f ) < PHP_INT_MAX && false === strpos( $s, '.' ) ) ? (int) $f : $f;
	}

	/**
	 * @param int|float $num  Number.
	 * @param string    $path Path.
	 * @return int|float
	 */
	private static function clamp( $num, string $path ) {
		$limit = self::rule_for( $path, self::LIMITS );
		if ( is_array( $limit ) ) {
			$num = max( $limit[0], min( $limit[1], $num ) );
		}
		return is_float( $num ) ? round( $num, 6 ) : $num;
	}

	/**
	 * Cross-field rules that keep the config coherent (so the tool never shows a wrong price).
	 *
	 * @param array $c Full config.
	 */
	private static function enforce( array $c ): array {
		$priced = array_map( 'strval', (array) ( $c['sizes']['pricedCm'] ?? array( 4, 6, 8, 10, 12 ) ) );

		foreach ( (array) ( $c['techniques'] ?? array() ) as $id => $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			$label = self::label( $t['label'] ?? $id );

			// Online ordering needs a price for every priced size.
			if ( 'order' === ( $t['mode'] ?? '' ) ) {
				$prices  = is_array( $t['basePrice'] ?? null ) ? $t['basePrice'] : array();
				$missing = array_filter( $priced, static fn( $cm ) => ! isset( $prices[ $cm ] ) || ! is_numeric( $prices[ $cm ] ) || (float) $prices[ $cm ] <= 0 );
				if ( $missing ) {
					$c['techniques'][ $id ]['mode'] = 'quote';
					self::notice(
						'warning',
						sprintf(
							/* translators: %s: technique name */
							__( '%s staat op "Offerte op aanvraag": vul eerst een prijs in voor elke maat om online bestellen aan te zetten.', 'borduur-patch-studio' ),
							$label
						)
					);
				} elseif ( empty( $t['maxOrderCm'] ) ) {
					$c['techniques'][ $id ]['maxOrderCm'] = (float) max( $priced );
				}
			}

			// The minimum must be reachable with the step (PVC: 50 per 10).
			$step = max( 1, (int) ( $t['qtyStep'] ?? 1 ) );
			$min  = max( 1, (int) ( $t['minQty'] ?? 1 ) );
			if ( $step > 1 && 0 !== $min % $step ) {
				$c['techniques'][ $id ]['minQty'] = (int) ( ceil( $min / $step ) * $step );
				self::notice(
					'info',
					sprintf(
						/* translators: 1: technique, 2: new minimum */
						__( 'Minimum voor %1$s aangepast naar %2$d zodat het past bij de stapgrootte.', 'borduur-patch-studio' ),
						$label,
						$c['techniques'][ $id ]['minQty']
					)
				);
			}
		}

		// Tiers: sorted, unique, always starting at 1 piece without discount.
		$steps = array();
		foreach ( (array) ( $c['tiers']['steps'] ?? array() ) as $s ) {
			if ( is_array( $s ) && isset( $s['min'] ) ) {
				$steps[ (int) $s['min'] ] = array(
					'min'      => (int) $s['min'],
					'discount' => round( (float) ( $s['discount'] ?? 0 ), 4 ),
				);
			}
		}
		$steps[1] = array(
			'min'      => 1,
			'discount' => 0,
		);
		ksort( $steps );
		$c['tiers']['steps'] = array_values( $steps );

		// Percent options are fractions between -90 % and +90 %.
		foreach ( (array) ( $c['options'] ?? array() ) as $i => $o ) {
			if ( is_array( $o ) && 'percent' === ( $o['price']['type'] ?? '' ) ) {
				$amount                               = max( -0.9, min( 0.9, (float) $o['price']['amount'] ) );
				$c['options'][ $i ]['price']['amount'] = $amount;
				// Keep a percentage mentioned in the label truthful, e.g. "Ik knip ze zelf uit (−10 %)".
				$pct = rtrim( rtrim( number_format( abs( $amount ) * 100, 2, ',', '' ), '0' ), ',' );
				$neg = $amount < 0 ? '−' : '+';
				foreach ( array( 'nl', 'fr' ) as $lng ) {
					if ( isset( $o['label'][ $lng ] ) && is_string( $o['label'][ $lng ] ) ) {
						$c['options'][ $i ]['label'][ $lng ] = preg_replace( '/\((?:−|-|\+)\s?\d+(?:[.,]\d+)?\s?%\)/u', '(' . $neg . $pct . ' %)', $o['label'][ $lng ] );
					}
				}
			}
		}

		// v1 only supports the own-product cart model (SPEC D1).
		if ( isset( $c['woo']['cartMode'] ) && 'own_product' !== $c['woo']['cartMode'] ) {
			$c['woo']['cartMode'] = 'own_product';
			self::notice( 'info', __( 'Bestellen via de bestaande producten komt in een volgende versie. Patch Studio gebruikt voorlopig zijn eigen product.', 'borduur-patch-studio' ) );
		}

		if ( isset( $c['shipping']['hintFromInclVat'], $c['shipping']['freeFromInclVat'] ) && $c['shipping']['hintFromInclVat'] > $c['shipping']['freeFromInclVat'] ) {
			$c['shipping']['hintFromInclVat'] = $c['shipping']['freeFromInclVat'];
		}

		// A technique's default fabric must still exist in its fabric list.
		foreach ( (array) ( $c['techniques'] ?? array() ) as $id => $t ) {
			$list_key = is_array( $t ) ? (string) ( $t['fabricList'] ?? 'fabrics' ) : '';
			$fabric   = is_array( $t ) ? (string) ( $t['defaultFabric'] ?? 'auto' ) : 'auto';
			if ( 'auto' === $fabric || ! isset( $c[ $list_key ] ) || ! is_array( $c[ $list_key ] ) ) {
				continue;
			}
			$ids = array_column( $c[ $list_key ], 'id' );
			if ( $ids && ! in_array( $fabric, $ids, true ) ) {
				$c['techniques'][ $id ]['defaultFabric'] = (string) $ids[0];
			}
		}

		$enabled = array_filter( (array) ( $c['techniques'] ?? array() ), static fn( $t ) => is_array( $t ) && ! empty( $t['enabled'] ) );
		if ( ! $enabled ) {
			self::notice( 'warning', __( 'Alle technieken staan uit. Klanten kunnen dan niets bestellen of aanvragen.', 'borduur-patch-studio' ) );
		}

		return $c;
	}

	/**
	 * @param string $type    'warning' | 'info' | 'error'.
	 * @param string $message Message.
	 */
	private static function notice( string $type, string $message ): void {
		self::$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
	}
}
