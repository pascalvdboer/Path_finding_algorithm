<?php
/**
 * Prompt adapter: the thin layer between the REST API / studio and the prompt engine (SPEC-AI-DIENSTEN §7).
 *
 * - input():      raw REST body + studio context → input for Prompt_Engine::brief(). The browser never sends a prompt
 *                 or a brief; this only maps and validates choice fields and fills gaps from the studio defaults.
 * - understood(): brief → the public "Zo begrepen we je" object (§4.3) in the customer's language, with human labels
 *                 and hex colours. Never the English prompt, never the safety lists.
 * - alt(), hints(), local_refine(), public_styles(): small helpers for JobResponse, ChooseResponse, LocalRefine and
 *                 public_config.ai.styles.
 *
 * Pure PHP and deterministic: no WordPress calls, no network. Config comes from config/ai-prompts.json (via
 * Module::prompt_cfg() when the AI module is loaded) and config/defaults.json (via Module::studio_defaults()).
 * Tests: tests/ai/run-prompt-adapter-php.php.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\AI;

defined( 'ABSPATH' ) || defined( 'BPS_TESTING' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\Prompt_Engine', false ) ) {
	require_once __DIR__ . '/class-ai-prompt-engine.php';
}

/**
 * Stateless adapter. All methods are static.
 */
final class Prompt_Adapter {

	/** Studio actions a free (local) refine may ask for — SPEC §10.3 event bps:ai:local:action. */
	const LOCAL_ACTIONS = array( 'open_text', 'open_colors', 'reduce_colors', 'open_size', 'open_shape', 'open_arrange' );

	/** Allowed text modes and backgrounds (SPEC §4.3, Prompt_Engine::brief()). */
	const TEXT_MODES  = array( 'auto', 'studio', 'ai', 'none' );
	const BACKGROUNDS = array( 'transparent', 'fabric', 'white' );

	/** Number words that may precede "kleur(en)" / "couleur(s)" in a description. */
	const COUNT_WORDS = array(
		'1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7, '8' => 8,
		'één' => 1, 'eén' => 1, 'twee' => 2, 'drie' => 3, 'vier' => 4, 'vijf' => 5, 'zes' => 6,
		'une seule' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6,
	);

	/** Glossary categories that describe the design itself (shown as the subject chip). */
	const SUBJECT_CATS = array( 'subject', 'object', 'group', 'adj' );

	/** Max length of the subject part of the summary. */
	const SUBJECT_MAX = 60;

	/* =====================================================================
	 * REST → brief input
	 * =================================================================== */

