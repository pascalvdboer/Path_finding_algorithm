<?php
/**
 * Existing machine embroidery files (DST/PES/EXP/JEF): read the DST header and show it on the production sheet
 * (concurrentie-features-8).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio;

defined( 'ABSPATH' ) || exit;

/**
 * A customer who reorders often has the embroidery file of an earlier supplier. We store it privately (Uploads) and,
 * for DST, read the 512-byte Tajima header: stitch count (ST), colour changes (CO) and the extents (+X/-X/+Y/-Y in
 * 0.1 mm). The digitising fee is never waived automatically: the atelier checks the file first.
 *
 * DST header layout (each record ends with a carriage return, the header with 0x1A, padded to 512 bytes):
 *   "LA:%-16s" label · "ST:%7d" stitches · "CO:%3d" colour changes · "+X:%5d" "-X:%5d" "+Y:%5d" "-Y:%5d" extents
 *   (0.1 mm) · "AX:" "AY:" "MX:" "MY:" "PD:" (not used). Usual offsets: LA 0, ST 20, CO 31, +X 38, -X 47, +Y 56, -Y 65.
 */
final class Emb_File {

	/** Meta key on the design with the parsed summary. */
	const META = '_bps_emb_file';

	/**
	 * Hooks (registered by Woo\Designs::register()).
	 */
	public static function register(): void {
		add_filter( 'bps_fiche_sections', array( __CLASS__, 'fiche_sections' ), 14, 3 );
		add_filter( 'bps_fiche_data', array( __CLASS__, 'fiche_data' ), 14, 3 );
	}

	/**
	 * Summary of an embroidery original: {format, stitches?, colorChanges?, widthMm?, heightMm?, label?}.
	 * Null for non-embroidery files or an unreadable DST header.
	 *
	 * @param string $path Stored file.
	 * @param string $ext  Stored extension (dst|pes|exp|jef).
	 */
	public static function summary( string $path, string $ext ): ?array {
		$ext = strtolower( $ext );
		if ( ! in_array( $ext, Uploads::EMB_TYPES, true ) ) {
			return null;
		}
		if ( 'dst' !== $ext ) {
			return array( 'format' => $ext );
		}
		$dst = self::parse_dst( $path );
		return $dst ? array( 'format' => 'dst' ) + $dst : array( 'format' => 'dst' );
	}

