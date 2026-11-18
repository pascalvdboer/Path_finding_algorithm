<?php
/**
 * Compacte regel in de winkelmand: "Ook op pet, muts of in een kader?" (SPEC-EXTRA §2.1).
 * Enkel als er minstens één kaart past, niet na "Nee, bedankt" in dezelfde sessie, nooit in de kassa.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Crosssell;

defined( 'ABSPATH' ) || exit;

/**
 * Mandregel.
 */
final class Cart_Prompt {

	/** @var bool Al getoond in deze pagina. */
	private static $printed = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_after_cart_table', array( __CLASS__, 'classic' ) );
		add_filter( 'render_block_woocommerce/cart-items-block', array( __CLASS__, 'block' ), 10, 1 );
	}

	/**
	 * Moet de regel getoond worden? Geeft het ontwerp terug of null.
	 */
	public static function design_to_offer(): ?array {
		$cfg = Config::get();
		if ( empty( $cfg['enabled'] ) || empty( $cfg['showInCart'] ) || self::$printed ) {
			return null;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return null;
		}
		if ( function_exists( 'WC' ) && WC()->session && WC()->session->get( 'bps_cs_declined' ) ) {
			return null;
		}
		$id  = Lines::last_design_id( Service::cart_items() );
		$rec = $id > 0 ? Service::design( $id ) : null;
		if ( ! $rec ) {
			return null;
		}
		$offers = Service::offers( $rec, Config::locale() );
		return ! empty( $offers['cards'] ) ? $rec : null;
	}

	/**
	 * HTML van de regel, verborgen gerenderd: cart-prompt.js maakt hem zichtbaar en koppelt de knop (zonder JS niets).
	 */
	public static function html(): string {
		$rec = self::design_to_offer();
		if ( ! $rec ) {
			return '';
		}
		self::$printed = true;
		Module::enqueue();
		$l   = Config::locale();
		$ov  = Config::strings();
		$pre = '<div class="bps-cs-cartline" data-bps-cs-cartline hidden>'
			. '<p class="bps-cs-cartline__text">' . esc_html( Messages::t( 'cs.cartLine', $l, array(), $ov ) ) . '</p>'
			. '<button type="button" class="button bps-cs-cartline__btn">' . esc_html( Messages::t( 'cs.cartLineBtn', $l, array(), $ov ) ) . '</button>'
			. '</div>';
		return $pre;
	}

	/**
	 * Klassieke mand.
	 */
	public static function classic(): void {
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- opgebouwd met esc_html.
	}

	/**
	 * Blokkenmand: onder het blok met de mandregels.
	 *
	 * @param string $content Blok-HTML.
	 */
	public static function block( $content ) {
		return (string) $content . self::html();
	}
}
