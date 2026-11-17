<?php
/**
 * Shared base for Patch Studio e-mails (WooCommerce look, customer language, our templates).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo\Emails;

use BorduurPatchStudio\Woo\Config;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WC_Email' ) ) {
	return;
}

/**
 * Base e-mail. Customer mails are sent in the language of the design/quote (nl-BE / fr-BE).
 */
abstract class Base extends \WC_Email {

	/** @var string Public locale of the recipient ('nl-BE' | 'fr-BE'), '' = site default. */
	protected $bps_locale = '';

	/** @var bool Whether we switched locale. */
	private $bps_switched = false;

	/** @var array Template variables for the current send. */
	protected $bps_args = array();

	/**
	 * Common setup; call from the child constructor before parent::__construct().
	 */
	protected function bps_setup(): void {
		$this->template_base = BPS_DIR . 'templates/';
		$this->placeholders  = array_merge(
			array(
				'{ref}'       => '',
				'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			),
			$this->placeholders
		);
	}

	/**
	 * Switch to the recipient language (instead of WooCommerce's site-locale switch).
	 */
	public function setup_locale() {
		if ( '' === $this->bps_locale ) {
			parent::setup_locale();
			return;
		}
		$this->bps_switched = (bool) switch_to_locale( Config::wp_locale( $this->bps_locale ) );
	}

	/**
	 * Restore the language.
	 */
	public function restore_locale() {
		if ( '' === $this->bps_locale ) {
			parent::restore_locale();
			return;
		}
		if ( $this->bps_switched ) {
			restore_previous_locale();
			$this->bps_switched = false;
		}
	}

	/**
	 * Send helper with the standard WooCommerce flow.
	 */
	protected function bps_send(): bool {
		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}
		return (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}

	/**
	 * Template variables (child adds its own).
	 */
	protected function template_args( bool $plain ): array {
		return array_merge(
			array(
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => ! $this->is_customer_email(),
				'plain_text'         => $plain,
				'email'              => $this,
				'locale'             => $this->bps_locale,
			),
			$this->bps_args
		);
	}

	/**
	 * HTML content.
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * Plain content.
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}
}
