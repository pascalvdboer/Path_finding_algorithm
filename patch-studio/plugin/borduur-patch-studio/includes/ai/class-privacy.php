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
		return array(
			'data' => $items,
			'done' => true,
		);
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
	 * Suggested privacy-policy text (04-juridisch §6.7).
	 */
	public static function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$p = array(
			__( 'AI-ontwerpstudio. Als je in de ontwerpstudio "Nog geen logo? Laat AI iets maken" gebruikt, sturen we enkel je ontwerpomschrijving (na het weghalen van namen, e-mailadressen, telefoonnummers en adressen) naar onze beeld-AI-dienst (OpenAI, en als terugval Recraft). We sturen nooit je naam, e-mailadres, IP-adres, bestelnummer of je eigen geüploade bestanden mee.', 'borduur-patch-studio' ),
			__( 'Om je voorstellen en gratis rondes bij te houden, zetten we bij je eerste AI-actie een strikt noodzakelijke cookie (bps_ai_sid, 30 dagen). Je IP-adres bewaren we niet; enkel een versleutelde vingerafdruk ervan tegen misbruik, die na 30 dagen gewist wordt.', 'borduur-patch-studio' ),
			__( 'Beschrijvingen en voorstellen van bezoekers zonder bestelling wissen we na 30 dagen. Gekozen ontwerpen in je account blijven 365 dagen na je laatste bezoek bewaard; ontwerpen bij een bestelling zo lang als de bestelling. Geweigerde beschrijvingen worden na 30 dagen gewist. Ontwerprondes en facturen bewaren we 10 jaar (boekhoudplicht).', 'borduur-patch-studio' ),
			__( 'AI-ontwerpen worden gemarkeerd als gemaakt met AI (in het bestand en in de studio). Je kan je gegevens opvragen of laten wissen via de gewone privacyaanvraag.', 'borduur-patch-studio' ),
		);
		wp_add_privacy_policy_content( __( 'Patch Studio AI-ontwerpstudio', 'borduur-patch-studio' ), '<p>' . implode( '</p><p>', array_map( 'esc_html', $p ) ) . '</p>' );
	}
}
