<?php
/**
 * Tab "Prijzen". Variables in scope: $config, $defaults, $vat.
 *
 * @package BorduurPatchStudio
 */

use BorduurPatchStudio\Admin\Settings_Page as P;
use BorduurPatchStudio\Settings;

defined( 'ABSPATH' ) || exit;

$bps_priced   = (array) ( $config['sizes']['pricedCm'] ?? array( 4, 6, 8, 10, 12 ) );
$bps_examples = P::price_examples( $config );
$bps_check    = P::shop_check( $config );
$bps_vat_pct  = P::num( $vat * 100, 2 );
?>
<section class="bps-card bps-card--wide" aria-labelledby="bps-examples">
	<header class="bps-card__head">
		<h2 id="bps-examples"><?php esc_html_e( 'Zo ziet de klant het', 'borduur-patch-studio' ); ?></h2>
	</header>
	<?php if ( ! $bps_examples['available'] ) : ?>
		<p class="bps-help"><?php esc_html_e( 'De voorbeeldtabel verschijnt zodra de prijsmodule geladen is.', 'borduur-patch-studio' ); ?></p>
	<?php elseif ( ! $bps_examples['rows'] ) : ?>
		<p class="bps-help"><?php esc_html_e( 'Er staat geen enkele techniek op "online bestellen".', 'borduur-patch-studio' ); ?></p>
	<?php else : ?>
		<p class="bps-help">
			<?php
			printf(
				/* translators: 1: size in cm, 2: VAT percentage */
				esc_html__( 'Totaal voor de klant bij %1$s cm met de standaardafwerking: alle eenmalige kosten, %2$s %% btw en verzending inbegrepen. Daaronder de prijs per stuk alles inbegrepen.', 'borduur-patch-studio' ),
				esc_html( P::num( $bps_examples['size'], 1 ) ),
				esc_html( $bps_vat_pct )
			);
			?>
		</p>
		<div class="bps-table-wrap">
		<table class="widefat striped bps-table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Techniek', 'borduur-patch-studio' ); ?></th>
				<?php foreach ( $bps_examples['qtys'] as $bps_q ) : ?>
					<th scope="col" class="num">
						<?php
						/* translators: %d: quantity */
						echo esc_html( sprintf( _n( '%d stuk', '%d stuks', $bps_q, 'borduur-patch-studio' ), $bps_q ) );
						?>
					</th>
				<?php endforeach; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $bps_examples['rows'] as $bps_row ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $bps_row['label'] ); ?></th>
					<?php foreach ( $bps_row['cells'] as $bps_cell ) : ?>
						<td class="num">
							<?php if ( isset( $bps_cell['note'] ) ) : ?>
								<span class="bps-muted"><?php echo esc_html( $bps_cell['note'] ); ?></span>
							<?php else : ?>
								<strong><?php echo esc_html( P::money( $bps_cell['total'] ) ); ?></strong>
								<span class="bps-sub">
								<?php
								/* translators: %s: price per piece */
								echo esc_html( sprintf( __( '%s/st.', 'borduur-patch-studio' ), P::money( $bps_cell['perPiece'] ) ) );
								?>
								</span>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<p class="bps-help"><?php esc_html_e( 'De tabel wordt bijgewerkt nadat je bewaart.', 'borduur-patch-studio' ); ?></p>
	<?php endif; ?>
</section>

