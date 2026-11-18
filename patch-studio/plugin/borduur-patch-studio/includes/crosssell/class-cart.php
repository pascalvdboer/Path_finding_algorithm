<?php
/**
 * Cross-sell in de WooCommerce-mand (SPEC-EXTRA §2.6–2.7): gekoppelde regels op het blanco/laserproduct met
 * cart item data `bps_cs`, absolute prijs per stuk op de server, eigen eenmalige kosten, validatie, weergave,
 * ordermeta en een sectie op de productiefiche.
 *
 * Koppeling via design_id/ref, niet via de mandsleutel: de klant mag de losse patchregel verwijderen en enkel
 * de petten houden (D6). Ordermeta gebruikt bewust `_bps_cs_design_id` (niet `_bps_design_id`): die laatste
 * maakt van een regel een Patch Studio-patchregel voor productie en ontwerpstatus.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Crosssell;

use BorduurPatchStudio\Logger;
use BorduurPatchStudio\Woo\Cart as WooCart;
use BorduurPatchStudio\Woo\Config as WooConfig;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Mandregels.
 */
final class Cart {

	const KEY = 'bps_cs';

	/** Klantteksten die enkel de server gebruikt (meldingen in de mand). */
	const TEXT = array(
		'nl' => array(
			'gone'      => 'We haalden "%s" uit je winkelmand: het ontwerp bestaat niet meer.',
			'unfit'     => 'We haalden "%s" uit je winkelmand: het ontwerp past niet (meer) op dit product. Neem gerust contact op.',
			'price'     => 'We haalden "%1$s" (ontwerp %2$s) uit je winkelmand: we konden de prijs niet berekenen. Probeer opnieuw of neem contact op.',
			'custom'    => 'Maatwerk',
			'custom_v'  => 'Speciaal voor jou gemaakt, geen herroepingsrecht.',
			'direct'    => 'Dit product maken we enkel met je eigen ontwerp. Start in de ontwerptool en kies het daarna als extra.',
			'qty_min'   => '"%1$s": bestel minstens %2$d stuks (vaste mal).',
			'qty_step'  => '"%1$s": bestel per %2$d stuks.',
			'names_qty' => '"%s": het aantal hangt af van je namenlijst. Pas de namen aan via "Ongedaan maken" en voeg opnieuw toe.',
			'design'    => 'Ontwerp',
			'patch'     => 'Patch',
			'plate'     => 'Plaatje',
			'names'     => 'Namen achterop',
			'options'   => 'Extra',
			'price_bd'  => 'Prijs per stuk',
			'section'   => 'Hetzelfde ontwerp ook op',
			'more'      => '+ %d meer',
			'alt'       => 'Voorbeeld van jouw patch %s',
		),
		'fr' => array(
			'gone'      => 'Nous avons retiré « %s » de votre panier : le design n\'existe plus.',
			'unfit'     => 'Nous avons retiré « %s » de votre panier : le design ne convient (plus) pour ce produit. N\'hésitez pas à nous contacter.',
			'price'     => 'Nous avons retiré « %1$s » (design %2$s) de votre panier : nous n\'avons pas pu calculer le prix. Réessayez ou contactez-nous.',
			'custom'    => 'Sur mesure',
			'custom_v'  => 'Fabriqué spécialement pour vous, pas de droit de rétractation.',
			'direct'    => 'Nous réalisons ce produit uniquement avec votre propre design. Commencez dans l\'outil de création et choisissez-le ensuite en extra.',
			'qty_min'   => '« %1$s » : commandez au moins %2$d pièces (moule fixe).',
			'qty_step'  => '« %1$s » : commandez par %2$d pièces.',
			'names_qty' => '« %s » : la quantité dépend de votre liste de noms. Modifiez les noms via « Annuler » et ajoutez à nouveau.',
			'design'    => 'Design',
			'patch'     => 'Écusson',
			'plate'     => 'Plaquette',
			'names'     => 'Noms au dos',
			'options'   => 'Extra',
			'price_bd'  => 'Prix par pièce',
			'section'   => 'Le même design aussi sur',
			'more'      => '+ %d de plus',
			'alt'       => 'Aperçu de votre écusson %s',
		),
	);

	/** @var bool Toevoegen door deze module (directe toevoegingen van eigen producten worden geweigerd). */
	private static $adding = false;

