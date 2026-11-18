<?php
/**
 * Digital proof per order line (atelier-flow-1): storage, tokens, sending, answers, cancellation and the
 * approval record (atelier-flow-3). Security-sensitive: everything a customer link can change goes through here.
 *
 * Storage: order item meta `_bps_proof` on the WC_Order_Item_Product (CRUD, HPOS-safe). Mirrors:
 * item meta `_bps_proof_status` (orders list) and order meta `_bps_proof_open` ('1' while a line waits for the
 * customer; used by the reminders and the "my account" list).
 *
 * Contract C6: get( $item ), url( $order, $item ), export_for_order( $order ); actions
 * `bps_proof_sent( $order_id, $item_id, $round )` and `bps_proof_response( $order_id, $item_id, $answer, $round )`.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Proof;

use BorduurPatchStudio\Admin\Order_Production;
use BorduurPatchStudio\Woo\Designs;

defined( 'ABSPATH' ) || exit;

/**
 * Proof service (static; hooks registered by Order_Production::register()).
 */
final class Proof_Service {

	/** Item meta with the proof record. */
	const META = '_bps_proof';

	/** Item meta mirroring the status (orders list without unserializing the record). */
	const STATUS_META = '_bps_proof_status';

	/** Order meta: '1' while at least one line waits for the customer. */
	const OPEN_META = '_bps_proof_open';

	/** Proof statuses. */
	const STATUSES = array( 'none', 'sent', 'approved', 'changes_requested', 'cancelled' );

	/** Order statuses in which no proof can be sent or answered (woo-compat-4). */
	const BLOCKED_ORDER_STATUSES = array( 'cancelled', 'refunded', 'failed', 'trash' );

	/** Site option with the HMAC key for IP hashes in the approval record (never the IP itself). */
	const IP_KEY_OPTION = 'bps_proof_ip_key';

	/** Option set once the design-level proofs were copied to the order lines. */
	const MIGRATED_OPTION = 'bps_proof_migrated';

	/** Max length of free text. */
	const MAX_TEXT = 2000;

	/** Max stored user agent length. */
	const MAX_UA = 200;

