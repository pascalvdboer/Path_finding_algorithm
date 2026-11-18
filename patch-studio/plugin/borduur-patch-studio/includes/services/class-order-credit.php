<?php
/**
 * "Terug als korting": personal WooCommerce coupons per pack (and per physical sample), applied automatically
 * (SPEC-AI-DIENSTEN §0 A11, §11.4).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * One coupon per pack line: fixed_cart, single use, restricted to the buyer's e-mail, valid on patch products only,
 * minimum article value, expiry. Never two credit coupons on one order.
 */
final class Order_Credit {

	const META_FLAG  = '_bps_ai_credit_coupon';
	const META_KIND  = '_bps_ai_credit_kind';
	const META_EMAIL = '_bps_ai_credit_email';
	const META_USER  = '_bps_ai_credit_user';
	const META_ORDER = '_bps_ai_credit_order';
	const META_MINQ  = '_bps_ai_credit_min_qty';
	const META_MIN   = '_bps_ai_credit_min_incl';
	const USED_META  = '_bps_ai_credit_used';
	const SESSION_OFF = 'bps_ai_credit_removed';

	/** Crockford base32 alphabet (no I, L, O, U). */
	const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/** @var bool Applied once per request. */
	private static $done = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'auto_apply' ), 30 );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'coupon_is_valid' ), 20, 3 );
		add_action( 'woocommerce_removed_coupon', array( __CLASS__, 'removed_coupon' ) );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( __CLASS__, 'coupon_label' ), 20, 2 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'record_usage' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'record_usage' ), 20 );
	}

	/* ---------------------------------------------------------------------
	 * Pure helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Coupon amount in the shop's price entry mode.
	 *
	 * @param float $price_incl Paid price incl. VAT.
	 * @param float $percent    Percentage that comes back.
	 * @param bool  $incl       Shop enters prices incl. VAT.
	 * @param float $vat        VAT rate.
	 */
	public static function amount( float $price_incl, float $percent, bool $incl, float $vat ): float {
		$back = Config::round_half_up( $price_incl * max( 0.0, min( 100.0, $percent ) ) / 100, 2 );
		return $incl ? $back : Config::round_half_up( $back / ( 1 + $vat ), 4 );
	}

	/**
	 * Random code with a prefix: 'AI-' + 8 Crockford chars.
	 *
	 * @param string $prefix Prefix.
	 */
	public static function code( string $prefix = 'AI-' ): string {
		$s = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$s .= self::ALPHABET[ random_int( 0, 31 ) ];
		}
		return $prefix . $s;
	}

	/**
	 * Pick the coupon to apply: valid, minimums reached, highest amount. Null = none.
	 *
	 * @param array $candidates [{code, amount, minIncl, minQty, expires(ts|0), used(bool), kind}].
	 * @param array $ctx        {patchSubtotalIncl, maxPatchQty, now, applied: string[]}.
	 */
	public static function choose( array $candidates, array $ctx ): ?string {
		$applied = array_map( 'strtolower', (array) ( $ctx['applied'] ?? array() ) );
		foreach ( $candidates as $c ) {
			if ( in_array( strtolower( (string) $c['code'] ), $applied, true ) ) {
				return null; // Never two credit coupons on one order.
			}
		}
		$best = null;
		foreach ( $candidates as $c ) {
			if ( ! empty( $c['used'] ) ) {
				continue;
			}
			if ( ! empty( $c['expires'] ) && (int) $c['expires'] <= (int) $ctx['now'] ) {
				continue;
			}
			if ( (float) $ctx['patchSubtotalIncl'] + 0.00001 < (float) ( $c['minIncl'] ?? 0 ) ) {
				continue;
			}
			if ( (int) ( $c['minQty'] ?? 0 ) > (int) ( $ctx['maxPatchQty'] ?? 0 ) ) {
				continue;
			}
			if ( null === $best || (float) $c['amount'] > (float) $best['amount'] ) {
				$best = $c;
			}
		}
		return $best ? (string) $best['code'] : null;
	}

	/* ---------------------------------------------------------------------
	 * Creating
	 * ------------------------------------------------------------------- */

	/**
	 * Patch products a credit coupon is valid on.
	 *
	 * @return int[]
	 */
	public static function patch_product_ids(): array {
		$ids = array();
		if ( class_exists( '\BorduurPatchStudio\Woo\Config' ) ) {
			$ids[] = (int) \BorduurPatchStudio\Woo\Config::product_id();
		} else {
			$ids[] = (int) get_option( 'bps_product_id', 0 );
		}
		$ids = (array) apply_filters( 'bps_ai_credit_product_ids', array_filter( $ids ) );
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Create the credit coupon for a paid pack or sample line (idempotent through the item meta _bps_ai_coupon).
	 *
	 * @param \WC_Order              $order      Order.
	 * @param \WC_Order_Item_Product $item       Item.
	 * @param string                 $kind       pack|sample.
	 * @param float                  $price_incl Paid incl. VAT for this line.
	 * @return string Coupon code ('' when not created).
	 */
	public static function create( $order, $item, string $kind, float $price_incl ): string {
		$existing = (string) $item->get_meta( '_bps_ai_coupon' );
		if ( '' !== $existing ) {
			return $existing;
		}
		if ( ! class_exists( 'WC_Coupon' ) || $price_incl <= 0 ) {
			return '';
		}
		$email = Owner::normalize_email( (string) $order->get_billing_email() );
		if ( ! is_email( $email ) ) {
			return '';
		}
		if ( 'sample' === $kind ) {
			$svc     = Config::service( 'd6_sample' );
			$percent = 100.0;
			$months  = max( 1, (int) ( $svc['refundValidMonths'] ?? 6 ) );
			$min_qty = (int) ( $svc['refundFromQty'] ?? 25 );
			$min     = 0.0;
			$prefix  = 'STAAL-';
		} else {
			$percent = (float) Config::get( 'orderCredit.percent', 100 );
			$months  = max( 1, (int) Config::get( 'orderCredit.validMonths', 12 ) );
			$min_qty = 0;
			$min     = (float) Config::get( 'orderCredit.minArticleIncl', 50 );
			$prefix  = 'AI-';
		}
		if ( $percent <= 0 ) {
			return '';
		}
		$amount = self::amount( $price_incl, $percent, wc_prices_include_tax(), Config::vat_rate() );
		if ( $amount <= 0 ) {
			return '';
		}
		$code = self::code( $prefix );
		for ( $i = 0; $i < 3 && wc_get_coupon_id_by_code( $code ); $i++ ) {
			$code = self::code( $prefix );
		}
		$c = new \WC_Coupon();
		$c->set_code( $code );
		$c->set_description( Text::t( 'sample' === $kind ? 'credit.sample_label' : 'credit.coupon_label', 'nl' ) . ' · #' . $order->get_order_number() );
		$c->set_discount_type( 'fixed_cart' );
		$c->set_amount( $amount );
		$c->set_individual_use( false );
		$c->set_usage_limit( 1 );
		$c->set_usage_limit_per_user( 1 );
		$c->set_email_restrictions( array( $email ) );
		$c->set_date_expires( strtotime( '+' . $months . ' months' ) );
		if ( $min > 0 ) {
			$c->set_minimum_amount( (string) $min );
		}
		$c->set_product_ids( self::patch_product_ids() );
		$c->set_excluded_product_ids( Products::all_ids() );
		$c->update_meta_data( self::META_FLAG, '1' );
		$c->update_meta_data( self::META_KIND, $kind );
		$c->update_meta_data( self::META_EMAIL, $email );
		$c->update_meta_data( self::META_USER, (int) $order->get_customer_id() );
		$c->update_meta_data( self::META_ORDER, (int) $order->get_id() );
		$c->update_meta_data( self::META_MIN, $min );
		$c->update_meta_data( self::META_MINQ, $min_qty );
		$c->save();
		$item->update_meta_data( '_bps_ai_coupon', $code );
		$item->save();
		return $code;
	}

	/**
	 * Take a coupon out of use (withdrawal, refund).
	 *
	 * @param string $code Code.
	 */
	public static function expire( string $code ): void {
		if ( '' === $code || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return;
		}
		$id = (int) wc_get_coupon_id_by_code( $code );
		if ( $id > 0 ) {
			wp_trash_post( $id );
		}
	}

	/* ---------------------------------------------------------------------
	 * Cart
	 * ------------------------------------------------------------------- */

	/**
	 * Credit coupons of a customer (by account or e-mail).
	 *
	 * @param int    $user_id User id.
	 * @param string $email   E-mail.
	 * @return array Candidates for choose().
	 */
	public static function candidates( int $user_id, string $email ): array {
		$or = array( 'relation' => 'OR' );
		if ( $user_id > 0 ) {
			$or[] = array(
				'key'   => self::META_USER,
				'value' => $user_id,
			);
		}
		if ( is_email( $email ) ) {
			$or[] = array(
				'key'   => self::META_EMAIL,
				'value' => Owner::normalize_email( $email ),
			);
		}
		if ( count( $or ) < 2 ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => self::META_FLAG,
						'value' => '1',
					),
					$or,
				),
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$c = new \WC_Coupon( (int) $id );
			if ( ! $c->get_id() ) {
				continue;
			}
			$exp   = $c->get_date_expires();
			$out[] = array(
				'code'    => $c->get_code(),
				'amount'  => (float) $c->get_amount(),
				'minIncl' => (float) $c->get_meta( self::META_MIN ),
				'minQty'  => (int) $c->get_meta( self::META_MINQ ),
				'expires' => $exp ? $exp->getTimestamp() : 0,
				'used'    => $c->get_usage_limit() > 0 && $c->get_usage_count() >= $c->get_usage_limit(),
				'kind'    => (string) $c->get_meta( self::META_KIND ),
			);
		}
		return $out;
	}

	/**
	 * Patch subtotal incl. VAT and largest patch quantity in a cart.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return array{subtotalIncl:float, maxQty:int, has:bool}
	 */
	public static function patch_stats( $cart ): array {
		$ids  = self::patch_product_ids();
		$sub  = 0.0;
		$max  = 0;
		$has  = false;
		$incl = wc_prices_include_tax();
		foreach ( $cart->get_cart() as $item ) {
			$pid = (int) ( $item['product_id'] ?? 0 );
			if ( ! in_array( $pid, $ids, true ) && empty( $item['bps'] ) ) {
				continue;
			}
			$has   = true;
			$qty   = (int) $item['quantity'];
			$price = isset( $item['data'] ) && is_object( $item['data'] ) ? (float) $item['data']->get_price() : 0.0;
			$sub  += ( $incl ? $price : $price * ( 1 + Config::vat_rate() ) ) * $qty;
			$max   = max( $max, $qty );
		}
		return array(
			'subtotalIncl' => Config::round_half_up( $sub, 2 ),
			'maxQty'       => $max,
			'has'          => $has,
		);
	}

	/**
	 * Apply the best credit coupon automatically (once per request, never against the customer's will).
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function auto_apply( $cart ): void {
		if ( self::$done || ! is_object( $cart ) || ( is_admin() && ! wp_doing_ajax() && ! defined( 'REST_REQUEST' ) ) ) {
			return;
		}
		self::$done = true;
		$stats      = self::patch_stats( $cart );
		if ( ! $stats['has'] ) {
			return;
		}
		$session = function_exists( 'WC' ) && WC()->session ? WC()->session : null;
		$user_id = (int) get_current_user_id();
		$email   = '';
		if ( $session ) {
			$customer = $session->get( 'customer' );
			$email    = is_array( $customer ) ? (string) ( $customer['email'] ?? '' ) : '';
		}
		if ( '' === $email && $user_id > 0 ) {
			$u     = get_userdata( $user_id );
			$email = $u ? (string) $u->user_email : '';
		}
		$cands = self::candidates( $user_id, $email );
		if ( ! $cands ) {
			return;
		}
		$removed = $session ? (array) $session->get( self::SESSION_OFF, array() ) : array();
		$cands   = array_values( array_filter( $cands, static fn( $c ) => ! in_array( strtolower( $c['code'] ), $removed, true ) ) );
		$code    = self::choose(
			$cands,
			array(
				'patchSubtotalIncl' => $stats['subtotalIncl'],
				'maxPatchQty'       => $stats['maxQty'],
				'now'               => time(),
				'applied'           => $cart->get_applied_coupons(),
			)
		);
		if ( ! $code ) {
			return;
		}
		if ( $cart->apply_coupon( $code ) ) {
			// Next to Woo's "coupon applied": say in plain words what it is.
			$c      = new \WC_Coupon( $code );
			$k      = 'sample' === $c->get_meta( self::META_KIND ) ? 'credit.sample_applied' : 'credit.applied';
			$amount = wc_prices_include_tax() ? (float) $c->get_amount() : Config::incl( (float) $c->get_amount() );
			wc_add_notice( esc_html( Text::t( $k, null, array( 'amount' => Text::money( $amount ) ) ) ), 'success' );
		}
	}

	/**
	 * Customer removed a credit coupon: do not put it back in this session.
	 *
	 * @param string $code Code.
	 */
	public static function removed_coupon( $code ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! self::is_ours( (string) $code ) ) {
			return;
		}
		$list   = (array) WC()->session->get( self::SESSION_OFF, array() );
		$list[] = strtolower( (string) $code );
		WC()->session->set( self::SESSION_OFF, array_values( array_unique( $list ) ) );
	}

	/**
	 * Is this one of our credit coupons?
	 *
	 * @param string $code Code.
	 */
	public static function is_ours( string $code ): bool {
		if ( '' === $code || ! class_exists( 'WC_Coupon' ) ) {
			return false;
		}
		$c = new \WC_Coupon( $code );
		return $c->get_id() && '1' === (string) $c->get_meta( self::META_FLAG );
	}

	/**
	 * Extra validity rules: one credit coupon per order; sample credit needs a minimum patch quantity.
	 *
	 * @param bool        $valid     Valid.
	 * @param \WC_Coupon  $coupon    Coupon.
	 * @param object|null $discounts WC_Discounts.
	 * @return bool
	 * @throws \Exception With a customer message when not valid.
	 */
	public static function coupon_is_valid( $valid, $coupon, $discounts = null ) {
		unset( $discounts );
		if ( ! $valid || ! is_object( $coupon ) || '1' !== (string) $coupon->get_meta( self::META_FLAG ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $valid;
		}
		$cart = WC()->cart;
		foreach ( $cart->get_applied_coupons() as $other ) {
			if ( strtolower( $other ) !== strtolower( $coupon->get_code() ) && self::is_ours( $other ) ) {
				return false;
			}
		}
		$min_qty = (int) $coupon->get_meta( self::META_MINQ );
		if ( $min_qty > 0 && self::patch_stats( $cart )['maxQty'] < $min_qty ) {
			throw new \Exception( esc_html( Text::t( 'credit.sample_min_qty', null, array( 'qty' => $min_qty ) ) ), 100 );
		}
		return true;
	}

	/**
	 * Readable coupon label in the totals.
	 *
	 * @param string     $label  Label.
	 * @param \WC_Coupon $coupon Coupon.
	 */
	public static function coupon_label( $label, $coupon ) {
		if ( is_object( $coupon ) && '1' === (string) $coupon->get_meta( self::META_FLAG ) ) {
			return esc_html( Text::t( 'sample' === $coupon->get_meta( self::META_KIND ) ? 'credit.sample_label' : 'credit.coupon_label' ) );
		}
		return $label;
	}

	/**
	 * Order paid: remember which credit coupon was used (_bps_ai_credit_used).
	 *
	 * @param int $order_id Order id.
	 */
	public static function record_usage( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::USED_META ) ) {
			return;
		}
		foreach ( $order->get_items( 'coupon' ) as $ci ) {
			$code = (string) $ci->get_code();
			if ( ! self::is_ours( $code ) ) {
				continue;
			}
			$c = new \WC_Coupon( $code );
			$order->update_meta_data(
				self::USED_META,
				array(
					'coupon'      => $code,
					'amount'      => (float) $ci->get_discount() + (float) $ci->get_discount_tax(),
					'packOrderId' => (int) $c->get_meta( self::META_ORDER ),
					'kind'        => (string) $c->get_meta( self::META_KIND ),
				)
			);
			$order->save();
			do_action( 'bps_ai_credit_used', $order, $code );
			break;
		}
	}
}
