<?php
/**
 * E-mail to the shop: new quote request (Reply-To = customer).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo\Emails;

use BorduurPatchStudio\Woo\Config;
use BorduurPatchStudio\Woo\Designs;
use BorduurPatchStudio\Woo\Quotes;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WC_Email' ) ) {
	return;
}

/**
 * BPS_Email_Quote_Admin.
 */
class Quote_Admin extends Base {

	/** @var array|null Current quote. */
	protected $quote = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'bps_quote_admin';
		$this->title          = __( 'Patch Studio: nieuwe offerte-aanvraag', 'borduur-patch-studio' );
		$this->description    = __( 'Wordt naar de winkel gestuurd wanneer een klant in de Patch Studio een prijs op maat vraagt. Antwoorden gaat rechtstreeks naar de klant (Reply-To).', 'borduur-patch-studio' );
		$this->template_html  = 'emails/quote-admin.php';
		$this->template_plain = 'emails/plain/quote-admin.php';
		$this->placeholders   = array(
			'{customer_name}' => '',
		);
		$this->bps_setup();
		parent::__construct();
		$default         = (string) Config::path( 'woo.quoteRecipient', '' );
		$this->recipient = $this->get_option( 'recipient', '' !== $default ? $default : get_option( 'admin_email' ) );
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject() {
		return __( '[{site_name}] Nieuwe offerte-aanvraag {ref} van {customer_name}', 'borduur-patch-studio' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return __( 'Nieuwe offerte-aanvraag {ref}', 'borduur-patch-studio' );
	}

	/**
	 * Send.
	 *
	 * @param int $quote_id Quote id.
	 */
	public function trigger( $quote_id ) {
		$this->setup_locale();
		$q = Quotes::get( (int) $quote_id );
		if ( $q ) {
			$this->quote                           = $q;
			$this->object                          = $q;
			$this->placeholders['{ref}']           = $q['ref'];
			$this->placeholders['{customer_name}'] = $q['name'];
			$d                                     = $q['design'];
			$this->bps_args                        = array(
				'quote'        => $q,
				'design'       => $d,
				'design_rows'  => $d ? Quotes::design_rows( $d ) : array(),
				'preview_url'  => $d ? $d['preview_url'] : '',
				'download_url' => $d && $d['original'] ? Designs::original_download_url( (int) $d['id'] ) : '',
				'edit_url'     => admin_url( 'post.php?post=' . (int) $q['id'] . '&action=edit' ),
			);
			$this->bps_send();
		}
		$this->restore_locale();
	}

	/**
	 * Reply-To: the customer.
	 */
	public function get_headers() {
		$headers = (string) parent::get_headers();
		if ( $this->quote && is_email( $this->quote['email'] ) ) {
			$headers = preg_replace( '/^Reply-to:.*\r?\n/mi', '', $headers );
			$name    = trim( preg_replace( '/[\r\n<>",;]+/', ' ', $this->quote['name'] ) );
			$headers .= 'Reply-To: ' . ( '' !== $name ? $name . ' ' : '' ) . '<' . sanitize_email( $this->quote['email'] ) . ">\r\n";
		}
		return $headers;
	}

	/**
	 * Settings form (adds recipient).
	 */
	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields = array_merge(
			array(
				'enabled'   => $this->form_fields['enabled'] ?? array(),
				'recipient' => array(
					'title'       => __( 'Ontvanger(s)', 'borduur-patch-studio' ),
					'type'        => 'text',
					/* translators: %s: admin email */
					'description' => sprintf( __( 'Komma-gescheiden. Standaard: %s', 'borduur-patch-studio' ), '<code>' . esc_html( get_option( 'admin_email' ) ) . '</code>' ),
					'placeholder' => '',
					'default'     => '',
					'desc_tip'    => false,
				),
			),
			array_diff_key( $this->form_fields, array( 'enabled' => 1 ) )
		);
	}
}
