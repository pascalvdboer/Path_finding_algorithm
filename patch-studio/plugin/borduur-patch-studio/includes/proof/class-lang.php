<?php
/**
 * Language switching for customer-facing proof texts (proof page, proof mails) and plain-text output helpers.
 *
 * Uses `BorduurPatchStudio\I18n::switch_lang()` (contract C9) when it exists. Without it, WordPress'
 * switch_to_locale() only works when the core language pack is installed; in that case the plugin's own fr_BE
 * catalogue is loaded directly, so a French customer always gets the plugin's French texts (i18n-a11y-5).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Proof;

defined( 'ABSPATH' ) || exit;

/**
 * Stack-based switcher: every switch_to() must be followed by restore().
 */
final class Lang {

	/** Text domain. */
	const DOMAIN = 'borduur-patch-studio';

	/**
	 * Switch stack.
	 *
	 * @var array<int,array{mode:string,lang:string}>
	 */
	private static $stack = array();

	/**
	 * Switch to 'nl' or 'fr' for the customer texts (also sets the Spec label language).
	 *
	 * @param string $lang 'nl'|'fr'.
	 */
	public static function switch_to( string $lang ): void {
		$lang  = 'fr' === $lang ? 'fr' : 'nl';
		$entry = array(
			'mode' => 'none',
			'lang' => Spec::set_lang( $lang ),
		);
		$i18n  = '\BorduurPatchStudio\I18n';
		if ( class_exists( $i18n ) && is_callable( array( $i18n, 'switch_lang' ) ) && is_callable( array( $i18n, 'restore' ) ) ) {
			$entry['mode'] = call_user_func( array( $i18n, 'switch_lang' ), $lang ) ? 'i18n' : 'none';
		} else {
			$entry['mode'] = self::fallback_switch( $lang );
		}
		self::$stack[] = $entry;
	}

	/**
	 * Undo the last switch_to().
	 */
	public static function restore(): void {
		$entry = array_pop( self::$stack );
		if ( ! $entry ) {
			return;
		}
		Spec::set_lang( $entry['lang'] );
		if ( 'i18n' === $entry['mode'] ) {
			call_user_func( array( '\BorduurPatchStudio\I18n', 'restore' ) );
		} elseif ( 'locale' === $entry['mode'] ) {
			restore_previous_locale();
		} elseif ( 'mo' === $entry['mode'] ) {
			unload_textdomain( self::DOMAIN );
			if ( function_exists( 'load_plugin_textdomain' ) && defined( 'BPS_FILE' ) ) {
				load_plugin_textdomain( self::DOMAIN, false, dirname( plugin_basename( BPS_FILE ) ) . '/languages' );
			}
		}
	}

	/**
	 * Fallback without the I18n helper: switch_to_locale(), else load the plugin catalogue directly.
	 *
	 * @param string $lang 'nl'|'fr'.
	 * @return string Mode used ('none'|'locale'|'mo').
	 */
	private static function fallback_switch( string $lang ): string {
		$wp_locale = 'fr' === $lang ? 'fr_BE' : 'nl_BE';
		$current   = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		if ( Spec::lang_of( $current ) === $lang ) {
			return 'none';
		}
		if ( function_exists( 'switch_to_locale' ) && switch_to_locale( $wp_locale ) ) {
			// The core pack exists; make sure our own catalogue follows (WP reloads it just in time).
			return 'locale';
		}
		// No core language pack: load (or drop) our own catalogue for this request part.
		unload_textdomain( self::DOMAIN );
		if ( 'fr' === $lang ) {
			$dir = defined( 'BPS_DIR' ) ? BPS_DIR : dirname( __DIR__, 2 ) . '/';
			load_textdomain( self::DOMAIN, $dir . 'languages/' . self::DOMAIN . '-fr_BE.mo' );
		}
		return 'mo';
	}

	/**
	 * Plain-text e-mail line: no HTML, entities decoded (D'Hondt stays D'Hondt, i18n-a11y-2). Delegates to
	 * Woo\Emails\Base::plain() when that helper exists.
	 *
	 * @param mixed $s Text.
	 */
	public static function plain( $s ): string {
		$base = '\BorduurPatchStudio\Woo\Emails\Base';
		if ( class_exists( $base, false ) && is_callable( array( $base, 'plain' ) ) ) {
			return (string) call_user_func( array( $base, 'plain' ), (string) $s );
		}
		return html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' );
	}
}
