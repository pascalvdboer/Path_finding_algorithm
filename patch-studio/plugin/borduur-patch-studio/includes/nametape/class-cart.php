<?php
/**
 * Name tapes in the WooCommerce cart and order (SPEC-EXTRA §1.8): one line per list, quantity locked at 1,
 * absolute line price from the server engine, display rows, order meta.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Nametape;

use BorduurPatchStudio\Logger;
use BorduurPatchStudio\Woo\Cart as WooCart;
use BorduurPatchStudio\Woo\Config as WooConfig;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Cart item data key: `bps_nt`.
 */
final class Cart {

	const KEY        = 'bps_nt';
	const FEE_ID     = 'bps-nt-setup';
	const CS_PATCHES = array( 'cap', 'beanie', 'hoodie', 'frame', 'leather_patch_cap' );

	/** @var bool Set while we add a line ourselves (bypasses the direct-add block). */
	public static $internal_add = false;

	/** @var array<string,array> Quote per cart key in this request. */
	private static $quotes = array();

	/** @var array<string,bool> Lines whose price could not be computed in this request. */
	private static $price_errors = array();

	/** Rights sentence shown next to the checkbox (nt.s3.rights in assets/src/nametape/messages.js). */
	const RIGHTS_TEXT = array(
		'nl' => 'Ik bevestig dat ik deze namen en teksten mag laten borduren.',
		'fr' => 'Je confirme avoir le droit de faire broder ces noms et textes.',
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_prices' ), 25 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_fees' ), 25 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart_items' ), 25 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'drop_orphans' ), 25 );
		// Mini-cart / Store API requests only load the cart: price the lines right away (woo-compat-2).
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'set_prices' ), 30 );
		add_filter( 'bps_cart_pool_qty', array( __CLASS__, 'pool_filter' ), 10, 2 );

		add_filter( 'advanced_woo_discount_rules_calculate_discount_for_cart_item', array( __CLASS__, 'wdr_exclude' ), 10, 2 );
		add_filter( 'advanced_woo_discount_rules_include_cart_item_to_count_quantity', array( __CLASS__, 'wdr_exclude' ), 10, 2 );

		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'quantity_html' ), 20, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_editable', array( __CLASS__, 'store_api_editable' ), 20, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', array( __CLASS__, 'store_api_one' ), 20, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( __CLASS__, 'update_cart_validation' ), 10, 4 );

		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( __CLASS__, 'thumbnail' ), 10, 2 );
		add_filter( 'woocommerce_store_api_cart_item_images', array( __CLASS__, 'store_api_images' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_permalink', array( __CLASS__, 'permalink' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'item_name' ), 10, 3 );

		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line_item' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'order_created' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'order_created' ), 10, 1 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( __CLASS__, 'meta_label' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Batch ↔ cart data
	 * ------------------------------------------------------------------- */

	/**
	 * Compact cart data from a validated batch.
	 *
	 * @param array  $b        Validated batch.
	 * @param array  $rights   {v}.
	 * @param string $preview  Preview URL.
	 * @param string $file     Preview file name.
	 * @param array  $review   Review words found.
	 * @param int    $pieces   Pieces (main + blood).
	 */
	public static function data_from_batch( array $b, array $rights, string $preview, string $file, array $review, int $pieces ): array {
		$rows = array();
		foreach ( $b['rows'] as $r ) {
			$rows[] = array(
				'n'  => (string) $r['name'],
				'l2' => (string) $r['line2'],
				'b'  => $r['blood'] ? $r['blood']['group'] . $r['blood']['rh'] : '',
				'q'  => (int) $r['qty'],
			);
		}
		return array(
			'v'           => 1,
			'batch_id'    => bin2hex( random_bytes( 8 ) ),
			'locale'      => (string) $b['locale'],
			'format'      => (string) $b['format'],
			'custom'      => $b['custom'],
			'fabric'      => (string) $b['fabric'],
			'letter'      => (string) $b['letter'],
			'border'      => (string) $b['border'],
			'borderColor' => $b['borderColor'],
			'font'        => (string) $b['font'],
			'uppercase'   => (bool) $b['uppercase'],
			'backing'     => (string) $b['backing'],
			'loop'        => (bool) $b['loop'],
			'bloodMode'   => $b['bloodMode'],
			'rows'        => $rows,
			'pieces'      => $pieces,
			'review'      => (bool) $review,
			'review_words' => array_values( array_map( 'strval', $review ) ),
			'org_note'    => (string) $b['orgNote'],
			'preview_url' => $preview,
			'preview_file' => $file,
			'rights'      => self::rights_record( $rights, (string) $b['locale'] ),
		) + ( isset( $b['bloodConsent'] ) && is_array( $b['bloodConsent'] ) ? array( 'bloodConsent' => $b['bloodConsent'] ) : array() );
	}

	/**
	 * Rights record (gdpr-juridisch-8): server text version, SHA-1 of the sentence that was shown, time, locale.
	 * A different client version is only kept as client_v.
	 *
	 * @param array  $rights Client {v, accepted}.
	 * @param string $locale Batch locale (nl-BE | fr-BE).
	 */
	public static function rights_record( array $rights, string $locale ): array {
		$server = substr( (string) WooConfig::path( 'legal.rightsTextVersion', '' ), 0, 20 );
		$client = substr( sanitize_text_field( (string) ( $rights['v'] ?? '' ) ), 0, 20 );
		$out    = array(
			'v'         => $server,
			'at'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'text_sha1' => sha1( self::rights_text( $locale ) ),
			'locale'    => 0 === stripos( $locale, 'fr' ) ? 'fr_BE' : 'nl_BE',
		);
		if ( '' !== $client && $client !== $server ) {
			$out['client_v'] = $client;
		}
		return $out;
	}

	/**
	 * The rights sentence of the name-tape studio (same text as nt.s3.rights in messages.js).
	 *
	 * @param string $locale Locale.
	 */
	public static function rights_text( string $locale = '' ): string {
		return 0 === stripos( $locale, 'fr' ) ? self::RIGHTS_TEXT['fr'] : self::RIGHTS_TEXT['nl'];
	}

	/**
	 * Batch (validator shape) from cart data.
	 *
	 * @param array $nt Cart data.
	 */
	public static function batch_from_data( array $nt ): array {
		return Production::batch_from_data( $nt );
	}

	/* ---------------------------------------------------------------------
	 * Pool / context
	 * ------------------------------------------------------------------- */

	/**
	 * Does a patch technique take part in the tier pool?
	 *
	 * @param string $technique Technique id.
	 */
	private static function technique_in_pool( string $technique ): bool {
		$t = WooConfig::technique( $technique );
		return is_array( $t ) && ! empty( $t['tiers'] ) && (bool) WooConfig::path( 'tiers.pooled', true );
	}

	/**
	 * Patch pieces in the cart that count for the patch tier pool (patch lines + patches on cross-sell carriers).
	 *
	 * @param array $items Cart contents.
	 */
	public static function patch_pool( array $items ): int {
		$pool = 0;
		foreach ( $items as $item ) {
			if ( ! empty( $item['bps']['technique'] ) && self::technique_in_pool( (string) $item['bps']['technique'] ) ) {
				$pool += (int) $item['quantity'];
			} elseif ( ! empty( $item['bps_cs']['item'] ) && in_array( (string) $item['bps_cs']['item'], self::CS_PATCHES, true )
				&& self::technique_in_pool( (string) ( $item['bps_cs']['technique'] ?? '' ) ) ) {
				$pool += (int) $item['quantity'];
			}
		}
		return (int) apply_filters( 'bps_nametape_patch_pool', $pool, $items );
	}

	/**
	 * Name-tape pieces in the cart, optionally excluding one line.
	 *
	 * @param array  $items  Cart contents.
	 * @param string $except Cart key to skip.
	 */
	public static function nametape_pieces( array $items, string $except = '' ): int {
		$n = 0;
		foreach ( $items as $key => $item ) {
			if ( (string) $key !== $except && ! empty( $item[ self::KEY ] ) ) {
				$n += (int) ( $item[ self::KEY ]['pieces'] ?? 0 );
			}
		}
		return $n;
	}

	/**
	 * Articles incl. VAT of the other lines (for the shipping estimate).
	 *
	 * @param array  $items  Cart contents.
	 * @param string $except Cart key to skip.
	 */
	private static function other_items_incl( array $items, string $except ): float {
		$sum = 0.0;
		foreach ( $items as $key => $item ) {
			if ( (string) $key === $except ) {
				continue;
			}
			$sum += (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 );
		}
		return $sum;
	}

	/**
	 * Pricing context for a line in the current cart.
	 *
	 * @param array  $items  Cart contents.
	 * @param string $except Cart key of the line itself ('' for a new list).
	 * @param string $locale Locale.
	 */
	public static function context( array $items, string $except, string $locale ): array {
		return array(
			'patchPoolQty'        => self::patch_pool( $items ),
			'otherNametapePieces' => self::nametape_pieces( $items, $except ),
			'otherItemsInclVat'   => self::other_items_incl( $items, $except ),
			'vatRate'             => Config::vat_rate(),
			'tiers'               => Config::tiers(),
			'shipping'            => Config::shipping(),
			'locale'              => $locale,
		);
	}

	/**
	 * Add name-tape pieces to woo's patch pool (handoff filter bps_cart_pool_qty).
	 *
	 * @param int   $pool  Pool.
	 * @param array $items Cart contents.
	 */
	public static function pool_filter( $pool, $items = array() ) {
		if ( false === Config::path( 'tiers.pooledWithPatches', true ) || ! is_array( $items ) ) {
			return $pool;
		}
		return (int) $pool + self::nametape_pieces( $items );
	}

	/* ---------------------------------------------------------------------
	 * Adding
	 * ------------------------------------------------------------------- */

	/**
	 * Add (or replace) a list.
	 *
	 * @param array  $check   Validator result (strict, ok).
	 * @param array  $rights  {v, accepted}.
	 * @param array  $preview {url, file} or empty.
	 * @param string $replace Cart key to replace ('' = new line).
	 * @return array|WP_Error {key, quote}
	 */
	public static function add_batch( array $check, array $rights, array $preview, string $replace = '' ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return new WP_Error( 'bps_unavailable', __( 'De winkelmand is even niet beschikbaar. Probeer het straks opnieuw.', 'borduur-patch-studio' ), array( 'status' => 503 ) );
		}
		$product_id = Product::ensure();
		if ( $product_id <= 0 ) {
			return new WP_Error( 'bps_cart', __( 'De winkelmand is even niet beschikbaar. Probeer het straks opnieuw.', 'borduur-patch-studio' ), array( 'status' => 500 ) );
		}
		$cart  = WC()->cart;
		$items = $cart->get_cart();
		if ( '' !== $replace && empty( $items[ $replace ][ self::KEY ] ) ) {
			$replace = '';
		}
		$b     = $check['batch'];
		// The old line is left out of the context and only removed after the new one was added (never lose a list).
		$quote = Pricing::quote( $b, self::context( $items, $replace, $b['locale'] ), Config::get() );
		if ( empty( $quote['ok'] ) ) {
			return new WP_Error( 'bps_nt_invalid', __( 'Deze lijst kunnen we zo niet bestellen. Kijk de namen en het formaat even na.', 'borduur-patch-studio' ), array( 'status' => 400 ) );
		}
		$data = array(
			self::KEY => self::data_from_batch( $b, $rights, (string) ( $preview['url'] ?? '' ), (string) ( $preview['file'] ?? '' ), (array) $check['review'], (int) $quote['pieces'] ),
		);

		self::$internal_add = true;
		$before             = function_exists( 'wc_get_notices' ) ? count( wc_get_notices( 'error' ) ) : 0;
		$add_error          = '';
		try {
			$key = $cart->add_to_cart( $product_id, 1, 0, array(), $data );
		} catch ( \Exception $e ) {
			$key       = false;
			$add_error = $e->getMessage(); // Logged below.
		}
		self::$internal_add = false;
		if ( ! $key ) {
			$new = function_exists( 'wc_get_notices' ) ? array_slice( wc_get_notices( 'error' ), $before ) : array();
			Logger::warning(
				'nametape_cart_add_failed',
				array(
					'pieces'    => (int) $quote['pieces'],
					'notices'   => array_map( static fn( $n ) => wp_strip_all_tags( (string) ( $n['notice'] ?? '' ) ), $new ),
					'exception' => $add_error,
				)
			);
			if ( function_exists( 'wc_clear_notices' ) ) {
				wc_clear_notices();
			}
			return new WP_Error( 'bps_cart', __( 'Toevoegen aan je winkelmand is niet gelukt. Probeer het nog eens.', 'borduur-patch-studio' ), array( 'status' => 400 ) );
		}
		if ( '' !== $replace && (string) $key !== $replace ) {
			$cart->remove_cart_item( $replace );
		}
		$cart->calculate_totals();
		do_action( 'bps_nametape_added', (string) $key, $b );
		return array(
			'key'   => (string) $key,
			'quote' => self::line_quote( (string) $key ),
		);
	}

	/**
	 * Server quote for an existing line in the context of the cart.
	 *
	 * @param string $key Cart key.
	 */
	public static function line_quote( string $key ): array {
		$items = WC()->cart ? WC()->cart->get_cart() : array();
		if ( empty( $items[ $key ][ self::KEY ] ) ) {
			return array();
		}
		$nt = $items[ $key ][ self::KEY ];
		$q  = Pricing::quote( self::batch_from_data( $nt ), self::context( $items, $key, (string) ( $nt['locale'] ?? 'nl-BE' ) ), Config::get() );
		return (array) apply_filters( 'bps_nametape_quote', $q, $nt );
	}

	/* ---------------------------------------------------------------------
	 * Totals
	 * ------------------------------------------------------------------- */

	/**
	 * Absolute line price (idempotent), priority 25 (after woo's 20) and on cart load. The engine price is excl. VAT;
	 * WooCommerce gets it through Woo\Config::woo_unit_price() (prices entered incl. VAT, woo-compat-1). A failed
	 * price never becomes € 0: the line is flagged, logged and check_cart_items blocks the checkout.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function set_prices( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		$items = $cart->get_cart();
		$cfg   = Config::get();
		foreach ( $items as $key => $item ) {
			if ( empty( $item[ self::KEY ] ) || ! isset( $item['data'] ) || ! $item['data'] instanceof \WC_Product ) {
				continue;
			}
			$nt   = $item[ self::KEY ];
			$line = null;
			$why  = '';
			try {
				$q = Pricing::quote( self::batch_from_data( $nt ), self::context( $items, (string) $key, (string) ( $nt['locale'] ?? 'nl-BE' ) ), $cfg );
				self::$quotes[ (string) $key ] = $q;
				if ( ! empty( $q['ok'] ) ) {
					$line = (float) $q['lineExcl'];
				} else {
					$why = 'quote_not_ok: ' . implode( ',', array_map( static fn( $e ) => is_array( $e ) ? (string) ( $e['code'] ?? '' ) : (string) $e, (array) ( $q['errors'] ?? array() ) ) );
				}
			} catch ( \Throwable $e ) {
				$why = $e->getMessage(); // Logged with context below (nametape_price_failed).
			}
			if ( null === $line || ! is_finite( $line ) || $line <= 0 ) {
				if ( ! isset( self::$price_errors[ (string) $key ] ) ) {
					Logger::error(
						'nametape_price_failed',
						array(
							'batch'  => (string) ( $nt['batch_id'] ?? '' ),
							'format' => (string) ( $nt['format'] ?? '' ),
							'pieces' => (int) ( $nt['pieces'] ?? 0 ),
							'error'  => '' !== $why ? $why : 'no_price',
						)
					);
				}
				self::$price_errors[ (string) $key ] = true;
				if ( isset( $cart->cart_contents[ $key ] ) ) {
					$cart->cart_contents[ $key ]['bps_price_error'] = true;
				}
				// Keep the last good price (session) so the line never shows € 0; checkout stays blocked.
				$last = (float) ( $item['bps_line_excl'] ?? 0 );
				if ( $last > 0 ) {
					WooCart::apply_price( $item['data'], WooConfig::woo_unit_price( $last / max( 1, (int) $item['quantity'] ), $item['data'] ) );
				}
				continue;
			}
			unset( self::$price_errors[ (string) $key ] );
			if ( isset( $cart->cart_contents[ $key ] ) ) {
				unset( $cart->cart_contents[ $key ]['bps_price_error'] );
				$cart->cart_contents[ $key ]['bps_line_excl'] = $line;
			}
			$qty = max( 1, (int) $item['quantity'] );
			WooCart::apply_price( $item['data'], WooConfig::woo_unit_price( $line / $qty, $item['data'] ) );
		}
	}

	/**
	 * Quote computed for a line in this request (null when not computed).
	 *
	 * @param string $key Cart key.
	 */
	public static function computed_quote( string $key ): ?array {
		return self::$quotes[ $key ] ?? null;
	}

	/**
	 * Small-order fee once per order (if enabled).
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function add_fees( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		$sf = (array) Config::path( 'smallOrderFee', array() );
		if ( empty( $sf['enabled'] ) ) {
			return;
		}
		$pieces = self::nametape_pieces( $cart->get_cart() );
		if ( $pieces <= 0 || $pieces >= (int) ( $sf['belowQty'] ?? 0 ) ) {
			return;
		}
		$amount = Fit::round_half_up( (float) ( $sf['amountExcl'] ?? 0 ), 2 );
		if ( $amount <= 0 ) {
			return;
		}
		foreach ( $cart->fees_api()->get_fees() as $fee ) {
			if ( self::FEE_ID === ( $fee->id ?? '' ) ) {
				return;
			}
		}
		$cart->fees_api()->add_fee(
			array(
				'id'        => self::FEE_ID,
				'name'      => Config::label( $sf['label'] ?? '', null ),
				'amount'    => $amount,
				'taxable'   => true,
				'tax_class' => '',
			)
		);
	}

	/**
	 * Woo Discount Rules: never on our lines (our own tiers already apply).
	 *
	 * @param mixed $value     Value.
	 * @param mixed $cart_item Cart item.
	 */
	public static function wdr_exclude( $value, $cart_item = null ) {
		return is_array( $cart_item ) && ! empty( $cart_item[ self::KEY ] ) ? false : $value;
	}

	/* ---------------------------------------------------------------------
	 * Validation
	 * ------------------------------------------------------------------- */

	/**
	 * Lines of our product without list data (added by another plugin) are removed.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function drop_orphans( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item[ self::KEY ] ) && Product::is_ours( (int) ( $item['product_id'] ?? 0 ) ) ) {
				$cart->remove_cart_item( $key );
			}
		}
	}

	/**
	 * Re-validate lists before checkout (config may have changed since).
	 */
	public static function check_cart_items(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$items = WC()->cart->get_cart();
		foreach ( $items as $key => $item ) {
			if ( empty( $item[ self::KEY ] ) ) {
				continue;
			}
			if ( isset( self::$price_errors[ (string) $key ] ) || ! empty( $item['bps_price_error'] ) ) {
				$tx = self::texts( $item[ self::KEY ] );
				wc_add_notice(
					esc_html(
						sprintf(
							'fr' === Config::lang( null )
								? 'Nous n’avons pas pu calculer le prix de votre liste de bandes nominatives (%s). Réessayez ou contactez-nous.'
								: 'We konden de prijs van je naamstrooklijst (%s) niet berekenen. Probeer opnieuw of neem contact op.',
							$tx['summary']
						)
					),
					'error'
				);
				continue;
			}
			$r = Validator::validate( self::batch_from_data( $item[ self::KEY ] ), Config::get(), Config::metrics(), true );
			if ( $r['ok'] ) {
				// A list that validates but cannot be priced would cost € 0 (set_prices): never let that reach checkout.
				$q = self::computed_quote( (string) $key );
				if ( null === $q ) {
					$nt = $item[ self::KEY ];
					$q  = Pricing::quote( self::batch_from_data( $nt ), self::context( $items, (string) $key, (string) ( $nt['locale'] ?? 'nl-BE' ) ), Config::get() );
				}
				$r['ok'] = ! empty( $q['ok'] ) && (float) ( $q['lineExcl'] ?? 0 ) > 0;
			}
			if ( ! $r['ok'] ) {
				$url = add_query_arg( 'bps_nt_edit', rawurlencode( (string) $key ), Config::page_url() ? Config::page_url() : home_url( '/' ) );
				wc_add_notice(
					sprintf(
						/* translators: %s: link "Namen wijzigen" */
						__( 'Een van je naamstrooklijsten past niet meer bij ons aanbod (bv. een stofkleur is uitverkocht). %s', 'borduur-patch-studio' ),
						'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Namen wijzigen', 'borduur-patch-studio' ) . '</a>'
					),
					'error'
				);
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Quantity lock
	 * ------------------------------------------------------------------- */

	/**
	 * Classic cart: "1 lijst" instead of a quantity input.
	 *
	 * @param string $html      Quantity HTML.
	 * @param string $key       Cart key.
	 * @param array  $cart_item Cart item.
	 */
	public static function quantity_html( $html, $key = '', $cart_item = array() ) {
		if ( ! is_array( $cart_item ) || empty( $cart_item[ self::KEY ] ) ) {
			return $html;
		}
		return '<span class="bps-nt-qty">' . esc_html__( '1 lijst', 'borduur-patch-studio' ) . '</span><input type="hidden" name="cart[' . esc_attr( (string) $key ) . '][qty]" value="1">';
	}

	/**
	 * Block cart: quantity not editable.
	 *
	 * @param mixed       $value     Value.
	 * @param \WC_Product $product   Product.
	 * @param array|null  $cart_item Cart item.
	 */
	public static function store_api_editable( $value, $product = null, $cart_item = null ) {
		if ( is_array( $cart_item ) && ! empty( $cart_item[ self::KEY ] ) ) {
			return false;
		}
		if ( $product instanceof \WC_Product && Product::is_ours( (int) $product->get_id() ) ) {
			return false;
		}
		return $value;
	}

	/**
	 * Block cart: max 1.
	 *
	 * @param mixed       $value     Value.
	 * @param \WC_Product $product   Product.
	 * @param array|null  $cart_item Cart item.
	 */
	public static function store_api_one( $value, $product = null, $cart_item = null ) {
		if ( ( is_array( $cart_item ) && ! empty( $cart_item[ self::KEY ] ) ) || ( $product instanceof \WC_Product && Product::is_ours( (int) $product->get_id() ) ) ) {
			return 1;
		}
		return $value;
	}

	/**
	 * Refuse quantity changes on a list line.
	 *
	 * @param bool   $passed   Passed.
	 * @param string $key      Cart key.
	 * @param array  $values   Cart item.
	 * @param int    $quantity New quantity.
	 */
	public static function update_cart_validation( $passed, $key, $values, $quantity ) {
		if ( is_array( $values ) && ! empty( $values[ self::KEY ] ) && 1 !== (int) $quantity ) {
			wc_add_notice( __( 'Een naamstrooklijst telt als 1 lijst. Aantallen per persoon pas je aan via "Namen wijzigen".', 'borduur-patch-studio' ), 'notice' );
			return false;
		}
		return $passed;
	}

	/* ---------------------------------------------------------------------
	 * Display
	 * ------------------------------------------------------------------- */

	/**
	 * Human texts for a list.
	 *
	 * @param array  $nt     Cart data.
	 * @param string $locale Locale.
	 * @return array{summary:string,style:string,backing:string,names:string,count:string,blood:string}
	 */
	public static function texts( array $nt, string $locale = '' ): array {
		$cfg    = Config::get();
		$lang   = Config::lang( '' !== $locale ? $locale : (string) ( $nt['locale'] ?? '' ) );
		$fr     = 'fr' === $lang;
		$b      = self::batch_from_data( $nt );
		$label  = static fn( $list, $id ) => ( $x = Fit::find( $cfg, $list, (string) $id ) ) ? Pricing::label( $x['label'] ?? $id, $lang ) : (string) $id;
		$format = 'custom' === $b['format'] && is_array( $b['custom'] )
			? str_replace( '.', ',', (string) ( $b['custom']['w'] / 10 ) ) . ' × ' . str_replace( '.', ',', (string) ( $b['custom']['h'] / 10 ) ) . ' cm'
			: $label( 'formats', $b['format'] );
		$main   = 0;
		$blood  = 0;
		$names  = array();
		foreach ( $b['rows'] as $r ) {
			$main += (int) $r['qty'];
			if ( $r['blood'] ) {
				$blood += (int) $r['qty'];
			}
			$names[] = Fit::apply_case( $r['name'], $b['uppercase'], Fit::find( $cfg, 'fonts', $b['font'] ) );
		}
		$shown = array_slice( $names, 0, 3 );
		$more  = count( $names ) - count( $shown );
		$border = $label( 'borders', $b['border'] );
		$style  = mb_strtolower( $label( 'fabrics', $b['fabric'] ) . ' / ' . $label( 'letterColors', $b['letter'] ), 'UTF-8' ) . ' · ' . mb_strtolower( $label( 'fonts', $b['font'] ), 'UTF-8' ) . ' · ' . mb_strtolower( $border, 'UTF-8' );
		$back   = $label( 'backings', $b['backing'] ) . ( $b['loop'] ? ( $fr ? ' + contre-partie' : ' + tegenstuk' ) : '' );
		$mode   = (string) ( $b['bloodMode'] ?? '' );
		$bl     = '';
		if ( $blood > 0 ) {
			$where = array(
				'separate' => $fr ? 'sur une bande séparée' : 'op eigen strook',
				'line2'    => $fr ? 'sur la ligne 2' : 'op regel 2',
				'inline'   => $fr ? 'après le nom' : 'achter de naam',
			);
			$bl = ( $where[ $mode ] ?? '' ) . ' (' . $blood . '×)';
		}
		return array(
			'summary' => sprintf(
				1 === $main ? ( $fr ? '%1$d bande nominative · %2$s' : '%1$d naamstrook · %2$s' ) : ( $fr ? '%1$d bandes nominatives · %2$s' : '%1$d naamstroken · %2$s' ),
				$main,
				$format
			),
			'style'   => $style,
			'backing' => $back,
			'names'   => implode( ', ', $shown ) . ( $more > 0 ? ' … (+' . $more . ')' : '' ),
			'count'   => (string) count( $names ),
			'blood'   => $bl,
		);
	}

	/**
	 * Cart rows (classic + blocks).
	 *
	 * @param array $item_data Item data.
	 * @param array $cart_item Cart item.
	 */
	public static function item_data( $item_data, $cart_item ) {
		if ( ! is_array( $cart_item ) || empty( $cart_item[ self::KEY ] ) ) {
			return $item_data;
		}
		$item_data = is_array( $item_data ) ? $item_data : array();
		$tx        = self::texts( $cart_item[ self::KEY ], Config::public_locale() );
		$rows      = array(
			array( __( 'Naamstroken', 'borduur-patch-studio' ), $tx['summary'] ),
			array( __( 'Stijl', 'borduur-patch-studio' ), $tx['style'] ),
			array( __( 'Achterkant', 'borduur-patch-studio' ), $tx['backing'] ),
			array( __( 'Namen', 'borduur-patch-studio' ), $tx['names'] ),
		);
		if ( '' !== $tx['blood'] ) {
			$rows[] = array( __( 'Bloedgroep', 'borduur-patch-studio' ), $tx['blood'] );
		}
		$rows[] = array( __( 'Maatwerk', 'borduur-patch-studio' ), __( 'Speciaal voor jou gemaakt, geen herroepingsrecht.', 'borduur-patch-studio' ) );
		foreach ( $rows as $r ) {
			$item_data[] = array(
				'key'   => $r[0],
				'value' => $r[1],
			);
		}
		return $item_data;
	}

	/**
	 * Cart line name with an edit link (classic cart).
	 *
	 * @param string $name      Name HTML.
	 * @param array  $cart_item Cart item.
	 * @param string $key       Cart key.
	 */
	public static function item_name( $name, $cart_item = array(), $key = '' ) {
		if ( ! is_array( $cart_item ) || empty( $cart_item[ self::KEY ] ) || ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return $name;
		}
		$url = self::edit_url( (string) $key );
		if ( '' === $url ) {
			return $name;
		}
		return $name . '<br><a class="bps-nt-edit" href="' . esc_url( $url ) . '">' . esc_html__( 'Namen wijzigen', 'borduur-patch-studio' ) . '</a>';
	}

	/**
	 * Edit URL for a line.
	 *
	 * @param string $key Cart key.
	 */
	public static function edit_url( string $key ): string {
		$page = Config::page_url();
		return '' === $page ? '' : add_query_arg( 'bps_nt_edit', rawurlencode( $key ), $page );
	}

	/**
	 * Permalink → edit page (blocks link the product name).
	 *
	 * @param string $url       URL.
	 * @param array  $cart_item Cart item.
	 */
	public static function permalink( $url, $cart_item = array() ) {
		if ( ! is_array( $cart_item ) || empty( $cart_item[ self::KEY ] ) ) {
			return $url;
		}
		$edit = self::edit_url( (string) ( $cart_item['key'] ?? '' ) );
		return '' !== $edit ? $edit : $url;
	}

	/**
	 * Thumbnail = preview of the first person.
	 *
	 * @param string $html      HTML.
	 * @param array  $cart_item Cart item.
	 */
	public static function thumbnail( $html, $cart_item = array() ) {
		$url = is_array( $cart_item ) ? (string) ( $cart_item[ self::KEY ]['preview_url'] ?? '' ) : '';
		if ( '' === $url ) {
			return $html;
		}
		return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr__( 'Voorbeeld van je naamstrook', 'borduur-patch-studio' ) . '" width="300" style="height:auto;object-fit:contain">';
	}

	/**
	 * Block cart thumbnail.
	 *
	 * @param array $images    Images.
	 * @param array $cart_item Cart item.
	 */
	public static function store_api_images( $images, $cart_item = array() ) {
		$url = is_array( $cart_item ) ? (string) ( $cart_item[ self::KEY ]['preview_url'] ?? '' ) : '';
		if ( '' === $url ) {
			return $images;
		}
		return array(
			(object) array(
				'id'        => 0,
				'src'       => $url,
				'thumbnail' => $url,
				'srcset'    => '',
				'sizes'     => '',
				'name'      => __( 'Naamstrook', 'borduur-patch-studio' ),
				'alt'       => __( 'Voorbeeld van je naamstrook', 'borduur-patch-studio' ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Order
	 * ------------------------------------------------------------------- */

	/**
	 * Order meta (CRUD, HPOS-safe).
	 *
	 * @param \WC_Order_Item_Product $item   Order item.
	 * @param string                 $key    Cart key.
	 * @param array                  $values Cart item.
	 * @param \WC_Order              $order  Order.
	 */
	public static function order_line_item( $item, $key, $values, $order = null ): void {
		if ( ! is_array( $values ) || empty( $values[ self::KEY ] ) || ! is_object( $item ) ) {
			return;
		}
		$nt = $values[ self::KEY ];
		$tx = self::texts( $nt, (string) ( $nt['locale'] ?? 'nl-BE' ) );
		$item->add_meta_data( 'bps_nt_summary', $tx['summary'], true );
		$item->add_meta_data( 'bps_nt_style', $tx['style'], true );
		$item->add_meta_data( 'bps_nt_backing', $tx['backing'], true );
		$item->add_meta_data( 'bps_nt_count', $tx['count'], true );
		if ( '' !== $tx['blood'] ) {
			$item->add_meta_data( 'bps_nt_blood', $tx['blood'], true );
		}
		$item->add_meta_data( '_bps_nt_batch', wp_json_encode( $nt ), true );
		$q = self::computed_quote( (string) $key );
		if ( null === $q && function_exists( 'WC' ) && WC()->cart ) {
			$q = self::line_quote( (string) $key );
		}
		$q = ! empty( $q['ok'] ) ? $q : null;
		$item->add_meta_data( '_bps_nt_unit_excl', $q ? (float) $q['mainUnitExcl'] : 0, true );
		$item->add_meta_data( '_bps_nt_line_excl', $q ? (float) $q['lineExcl'] : 0, true );
		$item->add_meta_data( '_bps_nt_review', ! empty( $nt['review'] ) ? 1 : 0, true );
		$item->add_meta_data( '_bps_nt_schema', 1, true );
		$item->add_meta_data( '_bps_nt_preview', (string) ( $nt['preview_file'] ?? '' ), true );
		$item->add_meta_data( '_bps_rights_consent', wp_json_encode( $nt['rights'] ?? array() ), true );
		// Visible, so the order e-mails also carry the custom-made notice (gdpr-juridisch-4).
		$item->add_meta_data( 'bps_nt_custom', 'fr' === Config::lang( (string) ( $nt['locale'] ?? '' ) ) ? 'Fabriqué spécialement pour vous, pas de droit de rétractation.' : 'Speciaal voor jou gemaakt, geen herroepingsrecht.', true );
	}

	/**
	 * Labels for the visible meta keys.
	 *
	 * @param string $display Display key.
	 * @param object $meta    Meta.
	 * @param object $item    Item.
	 */
	public static function meta_label( $display, $meta = null, $item = null ) {
		$map = array(
			'bps_nt_summary' => __( 'Naamstroken', 'borduur-patch-studio' ),
			'bps_nt_style'   => __( 'Stijl', 'borduur-patch-studio' ),
			'bps_nt_backing' => __( 'Achterkant', 'borduur-patch-studio' ),
			'bps_nt_count'   => __( 'Aantal namen', 'borduur-patch-studio' ),
			'bps_nt_blood'   => __( 'Bloedgroep', 'borduur-patch-studio' ),
			'bps_nt_custom'  => __( 'Maatwerk', 'borduur-patch-studio' ),
		);
		$k = is_object( $meta ) && isset( $meta->key ) ? (string) $meta->key : (string) $display;
		return $map[ $k ] ?? $display;
	}

	/**
	 * Order note for lists with review words (once per order).
	 *
	 * @param mixed $order Order or id.
	 */
	public static function order_created( $order ): void {
		$order = is_numeric( $order ) && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order ) : $order;
		if ( ! $order instanceof \WC_Order || $order->get_meta( '_bps_nt_review_noted' ) ) {
			return;
		}
		$notes = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! (int) $item->get_meta( '_bps_nt_review' ) ) {
				continue;
			}
			$nt = json_decode( (string) $item->get_meta( '_bps_nt_batch' ), true );
			if ( ! is_array( $nt ) ) {
				continue;
			}
			$words = array_map( 'strval', (array) ( $nt['review_words'] ?? array() ) );
			$rows  = array();
			foreach ( (array) ( $nt['rows'] ?? array() ) as $i => $r ) {
				if ( Fit::review_hits( array( (string) ( $r['n'] ?? '' ), (string) ( $r['l2'] ?? '' ) ), $words ) ) {
					$rows[] = $i + 1;
				}
			}
			$note = sprintf(
				/* translators: 1: words, 2: row numbers */
				__( 'Naamstroken: manuele controle nodig: bevat %1$s (rij %2$s).', 'borduur-patch-studio' ),
				"'" . implode( "', '", $words ) . "'",
				implode( ', ', $rows )
			);
			if ( '' !== (string) ( $nt['org_note'] ?? '' ) ) {
				/* translators: %s: organisation given by the customer */
				$note .= ' ' . sprintf( __( 'Organisatie volgens klant: %s', 'borduur-patch-studio' ), (string) $nt['org_note'] );
			}
			$notes[] = $note;
		}
		if ( $notes ) {
			foreach ( $notes as $n ) {
				$order->add_order_note( $n );
			}
			$order->update_meta_data( '_bps_nt_review_noted', 1 );
			$order->save();
		}
	}
}
