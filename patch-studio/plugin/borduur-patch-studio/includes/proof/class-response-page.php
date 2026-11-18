<?php
/**
 * Public proof page behind the links in the proof e-mail (one order line). GET only shows a page; the answer is
 * stored on POST, so mail scanners that prefetch links cannot approve. The HMAC token in the link is the
 * authorisation (the customer is not logged in).
 *
 * Links: ?bps_proof={designId}&o={orderId}&item={itemId}&t={token}&a=approve|changes|cancel
 * Old links without item= (token over the design id) keep working for the rounds sent before the migration.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Proof;

use BorduurPatchStudio\Admin\Order_Production;
use BorduurPatchStudio\Woo\Designs;

defined( 'ABSPATH' ) || exit;

/**
 * Response page controller.
 */
final class Response_Page {

	/**
	 * template_redirect handler.
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- the HMAC token in the link is the authorisation.
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		$get     = wp_unslash( $_GET );
		$post    = $is_post ? wp_unslash( $_POST ) : array();
		// phpcs:enable
		if ( isset( $get[ Order_Proof::PARAM ] ) || isset( $post[ Order_Proof::PARAM ] ) ) {
			$res = Order_Proof::process( (array) $get, (array) $post, $is_post );
		} elseif ( isset( $get['bps_proof'] ) || isset( $post['bps_proof'] ) ) {
			$res = self::process( (array) $get, (array) $post, $is_post );
		} else {
			return;
		}
		self::send_headers();
		status_header( (int) $res['status'] );
		Lang::switch_to( (string) $res['args']['lang'] );
		Order_Production::template( $res['template'], $res['args'] );
		Lang::restore();
		exit;
	}

	/**
	 * Headers for every proof page: never cached or indexed, no referrer (the URL carries the token), no framing.
	 */
	public static function send_headers(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Frame-Options: DENY' );
			header( "Content-Security-Policy: frame-ancestors 'none'" );
		}
	}

	/**
	 * Wrap the page in the theme? Off by default (security-5: no third-party scripts next to a token URL).
	 */
	public static function use_theme(): bool {
		return (bool) apply_filters( 'bps_proof_page_use_theme', false );
	}

	/**
	 * Request parameters of a single-line link.
	 *
	 * @param array $src Unslashed GET or POST data.
	 */
	private static function params( array $src ): array {
		$action = isset( $src['a'] ) ? sanitize_key( (string) $src['a'] ) : 'approve';
		return array(
			'design_id' => isset( $src['bps_proof'] ) ? absint( $src['bps_proof'] ) : 0,
			'order_id'  => isset( $src['o'] ) ? absint( $src['o'] ) : 0,
			'item_id'   => isset( $src['item'] ) ? absint( $src['item'] ) : 0,
			'token'     => isset( $src['t'] ) ? strtolower( (string) preg_replace( '/[^a-fA-F0-9]/', '', (string) $src['t'] ) ) : '',
			'action'    => in_array( $action, array( 'changes', 'cancel' ), true ) ? $action : 'approve',
		);
	}

	/**
	 * Resolve the order line and token state of a link.
	 *
	 * @param array $q self::params().
	 * @return array{0:?\WC_Order,1:?\WC_Order_Item_Product,2:string}
	 */
	public static function resolve( array $q ): array {
		$order = $q['order_id'] > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $q['order_id'] ) : null;
		if ( ! $order instanceof \WC_Order || 64 !== strlen( $q['token'] ) ) {
			return array( null, null, 'invalid' );
		}
		$items = Order_Production::design_items( $order );
		if ( $q['item_id'] > 0 ) {
			$item = $items[ $q['item_id'] ] ?? null;
			return $item ? array( $order, $item, Proof_Service::check_token( $order, $item, $q['token'] ) ) : array( null, null, 'invalid' );
		}
		// Old format: find the line of this design in this order.
		foreach ( $items as $item ) {
			if ( $q['design_id'] > 0 && (int) $item->get_meta( '_bps_design_id' ) === $q['design_id'] ) {
				$state = Proof_Service::check_token( $order, $item, $q['token'], true, $q['design_id'] );
				if ( 'invalid' !== $state ) {
					return array( $order, $item, $state );
				}
			}
		}
		return array( null, null, 'invalid' );
	}

	/**
	 * Process a request (no output). Returns {status, template, args}.
	 *
	 * @param array $get     Unslashed GET.
	 * @param array $post    Unslashed POST.
	 * @param bool  $is_post POST request.
	 */
	public static function process( array $get, array $post, bool $is_post ): array {
		$is_post = $is_post && isset( $post['bps_proof'] );
		$q       = self::params( $is_post ? $post : $get );
		// Sanitized at once (security-11); only used on POST.
		$note      = $is_post && isset( $post['note'] ) ? Proof_Service::clean_text( $post['note'] ) : '';
		$confirmed = $is_post && ! empty( $post['bps_confirm'] );

		list( $order, $item, $state ) = self::resolve( $q );
		if ( 'invalid' === $state ) {
			// Without a valid token we reveal nothing: no ref, order number, image or details.
			return self::result( 403, array( 'view' => 'invalid' ) );
		}

		$rec  = Order_Production::design_for_item( $item )['record'];
		$lang = Spec::lang_of( (string) ( $rec['payload']['locale'] ?? '' ) );
		Lang::switch_to( $lang );
		try {
			$res = self::decide( $order, $item, $rec, $state, $q, $is_post, $note, $confirmed, $lang );
		} finally {
			Lang::restore();
		}
		return $res;
	}

	/**
	 * Decide the view and apply the POST (inside the customer's language).
	 *
	 * @param \WC_Order              $order     Order.
	 * @param \WC_Order_Item_Product $item      Line.
	 * @param array                  $rec       DesignRecord.
	 * @param string                 $state     current|superseded.
	 * @param array                  $q         Params.
	 * @param bool                   $is_post   POST.
	 * @param string                 $note      Note.
	 * @param bool                   $confirmed Checkbox ticked.
	 * @param string                 $lang      Language.
	 */
	private static function decide( $order, $item, array $rec, string $state, array $q, bool $is_post, string $note, bool $confirmed, string $lang ): array {
		$base = array(
			'lang'   => $lang,
			'order'  => $order,
			'item'   => $item,
			'design' => $rec,
			'action' => $q['action'],
		);
		if ( '' !== Proof_Service::blocked_status( $order ) || 'cancelled' === Proof_Service::get( $item )['status'] ) {
			return self::result( 410, array_merge( $base, array( 'view' => 'stopped' ) ) );
		}
		if ( 'superseded' === $state ) {
			return self::result( 200, array_merge( $base, array( 'view' => 'superseded' ) ) );
		}

		$proof = Proof_Service::get( $item );
		$error = '';
		if ( 'cancel' === $q['action'] ) {
			if ( ! Proof_Service::can_customer_cancel( $order, $item ) ) {
				$view = 'sent' === $proof['status'] ? 'confirm' : 'answered';
				$q['action'] = 'approve';
				if ( $is_post ) {
					$error = __( 'Annuleren via deze pagina kan niet (meer). Neem contact met ons op.', 'borduur-patch-studio' );
				}
			} elseif ( $is_post ) {
				$view = 'requested' === Proof_Service::customer_cancel( $order, $item ) ? 'cancel_requested' : 'cancel_done';
			} else {
				$view = 'cancel_confirm';
			}
		} elseif ( 'sent' !== $proof['status'] ) {
			$view = 'answered';
		} elseif ( ! $is_post ) {
			$view = 'confirm';
		} elseif ( 'changes' === $q['action'] && '' === trim( $note ) ) {
			$view  = 'confirm';
			$error = __( 'Laat ons even weten wat je wil aanpassen.', 'borduur-patch-studio' );
		} elseif ( 'approve' === $q['action'] && ! $confirmed ) {
			$view  = 'confirm';
			$error = __( 'Vink aan dat je de spelling, de kleuren, de maat en het aantal hebt nagekeken.', 'borduur-patch-studio' );
		} else {
			Proof_Service::record_response(
				$order,
				$item,
				$q['action'],
				$note,
				array(
					'by'        => 'customer',
					'ip'        => Proof_Service::client_ip(),
					'ua'        => Proof_Service::client_ua(),
					'confirmed' => $confirmed,
				)
			);
			$view = 'done';
		}
		$proof = Proof_Service::get( $item );
		$token = Proof_Service::token( (int) $order->get_id(), (int) $item->get_id(), max( 1, $proof['round'] ) );
		if ( 0 === $q['item_id'] ) {
			$token = $q['token']; // Old link format: the form keeps posting the (verified) old token.
		}
		return self::result(
			200,
			array_merge(
				$base,
				self::images( $rec, $proof ),
				array(
					'view'       => $view,
					'action'     => $q['action'],
					'error'      => $error,
					'proof'      => $proof,
					'rows'       => in_array( $view, array( 'confirm', 'done', 'answered' ), true ) ? Spec::customer_rows( $item, $lang ) : array(),
					'note'       => $error ? $note : '',
					'form'       => array(
						'url'    => home_url( '/' ),
						'fields' => array(
							'bps_proof' => $q['design_id'],
							'o'         => (int) $order->get_id(),
							'item'      => $q['item_id'] > 0 ? (int) $item->get_id() : 0,
							't'         => $token,
							'a'         => $q['action'],
						),
					),
					'other_url'  => 'sent' === $proof['status'] ? Proof_Service::url( $order, $item, 'approve' === $q['action'] ? 'changes' : 'approve' ) : '',
					'cancel_url' => Proof_Service::can_customer_cancel( $order, $item ) ? Proof_Service::url( $order, $item, 'cancel' ) : '',
					'pay_url'    => self::pay_url( $order, $proof ),
				)
			)
		);
	}

	/**
	 * "Pay now" after approval when the shop promised payment after the proof (promises.payAfterProof).
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $proof Proof.
	 */
	public static function pay_url( $order, array $proof ): string {
		if ( 'approved' !== $proof['status'] || ! Spec::cfg( 'promises.payAfterProof', false ) || ! $order->needs_payment() ) {
			return '';
		}
		return (string) $order->get_checkout_payment_url();
	}

	/**
	 * Image URLs for the page: display, full resolution and the previous round (when it differs).
	 *
	 * @param array $rec   DesignRecord.
	 * @param array $proof Proof.
	 * @return array{img:string,full_url:string,prev_url:string}
	 */
	public static function images( array $rec, array $proof ): array {
		$full = '' !== $proof['image_url'] ? $proof['image_url'] : (string) ( $rec['preview_url'] ?? '' );
		$img  = $full;
		if ( $proof['attachment_id'] > 0 && function_exists( 'wp_get_attachment_image_src' ) ) {
			$src = wp_get_attachment_image_src( $proof['attachment_id'], 'large' );
			$img = is_array( $src ) && ! empty( $src[0] ) ? (string) $src[0] : $img;
			$url = (string) wp_get_attachment_url( $proof['attachment_id'] );
			$full = '' !== $url ? $url : $full;
		} elseif ( (int) ( $rec['id'] ?? 0 ) > 0 && $full === (string) ( $rec['preview_url'] ?? '' ) && class_exists( Designs::class ) && is_callable( array( Designs::class, 'preview_url' ) ) ) {
			$big  = (string) Designs::preview_url( (int) $rec['id'], 'full' );
			$full = '' !== $big ? $big : $full;
		}
		$sent = array_values(
			array_filter(
				$proof['history'],
				static function ( $h ) {
					return is_array( $h ) && 'sent' === ( $h['action'] ?? '' ) && '' !== (string) ( $h['image_url'] ?? '' );
				}
			)
		);
		$prev = count( $sent ) >= 2 ? (string) $sent[ count( $sent ) - 2 ]['image_url'] : '';
		return array(
			'img'      => $img,
			'full_url' => $full,
			'prev_url' => '' !== $prev && $prev !== $proof['image_url'] ? $prev : '',
		);
	}

	/**
	 * Result with the defaults every view needs.
	 *
	 * @param int   $status HTTP status.
	 * @param array $args   Template variables.
	 */
	public static function result( int $status, array $args ): array {
		return array(
			'status'   => $status,
			'template' => 'admin/proof-response.php',
			'args'     => array_merge(
				array(
					'view'       => 'invalid',
					'action'     => 'approve',
					'error'      => '',
					'lang'       => Spec::lang_of( function_exists( 'determine_locale' ) ? determine_locale() : get_locale() ),
					'design'     => null,
					'proof'      => null,
					'order'      => null,
					'item'       => null,
					'rows'       => array(),
					'form'       => array(
						'url'    => home_url( '/' ),
						'fields' => array(),
					),
					'other_url'  => '',
					'cancel_url' => '',
					'pay_url'    => '',
					'img'        => '',
					'full_url'   => '',
					'prev_url'   => '',
					'note'       => '',
					'contact'    => (array) Spec::cfg( 'contact', array() ),
					'use_theme'  => self::use_theme(),
				),
				$args
			),
		);
	}
}
