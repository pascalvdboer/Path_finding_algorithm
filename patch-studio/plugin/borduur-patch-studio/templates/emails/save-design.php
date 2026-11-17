<?php
/**
 * E-mail: saved design ("Bewaar & mail mijn ontwerp").
 *
 * Override: yourtheme/woocommerce/emails/save-design.php
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Email $email
 * @var array     $design
 * @var array     $design_rows
 * @var string    $preview_url
 * @var string    $restore_url
 * @var array     $quote|null
 * @var int       $qty
 * @var int       $days
 * @var array     $contact
 * @var string    $locale
 */

use BorduurPatchStudio\Woo\Config;

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php esc_html_e( 'Hallo,', 'borduur-patch-studio' ); ?></p>
<p>
	<?php
	/* translators: %s: design reference */
	echo esc_html( sprintf( __( 'Hier is je patch-ontwerp %s. Je kan er op elk moment mee verder gaan: alles staat nog zoals je het achterliet.', 'borduur-patch-studio' ), $design['ref'] ) );
	?>
</p>

<?php if ( ! empty( $preview_url ) ) : ?>
	<p style="text-align:center;margin:24px 0;">
		<img src="<?php echo esc_url( $preview_url ); ?>" alt="<?php esc_attr_e( 'Voorbeeld van je patch', 'borduur-patch-studio' ); ?>" width="240" style="display:inline-block;width:240px;max-width:100%;height:auto;border:0;" />
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
			<th scope="row" style="text-align:left;border:1px solid #E3EBF2;"><?php esc_html_e( 'Aantal', 'borduur-patch-studio' ); ?></th>
			<td style="text-align:left;border:1px solid #E3EBF2;"><?php echo esc_html( (string) (int) $qty ); ?></td>
		</tr>
		<tr>
			<th scope="row" style="text-align:left;border:1px solid #E3EBF2;"><?php esc_html_e( 'Prijs', 'borduur-patch-studio' ); ?></th>
			<td style="text-align:left;border:1px solid #E3EBF2;">
				<?php if ( $quote ) : ?>
					<strong><?php echo esc_html( Config::money( (float) $quote['subtotalIncl'], $locale ) ); ?></strong>
					<?php
					/* translators: %s: price per piece */
					echo esc_html( ' · ' . sprintf( __( '%s per stuk, alles inbegrepen', 'borduur-patch-studio' ), Config::money( (float) $quote['perPieceAllInIncl'], $locale ) ) );
					?>
					<br><small><?php esc_html_e( 'Incl. btw en eenmalige kosten. De verzending zie je in je winkelmand.', 'borduur-patch-studio' ); ?></small>
				<?php else : ?>
					<?php esc_html_e( 'Prijs op aanvraag: we maken graag een offerte op maat.', 'borduur-patch-studio' ); ?>
				<?php endif; ?>
			</td>
		</tr>
	</tbody>
</table>

<p style="text-align:center;margin:28px 0;">
	<a href="<?php echo esc_url( $restore_url ); ?>" style="display:inline-block;background:#0B74A6;color:#ffffff;text-decoration:none;font-weight:700;padding:14px 26px;border-radius:6px;">
		<?php esc_html_e( 'Verder met mijn ontwerp', 'borduur-patch-studio' ); ?>
	</a>
</p>

<p style="color:#6B6B6B;font-size:13px;">
	<?php
	/* translators: %d: number of days */
	echo esc_html( sprintf( __( 'De link werkt %d dagen na je laatste bezoek. We mailen je enkel deze link: geen nieuwsbrief.', 'borduur-patch-studio' ), (int) $days ) );
	?>
</p>
<p>
	<?php esc_html_e( 'Je krijgt altijd eerst een digitale proef ter goedkeuring vóór we starten. Bestellen kan vanaf 1 stuk.', 'borduur-patch-studio' ); ?>
	<?php if ( ! empty( $contact['phone'] ) || ! empty( $contact['email'] ) ) : ?>
		<?php esc_html_e( 'Vragen? Antwoord gewoon op deze e-mail of contacteer ons:', 'borduur-patch-studio' ); ?>
		<?php echo esc_html( trim( ( $contact['phone'] ?? '' ) . ( ! empty( $contact['phone'] ) && ! empty( $contact['email'] ) ? ' · ' : '' ) . ( $contact['email'] ?? '' ) ) ); ?>
	<?php endif; ?>
</p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
