<?php
/**
 * Allowlist SVG sanitizer (no third-party dependency).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * Removes everything from an SVG that can execute code or load remote resources:
 * DOCTYPE/entities (XXE, billion laughs), script/foreignObject/animation elements, event handlers,
 * javascript:/external references, CSS @import/url()/expression. Unknown elements are dropped.
 *
 * If enshrined/svg-sanitize is available (optionally prefixed via Strauss), it runs first as an extra pass.
 */
final class Svg_Sanitizer {

	const MAX_BYTES = 5242880; // 5 MB of XML is far more than any logo needs.

	/** Elements that are kept (lowercase). Everything else is removed including its children. */
	const ELEMENTS = array(
		'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata', 'style',
		'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
		'text', 'tspan', 'textpath',
		'lineargradient', 'radialgradient', 'stop', 'pattern', 'clippath', 'mask', 'marker', 'image',
		'filter', 'fegaussianblur', 'feoffset', 'feblend', 'fecolormatrix', 'fecomposite', 'feflood',
		'femerge', 'femergenode', 'femorphology', 'fedropshadow', 'fecomponenttransfer', 'fefunca',
		'fefuncr', 'fefuncg', 'fefuncb', 'feturbulence', 'fedisplacementmap', 'fetile', 'feimage',
		'switch',
	);

	/**
	 * CSS functions that take a URL or string argument and can load a resource (besides url()).
	 * Matched on lowercase, whitespace-free text; the look-behind keeps e.g. "-webkit-image-set(" in scope.
	 */
	const RESOURCE_FUNCTIONS = '/(?<![a-z0-9_])(?:-webkit-|-moz-|-o-|-ms-)?(?:image-set|image|cross-fade|src|element)\\(/';

	/** Elements whose children are kept but the element itself is unwrapped (links). */
	const UNWRAP = array( 'a' );

