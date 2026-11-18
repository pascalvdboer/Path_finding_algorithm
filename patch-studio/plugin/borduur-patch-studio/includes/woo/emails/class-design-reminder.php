<?php
/**
 * E-mail to the customer: the single reminder after "Bewaar & mail mijn ontwerp" (klant-account-1).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo\Emails;

use BorduurPatchStudio\Woo\Account;
use BorduurPatchStudio\Woo\Config;
use BorduurPatchStudio\Woo\Designs;
use BorduurPatchStudio\Woo\Quotes;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WC_Email' ) ) {
	return;
}

/**
 * BPS_Email_Design_Reminder. Honest by design: no countdown, no "only today", no invented scarcity. It says the
 * design is still there, offers the link back and a one-click unsubscribe, and states that this is the only reminder.
 */
class Design_Reminder extends Base {

	/** @var string Unsubscribe URL of the current send (also used for the List-Unsubscribe header). */
	protected $bps_unsubscribe = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'bps_design_reminder';
		$this->customer_email = true;
		$this->title          = __( 'Patch Studio: herinnering bewaard ontwerp', 'borduur-patch-studio' );
		$this->description    = __( 'Eén herinnering, alleen als de klant daarvoor koos bij "Bewaar & mail mijn ontwerp". Wordt niet verstuurd als het ontwerp intussen besteld, in de winkelmand gelegd of opnieuw geopend werd.', 'borduur-patch-studio' );
		$this->template_html  = 'emails/design-reminder.php';
		$this->template_plain = 'emails/plain/design-reminder.php';
		$this->bps_setup();
		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( 'Je patch-ontwerp {ref} staat nog voor je klaar', 'borduur-patch-studio' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Je ontwerp staat nog klaar', 'borduur-patch-studio' );
	}

	/**
	 * Headers with List-Unsubscribe (and one-click POST, RFC 8058).
	 */
	public function get_headers() {
		$headers = (string) parent::get_headers();
		if ( '' !== $this->bps_unsubscribe ) {
			$headers .= 'List-Unsubscribe: <' . esc_url_raw( $this->bps_unsubscribe ) . ">\r\n";
			$headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
		}
		return $headers;
	}

	/**
	 * Send.
	 *
	 * @param int    $design_id   Design id.
	 * @param string $email       Recipient.
	 * @param string $locale      Customer locale.
	 * @param string $unsubscribe Unsubscribe URL.
	 */
	public function trigger( $design_id, $email = '', $locale = '', $unsubscribe = '' ) {
		$d = Designs::get( (int) $design_id );
		if ( ! $d || ! is_email( $email ) ) {
			return false;
		}
		$p                     = $d['payload'];
		$this->bps_locale      = Config::public_locale( '' !== (string) $locale ? (string) $locale : (string) ( $p['locale'] ?? '' ) );
		$this->bps_unsubscribe = (string) $unsubscribe;
		$this->setup_locale();
		$this->object                = $d;
		$this->recipient             = (string) $email;
		$this->placeholders['{ref}'] = $d['ref'];
		$this->bps_args              = array(
			'design'          => $d,
			'design_rows'     => Quotes::design_rows( $d, $this->bps_locale ),
			'preview_url'     => Designs::preview_url( (int) $d['id'], 'mail' ),
			'restore_url'     => Designs::restore_url( (int) $d['id'] ),
			'unsubscribe_url' => $this->bps_unsubscribe,
			'account_url'     => (int) $d['author'] > 0 ? Account::url() : '',
			'qty'             => (int) ( $p['qty'] ?? 1 ),
			'days'            => (int) Config::path( 'retention.savedDays', 90 ),
			'contact'         => (array) Config::path( 'contact', array() ),
		);
		$sent = $this->bps_send();
		$this->restore_locale();
		$this->bps_unsubscribe = '';
		return $sent;
	}
}
