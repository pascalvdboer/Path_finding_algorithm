<?php
/**
 * Tab "Algemeen". Variables in scope: $config, $defaults.
 *
 * @package BorduurPatchStudio
 */

use BorduurPatchStudio\Admin\Settings_Page as P;
use BorduurPatchStudio\Settings;

defined( 'ABSPATH' ) || exit;

$bps_w  = (array) ( $config['woo'] ?? array() );
$bps_r  = (array) ( $config['retention'] ?? array() );
$bps_dr = (array) ( $defaults['retention'] ?? array() );

$bps_feature_labels = array(
	'textLayers'      => array( __( 'Tekst toevoegen op de patch', 'borduur-patch-studio' ), '' ),
	'garmentView'     => array( __( '"Bekijk op een kledingstuk"', 'borduur-patch-studio' ), __( 'Toont de patch op ware grootte op een polo, pet of jas.', 'borduur-patch-studio' ) ),
	'compare'         => array( __( 'Technieken naast elkaar vergelijken', 'borduur-patch-studio' ), '' ),
	'saveDesign'      => array( __( '"Bewaar & mail mijn ontwerp"', 'borduur-patch-studio' ), __( 'De klant krijgt een link om later verder te gaan, zonder account.', 'borduur-patch-studio' ) ),
	'quote'           => array( __( 'Offerte aanvragen vanuit de tool', 'borduur-patch-studio' ), '' ),
	'exclVatToggle'   => array( __( 'Schakelaar "prijzen zonder btw"', 'borduur-patch-studio' ), '' ),
	'cartPoolHint'    => array( __( 'Melding over patches die al in de winkelmand zitten', 'borduur-patch-studio' ), __( 'Bv. "Samen zit je in de 10 %-trede".', 'borduur-patch-studio' ) ),
	'analyticsEvents' => array( __( 'Gebeurtenissen doorgeven aan Google Analytics (dataLayer)', 'borduur-patch-studio' ), __( 'Enkel na toestemming via je cookiebanner. Zonder persoonsgegevens.', 'borduur-patch-studio' ) ),
);
?>
<section class="bps-card bps-card--wide" aria-labelledby="bps-status">
	<header class="bps-card__head"><h2 id="bps-status"><?php esc_html_e( 'Status', 'borduur-patch-studio' ); ?></h2></header>
	<ul class="bps-status">
		<?php foreach ( P::status_checks() as $bps_check ) : ?>
			<?php
			$bps_state = null === $bps_check['ok'] ? 'unknown' : ( $bps_check['ok'] ? 'ok' : 'bad' );
			$bps_icon  = array(
				'ok'      => '&#10003;',
				'bad'     => '!',
				'unknown' => '&#8226;',
			);
			$bps_word  = array(
				'ok'      => __( 'In orde', 'borduur-patch-studio' ),
				'bad'     => __( 'Actie nodig', 'borduur-patch-studio' ),
				'unknown' => __( 'Onbekend', 'borduur-patch-studio' ),
			);
			?>
			<li class="bps-status__item is-<?php echo esc_attr( $bps_state ); ?>">
				<span class="bps-status__icon" aria-hidden="true"><?php echo $bps_icon[ $bps_state ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static entity. ?></span>
				<span class="screen-reader-text"><?php echo esc_html( $bps_word[ $bps_state ] ); ?>:</span>
				<strong><?php echo esc_html( $bps_check['label'] ); ?></strong>
				<span><?php echo esc_html( $bps_check['detail'] ); ?></span>
				<?php if ( ! empty( $bps_check['link'] ) ) : ?>
					<a href="<?php echo esc_url( $bps_check['link'] ); ?>"><?php echo esc_html( $bps_check['link_label'] ?? __( 'Bekijken', 'borduur-patch-studio' ) ); ?></a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
		<li class="bps-status__item is-ok">
			<span class="bps-status__icon" aria-hidden="true">[ ]</span>
			<strong><?php esc_html_e( 'Shortcode', 'borduur-patch-studio' ); ?></strong>
			<?php P::shortcode_chip(); ?>
			<span class="bps-muted"><?php esc_html_e( 'Opties: techniek="embroidered" maat="8" aantal="25" vorm="circle"', 'borduur-patch-studio' ); ?></span>
		</li>
	</ul>
</section>

<div class="bps-grid">
<section class="bps-card" aria-labelledby="bps-shop">
	<header class="bps-card__head"><h2 id="bps-shop"><?php esc_html_e( 'Winkel', 'borduur-patch-studio' ); ?></h2></header>
	<?php
	P::select(
		array( 'woo', 'cartMode' ),
		'own_product',
		__( 'Hoe komt de patch in de winkelmand?', 'borduur-patch-studio' ),
		array(
			'own_product'       => __( 'Via het verborgen product "Patch op maat" (aanbevolen)', 'borduur-patch-studio' ),
			'existing_products' => '!' . __( 'Via je bestaande patchproducten (volgt later)', 'borduur-patch-studio' ),
		),
		__( 'Patch Studio rekent zelf de prijs uit met de tabel bij Prijzen, dus kortingsplugins of extra velden zijn niet nodig.', 'borduur-patch-studio' )
	);
	P::select(
		array( 'woo', 'productVisibility' ),
		(string) ( $bps_w['productVisibility'] ?? 'hidden' ),
		__( 'Zichtbaarheid van dat product in je winkel', 'borduur-patch-studio' ),
		array(
			'hidden'  => __( 'Verborgen (aanbevolen)', 'borduur-patch-studio' ),
			'search'  => __( 'Enkel in zoekresultaten', 'borduur-patch-studio' ),
			'catalog' => __( 'Enkel in de winkel', 'borduur-patch-studio' ),
			'visible' => __( 'Overal', 'borduur-patch-studio' ),
		)
	);
	P::toggle( array( 'woo', 'redirectToCart' ), ! empty( $bps_w['redirectToCart'] ), __( 'Na "Bestellen" meteen naar de winkelmand', 'borduur-patch-studio' ), __( 'Uit: de klant blijft in de tool en ziet een bevestiging.', 'borduur-patch-studio' ) );
	P::toggle(
		array( 'woo', 'skipFeeIfLegacyInCart' ),
		! empty( $bps_w['skipFeeIfLegacyInCart'] ),
		__( 'Eenmalige kosten niet dubbel aanrekenen', 'borduur-patch-studio' ),
		__( 'Als de klant ook al een gewoon patchproduct met borduurkaart in de winkelmand heeft.', 'borduur-patch-studio' )
	);
	P::text(
		array( 'woo', 'quoteRecipient' ),
		$bps_w['quoteRecipient'] ?? '',
		__( 'Offerteaanvragen sturen naar', 'borduur-patch-studio' ),
		array(
			'type'        => 'email',
			'placeholder' => get_option( 'admin_email' ),
			'help'        => __( 'Leeg = het e-mailadres van de website.', 'borduur-patch-studio' ),
		)
	);
	P::number(
		array( 'woo', 'maxUploadMb' ),
		$bps_w['maxUploadMb'] ?? 25,
		__( 'Grootste bestand dat een klant mag uploaden', 'borduur-patch-studio' ),
		array(
			'unit'     => 'MB',
			'decimals' => 0,
			'default'  => $defaults['woo']['maxUploadMb'] ?? null,
			'help'     => sprintf(
				/* translators: %s: server upload limit */
				__( 'Je server laat maximaal %s toe.', 'borduur-patch-studio' ),
				size_format( wp_max_upload_size() )
			),
		)
	);
	?>
</section>

<section class="bps-card" aria-labelledby="bps-features">
	<header class="bps-card__head"><h2 id="bps-features"><?php esc_html_e( 'Onderdelen van de tool', 'borduur-patch-studio' ); ?></h2></header>
	<?php
	foreach ( (array) ( $config['features'] ?? array() ) as $bps_f => $bps_on ) {
		$bps_l = $bps_feature_labels[ $bps_f ] ?? array( $bps_f, '' );
		P::toggle( array( 'features', $bps_f ), (bool) $bps_on, $bps_l[0], $bps_l[1] );
	}
	?>
	<h3><?php esc_html_e( 'Kassa', 'borduur-patch-studio' ); ?></h3>
	<?php
	$bps_l = (array) ( $config['legal'] ?? array() );
	P::toggle(
		array( 'legal', 'fixOrderButton' ),
		! empty( $bps_l['fixOrderButton'] ),
		__( 'Bestelknop in de kassa heet "Bestellen en betalen"', 'borduur-patch-studio' ),
		__( 'Wettelijk verplicht in de EU: de knop moet duidelijk zeggen dat de klant een betaling aangaat.', 'borduur-patch-studio' )
	);
	P::toggle(
		array( 'legal', 'showWithdrawalNotice' ),
		! empty( $bps_l['showWithdrawalNotice'] ),
		__( 'Melden dat maatwerk niet herroepen kan worden', 'borduur-patch-studio' ),
		__( 'Een patch op maat valt buiten het herroepingsrecht, op voorwaarde dat je dat vooraf duidelijk zegt.', 'borduur-patch-studio' )
	);
	?>
</section>

<section class="bps-card" aria-labelledby="bps-retention">
	<header class="bps-card__head"><h2 id="bps-retention"><?php esc_html_e( 'Privacy: hoe lang bewaren we ontwerpen?', 'borduur-patch-studio' ); ?></h2></header>
	<p class="bps-help"><?php esc_html_e( 'Daarna worden bestanden automatisch verwijderd. Kortere termijnen zijn beter voor de privacy van je klanten.', 'borduur-patch-studio' ); ?></p>
	<?php
	$bps_ret = array(
		'orphanDays' => array( __( 'Ontwerpen die nooit besteld werden', 'borduur-patch-studio' ), __( 'dagen', 'borduur-patch-studio' ) ),
		'savedDays'  => array( __( 'Bewaarde ontwerpen (via "Bewaar & mail")', 'borduur-patch-studio' ), __( 'dagen', 'borduur-patch-studio' ) ),
		'quoteDays'  => array( __( 'Offerteaanvragen', 'borduur-patch-studio' ), __( 'dagen', 'borduur-patch-studio' ) ),
		'orderYears' => array( __( 'Bestelde ontwerpen (voor herbestellingen)', 'borduur-patch-studio' ), __( 'jaar', 'borduur-patch-studio' ) ),
	);
	foreach ( $bps_ret as $bps_k => $bps_info ) {
		P::number(
			array( 'retention', $bps_k ),
			$bps_r[ $bps_k ] ?? null,
			$bps_info[0],
			array(
				'unit'     => $bps_info[1],
				'decimals' => 0,
				'default'  => $bps_dr[ $bps_k ] ?? null,
			)
		);
	}
	?>
	<h3><?php esc_html_e( 'Plugin verwijderen', 'borduur-patch-studio' ); ?></h3>
	<?php
	P::toggle(
		array( 'admin', 'deleteDataOnUninstall' ),
		! empty( $config['admin']['deleteDataOnUninstall'] ),
		__( 'Bij het verwijderen van de plugin ook alle ontwerpen, offertes en bestanden wissen', 'borduur-patch-studio' ),
		__( 'Standaard uit: je bestellingen verwijzen naar die ontwerpen. Instellingen worden altijd gewist.', 'borduur-patch-studio' )
	);
	?>
</section>
</div>
