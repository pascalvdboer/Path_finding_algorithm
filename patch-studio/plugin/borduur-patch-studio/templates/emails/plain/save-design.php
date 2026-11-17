<?php
/**
 * E-mail (plain text): saved design.
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Email $email
 * @var array     $design
 * @var array     $design_rows
 * @var string    $restore_url
 * @var array     $quote|null
 * @var int       $qty
 * @var int       $days
 * @var array     $contact
 * @var string    $locale
 */

use BorduurPatchStudio\Woo\Config;

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
esc_html_e( 'Hallo,', 'borduur-patch-studio' );
echo "\n\n";
/* translators: %s: design reference */
echo esc_html( sprintf( __( 'Hier is je patch-ontwerp %s. Je kan er op elk moment mee verder gaan: alles staat nog zoals je het achterliet.', 'borduur-patch-studio' ), $design['ref'] ) ) . "\n\n";
foreach ( $design_rows as $row ) {
	echo esc_html( $row[0] . ': ' . $row[1] ) . "\n";
}
echo esc_html( __( 'Aantal', 'borduur-patch-studio' ) . ': ' . (int) $qty ) . "\n";
if ( $quote ) {
	/* translators: 1: total, 2: price per piece */
	echo esc_html( __( 'Prijs', 'borduur-patch-studio' ) . ': ' . sprintf( __( '%1$s (%2$s per stuk, alles inbegrepen, incl. btw)', 'borduur-patch-studio' ), Config::money( (float) $quote['subtotalIncl'], $locale ), Config::money( (float) $quote['perPieceAllInIncl'], $locale ) ) ) . "\n";
} else {
	echo esc_html( __( 'Prijs', 'borduur-patch-studio' ) . ': ' . __( 'Prijs op aanvraag: we maken graag een offerte op maat.', 'borduur-patch-studio' ) ) . "\n";
}
echo "\n" . esc_html__( 'Verder met mijn ontwerp', 'borduur-patch-studio' ) . ': ' . esc_url_raw( $restore_url ) . "\n\n";
/* translators: %d: number of days */
echo esc_html( sprintf( __( 'De link werkt %d dagen na je laatste bezoek. We mailen je enkel deze link: geen nieuwsbrief.', 'borduur-patch-studio' ), (int) $days ) ) . "\n";
esc_html_e( 'Je krijgt altijd eerst een digitale proef ter goedkeuring vóór we starten. Bestellen kan vanaf 1 stuk.', 'borduur-patch-studio' );
echo "\n\n";
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
