<?php
/**
 * Production sheet ("productiefiche") and digital proof for Patch Studio order lines (SPEC §6.5–6.6).
 *
 * - Order screen (classic + HPOS): one card per patch line in a metabox, plus a short pointer under each line item.
 * - Printable A4 sheet: admin-post.php?action=bps_print_fiche&order=ID[&item=ID]&_wpnonce=…
 * - "Digitale proef versturen": AJAX → WC_Email BPS_Email_Proof (customer language) with approve / change links.
 * - Customer response: ?bps_proof={designId}&o={orderId}&t={token}&a=approve|changes on template_redirect
 *   (GET only shows a confirmation page; the state changes on POST, so mail scanners cannot approve by prefetching).
 * - Orders list: column "Patchproef".
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Admin;

use BorduurPatchStudio\Settings;
use BorduurPatchStudio\Woo\Designs;

defined( 'ABSPATH' ) || exit;

/**
 * Component registered by Plugin::boot() (SPEC §4.1).
 */
final class Order_Production {

	/** Nonce action for the AJAX endpoints on the order screen. */
	const NONCE = 'bps_production';

	/** Order item meta mirroring the proof status (orders list without loading designs). */
	const ITEM_PROOF_META = '_bps_proof_status';

	/** Proof statuses (stored in the design's _bps_proof). */
	const STATUSES = array( 'none', 'sent', 'approved', 'changes_requested' );

	/** Max length of free text (message to customer, customer note). */
	const MAX_TEXT = 2000;

	/** @var string Language used by label helpers and the customer-text fallback ('nl'|'fr'). */
	private static $lang = 'nl';

	/** @var array|null Per-request config cache. */
	private static $config = null;

	/**
	 * Built-in French for customer-facing texts (proof e-mail + response page), used only when the
	 * gettext catalogue has no translation. Keys are the Dutch source strings passed to __().
	 */
	const FR = array(
		'Je digitale proef voor patch {ref} staat klaar'                                                                => 'Votre épreuve numérique pour l\'écusson {ref} est prête',
		'Je digitale proef'                                                                                              => 'Votre épreuve numérique',
		'Hallo %s,'                                                                                                      => 'Bonjour %s,',
		'Hallo,'                                                                                                         => 'Bonjour,',
		'Hier is de digitale proef van je patch %1$s (bestelling #%2$s). Kijk je alles even na?'                        => 'Voici l\'épreuve numérique de votre écusson %1$s (commande n° %2$s). Pouvez-vous tout vérifier ?',
		'Klopt alles? Dan starten we de productie zodra je goedkeurt.'                                                   => 'Tout est correct ? Nous lançons la production dès votre accord.',
		'Ik keur goed'                                                                                                   => 'J\'approuve',
		'Ik wil iets aanpassen'                                                                                          => 'Je souhaite modifier quelque chose',
		'Digitale proef van je patch'                                                                                    => 'Épreuve numérique de votre écusson',
		'Bericht van ons atelier'                                                                                        => 'Message de notre atelier',
		'Pas na jouw akkoord starten we de productie. Garen- en drukkleuren kunnen licht afwijken van je scherm.'       => 'Nous ne démarrons la production qu\'après votre accord. Les couleurs du fil et de l\'impression peuvent légèrement différer de votre écran.',
		'Vragen? Antwoord gewoon op deze e-mail of bel %s.'                                                             => 'Des questions ? Répondez simplement à cet e-mail ou appelez le %s.',
		'Vragen? Antwoord gewoon op deze e-mail.'                                                                       => 'Des questions ? Répondez simplement à cet e-mail.',
		'Proefronde %d'                                                                                                  => 'Épreuve n° %d',
		'Techniek'                                                                                                       => 'Technique',
		'Maat'                                                                                                           => 'Taille',
		'Vorm'                                                                                                           => 'Forme',
		'Aantal'                                                                                                         => 'Quantité',
		'Rand'                                                                                                           => 'Bordure',
		'Randkleur'                                                                                                      => 'Couleur de la bordure',
		'Achterkant'                                                                                                     => 'Dos',
		'Stofkleur'                                                                                                      => 'Couleur du tissu',
		'Garenkleuren'                                                                                                   => 'Couleurs de fil',
		'Tekst'                                                                                                          => 'Texte',
		'%s stuks'                                                                                                       => '%s pièces',
		'Proef goedkeuren'                                                                                               => 'Approuver l\'épreuve',
		'Aanpassing vragen'                                                                                              => 'Demander une modification',
		'Bekijk je proef en bevestig hieronder. Pas na jouw akkoord starten we de productie.'                           => 'Vérifiez votre épreuve et confirmez ci-dessous. Nous ne démarrons la production qu\'après votre accord.',
		'Ja, ik keur deze proef goed'                                                                                    => 'Oui, j\'approuve cette épreuve',
		'Opmerking (optioneel)'                                                                                          => 'Remarque (facultatif)',
		'Wat wil je aanpassen?'                                                                                          => 'Que souhaitez-vous modifier ?',
		'Beschrijf zo concreet mogelijk wat er anders moet, bijvoorbeeld een kleur, de maat of een tekst.'              => 'Décrivez le plus concrètement possible ce qui doit changer, par exemple une couleur, la taille ou un texte.',
		'Verstuur mijn opmerking'                                                                                        => 'Envoyer ma remarque',
		'Toch goedkeuren'                                                                                                => 'Finalement, j\'approuve',
		'Toch iets aanpassen'                                                                                            => 'Finalement, je souhaite modifier',
		'Bedankt, je proef is goedgekeurd'                                                                               => 'Merci, votre épreuve est approuvée',
		'We starten de productie van je patch. Je hoort van ons zodra je bestelling onderweg is.'                       => 'Nous lançons la production de votre écusson. Nous vous prévenons dès que votre commande est en route.',
		'Bedankt, we hebben je opmerking ontvangen'                                                                      => 'Merci, nous avons bien reçu votre remarque',
		'We passen je ontwerp aan en sturen je een nieuwe digitale proef.'                                               => 'Nous adaptons votre design et vous envoyons une nouvelle épreuve numérique.',
		'Je hebt deze proef al goedgekeurd op %s.'                                                                       => 'Vous avez déjà approuvé cette épreuve le %s.',
		'Je vroeg op %s een aanpassing. We sturen je een nieuwe proef.'                                                  => 'Vous avez demandé une modification le %s. Nous vous enverrons une nouvelle épreuve.',
		'Wil je toch nog iets wijzigen? Neem dan contact met ons op.'                                                    => 'Vous souhaitez encore modifier quelque chose ? Contactez-nous.',
		'Er is intussen een nieuwere proef verstuurd'                                                                    => 'Une épreuve plus récente a été envoyée entre-temps',
		'Gebruik de knoppen in de laatste e-mail met je digitale proef.'                                                 => 'Utilisez les boutons du dernier e-mail contenant votre épreuve numérique.',
		'Deze link werkt niet (meer)'                                                                                    => 'Ce lien ne fonctionne pas (ou plus)',
		'Controleer of je de volledige link uit de e-mail gebruikt, of neem contact met ons op.'                        => 'Vérifiez que vous utilisez le lien complet de l\'e-mail, ou contactez-nous.',
		'Laat ons even weten wat je wil aanpassen.'                                                                      => 'Indiquez-nous ce que vous souhaitez modifier.',
		'Je reactie op de digitale proef'                                                                                => 'Votre réponse à l\'épreuve numérique',
		'Bestelling #%s'                                                                                                 => 'Commande n° %s',
		'Patch %s'                                                                                                       => 'Écusson %s',
		'Je opmerking'                                                                                                   => 'Votre remarque',
		'Contact'                                                                                                        => 'Contact',
		'Terug naar de website'                                                                                          => 'Retour au site',
		'Hallo %1$s, hier is de digitale proef van je patch. Kijk je alles even na? Klopt alles, dan starten we meteen met de productie.' => 'Bonjour %1$s, voici l\'épreuve numérique de votre écusson. Pouvez-vous tout vérifier ? Si tout est correct, nous lançons immédiatement la production.',
		'Hier is de digitale proef van je patch. Kijk je alles even na? Klopt alles, dan starten we meteen met de productie.' => 'Voici l\'épreuve numérique de votre écusson. Pouvez-vous tout vérifier ? Si tout est correct, nous lançons immédiatement la production.',
		'Recht'                                                                                                          => 'Droit',
		'Boog boven'                                                                                                     => 'Arc en haut',
		'Boog onder'                                                                                                     => 'Arc en bas',
		'klittenband %s'                                                                                                 => 'velcro %s',
	);

	/* =====================================================================
	 * Registration
	 * =================================================================== */

	/**
	 * Hooks (SPEC §4.1 component contract).
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 30, 2 );
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'after_order_itemmeta' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_action( 'admin_post_bps_print_fiche', array( __CLASS__, 'handle_print' ) );
		add_action( 'wp_ajax_bps_send_proof', array( __CLASS__, 'ajax_send_proof' ) );
		add_action( 'wp_ajax_bps_set_proof_status', array( __CLASS__, 'ajax_set_status' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_proof_response' ), 1 );
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'email_classes' ), 20 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_itemmeta' ) );

		// Orders list column (HPOS + legacy).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'list_columns' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'list_column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'list_columns' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'list_column_legacy' ), 10, 2 );
	}

	/* =====================================================================
	 * Small helpers (config, labels, language)
	 * =================================================================== */

	/**
	 * Full config (Settings::get(), SPEC §4.2).
	 */
	private static function config(): array {
		if ( null === self::$config ) {
			self::$config = class_exists( Settings::class ) ? (array) Settings::get() : array();
		}
		return self::$config;
	}

	/**
	 * Dot-path config lookup.
	 *
	 * @param string $dot     Path.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	private static function cfg( string $dot, $default = null ) {
		$node = self::config();
		foreach ( explode( '.', $dot ) as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return $default;
			}
			$node = $node[ $part ];
		}
		return $node;
	}

	/**
	 * 'nl'|'fr' for a locale-ish string.
	 *
	 * @param string|null $locale Locale ('fr-BE', 'fr_FR', 'nl_BE', …).
	 */
	public static function lang_of( ?string $locale ): string {
		return ( null !== $locale && 0 === stripos( $locale, 'fr' ) ) ? 'fr' : 'nl';
	}

	/**
	 * Language of the current admin user.
	 */
	private static function admin_lang(): string {
		return self::lang_of( function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale() );
	}

	/**
	 * Set the language for label helpers / customer text fallback; returns the previous one.
	 *
	 * @param string $lang 'nl'|'fr'.
	 */
	public static function set_lang( string $lang ): string {
		$prev       = self::$lang;
		self::$lang = 'fr' === $lang ? 'fr' : 'nl';
		return $prev;
	}

	/**
	 * Current helper language.
	 */
	public static function lang(): string {
		return self::$lang;
	}

	/**
	 * Customer-text fallback: a Dutch source string that gettext did not translate gets our built-in French.
	 *
	 * @param string      $translated Result of __( 'Dutch source', 'borduur-patch-studio' ).
	 * @param string|null $lang       Language (default: current helper language).
	 */
	public static function l( string $translated, ?string $lang = null ): string {
		$lang = $lang ?? self::$lang;
		if ( 'fr' === $lang && isset( self::FR[ $translated ] ) ) {
			return self::FR[ $translated ];
		}
		return $translated;
	}

	/**
	 * Text from a {nl,fr} label in the helper language.
	 *
	 * @param mixed $obj Label or string.
	 */
	private static function label( $obj ): string {
		if ( is_string( $obj ) ) {
			return $obj;
		}
		if ( ! is_array( $obj ) ) {
			return '';
		}
		$alt = 'fr' === self::$lang ? 'nl' : 'fr';
		return (string) ( ! empty( $obj[ self::$lang ] ) ? $obj[ self::$lang ] : ( $obj[ $alt ] ?? '' ) );
	}

	/**
	 * Number with decimal comma ("8", "7,5", "0,8").
	 *
	 * @param float $n        Number.
	 * @param int   $decimals Max decimals.
	 */
	public static function num( float $n, int $decimals = 1 ): string {
		$s = number_format( $n, $decimals, ',', '.' );
		if ( false !== strpos( $s, ',' ) ) {
			$s = rtrim( rtrim( $s, '0' ), ',' );
		}
		return $s;
	}

