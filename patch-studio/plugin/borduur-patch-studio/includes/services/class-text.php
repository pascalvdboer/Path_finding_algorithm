<?php
/**
 * Customer texts of the commerce module (NL "je" / FR "vous"). Legal texts come from config/ai-legal.json.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Small bilingual dictionary. Overridable per key with the filter `bps_svc_text`.
 * Honest copy only: no "credits", no scarcity, no countdowns (tests/services scan this file).
 */
final class Text {

	const STRINGS = array(
		// E-mail gate.
		'email.subject'          => array(
			'nl' => 'Je code voor de AI-ontwerpstudio',
			'fr' => 'Votre code pour le studio de création IA',
		),
		'email.body'             => array(
			'nl' => "Je code is: {code}\n\nVul hem in de AI-ontwerpstudio in. De code is {minutes} minuten geldig.\nVroeg je deze code niet aan? Dan mag je deze mail gewoon negeren.",
			'fr' => "Votre code : {code}\n\nSaisissez-le dans le studio de création IA. Le code est valable {minutes} minutes.\nVous n'avez pas demandé ce code ? Ignorez simplement cet e-mail.",
		),
		'email.invalid'          => array(
			'nl' => 'Dat e-mailadres lijkt niet te kloppen. Kijk je het even na?',
			'fr' => 'Cette adresse e-mail ne semble pas correcte. Pouvez-vous la vérifier ?',
		),
		'email.too_many'         => array(
			'nl' => 'Je vroeg al een paar codes aan. Wacht even en probeer het over een uur opnieuw.',
			'fr' => 'Vous avez déjà demandé plusieurs codes. Patientez et réessayez dans une heure.',
		),
		'email.wrong_code'       => array(
			'nl' => 'Die code klopt niet. Kijk je hem nog eens na?',
			'fr' => "Ce code n'est pas correct. Pouvez-vous le vérifier ?",
		),
		'email.expired'          => array(
			'nl' => 'Deze code is niet meer geldig. Vraag een nieuwe aan.',
			'fr' => "Ce code n'est plus valable. Demandez-en un nouveau.",
		),
		'email.send_failed'      => array(
			'nl' => 'We konden de mail nu niet versturen. Probeer het straks opnieuw.',
			'fr' => "Nous n'avons pas pu envoyer l'e-mail. Réessayez plus tard.",
		),
		'email.off'              => array(
			'nl' => 'Extra rondes met je e-mailadres zijn nu niet beschikbaar.',
			'fr' => 'Les essais supplémentaires avec votre e-mail ne sont pas disponibles pour le moment.',
		),

		// Offers.
		'offer.add'              => array(
			'nl' => 'Voeg toe · {price}',
			'fr' => 'Ajouter · {price}',
		),
		'offer.no'               => array(
			'nl' => 'Nee, dank je',
			'fr' => 'Non merci',
		),

		// Order credit (coupon).
		'credit.applied'         => array(
			'nl' => 'Je ontwerppakket komt terug: − {amount}',
			'fr' => 'Votre pack design vous est rendu : − {amount}',
		),
		'credit.sample_applied'  => array(
			'nl' => 'Je staal komt terug: − {amount}',
			'fr' => 'Votre échantillon vous est rendu : − {amount}',
		),
		'credit.coupon_label'    => array(
			'nl' => 'Terugbetaling ontwerppakket',
			'fr' => 'Remboursement pack design',
		),
		'credit.sample_label'    => array(
			'nl' => 'Terugbetaling staal',
			'fr' => 'Remboursement échantillon',
		),
		'credit.sample_min_qty'  => array(
			'nl' => 'De terugbetaling van je staal geldt vanaf {qty} stuks van een patch.',
			'fr' => "Le remboursement de votre échantillon s'applique dès {qty} pièces d'un écusson.",
		),

		// Checkout.
		'checkout.pack_title'    => array(
			'nl' => 'Je ontwerprondes',
			'fr' => 'Vos essais de création',
		),
		'checkout.svc_title'     => array(
			'nl' => 'Je dienst(en)',
			'fr' => 'Votre/vos service(s)',
		),
		'checkout.blocks_hint'   => array(
			'nl' => 'Enkel van toepassing als je ontwerprondes of een dienst koopt.',
			'fr' => "Uniquement si vous achetez des essais de création ou un service.",
		),

		// Cart.
		'cart.linked'            => array(
			'nl' => 'Bij je patch',
			'fr' => 'Avec votre écusson',
		),
		'cart.free_from'         => array(
			'nl' => 'Gratis vanaf {qty} stuks',
			'fr' => 'Gratuit dès {qty} pièces',
		),
		'cart.lead'              => array(
			'nl' => 'Levertijd',
			'fr' => 'Délai',
		),
		'cart.lead_days'         => array(
			'nl' => '± {n} werkdag(en)',
			'fr' => '± {n} jour(s) ouvrable(s)',
		),
		'cart.design'            => array(
			'nl' => 'Ontwerp',
			'fr' => 'Design',
		),
		'cart.linked_removed'    => array(
			'nl' => '"{service}" hoorde bij een patch die niet meer in je winkelmand zit, dus we haalden het weg.',
			'fr' => "« {service} » était lié à un écusson qui n'est plus dans votre panier, nous l'avons donc retiré.",
		),
		'cart.direct_blocked'    => array(
			'nl' => 'Deze optie voeg je toe in de ontwerptool, bij je patch.',
			'fr' => "Cette option s'ajoute dans l'outil de création, avec votre écusson.",
		),

		// REST errors.
		'err.off'                => array(
			'nl' => 'De AI-ontwerpstudio staat nu niet aan.',
			'fr' => "Le studio de création IA n'est pas activé pour le moment.",
		),
		'err.no_woo'             => array(
			'nl' => 'De winkelmand is even niet beschikbaar. Probeer het straks opnieuw.',
			'fr' => 'Le panier est momentanément indisponible. Réessayez plus tard.',
		),
		'err.pack_unknown'       => array(
			'nl' => 'Dit pakket bestaat niet (meer).',
			'fr' => "Ce pack n'existe pas (ou plus).",
		),
		'err.not_buyable'        => array(
			'nl' => 'Pakketten zijn in de testmodus niet te koop.',
			'fr' => 'Les packs ne sont pas en vente en mode test.',
		),
		'err.svc_unknown'        => array(
			'nl' => 'Deze dienst is nu niet beschikbaar.',
			'fr' => "Ce service n'est pas disponible pour le moment.",
		),
		'err.svc_needs_patch'    => array(
			'nl' => 'Deze optie hoort bij een patch. Zet eerst je patch in de winkelmand.',
			'fr' => "Cette option est liée à un écusson. Ajoutez d'abord votre écusson au panier.",
		),
		'err.svc_technique'      => array(
			'nl' => 'Deze optie bestaat niet voor deze techniek.',
			'fr' => "Cette option n'existe pas pour cette technique.",
		),
		'err.no_owner'           => array(
			'nl' => 'We vonden je ontwerprondes niet. Herlaad de pagina en probeer opnieuw.',
			'fr' => 'Nous ne trouvons pas vos essais. Rechargez la page et réessayez.',
		),
		'err.session'            => array(
			'nl' => 'Je sessie is verlopen. Herlaad de pagina en probeer het opnieuw.',
			'fr' => 'Votre session a expiré. Rechargez la page et réessayez.',
		),
		'err.origin'             => array(
			'nl' => 'Deze aanvraag komt niet van onze website.',
			'fr' => 'Cette demande ne provient pas de notre site.',
		),
		'err.invalid'            => array(
			'nl' => 'Er ontbreekt iets in je aanvraag.',
			'fr' => 'Il manque quelque chose dans votre demande.',
		),

		// My account.
		'account.menu'           => array(
			'nl' => 'Ontwerprondes & diensten',
			'fr' => 'Essais & services',
		),
		'account.rounds'         => array(
			'nl' => 'Je ontwerprondes',
			'fr' => 'Vos essais de création',
		),
		'account.balance'        => array(
			'nl' => 'Je hebt nog {n} ronde(s).',
			'fr' => 'Il vous reste {n} essai(s).',
		),
		'account.locked'         => array(
			'nl' => '{n} betaalde ronde(s) worden bruikbaar op {date}, of meteen als je in de AI-studio de twee vakjes aanvinkt.',
			'fr' => "{n} essai(s) payé(s) seront disponibles le {date}, ou immédiatement si vous cochez les deux cases dans le studio IA.",
		),
		'account.none'           => array(
			'nl' => 'Je hebt nog geen ontwerprondes. Probeer de AI-studio gratis bij het ontwerpen van je patch.',
			'fr' => "Vous n'avez pas encore d'essais. Essayez gratuitement le studio IA en créant votre écusson.",
		),
		'account.col.what'       => array(
			'nl' => 'Wat',
			'fr' => 'Quoi',
		),
		'account.col.used'       => array(
			'nl' => 'Gebruikt',
			'fr' => 'Utilisés',
		),
		'account.col.valid'      => array(
			'nl' => 'Geldig tot',
			'fr' => "Valable jusqu'au",
		),
		'account.col.status'     => array(
			'nl' => 'Status',
			'fr' => 'Statut',
		),
		'account.src.free_anon'  => array(
			'nl' => 'Gratis proefronde',
			'fr' => "Essai gratuit",
		),
		'account.src.free_email' => array(
			'nl' => 'Gratis met je e-mailadres',
			'fr' => 'Gratuit avec votre e-mail',
		),
		'account.src.free_order' => array(
			'nl' => 'Gratis bij je bestelling',
			'fr' => 'Gratuit avec votre commande',
		),
		'account.src.pack'       => array(
			'nl' => 'Pakket',
			'fr' => 'Pack',
		),
		'account.src.admin'      => array(
			'nl' => 'Van ons atelier',
			'fr' => 'De notre atelier',
		),
		'account.src.goodwill'   => array(
			'nl' => 'Van ons atelier',
			'fr' => 'De notre atelier',
		),
		'account.st.active'      => array(
			'nl' => 'Actief',
			'fr' => 'Actif',
		),
		'account.st.locked'      => array(
			'nl' => 'Nog niet bruikbaar',
			'fr' => 'Pas encore disponible',
		),
		'account.st.expired'     => array(
			'nl' => 'Vervallen',
			'fr' => 'Expiré',
		),
		'account.st.withdrawn'   => array(
			'nl' => 'Herroepen',
			'fr' => 'Rétracté',
		),
		'account.st.refunded'    => array(
			'nl' => 'Terugbetaald',
			'fr' => 'Remboursé',
		),
		'account.credit'         => array(
			'nl' => 'Je krijgt {amount} terug als korting op je patches (vanaf {min}, tot {date}). Dat gebeurt vanzelf in je winkelmand.',
			'fr' => "Vous récupérez {amount} en réduction sur vos écussons (dès {min}, jusqu'au {date}). Cela se fait automatiquement dans votre panier.",
		),
		'account.credit_used'    => array(
			'nl' => 'Je korting van {amount} is gebruikt. Bedankt voor je bestelling!',
			'fr' => 'Votre réduction de {amount} a été utilisée. Merci pour votre commande !',
		),
		'account.withdraw_done'  => array(
			'nl' => 'Je herroeping is goed ontvangen. Je krijgt {amount} terug binnen 14 dagen.',
			'fr' => 'Votre rétractation a bien été reçue. Vous serez remboursé de {amount} dans les 14 jours.',
		),
		'account.withdraw_none'  => array(
			'nl' => 'Dit pakket kan niet (meer) herroepen worden.',
			'fr' => 'Ce pack ne peut pas (ou plus) faire l\'objet d\'une rétractation.',
		),
		'account.cancel'         => array(
			'nl' => 'Toch niet',
			'fr' => 'Annuler',
		),
		'account.services'       => array(
			'nl' => 'Je diensten',
			'fr' => 'Vos services',
		),
		'account.services_none'  => array(
			'nl' => 'Je bestelde nog geen diensten.',
			'fr' => "Vous n'avez pas encore commandé de service.",
		),
		'account.order'          => array(
			'nl' => 'Bestelling',
			'fr' => 'Commande',
		),
		'account.ready_by'       => array(
			'nl' => 'Verwacht klaar rond {date}',
			'fr' => 'Prêt vers le {date}',
		),
		'account.studio_cta'     => array(
			'nl' => 'Naar de ontwerptool',
			'fr' => "Vers l'outil de création",
		),
		'account.buy'            => array(
			'nl' => 'Meer rondes nodig?',
			'fr' => "Besoin de plus d'essais ?",
		),
		'account.buy_btn'        => array(
			'nl' => '{n} rondes · {price}',
			'fr' => '{n} essais · {price}',
		),
		'account.pack_refund'    => array(
			'nl' => 'Je krijgt het bedrag terug als korting op je patches (vanaf {min}, {months} maanden geldig).',
			'fr' => 'Le montant vous est rendu en réduction sur vos écussons (dès {min}, valable {months} mois).',
		),
		'account.credit_title'   => array(
			'nl' => 'Je korting op patches',
			'fr' => 'Votre réduction sur écussons',
		),
		'account.withdraw_error' => array(
			'nl' => 'Je herroeping is goed ontvangen. We betalen {amount} manueel terug binnen 14 dagen; je krijgt een mail zodra het gebeurd is.',
			'fr' => 'Votre rétractation a bien été reçue. Nous remboursons {amount} manuellement dans les 14 jours ; vous recevrez un e-mail dès que c\'est fait.',
		),
		'cart.pack_terms'           => array(
			'nl' => 'Voorwaarden',
			'fr' => 'Conditions',
		),
		'svc_withdraw.subject'      => array(
			'nl' => 'Herroeping ontvangen: bestelling {nr}',
			'fr' => 'Rétractation reçue : commande {nr}',
		),
		'svc_withdraw.done'         => array(
			'nl' => 'Je herroeping is bevestigd. We betalen {amount} terug via je oorspronkelijke betaalmethode.',
			'fr' => 'Votre rétractation est confirmée. Nous remboursons {amount} via votre moyen de paiement initial.',
		),
		'svc_withdraw.done_started' => array(
			'nl' => 'Je herroeping is bevestigd. We stoppen het werk en mailen je binnen 14 dagen wat we terugbetalen.',
			'fr' => 'Votre rétractation est confirmée. Nous arrêtons le travail et vous indiquons par e-mail, dans les 14 jours, le montant remboursé.',
		),
		'svc_withdraw.email_line'   => array(
			'nl' => 'Bedenk je je binnen 14 dagen voor een dienst die nog niet volledig uitgevoerd is?',
			'fr' => 'Vous changez d\'avis dans les 14 jours pour un service qui n\'est pas encore entièrement exécuté ?',
		),
		'account.guest_title'    => array(
			'nl' => 'Je bestelling herroepen',
			'fr' => 'Vous rétracter de votre commande',
		),
		'account.link_invalid'   => array(
			'nl' => 'Deze link is verlopen of klopt niet. Log in op je account of antwoord op je bevestigingsmail, dan helpen we je verder.',
			'fr' => 'Ce lien a expiré ou n\'est pas valide. Connectez-vous à votre compte ou répondez à votre e-mail de confirmation, nous vous aiderons.',
		),
		'account.credit_none_used' => array(
			'nl' => 'De korting van dit pakket werd al gebruikt, dus het pakket kan niet meer herroepen worden.',
			'fr' => 'La réduction liée à ce pack a déjà été utilisée : le pack ne peut plus faire l\'objet d\'une rétractation.',
		),
		'account.services_intro' => array(
			'nl' => 'Hier volg je het werk van onze ontwerpers. Je krijgt ook een mail bij elke stap.',
			'fr' => 'Suivez ici le travail de nos graphistes. Vous recevez aussi un e-mail à chaque étape.',
		),
		'consent.saved'          => array(
			'nl' => 'Je rondes zijn meteen bruikbaar.',
			'fr' => 'Vos essais sont utilisables immédiatement.',
		),
		'consent.both'           => array(
			'nl' => 'Vink beide vakjes aan om meteen te beginnen.',
			'fr' => 'Cochez les deux cases pour commencer tout de suite.',
		),
		'svc.dismissed'          => array(
			'nl' => 'Begrepen, we vragen het niet meer voor dit ontwerp.',
			'fr' => 'Compris, nous ne le proposerons plus pour ce design.',
		),

		// Service statuses (customer view).
		'svc.st.waiting'         => array(
			'nl' => 'Start op {date} (na de herroepingstermijn)',
			'fr' => 'Démarre le {date} (après le délai de rétractation)',
		),
		'svc.st.queued'          => array(
			'nl' => 'Ingepland',
			'fr' => 'Planifié',
		),
		'svc.st.in_progress'     => array(
			'nl' => 'We zijn ermee bezig',
			'fr' => 'Nous y travaillons',
		),
		'svc.st.proof_sent'      => array(
			'nl' => 'Klaar: bekijk je proef',
			'fr' => 'Prêt : consultez votre épreuve',
		),
		'svc.st.delivered'       => array(
			'nl' => 'Geleverd',
			'fr' => 'Livré',
		),
		'svc.st.cancelled'       => array(
			'nl' => 'Geannuleerd',
			'fr' => 'Annulé',
		),

		// Service e-mails.
		'svcmail.subject'        => array(
			'nl' => 'Je dienst "{service}": {status}',
			'fr' => 'Votre service « {service} » : {status}',
		),
		'svcmail.heading'        => array(
			'nl' => 'Nieuws over je dienst',
			'fr' => 'Des nouvelles de votre service',
		),
		'svcmail.intro'          => array(
			'nl' => 'Hallo {name},',
			'fr' => 'Bonjour {name},',
		),
		'svcmail.body.queued'    => array(
			'nl' => 'We hebben je bestelling {nr} goed ontvangen. "{service}" staat ingepland; verwacht klaar rond {date}.',
			'fr' => 'Nous avons bien reçu votre commande {nr}. « {service} » est planifié ; prêt vers le {date}.',
		),
		'svcmail.body.waiting'   => array(
			'nl' => 'We hebben je bestelling {nr} goed ontvangen. Omdat je het vakje om meteen te starten niet aanvinkte, beginnen we aan "{service}" op {date}. Wil je toch sneller? Antwoord gewoon op deze mail.',
			'fr' => "Nous avons bien reçu votre commande {nr}. Comme vous n'avez pas coché la case pour démarrer immédiatement, nous commençons « {service} » le {date}. Vous préférez plus tôt ? Répondez simplement à cet e-mail.",
		),
		'svcmail.body.in_progress' => array(
			'nl' => 'Goed nieuws: we zijn begonnen aan "{service}" voor bestelling {nr}. Verwacht klaar rond {date}.',
			'fr' => 'Bonne nouvelle : nous avons commencé « {service} » pour la commande {nr}. Prêt vers le {date}.',
		),
		'svcmail.body.proof_sent' => array(
			'nl' => '"{service}" voor bestelling {nr} is klaar. Je vindt het resultaat bij je digitale proef of in Mijn account.',
			'fr' => '« {service} » pour la commande {nr} est prêt. Vous trouvez le résultat avec votre épreuve numérique ou dans Mon compte.',
		),
		'svcmail.body.delivered' => array(
			'nl' => '"{service}" voor bestelling {nr} is geleverd. Bedankt voor je vertrouwen!',
			'fr' => '« {service} » pour la commande {nr} a été livré. Merci pour votre confiance !',
		),
		'svcmail.body.cancelled' => array(
			'nl' => '"{service}" voor bestelling {nr} is geannuleerd. Betaalde je er al voor, dan betalen we je terug.',
			'fr' => '« {service} » pour la commande {nr} a été annulé. Si vous l\'avez déjà payé, nous vous remboursons.',
		),
		'svcmail.note'           => array(
			'nl' => 'Bericht van ons atelier:',
			'fr' => 'Message de notre atelier :',
		),
		'svcmail.account'        => array(
			'nl' => 'Je volgt al je diensten in Mijn account → Ontwerprondes & diensten.',
			'fr' => 'Suivez tous vos services dans Mon compte → Essais & services.',
		),
		'withdraw.admin_subject' => array(
			'nl' => 'Herroeping ontwerppakket: bestelling {nr} — terugbetaling nakijken',
			'fr' => 'Rétractation pack design : commande {nr} — remboursement à vérifier',
		),
		'withdraw.admin_body'    => array(
			'nl' => "Een klant herriep een ontwerppakket.\nBestelling: {nr}\nTe betalen: {amount}\nAutomatisch terugbetalen lukte niet ({reason}). Betaal manueel terug in WooCommerce.",
			'fr' => "Un client s'est rétracté d'un pack design.\nCommande : {nr}\nMontant : {amount}\nLe remboursement automatique a échoué ({reason}). Remboursez manuellement dans WooCommerce.",
		),
		'withdraw.email_subject' => array(
			'nl' => 'Je herroeping is ontvangen',
			'fr' => 'Votre rétractation a été reçue',
		),
	);

