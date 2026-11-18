<?php
/**
 * Name variants on one emblem (concurrentie-features-9): one text layer with `variable: true` and `values[]`
 * (one name per piece). One setup fee, the tier on the total quantity and fees.perVariantName per name (Pricing).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

use BorduurPatchStudio\Logger;
use BorduurPatchStudio\Nametape\Config as NametapeConfig;
use BorduurPatchStudio\Nametape\Validator;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Payload extension (filters of contract C4) + production sheet list and CSV download.
 */
final class Variant_Names {

	/** Most names on one design. */
	const MAX = 500;

	/** Raw lines we look at before cutting (protects against huge pasted lists). */
	const MAX_RAW = 2000;

	/** admin-post action of the CSV download. */
	const CSV_ACTION = 'bps_variant_csv';

	/** @var bool */
	private static $registered = false;

	/**
	 * Hooks (called from Woo\Cart::register()).
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_filter( 'bps_payload_clean', array( __CLASS__, 'clean' ), 10, 2 );
		add_filter( 'bps_payload_validate', array( __CLASS__, 'validate' ), 10, 3 );
		add_filter( 'bps_fiche_sections', array( __CLASS__, 'fiche_sections' ), 15, 3 );
		add_filter( 'bps_fiche_data', array( __CLASS__, 'fiche_data' ), 15, 3 );
		add_action( 'admin_post_' . self::CSV_ACTION, array( __CLASS__, 'download_csv' ) );
	}

	/*
	---------------------------------------------------------------------
	 * Payload (C4)
	 * ------------------------------------------------------------------- */

	/**
	 * Filter bps_payload_clean: copy `variable` + `values` of the raw text layers onto the clean layers. Payload::texts()
	 * keeps only layers with text, in order, so the same walk over the raw list finds the matching layer.
	 *
	 * @param mixed $out Clean payload.
	 * @param mixed $raw Untrusted input.
	 * @return mixed
	 */
	public static function clean( $out, $raw ) {
		if ( ! is_array( $out ) || ! is_array( $raw ) || empty( $raw['texts'] ) || ! is_array( $raw['texts'] ) || empty( $out['texts'] ) ) {
			return $out;
		}
		$max_chars = (int) Config::path( 'text.maxChars', 60 );
		$i         = 0;
		foreach ( $raw['texts'] as $t ) {
			if ( ! is_array( $t ) || '' === Payload::str( $t['text'] ?? '', $max_chars ) ) {
				continue;
			}
			if ( ! isset( $out['texts'][ $i ] ) ) {
				break;
			}
			if ( true === ( $t['variable'] ?? false ) || 'true' === ( $t['variable'] ?? '' ) ) {
				$out['texts'][ $i ]['variable'] = true;
				$out['texts'][ $i ]['values']   = self::clean_values( $t['values'] ?? array() );
			}
			++$i;
		}
		return $out;
	}