	/**
	 * Map a (validated) REST body and the studio context to the input of Prompt_Engine::brief().
	 *
	 * Precedence: explicit customer choice > words in the description (handled by the engine) > what the studio
	 * already knows (context) > defaults.json. Unknown or invalid values fall back silently: a customer never gets an
	 * error for a choice field.
	 *
	 * @param array      $rest       Body of POST /ai/generate (description, text, lang, style, shape, maxColors,
	 *                               textMode, background?, context?).
	 * @param array      $studio_ctx Studio context (technique, sizeMm, fabric, edge, shape + shapeChosen). Falls back to
	 *                               $rest['context'] when empty.
	 * @param array|null $defaults   defaults.json shape (null = Module::studio_defaults() or the bundled file).
	 * @param array|null $cfg        ai-prompts.json (null = Module::prompt_cfg() or the bundled file).
	 * @return array Input for Prompt_Engine::brief().
	 */
	public static function input( array $rest, array $studio_ctx = array(), ?array $defaults = null, ?array $cfg = null ): array {
		$cfg      = $cfg ?? self::cfg();
		$defaults = $defaults ?? self::defaults();
		$ctx      = ! empty( $studio_ctx ) ? $studio_ctx : ( is_array( $rest['context'] ?? null ) ? $rest['context'] : array() );

		$desc = self::one_line( (string) ( $rest['description'] ?? '' ) );
		$text = self::one_line( (string) ( $rest['text'] ?? '' ) );
		$lang = 'fr' === ( $rest['lang'] ?? 'nl' ) ? 'fr' : 'nl';

		$style = self::pick_key( (string) ( $rest['style'] ?? 'auto' ), (array) ( $cfg['styles'] ?? array() ), 'auto' );
		$shape = self::pick_key( (string) ( $rest['shape'] ?? 'auto' ), (array) ( $cfg['shapes'] ?? array() ), 'auto' );
		if ( 'auto' === $shape && ! empty( $ctx['shapeChosen'] ) ) { // Only a shape the customer picked in the studio, never its default.
			$from_ctx = self::pick_key( (string) ( $ctx['shape'] ?? '' ), (array) ( $cfg['shapes'] ?? array() ), '' );
			if ( '' !== $from_ctx && ! self::mentions_shape( $desc, $cfg ) ) {
				$shape = $from_ctx;
			}
		}

		$mode = (string) ( $rest['textMode'] ?? 'auto' );
		$mode = in_array( $mode, self::TEXT_MODES, true ) ? $mode : 'auto';
		$bg   = (string) ( $rest['background'] ?? 'transparent' );
		$bg   = in_array( $bg, self::BACKGROUNDS, true ) ? $bg : 'transparent';

		$out = array(
			'description' => $desc,
			'lang'        => $lang,
			'style'       => $style,
			'shape'       => $shape,
			'technique'   => self::technique( (string) ( $ctx['technique'] ?? '' ), $cfg, $defaults ),
			'text'        => $text,
			'textMode'    => $mode,
			'reserveText' => 'studio' === $mode,
			'background'  => $bg,
			'fabric'      => self::fabric( (string) ( $ctx['fabric'] ?? '' ), $cfg, $defaults ),
			'sizeMm'      => self::size_mm( $ctx['sizeMm'] ?? null, $defaults, '' === $desc && '' !== $text ),
		);

		$max = $rest['maxColors'] ?? null;
		if ( null !== $max && '' !== $max && is_numeric( $max ) ) {
			$out['maxColors'] = max( 1, min( 8, (int) $max ) );
		} else {
			$hint = self::color_count_hint( $desc );
			if ( null !== $hint ) {
				$out['maxColors'] = $hint;
			}
		}

		$edge = self::edge( (string) ( $ctx['edge'] ?? '' ), $defaults );
		if ( '' !== $edge ) {
			$out['edge'] = $edge;
		}
		return $out;
	}

	/**
	 * "Only one colour", "2 kleuren", "drie kleuren", "monochrome" → a colour count, or null.
	 *
	 * Deliberately strict: "een kleur" (also "a colour") is NOT counted, "één kleur" is.
	 *
	 * @param string $desc Description.
	 * @return int|null 1..8 or null.
	 */
	public static function color_count_hint( string $desc ): ?int {
		$s = mb_strtolower( $desc, 'UTF-8' );
		if ( preg_match( '/\b(?:monochro(?:om|me)|eenkleurig\w*|unicolore|une?\s+seule?\s+couleur|(?:in|en)\s+(?:één|eén)\s+kleur)\b/u', $s ) ) {
			return 1;
		}
		$words = implode( '|', array_map( static fn( $w ) => preg_quote( (string) $w, '/' ), array_keys( self::COUNT_WORDS ) ) );
		if ( preg_match( '/(?<![\p{L}\p{N}])(' . $words . ')\s+(?:garen)?(?:kleur|kleuren|couleur|couleurs)\b/u', $s, $m ) ) {
			$n = self::COUNT_WORDS[ $m[1] ] ?? null;
			if ( 'une' === $m[1] ) {
				return null; // "une couleur vive" is not a count.
			}
			return null === $n ? null : max( 1, min( 8, (int) $n ) );
		}
		return null;
	}

	/* =====================================================================
	 * brief → public fields
	 * =================================================================== */

