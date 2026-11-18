<?php
/**
 * "Mijn account → Ontwerprondes & diensten": balance, lots, credit coupons, ordered services, buying packs and the
 * withdrawal button (SPEC-AI-DIENSTEN §11.5, 04-juridisch §4). Guests use a signed link from the pack e-mail.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Withdrawal = voluntary refund of the unused rounds within refundUnusedDays (14) after purchase, only while the
 * pack's credit coupon is unused (otherwise the customer would get the money back twice).
 */
final class Withdrawal {

	const SLUG         = 'ontwerprondes';
	const REWRITE_OPT  = 'bps_svc_rewrite_v';
	const REWRITE_V    = 1;
	const GUEST_PARAM  = 'bps_wd';
	const LOT_PARAM    = 'bps_wd_lot';
	const NONCE        = 'bps_wd_';
	const LOG_META     = '_bps_ai_withdrawals';
	const LINK_DAYS    = 30;

	/** @var callable|null Tests: refund creator (order, amount, reason) → true|WP_Error. */
	public static $refunder = null;

	/** @var callable|null Tests: mailer (to, subject, body). */
	public static $mailer = null;

	/** @var array|null Result of a POST in this request, for the page render. */
	private static $flash = null;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 30 );
		add_filter( 'woocommerce_endpoint_' . self::slug() . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . self::slug() . '_endpoint', array( __CLASS__, 'render_account' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'guest_page' ), 20 );
	}

	/**
	 * Endpoint slug (FR sites: filter `bps_svc_account_slug`, e.g. "essais-design").
	 */
	public static function slug(): string {
		$s = (string) apply_filters( 'bps_svc_account_slug', self::SLUG );
		$s = sanitize_title( $s );
		return '' !== $s ? $s : self::SLUG;
	}

	/**
	 * Register the endpoint; flush rewrite rules once per version (never on every request).
	 */
	public static function endpoint(): void {
		add_rewrite_endpoint( self::slug(), EP_ROOT | EP_PAGES );
		if ( (int) get_option( self::REWRITE_OPT ) !== self::REWRITE_V ) {
			update_option( self::REWRITE_OPT, self::REWRITE_V, false );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Woo query var.
	 *
	 * @param array $vars Vars.
	 */
	public static function query_vars( $vars ) {
		$vars                = is_array( $vars ) ? $vars : array();
		$vars[ self::slug() ] = self::slug();
		return $vars;
	}

	/**
	 * Menu item before "Log out".
	 *
	 * @param array $items Items.
	 */
	public static function menu( $items ) {
		$items  = is_array( $items ) ? $items : array();
		$label  = Text::t( 'account.menu' );
		$out    = array();
		$placed = false;
		foreach ( $items as $k => $v ) {
			if ( 'customer-logout' === $k && ! $placed ) {
				$out[ self::slug() ] = $label;
				$placed              = true;
			}
			$out[ $k ] = $v;
		}
		if ( ! $placed ) {
			$out[ self::slug() ] = $label;
		}
		return $out;
	}

	/**
	 * Page title.
	 */
	public static function title(): string {
		return Text::t( 'account.menu' );
	}

	/* ---------------------------------------------------------------------
	 * Signed guest links
	 * ------------------------------------------------------------------- */

	/**
	 * Signature for a guest link.
	 *
	 * @param int $order_id Order id.
	 * @param int $exp      Expiry timestamp.
	 */
	public static function sign( int $order_id, int $exp ): string {
		return substr( hash_hmac( 'sha256', 'wd|' . $order_id . '|' . $exp, function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'bps' ), 0, 32 );
	}

	/**
	 * Token "orderId.exp.sig".
	 *
	 * @param int      $order_id Order id.
	 * @param int|null $now      Now (tests).
	 */
	public static function token( int $order_id, ?int $now = null ): string {
		$exp = ( $now ?? time() ) + self::LINK_DAYS * DAY_IN_SECONDS;
		return $order_id . '.' . $exp . '.' . self::sign( $order_id, $exp );
	}

	/**
	 * Order id from a token, 0 when invalid or expired.
	 *
	 * @param string   $token Token.
	 * @param int|null $now   Now (tests).
	 */
	public static function verify( string $token, ?int $now = null ): int {
		if ( ! preg_match( '/^(\d{1,12})\.(\d{9,11})\.([0-9a-f]{32})$/', $token, $m ) ) {
			return 0;
		}
		$order_id = (int) $m[1];
		$exp      = (int) $m[2];
		if ( $exp < ( $now ?? time() ) || ! hash_equals( self::sign( $order_id, $exp ), $m[3] ) ) {
			return 0;
		}
		return $order_id;
	}

	/**
	 * Where a customer withdraws for this order: the account page, or a signed link for guests.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function link_for_order( $order ): string {
		if ( (int) $order->get_customer_id() > 0 && function_exists( 'wc_get_account_endpoint_url' ) ) {
			return (string) wc_get_account_endpoint_url( self::slug() );
		}
		return add_query_arg( self::GUEST_PARAM, self::token( (int) $order->get_id() ), home_url( '/' ) );
	}

	/* ---------------------------------------------------------------------
	 * Withdrawal (domain)
	 * ------------------------------------------------------------------- */

	/**
	 * Is the credit coupon of a pack item already used?
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 */
	public static function coupon_used( $item ): bool {
		$code = (string) $item->get_meta( '_bps_ai_coupon' );
		if ( '' === $code || ! class_exists( 'WC_Coupon' ) ) {
			return false;
		}
		$c = new \WC_Coupon( $code );
		return $c->get_id() && $c->get_usage_count() > 0;
	}

	/**
	 * Pack items of an order that can be withdrawn now: [{item, itemId, lot, preview}].
	 *
	 * @param \WC_Order $order   Order.
	 * @param Credits   $credits Credits.
	 */
	public static function withdrawable_items( $order, Credits $credits ): array {
		$out = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$meta = $item->get_meta( '_bps_ai_pack' );
			if ( ! is_array( $meta ) || empty( $meta['lotId'] ) ) {
				continue;
			}
			$lot = $credits->store()->get_lot( (int) $meta['lotId'] );
			if ( ! $lot || (int) $lot['order_id'] !== (int) $order->get_id() || ! $credits->withdrawable( $lot ) || self::coupon_used( $item ) ) {
				continue;
			}
			$out[] = array(
				'item'    => $item,
				'itemId'  => (int) $item_id,
				'lot'     => $lot,
				'preview' => Credits::refund_amount( $lot ),
			);
		}
		return $out;
	}

	/**
	 * Withdraw one lot of an order: rounds to 0, refund, coupon out of use, e-mails.
	 *
	 * @param \WC_Order $order   Order.
	 * @param int       $lot_id  Lot id.
	 * @param Credits   $credits Credits.
	 * @param string    $lang    Lang.
	 * @return array|\WP_Error {amount, unused, total, refunded:bool}
	 */
	public static function perform( $order, int $lot_id, Credits $credits, string $lang ) {
		$target = null;
		foreach ( self::withdrawable_items( $order, $credits ) as $row ) {
			if ( (int) $row['lot']['id'] === $lot_id ) {
				$target = $row;
				break;
			}
		}
		if ( ! $target ) {
			return new \WP_Error( 'bps_ai_withdraw_none', Text::t( 'account.withdraw_none', $lang ), array( 'status' => 409 ) );
		}
		$res = $credits->withdraw( $lot_id, 'withdrawal', 'withdrawn' );
		if ( ! $res ) {
			return new \WP_Error( 'bps_ai_withdraw_none', Text::t( 'account.withdraw_none', $lang ), array( 'status' => 409 ) );
		}
		Order_Credit::expire( (string) $target['item']->get_meta( '_bps_ai_coupon' ) );

		$reason   = 'Herroeping ontwerppakket (ongebruikt: ' . $res['unused'] . '/' . $res['total'] . ')';
		$refunded = true;
		$error    = '';
		if ( $res['amount'] > 0 ) {
			$r = self::$refunder ? call_user_func( self::$refunder, $order, $res['amount'], $reason ) : self::create_refund( $order, $res['amount'], $reason );
			if ( is_wp_error( $r ) ) {
				$refunded = false;
				$error    = $r->get_error_message();
			}
		}
		$now = time();
		$log = $order->get_meta( self::LOG_META );
		$log = is_array( $log ) ? $log : array();
		$log[] = array(
			'lotId'    => $lot_id,
			'at'       => gmdate( 'Y-m-d H:i:s', $now ),
			'amount'   => $res['amount'],
			'unused'   => $res['unused'],
			'refunded' => $refunded,
		);
		$order->update_meta_data( self::LOG_META, $log );
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( $reason . ': ' . number_format( $res['amount'], 2, ',', '.' ) . ' EUR' . ( $refunded ? '' : ' — automatisch terugbetalen lukte niet, betaal manueel terug.' ) );
		}
		$order->save();

		$vars = array(
			'nr'     => (string) $order->get_order_number(),
			'datum'  => Text::date( gmdate( 'Y-m-d H:i:s', $now ), $lang ),
			'uur'    => function_exists( 'wp_date' ) ? wp_date( 'H:i', $now ) : gmdate( 'H:i', $now ),
			'bedrag' => number_format( $res['amount'], 2, ',', '.' ),
		);
		self::mail( (string) $order->get_billing_email(), Text::t( 'withdraw.email_subject', $lang ), Text::legal( 'legal.ai.withdraw_email', $lang, $vars ) );
		if ( ! $refunded ) {
			$admin = (string) get_option( 'admin_email' );
			self::mail(
				$admin,
				Text::t( 'withdraw.admin_subject', 'nl', array( 'nr' => $vars['nr'] ) ),
				Text::t(
					'withdraw.admin_body',
					'nl',
					array(
						'nr'     => $vars['nr'],
						'amount' => Text::money( $res['amount'], 'nl' ),
						'reason' => wp_strip_all_tags( $error ),
					)
				)
			);
		}
		do_action( 'bps_ai_pack_withdrawn', $order, $lot_id, $res );
		return array_merge( $res, array( 'refunded' => $refunded ) );
	}

	/**
	 * Woo refund through the original payment method; a gateway without automatic refunds gives a WP_Error.
	 *
	 * @param \WC_Order $order  Order.
	 * @param float     $amount Amount incl. VAT.
	 * @param string    $reason Reason.
	 * @return true|\WP_Error
	 */
	private static function create_refund( $order, float $amount, string $reason ) {
		if ( ! function_exists( 'wc_create_refund' ) ) {
			return new \WP_Error( 'bps_no_refund', 'WooCommerce refunds unavailable' );
		}
		$r = wc_create_refund(
			array(
				'amount'         => wc_format_decimal( $amount, 2 ),
				'reason'         => $reason,
				'order_id'       => (int) $order->get_id(),
				'refund_payment' => true,
				'restock_items'  => false,
			)
		);
		return is_wp_error( $r ) ? $r : true;
	}

	/**
	 * Send a plain mail (customer texts are short and contain no personal data beyond the order number).
	 *
	 * @param string $to      To.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 */
	private static function mail( string $to, string $subject, string $body ): void {
		if ( ! is_email( $to ) ) {
			return;
		}
		if ( self::$mailer ) {
			call_user_func( self::$mailer, $to, $subject, $body );
			return;
		}
		wp_mail( $to, $subject, $body );
	}

	/* ---------------------------------------------------------------------
	 * Requests
	 * ------------------------------------------------------------------- */

	/**
	 * Confirm a withdrawal (POST from the account page or the guest page).
	 */
	public static function handle_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['bps_wd_confirm'] ) || empty( $_POST[ self::LOT_PARAM ] ) ) {
			return;
		}
		$lot_id = absint( wp_unslash( $_POST[ self::LOT_PARAM ] ) );
		$nonce  = isset( $_POST['_bps_wd_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_bps_wd_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE . $lot_id ) ) {
			return;
		}
		$order = self::order_for_request( $lot_id );
		$lang  = Text::lang( null );
		if ( ! $order ) {
			self::$flash = array(
				'ok'  => false,
				'msg' => Text::t( 'account.withdraw_none', $lang ),
			);
			return;
		}
		$res = self::perform( $order, $lot_id, Credits::instance(), $lang );
		if ( is_wp_error( $res ) ) {
			self::$flash = array(
				'ok'  => false,
				'msg' => $res->get_error_message(),
			);
			return;
		}
		$key         = $res['refunded'] ? 'account.withdraw_done' : 'account.withdraw_error';
		self::$flash = array(
			'ok'  => true,
			'msg' => Text::t( $key, $lang, array( 'amount' => Text::money( (float) $res['amount'], $lang ) ) ),
		);
	}

	/**
	 * Order the current visitor may withdraw this lot for: own account order, or a valid guest token.
	 *
	 * @param int $lot_id Lot id.
	 * @return \WC_Order|null
	 */
	private static function order_for_request( int $lot_id ) {
		$lot = Credits::instance()->store()->get_lot( $lot_id );
		if ( ! $lot || empty( $lot['order_id'] ) || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( (int) $lot['order_id'] );
		if ( ! $order ) {
			return null;
		}
		$uid = (int) get_current_user_id();
		if ( $uid > 0 && (int) $order->get_customer_id() === $uid ) {
			return $order;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- the token is itself a signature; the POST nonce was checked.
		$token = isset( $_REQUEST[ self::GUEST_PARAM ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::GUEST_PARAM ] ) ) : '';
		if ( '' !== $token && self::verify( $token ) === (int) $order->get_id() ) {
			return $order;
		}
		return null;
	}

	/**
	 * Guest page: ?bps_wd=orderId.exp.sig shows the packs of that order with the withdrawal button.
	 */
	public static function guest_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification -- signed token.
		if ( empty( $_GET[ self::GUEST_PARAM ] ) ) {
			return;
		}
		$token    = sanitize_text_field( wp_unslash( $_GET[ self::GUEST_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$order_id = self::verify( $token );
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$lang     = $order ? Service_Status::order_lang( $order ) : Text::lang( null );
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		get_header();
		echo '<main class="bps-wd" style="max-width:720px;margin:2em auto;padding:0 16px">';
		echo '<h1>' . esc_html( Text::t( 'account.guest_title', $lang ) ) . '</h1>';
		self::render_flash();
		if ( ! $order ) {
			echo '<p>' . esc_html( Text::t( 'account.link_invalid', $lang ) ) . '</p>';
		} else {
			self::render_withdraw_list( $order, $lang, $token );
		}
		echo '</main>';
		get_footer();
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	/**
	 * Result message of a POST in this request.
	 */
	private static function render_flash(): void {
		if ( ! self::$flash ) {
			return;
		}
		$cls = self::$flash['ok'] ? 'woocommerce-message' : 'woocommerce-error';
		echo '<div class="' . esc_attr( $cls ) . '" role="status">' . esc_html( self::$flash['msg'] ) . '</div>';
	}

	/**
	 * Withdrawable packs of one order with summary + confirm form (two steps: button → summary → confirm).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $lang  Lang.
	 * @param string    $token Guest token ('' for account).
	 */
	private static function render_withdraw_list( $order, string $lang, string $token = '' ): void {
		$rows = self::withdrawable_items( $order, Credits::instance() );
		if ( ! $rows ) {
			if ( ! self::$flash ) {
				$used = false;
				foreach ( $order->get_items() as $item ) {
					$used = $used || ( is_array( $item->get_meta( '_bps_ai_pack' ) ) && self::coupon_used( $item ) );
				}
				echo '<p>' . esc_html( Text::t( $used ? 'account.credit_none_used' : 'account.withdraw_none', $lang ) ) . '</p>';
			}
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- display only.
		$asked = isset( $_GET[ self::LOT_PARAM ] ) ? absint( wp_unslash( $_GET[ self::LOT_PARAM ] ) ) : 0;
		foreach ( $rows as $row ) {
			$lot_id = (int) $row['lot']['id'];
			$p      = $row['preview'];
			echo '<div class="bps-wd__pack" style="margin:1em 0;padding:1em;border:1px solid #ddd;border-radius:8px">';
			if ( $asked === $lot_id ) {
				echo '<p>' . esc_html(
					Text::legal(
						'legal.ai.withdraw_summary',
						$lang,
						array(
							'nr'     => (string) $order->get_order_number(),
							'u'      => $p['unused'],
							'n'      => $p['total'],
							'bedrag' => number_format( $p['amount'], 2, ',', '.' ),
						)
					)
				) . '</p>';
				echo '<form method="post">';
				wp_nonce_field( self::NONCE . $lot_id, '_bps_wd_nonce' );
				echo '<input type="hidden" name="' . esc_attr( self::LOT_PARAM ) . '" value="' . (int) $lot_id . '">';
				if ( '' !== $token ) {
					echo '<input type="hidden" name="' . esc_attr( self::GUEST_PARAM ) . '" value="' . esc_attr( $token ) . '">';
				}
				echo '<button type="submit" class="button" name="bps_wd_confirm" value="1">' . esc_html( Text::legal( 'legal.ai.withdraw_confirm', $lang ) ) . '</button> ';
				echo '<a href="' . esc_url( remove_query_arg( self::LOT_PARAM ) ) . '">' . esc_html( Text::t( 'account.cancel', $lang ) ) . '</a>';
				echo '</form>';
			} else {
				echo '<p>' . esc_html( Text::t( 'account.order', $lang ) ) . ' ' . esc_html( (string) $order->get_order_number() ) . ' · ' . esc_html( Text::t( 'account.buy_btn', $lang, array( 'n' => $p['total'], 'price' => Text::money( (float) $row['lot']['price_incl'], $lang ) ) ) ) . '</p>';
				echo '<a class="button" href="' . esc_url( add_query_arg( self::LOT_PARAM, $lot_id ) ) . '">' . esc_html( Text::legal( 'legal.ai.withdraw_button', $lang ) ) . '</a>';
			}
			echo '</div>';
		}
	}

	/**
	 * The account page.
	 */
	public static function render_account(): void {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return;
		}
		$lang    = Text::lang( null );
		$owner   = Owner::for_user( $uid );
		$credits = Credits::instance();
		$bal     = $credits->balance( $owner );
		$lots    = $credits->lots( $owner );
		$user    = get_userdata( $uid );
		$email   = $user ? (string) $user->user_email : '';

		echo '<div class="bps-account">';
		self::render_flash();

		// Rounds.
		echo '<h2>' . esc_html( Text::t( 'account.rounds', $lang ) ) . '</h2>';
		if ( ! $lots ) {
			echo '<p>' . esc_html( Text::t( 'account.none', $lang ) ) . '</p>';
		} else {
			echo '<p><strong>' . esc_html( Text::t( 'account.balance', $lang, array( 'n' => $bal['total'] ) ) ) . '</strong></p>';
			if ( $bal['paidLocked'] > 0 ) {
				echo '<p>' . esc_html( Text::t( 'account.locked', $lang, array( 'n' => $bal['paidLocked'], 'date' => Text::date( $bal['lockedUntil'], $lang ) ) ) ) . '</p>';
			}
			echo '<table class="shop_table shop_table_responsive bps-account__lots"><thead><tr>';
			foreach ( array( 'what', 'used', 'valid', 'status' ) as $col ) {
				echo '<th>' . esc_html( Text::t( 'account.col.' . $col, $lang ) ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $lots as $lot ) {
				$st = $lot['locked'] ? 'locked' : $lot['status'];
				echo '<tr><td data-title="' . esc_attr( Text::t( 'account.col.what', $lang ) ) . '">' . esc_html( Text::t( 'account.src.' . $lot['source'], $lang ) ) . ( $lot['orderId'] ? ' · #' . esc_html( (string) $lot['orderId'] ) : '' ) . '</td>';
				echo '<td data-title="' . esc_attr( Text::t( 'account.col.used', $lang ) ) . '">' . (int) $lot['used'] . ' / ' . (int) $lot['total'] . '</td>';
				echo '<td data-title="' . esc_attr( Text::t( 'account.col.valid', $lang ) ) . '">' . esc_html( Text::date( $lot['expiresAt'], $lang ) ) . '</td>';
				echo '<td data-title="' . esc_attr( Text::t( 'account.col.status', $lang ) ) . '">' . esc_html( Text::t( 'account.st.' . $st, $lang ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// Withdrawal per pack order (only those still in the window).
		$seen = array();
		foreach ( $lots as $lot ) {
			if ( 'pack' !== $lot['source'] || empty( $lot['orderId'] ) || isset( $seen[ $lot['orderId'] ] ) || 'active' !== $lot['status'] ) {
				continue;
			}
			$seen[ $lot['orderId'] ] = true;
			$order                   = wc_get_order( (int) $lot['orderId'] );
			if ( $order && (int) $order->get_customer_id() === $uid && self::withdrawable_items( $order, $credits ) ) {
				self::render_withdraw_list( $order, $lang );
			}
		}

		// Credit coupons.
		$coupons = Order_Credit::candidates( $uid, $email );
		if ( $coupons ) {
			echo '<h3>' . esc_html( Text::t( 'account.credit_title', $lang ) ) . '</h3><ul class="bps-account__credits">';
			foreach ( $coupons as $c ) {
				$amount = wc_prices_include_tax() ? (float) $c['amount'] : Config::incl( (float) $c['amount'] );
				if ( $c['used'] ) {
					echo '<li>' . esc_html( Text::t( 'account.credit_used', $lang, array( 'amount' => Text::money( $amount, $lang ) ) ) ) . '</li>';
				} elseif ( ! $c['expires'] || $c['expires'] > time() ) {
					echo '<li>' . esc_html(
						Text::t(
							'account.credit',
							$lang,
							array(
								'amount' => Text::money( $amount, $lang ),
								'min'    => Text::money( (float) $c['minIncl'], $lang ),
								'date'   => $c['expires'] ? Text::date( gmdate( 'Y-m-d H:i:s', (int) $c['expires'] ), $lang ) : '—',
							)
						)
					) . '</li>';
				}
			}
			echo '</ul>';
		}

		// Buy more.
		if ( Products::packs_buyable() ) {
			echo '<h3>' . esc_html( Text::t( 'account.buy', $lang ) ) . '</h3><p>';
			foreach ( Config::packs( true ) as $p ) {
				$pid = Products::product_id( 'pack', $p['id'] );
				if ( $pid > 0 ) {
					$url = add_query_arg( 'add-to-cart', $pid, wc_get_cart_url() );
					echo '<a class="button" style="margin:0 .5em .5em 0" href="' . esc_url( $url ) . '">' . esc_html( Text::t( 'account.buy_btn', $lang, array( 'n' => $p['rounds'], 'price' => Text::money( $p['priceIncl'], $lang ) ) ) ) . '</a>';
				}
			}
			echo '</p>';
			if ( (float) Config::get( 'orderCredit.percent', 100 ) > 0 ) {
				echo '<p style="font-size:.9em">' . esc_html(
					Text::t(
						'account.pack_refund',
						$lang,
						array(
							'min'    => Text::money( (float) Config::get( 'orderCredit.minArticleIncl', 50 ), $lang ),
							'months' => (int) Config::get( 'orderCredit.validMonths', 12 ),
						)
					)
				) . '</p>';
			}
		}
		$studio = (string) apply_filters( 'bps_svc_studio_url', home_url( '/' ) );
		echo '<p><a class="button alt" href="' . esc_url( $studio ) . '">' . esc_html( Text::t( 'account.studio_cta', $lang ) ) . '</a></p>';

		// Services.
		echo '<h2>' . esc_html( Text::t( 'account.services', $lang ) ) . '</h2>';
		$services = Service_Status::for_customer( $uid, '', $lang );
		if ( ! $services ) {
			echo '<p>' . esc_html( Text::t( 'account.services_none', $lang ) ) . '</p>';
		} else {
			echo '<p>' . esc_html( Text::t( 'account.services_intro', $lang ) ) . '</p><table class="shop_table shop_table_responsive bps-account__services"><tbody>';
			foreach ( $services as $s ) {
				echo '<tr><td>' . esc_html( $s['service'] ) . '<br><small>' . esc_html( Text::t( 'account.order', $lang ) ) . ' ';
				echo '' !== $s['orderUrl'] ? '<a href="' . esc_url( $s['orderUrl'] ) . '">#' . esc_html( $s['order'] ) . '</a>' : '#' . esc_html( $s['order'] );
				echo '</small></td><td>' . esc_html( $s['label'] );
				if ( '' !== $s['readyBy'] ) {
					echo '<br><small>' . esc_html( Text::t( 'account.ready_by', $lang, array( 'date' => $s['readyBy'] ) ) ) . '</small>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}
