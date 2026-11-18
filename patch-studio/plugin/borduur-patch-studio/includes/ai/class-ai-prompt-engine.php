<?php
/**
 * AI prompt engine: turns a customer's own words (Flemish Dutch or French) into an English image prompt for an
 * embroidery-ready patch design, plus local safety/privacy filtering and the "refine" (chat) step.
 *
 * Pure PHP, no WordPress calls, so it runs in the standalone tests (tests/ai/run-prompt-php.php).
 * Data lives in config/ai-prompts.json; production limits per technique come from config/defaults.json.
 * Design and rationale: docs/ai/02-prompting.md.
 *
 * Security notes:
 * - The prompt is ALWAYS built on the server from a stored brief. Never accept a finished prompt from the browser.
 * - Customer words sit in one delimited field and the fixed rules come after them (sandwich), so "ignore the rules"
 *   style text cannot remove the constraints.
 * - In text mode "studio" (default) the text that goes on the patch is NOT sent to the image service at all.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || defined( 'BPS_TESTING' ) || exit;

/**
 * Stateless prompt builder. All methods are static and deterministic.
 */
final class Prompt_Engine {

	/** Categories in lookup priority order (first match wins for a phrase). */
	const CATEGORIES = array( 'style', 'shape', 'color', 'group', 'subject', 'object', 'adj' );

	/** Verbs that carry no meaning for the image ("maak", "fais"...). */
	const VERBS = array( 'maak', 'maakt', 'maken', 'doe', 'zet', 'geef', 'teken', 'fais', 'faites', 'faire', 'rends', 'rendez', 'rendre', 'mets', 'mettre', 'dessine', 'graag', 'svp', 'aub', 'alstublieft', 'please', 'stp', 'merci' );

	/** Verbs that mean "add" / "remove" in a refine instruction. */
	const ADD_VERBS    = array( 'voeg', 'toevoegen', 'erbij', 'ajoute', 'ajouter', 'rajoute' );
	const REMOVE_VERBS = array( 'verwijder', 'weg', 'zonder', 'haal', 'enleve', 'enlever', 'supprime', 'supprimer', 'retire', 'retirer', 'sans' );

	/** Cached config. @var array|null */
	private static $cache = null;

	/**
	 * Load config/ai-prompts.json (cached).
	 *
	 * @param string|null $path Optional path (tests).
	 * @return array
	 */
	public static function load_config( ?string $path = null ): array {
		if ( null === $path && null !== self::$cache ) {
			return self::$cache;
		}
		$file = $path ?? dirname( __DIR__, 2 ) . '/config/ai-prompts.json';
		$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = is_array( $data ) ? $data : array();
		if ( null === $path ) {
			self::$cache = $data;
		}
		return $data;
	}

	/**
	 * Lowercase, strip accents, turn punctuation into spaces, collapse whitespace.
	 *
	 * @param string $s Input.
	 * @return string
	 */
	public static function normalize( string $s ): string {
		$s = mb_strtolower( $s, 'UTF-8' );
		$map = array(
			'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c',
			'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
			'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'œ' => 'oe', 'ù' => 'u',
			'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
		);
		$s = strtr( $s, $map );
		$s = preg_replace( '/[^a-z0-9%\/]+/u', ' ', $s );
		$s = str_replace( '/', ' ', (string) $s );
		return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
	}