	/**
	 * The "Zo begrepen we je" object for JobResponse.understood (SPEC §4.3). Never contains the prompt.
	 *
	 * @param array      $brief Brief from Prompt_Engine::brief() (non-blocked).
	 * @param string     $lang  nl|fr.
	 * @param array|null $cfg   ai-prompts.json.
	 * @return array{summary:string, subject:string, style:string, shape:string, colors:string[], colorLabels:string[], maxColors:int, textMode:string, text:string, placement:string, warnings:string[], notes:array, chips:array}
	 */
	public static function understood( array $brief, string $lang, ?array $cfg = null ): array {
		$cfg   = $cfg ?? self::cfg();
		$lang  = 'fr' === $lang ? 'fr' : 'nl';
		$ul    = (array) ( $cfg['understoodLabels'] ?? array() );
		$style = (string) ( $brief['style'] ?? 'badge' );
		$shape = (string) ( $brief['shape'] ?? 'circle' );
		$max   = (int) ( $brief['maxColors'] ?? 0 );
		$text  = (string) ( $brief['text'] ?? '' );
		$mode  = (string) ( $brief['textMode'] ?? 'none' );
		$names = array_values( array_map( 'strval', (array) ( $brief['colors'] ?? array() ) ) );

		$subject = self::subject_phrase( $brief, $lang, $cfg );
		$chips   = array();
		$chips[] = array( 'kind' => 'subject', 'label' => $subject );
		$chips[] = array( 'kind' => 'shape', 'label' => self::label( $ul['shapes'][ $shape ] ?? null, $lang, $shape ) );

		$labels = self::color_labels( $names, $lang, $cfg );
		if ( ! empty( $labels ) ) {
			$chips[] = array( 'kind' => 'colors', 'label' => self::join_list( $labels, self::label( $ul['colorsJoin'] ?? null, $lang, 'nl' === $lang ? 'en' : 'et' ) ) );
		}
		if ( $max > 0 && empty( $labels ) ) {
			$tpl     = 1 === $max ? self::label( $ul['maxColorsOne'] ?? null, $lang, '1' ) : self::label( $ul['maxColors'] ?? null, $lang, 'max. {n}' );
			$chips[] = array( 'kind' => 'maxColors', 'label' => str_replace( '{n}', (string) $max, $tpl ) );
		}
		$style_label = (string) ( $cfg['styles'][ $style ]['label'][ $lang ] ?? $cfg['styles'][ $style ]['label']['nl'] ?? $style );
		$chips[]     = array( 'kind' => 'style', 'label' => self::label( $ul['stylePrefix'] ?? null, $lang, 'stijl' ) . ' ' . mb_strtolower( $style_label, 'UTF-8' ) );
		if ( '' !== $text && in_array( $mode, array( 'ai', 'studio' ), true ) ) {
			$key     = 'ai' === $mode ? 'textAi' : 'textStudio';
			$chips[] = array( 'kind' => 'text', 'label' => str_replace( '{text}', $text, self::label( $ul[ $key ] ?? null, $lang, '«{text}»' ) ) );
		} elseif ( 'studio' === $mode ) {
			$chips[] = array( 'kind' => 'text', 'label' => self::label( $ul['textReserve'] ?? null, $lang, '' ) );
		}

		$warnings = array_values( array_unique( array_map( 'strval', (array) ( $brief['warnings'] ?? array() ) ) ) );
		$notes    = array();
		foreach ( $warnings as $code ) {
			$msg = self::label( $ul['warnings'][ $code ] ?? null, $lang, '' );
			if ( '' !== $msg ) {
				$notes[] = array( 'code' => $code, 'message' => $msg );
			}
		}

		$chips = array_values( array_filter( $chips, static fn( $c ) => '' !== $c['label'] ) );
		return array(
			'summary'     => implode( ' · ', array_column( array_filter( $chips, static fn( $c ) => 'text' !== $c['kind'] ), 'label' ) ),
			'subject'     => $subject,
			'style'       => $style,
			'shape'       => $shape,
			'colors'      => self::colors_hex( $names, $cfg ),
			'colorLabels' => $labels,
			'maxColors'   => $max,
			'textMode'    => $mode,
			'text'        => $text,
			'placement'   => (string) ( $brief['placement'] ?? 'below' ),
			'warnings'    => $warnings,
			'notes'       => $notes,
			'chips'       => $chips,
		);
	}