	/**
	 * Lower-case (multibyte-safe when mbstring is available).
	 *
	 * @param string $s Text.
	 */
	private static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
	}

	/**
	 * Technique definition.
	 *
	 * @param string $id Technique id.
	 */
	private static function technique( string $id ): array {
		$t = self::cfg( 'techniques.' . $id );
		return is_array( $t ) ? $t : array();
	}

	/**
	 * Technique label.
	 *
	 * @param string $id Technique id.
	 */
	private static function technique_label( string $id ): string {
		$t = self::technique( $id );
		return '' !== $id && $t ? self::label( $t['label'] ?? $id ) : $id;
	}

	/**
	 * Shape label.
	 *
	 * @param string $id Shape id.
	 */
	private static function shape_label( string $id ): string {
		$s = self::cfg( 'shapes.' . $id );
		return is_array( $s ) ? self::label( $s['label'] ?? $id ) : $id;
	}

	/**
	 * Option definition by id.
	 *
	 * @param string $id Option id.
	 */
	private static function option( string $id ): ?array {
		foreach ( (array) self::cfg( 'options', array() ) as $opt ) {
			if ( is_array( $opt ) && isset( $opt['id'] ) && $opt['id'] === $id ) {
				return $opt;
			}
		}
		return null;
	}

	/**
	 * Thread from the palette by code.
	 *
	 * @param string $code Thread code.
	 */
	private static function thread( string $code ): ?array {
		static $map = null;
		if ( null === $map ) {
			$map = array();
			foreach ( (array) self::cfg( 'threads', array() ) as $t ) {
				if ( is_array( $t ) && isset( $t['code'] ) ) {
					$map[ (string) $t['code'] ] = $t;
				}
			}
		}
		return $map[ $code ] ?? null;
	}

	/**
	 * Font definition by id.
	 *
	 * @param string $id Font id.
	 */
	private static function font( string $id ): ?array {
		foreach ( (array) self::cfg( 'fonts', array() ) as $f ) {
			if ( is_array( $f ) && isset( $f['id'] ) && $f['id'] === $id ) {
				return $f;
			}
		}
		return null;
	}

	/**
	 * Thread brand shown before codes ("Polyneon").
	 */
	private static function brand(): string {
		$b = (string) self::cfg( 'threadBrand', 'Polyneon' );
		$b = '' !== $b ? $b : 'Polyneon';
		return trim( preg_replace( '/^Madeira\s+/i', '', $b ) );
	}

	/**
	 * Safe hex colour or ''.
	 *
	 * @param mixed $hex Value.
	 */
	private static function hex( $hex ): string {
		$hex = is_string( $hex ) ? trim( $hex ) : '';
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) ? strtolower( $hex ) : '';
	}

	/**
	 * Fabric / leather label by payload fabric.
	 *
	 * @param array $fabric {id, hex, label}.
	 */
	private static function fabric_label( array $fabric ): string {
		$id = (string) ( $fabric['id'] ?? '' );
		foreach ( array_merge( (array) self::cfg( 'fabrics', array() ), (array) self::cfg( 'leathers', array() ) ) as $f ) {
			if ( is_array( $f ) && isset( $f['id'] ) && $f['id'] === $id ) {
				return self::label( $f['label'] ?? $id );
			}
		}
		return (string) ( $fabric['label'] ?? $id );
	}

	/**
	 * Where the customer placed a text layer (empty for the default spot / older payloads).
	 *
	 * @param array $tx Text layer.
	 */
	private static function text_place( array $tx ): string {
		$path = (string) ( $tx['path'] ?? 'straight' );
		if ( 'straight' === $path ) {
			$x = (float) ( $tx['offsetMm']['x'] ?? 0 );
			$y = (float) ( $tx['offsetMm']['y'] ?? 0 );
			if ( abs( $x ) < 0.05 && abs( $y ) < 0.05 ) {
				return isset( $tx['offsetMm'] ) ? __( 'gecentreerd', 'borduur-patch-studio' ) : '';
			}
			/* translators: 1: x offset mm, 2: y offset mm from the patch centre */
			return sprintf( __( 'midden %1$s / %2$s mm (x / y) vanaf het centrum', 'borduur-patch-studio' ), self::num( $x ), self::num( $y ) );
		}
		if ( ! isset( $tx['angleDeg'] ) && ! isset( $tx['insetMm'] ) ) {
			return '';
		}
		/* translators: 1: angle in degrees along the edge, 2: extra distance to the edge in mm */
		return sprintf( __( 'gedraaid %1$s° · %2$s mm extra van de rand', 'borduur-patch-studio' ), (string) (int) ( $tx['angleDeg'] ?? 0 ), self::num( (float) ( $tx['insetMm'] ?? 0 ) ) );
	}

	/**
	 * Text path label.
	 *
	 * @param string $path straight|arc_top|arc_bottom.
	 */
	private static function path_label( string $path ): string {
		switch ( $path ) {
			case 'arc_top':
				return self::l( __( 'Boog boven', 'borduur-patch-studio' ) );
			case 'arc_bottom':
				return self::l( __( 'Boog onder', 'borduur-patch-studio' ) );
			default:
				return self::l( __( 'Recht', 'borduur-patch-studio' ) );
		}
	}

	/**
	 * Thread display "Naam · Polyneon 1747".
	 *
	 * @param string $code Code.
	 * @param string $name Fallback name.
	 * @return array{name:string, code:string, hex:string}
	 */
	private static function thread_info( string $code, string $name = '', string $hex = '' ): array {
		$t = '' !== $code ? self::thread( $code ) : null;
		if ( $t ) {
			$name = self::label( $t['name'] ?? $name );
			$hex  = '' !== self::hex( $hex ) ? $hex : (string) ( $t['hex'] ?? '' );
		}
		return array(
			'name' => '' !== $name ? $name : $code,
			'code' => $code,
			'hex'  => self::hex( $hex ),
		);
	}

	/* =====================================================================
	 * Data: order lines → fiche
	 * =================================================================== */

	/**
	 * Patch Studio lines of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return \WC_Order_Item_Product[] keyed by item id.
	 */
	public static function design_items( $order ): array {
		$out = array();
		if ( ! $order instanceof \WC_Order ) {
			return $out;
		}
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( $item instanceof \WC_Order_Item_Product && ( (int) $item->get_meta( '_bps_design_id' ) > 0 || '' !== (string) $item->get_meta( '_bps_payload' ) ) ) {
				$out[ (int) $item_id ] = $item;
			}
		}
		return $out;
	}

	/**
	 * Design record for an order line (or a synthesized record from the order snapshot when the post was cleaned up).
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 * @return array{record:array, exists:bool}
	 */
	public static function design_for_item( $item ): array {
		$id     = (int) $item->get_meta( '_bps_design_id' );
		$record = ( $id > 0 && class_exists( Designs::class ) ) ? Designs::get( $id ) : null;
		$snap   = json_decode( (string) $item->get_meta( '_bps_payload' ), true );
		$snap   = is_array( $snap ) ? $snap : array();
		if ( $record ) {
			// The order snapshot is what the customer ordered; prefer it over the (equal or later) design payload.
			if ( $snap ) {
				$record['payload'] = $snap;
			}
			if ( '' === (string) $record['preview_url'] ) {
				$record['preview_url'] = (string) $item->get_meta( '_bps_preview_url' );
			}
			return array(
				'record' => $record,
				'exists' => true,
			);
		}
		return array(
			'record' => array(
				'id'            => $id,
				'ref'           => (string) $item->get_meta( 'bps_ref' ),
				'status'        => 'ordered',
				'created_gmt'   => '',
				'payload'       => $snap,
				'original'      => null,
				'preview_url'   => (string) $item->get_meta( '_bps_preview_url' ),
				'order_id'      => $item->get_order_id(),
				'order_item_id' => $item->get_id(),
				'email'         => null,
				'proof'         => array(
					'status'  => (string) ( $item->get_meta( self::ITEM_PROOF_META ) ?: 'none' ),
					'history' => array(),
				),
			),
			'exists' => false,
		);
	}

	/**
	 * Everything the card, the print sheet and the proof mail need for one order line.
	 *
	 * @param \WC_Order_Item_Product $item  Item.
	 * @param string|null            $lang  Label language (default: admin user language).
	 */
	public static function fiche( $item, ?string $lang = null ): array {
		$prev = self::set_lang( $lang ?? self::admin_lang() );
		$d    = self::design_for_item( $item );
		$rec  = $d['record'];
		$p    = (array) $rec['payload'];

		$tech    = (string) ( $item->get_meta( '_bps_technique_id' ) ?: ( $p['technique'] ?? '' ) );
		$size_cm = (float) ( $item->get_meta( '_bps_size_cm' ) ?: ( $p['sizeCm'] ?? 0 ) );
		$shape   = (string) ( $item->get_meta( '_bps_shape_id' ) ?: ( $p['shape'] ?? '' ) );
		$options = json_decode( (string) $item->get_meta( '_bps_options' ), true );
		$options = is_array( $options ) ? array_map( 'strval', $options ) : array_map( 'strval', (array) ( $p['options'] ?? array() ) );
		$w_mm    = (float) ( $p['widthMm'] ?? 0 );
		$h_mm    = (float) ( $p['heightMm'] ?? 0 );
		if ( $w_mm <= 0 || $h_mm <= 0 ) {
			$w_mm = $size_cm * 10;
			$h_mm = $size_cm * 10;
		}
		$qty    = (int) $item->get_quantity();
		$t      = self::technique( $tech );
		$thread = ! empty( $t['threadBased'] );

		// Options per group.
		$groups = array();
		foreach ( $options as $oid ) {
			$opt = self::option( $oid );
			if ( ! $opt ) {
				continue;
			}
			if ( ! empty( $opt['requiresAny'] ) && ! array_intersect( (array) $opt['requiresAny'], $options ) ) {
				continue;
			}
			$groups[ (string) ( $opt['group'] ?? 'other' ) ][] = $opt;
		}
		$labels = static function ( string $g ) use ( $groups ): string {
			$out = array();
			foreach ( $groups[ $g ] ?? array() as $o ) {
				$out[] = self::label( $o['label'] ?? $o['id'] );
			}
			return implode( ', ', $out );
		};

		$edge_style = '';
		if ( ! empty( $groups['edge'][0]['render']['style'] ) ) {
			$edge_style = (string) $groups['edge'][0]['render']['style'];
		}

		/* ---------- Specs ---------- */
		$specs   = array();
		$specs[] = array(
			'label' => __( 'Techniek', 'borduur-patch-studio' ),
			'value' => self::technique_label( $tech ),
		);
		$specs[] = array(
			'label' => __( 'Maat', 'borduur-patch-studio' ),
			/* translators: 1: width mm, 2: height mm, 3: longest side in cm */
			'value' => sprintf( __( '%1$s × %2$s mm (langste zijde %3$s cm)', 'borduur-patch-studio' ), self::num( round( $w_mm ), 0 ), self::num( round( $h_mm ), 0 ), self::num( $size_cm ) ),
			'mono'  => true,
		);
		$specs[] = array(
			'label' => __( 'Vorm', 'borduur-patch-studio' ),
			'value' => self::shape_label( $shape ),
		);
		$specs[] = array(
			'label' => __( 'Aantal', 'borduur-patch-studio' ),
			/* translators: %s: quantity */
			'value' => sprintf( __( '%s stuks', 'borduur-patch-studio' ), number_format( $qty, 0, ',', '.' ) ),
		);
		if ( '' !== $labels( 'edge' ) ) {
			$specs[] = array(
				'label' => __( 'Rand', 'borduur-patch-studio' ),
				'value' => $labels( 'edge' ),
			);
		}
		$edge = isset( $p['edgeColor'] ) && is_array( $p['edgeColor'] ) ? $p['edgeColor'] : null;
		if ( $edge && ! empty( $edge['code'] ) && in_array( $edge_style, array( '', 'satin', 'merrow' ), true ) ) {
			$ti      = self::thread_info( (string) $edge['code'], (string) ( $edge['name'] ?? '' ), (string) ( $edge['hex'] ?? '' ) );
			$specs[] = array(
				'label' => __( 'Randkleur', 'borduur-patch-studio' ),
				'value' => $ti['name'] . ' · ' . self::brand() . ' ' . $ti['code'],
				'hex'   => $ti['hex'],
				'code'  => $ti['code'],
			);
		}
		if ( '' !== $labels( 'backing' ) ) {
			$specs[] = array(
				'label' => __( 'Achterkant', 'borduur-patch-studio' ),
				'value' => $labels( 'backing' ),
			);
		}
		$velcro = array_filter( array( $labels( 'velcro_color' ), $labels( 'backing_extra' ) ) );
		if ( $velcro ) {
			$specs[] = array(
				'label' => __( 'Klittenband', 'borduur-patch-studio' ),
				'value' => implode( ' · ', $velcro ),
			);
		}
		if ( '' !== $labels( 'thickness' ) ) {
			$specs[] = array(
				'label' => __( 'Dikte', 'borduur-patch-studio' ),
				'value' => $labels( 'thickness' ),
			);
		}
		if ( '' !== $labels( 'carrier' ) ) {
			$specs[] = array(
				'label' => __( 'Drager', 'borduur-patch-studio' ),
				'value' => $labels( 'carrier' ),
			);
		}
		$fabric = isset( $p['fabric'] ) && is_array( $p['fabric'] ) ? $p['fabric'] : array();
		$bg     = (string) ( $p['analysis']['background'] ?? '' );
		if ( $fabric ) {
			$fl = self::fabric_label( $fabric );
			if ( 'solid' === $bg || 'keep' === $bg ) {
				$fl .= ' — ' . __( 'achtergrond uit het ontwerp overgenomen', 'borduur-patch-studio' );
			}
			$specs[] = array(
				'label' => __( 'Stof / achtergrond', 'borduur-patch-studio' ),
				'value' => $fl,
				'hex'   => self::hex( $fabric['hex'] ?? '' ),
			);
		}
		$other = array_filter( array( $labels( 'extras' ), $labels( 'other' ) ) );
		if ( $other ) {
			$specs[] = array(
				'label' => __( 'Extra\'s', 'borduur-patch-studio' ),
				'value' => implode( ', ', $other ),
			);
		}

		/* ---------- Threads ---------- */
		$threads = array();
		foreach ( (array) ( $p['threads'] ?? array() ) as $th ) {
			if ( ! is_array( $th ) ) {
				continue;
			}
			$ti        = self::thread_info( (string) ( $th['code'] ?? '' ), (string) ( $th['name'] ?? '' ), (string) ( $th['hex'] ?? '' ) );
			$share     = isset( $th['share'] ) ? (float) $th['share'] : null;
			$pct       = null !== $share && $share > 0 ? self::num( $share * 100, $share < 0.01 ? 1 : 0 ) . ' %' : '';
			$threads[] = array(
				'label' => $ti['name'],
				'value' => trim( self::brand() . ' ' . $ti['code'] . ( '' !== $pct ? ' · ' . $pct : '' ) ),
				'hex'   => $ti['hex'],
				'code'  => $ti['code'],
				'share' => $pct,
				'mono'  => true,
			);
		}

		/* ---------- Text layers ---------- */
		$texts = array();
		foreach ( (array) ( $p['texts'] ?? array() ) as $tx ) {
			if ( ! is_array( $tx ) || '' === trim( (string) ( $tx['text'] ?? '' ) ) ) {
				continue;
			}
			$font    = self::font( (string) ( $tx['font'] ?? '' ) );
			$ti      = self::thread_info( (string) ( $tx['threadCode'] ?? '' ) );
			$parts   = array(
				$font ? self::label( $font['label'] ?? $font['id'] ) : (string) ( $tx['font'] ?? '' ),
				/* translators: %s: letter height in mm */
				sprintf( __( '%s mm hoog', 'borduur-patch-studio' ), self::num( (float) ( $tx['heightMm'] ?? 0 ) ) ),
				self::path_label( (string) ( $tx['path'] ?? 'straight' ) ),
				self::text_place( $tx ),
			);
			$parts[] = ( '' !== $ti['code'] && 'auto' !== $ti['code'] ) ? $ti['name'] . ' (' . self::brand() . ' ' . $ti['code'] . ')' : __( 'garenkleur automatisch', 'borduur-patch-studio' );
			$texts[] = array(
				'label' => '“' . (string) $tx['text'] . '”',
				'value' => implode( ' · ', array_filter( $parts ) ),
				'hex'   => $ti['hex'],
				'code'  => 'auto' !== $ti['code'] ? $ti['code'] : '',
			);
		}

		/* ---------- Production ---------- */
		$a    = isset( $p['analysis'] ) && is_array( $p['analysis'] ) ? $p['analysis'] : null;
		$prod = array();
		if ( $a && ! empty( $a['stitches'] ) && is_array( $a['stitches'] ) && $thread ) {
			$prod[] = array(
				'label' => __( 'Geschat steekaantal', 'borduur-patch-studio' ),
				/* translators: 1: min stitches, 2: max stitches */
				'value' => sprintf( __( '± %1$s – %2$s steken (schatting, per stuk)', 'borduur-patch-studio' ), number_format( (int) ( $a['stitches']['min'] ?? 0 ), 0, ',', '.' ), number_format( (int) ( $a['stitches']['max'] ?? 0 ), 0, ',', '.' ) ),
				'mono'  => true,
			);
		}
		if ( $threads ) {
			$prod[] = array(
				'label' => $thread ? __( 'Aantal garenkleuren', 'borduur-patch-studio' ) : __( 'Aantal kleuren', 'borduur-patch-studio' ),
				'value' => (string) count( $threads ) . ( $texts ? ' ' . __( '(inclusief tekst)', 'borduur-patch-studio' ) : '' ),
			);
		}
		$art = isset( $p['art'] ) && is_array( $p['art'] ) ? $p['art'] : null;
		if ( $art && ( 'text' !== ( $p['source']['kind'] ?? '' ) ) && ! empty( $p['source']['fileName'] ) ) {
			$prod[] = array(
				'label' => __( 'Plaatsing ontwerp', 'borduur-patch-studio' ),
				/* translators: 1: scale %, 2: x offset mm, 3: y offset mm, 4: rotation degrees */
				'value' => sprintf( __( 'schaal %1$s %% · verschoven %2$s / %3$s mm (x / y) · rotatie %4$s°', 'borduur-patch-studio' ), self::num( (float) ( $art['scale'] ?? 1 ) * 100, 0 ), self::num( (float) ( $art['offsetMm']['x'] ?? 0 ) ), self::num( (float) ( $art['offsetMm']['y'] ?? 0 ) ), (string) (int) ( $art['rotation'] ?? 0 ) ),
			);
		}

		/* ---------- Analysis ---------- */
		$an = array();
		if ( $a ) {
			$kinds = array(
				'flat'         => __( 'Vlak logo', 'borduur-patch-studio' ),
				'gradient'     => __( 'Logo met kleurverloop', 'borduur-patch-studio' ),
				'illustration' => __( 'Illustratie met tinten', 'borduur-patch-studio' ),
				'photo'        => __( 'Foto', 'borduur-patch-studio' ),
			);
			$kind  = (string) ( $a['kind'] ?? '' );
			$an[]  = array(
				'label' => __( 'Soort ontwerp', 'borduur-patch-studio' ),
				'value' => ( $kinds[ $kind ] ?? $kind ) . ( ! empty( $a['hasSoftShading'] ) ? ' · ' . __( 'met zachte schaduwen', 'borduur-patch-studio' ) : '' ),
			);
			$bgs   = array(
				'transparent' => __( 'transparant', 'borduur-patch-studio' ),
				'white'       => __( 'wit (weggelaten, stofkleur gekozen)', 'borduur-patch-studio' ),
				'solid'       => __( 'effen kleur (meegenomen als patchachtergrond)', 'borduur-patch-studio' ),
				'none'        => __( 'geen aparte achtergrond (ontwerp loopt tot de rand)', 'borduur-patch-studio' ),
			);
			if ( '' !== $bg ) {
				$an[] = array(
					'label' => __( 'Achtergrond bestand', 'borduur-patch-studio' ),
					'value' => $bgs[ $bg ] ?? $bg,
				);
			}
			if ( ! empty( $a['shape'] ) ) {
				$an[] = array(
					'label' => __( 'Vorm herkend', 'borduur-patch-studio' ),
					'value' => self::shape_label( (string) $a['shape'] ),
				);
			}
			if ( ! empty( $a['colors'] ) ) {
				$an[] = array(
					'label' => __( 'Kleuren in het bestand', 'borduur-patch-studio' ),
					'value' => (string) (int) $a['colors'],
				);
			}
			if ( isset( $a['fineLineMm'] ) && null !== $a['fineLineMm'] ) {
				$an[] = array(
					'label' => __( 'Fijnste lijn', 'borduur-patch-studio' ),
					/* translators: %s: mm */
					'value' => sprintf( __( '± %s mm', 'borduur-patch-studio' ), self::num( (float) $a['fineLineMm'] ) ),
					'mono'  => true,
				);
			}
			if ( isset( $a['minTextMm'] ) && null !== $a['minTextMm'] ) {
				$an[] = array(
					'label' => __( 'Kleinste tekst in het bestand', 'borduur-patch-studio' ),
					/* translators: %s: mm */
					'value' => sprintf( __( '± %s mm', 'borduur-patch-studio' ), self::num( (float) $a['minTextMm'] ) ),
					'mono'  => true,
				);
			}
		}

		/* ---------- Checks (attention points for the atelier) ---------- */
		$checks = self::checks( $tech, $t, $p, $a, $threads, $edge_style );

		/* ---------- Customer ---------- */
		$cust = array();
		$src  = isset( $p['source'] ) && is_array( $p['source'] ) ? $p['source'] : array();
		if ( ! empty( $src['fileName'] ) ) {
			$cust[] = array(
				'label' => __( 'Bestand van de klant', 'borduur-patch-studio' ),
				'value' => (string) $src['fileName'] . ( ! empty( $src['fileType'] ) ? ' (' . (string) $src['fileType'] . ')' : '' ),
			);
		} else {
			$cust[] = array(
				'label' => __( 'Bestand van de klant', 'borduur-patch-studio' ),
				'value' => __( 'Geen: patch met enkel tekst', 'borduur-patch-studio' ),
			);
		}
		if ( ! empty( $p['advice']['recommended'] ) ) {
			$rec_t  = (string) $p['advice']['recommended'];
			$words  = array(
				'ok'   => __( 'aanrader', 'borduur-patch-studio' ),
				'warn' => __( 'kan, met aanpassing', 'borduur-patch-studio' ),
				'bad'  => __( 'raden we af', 'borduur-patch-studio' ),
			);
			$status = (string) ( $p['advice']['chosenStatus'] ?? '' );
			$value  = sprintf(
				/* translators: 1: recommended technique, 2: chosen technique, 3: status word */
				__( 'Ons advies: %1$s · gekozen: %2$s (%3$s)', 'borduur-patch-studio' ),
				self::technique_label( $rec_t ),
				self::technique_label( $tech ),
				$words[ $status ] ?? $status
			);
			if ( ! empty( $p['advice']['headline'] ) ) {
				$value .= ' — ' . (string) $p['advice']['headline'];
			}
			$cust[] = array(
				'label' => __( 'Advies in de tool', 'borduur-patch-studio' ),
				'value' => $value,
				'level' => 'ok' === $status || '' === $status ? '' : ( 'bad' === $status ? 'bad' : 'warn' ),
			);
		}
		$rights = (string) $item->get_meta( 'bps_rights' );
		if ( '' !== $rights ) {
			$cust[] = array(
				'label' => __( 'Rechtenverklaring', 'borduur-patch-studio' ),
				'value' => $rights,
			);
		}

		$sections = array(
			'specs'      => array(
				'title' => __( 'Specificaties', 'borduur-patch-studio' ),
				'rows'  => $specs,
			),
			'threads'    => array(
				/* translators: %d: number of colours */
				'title' => $thread ? sprintf( __( 'Garenkleuren (%d)', 'borduur-patch-studio' ), count( $threads ) ) : sprintf( __( 'Kleuren (%d)', 'borduur-patch-studio' ), count( $threads ) ),
				'rows'  => $threads,
			),
			'texts'      => array(
				'title' => __( 'Tekstlagen', 'borduur-patch-studio' ),
				'rows'  => $texts,
			),
			'production' => array(
				'title' => __( 'Voor de digitaliseerder', 'borduur-patch-studio' ),
				'rows'  => $prod,
			),
			'checks'     => array(
				'title' => __( 'Aandachtspunten', 'borduur-patch-studio' ),
				'rows'  => $checks,
			),
			'analysis'   => array(
				'title' => __( 'Analyse van het bestand', 'borduur-patch-studio' ),
				'rows'  => $an,
			),
			'customer'   => array(
				'title' => __( 'Klant en advies', 'borduur-patch-studio' ),
				'rows'  => $cust,
			),
		);

		/**
		 * Sections of the production sheet (card + print). Each section: ['title' => string, 'rows' => [[
		 * 'label' => string, 'value' => string, 'hex' => '#rrggbb'?, 'code' => string?, 'level' => 'ok'|'warn'|'bad'?, 'mono' => bool? ]]].
		 *
		 * @param array                  $sections Sections keyed by id.
		 * @param array                  $design   DesignRecord (synthesized from the order snapshot if the design was removed).
		 * @param \WC_Order_Item_Product $item     Order line.
		 */
		$sections = (array) apply_filters( 'bps_fiche_sections', $sections, $rec, $item );
		$sections = array_filter(
			$sections,
			static function ( $s ) {
				return is_array( $s ) && ! empty( $s['rows'] );
			}
		);

		$original = null;
		if ( $d['exists'] && ! empty( $rec['original'] ) && class_exists( Designs::class ) ) {
			$original = array(
				'url'   => Designs::original_download_url( (int) $rec['id'] ),
				'name'  => (string) ( $rec['original']['name'] ?? '' ),
				'ext'   => strtoupper( (string) ( $rec['original']['ext'] ?? '' ) ),
				'bytes' => (int) ( $rec['original']['bytes'] ?? 0 ),
			);
		}

		$proof = self::proof_of( $rec, $item );

		$fiche = array(
			'item_id'       => (int) $item->get_id(),
			'order_id'      => (int) $item->get_order_id(),
			'design_id'     => (int) $rec['id'],
			'design_exists' => (bool) $d['exists'],
			'ref'           => '' !== (string) $rec['ref'] ? (string) $rec['ref'] : (string) $item->get_meta( 'bps_ref' ),
			'name'          => (string) $item->get_name(),
			'preview_url'   => (string) $rec['preview_url'],
			'technique'     => $tech,
			'technique_lbl' => self::technique_label( $tech ),
			'thread_based'  => $thread,
			'shape'         => $shape,
			'shape_lbl'     => self::shape_label( $shape ),
			'width_mm'      => $w_mm,
			'height_mm'     => $h_mm,
			'size_cm'       => $size_cm,
			'qty'           => $qty,
			'locale'        => (string) ( $p['locale'] ?? '' ),
			'notes'         => trim( (string) ( $p['notes'] ?? '' ) ),
			'sections'      => $sections,
			'checks_level'  => self::worst_level( $checks ),
			'original'      => $original,
			'file_removed'  => (string) $item->get_meta( 'bps_file_removed' ),
			'proof'         => $proof,
			'payload'       => $p,
		);
		self::set_lang( $prev );
		return $fiche;
	}

	/**
	 * Attention points for the atelier, from the technique's rules (config) and the stored analysis.
	 *
	 * @param string     $tech       Technique id.
	 * @param array      $t          Technique config.
	 * @param array      $p          Payload.
	 * @param array|null $a          Payload analysis.
	 * @param array      $threads    Thread rows.
	 * @param string     $edge_style Edge render style.
	 * @return array<int,array>
	 */
	private static function checks( string $tech, array $t, array $p, ?array $a, array $threads, string $edge_style ): array {
		$rules  = isset( $t['rules'] ) && is_array( $t['rules'] ) ? $t['rules'] : array();
		$tl     = self::technique_label( $tech );
		$thread = ! empty( $t['threadBased'] );
		$out    = array();
		$add    = static function ( string $level, string $label, string $value ) use ( &$out ) {
			$out[] = array(
				'label' => $label,
				'value' => $value,
				'level' => $level,
			);
		};

		$hard_text = isset( $rules['textCapsMm']['hard'] ) ? (float) $rules['textCapsMm']['hard'] : 0.0;
		$comf_text = isset( $rules['textCapsMm']['comfort'] ) ? (float) $rules['textCapsMm']['comfort'] : 0.0;
		if ( $a && isset( $a['minTextMm'] ) && null !== $a['minTextMm'] && $hard_text > 0 ) {
			$m = (float) $a['minTextMm'];
			if ( $m < $hard_text ) {
				/* translators: 1: measured mm, 2: minimum mm, 3: technique */
				$add( 'bad', __( 'Kleine tekst', 'borduur-patch-studio' ), sprintf( __( 'Kleinste tekst in het bestand ± %1$s mm, onder het minimum van %2$s mm voor %3$s. Vereenvoudigen of weglaten bij het digitaliseren, of overleggen met de klant.', 'borduur-patch-studio' ), self::num( $m ), self::num( $hard_text ), self::lower( $tl ) ) );
			} elseif ( $comf_text > 0 && $m < $comf_text ) {
				/* translators: 1: measured mm, 2: comfortable mm */
				$add( 'warn', __( 'Kleine tekst', 'borduur-patch-studio' ), sprintf( __( 'Kleinste tekst ± %1$s mm: haalbaar, maar onder de comfortgrens van %2$s mm. Extra zorg bij het digitaliseren.', 'borduur-patch-studio' ), self::num( $m ), self::num( $comf_text ) ) );
			}
		}

		foreach ( (array) ( $p['texts'] ?? array() ) as $tx ) {
			if ( ! is_array( $tx ) || $hard_text <= 0 ) {
				continue;
			}
			$font   = self::font( (string) ( $tx['font'] ?? '' ) );
			$factor = ( $font && ( ! empty( $font['thin'] ) || ! empty( $font['serif'] ) || ! empty( $font['script'] ) ) ) ? (float) ( $rules['serifScriptFactor'] ?? 1 ) : 1.0;
			$need   = $hard_text * max( 1.0, $factor );
			$h      = (float) ( $tx['heightMm'] ?? 0 );
			if ( $h > 0 && $h < $need ) {
				/* translators: 1: text, 2: height mm, 3: needed mm */
				$add( 'bad', __( 'Tekstlaag te klein', 'borduur-patch-studio' ), sprintf( __( '“%1$s” is %2$s mm hoog; met dit lettertype vragen we minstens %3$s mm.', 'borduur-patch-studio' ), (string) $tx['text'], self::num( $h ), self::num( $need ) ) );
			}
		}

		$hard_line = isset( $rules['lineMm']['hard'] ) ? (float) $rules['lineMm']['hard'] : 0.0;
		if ( $a && isset( $a['fineLineMm'] ) && null !== $a['fineLineMm'] && $hard_line > 0 && (float) $a['fineLineMm'] < $hard_line ) {
			$value = $thread
				/* translators: 1: measured mm, 2: minimum mm */
				? sprintf( __( 'Fijnste lijn ± %1$s mm (minimum %2$s mm): als loopsteek uitvoeren, verdikken of weglaten.', 'borduur-patch-studio' ), self::num( (float) $a['fineLineMm'] ), self::num( $hard_line ) )
				/* translators: 1: measured mm, 2: minimum mm */
				: sprintf( __( 'Fijnste lijn ± %1$s mm (minimum %2$s mm): controleer of die lijn zichtbaar blijft.', 'borduur-patch-studio' ), self::num( (float) $a['fineLineMm'] ), self::num( $hard_line ) );
			$add( 'warn', __( 'Dunne lijnen', 'borduur-patch-studio' ), $value );
		}

		$max  = isset( $rules['colors']['max'] ) && is_numeric( $rules['colors']['max'] ) ? (int) $rules['colors']['max'] : 0;
		$comf = isset( $rules['colors']['comfortMax'] ) && is_numeric( $rules['colors']['comfortMax'] ) ? (int) $rules['colors']['comfortMax'] : 0;
		$n    = count( $threads );
		if ( $max > 0 && $n > $max ) {
			/* translators: 1: number of colours, 2: max */
			$add( 'bad', __( 'Te veel kleuren', 'borduur-patch-studio' ), sprintf( __( '%1$d kleuren; maximum is %2$d. Vereenvoudigen vóór de proef.', 'borduur-patch-studio' ), $n, $max ) );
		} elseif ( $comf > 0 && $n > $comf ) {
			/* translators: 1: number of colours, 2: comfortable max */
			$add( 'warn', __( 'Veel kleuren', 'borduur-patch-studio' ), sprintf( __( '%1$d garenkleuren (comfortabel tot %2$d): reken op meer kleurwissels.', 'borduur-patch-studio' ), $n, $comf ) );
		}

		if ( $a && $thread ) {
			$kind = (string) ( $a['kind'] ?? '' );
			if ( 'photo' === $kind ) {
				$add( 'bad', __( 'Foto', 'borduur-patch-studio' ), __( 'Het bestand is een foto: borduren kan enkel als sterk vereenvoudigde versie. Bespreek dit in de proef.', 'borduur-patch-studio' ) );
			} elseif ( 'illustration' === $kind || ! empty( $a['hasSoftShading'] ) ) {
				/* translators: %d: number of thread colours */
				$add( 'warn', __( 'Vereenvoudigen', 'borduur-patch-studio' ), $n > 0 ? sprintf( __( 'Illustratie met tinten: schaduwen en verlopen omzetten naar vlakken in %d garenkleuren (zoals de klant in het voorbeeld zag).', 'borduur-patch-studio' ), $n ) : __( 'Illustratie met tinten: schaduwen en verlopen omzetten naar vlakken.', 'borduur-patch-studio' ) );
			} elseif ( 'gradient' === $kind ) {
				$add( 'warn', __( 'Kleurverloop', 'borduur-patch-studio' ), __( 'Kleurverloop in het ontwerp: uitvoeren in 2 tot 4 kleurbanden.', 'borduur-patch-studio' ) );
			}
		}

		$warn_text = array(
			'low_res'             => __( 'Lage resolutie voor deze maat: vraag eventueel een groter of origineel bestand.', 'borduur-patch-studio' ),
			'transparent_shadow'  => __( 'Transparante schaduw in het bestand: die zachte schaduw wordt niet overgenomen.', 'borduur-patch-studio' ),
			'mockup'              => __( 'De upload is een afbeelding van een patch (mock-up), geen origineel ontwerpbestand. Vraag het origineel als dat er is.', 'borduur-patch-studio' ),
			'patch_photo_cut'     => __( 'Foto van een bestaande patch, automatisch uitgeknipt: rand en details zelf nakijken.', 'borduur-patch-studio' ),
			'nearly_empty'        => __( 'Het bestand is bijna leeg: controleer of het juiste bestand werd geüpload.', 'borduur-patch-studio' ),
			'white_on_transparent' => __( 'Witte elementen op een transparante achtergrond: controleer wat wit moet blijven.', 'borduur-patch-studio' ),
			'shape_uncertain'     => __( 'Vorm niet met zekerheid herkend: controleer de gekozen vorm.', 'borduur-patch-studio' ),
			'svg_gradient'        => __( 'SVG met kleurverlopen.', 'borduur-patch-studio' ),
			'svg_embedded_raster' => __( 'SVG met een ingesloten pixelafbeelding (geen echte vector).', 'borduur-patch-studio' ),
		);
		if ( $a ) {
			foreach ( array_unique( array_map( 'strval', (array) ( $a['warnings'] ?? array() ) ) ) as $code ) {
				if ( '' === $code ) {
					continue;
				}
				$add( 'warn', __( 'Bestand', 'borduur-patch-studio' ), $warn_text[ $code ] ?? $code );
			}
		} elseif ( ! empty( $p['source']['fileName'] ) ) {
			$add( 'warn', __( 'Bestand', 'borduur-patch-studio' ), __( 'Dit bestandstype werd niet automatisch geanalyseerd (bv. PDF, AI, EPS of PSD): kleuren, lijnen en tekst zelf nakijken.', 'borduur-patch-studio' ) );
		}

		$status = (string) ( $p['advice']['chosenStatus'] ?? '' );
		$rec    = (string) ( $p['advice']['recommended'] ?? '' );
		if ( in_array( $status, array( 'warn', 'bad' ), true ) ) {
			$add(
				'bad' === $status ? 'bad' : 'warn',
				__( 'Keuze klant', 'borduur-patch-studio' ),
				'' !== $rec && $rec !== $tech
					/* translators: 1: chosen technique, 2: recommended technique */
					? sprintf( __( 'De klant koos %1$s, terwijl de tool %2$s aanraadde. Bekijk het ontwerp extra goed vóór je de proef maakt.', 'borduur-patch-studio' ), self::lower( $tl ), self::lower( self::technique_label( $rec ) ) )
					: __( 'De tool gaf voor deze keuze een waarschuwing. Bekijk het ontwerp extra goed vóór je de proef maakt.', 'borduur-patch-studio' )
			);
		}

		if ( 'uncut' === $edge_style ) {
			$add( 'warn', __( 'Niet uitsnijden', 'borduur-patch-studio' ), __( 'De klant knipt zelf: leveren op een stuk stof, niet uitsnijden.', 'borduur-patch-studio' ) );
		}
		if ( 'merrow' === $edge_style && ! in_array( (string) ( $p['shape'] ?? '' ), array( 'circle', 'oval', 'rect', 'rounded' ), true ) ) {
			$add( 'warn', __( 'Merrow-rand', 'borduur-patch-studio' ), __( 'Merrow (omgezoomde rand) gaat niet rond scherpe binnenhoeken: vorm nakijken.', 'borduur-patch-studio' ) );
		}

		return $out;
	}

	/**
	 * Worst level of a list of rows ('', 'warn', 'bad').
	 *
	 * @param array $rows Rows.
	 */
	private static function worst_level( array $rows ): string {
		$w = '';
		foreach ( $rows as $r ) {
			$l = (string) ( $r['level'] ?? '' );
			if ( 'bad' === $l ) {
				return 'bad';
			}
			if ( 'warn' === $l ) {
				$w = 'warn';
			}
		}
		return $w;
	}

	/* =====================================================================
	 * Proof state
	 * =================================================================== */

	/**
	 * Normalized proof data for a design record.
	 *
	 * @param array                       $rec  DesignRecord.
	 * @param \WC_Order_Item_Product|null $item Item.
	 */
	public static function proof_of( array $rec, $item = null ): array {
		$p      = isset( $rec['proof'] ) && is_array( $rec['proof'] ) ? $rec['proof'] : array();
		$status = (string) ( $p['status'] ?? 'none' );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'none';
		}
		return array(
			'status'       => $status,
			'round'        => (int) ( $p['round'] ?? 0 ),
			'sent_at'      => (string) ( $p['sent_at'] ?? '' ),
			'responded_at' => (string) ( $p['responded_at'] ?? '' ),
			'note'         => (string) ( $p['note'] ?? '' ),
			'image_url'    => (string) ( $p['image_url'] ?? '' ),
			'recipient'    => (string) ( $p['recipient'] ?? '' ),
			'order_id'     => (int) ( $p['order_id'] ?? 0 ),
			'item_id'      => (int) ( $p['item_id'] ?? 0 ),
			'history'      => isset( $p['history'] ) && is_array( $p['history'] ) ? array_values( $p['history'] ) : array(),
		);
	}

	/**
	 * Proof token (SPEC §6.6): HMAC over "proof|{designId}|{orderId}|{round}".
	 *
	 * @param int $design_id Design id.
	 * @param int $order_id  Order id.
	 * @param int $round     Proof round (1-based).
	 */
	public static function proof_token( int $design_id, int $order_id, int $round ): string {
		return hash_hmac( 'sha256', 'proof|' . $design_id . '|' . $order_id . '|' . $round, wp_salt( 'auth' ) );
	}

	/**
	 * Customer link for a proof answer.
	 *
	 * @param int    $design_id Design id.
	 * @param int    $order_id  Order id.
	 * @param int    $round     Round.
	 * @param string $action    approve|changes.
	 */
	public static function proof_url( int $design_id, int $order_id, int $round, string $action ): string {
		return add_query_arg(
			array(
				'bps_proof' => $design_id,
				'o'         => $order_id,
				't'         => self::proof_token( $design_id, $order_id, $round ),
				'a'         => 'changes' === $action ? 'changes' : 'approve',
			),
			home_url( '/' )
		);
	}

	/**
	 * Human date (site timezone) for an ISO/GMT timestamp.
	 *
	 * @param string $iso Timestamp.
	 */
	public static function date_text( string $iso ): string {
		$ts = '' !== $iso ? strtotime( $iso ) : false;
		if ( ! $ts ) {
			return '';
		}
		/* translators: 1: date, 2: time */
		return sprintf( __( '%1$s om %2$s', 'borduur-patch-studio' ), wp_date( 'd-m-Y', $ts ), wp_date( 'H:i', $ts ) );
	}

	/**
	 * Admin label for a proof status.
	 *
	 * @param string $status Status.
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case 'sent':
				return __( 'Proef verstuurd, wacht op antwoord', 'borduur-patch-studio' );
			case 'approved':
				return __( 'Proef goedgekeurd', 'borduur-patch-studio' );
			case 'changes_requested':
				return __( 'Klant vraagt een aanpassing', 'borduur-patch-studio' );
			default:
				return __( 'Nog geen proef verstuurd', 'borduur-patch-studio' );
		}
	}

	/**
	 * Mirror the proof status on the order line (orders list column, HPOS-safe CRUD).
	 *
	 * @param int    $order_id Order id.
	 * @param int    $item_id  Item id.
	 * @param string $status   Status.
	 */
	private static function mirror_status( int $order_id, int $item_id, string $status ): void {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$item = $order->get_item( $item_id );
		if ( $item instanceof \WC_Order_Item_Product ) {
			$item->update_meta_data( self::ITEM_PROOF_META, $status );
			$item->save();
		}
	}

	/* =====================================================================
	 * Order screen
	 * =================================================================== */

	/**
	 * Order edit screen ids (HPOS + legacy).
	 */
	private static function order_screens(): array {
		$ids = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$ids[] = wc_get_page_screen_id( 'shop-order' );
		}
		$ids[] = 'woocommerce_page_wc-orders';
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Order object from the metabox argument (WP_Post on legacy, WC_Order on HPOS).
	 *
	 * @param mixed $obj Post or order.
	 * @return \WC_Order|null
	 */
	private static function order_from( $obj ) {
		if ( $obj instanceof \WC_Order ) {
			return $obj;
		}
		if ( $obj instanceof \WP_Post && function_exists( 'wc_get_order' ) ) {
			$o = wc_get_order( $obj->ID );
			return $o instanceof \WC_Order ? $o : null;
		}
		return null;
	}

	/**
	 * Add the production metabox when the order has Patch Studio lines.
	 *
	 * @param string $post_type Post type or screen id.
	 * @param mixed  $obj       Post or order.
	 */
	public static function add_meta_boxes( $post_type = '', $obj = null ): void {
		$order = self::order_from( $obj );
		if ( ! $order || ! self::design_items( $order ) ) {
			return;
		}
		foreach ( self::order_screens() as $screen ) {
			add_meta_box(
				'bps-production',
				__( 'Patch Studio · productiefiche', 'borduur-patch-studio' ),
				array( __CLASS__, 'render_metabox' ),
				$screen,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Metabox content: one card per patch line + proof dialog.
	 *
	 * @param mixed $obj Post or order.
	 */
	public static function render_metabox( $obj ): void {
		$order = self::order_from( $obj );
		if ( ! $order ) {
			return;
		}
		$fiches = array();
		foreach ( self::design_items( $order ) as $item ) {
			$fiches[] = self::fiche( $item );
		}
		$can = current_user_can( 'edit_shop_orders' );
		self::template(
			'admin/order-metabox.php',
			array(
				'order'      => $order,
				'fiches'     => $fiches,
				'print_url'  => self::print_url( (int) $order->get_id() ),
				'can_proof'  => $can,
				'admin_data' => self::admin_data( $order, $fiches ),
			)
		);
	}

	/**
	 * Data for the admin script (JSON in the metabox; no extra globals).
	 *
	 * @param \WC_Order $order  Order.
	 * @param array     $fiches Fiches.
	 */
	private static function admin_data( $order, array $fiches ): array {
		$items = array();
		foreach ( $fiches as $f ) {
			$lang                          = self::lang_of( $f['locale'] );
			$first                         = trim( (string) $order->get_billing_first_name() );
			$items[ (string) $f['item_id'] ] = array(
				'ref'        => $f['ref'],
				'designId'   => $f['design_id'],
				'exists'     => $f['design_exists'],
				'preview'    => $f['preview_url'],
				'lang'       => $lang,
				'defaultMsg' => '' !== $first
					/* translators: %1$s: customer first name */
					? sprintf( self::l( __( 'Hallo %1$s, hier is de digitale proef van je patch. Kijk je alles even na? Klopt alles, dan starten we meteen met de productie.', 'borduur-patch-studio' ), $lang ), $first )
					: self::l( __( 'Hier is de digitale proef van je patch. Kijk je alles even na? Klopt alles, dan starten we meteen met de productie.', 'borduur-patch-studio' ), $lang ),
				'round'      => (int) $f['proof']['round'],
			);
		}
		return array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::NONCE ),
			'orderId'   => (int) $order->get_id(),
			'recipient' => (string) $order->get_billing_email(),
			'items'     => $items,
			'i18n'      => array(
				'sending'      => __( 'Bezig met versturen…', 'borduur-patch-studio' ),
				'saving'       => __( 'Bezig met opslaan…', 'borduur-patch-studio' ),
				'sent'         => __( 'De digitale proef is verstuurd.', 'borduur-patch-studio' ),
				'saved'        => __( 'Status bijgewerkt.', 'borduur-patch-studio' ),
				'error'        => __( 'Er ging iets mis. Probeer het opnieuw of herlaad de pagina.', 'borduur-patch-studio' ),
				'chooseImage'  => __( 'Kies een proefafbeelding', 'borduur-patch-studio' ),
				'useImage'     => __( 'Gebruik deze afbeelding', 'borduur-patch-studio' ),
				'copied'       => __( 'Gekopieerd', 'borduur-patch-studio' ),
				'copyFailed'   => __( 'Kopiëren lukte niet', 'borduur-patch-studio' ),
				'invalidEmail' => __( 'Vul een geldig e-mailadres in.', 'borduur-patch-studio' ),
				/* translators: %d: proof round */
				'roundN'       => __( 'Dit wordt proefronde %d.', 'borduur-patch-studio' ),
				'langFr'       => __( 'De e-mail wordt in het Frans verstuurd (taal van het ontwerp).', 'borduur-patch-studio' ),
				'langNl'       => __( 'De e-mail wordt in het Nederlands verstuurd (taal van het ontwerp).', 'borduur-patch-studio' ),
			),
		);
	}

	/**
	 * Hide our technical line-item meta in the admin items table (the card shows it readable).
	 *
	 * @param array $keys Hidden keys.
	 */
	public static function hidden_itemmeta( $keys ) {
		return array_values(
			array_unique(
				array_merge(
					is_array( $keys ) ? $keys : array(),
					array( '_bps_design_id', '_bps_preview_url', '_bps_schema', '_bps_technique_id', '_bps_size_cm', '_bps_shape_id', '_bps_options', '_bps_unit_excl', '_bps_payload', '_bps_rights_consent', self::ITEM_PROOF_META )
				)
			)
		);
	}

	/**
	 * Small pointer under each patch line in the items table.
	 *
	 * @param int   $item_id Item id.
	 * @param mixed $item    Item.
	 * @param mixed $product Product.
	 */
	public static function after_order_itemmeta( $item_id, $item = null, $product = null ): void {
		if ( ! is_admin() || ! $item instanceof \WC_Order_Item_Product || (int) $item->get_meta( '_bps_design_id' ) <= 0 && '' === (string) $item->get_meta( '_bps_payload' ) ) {
			return;
		}
		$status = (string) ( $item->get_meta( self::ITEM_PROOF_META ) ?: 'none' );
		printf(
			'<p class="bps-itemlink"><a href="#bps-fiche-%1$d">%2$s</a> · <span class="bps-proof-pill bps-proof-pill--%3$s">%4$s</span></p>',
			(int) $item_id,
			esc_html__( 'Productiefiche bekijken', 'borduur-patch-studio' ),
			esc_attr( $status ),
			esc_html( self::status_label( $status ) )
		);
	}

	/**
	 * Enqueue admin assets on the order screen.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue( $hook = '' ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, self::order_screens(), true ) ) {
			return;
		}
		self::ensure_assets_registered();
		wp_enqueue_script( 'bps-admin' );
		if ( wp_style_is( 'bps-admin', 'registered' ) ) {
			wp_enqueue_style( 'bps-admin' );
		}
		if ( function_exists( 'wp_enqueue_media' ) && current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Register bps-admin ourselves when core did not (e.g. the admin CSS was built after core registered).
	 */
	private static function ensure_assets_registered(): void {
		$base = defined( 'BPS_URL' ) ? BPS_URL : plugin_dir_url( dirname( __DIR__ ) . '/borduur-patch-studio.php' );
		$dir  = defined( 'BPS_DIR' ) ? BPS_DIR : dirname( __DIR__, 2 ) . '/';
		$ver  = defined( 'BPS_VERSION' ) ? BPS_VERSION : '1.0.0';
		if ( ! wp_script_is( 'bps-admin', 'registered' ) ) {
			$f = 'assets/dist/patch-studio-admin.js';
			wp_register_script( 'bps-admin', $base . $f, array(), $ver . '-' . ( is_readable( $dir . $f ) ? filemtime( $dir . $f ) : '0' ), array( 'in_footer' => true ) );
		}
		if ( ! wp_style_is( 'bps-admin', 'registered' ) && is_readable( $dir . 'assets/dist/patch-studio-admin.css' ) ) {
			$f = 'assets/dist/patch-studio-admin.css';
			wp_register_style( 'bps-admin', $base . $f, array(), $ver . '-' . filemtime( $dir . $f ) );
		}
	}

	/**
	 * Render one of our templates (templates/<name>) with variables.
	 *
	 * @param string $name Relative template path.
	 * @param array  $args Variables.
	 */
	public static function template( string $name, array $args = array() ): void {
		$dir  = defined( 'BPS_DIR' ) ? BPS_DIR : dirname( __DIR__, 2 ) . '/';
		$file = $dir . 'templates/' . ltrim( $name, '/' );
		if ( ! is_readable( $file ) ) {
			return;
		}
		( static function ( string $__file, array $__args ) {
			extract( $__args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $__file;
		} )( $file, $args );
	}

	/**
	 * Template as string.
	 *
	 * @param string $name Relative template path.
	 * @param array  $args Variables.
	 */
	public static function template_html( string $name, array $args = array() ): string {
		ob_start();
		self::template( $name, $args );
		return (string) ob_get_clean();
	}

	/**
	 * Proof status block HTML for a fiche (also returned by the AJAX endpoints).
	 *
	 * @param array $fiche Fiche.
	 */
	public static function proof_block_html( array $fiche ): string {
		return self::template_html(
			'admin/proof-status.php',
			array(
				'fiche'     => $fiche,
				'can_proof' => current_user_can( 'edit_shop_orders' ),
			)
		);
	}

	/* =====================================================================
	 * Printable sheet
	 * =================================================================== */

	/**
	 * URL of the printable sheet.
	 *
	 * @param int $order_id Order id.
	 * @param int $item_id  Optional single item.
	 */
	public static function print_url( int $order_id, int $item_id = 0 ): string {
		$args = array(
			'action' => 'bps_print_fiche',
			'order'  => $order_id,
		);
		if ( $item_id > 0 ) {
			$args['item'] = $item_id;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'bps_print_fiche_' . $order_id );
	}

	/**
	 * admin-post handler: printable A4 sheet (one patch per page).
	 */
	public static function handle_print(): void {
		$order_id = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze productiefiche.', 'borduur-patch-studio' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bps_print_fiche_' . $order_id );
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Bestelling niet gevonden.', 'borduur-patch-studio' ), '', array( 'response' => 404 ) );
		}
		$only   = isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$fiches = array();
		foreach ( self::design_items( $order ) as $item_id => $item ) {
			if ( $only > 0 && $only !== (int) $item_id ) {
				continue;
			}
			$fiches[] = self::fiche( $item );
		}
		if ( ! $fiches ) {
			wp_die( esc_html__( 'Deze bestelling bevat geen Patch Studio-patches.', 'borduur-patch-studio' ), '', array( 'response' => 404 ) );
		}
		self::ensure_assets_registered();
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$order_url = method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		$scripts   = wp_scripts();
		$js        = isset( $scripts->registered['bps-admin'] ) ? $scripts->registered['bps-admin'] : null;
		$js_url    = $js ? add_query_arg( 'ver', $js->ver, $js->src ) : '';
		self::template(
			'admin/fiche-print.php',
			array(
				'order'     => $order,
				'fiches'    => $fiches,
				'order_url' => $order_url,
				'js_url'    => $js_url,
				'site_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'autoprint' => ! empty( $_GET['autoprint'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			)
		);
		exit;
	}

	/* =====================================================================
	 * Proof: send (AJAX) and manual status
	 * =================================================================== */

	/**
	 * Validate the AJAX request and return [order, item].
	 *
	 * @return array{0:\WC_Order,1:\WC_Order_Item_Product}
	 */
	private static function ajax_context(): array {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Je sessie is verlopen. Herlaad de pagina en probeer opnieuw.', 'borduur-patch-studio' ) ), 403 );
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Je hebt geen rechten om bestellingen te bewerken.', 'borduur-patch-studio' ) ), 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$item_id  = isset( $_POST['item_id'] ) ? absint( wp_unslash( $_POST['item_id'] ) ) : 0;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		$items    = $order instanceof \WC_Order ? self::design_items( $order ) : array();
		if ( ! isset( $items[ $item_id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Deze patch hoort niet bij deze bestelling.', 'borduur-patch-studio' ) ), 404 );
		}
		return array( $order, $items[ $item_id ] );
	}

	/**
	 * AJAX: send the digital proof e-mail.
	 */
	public static function ajax_send_proof(): void {
		list( $order, $item ) = self::ajax_context();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in ajax_context().
		$message   = isset( $_POST['message'] ) ? self::clean_text( wp_unslash( $_POST['message'] ) ) : '';
		$image_id  = isset( $_POST['image_id'] ) ? absint( wp_unslash( $_POST['image_id'] ) ) : 0;
		$recipient = isset( $_POST['recipient'] ) ? sanitize_email( wp_unslash( $_POST['recipient'] ) ) : '';
		// phpcs:enable

		$result = self::send_proof( $order, $item, $message, $image_id, $recipient );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		$fiche = self::fiche( $item );
		wp_send_json_success(
			array(
				/* translators: 1: proof round, 2: e-mail address */
				'message' => sprintf( __( 'Proefronde %1$d is verstuurd naar %2$s.', 'borduur-patch-studio' ), (int) $result['round'], $result['recipient'] ),
				'html'    => self::proof_block_html( $fiche ),
				'status'  => $fiche['proof']['status'],
				'label'   => self::status_label( $fiche['proof']['status'] ),
				'round'   => (int) $fiche['proof']['round'],
			)
		);
	}

	/**
	 * Send a proof for an order line (also usable from code).
	 *
	 * @param \WC_Order              $order     Order.
	 * @param \WC_Order_Item_Product $item      Item.
	 * @param string                 $message   Message for the customer.
	 * @param int                    $image_id  Attachment id of a custom proof image (0 = preview PNG).
	 * @param string                 $recipient E-mail (default billing e-mail).
	 * @return array{round:int, recipient:string}|\WP_Error
	 */
	public static function send_proof( $order, $item, string $message = '', int $image_id = 0, string $recipient = '' ) {
		$d = self::design_for_item( $item );
		if ( ! $d['exists'] || ! class_exists( Designs::class ) ) {
			return new \WP_Error( 'bps_no_design', __( 'Het ontwerp van deze patch bestaat niet meer (opgeruimd). Stuur de proef manueel.', 'borduur-patch-studio' ) );
		}
		$rec       = $d['record'];
		$recipient = '' !== $recipient ? $recipient : (string) $order->get_billing_email();
		if ( ! is_email( $recipient ) ) {
			return new \WP_Error( 'bps_no_email', __( 'Deze bestelling heeft geen geldig e-mailadres. Vul een ontvanger in.', 'borduur-patch-studio' ) );
		}
		$image_url = (string) $rec['preview_url'];
		if ( $image_id > 0 ) {
			if ( ! wp_attachment_is_image( $image_id ) ) {
				return new \WP_Error( 'bps_bad_image', __( 'Het gekozen bestand is geen afbeelding.', 'borduur-patch-studio' ) );
			}
			$image_url = (string) wp_get_attachment_url( $image_id );
		}
		if ( '' === $image_url ) {
			return new \WP_Error( 'bps_no_image', __( 'Er is geen proefafbeelding. Kies een afbeelding uit de mediabibliotheek.', 'borduur-patch-studio' ) );
		}

		$proof = self::proof_of( $rec );
		$round = $proof['round'] + 1;
		$email = self::email( 'BPS_Email_Proof' );
		if ( ! $email ) {
			return new \WP_Error( 'bps_no_mailer', __( 'De e-mail "Patch Studio: digitale proef" is niet beschikbaar.', 'borduur-patch-studio' ) );
		}
		if ( ! $email->is_enabled() ) {
			return new \WP_Error( 'bps_mail_disabled', __( 'De e-mail "Patch Studio: digitale proef" staat uit onder WooCommerce › Instellingen › E-mails.', 'borduur-patch-studio' ) );
		}
		$sent = $email->trigger( $rec, $order, $item, $round, $image_url, $message, $recipient );
		if ( ! $sent ) {
			return new \WP_Error( 'bps_mail_failed', __( 'De e-mail kon niet verstuurd worden. Controleer de e-mailinstellingen van de website.', 'borduur-patch-studio' ) );
		}

		$now  = gmdate( 'c' );
		$user = wp_get_current_user();
		$by   = $user && $user->exists() ? $user->display_name : 'system';
		Designs::update_proof(
			(int) $rec['id'],
			array(
				'status'       => 'sent',
				'round'        => $round,
				'sent_at'      => $now,
				'responded_at' => '',
				'note'         => '',
				'image_url'    => $image_url,
				'message'      => $message,
				'recipient'    => $recipient,
				'order_id'     => (int) $order->get_id(),
				'item_id'      => (int) $item->get_id(),
				'history_add'  => array(
					'at'     => $now,
					'action' => 'sent',
					'by'     => $by,
					'note'   => $message,
					'round'  => $round,
				),
			)
		);
		self::mirror_status( (int) $order->get_id(), (int) $item->get_id(), 'sent' );
		$order = wc_get_order( $order->get_id() );
		if ( $order instanceof \WC_Order ) {
			$order->add_order_note(
				/* translators: 1: round, 2: design ref, 3: e-mail */
				sprintf( __( 'Digitale proef (ronde %1$d) voor patch %2$s verstuurd naar %3$s.', 'borduur-patch-studio' ), $round, $rec['ref'], $recipient ),
				0,
				true
			);
		}

		/**
		 * A digital proof was sent.
		 *
		 * @param int $design_id Design id.
		 * @param int $order_id  Order id.
		 */
		do_action( 'bps_proof_sent', (int) $rec['id'], (int) $order->get_id() );

		return array(
			'round'     => $round,
			'recipient' => $recipient,
		);
	}

	/**
	 * AJAX: set the proof status manually (e.g. approved by phone).
	 */
	public static function ajax_set_status(): void {
		list( $order, $item ) = self::ajax_context();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in ajax_context().
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? self::clean_text( wp_unslash( $_POST['note'] ) ) : '';
		// phpcs:enable
		if ( ! in_array( $status, array( 'approved', 'changes_requested', 'none' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Onbekende status.', 'borduur-patch-studio' ) ), 400 );
		}
		$d = self::design_for_item( $item );
		if ( ! $d['exists'] ) {
			wp_send_json_error( array( 'message' => __( 'Het ontwerp van deze patch bestaat niet meer.', 'borduur-patch-studio' ) ), 400 );
		}
		$rec  = $d['record'];
		$now  = gmdate( 'c' );
		$user = wp_get_current_user();
		$by   = $user && $user->exists() ? $user->display_name : 'admin';
		Designs::update_proof(
			(int) $rec['id'],
			array(
				'status'       => $status,
				'responded_at' => 'none' === $status ? '' : $now,
				'note'         => $note,
				'order_id'     => (int) $order->get_id(),
				'item_id'      => (int) $item->get_id(),
				'history_add'  => array(
					'at'     => $now,
					'action' => 'manual_' . $status,
					'by'     => $by,
					'note'   => $note,
					'round'  => self::proof_of( $rec )['round'],
				),
			)
		);
		self::mirror_status( (int) $order->get_id(), (int) $item->get_id(), $status );
		$order->add_order_note(
			/* translators: 1: design ref, 2: status, 3: user, 4: note */
			trim( sprintf( __( 'Proefstatus van patch %1$s manueel gezet op "%2$s" door %3$s. %4$s', 'borduur-patch-studio' ), $rec['ref'], self::status_label( $status ), $by, $note ) ),
			0,
			true
		);
		if ( in_array( $status, array( 'approved', 'changes_requested' ), true ) ) {
			/** This action is documented in handle_proof_response(). */
			do_action( 'bps_proof_response', (int) $rec['id'], 'approved' === $status ? 'approved' : 'changes', $note );
		}
		$fiche = self::fiche( $item );
		wp_send_json_success(
			array(
				'message' => __( 'Status bijgewerkt.', 'borduur-patch-studio' ),
				'html'    => self::proof_block_html( $fiche ),
				'status'  => $status,
				'label'   => self::status_label( $status ),
			)
		);
	}

	/**
	 * Sanitize free text (keeps line breaks), max MAX_TEXT characters.
	 *
	 * @param mixed $text Raw text.
	 */
	private static function clean_text( $text ): string {
		$text = sanitize_textarea_field( is_string( $text ) ? $text : '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_TEXT ) : substr( $text, 0, self::MAX_TEXT );
	}

	/* =====================================================================
	 * Proof: customer response page
	 * =================================================================== */

	/**
	 * Handle ?bps_proof=… (GET: confirmation page; POST: record the answer).
	 */
	public static function handle_proof_response(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- the HMAC token in the link is the authorisation (customer is not logged in).
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['bps_proof'] );
		$src     = $is_post ? $_POST : $_GET;
		if ( ! isset( $src['bps_proof'] ) ) {
			return;
		}
		$design_id = absint( wp_unslash( $src['bps_proof'] ) );
		$order_id  = isset( $src['o'] ) ? absint( wp_unslash( $src['o'] ) ) : 0;
		$token     = isset( $src['t'] ) ? strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) wp_unslash( $src['t'] ) ) ) : '';
		$action    = ( isset( $src['a'] ) && 'changes' === $src['a'] ) ? 'changes' : 'approve';
		$note_raw  = isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '';
		// phpcs:enable

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );

		$rec   = ( $design_id > 0 && class_exists( Designs::class ) ) ? Designs::get( $design_id ) : null;
		$proof = $rec ? self::proof_of( $rec ) : null;
		$state = 'invalid';
		if ( $rec && $proof['round'] > 0 && 64 === strlen( $token ) ) {
			$p_order = $proof['order_id'] > 0 ? $proof['order_id'] : (int) $rec['order_id'];
			if ( $p_order === $order_id && hash_equals( self::proof_token( $design_id, $order_id, $proof['round'] ), $token ) ) {
				$state = 'current';
			} else {
				for ( $r = $proof['round'] - 1; $r >= 1; $r-- ) {
					if ( hash_equals( self::proof_token( $design_id, $order_id, $r ), $token ) ) {
						$state = 'superseded';
						break;
					}
				}
			}
		}

		$lang = self::lang_of( $rec ? (string) ( $rec['payload']['locale'] ?? '' ) : '' );
		$wp_l = 'fr' === $lang ? 'fr_BE' : 'nl_BE';
		$sw   = function_exists( 'switch_to_locale' ) && $wp_l !== get_locale() ? switch_to_locale( $wp_l ) : false;
		$prev = self::set_lang( $lang );

		$error = '';
		$view  = 'invalid';
		if ( 'superseded' === $state ) {
			$view = 'superseded';
		} elseif ( 'current' === $state ) {
			if ( 'sent' !== $proof['status'] ) {
				$view = 'answered';
			} elseif ( $is_post ) {
				$note = self::clean_text( $note_raw );
				if ( 'changes' === $action && '' === trim( $note ) ) {
					$error = self::l( __( 'Laat ons even weten wat je wil aanpassen.', 'borduur-patch-studio' ) );
					$view  = 'confirm';
				} else {
					self::record_response( $rec, $order_id, $action, $note );
					$rec   = Designs::get( $design_id );
					$proof = self::proof_of( $rec );
					$view  = 'done';
				}
			} else {
				$view = 'confirm';
			}
		}

		if ( 'invalid' === $view ) {
			status_header( 403 );
			// Without a valid token we reveal nothing: no design ref, order number, proof image or details.
			$rec   = null;
			$proof = null;
		}
		$order = ( 'invalid' !== $view && $order_id > 0 ) ? wc_get_order( $order_id ) : null;
		$item  = ( $order instanceof \WC_Order && $proof && $proof['item_id'] > 0 ) ? $order->get_item( $proof['item_id'] ) : null;

		self::template(
			'admin/proof-response.php',
			array(
				'view'      => $view,
				'action'    => $action,
				'error'     => $error,
				'lang'      => $lang,
				'design'    => $rec,
				'proof'     => $proof,
				'order'     => $order instanceof \WC_Order ? $order : null,
				'rows'      => ( $rec && $item instanceof \WC_Order_Item_Product && in_array( $view, array( 'confirm', 'done', 'answered' ), true ) ) ? self::customer_rows( $item, $lang ) : array(),
				'form'      => array(
					'url'    => home_url( '/' ),
					'fields' => array(
						'bps_proof' => $design_id,
						'o'         => $order_id,
						't'         => $token,
						'a'         => $action,
					),
				),
				'other_url' => ( 'current' === $state ) ? self::proof_url( $design_id, $order_id, (int) $proof['round'], 'changes' === $action ? 'approve' : 'changes' ) : '',
				'contact'   => (array) self::cfg( 'contact', array() ),
				'note'      => is_string( $note_raw ) ? self::clean_text( $note_raw ) : '',
				'use_theme' => (bool) apply_filters( 'bps_proof_page_use_theme', ! ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ),
			)
		);

		self::set_lang( $prev );
		if ( $sw ) {
			restore_previous_locale();
		}
		exit;
	}

	/**
	 * Store a customer answer: design proof meta, order note, shop mail, action hook.
	 *
	 * @param array  $rec      DesignRecord.
	 * @param int    $order_id Order id.
	 * @param string $action   approve|changes.
	 * @param string $note     Customer note.
	 */
	public static function record_response( array $rec, int $order_id, string $action, string $note ): void {
		$proof  = self::proof_of( $rec );
		$status = 'changes' === $action ? 'changes_requested' : 'approved';
		$now    = gmdate( 'c' );
		Designs::update_proof(
			(int) $rec['id'],
			array(
				'status'       => $status,
				'responded_at' => $now,
				'note'         => $note,
				'history_add'  => array(
					'at'     => $now,
					'action' => 'changes' === $action ? 'changes' : 'approved',
					'by'     => 'customer',
					'note'   => $note,
					'round'  => $proof['round'],
				),
			)
		);
		self::mirror_status( $order_id, $proof['item_id'], $status );

		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$text = 'changes' === $action
				/* translators: 1: design ref, 2: round */
				? sprintf( __( 'De klant vraagt een aanpassing aan de digitale proef van patch %1$s (ronde %2$d).', 'borduur-patch-studio' ), $rec['ref'], $proof['round'] )
				/* translators: 1: design ref, 2: round */
				: sprintf( __( 'De klant keurde de digitale proef van patch %1$s goed (ronde %2$d).', 'borduur-patch-studio' ), $rec['ref'], $proof['round'] );
			if ( '' !== $note ) {
				/* translators: %s: customer note */
				$text .= ' ' . sprintf( __( 'Opmerking: %s', 'borduur-patch-studio' ), $note );
			}
			$order->add_order_note( $text, 0, false );
			$mail = self::email( 'BPS_Email_Proof_Response' );
			if ( $mail ) {
				$mail->trigger( Designs::get( (int) $rec['id'] ) ?: $rec, $order, $action, $note, $proof['round'] );
			}
		}

		/**
		 * The customer (or the shop, manually) answered a digital proof.
		 *
		 * @param int    $design_id Design id.
		 * @param string $action    'approved' | 'changes'.
		 * @param string $note      Note of the customer.
		 */
		do_action( 'bps_proof_response', (int) $rec['id'], 'changes' === $action ? 'changes' : 'approved', $note );
	}

	/**
	 * Customer-facing summary rows for the proof mail and page.
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 * @param string                 $lang Language.
	 * @return array<int,array{label:string,value:string,hex?:string}>
	 */
	public static function customer_rows( $item, string $lang ): array {
		$prev    = self::set_lang( $lang );
		$f       = self::fiche( $item, $lang );
		$p       = $f['payload'];
		$rows    = array();
		$rows[]  = array(
			'label' => self::l( __( 'Techniek', 'borduur-patch-studio' ) ),
			'value' => $f['technique_lbl'],
		);
		$rows[]  = array(
			'label' => self::l( __( 'Maat', 'borduur-patch-studio' ) ),
			'value' => self::num( $f['size_cm'] ) . ' cm (' . self::num( round( $f['width_mm'] ), 0 ) . ' × ' . self::num( round( $f['height_mm'] ), 0 ) . ' mm)',
		);
		$rows[]  = array(
			'label' => self::l( __( 'Vorm', 'borduur-patch-studio' ) ),
			'value' => $f['shape_lbl'],
		);
		$rows[]  = array(
			'label' => self::l( __( 'Aantal', 'borduur-patch-studio' ) ),
			/* translators: %s: quantity */
			'value' => sprintf( self::l( __( '%s stuks', 'borduur-patch-studio' ) ), number_format( $f['qty'], 0, ',', '.' ) ),
		);
		$groups = array();
		foreach ( (array) ( $p['options'] ?? array() ) as $oid ) {
			$opt = self::option( (string) $oid );
			if ( $opt && ( empty( $opt['requiresAny'] ) || array_intersect( (array) $opt['requiresAny'], (array) $p['options'] ) ) ) {
				$groups[ (string) ( $opt['group'] ?? '' ) ][] = self::label( $opt['label'] ?? $oid );
			}
		}
		if ( ! empty( $groups['edge'] ) ) {
			$rows[] = array(
				'label' => self::l( __( 'Rand', 'borduur-patch-studio' ) ),
				'value' => implode( ', ', $groups['edge'] ),
			);
		}
		if ( ! empty( $p['edgeColor']['code'] ) ) {
			$ti     = self::thread_info( (string) $p['edgeColor']['code'], (string) ( $p['edgeColor']['name'] ?? '' ), (string) ( $p['edgeColor']['hex'] ?? '' ) );
			$rows[] = array(
				'label' => self::l( __( 'Randkleur', 'borduur-patch-studio' ) ),
				'value' => $ti['name'],
				'hex'   => $ti['hex'],
			);
		}
		$back = $groups['backing'] ?? array();
		foreach ( $groups['velcro_color'] ?? array() as $vc ) {
			/* translators: %s: velcro colour */
			$back[] = sprintf( self::l( __( 'klittenband %s', 'borduur-patch-studio' ) ), function_exists( 'mb_strtolower' ) ? mb_strtolower( $vc ) : strtolower( $vc ) );
		}
		$back = array_merge( $back, $groups['backing_extra'] ?? array() );
		if ( $back ) {
			$rows[] = array(
				'label' => self::l( __( 'Achterkant', 'borduur-patch-studio' ) ),
				'value' => implode( ', ', $back ),
			);
		}
		if ( ! empty( $p['fabric'] ) && is_array( $p['fabric'] ) ) {
			$rows[] = array(
				'label' => self::l( __( 'Stofkleur', 'borduur-patch-studio' ) ),
				'value' => self::fabric_label( $p['fabric'] ),
				'hex'   => self::hex( $p['fabric']['hex'] ?? '' ),
			);
		}
		$names = array();
		foreach ( (array) ( $p['threads'] ?? array() ) as $th ) {
			if ( is_array( $th ) ) {
				$names[] = self::thread_info( (string) ( $th['code'] ?? '' ), (string) ( $th['name'] ?? '' ) )['name'];
			}
		}
		if ( $names && $f['thread_based'] ) {
			$rows[] = array(
				'label' => self::l( __( 'Garenkleuren', 'borduur-patch-studio' ) ),
				'value' => count( $names ) . ': ' . implode( ', ', array_slice( $names, 0, 16 ) ),
			);
		}
		$texts = array();
		foreach ( (array) ( $p['texts'] ?? array() ) as $tx ) {
			if ( is_array( $tx ) && '' !== trim( (string) ( $tx['text'] ?? '' ) ) ) {
				$texts[] = '“' . (string) $tx['text'] . '”';
			}
		}
		if ( $texts ) {
			$rows[] = array(
				'label' => self::l( __( 'Tekst', 'borduur-patch-studio' ) ),
				'value' => implode( ' · ', $texts ),
			);
		}
		self::set_lang( $prev );
		return $rows;
	}

	/* =====================================================================
	 * E-mails
	 * =================================================================== */

	/**
	 * Register BPS_Email_Proof (customer) and BPS_Email_Proof_Response (shop).
	 *
	 * @param array $emails Emails.
	 */
	public static function email_classes( $emails ) {
		$emails = is_array( $emails ) ? $emails : array();
		if ( declare_emails() ) {
			$emails['BPS_Email_Proof']          = new Proof_Email();
			$emails['BPS_Email_Proof_Response'] = new Proof_Response_Email();
		}
		return $emails;
	}

	/**
	 * One of our e-mail objects.
	 *
	 * @param string $key Class key.
	 * @return \WC_Email|null
	 */
	private static function email( string $key ) {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$mailer = WC()->mailer();
		$all    = $mailer ? $mailer->get_emails() : array();
		return $all[ $key ] ?? null;
	}

	/* =====================================================================
	 * Orders list column
	 * =================================================================== */

	/**
	 * Add the "Patchproef" column after the order status.
	 *
	 * @param array $cols Columns.
	 */
	public static function list_columns( $cols ) {
		$cols = is_array( $cols ) ? $cols : array();
		$out  = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'order_status' === $k ) {
				$out['bps_proof'] = __( 'Patchproef', 'borduur-patch-studio' );
			}
		}
		if ( ! isset( $out['bps_proof'] ) ) {
			$out['bps_proof'] = __( 'Patchproef', 'borduur-patch-studio' );
		}
		return $out;
	}

	/**
	 * HPOS column.
	 *
	 * @param string $column Column.
	 * @param mixed  $order  Order.
	 */
	public static function list_column_hpos( $column, $order ): void {
		if ( 'bps_proof' === $column ) {
			self::list_cell( $order instanceof \WC_Order ? $order : wc_get_order( $order ) );
		}
	}

	/**
	 * Legacy column.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Order id.
	 */
	public static function list_column_legacy( $column, $post_id ): void {
		if ( 'bps_proof' === $column ) {
			self::list_cell( wc_get_order( $post_id ) );
		}
	}

	/**
	 * Cell: aggregated proof status of the patch lines.
	 *
	 * @param mixed $order Order.
	 */
	private static function list_cell( $order ): void {
		$items = self::design_items( $order );
		if ( ! $items ) {
			echo '<span aria-hidden="true">–</span>';
			return;
		}
		$count = array(
			'none'              => 0,
			'sent'              => 0,
			'approved'          => 0,
			'changes_requested' => 0,
		);
		foreach ( $items as $item ) {
			$s = (string) ( $item->get_meta( self::ITEM_PROOF_META ) ?: 'none' );
			$count[ isset( $count[ $s ] ) ? $s : 'none' ]++;
		}
		$n = count( $items );
		if ( $count['changes_requested'] > 0 ) {
			$s = 'changes_requested';
		} elseif ( $count['approved'] === $n ) {
			$s = 'approved';
		} elseif ( $count['sent'] > 0 ) {
			$s = 'sent';
		} else {
			$s = 'none';
		}
		$labels = array(
			'none'              => __( 'Proef maken', 'borduur-patch-studio' ),
			'sent'              => __( 'Wacht op klant', 'borduur-patch-studio' ),
			'approved'          => __( 'Goedgekeurd', 'borduur-patch-studio' ),
			'changes_requested' => __( 'Aanpassing gevraagd', 'borduur-patch-studio' ),
		);
		$colors = array(
			'none'              => '#6B6B6B',
			'sent'              => '#0B74A6',
			'approved'          => '#15803D',
			'changes_requested' => '#B45309',
		);
		$text = $labels[ $s ] . ( $n > 1 ? ' (' . $count['approved'] . '/' . $n . ')' : '' );
		printf(
			'<span class="bps-proof-pill bps-proof-pill--%1$s" style="display:inline-block;padding:2px 8px;border-radius:10px;border:1px solid %2$s;color:%2$s;font-size:12px;line-height:1.6;white-space:nowrap">%3$s</span>',
			esc_attr( $s ),
			esc_attr( $colors[ $s ] ),
			esc_html( $text )
		);
	}
}

