<?php
/**
 * E-mail to the shop: the customer answered a digital proof.
 *
 * Override: yourtheme/woocommerce/admin/emails/proof-response.php
 *
 * @package BorduurPatchStudio
 * @var string    $email_heading
 * @var string    $additional_content
 * @var \WC_Email $email
 * @var array     $design
 * @var \WC_Order $order
 * @var string    $action     approve|changes|cancel
 * @var array     $lines      Whole-order answer: [{design, action, note, round}] (empty for one line).
 * @var string    $answer
 * @var string    $note
 * @var int       $round
 * @var string    $order_url
 * @var string    $customer
 */

defined( 'ABSPATH' ) || exit;

$design    = isset( $design ) && is_array( $design ) ? $design : array( 'ref' => '', 'preview_url' => '' );
$action    = isset( $action ) ? (string) $action : 'approve';
$note      = isset( $note ) ? (string) $note : '';
$round     = isset( $round ) ? (int) $round : 1;
$order_url = isset( $order_url ) ? (string) $order_url : admin_url();
$customer  = isset( $customer ) ? (string) $customer : '';
$bps_onum  = ( isset( $order ) && $order instanceof \WC_Order ) ? (string) $order->get_order_number() : '';
$bps_img   = ! empty( $design['proof']['image_url'] ) ? (string) $design['proof']['image_url'] : (string) ( $design['preview_url'] ?? '' );

do_action( 'woocommerce_email_header', $email_heading, $email );
$lines    = isset( $lines ) && is_array( $lines ) ? $lines : array();
$bps_who  = '' !== $customer ? $customer : __( 'De klant', 'borduur-patch-studio' );
$bps_warn = 'changes' === $action || 'cancel' === $action;
?>
<p style="margin:0 0 16px;padding:14px 16px;border-left:4px solid <?php echo $bps_warn ? '#B45309' : '#15803D'; ?>;background:<?php echo $bps_warn ? '#FFF8EB' : '#F0FAF3'; ?>;border-radius:6px;font-weight:700;">
	<?php
	if ( $lines ) {
		/* translators: %s: customer */
		echo esc_html( sprintf( __( '%s beantwoordde de proef voor de hele bestelling:', 'borduur-patch-studio' ), $bps_who ) );
	} elseif ( 'cancel' === $action ) {
		/* translators: 1: customer, 2: ref */
		echo esc_html( sprintf( __( '%1$s vraagt via de proefpagina om de bestelling te annuleren (patch %2$s, nog niet goedgekeurd). De bestelling is al betaald: beslis zelf over annulering en terugbetaling.', 'borduur-patch-studio' ), $bps_who, (string) $design['ref'] ) );
	} else {
		echo esc_html(
			'changes' === $action
				/* translators: 1: customer, 2: ref, 3: round */
				? sprintf( __( '%1$s vraagt een aanpassing aan de proef van patch %2$s (ronde %3$d).', 'borduur-patch-studio' ), $bps_who, (string) $design['ref'], $round )
				/* translators: 1: customer, 2: ref, 3: round */
				: sprintf( __( '%1$s keurde de proef van patch %2$s goed (ronde %3$d). Deze patch mag in productie.', 'borduur-patch-studio' ), $bps_who, (string) $design['ref'], $round )
		);
	}
	?>
</p>
<?php if ( $lines ) : ?>
	<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;border:1px solid #E3EBF2;margin-bottom:20px;">
		<tbody>
			<?php foreach ( $lines as $bps_l ) : ?>
				<tr>
					<th scope="row" style="text-align:left;border:1px solid #E3EBF2;"><?php echo esc_html( (string) ( $bps_l['design']['ref'] ?? '' ) ); ?></th>
					<td style="text-align:left;border:1px solid #E3EBF2;">
						<strong><?php echo esc_html( 'changes' === ( $bps_l['action'] ?? '' ) ? __( 'aanpassing gevraagd', 'borduur-patch-studio' ) : __( 'goedgekeurd', 'borduur-patch-studio' ) ); ?></strong>
						<?php if ( ! empty( $bps_l['note'] ) ) : ?>
							<br><?php echo nl2br( esc_html( (string) $bps_l['note'] ) ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
<?php if ( ! $lines && '' !== trim( $note ) ) : ?>
	<p style="margin:0 0 4px;font-weight:700;"><?php esc_html_e( 'Opmerking van de klant', 'borduur-patch-studio' ); ?></p>
	<blockquote style="margin:0 0 20px;padding:10px 14px;border-left:4px solid #E3EBF2;background:#FAFBFC;"><?php echo nl2br( esc_html( $note ) ); ?></blockquote>
<?php endif; ?>
<?php if ( ! $lines && '' !== $bps_img ) : ?>
	<p style="text-align:center;margin:20px 0;"><img src="<?php echo esc_url( $bps_img ); ?>" alt="" width="200" style="display:inline-block;width:200px;max-width:100%;height:auto;border:0;" /></p>
<?php endif; ?>
<p style="text-align:center;margin:24px 0;">
	<a href="<?php echo esc_url( $order_url ); ?>" style="display:inline-block;background:#0B74A6;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 24px;border-radius:6px;">
		<?php
		/* translators: %s: order number */
		echo esc_html( sprintf( __( 'Open bestelling #%s', 'borduur-patch-studio' ), $bps_onum ) );
		?>
	</a>
</p>
<?php
if ( ! empty( $additional_content ) ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
