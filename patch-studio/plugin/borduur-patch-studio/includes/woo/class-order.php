<?php
/**
 * Order line meta (classic + block checkout) and the design → order link. HPOS-safe (CRUD only).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

use BorduurPatchStudio\Lead_Time;
use BorduurPatchStudio\Logger;
use BorduurPatchStudio\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * `woocommerce_checkout_create_order_line_item` is used by WC_Checkout and by the Store API
 * OrderController, so one hook covers both checkouts. Only WC_Order/WC_Order_Item CRUD is used:
 * no get_post_meta() on orders, no queries on wp_posts for shop_order (HPOS compatible).
 */
final class Order {

	/** Visible meta key → label (translated at display time). */
	const VISIBLE = array( 'bps_ref', 'bps_technique', 'bps_size', 'bps_shape', 'bps_finish', 'bps_rights', 'bps_file_removed', 'bps_reorder', 'bps_variants' );

	/** Order meta with the promised delivery window (concurrentie-features-7). */
	const PROMISED_FROM = '_bps_promised_from';
	const PROMISED_TO   = '_bps_promised_to';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'line_item_meta' ), 10, 4 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'promise_dates' ), 20, 1 );
		add_filter( 'bps_fiche_sections', array( __CLASS__, 'fiche_sections' ), 12, 3 );
		add_filter( 'bps_fiche_data', array( __CLASS__, 'fiche_data' ), 12, 3 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( __CLASS__, 'display_key' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'classic_processed' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'mark_ordered' ), 10, 1 );
		// Safety net for orders created another way (e.g. admin "pay for order" after a failed payment).
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'mark_ordered_by_id' ), 10, 1 );
	}

	/**
	 * Copy the design to the order line (classic + block checkout).
	 *
	 * @param \WC_Order_Item_Product $item          Order item.
	 * @param string                 $cart_item_key Cart key.
	 * @param array                  $values        Cart item.
	 * @param \WC_Order              $order         Order.
	 */
	public static function line_item_meta( $item, $cart_item_key, $values, $order ): void {
		if ( empty( $values['bps'] ) || ! is_array( $values['bps'] ) || ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}
		// Null when set_prices did not run in this request: write_line_meta() then recomputes the price excl. VAT
		// (never the WooCommerce price, which is incl. VAT when the shop enters prices incl. VAT).
		self::write_line_meta( $item, $values['bps'], Cart::unit_price( (string) $cart_item_key ) );
	}

	/**
	 * Write every Patch Studio meta key on an order line from cart-style bps data. Public for other packages
	 * (contract C5: P07 converts a quote into an order with it). HPOS-safe: item CRUD only.
	 *
	 * @param \WC_Order_Item_Product $item      Order line.
	 * @param array                  $bps       Cart bps data (design_id, ref, technique, size_cm, shape, options, …).
	 * @param float|null             $unit_excl Unit price excl. VAT; null = locked_unit_excl or the current engine price.
	 */
	public static function write_line_meta( \WC_Order_Item_Product $item, array $bps, ?float $unit_excl = null ): void {
		$b      = $bps;
		$locale = (string) ( $b['locale'] ?? '' );
		// Same language as the cart, fees and WooCommerce texts of this checkout request.
		$lang   = Config::lang();
		$design = Designs::get( (int) ( $b['design_id'] ?? 0 ) );

		$item->add_meta_data( 'bps_ref', (string) ( $b['ref'] ?? '' ), true );
		$item->add_meta_data( 'bps_technique', Config::technique_label( (string) ( $b['technique'] ?? '' ), $lang ), true );
		$item->add_meta_data( 'bps_size', Config::size_text( (float) ( $b['size_cm'] ?? 0 ), (float) ( $b['width_mm'] ?? 0 ), (float) ( $b['height_mm'] ?? 0 ) ), true );
		$item->add_meta_data( 'bps_shape', Config::shape_label( (string) ( $b['shape'] ?? '' ), $lang ), true );
		$finish = Cart::finish_text( (array) ( $b['options'] ?? array() ), (string) ( $b['technique'] ?? '' ), $lang );
		if ( '' !== $finish ) {
			$item->add_meta_data( 'bps_finish', $finish, true );
		}
		$texts = Cart::texts_text( (array) ( $b['texts'] ?? array() ), $lang );
		if ( '' !== $texts ) {
			$item->add_meta_data( 'bps_texts', $texts, true );
		}

		$rights = isset( $b['rights'] ) && is_array( $b['rights'] ) ? $b['rights'] : array();
		if ( ! empty( $rights['at'] ) ) {
			// gdpr-juridisch-8: the version is the server's text version, never the client's value.
			$version = (string) Config::path( 'legal.rightsTextVersion', (string) ( $rights['v'] ?? '' ) );
			$ts      = strtotime( (string) $rights['at'] );
			$item->add_meta_data(
				'bps_rights',
				sprintf(
					/* translators: 1: date, 2: time, 3: text version */
					__( 'Bevestigd op %1$s om %2$s (tekstversie %3$s)', 'borduur-patch-studio' ),
					wp_date( 'd-m-Y', $ts ),
					wp_date( 'H:i', $ts ),
					$version
				),
				true
			);
			$record = array(
				'v'         => $version,
				'at'        => (string) $rights['at'],
				'text_sha1' => sha1( self::rights_text( (string) ( $rights['locale'] ?? $locale ) ) ),
				'locale'    => (string) ( $rights['locale'] ?? Config::wp_locale( $locale ) ),
			);
			$client = (string) ( $rights['client_v'] ?? ( ( $rights['v'] ?? '' ) !== $version ? ( $rights['v'] ?? '' ) : '' ) );
			if ( '' !== $client && $client !== $version ) {
				$record['client_v'] = substr( $client, 0, 20 );
			}
			if ( ! empty( $rights['source'] ) ) {
				$record['source'] = (string) $rights['source'];
			}
			$item->add_meta_data( '_bps_rights_consent', $record, true );
		}

		if ( ! empty( $b['reorder_of'] ) && is_array( $b['reorder_of'] ) ) {
			$r = Cart::reorder_ref( $b['reorder_of'] );
			$item->add_meta_data( 'bps_reorder', self::reorder_meta_text( $r ), true );
			$item->add_meta_data( '_bps_reorder_of', $r, true );
		}

		$names = $design ? Variant_Names::names( (array) $design['payload'] ) : array();
		if ( $names ) {
			/* translators: %s: number of names */
			$item->add_meta_data( 'bps_variants', sprintf( _n( '%s naam (namenlijst op de productiefiche)', '%s namen (namenlijst op de productiefiche)', count( $names ), 'borduur-patch-studio' ), number_format_i18n( count( $names ) ) ), true );
			$item->add_meta_data( '_bps_variant_names', wp_json_encode( $names, JSON_UNESCAPED_UNICODE ), true );
		}

		$item->add_meta_data( '_bps_design_id', (int) ( $b['design_id'] ?? 0 ), true );
		$item->add_meta_data( '_bps_preview_url', esc_url_raw( (string) ( $b['preview_url'] ?? '' ) ), true );
		$item->add_meta_data( '_bps_schema', 1, true );
		$item->add_meta_data( '_bps_technique_id', (string) ( $b['technique'] ?? '' ), true );
		$item->add_meta_data( '_bps_size_cm', (float) ( $b['size_cm'] ?? 0 ), true );
		$item->add_meta_data( '_bps_shape_id', (string) ( $b['shape'] ?? '' ), true );
		$item->add_meta_data( '_bps_options', wp_json_encode( array_values( (array) ( $b['options'] ?? array() ) ) ), true );
		if ( null === $unit_excl ) {
			$unit_excl = isset( $b['locked_unit_excl'] ) && is_numeric( $b['locked_unit_excl'] )
				? (float) $b['locked_unit_excl']
				: self::engine_unit( $b, (int) $item->get_quantity() );
		}
		// Always excl. VAT, also when the shop enters prices incl. VAT (woo-compat-1).
		$item->add_meta_data( '_bps_unit_excl', (float) $unit_excl, true );
		if ( isset( $b['locked_unit_excl'] ) ) {
			$item->add_meta_data( '_bps_price_locked', 1, true );
		}
		if ( $design ) {
			$item->add_meta_data( '_bps_payload', wp_json_encode( $design['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), true );
		}
	}

	/**
	 * Engine unit price excl. VAT for a line (0 when it cannot be computed; logged).
	 *
	 * @param array $bps Bps data.
	 * @param int   $qty Quantity.
	 */
	private static function engine_unit( array $bps, int $qty ): float {
		try {
			return (float) Pricing::cart_unit_excl( $bps, max( 1, $qty ), 0 );
		} catch ( \Throwable $e ) {
			Logger::error(
				'order_unit_price_failed',
				array(
					'ref'       => (string) ( $bps['ref'] ?? '' ),
					'technique' => (string) ( $bps['technique'] ?? '' ),
					'qty'       => $qty,
					'error'     => $e->getMessage(),
				)
			);
			return 0.0;
		}
	}

	/**
	 * Visible reorder line for the order and the production sheet.
	 *
	 * @param array $r {design_id, order_id, ref}.
	 */
	public static function reorder_meta_text( array $r ): string {
		$order = (int) ( $r['order_id'] ?? 0 );
		return $order > 0
			/* translators: 1: design reference, 2: order number */
			? sprintf( __( 'Herbestelling van %1$s (bestelling #%2$s): bestaand borduurbestand gebruiken', 'borduur-patch-studio' ), (string) ( $r['ref'] ?? '' ), $order )
			/* translators: %s: design reference */
			: sprintf( __( 'Herbestelling van %s: bestaand borduurbestand gebruiken', 'borduur-patch-studio' ), (string) ( $r['ref'] ?? '' ) );
	}

	/**
	 * Promised delivery window on the order (only when every lead-time setting is filled in; never invented).
	 *
	 * @param mixed $order Order being created.
	 */
	public static function promise_dates( $order ): void {
		if ( ! $order instanceof \WC_Order || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$techniques = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['bps']['technique'] ) ) {
				$techniques[] = (string) $item['bps']['technique'];
			}
		}
		$estimate = Lead_Time::estimate_many( Config::get(), $techniques );
		if ( null === $estimate ) {
			return;
		}
		$order->update_meta_data( self::PROMISED_FROM, $estimate['from'] );
		$order->update_meta_data( self::PROMISED_TO, $estimate['to'] );
	}

	/**
	 * Promised window of an order or null.
	 *
	 * @param mixed $order Order.
	 * @return array{from:string,to:string}|null
	 */
	public static function promised( $order ): ?array {
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}
		$from = (string) $order->get_meta( self::PROMISED_FROM );
		$to   = (string) $order->get_meta( self::PROMISED_TO );
		if ( '' === $from || '' === $to ) {
			return null;
		}
		return array(
			'from' => $from,
			'to'   => $to,
		);
	}

	/**
	 * Fiche row with the promised window (bps_fiche_sections of the current Order_Production).
	 *
	 * @param mixed $sections Sections.
	 * @param mixed $rec      DesignRecord.
	 * @param mixed $item     Order line.
	 * @return mixed
	 */
	public static function fiche_sections( $sections, $rec = array(), $item = null ) {
		if ( ! is_array( $sections ) || isset( $sections['delivery'] ) || ! $item instanceof \WC_Order_Item_Product ) {
			return $sections;
		}
		$row = self::delivery_row( $item->get_order() );
		if ( $row ) {
			$sections['delivery'] = array(
				'title' => __( 'Levering', 'borduur-patch-studio' ),
				'rows'  => array( $row ),
			);
		}
		return $sections;
	}

	/**
	 * Same data for the C6 filter bps_fiche_data (P05).
	 *
	 * @param mixed $fiche Fiche.
	 * @param mixed $order Order.
	 * @param mixed $item  Order line.
	 * @return mixed
	 */
	public static function fiche_data( $fiche, $order = null, $item = null ) {
		if ( ! is_array( $fiche ) ) {
			return $fiche;
		}
		$order = $order instanceof \WC_Order ? $order : ( $item instanceof \WC_Order_Item_Product ? $item->get_order() : null );
		$p     = self::promised( $order );
		if ( ! $p ) {
			return $fiche;
		}
		$fiche['promised'] = $p + array( 'text' => Lead_Time::format( $p, Config::lang() ) );
		if ( isset( $fiche['sections'] ) && is_array( $fiche['sections'] ) && ! isset( $fiche['sections']['delivery'] ) ) {
			$fiche['sections']['delivery'] = array(
				'title' => __( 'Levering', 'borduur-patch-studio' ),
				'rows'  => array( self::delivery_row( $order ) ),
			);
		}
		return $fiche;
	}

	/**
	 * Row "Beloofd aan de klant: di 24 nov – do 26 nov".
	 *
	 * @param mixed $order Order.
	 */
	private static function delivery_row( $order ): ?array {
		$p = self::promised( $order );
		if ( ! $p ) {
			return null;
		}
		return array(
			'label' => __( 'Beloofd aan de klant', 'borduur-patch-studio' ),
			'value' => Lead_Time::format( $p, Config::lang() ),
		);
	}

	/**
	 * Rights checkbox text (08 §3.2) — its SHA-1 is logged with the order as proof of what was shown.
	 *
	 * @param string $locale Locale.
	 */
	public static function rights_text( string $locale = '' ): string {
		if ( 'fr' === Config::lang( '' !== $locale ? $locale : null ) ) {
			// Identical to ui.s5.rights (fr) in assets/src/ui/messages.js, typographic apostrophes included.
			return 'Je confirme être autorisé(e) à faire réaliser ce design (logo, image, texte) : il m’appartient ou j’ai l’accord de son propriétaire.';
		}
		return 'Ik bevestig dat ik dit ontwerp (logo, afbeelding, tekst) mag laten maken: het is van mij, of ik heb toestemming van de eigenaar.';
	}

	/**
	 * Readable labels for our technical keys.
	 *
	 * @param string         $display_key Key.
	 * @param \WC_Meta_Data  $meta        Meta.
	 * @param \WC_Order_Item $item        Item.
	 */
	public static function display_key( $display_key, $meta = null, $item = null ) {
		$key = is_object( $meta ) && isset( $meta->key ) ? (string) $meta->key : (string) $display_key;
		switch ( $key ) {
			case 'bps_ref':
				return __( 'Ontwerp', 'borduur-patch-studio' );
			case 'bps_technique':
				return __( 'Techniek', 'borduur-patch-studio' );
			case 'bps_size':
				return __( 'Maat', 'borduur-patch-studio' );
			case 'bps_shape':
				return __( 'Vorm', 'borduur-patch-studio' );
			case 'bps_finish':
				return __( 'Afwerking', 'borduur-patch-studio' );
			case 'bps_texts':
				return __( 'Tekst', 'borduur-patch-studio' );
			case 'bps_rights':
				return __( 'Rechtenverklaring', 'borduur-patch-studio' );
			case 'bps_file_removed':
				return __( 'Ontwerpbestand', 'borduur-patch-studio' );
			case 'bps_reorder':
				return __( 'Herbestelling', 'borduur-patch-studio' );
			case 'bps_variants':
				return __( 'Namenlijst', 'borduur-patch-studio' );
		}
		return $display_key;
	}

	/**
	 * Classic checkout.
	 *
	 * @param int       $order_id Order id.
	 * @param array     $posted   Posted data.
	 * @param \WC_Order $order    Order.
	 */
	public static function classic_processed( $order_id, $posted = array(), $order = null ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order instanceof \WC_Order ) {
			self::mark_ordered( $order );
		}
	}

	/**
	 * By id (payment complete).
	 *
	 * @param int $order_id Order id.
	 */
	public static function mark_ordered_by_id( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			self::mark_ordered( $order );
		}
	}

	/**
	 * Set every design of the order to "ordered" and link it (idempotent).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function mark_ordered( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$design_id = (int) $item->get_meta( '_bps_design_id' );
			if ( $design_id <= 0 || ! Designs::get( $design_id ) ) {
				continue;
			}
			$already = (int) get_post_meta( $design_id, '_bps_order_item_id', true ) === (int) $item->get_id()
				&& 'ordered' === get_post_meta( $design_id, '_bps_status', true );
			if ( $already ) {
				continue;
			}
			Designs::set_status( $design_id, 'ordered' );
			self::link_author( $design_id, (int) $order->get_customer_id() );
			update_post_meta( $design_id, '_bps_order_id', (int) $order->get_id() );
			update_post_meta( $design_id, '_bps_order_item_id', (int) $item->get_id() );
			update_post_meta( $design_id, '_bps_ordered_gmt', gmdate( 'Y-m-d H:i:s' ) );
			$email = (string) $order->get_billing_email();
			if ( '' !== $email && ! get_post_meta( $design_id, '_bps_email', true ) ) {
				update_post_meta( $design_id, '_bps_email', sanitize_email( $email ) );
			}
			do_action( 'bps_design_ordered', $design_id, $order, $item );
		}
	}

	/**
	 * Link an anonymous design to the customer of the order (klant-account-2): only when it has no author yet,
	 * never re-assigned.
	 *
	 * @param int $design_id   Design id.
	 * @param int $customer_id Order customer (0 = guest).
	 */
	private static function link_author( int $design_id, int $customer_id ): void {
		if ( $customer_id <= 0 ) {
			return;
		}
		$post = get_post( $design_id );
		if ( ! $post || (int) $post->post_author > 0 ) {
			return;
		}
		$res = wp_update_post(
			array(
				'ID'          => $design_id,
				'post_author' => $customer_id,
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			Logger::warning(
				'design_author_link_failed',
				array(
					'design' => $design_id,
					'error'  => $res->get_error_message(),
				)
			);
		}
	}

	/**
	 * Does this order contain anything made to measure (patch, name tapes or a cross-sell product with the customer's
	 * design)? Used for the "no right of withdrawal" block in the e-mails (gdpr-juridisch-4; Emails is P07's).
	 *
	 * @param mixed $order Order.
	 */
	public static function has_custom_made( $order ): bool {
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && ( $item->get_meta( '_bps_design_id' ) || $item->get_meta( '_bps_nt_schema' ) || $item->get_meta( '_bps_cs' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does this order contain a Patch Studio line?
	 *
	 * @param mixed $order Order.
	 */
	public static function has_design( $order ): bool {
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && $item->get_meta( '_bps_design_id' ) ) {
				return true;
			}
		}
		return false;
	}
}
