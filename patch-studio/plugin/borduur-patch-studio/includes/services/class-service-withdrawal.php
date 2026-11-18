<?php
/**
 * Withdrawal function for services D1/D2/D3/D5/D7 (04-juridisch §4.4 and §5; art. 11bis Directive 2011/83 as
 * amended by Directive 2023/2673: a clearly visible "withdraw from contract here" function with a confirmation step).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Rules (pure, see eligibility()):
 * - only services with a withdrawal right (`needsConsent`; rush and physical sample are part of the made-to-measure
 *   good and have none), within withdrawalDays (14) after the order, and not yet delivered/cancelled;
 * - not started yet (waiting/queued): full refund of the line (incl. VAT), automatically through Woo;
 * - already started (in_progress/proof_sent): the customer pays the work already done (art. VI.51 WER); the shop
 *   refunds the rest by hand, the order gets a task note and the customer an honest e-mail.
 * The button sits in "Mijn account → Ontwerprondes & diensten" and on the signed guest page of Withdrawal; the customer
 * e-mail of an order with such a service contains the link.
 */
final class Service_Withdrawal {

	const ITEM_PARAM = 'bps_swd';
	const NONCE      = 'bps_swd_';
	const LOG_META   = '_bps_svc_withdrawals';

	/** Statuses where nothing was done yet (full refund). */
	const NOT_STARTED = array( 'waiting', 'queued', '' );

	/** Statuses where work has started (proportional). */
	const STARTED = array( 'in_progress', 'proof_sent' );

