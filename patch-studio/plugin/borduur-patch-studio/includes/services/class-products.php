<?php
/**
 * Pack and service products in WooCommerce: created and kept in sync automatically (SPEC-AI-DIENSTEN §11.1).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Services;

defined( 'ABSPATH' ) || exit;

/**
 * One virtual, non-downloadable simple product per pack and per service. Our settings are the source of the price
 * (excl. VAT, like the rest of the admin); Woo stays the till, tax, invoices and payment methods.
 */
final class Products {

	const OPTION      = 'bps_svc_products';
	const PACK_META   = '_bps_ai_pack';
	const SERVICE_META = '_bps_service';
	const NOTICE      = 'bps_svc_products_notice';

	/** @var array<int, array{type:string,id:string}>|null */
	private static $lookup = null;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'maybe_ensure' ), 25 );
		add_action( 'bps_ai_settings_saved', array( __CLASS__, 'ensure_quietly' ) );
		add_action( 'template_redirect', array( __CLASS__, 'hide_line_option_pages' ) );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'is_purchasable' ), 20, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/**
	 * Key in the option map.
	 *
	 * @param string $type pack|service.
	 * @param string $id   Pack/service id.
	 */
	private static function key( string $type, string $id ): string {
		return $type . ':' . $id;
	}

	/**
	 * Stored map {"pack:ai_pack_10": productId, ...}.
	 */
	public static function map(): array {
		$m = get_option( self::OPTION, array() );
		return is_array( $m ) ? $m : array();
	}

	/**
	 * Product id for a pack or service (0 = not created).
	 *
	 * @param string $type pack|service.
	 * @param string $id   Id.
	 */
	public static function product_id( string $type, string $id ): int {
		$m = self::map();
		return (int) ( $m[ self::key( $type, $id ) ] ?? 0 );
	}

	/**
	 * What does a product id stand for?
	 *
	 * @param int $product_id Product id.
	 * @return array{type:string,id:string}|null
	 */
	public static function lookup( int $product_id ): ?array {
		if ( null === self::$lookup ) {
			self::$lookup = array();
			foreach ( self::map() as $k => $pid ) {
				list( $type, $id )                = array_pad( explode( ':', (string) $k, 2 ), 2, '' );
				self::$lookup[ (int) $pid ] = array(
					'type' => $type,
					'id'   => $id,
				);
			}
		}
		return self::$lookup[ $product_id ] ?? null;
	}

	/**
	 * All our product ids (for coupon exclusions).
	 *
	 * @return int[]
	 */
	public static function all_ids(): array {
		return array_values( array_filter( array_map( 'intval', self::map() ) ) );
	}

	/**
	 * Packs can be bought only in live mode.
	 */
	public static function packs_buyable(): bool {
		return 'live' === Config::mode() && (bool) Config::packs( true );
	}

	/**
	 * Price to store on the Woo product, in the shop's price entry mode.
	 *
	 * @param float $excl Excl. price (4 decimals).
	 * @param bool  $incl Prices entered incl. tax.
	 */
	public static function woo_price( float $excl, bool $incl ): string {
		return $incl ? number_format( Config::incl( $excl ), 2, '.', '' ) : rtrim( rtrim( number_format( $excl, 4, '.', '' ), '0' ), '.' );
	}

	/**
	 * Everything we sell: [{type, id, name, priceExcl, enabled, visible, soldIndividually}].
	 */
	public static function wanted(): array {
		$out = array();
		foreach ( Config::packs( false ) as $p ) {
			$out[] = array(
				'type'       => 'pack',
				'id'         => $p['id'],
				'name'       => Text::label( $p['label'] ?: array( 'nl' => 'Ontwerppakket · ' . $p['rounds'] . ' rondes' ), 'nl' ),
				'priceExcl'  => $p['priceExcl'],
				'enabled'    => $p['enabled'],
				'visible'    => false,
				'individual' => false,
			);
		}
		foreach ( Config::services( false ) as $id => $s ) {
			$out[] = array(
				'type'       => 'service',
				'id'         => $id,
				'name'       => Text::label( $s['label'] ?? $id, 'nl' ),
				'priceExcl'  => (float) ( $s['priceExcl'] ?? 0 ),
				'enabled'    => $s['enabled'],
				'visible'    => $s['enabled'] && ! empty( $s['showInShop'] ) && 'product' === $s['kind'],
				'individual' => 'line_option' === $s['kind'],
			);
		}
		return $out;
	}

	/**
	 * Cheap check on init: create products once the module is switched on.
	 */
	public static function maybe_ensure(): void {
		if ( ! class_exists( 'WC_Product_Simple' ) || 'off' === Config::mode() ) {
			return;
		}
		$map = self::map();
		if ( count( $map ) >= count( self::wanted() ) ) {
			return;
		}
		if ( ! add_option( 'bps_svc_products_lock', time(), '', false ) ) {
			if ( time() - (int) get_option( 'bps_svc_products_lock' ) < 60 ) {
				return;
			}
			update_option( 'bps_svc_products_lock', time(), false );
		}
		self::ensure();
		delete_option( 'bps_svc_products_lock' );
	}

	/**
	 * Settings saved: sync without output.
	 */
	public static function ensure_quietly(): void {
		if ( class_exists( 'WC_Product_Simple' ) ) {
			self::ensure();
		}
	}

	/**
	 * Create missing products and repair changed ones. Returns a report for the admin button "Producten nakijken".
	 *
	 * @return array{created:string[], repaired:string[], ok:string[]}
	 */
	public static function ensure(): array {
		$report = array(
			'created'  => array(),
			'repaired' => array(),
			'ok'       => array(),
		);
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return $report;
		}
		$map  = self::map();
		$incl = wc_prices_include_tax();
		foreach ( self::wanted() as $w ) {
			$k       = self::key( $w['type'], $w['id'] );
			$meta    = 'pack' === $w['type'] ? self::PACK_META : self::SERVICE_META;
			$product = null;
			if ( ! empty( $map[ $k ] ) ) {
				$product = wc_get_product( (int) $map[ $k ] );
				if ( $product && 'trash' === $product->get_status() ) {
					$product = null;
				}
			}
			if ( ! $product ) {
				$found = get_posts(
					array(
						'post_type'      => 'product',
						'post_status'    => array( 'publish', 'private', 'draft' ),
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_key'       => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_value'     => $w['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				);
				$product = $found ? wc_get_product( (int) $found[0] ) : null;
			}
			$created = false;
			if ( ! $product ) {
				if ( ! $w['enabled'] ) {
					continue; // Never create products for switched-off packs/services.
				}
				$product = new \WC_Product_Simple();
				$product->set_name( $w['name'] );
				$product->set_slug( 'bps-' . str_replace( '_', '-', $w['id'] ) );
				$product->set_virtual( true );
				$product->set_downloadable( false );
				$product->set_tax_status( 'taxable' );
				$product->set_tax_class( '' );
				$product->set_manage_stock( false );
				$product->set_stock_status( 'instock' );
				$product->set_reviews_allowed( false );
				$product->update_meta_data( $meta, $w['id'] );
				$created = true;
			}
			$changed = self::apply( $product, $w, $incl );
			$id      = (int) $product->save();
			if ( $id <= 0 ) {
				continue;
			}
			$map[ $k ] = $id;
			if ( $created ) {
				$report['created'][] = $w['name'];
			} elseif ( $changed ) {
				$report['repaired'][] = $w['name'];
			} else {
				$report['ok'][] = $w['name'];
			}
		}
		update_option( self::OPTION, $map, false );
		self::$lookup = null;
		if ( $report['repaired'] ) {
			set_transient( self::NOTICE, $report['repaired'], HOUR_IN_SECONDS );
		}
		return $report;
	}

	/**
	 * Apply the essentials (price from our settings wins). Returns true when something was corrected.
	 *
	 * @param \WC_Product $p    Product.
	 * @param array       $w    Wanted.
	 * @param bool        $incl Prices incl. tax.
	 */
	private static function apply( \WC_Product $p, array $w, bool $incl ): bool {
		$dirty  = false;
		$price  = self::woo_price( (float) $w['priceExcl'], $incl );
		$status = $w['enabled'] ? 'publish' : 'private';
		$vis    = $w['visible'] ? 'visible' : 'hidden';
		if ( (string) $p->get_regular_price() !== $price ) {
			$p->set_regular_price( $price );
			$p->set_price( $price );
			$p->set_sale_price( '' );
			$dirty = true;
		}
		if ( $p->get_status() !== $status ) {
			$p->set_status( $status );
			$dirty = true;
		}
		if ( $p->get_catalog_visibility() !== $vis ) {
			$p->set_catalog_visibility( $vis );
			$dirty = true;
		}
		if ( ! $p->get_virtual() ) {
			$p->set_virtual( true );
			$dirty = true;
		}
		if ( (bool) $p->get_sold_individually() !== (bool) $w['individual'] ) {
			$p->set_sold_individually( (bool) $w['individual'] );
			$dirty = true;
		}
		return $dirty;
	}

	/**
	 * Switched-off packs/services cannot be bought, even with an old link.
	 *
	 * @param bool        $purchasable Purchasable.
	 * @param \WC_Product $product     Product.
	 */
	public static function is_purchasable( $purchasable, $product ) {
		if ( ! is_object( $product ) ) {
			return $purchasable;
		}
		$what = self::lookup( (int) $product->get_id() );
		if ( ! $what ) {
			return $purchasable;
		}
		if ( 'pack' === $what['type'] ) {
			$pack = Config::pack( $what['id'] );
			return $purchasable && $pack && $pack['enabled'] && self::packs_buyable();
		}
		$svc = Config::service( $what['id'] );
		return $purchasable && $svc && $svc['enabled'];
	}

	/**
	 * Line option product pages make no sense on their own: redirect to the shop.
	 */
	public static function hide_line_option_pages(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$what = self::lookup( (int) get_queried_object_id() );
		if ( ! $what ) {
			return;
		}
		$svc = 'service' === $what['type'] ? Config::service( $what['id'] ) : null;
		if ( 'pack' === $what['type'] || ( $svc && ( 'line_option' === $svc['kind'] || empty( $svc['showInShop'] ) ) ) ) {
			wp_safe_redirect( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) );
			exit;
		}
	}

	/**
	 * Tell the owner when we corrected a product he edited.
	 */
	public static function admin_notice(): void {
		$names = get_transient( self::NOTICE );
		if ( ! $names || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		delete_transient( self::NOTICE );
		echo '<div class="notice notice-info is-dismissible"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: product names */
				__( 'Patch Studio zette deze producten terug volgens de instellingen (prijs, zichtbaarheid): %s. Prijzen wijzig je bij WooCommerce → Patch Studio AI.', 'borduur-patch-studio' ),
				implode( ', ', array_map( 'strval', (array) $names ) )
			)
		);
		echo '</p></div>';
	}
}