	/**
	 * Remove personal data (e-mail, phone, IBAN, national number, street address, URL, person-name pairs).
	 *
	 * @param string $text Customer text.
	 * @param array  $cfg  Config.
	 * @return array{text:string, removed:string[]}
	 */
	public static function sanitize( string $text, array $cfg ): array {
		$removed = array();
		$text    = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
		foreach ( (array) ( $cfg['privacy']['patterns'] ?? array() ) as $type => $re ) {
			$count = 0;
			$text  = (string) preg_replace( self::rx( $re ), ' ', $text, -1, $count );
			if ( $count > 0 ) {
				$removed[] = $type;
			}
		}
		if ( ! empty( $cfg['privacy']['scrubNamePairs'] ) ) {
			$known = self::known_words( $cfg );
			$count = 0;
			$text  = (string) preg_replace_callback(
				'/(?<![\p{L}])\p{Lu}[\p{Ll}\'’-]+(?:\s+(?:van|de|der|den|le|la|du|von|d\'|di)?\s*\p{Lu}[\p{Ll}\'’-]+)+/u',
				static function ( $m ) use ( $known, &$count ) {
					foreach ( preg_split( '/\s+/u', $m[0] ) as $w ) {
						$n = self::normalize( $w );
						if ( '' !== $n && isset( $known[ $n ] ) ) {
							return $m[0];
						}
					}
					++$count;
					return ' ';
				},
				$text
			);
			if ( $count > 0 ) {
				$removed[] = 'name';
			}
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		$text = trim( (string) preg_replace( '/\s+([,.;:!?])/u', '$1', $text ), " ,;:" );
		return array( 'text' => $text, 'removed' => $removed );
	}

	/**
	 * Pull the text that should go ON the patch out of the description: “...”, "...", «...», „...“ or "tekst: ...".
	 *
	 * @param string $desc Description.
	 * @return array{description:string, text:string}
	 */
	public static function extract_text( string $desc ): array {
		$text = '';
		if ( preg_match( '/["“”„«]\s*([^"“”„«»]{1,80}?)\s*["“”»]/u', $desc, $m ) ) {
			$text = trim( $m[1] );
			$desc = str_replace( $m[0], ' ', $desc );
		} elseif ( preg_match( '/\b(?:tekst|opschrift|texte|inscription)\s*:\s*([^,.;\n]{1,60})/iu', $desc, $m ) ) {
			$text = trim( $m[1] );
			$desc = str_replace( $m[0], ' ', $desc );
		}
		if ( '' !== $text ) {
			$desc = preg_replace( '/\b(?:(?:met|en)\s+(?:de\s+|als\s+)?(?:tekst|opschrift|naam)|(?:avec|et)\s+(?:le\s+|comme\s+|l\')?(?:texte|inscription|nom)|tekst|opschrift|texte|inscription|erop)\b\s*:?/iu', ' ', $desc );
			$desc = preg_replace( '/\s+([,.;])/u', '$1', (string) $desc );
			$desc = preg_replace( '/([,.;])\s*[,.;]+/u', '$1', (string) $desc );
		}
		return array(
			'description' => trim( (string) preg_replace( '/\s+/u', ' ', (string) $desc ) ),
			'text'        => $text,
		);
	}

	/**
	 * Local safety filter. Returns the first problem found, or ok.
	 *
	 * @param string $desc Description (customer words).
	 * @param string $text Text for on the patch (checked for brands and hate only).
	 * @param array  $cfg  Config.
	 * @return array{ok:bool, code:string, match:string, softBrand:bool}
	 */
	public static function safety( string $desc, string $text, array $cfg ): array {
		$s    = $cfg['safety'] ?? array();
		$nd   = ' ' . self::normalize( $desc ) . ' ';
		$nt   = ' ' . self::normalize( $text ) . ' ';
		$both = $nd . ' | ' . $nt;
		$hit  = static function ( string $code, string $match ): array {
			return array( 'ok' => false, 'code' => $code, 'match' => trim( $match ), 'softBrand' => false );
		};

		foreach ( array( 'hate', 'adult', 'violence', 'drugs' ) as $code ) {
			foreach ( (array) ( $s[ $code ] ?? array() ) as $re ) {
				if ( preg_match( self::rx( $re ), $both, $m ) ) {
					return $hit( $code, $m[0] );
				}
			}
		}
		foreach ( (array) ( $s['brandsHard'] ?? array() ) as $brand ) {
			$b = ' ' . self::normalize( $brand ) . ' ';
			if ( false !== strpos( $both, $b ) ) {
				return $hit( 'brand', $brand );
			}
		}
		$soft = false;
		foreach ( (array) ( $s['brandsSoft'] ?? array() ) as $brand ) {
			$b = ' ' . self::normalize( $brand ) . ' ';
			if ( false === strpos( $both, $b ) ) {
				continue;
			}
			foreach ( (array) ( $s['softTriggers'] ?? array() ) as $t ) {
				if ( false !== strpos( $nd, ' ' . self::normalize( $t ) . ' ' ) ) {
					return $hit( 'brand', $brand );
				}
			}
			$soft = true;
		}
		$checks = array(
			'official' => $s['officialInsignia'] ?? array(),
			'person'   => $s['person'] ?? array(),
			'artist'   => $s['styleOfArtist'] ?? array(),
			'copy'     => $s['copyIntent'] ?? array(),
		);
		foreach ( $checks as $code => $list ) {
			foreach ( (array) $list as $re ) {
				if ( preg_match( self::rx( $re ), $nd, $m ) ) {
					return $hit( $code, $m[0] );
				}
			}
		}
		return array( 'ok' => true, 'code' => '', 'match' => '', 'softBrand' => $soft );
	}

	/**
	 * Customer-facing safety message.
	 *
	 * @param string $code Code.
	 * @param string $lang nl|fr.
	 * @param array  $cfg  Config.
	 * @return string
	 */
	public static function message( string $code, string $lang, array $cfg ): string {
		$m = $cfg['safety']['messages'][ $code ] ?? array();
		return (string) ( $m[ $lang ] ?? $m['nl'] ?? '' );
	}

	/**
	 * Glossary interpretation of free text (the translation layer, deterministic part).
	 *
	 * @param string $desc Description (already sanitised, text removed).
	 * @param array  $cfg  Config.
	 * @return array{en:string, style:?string, shape:?string, colors:string[], groups:string[], subjects:string[], objects:string[], adjs:string[], unknown:string[]}
	 */
	public static function interpret( string $desc, array $cfg, string $lang = 'nl' ): array {
		$g      = $cfg['glossary'] ?? array();
		$lex    = self::lexicon( $cfg );
		$stop   = array_flip( array_merge( $cfg['stopwords']['nl'] ?? array(), $cfg['stopwords']['fr'] ?? array(), self::VERBS ) );
		$conn   = $cfg['connectors'] ?? array();
		$tokens = '' === trim( $desc ) ? array() : explode( ' ', self::normalize( $desc ) );
		$out    = array(
			'en' => '', 'style' => null, 'shape' => null, 'colors' => array(), 'groups' => array(),
			'subjects' => array(), 'objects' => array(), 'adjs' => array(), 'unknown' => array(),
		);
		$words   = array();
		$pending = '';
		$n       = count( $tokens );
		$amb     = $cfg['ambiguous'] ?? array();
		for ( $i = 0; $i < $n; ) {
			$tok = $tokens[ $i ];
			if ( isset( $amb[ $tok ]['before'] ) && isset( $tokens[ $i + 1 ] ) && in_array( $tokens[ $i + 1 ], (array) $amb[ $tok ]['before'], true ) ) {
				$pending = (string) $amb[ $tok ]['en'];
				++$i;
				continue;
			}
			$matched = false;
			for ( $len = min( 4, $n - $i ); $len >= 1; $len-- ) {
				$phrase = implode( ' ', array_slice( $tokens, $i, $len ) );
				if ( ! isset( $lex[ $phrase ] ) ) {
					continue;
				}
				list( $cat, $en ) = $lex[ $phrase ];
				$matched = true;
				$i      += $len;
				switch ( $cat ) {
					case 'style':
						$out['style'] = $out['style'] ?? $en;
						break;
					case 'shape':
						$out['shape'] = $out['shape'] ?? $en;
						break;
					default:
						$key            = 'adj' === $cat ? 'adjs' : ( 'color' === $cat ? 'colors' : $cat . 's' );
						$out[ $key ][]  = $en;
						if ( '' !== $pending && ! empty( $words ) ) {
							$words[] = $pending;
						}
						$pending = '';
						$words[] = $en;
				}
				break;
			}
			if ( $matched ) {
				continue;
			}
			$t    = $tokens[ $i ];
			$stem = self::stem_match( $t, $lex );
			if ( null !== $stem && ! isset( $conn[ $t ] ) && ! isset( $stop[ $t ] ) ) {
				$tokens[ $i ] = $stem;
				continue;
			}
			++$i;
			if ( isset( $conn[ $t ] ) ) {
				$pending = $conn[ $t ];
			} elseif ( ! isset( $stop[ $t ] ) && ! is_numeric( $t ) && strlen( $t ) > 1 ) {
				$out['unknown'][] = $t;
			}
		}
		foreach ( array( 'colors', 'groups', 'subjects', 'objects', 'adjs', 'unknown' ) as $k ) {
			$out[ $k ] = array_values( array_unique( $out[ $k ] ) );
		}
		$out['en'] = self::tidy_gloss( $words, $out, 'fr' === $lang );
		unset( $g );
		return $out;
	}

	/**
	 * Build the brief (everything the prompt needs) from raw customer input.
	 *
	 * Input keys: description, lang (nl|fr), style (id|auto), shape (id|auto), technique, maxColors, text,
	 * textMode (auto|studio|ai|none), reserveText (bool), textPlacement, background (transparent|fabric|white),
	 * fabric (key of fabricColors), sizeMm, edge.
	 *
	 * @param array $input    Input.
	 * @param array $cfg      ai-prompts config.
	 * @param array $defaults defaults.json (optional, for technique rules and edge widths).
	 * @return array Brief. On a safety block: ['blocked'=>true, 'code'=>..., 'message'=>...].
	 */
	public static function brief( array $input, array $cfg, array $defaults = array() ): array {
		$lang  = 'fr' === ( $input['lang'] ?? 'nl' ) ? 'fr' : 'nl';
		$lim   = $cfg['limits'] ?? array();
		$raw   = mb_substr( (string) ( $input['description'] ?? '' ), 0, (int) ( $lim['descriptionMaxChars'] ?? 400 ) );
		$ex    = self::extract_text( $raw );
		$text  = trim( (string) ( $input['text'] ?? '' ) );
		$text  = '' !== $text ? $text : $ex['text'];
		$text  = mb_substr( (string) preg_replace( '/\s+/u', ' ', $text ), 0, 60 );
		$clean = self::sanitize( $ex['description'], $cfg );
		$tclean = self::sanitize( $text, array( 'privacy' => array( 'patterns' => $cfg['privacy']['patterns'] ?? array() ) ) );
		$text  = $tclean['text'];

		$warnings = array();
		if ( '' === trim( $clean['text'] ) && '' === $text ) {
			return array( 'blocked' => true, 'code' => 'empty', 'message' => self::message( 'empty', $lang, $cfg ) );
		}
		$safe = self::safety( $clean['text'], $text, $cfg );
		if ( ! $safe['ok'] ) {
			return array( 'blocked' => true, 'code' => $safe['code'], 'match' => $safe['match'], 'message' => self::message( $safe['code'], $lang, $cfg ) );
		}
		if ( ! empty( $clean['removed'] ) || ! empty( $tclean['removed'] ) ) {
			$warnings[] = 'personal_data_removed';
		}

		$seg       = self::shape_segments( $clean['text'], $cfg );
		$ip        = self::interpret( $seg['text'], $cfg, $lang );
		if ( null !== $seg['shape'] ) {
			$ip['shape'] = $seg['shape'];
		}
		$technique = (string) ( $input['technique'] ?? 'embroidered' );
		$technique = ( '_' !== substr( $technique, 0, 1 ) && isset( $cfg['techniqueColors'][ $technique ] ) ) ? $technique : 'embroidered';

		// Style: explicit > word in description > hint from group > badge.
		$style = ltrim( (string) ( $input['style'] ?? 'auto' ), '_' );
		if ( ! isset( $cfg['styles'][ $style ] ) ) {
			$style = $ip['style'] ?? '';
			if ( '' === $style ) {
				foreach ( $ip['groups'] as $grp ) {
					if ( isset( $cfg['groupStyleHints'][ $grp ] ) ) {
						$style = $cfg['groupStyleHints'][ $grp ];
						break;
					}
				}
			}
			if ( '' === $style && '' !== $text && empty( $ip['subjects'] ) && empty( $ip['objects'] ) ) {
				$style = 'typographic';
			}
			$style = isset( $cfg['styles'][ $style ] ) ? $style : 'badge';
		}
		$st = $cfg['styles'][ $style ];

		// Shape.
		$shape = ltrim( (string) ( $input['shape'] ?? 'auto' ), '_' );
		if ( ! isset( $cfg['shapes'][ $shape ] ) ) {
			$shape = $ip['shape'] ?? (string) ( $st['shapeHint'] ?? 'circle' );
			$shape = isset( $cfg['shapes'][ $shape ] ) ? $shape : 'circle';
		}

		// Colors.
		$tc      = $cfg['techniqueColors'][ $technique ];
		$colors  = $ip['colors'];
		$max     = isset( $input['maxColors'] ) ? (int) $input['maxColors'] : max( min( (int) ( $st['defaultColors'] ?? $tc['default'] ), (int) $tc['default'] ), count( $colors ) );
		$max     = max( (int) ( $lim['minColors'] ?? 1 ), min( $max, (int) $tc['max'], (int) ( $lim['maxColorsAbsolute'] ?? 8 ) ) );
		if ( count( $colors ) > $max ) {
			$warnings[] = 'too_many_colors';
			$colors     = array_slice( $colors, 0, $max );
		}

		// Text mode.
		$mode   = (string) ( $input['textMode'] ?? 'auto' );
		$aiOk   = '' !== $text && mb_strlen( $text ) <= (int) ( $lim['aiTextMaxChars'] ?? 24 )
			&& count( preg_split( '/\s+/u', $text ) ) <= (int) ( $lim['aiTextMaxWords'] ?? 4 );
		if ( 'auto' === $mode ) {
			$mode = ( '' !== $text && $aiOk && ! empty( $st['prefersAiText'] ) ) ? 'ai' : ( '' !== $text || ! empty( $input['reserveText'] ) ? 'studio' : 'none' );
		}
		if ( 'ai' === $mode && ! $aiOk ) {
			$warnings[] = '' === $text ? 'ai_text_missing' : 'text_too_long_for_ai';
			$mode       = 'studio';
		}
		if ( ! in_array( $mode, array( 'studio', 'ai', 'none' ), true ) ) {
			$mode = 'none';
		}
		$placement = ltrim( (string) ( $input['textPlacement'] ?? ( $st['textPlacement'] ?? 'below' ) ), '_' );
		$placement = isset( $cfg['textPlacements'][ $placement ] ) ? $placement : 'below';
		if ( 'none' === $placement && 'none' !== $mode ) {
			$placement = 'below';
		}
		if ( 'contour' === $shape && 0 === strpos( $placement, 'arc_' ) ) {
			$placement = 'below';
		}

		// Background.
		$bg     = ltrim( (string) ( $input['background'] ?? 'transparent' ), '_' );
		$bg     = isset( $cfg['backgrounds'][ $bg ] ) ? $bg : 'transparent';
		$fabric = (string) ( $input['fabric'] ?? 'black' );
		$fabric = isset( $cfg['fabricColors'][ $fabric ] ) ? $fabric : 'black';

		// Geometry from production rules.
		$size  = max( 25, min( 300, (int) ( $input['sizeMm'] ?? 80 ) ) );
		$rules = $defaults['techniques'][ $technique ]['rules'] ?? array();
		$edge  = (string) ( $input['edge'] ?? ( $defaults['techniques'][ $technique ]['defaults']['edge'] ?? '' ) );
		$geo   = self::geometry( $size, $rules, self::edge_margin_mm( $edge, $defaults ) );
		if ( 'ai' === $mode && $geo['capPctRaw'] > 30 ) {
			$warnings[] = 'text_small_for_size';
		}

		$meaningful = ! empty( $ip['subjects'] ) || ! empty( $ip['objects'] ) || ! empty( $ip['groups'] ) || ! empty( $ip['adjs'] ) || ! empty( $ip['unknown'] );
		$original   = $meaningful ? self::customer_words( $clean['text'] ) : '';
		return array(
			'blocked'     => false,
			'lang'        => $lang,
			'style'       => $style,
			'shape'       => $shape,
			'technique'   => $technique,
			'maxColors'   => $max,
			'colors'      => $colors,
			'subjectEn'   => $ip['en'],
			'original'    => $original,
			'unknown'     => $ip['unknown'],
			'text'        => $text,
			'textMode'    => $mode,
			'placement'   => $placement,
			'background'  => $bg,
			'fabric'      => $fabric,
			'sizeMm'      => $size,
			'geometry'    => $geo,
			'softBrand'   => $safe['softBrand'],
			'warnings'    => array_values( array_unique( $warnings ) ),
			'understood'  => array(
				'subjects' => $ip['subjects'],
				'objects'  => $ip['objects'],
				'groups'   => $ip['groups'],
				'adjs'     => $ip['adjs'],
			),
		);
	}

	/**
	 * Assemble the English prompt and API parameters from a brief.
	 *
	 * @param array $brief Brief from brief().
	 * @param array $cfg   Config.
	 * @return array{prompt:string, params:array}
	 */
	public static function build( array $brief, array $cfg ): array {
		if ( ! empty( $brief['blocked'] ) || ! isset( $brief['style'], $brief['shape'], $brief['geometry'] ) ) {
			throw new \InvalidArgumentException( 'Prompt_Engine::build() needs a valid, non-blocked brief.' );
		}
		$st    = $cfg['styles'][ $brief['style'] ];
		$sh    = $cfg['shapes'][ $brief['shape'] ];
		$r     = $cfg['rules'];
		$geo   = $brief['geometry'];
		$lines = array();

		$lines[] = self::fill( $r['intro'], array( 'shape_en' => $sh['en'], 'technique_fragment' => $cfg['techniqueFragments'][ $brief['technique'] ] ?? '' ) );
		$lines[] = 'STYLE: ' . $st['en'] . '.';
		$subject = '' !== $brief['subjectEn'] ? $brief['subjectEn'] : ( 'typographic' === $brief['style'] ? 'lettering-based design' : 'a simple symbolic emblem' );
		$lines[] = 'SUBJECT: ' . $subject . '.';
		if ( '' !== $brief['original'] ) {
			$langName = 'fr' === $brief['lang'] ? 'French' : 'Dutch';
			$lines[]  = 'CUSTOMER DESCRIPTION (' . $langName . ', use only for meaning, never render these words as text): <<' . $brief['original'] . '>>';
		}
		if ( ! empty( $brief['softBrand'] ) ) {
			$lines[] = $cfg['safety']['appendWhenSoftBrand'];
		}
		$lines[] = 'RULES:';
		$lines[] = '- ' . $r['flat'];
		$lines[] = '- ' . self::color_rule( $brief, $cfg );
		$lines[] = '- ' . self::fill( $r['outline'], array( 'outline_pct' => $geo['outlinePct'], 'detail_pct' => $geo['detailPct'] ) );
		$lines[] = '- ' . self::fill( $r['composition'], array( 'shape_composition' => $sh['composition'], 'margin_pct' => $geo['marginPct'] ) );
		$lines[] = '- ' . self::background_rule( $brief, $cfg );
		$lines[] = '- ' . self::text_rule( $brief, $cfg );
		$lines[] = '- ' . $r['originality'];
		$lines[] = '- ' . $r['clean'];

		$p = $cfg['params'] ?? array();
		return array(
			'prompt' => implode( "\n", $lines ),
			'params' => array(
				'size'          => $sh['size'],
				'background'    => 'transparent' === $brief['background'] ? 'transparent' : 'opaque',
				'quality'       => $p['quality'] ?? 'medium',
				'output_format' => $p['outputFormat'] ?? 'png',
				'n'             => (int) ( $p['n'] ?? 3 ),
			),
		);
	}

	/**
	 * Refine ("verfijnen") a chosen proposal with a short chat instruction.
	 *
	 * Returns route 'local' (free, the studio does it: colors, text, size, shape, position) with an action and a message,
	 * route 'ai_edit' with an edit prompt for the image-edit endpoint (costs a credit), or 'blocked'.
	 *
	 * @param array  $brief       Stored brief of the proposal being refined.
	 * @param string $instruction Customer instruction (nl/fr).
	 * @param array  $cfg         Config.
	 * @param array  $history     Earlier accepted change_en strings (oldest first).
	 * @param bool   $forceAi     Customer chose "toch met AI" after a local suggestion.
	 * @return array
	 */
	public static function refine( array $brief, string $instruction, array $cfg, array $history = array(), bool $forceAi = false ): array {
		$lang  = $brief['lang'] ?? 'nl';
		$instr = mb_substr( $instruction, 0, (int) ( $cfg['limits']['refineMaxChars'] ?? 200 ) );
		$ex    = self::extract_text( $instr );
		$clean = self::sanitize( $ex['description'], $cfg );
		if ( '' === trim( $clean['text'] ) && '' === $ex['text'] ) {
			return array( 'route' => 'blocked', 'code' => 'empty', 'message' => self::message( 'empty', $lang, $cfg ) );
		}
		$safe = self::safety( $clean['text'], $ex['text'], $cfg );
		if ( ! $safe['ok'] ) {
			return array( 'route' => 'blocked', 'code' => $safe['code'], 'message' => self::message( $safe['code'], $lang, $cfg ) );
		}
		$ip   = self::interpret( $clean['text'], $cfg, $lang );
		$norm = ' ' . self::normalize( $clean['text'] ) . ' ';

		if ( ! $forceAi ) {
			$local = self::local_route( $norm, $ip, $ex['text'], $cfg, $lang );
			if ( null !== $local ) {
				return $local;
			}
		}

		$change = self::change_phrase( $norm, $ip );
		$langName = 'fr' === $lang ? 'French' : 'Dutch';
		$change  .= ' (customer\'s words in ' . $langName . ': <<' . self::customer_words( $clean['text'] ) . '>>)';

		$e        = $cfg['edit'];
		$keep     = empty( $ip['colors'] ) ? $e['keepColors'] : '';
		$textMode = $brief['textMode'] ?? 'none';
		$keep    .= 'ai' === $textMode ? $e['keepText'] : $e['keepNoText'];
		$hist     = array_slice( array_values( array_filter( $history, 'is_string' ) ), - (int) ( $cfg['limits']['refineHistoryKeep'] ?? 3 ) );
		$histTxt  = empty( $hist ) ? '' : self::fill( $e['history'], array( 'items' => implode( '; ', $hist ) ) );
		$prompt   = self::fill(
			$e['template'],
			array(
				'change'     => $change,
				'shape_en'   => $cfg['shapes'][ $brief['shape'] ]['noun'] ?? 'patch',
				'keep_extra' => $keep,
				'history'    => $histTxt,
				'rules_short' => self::fill( $e['rulesShort'], array( 'max_colors' => $brief['maxColors'] ?? 6 ) ),
			)
		);
		$hist[] = self::change_phrase( $norm, $ip );
		return array(
			'route'   => 'ai_edit',
			'prompt'  => $prompt,
			'params'  => array(
				'size'       => $cfg['shapes'][ $brief['shape'] ]['size'] ?? '1024x1024',
				'background' => 'transparent' === ( $brief['background'] ?? '' ) ? 'transparent' : 'opaque',
				'n'          => (int) ( $cfg['params']['refineN'] ?? 2 ),
			),
			'history' => array_slice( $hist, - (int) ( $cfg['limits']['refineHistoryKeep'] ?? 3 ) ),
		);
	}

	/**
	 * Spell text letter by letter for the image model: "WOLF MC" -> "W-O-L-F / M-C".
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function spell( string $text ): string {
		$words = preg_split( '/\s+/u', trim( $text ) );
		$out   = array();
		foreach ( $words as $w ) {
			$out[] = implode( '-', preg_split( '//u', $w, -1, PREG_SPLIT_NO_EMPTY ) );
		}
		return implode( ' / ', $out );
	}

	/* ----------------------------------------------------------------- helpers */

	/**
	 * Flattened lexicon: normalized phrase => [category, english].
	 *
	 * @param array $cfg Config.
	 * @return array
	 */
	private static function lexicon( array $cfg ): array {
		$lex = array();
		foreach ( self::CATEGORIES as $cat ) {
			foreach ( (array) ( $cfg['glossary'][ $cat ] ?? array() ) as $k => $v ) {
				$nk = self::normalize( (string) $k );
				if ( '' !== $nk && ! isset( $lex[ $nk ] ) ) {
					$lex[ $nk ] = array( $cat, (string) $v );
				}
			}
		}
		return $lex;
	}

	/**
	 * Comma-separated pieces that only name a shape ("..., schild") set the patch shape and are removed.
	 *
	 * @param string $text Description.
	 * @param array  $cfg  Config.
	 * @return array{text:string, shape:?string}
	 */
	private static function shape_segments( string $text, array $cfg ): array {
		$map   = $cfg['shapeSegments'] ?? array();
		$shape = null;
		$keep  = array();
		foreach ( preg_split( '/\s*[,;]\s*/u', $text ) as $part ) {
			$n = self::normalize( $part );
			if ( '' !== $n && '_doc' !== $n && isset( $map[ $n ] ) ) {
				$shape = $shape ?? (string) $map[ $n ];
				continue;
			}
			$keep[] = $part;
		}
		return array( 'text' => implode( ', ', $keep ), 'shape' => $shape );
	}

	/**
	 * Simple inflection fallback: "brullende" -> "brullend", "loups" -> "loup", "sterren" stays a lexicon word.
	 *
	 * @param string $t   Token.
	 * @param array  $lex Lexicon.
	 * @return string|null Lexicon key or null.
	 */
	private static function stem_match( string $t, array $lex ): ?string {
		if ( isset( $lex[ $t ] ) || strlen( $t ) < 4 ) {
			return null;
		}
		foreach ( array( 'e', 'en', 's', 'x', 'es', 'er' ) as $suf ) {
			$l = strlen( $suf );
			if ( substr( $t, -$l ) === $suf ) {
				$base = substr( $t, 0, -$l );
				if ( isset( $lex[ $base ] ) ) {
					return $base;
				}
			}
		}
		return null;
	}

	/**
	 * Words that are never a person's name (glossary, stopwords, connectors).
	 *
	 * @param array $cfg Config.
	 * @return array
	 */
	private static function known_words( array $cfg ): array {
		$known = array();
		foreach ( self::CATEGORIES as $cat ) {
			foreach ( array_keys( (array) ( $cfg['glossary'][ $cat ] ?? array() ) ) as $k ) {
				foreach ( explode( ' ', self::normalize( (string) $k ) ) as $w ) {
					$known[ $w ] = true;
				}
			}
		}
		foreach ( array_merge( $cfg['stopwords']['nl'] ?? array(), $cfg['stopwords']['fr'] ?? array(), array_keys( $cfg['connectors'] ?? array() ), self::VERBS ) as $w ) {
			$known[ self::normalize( (string) $w ) ] = true;
		}
		return $known;
	}

	/**
	 * Gloss words into a readable English phrase: adjectives first, then nouns; colors are handled by the color rule.
	 *
	 * @param array $words Ordered English words incl. connectors.
	 * @param array $ip    Interpretation so far.
	 * @return string
	 */
	private static function tidy_gloss( array $words, array $ip, bool $postAdj = false ): string {
		$colors = array_flip( $ip['colors'] );
		$adjs   = array_flip( $ip['adjs'] );
		$links  = array( 'with', 'for', 'and', 'on', 'in', 'above', 'below', 'behind', 'between', 'without', 'around' );
		$parts  = array(); // Each: ['adj'=>[], 'noun'=>string, 'group'=>bool] or a link string.
		$adjBuf = array();
		foreach ( $words as $w ) {
			if ( isset( $colors[ $w ] ) ) {
				continue;
			}
			if ( isset( $adjs[ $w ] ) ) {
				$last = end( $parts );
				if ( $postAdj && is_array( $last ) && empty( $adjBuf ) ) {
					$parts[ key( $parts ) ]['adj'][] = $w; // French: adjective after the noun.
				} else {
					$adjBuf[] = $w;
				}
				continue;
			}
			if ( in_array( $w, $links, true ) ) {
				if ( ! empty( $parts ) && is_array( end( $parts ) ) ) {
					$parts[] = $w;
				}
				continue;
			}
			$parts[] = array( 'adj' => $adjBuf, 'noun' => $w, 'group' => in_array( $w, $ip['groups'], true ) );
			$adjBuf  = array();
		}
		while ( ! empty( $parts ) && is_string( end( $parts ) ) ) {
			array_pop( $parts );
		}
		$out    = array();
		$groups = array();
		$prev   = null;
		foreach ( $parts as $p ) {
			if ( is_string( $p ) ) {
				if ( ! empty( $out ) ) { // A link after a leading group ("club ... with rackets") has nothing to attach to.
					$out[] = $p;
				}
				$prev = $p;
				continue;
			}
			$pre  = array_values( array_filter( $p['adj'], static fn( $a ) => 0 !== strpos( $a, 'with ' ) ) );
			$post = array_values( array_filter( $p['adj'], static fn( $a ) => 0 === strpos( $a, 'with ' ) ) );
			$phrase = self::article( $pre[0] ?? $p['noun'], $p['noun'] )
				. implode( ' ', array_merge( $pre, array( $p['noun'] ) ) ) . ( empty( $post ) ? '' : ' ' . implode( ' ', $post ) );
			if ( $p['group'] && ! is_string( $prev ) ) {
				$groups[] = $phrase; // "scouts: een vos..." -> "... for a scout group".
			} else {
				$out[] = $phrase;
			}
			$prev = $p;
		}
		while ( ! empty( $out ) && in_array( end( $out ), $links, true ) ) {
			array_pop( $out );
		}
		if ( ! empty( $groups ) ) {
			$out[] = ( empty( $out ) ? '' : 'for ' ) . implode( ' and ', $groups );
		}
		$s = implode( ' ', $out );
		if ( ! empty( $adjBuf ) ) {
			$s = trim( $s . ( '' !== $s ? ', ' : '' ) . implode( ' and ', $adjBuf ) );
		}
		return $s;
	}

	/**
	 * Indefinite article for an English noun phrase ('' for plurals).
	 *
	 * @param string $w First word.
	 * @return string
	 */
	private static function article( string $w, string $noun = '' ): string {
		$noun = '' === $noun ? $w : $noun;
		if ( in_array( $noun, array( 'dice', 'fries', 'grapes', 'playing cards', 'glasses', 'hands' ), true ) ) {
			return '';
		}
		if ( preg_match( '/s$/', $noun ) && ! preg_match( '/(ss|us|is)$/', $noun ) ) {
			return '';
		}
		return preg_match( '/^[aeiou]/', $w ) ? 'an ' : 'a ';
	}

	/**
	 * Customer words for the delimited field: no quotes/brackets, single line, trimmed.
	 *
	 * @param string $s Sanitised text.
	 * @return string
	 */
	private static function customer_words( string $s ): string {
		$s = (string) preg_replace( '/[<>{}\[\]"“”„«»`\\\\]/u', ' ', $s );
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}

	/**
	 * Percentages for outline, detail, letters and margin, derived from the production rules and patch size.
	 *
	 * @param int   $sizeMm   Patch size (largest side).
	 * @param array $rules    defaults.json techniques.X.rules.
	 * @param float $marginMm Edge width + safe zone.
	 * @return array
	 */
	private static function geometry( int $sizeMm, array $rules, float $marginMm ): array {
		$line   = (float) ( $rules['lineMm']['comfort'] ?? 1.5 );
		$detail = (float) ( $rules['smallestElementMm']['comfort'] ?? 3 );
		$caps   = (float) ( $rules['textCapsMm']['comfort'] ?? 6 );
		$pct    = static fn( float $mm ) => $mm / $sizeMm * 100;
		$capRaw = $pct( $caps );
		return array(
			'outlinePct' => self::r( max( 1.5, min( 6, $pct( $line * 1.3 ) ) ) ),
			'detailPct'  => self::r( max( 2, min( 12, $pct( $detail ) ) ) ),
			'capPct'     => self::r( max( 8, min( 30, $capRaw ) ) ),
			'capPctRaw'  => self::r( $capRaw ),
			'marginPct'  => self::r( max( 4, min( 15, $pct( $marginMm ) ) ) ),
		);
	}

	/**
	 * Round to 1 decimal, drop ".0".
	 *
	 * @param float $v Value.
	 * @return float|int
	 */
	private static function r( float $v ) {
		$v = round( $v, 1 );
		return floor( $v ) === $v ? (int) $v : $v;
	}

	/**
	 * Edge width + safe zone in mm for an edge option (defaults.json options[].render).
	 *
	 * @param string $edge     Option id.
	 * @param array  $defaults defaults.json.
	 * @return float
	 */
	private static function edge_margin_mm( string $edge, array $defaults ): float {
		foreach ( (array) ( $defaults['options'] ?? array() ) as $o ) {
			if ( ( $o['id'] ?? '' ) === $edge ) {
				$w = (float) ( $o['render']['widthMm'] ?? 0 ) + (float) ( $o['render']['safeZoneMm'] ?? 0 );
				return $w > 0 ? $w : 4.0;
			}
		}
		return 5.0;
	}

	/**
	 * Color rule line.
	 *
	 * @param array $b   Brief.
	 * @param array $cfg Config.
	 * @return string
	 */
	private static function color_rule( array $b, array $cfg ): string {
		if ( 1 === (int) $b['maxColors'] ) {
			return 'Use one single dark color only for the whole design (plus the background), like a stamp. No second color, no grey tones.';
		}
		$list = '';
		if ( ! empty( $b['colors'] ) ) {
			$list = ', built around these colors: ' . implode( ', ', $b['colors'] );
			if ( count( $b['colors'] ) < (int) $b['maxColors'] ) {
				$list .= ' (you may add a neutral color such as white or black for outlines and details, staying within the limit)';
			}
		}
		return self::fill( $cfg['rules']['colors'], array( 'max_colors' => $b['maxColors'], 'color_list' => $list ) );
	}

	/**
	 * Background rule line.
	 *
	 * @param array $b   Brief.
	 * @param array $cfg Config.
	 * @return string
	 */
	private static function background_rule( array $b, array $cfg ): string {
		$tpl = $cfg['backgrounds'][ $b['background'] ];
		return self::fill( $tpl, array( 'fabric_en' => $cfg['fabricColors'][ $b['fabric'] ] ?? 'black' ) );
	}

	/**
	 * Text rule line.
	 *
	 * @param array $b   Brief.
	 * @param array $cfg Config.
	 * @return string
	 */
	private static function text_rule( array $b, array $cfg ): string {
		$tm = $cfg['textModes'];
		if ( 'ai' === $b['textMode'] ) {
			$font = array(
				'vintage'  => 'rounded retro',
				'military' => 'stencil',
				'club'     => 'collegiate block',
				'mascot'   => 'sporty slab',
			)[ $b['style'] ] ?? 'sans-serif block';
			$accent = preg_match( '/[^\x20-\x7E]/u', $b['text'] ) ? ', including every accent exactly as written' : '';
			return self::fill(
				$tm['ai'],
				array(
					'text'         => self::customer_words( $b['text'] ),
					'spelled'      => self::spell( self::customer_words( $b['text'] ) ),
					'font_kind'    => $font,
					'placement_ai' => $cfg['aiTextPlacements'][ $b['placement'] ] ?? 'the most natural free area',
					'cap_pct'      => $b['geometry']['capPct'],
					'accent_note'  => $accent,
				)
			);
		}
		if ( 'studio' === $b['textMode'] ) {
			$reserve = self::fill( $tm['studio_reserve'], array( 'placement' => $cfg['textPlacements'][ $b['placement'] ] ?? 'an empty band below the symbol' ) );
			return self::fill( $tm['studio'], array( 'reserve_rule' => $reserve ) );
		}
		return $tm['none'];
	}

	/**
	 * Local (free) route for a refine instruction, or null.
	 *
	 * @param string $norm Normalized instruction, padded with spaces.
	 * @param array  $ip   Interpretation.
	 * @param string $text Quoted text in the instruction.
	 * @param array  $cfg  Config.
	 * @param string $lang Lang.
	 * @return array|null
	 */
	private static function local_route( string $norm, array $ip, string $text, array $cfg, string $lang ): ?array {
		$rl       = $cfg['refineLocal'] ?? array();
		$hasThing = ! empty( $ip['subjects'] ) || ! empty( $ip['objects'] );
		$pick     = static function ( string $key ) use ( $rl, $lang ): array {
			return array(
				'route'      => 'local',
				'action'     => $rl[ $key ]['action'],
				'message'    => $rl[ $key ]['message'][ $lang ] ?? $rl[ $key ]['message']['nl'],
				'aiFallback' => true,
			);
		};
		$matches = static function ( string $key ) use ( $rl, $norm ): bool {
			foreach ( (array) ( $rl[ $key ]['patterns'] ?? array() ) as $re ) {
				if ( '@' !== $re[0] && preg_match( self::rx( $re ), $norm ) ) {
					return true;
				}
			}
			return false;
		};
		if ( '' !== $text || $matches( 'text' ) ) {
			$r = $pick( 'text' );
			if ( '' !== $text ) {
				$r['text'] = $text;
			}
			return $r;
		}
		if ( $matches( 'fewer' ) ) {
			return $pick( 'fewer' );
		}
		$onlyColors = ! empty( $ip['colors'] ) && empty( $ip['adjs'] ) && empty( $ip['unknown'] ) && empty( $ip['groups'] );
		if ( $matches( 'color' ) || $onlyColors ) {
			$r = $pick( 'color' );
			if ( ! empty( $ip['colors'] ) ) {
				$r['colors'] = $ip['colors'];
			}
			return $r;
		}
		if ( ! $hasThing && $matches( 'size' ) ) {
			return $pick( 'size' );
		}
		if ( ( ! $hasThing || preg_match( '/ (patch|vorm|forme|ecusson) /', $norm ) ) && ( null !== $ip['shape'] || $matches( 'shape' ) ) && empty( $ip['adjs'] ) ) {
			$r = $pick( 'shape' );
			if ( null !== $ip['shape'] ) {
				$r['shape'] = $ip['shape'];
			}
			return $r;
		}
		if ( ! $hasThing && $matches( 'move' ) ) {
			return $pick( 'move' );
		}
		return null;
	}

	/**
	 * English "change only" phrase for an edit instruction.
	 *
	 * @param string $norm Normalized instruction (padded).
	 * @param array  $ip   Interpretation.
	 * @return string
	 */
	private static function change_phrase( string $norm, array $ip ): string {
		$things = array_merge( $ip['subjects'], $ip['objects'], $ip['groups'] );
		$has    = static function ( array $verbs ) use ( $norm ): bool {
			foreach ( $verbs as $v ) {
				if ( false !== strpos( $norm, ' ' . $v . ' ' ) ) {
					return true;
				}
			}
			return false;
		};
		$thing = empty( $things ) ? 'the design' : implode( ' and ', $things );
		if ( $has( self::REMOVE_VERBS ) ) {
			return 'remove the ' . ( empty( $things ) ? 'mentioned element' : $thing );
		}
		if ( $has( self::ADD_VERBS ) ) {
			$adj = empty( $ip['adjs'] ) ? '' : implode( ' ', $ip['adjs'] ) . ' ';
			return 'add ' . self::article( $adj . $thing ) . $adj . $thing;
		}
		$quals = array_merge( $ip['adjs'], $ip['colors'] );
		if ( ! empty( $quals ) ) {
			$prefix = empty( $things ) ? 'make the design ' : 'make the ' . $thing . ' ';
			return $prefix . implode( ' and ', $quals );
		}
		return '' !== $ip['en'] ? 'adjust: ' . $ip['en'] : 'apply the customer\'s change';
	}

	/**
	 * Compile a config regex with a delimiter that never clashes ("/" appears in patterns like 14/88).
	 *
	 * @param string $re Pattern body.
	 * @return string
	 */
	private static function rx( string $re ): string {
		return '~' . str_replace( '~', '\~', $re ) . '~u';
	}

	/**
	 * Replace {placeholders}.
	 *
	 * @param string $tpl  Template.
	 * @param array  $vars Values.
	 * @return string
	 */
	private static function fill( string $tpl, array $vars ): string {
		$rep = array();
		foreach ( $vars as $k => $v ) {
			$rep[ '{' . $k . '}' ] = (string) $v;
		}
		return trim( strtr( $tpl, $rep ) );
	}
}
