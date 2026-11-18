<?php
/**
 * Status of ordered services (D1–D7) per order item, the admin box to change it and the customer e-mails.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Statuses: waiting (no consent: starts after the 14-day withdrawal period) → queued → in_progress → proof_sent →
 * delivered; cancelled from anywhere. Stored as order item meta (HPOS-safe through the item API), plus one order meta
 * `_bps_svc_waiting_until` (earliest start of a waiting service) so the daily cron finds orders without scanning items.
 *
 * Customer e-mails go out on every status change that matters to them (queued/waiting at payment, in_progress,
 * proof_sent, delivered, cancelled) and only contain the order number, the service name and dates.
 */
final class Service_Status {

	const STATUS_META  = '_bps_svc_status';
	const READY_META   = '_bps_svc_ready';
	const NOTE_META    = '_bps_svc_note';
	const LOG_META     = '_bps_svc_log';
	const WAITING_META = '_bps_svc_waiting_until';
	const NONCE        = 'bps_svc_status';

	const STATUSES = array( 'waiting', 'queued', 'in_progress', 'proof_sent', 'delivered', 'cancelled' );

	/** Statuses that end the service. */
	const FINAL = array( 'delivered', 'cancelled' );

	/** @var callable|null Tests: receives (to, subject, body) instead of sending. */
	public static $mailer = null;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ), 30, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_box' ), 30, 1 );
		add_action( Module::CRON, array( __CLASS__, 'release_waiting' ) );
	}

	/* ---------------------------------------------------------------------
	 * Pure rules
	 * ------------------------------------------------------------------- */

	/**
	 * Known statuses from the config (falls back to the built-in list).
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		$list = (array) ( Config::services_config()['statuses'] ?? array() );
		$list = array_values( array_intersect( self::STATUSES, array_map( 'strval', $list ) ) );
		return $list ? $list : self::STATUSES;
	}

	/**
	 * May an item go from one status to another? Final statuses can only be left by reopening to queued.
	 *
	 * @param string $from Current ('' = none yet).
	 * @param string $to   Next.
	 */
	public static function allowed( string $from, string $to ): bool {
		if ( ! in_array( $to, self::STATUSES, true ) || $from === $to ) {
			return false;
		}
		if ( in_array( $from, self::FINAL, true ) ) {
			return 'queued' === $to; // Reopen (mistake, extra round).
		}
		if ( 'waiting' === $to ) {
			return '' === $from || 'queued' === $from;
		}
		return true;
	}

	/**
	 * First status after payment and the dates that go with it.
	 *
	 * @param bool   $consent     Customer ticked "start right away".
	 * @param bool   $needs       The service needs that consent at all (needsConsent).
	 * @param string $paid_utc    Payment time 'Y-m-d H:i:s' (UTC).
	 * @param int    $lead_days   Working days to deliver.
	 * @param int    $wait_days   Withdrawal period in days (14).
	 * @return array{status:string, startAfter:?string, readyBy:string}
	 */
	public static function initial( bool $consent, bool $needs, string $paid_utc, int $lead_days, int $wait_days = 14 ): array {
		if ( $needs && ! $consent && $wait_days > 0 ) {
			$start = ( new \DateTimeImmutable( $paid_utc, new \DateTimeZone( 'UTC' ) ) )->modify( '+' . $wait_days . ' days' )->format( 'Y-m-d' );
			return array(
				'status'     => 'waiting',
				'startAfter' => $start,
				'readyBy'    => Services::add_working_days( $start . ' 00:00:00', max( 0, $lead_days ) ),
			);
		}
		return array(
			'status'     => 'queued',
			'startAfter' => null,
			'readyBy'    => Services::add_working_days( $paid_utc, max( 0, $lead_days ) ),
		);
	}

	/**
	 * Customer-facing label for a status.
	 *
	 * @param string      $status     Status.
	 * @param string|null $start_date Start date (waiting).
	 * @param string|null $lang       Lang.
	 */
	public static function label( string $status, ?string $start_date = null, ?string $lang = null ): string {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return '';
		}
		return Text::t( 'svc.st.' . $status, $lang, array( 'date' => Text::date( $start_date ? $start_date . ' 00:00:00' : null, $lang ) ) );
	}

	/**
	 * Mail subject and body for a status (null = no mail for this status).
	 *
	 * @param string $status  Status.
	 * @param array  $vars    {service, nr, date, name, note}.
	 * @param string $lang    Lang.
	 * @return array{subject:string, body:string}|null
	 */
	public static function mail_text( string $status, array $vars, string $lang ): ?array {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return null;
		}
		$vars['status'] = self::label( $status, $vars['startAfter'] ?? null, $lang );
		$lines          = array();
		$lines[]        = '' !== (string) ( $vars['name'] ?? '' ) ? Text::t( 'svcmail.intro', $lang, $vars ) : rtrim( Text::t( 'svcmail.intro', $lang, array( 'name' => '' ) ), ' ,' ) . ',';
		$lines[]        = '';
		$lines[]        = Text::t( 'svcmail.body.' . $status, $lang, $vars );
		if ( '' !== trim( (string) ( $vars['note'] ?? '' ) ) ) {
			$lines[] = '';
			$lines[] = Text::t( 'svcmail.note', $lang );
			$lines[] = trim( (string) $vars['note'] );
		}
		$lines[] = '';
		$lines[] = Text::t( 'svcmail.account', $lang );
		return array(
			'subject' => Text::t( 'svcmail.subject', $lang, $vars ),
			'body'    => implode( "\n", $lines ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Reading / writing an order item
	 * ------------------------------------------------------------------- */

	/**
	 * Service data of an order item, or null when it is not a service line.
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 * @return array|null {id, svc, status, readyBy, startAfter, note}
	 */
	public static function of_item( $item ): ?array {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return null;
		}
		$data = $item->get_meta( Services::ITEM_META );
		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			return null;
		}
		$start = (string) $item->get_meta( Services::START_AFTER_META );
		return array(
			'id'         => (string) $data['id'],
			'svc'        => Config::service( (string) $data['id'] ),
			'designRef'  => (string) ( $data['designRef'] ?? '' ),
			'leadDays'   => (int) ( $data['leadDays'] ?? 0 ),
			'status'     => (string) $item->get_meta( self::STATUS_META ),
			'readyBy'    => (string) $item->get_meta( self::READY_META ),
			'startAfter' => '' !== $start ? $start : null,
			'note'       => (string) $item->get_meta( self::NOTE_META ),
		);
	}

	/**
	 * Language of an order (consent locale, then WPML/Polylang meta, then site).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function order_lang( $order ): string {
		foreach ( array( '_bps_svc_consent', Checkout::PACK_META ) as $k ) {
			$c = $order->get_meta( $k );
			if ( is_array( $c ) && ! empty( $c['locale'] ) ) {
				return Text::lang( (string) $c['locale'] );
			}
		}
		foreach ( array( 'wpml_language', '_bps_locale', 'pll_language' ) as $k ) {
			$v = (string) $order->get_meta( $k );
			if ( '' !== $v ) {
				return Text::lang( $v );
			}
		}
		return Text::lang( null );
	}

	/**
	 * Start a service line after payment (idempotent: does nothing when a status exists).
	 *
	 * @param \WC_Order              $order   Order.
	 * @param \WC_Order_Item_Product $item    Item.
	 * @param bool                   $notify  Send the customer e-mail.
	 */
	public static function start( $order, $item, bool $notify = true ): ?string {
		$info = self::of_item( $item );
		if ( ! $info || '' !== $info['status'] ) {
			return null;
		}
		$consent = $order->get_meta( '_bps_svc_consent' );
		$given   = is_array( $consent ) && ! empty( $consent['given'] );
		$needs   = $info['svc'] ? ! empty( $info['svc']['needsConsent'] ) : true;
		$paid    = $order->get_date_paid() ? gmdate( 'Y-m-d H:i:s', $order->get_date_paid()->getTimestamp() ) : gmdate( 'Y-m-d H:i:s' );
		$wait    = (int) ( Text::legal_config()['defaults']['withdrawalDays'] ?? 14 );
		$init    = self::initial( $given, $needs, $paid, $info['leadDays'], $wait );
		$item->update_meta_data( self::STATUS_META, $init['status'] );
		$item->update_meta_data( self::READY_META, $init['readyBy'] );
		if ( $init['startAfter'] ) {
			$item->update_meta_data( Services::START_AFTER_META, $init['startAfter'] );
			$current = (string) $order->get_meta( self::WAITING_META );
			if ( '' === $current || $init['startAfter'] < $current ) {
				$order->update_meta_data( self::WAITING_META, $init['startAfter'] );
				$order->save();
			}
		}
		self::log( $item, '', $init['status'], 0 );
		$item->save();
		if ( $notify ) {
			self::notify( $order, $item, $init['status'], '' );
		}
		return $init['status'];
	}

	/**
	 * Change the status of a service line (admin box, cron, fulfilment). Returns true when it changed.
	 *
	 * @param \WC_Order              $order  Order.
	 * @param \WC_Order_Item_Product $item   Item.
	 * @param string                 $status New status.
	 * @param string                 $note   Optional message to the customer.
	 * @param bool                   $notify Send the customer e-mail.
	 */
	public static function set( $order, $item, string $status, string $note = '', bool $notify = true ): bool {
		$info = self::of_item( $item );
		if ( ! $info || ! self::allowed( $info['status'], $status ) ) {
			return false;
		}
		$item->update_meta_data( self::STATUS_META, $status );
		if ( in_array( $status, array( 'queued', 'in_progress' ), true ) && ( '' === $info['readyBy'] || 'waiting' === $info['status'] || in_array( $info['status'], self::FINAL, true ) ) ) {
			$item->update_meta_data( self::READY_META, Services::add_working_days( gmdate( 'Y-m-d H:i:s' ), $info['leadDays'] ) );
		}
		if ( '' !== trim( $note ) ) {
			$item->update_meta_data( self::NOTE_META, sanitize_textarea_field( $note ) );
		}
		self::log( $item, $info['status'], $status, function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
		$item->save();

		$svc_name = $info['svc'] ? Text::label( $info['svc']['label'] ?? $info['id'], 'nl' ) : $info['id'];
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( sprintf( 'Dienst "%s": %s → %s', $svc_name, $info['status'] ? $info['status'] : '—', $status ) );
		}
		if ( 'waiting' !== $status ) {
			self::refresh_waiting( $order );
		}
		do_action( 'bps_svc_status_changed', $order, $item, $info['status'], $status );
		if ( $notify ) {
			self::notify( $order, $item, $status, $note );
		}
		return true;
	}

	/**
	 * Append to the item's small status log (max 20 entries; user id only, no names).
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 * @param string                 $from From.
	 * @param string                 $to   To.
	 * @param int                    $by   User id (0 = system).
	 */
	private static function log( $item, string $from, string $to, int $by ): void {
		$log   = $item->get_meta( self::LOG_META );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'at'   => gmdate( 'Y-m-d H:i:s' ),
			'from' => $from,
			'to'   => $to,
			'by'   => $by,
		);
		$item->update_meta_data( self::LOG_META, array_slice( $log, -20 ) );
	}

	/**
	 * Recompute the order-level "earliest waiting start" after a change.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function refresh_waiting( $order ): void {
		$min = '';
		foreach ( $order->get_items() as $it ) {
			$info = self::of_item( $it );
			if ( $info && 'waiting' === $info['status'] && $info['startAfter'] && ( '' === $min || $info['startAfter'] < $min ) ) {
				$min = $info['startAfter'];
			}
		}
		if ( '' === $min ) {
			$order->delete_meta_data( self::WAITING_META );
		} else {
			$order->update_meta_data( self::WAITING_META, $min );
		}
		$order->save();
	}

	/**
	 * Send the status e-mail to the customer (billing e-mail of the order).
	 *
	 * @param \WC_Order              $order  Order.
	 * @param \WC_Order_Item_Product $item   Item.
	 * @param string                 $status Status.
	 * @param string                 $note   Note.
	 */
	public static function notify( $order, $item, string $status, string $note ): bool {
		$to = (string) $order->get_billing_email();
		if ( ! is_email( $to ) ) {
			return false;
		}
		$info = self::of_item( $item );
		$lang = self::order_lang( $order );
		$name = $info && $info['svc'] ? Text::label( $info['svc']['label'] ?? $info['id'], $lang ) : (string) $item->get_name();
		$date = 'waiting' === $status && $info && $info['startAfter'] ? $info['startAfter'] . ' 00:00:00' : ( $info ? $info['readyBy'] . ' 00:00:00' : null );
		$mail = self::mail_text(
			$status,
			array(
				'service'    => $name,
				'nr'         => (string) $order->get_order_number(),
				'date'       => Text::date( $date, $lang ),
				'name'       => (string) $order->get_billing_first_name(),
				'note'       => $note,
				'startAfter' => $info['startAfter'] ?? null,
			),
			$lang
		);
		if ( ! $mail ) {
			return false;
		}
		if ( self::$mailer ) {
			return (bool) call_user_func( self::$mailer, $to, $mail['subject'], $mail['body'] );
		}
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$mailer  = WC()->mailer();
			$html    = wpautop( esc_html( $mail['body'] ) );
			$message = $mailer->wrap_message( Text::t( 'svcmail.heading', $lang ), $html );
			return (bool) $mailer->send( $to, $mail['subject'], $message );
		}
		return (bool) wp_mail( $to, $mail['subject'], $mail['body'] );
	}

	/* ---------------------------------------------------------------------
	 * Cron
	 * ------------------------------------------------------------------- */

	/**
	 * Daily: waiting services whose withdrawal period is over become queued (with e-mail).
	 *
	 * @return int Items released.
	 */
	public static function release_waiting(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$today  = gmdate( 'Y-m-d' );
		$orders = wc_get_orders(
			array(
				'limit'      => 100,
				'status'     => array( 'wc-processing', 'wc-on-hold', 'wc-completed' ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => self::WAITING_META,
						'value'   => $today,
						'compare' => '<=',
						'type'    => 'DATE',
					),
				),
			)
		);
		$n = 0;
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$info = self::of_item( $item );
				if ( $info && 'waiting' === $info['status'] && $info['startAfter'] && $info['startAfter'] <= $today && self::set( $order, $item, 'queued' ) ) {
					++$n;
				}
			}
			self::refresh_waiting( $order );
		}
		return $n;
	}

	/* ---------------------------------------------------------------------
	 * Customer view
	 * ------------------------------------------------------------------- */

	/**
	 * Service lines of a customer for "Mijn account" (newest first, max 30).
	 *
	 * @param int    $user_id User id (0 = none).
	 * @param string $email   Billing e-mail for guest-bound orders ('' = none).
	 * @param string $lang    Lang.
	 * @return array<int, array{order:string, orderUrl:string, service:string, status:string, label:string, readyBy:string}>
	 */
	public static function for_customer( int $user_id, string $email, string $lang ): array {
		if ( ! function_exists( 'wc_get_orders' ) || ( $user_id <= 0 && ! is_email( $email ) ) ) {
			return array();
		}
		$args = array(
			'limit'   => 30,
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => array( 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled', 'wc-refunded' ),
		);
		if ( $user_id > 0 ) {
			$args['customer_id'] = $user_id;
		} else {
			$args['billing_email'] = $email;
		}
		$out = array();
		foreach ( wc_get_orders( $args ) as $order ) {
			foreach ( $order->get_items() as $item ) {
				$info = self::of_item( $item );
				if ( ! $info ) {
					continue;
				}
				$status = '' !== $info['status'] ? $info['status'] : 'queued';
				$out[]  = array(
					'order'    => (string) $order->get_order_number(),
					'orderUrl' => $user_id > 0 ? (string) $order->get_view_order_url() : '',
					'service'  => $info['svc'] ? Text::label( $info['svc']['label'] ?? $info['id'], $lang ) : (string) $item->get_name(),
					'status'   => $status,
					'label'    => self::label( $status, $info['startAfter'], $lang ),
					'readyBy'  => in_array( $status, array( 'queued', 'in_progress', 'waiting' ), true ) && $info['readyBy'] ? Text::date( $info['readyBy'] . ' 00:00:00', $lang ) : '',
				);
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Admin box (order edit screen, classic and HPOS)
	 * ------------------------------------------------------------------- */

	/**
	 * Add the box when the order has service lines.
	 *
	 * @param string $post_type Screen/post type.
	 * @param mixed  $post      Post or order.
	 */
	public static function add_box( $post_type, $post = null ): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		if ( ! in_array( $post_type, array( 'shop_order', $screen ), true ) ) {
			return;
		}
		$order = $post instanceof \WC_Order ? $post : ( is_object( $post ) && isset( $post->ID ) ? wc_get_order( $post->ID ) : null );
		if ( ! $order || ! self::order_has_services( $order ) ) {
			return;
		}
		add_meta_box( 'bps-svc-status', __( 'Diensten (Patch Studio)', 'borduur-patch-studio' ), array( __CLASS__, 'render_box' ), $screen, 'normal', 'high' );
	}

	/**
	 * Does the order contain service lines?
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function order_has_services( $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( self::of_item( $item ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render the box.
	 *
	 * @param mixed $post_or_order Post or order.
	 */
	public static function render_box( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$labels = array(
			'waiting'     => __( 'Wacht op herroepingstermijn', 'borduur-patch-studio' ),
			'queued'      => __( 'Ingepland', 'borduur-patch-studio' ),
			'in_progress' => __( 'Bezig', 'borduur-patch-studio' ),
			'proof_sent'  => __( 'Proef verstuurd', 'borduur-patch-studio' ),
			'delivered'   => __( 'Geleverd', 'borduur-patch-studio' ),
			'cancelled'   => __( 'Geannuleerd', 'borduur-patch-studio' ),
		);
		wp_nonce_field( self::NONCE, 'bps_svc_nonce' );
		echo '<p class="description">' . esc_html__( 'Wijzig je de status, dan krijgt de klant een korte mail (enkel bestelnummer, dienst en datum). Je bericht wordt mee verstuurd.', 'borduur-patch-studio' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Dienst', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Status', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Klaar rond', 'borduur-patch-studio' ) . '</th><th>' . esc_html__( 'Bericht aan de klant (optioneel)', 'borduur-patch-studio' ) . '</th></tr></thead><tbody>';
		foreach ( $order->get_items() as $item_id => $item ) {
			$info = self::of_item( $item );
			if ( ! $info ) {
				continue;
			}
			$name = $info['svc'] ? Text::label( $info['svc']['label'] ?? $info['id'], 'nl' ) : $item->get_name();
			echo '<tr><td><strong>' . esc_html( $name ) . '</strong>';
			if ( '' !== $info['designRef'] ) {
				echo '<br><small>' . esc_html__( 'Ontwerp', 'borduur-patch-studio' ) . ': ' . esc_html( $info['designRef'] ) . '</small>';
			}
			if ( 'waiting' === $info['status'] && $info['startAfter'] ) {
				/* translators: %s: date */
				echo '<br><small>' . esc_html( sprintf( __( 'Geen toestemming om meteen te starten: start op %s.', 'borduur-patch-studio' ), Text::date( $info['startAfter'] . ' 00:00:00', 'nl' ) ) ) . '</small>';
			}
			echo '</td><td><select name="bps_svc[' . (int) $item_id . '][status]">';
			foreach ( self::statuses() as $st ) {
				$ok = $st === $info['status'] || self::allowed( $info['status'], $st );
				echo '<option value="' . esc_attr( $st ) . '"' . selected( $st, $info['status'], false ) . disabled( ! $ok, true, false ) . '>' . esc_html( $labels[ $st ] ?? $st ) . '</option>';
			}
			echo '</select></td><td>' . esc_html( $info['readyBy'] ? Text::date( $info['readyBy'] . ' 00:00:00', 'nl' ) : '—' ) . '</td>';
			echo '<td><textarea rows="2" style="width:100%" maxlength="1000" name="bps_svc[' . (int) $item_id . '][note]"></textarea>';
			echo '<label><input type="checkbox" name="bps_svc[' . (int) $item_id . '][mail]" value="1" checked> ' . esc_html__( 'Klant mailen', 'borduur-patch-studio' ) . '</label></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Save the box.
	 *
	 * @param int $order_id Order id.
	 */
	public static function save_box( $order_id ): void {
		if ( ! isset( $_POST['bps_svc_nonce'], $_POST['bps_svc'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bps_svc_nonce'] ) ), self::NONCE ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$rows = (array) wp_unslash( $_POST['bps_svc'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field below.
		foreach ( $rows as $item_id => $row ) {
			$item = $order->get_item( (int) $item_id );
			if ( ! $item || ! is_array( $row ) ) {
				continue;
			}
			$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
			$note   = sanitize_textarea_field( (string) ( $row['note'] ?? '' ) );
			$info   = self::of_item( $item );
			if ( ! $info ) {
				continue;
			}
			if ( $status !== $info['status'] ) {
				self::set( $order, $item, $status, $note, ! empty( $row['mail'] ) );
			} elseif ( '' !== $note ) {
				// Only a message: store and send it with the current status.
				$item->update_meta_data( self::NOTE_META, $note );
				$item->save();
				if ( ! empty( $row['mail'] ) ) {
					self::notify( $order, $item, $info['status'], $note );
				}
			}
		}
	}
}