	/**
	 * Alt text of a proposal tile (SPEC §1.4): "Voorstel 2: wolf met bijl, rond, zwart en rood".
	 *
	 * @param array      $brief Brief.
	 * @param string     $lang  nl|fr.
	 * @param int        $index 0-based proposal index.
	 * @param array|null $cfg   ai-prompts.json.
	 * @return string
	 */
	public static function alt( array $brief, string $lang, int $index, ?array $cfg = null ): string {
		$cfg   = $cfg ?? self::cfg();
		$lang  = 'fr' === $lang ? 'fr' : 'nl';
		$u     = self::understood( $brief, $lang, $cfg );
		$parts = array();
		foreach ( $u['chips'] as $c ) {
			if ( in_array( $c['kind'], array( 'subject', 'shape', 'colors' ), true ) ) {
				$parts[] = $c['label'];
			}
		}
		$tpl = self::label( $cfg['understoodLabels']['alt'] ?? null, $lang, 'nl' === $lang ? 'Voorstel {i}: {summary}' : 'Proposition {i} : {summary}' );
		return strtr( $tpl, array( '{i}' => (string) ( $index + 1 ), '{summary}' => implode( ', ', $parts ) ) );
	}

	/**
	 * Hints for ChooseResponse.hints / the bps:ai:design:chosen event (SPEC §4.3, §10.3).
	 *
	 * @param array      $brief Brief.
	 * @param array|null $cfg   ai-prompts.json.
	 * @return array{shape:string, maxColors:int, colors:string[], textMode:string, text:string, placement:string, background:string}
	 */
	public static function hints( array $brief, ?array $cfg = null ): array {
		$cfg  = $cfg ?? self::cfg();
		$mode = (string) ( $brief['textMode'] ?? 'none' );
		return array(
			'shape'      => (string) ( $brief['shape'] ?? 'circle' ),
			'maxColors'  => (int) ( $brief['maxColors'] ?? 0 ),
			'colors'     => self::colors_hex( (array) ( $brief['colors'] ?? array() ), $cfg ),
			'textMode'   => in_array( $mode, array( 'studio', 'ai', 'none' ), true ) ? $mode : 'none',
			'text'       => (string) ( $brief['text'] ?? '' ),
			'placement'  => (string) ( $brief['placement'] ?? 'below' ),
			'background' => 'transparent',
		);
	}

	/**
	 * Prompt_Engine::refine() result with route=local → the LocalRefine body (SPEC §4.3) and the detail of the
	 * bps:ai:local:action event (§10.3). The action is guaranteed to be one the studio knows.
	 *
	 * @param array      $res  Result of Prompt_Engine::refine() (route 'local').
	 * @param int        $cost Rounds an AI refine would cost (for the "Toch met AI" button).
	 * @param array|null $cfg  ai-prompts.json.
	 * @return array{route:string, action:string, message:string, colors:?array, text:?string, shape:?string, aiOffer:array{cost:int}}
	 */
	public static function local_refine( array $res, int $cost = 1, ?array $cfg = null ): array {
		$cfg    = $cfg ?? self::cfg();
		$action = (string) ( $res['action'] ?? '' );
		$shape  = isset( $res['shape'] ) ? (string) $res['shape'] : null;
		$colors = isset( $res['colors'] ) ? self::colors_hex( (array) $res['colors'], $cfg ) : null;
		return array(
			'route'   => 'local',
			'action'  => in_array( $action, self::LOCAL_ACTIONS, true ) ? $action : 'open_colors',
			'message' => (string) ( $res['message'] ?? '' ),
			'colors'  => empty( $colors ) ? null : $colors,
			'text'    => isset( $res['text'] ) && '' !== (string) $res['text'] ? (string) $res['text'] : null,
			'shape'   => null !== $shape && isset( $cfg['shapes'][ $shape ] ) ? $shape : null,
			'aiOffer' => array( 'cost' => max( 0, $cost ) ),
		);
	}

