<?php
/**
 * Proof for a whole order (atelier-flow-10): one e-mail and one page with a card and a choice per patch line,
 * plus "Approve all". Every line keeps its own round and history; the per-line proof keeps working.
 *
 * Link: ?bps_proof_order={orderId}&t={token}, token = HMAC over "proof-order|{order}|{item}:{round},…" (sorted).
 * Order meta `_bps_order_proof` = {rev, items: {itemId: round}, sent_at, recipient, maps: [older maps]}.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Proof;

use BorduurPatchStudio\Admin\Order_Production;

defined( 'ABSPATH' ) || exit;

/**
 * Whole-order proof.
 */
final class Order_Proof {

	/** Query parameter of the page. */
	const PARAM = 'bps_proof_order';

	/** Order meta. */
	const META = '_bps_order_proof';

	/** Older maps kept to recognise superseded links. */
	const KEEP_MAPS = 5;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_post_bps_send_order_proof', array( __CLASS__, 'handle_admin' ) );
	}

	/**
	 * Token over an item → round map.
	 *
	 * @param int   $order_id Order id.
	 * @param array $map      itemId => round.
	 */
	public static function token( int $order_id, array $map ): string {
		ksort( $map );
		$parts = array();
		foreach ( $map as $item_id => $round ) {
			$parts[] = (int) $item_id . ':' . (int) $round;
		}
		return hash_hmac( 'sha256', 'proof-order|' . $order_id . '|' . implode( ',', $parts ), wp_salt( 'auth' ) );
	}

	/**
	 * Current record of the order ([] when never sent).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function record( $order ): array {
		$r = $order->get_meta( self::META );
		return is_array( $r ) && ! empty( $r['items'] ) ? $r : array();
	}

	/**
	 * Customer link of the current whole-order proof ('' when none).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function url( $order ): string {
		$r = self::record( $order );
		if ( ! $r ) {
			return '';
		}
		return add_query_arg(
			array(
				self::PARAM => (int) $order->get_id(),
				't'         => self::token( (int) $order->get_id(), (array) $r['items'] ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Lines that can take part in a whole-order proof (design exists, line not stopped).
	 *
	 * @param \WC_Order $order Order.
	 * @return \WC_Order_Item_Product[]
	 */
	public static function eligible( $order ): array {
		$out = array();
		foreach ( Order_Production::design_items( $order ) as $item_id => $item ) {
			if ( ! is_wp_error( Proof_Service::can_send( $order, $item ) ) && 'approved' !== Proof_Service::get( $item )['status'] ) {
				$out[ $item_id ] = $item;
			}
		}
		return $out;
	}

	/**
	 * Send one proof mail for every eligible line.
	 *
	 * @param \WC_Order $order     Order.
	 * @param string    $message   Message for the customer.
	 * @param string    $recipient Recipient (default billing e-mail).
	 * @return array{lines:int, recipient:string}|\WP_Error
	 */
	public static function send( $order, string $message = '', string $recipient = '' ) {
		if ( '' !== Proof_Service::blocked_status( $order ) ) {
			return new \WP_Error( 'bps_order_stopped', __( 'Deze bestelling is geannuleerd, terugbetaald of mislukt. Je kunt geen proef meer versturen.', 'borduur-patch-studio' ) );
		}
		$lines = self::eligible( $order );
		if ( ! $lines ) {
			return new \WP_Error( 'bps_no_lines', __( 'Er is geen patch in deze bestelling waarvoor nog een proef nodig is.', 'borduur-patch-studio' ) );
		}
		$recipient = '' !== $recipient ? $recipient : (string) $order->get_billing_email();
		if ( ! is_email( $recipient ) ) {
			return new \WP_Error( 'bps_no_email', __( 'Deze bestelling heeft geen geldig e-mailadres. Vul een ontvanger in.', 'borduur-patch-studio' ) );
		}
		$email = Proof_Service::email( 'BPS_Email_Proof_Order' );
		if ( ! $email || ! $email->is_enabled() ) {
			return new \WP_Error( 'bps_mail_disabled', __( 'De e-mail "Patch Studio: proef voor de hele bestelling" staat uit onder WooCommerce › Instellingen › E-mails.', 'borduur-patch-studio' ) );
		}
		$map   = array();
		$cards = array();
		foreach ( $lines as $item_id => $item ) {
			$rec   = Proof_Service::can_send( $order, $item );
			$image = Proof_Service::image( $rec, 0 );
			if ( is_wp_error( $image ) ) {
				return $image;
			}
			$map[ (int) $item_id ] = Proof_Service::get( $item )['round'] + 1;
			$cards[]               = array(
				'item'  => $item,
				'rec'   => $rec,
				'image' => $image,
				'round' => $map[ (int) $item_id ],
			);
		}
		$old = self::record( $order );
		$rev = (int) ( $old['rev'] ?? 0 ) + 1;
		$url = add_query_arg(
			array(
				self::PARAM => (int) $order->get_id(),
				't'         => self::token( (int) $order->get_id(), $map ),
			),
			home_url( '/' )
		);
		if ( ! $email->trigger( $order, $cards, $url, $message, $recipient ) ) {
			return new \WP_Error( 'bps_mail_failed', __( 'De e-mail kon niet verstuurd worden. Controleer de e-mailinstellingen van de website.', 'borduur-patch-studio' ) );
		}
		foreach ( $cards as $c ) {
			Proof_Service::mark_sent( $order, $c['item'], $c['rec'], $c['round'], $c['image'], $message, $recipient, $rev );
		}
		$maps = isset( $old['maps'] ) && is_array( $old['maps'] ) ? $old['maps'] : array();
		if ( ! empty( $old['items'] ) ) {
			$maps[] = $old['items'];
		}
		$order->update_meta_data(
			self::META,
			array(
				'rev'       => $rev,
				'items'     => $map,
				'sent_at'   => gmdate( 'c' ),
				'recipient' => $recipient,
				'maps'      => array_slice( $maps, -self::KEEP_MAPS ),
			)
		);
		$order->save();
		$order->add_order_note(
			/* translators: 1: number of patches, 2: e-mail */
			sprintf( _n( 'Digitale proef voor de hele bestelling (%1$d patch) verstuurd naar %2$s.', 'Digitale proef voor de hele bestelling (%1$d patches) verstuurd naar %2$s.', count( $cards ), 'borduur-patch-studio' ), count( $cards ), $recipient ),
			0,
			true
		);
		foreach ( $map as $item_id => $round ) {
			do_action( 'bps_proof_sent', (int) $order->get_id(), (int) $item_id, (int) $round );
		}
		return array(
			'lines'     => count( $cards ),
			'recipient' => $recipient,
		);
	}

	/**
	 * Process the customer page (no output). Returns {status, template, args}.
	 *
	 * @param array $get     Unslashed GET.
	 * @param array $post    Unslashed POST.
	 * @param bool  $is_post POST request.
	 */
	public static function process( array $get, array $post, bool $is_post ): array {
		$is_post  = $is_post && isset( $post[ self::PARAM ] );
		$src      = $is_post ? $post : $get;
		$order_id = absint( $src[ self::PARAM ] ?? 0 );
		$token    = strtolower( (string) preg_replace( '/[^a-fA-F0-9]/', '', (string) ( $src['t'] ?? '' ) ) );
		$order    = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$rec      = $order instanceof \WC_Order ? self::record( $order ) : array();
		$state    = 'invalid';
		if ( $rec && 64 === strlen( $token ) ) {
			if ( hash_equals( self::token( $order_id, (array) $rec['items'] ), $token ) ) {
				$state = 'current';
			} else {
				foreach ( (array) ( $rec['maps'] ?? array() ) as $old ) {
					if ( is_array( $old ) && hash_equals( self::token( $order_id, $old ), $token ) ) {
						$state = 'superseded';
					}
				}
			}
		}
		if ( 'invalid' === $state ) {
			return Response_Page::result( 403, array( 'view' => 'invalid' ) );
		}

		// Lines of this proof (in order), with the customer language of the first line.
		$items = array();
		$all   = Order_Production::design_items( $order );
		foreach ( (array) $rec['items'] as $item_id => $round ) {
			if ( isset( $all[ (int) $item_id ] ) ) {
				$items[ (int) $item_id ] = array(
					'item'  => $all[ (int) $item_id ],
					'round' => (int) $round,
				);
			}
		}
		$first = $items ? reset( $items )['item'] : null;
		$lang  = $first ? Spec::lang_of( (string) ( Order_Production::design_for_item( $first )['record']['payload']['locale'] ?? '' ) ) : 'nl';
		$base  = array(
			'lang'  => $lang,
			'order' => $order,
		);
		if ( '' !== Proof_Service::blocked_status( $order ) ) {
			return Response_Page::result( 410, array_merge( $base, array( 'view' => 'stopped' ) ) );
		}
		if ( 'superseded' === $state ) {
			return Response_Page::result( 200, array_merge( $base, array( 'view' => 'superseded' ) ) );
		}

		Lang::switch_to( $lang );
		try {
			$error = '';
			$done  = false;
			if ( $is_post ) {
				list( $error, $done ) = self::apply( $order, $items, $post );
			}
			$cards = array();
			foreach ( $items as $item_id => $row ) {
				$p   = Proof_Service::get( $row['item'] );
				$drc = Order_Production::design_for_item( $row['item'] )['record'];
				// A line is open only while it still waits at the round of this proof.
				$open    = 'sent' === $p['status'] && $p['round'] === $row['round'];
				$cards[] = array_merge(
					Response_Page::images( $drc, $p ),
					array(
						'item_id' => (int) $item_id,
						'ref'     => (string) $row['item']->get_meta( 'bps_ref' ),
						'proof'   => $p,
						'open'    => $open,
						'rows'    => Spec::customer_rows( $row['item'], $lang ),
						'choice'  => $error ? sanitize_key( (string) ( $post['choice'][ $item_id ] ?? '' ) ) : '',
						'note'    => $error ? Proof_Service::clean_text( $post['notes'][ $item_id ] ?? '' ) : '',
					)
				);
			}
			$open   = array_filter( array_column( $cards, 'open' ) );
			$all_ok = $cards && count( $cards ) === count( array_filter( $cards, static fn( $c ) => 'approved' === $c['proof']['status'] ) );
		} finally {
			Lang::restore();
		}
		return array(
			'status'   => 200,
			'template' => 'admin/proof-order.php',
			'args'     => array_merge(
				Response_Page::result( 200, array() )['args'],
				$base,
				array(
					'view'    => $done ? 'done' : ( $open ? 'confirm' : 'answered' ),
					'error'   => $error,
					'cards'   => $cards,
					'pay_url' => $all_ok ? Response_Page::pay_url( $order, array( 'status' => 'approved' ) ) : '',
					'form'    => array(
						'url'    => home_url( '/' ),
						'fields' => array(
							self::PARAM => $order_id,
							't'         => $token,
						),
					),
				)
			),
		);
	}

	/**
	 * Apply a POST: one choice per open line (or "approve all"), then one order note and one shop mail.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $items itemId => {item, round}.
	 * @param array     $post  Unslashed POST.
	 * @return array{0:string,1:bool} [error, done]
	 */
	private static function apply( $order, array $items, array $post ): array {
		$all       = ! empty( $post['approve_all'] );
		$confirmed = ! empty( $post['bps_confirm'] );
		$choices   = array();
		foreach ( $items as $item_id => $row ) {
			$p = Proof_Service::get( $row['item'] );
			if ( 'sent' !== $p['status'] || $p['round'] !== $row['round'] ) {
				continue;
			}
			$choice = $all ? 'approve' : sanitize_key( (string) ( $post['choice'][ $item_id ] ?? '' ) );
			$note   = Proof_Service::clean_text( $post['notes'][ $item_id ] ?? '' );
			if ( ! in_array( $choice, array( 'approve', 'changes' ), true ) ) {
				return array( __( 'Kies voor elke patch: goedkeuren of iets aanpassen.', 'borduur-patch-studio' ), false );
			}
			if ( 'changes' === $choice && '' === trim( $note ) ) {
				return array( __( 'Laat ons even weten wat je wil aanpassen.', 'borduur-patch-studio' ), false );
			}
			$choices[ $item_id ] = array( $choice, $note );
		}
		if ( ! $choices ) {
			return array( '', false );
		}
		if ( in_array( 'approve', array_column( $choices, 0 ), true ) && ! $confirmed ) {
			return array( __( 'Vink aan dat je de spelling, de kleuren, de maat en het aantal hebt nagekeken.', 'borduur-patch-studio' ), false );
		}
		$ctx   = array(
			'by'         => 'customer',
			'ip'         => Proof_Service::client_ip(),
			'ua'         => Proof_Service::client_ua(),
			'confirmed'  => $confirmed,
			'notify'     => false,
			'order_note' => false,
		);
		$lines = array();
		$text  = array();
		foreach ( $choices as $item_id => $c ) {
			$item = $items[ $item_id ]['item'];
			$new  = Proof_Service::record_response( $order, $item, $c[0], $c[1], $ctx );
			$ref  = (string) $item->get_meta( 'bps_ref' );
			$line = 'changes' === $c[0]
				/* translators: %s: design ref */
				? sprintf( __( '%s: aanpassing gevraagd', 'borduur-patch-studio' ), $ref )
				/* translators: %s: design ref */
				: sprintf( __( '%s: goedgekeurd', 'borduur-patch-studio' ), $ref );
			$text[]  = $line . ( '' !== $c[1] ? ' (' . $c[1] . ')' : '' );
			$lines[] = array(
				'design' => Proof_Service::design_record( $item, $new ),
				'action' => 'changes' === $c[0] ? 'changes' : 'approve',
				'note'   => $c[1],
				'round'  => $new['round'],
			);
		}
		$order->add_order_note(
			/* translators: %s: list of answers per patch */
			sprintf( __( 'Antwoord van de klant op de proef voor de hele bestelling: %s.', 'borduur-patch-studio' ), implode( '; ', $text ) ),
			0,
			false
		);
		$mail = Proof_Service::email( 'BPS_Email_Proof_Response' );
		if ( $mail ) {
			$first = reset( $lines );
			$mail->trigger( $first['design'], $order, $first['action'], $first['note'], $first['round'], $lines );
		}
		return array( '', true );
	}

	/**
	 * Admin-post handler: confirmation screen (GET) and sending (POST) of the whole-order proof. Works without
	 * JavaScript and outside the order form (no nested forms).
	 */
	public static function handle_admin(): void {
		$order_id = isset( $_REQUEST['order'] ) ? absint( wp_unslash( $_REQUEST['order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Je hebt geen rechten om bestellingen te bewerken.', 'borduur-patch-studio' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bps_send_order_proof_' . $order_id );
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Bestelling niet gevonden.', 'borduur-patch-studio' ), '', array( 'response' => 404 ) );
		}
		$back = method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$message   = isset( $_POST['message'] ) ? Proof_Service::clean_text( sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) ) : '';
			$recipient = isset( $_POST['recipient'] ) ? sanitize_email( wp_unslash( $_POST['recipient'] ) ) : '';
			$res       = self::send( $order, $message, $recipient );
			// Only a code travels in the URL; the metabox maps it to a fixed message.
			wp_safe_redirect( add_query_arg( 'bps_order_proof', is_wp_error( $res ) ? sanitize_key( $res->get_error_code() ) : 'sent', $back ) );
			exit;
		}
		nocache_headers();
		Order_Production::template(
			'admin/order-proof-send.php',
			array(
				'order' => $order,
				'lines' => self::eligible( $order ),
				'back'  => $back,
				'nonce' => wp_create_nonce( 'bps_send_order_proof_' . $order_id ),
			)
		);
		exit;
	}

	/**
	 * Admin link to the confirmation screen.
	 *
	 * @param int $order_id Order id.
	 */
	public static function admin_url( int $order_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'bps_send_order_proof',
					'order'  => $order_id,
				),
				admin_url( 'admin-post.php' )
			),
			'bps_send_order_proof_' . $order_id
		);
	}
}
