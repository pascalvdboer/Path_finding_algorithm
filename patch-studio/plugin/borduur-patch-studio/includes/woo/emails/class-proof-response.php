<?php
/**
 * BPS_Email_Proof_Response: the customer answered a proof (to the shop). Also used for a whole-order answer (one
 * mail with a line per patch) and for a cancellation request through the proof page.
 * E-mail id `bps_proof_response` and its option `woocommerce_bps_proof_response_settings` are unchanged since 1.0.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo\Emails;

use BorduurPatchStudio\Settings;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WC_Email' ) ) {
	return;
}

/**
 * Shop notification.
 */
class Proof_Response extends Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'bps_proof_response';
		$this->customer_email = false;
		$this->title          = __( 'Patch Studio: antwoord op digitale proef', 'borduur-patch-studio' );
		$this->description    = __( 'Melding aan de winkel wanneer een klant een digitale proef goedkeurt, een aanpassing vraagt of via de proefpagina wil annuleren.', 'borduur-patch-studio' );
		$this->template_html  = 'admin/emails/proof-response.php';
		$this->template_plain = 'admin/emails/plain/proof-response.php';
		$this->placeholders   = array(
			'{order_number}' => '',
			'{answer}'       => '',
		);
		$this->bps_setup();
		parent::__construct();
		$default         = class_exists( Settings::class ) ? (string) Settings::get_path( 'woo.quoteRecipient', '' ) : '';
		$this->recipient = $this->get_option( 'recipient', '' !== $default ? $default : get_option( 'admin_email' ) );
	}

	/**
	 * Settings fields (+ recipient).
	 */
	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields = array_merge(
			array_slice( $this->form_fields, 0, 1, true ),
			array(
				'recipient' => array(
					'title'       => __( 'Ontvanger(s)', 'borduur-patch-studio' ),
					'type'        => 'text',
					/* translators: %s: admin e-mail */
					'description' => sprintf( __( 'Komma-gescheiden. Standaard: %s.', 'borduur-patch-studio' ), esc_attr( get_option( 'admin_email' ) ) ),
					'placeholder' => '',
					'default'     => '',
					'desc_tip'    => true,
				),
			),
			array_slice( $this->form_fields, 1, null, true )
		);
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( '[{site_title}] Proef {ref} (bestelling #{order_number}): {answer}', 'borduur-patch-studio' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Antwoord op een digitale proef', 'borduur-patch-studio' );
	}

	/**
	 * Answer word for the subject.
	 *
	 * @param string $action approve|changes|cancel.
	 */
	private static function answer( string $action ): string {
		switch ( $action ) {
			case 'changes':
				return __( 'aanpassing gevraagd', 'borduur-patch-studio' );
			case 'cancel':
				return __( 'klant wil annuleren', 'borduur-patch-studio' );
			default:
				return __( 'goedgekeurd', 'borduur-patch-studio' );
		}
	}

	/**
	 * Send.
	 *
	 * @param array     $design DesignRecord (first line for a whole-order answer).
	 * @param \WC_Order $order  Order.
	 * @param string    $action approve|changes|cancel.
	 * @param string    $note   Note.
	 * @param int       $round  Round.
	 * @param array     $lines  Whole-order answer: [{design, action, note, round}] (empty for one line).
	 */
	public function trigger( $design = null, $order = null, $action = 'approve', $note = '', $round = 1, $lines = array() ) {
		if ( ! is_array( $design ) || ! $order instanceof \WC_Order ) {
			return false;
		}
		$this->setup_locale();
		$action = in_array( $action, array( 'changes', 'cancel' ), true ) ? $action : 'approve';
		$lines  = is_array( $lines ) ? array_values( $lines ) : array();
		$answer = self::answer( $action );
		if ( count( $lines ) > 1 ) {
			$approved = count( array_filter( $lines, static fn( $l ) => 'approve' === ( $l['action'] ?? '' ) ) );
			/* translators: 1: approved patches, 2: total patches */
			$answer = sprintf( __( '%1$d van %2$d goedgekeurd', 'borduur-patch-studio' ), $approved, count( $lines ) );
		}
		$this->object                         = $order;
		$this->placeholders['{ref}']          = count( $lines ) > 1 ? implode( ', ', array_map( static fn( $l ) => (string) ( $l['design']['ref'] ?? '' ), $lines ) ) : (string) $design['ref'];
		$this->placeholders['{order_number}'] = (string) $order->get_order_number();
		$this->placeholders['{answer}']       = $answer;
		$this->bps_args                       = array(
			'design'    => $design,
			'order'     => $order,
			'action'    => $action,
			'answer'    => $answer,
			'note'      => (string) $note,
			'round'     => (int) $round,
			'lines'     => count( $lines ) > 1 ? $lines : array(),
			'order_url' => method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
			'customer'  => trim( $order->get_formatted_billing_full_name() ),
		);
		$sent                                 = $this->bps_send();
		$this->restore_locale();
		return $sent;
	}
}
