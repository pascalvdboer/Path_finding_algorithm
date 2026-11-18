<?php
/**
 * Cross-sell klantteksten (cs.*), tweeling van assets/src/crosssell/messages.js (zelfde sleutels en teksten;
 * tests/crosssell/crosssell.test.js vergelijkt beide). Vlaams NL met "je", FR met "vous".
 * Beheerders kunnen teksten overschrijven via de instellingen (`strings.nl` / `strings.fr`).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Crosssell;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BPS_TESTING' ) ) {
	exit;
}

/**
 * Teksten.
 */
final class Messages {

	const ALL = array(
		'nl' => array(
			'cs.added'              => 'Je patch zit in je winkelmand.',
			'cs.heading'            => 'Hetzelfde ontwerp ook op …',
			'cs.feeNote'            => 'Geen extra borduurkaart: die betaal je maar één keer per bestelling.',
			'cs.adjust.size.cap'    => 'Op de pet maken we je patch {cm} cm.',
			'cs.adjust.size.beanie' => 'Op de muts maken we je patch {cm} cm.',
			'cs.adjust.back'        => 'Je patch is groter dan {chest} cm: we naaien hem op de rug.',
			'cs.adjust.backAlt'     => 'Liever op de borst? Dan maken we hem {cm} cm.',
			'cs.adjust.simplify'    => 'We vereenvoudigen dit in de proef.',
			'cs.leather.photo'      => 'Een foto wordt een gravure in bruintinten, geen kleurenfoto.',
			'cs.leather.gradient'   => 'Kleurverlopen worden tinten in het leder, geen kleuren.',
			'cs.pvc.min'            => 'Vanaf {n} stuks (vaste mal). Minder nodig? Kies leder, vanaf 1 stuk.',
			'cs.pvc.lead'           => 'Levertijd {lead}.',
			'cs.pvc.mold'           => 'Eenmalig + {price} voor de mal.',
			'cs.pvc.moldShared'     => 'Geen extra mal: we gebruiken die van je PVC-patch.',
			'cs.mockup'             => 'Voorbeeld. Je krijgt een digitale proef vóór productie.',
			'cs.mockupFallback'     => 'Voorbeeld volgt in je proef',
			'cs.decline'            => 'Nee, bedankt',
			'cs.toCart'             => 'Naar de winkelmand',
			'cs.cartLine'           => 'Ook op pet, muts of in een kader?',
			'cs.cartLineBtn'        => 'Bekijk',
			'cs.add'                => 'Toevoegen',
			'cs.addN'               => 'Voeg {n} stuks toe',
			'cs.change'             => 'Wijzig',
			'cs.chooseSizes'        => 'Kies maten',
			'cs.writePlate'         => 'Tekst voor het plaatje',
			'cs.added.card'         => 'Toegevoegd ✓',
			'cs.undo'               => 'Ongedaan maken',
			'cs.adding'             => 'Even geduld…',
			'cs.perPiece'           => '{price} per stuk',
			'cs.per'                => 'per stuk',
			'cs.inclAll'            => 'incl. btw, patch en aanbrengen',
			'cs.inclVat'            => 'incl. btw',
			'cs.inclFrame'          => 'incl. btw en patch',
			'cs.color'              => 'Kleur',
			'cs.qty'                => 'Aantal',
			'cs.size'               => 'Maat',
			'cs.sizes'              => 'Aantal per maat',
			'cs.sizesHelp'          => 'Vul per maat in hoeveel je er wil. Leeg = 0.',
			'cs.model'              => 'Model',
			'cs.options'            => 'Opties',
			'cs.namesBack'          => 'Namen voor de achterkant (één per regel)',
			'cs.namesHelp'          => 'Eén naam per sleutelhanger. Het aantal volgt de lijst.',
			'cs.plate1'             => 'Regel 1 (bv. een naam)',
			'cs.plate2'             => 'Regel 2 (bv. een datum)',
			'cs.plateHelp'          => 'Max. {n} tekens per regel. Leeg laten mag.',
			'cs.placement.chest'    => 'Borst',
			'cs.placement.back'     => 'Rug',
			'cs.placement'          => 'Plaats',
			'cs.total'              => 'Samen {price}',
			'cs.minus'              => 'Eén minder',
			'cs.plus'               => 'Eén meer',
			'cs.close'              => 'Sluiten',
			'cs.err.generic'        => 'Toevoegen lukte niet. Probeer het nog eens.',
			'cs.err.sizes'          => 'Kies minstens één maat.',
			'cs.err.names'          => 'Vul minstens één naam in of zet de optie uit.',
			'cs.alt.cap_trucker'    => 'Je patch op een {color} trucker-pet, voorbeeld',
			'cs.alt.cap_snapback'   => 'Je patch op een {color} snapback, voorbeeld',
			'cs.alt.cap_dad'        => 'Je patch op een {color} pet, voorbeeld',
			'cs.alt.beanie_cuff'    => 'Je patch op een {color} muts, voorbeeld',
			'cs.alt.hoodie_chest'   => 'Je patch op de borst van een {color} hoodie, voorbeeld',
			'cs.alt.hoodie_back'    => 'Je patch op de rug van een {color} hoodie, voorbeeld',
			'cs.alt.leather_tag'    => 'Je ontwerp gelaserd in een lederen sleutelhanger, voorbeeld',
			'cs.alt.bagtag'         => 'Je ontwerp gelaserd in een lederen tassenlabel, voorbeeld',
			'cs.alt.frame_box'      => 'Je patch in een schaduwdoos met gegraveerd plaatje, voorbeeld',
			'cs.alt.pvc_keychain'   => 'Je ontwerp als PVC-sleutelhanger, voorbeeld',
			'cs.colorUnknown'       => 'deze kleur',
			'cs.addedLive'          => '{title} toegevoegd aan je winkelmand.',
			'cs.undoneLive'         => '{title} verwijderd uit je winkelmand.',
		),
		'fr' => array(
			'cs.added'              => 'Votre écusson est dans votre panier.',
			'cs.heading'            => 'Le même design aussi sur …',
			'cs.feeNote'            => 'Pas de carte de broderie supplémentaire : vous ne la payez qu\'une fois par commande.',
			'cs.adjust.size.cap'    => 'Sur la casquette, votre écusson fera {cm} cm.',
			'cs.adjust.size.beanie' => 'Sur le bonnet, votre écusson fera {cm} cm.',
			'cs.adjust.back'        => 'Votre écusson dépasse {chest} cm : nous le cousons dans le dos.',
			'cs.adjust.backAlt'     => 'Plutôt sur la poitrine ? Il fera alors {cm} cm.',
			'cs.adjust.simplify'    => 'Nous le simplifions dans l\'épreuve.',
			'cs.leather.photo'      => 'Une photo devient une gravure en tons bruns, pas une photo en couleur.',
			'cs.leather.gradient'   => 'Les dégradés deviennent des nuances dans le cuir, pas des couleurs.',
			'cs.pvc.min'            => 'À partir de {n} pièces (moule fixe). Besoin de moins ? Choisissez le cuir, dès 1 pièce.',
			'cs.pvc.lead'           => 'Délai {lead}.',
			'cs.pvc.mold'           => 'Une seule fois + {price} pour le moule.',
			'cs.pvc.moldShared'     => 'Pas de moule supplémentaire : nous utilisons celui de votre écusson PVC.',
			'cs.mockup'             => 'Aperçu. Vous recevez une épreuve numérique avant la production.',
			'cs.mockupFallback'     => 'Aperçu dans votre épreuve',
			'cs.decline'            => 'Non merci',
			'cs.toCart'             => 'Vers le panier',
			'cs.cartLine'           => 'Aussi sur casquette, bonnet ou dans un cadre ?',
			'cs.cartLineBtn'        => 'Voir',
			'cs.add'                => 'Ajouter',
			'cs.addN'               => 'Ajouter {n} pièces',
			'cs.change'             => 'Modifier',
			'cs.chooseSizes'        => 'Choisir les tailles',
			'cs.writePlate'         => 'Texte de la plaquette',
			'cs.added.card'         => 'Ajouté ✓',
			'cs.undo'               => 'Annuler',
			'cs.adding'             => 'Un instant…',
			'cs.perPiece'           => '{price} la pièce',
			'cs.per'                => 'la pièce',
			'cs.inclAll'            => 'TVA, écusson et pose compris',
			'cs.inclVat'            => 'TVA comprise',
			'cs.inclFrame'          => 'TVA et écusson compris',
			'cs.color'              => 'Couleur',
			'cs.qty'                => 'Quantité',
			'cs.size'               => 'Taille',
			'cs.sizes'              => 'Quantité par taille',
			'cs.sizesHelp'          => 'Indiquez la quantité par taille. Vide = 0.',
			'cs.model'              => 'Modèle',
			'cs.options'            => 'Options',
			'cs.namesBack'          => 'Noms au dos (un par ligne)',
			'cs.namesHelp'          => 'Un nom par porte-clés. La quantité suit la liste.',
			'cs.plate1'             => 'Ligne 1 (p. ex. un nom)',
			'cs.plate2'             => 'Ligne 2 (p. ex. une date)',
			'cs.plateHelp'          => '{n} caractères max. par ligne. Vous pouvez laisser vide.',
			'cs.placement.chest'    => 'Poitrine',
			'cs.placement.back'     => 'Dos',
			'cs.placement'          => 'Emplacement',
			'cs.total'              => 'Total {price}',
			'cs.minus'              => 'Un de moins',
			'cs.plus'               => 'Un de plus',
			'cs.close'              => 'Fermer',
			'cs.err.generic'        => 'L\'ajout n\'a pas fonctionné. Veuillez réessayer.',
			'cs.err.sizes'          => 'Choisissez au moins une taille.',
			'cs.err.names'          => 'Indiquez au moins un nom ou désactivez l\'option.',
			'cs.alt.cap_trucker'    => 'Votre écusson sur une casquette trucker {color}, aperçu',
			'cs.alt.cap_snapback'   => 'Votre écusson sur une casquette snapback {color}, aperçu',
			'cs.alt.cap_dad'        => 'Votre écusson sur une casquette {color}, aperçu',
			'cs.alt.beanie_cuff'    => 'Votre écusson sur un bonnet {color}, aperçu',
			'cs.alt.hoodie_chest'   => 'Votre écusson sur la poitrine d\'un sweat {color}, aperçu',
			'cs.alt.hoodie_back'    => 'Votre écusson dans le dos d\'un sweat {color}, aperçu',
			'cs.alt.leather_tag'    => 'Votre design gravé sur un porte-clés en cuir, aperçu',
			'cs.alt.bagtag'         => 'Votre design gravé sur une étiquette de sac en cuir, aperçu',
			'cs.alt.frame_box'      => 'Votre écusson dans un cadre vitrine avec plaquette gravée, aperçu',
			'cs.alt.pvc_keychain'   => 'Votre design en porte-clés PVC, aperçu',
			'cs.colorUnknown'       => 'de cette couleur',
			'cs.addedLive'          => '{title} ajouté à votre panier.',
			'cs.undoneLive'         => '{title} retiré de votre panier.',
		),
	);

