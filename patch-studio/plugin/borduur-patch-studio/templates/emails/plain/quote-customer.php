<?php
/**
 * E-mail (plain text) to the customer: quote request received.
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var array     $quote
 * @var array     $design_rows
 * @var array     $contact
 * @var string    $response
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: %s: customer name */
echo esc_html( sprintf( __( 'Dag %s,', 'borduur-patch-studio' ), $quote['name'] ) ) . "\n\n";
/* translators: %s: quote reference */
echo esc_html( sprintf( __( 'Bedankt voor je aanvraag. We hebben ze goed ontvangen onder referentie %s.', 'borduur-patch-studio' ), $quote['ref'] ) ) . ' ' . esc_html( $response ) . "\n\n";
echo esc_html__( 'Wat gebeurt er nu?', 'borduur-patch-studio' ) . "\n";
echo '1. ' . esc_html__( 'Onze vakman bekijkt je ontwerp en stuurt je een prijs op maat.', 'borduur-patch-studio' ) . "\n";
echo '2. ' . esc_html__( 'Ga je akkoord, dan krijg je een digitale proef van je patch.', 'borduur-patch-studio' ) . "\n";
echo '3. ' . esc_html__( 'Pas na jouw akkoord op de proef starten we de productie.', 'borduur-patch-studio' ) . "\n\n";
echo esc_html__( 'Een offerte is vrijblijvend. Je zit pas vast als je de offerte en daarna de digitale proef goedkeurt.', 'borduur-patch-studio' ) . "\n\n";
foreach ( $design_rows as $row ) {
	echo esc_html( $row[0] . ': ' . $row[1] ) . "\n";
}
echo esc_html( __( 'Aantal', 'borduur-patch-studio' ) . ': ' . (int) $quote['qty'] ) . "\n";
if ( '' !== $quote['message'] ) {
	echo esc_html( __( 'Je bericht', 'borduur-patch-studio' ) . ': ' . $quote['message'] ) . "\n";
}
echo "\n";
if ( ! empty( $contact['phone'] ) || ! empty( $contact['email'] ) ) {
	echo esc_html__( 'Iets vergeten of een vraag? Antwoord gewoon op deze e-mail of contacteer ons:', 'borduur-patch-studio' ) . ' ' . esc_html( trim( ( $contact['phone'] ?? '' ) . ' ' . ( $contact['email'] ?? '' ) ) ) . "\n\n";
}
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
