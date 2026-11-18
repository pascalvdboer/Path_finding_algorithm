<?php
/**
 * Name tapes: printable production list / digital proof (Order_Tools::print_sheet()).
 *
 * @package BorduurPatchStudio
 * @var array $d Sheet data from Order_Tools::sheet_data().
 */

namespace BorduurPatchStudio\Nametape;

defined( 'ABSPATH' ) || exit;

$bps_fr  = 'fr' === $d['lang'];
$bps_t   = static fn( string $nl, string $fr ) => $bps_fr ? $fr : $nl;
$bps_st  = $d['style'];
$bps_tot = $d['totals'];
$bps_js  = wp_json_encode( $d['js'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
$bps_nt  = $d['nt'];
$bps_sts = array(
	'ok'    => $bps_t( 'OK', 'OK' ),
	'warn'  => $bps_t( 'Kleine letters', 'Petit texte' ),
	'error' => $bps_t( 'Nakijken', 'À vérifier' ),
);
?><!doctype html>
<html lang="<?php echo esc_attr( $d['lang'] ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $bps_t( 'Naamstroken', 'Bandes nominatives' ) . ' · #' . $d['order']['number'] ); ?></title>
<style>
	:root { --ink:#1d2433; --muted:#5b6475; --line:#dfe3ea; --warn:#b26200; --err:#b3202a; --ok:#1d7a3a; --accent:#0b74a6; }
	* { box-sizing: border-box; }
	body { margin: 0; padding: 24px; font: 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; color: var(--ink); background: #fff; }
	h1 { font-size: 20px; margin: 0 0 4px; }
	.meta { color: var(--muted); margin: 0 0 16px; }
	.bar { display: flex; gap: 8px; margin: 0 0 16px; }
	.bar button { font: inherit; padding: 8px 14px; border-radius: 8px; border: 1px solid var(--accent); background: var(--accent); color: #fff; cursor: pointer; }
	.style { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 6px 16px; padding: 12px; border: 1px solid var(--line); border-radius: 10px; margin: 0 0 12px; }
	.style div { display: flex; gap: 6px; align-items: center; }
	.style b { min-width: 92px; color: var(--muted); font-weight: 600; }
	.sw { display: inline-block; width: 14px; height: 14px; border-radius: 3px; border: 1px solid #0003; }
	.note { padding: 8px 12px; border-radius: 8px; margin: 0 0 12px; background: #fff4e5; color: var(--warn); font-weight: 600; }
	table { width: 100%; border-collapse: collapse; }
	th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line); vertical-align: middle; }
	th { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .03em; }
	td.n { width: 36px; color: var(--muted); }
	td.c canvas { display: block; max-width: 100%; }
	td.q { font-weight: 700; font-size: 16px; }
	.s-ok { color: var(--ok); } .s-warn { color: var(--warn); } .s-error { color: var(--err); font-weight: 700; }
	.foot { margin-top: 16px; color: var(--muted); font-size: 12px; }
	tr { break-inside: avoid; }
	@media print { body { padding: 0; } .bar { display: none; } @page { size: A4; margin: 12mm; } }
</style>
</head>
<body>
<div data-bps-nt-print>
	<script type="application/json" class="bps-nt-print-data"><?php echo $bps_js; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with HEX_TAG/AMP. ?></script>
	<div class="bar"><button type="button" data-bps-nt-print-btn><?php echo esc_html( $bps_t( 'Afdrukken / bewaren als PDF', 'Imprimer / enregistrer en PDF' ) ); ?></button></div>
	<h1><?php echo esc_html( $bps_t( 'Productielijst naamstroken', 'Liste de production bandes nominatives' ) ); ?> · #<?php echo esc_html( $d['order']['number'] ); ?></h1>
	<p class="meta">
		<?php echo esc_html( $d['order']['date'] . ( '' !== $d['order']['customer'] ? ' · ' . $d['order']['customer'] : '' ) ); ?> ·
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: persons, 2: pieces */
				$bps_t( '%1$d personen · %2$d naamstroken', '%1$d personnes · %2$d bandes' ),
				(int) $bps_tot['persons'],
				(int) $bps_tot['main']
			) . ( $bps_tot['blood'] > 0 ? ' + ' . (int) $bps_tot['blood'] . ' ' . $bps_t( 'bloedgroepstroken', 'bandes groupe sanguin' ) : '' )
		);
		?>
	</p>
	<div class="style">
		<div><b><?php echo esc_html( $bps_t( 'Formaat', 'Format' ) ); ?></b><?php echo esc_html( $bps_st['format'] ); ?></div>
		<div><b><?php echo esc_html( $bps_t( 'Stof', 'Tissu' ) ); ?></b><span class="sw" style="background:<?php echo esc_attr( $bps_st['fabricHex'] ); ?>"></span><?php echo esc_html( $bps_st['fabric'] . ' ' . $bps_st['fabricHex'] ); ?></div>
		<div><b><?php echo esc_html( $bps_t( 'Letterkleur', 'Couleur texte' ) ); ?></b><span class="sw" style="background:<?php echo esc_attr( $bps_st['letterHex'] ); ?>"></span><?php echo esc_html( $bps_st['letter'] . ' ' . $bps_st['letterHex'] . ( '' !== $bps_st['letterThread'] ? ' · ' . $bps_st['letterThread'] : '' ) ); ?></div>
		<div><b><?php echo esc_html( $bps_t( 'Lettertype', 'Police' ) ); ?></b><?php echo esc_html( $bps_st['font'] . ( $bps_st['uppercase'] ? ' · ' . $bps_t( 'hoofdletters', 'majuscules' ) : '' ) ); ?></div>
		<div><b><?php echo esc_html( $bps_t( 'Rand', 'Bord' ) ); ?></b><?php echo esc_html( $bps_st['border'] ); ?></div>
		<div><b><?php echo esc_html( $bps_t( 'Achterkant', 'Dos' ) ); ?></b><?php echo esc_html( $bps_st['backing'] ); ?></div>
	</div>
	<?php if ( ! empty( $bps_nt['review'] ) ) : ?>
		<p class="note">
			<?php
			echo esc_html(
				$bps_t( 'Manuele controle nodig: bevat ', 'Contrôle manuel nécessaire : contient ' ) . implode( ', ', array_map( 'strval', (array) ( $bps_nt['review_words'] ?? array() ) ) )
				. ( '' !== (string) ( $bps_nt['org_note'] ?? '' ) ? ' · ' . $bps_t( 'organisatie volgens klant: ', 'organisation selon le client : ' ) . (string) $bps_nt['org_note'] : '' )
			);
			?>
		</p>
	<?php endif; ?>
	<table>
		<thead><tr>
			<th>#</th>
			<th><?php echo esc_html( $bps_t( 'Voorbeeld', 'Aperçu' ) ); ?></th>
			<th><?php echo esc_html( $bps_t( 'Tekst', 'Texte' ) ); ?></th>
			<th><?php echo esc_html( $bps_t( 'Letterhoogte', 'Hauteur lettres' ) ); ?></th>
			<th><?php echo esc_html( $bps_t( 'Aantal', 'Qté' ) ); ?></th>
			<th><?php echo esc_html( $bps_t( 'Status', 'Statut' ) ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $d['rows'] as $bps_i => $bps_r ) : ?>
			<tr>
				<td class="n"><?php echo (int) $bps_r['nr']; ?></td>
				<td class="c">
					<canvas data-bps-nt-row="<?php echo (int) $bps_i; ?>" data-w="260" width="260" height="70" role="img" aria-label="<?php echo esc_attr( implode( ' / ', $bps_r['lines'] ) ); ?>"></canvas>
					<?php if ( $bps_r['bloodTape'] ) : ?>
						<canvas data-bps-nt-blood="<?php echo (int) $bps_i; ?>" width="140" height="70" role="img" aria-label="<?php echo esc_attr( $bps_r['bloodTape']['text'] ); ?>"></canvas>
					<?php endif; ?>
				</td>
				<td><?php echo implode( '<br>', array_map( 'esc_html', $bps_r['lines'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- each line escaped. ?></td>
				<td>
					<?php echo esc_html( Production::num( (float) $bps_r['capMm'] ) . ' mm' ); ?>
					<?php if ( $bps_r['bloodTape'] ) : ?>
						<br><small><?php echo esc_html( $bps_r['bloodTape']['text'] . ': ' . Production::num( (float) $bps_r['bloodTape']['capMm'] ) . ' mm' ); ?></small>
					<?php endif; ?>
				</td>
				<td class="q"><?php echo (int) $bps_r['qty']; ?>×</td>
				<td class="s-<?php echo esc_attr( $bps_r['status'] ); ?>">
					<?php echo esc_html( ( $bps_sts[ $bps_r['status'] ] ?? $bps_r['status'] ) . ( $bps_r['review'] ? ' · ' . $bps_t( 'controle', 'contrôle' ) : '' ) ); ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p class="foot"><?php echo esc_html( $bps_t( 'Voorbeelden op schaal van het scherm; kleuren kunnen licht afwijken van garen en stof. Letterhoogte = hoogte van een hoofdletter.', 'Aperçus à l’échelle de l’écran ; les couleurs peuvent légèrement différer du fil et du tissu. Hauteur = hauteur d’une majuscule.' ) ); ?></p>
</div>
<?php echo Order_Tools::script_tag(); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_url. ?>
</body>
</html>
