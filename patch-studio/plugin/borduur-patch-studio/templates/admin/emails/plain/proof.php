<?php
/**
 * E-mail to the customer (plain text): digital proof. Text goes through Lang::plain() (no HTML entities, so
 * "D'Hondt" stays "D'Hondt"; i18n-a11y-2) and links through esc_url_raw().
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
 * @var string    $full_url
 * @var string    $message
 * @var array     $rows
 * @var string    $approve_url
 * @var string    $changes_url
 * @var string    $first_name
 * @var array     $contact
 */

use BorduurPatchStudio\Proof\Lang;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text e-mail body, not HTML: Lang::plain() strips tags and decodes entities.
$design     = isset( $design ) && is_array( $design ) ? $design : array( 'ref' => '' );
$rows       = isset( $rows ) && is_array( $rows ) ? $rows : array();
$first_name = isset( $first_name ) ? (string) $first_name : '';
$contact    = isset( $contact ) && is_array( $contact ) ? $contact : array();
$bps_onum   = ( isset( $order ) && $order instanceof \WC_Order ) ? (string) $order->get_order_number() : '';
$bps_phone  = (string) ( $contact['phone'] ?? '' );
$bps_image  = ! empty( $full_url ) ? (string) $full_url : (string) ( $image_url ?? '' );

echo '= ' . Lang::plain( $email_heading ) . " =\n\n";
/* translators: %s: first name */
echo Lang::plain( '' !== $first_name ? sprintf( __( 'Hallo %s,', 'borduur-patch-studio' ), $first_name ) : __( 'Hallo,', 'borduur-patch-studio' ) ) . "\n\n";
/* translators: 1: design ref, 2: order number */
echo Lang::plain( sprintf( __( 'Hier is de digitale proef van je patch %1$s (bestelling #%2$s). Kijk je alles even na?', 'borduur-patch-studio' ), (string) $design['ref'], $bps_onum ) ) . "\n\n";
if ( ! empty( $message ) ) {
	echo Lang::plain( __( 'Bericht van ons atelier', 'borduur-patch-studio' ) ) . ":\n" . Lang::plain( (string) $message ) . "\n\n";
}
if ( '' !== $bps_image ) {
	echo Lang::plain( __( 'Digitale proef van je patch', 'borduur-patch-studio' ) ) . ': ' . esc_url_raw( $bps_image ) . "\n\n";
}
foreach ( $rows as $bps_row ) {
	echo Lang::plain( (string) $bps_row['label'] ) . ': ' . Lang::plain( (string) $bps_row['value'] ) . "\n";
}
echo "\n" . Lang::plain( __( 'Klopt alles? Dan starten we de productie zodra je goedkeurt.', 'borduur-patch-studio' ) ) . "\n\n";
echo Lang::plain( __( 'Ik keur goed', 'borduur-patch-studio' ) ) . ":\n" . esc_url_raw( (string) ( $approve_url ?? '' ) ) . "\n\n";
echo Lang::plain( __( 'Ik wil iets aanpassen', 'borduur-patch-studio' ) ) . ":\n" . esc_url_raw( (string) ( $changes_url ?? '' ) ) . "\n\n";
echo Lang::plain( __( 'Pas na jouw akkoord starten we de productie. Garen- en drukkleuren kunnen licht afwijken van je scherm.', 'borduur-patch-studio' ) ) . "\n";
/* translators: %s: phone */
echo Lang::plain( '' !== $bps_phone ? sprintf( __( 'Vragen? Antwoord gewoon op deze e-mail of bel %s.', 'borduur-patch-studio' ), $bps_phone ) : __( 'Vragen? Antwoord gewoon op deze e-mail.', 'borduur-patch-studio' ) ) . "\n\n";
if ( ! empty( $additional_content ) ) {
	echo Lang::plain( wptexturize( $additional_content ) ) . "\n\n";
}
echo Lang::plain( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
