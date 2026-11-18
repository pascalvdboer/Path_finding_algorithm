<?php
/**
 * Customer-facing server messages (Flemish NL "je" / FR "vous") and the error shape of the AI routes.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Wording follows SPEC-AI-DIENSTEN §14. Error shape: { code, message, data: { status, ... } } (§4.1).
 * No "credits", no urgency, no blame: every failure says the round is kept or given back.
 */
final class Messages {

	/** @var string nl|fr for the current request. */
	public static $locale = 'nl';

	const TEXT = array(
		'bps_ai_invalid'        => array(
			'nl' => 'Er ontbreekt iets of een veld is te lang. Kijk je beschrijving even na.',
			'fr' => 'Il manque quelque chose ou un champ est trop long. Vérifiez votre description.',
		),
		'bps_bad_token'         => array(
			'nl' => 'Je sessie is verlopen. We proberen het meteen opnieuw.',
			'fr' => 'Votre session a expiré. Nous réessayons tout de suite.',
		),
		'bps_bad_origin'        => array(
			'nl' => 'Dit verzoek komt niet van onze website.',
			'fr' => 'Cette demande ne provient pas de notre site.',
		),
		'bps_ai_no_rounds'      => array(
			'nl' => 'Je gratis rondes zijn op. Je ontwerpen blijven bewaard.',
			'fr' => 'Vos essais gratuits sont épuisés. Vos designs restent conservés.',
		),
		'bps_ai_consent_needed' => array(
			'nl' => 'Je rondes staan klaar. Wil je meteen beginnen? Vink dan beide vakjes aan.',
			'fr' => 'Vos essais sont prêts. Vous voulez commencer tout de suite ? Cochez alors les deux cases.',
		),
		'bps_ai_off'            => array(
			'nl' => 'De AI-studio staat momenteel uit. Je kan wel je eigen logo opladen.',
			'fr' => 'Le studio IA est actuellement désactivé. Vous pouvez importer votre propre logo.',
		),
		'bps_ai_not_found'      => array(
			'nl' => 'Dit voorstel vinden we niet (meer). Maak gerust nieuwe voorstellen.',
			'fr' => 'Nous ne trouvons pas (plus) cette proposition. Créez-en de nouvelles.',
		),
		'bps_ai_busy'           => array(
			'nl' => 'We zijn nog bezig met je vorige voorstellen. Even geduld.',
			'fr' => 'Nous travaillons encore sur vos propositions précédentes. Un instant.',
		),
		'bps_ai_slow_down'      => array(
			'nl' => 'Even wachten, over {s} seconden kan het weer.',
			'fr' => 'Patientez un instant, c\'est à nouveau possible dans {s} secondes.',
		),
		'bps_ai_daily_limit'    => array(
			'nl' => 'Je hebt vandaag het maximum aantal rondes gebruikt. Morgen kan het weer.',
			'fr' => 'Vous avez utilisé le maximum d\'essais pour aujourd\'hui. Ce sera à nouveau possible demain.',
		),
		'bps_ai_paused'         => array(
			'nl' => 'We pauzeren de AI-studio even voor je. Probeer het over een uur opnieuw, of vraag een gratis offerte aan.',
			'fr' => 'Nous mettons le studio IA en pause pour vous. Réessayez dans une heure, ou demandez un devis gratuit.',
		),
		'bps_ai_budget'         => array(
			'nl' => 'De AI-studio is even niet beschikbaar. Je rondes blijven bewaard.',
			'fr' => 'Le studio IA est momentanément indisponible. Vos essais sont conservés.',
		),
		'bps_ai_free_paused'    => array(
			'nl' => 'Gratis rondes zijn vandaag even op. Betaalde rondes werken gewoon, of probeer morgen opnieuw.',
			'fr' => 'Les essais gratuits sont épuisés pour aujourd\'hui. Les essais payés fonctionnent, ou réessayez demain.',
		),
		'bps_ai_bad_proposal'   => array(
			'nl' => 'Kies eerst een van de voorstellen.',
			'fr' => 'Choisissez d\'abord une des propositions.',
		),
		'bps_ai_not_ready'      => array(
			'nl' => 'De voorstellen zijn nog niet klaar.',
			'fr' => 'Les propositions ne sont pas encore prêtes.',
		),
		// Job errors (status failed): the round is always given back.
		'provider_error'        => array(
			'nl' => 'Dat lukte niet, je ronde is teruggezet. Probeer het opnieuw.',
			'fr' => 'Cela n\'a pas fonctionné, votre essai a été recrédité. Réessayez.',
		),
		'provider_unavailable'  => array(
			'nl' => 'De AI-studio is even overbelast. Je ronde is teruggezet. Probeer het zo meteen opnieuw.',
			'fr' => 'Le studio IA est momentanément surchargé. Votre essai a été recrédité. Réessayez dans un instant.',
		),
		'timeout'               => array(
			'nl' => 'Dit duurt te lang. Je ronde is teruggezet.',
			'fr' => 'Cela prend trop de temps. Votre essai a été recrédité.',
		),
		'storage'               => array(
			'nl' => 'We konden de voorstellen niet bewaren. Je ronde is teruggezet. Probeer het opnieuw.',
			'fr' => 'Nous n\'avons pas pu enregistrer les propositions. Votre essai a été recrédité. Réessayez.',
		),
		'cancelled'             => array(
			'nl' => 'Geannuleerd.',
			'fr' => 'Annulé.',
		),
		'cancelled_refunded'    => array(
			'nl' => 'Geannuleerd. Je ronde is teruggezet.',
			'fr' => 'Annulé. Votre essai a été recrédité.',
		),
		// Refusals that do not come from the local prompt filter.
		'provider_policy'       => array(
			'nl' => 'Dit ontwerp kunnen we niet maken: de beeld-AI weigerde de beschrijving. Je ronde is teruggezet. Beschrijf je eigen idee, of laad je eigen logo op.',
			'fr' => 'Nous ne pouvons pas créer ce design : l\'IA d\'image a refusé la description. Votre essai a été recrédité. Décrivez votre propre idée ou importez votre logo.',
		),
		'moderation'            => array(
			'nl' => 'Dit ontwerp kunnen we niet maken. Er werd geen ronde gebruikt. Beschrijf gerust een ander idee.',
			'fr' => 'Nous ne pouvons pas créer ce design. Aucun essai n\'a été utilisé. Décrivez volontiers une autre idée.',
		),
		'test_mode'             => array(
			'nl' => 'Testmodus: dit zijn voorbeeldontwerpen, geen echte AI.',
			'fr' => 'Mode test : ce sont des exemples, pas une vraie IA.',
		),
	);

