<?php
/**
 * GDPR: privacy policy text, personal data exporter and eraser (research 08 §2.5–2.6, premium P03).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Data is found by e-mail address:
 * - designs with _bps_email, designs of the account with that e-mail (post_author) and designs linked to orders of
 *   that billing e-mail or customer (wc_get_orders, HPOS-safe, paginated without a cap);
 * - quote requests with _bps_email or of the account (post_author).
 *
 * Export and erase work in pages of self::PAGE items, sorted by id. The eraser always works on the first PAGE items
 * that still need work (erased items drop out of that list), and reports done = (slice < PAGE).
 */
final class Privacy {

	const PAGE = 50;

	/** Orders fetched per wc_get_orders call when collecting designs. */
	const ORDER_BATCH = 100;

	/** Replacement for erased free text. */
	const ERASED = '[gewist]';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		// Retention companions (always loaded together with the privacy hooks).
		Order_Lifecycle::register();
		User_Lifecycle::register();
	}

	/* ---------------------------------------------------------------------
	 * Policy text
	 * ------------------------------------------------------------------- */

	/**
	 * Name-tape retention in days (setting of the name-tape module, P03), with fallback.
	 */
	private static function nametape_days(): int {
		$cls = '\BorduurPatchStudio\Nametape\Privacy';
		if ( class_exists( $cls ) && is_callable( array( $cls, 'retention_days' ) ) ) {
			return (int) call_user_func( array( $cls, 'retention_days' ) );
		}
		return 180;
	}

	/**
	 * Suggested privacy-policy HTML ("je" form; every period comes from the settings).
	 */
	public static function policy_html(): string {
		$r    = Cleanup::settings();
		$keep = $r['keepPreviewOnExpiry'];
		$p    = static fn( string $label, string $body ): string => '<p><strong>' . esc_html( $label ) . '</strong> ' . esc_html( $body ) . '</p>';

		$retention = sprintf(
			/* translators: 1: days without order, 2: days for saved designs without account, 3: days for designs in an account, 4: days for quote requests, 5: years after the last order */
			__( 'bestanden zonder bestelling wissen we automatisch na %1$d dagen. Bewaarde ontwerpen zonder account wissen we %2$d dagen na je laatste bezoek aan de link, ontwerpen in je account %3$d dagen na je laatste bezoek. Offerteaanvragen zonder bestelling (contactgegevens en bestanden) wissen we na %4$d dagen. Het originele bestand van een bestelling wissen we %5$d jaar na je laatste bestelling van dat ontwerp, zodat je het makkelijk opnieuw kunt bestellen.', 'borduur-patch-studio' ),
			$r['orphanDays'],
			$r['savedDays'],
			$r['accountSavedDays'],
			$r['quoteDays'],
			$r['orderYears']
		);
		if ( $keep ) {
			$retention .= ' ' . __( 'Daarna houden we bij de bestelling alleen een klein voorbeeldbeeld van je patch.', 'borduur-patch-studio' );
		}
		$retention .= ' ' . __( 'Je kunt altijd vragen om je gegevens eerder te wissen.', 'borduur-patch-studio' );

		$html = '<h2>' . esc_html__( 'Patch Studio (patches online ontwerpen)', 'borduur-patch-studio' ) . '</h2>'
			. '<p>' . esc_html__( 'Wanneer je in onze Patch Studio een afbeelding opent, analyseert je browser die afbeelding op je eigen toestel. Je bestand wordt pas naar ons verzonden wanneer je je patch in de winkelmand legt, een offerte aanvraagt of je ontwerp bewaart.', 'borduur-patch-studio' ) . '</p>'
			. $p( __( 'Gegevens:', 'borduur-patch-studio' ), __( 'je afbeelding of logo, de tekst en keuzes van je ontwerp, bij bewaren je e-mailadres, en bij een offerteaanvraag ook je naam, telefoonnummer, bedrijf, btw-nummer en bericht.', 'borduur-patch-studio' ) )
			. $p( __( 'Doel en rechtsgrond:', 'borduur-patch-studio' ), __( 'je patch adviseren, een digitale proef maken, produceren en eventueel later opnieuw maken (uitvoering van de overeenkomst, of stappen op jouw verzoek vóór een overeenkomst).', 'borduur-patch-studio' ) )
			. $p( __( 'Bewaartermijn:', 'borduur-patch-studio' ), $retention )
			. $p( __( 'Verklaring over rechten:', 'borduur-patch-studio' ), __( 'we bewaren bij je bestelling je verklaring dat je het ontwerp mag gebruiken, met datum en tijd.', 'borduur-patch-studio' ) )
			. '<h3>' . esc_html__( 'Naamstroken', 'borduur-patch-studio' ) . '</h3>'
			. '<p>' . esc_html__( 'Bestel je naamstroken, dan verwerken we de namen (en eventueel een tweede regel) van de mensen die de stroken dragen en, als je die kiest, hun bloedgroep. Een bloedgroep is een gezondheidsgegeven: we borduren die alleen als je bevestigt dat je de toestemming van die mensen hebt, en we bewaren die bevestiging met datum en tijd. Je bent zelf verantwoordelijk voor de namen en gegevens die je doorgeeft.', 'borduur-patch-studio' ) . '</p>'
			. '<p>' . esc_html(
				sprintf(
					/* translators: %d: days after the order was completed */
					__( '%d dagen na het afronden van je bestelling vervangen we de namen door "[gewist]", wissen we de bloedgroepen en verwijderen we het voorbeeldbeeld. Tot dan kun je dezelfde stroken makkelijk opnieuw bestellen.', 'borduur-patch-studio' ),
					self::nametape_days()
				)
			) . '</p>'
			. '<h3>' . esc_html__( 'AI-ontwerpstudio en doorgifte buiten de EU', 'borduur-patch-studio' ) . '</h3>'
			. '<p>' . esc_html__( 'Gebruik je de AI-ontwerpstudio, dan sturen we je ontwerpbeschrijving, zonder je naam of contactgegevens, naar onze AI-leveranciers OpenAI en Recraft. Zij kunnen die gegevens in de Verenigde Staten verwerken. Die doorgifte gebeurt op basis van het EU-VS Data Privacy Framework of de modelcontractbepalingen (SCC) van de Europese Commissie. Meer uitleg en de bewaartermijnen staan in het deel over de AI-ontwerpstudio.', 'borduur-patch-studio' ) . '</p>';
		return $html;
	}

	/**
	 * Register the suggested privacy-policy text.
	 */
	public static function policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( 'Patch Studio', wp_kses_post( self::policy_html() ) );
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $e Exporters.
	 */
	public static function exporters( $e ) {
		$e                         = is_array( $e ) ? $e : array();
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
		$e                         = is_array( $e ) ? $e : array();
		$e['borduur-patch-studio'] = array(
			'eraser_friendly_name' => __( 'Patch Studio-ontwerpen en offertes', 'borduur-patch-studio' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $e;
	}

	/* ---------------------------------------------------------------------
	 * Lookups
	 * ------------------------------------------------------------------- */

	/**
	 * WordPress user id with this e-mail (0 = none).
	 *
	 * @param string $email E-mail.
	 */
	public static function user_id( string $email ): int {
		if ( '' === $email || ! function_exists( 'get_user_by' ) ) {
			return 0;
		}
		$u = get_user_by( 'email', $email );
		return $u && ! empty( $u->ID ) ? (int) $u->ID : 0;
	}

	/**
	 * All orders of a billing e-mail and of the account with that e-mail (no cap; fetched in batches by id).
	 *
	 * @param string $email E-mail.
	 * @return array<int, \WC_Order> id => order.
	 */
	public static function orders( string $email ): array {
		$out = array();
		if ( '' === $email || ! function_exists( 'wc_get_orders' ) ) {
			return $out;
		}
		$queries = array( array( 'billing_email' => $email ) );
		$uid     = self::user_id( $email );
		if ( $uid > 0 ) {
			$queries[] = array( 'customer_id' => $uid );
		}
		foreach ( $queries as $where ) {
			for ( $page = 1; $page < 10000; $page++ ) {
				$batch = wc_get_orders(
					array_merge(
						$where,
						array(
							'limit'   => self::ORDER_BATCH,
							'page'    => $page,
							'orderby' => 'ID',
							'order'   => 'ASC',
							'return'  => 'objects',
							'type'    => 'shop_order',
						)
					)
				);
				$batch = is_array( $batch ) ? $batch : array();
				foreach ( $batch as $order ) {
					if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
						$out[ (int) $order->get_id() ] = $order;
					}
				}
				if ( count( $batch ) < self::ORDER_BATCH ) {
					break;
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Design ids linked to an e-mail (own meta, account, orders), sorted by id, no cap.
	 *
	 * @param string $email E-mail.
	 * @return int[]
	 */
	public static function design_ids( string $email ): array {
		if ( '' === $email ) {
			return array();
		}
		$ids = Designs::query(
			array(
				array(
					'key'   => '_bps_email',
					'value' => $email,
				),
			),
			-1
		);
		$ids = array_merge( $ids, User_Lifecycle::authored( Designs::CPT, self::user_id( $email ) ) );
		foreach ( self::orders( $email ) as $order ) {
			$ids = array_merge( $ids, Order_Lifecycle::design_ids( $order ) );
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		// Order lines can point to designs that no longer exist (deleted after the orphan period).
		$ids = array_values( array_filter( $ids, static fn( $id ) => $id > 0 && Designs::CPT === get_post_type( $id ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Quote ids for an e-mail (meta and account), sorted by id, no cap.
	 *
	 * @param string $email E-mail.
	 * @return int[]
	 */
	public static function quote_ids( string $email ): array {
		if ( '' === $email ) {
			return array();
		}
		$q   = new \WP_Query(
			array(
				'post_type'              => Quotes::CPT,
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_bps_email',
						'value' => $email,
					),
				),
			)
		);
		$ids = array_merge( array_map( 'intval', (array) $q->posts ), User_Lifecycle::authored( Quotes::CPT, self::user_id( $email ) ) );
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return $ids;
	}

	/* ---------------------------------------------------------------------
	 * Exporter
	 * ------------------------------------------------------------------- */

	/**
	 * Text for an arbitrary meta value (title, reminder, …).
	 *
	 * @param mixed $v Value.
	 */
	private static function text( $v ): string {
		if ( is_scalar( $v ) ) {
			return is_bool( $v ) ? ( $v ? __( 'ja', 'borduur-patch-studio' ) : '' ) : (string) $v;
		}
		if ( is_array( $v ) ) {
			$parts = array();
			foreach ( $v as $k => $x ) {
				if ( is_scalar( $x ) && '' !== (string) $x ) {
					$parts[] = ( is_string( $k ) ? $k . ': ' : '' ) . ( is_bool( $x ) ? ( $x ? 'true' : 'false' ) : (string) $x );
				}
			}
			return implode( ', ', $parts );
		}
		return '';
	}

	/**
	 * Proof history of a design as readable lines (used when Proof_Service is not available).
	 *
	 * @param array $proof Proof record.
	 */
	private static function proof_lines( array $proof ): string {
		$lines = array();
		foreach ( (array) ( $proof['history'] ?? array() ) as $h ) {
			if ( ! is_array( $h ) ) {
				continue;
			}
			$line = trim( (string) ( $h['at'] ?? '' ) . ' · ' . (string) ( $h['action'] ?? '' ) . ( isset( $h['round'] ) ? ' · ' . (int) $h['round'] : '' ) );
			if ( '' !== (string) ( $h['note'] ?? '' ) ) {
				$line .= ' · ' . (string) $h['note'];
			}
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Is the proof service of P05 available?
	 */
	private static function proof_service(): string {
		$cls = '\BorduurPatchStudio\Proof\Proof_Service';
		return class_exists( $cls ) && is_callable( array( $cls, 'export_for_order' ) ) ? $cls : '';
	}

	/**
	 * Export items of the proof service for one order: accepts a list of ready export items or name/value rows.
	 *
	 * @param \WC_Order $order Order.
	 * @return array[]
	 */
	private static function proof_items( $order ): array {
		$cls = self::proof_service();
		if ( '' === $cls ) {
			return array();
		}
		try {
			$res = call_user_func( array( $cls, 'export_for_order' ), $order );
		} catch ( \Throwable $e ) {
			self::log( 'privacy_proof_export_failed', array( 'exception' => $e ) );
			return array();
		}
		if ( ! is_array( $res ) || ! $res ) {
			return array();
		}
		$items = array();
		$rows  = array();
		foreach ( $res as $i => $entry ) {
			if ( is_array( $entry ) && isset( $entry['group_id'], $entry['data'] ) ) {
				$items[] = $entry;
			} elseif ( is_array( $entry ) && isset( $entry['name'] ) ) {
				$rows[] = array(
					'name'  => (string) $entry['name'],
					'value' => self::text( $entry['value'] ?? '' ),
				);
			}
		}
		if ( $rows ) {
			$items[] = array(
				'group_id'    => 'bps-proofs',
				'group_label' => __( 'Patch Studio-proeven', 'borduur-patch-studio' ),
				'item_id'     => 'bps-proof-' . (int) $order->get_id(),
				'data'        => $rows,
			);
		}
		return $items;
	}

	/**
	 * One export item for a design.
	 *
	 * @param array $d DesignRecord.
	 */
	private static function design_item( array $d ): array {
		$p    = $d['payload'];
		$rows = array(
			array( 'name' => __( 'Referentie', 'borduur-patch-studio' ), 'value' => $d['ref'] ),
			array( 'name' => __( 'Naam van het ontwerp', 'borduur-patch-studio' ), 'value' => self::text( get_post_meta( $d['id'], '_bps_title', true ) ) ),
			array( 'name' => __( 'Aangemaakt', 'borduur-patch-studio' ), 'value' => $d['created_gmt'] ),
			array( 'name' => __( 'Status', 'borduur-patch-studio' ), 'value' => $d['status'] ),
			array( 'name' => __( 'E-mail', 'borduur-patch-studio' ), 'value' => (string) $d['email'] ),
			array( 'name' => __( 'Herinnering', 'borduur-patch-studio' ), 'value' => self::text( get_post_meta( $d['id'], '_bps_reminder', true ) ) ),
			array( 'name' => __( 'Bestelling', 'borduur-patch-studio' ), 'value' => $d['order_id'] ? (string) $d['order_id'] : '' ),
			array( 'name' => __( 'Techniek', 'borduur-patch-studio' ), 'value' => Config::technique_label( (string) ( $p['technique'] ?? '' ) ) ),
			array( 'name' => __( 'Maat', 'borduur-patch-studio' ), 'value' => ! empty( $p['sizeCm'] ) ? Config::size_text( (float) $p['sizeCm'] ) : '' ),
			array( 'name' => __( 'Tekst op de patch', 'borduur-patch-studio' ), 'value' => implode( ' · ', array_filter( array_map( static fn( $t ) => is_array( $t ) ? (string) ( $t['text'] ?? '' ) : '', (array) ( $p['texts'] ?? array() ) ) ) ) ),
			array( 'name' => __( 'Opmerking', 'borduur-patch-studio' ), 'value' => (string) ( $p['notes'] ?? '' ) ),
			array( 'name' => __( 'Voorbeeld', 'borduur-patch-studio' ), 'value' => $d['preview_url'] ),
			array( 'name' => __( 'Origineel bestand', 'borduur-patch-studio' ), 'value' => $d['original'] ? $d['original']['name'] : '' ),
		);
		if ( '' === self::proof_service() ) {
			$rows[] = array( 'name' => __( 'Proefgeschiedenis', 'borduur-patch-studio' ), 'value' => self::proof_lines( (array) $d['proof'] ) );
		}
		return array(
			'group_id'    => 'bps-designs',
			'group_label' => __( 'Patch Studio-ontwerpen', 'borduur-patch-studio' ),
			'item_id'     => 'bps-design-' . $d['id'],
			'data'        => array_values( array_filter( $rows, static fn( $row ) => '' !== (string) $row['value'] ) ),
		);
	}

	/**
	 * One export item for a quote.
	 *
	 * @param array $q Quote data.
	 */
	private static function quote_item( array $q ): array {
		$rows = array(
			array( 'name' => __( 'Referentie', 'borduur-patch-studio' ), 'value' => $q['ref'] ),
			array( 'name' => __( 'Datum', 'borduur-patch-studio' ), 'value' => $q['created'] ),
			array( 'name' => __( 'Naam', 'borduur-patch-studio' ), 'value' => $q['name'] ),
			array( 'name' => __( 'E-mail', 'borduur-patch-studio' ), 'value' => $q['email'] ),
			array( 'name' => __( 'Telefoon', 'borduur-patch-studio' ), 'value' => $q['phone'] ),
			array( 'name' => __( 'Bedrijf', 'borduur-patch-studio' ), 'value' => $q['company'] ),
			array( 'name' => __( 'Btw-nummer', 'borduur-patch-studio' ), 'value' => $q['vat'] ),
			array( 'name' => __( 'Aantal', 'borduur-patch-studio' ), 'value' => $q['qty'] ? (string) $q['qty'] : '' ),
			array( 'name' => __( 'Bericht', 'borduur-patch-studio' ), 'value' => $q['message'] ),
		);
		return array(
			'group_id'    => 'bps-quotes',
			'group_label' => __( 'Patch Studio-offertes', 'borduur-patch-studio' ),
			'item_id'     => 'bps-quote-' . $q['id'],
			'data'        => array_values( array_filter( $rows, static fn( $row ) => '' !== (string) $row['value'] ) ),
		);
	}

	/**
	 * Exporter: designs, quotes and (with P05) the proof history per order, PAGE entries per call.
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page (1-based).
	 */
	public static function export( $email, $page = 1 ): array {
		$email   = sanitize_email( (string) $email );
		$page    = max( 1, (int) $page );
		$data    = array();
		$designs = self::design_ids( $email );
		$all     = array_merge(
			array_map( static fn( $id ) => array( 'd', $id ), $designs ),
			array_map( static fn( $id ) => array( 'q', $id ), self::quote_ids( $email ) )
		);
		if ( '' !== self::proof_service() ) {
			$orders = array();
			foreach ( $designs as $id ) {
				$oid = (int) get_post_meta( $id, '_bps_order_id', true );
				if ( $oid > 0 ) {
					$orders[ $oid ] = true;
				}
			}
			ksort( $orders );
			foreach ( array_keys( $orders ) as $oid ) {
				$all[] = array( 'p', $oid );
			}
		}
		$slice = array_slice( $all, ( $page - 1 ) * self::PAGE, self::PAGE );
		foreach ( $slice as $entry ) {
			try {
				if ( 'd' === $entry[0] ) {
					$d = Designs::get( $entry[1] );
					if ( $d ) {
						$data[] = self::design_item( $d );
					}
				} elseif ( 'q' === $entry[0] ) {
					$q = Quotes::get( $entry[1] );
					if ( $q ) {
						$data[] = self::quote_item( $q );
					}
				} else {
					$order = function_exists( 'wc_get_order' ) ? wc_get_order( $entry[1] ) : null;
					if ( $order ) {
						foreach ( self::proof_items( $order ) as $item ) {
							$data[] = $item;
						}
					}
				}
			} catch ( \Throwable $e ) {
				self::log( 'privacy_export_item_failed', array( 'exception' => $e ) );
			}
		}
		return array(
			'data' => $data,
			'done' => count( $all ) <= $page * self::PAGE,
		);
	}

	/* ---------------------------------------------------------------------
	 * Eraser
	 * ------------------------------------------------------------------- */

	/**
	 * Erase one design whose order is finished (or that never had one).
	 *
	 * @param array $d DesignRecord.
	 */
	public static function erase_design( array $d ): void {
		$id = (int) $d['id'];
		if ( 'ordered' !== $d['status'] && ! $d['order_id'] ) {
			wp_delete_post( $id, true ); // Files go via Designs::on_delete_post.
			return;
		}
		$orig = $d['payload'];
		Cleanup::expire_ordered( $id, false );
		// Free text of the customer on the design (the order line meta is left to WooCommerce' own anonymisation).
		$payload = json_decode( (string) get_post_meta( $id, '_bps_payload', true ), true );
		$payload = is_array( $payload ) ? $payload : $orig;
		if ( isset( $payload['texts'] ) && is_array( $payload['texts'] ) ) {
			foreach ( $payload['texts'] as $i => $t ) {
				if ( is_array( $t ) && '' !== (string) ( $t['text'] ?? '' ) ) {
					$payload['texts'][ $i ]['text'] = self::ERASED;
				}
			}
		}
		if ( '' !== (string) ( $orig['notes'] ?? '' ) ) {
			$payload['notes'] = self::ERASED;
		}
		update_post_meta( $id, '_bps_payload', wp_slash( (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		delete_post_meta( $id, '_bps_title' );
		delete_post_meta( $id, '_bps_reminder' );
		$post = get_post( $id );
		if ( $post && (int) $post->post_author > 0 ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_author' => 0,
				)
			);
		}
		update_post_meta( $id, '_bps_erased_gmt', gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Erase one quote request (same routine as the retention cron) and drop the account link.
	 *
	 * @param int $qid Quote id.
	 */
	public static function erase_quote( int $qid ): void {
		Cleanup::anonymise_quote( $qid );
		$post = get_post( $qid );
		if ( $post && (int) $post->post_author > 0 ) {
			wp_update_post(
				array(
					'ID'          => $qid,
					'post_author' => 0,
				)
			);
		}
	}

	/**
	 * Work list of the eraser: entries that still need erasing, and the designs that must be retained.
	 *
	 * @param string $email E-mail.
	 * @return array{todo: array<int, array{0:string,1:int}>, retained: string[]}
	 */
	private static function erase_plan( string $email ): array {
		$todo     = array();
		$retained = array();
		foreach ( self::design_ids( $email ) as $id ) {
			if ( get_post_meta( $id, '_bps_erased_gmt', true ) ) {
				continue; // Already erased earlier (still linked through the order).
			}
			$status   = (string) get_post_meta( $id, '_bps_status', true );
			$order_id = (int) get_post_meta( $id, '_bps_order_id', true );
			if ( 'ordered' === $status || $order_id > 0 ) {
				$order = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
				if ( $order && ! Order_Lifecycle::is_finished( $order ) ) {
					$post       = get_post( $id );
					$retained[] = $post ? (string) $post->post_title : (string) $id;
					continue;
				}
			}
			$todo[] = array( 'd', $id );
		}
		foreach ( self::quote_ids( $email ) as $qid ) {
			$post = get_post( $qid );
			if ( ! $post || ( get_post_meta( $qid, '_bps_anonymised_gmt', true ) && 0 === (int) $post->post_author ) ) {
				continue;
			}
			$todo[] = array( 'q', $qid );
		}
		return array(
			'todo'     => $todo,
			'retained' => $retained,
		);
	}

	/**
	 * Eraser. Files of orders that are still being processed are kept (needed to fulfil the contract); a pending
	 * order older than 14 days counts as finished (Order_Lifecycle::is_finished).
	 *
	 * @param string $email E-mail.
	 * @param int    $page  Page (WordPress counts up; the work list shrinks as items are erased).
	 */
	public static function erase( $email, $page = 1 ): array {
		unset( $page );
		$email    = sanitize_email( (string) $email );
		$removed  = false;
		$messages = array();
		$plan     = self::erase_plan( $email );
		$slice    = array_slice( $plan['todo'], 0, self::PAGE );

		foreach ( $slice as $entry ) {
			try {
				if ( 'd' === $entry[0] ) {
					$d = Designs::get( $entry[1] );
					if ( $d ) {
						self::erase_design( $d );
						$removed = true;
					}
				} else {
					self::erase_quote( $entry[1] );
					$removed = true;
				}
			} catch ( \Throwable $e ) {
				self::log( 'privacy_erase_item_failed', array( 'exception' => $e ) );
			}
		}

		$done = count( $slice ) < self::PAGE;
		if ( $done ) {
			foreach ( $plan['retained'] as $ref ) {
				/* translators: %s: design reference */
				$messages[] = sprintf( __( 'Ontwerp %s hoort bij een bestelling die nog loopt en wordt bewaard tot ze afgerond is.', 'borduur-patch-studio' ), $ref );
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => ! empty( $plan['retained'] ),
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Log through the plugin Logger (contract C1) when available.
	 *
	 * @param string $event   Event.
	 * @param array  $context Context.
	 */
	private static function log( string $event, array $context = array() ): void {
		if ( class_exists( '\BorduurPatchStudio\Logger' ) ) {
			\BorduurPatchStudio\Logger::error( $event, $context );
		}
	}
}
