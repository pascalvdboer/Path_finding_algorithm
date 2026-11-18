<?php
/**
 * BPS_Email_Proof_Order: one digital proof e-mail for all patches of an order (atelier-flow-10), with one link
 * to a page where the customer answers per patch or approves everything at once.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo\Emails;

use BorduurPatchStudio\Proof\Fr_Email_Fields;
use BorduurPatchStudio\Proof\Spec;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WC_Email' ) ) {
	return;
}

/**
 * Whole-order proof e-mail.
 */
class Proof_Order extends Base {

	use Fr_Email_Fields;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'bps_proof_order';
		$this->customer_email = true;
		$this->title          = __( 'Patch Studio: proef voor de hele bestelling', 'borduur-patch-studio' );
		$this->description    = __( 'Eén e-mail met de digitale proef van alle patches van een bestelling en één pagina om per patch te antwoorden of alles in één keer goed te keuren.', 'borduur-patch-studio' );
		$this->template_html  = 'admin/emails/proof-order.php';
		$this->template_plain = 'admin/emails/plain/proof-order.php';
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
		return __( 'De digitale proef van je bestelling #{order_number} staat klaar', 'borduur-patch-studio' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Je digitale proef', 'borduur-patch-studio' );
	}

	/**
	 * Send.
	 *
	 * @param \WC_Order $order     Order.
	 * @param array     $cards     [{item, rec, image, round}].
	 * @param string    $url       Link to the page.
	 * @param string    $message   Message from the atelier.
	 * @param string    $recipient Recipient.
	 */
	public function trigger( $order = null, $cards = array(), $url = '', $message = '', $recipient = '' ) {
		if ( ! $order instanceof \WC_Order || ! is_array( $cards ) || ! $cards ) {
			return false;
		}
		$locale           = (string) ( reset( $cards )['rec']['payload']['locale'] ?? '' );
		$lang             = Spec::lang_of( '' !== $locale ? $locale : get_locale() );
		$this->bps_locale = 'fr' === $lang ? 'fr-BE' : 'nl-BE';
		$this->setup_locale();
		$lines = array();
		foreach ( $cards as $c ) {
			$lines[] = array(
				'ref'   => (string) $c['rec']['ref'],
				'image' => (string) $c['image']['mail'],
				'round' => (int) $c['round'],
				'rows'  => array_slice( Spec::customer_rows( $c['item'], $lang ), 0, 6 ),
			);
		}
		$this->object                         = $order;
		$this->recipient                      = '' !== $recipient ? $recipient : (string) $order->get_billing_email();
		$this->placeholders['{ref}']          = implode( ', ', array_column( $lines, 'ref' ) );
		$this->placeholders['{order_number}'] = (string) $order->get_order_number();
		$this->bps_args                       = array(
			'order'      => $order,
			'lines'      => $lines,
			'url'        => (string) $url,
			'message'    => (string) $message,
			'first_name' => trim( (string) $order->get_billing_first_name() ),
			'contact'    => (array) Spec::cfg( 'contact', array() ),
			'lang'       => $lang,
		);
		$sent                                 = $this->bps_send();
		$this->restore_locale();
		return $sent;
	}
}
