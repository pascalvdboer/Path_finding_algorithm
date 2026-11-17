<?php
/**
 * Printable production sheet: A4, one patch per page, readable in black and white.
 * Served by admin-post.php?action=bps_print_fiche (capability edit_shop_orders + nonce).
 *
 * @package BorduurPatchStudio
 * @var \WC_Order $order
 * @var array     $fiches
 * @var string    $order_url  Admin URL of the order (QR code target).
 * @var string    $js_url     URL of patch-studio-admin.js (QR rendering, print button).
 * @var string    $site_name
 * @var bool      $autoprint
 */

use BorduurPatchStudio\Admin\Order_Production;

defined( 'ABSPATH' ) || exit;

$bps_total   = count( $fiches );
$bps_created = $order->get_date_created();
$bps_name    = trim( $order->get_formatted_billing_full_name() );
$bps_company = trim( (string) $order->get_billing_company() );
$bps_levels  = array(
	'bad'  => __( 'Belangrijk', 'borduur-patch-studio' ),
	'warn' => __( 'Let op', 'borduur-patch-studio' ),
	'ok'   => __( 'In orde', 'borduur-patch-studio' ),
);
/* translators: %s: order number */
$bps_title = sprintf( __( 'Productiefiche bestelling #%s', 'borduur-patch-studio' ), $order->get_order_number() );
?><!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_user_locale() ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $bps_title ); ?></title>
<style>
	@page { size: A4 portrait; margin: 10mm 11mm 12mm; }
	* { box-sizing: border-box; }
	html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
	body { margin: 0; background: #e9eef3; color: #111; font: 10pt/1.4 "Sora", "Assistant", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
	.toolbar { position: sticky; top: 0; z-index: 2; display: flex; gap: 12px; align-items: center; justify-content: center; padding: 10px 16px; background: #0A1F44; color: #fff; }
	.toolbar button, .toolbar a { font: inherit; font-weight: 700; border-radius: 6px; padding: 8px 16px; border: 2px solid #fff; background: #0B74A6; color: #fff; text-decoration: none; cursor: pointer; }
	.toolbar a { background: transparent; }
	.toolbar button:focus-visible, .toolbar a:focus-visible { outline: 3px solid #5926C5; outline-offset: 2px; }
	.page { width: 210mm; min-height: 297mm; margin: 12px auto; padding: 10mm 11mm 12mm; background: #fff; box-shadow: 0 2px 12px rgba(10,31,68,.15); display: flex; flex-direction: column; gap: 4mm; }
	.head { display: grid; grid-template-columns: 1fr auto; gap: 6mm; align-items: start; border-bottom: 1.2pt solid #111; padding-bottom: 3mm; }
	.head__site { font-size: 8.5pt; color: #444; margin: 0 0 1mm; }
	.head__ref { font-size: 30pt; font-weight: 800; letter-spacing: .02em; line-height: 1; margin: 0 0 2mm; font-variant-numeric: tabular-nums; }
	.head__meta { margin: 0; font-size: 10pt; }
	.head__meta strong { font-weight: 700; }
	.qr { width: 28mm; height: 28mm; }
	.qr svg { width: 100%; height: 100%; display: block; }
	.qr__label { font-size: 7pt; text-align: center; color: #444; margin-top: 1mm; }
	.facts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 2.5mm; }
	.fact { border: 1pt solid #111; border-radius: 2mm; padding: 2mm 3mm; }
	.fact__k { display: block; font-size: 7.5pt; color: #444; text-transform: none; }
	.fact__v { display: block; font-size: 13pt; font-weight: 800; line-height: 1.15; }
	.cols { display: grid; grid-template-columns: 74mm 1fr; gap: 6mm; align-items: start; }
	.visual { border: 1pt solid #bbb; border-radius: 2mm; padding: 3mm; text-align: center; background: #fff; }
	.visual__box { display: flex; align-items: center; justify-content: center; min-height: 40mm; background-color: #fff; background-image: linear-gradient(45deg, #eee 25%, transparent 25%), linear-gradient(-45deg, #eee 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #eee 75%), linear-gradient(-45deg, transparent 75%, #eee 75%); background-size: 4mm 4mm; background-position: 0 0, 0 2mm, 2mm -2mm, -2mm 0; }
	.visual__img { object-fit: contain; display: block; }
	.visual__cap { font-size: 7.5pt; color: #444; margin: 2mm 0 0; }
	.scale { display: inline-flex; align-items: center; gap: 1.5mm; font-size: 7pt; color: #444; margin-top: 1.5mm; }
	.scale i { display: inline-block; width: 10mm; height: 1.6mm; border: .8pt solid #111; border-top: 0; }
	h2 { font-size: 10.5pt; margin: 0 0 1.2mm; padding-bottom: .8mm; border-bottom: .6pt solid #999; }
	.sec { break-inside: avoid; }
	table { width: 100%; border-collapse: collapse; }
	th, td { text-align: left; vertical-align: top; padding: 1mm 1.5mm; border-bottom: .5pt solid #ccc; }
	th { width: 34%; font-weight: 600; color: #222; }
	.mono { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; font-variant-numeric: tabular-nums; }
	.sw { display: inline-block; width: 4.2mm; height: 4.2mm; border: .7pt solid #111; border-radius: 1mm; vertical-align: -1mm; margin-right: 1.5mm; }
	.threads td.code { font-size: 12pt; font-weight: 800; white-space: nowrap; width: 30mm; }
	.threads td.share { text-align: right; white-space: nowrap; width: 16mm; }
	.lvl { font-weight: 800; }
	.lvl--bad::before { content: "!! "; }
	.lvl--warn::before { content: "! "; }
	.row--bad td, .row--bad th { background: #f3f3f3; }
	.note { border: 1.2pt solid #111; border-radius: 2mm; padding: 2mm 3mm; break-inside: avoid; }
	.note h2 { border: 0; margin-bottom: .5mm; }
	.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 5mm; align-items: start; }
	.check { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5mm 4mm; margin: 0; padding: 0; list-style: none; }
	.check li { display: flex; gap: 2mm; align-items: center; font-size: 9.5pt; }
	.check li::before { content: ""; width: 4.2mm; height: 4.2mm; border: 1pt solid #111; border-radius: .8mm; flex: none; }
	.check li.done::before { content: "✓"; font-weight: 800; text-align: center; line-height: 4mm; }
	.foot { margin-top: auto; display: flex; justify-content: space-between; font-size: 7.5pt; color: #444; border-top: .6pt solid #999; padding-top: 1.5mm; }
	@media print {
		body { background: #fff; }
		.toolbar { display: none; }
		.page { margin: 0; padding: 0; width: auto; min-height: calc(297mm - 22mm); box-shadow: none; break-after: page; page-break-after: always; }
		.page:last-of-type { break-after: auto; page-break-after: auto; }
	}
	@media screen and (max-width: 820px) {
		.page { width: auto; margin: 8px; min-height: 0; }
		.cols, .grid2 { grid-template-columns: 1fr; }
		.facts { grid-template-columns: repeat(2, 1fr); }
	}
</style>
</head>
<body class="bps-fiche-print"<?php echo $autoprint ? ' data-bps-autoprint="1"' : ''; ?>>
<div class="toolbar">
	<button type="button" data-bps-print><?php esc_html_e( 'Afdrukken', 'borduur-patch-studio' ); ?></button>
	<a href="<?php echo esc_url( $order_url ); ?>"><?php esc_html_e( 'Terug naar de bestelling', 'borduur-patch-studio' ); ?></a>
	<span><?php esc_html_e( 'Tip: druk af op 100 % (geen "passend maken"), dan klopt het voorbeeld op ware grootte.', 'borduur-patch-studio' ); ?></span>
</div>

<?php
foreach ( array_values( $fiches ) as $bps_i => $fiche ) :
	$bps_w      = (float) $fiche['width_mm'];
	$bps_h      = (float) $fiche['height_mm'];
	$bps_fit    = min( 1, 66 / max( 1, $bps_w ), 80 / max( 1, $bps_h ) );
	$bps_true   = $bps_fit >= 0.999;
	$bps_status = (string) $fiche['proof']['status'];
	$bps_secs   = $fiche['sections'];
	$bps_thread = $bps_secs['threads'] ?? null;
	unset( $bps_secs['threads'] );
	?>
	<section class="page" aria-label="<?php echo esc_attr( $fiche['ref'] ); ?>">
		<header class="head">
			<div>
				<p class="head__site"><?php echo esc_html( $site_name ); ?> · <?php esc_html_e( 'Productiefiche', 'borduur-patch-studio' ); ?></p>
				<p class="head__ref"><?php echo esc_html( $fiche['ref'] ); ?></p>
				<p class="head__meta">
					<?php /* translators: %s: order number */ ?>
					<strong><?php echo esc_html( sprintf( __( 'Bestelling #%s', 'borduur-patch-studio' ), $order->get_order_number() ) ); ?></strong>
					<?php if ( $bps_created ) : ?>
						· <?php echo esc_html( wc_format_datetime( $bps_created, 'd-m-Y' ) ); ?>
					<?php endif; ?>
					<?php if ( '' !== $bps_name || '' !== $bps_company ) : ?>
						· <?php echo esc_html( trim( $bps_name . ( '' !== $bps_company ? ' (' . $bps_company . ')' : '' ) ) ); ?>
					<?php endif; ?>
				</p>
			</div>
			<div>
				<div class="qr" data-bps-qr="<?php echo esc_attr( $order_url ); ?>" role="img" aria-label="<?php esc_attr_e( 'QR-code naar de bestelling', 'borduur-patch-studio' ); ?>"></div>
				<div class="qr__label"><?php esc_html_e( 'Scan: open bestelling', 'borduur-patch-studio' ); ?></div>
			</div>
		</header>

		<div class="facts">
			<div class="fact"><span class="fact__k"><?php esc_html_e( 'Techniek', 'borduur-patch-studio' ); ?></span><span class="fact__v"><?php echo esc_html( $fiche['technique_lbl'] ); ?></span></div>
			<div class="fact"><span class="fact__k"><?php esc_html_e( 'Maat (b × h)', 'borduur-patch-studio' ); ?></span><span class="fact__v mono"><?php echo esc_html( Order_Production::num( round( $bps_w ), 0 ) . ' × ' . Order_Production::num( round( $bps_h ), 0 ) . ' mm' ); ?></span></div>
			<div class="fact"><span class="fact__k"><?php esc_html_e( 'Vorm', 'borduur-patch-studio' ); ?></span><span class="fact__v"><?php echo esc_html( $fiche['shape_lbl'] ); ?></span></div>
			<div class="fact"><span class="fact__k"><?php esc_html_e( 'Aantal', 'borduur-patch-studio' ); ?></span><span class="fact__v mono"><?php echo esc_html( number_format( (int) $fiche['qty'], 0, ',', '.' ) ); ?></span></div>
		</div>

		<?php if ( '' !== $fiche['notes'] ) : ?>
			<div class="note">
				<h2><?php esc_html_e( 'Opmerking van de klant', 'borduur-patch-studio' ); ?></h2>
				<div><?php echo nl2br( esc_html( $fiche['notes'] ) ); ?></div>
			</div>
		<?php endif; ?>

		<div class="cols">
			<div class="visual">
				<div class="visual__box">
					<?php if ( '' !== $fiche['preview_url'] ) : ?>
						<img class="visual__img" src="<?php echo esc_url( $fiche['preview_url'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: ref */ __( 'Voorbeeld van patch %s', 'borduur-patch-studio' ), $fiche['ref'] ) ); ?>" style="width:<?php echo esc_attr( (string) round( $bps_w * $bps_fit, 2 ) ); ?>mm;height:<?php echo esc_attr( (string) round( $bps_h * $bps_fit, 2 ) ); ?>mm">
					<?php else : ?>
						<span><?php esc_html_e( 'Geen voorbeeld', 'borduur-patch-studio' ); ?></span>
					<?php endif; ?>
				</div>
				<p class="visual__cap">
					<?php
					echo esc_html(
						$bps_true
							? __( '± ware grootte (1:1) bij afdrukken op 100 %', 'borduur-patch-studio' )
							/* translators: %s: scale percentage */
							: sprintf( __( 'Verkleind tot %s %%', 'borduur-patch-studio' ), Order_Production::num( $bps_fit * 100, 0 ) )
					);
					?>
				</p>
				<?php if ( $bps_true ) : ?>
					<span class="scale"><i></i> 10 mm</span>
				<?php endif; ?>
			</div>

			<div>
				<?php if ( isset( $bps_secs['specs'] ) ) : ?>
					<div class="sec">
						<h2><?php echo esc_html( (string) $bps_secs['specs']['title'] ); ?></h2>
						<table>
							<?php foreach ( (array) $bps_secs['specs']['rows'] as $bps_row ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( (string) $bps_row['label'] ); ?></th>
									<td class="<?php echo ! empty( $bps_row['mono'] ) ? 'mono' : ''; ?>">
										<?php if ( ! empty( $bps_row['hex'] ) ) : ?>
											<span class="sw" style="background:<?php echo esc_attr( (string) $bps_row['hex'] ); ?>"></span>
										<?php endif; ?>
										<?php echo esc_html( (string) $bps_row['value'] ); ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
					</div>
					<?php unset( $bps_secs['specs'] ); ?>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $bps_thread ) : ?>
			<div class="sec">
				<h2><?php echo esc_html( (string) $bps_thread['title'] ); ?></h2>
				<table class="threads">
					<?php foreach ( (array) $bps_thread['rows'] as $bps_row ) : ?>
						<tr>
							<td class="code mono">
								<?php if ( ! empty( $bps_row['hex'] ) ) : ?>
									<span class="sw" style="background:<?php echo esc_attr( (string) $bps_row['hex'] ); ?>"></span>
								<?php endif; ?>
								<?php echo esc_html( (string) ( $bps_row['code'] ?? '' ) ); ?>
							</td>
							<td><?php echo esc_html( (string) $bps_row['label'] ); ?></td>
							<td class="share mono">
								<?php echo esc_html( (string) ( $bps_row['share'] ?? '' ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			</div>
		<?php endif; ?>

		<div class="grid2">
			<?php foreach ( $bps_secs as $bps_section ) : ?>
				<div class="sec">
					<h2><?php echo esc_html( (string) $bps_section['title'] ); ?></h2>
					<table>
						<?php foreach ( (array) $bps_section['rows'] as $bps_row ) : ?>
							<?php $bps_level = (string) ( $bps_row['level'] ?? '' ); ?>
							<tr class="<?php echo $bps_level ? 'row--' . esc_attr( $bps_level ) : ''; ?>">
								<th scope="row">
									<?php if ( $bps_level && isset( $bps_levels[ $bps_level ] ) ) : ?>
										<span class="lvl lvl--<?php echo esc_attr( $bps_level ); ?>"><?php echo esc_html( $bps_levels[ $bps_level ] ); ?>:</span>
									<?php endif; ?>
									<?php if ( ! empty( $bps_row['hex'] ) ) : ?>
										<span class="sw" style="background:<?php echo esc_attr( (string) $bps_row['hex'] ); ?>"></span>
									<?php endif; ?>
									<?php echo esc_html( (string) $bps_row['label'] ); ?>
								</th>
								<td class="<?php echo ! empty( $bps_row['mono'] ) ? 'mono' : ''; ?>"><?php echo esc_html( (string) $bps_row['value'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</table>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="sec">
			<h2><?php esc_html_e( 'Werkvolgorde', 'borduur-patch-studio' ); ?></h2>
			<ul class="check">
				<li><?php esc_html_e( 'Bestand nagekeken', 'borduur-patch-studio' ); ?></li>
				<li><?php echo esc_html( $fiche['thread_based'] ? __( 'Gedigitaliseerd', 'borduur-patch-studio' ) : __( 'Drukbestand klaar', 'borduur-patch-studio' ) ); ?></li>
				<li class="<?php echo in_array( $bps_status, array( 'sent', 'approved', 'changes_requested' ), true ) ? 'done' : ''; ?>"><?php esc_html_e( 'Proef verstuurd', 'borduur-patch-studio' ); ?></li>
				<li class="<?php echo 'approved' === $bps_status ? 'done' : ''; ?>"><?php esc_html_e( 'Proef goedgekeurd', 'borduur-patch-studio' ); ?></li>
				<li><?php esc_html_e( 'Geproduceerd', 'borduur-patch-studio' ); ?></li>
				<li><?php esc_html_e( 'Gecontroleerd en verpakt', 'borduur-patch-studio' ); ?></li>
			</ul>
			<p><strong><?php esc_html_e( 'Status proef:', 'borduur-patch-studio' ); ?></strong> <?php echo esc_html( Order_Production::status_label( $bps_status ) ); ?>
				<?php if ( $fiche['proof']['round'] > 0 ) : ?>
					<?php /* translators: %d: round */ ?>
					(<?php echo esc_html( sprintf( __( 'ronde %d', 'borduur-patch-studio' ), (int) $fiche['proof']['round'] ) ); ?>)
				<?php endif; ?>
			</p>
		</div>

		<footer class="foot">
			<span><?php echo esc_html( $site_name . ' · ' . $fiche['ref'] . ' · ' . sprintf( /* translators: %s: date */ __( 'afgedrukt op %s', 'borduur-patch-studio' ), wp_date( 'd-m-Y H:i' ) ) ); ?></span>
			<span>
				<?php
				/* translators: 1: page number, 2: total */
				echo esc_html( sprintf( __( 'Patch %1$d van %2$d', 'borduur-patch-studio' ), $bps_i + 1, $bps_total ) );
				?>
			</span>
		</footer>
	</section>
<?php endforeach; ?>

<?php if ( '' !== $js_url ) : ?>
	<script src="<?php echo esc_url( $js_url ); ?>" defer></script><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone print document. ?>
<?php endif; ?>
</body>
</html>