	/**
	 * Taal uit een locale.
	 *
	 * @param string|null $locale Locale.
	 */
	public static function lang( ?string $locale ): string {
		return str_starts_with( strtolower( (string) $locale ), 'fr' ) ? 'fr' : 'nl';
	}

	/**
	 * Vertaal met {param}-vervanging (twin van t()).
	 *
	 * @param string $key       Sleutel.
	 * @param string $locale    Locale.
	 * @param array  $params    Parameters.
	 * @param array  $overrides ['nl' => [key => tekst], 'fr' => …].
	 */
	public static function t( string $key, string $locale, array $params = array(), array $overrides = array() ): string {
		$l = self::lang( $locale );
		$o = $overrides[ $l ][ $key ] ?? null;
		$s = is_string( $o ) && '' !== $o ? $o : ( self::ALL[ $l ][ $key ] ?? ( self::ALL['nl'][ $key ] ?? $key ) );
		foreach ( $params as $k => $v ) {
			$s = str_replace( '{' . $k . '}', (string) $v, $s );
		}
		return $s;
	}

	/**
	 * Bedrag zoals Intl in de browser: nl "€ 5,98", fr "5,98 €" (harde spatie; fr duizendtallen met smalle harde spatie).
	 *
	 * @param float  $amount Bedrag.
	 * @param string $locale Locale.
	 */
	public static function money( float $amount, string $locale ): string {
		$amount = Pricing::round_half_up( $amount, 2 );
		if ( 'fr' === self::lang( $locale ) ) {
			return number_format( $amount, 2, ',', "\u{202F}" ) . "\u{00A0}€";
		}
		return "€\u{00A0}" . number_format( $amount, 2, ',', '.' );
	}
}