	/**
	 * Hooks (called from Order_Production::register()).
	 */
	public static function register(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'email_classes' ), 20 );
		add_action( 'template_redirect', array( Response_Page::class, 'handle' ), 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_order_stopped' ), 10, 2 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_order_stopped' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_order_refunded' ), 10, 2 );
		add_action( 'bps_upgraded', array( __CLASS__, 'migrate' ), 10, 0 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		Reminders::register();
		Account::register();
		Order_Proof::register();
	}

	/**
	 * Data the plugin owns (contract C2; read by Plugin::owned_data() through Order_Production::owned_data()).
	 */
	public static function owned_data(): array {
		return array(
			'options'    => array( self::IP_KEY_OPTION, self::MIGRATED_OPTION ),
			'cron_hooks' => array( Reminders::HOOK ),
			'as_groups'  => array( Reminders::GROUP ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Reading and writing
	 * ------------------------------------------------------------------- */

	/**
	 * Normalized proof record of an order line.
	 *
	 * @param \WC_Order_Item_Product $item Order line.
	 */
	public static function get( $item ): array {
		$raw = array();
		if ( $item instanceof \WC_Order_Item_Product ) {
			$raw = $item->get_meta( self::META );
			$raw = is_array( $raw ) ? $raw : self::legacy_record( $item );
		}
		return self::normalize( $raw );
	}

	/**
	 * Normalize a raw record.
	 *
	 * @param array $p Raw record.
	 */
	public static function normalize( array $p ): array {
		$status = (string) ( $p['status'] ?? 'none' );
		return array(
			'status'           => in_array( $status, self::STATUSES, true ) ? $status : 'none',
			'round'            => max( 0, (int) ( $p['round'] ?? 0 ) ),
			'sent_at'          => (string) ( $p['sent_at'] ?? '' ),
			'responded_at'     => (string) ( $p['responded_at'] ?? '' ),
			'note'             => (string) ( $p['note'] ?? '' ),
			'message'          => (string) ( $p['message'] ?? '' ),
			'image_url'        => (string) ( $p['image_url'] ?? '' ),
			'attachment_id'    => (int) ( $p['attachment_id'] ?? 0 ),
			'recipient'        => (string) ( $p['recipient'] ?? '' ),
			'design_id'        => (int) ( $p['design_id'] ?? 0 ),
			'order_id'         => (int) ( $p['order_id'] ?? 0 ),
			'item_id'          => (int) ( $p['item_id'] ?? 0 ),
			'legacy_max_round' => (int) ( $p['legacy_max_round'] ?? 0 ),
			'before_cancel'    => (string) ( $p['before_cancel'] ?? '' ),
			'cancel_requested' => (string) ( $p['cancel_requested'] ?? '' ),
			'reminders_sent'   => isset( $p['reminders_sent'] ) && is_array( $p['reminders_sent'] ) ? array_values( $p['reminders_sent'] ) : array(),
			'history'          => isset( $p['history'] ) && is_array( $p['history'] ) ? array_values( $p['history'] ) : array(),
		);
	}

	/**
	 * Proof stored on the design post before 1.x (read-only fallback until the migration ran). Only used when it
	 * belongs to exactly this order line, so a second order of the same design starts without a proof.
	 *
	 * @param \WC_Order_Item_Product $item Order line.
	 */
	private static function legacy_record( $item ): array {
		$design_id = (int) $item->get_meta( '_bps_design_id' );
		if ( $design_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}
		$old = get_post_meta( $design_id, '_bps_proof', true );
		if ( ! is_array( $old ) || 'none' === ( $old['status'] ?? 'none' ) && empty( $old['history'] ) ) {
			return array();
		}
		$order_id = (int) ( $old['order_id'] ?? 0 );
		$item_id  = (int) ( $old['item_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			$order_id = (int) get_post_meta( $design_id, '_bps_order_id', true );
		}
		if ( $order_id !== (int) $item->get_order_id() || ( $item_id > 0 && $item_id !== (int) $item->get_id() ) ) {
			return array();
		}
		$old['design_id']        = $design_id;
		$old['legacy_max_round'] = (int) ( $old['round'] ?? 0 );
		return $old;
	}

	/**
	 * Merge changes into the line's proof record and save (history_add appends one entry).
	 *
	 * @param \WC_Order_Item_Product $item    Order line.
	 * @param array                  $changes Fields.
	 * @return array New normalized record.
	 */
	public static function save( $item, array $changes ): array {
		$cur     = $item->get_meta( self::META );
		$cur     = is_array( $cur ) ? $cur : self::legacy_record( $item );
		$history = isset( $cur['history'] ) && is_array( $cur['history'] ) ? array_values( $cur['history'] ) : array();
		if ( isset( $changes['history_add'] ) && is_array( $changes['history_add'] ) ) {
			$history[] = $changes['history_add'];
		}
		unset( $changes['history_add'], $changes['history'] );
		$new              = array_merge( $cur, $changes );
		$new['history']   = $history;
		$new['order_id']  = (int) $item->get_order_id();
		$new['item_id']   = (int) $item->get_id();
		$new['design_id'] = (int) ( $new['design_id'] ?? 0 ) > 0 ? (int) $new['design_id'] : (int) $item->get_meta( '_bps_design_id' );
		$norm             = self::normalize( $new );
		$item->update_meta_data( self::META, $new );
		$item->update_meta_data( self::STATUS_META, $norm['status'] );
		$item->save();
		self::sync_order_flag( (int) $item->get_order_id() );
		return $norm;
	}

	/**
	 * Keep the order flag `_bps_proof_open` in line with the lines.
	 *
	 * @param int $order_id Order id.
	 */
	public static function sync_order_flag( int $order_id ): void {
		$order = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$open = false;
		foreach ( Order_Production::design_items( $order ) as $line ) {
			if ( 'sent' === (string) $line->get_meta( self::STATUS_META ) ) {
				$open = true;
				break;
			}
		}
		$want = $open ? '1' : '';
		if ( (string) $order->get_meta( self::OPEN_META ) !== $want ) {
			if ( $open ) {
				$order->update_meta_data( self::OPEN_META, '1' );
			} else {
				$order->delete_meta_data( self::OPEN_META );
			}
			$order->save();
		}
	}

	/* ---------------------------------------------------------------------
	 * Tokens and links
	 * ------------------------------------------------------------------- */

	/**
	 * Token: HMAC-SHA256 over "proof|{order}|{item}|{round}".
	 *
	 * @param int $order_id Order id.
	 * @param int $item_id  Item id.
	 * @param int $round    Round (1-based).
	 */
	public static function token( int $order_id, int $item_id, int $round ): string {
		return hash_hmac( 'sha256', 'proof|' . $order_id . '|' . $item_id . '|' . $round, wp_salt( 'auth' ) );
	}

	/**
	 * Token of links sent before proofs were stored per line: HMAC over "proof|{design}|{order}|{round}".
	 *
	 * @param int $design_id Design id.
	 * @param int $order_id  Order id.
	 * @param int $round     Round.
	 */
	public static function legacy_token( int $design_id, int $order_id, int $round ): string {
		return hash_hmac( 'sha256', 'proof|' . $design_id . '|' . $order_id . '|' . $round, wp_salt( 'auth' ) );
	}

	/**
	 * Customer link for the current (or a given) round.
	 *
	 * @param \WC_Order              $order  Order.
	 * @param \WC_Order_Item_Product $item   Order line.
	 * @param string                 $action approve|changes|cancel.
	 * @param int                    $round  Round (0 = current).
	 */
	public static function url( $order, $item, string $action = 'approve', int $round = 0 ): string {
		$round = $round > 0 ? $round : self::get( $item )['round'];
		return add_query_arg(
			array(
				'bps_proof' => (int) $item->get_meta( '_bps_design_id' ),
				'o'         => (int) $order->get_id(),
				'item'      => (int) $item->get_id(),
				't'         => self::token( (int) $order->get_id(), (int) $item->get_id(), max( 1, $round ) ),
				'a'         => in_array( $action, array( 'changes', 'cancel' ), true ) ? $action : 'approve',
			),
			home_url( '/' )
		);
	}

	/**
	 * Check a link token for a line: 'current', 'superseded' (an older round) or 'invalid'.
	 *
	 * @param \WC_Order              $order     Order.
	 * @param \WC_Order_Item_Product $item      Order line.
	 * @param string                 $token     Token from the link.
	 * @param bool                   $legacy    Link in the old format (design id instead of item id).
	 * @param int                    $design_id Design id from the link (legacy format).
	 */
	public static function check_token( $order, $item, string $token, bool $legacy = false, int $design_id = 0 ): string {
		$proof = self::get( $item );
		if ( $proof['round'] < 1 || 64 !== strlen( $token ) ) {
			return 'invalid';
		}
		$oid = (int) $order->get_id();
		$iid = (int) $item->get_id();
		for ( $r = $proof['round']; $r >= 1; $r-- ) {
			if ( $legacy ) {
				// Old links only exist for rounds sent before the per-line storage.
				$ok = $r <= $proof['legacy_max_round'] && $design_id > 0 && hash_equals( self::legacy_token( $design_id, $oid, $r ), $token );
			} else {
				$ok = hash_equals( self::token( $oid, $iid, $r ), $token );
			}
			if ( $ok ) {
				return $r === $proof['round'] ? 'current' : 'superseded';
			}
		}
		return 'invalid';
	}

	/* ---------------------------------------------------------------------
	 * Order status rules (woo-compat-4)
	 * ------------------------------------------------------------------- */

	/**
	 * The blocking order status ('' when proofs are allowed). Pending and on-hold stay allowed (pay after proof,
	 * bank transfer).
	 *
	 * @param mixed $order Order.
	 */
	public static function blocked_status( $order ): string {
		if ( ! $order instanceof \WC_Order ) {
			return 'trash';
		}
		$status = (string) $order->get_status();
		return in_array( $status, self::BLOCKED_ORDER_STATUSES, true ) ? $status : '';
	}

	/**
	 * Order cancelled or refunded: note + every patch line to 'cancelled'.
	 *
	 * @param int   $order_id Order id.
	 * @param mixed $order    Order.
	 */
	public static function on_order_stopped( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$done = self::cancel_lines( $order, Order_Production::design_items( $order ), 'order_' . $order->get_status() );
		if ( $done ) {
			$order->add_order_note( __( 'Proef/productie gestopt: de bestelling is geannuleerd of terugbetaald. Proeflinks werken niet meer.', 'borduur-patch-studio' ), 0, false );
		}
	}

	/**
	 * Order reopened after a cancellation/refund (e.g. by mistake): restore the previous proof status.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 * @param mixed  $order    Order.
	 */
	public static function on_status_changed( $order_id, $from, $to, $order = null ): void {
		if ( ! in_array( (string) $from, array( 'cancelled', 'refunded' ), true ) || in_array( (string) $to, self::BLOCKED_ORDER_STATUSES, true ) ) {
			return;
		}
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		foreach ( Order_Production::design_items( $order ) as $item ) {
			$p   = self::get( $item );
			$why = '';
			foreach ( array_reverse( $p['history'] ) as $h ) {
				if ( is_array( $h ) && 'cancelled' === ( $h['action'] ?? '' ) ) {
					$why = (string) ( $h['by'] ?? '' );
					break;
				}
			}
			// Lines stopped by a (partial) refund stay stopped; only an order-level stop is undone.
			if ( 'cancelled' === $p['status'] && '' !== $p['before_cancel'] && 0 === strpos( $why, 'order_' ) ) {
				self::save(
					$item,
					array(
						'status'        => $p['before_cancel'],
						'before_cancel' => '',
						'history_add'   => self::event( 'reopened', 'system', '', $p['round'] ),
					)
				);
			}
		}
	}

	/**
	 * Partial refund: a line refunded completely stops like a cancelled order.
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 */
	public static function on_order_refunded( $order_id, $refund_id = 0 ): void {
		unset( $refund_id );
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$lines = array();
		foreach ( Order_Production::design_items( $order ) as $item_id => $item ) {
			$qty = (int) $item->get_quantity();
			if ( $qty > 0 && abs( (int) $order->get_qty_refunded_for_item( $item_id ) ) >= $qty ) {
				$lines[ $item_id ] = $item;
			}
		}
		$done = self::cancel_lines( $order, $lines, 'refund' );
		foreach ( $done as $item ) {
			$order->add_order_note(
				/* translators: %s: design ref */
				sprintf( __( 'Proef/productie gestopt voor patch %s: deze regel is volledig terugbetaald.', 'borduur-patch-studio' ), (string) $item->get_meta( 'bps_ref' ) ),
				0,
				false
			);
		}
	}

	/**
	 * Set lines to 'cancelled' (idempotent). Returns the lines that changed.
	 *
	 * @param \WC_Order $order  Order.
	 * @param array     $lines  Items.
	 * @param string    $reason Reason stored in the history ("by").
	 * @return \WC_Order_Item_Product[]
	 */
	private static function cancel_lines( $order, array $lines, string $reason ): array {
		unset( $order );
		$done = array();
		foreach ( $lines as $item ) {
			$p = self::get( $item );
			if ( 'cancelled' === $p['status'] ) {
				continue;
			}
			self::save(
				$item,
				array(
					'status'        => 'cancelled',
					'before_cancel' => $p['status'],
					'history_add'   => self::event( 'cancelled', $reason, '', $p['round'] ),
				)
			);
			$done[] = $item;
		}
		return $done;
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------- */

	/**
	 * Common checks before a proof can be sent for a line. Returns the design record or a WP_Error.
	 *
	 * @param \WC_Order              $order Order.
	 * @param \WC_Order_Item_Product $item  Line.
	 * @return array|\WP_Error
	 */
	public static function can_send( $order, $item ) {
		if ( '' !== self::blocked_status( $order ) ) {
			return new \WP_Error( 'bps_order_stopped', __( 'Deze bestelling is geannuleerd, terugbetaald of mislukt. Je kunt geen proef meer versturen.', 'borduur-patch-studio' ) );
		}
		if ( 'cancelled' === self::get( $item )['status'] ) {
			return new \WP_Error( 'bps_line_stopped', __( 'Deze patch is gestopt (terugbetaald). Je kunt geen proef meer versturen.', 'borduur-patch-studio' ) );
		}
		$d = Order_Production::design_for_item( $item );
		if ( ! $d['exists'] ) {
			return new \WP_Error( 'bps_no_design', __( 'Het ontwerp van deze patch bestaat niet meer (opgeruimd). Stuur de proef manueel.', 'borduur-patch-studio' ) );
		}
		return $d['record'];
	}

	/**
	 * Send a proof for one order line.
	 *
	 * @param \WC_Order              $order     Order.
	 * @param \WC_Order_Item_Product $item      Line.
	 * @param string                 $message   Message for the customer.
	 * @param int                    $image_id  Attachment id of a custom proof image (0 = design preview).
	 * @param string                 $recipient E-mail (default billing e-mail).
	 * @return array{round:int, recipient:string}|\WP_Error
	 */
	public static function send( $order, $item, string $message = '', int $image_id = 0, string $recipient = '' ) {
		$rec = self::can_send( $order, $item );
		if ( is_wp_error( $rec ) ) {
			return $rec;
		}
		$recipient = '' !== $recipient ? $recipient : (string) $order->get_billing_email();
		if ( ! is_email( $recipient ) ) {
			return new \WP_Error( 'bps_no_email', __( 'Deze bestelling heeft geen geldig e-mailadres. Vul een ontvanger in.', 'borduur-patch-studio' ) );
		}
		$image = self::image( $rec, $image_id );
		if ( is_wp_error( $image ) ) {
			return $image;
		}
		$email = self::email( 'BPS_Email_Proof' );
		if ( ! $email ) {
			return new \WP_Error( 'bps_no_mailer', __( 'De e-mail "Patch Studio: digitale proef" is niet beschikbaar.', 'borduur-patch-studio' ) );
		}
		if ( ! $email->is_enabled() ) {
			return new \WP_Error( 'bps_mail_disabled', __( 'De e-mail "Patch Studio: digitale proef" staat uit onder WooCommerce › Instellingen › E-mails.', 'borduur-patch-studio' ) );
		}
		$round = self::get( $item )['round'] + 1;
		$sent  = $email->trigger( $rec, $order, $item, $round, $image['url'], $message, $recipient, $image['mail'] );
		if ( ! $sent ) {
			return new \WP_Error( 'bps_mail_failed', __( 'De e-mail kon niet verstuurd worden. Controleer de e-mailinstellingen van de website.', 'borduur-patch-studio' ) );
		}
		self::mark_sent( $order, $item, $rec, $round, $image, $message, $recipient );
		$order->add_order_note(
			/* translators: 1: round, 2: design ref, 3: e-mail */
			sprintf( __( 'Digitale proef (ronde %1$d) voor patch %2$s verstuurd naar %3$s.', 'borduur-patch-studio' ), $round, (string) $rec['ref'], $recipient ),
			0,
			true
		);
		do_action( 'bps_proof_sent', (int) $order->get_id(), (int) $item->get_id(), $round );
		return array(
			'round'     => $round,
			'recipient' => $recipient,
		);
	}

	/**
	 * Store a sent round with its approval-record data (atelier-flow-3): image, SHA-256 of the file on the server
	 * and a snapshot of the customer summary in the customer's language.
	 *
	 * @param \WC_Order              $order     Order.
	 * @param \WC_Order_Item_Product $item      Line.
	 * @param array                  $rec       DesignRecord.
	 * @param int                    $round     Round.
	 * @param array                  $image     self::image().
	 * @param string                 $message   Message.
	 * @param string                 $recipient Recipient.
	 * @param int                    $order_rev Revision of a whole-order proof (0 = single line).
	 */
	public static function mark_sent( $order, $item, array $rec, int $round, array $image, string $message, string $recipient, int $order_rev = 0 ): array {
		unset( $order );
		$now   = gmdate( 'c' );
		$lang  = Spec::lang_of( (string) ( $rec['payload']['locale'] ?? '' ) );
		$event = self::event( 'sent', self::actor(), $message, $round );
		$event = array_merge(
			$event,
			array(
				'image_url'     => $image['url'],
				'attachment_id' => (int) $image['id'],
				'image_sha256'  => self::image_sha256( (int) $image['id'], $image['url'] ),
				'spec_snapshot' => self::snapshot( $item, $lang ),
				'lang'          => $lang,
			)
		);
		if ( $order_rev > 0 ) {
			$event['order_proof'] = $order_rev;
		}
		return self::save(
			$item,
			array(
				'status'           => 'sent',
				'round'            => $round,
				'sent_at'          => $now,
				'responded_at'     => '',
				'note'             => '',
				'image_url'        => $image['url'],
				'attachment_id'    => (int) $image['id'],
				'message'          => $message,
				'recipient'        => $recipient,
				'cancel_requested' => '',
				'design_id'        => (int) $rec['id'],
				'history_add'      => $event,
			)
		);
	}

	/**
	 * Proof image: {id, url (full size, for the page/record), mail (small, for e-mail)} or WP_Error.
	 *
	 * @param array $rec      DesignRecord.
	 * @param int   $image_id Attachment id (0 = preview).
	 * @return array{id:int,url:string,mail:string}|\WP_Error
	 */
	public static function image( array $rec, int $image_id ) {
		if ( $image_id > 0 ) {
			if ( ! wp_attachment_is_image( $image_id ) ) {
				return new \WP_Error( 'bps_bad_image', __( 'Het gekozen bestand is geen afbeelding.', 'borduur-patch-studio' ) );
			}
			$url  = (string) wp_get_attachment_url( $image_id );
			$mail = function_exists( 'wp_get_attachment_image_src' ) ? wp_get_attachment_image_src( $image_id, 'medium_large' ) : false;
			return array(
				'id'   => $image_id,
				'url'  => $url,
				'mail' => is_array( $mail ) && ! empty( $mail[0] ) ? (string) $mail[0] : $url,
			);
		}
		$url = (string) ( $rec['preview_url'] ?? '' );
		if ( '' === $url ) {
			return new \WP_Error( 'bps_no_image', __( 'Er is geen proefafbeelding. Kies een afbeelding uit de mediabibliotheek.', 'borduur-patch-studio' ) );
		}
		$mail = $url;
		if ( class_exists( Designs::class ) && is_callable( array( Designs::class, 'preview_url' ) ) && (int) ( $rec['id'] ?? 0 ) > 0 ) {
			$mail = (string) Designs::preview_url( (int) $rec['id'], 'mail' );
			$mail = '' !== $mail ? $mail : $url;
		}
		return array(
			'id'   => 0,
			'url'  => $url,
			'mail' => $mail,
		);
	}

	/**
	 * SHA-256 of the proof image file on this server ('' when it cannot be found locally).
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $url           Image URL.
	 */
	public static function image_sha256( int $attachment_id, string $url ): string {
		$path = $attachment_id > 0 && function_exists( 'get_attached_file' ) ? (string) get_attached_file( $attachment_id ) : '';
		if ( ( '' === $path || ! is_readable( $path ) ) && '' !== $url && function_exists( 'wp_upload_dir' ) ) {
			$up   = wp_upload_dir( null, false );
			$base = (string) ( $up['baseurl'] ?? '' );
			$rel  = (string) wp_parse_url( $url, PHP_URL_PATH );
			$bp   = (string) wp_parse_url( $base, PHP_URL_PATH );
			if ( '' !== $bp && 0 === strpos( $rel, $bp . '/' ) ) {
				$candidate = (string) $up['basedir'] . substr( $rel, strlen( $bp ) );
				$real      = realpath( $candidate );
				$root      = realpath( (string) $up['basedir'] );
				if ( $real && $root && 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
					$path = $real;
				}
			}
		}
		return ( '' !== $path && is_readable( $path ) ) ? (string) hash_file( 'sha256', $path ) : '';
	}

	/**
	 * Snapshot of the customer summary (label/value pairs) in the customer's language.
	 *
	 * @param \WC_Order_Item_Product $item Line.
	 * @param string                 $lang Language.
	 */
	public static function snapshot( $item, string $lang ): array {
		Lang::switch_to( $lang );
		try {
			$rows = Spec::customer_rows( $item, $lang );
		} finally {
			Lang::restore();
		}
		return array_map(
			static function ( $r ) {
				return array(
					'label' => (string) ( $r['label'] ?? '' ),
					'value' => (string) ( $r['value'] ?? '' ),
				);
			},
			$rows
		);
	}

	/* ---------------------------------------------------------------------
	 * Answers
	 * ------------------------------------------------------------------- */

	/**
	 * Store a customer (or manual) answer for the current round.
	 *
	 * Context keys: by ('customer'|admin name), ip, ua, confirmed (bool), notify (shop mail, default true),
	 * order_note (default true).
	 *
	 * @param \WC_Order              $order  Order.
	 * @param \WC_Order_Item_Product $item   Line.
	 * @param string                 $action approve|changes.
	 * @param string                 $note   Note.
	 * @param array                  $ctx    Context.
	 * @return array New record.
	 */
	public static function record_response( $order, $item, string $action, string $note, array $ctx = array() ): array {
		$proof  = self::get( $item );
		$answer = 'changes' === $action ? 'changes' : 'approved';
		$status = 'changes' === $answer ? 'changes_requested' : 'approved';
		$by     = (string) ( $ctx['by'] ?? 'customer' );
		$event  = self::event( 'changes' === $answer ? 'changes' : 'approved', $by, $note, $proof['round'] );
		if ( 'customer' === $by ) {
			$ip                = (string) ( $ctx['ip'] ?? '' );
			$event['ip_hash']  = '' !== $ip ? self::ip_hash( $ip ) : '';
			$event['ua']       = self::cut( (string) ( $ctx['ua'] ?? '' ), self::MAX_UA );
			$event['confirmed'] = ! empty( $ctx['confirmed'] );
		}
		$new = self::save(
			$item,
			array(
				'status'       => $status,
				'responded_at' => $event['at'],
				'note'         => $note,
				'history_add'  => $event,
			)
		);
		$ref = (string) $item->get_meta( 'bps_ref' );
		if ( $ctx['order_note'] ?? true ) {
			$text = 'changes' === $answer
				/* translators: 1: design ref, 2: round */
				? sprintf( __( 'De klant vraagt een aanpassing aan de digitale proef van patch %1$s (ronde %2$d).', 'borduur-patch-studio' ), $ref, $proof['round'] )
				/* translators: 1: design ref, 2: round */
				: sprintf( __( 'De klant keurde de digitale proef van patch %1$s goed (ronde %2$d).', 'borduur-patch-studio' ), $ref, $proof['round'] );
			if ( '' !== $note ) {
				/* translators: %s: customer note */
				$text .= ' ' . sprintf( __( 'Opmerking: %s', 'borduur-patch-studio' ), $note );
			}
			$order->add_order_note( $text, 0, false );
		}
		if ( $ctx['notify'] ?? true ) {
			$mail = self::email( 'BPS_Email_Proof_Response' );
			if ( $mail ) {
				$mail->trigger( self::design_record( $item, $new ), $order, 'changes' === $answer ? 'changes' : 'approve', $note, $proof['round'] );
			}
		}
		/**
		 * A proof was answered (by the customer or set manually by the shop).
		 *
		 * @param int    $order_id Order id.
		 * @param int    $item_id  Item id.
		 * @param string $answer   'approved' | 'changes'.
		 * @param int    $round    Round.
		 */
		do_action( 'bps_proof_response', (int) $order->get_id(), (int) $item->get_id(), $answer, $proof['round'] );
		return $new;
	}

	/**
	 * Manual status from the order screen (e.g. approved by phone).
	 *
	 * @param \WC_Order              $order  Order.
	 * @param \WC_Order_Item_Product $item   Line.
	 * @param string                 $status approved|changes_requested|none.
	 * @param string                 $note   Note.
	 */
	public static function set_manual( $order, $item, string $status, string $note ): array {
		$proof = self::get( $item );
		$by    = self::actor();
		$new   = self::save(
			$item,
			array(
				'status'       => $status,
				'responded_at' => 'none' === $status ? '' : gmdate( 'c' ),
				'note'         => $note,
				'history_add'  => self::event( 'manual_' . $status, $by, $note, $proof['round'] ),
			)
		);
		$order->add_order_note(
			/* translators: 1: design ref, 2: status, 3: user, 4: note */
			trim( sprintf( __( 'Proefstatus van patch %1$s manueel gezet op "%2$s" door %3$s. %4$s', 'borduur-patch-studio' ), (string) $item->get_meta( 'bps_ref' ), self::status_label( $status ), $by, $note ) ),
			0,
			true
		);
		if ( in_array( $status, array( 'approved', 'changes_requested' ), true ) ) {
			/** This action is documented in record_response(). */
			do_action( 'bps_proof_response', (int) $order->get_id(), (int) $item->get_id(), 'approved' === $status ? 'approved' : 'changes', $proof['round'] );
		}
		return $new;
	}

	/**
	 * Customer cancels before approval (promises.cancelUntilProof, atelier-flow-11): unpaid ⇒ order cancelled;
	 * paid ⇒ a cancellation request (note + shop mail), never an automatic refund.
	 *
	 * @param \WC_Order              $order Order.
	 * @param \WC_Order_Item_Product $item  Line the link belongs to.
	 * @return string 'cancelled' | 'requested'
	 */
	public static function customer_cancel( $order, $item ): string {
		$proof = self::get( $item );
		if ( ! $order->is_paid() ) {
			$order->update_status( 'cancelled', __( 'De klant annuleerde de bestelling via de proefpagina (vóór goedkeuring van de proef).', 'borduur-patch-studio' ) );
			return 'cancelled';
		}
		self::save(
			$item,
			array(
				'cancel_requested' => gmdate( 'c' ),
				'history_add'      => self::event( 'cancel_requested', 'customer', '', $proof['round'] ),
			)
		);
		$order->add_order_note( __( 'De klant vraagt via de proefpagina om de bestelling te annuleren (vóór goedkeuring). De bestelling is al betaald: beslis zelf over annulering en terugbetaling.', 'borduur-patch-studio' ), 0, false );
		$mail = self::email( 'BPS_Email_Proof_Response' );
		if ( $mail ) {
			$mail->trigger( self::design_record( $item, self::get( $item ) ), $order, 'cancel', '', $proof['round'] );
		}
		return 'requested';
	}

	/**
	 * Is cancelling through the proof page allowed for this line right now?
	 *
	 * @param \WC_Order              $order Order.
	 * @param \WC_Order_Item_Product $item  Line.
	 */
	public static function can_customer_cancel( $order, $item ): bool {
		$p = self::get( $item );
		return (bool) Spec::cfg( 'promises.cancelUntilProof', false )
			&& '' === self::blocked_status( $order )
			&& in_array( $p['status'], array( 'sent', 'changes_requested' ), true )
			&& '' === $p['cancel_requested'];
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * History entry.
	 *
	 * @param string $action Action.
	 * @param string $by     Actor.
	 * @param string $note   Note.
	 * @param int    $round  Round.
	 */
	public static function event( string $action, string $by, string $note, int $round ): array {
		return array(
			'at'     => gmdate( 'c' ),
			'action' => $action,
			'by'     => $by,
			'note'   => $note,
			'round'  => $round,
		);
	}

	/**
	 * Display name of the current user ('system' for cron / CLI).
	 */
	private static function actor(): string {
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		return $user && $user->exists() ? (string) $user->display_name : 'system';
	}

	/**
	 * HMAC-SHA256 of an IP with a site-specific key (64 hex chars; the IP itself is never stored).
	 *
	 * @param string $ip IP address.
	 */
	public static function ip_hash( string $ip ): string {
		$key = (string) get_option( self::IP_KEY_OPTION, '' );
		if ( strlen( $key ) < 32 ) {
			$key = function_exists( 'wp_generate_password' ) ? wp_generate_password( 64, true, true ) : bin2hex( random_bytes( 32 ) );
			update_option( self::IP_KEY_OPTION, $key, false );
		}
		return hash_hmac( 'sha256', $ip, $key );
	}

	/**
	 * Client IP for the approval record.
	 */
	public static function client_ip(): string {
		if ( class_exists( '\WC_Geolocation' ) && is_callable( array( '\WC_Geolocation', 'get_ip_address' ) ) ) {
			$ip = (string) \WC_Geolocation::get_ip_address();
		} else {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		}
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * User agent of the request (max MAX_UA characters).
	 */
	public static function client_ua(): string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return self::cut( $ua, self::MAX_UA );
	}

	/**
	 * Sanitize free text (keeps line breaks), max MAX_TEXT characters.
	 *
	 * @param mixed $text Raw (already unslashed) text.
	 */
	public static function clean_text( $text ): string {
		return self::cut( sanitize_textarea_field( is_string( $text ) ? $text : '' ), self::MAX_TEXT );
	}

	/**
	 * Multibyte-safe cut.
	 *
	 * @param string $s   Text.
	 * @param int    $max Max characters.
	 */
	private static function cut( string $s, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/**
	 * Design record for mails (synthesized when the design was cleaned up) with the line's proof attached.
	 *
	 * @param \WC_Order_Item_Product $item  Line.
	 * @param array                  $proof Normalized proof.
	 */
	public static function design_record( $item, array $proof ): array {
		$rec          = Order_Production::design_for_item( $item )['record'];
		$rec['proof'] = $proof;
		return $rec;
	}

	/**
	 * Admin label for a proof status.
	 *
	 * @param string $status Status.
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case 'sent':
				return __( 'Proef verstuurd, wacht op antwoord', 'borduur-patch-studio' );
			case 'approved':
				return __( 'Proef goedgekeurd', 'borduur-patch-studio' );
			case 'changes_requested':
				return __( 'Klant vraagt een aanpassing', 'borduur-patch-studio' );
			case 'cancelled':
				return __( 'Geannuleerd: proef en productie gestopt', 'borduur-patch-studio' );
			default:
				return __( 'Nog geen proef verstuurd', 'borduur-patch-studio' );
		}
	}

	/**
	 * Register our e-mails (WC_Email exists inside this filter, so the classes can extend Woo\Emails\Base).
	 *
	 * @param array $emails Emails.
	 */
	public static function email_classes( $emails ) {
		$emails = is_array( $emails ) ? $emails : array();
		if ( ! class_exists( '\WC_Email' ) ) {
			return $emails;
		}
		$map = array(
			'BPS_Email_Proof'          => '\BorduurPatchStudio\Woo\Emails\Proof',
			'BPS_Email_Proof_Response' => '\BorduurPatchStudio\Woo\Emails\Proof_Response',
			'BPS_Email_Proof_Reminder' => '\BorduurPatchStudio\Woo\Emails\Proof_Reminder',
			'BPS_Email_Proof_Order'    => '\BorduurPatchStudio\Woo\Emails\Proof_Order',
		);
		foreach ( $map as $key => $class ) {
			if ( class_exists( $class ) ) {
				$emails[ $key ] = new $class();
			}
		}
		return $emails;
	}

	/**
	 * One of our e-mail objects.
	 *
	 * @param string $key Class key.
	 * @return \WC_Email|null
	 */
	public static function email( string $key ) {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$mailer = WC()->mailer();
		$all    = $mailer ? $mailer->get_emails() : array();
		return $all[ $key ] ?? null;
	}

	/* ---------------------------------------------------------------------
	 * Migration (atelier-flow-1): design-level proofs → order lines
	 * ------------------------------------------------------------------- */

	/**
	 * Run the migration once on admin requests when it did not run yet (e.g. no version bump happened).
	 */
	public static function maybe_migrate(): void {
		if ( '' === (string) get_option( self::MIGRATED_OPTION, '' ) ) {
			self::migrate();
		}
	}

	/**
	 * Copy every design proof to the order line in proof.item_id (once; lines with their own proof are kept).
	 * Returns the number of copied proofs.
	 */
	public static function migrate(): int {
		if ( '' !== (string) get_option( self::MIGRATED_OPTION, '' ) || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}
		if ( ! add_option( 'bps_proof_migrating', time(), '', false ) ) {
			$since = (int) get_option( 'bps_proof_migrating', 0 );
			if ( time() - $since < 600 ) {
				return 0;
			}
			update_option( 'bps_proof_migrating', time(), false );
		}
		$cpt    = class_exists( Designs::class ) && defined( Designs::class . '::CPT' ) ? (string) constant( Designs::class . '::CPT' ) : 'bps_design';
		$copied = 0;
		$page   = 1;
		try {
			do {
				$ids = get_posts(
					array(
						'post_type'        => $cpt,
						'post_status'      => 'any',
						'fields'           => 'ids',
						'posts_per_page'   => 200,
						'paged'            => $page,
						'orderby'          => 'ID',
						'order'            => 'ASC',
						'meta_key'         => '_bps_proof', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off migration in batches.
						'no_found_rows'    => true,
						'suppress_filters' => true,
					)
				);
				foreach ( (array) $ids as $id ) {
					$copied += self::migrate_design( (int) $id ) ? 1 : 0;
				}
				++$page;
			} while ( is_array( $ids ) && count( $ids ) >= 200 );
			update_option( self::MIGRATED_OPTION, defined( 'BPS_VERSION' ) ? (string) BPS_VERSION : '1', false );
		} catch ( \Throwable $e ) {
			if ( class_exists( '\BorduurPatchStudio\Logger' ) ) {
				\BorduurPatchStudio\Logger::error( 'proof_migration_failed', array( 'exception' => $e ) );
			}
		}
		delete_option( 'bps_proof_migrating' );
		return $copied;
	}

	/**
	 * Copy one design proof to its order line.
	 *
	 * @param int $design_id Design id.
	 */
	private static function migrate_design( int $design_id ): bool {
		$old = get_post_meta( $design_id, '_bps_proof', true );
		if ( ! is_array( $old ) || ( 'none' === ( $old['status'] ?? 'none' ) && empty( $old['history'] ) ) ) {
			return false;
		}
		$order_id = (int) ( $old['order_id'] ?? 0 ) ?: (int) get_post_meta( $design_id, '_bps_order_id', true );
		$item_id  = (int) ( $old['item_id'] ?? 0 ) ?: (int) get_post_meta( $design_id, '_bps_order_item_id', true );
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		$item     = $order instanceof \WC_Order ? $order->get_item( $item_id ) : null;
		if ( ! $item instanceof \WC_Order_Item_Product || (int) $item->get_meta( '_bps_design_id' ) !== $design_id || is_array( $item->get_meta( self::META ) ) ) {
			return false;
		}
		$old['design_id']        = $design_id;
		$old['order_id']         = $order_id;
		$old['item_id']          = $item_id;
		$old['legacy_max_round'] = (int) ( $old['round'] ?? 0 );
		$item->update_meta_data( self::META, $old );
		$item->update_meta_data( self::STATUS_META, self::normalize( $old )['status'] );
		$item->save();
		self::sync_order_flag( $order_id );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Privacy (export for P03, own eraser)
	 * ------------------------------------------------------------------- */

	/**
	 * Proof history of an order as privacy export items (one per patch line).
	 *
	 * @param \WC_Order $order Order.
	 * @return array[]
	 */
	public static function export_for_order( $order ): array {
		$out = array();
		if ( ! $order instanceof \WC_Order ) {
			return $out;
		}
		foreach ( Order_Production::design_items( $order ) as $item_id => $item ) {
			$p = self::get( $item );
			if ( 'none' === $p['status'] && ! $p['history'] ) {
				continue;
			}
			$lines = array();
			foreach ( $p['history'] as $h ) {
				if ( ! is_array( $h ) ) {
					continue;
				}
				$bits = array( (string) ( $h['at'] ?? '' ), (string) ( $h['action'] ?? '' ) );
				if ( ! empty( $h['round'] ) ) {
					/* translators: %d: round */
					$bits[] = sprintf( __( 'ronde %d', 'borduur-patch-studio' ), (int) $h['round'] );
				}
				foreach ( array( 'note', 'image_url', 'image_sha256', 'ip_hash', 'ua' ) as $k ) {
					if ( '' !== (string) ( $h[ $k ] ?? '' ) ) {
						$bits[] = $k . ': ' . (string) $h[ $k ];
					}
				}
				if ( isset( $h['confirmed'] ) ) {
					$bits[] = 'confirmed: ' . ( $h['confirmed'] ? 'yes' : 'no' );
				}
				$lines[] = implode( ' · ', array_filter( $bits, 'strlen' ) );
			}
			$out[] = array(
				'group_id'    => 'bps-proofs',
				'group_label' => __( 'Patch Studio-proeven', 'borduur-patch-studio' ),
				'item_id'     => 'bps-proof-' . (int) $order->get_id() . '-' . (int) $item_id,
				'data'        => array(
					array(
						'name'  => __( 'Bestelling', 'borduur-patch-studio' ),
						'value' => (string) $order->get_order_number(),
					),
					array(
						'name'  => __( 'Patch', 'borduur-patch-studio' ),
						'value' => (string) $item->get_meta( 'bps_ref' ),
					),
					array(
						'name'  => __( 'Status', 'borduur-patch-studio' ),
						'value' => self::status_label( $p['status'] ),
					),
					array(
						'name'  => __( 'Proefgeschiedenis', 'borduur-patch-studio' ),
						'value' => implode( "\n", $lines ),
					),
				),
			);
		}
		return $out;
	}

	/**
	 * Register the proof eraser.
	 *
	 * @param array $erasers Erasers.
	 */
	public static function register_eraser( $erasers ) {
		$erasers                 = is_array( $erasers ) ? $erasers : array();
		$erasers['bps-proofs'] = array(
			'eraser_friendly_name' => __( 'Patch Studio-proeven', 'borduur-patch-studio' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Eraser: IP hash and user agent of proof answers are always removed; notes and messages only when WooCommerce
	 * is set to remove order data on erasure requests (otherwise they stay as part of the order contract).
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 */
	public static function erase( $email, $page = 1 ): array {
		$res = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
		$email = sanitize_email( (string) $email );
		if ( '' === $email || ! function_exists( 'wc_get_orders' ) ) {
			return $res;
		}
		$orders      = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => 10,
				'paged'         => max( 1, (int) $page ),
				'type'          => 'shop_order',
			)
		);
		$full        = 'yes' === get_option( 'woocommerce_erasure_request_removes_order_data', 'no' );
		$res['done'] = count( (array) $orders ) < 10;
		foreach ( (array) $orders as $order ) {
			foreach ( Order_Production::design_items( $order ) as $item ) {
				$raw = $item->get_meta( self::META );
				if ( ! is_array( $raw ) ) {
					continue;
				}
				$changed = false;
				foreach ( (array) ( $raw['history'] ?? array() ) as $i => $h ) {
					foreach ( $full ? array( 'ip_hash', 'ua', 'note' ) : array( 'ip_hash', 'ua' ) as $k ) {
						if ( is_array( $h ) && '' !== (string) ( $h[ $k ] ?? '' ) ) {
							$raw['history'][ $i ][ $k ] = '';
							$changed                    = true;
						}
					}
				}
				if ( $full ) {
					foreach ( array( 'note', 'message', 'recipient' ) as $k ) {
						if ( '' !== (string) ( $raw[ $k ] ?? '' ) ) {
							$raw[ $k ] = '';
							$changed   = true;
						}
					}
				} elseif ( '' !== (string) ( $raw['note'] ?? '' ) ) {
					$res['items_retained'] = true;
				}
				if ( $changed ) {
					$item->update_meta_data( self::META, $raw );
					$item->save();
					$res['items_removed'] = true;
				}
			}
		}
		if ( $res['items_retained'] ) {
			$res['messages'][] = __( 'Opmerkingen bij proeven van bestellingen werden bewaard als deel van de bestelling.', 'borduur-patch-studio' );
		}
		return $res;
	}
}
