<?php
/**
 * Order line meta (classic + block checkout) and the design → order link. HPOS-safe (CRUD only).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * `woocommerce_checkout_create_order_line_item` is used by WC_Checkout and by the Store API
 * OrderController, so one hook covers both checkouts. Only WC_Order/WC_Order_Item CRUD is used:
 * no get_post_meta() on orders, no queries on wp_posts for shop_order (HPOS compatible).
 */
final class Order {

	/** Visible meta key → label (translated at display time). */
	const VISIBLE = array( 'bps_ref', 'bps_technique', 'bps_size', 'bps_shape', 'bps_finish', 'bps_rights', 'bps_file_removed' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'line_item_meta' ), 10, 4 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( __CLASS__, 'display_key' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'classic_processed' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'mark_ordered' ), 10, 1 );
		// Safety net for orders created another way (e.g. admin "pay for order" after a failed payment).
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'mark_ordered_by_id' ), 10, 1 );
	}

	/**
	 * Copy the design to the order line.
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
		$b      = $values['bps'];
		$locale = (string) ( $b['locale'] ?? '' );
		// Same language as the cart, fees and WooCommerce texts of this checkout request.
		$lang = Config::lang();
		$design = Designs::get( (int) $b['design_id'] );

		$item->add_meta_data( 'bps_ref', (string) $b['ref'], true );
		$item->add_meta_data( 'bps_technique', Config::technique_label( (string) $b['technique'], $lang ), true );
		$item->add_meta_data( 'bps_size', Config::size_text( (float) $b['size_cm'], (float) ( $b['width_mm'] ?? 0 ), (float) ( $b['height_mm'] ?? 0 ) ), true );
		$item->add_meta_data( 'bps_shape', Config::shape_label( (string) $b['shape'], $lang ), true );
		$finish = Cart::finish_text( (array) $b['options'], (string) $b['technique'], $lang );
		if ( '' !== $finish ) {
			$item->add_meta_data( 'bps_finish', $finish, true );
		}
		$texts = Cart::texts_text( (array) ( $b['texts'] ?? array() ), $lang );
		if ( '' !== $texts ) {
			$item->add_meta_data( 'bps_texts', $texts, true );
		}

		$rights = isset( $b['rights'] ) && is_array( $b['rights'] ) ? $b['rights'] : array();
		if ( ! empty( $rights['at'] ) ) {
			$ts = strtotime( (string) $rights['at'] );
			$item->add_meta_data(
				'bps_rights',
				sprintf(
					/* translators: 1: date, 2: time, 3: text version */
					__( 'Bevestigd op %1$s om %2$s (tekstversie %3$s)', 'borduur-patch-studio' ),
					wp_date( 'd-m-Y', $ts ),
					wp_date( 'H:i', $ts ),
					(string) ( $rights['v'] ?? '' )
				),
				true
			);
			$item->add_meta_data(
				'_bps_rights_consent',
				array(
					'v'         => (string) ( $rights['v'] ?? '' ),
					'at'        => (string) $rights['at'],
					'text_sha1' => sha1( self::rights_text( (string) ( $rights['locale'] ?? $locale ) ) ),
					'locale'    => (string) ( $rights['locale'] ?? Config::wp_locale( $locale ) ),
				),
				true
			);
		}

		$item->add_meta_data( '_bps_design_id', (int) $b['design_id'], true );
		$item->add_meta_data( '_bps_preview_url', esc_url_raw( (string) $b['preview_url'] ), true );
		$item->add_meta_data( '_bps_schema', 1, true );
		$item->add_meta_data( '_bps_technique_id', (string) $b['technique'], true );
		$item->add_meta_data( '_bps_size_cm', (float) $b['size_cm'], true );
		$item->add_meta_data( '_bps_shape_id', (string) $b['shape'], true );
		$item->add_meta_data( '_bps_options', wp_json_encode( array_values( (array) $b['options'] ) ), true );
		$unit = Cart::unit_price( (string) $cart_item_key );
		if ( null === $unit && isset( $values['data'] ) && $values['data'] instanceof \WC_Product ) {
			$unit = (float) $values['data']->get_price();
		}
		$item->add_meta_data( '_bps_unit_excl', (float) $unit, true );
		if ( $design ) {
			$item->add_meta_data( '_bps_payload', wp_json_encode( $design['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), true );
		}
	}

	/**
	 * Rights checkbox text (08 §3.2) — its SHA-1 is logged with the order as proof of what was shown.
	 *
	 * @param string $locale Locale.
	 */
	public static function rights_text( string $locale = '' ): string {
		if ( 'fr' === Config::lang( '' !== $locale ? $locale : null ) ) {
			return "Je confirme être autorisé(e) à faire réaliser ce design (logo, image, texte) : il m'appartient ou j'ai l'accord de son propriétaire.";
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
