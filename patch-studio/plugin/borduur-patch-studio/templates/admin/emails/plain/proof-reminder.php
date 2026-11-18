<?php
/**
 * E-mail to the customer (plain text): reminder for a digital proof.
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Order $order
 * @var array     $cards      [{ref, image, url, round, sent}].
 * @var string    $first_name
 * @var array     $contact
 */

use BorduurPatchStudio\Proof\Lang;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text e-mail body, not HTML: Lang::plain() strips tags and decodes entities.
$cards      = isset( $cards ) && is_array( $cards ) ? $cards : array();
$first_name = isset( $first_name ) ? (string) $first_name : '';
$contact    = isset( $contact ) && is_array( $contact ) ? $contact : array();
$bps_onum   = ( isset( $order ) && $order instanceof \WC_Order ) ? (string) $order->get_order_number() : '';
$bps_phone  = (string) ( $contact['phone'] ?? '' );

echo '= ' . Lang::plain( $email_heading ) . " =\n\n";
/* translators: %s: first name */
echo Lang::plain( '' !== $first_name ? sprintf( __( 'Hallo %s,', 'borduur-patch-studio' ), $first_name ) : __( 'Hallo,', 'borduur-patch-studio' ) ) . "\n\n";
/* translators: %s: order number */
echo Lang::plain( sprintf( _n( 'De digitale proef voor je bestelling #%s wacht nog op je antwoord. Zodra je goedkeurt, starten we de productie.', 'De digitale proeven voor je bestelling #%s wachten nog op je antwoord. Zodra je goedkeurt, starten we de productie.', count( $cards ), 'borduur-patch-studio' ), $bps_onum ) ) . "\n\n";
foreach ( $cards as $bps_c ) {
	/* translators: %s: ref */
	echo Lang::plain( sprintf( __( 'Patch %s', 'borduur-patch-studio' ), $bps_c['ref'] ) ) . "\n" . Lang::plain( __( 'Proef bekijken', 'borduur-patch-studio' ) ) . ': ' . esc_url_raw( $bps_c['url'] ) . "\n\n";
}
/* translators: %s: phone */
echo Lang::plain( '' !== $bps_phone ? sprintf( __( 'Vragen? Antwoord gewoon op deze e-mail of bel %s.', 'borduur-patch-studio' ), $bps_phone ) : __( 'Vragen? Antwoord gewoon op deze e-mail.', 'borduur-patch-studio' ) ) . "\n\n";
if ( ! empty( $additional_content ) ) {
	echo Lang::plain( wptexturize( $additional_content ) ) . "\n\n";
}
echo Lang::plain( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
