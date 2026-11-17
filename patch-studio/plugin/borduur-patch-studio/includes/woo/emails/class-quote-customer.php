<?php
/**
 * E-mail to the customer: confirmation of the quote request, in the customer's language.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo\Emails;

use BorduurPatchStudio\Woo\Config;
use BorduurPatchStudio\Woo\Quotes;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WC_Email' ) ) {
	return;
}

/**
 * BPS_Email_Quote_Customer.
 */
class Quote_Customer extends Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'bps_quote_customer';
		$this->customer_email = true;
		$this->title          = __( 'Patch Studio: bevestiging offerte-aanvraag', 'borduur-patch-studio' );
		$this->description    = __( 'Bevestiging naar de klant na een offerte-aanvraag in de Patch Studio, in de taal van de klant.', 'borduur-patch-studio' );
		$this->template_html  = 'emails/quote-customer.php';
		$this->template_plain = 'emails/plain/quote-customer.php';
		$this->bps_setup();
		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'We hebben je aanvraag {ref} goed ontvangen', 'borduur-patch-studio' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Bedankt voor je aanvraag', 'borduur-patch-studio' );
	}

	/**
	 * Send.
	 *
	 * @param int $quote_id Quote id.
	 */
	public function trigger( $quote_id ) {
		$q = Quotes::get( (int) $quote_id );
		if ( ! $q || ! is_email( $q['email'] ) ) {
			return;
		}
		$this->bps_locale = Config::public_locale( $q['locale'] );
		$this->setup_locale();
		$this->object                = $q;
		$this->recipient             = $q['email'];
		$this->placeholders['{ref}'] = $q['ref'];
		$d                           = $q['design'];
		$this->bps_args              = array(
			'quote'       => $q,
			'design'      => $d,
			'design_rows' => $d ? Quotes::design_rows( $d, $this->bps_locale ) : array(),
			'preview_url' => $d ? $d['preview_url'] : '',
			'contact'     => (array) Config::path( 'contact', array() ),
			'response'    => Config::label( Config::path( 'promises.responseTime' ), $this->bps_locale ),
		);
		$this->bps_send();
		$this->restore_locale();
	}
}
