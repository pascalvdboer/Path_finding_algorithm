<?php
/**
 * Spec formatting shared by the production sheet (Admin\Order_Production) and the proof (mail, page, approval
 * record): config labels in a chosen language, thread / font / fabric names, the customer summary rows and the
 * attention points for the atelier.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Proof;

use BorduurPatchStudio\Admin\Order_Production;
use BorduurPatchStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helpers (one language switch for label lookups).
 */
final class Spec {

	/**
	 * Label language ('nl'|'fr').
	 *
	 * @var string
	 */
	private static $lang = 'nl';

	/*
	---------------------------------------------------------------------
	 * Config and language
	 * ------------------------------------------------------------------- */

	/**
	 * Dot-path config lookup (Settings::get_path, php-kwaliteit-7d).
	 *
	 * @param string $dot     Path.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function cfg( string $dot, $default = null ) {
		if ( ! class_exists( Settings::class ) ) {
			return $default;
		}
		return Settings::get_path( $dot, $default );
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
	 * Set the label language; returns the previous one.
	 *
	 * @param string $lang 'nl'|'fr'.
	 */
	public static function set_lang( string $lang ): string {
		$prev       = self::$lang;
		self::$lang = 'fr' === $lang ? 'fr' : 'nl';
		return $prev;
	}

	/**
	 * Current label language.
	 */
	public static function lang(): string {
		return self::$lang;
	}

	/**
	 * Text from a {nl,fr} label in the label language.
	 *
	 * @param mixed $obj Label or string.
	 */
	public static function label( $obj ): string {
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
	 * Integer for display (thousands separator of the current locale when WordPress is loaded).
	 *
	 * @param int $n Number.
	 */
	public static function int( int $n ): string {
		return function_exists( 'number_format_i18n' ) ? (string) number_format_i18n( $n ) : number_format( $n, 0, ',', '.' );
	}

	/**
	 * "1 stuk" / "2 stuks" (i18n-a11y-6).
	 *
	 * @param int $qty Quantity.
	 */
	public static function pieces( int $qty ): string {
		/* translators: %s: quantity */
		return sprintf( _n( '%s stuk', '%s stuks', $qty, 'borduur-patch-studio' ), self::int( $qty ) );
	}

	/**
	 * Lower-case (multibyte-safe when mbstring is available).
	 *
	 * @param string $s Text.
	 */
	public static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
	}

