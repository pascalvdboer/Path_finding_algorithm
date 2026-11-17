<?php
/**
 * E-mail to the shop (plain text): the customer answered a digital proof.
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var array     $design
 * @var \WC_Order $order
 * @var string    $action
 * @var string    $note
 * @var int       $round
 * @var string    $order_url
 * @var string    $customer
 */

defined( 'ABSPATH' ) || exit;

$design   = isset( $design ) && is_array( $design ) ? $design : array( 'ref' => '' );
$customer = isset( $customer ) && '' !== (string) $customer ? (string) $customer : __( 'De klant', 'borduur-patch-studio' );
$round    = isset( $round ) ? (int) $round : 1;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
echo esc_html(
	'changes' === ( $action ?? '' )
		/* translators: 1: customer, 2: ref, 3: round */
		? sprintf( __( '%1$s vraagt een aanpassing aan de proef van patch %2$s (ronde %3$d).', 'borduur-patch-studio' ), $customer, (string) $design['ref'], $round )
		/* translators: 1: customer, 2: ref, 3: round */
		: sprintf( __( '%1$s keurde de proef van patch %2$s goed (ronde %3$d). Deze patch mag in productie.', 'borduur-patch-studio' ), $customer, (string) $design['ref'], $round )
) . "\n\n";
if ( ! empty( $note ) ) {
	echo esc_html__( 'Opmerking van de klant', 'borduur-patch-studio' ) . ":\n" . esc_html( (string) $note ) . "\n\n";
}
echo esc_url_raw( (string) ( $order_url ?? '' ) ) . "\n\n";
if ( ! empty( $additional_content ) ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
