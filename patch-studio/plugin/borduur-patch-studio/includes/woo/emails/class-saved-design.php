<?php
/**
 * E-mail to the customer: "Bewaar & mail mijn ontwerp" with a link back to the studio.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo\Emails;

use BorduurPatchStudio\Woo\Cart;
use BorduurPatchStudio\Woo\Config;
use BorduurPatchStudio\Woo\Designs;
use BorduurPatchStudio\Woo\Quotes;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WC_Email' ) ) {
	return;
}

/**
 * BPS_Email_Saved_Design.
 */
class Saved_Design extends Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'bps_saved_design';
		$this->customer_email = true;
		$this->title          = __( 'Patch Studio: bewaard ontwerp', 'borduur-patch-studio' );
		$this->description    = __( 'Wordt naar de klant gestuurd die "Bewaar & mail mijn ontwerp" kiest: voorbeeld, samenvatting, prijs en een link om verder te gaan.', 'borduur-patch-studio' );
		$this->template_html  = 'emails/save-design.php';
		$this->template_plain = 'emails/plain/save-design.php';
		$this->bps_setup();
		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'Je patch-ontwerp {ref} staat klaar', 'borduur-patch-studio' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Je ontwerp is bewaard', 'borduur-patch-studio' );
	}

	/**
	 * Send.
	 *
	 * @param int    $design_id Design id.
	 * @param string $email     Recipient.
	 * @param string $locale    Locale.
	 */
	public function trigger( $design_id, $email = '', $locale = '' ) {
		$d = Designs::get( (int) $design_id );
		if ( ! $d || ! is_email( $email ) ) {
			return false;
		}
		$p                = $d['payload'];
		$this->bps_locale = Config::public_locale( '' !== (string) $locale ? (string) $locale : (string) ( $p['locale'] ?? '' ) );
		$this->setup_locale();
		$this->object                = $d;
		$this->recipient             = (string) $email;
		$this->placeholders['{ref}'] = $d['ref'];

		$quote = Cart::quote_for(
			array(
				'technique' => (string) ( $p['technique'] ?? '' ),
				'size_cm'   => (float) ( $p['sizeCm'] ?? 0 ),
				'options'   => (array) ( $p['options'] ?? array() ),
			),
			(int) ( $p['qty'] ?? 1 ),
			0,
			array(),
			0,
			$this->bps_locale
		);
		$this->bps_args = array(
			'design'      => $d,
			'design_rows' => Quotes::design_rows( $d, $this->bps_locale ),
			'preview_url' => $d['preview_url'],
			'restore_url' => Designs::restore_url( (int) $d['id'] ),
			'quote'       => ! empty( $quote['ok'] ) ? $quote : null,
			'qty'         => (int) ( $p['qty'] ?? 1 ),
			'days'        => (int) Config::path( 'retention.savedDays', 90 ),
			'contact'     => (array) Config::path( 'contact', array() ),
		);
		$sent = $this->bps_send();
		$this->restore_locale();
		return $sent;
	}
}