	/**
	 * Message for a code in the request locale.
	 *
	 * @param string $code Code.
	 * @param array  $vars {placeholder => value}.
	 */
	public static function text( string $code, array $vars = array() ): string {
		$lang = 'fr' === self::$locale ? 'fr' : 'nl';
		$msg  = self::TEXT[ $code ][ $lang ] ?? ( self::TEXT['provider_error'][ $lang ] );
		foreach ( $vars as $k => $v ) {
			$msg = str_replace( '{' . $k . '}', (string) $v, $msg );
		}
		return $msg;
	}

	/**
	 * WP_Error in the spec shape.
	 *
	 * @param string $code   Code.
	 * @param int    $status HTTP status.
	 * @param array  $data   Extra data.
	 */
	public static function error( string $code, int $status, array $data = array() ): WP_Error {
		$vars = array();
		if ( isset( $data['retryAfter'] ) ) {
			$vars['s'] = (int) $data['retryAfter'];
		}
		$key = $code;
		if ( 'bps_ai_budget' === $code && ! empty( $data['freeOnly'] ) ) {
			$key = 'bps_ai_free_paused';
		}
		return new WP_Error( $code, self::text( $key, $vars ), array_merge( array( 'status' => $status ), $data ) );
	}

	/**
	 * Set the locale from 'nl'|'fr'|'nl_BE'|'fr_BE'|... .
	 *
	 * @param string $locale Locale.
	 */
	public static function set_locale( string $locale ): void {
		self::$locale = 0 === strpos( strtolower( $locale ), 'fr' ) ? 'fr' : 'nl';
	}
}