<section class="bps-card bps-card--wide" aria-labelledby="bps-base">
	<header class="bps-card__head"><h2 id="bps-base"><?php esc_html_e( 'Prijs per stuk', 'borduur-patch-studio' ); ?></h2></header>
	<p class="bps-help">
		<?php
		esc_html_e( 'Per maat (langste zijde), zonder btw. Een tussenmaat krijgt de prijs van de volgende maat, net zoals in je webshop. Leeg laten bij een techniek op offerte is prima.', 'borduur-patch-studio' );
		?>
	</p>
	<div class="bps-table-wrap">
	<table class="bps-table bps-table--inputs">
		<thead><tr>
			<th scope="col"><?php esc_html_e( 'Techniek', 'borduur-patch-studio' ); ?></th>
			<?php foreach ( $bps_priced as $bps_cm ) : ?>
				<th scope="col"><?php echo esc_html( P::num( $bps_cm, 1 ) . ' cm' ); ?></th>
			<?php endforeach; ?>
			<th scope="col"><?php esc_html_e( 'Staffelkorting', 'borduur-patch-studio' ); ?></th>
		</tr></thead>
		<tbody>
		<?php
		foreach ( P::techniques( $config ) as $bps_id => $bps_t ) :
			$bps_def = $defaults['techniques'][ $bps_id ]['basePrice'] ?? null;
			?>
			<tr class="<?php echo empty( $bps_t['enabled'] ) ? 'bps-row-off' : ''; ?>">
				<th scope="row">
					<?php echo esc_html( Settings::label( $bps_t['label'] ?? $bps_id ) ); ?>
					<?php if ( 'order' !== ( $bps_t['mode'] ?? '' ) ) : ?>
						<span class="bps-pill bps-pill--quote"><?php esc_html_e( 'offerte', 'borduur-patch-studio' ); ?></span>
					<?php endif; ?>
				</th>
				<?php foreach ( $bps_priced as $bps_cm ) : ?>
					<td>
						<?php
						$bps_key = (string) $bps_cm;
						$bps_val = is_array( $bps_t['basePrice'] ?? null ) ? ( $bps_t['basePrice'][ $bps_key ] ?? null ) : null;
						P::number(
							array( 'techniques', $bps_id, 'basePrice', $bps_key ),
							$bps_val,
							sprintf(
								/* translators: 1: technique, 2: size */
								__( '%1$s %2$s cm', 'borduur-patch-studio' ),
								Settings::label( $bps_t['label'] ?? $bps_id ),
								P::num( $bps_cm, 1 )
							),
							array(
								'money'   => true,
								'default' => is_array( $bps_def ) ? ( $bps_def[ $bps_key ] ?? null ) : null,
							)
						);
						?>
					</td>
				<?php endforeach; ?>
				<td>
					<?php
					P::toggle(
						array( 'techniques', $bps_id, 'tiers' ),
						! empty( $bps_t['tiers'] ),
						__( 'Geldt', 'borduur-patch-studio' )
					);
					?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
</section>

<div class="bps-grid">
<section class="bps-card" aria-labelledby="bps-tiers">
	<header class="bps-card__head"><h2 id="bps-tiers"><?php esc_html_e( 'Staffelkorting', 'borduur-patch-studio' ); ?></h2></header>
	<p class="bps-help"><?php esc_html_e( 'Korting op de prijs per stuk vanaf een aantal. Laat een rij leeg om ze te verwijderen.', 'borduur-patch-studio' ); ?></p>
	<table class="bps-table bps-table--inputs bps-tiers" data-bps-rows>
		<thead><tr><th scope="col"><?php esc_html_e( 'Vanaf', 'borduur-patch-studio' ); ?></th><th scope="col"><?php esc_html_e( 'Korting', 'borduur-patch-studio' ); ?></th></tr></thead>
		<tbody>
		<?php
		$bps_steps   = array_values( (array) ( $config['tiers']['steps'] ?? array() ) );
		$bps_steps[] = array(
			'min'      => '',
			'discount' => '',
		);
		foreach ( $bps_steps as $bps_i => $bps_s ) :
			$bps_first = 0 === $bps_i;
			?>
			<tr>
				<td>
					<?php
					if ( $bps_first ) {
						echo '<input type="hidden" name="' . esc_attr( P::name( array( 'tiers', 'steps', $bps_i, 'min' ) ) ) . '" value="1"><span class="bps-muted">' . esc_html__( '1 stuk', 'borduur-patch-studio' ) . '</span>';
					} else {
						P::number(
							array( 'tiers', 'steps', $bps_i, 'min' ),
							$bps_s['min'],
							__( 'Vanaf aantal', 'borduur-patch-studio' ),
							array(
								'unit'     => __( 'stuks', 'borduur-patch-studio' ),
								'decimals' => 0,
							)
						);
					}
					?>
				</td>
				<td>
					<?php
					if ( $bps_first ) {
						echo '<input type="hidden" name="' . esc_attr( P::name( array( 'tiers', 'steps', $bps_i, 'discount' ) ) ) . '" value="0"><span class="bps-muted">' . esc_html__( 'geen korting', 'borduur-patch-studio' ) . '</span>';
					} else {
						P::number(
							array( 'tiers', 'steps', $bps_i, 'discount' ),
							'' === $bps_s['discount'] ? '' : (float) $bps_s['discount'] * 100,
							__( 'Korting', 'borduur-patch-studio' ),
							array(
								'unit'     => '%',
								'decimals' => 2,
							)
						);
					}
					?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	P::toggle(
		array( 'tiers', 'pooled' ),
		! empty( $config['tiers']['pooled'] ),
		__( 'Alle patches in de winkelmand tellen samen voor de staffel', 'borduur-patch-studio' ),
		__( 'Zoals in je webshop: 10 stuks van ontwerp A en 15 van ontwerp B geven samen de korting van 25 stuks.', 'borduur-patch-studio' )
	);
	?>
