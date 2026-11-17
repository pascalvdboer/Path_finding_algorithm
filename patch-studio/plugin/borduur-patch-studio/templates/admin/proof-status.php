<?php
/**
 * Digital proof block of a production card (re-rendered after AJAX actions).
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
	'sent'                       => __( 'Proef verstuurd', 'borduur-patch-studio' ),
	'approved'                   => __( 'Goedgekeurd door de klant', 'borduur-patch-studio' ),
	'changes'                    => __( 'Aanpassing gevraagd door de klant', 'borduur-patch-studio' ),
	'manual_approved'            => __( 'Manueel op goedgekeurd gezet', 'borduur-patch-studio' ),
	'manual_changes_requested'   => __( 'Manueel op "aanpassing gevraagd" gezet', 'borduur-patch-studio' ),
	'manual_none'                => __( 'Proefstatus manueel teruggezet', 'borduur-patch-studio' ),
);
?>
<div class="bps-proof bps-proof--<?php echo esc_attr( $bps_status ); ?>">
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
		<?php if ( '' !== $bps_p['note'] ) : ?>
			<blockquote class="bps-proof__note"><?php echo nl2br( esc_html( (string) $bps_p['note'] ) ); ?></blockquote>
		<?php endif; ?>
		<?php if ( 'approved' === $bps_status ) : ?>
			<p class="bps-proof__next"><?php esc_html_e( 'De klant gaf akkoord: deze patch mag in productie.', 'borduur-patch-studio' ); ?></p>
		<?php elseif ( 'changes_requested' === $bps_status ) : ?>
			<p class="bps-proof__next"><?php esc_html_e( 'Pas het ontwerp aan en stuur een nieuwe proef.', 'borduur-patch-studio' ); ?></p>
		<?php elseif ( 'none' === $bps_status ) : ?>
			<p class="bps-proof__next"><?php esc_html_e( 'Klaar met digitaliseren? Stuur de klant de proef ter goedkeuring. Pas na akkoord start de productie.', 'borduur-patch-studio' ); ?></p>
		<?php endif; ?>
	</div>

	<?php if ( $can_proof ) : ?>
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
		<details class="bps-proof__history">
			<summary>
				<?php
				/* translators: %d: number of events */
				echo esc_html( sprintf( _n( 'Geschiedenis (%d)', 'Geschiedenis (%d)', count( $bps_p['history'] ), 'borduur-patch-studio' ), count( $bps_p['history'] ) ) );
				?>
			</summary>
			<ol class="bps-history">
				<?php foreach ( array_reverse( $bps_p['history'] ) as $bps_h ) : ?>
					<?php
					if ( ! is_array( $bps_h ) ) {
						continue;
					}
					$bps_a = (string) ( $bps_h['action'] ?? '' );
					$bps_b = (string) ( $bps_h['by'] ?? '' );
					?>
					<li>
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
							<div class="bps-history__note"><?php echo nl2br( esc_html( (string) $bps_h['note'] ) ); ?></div>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</details>
	<?php endif; ?>
</div>
