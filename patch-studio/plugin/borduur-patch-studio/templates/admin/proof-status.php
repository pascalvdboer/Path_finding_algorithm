<?php
/**
 * Digital proof block of a production card (re-rendered after AJAX actions): status, approval record and a
 * timeline with a thumbnail per round (atelier-flow-3).
 *
 * @package BorduurPatchStudio
 * @var array $fiche
 * @var bool  $can_proof
 */

use BorduurPatchStudio\Admin\Order_Production;

defined( 'ABSPATH' ) || exit;

$bps_p      = (array) $fiche['proof'];
$bps_status = (string) $bps_p['status'];
$bps_item   = (int) $fiche['item_id'];
$bps_acts   = array(
	'sent'                     => __( 'Proef verstuurd', 'borduur-patch-studio' ),
	'approved'                 => __( 'Goedgekeurd door de klant', 'borduur-patch-studio' ),
	'changes'                  => __( 'Aanpassing gevraagd door de klant', 'borduur-patch-studio' ),
	'manual_approved'          => __( 'Manueel op goedgekeurd gezet', 'borduur-patch-studio' ),
	'manual_changes_requested' => __( 'Manueel op "aanpassing gevraagd" gezet', 'borduur-patch-studio' ),
	'manual_none'              => __( 'Proefstatus manueel teruggezet', 'borduur-patch-studio' ),
	'reminder'                 => __( 'Herinnering verstuurd', 'borduur-patch-studio' ),
	'cancelled'                => __( 'Gestopt: bestelling geannuleerd of terugbetaald', 'borduur-patch-studio' ),
	'reopened'                 => __( 'Bestelling heropend: vorige proefstatus teruggezet', 'borduur-patch-studio' ),
	'cancel_requested'         => __( 'Klant vraagt om te annuleren', 'borduur-patch-studio' ),
);
?>
<div class="bps-proof bps-proof--<?php echo esc_attr( $bps_status ); ?>">
	<style>
		.bps-proof-pill--cancelled { color: #B42318; border-color: #B42318; background: #FEF3F2; }
		.bps-timeline { list-style: none; margin: 8px 0 0; padding: 0; }
		.bps-timeline li { display: grid; grid-template-columns: 56px 1fr; gap: 10px; align-items: start; padding: 8px 0; border-top: 1px solid #E3EBF2; }
		.bps-timeline__thumb { width: 56px; height: 56px; object-fit: contain; border: 1px solid #DCDCDE; border-radius: 4px; background: #fff; }
		.bps-timeline__meta { color: #50575E; font-size: 12px; word-break: break-all; }
	</style>
	<div class="bps-proof__main">
		<h4 class="bps-proof__title"><?php esc_html_e( 'Digitale proef', 'borduur-patch-studio' ); ?></h4>
		<p class="bps-proof__status">
			<span class="bps-proof-pill bps-proof-pill--<?php echo esc_attr( $bps_status ); ?>"><?php echo esc_html( Order_Production::status_label( $bps_status ) ); ?></span>
			<?php if ( $bps_p['round'] > 0 ) : ?>
				<span class="bps-proof__meta">
					<?php
					/* translators: 1: round, 2: date, 3: e-mail */
					echo esc_html( trim( sprintf( __( 'Ronde %1$d · verstuurd op %2$s %3$s', 'borduur-patch-studio' ), (int) $bps_p['round'], Order_Production::date_text( (string) $bps_p['sent_at'] ), '' !== $bps_p['recipient'] ? sprintf( /* translators: %s: e-mail */ __( 'naar %s', 'borduur-patch-studio' ), $bps_p['recipient'] ) : '' ) ) );
					?>
				</span>
			<?php endif; ?>
		</p>
		<?php if ( in_array( $bps_status, array( 'approved', 'changes_requested' ), true ) && '' !== $bps_p['responded_at'] ) : ?>
			<p class="bps-proof__meta">
				<?php
				/* translators: %s: date */
				echo esc_html( sprintf( __( 'Antwoord ontvangen op %s', 'borduur-patch-studio' ), Order_Production::date_text( (string) $bps_p['responded_at'] ) ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( '' !== $bps_p['cancel_requested'] ) : ?>
			<p class="bps-proof__next"><strong><?php esc_html_e( 'De klant vraagt om de (betaalde) bestelling te annuleren. Beslis zelf over annulering en terugbetaling.', 'borduur-patch-studio' ); ?></strong></p>
		<?php endif; ?>
		<?php if ( '' !== $bps_p['note'] ) : ?>
			<blockquote class="bps-proof__note"><?php echo nl2br( esc_html( (string) $bps_p['note'] ) ); ?></blockquote>
		<?php endif; ?>
		<?php if ( 'approved' === $bps_status ) : ?>
			<p class="bps-proof__next"><?php esc_html_e( 'De klant gaf akkoord: deze patch mag in productie.', 'borduur-patch-studio' ); ?></p>
		<?php elseif ( 'changes_requested' === $bps_status ) : ?>
			<p class="bps-proof__next"><?php esc_html_e( 'Pas het ontwerp aan en stuur een nieuwe proef.', 'borduur-patch-studio' ); ?></p>
		<?php elseif ( 'cancelled' === $bps_status ) : ?>
			<p class="bps-proof__next"><?php esc_html_e( 'Niet produceren: de bestelling of deze regel is geannuleerd of terugbetaald. Proeflinks werken niet meer.', 'borduur-patch-studio' ); ?></p>
		<?php elseif ( 'none' === $bps_status ) : ?>
			<p class="bps-proof__next"><?php esc_html_e( 'Klaar met digitaliseren? Stuur de klant de proef ter goedkeuring. Pas na akkoord start de productie.', 'borduur-patch-studio' ); ?></p>
		<?php endif; ?>
	</div>

	<?php if ( $can_proof && 'cancelled' !== $bps_status ) : ?>
		<div class="bps-proof__actions">
			<?php if ( $fiche['design_exists'] ) : ?>
				<button type="button" class="button button-primary" data-bps-proof-open="<?php echo (int) $bps_item; ?>">
					<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
					<?php echo esc_html( $bps_p['round'] > 0 ? __( 'Nieuwe proef versturen', 'borduur-patch-studio' ) : __( 'Digitale proef versturen', 'borduur-patch-studio' ) ); ?>
				</button>
				<details class="bps-proof__manual">
					<summary><?php esc_html_e( 'Status manueel aanpassen', 'borduur-patch-studio' ); ?></summary>
					<p class="bps-proof__manual-help"><?php esc_html_e( 'Bijvoorbeeld als de klant telefonisch of per mail goedkeurde.', 'borduur-patch-studio' ); ?></p>
					<p>
						<label class="screen-reader-text" for="bps-status-<?php echo (int) $bps_item; ?>"><?php esc_html_e( 'Nieuwe status', 'borduur-patch-studio' ); ?></label>
						<select id="bps-status-<?php echo (int) $bps_item; ?>" data-bps-status-select>
							<option value="approved"><?php esc_html_e( 'Goedgekeurd', 'borduur-patch-studio' ); ?></option>
							<option value="changes_requested"><?php esc_html_e( 'Aanpassing gevraagd', 'borduur-patch-studio' ); ?></option>
							<option value="none"><?php esc_html_e( 'Nog geen proef', 'borduur-patch-studio' ); ?></option>
						</select>
					</p>
					<p>
						<label for="bps-status-note-<?php echo (int) $bps_item; ?>"><?php esc_html_e( 'Notitie (optioneel)', 'borduur-patch-studio' ); ?></label>
						<textarea id="bps-status-note-<?php echo (int) $bps_item; ?>" rows="2" class="widefat" maxlength="2000" data-bps-status-note></textarea>
					</p>
					<button type="button" class="button" data-bps-status-save="<?php echo (int) $bps_item; ?>"><?php esc_html_e( 'Status opslaan', 'borduur-patch-studio' ); ?></button>
				</details>
			<?php else : ?>
				<p class="bps-proof__meta"><?php esc_html_e( 'Het ontwerp bestaat niet meer; een proef versturen kan hier niet meer.', 'borduur-patch-studio' ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $bps_p['image_url'] && $bps_p['round'] > 0 ) : ?>
		<a class="bps-proof__thumb" href="<?php echo esc_url( (string) $bps_p['image_url'] ); ?>" target="_blank" rel="noopener">
			<img src="<?php echo esc_url( (string) $bps_p['image_url'] ); ?>" alt="<?php esc_attr_e( 'Verstuurde proefafbeelding', 'borduur-patch-studio' ); ?>" loading="lazy">
		</a>
	<?php endif; ?>

	<?php if ( $bps_p['history'] ) : ?>
		<details class="bps-proof__history"<?php echo $bps_p['round'] > 1 ? ' open' : ''; ?>>
			<summary>
				<?php
				/* translators: %d: number of events */
				echo esc_html( sprintf( _n( 'Goedkeuringsdossier (%d gebeurtenis)', 'Goedkeuringsdossier (%d gebeurtenissen)', count( $bps_p['history'] ), 'borduur-patch-studio' ), count( $bps_p['history'] ) ) );
				?>
			</summary>
			<ol class="bps-history bps-timeline">
				<?php foreach ( array_reverse( $bps_p['history'] ) as $bps_h ) : ?>
					<?php
					if ( ! is_array( $bps_h ) ) {
						continue;
					}
					$bps_a = (string) ( $bps_h['action'] ?? '' );
					$bps_b = (string) ( $bps_h['by'] ?? '' );
					?>
					<li class="bps-timeline__item bps-timeline__item--<?php echo esc_attr( $bps_a ); ?>">
						<span>
							<?php if ( ! empty( $bps_h['image_url'] ) ) : ?>
								<a href="<?php echo esc_url( (string) $bps_h['image_url'] ); ?>" target="_blank" rel="noopener">
									<img class="bps-timeline__thumb" src="<?php echo esc_url( (string) $bps_h['image_url'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %d: round */ __( 'Proefbeeld ronde %d', 'borduur-patch-studio' ), (int) ( $bps_h['round'] ?? 0 ) ) ); ?>" loading="lazy">
								</a>
							<?php endif; ?>
						</span>
						<span>
							<strong><?php echo esc_html( $bps_acts[ $bps_a ] ?? $bps_a ); ?></strong>
							<?php if ( ! empty( $bps_h['round'] ) ) : ?>
								<?php /* translators: %d: round */ ?>
								<span><?php echo esc_html( sprintf( __( '(ronde %d)', 'borduur-patch-studio' ), (int) $bps_h['round'] ) ); ?></span>
							<?php endif; ?>
							<span class="bps-history__when"><?php echo esc_html( Order_Production::date_text( (string) ( $bps_h['at'] ?? '' ) ) ); ?></span>
							<?php if ( '' !== $bps_b ) : ?>
								<span class="bps-history__by">· <?php echo esc_html( 'customer' === $bps_b ? __( 'klant', 'borduur-patch-studio' ) : $bps_b ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $bps_h['note'] ) ) : ?>
								<span class="bps-history__note" style="display:block"><?php echo nl2br( esc_html( (string) $bps_h['note'] ) ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $bps_h['confirmed'] ) ) : ?>
								<span class="bps-timeline__meta" style="display:block"><?php esc_html_e( 'Klant vinkte aan: spelling, kleuren, maat en aantal nagekeken.', 'borduur-patch-studio' ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $bps_h['image_sha256'] ) ) : ?>
								<span class="bps-timeline__meta" style="display:block">SHA-256 <?php echo esc_html( (string) $bps_h['image_sha256'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $bps_h['ip_hash'] ) ) : ?>
								<span class="bps-timeline__meta" style="display:block">
									<?php
									/* translators: 1: short hash of the IP address, 2: browser (user agent) */
									echo esc_html( sprintf( __( 'IP-hash %1$s… · %2$s', 'borduur-patch-studio' ), substr( (string) $bps_h['ip_hash'], 0, 12 ), (string) ( $bps_h['ua'] ?? '' ) ) );
									?>
								</span>
							<?php endif; ?>
							<?php if ( ! empty( $bps_h['spec_snapshot'] ) && is_array( $bps_h['spec_snapshot'] ) ) : ?>
								<details>
									<summary><?php esc_html_e( 'Specificaties zoals de klant ze zag', 'borduur-patch-studio' ); ?></summary>
									<ul style="margin:4px 0 0 1em;list-style:disc">
										<?php foreach ( $bps_h['spec_snapshot'] as $bps_s ) : ?>
											<li><?php echo esc_html( (string) ( $bps_s['label'] ?? '' ) . ': ' . (string) ( $bps_s['value'] ?? '' ) ); ?></li>
										<?php endforeach; ?>
									</ul>
								</details>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
		</details>
	<?php endif; ?>
</div>
