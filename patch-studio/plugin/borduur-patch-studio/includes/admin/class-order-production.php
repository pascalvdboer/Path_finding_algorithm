<?php
/**
 * Production sheet ("productiefiche" / werkbon) for Patch Studio order lines (SPEC §6.5): the metabox on the order
 * screen (classic + HPOS), the printable A4 sheet, the AJAX endpoints for the proof dialog and the orders list
 * column. The proof itself (storage per order line, tokens, customer page, mails, reminders) lives in
 * BorduurPatchStudio\Proof (php-kwaliteit-3).
 *
 * Contract C6: fiche( $order, $item ) → filter `bps_fiche_data( $fiche, $order, $item )`; action
 * `bps_fiche_after_specs( $fiche, $context )`; render_print( array $pairs ): string. The work steps on the sheet
 * read `$fiche['production']['status']`.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Admin;

use BorduurPatchStudio\Proof\Lang;
use BorduurPatchStudio\Proof\Order_Proof;
use BorduurPatchStudio\Proof\Proof_Service;
use BorduurPatchStudio\Proof\Spec;
use BorduurPatchStudio\Proof\Work_Order;
use BorduurPatchStudio\Woo\Designs;

defined( 'ABSPATH' ) || exit;

/**
 * Component registered by Plugin::boot() (SPEC §4.1).
 */
final class Order_Production {

	/** Nonce action for the AJAX endpoints on the order screen. */
	const NONCE = 'bps_production';

	/** Order item meta mirroring the proof status (orders list without loading the record). */
	const ITEM_PROOF_META = Proof_Service::STATUS_META;

	/** Proof statuses. */
	const STATUSES = Proof_Service::STATUSES;

	/** Max length of free text (message to customer, customer note). */
	const MAX_TEXT = Proof_Service::MAX_TEXT;


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
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_itemmeta' ) );

		// Orders list column (HPOS + legacy).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'list_columns' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'list_column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'list_columns' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'list_column_legacy' ), 10, 2 );

