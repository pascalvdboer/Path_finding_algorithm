<?php
/**
 * E-mail to the customer (plain text): one digital proof for all patches of an order.
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Order $order
 * @var array     $lines      [{ref, image, round, rows}].
 * @var string    $url
 * @var string    $message
 * @var string    $first_name
 * @var array     $contact
 */

use BorduurPatchStudio\Proof\Lang;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text e-mail body, not HTML: Lang::plain() strips tags and decodes entities.
$lines      = isset( $lines ) && is_array( $lines ) ? $lines : array();
$first_name = isset( $first_name ) ? (string) $first_name : '';
$contact    = isset( $contact ) && is_array( $contact ) ? $contact : array();
$bps_onum   = ( isset( $order ) && $order instanceof \WC_Order ) ? (string) $order->get_order_number() : '';
$bps_phone  = (string) ( $contact['phone'] ?? '' );

echo '= ' . Lang::plain( $email_heading ) . " =\n\n";
/* translators: %s: first name */
echo Lang::plain( '' !== $first_name ? sprintf( __( 'Hallo %s,', 'borduur-patch-studio' ), $first_name ) : __( 'Hallo,', 'borduur-patch-studio' ) ) . "\n\n";
/* translators: 1: number of patches, 2: order number */
echo Lang::plain( sprintf( _n( 'Hier is de digitale proef van %1$d patch uit je bestelling #%2$s. Kijk je alles even na?', 'Hier is de digitale proef van de %1$d patches uit je bestelling #%2$s. Kijk je alles even na?', count( $lines ), 'borduur-patch-studio' ), count( $lines ), $bps_onum ) ) . "\n\n";
if ( ! empty( $message ) ) {
	echo Lang::plain( __( 'Bericht van ons atelier', 'borduur-patch-studio' ) ) . ":\n" . Lang::plain( (string) $message ) . "\n\n";
}
foreach ( $lines as $bps_l ) {
	/* translators: %s: ref */
	echo Lang::plain( sprintf( __( 'Patch %s', 'borduur-patch-studio' ), $bps_l['ref'] ) ) . "\n";
	foreach ( (array) $bps_l['rows'] as $bps_row ) {
		echo '  ' . Lang::plain( (string) $bps_row['label'] ) . ': ' . Lang::plain( (string) $bps_row['value'] ) . "\n";
	}
	echo "\n";
}
echo Lang::plain( __( 'Proeven bekijken en goedkeuren', 'borduur-patch-studio' ) ) . ":\n" . esc_url_raw( (string) ( $url ?? '' ) ) . "\n\n";
/* translators: %s: phone */
echo Lang::plain( '' !== $bps_phone ? sprintf( __( 'Vragen? Antwoord gewoon op deze e-mail of bel %s.', 'borduur-patch-studio' ), $bps_phone ) : __( 'Vragen? Antwoord gewoon op deze e-mail.', 'borduur-patch-studio' ) ) . "\n\n";
if ( ! empty( $additional_content ) ) {
	echo Lang::plain( wptexturize( $additional_content ) ) . "\n\n";
}
echo Lang::plain( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