	/**
	 * 'nl' or 'fr'.
	 *
	 * @param string|null $locale Locale-ish string, null = current WordPress locale.
	 */
	public static function lang( ?string $locale = null ): string {
		if ( null === $locale || '' === $locale ) {
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : ( function_exists( 'get_locale' ) ? get_locale() : 'nl_BE' );
		}
		return 0 === stripos( (string) $locale, 'fr' ) ? 'fr' : 'nl';
	}

	/**
	 * Text for a key with {vars}.
	 *
	 * @param string      $key  Key.
	 * @param string|null $lang nl|fr|locale|null.
	 * @param array       $vars Replacements.
	 */
	public static function t( string $key, ?string $lang = null, array $vars = array() ): string {
		$lang = self::lang( $lang );
		$row  = self::STRINGS[ $key ] ?? null;
		$s    = is_array( $row ) ? (string) ( $row[ $lang ] ?? $row['nl'] ) : $key;
		if ( function_exists( 'apply_filters' ) ) {
			$s = (string) apply_filters( 'bps_svc_text', $s, $key, $lang );
		}
		return self::fill( $s, $vars );
	}

	/**
	 * Replace {name} placeholders.
	 *
	 * @param string $s    Template.
	 * @param array  $vars Values.
	 */
	public static function fill( string $s, array $vars ): string {
		foreach ( $vars as $k => $v ) {
			$s = str_replace( '{' . $k . '}', (string) $v, $s );
		}
		return $s;
	}

