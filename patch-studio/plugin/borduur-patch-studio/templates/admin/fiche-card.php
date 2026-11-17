<?php
/**
 * One production card (order screen).
 *
 * @package BorduurPatchStudio
 * @var array     $fiche     Order_Production::fiche().
 * @var \WC_Order $order
 * @var bool      $can_proof
 * @var string    $print_url Printable sheet for this patch only.
 */

use BorduurPatchStudio\Admin\Order_Production;

defined( 'ABSPATH' ) || exit;

$bps_w      = Order_Production::num( round( (float) $fiche['width_mm'] ), 0 );
$bps_h      = Order_Production::num( round( (float) $fiche['height_mm'] ), 0 );
$bps_codes  = array();
$bps_levels = array(
	'bad'  => __( 'Belangrijk', 'borduur-patch-studio' ),
	'warn' => __( 'Let op', 'borduur-patch-studio' ),
	'ok'   => __( 'In orde', 'borduur-patch-studio' ),
);
foreach ( (array) ( $fiche['sections']['threads']['rows'] ?? array() ) as $bps_row ) {
	if ( ! empty( $bps_row['code'] ) ) {
		$bps_codes[] = $bps_row['code'] . ' ' . $bps_row['label'];
	}
}
$bps_ratio = ( (float) $fiche['height_mm'] > 0 ) ? (float) $fiche['width_mm'] / (float) $fiche['height_mm'] : 1;
?>
<article class="bps-card<?php echo $fiche['checks_level'] ? ' bps-card--' . esc_attr( $fiche['checks_level'] ) : ''; ?>" id="bps-fiche-<?php echo (int) $fiche['item_id']; ?>" data-bps-item="<?php echo (int) $fiche['item_id']; ?>" aria-labelledby="bps-fiche-title-<?php echo (int) $fiche['item_id']; ?>">
	<header class="bps-card__head">
		<h3 class="bps-card__title" id="bps-fiche-title-<?php echo (int) $fiche['item_id']; ?>">
			<span class="bps-card__ref"><?php echo esc_html( $fiche['ref'] ); ?></span>
			<span class="bps-card__chips">
				<span class="bps-chip"><?php echo esc_html( $fiche['technique_lbl'] ); ?></span>
				<span class="bps-chip bps-chip--mono"><?php echo esc_html( $bps_w . ' × ' . $bps_h . ' mm' ); ?></span>
				<span class="bps-chip"><?php echo esc_html( $fiche['shape_lbl'] ); ?></span>
				<span class="bps-chip bps-chip--strong">
					<?php
					/* translators: %s: quantity */
					echo esc_html( sprintf( __( '%s stuks', 'borduur-patch-studio' ), number_format( (int) $fiche['qty'], 0, ',', '.' ) ) );
					?>
				</span>
			</span>
		</h3>
		<?php if ( ! $fiche['design_exists'] ) : ?>
			<p class="bps-card__notice"><?php esc_html_e( 'Het ontwerp werd intussen opgeruimd; je ziet de gegevens zoals ze bij de bestelling bewaard werden.', 'borduur-patch-studio' ); ?></p>
		<?php endif; ?>
	</header>

	<div class="bps-card__grid">
		<div class="bps-card__visual">
			<figure class="bps-preview" style="--bps-ratio: <?php echo esc_attr( (string) round( $bps_ratio, 4 ) ); ?>">
				<?php if ( '' !== $fiche['preview_url'] ) : ?>
					<a href="<?php echo esc_url( $fiche['preview_url'] ); ?>" target="_blank" rel="noopener" class="bps-preview__link">
						<img src="<?php echo esc_url( $fiche['preview_url'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: design ref */ __( 'Voorbeeld van patch %s', 'borduur-patch-studio' ), $fiche['ref'] ) ); ?>" loading="lazy" decoding="async">
					</a>
				<?php else : ?>
					<span class="bps-preview__empty"><?php esc_html_e( 'Geen voorbeeld beschikbaar', 'borduur-patch-studio' ); ?></span>
				<?php endif; ?>
				<span class="bps-preview__dim bps-preview__dim--w" aria-hidden="true"><?php echo esc_html( $bps_w . ' mm' ); ?></span>
				<span class="bps-preview__dim bps-preview__dim--h" aria-hidden="true"><?php echo esc_html( $bps_h . ' mm' ); ?></span>
			</figure>
			<p class="bps-preview__note"><?php esc_html_e( 'Voorbeeld uit de ontwerptool, zoals de klant het zag.', 'borduur-patch-studio' ); ?></p>

			<div class="bps-card__files">
				<?php if ( $fiche['original'] ) : ?>
					<a class="button button-primary bps-btn-block" href="<?php echo esc_url( $fiche['original']['url'] ); ?>">
						<span class="dashicons dashicons-download" aria-hidden="true"></span>
						<?php esc_html_e( 'Origineel bestand downloaden', 'borduur-patch-studio' ); ?>
					</a>
					<p class="bps-file-meta">
						<?php
						echo esc_html(
							trim(
								$fiche['original']['name'] . ' · ' . $fiche['original']['ext'] . ( $fiche['original']['bytes'] > 0 ? ' · ' . size_format( $fiche['original']['bytes'], 1 ) : '' ),
								' ·'
							)
						);
						?>
					</p>
				<?php elseif ( '' !== $fiche['file_removed'] ) : ?>
					<p class="bps-file-meta"><?php echo esc_html( $fiche['file_removed'] ); ?></p>
				<?php elseif ( empty( $fiche['payload']['source']['fileName'] ) ) : ?>
					<p class="bps-file-meta"><?php esc_html_e( 'Geen bestand: de klant ontwierp een patch met enkel tekst.', 'borduur-patch-studio' ); ?></p>
				<?php else : ?>
					<p class="bps-file-meta"><?php esc_html_e( 'Het originele bestand is niet (meer) beschikbaar.', 'borduur-patch-studio' ); ?></p>
				<?php endif; ?>
				<a class="button bps-btn-block" href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="noopener">
					<span class="dashicons dashicons-printer" aria-hidden="true"></span>
					<?php esc_html_e( 'Fiche van deze patch afdrukken', 'borduur-patch-studio' ); ?>
				</a>
				<?php if ( $bps_codes ) : ?>
					<button type="button" class="button bps-btn-block" data-bps-copy="<?php echo esc_attr( implode( "\n", $bps_codes ) ); ?>">
						<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
						<?php esc_html_e( 'Garencodes kopiëren', 'borduur-patch-studio' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>

		<div class="bps-card__data">
			<?php if ( '' !== $fiche['notes'] ) : ?>
				<div class="bps-note">
					<h4 class="bps-note__title"><?php esc_html_e( 'Opmerking van de klant', 'borduur-patch-studio' ); ?></h4>
					<p><?php echo nl2br( esc_html( $fiche['notes'] ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php foreach ( $fiche['sections'] as $bps_sid => $bps_section ) : ?>
				<section class="bps-sec bps-sec--<?php echo esc_attr( (string) $bps_sid ); ?>">
					<h4 class="bps-sec__title"><?php echo esc_html( (string) $bps_section['title'] ); ?></h4>
					<dl class="bps-rows">
						<?php foreach ( (array) $bps_section['rows'] as $bps_row ) : ?>
							<?php $bps_level = (string) ( $bps_row['level'] ?? '' ); ?>
							<div class="bps-row<?php echo $bps_level ? ' bps-row--' . esc_attr( $bps_level ) : ''; ?>">
								<dt>
									<?php if ( ! empty( $bps_row['hex'] ) ) : ?>
										<span class="bps-swatch" style="background:<?php echo esc_attr( (string) $bps_row['hex'] ); ?>" aria-hidden="true"></span>
									<?php endif; ?>
									<?php if ( $bps_level && isset( $bps_levels[ $bps_level ] ) ) : ?>
										<span class="bps-level bps-level--<?php echo esc_attr( $bps_level ); ?>"><?php echo esc_html( $bps_levels[ $bps_level ] ); ?>:</span>
									<?php endif; ?>
									<?php echo esc_html( (string) ( $bps_row['label'] ?? '' ) ); ?>
								</dt>
								<dd class="<?php echo ! empty( $bps_row['mono'] ) ? 'bps-mono' : ''; ?>"><?php echo esc_html( (string) ( $bps_row['value'] ?? '' ) ); ?></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				</section>
			<?php endforeach; ?>
			<?php if ( empty( $fiche['sections']['checks'] ) ) : ?>
				<p class="bps-allgood"><?php esc_html_e( 'Geen aandachtspunten uit de automatische analyse. Kijk het bestand zoals altijd zelf na bij het digitaliseren.', 'borduur-patch-studio' ); ?></p>
			<?php endif; ?>
		</div>
	</div>

	<div class="bps-card__proof" data-bps-proof-status>
		<?php echo Order_Production::proof_block_html( $fiche ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template. ?>
	</div>
</article>
