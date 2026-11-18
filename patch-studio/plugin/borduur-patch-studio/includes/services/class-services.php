<?php
/**
 * Service catalogue, offer rules and service lines in the cart (SPEC-AI-DIENSTEN §10.5, §11.1, 03 §5).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Honest offers: max 1 at a time, only when a measured problem or a real situation calls for it, never preselected,
 * always with the reason and the free alternative. The JS module (assets/src/services) mirrors evaluate();
 * both are checked against tests/services/offer-vectors.json.
 */
final class Services {

	/** Cart item data key for a service line. */
	const CART_KEY = '_bps_service';

	/** Cart item data key: design ref of the patch line a line_option belongs to. */
	const LINK_KEY = '_bps_linked_ref';

	/** Order item meta. */
	const ITEM_META        = '_bps_service';
	const START_AFTER_META = '_bps_svc_start_after';

	/** @var bool True while our own REST route adds to the cart. */
	public static $internal_add = false;

	/**
	 * Hooks (WooCommerce present).
	 */
	public static function register(): void {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_prices' ), 25 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'drop_orphans' ), 25 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'drop_orphans_notice' ), 25 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 20, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'block_direct_add' ), 6, 2 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( __CLASS__, 'block_store_api_add' ), 6, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line_item' ), 20, 4 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'fixed_quantity' ), 20, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', array( __CLASS__, 'store_api_qty_max' ), 20, 3 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_meta' ) );
	}

	/* ---------------------------------------------------------------------
	 * Catalogue (public)
	 * ------------------------------------------------------------------- */

	/**
	 * Public ServiceDef list (no internal numbers beyond what the offer needs).
	 *
	 * @param string|null $lang nl|fr.
	 * @return array<int, array>
	 */
	public static function catalog( ?string $lang = null ): array {
		$lang = Text::lang( $lang );
		$out  = array();
		foreach ( Config::services( true ) as $id => $s ) {
			$def = array(
				'id'              => $id,
				'enabled'         => true,
				'kind'            => $s['kind'],
				'label'           => Text::label( $s['label'] ?? $id, $lang ),
				'short'           => Text::label( $s['short'] ?? '', $lang ),
				'reason'          => Text::label( $s['reason'] ?? '', $lang ),
				'priceIncl'       => (float) $s['priceIncl'],
				'priceHtml'       => Text::money( (float) $s['priceIncl'], $lang ),
				'leadDays'        => (int) ( $s['leadDays'] ?? 0 ),
				'needsConsent'    => ! empty( $s['needsConsent'] ),
				'stages'          => array_values( array_map( 'strval', (array) ( $s['stages'] ?? array() ) ) ),
				'triggers'        => (array) ( $s['triggers'] ?? array() ),
				'freeAlternative' => isset( $s['freeAlternative'] ) ? ( Text::label( $s['freeAlternative'], $lang ) ?: null ) : null,
			);
			foreach ( array( 'percent', 'minIncl', 'freeFromQty', 'refundFromQty', 'techniques' ) as $k ) {
				if ( isset( $s[ $k ] ) ) {
					$def[ $k ] = $s[ $k ];
				}
			}
			$out[] = $def;
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Offer rules (pure)
	 * ------------------------------------------------------------------- */

	/**
	 * Does a service match the signals? Thresholds come from the service's `triggers`.
	 *
	 * @param array $def     ServiceDef (catalog shape).
	 * @param array $signals Signals (SPEC §10.5).
	 */
	public static function matches( array $def, array $signals ): bool {
		$t     = (array) ( $def['triggers'] ?? array() );
		$stage = (string) ( $signals['stage'] ?? '' );
		if ( ! in_array( $stage, (array) ( $def['stages'] ?? array() ), true ) ) {
			return false;
		}
		$a     = (array) ( $signals['analysis'] ?? array() );
		$check = isset( $signals['check'] ) && is_array( $signals['check'] ) ? $signals['check'] : null;
		$tech  = (string) ( $signals['technique'] ?? '' );
		$is_ai = ! empty( $signals['isAi'] );
		switch ( $def['id'] ) {
			case 'd1_finish':
				return $is_ai && null !== $check && 'ok' !== ( $check['status'] ?? 'ok' ) && empty( $check['autoFixAvailable'] );
			case 'd2_redraw':
				if ( $is_ai ) {
					return false;
				}
				$px    = isset( $a['minSidePx'] ) ? (float) $a['minSidePx'] : null;
				$noise = isset( $a['jpegNoise'] ) ? (float) $a['jpegNoise'] : null;
				return ( null !== $px && $px > 0 && isset( $t['minSidePxBelow'] ) && $px < (float) $t['minSidePxBelow'] )
					|| ( ! empty( $t['photoOfLogo'] ) && ! empty( $a['photoOfLogo'] ) )
					|| ( null !== $noise && isset( $t['jpegNoiseAbove'] ) && $noise > (float) $t['jpegNoiseAbove'] );
			case 'd2b_redraw_xl':
				return ! $is_ai && ! empty( $t['sketch'] ) && ! empty( $a['sketch'] );
			case 'd3_file':
				return ! empty( $signals['selfEmbroider'] );
			case 'd3b_file_addon':
				return 'thanks' === $stage && empty( $signals['selfEmbroider'] );
			case 'd4_rush':
				$techs = (array) ( $def['techniques'] ?? array() );
				$need  = (string) ( $signals['neededBy'] ?? '' );
				$norm  = (string) ( $signals['normalDate'] ?? '' );
				$rush  = (string) ( $signals['rushDate'] ?? '' );
				return '' !== $need && '' !== $norm && '' !== $rush && $need < $norm && $rush <= $need
					&& ( ! $techs || in_array( $tech, $techs, true ) );
			case 'd6_sample':
				$qty   = (int) ( $signals['qty'] ?? 0 );
				$total = (float) ( $signals['totalIncl'] ?? 0 );
				return ( isset( $t['offerFromQty'] ) && $qty >= (int) $t['offerFromQty'] )
					|| ( isset( $t['offerFromTotalIncl'] ) && $total >= (float) $t['offerFromTotalIncl'] )
					|| ( '' !== $tech && in_array( $tech, (array) ( $t['techniques'] ?? array() ), true ) );
		}
		// Unknown/extra services: only through the filter.
		return (bool) apply_filters( 'bps_svc_offer_rules', false, $def, $signals );
	}

	/**
	 * Best single offer for the signals, or null. Pure (no WordPress state besides the filter).
	 *
	 * @param array    $signals   Signals: stage, analysis, check, isAi, qty, totalIncl, technique, neededBy, normalDate, rushDate, selfEmbroider, lang.
	 * @param array    $catalog   ServiceDef list (catalog()).
	 * @param string[] $dismissed Service ids the customer declined for this design.
	 * @return array|null Offer {serviceId, reason, priceHtml, priceIncl, freeAlternative, cta, decline}
	 */
	public static function evaluate( array $signals, array $catalog, array $dismissed = array() ): ?array {
		$lang = Text::lang( (string) ( $signals['lang'] ?? '' ) );
		foreach ( $catalog as $def ) {
			if ( ! is_array( $def ) || empty( $def['id'] ) || empty( $def['enabled'] ) || in_array( $def['id'], $dismissed, true ) ) {
				continue;
			}
			if ( ! self::matches( $def, $signals ) ) {
				continue;
			}
			$price = (float) $def['priceIncl'];
			if ( 'd4_rush' === $def['id'] ) {
				$price = self::rush_amount( (float) ( $signals['totalIncl'] ?? 0 ), (float) ( $def['percent'] ?? 25 ), (float) ( $def['minIncl'] ?? 0 ) );
			}
			if ( 'd7_photo_test' === $def['id'] && isset( $def['freeFromQty'] ) && (int) ( $signals['qty'] ?? 0 ) >= (int) $def['freeFromQty'] ) {
				$price = 0.0;
			}
			$vars      = array(
				'px'         => isset( $signals['analysis']['minSidePx'] ) ? (int) $signals['analysis']['minSidePx'] : '',
				'qty'        => (int) ( $signals['qty'] ?? 0 ),
				'refundQty'  => (int) ( $def['refundFromQty'] ?? 0 ),
				'date'       => (string) ( $signals['rushDate'] ?? '' ),
				'normalDate' => (string) ( $signals['normalDate'] ?? '' ),
			);
			$price_txt = Text::money( $price, $lang );
			return array(
				'serviceId'       => (string) $def['id'],
				'label'           => (string) $def['label'],
				'reason'          => Text::fill( (string) ( $def['reason'] ?? '' ), $vars ),
				'priceIncl'       => $price,
				'priceHtml'       => $price_txt,
				'freeAlternative' => isset( $def['freeAlternative'] ) && $def['freeAlternative'] ? Text::fill( (string) $def['freeAlternative'], $vars ) : null,
				'cta'             => Text::t( 'offer.add', $lang, array( 'price' => $price_txt ) ),
				'decline'         => Text::t( 'offer.no', $lang ),
				'preselected'     => false,
			);
		}
		return null;
	}

	/**
	 * Rush surcharge: max(min, percent × base).
	 *
	 * @param float $base    Patch subtotal.
	 * @param float $percent Percent.
	 * @param float $min     Minimum.
	 */
	public static function rush_amount( float $base, float $percent, float $min ): float {
		return Config::round_half_up( max( $min, $base * $percent / 100 ), 2 );
	}

	/**
	 * Price of a service line (in the store's price entry mode: incl. when prices include tax, else excl.).
	 *
	 * @param array $svc       Service (Config::service()).
	 * @param float $base      Subtotal of the linked patch line(s), same tax mode.
	 * @param int   $patch_qty Quantity of the linked patch line(s).
	 * @param bool  $incl      Prices entered incl. tax.
	 */
	public static function line_price( array $svc, float $base, int $patch_qty, bool $incl ): float {
		$id = (string) ( $svc['id'] ?? '' );
		if ( 'd4_rush' === $id ) {
			$min = $incl ? (float) ( $svc['minIncl'] ?? 0 ) : (float) ( $svc['minExcl'] ?? 0 );
			return Config::round_half_up( max( $min, $base * (float) ( $svc['percent'] ?? 25 ) / 100 ), $incl ? 2 : 4 );
		}
		if ( 'd7_photo_test' === $id && isset( $svc['freeFromQty'] ) && $patch_qty >= (int) $svc['freeFromQty'] ) {
			return 0.0;
		}
		return $incl ? (float) $svc['priceIncl'] : (float) ( $svc['priceExcl'] ?? 0 );
	}

	/**
	 * Add N working days (Mon–Fri) to a UTC date.
	 *
	 * @param string $start_utc 'Y-m-d H:i:s'.
	 * @param int    $days      Working days.
	 * @return string 'Y-m-d'
	 */
	public static function add_working_days( string $start_utc, int $days ): string {
		$d = new \DateTimeImmutable( $start_utc, new \DateTimeZone( 'UTC' ) );
		while ( $days > 0 ) {
			$d = $d->modify( '+1 day' );
			if ( (int) $d->format( 'N' ) <= 5 ) {
				--$days;
			}
		}
		return $d->format( 'Y-m-d' );
	}

	/* ---------------------------------------------------------------------
	 * Cart
	 * ------------------------------------------------------------------- */

	/**
	 * Patch lines in the cart (they carry Woo\Cart's 'bps' data).
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return array<string, array> cart key => item
	 */
	public static function patch_lines( $cart ): array {
		$out = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! empty( $item['bps'] ) && is_array( $item['bps'] ) ) {
				$out[ $key ] = $item;
			}
		}
		return $out;
	}

	/**
	 * Add a service to the cart (POST /services/cart).
	 *
	 * @param string $service_id Service id.
	 * @param string $design_ref Design ref (patch line), optional for kind=product.
	 * @param string $lang       nl|fr.
	 * @return array|\WP_Error {ok, cartUrl, cartCount, line:{key,label,priceIncl}}
	 */
	public static function add_to_cart( string $service_id, string $design_ref, string $lang ) {
		$svc = Config::service( $service_id );
		if ( ! $svc || ! $svc['enabled'] ) {
			return new \WP_Error( 'bps_svc_unknown', Text::t( 'err.svc_unknown', $lang ), array( 'status' => 404 ) );
		}
		$product_id = Products::product_id( 'service', $service_id );
		if ( $product_id <= 0 || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return new \WP_Error( 'bps_ai_no_woo', Text::t( 'err.no_woo', $lang ), array( 'status' => 503 ) );
		}
		$cart    = WC()->cart;
		$patches = self::patch_lines( $cart );
		$linked  = '';
		if ( 'line_option' === $svc['kind'] ) {
			foreach ( $patches as $item ) {
				if ( '' === $design_ref || (string) ( $item['bps']['ref'] ?? '' ) === $design_ref ) {
					$linked = (string) ( $item['bps']['ref'] ?? '' );
					if ( '' !== $design_ref ) {
						break;
					}
				}
			}
			if ( '' === $linked || ( '' === $design_ref && count( $patches ) > 1 ) ) {
				return new \WP_Error( 'bps_svc_needs_patch', Text::t( 'err.svc_needs_patch', $lang ), array( 'status' => 409 ) );
			}
			$techs = (array) ( $svc['techniques'] ?? array() );
			foreach ( $patches as $item ) {
				if ( $techs && (string) $item['bps']['ref'] === $linked && ! in_array( (string) ( $item['bps']['technique'] ?? '' ), $techs, true ) ) {
					return new \WP_Error( 'bps_svc_technique', Text::t( 'err.svc_technique', $lang ), array( 'status' => 409 ) );
				}
			}
		}
		$ref = '' !== $linked ? $linked : substr( preg_replace( '/[^A-Za-z0-9_-]/', '', $design_ref ), 0, 40 );

		// Already in the cart for this design? Return that line (double tap).
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ( $item[ self::CART_KEY ]['id'] ?? '' ) === $service_id && ( $item[ self::CART_KEY ]['designRef'] ?? '' ) === $ref ) {
				return self::cart_answer( $key, $svc, $lang );
			}
		}
		$data = array(
			self::CART_KEY => array(
				'v'         => 1,
				'id'        => $service_id,
				'designRef' => $ref,
				'leadDays'  => (int) ( $svc['leadDays'] ?? 0 ),
				'lang'      => Text::lang( $lang ),
			),
		);
		if ( '' !== $linked ) {
			$data[ self::LINK_KEY ] = $linked;
		}
		self::$internal_add = true;
		try {
			$key = $cart->add_to_cart( $product_id, 1, 0, array(), $data );
		} catch ( \Exception $e ) {
			$key = false;
		}
		self::$internal_add = false;
		if ( ! $key ) {
			return new \WP_Error( 'bps_ai_no_woo', Text::t( 'err.no_woo', $lang ), array( 'status' => 503 ) );
		}
		$cart->calculate_totals();
		do_action( 'bps_svc_added', $service_id, $key, $ref );
		return self::cart_answer( (string) $key, $svc, $lang );
	}

	/**
	 * REST answer for a cart line.
	 *
	 * @param string $key  Cart key.
	 * @param array  $svc  Service.
	 * @param string $lang Lang.
	 */
	private static function cart_answer( string $key, array $svc, string $lang ): array {
		$cart  = WC()->cart;
		$item  = $cart->get_cart_item( $key );
		$price = (float) $svc['priceIncl'];
		if ( $item && isset( $item['data'] ) && is_object( $item['data'] ) ) {
			$p     = (float) $item['data']->get_price();
			$price = wc_prices_include_tax() ? $p : Config::incl( $p );
		}
		return array(
			'ok'        => true,
			'cartUrl'   => wc_get_cart_url(),
			'cartCount' => (int) $cart->get_cart_contents_count(),
			'line'      => array(
				'key'       => $key,
				'label'     => Text::label( $svc['label'] ?? $svc['id'], $lang ),
				'priceIncl' => $price,
			),
		);
	}

	/**
	 * Price service lines from our settings (never from the client), after the patch prices (prio 20).
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function set_prices( $cart ): void {
		if ( ! is_object( $cart ) || ( is_admin() && ! wp_doing_ajax() && ! defined( 'REST_REQUEST' ) ) ) {
			return;
		}
		$incl    = wc_prices_include_tax();
		$patches = self::patch_lines( $cart );
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item[ self::CART_KEY ]['id'] ) || ! isset( $item['data'] ) || ! is_object( $item['data'] ) ) {
				continue;
			}
			$svc = Config::service( (string) $item[ self::CART_KEY ]['id'] );
			if ( ! $svc ) {
				continue;
			}
			$base = 0.0;
			$qty  = 0;
			$ref  = (string) ( $item[ self::LINK_KEY ] ?? '' );
			if ( '' !== $ref ) {
				foreach ( $patches as $p ) {
					if ( (string) ( $p['bps']['ref'] ?? '' ) === $ref && isset( $p['data'] ) && is_object( $p['data'] ) ) {
						$base += (float) $p['data']->get_price() * (int) $p['quantity'];
						$qty  += (int) $p['quantity'];
					}
				}
			}
			$item['data']->set_price( self::line_price( $svc, $base, $qty, $incl ) );
		}
	}

	/**
	 * Remove line options whose patch line is gone. Returns removed service labels.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function drop_orphans( $cart ): array {
		if ( ! is_object( $cart ) ) {
			return array();
		}
		$refs = array();
		foreach ( self::patch_lines( $cart ) as $p ) {
			$refs[] = (string) ( $p['bps']['ref'] ?? '' );
		}
		$removed = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			$ref = (string) ( $item[ self::LINK_KEY ] ?? '' );
			if ( '' !== $ref && ! in_array( $ref, $refs, true ) ) {
				$svc       = Config::service( (string) ( $item[ self::CART_KEY ]['id'] ?? '' ) );
				$removed[] = $svc ? Text::label( $svc['label'] ) : (string) ( $item[ self::CART_KEY ]['id'] ?? '' );
				$cart->remove_cart_item( $key );
			}
		}
		return $removed;
	}

	/**
	 * Same as drop_orphans with a notice (classic cart).
	 */
	public static function drop_orphans_notice(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( self::drop_orphans( WC()->cart ) as $label ) {
			wc_add_notice( esc_html( Text::t( 'cart.linked_removed', null, array( 'service' => $label ) ) ), 'notice' );
		}
	}

	/**
	 * Show "with your patch" and lead time under a service line.
	 *
	 * @param array $data Item data.
	 * @param array $item Cart item.
	 */
	public static function item_data( $data, $item ) {
		if ( empty( $item[ self::CART_KEY ]['id'] ) ) {
			return $data;
		}
		$data = is_array( $data ) ? $data : array();
		$svc  = Config::service( (string) $item[ self::CART_KEY ]['id'] );
		if ( ! empty( $item[ self::LINK_KEY ] ) ) {
			$data[] = array(
				'key'   => Text::t( 'cart.linked' ),
				'value' => (string) $item[ self::LINK_KEY ],
			);
		} elseif ( ! empty( $item[ self::CART_KEY ]['designRef'] ) ) {
			$data[] = array(
				'key'   => Text::t( 'cart.design' ),
				'value' => (string) $item[ self::CART_KEY ]['designRef'],
			);
		}
		if ( $svc && (int) ( $svc['leadDays'] ?? 0 ) > 0 ) {
			$data[] = array(
				'key'   => Text::t( 'cart.lead' ),
				'value' => Text::t( 'cart.lead_days', null, array( 'n' => (int) $svc['leadDays'] ) ),
			);
		}
		if ( $svc && isset( $svc['freeFromQty'] ) ) {
			$data[] = array(
				'key'   => Text::label( $svc['label'] ),
				'value' => Text::t( 'cart.free_from', null, array( 'qty' => (int) $svc['freeFromQty'] ) ),
			);
		}
		return $data;
	}

	/**
	 * Line options only through our route (they need a patch line).
	 *
	 * @param bool $passed     Passed.
	 * @param int  $product_id Product.
	 */
	public static function block_direct_add( $passed, $product_id ) {
		if ( self::$internal_add ) {
			return $passed;
		}
		$what = Products::lookup( (int) $product_id );
		if ( ! $what ) {
			return $passed;
		}
		if ( 'service' === $what['type'] ) {
			$svc = Config::service( $what['id'] );
			if ( ! $svc || ! $svc['enabled'] || 'line_option' === $svc['kind'] ) {
				wc_add_notice( esc_html( Text::t( 'cart.direct_blocked' ) ), 'error' );
				return false;
			}
		}
		if ( 'pack' === $what['type'] && ! Products::packs_buyable() ) {
			wc_add_notice( esc_html( Text::t( 'err.not_buyable' ) ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Store API: same rule.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $request Request.
	 * @throws \Exception When not allowed.
	 */
	public static function block_store_api_add( $product, $request ): void {
		unset( $request );
		if ( ! is_object( $product ) || self::$internal_add ) {
			return;
		}
		if ( false === self::block_direct_add( true, (int) $product->get_id() ) ) {
			$msg = Text::t( 'cart.direct_blocked' );
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'bps_svc_blocked', esc_html( $msg ), 400 );
			}
			throw new \Exception( esc_html( $msg ) );
		}
	}

	/**
	 * Copy the service data to the order item (classic and block checkout).
	 *
	 * @param \WC_Order_Item_Product $item     Item.
	 * @param string                 $cart_key Cart key.
	 * @param array                  $values   Cart item.
	 * @param \WC_Order              $order    Order.
	 */
	public static function order_line_item( $item, $cart_key, $values, $order ): void {
		unset( $cart_key, $order );
		if ( empty( $values[ self::CART_KEY ]['id'] ) ) {
			return;
		}
		$svc = Config::service( (string) $values[ self::CART_KEY ]['id'] );
		$item->add_meta_data(
			self::ITEM_META,
			array(
				'id'        => (string) $values[ self::CART_KEY ]['id'],
				'designRef' => (string) ( $values[ self::CART_KEY ]['designRef'] ?? '' ),
				'linkedRef' => (string) ( $values[ self::LINK_KEY ] ?? '' ),
				'leadDays'  => $svc ? (int) ( $svc['leadDays'] ?? 0 ) : 0,
			),
			true
		);
	}

	/**
	 * Line options and service products: quantity 1, not editable.
	 *
	 * @param string $html     Quantity HTML.
	 * @param string $key      Cart key.
	 * @param array  $item     Cart item.
	 */
	public static function fixed_quantity( $html, $key = '', $item = array() ) {
		if ( is_array( $item ) && ! empty( $item[ self::CART_KEY ]['id'] ) ) {
			return '1';
		}
		return $html;
	}

	/**
	 * Store API: max 1 for service lines.
	 *
	 * @param int         $value     Max.
	 * @param \WC_Product $product   Product.
	 * @param array|null  $cart_item Cart item.
	 */
	public static function store_api_qty_max( $value, $product, $cart_item = null ) {
		return ( is_array( $cart_item ) && ! empty( $cart_item[ self::CART_KEY ]['id'] ) ) ? 1 : $value;
	}

	/**
	 * Hide our internal item meta in the admin item list (shown through Service_Status instead).
	 *
	 * @param string[] $keys Hidden keys.
	 */
	public static function hidden_meta( $keys ) {
		$keys   = is_array( $keys ) ? $keys : array();
		$keys[] = self::ITEM_META;
		$keys[] = self::START_AFTER_META;
		$keys[] = Service_Status::STATUS_META;
		$keys[] = Service_Status::READY_META;
		$keys[] = Service_Status::NOTE_META;
		$keys[] = '_bps_ai_pack';
		$keys[] = '_bps_ai_coupon';
		$keys[] = '_bps_ai_fulfilled';
		return $keys;
	}
}