		add_filter( 'bps_owned_data', array( __CLASS__, 'filter_owned_data' ) );
		Proof_Service::register();
	}

	/**
	 * Data the proof workflow owns (contract C2; also read during uninstall).
	 */
	public static function owned_data(): array {
		return Proof_Service::owned_data();
	}

	/**
	 * Filter bps_owned_data (runtime).
	 *
	 * @param array $data Owned data.
	 */
	public static function filter_owned_data( $data ) {
		$data = is_array( $data ) ? $data : array();
		foreach ( self::owned_data() as $key => $list ) {
			$data[ $key ] = array_values( array_unique( array_merge( (array) ( $data[ $key ] ?? array() ), $list ) ) );
		}
		return $data;
	}

	/* =====================================================================
	 * Small helpers (kept for templates and other packages)
	 * =================================================================== */

	/**
	 * 'nl'|'fr' for a locale-ish string.
	 *
	 * @param string|null $locale Locale.
	 */
	public static function lang_of( ?string $locale ): string {
		return Spec::lang_of( $locale );
	}

	/**
	 * Set the label language; returns the previous one.
	 *
	 * @param string $lang 'nl'|'fr'.
	 */
	public static function set_lang( string $lang ): string {
		return Spec::set_lang( $lang );
	}

	/**
	 * Current label language.
	 */
	public static function lang(): string {
		return Spec::lang();
	}

	/**
	 * Number with decimal comma.
	 *
	 * @param float $n        Number.
	 * @param int   $decimals Max decimals.
	 */
	public static function num( float $n, int $decimals = 1 ): string {
		return Spec::num( $n, $decimals );
	}

	/**
	 * Language of the current admin user.
	 */
	private static function admin_lang(): string {
		return Spec::lang_of( function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale() );
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
		return Proof_Service::status_label( $status );
	}

	/**
	 * Normalized proof of an order line (or, without a line, of a design record — legacy callers).
	 *
	 * @param array                       $rec  DesignRecord.
	 * @param \WC_Order_Item_Product|null $item Line.
	 */
	public static function proof_of( array $rec, $item = null ): array {
		if ( $item instanceof \WC_Order_Item_Product ) {
			return Proof_Service::get( $item );
		}
		return Proof_Service::normalize( isset( $rec['proof'] ) && is_array( $rec['proof'] ) ? $rec['proof'] : array() );
	}

	/**
	 * Customer-facing summary rows (proof mail and page).
	 *
	 * @param \WC_Order_Item_Product $item Line.
	 * @param string                 $lang Language.
	 */
	public static function customer_rows( $item, string $lang ): array {
		return Spec::customer_rows( $item, $lang );
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
	 * Design record for an order line (synthesized from the order snapshot when the post was cleaned up). The
	 * proof always comes from the order line (atelier-flow-1), never from the design post.
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 * @return array{record:array, exists:bool}
	 */
	public static function design_for_item( $item ): array {
		$id     = (int) $item->get_meta( '_bps_design_id' );
		$record = ( $id > 0 && class_exists( Designs::class ) ) ? Designs::get( $id ) : null;
		$snap   = json_decode( (string) $item->get_meta( '_bps_payload' ), true );
		$snap   = is_array( $snap ) ? $snap : array();
		$exists = (bool) $record;
		if ( $record ) {
			// The order snapshot is what the customer ordered; prefer it over the (equal or later) design payload.
			if ( $snap ) {
				$record['payload'] = $snap;
			}
			if ( '' === (string) $record['preview_url'] ) {
				$record['preview_url'] = (string) $item->get_meta( '_bps_preview_url' );
			}
		} else {
			$record = array(
				'id'          => $id,
				'ref'         => (string) $item->get_meta( 'bps_ref' ),
				'status'      => 'ordered',
				'created_gmt' => '',
				'payload'     => $snap,
				'original'    => null,
				'preview_url' => (string) $item->get_meta( '_bps_preview_url' ),
				'email'       => null,
			);
		}
		$record['order_id']      = (int) $item->get_order_id();
		$record['order_item_id'] = (int) $item->get_id();
		$record['proof']         = Proof_Service::get( $item );
		return array(
			'record' => $record,
			'exists' => $exists,
		);
	}

	/**
	 * Everything the card, the print sheet and the proof mail need for one order line.
	 * Accepts ( $item, ?$lang ) and, per contract C6, ( $order, $item ).
	 *
	 * @param \WC_Order|\WC_Order_Item_Product $a Order (C6) or line.
	 * @param \WC_Order_Item_Product|string    $b Line (C6) or label language.
	 */
	public static function fiche( $a, $b = null ): array {
		if ( $a instanceof \WC_Order ) {
			$item = $b;
			$lang = null;
		} else {
			$item = $a;
			$lang = is_string( $b ) ? $b : null;
		}
		$prev = Spec::set_lang( $lang ?? self::admin_lang() );
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
		$t      = Spec::technique( $tech );
		$thread = ! empty( $t['threadBased'] );

		// Options per group.
		$groups = array();
		foreach ( $options as $oid ) {
			$opt = Spec::option( $oid );
			if ( ! $opt || ( ! empty( $opt['requiresAny'] ) && ! array_intersect( (array) $opt['requiresAny'], $options ) ) ) {
				continue;
			}
			$groups[ (string) ( $opt['group'] ?? 'other' ) ][] = $opt;
		}
		$labels     = static function ( string $g ) use ( $groups ): string {
			$out = array();
			foreach ( $groups[ $g ] ?? array() as $o ) {
				$out[] = Spec::label( $o['label'] ?? $o['id'] );
			}
			return implode( ', ', $out );
		};
		$edge_style = (string) ( $groups['edge'][0]['render']['style'] ?? '' );

		/* ---------- Specs ---------- */
		$specs = array(
			self::row( __( 'Techniek', 'borduur-patch-studio' ), Spec::technique_label( $tech ) ),
			/* translators: 1: width mm, 2: height mm, 3: longest side in cm */
			self::row( __( 'Maat', 'borduur-patch-studio' ), sprintf( __( '%1$s × %2$s mm (langste zijde %3$s cm)', 'borduur-patch-studio' ), Spec::num( round( $w_mm ), 0 ), Spec::num( round( $h_mm ), 0 ), Spec::num( $size_cm ) ), array( 'mono' => true ) ),
			self::row( __( 'Vorm', 'borduur-patch-studio' ), Spec::shape_label( $shape ) ),
			self::row( __( 'Aantal', 'borduur-patch-studio' ), Spec::pieces( $qty ) ),
		);
		if ( '' !== $labels( 'edge' ) ) {
			$specs[] = self::row( __( 'Rand', 'borduur-patch-studio' ), $labels( 'edge' ) );
		}
		$edge = isset( $p['edgeColor'] ) && is_array( $p['edgeColor'] ) ? $p['edgeColor'] : null;
		if ( $edge && ! empty( $edge['code'] ) && in_array( $edge_style, array( '', 'satin', 'merrow' ), true ) ) {
			$ti      = Spec::thread_info( (string) $edge['code'], (string) ( $edge['name'] ?? '' ), (string) ( $edge['hex'] ?? '' ) );
			$specs[] = self::row(
				__( 'Randkleur', 'borduur-patch-studio' ),
				$ti['name'] . ' · ' . Spec::brand() . ' ' . $ti['code'],
				array(
					'hex'  => $ti['hex'],
					'code' => $ti['code'],
				)
			);
		}
		if ( '' !== $labels( 'backing' ) ) {
			$specs[] = self::row( __( 'Achterkant', 'borduur-patch-studio' ), $labels( 'backing' ) );
		}
		$velcro = array_filter( array( $labels( 'velcro_color' ), $labels( 'backing_extra' ) ) );
		if ( $velcro ) {
			$specs[] = self::row( __( 'Klittenband', 'borduur-patch-studio' ), implode( ' · ', $velcro ) );
		}
		if ( '' !== $labels( 'thickness' ) ) {
			$specs[] = self::row( __( 'Dikte', 'borduur-patch-studio' ), $labels( 'thickness' ) );
		}
		if ( '' !== $labels( 'carrier' ) ) {
			$specs[] = self::row( __( 'Drager', 'borduur-patch-studio' ), $labels( 'carrier' ) );
		}
		$fabric = isset( $p['fabric'] ) && is_array( $p['fabric'] ) ? $p['fabric'] : array();
		$bg     = (string) ( $p['analysis']['background'] ?? '' );
		if ( $fabric ) {
			$fl      = Spec::fabric_label( $fabric ) . ( in_array( $bg, array( 'solid', 'keep' ), true ) ? ' — ' . __( 'achtergrond uit het ontwerp overgenomen', 'borduur-patch-studio' ) : '' );
			$specs[] = self::row( __( 'Stof / achtergrond', 'borduur-patch-studio' ), $fl, array( 'hex' => Spec::hex( $fabric['hex'] ?? '' ) ) );
		}
		$other = array_filter( array( $labels( 'extras' ), $labels( 'other' ) ) );
		if ( $other ) {
			$specs[] = self::row( __( 'Extra\'s', 'borduur-patch-studio' ), implode( ', ', $other ) );
		}

		/* ---------- Threads ---------- */
		$threads = array();
		foreach ( (array) ( $p['threads'] ?? array() ) as $th ) {
			if ( ! is_array( $th ) ) {
				continue;
			}
			$ti        = Spec::thread_info( (string) ( $th['code'] ?? '' ), (string) ( $th['name'] ?? '' ), (string) ( $th['hex'] ?? '' ) );
			$share     = isset( $th['share'] ) ? (float) $th['share'] : null;
			$pct       = null !== $share && $share > 0 ? Spec::num( $share * 100, $share < 0.01 ? 1 : 0 ) . ' %' : '';
			$threads[] = self::row(
				$ti['name'],
				trim( Spec::brand() . ' ' . $ti['code'] . ( '' !== $pct ? ' · ' . $pct : '' ) ),
				array(
					'hex'   => $ti['hex'],
					'code'  => $ti['code'],
					'share' => $pct,
					'mono'  => true,
				)
			);
		}

		/* ---------- Text layers (font with machine font, concurrentie-features-5) ---------- */
		$texts = array();
		foreach ( (array) ( $p['texts'] ?? array() ) as $tx ) {
			if ( ! is_array( $tx ) || '' === trim( (string) ( $tx['text'] ?? '' ) ) ) {
				continue;
			}
			$ti      = Spec::thread_info( (string) ( $tx['threadCode'] ?? '' ) );
			$parts   = array(
				Spec::font_atelier( (string) ( $tx['font'] ?? '' ) ),
				/* translators: %s: letter height in mm */
				sprintf( __( '%s mm hoog', 'borduur-patch-studio' ), Spec::num( (float) ( $tx['heightMm'] ?? 0 ) ) ),
				Spec::path_label( (string) ( $tx['path'] ?? 'straight' ) ),
				Spec::text_place( $tx ),
			);
			$parts[] = ( '' !== $ti['code'] && 'auto' !== $ti['code'] ) ? $ti['name'] . ' (' . Spec::brand() . ' ' . $ti['code'] . ')' : __( 'garenkleur automatisch', 'borduur-patch-studio' );
			$texts[] = self::row(
				'“' . (string) $tx['text'] . '”',
				implode( ' · ', array_filter( $parts ) ),
				array(
					'hex'  => $ti['hex'],
					'code' => 'auto' !== $ti['code'] ? $ti['code'] : '',
				)
			);
		}

		/* ---------- Production ---------- */
		$a    = isset( $p['analysis'] ) && is_array( $p['analysis'] ) ? $p['analysis'] : null;
		$prod = array();
		if ( $a && ! empty( $a['stitches'] ) && is_array( $a['stitches'] ) && $thread ) {
			/* translators: 1: min stitches, 2: max stitches */
			$prod[] = self::row( __( 'Geschat steekaantal', 'borduur-patch-studio' ), sprintf( __( '± %1$s – %2$s steken (schatting, per stuk)', 'borduur-patch-studio' ), Spec::int( (int) ( $a['stitches']['min'] ?? 0 ) ), Spec::int( (int) ( $a['stitches']['max'] ?? 0 ) ) ), array( 'mono' => true ) );
		}
		if ( $threads ) {
			$prod[] = self::row( $thread ? __( 'Aantal garenkleuren', 'borduur-patch-studio' ) : __( 'Aantal kleuren', 'borduur-patch-studio' ), (string) count( $threads ) . ( $texts ? ' ' . __( '(inclusief tekst)', 'borduur-patch-studio' ) : '' ) );
		}
		$art = isset( $p['art'] ) && is_array( $p['art'] ) ? $p['art'] : null;
		if ( $art && 'text' !== ( $p['source']['kind'] ?? '' ) && ! empty( $p['source']['fileName'] ) ) {
			/* translators: 1: scale %, 2: x offset mm, 3: y offset mm, 4: rotation degrees */
			$prod[] = self::row( __( 'Plaatsing ontwerp', 'borduur-patch-studio' ), sprintf( __( 'schaal %1$s %% · verschoven %2$s / %3$s mm (x / y) · rotatie %4$s°', 'borduur-patch-studio' ), Spec::num( (float) ( $art['scale'] ?? 1 ) * 100, 0 ), Spec::num( (float) ( $art['offsetMm']['x'] ?? 0 ) ), Spec::num( (float) ( $art['offsetMm']['y'] ?? 0 ) ), (string) (int) ( $art['rotation'] ?? 0 ) ) );
		}

		$checks   = Spec::checks( $tech, $t, $p, $a, $threads, $edge_style );
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
				'rows'  => self::analysis_rows( $a, $bg ),
			),
			'customer'   => array(
				'title' => __( 'Klant en advies', 'borduur-patch-studio' ),
				'rows'  => self::customer_section( $p, $tech, $item ),
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
		$sections = array_filter(
			(array) apply_filters( 'bps_fiche_sections', $sections, $rec, $item ),
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

		$proof  = Proof_Service::get( $item );
		$ref    = '' !== (string) $rec['ref'] ? (string) $rec['ref'] : (string) $item->get_meta( 'bps_ref' );
		$order  = $item->get_order();
		$copies = array();
		foreach ( $threads as $row ) {
			if ( '' !== (string) $row['code'] ) {
				$copies[] = Spec::copy_line( (string) $row['code'], (string) $row['label'] );
			}
		}
		$fiche = array_merge(
			array(
				'item_id'       => (int) $item->get_id(),
				'order_id'      => (int) $item->get_order_id(),
				'design_id'     => (int) $rec['id'],
				'design_exists' => (bool) $d['exists'],
				'ref'           => $ref,
				'name'          => (string) $item->get_name(),
				'preview_url'   => (string) $rec['preview_url'],
				'technique'     => $tech,
				'technique_lbl' => Spec::technique_label( $tech ),
				'thread_based'  => $thread,
				'shape'         => $shape,
				'shape_lbl'     => Spec::shape_label( $shape ),
				'width_mm'      => $w_mm,
				'height_mm'     => $h_mm,
				'size_cm'       => $size_cm,
				'qty'           => $qty,
				'locale'        => (string) ( $p['locale'] ?? '' ),
				'notes'         => trim( (string) ( $p['notes'] ?? '' ) ),
				'sections'      => $sections,
				'checks_level'  => Spec::worst_level( $checks ),
				'original'      => $original,
				'file_removed'  => (string) $item->get_meta( 'bps_file_removed' ),
				'proof'         => $proof,
				'payload'       => $p,
				'copy_codes'    => $copies,
				'facts'         => Work_Order::facts( $order, $ref ),
				'production'    => array( 'status' => '' ),
			),
			Work_Order::visual( $rec, $proof, $t )
		);
		Spec::set_lang( $prev );

		/**
		 * Data of one production sheet (card, print, other packages such as the atelier overview).
		 *
		 * @param array                  $fiche Fiche.
		 * @param \WC_Order|false        $order Order.
		 * @param \WC_Order_Item_Product $item  Line.
		 */
		$fiche = apply_filters( 'bps_fiche_data', $fiche, $order, $item );
		return is_array( $fiche ) ? $fiche : array();
	}

	/**
	 * Sheet row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param array  $extra hex, code, mono, level, share.
	 */
	private static function row( string $label, string $value, array $extra = array() ): array {
		return array_merge(
			array(
				'label' => $label,
				'value' => $value,
			),
			$extra
		);
	}

	/**
	 * Analysis rows.
	 *
	 * @param array|null $a  Analysis.
	 * @param string     $bg Background kind.
	 */
	private static function analysis_rows( ?array $a, string $bg ): array {
		if ( ! $a ) {
			return array();
		}
		$kinds = array(
			'flat'         => __( 'Vlak logo', 'borduur-patch-studio' ),
			'gradient'     => __( 'Logo met kleurverloop', 'borduur-patch-studio' ),
			'illustration' => __( 'Illustratie met tinten', 'borduur-patch-studio' ),
			'photo'        => __( 'Foto', 'borduur-patch-studio' ),
		);
		$bgs   = array(
			'transparent' => __( 'transparant', 'borduur-patch-studio' ),
			'white'       => __( 'wit (weggelaten, stofkleur gekozen)', 'borduur-patch-studio' ),
			'solid'       => __( 'effen kleur (meegenomen als patchachtergrond)', 'borduur-patch-studio' ),
			'none'        => __( 'geen aparte achtergrond (ontwerp loopt tot de rand)', 'borduur-patch-studio' ),
		);
		$kind  = (string) ( $a['kind'] ?? '' );
		$an    = array( self::row( __( 'Soort ontwerp', 'borduur-patch-studio' ), ( $kinds[ $kind ] ?? $kind ) . ( ! empty( $a['hasSoftShading'] ) ? ' · ' . __( 'met zachte schaduwen', 'borduur-patch-studio' ) : '' ) ) );
		if ( '' !== $bg ) {
			$an[] = self::row( __( 'Achtergrond bestand', 'borduur-patch-studio' ), $bgs[ $bg ] ?? $bg );
		}
		if ( ! empty( $a['shape'] ) ) {
			$an[] = self::row( __( 'Vorm herkend', 'borduur-patch-studio' ), Spec::shape_label( (string) $a['shape'] ) );
		}
		if ( ! empty( $a['colors'] ) ) {
			$an[] = self::row( __( 'Kleuren in het bestand', 'borduur-patch-studio' ), (string) (int) $a['colors'] );
		}
		if ( isset( $a['fineLineMm'] ) && null !== $a['fineLineMm'] ) {
			/* translators: %s: mm */
			$an[] = self::row( __( 'Fijnste lijn', 'borduur-patch-studio' ), sprintf( __( '± %s mm', 'borduur-patch-studio' ), Spec::num( (float) $a['fineLineMm'] ) ), array( 'mono' => true ) );
		}
		if ( isset( $a['minTextMm'] ) && null !== $a['minTextMm'] ) {
			/* translators: %s: mm */
			$an[] = self::row( __( 'Kleinste tekst in het bestand', 'borduur-patch-studio' ), sprintf( __( '± %s mm', 'borduur-patch-studio' ), Spec::num( (float) $a['minTextMm'] ) ), array( 'mono' => true ) );
		}
		return $an;
	}

	/**
	 * "Klant en advies" rows.
	 *
	 * @param array                  $p    Payload.
	 * @param string                 $tech Technique id.
	 * @param \WC_Order_Item_Product $item Line.
	 */
	private static function customer_section( array $p, string $tech, $item ): array {
		$src  = isset( $p['source'] ) && is_array( $p['source'] ) ? $p['source'] : array();
		$cust = array(
			self::row(
				__( 'Bestand van de klant', 'borduur-patch-studio' ),
				! empty( $src['fileName'] ) ? (string) $src['fileName'] . ( ! empty( $src['fileType'] ) ? ' (' . (string) $src['fileType'] . ')' : '' ) : __( 'Geen: patch met enkel tekst', 'borduur-patch-studio' )
			),
		);
		if ( ! empty( $p['advice']['recommended'] ) ) {
			$words  = array(
				'ok'   => __( 'aanrader', 'borduur-patch-studio' ),
				'warn' => __( 'kan, met aanpassing', 'borduur-patch-studio' ),
				'bad'  => __( 'raden we af', 'borduur-patch-studio' ),
			);
			$status = (string) ( $p['advice']['chosenStatus'] ?? '' );
			/* translators: 1: recommended technique, 2: chosen technique, 3: status word */
			$value = sprintf( __( 'Ons advies: %1$s · gekozen: %2$s (%3$s)', 'borduur-patch-studio' ), Spec::technique_label( (string) $p['advice']['recommended'] ), Spec::technique_label( $tech ), $words[ $status ] ?? $status );
			if ( ! empty( $p['advice']['headline'] ) ) {
				$value .= ' — ' . (string) $p['advice']['headline'];
			}
			$cust[] = self::row( __( 'Advies in de tool', 'borduur-patch-studio' ), $value, array( 'level' => in_array( $status, array( 'ok', '' ), true ) ? '' : ( 'bad' === $status ? 'bad' : 'warn' ) ) );
		}
		$rights = (string) $item->get_meta( 'bps_rights' );
		if ( '' !== $rights ) {
			$cust[] = self::row( __( 'Rechtenverklaring', 'borduur-patch-studio' ), $rights );
		}
		return $cust;
	}


	/**
	 * Work steps for the sheet with their done state (see Proof\Work_Order::work_steps()).
	 *
	 * @param array $fiche Fiche.
	 */
	public static function work_steps( array $fiche ): array {
		return Work_Order::work_steps( $fiche );
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
		unset( $post_type );
		$order = self::order_from( $obj );
		if ( ! $order || ! self::design_items( $order ) ) {
			return;
		}
		foreach ( self::order_screens() as $screen ) {
			add_meta_box( 'bps-production', __( 'Patch Studio · productiefiche', 'borduur-patch-studio' ), array( __CLASS__, 'render_metabox' ), $screen, 'normal', 'high' );
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
			$fiches[] = self::fiche( $order, $item );
		}
		$can     = current_user_can( 'edit_shop_orders' );
		$stopped = Proof_Service::blocked_status( $order );
		$code    = isset( $_GET['bps_order_proof'] ) ? sanitize_key( wp_unslash( $_GET['bps_order_proof'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, fixed messages.
		self::template(
			'admin/order-metabox.php',
			array(
				'order'       => $order,
				'fiches'      => $fiches,
				'print_url'   => self::print_url( (int) $order->get_id() ),
				'can_proof'   => $can,
				'admin_data'  => self::admin_data( $order, $fiches ),
				'order_proof' => $can && '' === $stopped && count( Order_Proof::eligible( $order ) ) > 1 ? Order_Proof::admin_url( (int) $order->get_id() ) : '',
				'notice'      => self::notice( $code ),
				'stopped'     => $stopped,
			)
		);
	}

	/**
	 * Fixed admin notice for a result code of the whole-order proof.
	 *
	 * @param string $code Code.
	 * @return array{type:string,text:string}|null
	 */
	private static function notice( string $code ): ?array {
		if ( '' === $code ) {
			return null;
		}
		$texts = array(
			'sent'              => __( 'De proef voor de hele bestelling is verstuurd.', 'borduur-patch-studio' ),
			'bps_order_stopped' => __( 'Deze bestelling is geannuleerd, terugbetaald of mislukt. Je kunt geen proef meer versturen.', 'borduur-patch-studio' ),
			'bps_no_lines'      => __( 'Er is geen patch in deze bestelling waarvoor nog een proef nodig is.', 'borduur-patch-studio' ),
			'bps_no_email'      => __( 'Deze bestelling heeft geen geldig e-mailadres. Vul een ontvanger in.', 'borduur-patch-studio' ),
			'bps_mail_disabled' => __( 'De e-mail "Patch Studio: proef voor de hele bestelling" staat uit onder WooCommerce › Instellingen › E-mails.', 'borduur-patch-studio' ),
		);
		return array(
			'type' => 'sent' === $code ? 'success' : 'error',
			'text' => $texts[ $code ] ?? __( 'De proef kon niet verstuurd worden.', 'borduur-patch-studio' ),
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
		$first = trim( (string) $order->get_billing_first_name() );
		foreach ( $fiches as $f ) {
			$lang = Spec::lang_of( $f['locale'] );
			Lang::switch_to( $lang );
			$items[ (string) $f['item_id'] ] = array(
				'ref'        => $f['ref'],
				'designId'   => $f['design_id'],
				'exists'     => $f['design_exists'],
				'preview'    => $f['preview_url'],
				'lang'       => $lang,
				'defaultMsg' => '' !== $first
					/* translators: %1$s: customer first name */
					? sprintf( __( 'Hallo %1$s, hier is de digitale proef van je patch. Kijk je alles even na? Klopt alles, dan starten we meteen met de productie.', 'borduur-patch-studio' ), $first )
					: __( 'Hier is de digitale proef van je patch. Kijk je alles even na? Klopt alles, dan starten we meteen met de productie.', 'borduur-patch-studio' ),
				'round'      => (int) $f['proof']['round'],
			);
			Lang::restore();
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
					array( '_bps_design_id', '_bps_preview_url', '_bps_schema', '_bps_technique_id', '_bps_size_cm', '_bps_shape_id', '_bps_options', '_bps_unit_excl', '_bps_payload', '_bps_rights_consent', self::ITEM_PROOF_META, Proof_Service::META )
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
		unset( $product );
		if ( ! is_admin() || ! $item instanceof \WC_Order_Item_Product || (int) $item->get_meta( '_bps_design_id' ) <= 0 && '' === (string) $item->get_meta( '_bps_payload' ) ) {
			return;
		}
		$status = Proof_Service::get( $item )['status'];
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
		unset( $hook );
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
		$order_id = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze productiefiche.', 'borduur-patch-studio' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bps_print_fiche_' . $order_id );
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Bestelling niet gevonden.', 'borduur-patch-studio' ), '', array( 'response' => 404 ) );
		}
		$only  = isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0;
		$pairs = array();
		foreach ( array_keys( self::design_items( $order ) ) as $item_id ) {
			if ( 0 === $only || $only === (int) $item_id ) {
				$pairs[] = array( $order_id, (int) $item_id );
			}
		}
		if ( ! $pairs ) {
			wp_die( esc_html__( 'Deze bestelling bevat geen Patch Studio-patches.', 'borduur-patch-studio' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo self::render_print( $pairs, ! empty( $_GET['autoprint'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
		exit;
	}

	/**
	 * Printable sheets for any [order_id, item_id] pairs, also across orders (bulk print of the atelier overview).
	 * The caller checks capabilities.
	 *
	 * @param array $pairs     [[order_id, item_id], …].
	 * @param bool  $autoprint Open the print dialog.
	 */
	public static function render_print( array $pairs, bool $autoprint = false ): string {
		$pages = array();
		foreach ( $pairs as $pair ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) ( $pair[0] ?? 0 ) ) : null;
			$items = $order instanceof \WC_Order ? self::design_items( $order ) : array();
			$item  = $items[ (int) ( $pair[1] ?? 0 ) ] ?? null;
			if ( $item ) {
				$pages[] = array(
					'order'     => $order,
					'fiche'     => self::fiche( $order, $item ),
					'order_url' => method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . (int) $order->get_id() . '&action=edit' ),
				);
			}
		}
		if ( ! $pages ) {
			return '';
		}
		self::ensure_assets_registered();
		$scripts = wp_scripts();
		$js      = isset( $scripts->registered['bps-admin'] ) ? $scripts->registered['bps-admin'] : null;
		return self::template_html(
			'admin/fiche-print.php',
			array(
				'pages'     => $pages,
				'js_url'    => $js ? add_query_arg( 'ver', $js->ver, $js->src ) : '',
				'site_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'autoprint' => $autoprint,
			)
		);
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
		$message   = isset( $_POST['message'] ) ? Proof_Service::clean_text( wp_unslash( $_POST['message'] ) ) : '';
		$image_id  = isset( $_POST['image_id'] ) ? absint( wp_unslash( $_POST['image_id'] ) ) : 0;
		$recipient = isset( $_POST['recipient'] ) ? sanitize_email( wp_unslash( $_POST['recipient'] ) ) : '';
		// phpcs:enable
		$result = self::send_proof( $order, $item, $message, $image_id, $recipient );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		$fiche = self::fiche( $order, $item );
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
		return Proof_Service::send( $order, $item, $message, $image_id, $recipient );
	}

	/**
	 * AJAX: set the proof status manually (e.g. approved by phone).
	 */
	public static function ajax_set_status(): void {
		list( $order, $item ) = self::ajax_context();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in ajax_context().
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? Proof_Service::clean_text( wp_unslash( $_POST['note'] ) ) : '';
		// phpcs:enable
		if ( ! in_array( $status, array( 'approved', 'changes_requested', 'none' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Onbekende status.', 'borduur-patch-studio' ) ), 400 );
		}
		if ( '' !== Proof_Service::blocked_status( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Deze bestelling is geannuleerd, terugbetaald of mislukt. De proefstatus kan niet meer veranderen.', 'borduur-patch-studio' ) ), 400 );
		}
		Proof_Service::set_manual( $order, $item, $status, $note );
		$fiche = self::fiche( $order, $item );
		wp_send_json_success(
			array(
				'message' => __( 'Status bijgewerkt.', 'borduur-patch-studio' ),
				'html'    => self::proof_block_html( $fiche ),
				'status'  => $status,
				'label'   => self::status_label( $status ),
			)
		);
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
			echo self::list_cell_html( $order instanceof \WC_Order ? $order : wc_get_order( $order ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in list_cell_html().
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
			echo self::list_cell_html( wc_get_order( $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in list_cell_html().
		}
	}

	/**
	 * Cell markup: aggregated proof status of the patch lines ("Geannuleerd" in red for stopped orders).
	 *
	 * @param mixed $order Order.
	 */
	public static function list_cell_html( $order ): string {
		$items = self::design_items( $order );
		if ( ! $items ) {
			return '<span aria-hidden="true">–</span>';
		}
		$count = array_fill_keys( self::STATUSES, 0 );
		foreach ( $items as $item ) {
			$s = (string) ( $item->get_meta( self::ITEM_PROOF_META ) ?: 'none' );
			++$count[ isset( $count[ $s ] ) ? $s : 'none' ];
		}
		$n = count( $items );
		if ( '' !== Proof_Service::blocked_status( $order ) || $count['cancelled'] === $n ) {
			$s = 'cancelled';
		} elseif ( $count['changes_requested'] > 0 ) {
			$s = 'changes_requested';
		} elseif ( $count['approved'] + $count['cancelled'] === $n ) {
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
			'cancelled'         => __( 'Geannuleerd', 'borduur-patch-studio' ),
		);
		$colors = array(
			'none'              => '#6B6B6B',
			'sent'              => '#0B74A6',
			'approved'          => '#15803D',
			'changes_requested' => '#B45309',
			'cancelled'         => '#B42318',
		);
		$text = $labels[ $s ] . ( $n > 1 && 'cancelled' !== $s ? ' (' . $count['approved'] . '/' . $n . ')' : '' );
		return sprintf(
			'<span class="bps-proof-pill bps-proof-pill--%1$s" style="display:inline-block;padding:2px 8px;border-radius:10px;border:1px solid %2$s;color:%2$s;font-size:12px;line-height:1.6;white-space:nowrap">%3$s</span>',
			esc_attr( $s ),
			esc_attr( $colors[ $s ] ),
			esc_html( $text )
		);
	}
}
