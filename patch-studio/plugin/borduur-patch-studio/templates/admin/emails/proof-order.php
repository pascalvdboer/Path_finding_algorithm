<?php
/**
 * E-mail to the customer: one digital proof for all patches of an order (atelier-flow-10).
 *
 * Override: yourtheme/woocommerce/admin/emails/proof-order.php
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Email $email
 * @var \WC_Order $order
 * @var array     $lines      [{ref, image, round, rows}].
 * @var string    $url        Link to the proof page.
 * @var string    $message    Message from the atelier.
 * @var string    $first_name
 * @var array     $contact
 */

defined( 'ABSPATH' ) || exit;

$lines      = isset( $lines ) && is_array( $lines ) ? $lines : array();
$first_name = isset( $first_name ) ? (string) $first_name : '';
$message    = isset( $message ) ? (string) $message : '';
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
	/* translators: 1: number of patches, 2: order number */
	echo esc_html( sprintf( _n( 'Hier is de digitale proef van %1$d patch uit je bestelling #%2$s. Kijk je alles even na?', 'Hier is de digitale proef van de %1$d patches uit je bestelling #%2$s. Kijk je alles even na?', count( $lines ), 'borduur-patch-studio' ), count( $lines ), $bps_onum ) );
	?>
</p>
<?php if ( '' !== trim( $message ) ) : ?>
	<div style="margin:0 0 20px;padding:14px 16px;border-left:4px solid #21A9E2;background:#F7FBFE;border-radius:6px;">
		<p style="margin:0 0 4px;font-weight:700;color:#0A1F44;"><?php esc_html_e( 'Bericht van ons atelier', 'borduur-patch-studio' ); ?></p>
		<p style="margin:0;color:#323232;"><?php echo nl2br( esc_html( $message ) ); ?></p>
	</div>
<?php endif; ?>
<?php foreach ( $lines as $bps_l ) : ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 16px;border:1px solid #E3EBF2;border-radius:6px;">
		<tr>
			<?php if ( '' !== $bps_l['image'] ) : ?>
				<td style="width:150px;padding:12px;vertical-align:top;">
					<img src="<?php echo esc_url( $bps_l['image'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: ref */ __( 'Digitale proef van patch %s', 'borduur-patch-studio' ), $bps_l['ref'] ) ); ?>" width="140" style="display:block;width:140px;max-width:100%;height:auto;border:0;" />
				</td>
			<?php endif; ?>
			<td style="padding:12px;vertical-align:top;">
				<p style="margin:0 0 6px;font-weight:700;color:#0A1F44;">
					<?php
					/* translators: %s: ref */
					echo esc_html( sprintf( __( 'Patch %s', 'borduur-patch-studio' ), $bps_l['ref'] ) );
					?>
				</p>
				<?php foreach ( (array) $bps_l['rows'] as $bps_row ) : ?>
					<div style="font-size:13px;color:#323232;"><strong><?php echo esc_html( (string) $bps_row['label'] ); ?>:</strong> <?php echo esc_html( (string) $bps_row['value'] ); ?></div>
				<?php endforeach; ?>
			</td>
		</tr>
	</table>
<?php endforeach; ?>
<p style="text-align:center;margin:24px 0;">
	<a href="<?php echo esc_url( (string) $url ); ?>" style="display:inline-block;min-width:240px;background:#0B74A6;color:#ffffff;text-decoration:none;font-weight:700;padding:14px 26px;border-radius:6px;"><?php esc_html_e( 'Proeven bekijken en goedkeuren', 'borduur-patch-studio' ); ?></a>
</p>
<p style="color:#6B6B6B;font-size:13px;"><?php esc_html_e( 'Je kunt per patch goedkeuren of een aanpassing vragen, of alles in één keer goedkeuren. Pas na jouw akkoord starten we de productie. Garen- en drukkleuren kunnen licht afwijken van je scherm.', 'borduur-patch-studio' ); ?></p>
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