	/**
	 * Trim, drop empty lines, cut each line, keep at most MAX + 1 (so "too many" can still be reported).
	 *
	 * @param mixed $values Raw values (list or newline separated string).
	 * @return string[]
	 */
	public static function clean_values( $values ): array {
		if ( is_string( $values ) ) {
			$values = preg_split( '/\R/u', $values );
		}
		$out = array();
		foreach ( array_slice( is_array( $values ) ? array_values( $values ) : array(), 0, self::MAX_RAW ) as $v ) {
			if ( ! is_scalar( $v ) ) {
				continue;
			}
			$v = trim( (string) $v );
			if ( '' === $v ) {
				continue;
			}
			$out[] = function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 200 ) : substr( $v, 0, 200 );
			if ( count( $out ) > self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Filter bps_payload_validate: at most one variable layer, 1..500 names, every name passes the name-tape
	 * character rules, quantity = number of names. Error bps_variant_invalid with data.lines[] (1-based line numbers).
	 *
	 * @param mixed $err     Null or an earlier WP_Error.
	 * @param mixed $payload Clean payload.
	 * @param mixed $raw     Raw input (unused).
	 * @return mixed
	 */
	public static function validate( $err, $payload, $raw = null ) {
		if ( is_wp_error( $err ) || ! is_array( $payload ) ) {
			return $err;
		}
		$layers = array();
		foreach ( (array) ( $payload['texts'] ?? array() ) as $i => $t ) {
			if ( is_array( $t ) && ! empty( $t['variable'] ) ) {
				$layers[] = $i;
			}
		}
		if ( ! $layers ) {
			return $err;
		}
		if ( count( $layers ) > 1 ) {
			return self::error( __( 'Je kan maar één tekstregel per ontwerp met een namenlijst maken.', 'borduur-patch-studio' ), 'too_many_layers' );
		}
		$values = (array) ( $payload['texts'][ $layers[0] ]['values'] ?? array() );
		if ( ! $values ) {
			return self::error( __( 'Je namenlijst is leeg. Zet één naam per regel.', 'borduur-patch-studio' ), 'empty' );
		}
		if ( count( $values ) > self::MAX ) {
			return self::error(
				sprintf(
					/* translators: %s: maximum number of names */
					__( 'Je namenlijst is te lang: maximaal %s namen per ontwerp. Splits je lijst over twee ontwerpen of vraag een offerte.', 'borduur-patch-studio' ),
					number_format_i18n( self::MAX )
				),
				'too_many',
				array(
					array(
						'line'  => self::MAX + 1,
						'codes' => array( 'too_many' ),
					),
				)
			);
		}
		$lines = self::check_values( $values );
		if ( $lines ) {
			$nums = array_map( static fn( $l ) => (string) $l['line'], array_slice( $lines, 0, 10 ) );
			return self::error(
				sprintf(
					/* translators: %s: line numbers, e.g. "3, 7" */
					_n( 'Er staat een teken in je namenlijst dat we niet kunnen borduren of de naam is te lang (regel %s). Pas die regel aan.', 'Er staan tekens in je namenlijst die we niet kunnen borduren of namen die te lang zijn (regels %s). Pas die regels aan.', count( $lines ), 'borduur-patch-studio' ),
					implode( ', ', $nums )
				),
				'lines',
				$lines
			);
		}
		if ( (int) ( $payload['qty'] ?? 0 ) !== count( $values ) ) {
			return self::error(
				sprintf(
					/* translators: 1: number of names, 2: quantity */
					__( 'Je namenlijst telt %1$s namen, maar je bestelt %2$s stuks. Bij een namenlijst is het aantal gelijk aan het aantal namen.', 'borduur-patch-studio' ),
					number_format_i18n( count( $values ) ),
					number_format_i18n( (int) ( $payload['qty'] ?? 0 ) )
				),
				'qty_mismatch'
			);
		}
		return $err;
	}

	/**
	 * Per-line problems (reuses Nametape\Validator::check_line, the name-tape character rules).
	 *
	 * @param string[] $values Names.
	 * @return array<int,array{line:int,codes:string[],badChars?:string[]}>
	 */
	public static function check_values( array $values ): array {
		$cfg   = NametapeConfig::get();
		$max   = (int) Config::path( 'text.maxChars', 60 );
		$lines = array();
		foreach ( array_values( $values ) as $n => $v ) {
			$r = Validator::check_line( $v, $cfg, $max );
			if ( $r['codes'] ) {
				$row = array(
					'line'  => $n + 1,
					'codes' => $r['codes'],
				);
				if ( $r['badChars'] ) {
					$row['badChars'] = $r['badChars'];
				}
				$lines[] = $row;
			}
		}
		return $lines;
	}

	/**
	 * Names of a payload ([] when the design has no name list).
	 *
	 * @param array $payload Design payload.
	 * @return string[]
	 */
	public static function names( array $payload ): array {
		foreach ( (array) ( $payload['texts'] ?? array() ) as $t ) {
			if ( is_array( $t ) && ! empty( $t['variable'] ) && ! empty( $t['values'] ) ) {
				return array_values( array_map( 'strval', (array) $t['values'] ) );
			}
		}
		return array();
	}

	/**
	 * Error for the REST layer.
	 *
	 * @param string $message Customer message.
	 * @param string $reason  Machine reason.
	 * @param array  $lines   Line problems.
	 */
	private static function error( string $message, string $reason, array $lines = array() ): WP_Error {
		return new WP_Error(
			'bps_variant_invalid',
			$message,
			array(
				'status' => 400,
				'reason' => $reason,
				'lines'  => $lines,
			)
		);
	}

	/*
	---------------------------------------------------------------------
	 * Production sheet (C6) + CSV
	 * ------------------------------------------------------------------- */

	/**
	 * Names of an order line: from the order snapshot (_bps_variant_names), else from the stored payload.
	 *
	 * @param mixed $item Order line.
	 * @return string[]
	 */
	public static function names_for_item( $item ): array {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return array();
		}
		$list = json_decode( (string) $item->get_meta( '_bps_variant_names' ), true );
		if ( is_array( $list ) && $list ) {
			return array_values( array_map( 'strval', $list ) );
		}
		$payload = json_decode( (string) $item->get_meta( '_bps_payload' ), true );
		return is_array( $payload ) ? self::names( $payload ) : array();
	}

	/**
	 * Section rows: "1 · JAN", "2 · PIET", …
	 *
	 * @param string[] $names Names.
	 */
	private static function section( array $names ): array {
		$rows = array();
		foreach ( $names as $i => $n ) {
			$rows[] = array(
				'label' => (string) ( $i + 1 ),
				'value' => $n,
				'mono'  => true,
			);
		}
		return array(
			/* translators: %s: number of names */
			'title' => sprintf( __( 'Namenlijst (%s)', 'borduur-patch-studio' ), number_format_i18n( count( $names ) ) ),
			'rows'  => $rows,
		);
	}

	/**
	 * Filter bps_fiche_sections (current Order_Production): numbered name list.
	 *
	 * @param mixed $sections Sections.
	 * @param mixed $rec      DesignRecord.
	 * @param mixed $item     Order line.
	 * @return mixed
	 */
	public static function fiche_sections( $sections, $rec = array(), $item = null ) {
		if ( ! is_array( $sections ) || isset( $sections['variant_names'] ) ) {
			return $sections;
		}
		$names = self::names_for_item( $item );
		if ( ! $names && is_array( $rec ) && ! empty( $rec['payload'] ) ) {
			$names = self::names( (array) $rec['payload'] );
		}
		if ( $names ) {
			$sections['variant_names'] = self::section( $names );
		}
		return $sections;
	}

	/**
	 * Filter bps_fiche_data (contract C6, P05): list + CSV link. Adds to $fiche['sections'] when that key exists.
	 *
	 * @param mixed $fiche Fiche data.
	 * @param mixed $order Order.
	 * @param mixed $item  Order line.
	 * @return mixed
	 */
	public static function fiche_data( $fiche, $order = null, $item = null ) {
		if ( ! is_array( $fiche ) ) {
			return $fiche;
		}
		$names = self::names_for_item( $item );
		if ( ! $names ) {
			return $fiche;
		}
		$fiche['variantNames'] = array(
			'names'  => $names,
			'csvUrl' => self::csv_url( $item ),
		);
		if ( isset( $fiche['sections'] ) && is_array( $fiche['sections'] ) && ! isset( $fiche['sections']['variant_names'] ) ) {
			$fiche['sections']['variant_names'] = self::section( $names );
		}
		$fiche['downloads']   = isset( $fiche['downloads'] ) && is_array( $fiche['downloads'] ) ? $fiche['downloads'] : array();
		$fiche['downloads'][] = array(
			'label' => __( 'Namenlijst (CSV)', 'borduur-patch-studio' ),
			'url'   => $fiche['variantNames']['csvUrl'],
		);
		return $fiche;
	}

	/**
	 * Signed admin URL of the CSV.
	 *
	 * @param mixed $item Order line.
	 */
	public static function csv_url( $item ): string {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return '';
		}
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::CSV_ACTION,
					'item'   => (int) $item->get_id(),
				),
				admin_url( 'admin-post.php' )
			),
			self::CSV_ACTION . '_' . (int) $item->get_id()
		);
	}

	/**
	 * CSV rows (number, name) with a BOM so Excel opens UTF-8 correctly. Formula-looking cells are prefixed with '.
	 *
	 * @param string[] $names Names.
	 */
	public static function csv( array $names ): string {
		$out = "\xEF\xBB\xBF" . '"#";"' . __( 'Naam', 'borduur-patch-studio' ) . "\"\r\n";
		foreach ( $names as $i => $n ) {
			$n    = preg_match( '/^[=+\-@\t\r]/', $n ) ? "'" . $n : $n;
			$out .= ( $i + 1 ) . ';"' . str_replace( '"', '""', $n ) . "\"\r\n";
		}
		return $out;
	}

	/**
	 * admin-post handler: stream the CSV of one order line (shop managers only).
	 */
	public static function download_csv(): void {
		$item_id = isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( $item_id <= 0 || ! current_user_can( 'edit_shop_orders' ) || ! check_admin_referer( self::CSV_ACTION . '_' . $item_id ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze namenlijst.', 'borduur-patch-studio' ), '', array( 'response' => 403 ) );
		}
		$item  = class_exists( '\WC_Order_Factory' ) ? \WC_Order_Factory::get_order_item( $item_id ) : null;
		$names = self::names_for_item( $item );
		if ( ! $names ) {
			wp_die( esc_html__( 'Deze bestelregel heeft geen namenlijst.', 'borduur-patch-studio' ), '', array( 'response' => 404 ) );
		}
		$order = $item->get_order();
		$file  = 'namenlijst-' . ( $order ? $order->get_order_number() : 'order' ) . '-' . $item_id . '.csv';
		Logger::info(
			'variant_csv_download',
			array(
				'item'  => $item_id,
				'names' => count( $names ),
			)
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $file ) . '"' );
		echo self::csv( $names ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, not HTML.
		exit;
	}
}
