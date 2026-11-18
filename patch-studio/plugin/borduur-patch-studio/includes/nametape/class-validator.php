<?php
/**
 * Name tapes: server-side validation of a NametapeBatch (SPEC-EXTRA §1.6). Pure class, no WordPress.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Nametape;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BPS_TESTING' ) ) {
	exit;
}

/**
 * Whitelists every enum against the merged config, bounds numbers, cleans text and re-runs the fit check per row.
 * Never trusts the client: prices and review flags are recomputed from this normalised batch only.
 */
final class Validator {

	const MAX_NAME    = 60;
	const MAX_ORGNOTE = 200;
	const BLOOD_RE    = '/^(?:(?:BT|BG)\s*:?\s*)?(AB|A|B|O|0)\s*(POS|NEG|POSITIEF|NEGATIEF|POSITIVE|NEGATIVE|POSITIF|NEGATIF|\+|-|\x{2212})$/iu';

	/**
	 * Parse a blood group from {group, rh} or a string like "O+", "A NEG", "BT: 0 pos".
	 *
	 * @param mixed $v Value.
	 * @return array{group:string,rh:string}|null
	 */
	public static function parse_blood( $v ): ?array {
		if ( is_array( $v ) ) {
			$g  = strtoupper( (string) ( $v['group'] ?? '' ) );
			$rh = (string) ( $v['rh'] ?? '' );
			if ( '0' === $g ) {
				$g = 'O';
			}
			if ( in_array( $g, array( 'O', 'A', 'B', 'AB' ), true ) && in_array( $rh, array( '+', '-' ), true ) ) {
				return array(
					'group' => $g,
					'rh'    => $rh,
				);
			}
			return null;
		}
		if ( ! is_string( $v ) ) {
			return null;
		}
		$s = Fit::clean_text( $v );
		if ( '' === $s || ! preg_match( self::BLOOD_RE, $s, $m ) ) {
			return null;
		}
		$g   = strtoupper( $m[1] );
		$g   = '0' === $g ? 'O' : $g;
		$rhs = strtoupper( $m[2] );
		$pos = '+' === $rhs || 0 === strpos( $rhs, 'POS' );
		return array(
			'group' => $g,
			'rh'    => $pos ? '+' : '-',
		);
	}

	/**
	 * Is an entry enabled (and in stock for fabrics)?
	 *
	 * @param array|null $row   Entry.
	 * @param bool       $stock Check inStock.
	 */
	private static function usable( ?array $row, bool $stock = false ): bool {
		if ( ! $row ) {
			return false;
		}
		if ( isset( $row['enabled'] ) && false === $row['enabled'] ) {
			return false;
		}
		return ! ( $stock && isset( $row['inStock'] ) && false === $row['inStock'] );
	}

	/**
	 * "auto" blood mode (§1.5.2): format with ≥ 2 lines and no line 2 → line2, else separate.
	 *
	 * @param array $format {lines}.
	 * @param array $rows   Rows.
	 */
	public static function auto_blood_mode( array $format, array $rows ): ?string {
		$any_blood = false;
		$any_line2 = false;
		foreach ( $rows as $r ) {
			if ( ! empty( $r['blood'] ) ) {
				$any_blood = true;
			}
			if ( '' !== (string) ( $r['line2'] ?? '' ) ) {
				$any_line2 = true;
			}
		}
		if ( ! $any_blood ) {
			return null;
		}
		return ( (int) $format['lines'] >= 2 && ! $any_line2 ) ? 'line2' : 'separate';
	}

	/**
	 * Truncate to n code points.
	 *
	 * @param string $s Text.
	 * @param int    $n Max.
	 */
	private static function cut( string $s, int $n ): string {
		return mb_substr( $s, 0, $n, 'UTF-8' );
	}

	/**
	 * Clean and check one free text line with the name-tape character rules (shared with the name variants of
	 * Woo\Variant_Names, concurrentie-features-9): same cleaning, same allowed characters, a length limit.
	 *
	 * @param mixed $raw       Raw text.
	 * @param array $cfg       Name-tape config (text.allowedPattern).
	 * @param int   $max_chars Max characters (0 = MAX_NAME).
	 * @return array{text:string,codes:string[],badChars:string[]}
	 */
	public static function check_line( $raw, array $cfg, int $max_chars = 0 ): array {
		$max   = $max_chars > 0 ? $max_chars : self::MAX_NAME;
		$text  = Fit::clean_text( wp_strip_tags_compat( $raw ) );
		$codes = array();
		$bad   = array();
		if ( '' === $text ) {
			$codes[] = 'empty';
		}
		$chars = Fit::chars( $text );
		if ( count( $chars ) > $max ) {
			$codes[] = 'too_many_chars';
		}
		$re = Fit::allowed_regex( $cfg );
		foreach ( $chars as $ch ) {
			if ( ' ' !== $ch && ! preg_match( $re, $ch ) && ! in_array( $ch, $bad, true ) ) {
				$bad[] = $ch;
			}
		}
		if ( $bad ) {
			$codes[] = 'bad_chars';
		}
		return array(
			'text'     => $text,
			'codes'    => $codes,
			'badChars' => $bad,
		);
	}

