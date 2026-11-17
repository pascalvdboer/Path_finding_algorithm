<?php
/**
 * E-mail to the customer: quote request received.
 *
 * Override: yourtheme/woocommerce/emails/quote-customer.php
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Email $email
 * @var array     $quote
 * @var array     $design|null
 * @var array     $design_rows
 * @var string    $preview_url
 * @var array     $contact
 * @var string    $response
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p>
	<?php
	/* translators: %s: customer name */
	echo esc_html( sprintf( __( 'Dag %s,', 'borduur-patch-studio' ), $quote['name'] ) );
	?>
</p>
<p>
	<?php
	/* translators: %s: quote reference */
	echo esc_html( sprintf( __( 'Bedankt voor je aanvraag. We hebben ze goed ontvangen onder referentie %s.', 'borduur-patch-studio' ), $quote['ref'] ) );
	?>
	<?php if ( '' !== $response ) : ?>
		<?php echo esc_html( $response ); ?>
	<?php endif; ?>
</p>

<p style="font-weight:700;margin-bottom:6px;color:#0A1F44;"><?php esc_html_e( 'Wat gebeurt er nu?', 'borduur-patch-studio' ); ?></p>
<ol style="margin-top:0;padding-left:20px;">
	<li><?php esc_html_e( 'Onze vakman bekijkt je ontwerp en stuurt je een prijs op maat.', 'borduur-patch-studio' ); ?></li>
	<li><?php esc_html_e( 'Ga je akkoord, dan krijg je een digitale proef van je patch.', 'borduur-patch-studio' ); ?></li>
	<li><?php esc_html_e( 'Pas na jouw akkoord op de proef starten we de productie.', 'borduur-patch-studio' ); ?></li>
</ol>
<p style="color:#6B6B6B;font-size:13px;"><?php esc_html_e( 'Een offerte is vrijblijvend. Je zit pas vast als je de offerte en daarna de digitale proef goedkeurt.', 'borduur-patch-studio' ); ?></p>

<?php if ( ! empty( $preview_url ) ) : ?>
	<p style="text-align:center;margin:24px 0;">
		<img src="<?php echo esc_url( $preview_url ); ?>" alt="<?php esc_attr_e( 'Voorbeeld van je patch', 'borduur-patch-studio' ); ?>" width="200" style="display:inline-block;width:200px;max-width:100%;height:auto;border:0;" />
	</p>
<?php endif; ?>

<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;border:1px solid #E3EBF2;margin-bottom:20px;">
	<tbody>
		<?php foreach ( $design_rows as $row ) : ?>
			<tr>
				<th scope="row" style="text-align:left;border:1px solid #E3EBF2;width:38%;"><?php echo esc_html( $row[0] ); ?></th>
				<td style="text-align:left;border:1px solid #E3EBF2;"><?php echo esc_html( $row[1] ); ?></td>
			</tr>
		<?php endforeach; ?>
		<tr>
			<th scope="row" style="text-align:left;border:1px solid #E3EBF2;width:38%;"><?php esc_html_e( 'Aantal', 'borduur-patch-studio' ); ?></th>
			<td style="text-align:left;border:1px solid #E3EBF2;"><?php echo esc_html( (string) (int) $quote['qty'] ); ?></td>
		</tr>
		<?php if ( '' !== $quote['deadline'] ) : ?>
			<tr>
				<th scope="row" style="text-align:left;border:1px solid #E3EBF2;"><?php esc_html_e( 'Gewenste datum', 'borduur-patch-studio' ); ?></th>
				<td style="text-align:left;border:1px solid #E3EBF2;"><?php echo esc_html( wp_date( 'd-m-Y', strtotime( $quote['deadline'] . ' 12:00:00' ) ) ); ?></td>
			</tr>
		<?php endif; ?>
		<?php if ( '' !== $quote['message'] ) : ?>
			<tr>
				<th scope="row" style="text-align:left;border:1px solid #E3EBF2;"><?php esc_html_e( 'Je bericht', 'borduur-patch-studio' ); ?></th>
				<td style="text-align:left;border:1px solid #E3EBF2;white-space:pre-wrap;"><?php echo esc_html( $quote['message'] ); ?></td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>

<?php if ( ! empty( $contact['phone'] ) || ! empty( $contact['email'] ) ) : ?>
	<p>
		<?php esc_html_e( 'Iets vergeten of een vraag? Antwoord gewoon op deze e-mail of contacteer ons:', 'borduur-patch-studio' ); ?>
		<?php echo esc_html( trim( ( $contact['phone'] ?? '' ) . ( ! empty( $contact['phone'] ) && ! empty( $contact['email'] ) ? ' · ' : '' ) . ( $contact['email'] ?? '' ) ) ); ?>
	</p>
<?php endif; ?>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
