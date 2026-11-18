<?php
/**
 * E-mail to the shop (plain text): the customer answered a digital proof (one line, a whole order, or a
 * cancellation request).
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var array     $design
 * @var \WC_Order $order
 * @var string    $action    approve|changes|cancel
 * @var string    $note
 * @var int       $round
 * @var array     $lines     Whole-order answer: [{design, action, note, round}].
 * @var string    $order_url
 * @var string    $customer
 */

use BorduurPatchStudio\Proof\Lang;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text e-mail body, not HTML: Lang::plain() strips tags and decodes entities.
$design   = isset( $design ) && is_array( $design ) ? $design : array( 'ref' => '' );
$customer = isset( $customer ) && '' !== (string) $customer ? (string) $customer : __( 'De klant', 'borduur-patch-studio' );
$round    = isset( $round ) ? (int) $round : 1;
$lines    = isset( $lines ) && is_array( $lines ) ? $lines : array();
$bps_action = isset( $action ) ? (string) $action : 'approve';

echo '= ' . Lang::plain( $email_heading ) . " =\n\n";
if ( $lines ) {
	/* translators: %s: customer */
	echo Lang::plain( sprintf( __( '%s beantwoordde de proef voor de hele bestelling:', 'borduur-patch-studio' ), $customer ) ) . "\n\n";
	foreach ( $lines as $bps_l ) {
		$bps_word = 'changes' === ( $bps_l['action'] ?? '' ) ? __( 'aanpassing gevraagd', 'borduur-patch-studio' ) : __( 'goedgekeurd', 'borduur-patch-studio' );
		echo '- ' . Lang::plain( (string) ( $bps_l['design']['ref'] ?? '' ) . ': ' . $bps_word ) . "\n";
		if ( ! empty( $bps_l['note'] ) ) {
			echo '  ' . Lang::plain( (string) $bps_l['note'] ) . "\n";
		}
	}
	echo "\n";
} elseif ( 'cancel' === $bps_action ) {
	/* translators: 1: customer, 2: ref */
	echo Lang::plain( sprintf( __( '%1$s vraagt via de proefpagina om de bestelling te annuleren (patch %2$s, nog niet goedgekeurd). De bestelling is al betaald: beslis zelf over annulering en terugbetaling.', 'borduur-patch-studio' ), $customer, (string) $design['ref'] ) ) . "\n\n";
} else {
	echo Lang::plain(
		'changes' === $bps_action
			/* translators: 1: customer, 2: ref, 3: round */
			? sprintf( __( '%1$s vraagt een aanpassing aan de proef van patch %2$s (ronde %3$d).', 'borduur-patch-studio' ), $customer, (string) $design['ref'], $round )
			/* translators: 1: customer, 2: ref, 3: round */
			: sprintf( __( '%1$s keurde de proef van patch %2$s goed (ronde %3$d). Deze patch mag in productie.', 'borduur-patch-studio' ), $customer, (string) $design['ref'], $round )
	) . "\n\n";
	if ( ! empty( $note ) ) {
		echo Lang::plain( __( 'Opmerking van de klant', 'borduur-patch-studio' ) ) . ":\n" . Lang::plain( (string) $note ) . "\n\n";
	}
}
echo esc_url_raw( (string) ( $order_url ?? '' ) ) . "\n\n";
if ( ! empty( $additional_content ) ) {
	echo Lang::plain( wptexturize( $additional_content ) ) . "\n\n";
}
echo Lang::plain( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
