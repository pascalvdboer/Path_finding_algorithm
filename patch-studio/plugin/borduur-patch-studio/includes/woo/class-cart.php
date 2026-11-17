<?php
/**
 * Cart + checkout: server-side prices, one-off fees, display, quantity rules, legal notices.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

use BorduurPatchStudio\Pricing;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The browser never sends a price: the cart line stores choices only and every price is recomputed
 * by the PHP pricing engine (SPEC §6.3/§6.4) on each calculate_totals.
 */
final class Cart {

	/** True while the Patch Studio itself adds a line (lets Product::block_direct_add pass). */
	public static $internal_add = false;

	/** @var array<string,float> Unit prices set during the current calculation (cart key → unit excl). */
	private static $unit_prices = array();

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_prices' ), 20 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_fees' ), 20 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'drop_orphans' ), 20 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart_items' ), 20 );

		// Woo Discount Rules: our tiers are already in the price; never discount or count our lines (06 §5b-5).
		add_filter( 'advanced_woo_discount_rules_calculate_discount_for_cart_item', array( __CLASS__, 'wdr_exclude' ), 10, 2 );
		add_filter( 'advanced_woo_discount_rules_include_cart_item_to_count_quantity', array( __CLASS__, 'wdr_exclude' ), 10, 2 );

		// Display (classic cart + mini-cart + blocks through the Store API).
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( __CLASS__, 'thumbnail' ), 10, 2 );
		add_filter( 'woocommerce_store_api_cart_item_images', array( __CLASS__, 'store_api_images' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_permalink', array( __CLASS__, 'permalink' ), 10, 2 );

		// Quantity rules (PVC: min 50, steps of 10).
		add_filter( 'woocommerce_quantity_input_args', array( __CLASS__, 'quantity_input_args' ), 20, 2 );
		add_filter( 'woocommerce_update_cart_validation', array( __CLASS__, 'update_cart_validation' ), 10, 4 );
		add_filter( 'woocommerce_store_api_product_quantity_minimum', array( __CLASS__, 'store_api_qty_min' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_multiple_of', array( __CLASS__, 'store_api_qty_step' ), 10, 3 );

		// Checkout: custom-made notice (08 §1.4-D) and an unambiguous order button (08 §1.2).
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'checkout_notice' ) );
		add_filter( 'render_block_woocommerce/checkout', array( __CLASS__, 'block_checkout_notice' ), 10, 1 );
		add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'order_button_text' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'block_checkout_button_label' ), 30 );
	}

	/* ---------------------------------------------------------------------
	 * Adding (used by REST POST /cart)
	 * ------------------------------------------------------------------- */

	/**
	 * Add a stored design to the cart (replaces an earlier line of the same design).
	 *
	 * @param array $design DesignRecord.
	 * @param array $rights {v, accepted}.
	 * @return array|WP_Error {key, quote}
	 */
	public static function add_design( array $design, array $rights ) {
		$p    = $design['payload'];
		$tech = Config::technique( (string) ( $p['technique'] ?? '' ) );
		if ( ! $tech || empty( $tech['enabled'] ) ) {
			return new WP_Error( 'bps_invalid_design', __( 'Deze techniek is niet meer beschikbaar. Kies een andere techniek.', 'borduur-patch-studio' ), array( 'status' => 400 ) );
		}

		// Validate with the server engine first (same rules as the checkout).
		$probe = self::quote_for(
			array(
				'technique' => $p['technique'],
				'size_cm'   => (float) $p['sizeCm'],
				'options'   => (array) $p['options'],
			),
			(int) $p['qty'],
			0,
			array(),
			0,
			(string) ( $p['locale'] ?? '' )
		);
		$err = self::quote_error( $probe, (string) $design['ref'] );
		if ( $err ) {
			return $err;
		}

		$product_id = Product::ensure();
		if ( $product_id <= 0 ) {
			return new WP_Error( 'bps_cart', __( 'De winkelmand is even niet beschikbaar. Probeer het straks opnieuw of vraag een offerte.', 'borduur-patch-studio' ), array( 'status' => 500 ) );
		}

		$cart = WC()->cart;
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! empty( $item['bps']['design_id'] ) && (int) $item['bps']['design_id'] === (int) $design['id'] ) {
				$cart->remove_cart_item( $key );
			}
		}

		$data = array(
			'bps' => array(
				'v'           => 1,
				'design_id'   => (int) $design['id'],
				'ref'         => (string) $design['ref'],
				'technique'   => (string) $p['technique'],
				'size_cm'     => (float) $p['sizeCm'],
				'width_mm'    => (float) ( $p['widthMm'] ?? 0 ),
				'height_mm'   => (float) ( $p['heightMm'] ?? 0 ),
				'shape'       => (string) $p['shape'],
				'options'     => array_values( array_map( 'strval', (array) $p['options'] ) ),
				'preview_url' => (string) $design['preview_url'],
				'locale'      => (string) ( $p['locale'] ?? 'nl-BE' ),
				'rights'      => array(
					'v'      => substr( sanitize_text_field( (string) ( $rights['v'] ?? '' ) ), 0, 20 ),
					'at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'locale' => Config::wp_locale( (string) ( $p['locale'] ?? '' ) ),
				),
			),
		);

		self::$internal_add = true;
		$notices_before     = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : array();
		try {
			$key = $cart->add_to_cart( $product_id, (int) $p['qty'], 0, array(), $data );
		} catch ( \Exception $e ) {
			$key = false;
		}
		self::$internal_add = false;

		if ( ! $key ) {
			$errors = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : array();
			$msg    = count( $errors ) > count( $notices_before ) ? wp_strip_all_tags( (string) ( end( $errors )['notice'] ?? '' ) ) : '';
			wc_clear_notices();
			return new WP_Error( 'bps_cart', '' !== $msg ? $msg : __( 'Toevoegen aan je winkelmand is niet gelukt. Probeer het nog eens.', 'borduur-patch-studio' ), array( 'status' => 400 ) );
		}

		$cart->calculate_totals();
		return array(
			'key'   => (string) $key,
			'quote' => self::line_quote( (string) $key ),
		);
	}

	/**
	 * Server PriceQuote for one cart line in the context of the current cart (pool, fees, other items).
	 *
	 * @param string $key Cart item key.
	 */
	public static function line_quote( string $key ): array {
		$cart  = WC()->cart;
		$items = $cart->get_cart();
		if ( empty( $items[ $key ]['bps'] ) ) {
			return array();
		}
		$item  = $items[ $key ];
		$bps   = $item['bps'];
		$pool  = self::pool_qty( $items ) - ( self::in_pool( (string) $bps['technique'] ) ? (int) $item['quantity'] : 0 );
		$fees  = array();
		$other = 0.0;
		$own   = array();
		foreach ( $items as $k => $it ) {
			if ( $k === $key ) {
				continue;
			}
			$other += (float) ( $it['line_total'] ?? 0 ) + (float) ( $it['line_tax'] ?? 0 );
			if ( ! empty( $it['bps'] ) && 'order' === self::fee_scope() ) {
				$t = Config::technique( (string) $it['bps']['technique'] );
				if ( ! empty( $t['feeGroup'] ) ) {
					$own[] = (string) $t['feeGroup'];
				}
			}
		}
		$fees = array_values( array_unique( array_merge( $own, self::legacy_fee_groups( $items ) ) ) );
		return self::quote_for( $bps, (int) $item['quantity'], max( 0, $pool ), $fees, round( $other, 2 ), (string) ( $bps['locale'] ?? '' ), true );
	}

	/* ---------------------------------------------------------------------
	 * Pricing
	 * ------------------------------------------------------------------- */

	/**
	 * Set the unit price of each Patch Studio line (absolute price → idempotent).
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function set_prices( $cart ): void {
		if ( ! $cart instanceof \WC_Cart || ! class_exists( Pricing::class ) ) {
			return;
		}
		$items = $cart->get_cart();
		$pool  = self::pool_qty( $items );
		foreach ( $items as $key => $item ) {
			if ( empty( $item['bps'] ) || ! isset( $item['data'] ) || ! $item['data'] instanceof \WC_Product ) {
				continue;
			}
			$qty  = (int) $item['quantity'];
			$bps  = $item['bps'];
			$own  = self::in_pool( (string) $bps['technique'] ) ? $qty : 0;
			try {
				$unit = (float) Pricing::cart_unit_excl( $bps, $qty, max( 0, $pool - $own ) );
			} catch ( \Throwable $e ) {
				$unit = 0.0;
			}
			if ( ! is_finite( $unit ) || $unit < 0 ) {
				$unit = 0.0;
			}
			self::$unit_prices[ $key ] = $unit;
			$item['data']->set_price( $unit );
		}
	}

	/**
	 * Unit price set for a line in this request (used for order meta).
	 *
	 * @param string $key Cart item key.
	 */
	public static function unit_price( string $key ): ?float {
		return self::$unit_prices[ $key ] ?? null;
	}

	/**
	 * Fee scope: 'order' (default) or 'design'.
	 */
	private static function fee_scope(): string {
		return 'design' === Config::path( 'fees.scope', 'order' ) ? 'design' : 'order';
	}

	/**
	 * Is this technique part of the shared tier pool?
	 *
	 * @param string $technique Technique id.
	 */
	private static function in_pool( string $technique ): bool {
		$t = Config::technique( $technique );
		return ! empty( $t['tiers'] ) && (bool) Config::path( 'tiers.pooled', true );
	}

	/**
	 * Total quantity in the tier pool.
	 *
	 * @param array $items Cart contents.
	 */
	private static function pool_qty( array $items ): int {
		$pool        = 0;
		$with_legacy = (bool) Config::path( 'woo.poolIncludeLegacy', false );
		$legacy      = $with_legacy ? self::legacy_products( true ) : array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['bps'] ) ) {
				if ( self::in_pool( (string) $item['bps']['technique'] ) ) {
					$pool += (int) $item['quantity'];
				}
				continue;
			}
			if ( $with_legacy && isset( $legacy[ (int) ( $item['product_id'] ?? 0 ) ] ) ) {
				$pool += (int) $item['quantity'];
			}
		}
		return $pool;
	}

	/**
	 * Legacy product ids → technique id.
	 *
	 * @param bool $tiers_only Only techniques with tiers.
	 * @return array<int,string>
	 */
	private static function legacy_products( bool $tiers_only = false ): array {
		$out = array();
		foreach ( (array) Config::path( 'techniques', array() ) as $id => $t ) {
			if ( empty( $t['legacy']['productId'] ) || ( $tiers_only && empty( $t['tiers'] ) ) ) {
				continue;
			}
			$out[ (int) $t['legacy']['productId'] ] = (string) $id;
		}
		return $out;
	}

	/**
	 * Fee groups already charged by legacy products in the cart (Conditional Extra Fees charges those).
	 *
	 * @param array $items Cart contents.
	 * @return string[]
	 */
	private static function legacy_fee_groups( array $items ): array {
		if ( ! Config::path( 'woo.skipFeeIfLegacyInCart', true ) ) {
			return array();
		}
		$legacy = self::legacy_products();
		$groups = array();
		foreach ( $items as $item ) {
			$pid = (int) ( $item['product_id'] ?? 0 );
			if ( empty( $item['bps'] ) && isset( $legacy[ $pid ] ) ) {
				$t = Config::technique( $legacy[ $pid ] );
				if ( ! empty( $t['feeGroup'] ) ) {
					$groups[] = (string) $t['feeGroup'];
				}
			}
		}
		return array_values( array_unique( $groups ) );
	}

	/**
	 * One-off costs: fee groups (per order or per design) and per-line extras.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function add_fees( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		$items   = $cart->get_cart();
		$skip    = self::legacy_fee_groups( $items );
		$scope   = self::fee_scope();
		$charged = array();
		$lang    = Config::lang();
		foreach ( $items as $item ) {
			if ( empty( $item['bps'] ) ) {
				continue;
			}
			$bps  = $item['bps'];
			$tech = Config::technique( (string) $bps['technique'] );
			if ( ! $tech ) {
				continue;
			}
			$group = isset( $tech['feeGroup'] ) ? (string) $tech['feeGroup'] : '';
			$def   = '' !== $group ? Config::path( 'fees.groups.' . $group ) : null;
			if ( is_array( $def ) && ! in_array( $group, $skip, true ) ) {
				$amount = (float) ( $def['amount'] ?? 0 );
				$label  = Config::label( $def['label'] ?? $group, $lang );
				if ( 'design' === $scope ) {
					self::fee( $cart, 'bps-fee-' . $group . '-' . (int) $bps['design_id'], $label . ' (' . $bps['ref'] . ')', $amount );
				} elseif ( ! isset( $charged[ $group ] ) ) {
					$charged[ $group ] = true;
					self::fee( $cart, 'bps-fee-' . $group, $label, $amount );
				}
			}
			foreach ( (array) $bps['options'] as $oid ) {
				$opt = Config::option( (string) $oid );
				if ( ! $opt || ( isset( $opt['enabled'] ) && ! $opt['enabled'] ) || ! in_array( (string) $bps['technique'], (array) ( $opt['techniques'] ?? array() ), true ) ) {
					continue;
				}
				if ( ! empty( $opt['requiresAny'] ) && ! array_intersect( (array) $opt['requiresAny'], (array) $bps['options'] ) ) {
					continue;
				}
				$price = Config::option_price( $opt, (string) $bps['technique'] );
				if ( 'per_line' !== ( $price['type'] ?? '' ) ) {
					continue;
				}
				self::fee( $cart, 'bps-extra-' . $oid . '-' . (int) $bps['design_id'], Config::label( $opt['label'] ?? $oid, $lang ) . ' (' . $bps['ref'] . ')', (float) ( $price['amount'] ?? 0 ) );
			}
		}
	}

	/**
	 * Add one taxable fee (standard rate).
	 *
	 * @param \WC_Cart $cart   Cart.
	 * @param string   $id     Fee id.
	 * @param string   $name   Label.
	 * @param float    $amount Amount excl. VAT.
	 */
	private static function fee( \WC_Cart $cart, string $id, string $name, float $amount ): void {
		if ( $amount == 0 ) { // phpcs:ignore Universal.Operators.StrictComparisons
			return;
		}
		$cart->fees_api()->add_fee(
			array(
				'id'        => sanitize_title( $id ),
				'name'      => $name,
				'amount'    => round( $amount, 2 ),
				'taxable'   => true,
				'tax_class' => '',
			)
		);
	}

	/**
	 * Woo Discount Rules exclusion.
	 *
	 * @param mixed $value     Value.
	 * @param mixed $cart_item Cart item.
	 */
	public static function wdr_exclude( $value, $cart_item = null ) {
		return is_array( $cart_item ) && ! empty( $cart_item['bps'] ) ? false : $value;
	}

	/* ---------------------------------------------------------------------
	 * Validation
	 * ------------------------------------------------------------------- */

	/**
	 * Remove lines of our product that carry no design (e.g. added by another plugin).
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function drop_orphans( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['bps'] ) && Product::is_ours( (int) ( $item['product_id'] ?? 0 ) ) ) {
				$cart->remove_cart_item( $key );
			}
		}
	}

	/**
	 * Re-validate every Patch Studio line before checkout (config may have changed since).
	 */
	public static function check_cart_items(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! class_exists( Pricing::class ) ) {
			return;
		}
		$items = WC()->cart->get_cart();
		$pool  = self::pool_qty( $items );
		foreach ( $items as $item ) {
			if ( empty( $item['bps'] ) ) {
				continue;
			}
			$bps = $item['bps'];
			if ( ! Designs::get( (int) $bps['design_id'] ) ) {
				wc_add_notice(
					/* translators: %s: design reference */
					sprintf( __( 'Je ontwerp %s is niet meer beschikbaar. Verwijder het uit je winkelmand en ontwerp je patch opnieuw.', 'borduur-patch-studio' ), esc_html( (string) $bps['ref'] ) ),
					'error'
				);
				continue;
			}
			$qty   = (int) $item['quantity'];
			$own   = self::in_pool( (string) $bps['technique'] ) ? $qty : 0;
			$quote = self::quote_for( $bps, $qty, max( 0, $pool - $own ), array(), 0, '' );
			$err   = self::quote_error( $quote, (string) $bps['ref'] );
			if ( $err ) {
				wc_add_notice( esc_html( $err->get_error_message() ), 'error' );
			}
		}
	}

	/**
	 * PriceQuote via the PHP engine.
	 *
	 * @param array  $bps         Cart bps data (technique, size_cm, options).
	 * @param int    $qty         Quantity.
	 * @param int    $pool        Pool qty of other lines.
	 * @param array  $fees        Fee groups already charged.
	 * @param float  $other_incl  Other items incl. VAT.
	 * @param string $locale      Locale.
	 * @param bool   $with_ladder Include the tier ladder.
	 */
	public static function quote_for( array $bps, int $qty, int $pool, array $fees, float $other_incl, string $locale, bool $with_ladder = false ): array {
		if ( ! class_exists( Pricing::class ) ) {
			return array(
				'ok'     => false,
				'mode'   => 'order',
				'errors' => array( array( 'code' => 'engine_missing', 'params' => array() ) ),
			);
		}
		$input = array(
			'technique'          => (string) ( $bps['technique'] ?? '' ),
			'sizeCm'             => (float) ( $bps['size_cm'] ?? 0 ),
			'qty'                => $qty,
			'options'            => array_values( (array) ( $bps['options'] ?? array() ) ),
			'poolQty'            => $pool,
			'feesAlreadyCharged' => $fees,
			'otherItemsInclVat'  => $other_incl,
			'withLadder'         => $with_ladder,
			'locale'             => Config::public_locale( '' !== $locale ? $locale : null ),
		);
		try {
			return (array) Pricing::quote( $input );
		} catch ( \Throwable $e ) {
			return array(
				'ok'     => false,
				'mode'   => 'order',
				'errors' => array( array( 'code' => 'engine_error', 'params' => array() ) ),
			);
		}
	}

	/**
	 * Map PriceQuote errors to REST errors with customer-friendly Flemish messages.
	 *
	 * @param array  $quote Quote.
	 * @param string $ref   Design ref.
	 */
	public static function quote_error( array $quote, string $ref = '' ): ?WP_Error {
		if ( ! empty( $quote['ok'] ) ) {
			return null;
		}
		$errors = isset( $quote['errors'] ) && is_array( $quote['errors'] ) ? $quote['errors'] : array();
		$codes  = array();
		$params = array();
		foreach ( $errors as $e ) {
			$code            = is_array( $e ) ? (string) ( $e['code'] ?? '' ) : (string) $e;
			$codes[]         = $code;
			$params[ $code ] = is_array( $e ) && isset( $e['params'] ) ? (array) $e['params'] : array();
		}
		$tech  = Config::technique_label( (string) ( $quote['technique'] ?? '' ) );
		$label = '' !== $ref ? $ref . ': ' : '';
		if ( 'quote' === ( $quote['mode'] ?? '' ) || array_intersect( $codes, array( 'quote_only', 'size_too_large' ) ) ) {
			return new WP_Error(
				'bps_quote_only',
				$label . __( 'Deze patch maken we op offerte. Vraag vrijblijvend je prijs op maat aan; je ontwerp gaat automatisch mee.', 'borduur-patch-studio' ),
				array( 'status' => 409 )
			);
		}
		if ( in_array( 'below_min_qty', $codes, true ) ) {
			$min = (int) ( $params['below_min_qty']['min'] ?? 1 );
			return new WP_Error(
				'bps_min_qty',
				/* translators: 1: technique, 2: minimum quantity */
				$label . sprintf( __( '%1$s maken we vanaf %2$d stuks. Pas het aantal aan.', 'borduur-patch-studio' ), $tech, $min ),
				array( 'status' => 400, 'min' => $min )
			);
		}
		if ( in_array( 'qty_step', $codes, true ) ) {
			$step = (int) ( $params['qty_step']['step'] ?? 1 );
			return new WP_Error(
				'bps_min_qty',
				/* translators: 1: technique, 2: step */
				$label . sprintf( __( '%1$s bestel je per %2$d stuks. Pas het aantal aan.', 'borduur-patch-studio' ), $tech, $step ),
				array( 'status' => 400, 'step' => $step )
			);
		}
		if ( in_array( 'size_too_small', $codes, true ) ) {
			return new WP_Error( 'bps_invalid_design', $label . __( 'Deze maat is te klein voor deze techniek. Kies een grotere maat.', 'borduur-patch-studio' ), array( 'status' => 400 ) );
		}
		if ( array_intersect( $codes, array( 'invalid_option', 'missing_required_option', 'option_conflict' ) ) ) {
			return new WP_Error( 'bps_invalid_design', $label . __( 'De gekozen afwerking past niet (meer) bij deze techniek. Kies je afwerking opnieuw.', 'borduur-patch-studio' ), array( 'status' => 400 ) );
		}
		if ( array_intersect( $codes, array( 'engine_missing', 'engine_error' ) ) ) {
			return new WP_Error( 'bps_unavailable', __( 'De prijsberekening is even niet beschikbaar. Probeer het straks opnieuw of vraag een offerte.', 'borduur-patch-studio' ), array( 'status' => 503 ) );
		}
		return new WP_Error( 'bps_invalid_design', $label . __( 'Deze patch kan zo niet online besteld worden. Vraag een offerte of pas je keuzes aan.', 'borduur-patch-studio' ), array( 'status' => 400 ) );
	}

	/* ---------------------------------------------------------------------
	 * Display
	 * ------------------------------------------------------------------- */

	/**
	 * Lines under the product name (classic + blocks).
	 *
	 * @param array $item_data Existing data.
	 * @param array $cart_item Cart item.
	 */
	public static function item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item['bps'] ) || ! is_array( $cart_item['bps'] ) ) {
			return $item_data;
		}
		$item_data = is_array( $item_data ) ? $item_data : array();
		$b         = $cart_item['bps'];
		$lang      = Config::lang();
		$rows      = self::summary_rows( $b, $lang );
		foreach ( $rows as $row ) {
			$item_data[] = array(
				'key'   => $row[0],
				'value' => $row[1],
			);
		}
		$item_data[] = array(
			'key'   => __( 'Maatwerk', 'borduur-patch-studio' ),
			'value' => __( 'Geen herroepingsrecht · digitale proef vóór productie', 'borduur-patch-studio' ),
		);
		return apply_filters( 'bps_cart_item_display', $item_data, $b );
	}

	/**
	 * Human summary rows [label, value] for a bps array (cart, e-mails).
	 *
	 * @param array  $b    Bps data.
	 * @param string $lang Language.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function summary_rows( array $b, string $lang ): array {
		$rows   = array();
		$rows[] = array( __( 'Ontwerp', 'borduur-patch-studio' ), (string) ( $b['ref'] ?? '' ) );
		$rows[] = array( __( 'Techniek', 'borduur-patch-studio' ), Config::technique_label( (string) ( $b['technique'] ?? '' ), $lang ) );
		$rows[] = array( __( 'Maat', 'borduur-patch-studio' ), Config::size_text( (float) ( $b['size_cm'] ?? 0 ), (float) ( $b['width_mm'] ?? 0 ), (float) ( $b['height_mm'] ?? 0 ) ) );
		$rows[] = array( __( 'Vorm', 'borduur-patch-studio' ), Config::shape_label( (string) ( $b['shape'] ?? '' ), $lang ) );
		$finish = self::finish_text( (array) ( $b['options'] ?? array() ), (string) ( $b['technique'] ?? '' ), $lang );
		if ( '' !== $finish ) {
			$rows[] = array( __( 'Afwerking', 'borduur-patch-studio' ), $finish );
		}
		return $rows;
	}

	/**
	 * Chosen options as text (requiresAny-orphans skipped).
	 *
	 * @param array  $options   Option ids.
	 * @param string $technique Technique id.
	 * @param string $lang      Language.
	 */
	public static function finish_text( array $options, string $technique, string $lang ): string {
		$labels = array();
		foreach ( $options as $oid ) {
			$opt = Config::option( (string) $oid );
			if ( ! $opt ) {
				continue;
			}
			if ( ! empty( $opt['requiresAny'] ) && ! array_intersect( (array) $opt['requiresAny'], $options ) ) {
				continue;
			}
			$text = Config::label( $opt['label'] ?? $oid, $lang );
			if ( 'velcro_color' === ( $opt['group'] ?? '' ) ) {
				/* translators: %s: colour */
				$text = sprintf( __( 'klittenband %s', 'borduur-patch-studio' ), function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text ) );
			}
			$labels[] = $text;
		}
		return implode( ', ', $labels );
	}

	/**
	 * Preview as cart thumbnail (classic cart, mini-cart, Elementor menu-cart).
	 *
	 * @param string $html      Thumbnail HTML.
	 * @param array  $cart_item Cart item.
	 */
	public static function thumbnail( $html, $cart_item ) {
		if ( empty( $cart_item['bps']['preview_url'] ) ) {
			return $html;
		}
		return sprintf(
			'<img src="%1$s" alt="%2$s" width="300" height="300" class="bps-cart-preview skip-lazy attachment-woocommerce_thumbnail" data-no-lazy="1" loading="eager" decoding="async" />',
			esc_url( (string) $cart_item['bps']['preview_url'] ),
			/* translators: %s: design reference */
			esc_attr( sprintf( __( 'Voorbeeld van jouw patch %s', 'borduur-patch-studio' ), (string) ( $cart_item['bps']['ref'] ?? '' ) ) )
		);
	}

	/**
	 * Preview in the block cart / mini-cart (Store API).
	 *
	 * @param array $images    Images.
	 * @param array $cart_item Cart item.
	 */
	public static function store_api_images( $images, $cart_item ) {
		if ( empty( $cart_item['bps']['preview_url'] ) ) {
			return $images;
		}
		$url = esc_url_raw( (string) $cart_item['bps']['preview_url'] );
		return array(
			(object) array(
				'id'        => 0,
				'src'       => $url,
				'thumbnail' => $url,
				'srcset'    => '',
				'sizes'     => '',
				'name'      => (string) ( $cart_item['bps']['ref'] ?? '' ),
				'alt'       => __( 'Voorbeeld van jouw patch', 'borduur-patch-studio' ),
			),
		);
	}

	/**
	 * Clicking the product in the cart reopens the design in the studio.
	 *
	 * @param string $url       Permalink.
	 * @param array  $cart_item Cart item.
	 */
	public static function permalink( $url, $cart_item ) {
		if ( empty( $cart_item['bps']['design_id'] ) ) {
			return $url;
		}
		return Designs::restore_url( (int) $cart_item['bps']['design_id'] );
	}

	/* ---------------------------------------------------------------------
	 * Quantity rules
	 * ------------------------------------------------------------------- */

	/**
	 * [min, step] for a technique.
	 *
	 * @param string $technique Technique id.
	 * @return array{0:int,1:int}
	 */
	private static function qty_rules( string $technique ): array {
		$t = Config::technique( $technique );
		return array( max( 1, (int) ( $t['minQty'] ?? 1 ) ), max( 1, (int) ( $t['qtyStep'] ?? 1 ) ) );
	}

	/**
	 * Classic cart quantity input: the cart item key is part of the input name.
	 *
	 * @param array       $args    Args.
	 * @param \WC_Product $product Product.
	 */
	public static function quantity_input_args( $args, $product ) {
		if ( ! is_array( $args ) || ! $product instanceof \WC_Product || ! Product::is_ours( (int) $product->get_id() ) ) {
			return $args;
		}
		if ( empty( $args['input_name'] ) || ! preg_match( '/^cart\[([a-f0-9]{32})\]\[qty\]$/', (string) $args['input_name'], $m ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $args;
		}
		$item = WC()->cart->get_cart_item( $m[1] );
		if ( empty( $item['bps'] ) ) {
			return $args;
		}
		list( $min, $step ) = self::qty_rules( (string) $item['bps']['technique'] );
		$args['min_value']  = $min;
		$args['step']       = $step;
		return $args;
	}

	/**
	 * Classic cart "Update cart".
	 *
	 * @param bool   $passed        Passed.
	 * @param string $cart_item_key Key.
	 * @param array  $values        Cart item.
	 * @param int    $quantity      New quantity.
	 */
	public static function update_cart_validation( $passed, $cart_item_key, $values, $quantity ) {
		if ( empty( $values['bps'] ) ) {
			return $passed;
		}
		list( $min, $step ) = self::qty_rules( (string) $values['bps']['technique'] );
		$quantity           = (int) $quantity;
		$tech               = Config::technique_label( (string) $values['bps']['technique'] );
		if ( $quantity < $min ) {
			/* translators: 1: technique, 2: minimum */
			wc_add_notice( sprintf( __( '%1$s maken we vanaf %2$d stuks.', 'borduur-patch-studio' ), $tech, $min ), 'error' );
			return false;
		}
		if ( 0 !== $quantity % $step ) {
			/* translators: 1: technique, 2: step */
			wc_add_notice( sprintf( __( '%1$s bestel je per %2$d stuks.', 'borduur-patch-studio' ), $tech, $step ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Block cart minimum.
	 *
	 * @param mixed       $value     Value.
	 * @param \WC_Product $product   Product.
	 * @param array|null  $cart_item Cart item.
	 */
	public static function store_api_qty_min( $value, $product, $cart_item = null ) {
		if ( is_array( $cart_item ) && ! empty( $cart_item['bps'] ) ) {
			return self::qty_rules( (string) $cart_item['bps']['technique'] )[0];
		}
		return $value;
	}

	/**
	 * Block cart step.
	 *
	 * @param mixed       $value     Value.
	 * @param \WC_Product $product   Product.
	 * @param array|null  $cart_item Cart item.
	 */
	public static function store_api_qty_step( $value, $product, $cart_item = null ) {
		if ( is_array( $cart_item ) && ! empty( $cart_item['bps'] ) ) {
			return self::qty_rules( (string) $cart_item['bps']['technique'] )[1];
		}
		return $value;
	}

	/* ---------------------------------------------------------------------
	 * Checkout
	 * ------------------------------------------------------------------- */

	/**
	 * Does the current cart contain a Patch Studio line?
	 */
	public static function has_design(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['bps'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Legal notice D text.
	 */
	public static function custom_made_text(): string {
		return __( 'Je bestelling bevat maatwerk. Voor op maat gemaakte patches geldt geen herroepingsrecht (art. VI.53 WER). Je ontvangt eerst een digitale proef; de productie start pas na jouw akkoord.', 'borduur-patch-studio' );
	}

	/**
	 * Classic checkout notice.
	 */
	public static function checkout_notice(): void {
		if ( self::has_design() ) {
			echo '<p class="bps-legal-note" role="note">' . esc_html( self::custom_made_text() ) . '</p>';
		}
	}

	/**
	 * Block checkout notice (printed above the checkout block).
	 *
	 * @param string $content Block HTML.
	 */
	public static function block_checkout_notice( $content ) {
		if ( ! self::has_design() ) {
			return $content;
		}
		return '<div class="woocommerce-info bps-legal-note" role="note">' . esc_html( self::custom_made_text() ) . '</div>' . $content;
	}

	/**
	 * Is the "Bestellen en betalen" button wanted (legal.fixOrderButton)?
	 */
	private static function fix_button(): bool {
		return (bool) Config::path( 'legal.fixOrderButton', true );
	}

	/**
	 * Classic checkout button text.
	 *
	 * @param string $text Text.
	 */
	public static function order_button_text( $text ) {
		return self::fix_button() ? __( 'Bestellen en betalen', 'borduur-patch-studio' ) : $text;
	}

	/**
	 * Block checkout button label via the official checkout filter API (only where the block is used).
	 */
	public static function block_checkout_button_label(): void {
		if ( ! self::fix_button() || ! wp_script_is( 'wc-blocks-checkout', 'registered' ) ) {
			return;
		}
		$is_checkout = function_exists( 'is_checkout' ) && is_checkout();
		$post        = get_post();
		if ( ! $is_checkout && ! ( $post && has_block( 'woocommerce/checkout', $post ) ) ) {
			return;
		}
		$label = wp_json_encode( __( 'Bestellen en betalen', 'borduur-patch-studio' ) );
		wp_add_inline_script(
			'wc-blocks-checkout',
			'(function(){try{var c=window.wc&&window.wc.blocksCheckout;if(c&&c.registerCheckoutFilters){c.registerCheckoutFilters("borduur-patch-studio",{placeOrderButtonLabel:function(){return ' . $label . ';}});}}catch(e){}})();',
			'after'
		);
	}
}
