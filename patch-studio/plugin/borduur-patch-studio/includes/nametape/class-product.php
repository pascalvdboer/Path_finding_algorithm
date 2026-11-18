<?php
/**
 * Name tapes: the hidden WooCommerce product "Naamstroken op maat" (SPEC-EXTRA §1.8).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Nametape;

defined( 'ABSPATH' ) || exit;

/**
 * Hidden simple product, price 0 (the cart sets the line price), own category, never added without a list.
 */
final class Product {

	const META_FLAG = '_bps_nametape_product';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'maybe_ensure' ), 25 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'block_direct_add' ), 5, 2 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( __CLASS__, 'block_store_api_add' ), 5, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_product_page' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	/**
	 * Current product id (0 when missing).
	 */
	public static function id(): int {
		return (int) get_option( Config::PRODUCT_OPTION, 0 );
	}

	/**
	 * Is this our product?
	 *
	 * @param int $product_id Product id.
	 */
	public static function is_ours( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}
		if ( $product_id === self::id() ) {
			return true;
		}
		return '1' === (string) get_post_meta( $product_id, self::META_FLAG, true );
	}

	/**
	 * Cheap check on init; repairs a missing product once per minute at most.
	 */
	public static function maybe_ensure(): void {
		if ( ! function_exists( 'wc_get_product' ) || ! Config::enabled() ) {
			return;
		}
		$id = self::id();
		if ( $id > 0 && 'product' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
			return;
		}
		if ( get_transient( 'bps_nt_product_lock' ) ) {
			return;
		}
		set_transient( 'bps_nt_product_lock', 1, MINUTE_IN_SECONDS );
		self::ensure();
	}

	/**
	 * Category term id (created when missing).
	 */
	private static function category_id(): int {
		$slug = sanitize_title( (string) Config::path( 'woo.categorySlug', 'naamstroken' ) );
		if ( '' === $slug || ! taxonomy_exists( 'product_cat' ) ) {
			return 0;
		}
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		$name = Config::label( Config::path( 'woo.productName', array( 'nl' => 'Naamstroken op maat' ) ), 'nl_BE' );
		$res  = wp_insert_term( '' !== $name ? $name : 'Naamstroken', 'product_cat', array( 'slug' => $slug ) );
		return is_wp_error( $res ) ? 0 : (int) $res['term_id'];
	}

	/**
	 * Shipping class id from woo.shippingClassSlug (0 = none).
	 */
	private static function shipping_class_id(): int {
		$slug = sanitize_title( (string) Config::path( 'woo.shippingClassSlug', '' ) );
		if ( '' === $slug || ! taxonomy_exists( 'product_shipping_class' ) ) {
			return 0;
		}
		$term = get_term_by( 'slug', $slug, 'product_shipping_class' );
		return $term && ! is_wp_error( $term ) ? (int) $term->term_id : 0;
	}

	/**
	 * Return a valid product id, creating or repairing the product.
	 */
	public static function ensure(): int {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return 0;
		}
		$id = self::id();
		$p  = $id > 0 ? wc_get_product( $id ) : null;
		if ( ! $p || 'trash' === $p->get_status() ) {
			$found = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'private', 'draft' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => self::META_FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			$p = $found ? wc_get_product( (int) $found[0] ) : null;
		}
		if ( ! $p ) {
			$p = new \WC_Product_Simple();
			$p->set_name( Config::label( Config::path( 'woo.productName', array( 'nl' => 'Naamstroken op maat' ) ), 'nl_BE' ) );
			$p->set_slug( 'naamstroken-op-maat' );
			$p->set_status( 'publish' );
			$p->set_regular_price( '0' );
			$p->set_price( '0' );
			$p->set_tax_status( 'taxable' );
			$p->set_tax_class( '' );
			$p->set_virtual( false );
			$p->set_downloadable( false );
			$p->set_sold_individually( false );
			$p->set_manage_stock( false );
			$p->set_stock_status( 'instock' );
			$p->set_reviews_allowed( false );
		}
		self::apply_essentials( $p );
		$id = (int) $p->save();
		if ( $id > 0 ) {
			update_option( Config::PRODUCT_OPTION, $id, true );
		}
		return $id;
	}

	/**
	 * Hidden, flagged, own category (never the patch category: no embroidery-card fee), optional shipping class.
	 *
	 * @param \WC_Product $p Product.
	 */
	private static function apply_essentials( \WC_Product $p ): void {
		$p->set_catalog_visibility( 'hidden' );
		$p->update_meta_data( self::META_FLAG, '1' );
		$cat = self::category_id();
		if ( $cat > 0 ) {
			$p->set_category_ids( array( $cat ) );
		}
		$ship = self::shipping_class_id();
		$p->set_shipping_class_id( $ship );
	}

	/**
	 * Settings changed: keep name/category/shipping class in sync.
	 */
	public static function sync(): void {
		$id = self::id();
		$p  = $id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		if ( ! $p ) {
			return;
		}
		self::apply_essentials( $p );
		$p->save();
	}

	/**
	 * Link to the name-tape page.
	 */
	public static function direct_add_message(): string {
		$url = Config::page_url();
		$msg = __( 'Naamstroken stel je samen op onze naamstrookpagina: namen typen of je lijst plakken.', 'borduur-patch-studio' );
		if ( '' === $url ) {
			return $msg;
		}
		return $msg . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Naar de naamstroken', 'borduur-patch-studio' ) . '</a>';
	}

	/**
	 * Classic add-to-cart: never without a list.
	 *
	 * @param bool $passed     Passed.
	 * @param int  $product_id Product id.
	 */
	public static function block_direct_add( $passed, $product_id ) {
		if ( ! self::is_ours( (int) $product_id ) || Cart::$internal_add ) {
			return $passed;
		}
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::direct_add_message(), 'error' );
		}
		return false;
	}

	/**
	 * Store API add-item: same rule.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $request Request.
	 * @throws \Exception When it is our product.
	 */
	public static function block_store_api_add( $product, $request ): void {
		if ( ! $product instanceof \WC_Product || ! self::is_ours( (int) $product->get_id() ) || Cart::$internal_add ) {
			return;
		}
		$msg = wp_strip_all_tags( self::direct_add_message() );
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'bps_nt_list_required', $msg, 400 ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		throw new \Exception( $msg ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * The product page makes no sense: send visitors to the name-tape page.
	 */
	public static function redirect_product_page(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() || is_admin() ) {
			return;
		}
		if ( ! self::is_ours( (int) get_queried_object_id() ) ) {
			return;
		}
		$url = Config::page_url();
		if ( '' !== $url ) {
			wp_safe_redirect( $url, 302 );
			exit;
		}
	}

	/**
	 * noindex for the product page.
	 *
	 * @param array $robots Robots.
	 */
	public static function robots( $robots ) {
		if ( function_exists( 'is_product' ) && is_product() && self::is_ours( (int) get_queried_object_id() ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}
}