</section>

<section class="bps-card" aria-labelledby="bps-fees">
	<header class="bps-card__head"><h2 id="bps-fees"><?php esc_html_e( 'Eenmalige kosten', 'borduur-patch-studio' ); ?></h2></header>
	<p class="bps-help"><?php esc_html_e( 'Zonder btw. De klant ziet deze kosten apart, met uitleg waarom ze er zijn.', 'borduur-patch-studio' ); ?></p>
	<?php
	foreach ( (array) ( $config['fees']['groups'] ?? array() ) as $bps_gid => $bps_g ) {
		$bps_used = array();
		foreach ( P::techniques( $config ) as $bps_id => $bps_t ) {
			if ( ( $bps_t['feeGroup'] ?? null ) === $bps_gid ) {
				$bps_used[] = Settings::label( $bps_t['label'] ?? $bps_id );
			}
		}
		P::number(
			array( 'fees', 'groups', $bps_gid, 'amount' ),
			$bps_g['amount'] ?? 0,
			Settings::label( $bps_g['label'] ?? $bps_gid ),
			array(
				'money'   => true,
				'default' => $defaults['fees']['groups'][ $bps_gid ]['amount'] ?? null,
				'help'    => $bps_used ? sprintf(
					/* translators: %s: technique names */
					__( 'Voor: %s', 'borduur-patch-studio' ),
					implode( ', ', $bps_used )
				) : '',
			)
		);
	}
	P::select(
		array( 'fees', 'scope' ),
		(string) ( $config['fees']['scope'] ?? 'order' ),
		__( 'Hoe vaak aanrekenen?', 'borduur-patch-studio' ),
		array(
			'order'  => __( 'Eén keer per bestelling (zoals je kassa nu)', 'borduur-patch-studio' ),
			'design' => __( 'Eén keer per ontwerp', 'borduur-patch-studio' ),
		)
	);
	?>
</section>

<section class="bps-card" aria-labelledby="bps-ship">
	<header class="bps-card__head"><h2 id="bps-ship"><?php esc_html_e( 'Verzending en btw', 'borduur-patch-studio' ); ?></h2></header>
	<p class="bps-help"><?php esc_html_e( 'Enkel voor de schatting in de tool. WooCommerce berekent de echte verzending in de kassa; hou beide gelijk.', 'borduur-patch-studio' ); ?></p>
	<?php
	P::number(
		array( 'shipping', 'flat' ),
		$config['shipping']['flat'] ?? 0,
		__( 'Verzendkost', 'borduur-patch-studio' ),
		array(
			'money'   => true,
			'default' => $defaults['shipping']['flat'] ?? null,
		)
	);
	P::number(
		array( 'shipping', 'freeFromInclVat' ),
		$config['shipping']['freeFromInclVat'] ?? 0,
		__( 'Gratis verzending vanaf (met btw)', 'borduur-patch-studio' ),
		array(
			'unit'    => '€',
			'default' => $defaults['shipping']['freeFromInclVat'] ?? null,
		)
	);
	P::number(
		array( 'shipping', 'hintFromInclVat' ),
		$config['shipping']['hintFromInclVat'] ?? 0,
		__( '"Nog € x tot gratis verzending" tonen vanaf (met btw)', 'borduur-patch-studio' ),
		array(
			'unit'    => '€',
			'default' => $defaults['shipping']['hintFromInclVat'] ?? null,
		)
	);
	P::toggle(
		array( 'vat', 'showExclToggle' ),
		! empty( $config['vat']['showExclToggle'] ),
		__( 'Bedrijven kunnen prijzen zonder btw bekijken', 'borduur-patch-studio' ),
		__( 'Het hoofdbedrag blijft altijd met btw, zoals de wet vraagt voor consumenten.', 'borduur-patch-studio' )
	);
	?>
