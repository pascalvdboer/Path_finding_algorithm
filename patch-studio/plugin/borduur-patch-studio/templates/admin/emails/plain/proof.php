<?php
/**
 * E-mail to the customer (plain text): digital proof.
 *
 * Override: yourtheme/woocommerce/admin/emails/plain/proof.php
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var array     $design
 * @var \WC_Order $order
 * @var int       $round
 * @var string    $image_url
 * @var string    $message
 * @var array     $rows
 * @var string    $approve_url
 * @var string    $changes_url
 * @var string    $first_name
 * @var array     $contact
 */

use BorduurPatchStudio\Admin\Order_Production as BPS_OP;

defined( 'ABSPATH' ) || exit;

$design     = isset( $design ) && is_array( $design ) ? $design : array( 'ref' => '' );
$rows       = isset( $rows ) && is_array( $rows ) ? $rows : array();
$first_name = isset( $first_name ) ? (string) $first_name : '';
$contact    = isset( $contact ) && is_array( $contact ) ? $contact : array();
$bps_onum   = ( isset( $order ) && $order instanceof \WC_Order ) ? (string) $order->get_order_number() : '';
$bps_phone  = (string) ( $contact['phone'] ?? '' );

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: %s: first name */
echo esc_html( '' !== $first_name ? sprintf( BPS_OP::l( __( 'Hallo %s,', 'borduur-patch-studio' ) ), $first_name ) : BPS_OP::l( __( 'Hallo,', 'borduur-patch-studio' ) ) ) . "\n\n";
/* translators: 1: design ref, 2: order number */
echo esc_html( sprintf( BPS_OP::l( __( 'Hier is de digitale proef van je patch %1$s (bestelling #%2$s). Kijk je alles even na?', 'borduur-patch-studio' ) ), (string) $design['ref'], $bps_onum ) ) . "\n\n";
if ( ! empty( $message ) ) {
	echo esc_html( BPS_OP::l( __( 'Bericht van ons atelier', 'borduur-patch-studio' ) ) ) . ":\n" . esc_html( (string) $message ) . "\n\n";
}
if ( ! empty( $image_url ) ) {
	echo esc_html( BPS_OP::l( __( 'Digitale proef van je patch', 'borduur-patch-studio' ) ) ) . ': ' . esc_url_raw( (string) $image_url ) . "\n\n";
}
foreach ( $rows as $bps_row ) {
	echo esc_html( (string) $bps_row['label'] ) . ': ' . esc_html( (string) $bps_row['value'] ) . "\n";
}
echo "\n" . esc_html( BPS_OP::l( __( 'Klopt alles? Dan starten we de productie zodra je goedkeurt.', 'borduur-patch-studio' ) ) ) . "\n\n";
echo esc_html( BPS_OP::l( __( 'Ik keur goed', 'borduur-patch-studio' ) ) ) . ":\n" . esc_url_raw( (string) ( $approve_url ?? '' ) ) . "\n\n";
echo esc_html( BPS_OP::l( __( 'Ik wil iets aanpassen', 'borduur-patch-studio' ) ) ) . ":\n" . esc_url_raw( (string) ( $changes_url ?? '' ) ) . "\n\n";
echo esc_html( BPS_OP::l( __( 'Pas na jouw akkoord starten we de productie. Garen- en drukkleuren kunnen licht afwijken van je scherm.', 'borduur-patch-studio' ) ) ) . "\n";
/* translators: %s: phone */
echo esc_html( '' !== $bps_phone ? sprintf( BPS_OP::l( __( 'Vragen? Antwoord gewoon op deze e-mail of bel %s.', 'borduur-patch-studio' ) ), $bps_phone ) : BPS_OP::l( __( 'Vragen? Antwoord gewoon op deze e-mail.', 'borduur-patch-studio' ) ) ) . "\n\n";
if ( ! empty( $additional_content ) ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