	/**
	 * Style cards for public_config.ai.styles: only customer texts (no English fragments, no safety lists).
	 *
	 * @param string     $lang nl|fr.
	 * @param array|null $cfg  ai-prompts.json.
	 * @return array<int, array{id:string, label:string, hint:string}>
	 */
	public static function public_styles( string $lang, ?array $cfg = null ): array {
		$cfg  = $cfg ?? self::cfg();
		$lang = 'fr' === $lang ? 'fr' : 'nl';
		$out  = array();
		foreach ( (array) ( $cfg['styles'] ?? array() ) as $id => $st ) {
			if ( ! is_array( $st ) || '_' === substr( (string) $id, 0, 1 ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (string) $id,
				'label' => self::label( $st['label'] ?? null, $lang, (string) $id ),
				'hint'  => self::label( $st['hint'] ?? null, $lang, '' ),
			);
		}
		return $out;
	}

	/**
	 * English colour names (or #hex) → unique lowercase hex values; unknown names are dropped.
	 *
	 * @param array      $names Names.
	 * @param array|null $cfg   ai-prompts.json.
	 * @return string[]
	 */
	public static function colors_hex( array $names, ?array $cfg = null ): array {
		$cfg = $cfg ?? self::cfg();
		$map = (array) ( $cfg['colorNames'] ?? array() );
		$out = array();
		foreach ( $names as $n ) {
			$n = strtolower( trim( (string) $n ) );
			$h = preg_match( '/^#[0-9a-f]{6}$/', $n ) ? $n : strtolower( (string) ( $map[ $n ]['hex'] ?? '' ) );
			if ( preg_match( '/^#[0-9a-f]{6}$/', $h ) && ! in_array( $h, $out, true ) ) {
				$out[] = $h;
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	/**
	 * Subject in the customer's own words. The description is split into segments at , ; : and per segment the span
	 * from the first to the last design word (subjects, objects, groups, adjectives, unknown words) is kept, exactly
	 * as typed. Segments with only colours, a shape or a style word are left out (they have their own chip).
	 * Example: "een wolf met een bijl voor onze motorclub, rond, zwart-rood" → "wolf met een bijl voor onze motorclub".
	 *
	 * @param array  $brief Brief.
	 * @param string $lang  nl|fr.
	 * @param array  $cfg   Config.
	 * @return string
	 */
	private static function subject_phrase( array $brief, string $lang, array $cfg ): string {
		$ul       = (array) ( $cfg['understoodLabels'] ?? array() );
		$original = (string) ( $brief['original'] ?? '' );
		$toks     = array(); // [normalized, segment, byte start, byte end].
		$seg      = 0;
		preg_match_all( '/[\p{L}\p{N}]+|[,;:]/u', $original, $m, PREG_OFFSET_CAPTURE );
		foreach ( $m[0] as $hit ) {
			if ( 1 === preg_match( '/^[,;:]$/', $hit[0] ) ) {
				++$seg;
				continue;
			}
			$n = Prompt_Engine::normalize( $hit[0] );
			if ( '' !== $n ) {
				$toks[] = array( $n, $seg, $hit[1], $hit[1] + strlen( $hit[0] ) );
			}
		}
		$lex  = self::lexicon( $cfg );
		$stop = array_flip( array_merge( (array) ( $cfg['stopwords']['nl'] ?? array() ), (array) ( $cfg['stopwords']['fr'] ?? array() ), Prompt_Engine::VERBS ) );
		$conn = (array) ( $cfg['connectors'] ?? array() );
		$amb  = (array) ( $cfg['ambiguous'] ?? array() );
		$span = array(); // segment => [start, end].
		$mark = static function ( int $from, int $to ) use ( &$span, $toks ): void {
			$sg = $toks[ $from ][1];
			$st = $toks[ $from ][2];
			$en = $toks[ $to ][3];
			$span[ $sg ] = isset( $span[ $sg ] ) ? array( min( $span[ $sg ][0], $st ), max( $span[ $sg ][1], $en ) ) : array( $st, $en );
		};
		$cnt = count( $toks );
		for ( $i = 0; $i < $cnt; ) {
			$tok = $toks[ $i ][0];
			if ( isset( $amb[ $tok ]['before'] ) && isset( $toks[ $i + 1 ] ) && in_array( $toks[ $i + 1 ][0], (array) $amb[ $tok ]['before'], true ) ) {
				++$i; // "rond een kampvuur": a linking word, not a shape.
				continue;
			}
			$cat = null;
			$len = 0;
			for ( $l = min( 4, $cnt - $i ); $l >= 1; $l-- ) {
				$slice = array_slice( $toks, $i, $l );
				if ( count( array_unique( array_column( $slice, 1 ) ) ) > 1 ) {
					continue; // A phrase never crosses a comma.
				}
				$phrase = implode( ' ', array_column( $slice, 0 ) );
				if ( isset( $lex[ $phrase ] ) ) {
					$cat = $lex[ $phrase ];
					$len = $l;
					break;
				}
			}
			if ( null === $cat && ! isset( $conn[ $tok ] ) && ! isset( $stop[ $tok ] ) ) {
				$cat = self::stem_category( $tok, $lex );
				$len = null === $cat ? 0 : 1;
			}
			if ( null !== $cat ) {
				if ( in_array( $cat, self::SUBJECT_CATS, true ) ) {
					$mark( $i, $i + $len - 1 );
				}
				$i += $len;
				continue;
			}
			if ( ! isset( $conn[ $tok ] ) && ! isset( $stop[ $tok ] ) && ! is_numeric( $tok ) && strlen( $tok ) > 1 ) {
				$mark( $i, $i ); // Unknown word: the customer's meaning, keep it.
			}
			++$i;
		}
		ksort( $span );
		$parts = array();
		foreach ( $span as $se ) {
			$parts[] = trim( substr( $original, $se[0], $se[1] - $se[0] ) );
		}
		$s = trim( (string) preg_replace( '/\s+/u', ' ', implode( ', ', array_filter( $parts, 'strlen' ) ) ) );
		if ( '' === $s ) {
			$key = 'typographic' === ( $brief['style'] ?? '' ) ? 'typographic' : 'subjectFallback';
			return self::label( $ul[ $key ] ?? null, $lang, '' );
		}
		if ( mb_strlen( $s, 'UTF-8' ) > self::SUBJECT_MAX ) {
			$cut = mb_substr( $s, 0, self::SUBJECT_MAX - 1, 'UTF-8' );
			$sp  = mb_strrpos( $cut, ' ', 0, 'UTF-8' );
			$s   = rtrim( false !== $sp && $sp > 20 ? mb_substr( $cut, 0, $sp, 'UTF-8' ) : $cut, " ,;:" ) . '…';
		}
		return $s;
	}

	/**
	 * Normalized glossary phrase → category (first category in Prompt_Engine::CATEGORIES order wins, like the engine).
	 *
	 * @param array $cfg Config.
	 * @return array<string,string>
	 */
	private static function lexicon( array $cfg ): array {
		$lex = array();
		foreach ( Prompt_Engine::CATEGORIES as $cat ) {
			foreach ( array_keys( (array) ( $cfg['glossary'][ $cat ] ?? array() ) ) as $k ) {
				$nk = Prompt_Engine::normalize( (string) $k );
				if ( '' !== $nk && '_doc' !== (string) $k && ! isset( $lex[ $nk ] ) ) {
					$lex[ $nk ] = $cat;
				}
			}
		}
		return $lex;
	}

	/**
	 * Same inflection fallback as the engine ("brullende" → "brullend").
	 *
	 * @param string $t   Token.
	 * @param array  $lex Lexicon.
	 * @return string|null Category.
	 */
	private static function stem_category( string $t, array $lex ): ?string {
		if ( strlen( $t ) < 4 ) {
			return null;
		}
		foreach ( array( 'e', 'en', 's', 'x', 'es', 'er' ) as $suf ) {
			$l = strlen( $suf );
			if ( substr( $t, -$l ) === $suf && isset( $lex[ substr( $t, 0, -$l ) ] ) ) {
				return $lex[ substr( $t, 0, -$l ) ];
			}
		}
		return null;
	}

	/**
	 * English colour names → labels in the customer's language (unknown names are skipped).
	 *
	 * @param string[] $names Names.
	 * @param string   $lang  nl|fr.
	 * @param array    $cfg   Config.
	 * @return string[]
	 */
	private static function color_labels( array $names, string $lang, array $cfg ): array {
		$out = array();
		foreach ( $names as $n ) {
			$l = (string) ( $cfg['colorNames'][ strtolower( $n ) ][ $lang ] ?? '' );
			if ( '' !== $l && ! in_array( $l, $out, true ) ) {
				$out[] = $l;
			}
		}
		return $out;
	}

	/**
	 * "zwart, wit en rood".
	 *
	 * @param string[] $items Items.
	 * @param string   $and   Word for "and".
	 * @return string
	 */
	private static function join_list( array $items, string $and ): string {
		if ( count( $items ) < 2 ) {
			return (string) ( $items[0] ?? '' );
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ' ' . $and . ' ' . $last;
	}

	/**
	 * Does the description name a patch shape (word or comma segment)? Then the studio shape must not override it.
	 *
	 * @param string $desc Description.
	 * @param array  $cfg  Config.
	 * @return bool
	 */
	private static function mentions_shape( string $desc, array $cfg ): bool {
		if ( '' === $desc ) {
			return false;
		}
		foreach ( preg_split( '/\s*[,;]\s*/u', $desc ) as $part ) {
			$n = Prompt_Engine::normalize( (string) $part );
			if ( '' !== $n && '_doc' !== $n && isset( $cfg['shapeSegments'][ $n ] ) ) {
				return true;
			}
		}
		return null !== Prompt_Engine::interpret( $desc, $cfg )['shape'];
	}

	/**
	 * Technique: context value when the engine knows it, else the first technique of the studio, else embroidered.
	 *
	 * @param string $t        Technique from context.
	 * @param array  $cfg      Config.
	 * @param array  $defaults defaults.json.
	 * @return string
	 */
	private static function technique( string $t, array $cfg, array $defaults ): string {
		$known = (array) ( $cfg['techniqueColors'] ?? array() );
		$ok    = static fn( string $x ) => '' !== $x && '_' !== $x[0] && isset( $known[ $x ] );
		if ( $ok( $t ) ) {
			return $t;
		}
		$first = (string) ( $defaults['techniqueOrder'][0] ?? '' );
		return $ok( $first ) ? $first : 'embroidered';
	}

	/**
	 * Studio fabric (id from defaults.json fabrics, an alias, or a #hex) → key of ai-prompts.json fabricColors.
	 *
	 * @param string $f        Fabric.
	 * @param array  $cfg      Config.
	 * @param array  $defaults defaults.json.
	 * @return string
	 */
	private static function fabric( string $f, array $cfg, array $defaults ): string {
		$colors  = array_filter( (array) ( $cfg['fabricColors'] ?? array() ), static fn( $k ) => '_' !== substr( (string) $k, 0, 1 ), ARRAY_FILTER_USE_KEY );
		$aliases = (array) ( $cfg['fabricAliases'] ?? array() );
		$f       = strtolower( trim( $f ) );
		$resolve = static function ( string $id ) use ( $colors, $aliases ): string {
			if ( isset( $colors[ $id ] ) ) {
				return $id;
			}
			$a = (string) ( $aliases[ $id ] ?? '' );
			return '_doc' !== $id && isset( $colors[ $a ] ) ? $a : '';
		};
		if ( preg_match( '/^#[0-9a-f]{6}$/', $f ) ) {
			$best = '';
			$dist = PHP_INT_MAX;
			foreach ( (array) ( $defaults['fabrics'] ?? array() ) as $fab ) {
				$hex = strtolower( (string) ( $fab['hex'] ?? '' ) );
				$id  = $resolve( (string) ( $fab['id'] ?? '' ) );
				if ( '' === $id || ! preg_match( '/^#[0-9a-f]{6}$/', $hex ) ) {
					continue;
				}
				$d = self::rgb_distance( $f, $hex );
				if ( $d < $dist ) {
					$dist = $d;
					$best = $id;
				}
			}
			return '' !== $best ? $best : 'black';
		}
		$id = $resolve( $f );
		return '' !== $id ? $id : ( isset( $colors['black'] ) ? 'black' : (string) ( array_key_first( $colors ) ?? 'black' ) );
	}

	/**
	 * Squared RGB distance between two #rrggbb values.
	 *
	 * @param string $a Hex.
	 * @param string $b Hex.
	 * @return int
	 */
	private static function rgb_distance( string $a, string $b ): int {
		$d = 0;
		for ( $i = 1; $i < 7; $i += 2 ) {
			$x  = hexdec( substr( $a, $i, 2 ) ) - hexdec( substr( $b, $i, 2 ) );
			$d += $x * $x;
		}
		return (int) $d;
	}

	/**
	 * Patch size in mm: context (25..300) or the studio default (defaultTextOnlyCm for text-only), else 80.
	 *
	 * @param mixed $v         Context value.
	 * @param array $defaults  defaults.json.
	 * @param bool  $text_only Only text, no description.
	 * @return int
	 */
	private static function size_mm( $v, array $defaults, bool $text_only ): int {
		if ( is_numeric( $v ) && (float) $v > 0 ) {
			return max( 25, min( 300, (int) round( (float) $v ) ) );
		}
		$cm = $text_only ? ( $defaults['sizes']['defaultTextOnlyCm'] ?? null ) : null;
		$cm = $cm ?? ( $defaults['sizes']['defaultCm'] ?? 8 );
		return max( 25, min( 300, (int) round( (float) $cm * 10 ) ) );
	}

	/**
	 * Edge option id, only when it is a known edge option in defaults.json (the engine then uses its width).
	 *
	 * @param string $e        Edge.
	 * @param array  $defaults defaults.json.
	 * @return string
	 */
	private static function edge( string $e, array $defaults ): string {
		if ( '' === $e ) {
			return '';
		}
		foreach ( (array) ( $defaults['options'] ?? array() ) as $o ) {
			if ( ( $o['id'] ?? null ) === $e && 'edge' === ( $o['group'] ?? '' ) ) {
				return $e;
			}
		}
		return '';
	}

	/**
	 * Key that exists in a config map (ids starting with "_" are documentation, never valid), else the fallback.
	 *
	 * @param string $v        Value.
	 * @param array  $map      Map.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function pick_key( string $v, array $map, string $fallback ): string {
		$v = strtolower( trim( $v ) );
		return ( '' !== $v && '_' !== $v[0] && isset( $map[ $v ] ) && is_array( $map[ $v ] ) ) ? $v : $fallback;
	}

	/**
	 * Single line, control characters removed, trimmed.
	 *
	 * @param string $s Input.
	 * @return string
	 */
	private static function one_line( string $s ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F\s]+/u', ' ', $s ) );
	}

	/**
	 * Localised label from {nl, fr}.
	 *
	 * @param mixed  $m        Label map.
	 * @param string $lang     nl|fr.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function label( $m, string $lang, string $fallback ): string {
		if ( ! is_array( $m ) ) {
			return $fallback;
		}
		return (string) ( $m[ $lang ] ?? $m['nl'] ?? $fallback );
	}

	/**
	 * Prompt config: the AI module's (possibly overridden) config when loaded, else the bundled file.
	 *
	 * @return array
	 */
	private static function cfg(): array {
		if ( class_exists( __NAMESPACE__ . '\\Module' ) && method_exists( __NAMESPACE__ . '\\Module', 'prompt_cfg' ) ) {
			$c = Module::prompt_cfg();
			if ( is_array( $c ) && ! empty( $c ) ) {
				return $c;
			}
		}
		return Prompt_Engine::load_config();
	}

	/**
	 * Studio defaults: the merged settings when the AI module is loaded, else config/defaults.json.
	 *
	 * @return array
	 */
	private static function defaults(): array {
		if ( class_exists( __NAMESPACE__ . '\\Module' ) && method_exists( __NAMESPACE__ . '\\Module', 'studio_defaults' ) ) {
			$d = Module::studio_defaults();
			if ( is_array( $d ) && ! empty( $d ) ) {
				return $d;
			}
		}
		$file = dirname( __DIR__, 2 ) . '/config/defaults.json';
		$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $data ) ? $data : array();
	}
}