	/**
	 * Safe hex colour or ''.
	 *
	 * @param mixed $hex Value.
	 */
	public static function hex( $hex ): string {
		$hex = is_string( $hex ) ? trim( $hex ) : '';
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) ? strtolower( $hex ) : '';
	}

	/*
	---------------------------------------------------------------------
	 * Config lookups
	 * ------------------------------------------------------------------- */

	/**
	 * Technique definition.
	 *
	 * @param string $id Technique id.
	 */
	public static function technique( string $id ): array {
		$t = '' !== $id ? self::cfg( 'techniques.' . $id ) : null;
		return is_array( $t ) ? $t : array();
	}

	/**
	 * Technique label.
	 *
	 * @param string $id Technique id.
	 */
	public static function technique_label( string $id ): string {
		$t = self::technique( $id );
		return '' !== $id && $t ? self::label( $t['label'] ?? $id ) : $id;
	}

	/**
	 * Shape label.
	 *
	 * @param string $id Shape id.
	 */
	public static function shape_label( string $id ): string {
		$s = '' !== $id ? self::cfg( 'shapes.' . $id ) : null;
		return is_array( $s ) ? self::label( $s['label'] ?? $id ) : $id;
	}

	/**
	 * Option definition by id.
	 *
	 * @param string $id Option id.
	 */
	public static function option( string $id ): ?array {
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
	public static function thread( string $code ): ?array {
		foreach ( (array) self::cfg( 'threads', array() ) as $t ) {
			if ( is_array( $t ) && isset( $t['code'] ) && (string) $t['code'] === $code ) {
				return $t;
			}
		}
		return null;
	}

	/**
	 * Font definition by id.
	 *
	 * @param string $id Font id.
	 */
	public static function font( string $id ): ?array {
		foreach ( (array) self::cfg( 'fonts', array() ) as $f ) {
			if ( is_array( $f ) && isset( $f['id'] ) && $f['id'] === $id ) {
				return $f;
			}
		}
		return null;
	}

	/**
	 * Font label for the customer ("Oswald (smal en sterk)") or the raw id.
	 *
	 * @param string $id Font id.
	 */
	public static function font_label( string $id ): string {
		$f = self::font( $id );
		return $f ? self::label( $f['label'] ?? $f['id'] ) : $id;
	}

	/**
	 * Font for the atelier: "Oswald (smal en sterk) → machinefont: Block 2" when the private setting
	 * fonts[].machineFont is filled in (concurrentie-features-5), otherwise the web font label.
	 *
	 * @param string $id Font id.
	 */
	public static function font_atelier( string $id ): string {
		$f       = self::font( $id );
		$label   = $f ? self::label( $f['label'] ?? $f['id'] ) : $id;
		$machine = $f ? trim( (string) ( $f['machineFont'] ?? '' ) ) : '';
		/* translators: 1: web font label, 2: font name in the embroidery software */
		return '' !== $machine ? sprintf( __( '%1$s → machinefont: %2$s', 'borduur-patch-studio' ), $label, $machine ) : $label;
	}

	/**
	 * Thread brand shown before codes ("Polyneon").
	 */
	public static function brand(): string {
		$b = trim( (string) self::cfg( 'threadBrand', 'Polyneon' ) );
		$b = '' !== $b ? $b : 'Polyneon';
		return trim( (string) preg_replace( '/^Madeira\s+/i', '', $b ) );
	}

	/**
	 * Full thread brand for ordering / copying ("Madeira Polyneon").
	 */
	public static function brand_full(): string {
		$b = trim( (string) self::cfg( 'threadBrand', 'Madeira Polyneon' ) );
		return '' !== $b ? $b : 'Madeira Polyneon';
	}

	/**
	 * Fabric / leather label by payload fabric.
	 *
	 * @param array $fabric {id, hex, label}.
	 */
	public static function fabric_label( array $fabric ): string {
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
	public static function text_place( array $tx ): string {
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
	public static function path_label( string $path ): string {
		switch ( $path ) {
			case 'arc_top':
				return __( 'Boog boven', 'borduur-patch-studio' );
			case 'arc_bottom':
				return __( 'Boog onder', 'borduur-patch-studio' );
			default:
				return __( 'Recht', 'borduur-patch-studio' );
		}
	}

	/**
	 * Thread display data.
	 *
	 * @param string $code Code.
	 * @param string $name Fallback name.
	 * @param string $hex  Fallback hex.
	 * @return array{name:string, code:string, hex:string}
	 */
	public static function thread_info( string $code, string $name = '', string $hex = '' ): array {
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

	/**
	 * Copy line for ordering thread: "Madeira Polyneon 1801 Wit" (atelier-flow-6).
	 *
	 * @param string $code Code.
	 * @param string $name Thread name.
	 */
	public static function copy_line( string $code, string $name ): string {
		return trim( self::brand_full() . ' ' . $code . ' ' . $name );
	}

	/*
	---------------------------------------------------------------------
	 * Customer summary (proof mail, proof page, approval snapshot)
	 * ------------------------------------------------------------------- */

	/**
	 * Customer-facing summary rows. Text layers show font, thread code and name (atelier-flow-7).
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 * @param string                 $lang Language.
	 * @return array<int,array{label:string,value:string,hex?:string}>
	 */
	public static function customer_rows( $item, string $lang ): array {
		$prev   = self::set_lang( $lang );
		$f      = Order_Production::fiche( $item, $lang );
		$p      = $f['payload'];
		$rows   = array();
		$rows[] = array(
			'label' => __( 'Techniek', 'borduur-patch-studio' ),
			'value' => $f['technique_lbl'],
		);
		$rows[] = array(
			'label' => __( 'Maat', 'borduur-patch-studio' ),
			'value' => self::num( $f['size_cm'] ) . ' cm (' . self::num( round( $f['width_mm'] ), 0 ) . ' × ' . self::num( round( $f['height_mm'] ), 0 ) . ' mm)',
		);
		$rows[] = array(
			'label' => __( 'Vorm', 'borduur-patch-studio' ),
			'value' => $f['shape_lbl'],
		);
		$rows[] = array(
			'label' => __( 'Aantal', 'borduur-patch-studio' ),
			'value' => self::pieces( (int) $f['qty'] ),
		);
		$opts   = array_map( 'strval', (array) ( $p['options'] ?? array() ) );
		$groups = array();
		foreach ( $opts as $oid ) {
			$opt = self::option( $oid );
			if ( $opt && ( empty( $opt['requiresAny'] ) || array_intersect( (array) $opt['requiresAny'], $opts ) ) ) {
				$groups[ (string) ( $opt['group'] ?? '' ) ][] = self::label( $opt['label'] ?? $oid );
			}
		}
		if ( ! empty( $groups['edge'] ) ) {
			$rows[] = array(
				'label' => __( 'Rand', 'borduur-patch-studio' ),
				'value' => implode( ', ', $groups['edge'] ),
			);
		}
		if ( ! empty( $p['edgeColor']['code'] ) ) {
			$ti     = self::thread_info( (string) $p['edgeColor']['code'], (string) ( $p['edgeColor']['name'] ?? '' ), (string) ( $p['edgeColor']['hex'] ?? '' ) );
			$rows[] = array(
				'label' => __( 'Randkleur', 'borduur-patch-studio' ),
				'value' => $ti['name'] . ' (' . self::brand() . ' ' . $ti['code'] . ')',
				'hex'   => $ti['hex'],
			);
		}
		$back = $groups['backing'] ?? array();
		foreach ( $groups['velcro_color'] ?? array() as $vc ) {
			/* translators: %s: velcro colour */
			$back[] = sprintf( __( 'klittenband %s', 'borduur-patch-studio' ), self::lower( $vc ) );
		}
		$back = array_merge( $back, $groups['backing_extra'] ?? array() );
		if ( $back ) {
			$rows[] = array(
				'label' => __( 'Achterkant', 'borduur-patch-studio' ),
				'value' => implode( ', ', $back ),
			);
		}
		if ( ! empty( $p['fabric'] ) && is_array( $p['fabric'] ) ) {
			$rows[] = array(
				'label' => __( 'Stofkleur', 'borduur-patch-studio' ),
				'value' => self::fabric_label( $p['fabric'] ),
				'hex'   => self::hex( $p['fabric']['hex'] ?? '' ),
			);
		}
		$names = array();
		foreach ( (array) ( $p['threads'] ?? array() ) as $th ) {
			if ( is_array( $th ) ) {
				$ti      = self::thread_info( (string) ( $th['code'] ?? '' ), (string) ( $th['name'] ?? '' ) );
				$names[] = '' !== $ti['code'] && $ti['code'] !== $ti['name'] ? $ti['name'] . ' ' . $ti['code'] : $ti['name'];
			}
		}
		if ( $names && $f['thread_based'] ) {
			$rows[] = array(
				'label' => __( 'Garenkleuren', 'borduur-patch-studio' ),
				'value' => count( $names ) . ' (' . self::brand() . '): ' . implode( ', ', array_slice( $names, 0, 16 ) ),
			);
		}
		foreach ( (array) ( $p['texts'] ?? array() ) as $tx ) {
			if ( ! is_array( $tx ) || '' === trim( (string) ( $tx['text'] ?? '' ) ) ) {
				continue;
			}
			$parts = array( self::font_label( (string) ( $tx['font'] ?? '' ) ) );
			$code  = (string) ( $tx['threadCode'] ?? '' );
			if ( '' !== $code && 'auto' !== $code ) {
				$ti      = self::thread_info( $code );
				$parts[] = trim( self::brand() . ' ' . $ti['code'] . ( $ti['name'] !== $ti['code'] ? ' ' . $ti['name'] : '' ) );
			}
			$rows[] = array(
				'label' => __( 'Tekst', 'borduur-patch-studio' ),
				'value' => '“' . (string) $tx['text'] . '” · ' . implode( ' · ', array_filter( $parts ) ),
				'hex'   => '' !== $code && 'auto' !== $code ? self::thread_info( $code )['hex'] : '',
			);
		}
		self::set_lang( $prev );
		return $rows;
	}

	/*
	---------------------------------------------------------------------
	 * Attention points for the atelier
	 * ------------------------------------------------------------------- */

	/**
	 * Attention points from the technique's rules (config) and the stored analysis.
	 *
	 * @param string     $tech       Technique id.
	 * @param array      $t          Technique config.
	 * @param array      $p          Payload.
	 * @param array|null $a          Payload analysis.
	 * @param array      $threads    Thread rows.
	 * @param string     $edge_style Edge render style.
	 * @return array<int,array>
	 */
	public static function checks( string $tech, array $t, array $p, ?array $a, array $threads, string $edge_style ): array {
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
			$add( 'bad', __( 'Te veel kleuren', 'borduur-patch-studio' ), sprintf( _n( '%1$d kleur; maximum is %2$d. Vereenvoudigen vóór de proef.', '%1$d kleuren; maximum is %2$d. Vereenvoudigen vóór de proef.', $n, 'borduur-patch-studio' ), $n, $max ) );
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
			'low_res'              => __( 'Lage resolutie voor deze maat: vraag eventueel een groter of origineel bestand.', 'borduur-patch-studio' ),
			'transparent_shadow'   => __( 'Transparante schaduw in het bestand: die zachte schaduw wordt niet overgenomen.', 'borduur-patch-studio' ),
			'mockup'               => __( 'De upload is een afbeelding van een patch (mock-up), geen origineel ontwerpbestand. Vraag het origineel als dat er is.', 'borduur-patch-studio' ),
			'patch_photo_cut'      => __( 'Foto van een bestaande patch, automatisch uitgeknipt: rand en details zelf nakijken.', 'borduur-patch-studio' ),
			'nearly_empty'         => __( 'Het bestand is bijna leeg: controleer of het juiste bestand werd geüpload.', 'borduur-patch-studio' ),
			'white_on_transparent' => __( 'Witte elementen op een transparante achtergrond: controleer wat wit moet blijven.', 'borduur-patch-studio' ),
			'shape_uncertain'      => __( 'Vorm niet met zekerheid herkend: controleer de gekozen vorm.', 'borduur-patch-studio' ),
			'svg_gradient'         => __( 'SVG met kleurverlopen.', 'borduur-patch-studio' ),
			'svg_embedded_raster'  => __( 'SVG met een ingesloten pixelafbeelding (geen echte vector).', 'borduur-patch-studio' ),
		);
		if ( $a ) {
			foreach ( array_unique( array_map( 'strval', (array) ( $a['warnings'] ?? array() ) ) ) as $code ) {
				if ( '' !== $code ) {
					$add( 'warn', __( 'Bestand', 'borduur-patch-studio' ), $warn_text[ $code ] ?? $code );
				}
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
	public static function worst_level( array $rows ): string {
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
}
