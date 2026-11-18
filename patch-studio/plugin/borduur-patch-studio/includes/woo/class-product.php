<?php
/**
 * The single "Patch op maat" product (cart model own_product, SPEC D1 / §6.4).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

use BorduurPatchStudio\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Creates/repairs the product, keeps its visibility in sync with the settings and refuses
 * any add-to-cart that does not come from the Patch Studio.
 */
final class Product {

	const OPTION        = 'bps_product_id';
	const STUDIO_OPTION = 'bps_studio_page_id';
	const META_FLAG     = '_bps_patch_product';

	/** WooCommerce Product Add-Ons: never apply global add-on groups to this product (woo-compat-9). */
	const ADDONS_EXCLUDE = '_product_addons_exclude_global';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'maybe_ensure' ), 20 );
		add_action( 'bps_settings_updated', array( __CLASS__, 'sync_settings' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'block_direct_add' ), 5, 6 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( __CLASS__, 'block_store_api_add' ), 5, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_product_page' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_delete' ) );
	}

	/**
	 * Activation: create the product.
	 */
	public static function activate(): void {
		self::ensure();
	}

	/**
	 * Make sure the product exists (cheap check on every init, repaired when missing).
	 */
	public static function maybe_ensure(): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$id = Config::product_id();
		if ( $id > 0 && 'product' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
			return;
		}
		// The owner pinned a product id in the settings: never auto-create next to it.
		if ( (int) Config::path( 'woo.productId', 0 ) > 0 ) {
			return;
		}
		// Simple lock so two simultaneous first requests do not both create a product.
		if ( ! add_option( 'bps_product_lock', time(), '', false ) ) {
			if ( time() - (int) get_option( 'bps_product_lock' ) < 60 ) {
				return;
			}
			update_option( 'bps_product_lock', time(), false );
		}
		self::ensure();
		delete_option( 'bps_product_lock' );
	}

	/**
	 * Return a valid product id, creating the product if needed.
	 */
	public static function ensure(): int {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return 0;
		}
		$id = Config::product_id();
		if ( $id > 0 ) {
			$p = wc_get_product( $id );
			if ( $p && 'trash' !== $p->get_status() ) {
				self::repair( $p );
				return (int) $p->get_id();
			}
		}
		// Recover a product created earlier (option lost) before creating a new one.
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
		if ( $found ) {
			$p = wc_get_product( (int) $found[0] );
			if ( $p ) {
				self::repair( $p );
				update_option( self::OPTION, (int) $p->get_id(), true );
				return (int) $p->get_id();
			}
		}

		$p = new \WC_Product_Simple();
		$p->set_name( Config::label( Config::path( 'woo.productName', array( 'nl' => 'Patch op maat' ) ), 'nl' ) );
		$p->set_slug( 'patch-op-maat' );
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
		$p->set_catalog_visibility( self::visibility() );
		// No short description: block cart/checkout would print it under every patch line.
		$p->update_meta_data( self::META_FLAG, '1' );
		$p->update_meta_data( self::ADDONS_EXCLUDE, '1' );
		$id = (int) $p->save();
		if ( $id > 0 ) {
			update_option( self::OPTION, $id, true );
			Logger::info( 'patch_product_created', array( 'product_id' => $id ) );
		} else {
			Logger::error( 'patch_product_create_failed', array() );
		}
		return $id;
	}

	/**
	 * Keep the essentials right if the owner edited the product.
	 *
	 * @param \WC_Product $p Product.
	 */
	private static function repair( \WC_Product $p ): void {
		$dirty = false;
		if ( '1' !== (string) $p->get_meta( self::META_FLAG ) ) {
			$p->update_meta_data( self::META_FLAG, '1' );
			$dirty = true;
		}
		if ( '1' !== (string) $p->get_meta( self::ADDONS_EXCLUDE ) ) {
			$p->update_meta_data( self::ADDONS_EXCLUDE, '1' );
			$dirty = true;
		}
		if ( '' === (string) $p->get_regular_price() ) {
			$p->set_regular_price( '0' );
			$dirty = true;
		}
		if ( $p->get_sold_individually() ) {
			$p->set_sold_individually( false );
			$dirty = true;
		}
		if ( 'publish' !== $p->get_status() && 'private' !== $p->get_status() ) {
			$p->set_status( 'publish' );
			$dirty = true;
		}
		if ( $dirty ) {
			$p->save();
			Logger::info( 'patch_product_repaired', array( 'product_id' => (int) $p->get_id() ) );
		}
		if ( (int) get_option( self::OPTION ) !== (int) $p->get_id() ) {
			update_option( self::OPTION, (int) $p->get_id(), true );
		}
	}

	/**
	 * Catalog visibility from the settings (hidden | catalog | search | visible).
	 */
	public static function visibility(): string {
		$v = (string) Config::path( 'woo.productVisibility', 'hidden' );
		return in_array( $v, array( 'hidden', 'catalog', 'search', 'visible' ), true ) ? $v : 'hidden';
	}

	/**
	 * Apply settings changes (visibility, name) to the product.
	 */
	public static function sync_settings(): void {
		$id = Config::product_id();
		$p  = $id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		if ( ! $p ) {
			return;
		}
		$changed = false;
		if ( $p->get_catalog_visibility() !== self::visibility() ) {
			$p->set_catalog_visibility( self::visibility() );
			$changed = true;
		}
		$name = Config::label( Config::path( 'woo.productName', array() ), 'nl' );
		if ( '' !== $name && $p->get_name() !== $name ) {
			$p->set_name( $name );
			$changed = true;
		}
		if ( $changed ) {
			$p->save();
		}
	}

	/**
	 * Is this our product?
	 *
	 * @param int $product_id Product id.
	 */
	public static function is_ours( int $product_id ): bool {
		$ours = Config::product_id();
		return $ours > 0 && $product_id === $ours;
	}

	/**
	 * Classic add-to-cart (product page, ?add-to-cart=): never without a Patch Studio design. The one exception
	 * besides our own add_design(): WooCommerce "Order again", where Order_Again rebuilt the line server-side
	 * (the cart item data arrives as 6th argument only from WC_Cart_Session::populate_cart_from_order()).
	 *
	 * @param bool  $passed         Passed.
	 * @param int   $product_id     Product id.
	 * @param int   $quantity       Quantity.
	 * @param int   $variation_id   Variation id.
	 * @param array $variations     Variations.
	 * @param array $cart_item_data Cart item data (order again).
	 */
	public static function block_direct_add( $passed, $product_id, $quantity = 0, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		if ( ! self::is_ours( (int) $product_id ) || Cart::$internal_add ) {
			return $passed;
		}
		if ( is_array( $cart_item_data ) && Order_Again::is_rebuilt( $cart_item_data ) ) {
			return $passed;
		}
		wc_add_notice( self::direct_add_message(), 'error' );
		return false;
	}

	/**
	 * Store API add-item: same rule.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $request Request.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When it is our product.
	 */
	public static function block_store_api_add( $product, $request ): void {
		if ( ! $product instanceof \WC_Product || ! self::is_ours( (int) $product->get_id() ) || Cart::$internal_add || Order_Again::$adding ) {
			return;
		}
		$msg = wp_strip_all_tags( self::direct_add_message() );
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'bps_design_required', $msg, 400 ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		throw new \Exception( $msg ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Notice with a link to the studio.
	 */
	public static function direct_add_message(): string {
		return sprintf(
			/* translators: %s: link to the Patch Studio page */
			__( 'Een patch op maat stel je samen in onze ontwerptool. %s', 'borduur-patch-studio' ),
			'<a href="' . esc_url( self::studio_url() ) . '">' . esc_html__( 'Ontwerp je patch', 'borduur-patch-studio' ) . '</a>'
		);
	}

	/**
	 * URL of the page that contains the studio: Frontend\Studio_Pages::url() (contract C8, P09) when available,
	 * else the page tracked by the shortcode, else home.
	 */
	public static function studio_url(): string {
		// Studio_Pages is delivered by another package (P09); until then the tracked page is used.
		if ( method_exists( '\BorduurPatchStudio\Frontend\Studio_Pages', 'url' ) ) {
			$url = (string) \BorduurPatchStudio\Frontend\Studio_Pages::url();
			if ( '' !== $url ) {
				return $url;
			}
		}
		$page = (int) get_option( self::STUDIO_OPTION, 0 );
		if ( $page > 0 && 'publish' === get_post_status( $page ) ) {
			$url = get_permalink( $page );
			if ( $url ) {
				return (string) $url;
			}
		}
		return home_url( '/' );
	}

	/**
	 * The product page itself makes no sense without a design: send visitors to the studio.
	 */
	public static function redirect_product_page(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() || is_admin() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( ! self::is_ours( $id ) || current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$page = (int) get_option( self::STUDIO_OPTION, 0 );
		if ( $page > 0 && 'publish' === get_post_status( $page ) ) {
			wp_safe_redirect( self::studio_url(), 302 );
			exit;
		}
	}

	/**
	 * noindex for the product page.
	 *
	 * @param array $robots Robots directives.
	 */
	public static function robots( $robots ) {
		if ( function_exists( 'is_product' ) && is_product() && self::is_ours( (int) get_queried_object_id() ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/**
	 * Forget the id when the owner deletes/trashes the product (it is recreated on next init).
	 *
	 * @param int $post_id Post id.
	 */
	public static function on_delete( $post_id ): void {
		if ( (int) get_option( self::OPTION ) === (int) $post_id ) {
			delete_option( self::OPTION );
		}
	}
}