	/**
	 * Label from a {nl,fr} object.
	 *
	 * @param mixed       $obj  Object or string.
	 * @param string|null $lang Lang.
	 */
	public static function label( $obj, ?string $lang = null ): string {
		if ( is_string( $obj ) ) {
			return $obj;
		}
		if ( ! is_array( $obj ) ) {
			return '';
		}
		$lang = self::lang( $lang );
		$alt  = 'fr' === $lang ? 'nl' : 'fr';
		return (string) ( ! empty( $obj[ $lang ] ) ? $obj[ $lang ] : ( $obj[ $alt ] ?? '' ) );
	}

	/**
	 * "€ 4,95" (nl) / "4,95 €" (fr).
	 *
	 * @param float       $amount Amount.
	 * @param string|null $lang   Lang.
	 */
	public static function money( float $amount, ?string $lang = null ): string {
		$n = number_format( $amount, 2, ',', '.' );
		return 'fr' === self::lang( $lang ) ? $n . "\u{00A0}€" : "€\u{00A0}" . $n;
	}

	/**
	 * Date "17-11-2026" (nl) / "17/11/2026" (fr) in the site timezone.
	 *
	 * @param string|null $utc  UTC 'Y-m-d H:i:s' or ISO.
	 * @param string|null $lang Lang.
	 */
	public static function date( ?string $utc, ?string $lang = null ): string {
		if ( ! $utc ) {
			return '';
		}
		$ts = strtotime( rtrim( str_replace( 'T', ' ', $utc ), 'Z' ) . ' UTC' );
		if ( false === $ts ) {
			return '';
		}
		$fmt = 'fr' === self::lang( $lang ) ? 'd/m/Y' : 'd-m-Y';
		return function_exists( 'wp_date' ) ? (string) wp_date( $fmt, $ts ) : gmdate( $fmt, $ts );
	}

	/**
	 * A legal string from config/ai-legal.json.
	 *
	 * @param string      $key  Key like 'legal.ai.pack_consent_start'.
	 * @param string|null $lang Lang.
	 * @param array       $vars Replacements.
	 */
	public static function legal( string $key, ?string $lang = null, array $vars = array() ): string {
		$legal = self::legal_config();
		$row   = $legal['strings'][ $key ] ?? null;
		return is_array( $row ) ? self::fill( self::label( array_intersect_key( $row, array_flip( array( 'nl', 'fr' ) ) ), $lang ), $vars ) : '';
	}

	/**
	 * Parsed ai-legal.json (cached).
	 */
	public static function legal_config(): array {
		static $cache = null;
		if ( null === $cache ) {
			$path  = dirname( __DIR__, 2 ) . '/config/ai-legal.json';
			$cache = is_readable( $path ) ? (array) json_decode( (string) file_get_contents( $path ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $cache;
	}

	/**
	 * Legal text version (stored with every consent).
	 */
	public static function legal_version(): string {
		return (string) ( self::legal_config()['version'] ?? '' );
	}
}