	/** @var array|null Result of a POST in this request. */
	private static $flash = null;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ), 6 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_block' ), 26, 4 );
	}

	/* ---------------------------------------------------------------------
	 * Pure rules
	 * ------------------------------------------------------------------- */

	/**
	 * Can this service line still be withdrawn, and how?
	 *
	 * @param array|null $info        Service_Status::of_item().
	 * @param string     $ordered_utc Order date 'Y-m-d H:i:s' (UTC).
	 * @param string     $now_utc     Now.
	 * @param int        $days        Withdrawal period in days.
	 * @return string|null 'full' | 'partial' | null
	 */
	public static function eligibility( ?array $info, string $ordered_utc, string $now_utc, int $days = 14 ): ?string {
		if ( ! $info || empty( $info['svc'] ) || empty( $info['svc']['needsConsent'] ) ) {
			return null;
		}
		$end = gmdate( 'Y-m-d H:i:s', strtotime( $ordered_utc . ' UTC' ) + max( 0, $days ) * DAY_IN_SECONDS );
		if ( $now_utc > $end ) {
			return null;
		}
		$status = (string) $info['status'];
		if ( in_array( $status, self::NOT_STARTED, true ) ) {
			return 'full';
		}
		if ( in_array( $status, self::STARTED, true ) ) {
			return 'partial';
		}
		return null; // delivered / cancelled.
	}

	/**
	 * Refund for a not-started line: line total incl. VAT, half-up on the cent.
	 *
	 * @param object $item Order item.
	 */
	public static function full_amount( $item ): float {
		return Config::round_half_up( (float) $item->get_total() + (float) $item->get_total_tax(), 2 );
	}

	/**
	 * Withdrawable service lines of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @param int|null  $now   Timestamp (tests).
	 * @return array<int, array{item:object, info:array, mode:string, amount:float}>
	 */
	public static function items( $order, ?int $now = null ): array {
		$out     = array();
		$created = $order->get_date_created() ? gmdate( 'Y-m-d H:i:s', $order->get_date_created()->getTimestamp() ) : gmdate( 'Y-m-d H:i:s' );
		$days    = (int) ( Text::legal_config()['defaults']['withdrawalDays'] ?? 14 );
		$now_utc = gmdate( 'Y-m-d H:i:s', $now ?? time() );
		foreach ( $order->get_items() as $item ) {
			$info = Service_Status::of_item( $item );
			$mode = self::eligibility( $info, $created, $now_utc, $days );
			if ( null === $mode ) {
				continue;
			}
			$out[ (int) $item->get_id() ] = array(
				'item'   => $item,
				'info'   => $info,
				'mode'   => $mode,
				'amount' => 'full' === $mode ? self::full_amount( $item ) : 0.0,
			);
		}
		return $out;
	}

	/**
	 * Customer-facing service name.
	 *
	 * @param array  $info Service info.
	 * @param object $item Item.
	 * @param string $lang Lang.
	 */
	private static function name( array $info, $item, string $lang ): string {
		return $info['svc'] ? Text::label( $info['svc']['label'] ?? $info['id'], $lang ) : (string) $item->get_name();
	}

	/**
	 * Summary shown before the confirm button.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $row   Row of items().
	 * @param string    $lang  Lang.
	 */
	public static function summary( $order, array $row, string $lang ): string {
		$vars = array(
			'dienst' => self::name( $row['info'], $row['item'], $lang ),
			'nr'     => (string) $order->get_order_number(),
			'bedrag' => number_format( $row['amount'], 2, ',', '.' ),
		);
		return Text::legal( 'full' === $row['mode'] ? 'legal.svc.withdraw_summary' : 'legal.svc.withdraw_summary_started', $lang, $vars );
	}

	/* ---------------------------------------------------------------------
	 * Domain
	 * ------------------------------------------------------------------- */

	/**
	 * Withdraw one service line: status cancelled, refund (full) or task note (started), log, e-mails.
	 *
	 * @param \WC_Order $order   Order.
	 * @param int       $item_id Item id.
	 * @param string    $lang    Lang.
	 * @param int|null  $now     Timestamp (tests).
	 * @return array|\WP_Error {mode, amount, refunded}
	 */
	public static function perform( $order, int $item_id, string $lang, ?int $now = null ) {
		$rows = self::items( $order, $now );
		if ( ! isset( $rows[ $item_id ] ) ) {
			return new \WP_Error( 'bps_swd_none', Text::t( 'account.withdraw_none', $lang ) );
		}
		$row  = $rows[ $item_id ];
		$item = $row['item'];
		$name = self::name( $row['info'], $item, 'nl' );
		if ( ! Service_Status::set( $order, $item, 'cancelled', '', false ) ) {
			return new \WP_Error( 'bps_swd_state', Text::t( 'account.withdraw_none', $lang ) );
		}
		$refunded = false;
		if ( 'full' === $row['mode'] && $row['amount'] > 0 ) {
			$reason   = 'Herroeping dienst "' . $name . '"';
			$r        = Withdrawal::$refunder ? call_user_func( Withdrawal::$refunder, $order, $row['amount'], $reason ) : Withdrawal::create_refund( $order, $row['amount'], $reason );
			$refunded = ! is_wp_error( $r );
		}
		$at  = gmdate( 'Y-m-d H:i:s', $now ?? time() );
		$log = $order->get_meta( self::LOG_META );
		$log = is_array( $log ) ? $log : array();
		$log[] = array(
			'at'       => $at,
			'item'     => $item_id,
			'service'  => (string) $row['info']['id'],
			'mode'     => $row['mode'],
			'amount'   => $row['amount'],
			'refunded' => $refunded,
		);
		$order->update_meta_data( self::LOG_META, $log );
		if ( 'full' === $row['mode'] ) {
			$order->add_order_note( 'Herroeping dienst "' . $name . '": ' . number_format( $row['amount'], 2, ',', '.' ) . ' EUR' . ( $refunded ? ' terugbetaald.' : ' — automatisch terugbetalen lukte niet, betaal manueel terug binnen 14 dagen.' ) );
		} else {
			$order->add_order_note( 'Herroeping dienst "' . $name . '" tijdens de uitvoering: werk stoppen, evenredig deel van het gedane werk aanrekenen en de rest manueel terugbetalen binnen 14 dagen (art. VI.51 WER).' );
		}
		$order->save();

		$ts   = strtotime( $at . ' UTC' );
		$vars = array(
			'nr'     => (string) $order->get_order_number(),
			'dienst' => self::name( $row['info'], $item, $lang ),
			'datum'  => Text::date( $at, $lang ),
			'uur'    => function_exists( 'wp_date' ) ? wp_date( 'H:i', $ts ) : gmdate( 'H:i', $ts ),
			'bedrag' => number_format( $row['amount'], 2, ',', '.' ),
		);
		$key  = 'full' === $row['mode'] ? 'legal.svc.withdraw_email' : 'legal.svc.withdraw_email_started';
		Withdrawal::mail( (string) $order->get_billing_email(), Text::t( 'svc_withdraw.subject', $lang, $vars ), Text::legal( $key, $lang, $vars ) );
		if ( 'partial' === $row['mode'] || ! $refunded ) {
			$admin = function_exists( 'get_option' ) ? (string) get_option( 'admin_email' ) : '';
			$task  = 'partial' === $row['mode']
				? 'De klant herroept de dienst "' . $name . '" tijdens de uitvoering. Stop het werk, reken enkel het al gedane deel aan en betaal de rest binnen 14 dagen terug.'
				: 'De klant herroept de dienst "' . $name . '". Automatisch terugbetalen lukte niet: betaal ' . number_format( $row['amount'], 2, ',', '.' ) . ' EUR manueel terug binnen 14 dagen.';
			Withdrawal::mail( $admin, 'Herroeping dienst — bestelling ' . $order->get_order_number(), $task );
		}
		do_action( 'bps_svc_withdrawn', $order, $item, $row['mode'], $row['amount'] );
		return array(
			'mode'     => $row['mode'],
			'amount'   => $row['amount'],
			'refunded' => $refunded,
		);
	}

	/* ---------------------------------------------------------------------
	 * Requests and rendering
	 * ------------------------------------------------------------------- */

	/**
	 * Order the visitor may act on: own account order or a valid guest token (same rules as Withdrawal).
	 *
	 * @param int $order_id Order id.
	 * @return \WC_Order|null
	 */
	private static function order_for_request( int $order_id ) {
		$order = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return null;
		}
		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $uid > 0 && (int) $order->get_customer_id() === $uid ) {
			return $order;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- the token is itself a signature; the POST nonce was checked.
		$token = isset( $_REQUEST[ Withdrawal::GUEST_PARAM ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ Withdrawal::GUEST_PARAM ] ) ) : '';
		return '' !== $token && Withdrawal::verify( $token ) === (int) $order->get_id() ? $order : null;
	}

	/**
	 * Confirm (POST from the account page or the guest page).
	 */
	public static function handle_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['bps_swd_confirm'] ) || empty( $_POST[ self::ITEM_PARAM ] ) || empty( $_POST['bps_swd_order'] ) ) {
			return;
		}
		$item_id  = absint( wp_unslash( $_POST[ self::ITEM_PARAM ] ) );
		$order_id = absint( wp_unslash( $_POST['bps_swd_order'] ) );
		$nonce    = isset( $_POST['_bps_swd_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_bps_swd_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE . $order_id . '_' . $item_id ) ) {
			return;
		}
		$lang  = Text::lang( null );
		$order = self::order_for_request( $order_id );
		$res   = $order ? self::perform( $order, $item_id, $lang ) : new \WP_Error( 'bps_swd_none', Text::t( 'account.withdraw_none', $lang ) );
		if ( is_wp_error( $res ) ) {
			self::$flash = array( 'ok' => false, 'msg' => $res->get_error_message() );
			return;
		}
		$key         = 'partial' === $res['mode'] ? 'svc_withdraw.done_started' : ( $res['refunded'] ? 'svc_withdraw.done' : 'account.withdraw_error' );
		self::$flash = array( 'ok' => true, 'msg' => Text::t( $key, $lang, array( 'amount' => Text::money( (float) $res['amount'], $lang ) ) ) );
	}

	/**
	 * Withdrawable services of one order: button → summary → confirm (two steps, like the pack withdrawal).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $lang  Lang.
	 * @param string    $token Guest token ('' for the account page).
	 */
	public static function render_for_order( $order, string $lang, string $token = '' ): void {
		if ( self::$flash ) {
			$cls = self::$flash['ok'] ? 'woocommerce-message' : 'woocommerce-error';
			echo '<div class="' . esc_attr( $cls ) . '" role="status">' . esc_html( self::$flash['msg'] ) . '</div>';
			self::$flash = null;
		}
		$rows = self::items( $order );
		if ( ! $rows ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- display only.
		$asked = isset( $_GET[ self::ITEM_PARAM ] ) ? absint( wp_unslash( $_GET[ self::ITEM_PARAM ] ) ) : 0;
		foreach ( $rows as $item_id => $row ) {
			echo '<div class="bps-wd__svc" style="margin:1em 0;padding:1em;border:1px solid #ddd;border-radius:8px">';
			echo '<p>' . esc_html( self::name( $row['info'], $row['item'], $lang ) ) . ' · ' . esc_html( Text::t( 'account.order', $lang ) ) . ' ' . esc_html( (string) $order->get_order_number() ) . '</p>';
			if ( $asked === (int) $item_id ) {
				echo '<p>' . esc_html( self::summary( $order, $row, $lang ) ) . '</p><form method="post">';
				wp_nonce_field( self::NONCE . (int) $order->get_id() . '_' . (int) $item_id, '_bps_swd_nonce' );
				echo '<input type="hidden" name="' . esc_attr( self::ITEM_PARAM ) . '" value="' . (int) $item_id . '">';
				echo '<input type="hidden" name="bps_swd_order" value="' . (int) $order->get_id() . '">';
				if ( '' !== $token ) {
					echo '<input type="hidden" name="' . esc_attr( Withdrawal::GUEST_PARAM ) . '" value="' . esc_attr( $token ) . '">';
				}
				echo '<button type="submit" class="button" name="bps_swd_confirm" value="1">' . esc_html( Text::legal( 'legal.ai.withdraw_confirm', $lang ) ) . '</button> ';
				echo '<a href="' . esc_url( remove_query_arg( self::ITEM_PARAM ) ) . '">' . esc_html( Text::t( 'account.cancel', $lang ) ) . '</a></form>';
			} else {
				echo '<a class="button" href="' . esc_url( add_query_arg( self::ITEM_PARAM, (int) $item_id ) ) . '">' . esc_html( Text::legal( 'legal.ai.withdraw_button', $lang ) ) . '</a>';
			}
			echo '</div>';
		}
	}

	/**
	 * Account page: withdrawable services of the customer's recent orders.
	 *
	 * @param int    $user_id User id.
	 * @param string $lang    Lang.
	 */
	public static function render_account( int $user_id, string $lang ): void {
		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$days   = (int) ( Text::legal_config()['defaults']['withdrawalDays'] ?? 14 );
		$orders = wc_get_orders(
			array(
				'customer_id'  => $user_id,
				'limit'        => 20,
				'date_created' => '>' . ( time() - ( $days + 1 ) * DAY_IN_SECONDS ),
			)
		);
		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			self::render_for_order( $order, $lang );
		}
	}

	/**
	 * Customer e-mail: link to the withdrawal function when the order has a withdrawable service.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Admin copy.
	 * @param bool      $plain_text    Plain text.
	 * @param mixed     $email         WC_Email.
	 */
	public static function email_block( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		unset( $email );
		if ( $sent_to_admin || ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return;
		}
		$text = self::email_text( $order );
		if ( '' === $text ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html( $text ) . "\n";
			return;
		}
		echo '<p class="bps-svc-withdraw" style="margin:16px 0">' . esc_html( $text ) . '</p>';
	}

	/**
	 * E-mail line ('' without withdrawable services).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function email_text( $order ): string {
		if ( ! self::items( $order ) ) {
			return '';
		}
		$lang = Service_Status::order_lang( $order );
		return Text::t( 'svc_withdraw.email_line', $lang ) . ' ' . Text::legal( 'legal.ai.withdraw_button', $lang ) . ': ' . Withdrawal::link_for_order( $order );
	}
}
