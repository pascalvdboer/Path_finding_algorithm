<?php
/**
 * Order screen metabox: production cards for every Patch Studio line + the proof dialog.
 *
 * @package BorduurPatchStudio
 * @var \WC_Order $order
 * @var array     $fiches     Output of Order_Production::fiche() per line.
 * @var string    $print_url  Printable sheet for the whole order.
 * @var bool      $can_proof  Current user may send proofs.
 * @var array     $admin_data Data for assets/src/admin/index.js.
 * @var string    $order_proof Admin link to "Proef voor hele bestelling versturen" ('' when not offered).
 * @var array     $notice      {type, text} result of a whole-order proof, or null.
 * @var string    $stopped     Blocking order status ('' when proofs are allowed).
 */

defined( 'ABSPATH' ) || exit;

$bps_count = count( $fiches );
?>
<div class="bps-prod" data-bps-production>
	<?php if ( ! empty( $notice ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> inline" role="status"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
	<?php endif; ?>
	<?php if ( ! empty( $stopped ) ) : ?>
		<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'Geannuleerd: proef en productie gestopt.', 'borduur-patch-studio' ); ?></strong> <?php esc_html_e( 'Deze bestelling is geannuleerd, terugbetaald of mislukt. Proeflinks werken niet meer en er kan geen proef verstuurd worden.', 'borduur-patch-studio' ); ?></p></div>
	<?php endif; ?>
	<div class="bps-prod__bar">
		<p class="bps-prod__intro">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of patches */
					_n( '%d patch op maat in deze bestelling. Alles wat het atelier nodig heeft staat hieronder.', '%d patches op maat in deze bestelling. Alles wat het atelier nodig heeft staat hieronder.', $bps_count, 'borduur-patch-studio' ),
					$bps_count
				)
			);
			?>
		</p>
		<a class="button button-secondary bps-prod__print" href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="noopener">
			<span class="dashicons dashicons-printer" aria-hidden="true"></span>
			<?php echo esc_html( 1 === $bps_count ? __( 'Productiefiche afdrukken', 'borduur-patch-studio' ) : __( 'Alle productiefiches afdrukken', 'borduur-patch-studio' ) ); ?>
		</a>
		<?php if ( ! empty( $order_proof ) ) : ?>
			<a class="button button-primary bps-prod__orderproof" href="<?php echo esc_url( $order_proof ); ?>" data-bps-order-proof>
				<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
				<?php esc_html_e( 'Proef voor hele bestelling versturen', 'borduur-patch-studio' ); ?>
			</a>
		<?php endif; ?>
	</div>

	<?php
	foreach ( $fiches as $fiche ) {
		\BorduurPatchStudio\Admin\Order_Production::template(
			'admin/fiche-card.php',
			array(
				'fiche'     => $fiche,
				'order'     => $order,
				'can_proof' => $can_proof,
				'print_url' => \BorduurPatchStudio\Admin\Order_Production::print_url( (int) $order->get_id(), (int) $fiche['item_id'] ),
			)
		);
	}
	?>

	<?php if ( $can_proof ) : ?>
		<?php // No <form> and no name attributes: this markup lives inside the order edit form (nested forms are invalid). ?>
		<dialog class="bps-dialog" data-bps-proof-dialog aria-labelledby="bps-proof-title">
			<div class="bps-dialog__form" data-bps-proof-form>
				<h2 id="bps-proof-title" class="bps-dialog__title"><?php esc_html_e( 'Digitale proef versturen', 'borduur-patch-studio' ); ?> <span data-bps-proof-ref></span></h2>
				<div class="bps-dialog__body">
					<figure class="bps-dialog__figure">
						<img src="" alt="<?php esc_attr_e( 'Proefafbeelding', 'borduur-patch-studio' ); ?>" data-bps-proof-img>
						<figcaption>
							<button type="button" class="button-link" data-bps-proof-pick><?php esc_html_e( 'Andere afbeelding kiezen', 'borduur-patch-studio' ); ?></button>
							<button type="button" class="button-link" data-bps-proof-reset hidden><?php esc_html_e( 'Terug naar het voorbeeld uit de tool', 'borduur-patch-studio' ); ?></button>
						</figcaption>
					</figure>
					<div class="bps-dialog__fields">
						<p class="bps-field">
							<label for="bps-proof-to"><?php esc_html_e( 'Ontvanger', 'borduur-patch-studio' ); ?></label>
							<input type="email" id="bps-proof-to" class="widefat" data-bps-proof-to autocomplete="off" required>
						</p>
						<p class="bps-field">
							<label for="bps-proof-msg"><?php esc_html_e( 'Bericht aan de klant', 'borduur-patch-studio' ); ?></label>
							<textarea id="bps-proof-msg" class="widefat" rows="6" maxlength="2000" data-bps-proof-msg></textarea>
						</p>
						<p class="bps-dialog__hint" data-bps-proof-hint></p>
						<p class="bps-dialog__hint"><?php esc_html_e( 'De klant krijgt de afbeelding, een samenvatting van de patch en twee knoppen: "Ik keur goed" en "Ik wil iets aanpassen". Het antwoord verschijnt hier en als notitie bij de bestelling.', 'borduur-patch-studio' ); ?></p>
						<p class="bps-dialog__error" role="alert" data-bps-proof-error hidden></p>
					</div>
				</div>
				<div class="bps-dialog__actions">
					<button type="button" class="button" data-bps-proof-cancel><?php esc_html_e( 'Annuleren', 'borduur-patch-studio' ); ?></button>
					<button type="button" class="button button-primary" data-bps-proof-send><?php esc_html_e( 'Proef versturen', 'borduur-patch-studio' ); ?></button>
				</div>
			</div>
		</dialog>
	<?php endif; ?>

	<div class="bps-prod__live screen-reader-text" role="status" aria-live="polite" data-bps-live></div>
	<script type="application/json" class="bps-admin-data"><?php echo wp_json_encode( $admin_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
</div>
