<?php
/**
 * Name tapes: fit check (SPEC-EXTRA §1.5.1). Pure class, no WordPress. Twin of assets/src/nametape/fit.js:
 * same algorithm and order of float operations, verified with tests/nametape/fit-vectors.json in Node and PHP.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Nametape;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BPS_TESTING' ) ) {
	exit;
}

/**
 * Letter height / length check with a per-font advance-width table.
 */
final class Fit {

	const RANK = array(
		'error' => 0,
		'warn'  => 1,
		'ok'    => 2,
	);

	const DEFAULT_PATTERN = "^[\\p{L}\\p{M}\\p{N} .,'’\\-/&+#()]+$";

	/**
	 * Half-up rounding, identical to shared/money.js roundHalfUp() (own twin, SPEC-EXTRA §4).
	 *
	 * @param float $x Value.
	 * @param int   $d Decimals.
	 */
	public static function round_half_up( float $x, int $d = 2 ): float {
		$s = $x < 0 ? -1 : 1;
		return $s * floor( (float) sprintf( '%.15g', abs( $x ) * 10 ** $d ) + 0.5 ) / 10 ** $d;
	}

	/**
	 * NFC, control characters out, whitespace collapsed, trimmed.
	 *
	 * @param mixed $s Text.
	 */
	public static function clean_text( $s ): string {
		$s = is_scalar( $s ) ? (string) $s : '';
		if ( '' === $s ) {
			return '';
		}
		if ( ! mb_check_encoding( $s, 'UTF-8' ) ) {
			$s = mb_convert_encoding( $s, 'UTF-8', 'UTF-8' );
		}
		if ( class_exists( '\Normalizer' ) ) {
			$n = \Normalizer::normalize( $s, \Normalizer::FORM_C );
			if ( is_string( $n ) ) {
				$s = $n;
			}
		}
		$s = (string) preg_replace( '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', ' ', $s );
		$s = (string) preg_replace( '/[\s\p{Z}]+/u', ' ', $s );
		return trim( $s, ' ' );
	}

