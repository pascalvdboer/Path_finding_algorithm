<?php
/**
 * BPS_Email_Proof: digital proof for one order line, to the customer, in the design's language (SPEC §6.6).
 * E-mail id `bps_proof` and its option `woocommerce_bps_proof_settings` are unchanged since 1.0.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo\Emails;

use BorduurPatchStudio\Proof\Fr_Email_Fields;
use BorduurPatchStudio\Proof\Proof_Service;
use BorduurPatchStudio\Proof\Spec;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WC_Email' ) ) {
	return;
}

/**
 * Proof e-mail.
 */
class Proof extends Base {

	use Fr_Email_Fields;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'bps_proof';
		$this->customer_email = true;
		$this->title          = __( 'Patch Studio: digitale proef', 'borduur-patch-studio' );
		$this->description    = __( 'Wordt vanuit de bestelling verstuurd met de knop "Digitale proef versturen": proefafbeelding, samenvatting en knoppen om goed te keuren of een aanpassing te vragen. In de taal van het ontwerp.', 'borduur-patch-studio' );
		$this->template_html  = 'admin/emails/proof.php';
		$this->template_plain = 'admin/emails/plain/proof.php';
		$this->placeholders   = array(
			'{order_number}' => '',
		);
		$this->bps_setup();
		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'Je digitale proef voor patch {ref} staat klaar', 'borduur-patch-studio' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Je digitale proef', 'borduur-patch-studio' );
	}

	/**
	 * Send the proof.
	 *
	 * @param array                  $design     DesignRecord.
	 * @param \WC_Order              $order      Order.
	 * @param \WC_Order_Item_Product $item       Line.
	 * @param int                    $round      Round.
	 * @param string                 $image_url  Proof image (full size; the page links to it).
	 * @param string                 $message    Message from the atelier.
	 * @param string                 $recipient  Recipient.
	 * @param string                 $mail_image Small image for the e-mail body (default $image_url).
	 */
	public function trigger( $design = null, $order = null, $item = null, $round = 1, $image_url = '', $message = '', $recipient = '', $mail_image = '' ) {
		if ( ! is_array( $design ) || ! $order instanceof \WC_Order || ! $item instanceof \WC_Order_Item_Product ) {
			return false;
		}
		$locale           = (string) ( $design['payload']['locale'] ?? '' );
		$lang             = Spec::lang_of( '' !== $locale ? $locale : get_locale() );
		$this->bps_locale = 'fr' === $lang ? 'fr-BE' : 'nl-BE';
		$this->setup_locale();

		$this->object                         = $order;
		$this->recipient                      = '' !== $recipient ? $recipient : (string) $order->get_billing_email();
		$this->placeholders['{ref}']          = (string) $design['ref'];
		$this->placeholders['{order_number}'] = (string) $order->get_order_number();
		$this->bps_args                       = array(
			'design'      => $design,
			'order'       => $order,
			'round'       => (int) $round,
			'image_url'   => '' !== (string) $mail_image ? (string) $mail_image : (string) $image_url,
			'full_url'    => (string) $image_url,
			'message'     => (string) $message,
			'rows'        => Spec::customer_rows( $item, $lang ),
			'approve_url' => Proof_Service::url( $order, $item, 'approve', (int) $round ),
			'changes_url' => Proof_Service::url( $order, $item, 'changes', (int) $round ),
			'first_name'  => trim( (string) $order->get_billing_first_name() ),
			'contact'     => (array) Spec::cfg( 'contact', array() ),
			'lang'        => $lang,
		);
		$sent = $this->bps_send();
		$this->restore_locale();
		return $sent;
	}
}
