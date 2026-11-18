<?php
/**
 * BPS_Email_Proof_Reminder: "your proof is still waiting" to the customer (atelier-flow-9), in the design's
 * language, with the valid link of the current round per patch. On by default; can be switched off under
 * WooCommerce › Settings › E-mails.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo\Emails;

use BorduurPatchStudio\Admin\Order_Production;
use BorduurPatchStudio\Proof\Fr_Email_Fields;
use BorduurPatchStudio\Proof\Spec;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WC_Email' ) ) {
	return;
}

/**
 * Proof reminder.
 */
class Proof_Reminder extends Base {

	use Fr_Email_Fields;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'bps_proof_reminder';
		$this->customer_email = true;
		$this->title          = __( 'Patch Studio: herinnering proef', 'borduur-patch-studio' );
		$this->description    = __( 'Vriendelijke herinnering als een digitale proef na enkele dagen nog niet beantwoord is (hoogstens 2 keer per proefronde). Het aantal dagen stel je in bij Patch Studio (proef.reminderDays, standaard 3).', 'borduur-patch-studio' );
		$this->template_html  = 'admin/emails/proof-reminder.php';
		$this->template_plain = 'admin/emails/plain/proof-reminder.php';
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
		return __( 'Je digitale proef wacht nog op je antwoord (bestelling #{order_number})', 'borduur-patch-studio' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Je proef wacht op je', 'borduur-patch-studio' );
	}

	/**
	 * Send.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $lines Reminders::due_lines().
	 */
	public function trigger( $order = null, $lines = array() ) {
		if ( ! $order instanceof \WC_Order || ! is_array( $lines ) || ! $lines ) {
			return false;
		}
		$first            = reset( $lines )['item'];
		$locale           = (string) ( Order_Production::design_for_item( $first )['record']['payload']['locale'] ?? '' );
		$lang             = Spec::lang_of( '' !== $locale ? $locale : get_locale() );
		$this->bps_locale = 'fr' === $lang ? 'fr-BE' : 'nl-BE';
		$this->setup_locale();
		$cards = array();
		foreach ( $lines as $l ) {
			$cards[] = array(
				'ref'   => (string) $l['item']->get_meta( 'bps_ref' ),
				'image' => (string) $l['proof']['image_url'],
				'url'   => (string) $l['url'],
				'round' => (int) $l['proof']['round'],
				'sent'  => (string) $l['proof']['sent_at'],
			);
		}
		$this->object                         = $order;
		$this->recipient                      = (string) ( reset( $lines )['proof']['recipient'] ?: $order->get_billing_email() );
		$this->placeholders['{ref}']          = implode( ', ', array_column( $cards, 'ref' ) );
		$this->placeholders['{order_number}'] = (string) $order->get_order_number();
		$this->bps_args                       = array(
			'order'      => $order,
			'cards'      => $cards,
			'first_name' => trim( (string) $order->get_billing_first_name() ),
			'contact'    => (array) Spec::cfg( 'contact', array() ),
			'lang'       => $lang,
		);
		$sent = $this->bps_send();
		$this->restore_locale();
		return $sent;
	}
}
