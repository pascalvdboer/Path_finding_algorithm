<?php
/**
 * Cross-sell meten zonder persoonsgegevens (SPEC-EXTRA §2.8): per item tellers shown/added/removed/ordered/declined
 * in de optie bps_cs_stats. Automatisch uitzetten (te weinig toevoegingen) enkel als de eigenaar dat aanzet.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Crosssell;

defined( 'ABSPATH' ) || exit;

/**
 * Tellers.
 */
final class Stats {

	const OPTION = 'bps_cs_stats';
	const KINDS  = array( 'shown', 'added', 'removed', 'ordered', 'declined' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'on_removed' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status' ), 10, 4 );
	}

	/**
	 * Alle tellers.
	 *
	 * @return array<string,array<string,int>>
	 */
	public static function all(): array {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Teller verhogen (enkel bekende items en soorten).
	 *
	 * @param string $item Item-id.
	 * @param string $kind Soort.
	 * @param int    $n    Aantal.
	 */
	public static function bump( string $item, string $kind, int $n = 1 ): void {
		$cfg = Config::get();
		if ( empty( $cfg['stats']['enabled'] ) || ! in_array( $kind, self::KINDS, true ) || ! Config::item( $item ) || $n <= 0 ) {
			return;
		}
		$all                  = self::all();
		$row                  = isset( $all[ $item ] ) && is_array( $all[ $item ] ) ? $all[ $item ] : array();
		$row[ $kind ]         = (int) ( $row[ $kind ] ?? 0 ) + $n;
		$all[ $item ]         = $row;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Alles op nul.
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Items die automatisch uitgeschakeld zijn: ≥ minShown vertoningen en < minAddRate toevoegingen,
	 * enkel als stats.autoDisable aan staat (standaard uit). Formaat voor Rules::pick().
	 *
	 * @param array|null $cfg Config.
	 * @return array<string,array{autoDisabled:bool}>
	 */
	public static function flags( ?array $cfg = null ): array {
		$cfg = $cfg ?? Config::get();
		$s   = isset( $cfg['stats'] ) && is_array( $cfg['stats'] ) ? $cfg['stats'] : array();
		if ( empty( $s['enabled'] ) || empty( $s['autoDisable'] ) ) {
			return array();
		}
		$out = array();
		foreach ( self::all() as $id => $row ) {
			if ( self::auto_disabled( is_array( $row ) ? $row : array(), $s ) ) {
				$out[ (string) $id ] = array( 'autoDisabled' => true );
			}
		}
		return $out;
	}

	/**
	 * Valt een item onder de drempel? (Pure regel, ook voor de beheertabel.)
	 *
	 * @param array $row Tellers.
	 * @param array $s   stats-config.
	 */
	public static function auto_disabled( array $row, array $s ): bool {
		$shown = (int) ( $row['shown'] ?? 0 );
		$added = (int) ( $row['added'] ?? 0 );
		$min   = max( 1, (int) ( $s['minShown'] ?? 200 ) );
		$rate  = (float) ( $s['minAddRate'] ?? 0.01 );
		return $shown >= $min && ( $added / $shown ) < $rate;
	}

	/**
	 * Regel uit de mand verwijderd.
	 *
	 * @param string   $key  Mandsleutel.
	 * @param \WC_Cart $cart Mand.
	 */
	public static function on_removed( $key, $cart ): void {
		$it = ( $cart instanceof \WC_Cart && isset( $cart->removed_cart_contents[ $key ] ) ) ? $cart->removed_cart_contents[ $key ] : null;
		if ( is_array( $it ) && ! empty( $it['bps_cs']['item'] ) ) {
			self::bump( (string) $it['bps_cs']['item'], 'removed' );
		}
	}

	/**
	 * Besteld: één keer per bestelling tellen zodra ze betaald/in behandeling is.
	 *
	 * @param int       $order_id Bestelling.
	 * @param string    $from     Oude status.
	 * @param string    $to       Nieuwe status.
	 * @param \WC_Order $order    Bestelling.
	 */
	public static function on_status( $order_id, $from, $to, $order = null ): void {
		if ( ! in_array( $to, array( 'processing', 'on-hold', 'completed' ), true ) ) {
			return;
		}
		$order = $order instanceof \WC_Order ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null );
		if ( ! $order instanceof \WC_Order || $order->get_meta( '_bps_cs_counted' ) ) {
			return;
		}
		$found = false;
		foreach ( $order->get_items() as $item ) {
			$raw = $item instanceof \WC_Order_Item_Product ? (string) $item->get_meta( '_bps_cs' ) : '';
			$cs  = '' !== $raw ? json_decode( $raw, true ) : null;
			if ( is_array( $cs ) && ! empty( $cs['item'] ) ) {
				self::bump( (string) $cs['item'], 'ordered' );
				$found = true;
			}
		}
		if ( $found ) {
			$order->update_meta_data( '_bps_cs_counted', 1 );
			$order->save_meta_data();
		}
	}
}
