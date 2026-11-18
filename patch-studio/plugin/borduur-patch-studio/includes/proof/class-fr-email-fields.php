<?php
/**
 * French subject / heading / additional content for customer proof e-mails (i18n-a11y-7). A custom Dutch text
 * no longer goes to French customers: they get the French field when it is filled in, otherwise the translated
 * default text.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Proof;

defined( 'ABSPATH' ) || exit;

/**
 * For classes extending Woo\Emails\Base (uses $bps_locale).
 */
trait Fr_Email_Fields {

	/**
	 * Whether setup_locale() switched through Proof\Lang.
	 *
	 * @var bool
	 */
	private $bps_lang_switched = false;

	/**
	 * Is this send in French?
	 */
	protected function bps_is_fr(): bool {
		return 0 === stripos( (string) $this->bps_locale, 'fr' );
	}

	/**
	 * Customer language through Proof\Lang (works without the WordPress core language pack).
	 */
	public function setup_locale() {
		if ( '' === (string) $this->bps_locale ) {
			parent::setup_locale();
			return;
		}
		if ( ! $this->bps_lang_switched ) {
			Lang::switch_to( $this->bps_is_fr() ? 'fr' : 'nl' );
			$this->bps_lang_switched = true;
		}
	}

	/**
	 * Restore the language.
	 */
	public function restore_locale() {
		if ( '' === (string) $this->bps_locale ) {
			parent::restore_locale();
			return;
		}
		if ( $this->bps_lang_switched ) {
			Lang::restore();
			$this->bps_lang_switched = false;
		}
	}

	/**
	 * Settings fields: the parent fields plus the French variants right after their Dutch field.
	 */
	public function init_form_fields() {
		parent::init_form_fields();
		$extra = array(
			'subject'            => array(
				'subject_fr',
				__( 'Onderwerp (Frans)', 'borduur-patch-studio' ),
				'text',
			),
			'heading'            => array(
				'heading_fr',
				__( 'Koptekst (Frans)', 'borduur-patch-studio' ),
				'text',
			),
			'additional_content' => array(
				'additional_content_fr',
				__( 'Extra tekst (Frans)', 'borduur-patch-studio' ),
				'textarea',
			),
		);
		$out   = array();
		foreach ( (array) $this->form_fields as $key => $field ) {
			$out[ $key ] = $field;
			if ( isset( $extra[ $key ] ) ) {
				$out[ $extra[ $key ][0] ] = array(
					'title'       => $extra[ $key ][1],
					'type'        => $extra[ $key ][2],
					'desc_tip'    => true,
					'description' => __( 'Voor klanten die in het Frans bestelden. Leeg = de vertaalde standaardtekst (niet je Nederlandse tekst).', 'borduur-patch-studio' ),
					'placeholder' => '',
					'default'     => '',
					'css'         => 'textarea' === $extra[ $key ][2] ? 'width:400px; height: 75px;' : '',
				);
			}
		}
		$this->form_fields = $out;
	}

	/**
	 * Subject in the recipient's language.
	 */
	public function get_subject() {
		if ( ! $this->bps_is_fr() ) {
			return parent::get_subject();
		}
		$custom = trim( (string) $this->get_option( 'subject_fr', '' ) );
		$text   = '' !== $custom ? $custom : $this->get_default_subject();
		return apply_filters( 'woocommerce_email_subject_' . $this->id, $this->format_string( $text ), $this->object, $this );
	}

	/**
	 * Heading in the recipient's language.
	 */
	public function get_heading() {
		if ( ! $this->bps_is_fr() ) {
			return parent::get_heading();
		}
		$custom = trim( (string) $this->get_option( 'heading_fr', '' ) );
		$text   = '' !== $custom ? $custom : $this->get_default_heading();
		return apply_filters( 'woocommerce_email_heading_' . $this->id, $this->format_string( $text ), $this->object, $this );
	}

	/**
	 * Additional content in the recipient's language.
	 */
	public function get_additional_content() {
		if ( ! $this->bps_is_fr() ) {
			return parent::get_additional_content();
		}
		$custom = trim( (string) $this->get_option( 'additional_content_fr', '' ) );
		$text   = '' !== $custom ? $custom : ( method_exists( $this, 'get_default_additional_content' ) ? (string) $this->get_default_additional_content() : '' );
		return apply_filters( 'woocommerce_email_additional_content_' . $this->id, $this->format_string( $text ), $this->object, $this );
	}
}
