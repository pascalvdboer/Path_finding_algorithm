<?php
/**
 * E-mail (plain text) to the shop: new quote request.
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var array     $quote
 * @var array     $design_rows
 * @var string    $download_url
 * @var string    $edit_url
 */

use BorduurPatchStudio\Woo\Config;

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
$bps_rows = array(
	__( 'Naam', 'borduur-patch-studio' )              => $quote['name'],
	__( 'E-mail', 'borduur-patch-studio' )            => $quote['email'],
	__( 'Telefoon', 'borduur-patch-studio' )          => $quote['phone'],
	__( 'Bedrijf', 'borduur-patch-studio' )           => $quote['company'],
	__( 'Btw-nummer', 'borduur-patch-studio' )        => $quote['vat'],
	__( 'Aantal', 'borduur-patch-studio' )            => (string) (int) $quote['qty'],
	__( 'Gewenste techniek', 'borduur-patch-studio' ) => '' !== $quote['technique'] ? Config::technique_label( $quote['technique'] ) : '',
	__( 'Gewenste datum', 'borduur-patch-studio' )    => $quote['deadline'],
);
foreach ( $bps_rows as $bps_label => $bps_value ) {
	if ( '' !== (string) $bps_value ) {
		echo esc_html( $bps_label . ': ' . $bps_value ) . "\n";
	}
}
echo "\n";
foreach ( $design_rows as $row ) {
	echo esc_html( $row[0] . ': ' . $row[1] ) . "\n";
}
if ( '' !== $quote['message'] ) {
	echo "\n" . esc_html( $quote['message'] ) . "\n";
}
echo "\n";
if ( '' !== $download_url ) {
	echo esc_html__( 'Originele bestand downloaden', 'borduur-patch-studio' ) . ': ' . esc_url_raw( $download_url ) . "\n";
}
echo esc_html__( 'Bekijk de aanvraag in WordPress', 'borduur-patch-studio' ) . ': ' . esc_url_raw( $edit_url ) . "\n\n";
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
