<?php
/**
 * GDPR: privacy policy text, personal data exporter and eraser (research 08 §2.5–2.6).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Data is found by e-mail address: saved/quoted designs (_bps_email), quotes (_bps_email) and
 * designs linked to orders of that billing e-mail (via wc_get_orders → HPOS-safe).
 */
final class Privacy {

	const PAGE = 50;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	/**
	 * Suggested text for the privacy policy (in the "u" style of the existing policy).
	 */
	public static function policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$r    = (array) Config::path( 'retention', array() );
		$text = '<h2>' . esc_html__( 'Patch Studio (patches online ontwerpen)', 'borduur-patch-studio' ) . '</h2>'
			. '<p>' . esc_html__( 'Wanneer u in onze Patch Studio een afbeelding opent, analyseert uw browser die afbeelding op uw eigen toestel. Uw bestand wordt pas naar ons verzonden wanneer u uw patch in de winkelmand legt, een offerte aanvraagt of uw ontwerp bewaart.', 'borduur-patch-studio' ) . '</p>'
			. '<p><strong>' . esc_html__( 'Gegevens:', 'borduur-patch-studio' ) . '</strong> ' . esc_html__( 'uw afbeelding of logo, de tekst en keuzes van uw ontwerp, en — bij bewaren of een offerte — uw e-mailadres.', 'borduur-patch-studio' ) . '</p>'
			. '<p><strong>' . esc_html__( 'Doel en rechtsgrond:', 'borduur-patch-studio' ) . '</strong> ' . esc_html__( 'uw patch adviseren, een digitale proef maken, produceren en eventueel later opnieuw maken (uitvoering van de overeenkomst of stappen op uw verzoek vóór een overeenkomst).', 'borduur-patch-studio' ) . '</p>'
			. '<p><strong>' . esc_html__( 'Bewaartermijn:', 'borduur-patch-studio' ) . '</strong> ' . esc_html(
				sprintf(
					/* translators: 1: days without order, 2: days for saved designs, 3: days for quotes, 4: years after order */
					__( 'bestanden zonder bestelling wissen wij automatisch na %1$d dagen; bewaarde ontwerpen %2$d dagen na uw laatste bezoek aan de link; bestanden van een offerte zonder bestelling na %3$d dagen; bestanden van een bestelling %4$d jaar na uw laatste bestelling van dat ontwerp, zodat u het makkelijk opnieuw kunt bestellen. U kunt altijd vragen om ze eerder te wissen.', 'borduur-patch-studio' ),
					(int) ( $r['orphanDays'] ?? 30 ),
					(int) ( $r['savedDays'] ?? 90 ),
					(int) ( $r['quoteDays'] ?? 365 ),
					(int) ( $r['orderYears'] ?? 3 )
				)
			) . '</p>'
			. '<p><strong>' . esc_html__( 'Verklaring over rechten:', 'borduur-patch-studio' ) . '</strong> ' . esc_html__( 'wij bewaren bij uw bestelling uw verklaring dat u het ontwerp mag gebruiken, met datum en tijd.', 'borduur-patch-studio' ) . '</p>';
		wp_add_privacy_policy_content( 'Patch Studio', wp_kses_post( $text ) );
	}

	/**
	 * @param array $e Exporters.
	 */
	public static function exporters( $e ) {
		$e['borduur-patch-studio'] = array(
			'exporter_friendly_name' => __( 'Patch Studio-ontwerpen en offertes', 'borduur-patch-studio' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $e;
	}

	/**
	 * @param array $e Erasers.
	 */
	public static function erasers( $e ) {
		$e['borduur-patch-studio'] = array(
			'eraser_friendly_name' => __( 'Patch Studio-ontwerpen en offertes', 'borduur-patch-studio' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $e;
	}

	/**
	 * Design ids linked to an e-mail (own meta + orders of that billing e-mail).
	 *
	 * @param string $email E-mail.
	 * @return int[]
	 */
	private static function design_ids( string $email ): array {
		$ids = Designs::query(
			array(
				array(
					'key'   => '_bps_email',
					'value' => $email,
				),
			),
			500
		);
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'billing_email' => $email,
					'limit'         => 200,
					'return'        => 'objects',
				)
			);
			foreach ( $orders as $order ) {
				foreach ( $order->get_items() as $item ) {
					$id = (int) $item->get_meta( '_bps_design_id' );
					if ( $id > 0 ) {
						$ids[] = $id;
					}
				}
			}
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Quote ids for an e-mail.
	 *
	 * @param string $email E-mail.
	 * @return int[]
	 */
	private static function quote_ids( string $email ): array {
		$q = new \WP_Query(
			array(
				'post_type'      => Quotes::CPT,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 500,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_bps_email',
						'value' => $email,
					),
				),
			)
		);
		return array_map( 'intval', $q->posts );
	}

	/**
	 * Exporter.
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 */
	public static function export( $email, $page = 1 ): array {
		$email = sanitize_email( (string) $email );
		$data  = array();
		$all   = array_merge(
			array_map( static fn( $id ) => array( 'd', $id ), self::design_ids( $email ) ),
			array_map( static fn( $id ) => array( 'q', $id ), self::quote_ids( $email ) )
		);
		$slice = array_slice( $all, ( max( 1, (int) $page ) - 1 ) * self::PAGE, self::PAGE );
		foreach ( $slice as $entry ) {
			if ( 'd' === $entry[0] ) {
				$d = Designs::get( $entry[1] );
				if ( ! $d ) {
					continue;
				}
				$p      = $d['payload'];
				$data[] = array(
					'group_id'    => 'bps-designs',
					'group_label' => __( 'Patch Studio-ontwerpen', 'borduur-patch-studio' ),
					'item_id'     => 'bps-design-' . $d['id'],
					'data'        => array_values(
						array_filter(
							array(
								array( 'name' => __( 'Referentie', 'borduur-patch-studio' ), 'value' => $d['ref'] ),
								array( 'name' => __( 'Aangemaakt', 'borduur-patch-studio' ), 'value' => $d['created_gmt'] ),
								array( 'name' => __( 'Status', 'borduur-patch-studio' ), 'value' => $d['status'] ),
								array( 'name' => __( 'Techniek', 'borduur-patch-studio' ), 'value' => Config::technique_label( (string) ( $p['technique'] ?? '' ) ) ),
								array( 'name' => __( 'Maat', 'borduur-patch-studio' ), 'value' => Config::size_text( (float) ( $p['sizeCm'] ?? 0 ) ) ),
								array( 'name' => __( 'Tekst op de patch', 'borduur-patch-studio' ), 'value' => implode( ' · ', array_map( static fn( $t ) => (string) ( $t['text'] ?? '' ), (array) ( $p['texts'] ?? array() ) ) ) ),
								array( 'name' => __( 'Opmerking', 'borduur-patch-studio' ), 'value' => (string) ( $p['notes'] ?? '' ) ),
								array( 'name' => __( 'Voorbeeld', 'borduur-patch-studio' ), 'value' => $d['preview_url'] ),
								array( 'name' => __( 'Origineel bestand', 'borduur-patch-studio' ), 'value' => $d['original'] ? $d['original']['name'] : '' ),
							),
							static fn( $row ) => '' !== (string) $row['value']
						)
					),
				);
			} else {
				$q = Quotes::get( $entry[1] );
				if ( ! $q ) {
					continue;
				}
				$data[] = array(
					'group_id'    => 'bps-quotes',
					'group_label' => __( 'Patch Studio-offertes', 'borduur-patch-studio' ),
					'item_id'     => 'bps-quote-' . $q['id'],
					'data'        => array_values(
						array_filter(
							array(
								array( 'name' => __( 'Referentie', 'borduur-patch-studio' ), 'value' => $q['ref'] ),
								array( 'name' => __( 'Datum', 'borduur-patch-studio' ), 'value' => $q['created'] ),
								array( 'name' => __( 'Naam', 'borduur-patch-studio' ), 'value' => $q['name'] ),
								array( 'name' => __( 'E-mail', 'borduur-patch-studio' ), 'value' => $q['email'] ),
								array( 'name' => __( 'Telefoon', 'borduur-patch-studio' ), 'value' => $q['phone'] ),
								array( 'name' => __( 'Bedrijf', 'borduur-patch-studio' ), 'value' => $q['company'] ),
								array( 'name' => __( 'Btw-nummer', 'borduur-patch-studio' ), 'value' => $q['vat'] ),
								array( 'name' => __( 'Aantal', 'borduur-patch-studio' ), 'value' => (string) $q['qty'] ),
								array( 'name' => __( 'Bericht', 'borduur-patch-studio' ), 'value' => $q['message'] ),
							),
							static fn( $row ) => '' !== (string) $row['value']
						)
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => count( $all ) <= max( 1, (int) $page ) * self::PAGE,
		);
	}

	/**
	 * Eraser. Files of orders that are still being processed are kept (needed to fulfil the contract).
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 */
	public static function erase( $email, $page = 1 ): array {
		$email    = sanitize_email( (string) $email );
		$removed  = false;
		$retained = false;
		$messages = array();

		foreach ( self::design_ids( $email ) as $id ) {
			$d = Designs::get( $id );
			if ( ! $d ) {
				continue;
			}
			if ( 'ordered' === $d['status'] ) {
				$order = $d['order_id'] && function_exists( 'wc_get_order' ) ? wc_get_order( $d['order_id'] ) : null;
				if ( $order && ! in_array( $order->get_status(), array( 'completed', 'cancelled', 'refunded', 'failed' ), true ) ) {
					$retained   = true;
					/* translators: %s: design reference */
					$messages[] = sprintf( __( 'Ontwerp %s hoort bij een bestelling die nog loopt en wordt bewaard tot ze afgerond is.', 'borduur-patch-studio' ), $d['ref'] );
					continue;
				}
				Cleanup::expire_ordered( $id );
				delete_post_meta( $id, '_bps_email' );
				delete_post_meta( $id, '_bps_email_sent_to' );
				$removed = true;
				continue;
			}
			wp_delete_post( $id, true );
			$removed = true;
		}

		foreach ( self::quote_ids( $email ) as $qid ) {
			foreach ( Quotes::CONTACT_META as $key ) {
				delete_post_meta( $qid, $key );
			}
			update_post_meta( $qid, '_bps_anonymised_gmt', gmdate( 'Y-m-d H:i:s' ) );
			$removed = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
