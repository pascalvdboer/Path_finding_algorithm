<?php
/**
 * E-mail (plain text): the single reminder about a saved design.
 *
 * Override: yourtheme/woocommerce/emails/plain/design-reminder.php
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Email $email
 * @var array     $design
 * @var array     $design_rows
 * @var string    $restore_url
 * @var string    $unsubscribe_url
 * @var string    $account_url
 * @var int       $qty
 * @var array     $contact
 * @var string    $locale
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
esc_html_e( 'Hallo,', 'borduur-patch-studio' );
echo "\n\n";
/* translators: %s: design reference */
echo esc_html( sprintf( __( 'Een paar dagen geleden bewaarde je patch-ontwerp %s en je vroeg ons om je er één keer aan te herinneren. Het staat nog precies zoals je het achterliet.', 'borduur-patch-studio' ), $design['ref'] ) ) . "\n\n";
foreach ( $design_rows as $row ) {
	echo esc_html( $row[0] . ': ' . $row[1] ) . "\n";
}
echo esc_html( __( 'Aantal', 'borduur-patch-studio' ) . ': ' . (int) $qty ) . "\n\n";
echo esc_html__( 'Verder met mijn ontwerp', 'borduur-patch-studio' ) . ': ' . esc_url_raw( $restore_url ) . "\n";
if ( ! empty( $account_url ) ) {
	echo esc_html__( 'Al je ontwerpen in Mijn account', 'borduur-patch-studio' ) . ': ' . esc_url_raw( $account_url ) . "\n";
}
echo "\n";
esc_html_e( 'Je prijs wordt opnieuw berekend wanneer je verdergaat. Je krijgt altijd eerst een digitale proef ter goedkeuring vóór we starten.', 'borduur-patch-studio' );
echo "\n\n";
esc_html_e( 'Dit is de enige herinnering die we je over dit ontwerp sturen.', 'borduur-patch-studio' );
if ( ! empty( $unsubscribe_url ) ) {
	echo "\n" . esc_html__( 'Geen herinneringen meer ontvangen', 'borduur-patch-studio' ) . ': ' . esc_url_raw( $unsubscribe_url );
}
echo "\n\n";
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