	/**
	 * Sanitize SVG markup.
	 *
	 * @param string $svg Raw SVG.
	 * @return string|false Clean SVG or false when it cannot be parsed safely.
	 */
	public static function sanitize( string $svg ) {
		if ( '' === trim( $svg ) || strlen( $svg ) > self::MAX_BYTES ) {
			return false;
		}
		// Strip UTF-8 BOM; refuse DTDs/entities outright (XXE + entity expansion).
		$svg = preg_replace( '/^\xEF\xBB\xBF/', '', $svg );
		if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', (string) $svg ) ) {
			$svg = preg_replace( '/<!DOCTYPE[^>\[]*(\[[^\]]*\])?\s*>/is', '', (string) $svg );
			if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', (string) $svg ) ) {
				return false;
			}
		}

		$svg = self::maybe_enshrined( (string) $svg );
		if ( false === $svg ) {
			return false;
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			return false;
		}
		$prev = libxml_use_internal_errors( true );
		$doc  = new \DOMDocument();
		$doc->preserveWhiteSpace = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$doc->resolveExternals   = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$doc->substituteEntities = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$ok   = $doc->loadXML( $svg, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok || ! $doc->documentElement || 'svg' !== strtolower( $doc->documentElement->localName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			return false;
		}
		$root_ns = (string) $doc->documentElement->namespaceURI; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		if ( '' !== $root_ns && 'http://www.w3.org/2000/svg' !== $root_ns ) {
			return false; // E.g. an XHTML element called "svg": not an SVG document.
		}

		self::clean_node( $doc->documentElement ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName

		// Remove comments, processing instructions and entity references anywhere.
		$xpath = new \DOMXPath( $doc );
		foreach ( iterator_to_array( $xpath->query( '//comment() | //processing-instruction()' ) ) as $n ) {
			$n->parentNode->removeChild( $n ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}

		$out = $doc->saveXML( $doc->documentElement ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		if ( ! is_string( $out ) || '' === $out ) {
			return false;
		}
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out;
	}

	/**
	 * Optional extra pass with enshrined/svg-sanitize when present.
	 *
	 * @param string $svg SVG.
	 * @return string|false
	 */
	private static function maybe_enshrined( string $svg ) {
		foreach ( array( 'BorduurPatchStudio\\Vendor\\enshrined\\svgSanitize\\Sanitizer', 'enshrined\\svgSanitize\\Sanitizer' ) as $class ) {
			if ( class_exists( $class ) ) {
				$s = new $class();
				if ( method_exists( $s, 'removeRemoteReferences' ) ) {
					$s->removeRemoteReferences( true );
				}
				$res = $s->sanitize( $svg );
				return is_string( $res ) && '' !== $res ? $res : false;
			}
		}
		return $svg;
	}

	/**
	 * Recursively clean an element.
	 *
	 * @param \DOMElement $el Element.
	 */
	private static function clean_node( \DOMElement $el ): void {
		self::clean_attributes( $el );
		if ( 'style' === strtolower( $el->localName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$css = self::clean_css( (string) $el->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			while ( $el->firstChild ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				$el->removeChild( $el->firstChild ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			}
			$el->appendChild( $el->ownerDocument->createTextNode( $css ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			return;
		}

		$children = array();
		foreach ( $el->childNodes as $c ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$children[] = $c;
		}
		foreach ( $children as $child ) {
			if ( $child instanceof \DOMElement ) {
				$name = strtolower( $child->localName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				$ns   = (string) $child->namespaceURI; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				if ( '' !== $ns && 'http://www.w3.org/2000/svg' !== $ns ) {
					$el->removeChild( $child ); // Foreign namespaces (Inkscape/Illustrator metadata, XHTML…).
					continue;
				}
				if ( in_array( $name, self::UNWRAP, true ) ) {
					self::clean_node( $child );
					while ( $child->firstChild ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
						$el->insertBefore( $child->firstChild, $child ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
					}
					$el->removeChild( $child );
					continue;
				}
				if ( ! in_array( $name, self::ELEMENTS, true ) ) {
					$el->removeChild( $child );
					continue;
				}
				self::clean_node( $child );
			} elseif ( $child instanceof \DOMEntityReference || $child instanceof \DOMProcessingInstruction || $child instanceof \DOMComment ) {
				$el->removeChild( $child );
			}
		}
	}

	/**
	 * Remove dangerous attributes.
	 *
	 * @param \DOMElement $el Element.
	 */
	private static function clean_attributes( \DOMElement $el ): void {
		$remove = array();
		foreach ( $el->attributes as $attr ) {
			$name  = strtolower( (string) $attr->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$local = strtolower( (string) $attr->localName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$value = (string) $attr->value;
			$flat  = self::flatten( $value );

			if ( 0 === strpos( $local, 'on' ) || ! preg_match( '/^[a-z_:][-a-z0-9_:.]*$/', $name ) ) {
				$remove[] = $attr;
				continue;
			}
			if ( 'href' === $local ) {
				$is_image = 'image' === strtolower( $el->localName ) || 'feimage' === strtolower( $el->localName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
				$ok_ref   = '' !== $value && '#' === $value[0] && preg_match( '/^#[A-Za-z_][-\w.:]*$/', $value );
				$ok_data  = $is_image && preg_match( '#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]+$#i', $value );
				if ( ! $ok_ref && ! $ok_data ) {
					$remove[] = $attr;
				}
				continue;
			}
			if ( 'style' === $local ) {
				$attr->value = self::clean_css( $value );
				continue;
			}
			// Presentation attributes: a valid logo never needs CSS escapes, so every backslash goes first
			// (u\rl(…) would otherwise slip past the url() check below).
			if ( false !== strpos( $value, '\\' ) ) {
				$value       = str_replace( '\\', '', $value );
				$attr->value = $value;
				$flat        = self::flatten( $value );
			}
			if ( false !== strpos( $flat, 'javascript:' ) || false !== strpos( $flat, 'vbscript:' ) || false !== strpos( $flat, 'data:' ) || false !== strpos( $flat, 'expression(' ) || preg_match( self::RESOURCE_FUNCTIONS, $flat ) ) {
				$remove[] = $attr;
				continue;
			}
			// Presentation attributes may only reference local fragments: url(#id).
			if ( false !== strpos( $flat, 'url(' ) ) {
				preg_match_all( '/url\(([^)]*)\)/', $flat, $mm );
				$unclosed = substr_count( $flat, 'url(' ) !== count( $mm[1] );
				foreach ( $mm[1] as $u ) {
					if ( ! preg_match( '/^#[-\w.:]+$/', $u ) ) {
						$unclosed = true;
					}
				}
				if ( $unclosed ) {
					$remove[] = $attr;
				}
			}
		}
		foreach ( $remove as $attr ) {
			$el->removeAttributeNode( $attr );
		}
	}

	/**
	 * Lowercase, entity-decoded value without whitespace/control characters (for pattern checks only).
	 *
	 * @param string $value Attribute value.
	 */
	private static function flatten( string $value ): string {
		return strtolower( (string) preg_replace( '/[\s\x00-\x1f]+/', '', html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Clean CSS (style element or attribute): drop @import, external url(), expression(), behaviours.
	 *
	 * @param string $css CSS.
	 */
	public static function clean_css( string $css ): string {
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		// CSS escapes are a classic bypass (@imp\ort, u\rl(, \75 rl(). Normalise them the way a browser reads
		// them BEFORE any other filter runs: hex escapes become their character, then every remaining backslash
		// goes (a valid logo never needs escapes, so nothing legitimate is lost).
		$css = preg_replace_callback(
			'/\\\\([0-9a-f]{1,6})\s?/i',
			static function ( $m ) {
				$cp = hexdec( $m[1] );
				return ( $cp < 0x20 || ( $cp >= 0xD800 && $cp <= 0xDFFF ) || $cp > 0x10FFFF ) ? '' : (string) mb_chr( (int) $cp, 'UTF-8' );
			},
			(string) $css
		);
		$css = str_replace( '\\', '', (string) $css );
		$css = preg_replace( '/@import[^;]*;?/i', '', (string) $css );
		$css = preg_replace( '/@font-face\s*\{[^}]*\}/i', '', (string) $css );
		$css = preg_replace_callback(
			'/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/i',
			static function ( $m ) {
				return preg_match( '/^#[-\w.:]+$/', trim( $m[2] ) ) ? 'url(' . trim( $m[2] ) . ')' : 'none';
			},
			(string) $css
		);
		// Leftover url( (unclosed at end of input, which CSS auto-closes) and other functions with a string or
		// URL argument (image(), image-set(), cross-fade(), src(), element()) could still load remote resources:
		// replace the whole call, argument included, by "none".
		$css = self::drop_calls( (string) $css, '/url\s*\((?!#[-\w.:]+\))/i' );
		$css = self::drop_calls( (string) $css, '/(?<![a-z0-9_-])(?:-webkit-|-moz-|-o-|-ms-)?(?:image-set|image|cross-fade|src|element)\s*\(/i' );
		$css = preg_replace( '/(expression|behavior|-moz-binding|javascript:|vbscript:)/i', '', (string) $css );
		return str_replace( array( '<', '>' ), '', (string) $css );
	}

	/**
	 * Replace every call matched by $open (a pattern ending in "(") by "none", including its argument up to the
	 * balanced closing parenthesis (quotes respected). An unclosed call runs to the end of the input, like CSS.
	 *
	 * @param string $css  CSS.
	 * @param string $open Pattern for the function name plus "(".
	 */
	private static function drop_calls( string $css, string $open ): string {
		$guard = 0;
		while ( $guard++ < 1000 && preg_match( $open, $css, $m, PREG_OFFSET_CAPTURE ) ) {
			$start = (int) $m[0][1];
			$i     = $start + strlen( $m[0][0] );
			$depth = 1;
			$quote = '';
			$len   = strlen( $css );
			for ( ; $i < $len && $depth > 0; $i++ ) {
				$ch = $css[ $i ];
				if ( '' !== $quote ) {
					$quote = $ch === $quote ? '' : $quote;
				} elseif ( '"' === $ch || "'" === $ch ) {
					$quote = $ch;
				} elseif ( '(' === $ch ) {
					++$depth;
				} elseif ( ')' === $ch ) {
					--$depth;
				}
			}
			$css = substr( $css, 0, $start ) . 'none' . substr( $css, $i );
		}
		return $css;
	}
}
