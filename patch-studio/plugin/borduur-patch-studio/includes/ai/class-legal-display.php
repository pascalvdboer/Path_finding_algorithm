<?php
/**
 * Visible "based on an AI proposal" lines in the cart and on order items (04-juridisch §1.2–§1.3, AI Act art. 50).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * - Cart / mini-cart / checkout: `legal.ai.cart_line` ("Ontwerp → Op basis van een AI-voorstel · nagekeken bij je
 *   proef") for every cart line whose saved design carries the `_bps_ai` marker (set by Design_Link).
 * - Order item (customer e-mails incl. the proof mail, Mijn account, admin, invoice plugins that use the formatted
 *   meta): `legal.ai.proof_line`. The raw `_bps_ai` record stays hidden (underscore key).
 * Registered from Module::register_always(): orders with an AI design keep their label when the module is off later.
 * Only reads data; never adds the label to designs that were not verified as AI designs.
 */
final class Legal_Display {

	/**
	 * Hooks (filters only; priorities after the core cart/order lines).
	 */
	public static function register(): void {
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 40, 2 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( __CLASS__, 'order_item_meta' ), 40, 2 );
	}

	/**
	 * 'nl' | 'fr' of the current request.
	 *
	 * @param string|null $locale Locale override.
	 */
	public static function lang( ?string $locale = null ): string {
		if ( null === $locale || '' === $locale ) {
			$locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : 'nl_BE';
		}
		return 0 === strpos( strtolower( $locale ), 'fr' ) ? 'fr' : 'nl';
	}

	/**
	 * Legal string from config/ai-legal.json ('' when missing: never show a key).
	 *
	 * @param string $key  Key.
	 * @param string $lang nl|fr.
	 */
	public static function text( string $key, string $lang ): string {
		$legal = Module::legal();
		$s     = $legal['strings'][ $key ] ?? null;
		return is_array( $s ) && isset( $s[ $lang ] ) ? (string) $s[ $lang ] : '';
	}

	/**
	 * Split "Label → value" (legal.ai.cart_line) into [label, value].
	 *
	 * @param string $line Line.
	 * @return array{0:string,1:string}
	 */
	public static function split( string $line ): array {
		$parts = preg_split( '/\s*→\s*/u', $line, 2 );
		if ( is_array( $parts ) && 2 === count( $parts ) ) {
			return array( trim( $parts[0] ), trim( $parts[1] ) );
		}
		return array( '', trim( $line ) );
	}

	/**
	 * Is this `_bps_ai` record a verified AI design?
	 *
	 * @param mixed $meta Meta value.
	 */
	public static function is_ai( $meta ): bool {
		return is_array( $meta ) && ! empty( $meta['jobId'] );
	}

	/**
	 * Cart line: woocommerce_get_item_data.
	 *
	 * @param mixed $data      Item data rows.
	 * @param mixed $cart_item Cart item.
	 * @return array
	 */
	public static function cart_item_data( $data, $cart_item ): array {
		$data = is_array( $data ) ? $data : array();
		$id   = is_array( $cart_item ) ? (int) ( $cart_item['bps']['design_id'] ?? 0 ) : 0;
		if ( $id <= 0 || ! function_exists( 'get_post_meta' ) || ! self::is_ai( get_post_meta( $id, Design_Link::META, true ) ) ) {
			return $data;
		}
		$lang = self::lang();
		list( $label, $value ) = self::split( self::text( 'legal.ai.cart_line', $lang ) );
		if ( '' === $value ) {
			return $data;
		}
		$data[] = array(
			'key'   => '' !== $label ? $label : ( 'fr' === $lang ? 'Design' : 'Ontwerp' ),
			'value' => $value,
		);
		return $data;
	}

	/**
	 * Order item: woocommerce_order_item_get_formatted_meta_data.
	 *
	 * @param mixed $formatted Formatted meta (id => object).
	 * @param mixed $item      WC_Order_Item.
	 * @return array
	 */
	public static function order_item_meta( $formatted, $item ): array {
		$formatted = is_array( $formatted ) ? $formatted : array();
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) || ! self::is_ai( $item->get_meta( Design_Link::META ) ) ) {
			return $formatted;
		}
		$lang = self::order_lang( $item );
		$line = self::text( 'legal.ai.proof_line', $lang );
		if ( '' === $line ) {
			return $formatted;
		}
		list( $label ) = self::split( self::text( 'legal.ai.cart_line', $lang ) );
		$label         = '' !== $label ? $label : ( 'fr' === $lang ? 'Design' : 'Ontwerp' );
		$formatted[ 'bps-ai-' . ( method_exists( $item, 'get_id' ) ? (int) $item->get_id() : 0 ) ] = (object) array(
			'key'           => 'bps_ai_label',
			'value'         => $line,
			'display_key'   => $label,
			'display_value' => '<p>' . ( function_exists( 'esc_html' ) ? esc_html( $line ) : htmlspecialchars( $line, ENT_QUOTES ) ) . '</p>',
		);
		return $formatted;
	}

	/**
	 * Language of the order an item belongs to (WPML/Polylang meta, else the request).
	 *
	 * @param object $item Order item.
	 */
	private static function order_lang( $item ): string {
		$order = method_exists( $item, 'get_order' ) ? $item->get_order() : null;
		if ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
			foreach ( array( 'wpml_language', 'pll_language', '_bps_lang' ) as $k ) {
				$v = (string) $order->get_meta( $k );
				if ( '' !== $v ) {
					return self::lang( $v );
				}
			}
		}
		return self::lang();
	}
}
