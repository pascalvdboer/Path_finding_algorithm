<?php
/**
 * Name tapes: production data (SPEC-EXTRA §1.8 "Beheer bij de bestelling"). Pure class, no WordPress:
 * cart/order snapshot → batch, per-person production rows (letter height in mm, status), style summary and a
 * CSV that is safe against spreadsheet formula injection. Used by Order_Tools (CSV + print sheet) and the tests.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Nametape;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BPS_TESTING' ) ) {
	exit;
}

/**
 * Production list for one name-tape order line.
 */
final class Production {

	/**
	 * Batch (validator shape) from the compact cart/order snapshot `bps_nt` / `_bps_nt_batch`.
	 *
	 * @param array $nt Snapshot.
	 */
	public static function batch_from_data( array $nt ): array {
		$rows = array();
		foreach ( (array) ( $nt['rows'] ?? array() ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$b      = (string) ( $r['b'] ?? '' );
			$rows[] = array(
				'name'  => (string) ( $r['n'] ?? '' ),
				'line2' => (string) ( $r['l2'] ?? '' ),
				'blood' => '' !== $b ? Validator::parse_blood( $b ) : null,
				'qty'   => max( 1, min( 99, (int) ( $r['q'] ?? 1 ) ) ),
			);
		}
		return array(
			'v'           => 1,
			'locale'      => (string) ( $nt['locale'] ?? 'nl-BE' ),
			'format'      => (string) ( $nt['format'] ?? '' ),
			'custom'      => isset( $nt['custom'] ) && is_array( $nt['custom'] ) ? $nt['custom'] : null,
			'fabric'      => (string) ( $nt['fabric'] ?? '' ),
			'letter'      => (string) ( $nt['letter'] ?? '' ),
			'border'      => (string) ( $nt['border'] ?? '' ),
			'borderColor' => isset( $nt['borderColor'] ) && '' !== (string) $nt['borderColor'] ? (string) $nt['borderColor'] : null,
			'font'        => (string) ( $nt['font'] ?? '' ),
			'uppercase'   => ! isset( $nt['uppercase'] ) || (bool) $nt['uppercase'],
			'backing'     => (string) ( $nt['backing'] ?? '' ),
			'loop'        => ! empty( $nt['loop'] ),
			'bloodMode'   => isset( $nt['bloodMode'] ) && in_array( $nt['bloodMode'], array( 'line2', 'separate', 'inline' ), true ) ? $nt['bloodMode'] : null,
			'rows'        => $rows,
			'orgNote'     => (string) ( $nt['org_note'] ?? '' ),
		);
	}

	/**
	 * Label of a config entry, or its id.
	 *
	 * @param array  $cfg  Config.
	 * @param string $list List key.
	 * @param string $id   Id.
	 * @param string $lang nl|fr.
	 */
	private static function label( array $cfg, string $list, string $id, string $lang ): string {
		$x = Fit::find( $cfg, $list, $id );
		return $x ? Pricing::label( $x['label'] ?? $id, $lang ) : $id;
	}

	/**
	 * Decimal comma.
	 *
	 * @param float $n Number.
	 * @param int   $d Decimals.
	 */
	public static function num( float $n, int $d = 1 ): string {
		return str_replace( '.', ',', number_format( $n, $d, '.', '' ) );
	}

	/**
	 * Style summary for the production sheet.
	 *
	 * @param array  $batch Batch.
	 * @param array  $cfg   Config.
	 * @param string $lang  nl|fr.
	 * @return array<string,mixed>
	 */
	public static function style( array $batch, array $cfg, string $lang = 'nl' ): array {
		$fr     = 'fr' === $lang;
		$format = Fit::resolve_format( $batch, $cfg );
		$fabric = Fit::find( $cfg, 'fabrics', $batch['fabric'] ) ?? array();
		$letter = Fit::find( $cfg, 'letterColors', $batch['letter'] ) ?? array();
		$border = Fit::find( $cfg, 'borders', $batch['border'] ) ?? array();
		$bcol   = 'satin_color' === $batch['border'] && $batch['borderColor'] ? ( Fit::find( $cfg, 'letterColors', (string) $batch['borderColor'] ) ?? $letter ) : $letter;
		$fmt    = $format ? self::num( $format['w'] / 10, ( (int) $format['w'] % 10 ) ? 1 : 0 ) . ' × ' . self::num( $format['h'] / 10, ( (int) $format['h'] % 10 ) ? 1 : 0 ) . ' cm' : $batch['format'];
		$back   = self::label( $cfg, 'backings', $batch['backing'], $lang );
		if ( $batch['loop'] ) {
			$back .= $fr ? ' + contre-partie velours' : ' + zacht tegenstuk';
		}
		$border_text = self::label( $cfg, 'borders', $batch['border'], $lang );
		if ( (float) ( $border['borderMm'] ?? 0 ) > 0 ) {
			$border_text .= ' · ' . Pricing::label( $bcol['label'] ?? ( $bcol['id'] ?? '' ), $lang ) . ' ' . (string) ( $bcol['hex'] ?? '' );
		}
		return array(
			'format'        => $fmt,
			'formatMm'      => $format ? array( 'w' => (float) $format['w'], 'h' => (float) $format['h'], 'lines' => (int) $format['lines'] ) : null,
			'fabric'        => self::label( $cfg, 'fabrics', $batch['fabric'], $lang ),
			'fabricHex'     => (string) ( $fabric['hex'] ?? '' ),
			'letter'        => self::label( $cfg, 'letterColors', $batch['letter'], $lang ),
			'letterHex'     => (string) ( $letter['hex'] ?? '' ),
			'letterThread'  => (string) ( $letter['threadCode'] ?? '' ),
			'font'          => self::label( $cfg, 'fonts', $batch['font'], $lang ),
			'border'        => $border_text,
			'backing'       => $back,
			'uppercase'     => (bool) $batch['uppercase'],
			'bloodMode'     => $batch['bloodMode'],
		);
	}

	/**
	 * One production row per person (+ the separate blood tape when used).
	 *
	 * @param array $batch   Batch.
	 * @param array $cfg     Config.
	 * @param array $metrics Metrics (the "fonts" map of nametape-metrics.json).
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( array $batch, array $cfg, array $metrics ): array {
		$font     = Fit::find( $cfg, 'fonts', $batch['font'] );
		$notation = (string) ( $cfg['blood']['notation'] ?? 'O POS' );
		$out      = array();
		foreach ( $batch['rows'] as $i => $row ) {
			$check = Fit::check_row( $row, $batch, $cfg, $metrics );
			$lines = array_map(
				static fn( $l ) => Fit::apply_case( $l, (bool) $batch['uppercase'], $font ),
				Fit::row_lines( $row, $batch['bloodMode'], $cfg )
			);
			$blood = Fit::blood_text( $row['blood'] ?? null, $notation );
			$out[] = array(
				'nr'         => $i + 1,
				'name'       => Fit::apply_case( Fit::clean_text( $row['name'] ), (bool) $batch['uppercase'], $font ),
				'line2'      => Fit::apply_case( Fit::clean_text( $row['line2'] ), (bool) $batch['uppercase'], $font ),
				'blood'      => $blood,
				'qty'        => (int) $row['qty'],
				'lines'      => $lines,
				'capMm'      => (float) $check['fit']['capMm'],
				'status'     => (string) $check['fit']['status'],
				'codes'      => (array) $check['fit']['codes'],
				'bloodTape'  => $check['bloodFit'] ? array(
					'text'  => $blood,
					'capMm' => (float) $check['bloodFit']['capMm'],
				) : null,
				'review'     => (array) $check['review'],
			);
		}
		return $out;
	}

	/**
	 * Spreadsheet-safe cell: values starting with = + - @ (or tab/CR) get a leading apostrophe (CSV injection).
	 *
	 * @param mixed $v Value.
	 */
	public static function csv_cell( $v ): string {
		$s = is_bool( $v ) ? ( $v ? '1' : '0' ) : (string) $v;
		$s = str_replace( array( "\r\n", "\r", "\n" ), ' ', $s );
		if ( '' !== $s && false !== strpos( "=+-@\t\r", $s[0] ) ) {
			$s = "'" . $s;
		}
		return $s;
	}

	/**
	 * One CSV line (RFC 4180 quoting).
	 *
	 * @param array  $cells Cells.
	 * @param string $sep   Separator.
	 */
	public static function csv_line( array $cells, string $sep = ';' ): string {
		$out = array();
		foreach ( $cells as $c ) {
			$c = self::csv_cell( $c );
			if ( '' !== $c && preg_match( '/[' . preg_quote( $sep, '/' ) . '"\r\n]/u', $c ) ) {
				$c = '"' . str_replace( '"', '""', $c ) . '"';
			}
			$out[] = $c;
		}
		return implode( $sep, $out ) . "\r\n";
	}

	/**
	 * Full CSV (UTF-8 with BOM, ';' for Belgian Excel): one line per person, style repeated per line so the file stands alone.
	 *
	 * @param array  $nt      Snapshot.
	 * @param array  $cfg     Config.
	 * @param array  $metrics Font metrics map.
	 * @param string $lang    nl|fr.
	 */
	public static function csv( array $nt, array $cfg, array $metrics, string $lang = 'nl' ): string {
		$fr    = 'fr' === $lang;
		$batch = self::batch_from_data( $nt );
		$st    = self::style( $batch, $cfg, $lang );
		$head  = $fr
			? array( 'N°', 'Nom', 'Ligne 2', 'Groupe sanguin', 'Quantité', 'Hauteur lettres (mm)', 'Bande groupe sanguin', 'Statut', 'Format', 'Tissu', 'Couleur texte', 'Police', 'Bord', 'Dos' )
			: array( 'Nr', 'Naam', 'Regel 2', 'Bloedgroep', 'Aantal', 'Letterhoogte (mm)', 'Eigen bloedgroepstrook', 'Status', 'Formaat', 'Stof', 'Letterkleur', 'Lettertype', 'Rand', 'Achterkant' );
		$csv   = "\xEF\xBB\xBF" . self::csv_line( $head );
		foreach ( self::rows( $batch, $cfg, $metrics ) as $r ) {
			$status = array(
				'ok'    => 'OK',
				'warn'  => $fr ? 'petit texte' : 'kleine letters',
				'error' => $fr ? 'À VÉRIFIER' : 'NAKIJKEN',
			);
			if ( $r['review'] ) {
				$status[ $r['status'] ] .= $fr ? ' · contrôle manuel' : ' · manuele controle';
			}
			$csv .= self::csv_line(
				array(
					$r['nr'],
					$r['name'],
					$r['line2'],
					$r['blood'],
					$r['qty'],
					self::num( $r['capMm'] ),
					$r['bloodTape'] ? $r['bloodTape']['text'] . ' (' . self::num( $r['bloodTape']['capMm'] ) . ' mm)' : '',
					$status[ $r['status'] ] ?? $r['status'],
					$st['format'],
					$st['fabric'],
					$st['letter'],
					$st['font'],
					$st['border'],
					$st['backing'],
				)
			);
		}
		return $csv;
	}

	/**
	 * Totals for the sheet header.
	 *
	 * @param array $batch Batch.
	 * @return array{persons:int,main:int,blood:int}
	 */
	public static function totals( array $batch ): array {
		$main  = 0;
		$blood = 0;
		foreach ( $batch['rows'] as $r ) {
			$main += (int) $r['qty'];
			if ( 'separate' === $batch['bloodMode'] && ! empty( $r['blood'] ) ) {
				$blood += (int) $r['qty'];
			}
		}
		return array(
			'persons' => count( $batch['rows'] ),
			'main'    => $main,
			'blood'   => $blood,
		);
	}
}