	/**
	 * Parse the DST header. Returns {stitches, colorChanges, widthMm, heightMm, label} or null.
	 *
	 * @param string $path File path.
	 */
	public static function parse_dst( string $path ): ?array {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$h = (string) file_get_contents( $path, false, null, 0, 512 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return self::parse_dst_header( $h );
	}

	/**
	 * Parse a DST header string (pure, testable). Fields are "KEY:value" records separated by a carriage return,
	 * ending with 0x1A; parsing by key (not by fixed offset) also accepts writers that pad fields differently.
	 *
	 * @param string $h First 512 bytes.
	 */
	public static function parse_dst_header( string $h ): ?array {
		if ( strlen( $h ) < 60 || 0 !== strpos( $h, 'LA:' ) ) {
			return null;
		}
		$end    = strpos( $h, "\x1a" );
		$fields = array();
		foreach ( explode( "\r", false === $end ? $h : substr( $h, 0, $end ) ) as $record ) {
			if ( preg_match( '/^\s*([A-Z+\-]{2}):(.*)$/s', $record, $m ) && ! isset( $fields[ $m[1] ] ) ) {
				$fields[ $m[1] ] = $m[2];
			}
		}
		$num = static function ( string $key ) use ( $fields ): ?int {
			$raw = isset( $fields[ $key ] ) ? str_replace( ' ', '', trim( $fields[ $key ], "\0" ) ) : '';
			return preg_match( '/^[+\-]?\d+$/', $raw ) ? (int) $raw : null;
		};
		$st = $num( 'ST' );
		$co = $num( 'CO' );
		$px = $num( '+X' );
		$mx = $num( '-X' );
		$py = $num( '+Y' );
		$my = $num( '-Y' );
		if ( null === $st || null === $co || null === $px || null === $mx || null === $py || null === $my ) {
			return null;
		}
		$label = trim( (string) ( $fields['LA'] ?? '' ), " \r\n\t\0" );
		$label = function_exists( 'mb_convert_encoding' ) ? (string) mb_convert_encoding( $label, 'UTF-8', 'ISO-8859-1' ) : $label;
		return array(
			'stitches'     => max( 0, $st ),
			'colorChanges' => max( 0, $co ),
			// Extents are in 0.1 mm from the start point, so width = (+X + -X) / 10.
			'widthMm'      => round( ( abs( $px ) + abs( $mx ) ) / 10, 1 ),
			'heightMm'     => round( ( abs( $py ) + abs( $my ) ) / 10, 1 ),
			'label'        => sanitize_text_field( $label ),
		);
	}

	/**
	 * Stored summary of a design (meta, else the payload snapshot of the order line).
	 *
	 * @param int   $design_id Design id.
	 * @param mixed $item      Order line (optional).
	 */
	public static function for_design( int $design_id, $item = null ): ?array {
		$emb = $design_id > 0 ? get_post_meta( $design_id, self::META, true ) : '';
		if ( is_array( $emb ) && ! empty( $emb['format'] ) ) {
			return $emb;
		}
		if ( is_object( $item ) && method_exists( $item, 'get_meta' ) ) {
			$payload = json_decode( (string) $item->get_meta( '_bps_payload' ), true );
			if ( is_array( $payload ) && isset( $payload['embFile'] ) && is_array( $payload['embFile'] ) ) {
				return $payload['embFile'];
			}
		}
		return null;
	}

	/**
	 * Fiche rows "Borduurbestand van de klant".
	 *
	 * @param array $emb Summary.
	 * @return array<int, array{label:string, value:string}>
	 */
	public static function rows( array $emb ): array {
		$rows = array(
			array(
				'label' => __( 'Bestandstype', 'borduur-patch-studio' ),
				'value' => strtoupper( (string) $emb['format'] ),
			),
		);
		if ( isset( $emb['stitches'] ) ) {
			$rows[] = array(
				'label' => __( 'Steken', 'borduur-patch-studio' ),
				'value' => number_format_i18n( (int) $emb['stitches'] ),
			);
			$rows[] = array(
				'label' => __( 'Kleurwissels', 'borduur-patch-studio' ),
				'value' => number_format_i18n( (int) $emb['colorChanges'] ),
			);
			$rows[] = array(
				'label' => __( 'Breedte × hoogte', 'borduur-patch-studio' ),
				/* translators: 1: width in mm, 2: height in mm */
				'value' => sprintf( __( '%1$s × %2$s mm', 'borduur-patch-studio' ), number_format_i18n( (float) $emb['widthMm'], 1 ), number_format_i18n( (float) $emb['heightMm'], 1 ) ),
			);
		}
		$rows[] = array(
			'label' => __( 'Let op', 'borduur-patch-studio' ),
			'value' => __( 'Bestaand borduurbestand van de klant: controleer het voor je het gebruikt.', 'borduur-patch-studio' ),
		);
		return $rows;
	}

	/**
	 * Section on the current production sheet (filter bps_fiche_sections).
	 *
	 * @param mixed $sections Sections.
	 * @param mixed $rec      DesignRecord.
	 * @param mixed $item     Order line.
	 * @return mixed
	 */
	public static function fiche_sections( $sections, $rec = array(), $item = null ) {
		if ( ! is_array( $sections ) || isset( $sections['emb_file'] ) ) {
			return $sections;
		}
		$id  = is_array( $rec ) ? (int) ( $rec['id'] ?? 0 ) : 0;
		$emb = self::for_design( $id, $item );
		if ( $emb ) {
			$sections['emb_file'] = array(
				'title' => __( 'Borduurbestand van de klant', 'borduur-patch-studio' ),
				'rows'  => self::rows( $emb ),
			);
		}
		return $sections;
	}

	/**
	 * Same data for the C6 filter bps_fiche_data (P05): $fiche['embFile'] plus a section.
	 *
	 * @param mixed $fiche Fiche.
	 * @param mixed $order Order.
	 * @param mixed $item  Order line.
	 * @return mixed
	 */
	public static function fiche_data( $fiche, $order = null, $item = null ) {
		if ( ! is_array( $fiche ) ) {
			return $fiche;
		}
		$id = is_object( $item ) && method_exists( $item, 'get_meta' ) ? (int) $item->get_meta( '_bps_design_id' ) : (int) ( $fiche['design']['id'] ?? 0 );
		unset( $order );
		$emb = self::for_design( $id, $item );
		if ( ! $emb ) {
			return $fiche;
		}
		$fiche['embFile'] = $emb;
		if ( isset( $fiche['sections'] ) && is_array( $fiche['sections'] ) && ! isset( $fiche['sections']['emb_file'] ) ) {
			$fiche['sections']['emb_file'] = array(
				'title' => __( 'Borduurbestand van de klant', 'borduur-patch-studio' ),
				'rows'  => self::rows( $emb ),
			);
		}
		return $fiche;
	}

	/**
	 * Store the summary on a design and in its payload (called by Designs::create/revise/clone).
	 *
	 * @param int        $id       Design id.
	 * @param array|null $original Stored original {path, ext}.
	 * @param array      $payload  Payload (by reference: embFile added or removed).
	 */
	public static function attach( int $id, ?array $original, array &$payload ): void {
		$emb = $original ? self::summary( (string) $original['path'], (string) $original['ext'] ) : null;
		if ( $emb ) {
			update_post_meta( $id, self::META, $emb );
			$payload['embFile'] = $emb;
			return;
		}
		delete_post_meta( $id, self::META );
		unset( $payload['embFile'] );
	}
}
