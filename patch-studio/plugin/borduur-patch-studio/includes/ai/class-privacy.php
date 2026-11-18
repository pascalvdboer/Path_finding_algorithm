<?php
/**
 * GDPR: personal-data exporter and eraser for the AI studio, and the privacy-policy text.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * SPEC-AI-DIENSTEN §13 (additive to the existing exporters of Woo\Privacy):
 * - export: owner link (kind, dates), jobs (date, status, cleaned description, patch text, image links), rounds
 *   (commerce, through the gateway);
 * - erase: jobs + images deleted, e-mail on the owner row removed; lots/ledger stay for bookkeeping but commerce
 *   anonymises the key when no rounds are open.
 * Owners are found by e-mail and by the WordPress user with that e-mail, including keys merged into them.
 */
final class Privacy {

	const GROUP = 'bps-ai';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	/**
	 * @param mixed $list Exporters.
	 */
	public static function exporters( $list ): array {
		$list                          = is_array( $list ) ? $list : array();
		$list['borduur-patch-studio-ai'] = array(
			'exporter_friendly_name' => __( 'Patch Studio AI-ontwerpen', 'borduur-patch-studio' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	/**
	 * @param mixed $list Erasers.
	 */
	public static function erasers( $list ): array {
		$list                          = is_array( $list ) ? $list : array();
		$list['borduur-patch-studio-ai'] = array(
			'eraser_friendly_name' => __( 'Patch Studio AI-ontwerpen', 'borduur-patch-studio' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/**
	 * All owner keys that belong to an e-mail address (account, verified e-mail, merged anonymous keys).
	 *
	 * @param string $email E-mail.
	 * @return string[]
	 */
	public static function owner_keys( string $email ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}
		$user  = function_exists( 'get_user_by' ) ? get_user_by( 'email', $email ) : false;
		$uid   = $user && ! empty( $user->ID ) ? (int) $user->ID : 0;
		$keys  = array( Identity::for_email( $email ) );
		if ( $uid > 0 ) {
			$keys[] = Identity::for_user( $uid );
		}
		$store = Module::store();
		foreach ( $store->owner_find( $email, $uid ) as $row ) {
			$keys[] = (string) $row['owner_key'];
		}
		$keys = array_values( array_unique( array_filter( $keys, array( Identity::class, 'valid' ) ) ) );
		foreach ( $keys as $k ) {
			foreach ( $store->owner_children( $k ) as $child ) {
				$keys[] = $child;
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Exporter callback (single page: at most a few hundred jobs per person).
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 */
	public static function export( $email, $page = 1 ): array {
		unset( $page );
		$items = array();
		$store = Module::store();
		$group = __( 'AI-ontwerpstudio', 'borduur-patch-studio' );
		foreach ( self::owner_keys( (string) $email ) as $key ) {
			$owner = $store->owner_get( $key );
			if ( $owner ) {
				$items[] = array(
					'group_id'    => self::GROUP,
					'group_label' => $group,
					'item_id'     => 'owner-' . substr( $key, 2, 12 ),
					'data'        => array(
						array( 'name' => __( 'Soort', 'borduur-patch-studio' ), 'value' => (string) $owner['kind'] ),
						array( 'name' => __( 'E-mail', 'borduur-patch-studio' ), 'value' => (string) ( $owner['email'] ?? '' ) ),
						array( 'name' => __( 'E-mail bevestigd op', 'borduur-patch-studio' ), 'value' => (string) ( $owner['email_verified_at'] ?? '' ) ),
						array( 'name' => __( 'Eerste gebruik', 'borduur-patch-studio' ), 'value' => (string) $owner['created_at'] ),
						array( 'name' => __( 'Laatst gebruikt', 'borduur-patch-studio' ), 'value' => (string) $owner['last_seen_at'] ),
					),
				);
			}
			foreach ( $store->job_list( $key, '1970-01-01 00:00:00', 500 ) as $job ) {
				$in    = Jobs::json( $job['input'] );
				$links = array();
				if ( 'done' === $job['status'] ) {
					foreach ( Jobs::json( $job['result'] ) as $row ) {
						$links[] = Image_Store::signed_url( (string) $job['id'], (int) ( $row['index'] ?? 0 ) );
					}
				}
				$items[] = array(
					'group_id'    => self::GROUP,
					'group_label' => $group,
					'item_id'     => 'job-' . $job['id'],
					'data'        => array(
						array( 'name' => __( 'Datum', 'borduur-patch-studio' ), 'value' => (string) $job['created_at'] ),
						array( 'name' => __( 'Soort', 'borduur-patch-studio' ), 'value' => 'refine' === $job['kind'] ? __( 'aanpassing', 'borduur-patch-studio' ) : __( 'nieuwe voorstellen', 'borduur-patch-studio' ) ),
						array( 'name' => __( 'Status', 'borduur-patch-studio' ), 'value' => (string) $job['status'] ),
						array( 'name' => __( 'Beschrijving', 'borduur-patch-studio' ), 'value' => (string) ( $in['description'] ?? $in['instruction'] ?? '' ) ),
						array( 'name' => __( 'Tekst op de patch', 'borduur-patch-studio' ), 'value' => (string) ( $in['text'] ?? '' ) ),
						array( 'name' => __( 'Voorstellen (link 1 uur geldig)', 'borduur-patch-studio' ), 'value' => implode( "\n", $links ) ),
					),
				);
			}
			$rounds = Credits_Gateway::instance()->optional( 'export_owner', array( $key ) );
			foreach ( is_array( $rounds ) ? $rounds : array() as $i => $data ) {
				if ( is_array( $data ) ) {
					$items[] = array(
						'group_id'    => self::GROUP . '-rounds',
						'group_label' => __( 'Ontwerprondes', 'borduur-patch-studio' ),
						'item_id'     => 'lot-' . substr( $key, 2, 8 ) . '-' . $i,
						'data'        => $data,
					);
				}
			}
		}
		foreach ( self::consent_items( (string) $email ) as $row ) {
			$items[] = $row;
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Withdrawal consents stored on orders (04 §4.2 / §6.5): which text version was accepted, when and in which
	 * language. Kept for 10 years (proof), exported on request. Empty without WooCommerce.
	 *
	 * @param string $email E-mail.
	 * @return array
	 */
	public static function consent_items( string $email ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => 200,
				'return'        => 'objects',
			)
		);
		$out   = array();
		$keys  = array(
			'_bps_ai_withdrawal_consent' => __( 'Toestemming ontwerprondes (meteen starten + herroepingsrecht)', 'borduur-patch-studio' ),
			'_bps_svc_consent'           => __( 'Toestemming diensten (meteen starten)', 'borduur-patch-studio' ),
		);
		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
				continue;
			}
			foreach ( $keys as $meta => $label ) {
				$rec = $order->get_meta( $meta );
				if ( ! is_array( $rec ) ) {
					continue;
				}
				$given = isset( $rec['given'] ) ? ! empty( $rec['given'] ) : ( ! empty( $rec['start'] ) && ! empty( $rec['ack'] ) );
				$out[] = array(
					'group_id'    => self::GROUP . '-consents',
					'group_label' => __( 'Toestemmingen', 'borduur-patch-studio' ),
					'item_id'     => 'consent-' . (int) $order->get_id() . '-' . substr( $meta, 1, 10 ),
					'data'        => array(
						array( 'name' => __( 'Bestelling', 'borduur-patch-studio' ), 'value' => (string) $order->get_id() ),
						array( 'name' => __( 'Soort', 'borduur-patch-studio' ), 'value' => $label ),
						array( 'name' => __( 'Gegeven', 'borduur-patch-studio' ), 'value' => $given ? __( 'ja', 'borduur-patch-studio' ) : __( 'nee', 'borduur-patch-studio' ) ),
						array( 'name' => __( 'Datum en uur (UTC)', 'borduur-patch-studio' ), 'value' => (string) ( $rec['at'] ?? '' ) ),
						array( 'name' => __( 'Tekstversie', 'borduur-patch-studio' ), 'value' => (string) ( $rec['v'] ?? '' ) ),
						array( 'name' => __( 'Taal', 'borduur-patch-studio' ), 'value' => (string) ( $rec['locale'] ?? '' ) ),
					),
				);
			}
		}
		return $out;
	}

	/**
	 * Eraser callback.
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 */
	public static function erase( $email, $page = 1 ): array {
		unset( $page );
		$removed  = false;
		$retained = false;
		$messages = array();
		$store    = Module::store();
		foreach ( self::owner_keys( (string) $email ) as $key ) {
			foreach ( $store->job_list( $key, '1970-01-01 00:00:00', 1000 ) as $job ) {
				Image_Store::delete_files( Jobs::json( $job['result'] ) );
				$removed = $store->job_delete( (string) $job['id'] ) || $removed;
			}
			$owner = $store->owner_get( $key );
			if ( $owner && ( ! empty( $owner['email'] ) || ! empty( $owner['ip_hash'] ) ) ) {
				$store->owner_upsert(
					array(
						'owner_key' => $key,
						'email'     => null,
						'ip_hash'   => null,
					)
				);
				$removed = true;
			}
			$res = Credits_Gateway::instance()->optional( 'erase_owner', array( $key ) );
			if ( is_array( $res ) ) {
				$removed  = $removed || ! empty( $res['removed'] );
				$retained = $retained || ! empty( $res['retained'] );
			}
		}
		if ( $retained ) {
			$messages[] = __( 'Er staan nog ongebruikte ontwerprondes open. Die blijven bewaard tot ze gebruikt, herroepen of vervallen zijn; de boekhouding (bestellingen) blijft 10 jaar bewaard.', 'borduur-patch-studio' );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Suggested privacy-policy text (04-juridisch §6.7), NL ("je") and FR ("vous"), the language of the site first.
	 * WordPress shows it as a suggestion under Settings → Privacy; the owner copies it into the policy (anchor #ai).
	 */
	public static function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( __( 'Patch Studio AI-ontwerpstudio', 'borduur-patch-studio' ), self::policy_html() );
	}

	/**
	 * Policy HTML with both languages; the language of $locale (default: site locale) comes first.
	 *
	 * @param string $locale Locale.
	 */
	public static function policy_html( string $locale = '' ): string {
		if ( '' === $locale ) {
			$locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'nl_BE' );
		}
		$langs = 0 === stripos( $locale, 'fr' ) ? array( 'fr' => 'Français', 'nl' => 'Nederlands' ) : array( 'nl' => 'Nederlands', 'fr' => 'Français' );
		$html  = '<div id="ai">';
		foreach ( $langs as $lang => $name ) {
			$html .= '<div lang="' . esc_attr( $lang ) . '"><p><strong>' . esc_html( $name ) . '</strong></p><p>' . implode( '</p><p>', array_map( 'esc_html', self::policy_paragraphs( $lang ) ) ) . '</p></div>';
		}
		return $html . '</div>';
	}

	/**
	 * Privacy-policy paragraphs for one language. Every period follows the (admin-adjustable) settings or the code
	 * constant it describes. Items marked [NAKIJKEN] in 04 §6.1 (exact OpenAI entity, transfer basis) are worded so
	 * that both options stay correct; the owner confirms them when accepting the provider's DPA.
	 *
	 * @param string $lang nl|fr.
	 * @return string[]
	 */
	public static function policy_paragraphs( string $lang ): array {
		$fr     = 'fr' === $lang;
		$guest  = self::period( AI_Settings::retention( 'guestPromptsAndProposals', 30 ), $fr );
		$acct   = self::period( AI_Settings::retention( 'accountDesignsAfterLastOpen', 365 ), $fr );
		$order  = self::period( AI_Settings::retention( 'orderDesignsAfterLastOrder', 1095 ), $fr );
		$refuse = self::period( AI_Settings::retention( 'refusedPromptLog', 30 ), $fr );
		$ledger = self::period( AI_Settings::retention( 'ledgerAndInvoices', 3650 ), $fr );
		$owners = self::period( AI_Settings::retention( 'ownerRows', 365 ), $fr );
		$anon   = self::period( AI_Settings::retention( 'anonymousOwnerRows', 30 ), $fr );
		$cookie = self::period( (int) round( Identity::COOKIE_TTL / DAY_IN_SECONDS ), $fr );
		$ip     = self::period( Cleanup::IP_HASH_DAYS, $fr );
		if ( $fr ) {
			return array(
				'Studio de création IA. Lorsque vous utilisez le studio de création IA, nous traitons votre description, vos choix et les designs générés afin de vous montrer des propositions et de gérer vos essais (exécution du contrat).',
				'Pour créer les propositions, nous envoyons votre description, expurgée des noms, coordonnées et adresses, et, en cas de modification, la proposition choisie, à notre sous-traitant OpenAI (OpenAI Ireland Ltd.) et, si ce service est indisponible, à Recraft. Nous n\'envoyons jamais votre nom, votre adresse e-mail, votre adresse IP, votre numéro de commande ni vos propres fichiers. Ces données peuvent être traitées aux États-Unis sur la base du cadre de protection des données UE-États-Unis (Data Privacy Framework) ou des clauses contractuelles types de la Commission européenne. Ces fournisseurs ne les utilisent pas pour entraîner des modèles ; OpenAI les conserve au maximum 30 jours à des fins de contrôle des abus.',
				'Nous filtrons automatiquement les descriptions afin de prévenir les atteintes aux droits de tiers et les abus (intérêt légitime) ; les descriptions refusées sont conservées ' . $refuse . '. Pour gérer vos essais gratuits, nous plaçons lors de votre première action IA (pas lors d\'une simple visite) un cookie strictement nécessaire (bps_ai_sid, ' . $cookie . '). Nous ne conservons pas votre adresse IP, seulement une empreinte chiffrée contre les abus, supprimée après ' . $ip . '.',
				'Les designs sans commande sont supprimés après ' . $guest . ', ceux de votre compte ' . $acct . ' après votre dernière visite, ceux liés à une commande ' . $order . ' après votre dernière commande. Votre profil d\'utilisateur IA (adresse e-mail confirmée) est supprimé ' . $owners . ' après votre dernière visite, un profil anonyme après ' . $anon . ' ; s\'il vous reste des essais achetés, nous le gardons jusqu\'à leur utilisation ou leur expiration. Les essais achetés, les factures et vos consentements (rétractation) sont conservés ' . $ledger . ' (obligation comptable). Les images générées contiennent un marquage technique indiquant qu\'elles ont été créées par IA. Vous pouvez demander l\'accès à vos données ou leur suppression via la demande de confidentialité habituelle.',
			);
		}
		return array(
			'AI-ontwerpstudio. Als je de AI-ontwerpstudio gebruikt, verwerken we je ontwerpbeschrijving, je keuzes en de gegenereerde ontwerpen om je ontwerpvoorstellen te tonen en je ontwerprondes bij te houden (uitvoering van de overeenkomst).',
			'Om de voorstellen te maken sturen we je beschrijving, ontdaan van namen, contactgegevens en adressen, en bij een aanpassing het gekozen voorstel, naar onze verwerker OpenAI (OpenAI Ireland Ltd.) en, als die dienst niet beschikbaar is, naar Recraft. We sturen nooit je naam, e-mailadres, IP-adres, bestelnummer of je eigen bestanden mee. Die gegevens kunnen in de Verenigde Staten verwerkt worden op basis van het EU-VS Data Privacy Framework of de modelcontractbepalingen van de Europese Commissie. Deze leveranciers gebruiken ze niet om modellen te trainen; OpenAI bewaart ze maximaal 30 dagen voor misbruikcontrole.',
			'We filteren beschrijvingen automatisch om inbreuken op rechten van anderen en misbruik te voorkomen (gerechtvaardigd belang); geweigerde beschrijvingen bewaren we ' . $refuse . '. Om je gratis rondes bij te houden, zetten we bij je eerste AI-actie (niet bij een gewoon bezoek) een strikt noodzakelijke cookie (bps_ai_sid, ' . $cookie . '). Je IP-adres bewaren we niet, alleen een versleutelde vingerafdruk ervan tegen misbruik, die we na ' . $ip . ' wissen.',
			'Ontwerpen zonder bestelling wissen we na ' . $guest . ', ontwerpen in je account ' . $acct . ' na je laatste bezoek, ontwerpen bij een bestelling ' . $order . ' na je laatste bestelling. Je AI-gebruikersprofiel (bevestigd e-mailadres) wissen we ' . $owners . ' na je laatste bezoek, een anoniem profiel na ' . $anon . '; heb je nog gekochte rondes, dan bewaren we het tot die gebruikt of vervallen zijn. Gekochte rondes, facturen en je toestemmingen (herroeping) bewaren we ' . $ledger . ' (boekhoudplicht). Gegenereerde beelden bevatten een technische markering dat ze met AI gemaakt zijn. Je kunt je gegevens opvragen of laten wissen via de gewone privacyaanvraag.',
		);
	}

	/**
	 * Days → readable period ("30 dagen", "12 maanden", "3 jaar").
	 *
	 * @param int  $days Days.
	 * @param bool $fr   French.
	 */
	private static function period( int $days, bool $fr ): string {
		if ( $days > 0 && 365 === $days ) {
			return $fr ? '12 mois' : '12 maanden';
		}
		if ( $days > 365 && 0 === $days % 365 ) {
			return ( $days / 365 ) . ( $fr ? ' ans' : ' jaar' );
		}
		return $days . ( $fr ? ' jours' : ' dagen' );
	}
}
