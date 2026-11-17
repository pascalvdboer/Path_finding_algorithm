<?php
/**
 * Tab "Teksten & beloftes". Variables in scope: $config, $defaults.
 *
 * @package BorduurPatchStudio
 */

use BorduurPatchStudio\Admin\Settings_Page as P;
use BorduurPatchStudio\Settings;

defined( 'ABSPATH' ) || exit;

$bps_p  = (array) ( $config['promises'] ?? array() );
$bps_dp = (array) ( $defaults['promises'] ?? array() );
$bps_c  = (array) ( $config['contact'] ?? array() );
?>
<div class="bps-grid">
<section class="bps-card" aria-labelledby="bps-proof">
	<header class="bps-card__head"><h2 id="bps-proof"><?php esc_html_e( 'Digitale proef', 'borduur-patch-studio' ); ?></h2></header>
	<?php
	P::number(
		array( 'promises', 'proofDays' ),
		$bps_p['proofDays'] ?? null,
		__( 'Proef in de mailbox binnen', 'borduur-patch-studio' ),
		array(
			'unit'        => __( 'werkdagen', 'borduur-patch-studio' ),
			'decimals'    => 0,
			'placeholder' => __( 'niet beloven', 'borduur-patch-studio' ),
			'help'        => __( 'Leeg = de klant leest "Zo snel mogelijk krijg je je digitale proef". Vul enkel een aantal in dat je altijd haalt.', 'borduur-patch-studio' ),
		)
	);
	P::text_pair( array( 'promises', 'proof' ), $bps_p['proof'] ?? array(), __( 'Belofte over de proef', 'borduur-patch-studio' ) );
	P::toggle(
		array( 'promises', 'cancelUntilProof' ),
		! empty( $bps_p['cancelUntilProof'] ),
		__( 'Kosteloos annuleren tot de klant de proef goedkeurt', 'borduur-patch-studio' ),
		__( 'Zet dit enkel aan als je het echt zo doet: de tool belooft het dan bij het bestellen.', 'borduur-patch-studio' )
	);
	P::toggle(
		array( 'promises', 'payAfterProof' ),
		! empty( $bps_p['payAfterProof'] ),
		__( 'Betalen pas na goedkeuring van de proef', 'borduur-patch-studio' ),
		__( 'Enkel aanzetten als je kassa dat ook zo regelt.', 'borduur-patch-studio' )
	);
	?>
</section>

<section class="bps-card" aria-labelledby="bps-promise">
	<header class="bps-card__head"><h2 id="bps-promise"><?php esc_html_e( 'Service en levertijd', 'borduur-patch-studio' ); ?></h2></header>
	<?php
	P::toggle( array( 'promises', 'fromOne' ), ! empty( $bps_p['fromOne'] ), __( '"Vanaf 1 stuk" tonen', 'borduur-patch-studio' ) );
	P::text_pair( array( 'promises', 'fromOneText' ), $bps_p['fromOneText'] ?? array(), __( 'Tekst "vanaf 1 stuk"', 'borduur-patch-studio' ) );
	P::text_pair( array( 'promises', 'responseTime' ), $bps_p['responseTime'] ?? array(), __( 'Hoe snel je antwoordt', 'borduur-patch-studio' ) );
	P::text_pair(
		array( 'promises', 'leadTimeFallback' ),
		$bps_p['leadTimeFallback'] ?? array(),
		__( 'Levertijd als er per techniek niets is ingevuld', 'borduur-patch-studio' )
	);
	P::toggle(
		array( 'promises', 'reorderKeepsSetup' ),
		! empty( $bps_p['reorderKeepsSetup'] ),
		__( 'Bij herbestelling vervallen de eenmalige kosten', 'borduur-patch-studio' ),
		__( 'De tool vermeldt dit dan als voordeel. De kassa rekent ze vandaag wel opnieuw aan, dus laat uit tot dat geregeld is.', 'borduur-patch-studio' )
	);
	?>
</section>
</div>