/**
 * Declare our WC_Email classes. They extend Woo\Emails\Base (customer language + our templates), which only exists once
 * WooCommerce has loaded WC_Email, so they are declared lazily from the woocommerce_email_classes filter.
 * (PHP does not allow class declarations inside methods, hence this namespaced function.)
 *
 * @return bool True when the classes are available.
 */
function declare_emails(): bool {
	if ( class_exists( __NAMESPACE__ . '\Proof_Email', false ) ) {
		return true;
	}
	if ( ! class_exists( '\WC_Email' ) || ! class_exists( '\BorduurPatchStudio\Woo\Emails\Base' ) ) {
		return false;
	}

	/**
	 * BPS_Email_Proof: digital proof to the customer (SPEC §6.6).
	 */
	class Proof_Email extends \BorduurPatchStudio\Woo\Emails\Base {

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->id             = 'bps_proof';
			$this->customer_email = true;
			$this->title          = __( 'Patch Studio: digitale proef', 'borduur-patch-studio' );
			$this->description    = __( 'Wordt vanuit de bestelling verstuurd met de knop "Digitale proef versturen": proefafbeelding, samenvatting en knoppen om goed te keuren of een aanpassing te vragen. In de taal van het ontwerp.', 'borduur-patch-studio' );
			$this->template_html  = 'admin/emails/proof.php';
			$this->template_plain = 'admin/emails/plain/proof.php';
			$this->placeholders   = array(
				'{order_number}' => '',
			);
			$this->bps_setup();
			parent::__construct();
		}

		/**
		 * Default subject.
		 */
		public function get_default_subject() {
			return Order_Production::l( __( 'Je digitale proef voor patch {ref} staat klaar', 'borduur-patch-studio' ) );
		}

		/**
		 * Default heading.
		 */
		public function get_default_heading() {
			return Order_Production::l( __( 'Je digitale proef', 'borduur-patch-studio' ) );
		}

		/**
		 * Send the proof.
		 *
		 * @param array                  $design    DesignRecord.
		 * @param \WC_Order              $order     Order.
		 * @param \WC_Order_Item_Product $item      Item.
		 * @param int                    $round     Round.
		 * @param string                 $image_url Proof image.
		 * @param string                 $message   Message from the atelier.
		 * @param string                 $recipient Recipient.
		 */
		public function trigger( $design = null, $order = null, $item = null, $round = 1, $image_url = '', $message = '', $recipient = '' ) {
			if ( ! is_array( $design ) || ! $order instanceof \WC_Order || ! $item instanceof \WC_Order_Item_Product ) {
				return false;
			}
			$locale           = (string) ( $design['payload']['locale'] ?? '' );
			$lang             = Order_Production::lang_of( '' !== $locale ? $locale : get_locale() );
			$this->bps_locale = 'fr' === $lang ? 'fr-BE' : 'nl-BE';
			$this->setup_locale();
			$prev = Order_Production::set_lang( $lang );

			$this->object                         = $order;
			$this->recipient                      = '' !== $recipient ? $recipient : (string) $order->get_billing_email();
			$this->placeholders['{ref}']          = (string) $design['ref'];
			$this->placeholders['{order_number}'] = (string) $order->get_order_number();
			$this->bps_args                       = array(
				'design'      => $design,
				'order'       => $order,
				'round'       => (int) $round,
				'image_url'   => (string) $image_url,
				'message'     => (string) $message,
				'rows'        => Order_Production::customer_rows( $item, $lang ),
				'approve_url' => Order_Production::proof_url( (int) $design['id'], (int) $order->get_id(), (int) $round, 'approve' ),
				'changes_url' => Order_Production::proof_url( (int) $design['id'], (int) $order->get_id(), (int) $round, 'changes' ),
				'first_name'  => trim( (string) $order->get_billing_first_name() ),
				'contact'     => (array) ( class_exists( Settings::class ) ? Settings::get_path( 'contact', array() ) : array() ),
				'lang'        => $lang,
			);
			$sent = $this->bps_send();
			Order_Production::set_lang( $prev );
			$this->restore_locale();
			return $sent;
		}
	}

	/**
	 * BPS_Email_Proof_Response: the customer answered a proof (to the shop).
	 */
	class Proof_Response_Email extends \BorduurPatchStudio\Woo\Emails\Base {

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->id             = 'bps_proof_response';
			$this->customer_email = false;
			$this->title          = __( 'Patch Studio: antwoord op digitale proef', 'borduur-patch-studio' );
			$this->description    = __( 'Melding aan de winkel wanneer een klant een digitale proef goedkeurt of een aanpassing vraagt.', 'borduur-patch-studio' );
			$this->template_html  = 'admin/emails/proof-response.php';
			$this->template_plain = 'admin/emails/plain/proof-response.php';
			$this->placeholders   = array(
				'{order_number}' => '',
				'{answer}'       => '',
			);
			$this->bps_setup();
			parent::__construct();
			$default         = class_exists( Settings::class ) ? (string) Settings::get_path( 'woo.quoteRecipient', '' ) : '';
			$this->recipient = $this->get_option( 'recipient', '' !== $default ? $default : get_option( 'admin_email' ) );
		}

		/**
		 * Settings fields (+ recipient).
		 */
		public function init_form_fields() {
			parent::init_form_fields();
			$this->form_fields = array_merge(
				array_slice( $this->form_fields, 0, 1, true ),
				array(
					'recipient' => array(
						'title'       => __( 'Ontvanger(s)', 'borduur-patch-studio' ),
						'type'        => 'text',
						/* translators: %s: admin e-mail */
						'description' => sprintf( __( 'Komma-gescheiden. Standaard: %s.', 'borduur-patch-studio' ), esc_attr( get_option( 'admin_email' ) ) ),
						'placeholder' => '',
						'default'     => '',
						'desc_tip'    => true,
					),
				),
				array_slice( $this->form_fields, 1, null, true )
			);
		}

		/**
		 * Default subject.
		 */
		public function get_default_subject() {
			return __( '[{site_title}] Proef {ref} (bestelling #{order_number}): {answer}', 'borduur-patch-studio' );
		}

		/**
		 * Default heading.
		 */
		public function get_default_heading() {
			return __( 'Antwoord op een digitale proef', 'borduur-patch-studio' );
		}

		/**
		 * Send.
		 *
		 * @param array     $design DesignRecord.
		 * @param \WC_Order $order  Order.
		 * @param string    $action approve|changes.
		 * @param string    $note   Note.
		 * @param int       $round  Round.
		 */
		public function trigger( $design = null, $order = null, $action = 'approve', $note = '', $round = 1 ) {
			if ( ! is_array( $design ) || ! $order instanceof \WC_Order ) {
				return false;
			}
			$this->setup_locale();
			$answer                               = 'changes' === $action ? __( 'aanpassing gevraagd', 'borduur-patch-studio' ) : __( 'goedgekeurd', 'borduur-patch-studio' );
			$this->object                         = $order;
			$this->placeholders['{ref}']          = (string) $design['ref'];
			$this->placeholders['{order_number}'] = (string) $order->get_order_number();
			$this->placeholders['{answer}']       = $answer;
			$this->bps_args                       = array(
				'design'    => $design,
				'order'     => $order,
				'action'    => 'changes' === $action ? 'changes' : 'approve',
				'answer'    => $answer,
				'note'      => (string) $note,
				'round'     => (int) $round,
				'order_url' => method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
				'customer'  => trim( $order->get_formatted_billing_full_name() ),
			);
			$sent = $this->bps_send();
			$this->restore_locale();
			return $sent;
		}
	}

	return true;
}