	/**
	 * Split into code points.
	 *
	 * @param string $s Text.
	 * @return string[]
	 */
	public static function chars( string $s ): array {
		if ( '' === $s ) {
			return array();
		}
		$out = preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $out ) ? $out : array();
	}

	/**
	 * Strip combining marks after NFD (with a small fallback map when intl is missing).
	 *
	 * @param string $s Text.
	 */
	public static function strip_marks( string $s ): string {
		if ( class_exists( '\Normalizer' ) ) {
			$n = \Normalizer::normalize( $s, \Normalizer::FORM_D );
			if ( is_string( $n ) ) {
				return (string) preg_replace( '/\p{M}+/u', '', $n );
			}
		}
		if ( function_exists( 'remove_accents' ) ) {
			return remove_accents( $s );
		}
		$t = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return is_string( $t ) && '' !== $t ? $t : $s;
	}

	/**
	 * Base character for the width table.
	 *
	 * @param string $ch One code point.
	 */
	public static function base_char( string $ch ): string {
		if ( "\u{2019}" === $ch || "\u{2018}" === $ch || '`' === $ch ) {
			return "'";
		}
		if ( "\u{2013}" === $ch || "\u{2014}" === $ch || "\u{2212}" === $ch ) {
			return '-';
		}
		$b = self::strip_marks( $ch );
		return '' === $b ? $ch : $b;
	}

	/**
	 * Sum of advance widths (em) of one line, in reading order.
	 *
	 * @param string $text Line.
	 * @param array  $fm   Font metrics {capHeightEm, adv, fallbackEm}.
	 */
	public static function line_em( string $text, array $fm ): float {
		$em = 0.0;
		foreach ( self::chars( $text ) as $ch ) {
			$k   = self::base_char( $ch );
			$a   = $fm['adv'][ $k ] ?? null;
			$em += is_int( $a ) || is_float( $a ) ? (float) $a : (float) $fm['fallbackEm'];
		}
		return $em;
	}

	/**
	 * Find an entry by id in a config list.
	 *
	 * @param array  $cfg  Config.
	 * @param string $list List key.
	 * @param string $id   Id.
	 */
	public static function find( array $cfg, string $list, string $id ): ?array {
		foreach ( (array) ( $cfg[ $list ] ?? array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && (string) $row['id'] === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Uppercase unless the font must keep mixed case.
	 *
	 * @param mixed      $text      Text.
	 * @param bool       $uppercase Uppercase.
	 * @param array|null $font      Font.
	 */
	public static function apply_case( $text, bool $uppercase, ?array $font ): string {
		$t = self::clean_text( $text );
		if ( $font && ! empty( $font['forceMixedCase'] ) ) {
			return $t;
		}
		return $uppercase ? mb_strtoupper( $t, 'UTF-8' ) : $t;
	}

	/**
	 * Lines for a custom height.
	 *
	 * @param array $cfg Config.
	 * @param float $h   Height mm.
	 */
	public static function custom_lines( array $cfg, float $h ): int {
		$map = (array) ( $cfg['customSize']['linesByHeight'] ?? array() );
		$key = (string) ( (float) (int) $h === $h ? (int) $h : $h );
		$n   = isset( $map[ $key ] ) ? (int) $map[ $key ] : 0;
		if ( $n > 0 ) {
			return $n;
		}
		return $h >= 50 ? 3 : ( $h >= 30 ? 2 : 1 );
	}

	/**
	 * Format of a batch as {id, w, h, lines}, or null.
	 *
	 * @param array $batch Batch.
	 * @param array $cfg   Config.
	 */
	public static function resolve_format( array $batch, array $cfg ): ?array {
		$id = (string) ( $batch['format'] ?? '' );
		if ( 'custom' === $id ) {
			$c = is_array( $batch['custom'] ?? null ) ? $batch['custom'] : array();
			$w = (float) ( $c['w'] ?? 0 );
			$h = (float) ( $c['h'] ?? 0 );
			if ( $w <= 0 || $h <= 0 ) {
				return null;
			}
			return array(
				'id'    => 'custom',
				'w'     => $w,
				'h'     => $h,
				'lines' => self::custom_lines( $cfg, $h ),
			);
		}
		$f = self::find( $cfg, 'formats', $id );
		if ( ! $f ) {
			return null;
		}
		return array(
			'id'    => (string) $f['id'],
			'w'     => (float) $f['w'],
			'h'     => (float) $f['h'],
			'lines' => (int) $f['lines'],
		);
	}

	/**
	 * Blood group as text ("O POS" or "O+").
	 *
	 * @param array|null $blood    {group, rh}.
	 * @param string     $notation Notation sample.
	 */
	public static function blood_text( $blood, string $notation = 'O POS' ): string {
		if ( ! is_array( $blood ) || empty( $blood['group'] ) ) {
			return '';
		}
		$pos = '+' === ( $blood['rh'] ?? '' );
		if ( false === strpos( $notation, ' ' ) ) {
			return $blood['group'] . ( $pos ? '+' : '-' );
		}
		return $blood['group'] . ( $pos ? ' POS' : ' NEG' );
	}

	/**
	 * Lines of one row for a blood mode (§1.5.2).
	 *
	 * @param array       $row        {name, line2, blood}.
	 * @param string|null $blood_mode line2|separate|inline|null.
	 * @param array       $cfg        Config.
	 * @return string[]
	 */
	public static function row_lines( array $row, $blood_mode, array $cfg ): array {
		$bt  = self::blood_text( $row['blood'] ?? null, (string) ( $cfg['blood']['notation'] ?? 'O POS' ) );
		$l1  = self::clean_text( $row['name'] ?? '' );
		$out = array();
		if ( 'inline' === $blood_mode && '' !== $bt ) {
			$l1 = self::clean_text( $l1 . ' ' . $bt );
		}
		$out[] = $l1;
		$l2    = self::clean_text( $row['line2'] ?? '' );
		if ( '' !== $l2 ) {
			$out[] = $l2;
		}
		if ( 'line2' === $blood_mode && '' !== $bt ) {
			$out[] = $bt;
		}
		return $out;
	}

	/**
	 * Is status $a better than $b?
	 *
	 * @param string $a Status.
	 * @param string $b Status.
	 */
	private static function better( string $a, string $b ): bool {
		return self::RANK[ $a ] > self::RANK[ $b ];
	}

	/**
	 * Allowed-characters regex.
	 *
	 * @param array $cfg Config.
	 */
	public static function allowed_regex( array $cfg ): string {
		$src = (string) ( $cfg['text']['allowedPattern'] ?? self::DEFAULT_PATTERN );
		if ( '' === $src ) {
			$src = self::DEFAULT_PATTERN;
		}
		return '~' . str_replace( '~', '\~', $src ) . '~u';
	}

	/**
	 * Metrics of a font id.
	 *
	 * @param array  $metrics Metrics file.
	 * @param string $id      Font id.
	 */
	private static function metrics_for( array $metrics, string $id ): ?array {
		$all = isset( $metrics['fonts'] ) && is_array( $metrics['fonts'] ) ? $metrics['fonts'] : $metrics;
		return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Fit check.
	 *
	 * @param array $input   {lines, fontId, format:{id,w,h,lines}, borderId, uppercase}.
	 * @param array $cfg     Merged name-tape config.
	 * @param array $metrics Metrics.
	 * @param bool  $fixes   Compute fixes.
	 * @return array{status:string,codes:string[],capMm:float,widthsMm:float[],badChars:string[],fixes:string[]}
	 */
	public static function fit_text( array $input, array $cfg, array $metrics, bool $fixes = true ): array {
		$fit = (array) ( $cfg['fit'] ?? array() );
		$font = self::find( $cfg, 'fonts', (string) ( $input['fontId'] ?? '' ) );
		if ( ! $font ) {
			$font = self::find( $cfg, 'fonts', 'block' );
		}
		if ( ! $font ) {
			$font = isset( $cfg['fonts'][0] ) && is_array( $cfg['fonts'][0] ) ? $cfg['fonts'][0] : array( 'id' => 'block' );
		}
		$fm     = self::metrics_for( $metrics, (string) $font['id'] );
		$border = self::find( $cfg, 'borders', (string) ( $input['borderId'] ?? '' ) );
		$bmm    = $border ? (float) $border['borderMm'] : 0.0;
		$format = (array) $input['format'];
		$script = ! empty( $font['script'] );
		$upper  = ! array_key_exists( 'uppercase', $input ) || false !== $input['uppercase'];

		$lines = array();
		foreach ( (array) ( $input['lines'] ?? array() ) as $l ) {
			$lines[] = self::apply_case( $l, $upper, $font );
		}
		$used  = array_values( array_filter( $lines, static fn( $l ) => '' !== $l ) );
		$empty = ! $lines || '' === $lines[0];
		$n     = count( $used );
		$codes = array();
		$cap   = 0.0;
		$wids  = array();
		$bad   = array();
		$max_l = (int) $format['lines'];

		if ( $empty ) {
			$codes[] = 'empty';
		} elseif ( $n > $max_l ) {
			$codes[] = ( 1 === $max_l && 2 === $n ) ? 'line2_no_room' : 'too_many_lines';
		}

		$re        = self::allowed_regex( $cfg );
		$max_chars = (int) ( $fit['maxCharsPerLine'] ?? 30 );
		if ( $max_chars <= 0 ) {
			$max_chars = 30;
		}
		$too_many = false;
		foreach ( $used as $l ) {
			$chs = self::chars( $l );
			if ( count( $chs ) > $max_chars ) {
				$too_many = true;
			}
			foreach ( $chs as $ch ) {
				if ( ! preg_match( $re, $ch ) && ! in_array( $ch, $bad, true ) ) {
					$bad[] = $ch;
				}
			}
		}
		if ( $bad ) {
			$codes[] = 'bad_chars';
		}
		if ( $too_many ) {
			$codes[] = 'too_many_chars';
		}

		if ( ! $empty && $n <= $max_l && $fm ) {
			$inset   = $bmm + (float) ( $fit['padMm'] ?? 0 );
			$inner_w = (float) $format['w'] - 2 * $inset;
			$inner_h = (float) $format['h'] - 2 * $inset;
			$c       = min( (float) ( $fit['maxCapMm'] ?? 13 ), $inner_h / ( $n + ( $n - 1 ) * (float) ( $fit['lineGapRatio'] ?? 0 ) ) );
			$ems     = array();
			foreach ( $used as $l ) {
				$ems[] = self::line_em( $l, $fm );
			}
			$che = (float) $fm['capHeightEm'];
			foreach ( $ems as $em ) {
				if ( $em > 0 ) {
					$c = min( $c, ( $inner_w * $che ) / $em );
				}
			}
			$cap = self::round_half_up( max( 0.0, $c ), 1 );
			foreach ( $ems as $em ) {
				$wids[] = self::round_half_up( ( $cap * $em ) / $che, 1 );
			}
			$min = $script ? (float) ( $fit['scriptMinCapMm'] ?? 7 ) : (float) ( $fit['minCapMm'] ?? 6 );
			$abs = $script ? (float) ( $fit['scriptAbsMinCapMm'] ?? 5 ) : (float) ( $fit['absMinCapMm'] ?? 4 );
			if ( $cap < $abs ) {
				$codes[] = 'too_long';
			} elseif ( $cap < $min ) {
				$codes[] = 'small_text';
			}
		}

		$is_error = false;
		foreach ( $codes as $code ) {
			if ( 'small_text' !== $code ) {
				$is_error = true;
			}
		}
		$status = $is_error ? 'error' : ( $codes ? 'warn' : 'ok' );
		$res    = array(
			'status'   => $status,
			'codes'    => $codes,
			'capMm'    => $cap,
			'widthsMm' => $wids,
			'badChars' => $bad,
			'fixes'    => array(),
		);
		if ( $fixes && 'ok' !== $status ) {
			$res['fixes'] = self::compute_fixes( $input, $used, $res, $cfg, $metrics );
		}
		return $res;
	}

	/**
	 * Split on the space that makes the longest line shortest.
	 *
	 * @param string $text Text.
	 * @param array  $fm   Metrics.
	 * @return string[]|null
	 */
	public static function best_split( string $text, array $fm ): ?array {
		$t       = self::clean_text( $text );
		$chs     = self::chars( $t );
		$best    = null;
		$best_em = INF;
		$count   = count( $chs );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ' ' !== $chs[ $i ] ) {
				continue;
			}
			$a = trim( implode( '', array_slice( $chs, 0, $i ) ) );
			$b = trim( implode( '', array_slice( $chs, $i + 1 ) ) );
			if ( '' === $a || '' === $b ) {
				continue;
			}
			$m = max( self::line_em( $a, $fm ), self::line_em( $b, $fm ) );
			if ( $m < $best_em ) {
				$best_em = $m;
				$best    = array( $a, $b );
			}
		}
		return $best;
	}

	/**
	 * One-click fixes, re-running fit_text (order fixed by the spec).
	 *
	 * @param array    $input   Input.
	 * @param string[] $used    Non-empty lines (cased).
	 * @param array    $res     Current result.
	 * @param array    $cfg     Config.
	 * @param array    $metrics Metrics.
	 * @return string[]
	 */
	private static function compute_fixes( array $input, array $used, array $res, array $cfg, array $metrics ): array {
		$fixes      = array();
		$st         = $res['status'];
		$n          = count( $used );
		$structural = (bool) array_intersect( $res['codes'], array( 'empty', 'bad_chars', 'too_many_chars' ) );
		$line_err   = (bool) array_intersect( $res['codes'], array( 'line2_no_room', 'too_many_lines' ) );
		$format     = (array) $input['format'];

		if ( ! $structural ) {
			$cond = self::find( $cfg, 'fonts', 'condensed' );
			if ( $cond && ( ! array_key_exists( 'enabled', $cond ) || false !== $cond['enabled'] ) && 'condensed' !== ( $input['fontId'] ?? '' ) ) {
				$r = self::fit_text( array_merge( $input, array( 'fontId' => 'condensed' ) ), $cfg, $metrics, false );
				if ( self::better( $r['status'], $st ) ) {
					$fixes[] = 'use_font:condensed';
				}
			}

			$area  = (float) $format['w'] * (float) $format['h'];
			$cands = array();
			foreach ( (array) ( $cfg['formats'] ?? array() ) as $f ) {
				if ( ! is_array( $f ) || ! empty( $f['bloodFormat'] ) || ( isset( $f['enabled'] ) && false === $f['enabled'] ) ) {
					continue;
				}
				if ( (string) $f['id'] === (string) ( $format['id'] ?? '' ) ) {
					continue;
				}
				if ( (float) $f['w'] * (float) $f['h'] > $area && (int) $f['lines'] >= $n ) {
					$cands[] = $f;
				}
			}
			usort(
				$cands,
				static function ( $a, $b ) {
					$d = (float) $a['w'] * (float) $a['h'] - (float) $b['w'] * (float) $b['h'];
					if ( 0.0 !== $d ) {
						return $d < 0 ? -1 : 1;
					}
					return strcmp( (string) $a['id'], (string) $b['id'] );
				}
			);
			$pick = null;
			foreach ( $cands as $f ) {
				$r = self::fit_text(
					array_merge(
						$input,
						array(
							'format' => array(
								'id'    => (string) $f['id'],
								'w'     => (float) $f['w'],
								'h'     => (float) $f['h'],
								'lines' => (int) $f['lines'],
							),
						)
					),
					$cfg,
					$metrics,
					false
				);
				if ( 'ok' === $r['status'] ) {
					$pick = (string) $f['id'];
					break;
				}
				if ( null === $pick && self::better( $r['status'], $st ) ) {
					$pick = (string) $f['id'];
				}
			}
			if ( null !== $pick ) {
				$fixes[] = 'use_format:' . $pick;
			}

			if ( (int) $format['lines'] >= 2 && 1 === $n ) {
				$font = self::find( $cfg, 'fonts', (string) ( $input['fontId'] ?? '' ) );
				$fm   = self::metrics_for( $metrics, $font ? (string) $font['id'] : 'block' );
				$sp   = $fm ? self::best_split( $used[0], $fm ) : null;
				if ( $sp ) {
					$r = self::fit_text( array_merge( $input, array( 'lines' => $sp ) ), $cfg, $metrics, false );
					if ( self::better( $r['status'], $st ) ) {
						$fixes[] = 'split_lines';
					}
				}
			}

			if ( $line_err && $n >= 2 ) {
				$first = (array) ( $input['lines'] ?? array() );
				$r     = self::fit_text( array_merge( $input, array( 'lines' => array( $first[0] ?? '' ) ) ), $cfg, $metrics, false );
				if ( self::better( $r['status'], $st ) ) {
					$fixes[] = 'drop_line2';
				}
			}
		}
		$fixes[] = 'edit';
		return $fixes;
	}

	/**
	 * Accent-free uppercase text.
	 *
	 * @param mixed $s Text.
	 */
	public static function fold_text( $s ): string {
		return mb_strtoupper( self::strip_marks( self::clean_text( $s ) ), 'UTF-8' );
	}

	/**
	 * Review words found (whole words, case and accent insensitive).
	 *
	 * @param string[] $texts Texts.
	 * @param string[] $words Words.
	 * @return string[]
	 */
	public static function review_hits( array $texts, array $words ): array {
		$parts = array();
		foreach ( $texts as $t ) {
			$parts[] = self::fold_text( $t );
		}
		$hay  = ' ' . preg_replace( '/[^\p{L}\p{N}]+/u', ' ', implode( ' ', $parts ) ) . ' ';
		$hits = array();
		foreach ( $words as $w ) {
			$fw = trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', self::fold_text( $w ) ) );
			if ( '' !== $fw && false !== strpos( $hay, ' ' . $fw . ' ' ) ) {
				$hits[] = (string) $w;
			}
		}
		return $hits;
	}

	/**
	 * Check one row of a batch (main tape + optional separate blood tape).
	 *
	 * @param array $row     Row.
	 * @param array $batch   Batch.
	 * @param array $cfg     Config.
	 * @param array $metrics Metrics.
	 * @return array{fit:array,bloodFit:array|null,review:string[]}
	 */
	public static function check_row( array $row, array $batch, array $cfg, array $metrics ): array {
		$format = self::resolve_format( $batch, $cfg );
		$base   = array(
			'fontId'    => (string) ( $batch['font'] ?? '' ),
			'borderId'  => (string) ( $batch['border'] ?? '' ),
			'uppercase' => ! array_key_exists( 'uppercase', $batch ) || false !== $batch['uppercase'],
		);
		$mode   = $batch['bloodMode'] ?? null;
		$fit    = $format
			? self::fit_text( array_merge( $base, array( 'lines' => self::row_lines( $row, $mode, $cfg ), 'format' => $format ) ), $cfg, $metrics )
			: array(
				'status'   => 'error',
				'codes'    => array( 'bad_format' ),
				'capMm'    => 0.0,
				'widthsMm' => array(),
				'badChars' => array(),
				'fixes'    => array(),
			);
		$blood_fit = null;
		if ( 'separate' === $mode && ! empty( $row['blood'] ) ) {
			$bf = self::find( $cfg, 'formats', (string) ( $cfg['blood']['separateFormat'] ?? '5x2.5' ) );
			if ( $bf ) {
				$blood_fit = self::fit_text(
					array_merge(
						$base,
						array(
							'lines'  => array( self::blood_text( $row['blood'], (string) ( $cfg['blood']['notation'] ?? 'O POS' ) ) ),
							'format' => array(
								'id'    => (string) $bf['id'],
								'w'     => (float) $bf['w'],
								'h'     => (float) $bf['h'],
								'lines' => (int) $bf['lines'],
							),
						)
					),
					$cfg,
					$metrics
				);
			}
		}
		return array(
			'fit'      => $fit,
			'bloodFit' => $blood_fit,
			'review'   => self::review_hits( array( (string) ( $row['name'] ?? '' ), (string) ( $row['line2'] ?? '' ) ), (array) ( $cfg['reviewWords'] ?? array() ) ),
		);
	}
}