<section class="bps-card bps-card--wide" aria-labelledby="bps-lead">
	<header class="bps-card__head"><h2 id="bps-lead"><?php esc_html_e( 'Levertijd per techniek', 'borduur-patch-studio' ); ?></h2></header>
	<p class="bps-help"><?php esc_html_e( 'Bijvoorbeeld "± 2 weken na goedkeuring van je proef". Leeg = de algemene levertijdzin hierboven.', 'borduur-patch-studio' ); ?></p>
	<div class="bps-grid bps-grid--tight">
	<?php
	foreach ( P::techniques( $config ) as $bps_id => $bps_t ) {
		if ( empty( $bps_t['enabled'] ) ) {
			continue;
		}
		P::text_pair( array( 'techniques', $bps_id, 'leadTime' ), $bps_t['leadTime'] ?? array(), Settings::label( $bps_t['label'] ?? $bps_id ) );
	}
	?>
	</div>
</section>

<div class="bps-grid">
<section class="bps-card" aria-labelledby="bps-contact">
	<header class="bps-card__head"><h2 id="bps-contact"><?php esc_html_e( 'Contact', 'borduur-patch-studio' ); ?></h2></header>
	<?php
	P::text( array( 'contact', 'phone' ), $bps_c['phone'] ?? '', __( 'Telefoon', 'borduur-patch-studio' ), array( 'type' => 'tel' ) );
	P::text(
		array( 'contact', 'whatsapp' ),
		$bps_c['whatsapp'] ?? '',
		__( 'WhatsApp-nummer (internationaal, enkel cijfers)', 'borduur-patch-studio' ),
		array(
			'type'        => 'tel',
			'placeholder' => '32491063201',
		)
	);
	P::text( array( 'contact', 'email' ), $bps_c['email'] ?? '', __( 'E-mail', 'borduur-patch-studio' ), array( 'type' => 'email' ) );
	P::text( array( 'contact', 'city' ), $bps_c['city'] ?? '', __( 'Gemeente atelier', 'borduur-patch-studio' ) );
	P::text( array( 'contact', 'quoteUrl' ), $bps_c['quoteUrl'] ?? '', __( 'Pagina offerte aanvragen', 'borduur-patch-studio' ), array( 'placeholder' => '/offerte-aanvragen/' ) );
	P::text( array( 'contact', 'privacyUrl' ), $bps_c['privacyUrl'] ?? '', __( 'Pagina privacybeleid', 'borduur-patch-studio' ) );
	P::text( array( 'contact', 'termsUrl' ), $bps_c['termsUrl'] ?? '', __( 'Pagina algemene voorwaarden', 'borduur-patch-studio' ) );
	?>
</section>

<section class="bps-card" aria-labelledby="bps-trust">
	<header class="bps-card__head"><h2 id="bps-trust"><?php esc_html_e( 'Klantgetuigenis en actie', 'borduur-patch-studio' ); ?></h2></header>
	<p class="bps-help"><?php esc_html_e( 'Toon enkel een echte getuigenis van een echte klant, met diens toestemming. Patch Studio toont nooit sterren, nep-reviews of aftelklokken.', 'borduur-patch-studio' ); ?></p>
	<?php
	$bps_tm = (array) ( $bps_p['testimonial'] ?? array() );
	P::toggle( array( 'promises', 'testimonial', 'enabled' ), ! empty( $bps_tm['enabled'] ), __( 'Getuigenis tonen bij het overzicht', 'borduur-patch-studio' ) );
	P::text( array( 'promises', 'testimonial', 'name' ), $bps_tm['name'] ?? '', __( 'Voornaam klant', 'borduur-patch-studio' ) );
	P::text_pair( array( 'promises', 'testimonial', 'role' ), $bps_tm['role'] ?? array(), __( 'Wie is het?', 'borduur-patch-studio' ) );
	P::text_pair( array( 'promises', 'testimonial', 'text' ), $bps_tm['text'] ?? array(), __( 'Wat zei de klant?', 'borduur-patch-studio' ), array( 'textarea' => true ) );

	$bps_dl = (array) ( $bps_p['deadline'] ?? array() );
	P::toggle(
		array( 'promises', 'deadline', 'enabled' ),
		! empty( $bps_dl['enabled'] ),
		__( 'Meldingsbalk tonen (bv. sluitingsperiode of besteldatum voor de feestdagen)', 'borduur-patch-studio' ),
		__( 'Enkel voor echte data. Zet hem uit zodra de datum voorbij is.', 'borduur-patch-studio' )
	);
	P::text_pair( array( 'promises', 'deadline', 'text' ), $bps_dl['text'] ?? array(), __( 'Tekst van de melding', 'borduur-patch-studio' ), array( 'textarea' => true ) );
	?>
</section>
</div>