</section>
</div>

<section class="bps-card bps-card--wide" aria-labelledby="bps-options">
	<header class="bps-card__head"><h2 id="bps-options"><?php esc_html_e( 'Afwerking en extra\'s', 'borduur-patch-studio' ); ?></h2></header>
	<p class="bps-help"><?php esc_html_e( 'Meerprijs zonder btw. "Per stuk" telt mee per patch, "per bestelregel" één keer, een percentage past de stukprijs aan. Zet uit wat je niet aanbiedt.', 'borduur-patch-studio' ); ?></p>
	<?php
	$bps_groups   = (array) ( $config['optionGroups'] ?? array() );
	$bps_by_group = array();
	foreach ( (array) ( $config['options'] ?? array() ) as $bps_o ) {
		if ( is_array( $bps_o ) && isset( $bps_o['id'] ) ) {
			$bps_by_group[ $bps_o['group'] ?? '' ][] = $bps_o;
		}
	}
	$bps_def_opts = array();
	foreach ( (array) ( $defaults['options'] ?? array() ) as $bps_o ) {
		if ( is_array( $bps_o ) && isset( $bps_o['id'] ) ) {
			$bps_def_opts[ $bps_o['id'] ] = $bps_o;
		}
	}
	$bps_types = array(
		'per_piece' => __( 'per stuk', 'borduur-patch-studio' ),
		'per_line'  => __( 'per bestelregel', 'borduur-patch-studio' ),
		'percent'   => __( 'op de stukprijs', 'borduur-patch-studio' ),
	);
	$bps_tech_labels = array();
	foreach ( P::techniques( $config ) as $bps_id => $bps_t ) {
		$bps_tech_labels[ $bps_id ] = Settings::label( $bps_t['label'] ?? $bps_id );
	}
	?>
	<div class="bps-table-wrap">
	<table class="bps-table bps-table--inputs bps-options">
		<thead><tr>
			<th scope="col"><?php esc_html_e( 'Optie', 'borduur-patch-studio' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Voor', 'borduur-patch-studio' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Meerprijs', 'borduur-patch-studio' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Aangeboden', 'borduur-patch-studio' ); ?></th>
		</tr></thead>
		<?php foreach ( $bps_by_group as $bps_gid => $bps_opts ) : ?>
			<tbody>
				<tr class="bps-group-row"><th colspan="4" scope="rowgroup"><?php echo esc_html( Settings::label( $bps_groups[ $bps_gid ]['label'] ?? $bps_gid ) ); ?></th></tr>
				<?php
				foreach ( $bps_opts as $bps_o ) :
					$bps_oid   = (string) $bps_o['id'];
					$bps_type  = (string) ( $bps_o['price']['type'] ?? 'per_piece' );
					$bps_pct   = 'percent' === $bps_type;
					$bps_amt   = (float) ( $bps_o['price']['amount'] ?? 0 );
					$bps_damt  = $bps_def_opts[ $bps_oid ]['price']['amount'] ?? null;
					$bps_techs = array_map( static fn( $x ) => $bps_tech_labels[ $x ] ?? $x, (array) ( $bps_o['techniques'] ?? array() ) );
					?>
					<tr>
						<th scope="row"><?php echo esc_html( Settings::label( $bps_o['label'] ?? $bps_oid ) ); ?></th>
						<td class="bps-muted"><?php echo esc_html( implode( ', ', $bps_techs ) ); ?></td>
						<td>
							<?php
							P::number(
								array( 'options', $bps_oid, 'amount' ),
								$bps_pct ? $bps_amt * 100 : $bps_amt,
								Settings::label( $bps_o['label'] ?? $bps_oid ),
								array(
									'money'   => ! $bps_pct,
									'unit'    => $bps_pct ? '%' : $bps_types[ $bps_type ] ?? '',
									'default' => null === $bps_damt ? null : ( $bps_pct ? $bps_damt * 100 : $bps_damt ),
								)
							);
							foreach ( (array) ( $bps_o['priceByTechnique'] ?? array() ) as $bps_tid => $bps_tp ) {
								$bps_tpct = 'percent' === ( $bps_tp['type'] ?? '' );
								P::number(
									array( 'options', $bps_oid, 'byTech', $bps_tid ),
									$bps_tpct ? (float) ( $bps_tp['amount'] ?? 0 ) * 100 : ( $bps_tp['amount'] ?? 0 ),
									sprintf(
										/* translators: %s: technique */
										__( 'Bij %s', 'borduur-patch-studio' ),
										$bps_tech_labels[ $bps_tid ] ?? $bps_tid
									),
									array(
										'money' => ! $bps_tpct,
										'unit'  => $bps_tpct ? '%' : ( $bps_types[ $bps_tp['type'] ?? 'per_piece' ] ?? '' ),
									)
								);
							}
							?>
						</td>
						<td>
							<?php P::toggle( array( 'options', $bps_oid, 'enabled' ), false !== ( $bps_o['enabled'] ?? true ), __( 'Ja', 'borduur-patch-studio' ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		<?php endforeach; ?>
	</table>
	</div>
</section>

<?php if ( $bps_check ) : ?>
<section class="bps-card bps-card--wide" aria-labelledby="bps-check">
	<header class="bps-card__head"><h2 id="bps-check"><?php esc_html_e( 'Controle met je webshop', 'borduur-patch-studio' ); ?></h2></header>
	<p class="bps-help"><?php esc_html_e( 'We vergelijken de prijzen hierboven met je bestaande patchproducten, zodat de tool nooit iets anders zegt dan je winkel.', 'borduur-patch-studio' ); ?></p>
	<div class="bps-table-wrap">
	<table class="widefat striped bps-table">
		<thead><tr>
			<th scope="col"><?php esc_html_e( 'Techniek', 'borduur-patch-studio' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Maat', 'borduur-patch-studio' ); ?></th>
			<th scope="col" class="num"><?php esc_html_e( 'Patch Studio', 'borduur-patch-studio' ); ?></th>
			<th scope="col" class="num"><?php esc_html_e( 'Webshop', 'borduur-patch-studio' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Resultaat', 'borduur-patch-studio' ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $bps_check as $bps_r ) : ?>
			<tr>
				<td><?php echo esc_html( $bps_r['label'] ); ?></td>
				<td><?php echo esc_html( $bps_r['size'] . ' cm' ); ?></td>
				<td class="num"><?php echo esc_html( P::money( $bps_r['config'] ) ); ?></td>
				<td class="num"><?php echo null === $bps_r['shop'] ? '<span class="bps-muted">' . esc_html__( 'niet gevonden', 'borduur-patch-studio' ) . '</span>' : esc_html( P::money( $bps_r['shop'] ) ); ?></td>
				<td>
					<?php if ( $bps_r['ok'] ) : ?>
						<span class="bps-ok">&#10003; <?php esc_html_e( 'Gelijk', 'borduur-patch-studio' ); ?></span>
					<?php elseif ( null === $bps_r['shop'] ) : ?>
						<span class="bps-muted"><?php esc_html_e( 'Product bestaat niet op deze site', 'borduur-patch-studio' ); ?></span>
					<?php else : ?>
						<span class="bps-warn">! <?php esc_html_e( 'Verschillend', 'borduur-patch-studio' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
</section>
<?php endif; ?>
