<?php
/**
 * E-mail to the shop: new quote request.
 *
 * Override: yourtheme/woocommerce/emails/quote-admin.php
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Email $email
 * @var array     $quote
 * @var array     $design|null
 * @var array     $design_rows
 * @var string    $preview_url
 * @var string    $download_url
 * @var string    $edit_url
 */

use BorduurPatchStudio\Woo\Config;

defined( 'ABSPATH' ) || exit;

$bps_contact_rows = array(
	__( 'Naam', 'borduur-patch-studio' )           => $quote['name'],
	__( 'E-mail', 'borduur-patch-studio' )         => $quote['email'],
	__( 'Telefoon', 'borduur-patch-studio' )       => $quote['phone'],
	__( 'Bedrijf', 'borduur-patch-studio' )        => $quote['company'],
	__( 'Btw-nummer', 'borduur-patch-studio' )     => $quote['vat'],
	__( 'Aantal', 'borduur-patch-studio' )         => (string) (int) $quote['qty'],
	__( 'Gewenste techniek', 'borduur-patch-studio' ) => '' !== $quote['technique'] ? Config::technique_label( $quote['technique'] ) : '',
	__( 'Gewenste datum', 'borduur-patch-studio' ) => '' !== $quote['deadline'] ? wp_date( 'd-m-Y', strtotime( $quote['deadline'] . ' 12:00:00' ) ) : '',
	__( 'Taal klant', 'borduur-patch-studio' )     => 'fr' === Config::lang( $quote['locale'] ) ? 'Français' : 'Nederlands',
);

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p>
	<?php
	/* translators: 1: customer name, 2: quote reference */
	echo esc_html( sprintf( __( '%1$s vraagt een prijs op maat (%2$s). Antwoord op deze e-mail om de klant rechtstreeks te bereiken.', 'borduur-patch-studio' ), $quote['name'], $quote['ref'] ) );
	?>
</p>

<?php if ( ! empty( $preview_url ) ) : ?>
	<p style="text-align:center;margin:20px 0;">
		<img src="<?php echo esc_url( $preview_url ); ?>" alt="" width="220" style="display:inline-block;width:220px;max-width:100%;height:auto;border:0;" />
	</p>
<?php endif; ?>

<h2><?php esc_html_e( 'Klant', 'borduur-patch-studio' ); ?></h2>
<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;border:1px solid #E3EBF2;margin-bottom:20px;">
	<tbody>
		<?php foreach ( $bps_contact_rows as $bps_label => $bps_value ) : ?>
			<?php if ( '' === (string) $bps_value ) { continue; } ?>
			<tr>
				<th scope="row" style="text-align:left;border:1px solid #E3EBF2;width:38%;"><?php echo esc_html( $bps_label ); ?></th>
				<td style="text-align:left;border:1px solid #E3EBF2;"><?php echo esc_html( $bps_value ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php if ( $design_rows ) : ?>
	<h2><?php esc_html_e( 'Ontwerp', 'borduur-patch-studio' ); ?></h2>
	<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;border:1px solid #E3EBF2;margin-bottom:20px;">
		<tbody>
			<?php foreach ( $design_rows as $row ) : ?>
				<tr>
					<th scope="row" style="text-align:left;border:1px solid #E3EBF2;width:38%;"><?php echo esc_html( $row[0] ); ?></th>
					<td style="text-align:left;border:1px solid #E3EBF2;"><?php echo esc_html( $row[1] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php if ( '' !== $quote['message'] ) : ?>
	<h2><?php esc_html_e( 'Bericht', 'borduur-patch-studio' ); ?></h2>
	<p style="white-space:pre-wrap;"><?php echo esc_html( $quote['message'] ); ?></p>
<?php endif; ?>

<p style="margin:24px 0;">
	<?php if ( '' !== $download_url ) : ?>
		<a href="<?php echo esc_url( $download_url ); ?>" style="display:inline-block;background:#0B74A6;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:6px;margin:0 8px 8px 0;"><?php esc_html_e( 'Originele bestand downloaden', 'borduur-patch-studio' ); ?></a>
	<?php endif; ?>
	<a href="<?php echo esc_url( $edit_url ); ?>" style="display:inline-block;background:#0A1F44;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:6px;margin:0 8px 8px 0;"><?php esc_html_e( 'Bekijk de aanvraag in WordPress', 'borduur-patch-studio' ); ?></a>
</p>
<p style="color:#6B6B6B;font-size:12px;"><?php esc_html_e( 'De downloadknop werkt alleen als je in WordPress bent aangemeld.', 'borduur-patch-studio' ); ?></p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