	/**
	 * Validate and normalise.
	 *
	 * @param mixed $raw     Raw batch (decoded JSON).
	 * @param array $cfg     Merged config.
	 * @param array $metrics Metrics.
	 * @param bool  $strict  true = rows with fit errors make the batch invalid (cart); false = only enums (quote).
	 * @return array{ok:bool,errors:array,batch:array,rows:array,review:string[],reviewRows:int[]}
	 */
	public static function validate( $raw, array $cfg, array $metrics, bool $strict = true ): array {
		$errors = array();
		$raw    = is_array( $raw ) ? $raw : array();
		$err    = static function ( string $code, string $field = '' ) use ( &$errors ) {
			$errors[] = array_filter(
				array(
					'code'  => $code,
					'field' => $field,
				)
			);
		};

		if ( 1 !== (int) ( $raw['v'] ?? 1 ) ) {
			$err( 'bad_version', 'v' );
		}
		$locale = 0 === stripos( (string) ( $raw['locale'] ?? '' ), 'fr' ) ? 'fr-BE' : 'nl-BE';

		// Format.
		$format_id = is_string( $raw['format'] ?? null ) ? $raw['format'] : '';
		$custom    = null;
		if ( 'custom' === $format_id ) {
			$cs = (array) ( $cfg['customSize'] ?? array() );
			$c  = is_array( $raw['custom'] ?? null ) ? $raw['custom'] : array();
			$w  = is_numeric( $c['w'] ?? null ) ? (int) round( (float) $c['w'] ) : 0;
			$h  = is_numeric( $c['h'] ?? null ) ? (int) round( (float) $c['h'] ) : 0;
			$hs = array_map( 'intval', (array) ( $cs['heights'] ?? array() ) );
			if ( empty( $cs['enabled'] ) || $w < (int) ( $cs['minW'] ?? 0 ) || $w > (int) ( $cs['maxW'] ?? 0 ) || ! in_array( $h, $hs, true ) ) {
				$err( 'size_out_of_range', 'custom' );
			} else {
				$custom = array(
					'w' => $w,
					'h' => $h,
				);
			}
		} elseif ( ! self::usable( Fit::find( $cfg, 'formats', $format_id ) ) ) {
			$err( 'bad_format', 'format' );
		}

		// Style enums.
		$fabric = is_string( $raw['fabric'] ?? null ) ? $raw['fabric'] : '';
		if ( ! self::usable( Fit::find( $cfg, 'fabrics', $fabric ), true ) ) {
			$err( 'bad_fabric', 'fabric' );
		}
		$letter = is_string( $raw['letter'] ?? null ) ? $raw['letter'] : '';
		if ( ! self::usable( Fit::find( $cfg, 'letterColors', $letter ) ) ) {
			$err( 'bad_letter', 'letter' );
		}
		$border = is_string( $raw['border'] ?? null ) ? $raw['border'] : '';
		if ( ! self::usable( Fit::find( $cfg, 'borders', $border ) ) ) {
			$err( 'bad_border', 'border' );
		}
		$border_color = null;
		if ( 'satin_color' === $border ) {
			$bc = is_string( $raw['borderColor'] ?? null ) ? $raw['borderColor'] : '';
			if ( self::usable( Fit::find( $cfg, 'letterColors', $bc ) ) ) {
				$border_color = $bc;
			} else {
				$err( 'bad_border_color', 'borderColor' );
			}
		}
		$font = is_string( $raw['font'] ?? null ) ? $raw['font'] : '';
		if ( ! self::usable( Fit::find( $cfg, 'fonts', $font ) ) ) {
			$err( 'bad_font', 'font' );
		}
		$backing = is_string( $raw['backing'] ?? null ) ? $raw['backing'] : '';
		if ( ! self::usable( Fit::find( $cfg, 'backings', $backing ) ) ) {
			$err( 'bad_backing', 'backing' );
		}
		$lc   = (array) ( $cfg['loopCounterpart'] ?? array() );
		$loop = ! empty( $raw['loop'] ) && true === filter_var( $raw['loop'], FILTER_VALIDATE_BOOLEAN ) && ! empty( $lc['enabled'] )
			&& in_array( $backing, isset( $lc['onlyWith'] ) ? (array) $lc['onlyWith'] : array( 'hook' ), true );
		$uppercase = array_key_exists( 'uppercase', $raw ) ? filter_var( $raw['uppercase'], FILTER_VALIDATE_BOOLEAN ) : true;

		// Rows.
		$max_rows = max( 1, (int) ( $cfg['maxRows'] ?? 250 ) );
		$in_rows  = is_array( $raw['rows'] ?? null ) ? array_values( $raw['rows'] ) : array();
		if ( count( $in_rows ) > $max_rows ) {
			$err( 'too_many_rows', 'rows' );
			$in_rows = array_slice( $in_rows, 0, $max_rows );
		}
		$rows  = array();
		$total = 0;
		foreach ( $in_rows as $r ) {
			$r = is_array( $r ) ? $r : array( 'name' => $r );
			$q = Pricing::row_qty( $r['qty'] ?? 1 );
			$rows[] = array(
				'name'  => self::cut( Fit::clean_text( wp_strip_tags_compat( $r['name'] ?? '' ) ), self::MAX_NAME ),
				'line2' => self::cut( Fit::clean_text( wp_strip_tags_compat( $r['line2'] ?? '' ) ), self::MAX_NAME ),
				'blood' => self::parse_blood( $r['blood'] ?? null ),
				'qty'   => $q,
			);
			$total += $q;
		}
		if ( ! $rows && $strict ) {
			$err( 'no_rows', 'rows' );
		}
		if ( $total > (int) ( $cfg['maxTotalQty'] ?? 2000 ) ) {
			$err( 'too_many_pieces', 'rows' );
		}

		// Blood mode.
		$format = 'custom' === $format_id
			? ( $custom ? Fit::resolve_format(
				array(
					'format' => 'custom',
					'custom' => $custom,
				),
				$cfg
			) : null )
			: Fit::resolve_format( array( 'format' => $format_id ), $cfg );
		$mode   = $raw['bloodMode'] ?? null;
		$mode   = in_array( $mode, array( 'line2', 'separate', 'inline' ), true ) ? $mode : null;
		$has_bl = (bool) array_filter( $rows, static fn( $r ) => ! empty( $r['blood'] ) );
		if ( ! $has_bl ) {
			$mode = null;
		} elseif ( null === $mode && $format ) {
			$mode = self::auto_blood_mode( $format, $rows );
		} elseif ( 'line2' === $mode && $format && (int) $format['lines'] < 2 ) {
			$err( 'bad_blood_mode', 'bloodMode' );
		}

		$org = wp_strip_tags_compat( $raw['orgNote'] ?? '' );
		$org = self::cut( Fit::clean_text( $org ), self::MAX_ORGNOTE );

		$batch = array(
			'v'           => 1,
			'locale'      => $locale,
			'format'      => $format_id,
			'custom'      => $custom,
			'fabric'      => $fabric,
			'letter'      => $letter,
			'border'      => $border,
			'borderColor' => $border_color,
			'font'        => $font,
			'uppercase'   => (bool) $uppercase,
			'backing'     => $backing,
			'loop'        => (bool) $loop,
			'bloodMode'   => $mode,
			'rows'        => $rows,
			'orgNote'     => $org,
		);

		$out_rows    = array();
		$bad_rows    = array();
		$review      = array();
		$review_rows = array();
		$enum_ok     = ! $errors;
		if ( $enum_ok ) {
			foreach ( $rows as $i => $row ) {
				$chk        = Fit::check_row( $row, $batch, $cfg, $metrics );
				$out_rows[] = array(
					'index'    => $i,
					'fit'      => $chk['fit'],
					'bloodFit' => $chk['bloodFit'],
					'review'   => $chk['review'],
				);
				$codes = array();
				if ( 'error' === $chk['fit']['status'] ) {
					$codes = $chk['fit']['codes'];
				}
				if ( $chk['bloodFit'] && 'error' === $chk['bloodFit']['status'] ) {
					$codes = array_merge( $codes, array_map( static fn( $c ) => 'blood_' . $c, $chk['bloodFit']['codes'] ) );
				}
				if ( $codes ) {
					$bad_rows[] = array(
						'index' => $i,
						'codes' => array_values( array_unique( $codes ) ),
					);
				}
				if ( $chk['review'] ) {
					$review_rows[] = $i;
					foreach ( $chk['review'] as $w ) {
						if ( ! in_array( $w, $review, true ) ) {
							$review[] = $w;
						}
					}
				}
			}
			if ( $bad_rows && $strict ) {
				$err( 'row_errors', 'rows' );
			}
		}

		return array(
			'ok'         => ! $errors,
			'errors'     => $errors,
			'batch'      => $batch,
			'rows'       => $out_rows,
			'badRows'    => $bad_rows,
			'review'     => $review,
			'reviewRows' => $review_rows,
		);
	}
}

if ( ! function_exists( __NAMESPACE__ . '\wp_strip_tags_compat' ) ) {
	/**
	 * Strip tags without WordPress (wp_strip_all_tags when available).
	 *
	 * @param mixed $s Value.
	 */
	function wp_strip_tags_compat( $s ): string {
		$s = is_scalar( $s ) ? (string) $s : '';
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return wp_strip_all_tags( $s );
		}
		return strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $s ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	}
}
