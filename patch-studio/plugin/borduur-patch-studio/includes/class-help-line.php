<?php
/**
 * "How to reach us" sentence built from the contact settings that are actually filled in.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio;

use BorduurPatchStudio\Woo\Config;

defined( 'ABSPATH' ) || exit;

/**
 * One helper for every customer-facing "call or WhatsApp us" line (rate-limit errors, nametape noscript, JS
 * error texts). It only mentions a channel the shop really configured: phone, WhatsApp, e-mail, and otherwise a
 * link to the quote page. Never a hard-coded number or city, so the plugin works for any shop.
 *
 * Both languages live here (and not only in the .po) because the caller passes the customer's locale explicitly
 * and the same sentence is handed to the browser (nametape) for both languages.
 */
final class Help_Line {

	/**
	 * Sentence templates per language and channel combination. Placeholders: {phone}, {wa}, {email}, {url}.
	 *
	 * @var array<string, array<string, string>>
	 */
	const TEXTS = array(
		'nl' => array(
			'phone_wa'   => 'Bel of WhatsApp ons gerust op {phone}.',
			'phone'      => 'Bel ons gerust op {phone}.',
			'wa'         => 'Stuur ons gerust een berichtje via WhatsApp ({wa}).',
			'email'      => 'Mail ons gerust op {email} of stel je vraag via {url}.',
			'email_only' => 'Mail ons gerust op {email}.',
			'url'        => 'Stel je vraag gerust via {url}.',
			'none'       => 'Neem gerust contact met ons op.',
		),
		'fr' => array(
			'phone_wa'   => 'N\'hésite pas à nous appeler ou à nous écrire sur WhatsApp au {phone}.',
			'phone'      => 'N\'hésite pas à nous appeler au {phone}.',
			'wa'         => 'N\'hésite pas à nous écrire sur WhatsApp ({wa}).',
			'email'      => 'N\'hésite pas à nous écrire à {email} ou à poser ta question via {url}.',
			'email_only' => 'N\'hésite pas à nous écrire à {email}.',
			'url'        => 'N\'hésite pas à poser ta question via {url}.',
			'none'       => 'N\'hésite pas à nous contacter.',
		),
	);

	/**
	 * The help sentence for a locale ('nl', 'fr', 'fr_BE', 'fr-BE', … ; empty = current locale).
	 *
	 * @param string $locale Locale.
	 */
	public static function text( string $locale = '' ): string {
		$lang = Config::lang( '' !== $locale ? $locale : null );
		$key  = self::variant();
		$c    = self::contact();
		$text = strtr(
			self::TEXTS[ $lang ][ $key ] ?? self::TEXTS['nl'][ $key ],
			array(
				'{phone}' => $c['phone'],
				'{wa}'    => $c['wa_display'],
				'{email}' => $c['email'],
				'{url}'   => $c['url'],
			)
		);
		/**
		 * Filter the "how to reach us" sentence.
		 *
		 * @param string $text    Sentence.
		 * @param string $lang    'nl' | 'fr'.
		 * @param string $variant phone_wa | phone | wa | email | email_only | url | none.
		 * @param array  $contact Normalised contact data.
		 */
		return (string) apply_filters( 'bps_help_line', $text, $lang, $key, $c );
	}

	/**
	 * Which channel combination applies: phone_wa | phone | wa | email | email_only | url | none.
	 */
	public static function variant(): string {
		$c = self::contact();
		if ( '' !== $c['phone'] && '' !== $c['wa'] ) {
			return 'phone_wa';
		}
		if ( '' !== $c['phone'] ) {
			return 'phone';
		}
		if ( '' !== $c['wa'] ) {
			return 'wa';
		}
		if ( '' !== $c['email'] ) {
			return '' !== $c['url'] ? 'email' : 'email_only';
		}
		return '' !== $c['url'] ? 'url' : 'none';
	}

	/**
	 * Both languages at once (for the browser).
	 *
	 * @return array{nl:string, fr:string}
	 */
	public static function all(): array {
		return array(
			'nl' => self::text( 'nl' ),
			'fr' => self::text( 'fr' ),
		);
	}

	/**
	 * Normalised contact settings (plain text, never markup).
	 *
	 * @return array{phone:string, wa:string, wa_display:string, email:string, url:string}
	 */
	private static function contact(): array {
		$contact = Config::path( 'contact', array() );
		$contact = is_array( $contact ) ? $contact : array();
		$str     = static function ( $v ): string {
			return is_scalar( $v ) ? trim( sanitize_text_field( (string) $v ) ) : '';
		};
		$phone = $str( $contact['phone'] ?? '' );
		$wa    = preg_replace( '/\D+/', '', $str( $contact['whatsapp'] ?? '' ) );
		$email = $str( $contact['email'] ?? '' );
		$email = '' !== $email && is_email( $email ) ? $email : '';
		$url   = $str( $contact['quoteUrl'] ?? '' );
		if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
			$url = '/' === $url[0] ? home_url( $url ) : '';
		}
		$url = '' !== $url ? esc_url_raw( $url ) : '';
		return array(
			'phone'      => $phone,
			'wa'         => (string) $wa,
			'wa_display' => '' !== (string) $wa ? '+' . $wa : '',
			'email'      => $email,
			'url'        => $url,
		);
	}
}