	/** @var array<string,array> Prijs per mandsleutel in dit verzoek: ok, unitExcl, parts. */
	private static $priced = array();

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_prices' ), 30 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_fees' ), 30 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'block_direct' ), 10, 2 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'drop_orphans' ), 25 );
		// Mini-cart / Store API requests only load the cart: price the lines right away (woo-compat-2).
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'set_prices' ), 30 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart_items' ), 25 );

		add_filter( 'advanced_woo_discount_rules_calculate_discount_for_cart_item', array( __CLASS__, 'wdr_exclude' ), 10, 2 );
		add_filter( 'advanced_woo_discount_rules_include_cart_item_to_count_quantity', array( __CLASS__, 'wdr_exclude' ), 10, 2 );

		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( __CLASS__, 'thumbnail' ), 10, 2 );
		add_filter( 'woocommerce_store_api_cart_item_images', array( __CLASS__, 'store_api_images' ), 10, 2 );

		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'quantity_html' ), 20, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( __CLASS__, 'update_cart_validation' ), 10, 4 );
		add_filter( 'woocommerce_store_api_product_quantity_minimum', array( __CLASS__, 'store_api_qty_min' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_multiple_of', array( __CLASS__, 'store_api_qty_step' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_editable', array( __CLASS__, 'store_api_qty_editable' ), 10, 3 );

		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_meta' ), 10, 4 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( __CLASS__, 'meta_label' ), 10, 3 );
		add_filter( 'bps_fiche_sections', array( __CLASS__, 'fiche_section' ), 10, 3 );
	}

	/**
	 * Servertekst.
	 *
	 * @param string $key  Sleutel.
	 * @param string $lang nl|fr.
	 */
	public static function text( string $key, ?string $lang = null ): string {
		$lang = $lang ?? Messages::lang( Config::locale() );
		return self::TEXT[ 'fr' === $lang ? 'fr' : 'nl' ][ $key ] ?? $key;
	}

	/* ---------------------------------------------------------------------
	 * Toevoegen / ongedaan maken (REST)
	 * ------------------------------------------------------------------- */

	/**
	 * Gekoppelde regels toevoegen voor één kaart.
	 *
	 * @param array  $rec    DesignRecord.
	 * @param string $item   Item-id.
	 * @param array  $body   Verzoek (lines, options, plate, names, placement).
	 * @param string $locale Locale.
	 * @return array|WP_Error {keys: string[]}
	 */
	public static function add( array $rec, string $item, array $body, string $locale ) {
		$cfg_item = Config::item( $item );
		if ( ! $cfg_item || ( array_key_exists( 'enabled', $cfg_item ) && false === $cfg_item['enabled'] ) ) {
			return new WP_Error( 'bps_cs_invalid', Messages::t( 'cs.err.generic', $locale ), array( 'status' => 400 ) );
		}
		$card = Service::card( $rec, $item, $locale );
		if ( ! $card ) {
			return new WP_Error( 'bps_cs_unfit', Messages::t( 'cs.err.generic', $locale ), array( 'status' => 409 ) );
		}
		$n = Lines::normalize( $body, $card );
		if ( ! $n['ok'] ) {
			return new WP_Error( 'bps_cs_invalid', Messages::t( 'cs.err.generic', $locale ), array( 'status' => 400, 'errors' => $n['errors'] ) );
		}
		$pricing = Service::offers( $rec, $locale, true )['pricing'];
		$total   = (int) array_sum( array_column( $n['lines'], 'qty' ) );
		foreach ( $n['lines'] as $l ) {
			$q = Offers::card_price(
				$card,
				array(
					'variationId' => $l['variationId'] ?: null,
					'productId'   => $l['productId'],
					'qty'         => $total,
					'options'     => $n['options'],
					'placement'   => $n['placement'],
				),
				$pricing,
				$locale
			);
			if ( empty( $q['ok'] ) ) {
				return new WP_Error( 'bps_cs_invalid', Messages::t( 'cs.err.generic', $locale ), array( 'status' => 400, 'errors' => $q['errors'] ?? array() ) );
			}
		}
		$use_alt = ! empty( $card['alt'] ) && $n['placement'] === ( $card['alt']['placement'] ?? null );
		$payload = (array) $rec['payload'];
		$data    = array(
			'v'              => 1,
			'item'           => $item,
			'type'           => (string) ( $cfg_item['type'] ?? '' ),
			'design_id'      => (int) $rec['id'],
			'ref'            => (string) $rec['ref'],
			'technique'      => (string) ( $payload['technique'] ?? '' ),
			'patch_size_cm'  => $use_alt ? (float) $card['alt']['patchSizeCm'] : ( null !== $card['patchSizeCm'] ? (float) $card['patchSizeCm'] : null ),
			'placement'      => $n['placement'],
			'frame_size'     => $card['frameSize'] ?? null,
			'options'        => $n['options'],
			'design_options' => array_values( array_map( 'strval', (array) ( $payload['options'] ?? array() ) ) ),
			'plate'          => $n['plate'],
			'names'          => $n['names'],
			'preview_url'    => esc_url_raw( (string) ( $rec['preview_url'] ?? '' ) ),
			'batch'          => substr( md5( wp_generate_uuid4() ), 0, 12 ),
			'locale'         => $locale,
		);

		$keys         = array();
		self::$adding = true;
		try {
			foreach ( $n['lines'] as $l ) {
				$attrs = array();
				if ( $l['variationId'] > 0 ) {
					$v     = wc_get_product( $l['variationId'] );
					$attrs = $v && is_callable( array( $v, 'get_variation_attributes' ) ) ? (array) $v->get_variation_attributes() : array();
				}
				$key = WC()->cart->add_to_cart( $l['productId'], $l['qty'], $l['variationId'], $attrs, array( self::KEY => $data ) );
				if ( ! $key ) {
					foreach ( $keys as $k ) {
						WC()->cart->remove_cart_item( $k );
					}
					return new WP_Error( 'bps_cs_invalid', Messages::t( 'cs.err.generic', $locale ), array( 'status' => 400 ) );
				}
				$keys[] = (string) $key;
			}
		} finally {
			self::$adding = false;
		}
		Service::flush();
		Stats::bump( $item, 'added' );
		/**
		 * Na het toevoegen van gekoppelde regels.
		 *
		 * @param string[] $keys      Mandsleutels.
		 * @param string   $item      Item-id.
		 * @param int      $design_id Ontwerp.
		 */
		do_action( 'bps_crosssell_added', $keys, $item, (int) $rec['id'] );
		return array( 'keys' => $keys );
	}

	/**
	 * Eén eigen regel verwijderen (enkel bps_cs-regels van de eigen sessie).
	 *
	 * @param string $key Mandsleutel.
	 */
	public static function undo( string $key ): bool {
		$cart = WC()->cart;
		$item = $cart ? $cart->get_cart_item( $key ) : array();
		if ( empty( $item[ self::KEY ] ) ) {
			return false;
		}
		$ok = (bool) $cart->remove_cart_item( $key );
		Service::flush();
		return $ok;
	}

	/* ---------------------------------------------------------------------
	 * Prijs en kosten
	 * ------------------------------------------------------------------- */

	/**
	 * Prijs per stuk van één regel (pure berekening op mandgegevens; ook voor de weergave).
	 *
	 * @param string $key   Mandsleutel.
	 * @param array  $line  Mandregel.
	 * @param array  $items Hele mand.
	 * @param array  $main  Hoofdconfig.
	 */
	public static function price_line( string $key, array $line, array $items, array $main ): array {
		$cs       = $line[ self::KEY ];
		$cfg      = Config::get();
		$cfg_item = Config::item( (string) ( $cs['item'] ?? '' ) );
		if ( ! $cfg_item ) {
			return array( 'ok' => false, 'errors' => array( 'item' ) );
		}
		$qty = 0;
		foreach ( $items as $it ) {
			if ( ! empty( $it[ self::KEY ] ) && ( $it[ self::KEY ]['batch'] ?? '' ) === ( $cs['batch'] ?? '-' ) ) {
				$qty += (int) $it['quantity'];
			}
		}
		$qty  = max( $qty, (int) $line['quantity'] );
		$own  = Lines::carries_patch( $cs ) && Lines::in_pool( (string) ( $cs['technique'] ?? '' ), $main ) ? $qty : 0;
		$pool = max( 0, Lines::pool_qty( $items, $main ) - $own );
		$vid  = (int) ( $line['variation_id'] ?? 0 );
		$pid  = (int) ( $line['product_id'] ?? 0 );
		$excl = Products::fresh_price_excl( $vid > 0 ? $vid : $pid );
		return Pricing::item(
			$cfg_item,
			array(
				'qty'           => $qty,
				'poolQty'       => $pool,
				'blankExcl'     => $excl,
				'productExcl'   => $excl,
				'options'       => (array) ( $cs['options'] ?? array() ),
				'placement'     => (string) ( $cs['placement'] ?? '' ),
				'technique'     => (string) ( $cs['technique'] ?? '' ),
				'patchSizeCm'   => (float) ( $cs['patch_size_cm'] ?? 0 ),
				'designOptions' => (array) ( $cs['design_options'] ?? array() ),
				'moldCovered'   => true,
				'moldCharged'   => true,
			),
			array(
				'cfg'        => $cfg,
				'main'       => $main,
				'patch_unit' => Service::patch_unit(),
				'locale'     => (string) ( $cs['locale'] ?? Config::locale() ),
			)
		);
	}

	/**
	 * Absolute prijs per stuk (excl. btw) op elke cross-sell-regel. Prio 30: na woo (20), dus idempotent.
	 *
	 * @param \WC_Cart $cart Mand.
	 */
	public static function set_prices( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		$items = $cart->get_cart();
		$main  = Config::main();
		foreach ( $items as $key => $line ) {
			if ( empty( $line[ self::KEY ] ) || ! isset( $line['data'] ) || ! $line['data'] instanceof \WC_Product ) {
				continue;
			}
			try {
				$q = self::price_line( (string) $key, $line, $items, $main );
			} catch ( \Throwable $e ) {
				$q = array(
					'ok'     => false,
					'errors' => array( 'exception' ),
				);
				Logger::error(
					'crosssell_price_failed',
					array(
						'design_id' => (int) ( $line[ self::KEY ]['design_id'] ?? 0 ),
						'ref'       => (string) ( $line[ self::KEY ]['ref'] ?? '' ),
						'item'      => (string) ( $line[ self::KEY ]['item'] ?? '' ),
						'qty'       => (int) $line['quantity'],
						'error'     => $e->getMessage(),
					)
				);
			}
			self::$priced[ $key ] = $q;
			// Geen geldige prijs → prijs niet aanraken; check_cart_items haalt de regel weg (nooit gratis).
			if ( ! empty( $q['ok'] ) && is_finite( (float) $q['unitExcl'] ) && $q['unitExcl'] > 0 ) {
				// Excl. btw uit de engine → wat WooCommerce verwacht (incl. btw als de winkel zo invoert, woo-compat-1).
				WooCart::apply_price( $line['data'], WooConfig::woo_unit_price( (float) $q['unitExcl'], $line['data'] ) );
			}
		}
	}

	/**
	 * Eenmalige kosten (borduurkaart zonder patchregel, PVC-mal sleutelhanger).
	 *
	 * @param \WC_Cart $cart Mand.
	 */
	public static function add_fees( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		$items = $cart->get_cart();
		$has   = false;
		foreach ( $items as $it ) {
			if ( ! empty( $it[ self::KEY ] ) ) {
				$has = true;
				break;
			}
		}
		if ( ! $has ) {
			return;
		}
		$main = Config::main();
		$lang = Messages::lang( Config::locale() );
		foreach ( Lines::fees( $items, $main, Config::get(), Service::legacy_groups( $items, $main ), $lang ) as $f ) {
			// Zelfde id als woo (bps-fee-<groep>): WooCommerce weigert een dubbele fee, dus nooit twee keer.
			$cart->fees_api()->add_fee(
				array(
					'id'        => sanitize_title( $f['id'] ),
					'name'      => $f['label'],
					'amount'    => round( (float) $f['excl'], 2 ),
					'taxable'   => true,
					'tax_class' => '',
				)
			);
		}
	}

	/**
	 * Woo Discount Rules: onze prijs is al volledig, nooit korting of meetellen.
	 *
	 * @param mixed $value     Waarde.
	 * @param mixed $cart_item Mandregel.
	 */
	public static function wdr_exclude( $value, $cart_item = null ) {
		return is_array( $cart_item ) && ! empty( $cart_item[ self::KEY ] ) ? false : $value;
	}

	/* ---------------------------------------------------------------------
	 * Validatie
	 * ------------------------------------------------------------------- */

	/**
	 * Laser/kader/PVC-producten van de module niet rechtstreeks toevoegen (enkel met een ontwerp).
	 *
	 * @param bool $passed     Toegelaten.
	 * @param int  $product_id Product.
	 */
	public static function block_direct( $passed, $product_id = 0 ) {
		if ( self::$adding || ! $passed ) {
			return $passed;
		}
		if ( Products::is_ours( (int) $product_id ) ) {
			wc_add_notice( self::text( 'direct' ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Eigen producten zonder bps_cs (bv. via een ander plugin toegevoegd) uit de mand halen.
	 *
	 * @param \WC_Cart $cart Mand.
	 */
	public static function drop_orphans( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $key => $it ) {
			if ( empty( $it[ self::KEY ] ) && Products::is_ours( (int) ( $it['product_id'] ?? 0 ) ) ) {
				$cart->remove_cart_item( $key );
			}
		}
	}

	/**
	 * Vóór de kassa: ontwerp bestaat nog, item staat aan, pastoets geeft geen "hide", prijs is berekend, aantallen kloppen.
	 */
	public static function check_cart_items(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$cart  = WC()->cart;
		$main  = Config::main();
		$items = $cart->get_cart();
		foreach ( $items as $key => $it ) {
			if ( empty( $it[ self::KEY ] ) ) {
				continue;
			}
			$cs   = $it[ self::KEY ];
			$name = isset( $it['data'] ) && $it['data'] instanceof \WC_Product ? wp_strip_all_tags( $it['data']->get_name() ) : (string) ( $cs['item'] ?? '' );
			$rec  = Service::design( (int) ( $cs['design_id'] ?? 0 ) );
			if ( ! $rec ) {
				$cart->remove_cart_item( $key );
				wc_add_notice( sprintf( self::text( 'gone' ), $name ), 'error' );
				continue;
			}
			$cfg_item = Config::item( (string) ( $cs['item'] ?? '' ) );
			if ( ! $cfg_item || ( array_key_exists( 'enabled', $cfg_item ) && false === $cfg_item['enabled'] ) || ! self::still_fits( $cfg_item, $rec, $it, $main ) ) {
				$cart->remove_cart_item( $key );
				wc_add_notice( sprintf( self::text( 'unfit' ), $name ), 'error' );
				continue;
			}
			$q = self::$priced[ $key ] ?? self::price_line( (string) $key, $it, $items, $main );
			if ( empty( $q['ok'] ) || ! ( $q['unitExcl'] > 0 ) ) {
				if ( ! array_intersect( (array) ( $q['errors'] ?? array() ), array( 'below_min_qty', 'qty_step' ) ) ) {
					Logger::error(
						'crosssell_price_missing',
						array(
							'design_id' => (int) ( $cs['design_id'] ?? 0 ),
							'ref'       => (string) ( $cs['ref'] ?? '' ),
							'item'      => (string) ( $cs['item'] ?? '' ),
							'qty'       => (int) $it['quantity'],
							'errors'    => array_values( (array) ( $q['errors'] ?? array() ) ),
						)
					);
					$cart->remove_cart_item( $key );
					wc_add_notice( esc_html( sprintf( self::text( 'price' ), $name, (string) ( $cs['ref'] ?? '' ) ) ), 'error' );
					continue;
				}
			}
			$msg = self::qty_problem( $cs, (int) $it['quantity'], $name, self::batch_qty( $items, $cs, (string) $key, (int) $it['quantity'] ) );
			if ( $msg ) {
				wc_add_notice( $msg, 'error' );
			}
		}
	}

	/**
	 * Totaal aantal van dezelfde toevoeging (batch), met een gewijzigd aantal voor één regel.
	 *
	 * @param array  $items Mand.
	 * @param array  $cs    bps_cs.
	 * @param string $key   Mandsleutel van de regel.
	 * @param int    $qty   Aantal van die regel.
	 */
	private static function batch_qty( array $items, array $cs, string $key, int $qty ): int {
		$sum = $qty;
		foreach ( $items as $k => $it ) {
			if ( (string) $k !== $key && ! empty( $it[ self::KEY ] ) && ( $it[ self::KEY ]['batch'] ?? '' ) === ( $cs['batch'] ?? '-' ) ) {
				$sum += (int) $it['quantity'];
			}
		}
		return $sum;
	}

	/**
	 * Pastoets opnieuw (SPEC-EXTRA §2.7). Het tonen-drempel van PVC (showFromQty) telt hier niet: de klant koos al.
	 *
	 * @param array $cfg_item Item.
	 * @param array $rec      DesignRecord.
	 * @param array $line     Mandregel.
	 * @param array $main     Hoofdconfig.
	 */
	private static function still_fits( array $cfg_item, array $rec, array $line, array $main ): bool {
		$d    = Fit::design_from_payload( (array) $rec['payload'] );
		$tech = $main['techniques'][ $d['technique'] ] ?? null;
		$f    = Fit::item(
			$cfg_item,
			$d,
			array(
				'tech'    => is_array( $tech ) ? $tech : null,
				'cartQty' => max( (int) $line['quantity'], (int) ( $cfg_item['minQty'] ?? 0 ), (int) ( $cfg_item['showFromQty'] ?? 0 ) ),
			)
		);
		return 'hide' !== ( $f['fit'] ?? 'hide' );
	}

	/**
	 * Aantalprobleem (PVC-minimum/stappen, namenlijst) of null.
	 *
	 * @param array  $cs   bps_cs.
	 * @param int    $qty  Aantal.
	 * @param string $name Productnaam.
	 * @param int    $total Totaal van de batch (namenlijst kan over meerdere maten verdeeld zijn); 0 = $qty.
	 */
	private static function qty_problem( array $cs, int $qty, string $name, int $total = 0 ): ?string {
		if ( ! empty( $cs['names'] ) && count( (array) $cs['names'] ) !== ( $total > 0 ? $total : $qty ) ) {
			return sprintf( self::text( 'names_qty' ), $name );
		}
		if ( 'pvc' === ( $cs['type'] ?? '' ) ) {
			$it   = Config::item( (string) $cs['item'] ) ?? array();
			$min  = max( 1, (int) ( $it['minQty'] ?? 1 ) );
			$step = max( 1, (int) ( $it['qtyStep'] ?? 1 ) );
			if ( $qty < $min ) {
				return sprintf( self::text( 'qty_min' ), $name, $min );
			}
			if ( 0 !== $qty % $step ) {
				return sprintf( self::text( 'qty_step' ), $name, $step );
			}
		}
		return null;
	}

	/**
	 * Aantal wijzigen in de klassieke mand.
	 *
	 * @param bool   $passed   Toegelaten.
	 * @param string $key      Mandsleutel.
	 * @param array  $values   Mandregel.
	 * @param int    $quantity Nieuw aantal.
	 */
	public static function update_cart_validation( $passed, $key, $values, $quantity ) {
		if ( ! $passed || empty( $values[ self::KEY ] ) ) {
			return $passed;
		}
		$name = isset( $values['data'] ) && $values['data'] instanceof \WC_Product ? wp_strip_all_tags( $values['data']->get_name() ) : '';
		$items = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart() : array();
		$msg   = self::qty_problem( $values[ self::KEY ], (int) $quantity, $name, self::batch_qty( $items, $values[ self::KEY ], (string) $key, (int) $quantity ) );
		if ( $msg ) {
			wc_add_notice( $msg, 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Aantalveld in de klassieke mand: namenlijst = vast aantal; PVC = minimum en stappen.
	 *
	 * @param string $html Veld.
	 * @param string $key  Mandsleutel.
	 * @param array  $item Mandregel.
	 */
	public static function quantity_html( $html, $key, $item = array() ) {
		if ( ! is_array( $item ) || empty( $item[ self::KEY ] ) ) {
			return $html;
		}
		$cs = $item[ self::KEY ];
		if ( ! empty( $cs['names'] ) ) {
			return sprintf( '%1$d <input type="hidden" name="cart[%2$s][qty]" value="%1$d" />', (int) $item['quantity'], esc_attr( (string) $key ) );
		}
		if ( 'pvc' === ( $cs['type'] ?? '' ) && function_exists( 'woocommerce_quantity_input' ) && isset( $item['data'] ) ) {
			$it = Config::item( (string) $cs['item'] ) ?? array();
			return woocommerce_quantity_input(
				array(
					'input_name'  => "cart[{$key}][qty]",
					'input_value' => (int) $item['quantity'],
					'min_value'   => max( 1, (int) ( $it['minQty'] ?? 1 ) ),
					'step'        => max( 1, (int) ( $it['qtyStep'] ?? 1 ) ),
					'max_value'   => Lines::MAX_QTY,
				),
				$item['data'],
				false
			);
		}
		return $html;
	}

	/**
	 * Store API: minimum (PVC).
	 *
	 * @param mixed $value     Waarde.
	 * @param mixed $product   Product.
	 * @param mixed $cart_item Mandregel.
	 */
	public static function store_api_qty_min( $value, $product = null, $cart_item = null ) {
		if ( is_array( $cart_item ) && 'pvc' === ( $cart_item[ self::KEY ]['type'] ?? '' ) ) {
			return max( 1, (int) ( Config::item( (string) $cart_item[ self::KEY ]['item'] )['minQty'] ?? 1 ) );
		}
		return $value;
	}

	/**
	 * Store API: stappen (PVC).
	 *
	 * @param mixed $value     Waarde.
	 * @param mixed $product   Product.
	 * @param mixed $cart_item Mandregel.
	 */
	public static function store_api_qty_step( $value, $product = null, $cart_item = null ) {
		if ( is_array( $cart_item ) && 'pvc' === ( $cart_item[ self::KEY ]['type'] ?? '' ) ) {
			return max( 1, (int) ( Config::item( (string) $cart_item[ self::KEY ]['item'] )['qtyStep'] ?? 1 ) );
		}
		return $value;
	}

	/**
	 * Store API: namenlijst = aantal niet aanpasbaar.
	 *
	 * @param mixed $value     Waarde.
	 * @param mixed $product   Product.
	 * @param mixed $cart_item Mandregel.
	 */
	public static function store_api_qty_editable( $value, $product = null, $cart_item = null ) {
		return is_array( $cart_item ) && ! empty( $cart_item[ self::KEY ]['names'] ) ? false : $value;
	}

	/* ---------------------------------------------------------------------
	 * Weergave
	 * ------------------------------------------------------------------- */

	/**
	 * Regels onder de productnaam (klassiek + blokken).
	 *
	 * @param array $item_data Bestaand.
	 * @param array $cart_item Mandregel.
	 */
	public static function item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item[ self::KEY ] ) || ! is_array( $cart_item[ self::KEY ] ) ) {
			return $item_data;
		}
		$item_data = is_array( $item_data ) ? $item_data : array();
		$lang      = Messages::lang( Config::locale() );
		foreach ( self::rows( $cart_item[ self::KEY ], $lang ) as $r ) {
			$item_data[] = array(
				'key'   => $r[0],
				'value' => $r[1],
			);
		}
		$item_data[] = array(
			'key'   => self::text( 'custom', $lang ),
			'value' => self::text( 'custom_v', $lang ),
		);
		$key = (string) ( $cart_item['key'] ?? '' );
		$q   = '' !== $key ? ( self::$priced[ $key ] ?? null ) : null;
		if ( is_array( $q ) && ! empty( $q['ok'] ) && ! empty( $q['parts'] ) ) {
			$locale      = 'fr' === $lang ? 'fr-BE' : 'nl-BE';
			$item_data[] = array(
				'key'   => self::text( 'price_bd', $lang ),
				'value' => implode(
					' · ',
					array_map( static fn( $p ) => $p['label'] . ' ' . Messages::money( (float) $p['incl'], $locale ), $q['parts'] )
				),
			);
		}
		return $item_data;
	}

	/**
	 * Leesbare regels [label, waarde] voor mand, bestelling en fiche.
	 *
	 * @param array  $cs   bps_cs.
	 * @param string $lang nl|fr.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function rows( array $cs, string $lang ): array {
		$rows  = array();
		$tech  = WooConfig::technique_label( (string) ( $cs['technique'] ?? '' ), $lang );
		$tech  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $tech ) : strtolower( $tech );
		$patch = Lines::patch_text( $cs, $tech, $lang );
		$rows[] = array( self::text( 'design', $lang ), (string) ( $cs['ref'] ?? '' ) );
		if ( '' !== $patch ) {
			$rows[] = array( self::text( 'patch', $lang ), $patch );
		}
		if ( ! empty( $cs['plate'] ) && ( '' !== ( $cs['plate']['l1'] ?? '' ) || '' !== ( $cs['plate']['l2'] ?? '' ) ) ) {
			$rows[] = array( self::text( 'plate', $lang ), trim( ( $cs['plate']['l1'] ?? '' ) . ' / ' . ( $cs['plate']['l2'] ?? '' ), ' /' ) );
		}
		$opts = array();
		foreach ( (array) ( $cs['options'] ?? array() ) as $oid ) {
			foreach ( (array) ( Config::item( (string) ( $cs['item'] ?? '' ) )['options'] ?? array() ) as $o ) {
				if ( ( $o['id'] ?? null ) === $oid && 'name_back' !== $oid ) {
					$opts[] = Pricing::lbl( $o['label'] ?? $oid, $lang );
				}
			}
		}
		if ( $opts ) {
			$rows[] = array( self::text( 'options', $lang ), implode( ', ', $opts ) );
		}
		if ( ! empty( $cs['names'] ) ) {
			$names  = (array) $cs['names'];
			$shown  = array_slice( $names, 0, 6 );
			$rest   = count( $names ) - count( $shown );
			$rows[] = array( self::text( 'names', $lang ), implode( ', ', $shown ) . ( $rest > 0 ? ' ' . sprintf( self::text( 'more', $lang ), $rest ) : '' ) );
		}
		return $rows;
	}

	/**
	 * Miniatuur = preview van het ontwerp (geen opslag van gegenereerde mockups).
	 *
	 * @param string $html      HTML.
	 * @param array  $cart_item Mandregel.
	 */
	public static function thumbnail( $html, $cart_item ) {
		if ( empty( $cart_item[ self::KEY ]['preview_url'] ) ) {
			return $html;
		}
		$img = WooCart::preview_images( $cart_item[ self::KEY ] );
		return sprintf(
			'<img src="%1$s"%2$s alt="%3$s" width="300" height="300" class="bps-cart-preview skip-lazy attachment-woocommerce_thumbnail" data-no-lazy="1" loading="eager" decoding="async" />',
			esc_url( $img['src'] ),
			'' !== $img['srcset'] ? ' srcset="' . esc_attr( $img['srcset'] ) . '"' : '',
			esc_attr( sprintf( self::text( 'alt' ), (string) ( $cart_item[ self::KEY ]['ref'] ?? '' ) ) )
		);
	}

	/**
	 * Miniatuur in de blokkenmand (Store API).
	 *
	 * @param array $images    Beelden.
	 * @param array $cart_item Mandregel.
	 */
	public static function store_api_images( $images, $cart_item ) {
		if ( empty( $cart_item[ self::KEY ]['preview_url'] ) ) {
			return $images;
		}
		$url = esc_url_raw( WooCart::preview_images( $cart_item[ self::KEY ] )['src'] );
		return array(
			(object) array(
				'id'        => 0,
				'src'       => $url,
				'thumbnail' => $url,
				'srcset'    => '',
				'sizes'     => '',
				'name'      => (string) ( $cart_item[ self::KEY ]['ref'] ?? '' ),
				'alt'       => sprintf( self::text( 'alt' ), (string) ( $cart_item[ self::KEY ]['ref'] ?? '' ) ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Bestelling
	 * ------------------------------------------------------------------- */

	/**
	 * Ordermeta (zichtbaar + verborgen).
	 *
	 * @param \WC_Order_Item_Product $item   Orderregel.
	 * @param string                 $key    Mandsleutel.
	 * @param array                  $values Mandregel.
	 * @param \WC_Order              $order  Bestelling.
	 */
	public static function order_meta( $item, $key, $values, $order = null ): void {
		if ( empty( $values[ self::KEY ] ) || ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}
		$cs   = $values[ self::KEY ];
		$lang = Messages::lang( (string) ( $cs['locale'] ?? '' ) );
		foreach ( self::rows( $cs, $lang ) as $r ) {
			$map = array(
				self::text( 'design', $lang )  => 'bps_cs_design',
				self::text( 'patch', $lang )   => 'bps_cs_patch',
				self::text( 'plate', $lang )   => 'bps_cs_plate',
				self::text( 'names', $lang )   => 'bps_cs_names',
				self::text( 'options', $lang ) => 'bps_cs_options',
			);
			if ( isset( $map[ $r[0] ] ) ) {
				$item->add_meta_data( $map[ $r[0] ], $r[1], true );
			}
		}
		if ( ! empty( $cs['names'] ) ) {
			// Volledige lijst (de rij hierboven is ingekort voor de mand).
			$item->update_meta_data( 'bps_cs_names', implode( ', ', (array) $cs['names'] ) );
		}
		// Zichtbaar, zodat ook de bestelmails de maatwerkmelding dragen (gdpr-juridisch-4).
		$item->add_meta_data( 'bps_cs_custom', self::text( 'custom_v', $lang ), true );
		$item->add_meta_data( '_bps_cs', wp_json_encode( $cs ), true );
		$item->add_meta_data( '_bps_cs_design_id', (int) ( $cs['design_id'] ?? 0 ), true );
		$q = self::$priced[ (string) $key ] ?? null;
		if ( is_array( $q ) && ! empty( $q['ok'] ) ) {
			$item->add_meta_data( '_bps_cs_unit_excl', (float) $q['unitExcl'], true );
		}
	}

	/**
	 * Labels van de zichtbare ordermeta.
	 *
	 * @param string $display_key Sleutel.
	 * @param object $meta        Meta.
	 * @param mixed  $item        Orderregel.
	 */
	public static function meta_label( $display_key, $meta = null, $item = null ) {
		$map = array(
			'bps_cs_design'  => 'design',
			'bps_cs_patch'   => 'patch',
			'bps_cs_plate'   => 'plate',
			'bps_cs_names'   => 'names',
			'bps_cs_options' => 'options',
			'bps_cs_custom'  => 'custom',
		);
		return isset( $map[ $display_key ] ) ? self::text( $map[ $display_key ] ) : $display_key;
	}

	/**
	 * Productiefiche: sectie "Hetzelfde ontwerp ook op" met alle cross-sell-regels van dit ontwerp in de bestelling.
	 *
	 * @param array $sections Secties.
	 * @param array $rec      DesignRecord.
	 * @param mixed $item     Orderregel (de patchregel).
	 */
	public static function fiche_section( $sections, $rec = array(), $item = null ) {
		if ( ! is_array( $sections ) || ! $item instanceof \WC_Order_Item_Product ) {
			return $sections;
		}
		$order = $item->get_order();
		$id    = (int) ( $rec['id'] ?? 0 );
		if ( ! $order || $id <= 0 ) {
			return $sections;
		}
		$lang = Messages::lang( Config::locale() );
		$rows = array();
		foreach ( $order->get_items() as $oi ) {
			if ( ! $oi instanceof \WC_Order_Item_Product || (int) $oi->get_meta( '_bps_cs_design_id' ) !== $id ) {
				continue;
			}
			$cs    = json_decode( (string) $oi->get_meta( '_bps_cs' ), true );
			$cs    = is_array( $cs ) ? $cs : array();
			$parts = array( (int) $oi->get_quantity() . ' ×' );
			foreach ( self::rows( $cs, $lang ) as $r ) {
				if ( self::text( 'design', $lang ) !== $r[0] ) {
					$parts[] = $r[1];
				}
			}
			$rows[] = array(
				'label' => wp_strip_all_tags( $oi->get_name() ),
				'value' => implode( ' · ', $parts ),
			);
		}
		if ( $rows ) {
			$sections['crosssell'] = array(
				'title' => self::text( 'section', $lang ),
				'rows'  => $rows,
			);
		}
		return $sections;
	}
}
