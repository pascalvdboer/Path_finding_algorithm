<?php
/**
 * E-mail to the customer: digital proof with approve / change buttons (SPEC §6.6).
 *
 * Override: yourtheme/woocommerce/admin/emails/proof.php
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Email $email
 * @var array     $design      DesignRecord.
 * @var \WC_Order $order
 * @var int       $round
 * @var string    $image_url   Small image for the mail body.
 * @var string    $full_url    Full size proof image.
 * @var string    $message     Message from the atelier.
 * @var array     $rows        Summary rows [label, value, hex?].
 * @var string    $approve_url
 * @var string    $changes_url
 * @var string    $first_name
 * @var array     $contact
 * @var string    $lang
 */

defined( 'ABSPATH' ) || exit;

$design      = isset( $design ) && is_array( $design ) ? $design : array( 'ref' => '' );
$rows        = isset( $rows ) && is_array( $rows ) ? $rows : array();
$round       = isset( $round ) ? (int) $round : 1;
$image_url   = isset( $image_url ) ? (string) $image_url : '';
$message     = isset( $message ) ? (string) $message : '';
$approve_url = isset( $approve_url ) ? (string) $approve_url : '';
$changes_url = isset( $changes_url ) ? (string) $changes_url : '';
$first_name  = isset( $first_name ) ? (string) $first_name : '';
$contact     = isset( $contact ) && is_array( $contact ) ? $contact : array();
$bps_onum    = ( isset( $order ) && $order instanceof \WC_Order ) ? (string) $order->get_order_number() : '';
$bps_phone   = (string) ( $contact['phone'] ?? '' );

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
	/* translators: 1: design ref, 2: order number */
	echo esc_html( sprintf( __( 'Hier is de digitale proef van je patch %1$s (bestelling #%2$s). Kijk je alles even na?', 'borduur-patch-studio' ), (string) $design['ref'], $bps_onum ) );
	if ( $round > 1 ) {
		/* translators: %d: proof round */
		echo ' ' . esc_html( '(' . sprintf( __( 'Proefronde %d', 'borduur-patch-studio' ), $round ) . ')' );
	}
	?>
</p>

<?php if ( '' !== trim( $message ) ) : ?>
	<div style="margin:0 0 20px;padding:14px 16px;border-left:4px solid #21A9E2;background:#F7FBFE;border-radius:6px;">
		<p style="margin:0 0 4px;font-weight:700;color:#0A1F44;"><?php echo esc_html( __( 'Bericht van ons atelier', 'borduur-patch-studio' ) ); ?></p>
		<p style="margin:0;color:#323232;"><?php echo nl2br( esc_html( $message ) ); ?></p>
	</div>
<?php endif; ?>

<?php if ( '' !== $image_url ) : ?>
	<p style="text-align:center;margin:24px 0;">
		<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( __( 'Digitale proef van je patch', 'borduur-patch-studio' ) ); ?>" width="360" style="display:inline-block;width:360px;max-width:100%;height:auto;border:0;" />
	</p>
<?php endif; ?>

<?php if ( $rows ) : ?>
	<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;border:1px solid #E3EBF2;margin-bottom:24px;">
		<tbody>
			<?php foreach ( $rows as $bps_row ) : ?>
				<tr>
					<th scope="row" style="text-align:left;border:1px solid #E3EBF2;width:38%;"><?php echo esc_html( (string) $bps_row['label'] ); ?></th>
					<td style="text-align:left;border:1px solid #E3EBF2;">
						<?php if ( ! empty( $bps_row['hex'] ) ) : ?>
							<span style="display:inline-block;width:12px;height:12px;border-radius:3px;border:1px solid #999;background:<?php echo esc_attr( (string) $bps_row['hex'] ); ?>;vertical-align:-1px;margin-right:6px;"></span>
						<?php endif; ?>
						<?php echo esc_html( (string) $bps_row['value'] ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<p style="margin:0 0 16px;"><?php echo esc_html( __( 'Klopt alles? Dan starten we de productie zodra je goedkeurt.', 'borduur-patch-studio' ) ); ?></p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 24px;">
	<tr>
		<td style="padding:0 0 10px;text-align:center;">
			<a href="<?php echo esc_url( $approve_url ); ?>" style="display:inline-block;min-width:240px;background:#0B74A6;color:#ffffff;text-decoration:none;font-weight:700;padding:14px 26px;border-radius:6px;">
				<?php echo esc_html( __( 'Ik keur goed', 'borduur-patch-studio' ) ); ?>
			</a>
		</td>
	</tr>
	<tr>
		<td style="text-align:center;">
			<a href="<?php echo esc_url( $changes_url ); ?>" style="display:inline-block;min-width:240px;background:#ffffff;color:#0B74A6;text-decoration:none;font-weight:700;padding:12px 24px;border-radius:6px;border:2px solid #0B74A6;">
				<?php echo esc_html( __( 'Ik wil iets aanpassen', 'borduur-patch-studio' ) ); ?>
			</a>
		</td>
	</tr>
</table>

<p style="color:#6B6B6B;font-size:13px;"><?php echo esc_html( __( 'Pas na jouw akkoord starten we de productie. Garen- en drukkleuren kunnen licht afwijken van je scherm.', 'borduur-patch-studio' ) ); ?></p>
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
