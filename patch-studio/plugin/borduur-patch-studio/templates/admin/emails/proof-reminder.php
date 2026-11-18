<?php
/**
 * E-mail to the customer: friendly reminder that a digital proof waits for an answer (atelier-flow-9).
 *
 * Override: yourtheme/woocommerce/admin/emails/proof-reminder.php
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Email $email
 * @var \WC_Order $order
 * @var array     $cards      [{ref, image, url, round, sent}].
 * @var string    $first_name
 * @var array     $contact
 */

defined( 'ABSPATH' ) || exit;

$cards      = isset( $cards ) && is_array( $cards ) ? $cards : array();
$first_name = isset( $first_name ) ? (string) $first_name : '';
$contact    = isset( $contact ) && is_array( $contact ) ? $contact : array();
$bps_onum   = ( isset( $order ) && $order instanceof \WC_Order ) ? (string) $order->get_order_number() : '';
$bps_phone  = (string) ( $contact['phone'] ?? '' );

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p>
	<?php
	/* translators: %s: customer first name */
	echo esc_html( '' !== $first_name ? sprintf( __( 'Hallo %s,', 'borduur-patch-studio' ), $first_name ) : __( 'Hallo,', 'borduur-patch-studio' ) );
	?>
</p>
<p>
	<?php
	/* translators: %s: order number */
	echo esc_html( sprintf( _n( 'De digitale proef voor je bestelling #%s wacht nog op je antwoord. Zodra je goedkeurt, starten we de productie.', 'De digitale proeven voor je bestelling #%s wachten nog op je antwoord. Zodra je goedkeurt, starten we de productie.', count( $cards ), 'borduur-patch-studio' ), $bps_onum ) );
	?>
</p>
<?php foreach ( $cards as $bps_c ) : ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 20px;border:1px solid #E3EBF2;border-radius:6px;">
		<tr>
			<?php if ( '' !== $bps_c['image'] ) : ?>
				<td style="width:120px;padding:12px;vertical-align:top;">
					<img src="<?php echo esc_url( $bps_c['image'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: ref */ __( 'Digitale proef van patch %s', 'borduur-patch-studio' ), $bps_c['ref'] ) ); ?>" width="110" style="display:block;width:110px;max-width:100%;height:auto;border:0;" />
				</td>
			<?php endif; ?>
			<td style="padding:12px;vertical-align:top;">
				<p style="margin:0 0 10px;font-weight:700;color:#0A1F44;">
					<?php
					/* translators: %s: ref */
					echo esc_html( sprintf( __( 'Patch %s', 'borduur-patch-studio' ), $bps_c['ref'] ) );
					?>
				</p>
				<a href="<?php echo esc_url( $bps_c['url'] ); ?>" style="display:inline-block;background:#0B74A6;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:6px;"><?php esc_html_e( 'Proef bekijken', 'borduur-patch-studio' ); ?></a>
			</td>
		</tr>
	</table>
<?php endforeach; ?>
<p style="color:#6B6B6B;font-size:13px;">
	<?php
	echo esc_html(
		'' !== $bps_phone
			/* translators: %s: phone number */
			? sprintf( __( 'Vragen? Antwoord gewoon op deze e-mail of bel %s.', 'borduur-patch-studio' ), $bps_phone )
			: __( 'Vragen? Antwoord gewoon op deze e-mail.', 'borduur-patch-studio' )
	);
	?>
</p>
<?php
if ( ! empty( $additional_content ) ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
