<?php
/**
 * Quote requests ("Vraag je prijs op maat aan" / "Laat een vakman meekijken"): CPT bps_quote + admin view.
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A quote is a lead, not an order: private CPT, listed under WooCommerce › Patch-offertes.
 */
final class Quotes {

	const CPT = 'bps_quote';

	/** Meta keys holding customer data (privacy exporter/eraser). */
	const CONTACT_META = array( '_bps_name', '_bps_email', '_bps_phone', '_bps_company', '_bps_vat', '_bps_message' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 5 );
		add_action( 'add_meta_boxes_' . self::CPT, array( __CLASS__, 'meta_boxes' ) );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
	}

	/**
	 * CPT.
	 */
	public static function register_post_type(): void {
		if ( post_type_exists( self::CPT ) ) {
			return;
		}
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'               => __( 'Patch-offertes', 'borduur-patch-studio' ),
					'singular_name'      => __( 'Patch-offerte', 'borduur-patch-studio' ),
					'menu_name'          => __( 'Patch-offertes', 'borduur-patch-studio' ),
					'all_items'          => __( 'Patch-offertes', 'borduur-patch-studio' ),
					'edit_item'          => __( 'Offerte-aanvraag bekijken', 'borduur-patch-studio' ),
					'search_items'       => __( 'Offertes zoeken', 'borduur-patch-studio' ),
					'not_found'          => __( 'Nog geen offerte-aanvragen.', 'borduur-patch-studio' ),
					'not_found_in_trash' => __( 'Geen offertes in de prullenbak.', 'borduur-patch-studio' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'woocommerce',
				'show_in_rest'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'query_var'           => false,
				'rewrite'             => false,
				'has_archive'         => false,
				'can_export'          => true,
				'delete_with_user'    => false,
				'supports'            => array( 'title' ),
				'capabilities'        => array_merge( Designs::caps(), array( 'create_posts' => 'do_not_allow' ) ),
				'map_meta_cap'        => false,
			)
		);
	}

	/**
	 * Create a quote request.
	 *
	 * @param array      $data   Clean fields (name, email, phone, company, vat, qty, technique, deadline, message, locale, rights).
	 * @param array|null $design DesignRecord or null.
	 * @return array|WP_Error {id, ref}
	 */
	public static function create( array $data, ?array $design ) {
		$ref = Designs::next_ref( 'bps_quote_seq', 'OF' );
		$id  = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'private',
				'post_title'  => $ref,
				'post_author' => 0,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'bps_store', __( 'Je aanvraag kon niet bewaard worden. Probeer het nog eens of mail ons.', 'borduur-patch-studio' ), array( 'status' => 500 ) );
		}
		$id   = (int) $id;
		$meta = array(
			'_bps_name'       => $data['name'],
			'_bps_email'      => $data['email'],
			'_bps_phone'      => $data['phone'],
			'_bps_company'    => $data['company'],
			'_bps_vat'        => $data['vat'],
			'_bps_qty'        => (int) $data['qty'],
			'_bps_technique'  => $data['technique'],
			'_bps_deadline'   => $data['deadline'],
			'_bps_message'    => $data['message'],
			'_bps_locale'     => $data['locale'],
			'_bps_design_id'  => $design ? (int) $design['id'] : 0,
			'_bps_created_gmt'=> gmdate( 'Y-m-d H:i:s' ),
			'_bps_rights_consent' => array(
				'v'         => (string) ( $data['rights']['v'] ?? '' ),
				'at'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'text_sha1' => sha1( Order::rights_text( $data['locale'] ) ),
				'locale'    => Config::wp_locale( $data['locale'] ),
			),
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, is_string( $v ) ? wp_slash( $v ) : $v );
		}
		if ( $design ) {
			Designs::set_status( (int) $design['id'], 'quoted' );
			update_post_meta( (int) $design['id'], '_bps_email', $data['email'] );
			update_post_meta( (int) $design['id'], '_bps_quote_id', $id );
			Designs::touch( (int) $design['id'] );
		}

		do_action( 'bps_quote_requested', $id, $design ? (int) $design['id'] : 0 );

		$admin = Emails::get( 'BPS_Email_Quote_Admin' );
		if ( $admin ) {
			$admin->trigger( $id );
		}
		$customer = Emails::get( 'BPS_Email_Quote_Customer' );
		if ( $customer ) {
			$customer->trigger( $id );
		}
		return array(
			'id'  => $id,
			'ref' => $ref,
		);
	}

	/**
	 * Data for templates.
	 *
	 * @param int $id Quote id.
	 */
	public static function get( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return null;
		}
		$m         = static function ( $k ) use ( $id ) {
			return get_post_meta( $id, $k, true );
		};
		$design_id = (int) $m( '_bps_design_id' );
		return array(
			'id'        => $id,
			'ref'       => (string) $post->post_title,
			'name'      => (string) $m( '_bps_name' ),
			'email'     => (string) $m( '_bps_email' ),
			'phone'     => (string) $m( '_bps_phone' ),
			'company'   => (string) $m( '_bps_company' ),
			'vat'       => (string) $m( '_bps_vat' ),
			'qty'       => (int) $m( '_bps_qty' ),
			'technique' => (string) $m( '_bps_technique' ),
			'deadline'  => (string) $m( '_bps_deadline' ),
			'message'   => (string) $m( '_bps_message' ),
			'locale'    => (string) ( $m( '_bps_locale' ) ?: 'nl-BE' ),
			'created'   => (string) $m( '_bps_created_gmt' ),
			'design'    => $design_id > 0 ? Designs::get( $design_id ) : null,
		);
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------- */

	/**
	 * Meta box with all details.
	 */
	public static function meta_boxes(): void {
		remove_meta_box( 'submitdiv', self::CPT, 'side' );
		add_meta_box( 'bps-quote-details', __( 'Offerte-aanvraag', 'borduur-patch-studio' ), array( __CLASS__, 'render_box' ), self::CPT, 'normal', 'high' );
	}

	/**
	 * Render the details box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_box( $post ): void {
		$q = self::get( (int) $post->ID );
		if ( ! $q ) {
			return;
		}
		$d    = $q['design'];
		$rows = array(
			__( 'Naam', 'borduur-patch-studio' )      => $q['name'],
			__( 'E-mail', 'borduur-patch-studio' )    => $q['email'],
			__( 'Telefoon', 'borduur-patch-studio' )  => $q['phone'],
			__( 'Bedrijf', 'borduur-patch-studio' )   => $q['company'],
			__( 'Btw-nummer', 'borduur-patch-studio' ) => $q['vat'],
			__( 'Aantal', 'borduur-patch-studio' )    => (string) $q['qty'],
			__( 'Techniek', 'borduur-patch-studio' )  => '' !== $q['technique'] ? Config::technique_label( $q['technique'] ) : '',
			__( 'Gewenste datum', 'borduur-patch-studio' ) => $q['deadline'],
			__( 'Taal', 'borduur-patch-studio' )      => 'fr' === Config::lang( $q['locale'] ) ? 'Français' : 'Nederlands',
		);
		echo '<div style="display:flex;gap:24px;flex-wrap:wrap">';
		if ( $d && '' !== $d['preview_url'] ) {
			echo '<div><img src="' . esc_url( $d['preview_url'] ) . '" alt="" style="width:220px;height:auto;border:1px solid #ddd;border-radius:8px;background:#f5f5f5"></div>';
		}
		echo '<div style="flex:1;min-width:280px"><table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			echo '<tr><th style="width:160px">' . esc_html( $label ) . '</th><td>';
			if ( __( 'E-mail', 'borduur-patch-studio' ) === $label ) {
				echo '<a href="mailto:' . esc_attr( $value ) . '?subject=' . rawurlencode( $q['ref'] ) . '">' . esc_html( $value ) . '</a>';
			} else {
				echo esc_html( $value );
			}
			echo '</td></tr>';
		}
		if ( $d ) {
			foreach ( self::design_rows( $d ) as $row ) {
				echo '<tr><th>' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';
		if ( '' !== $q['message'] ) {
			echo '<h4>' . esc_html__( 'Bericht van de klant', 'borduur-patch-studio' ) . '</h4><p style="white-space:pre-wrap">' . esc_html( $q['message'] ) . '</p>';
		}
		if ( $d && $d['original'] ) {
			echo '<p><a class="button button-primary" href="' . esc_url( Designs::original_download_url( (int) $d['id'] ) ) . '">' . esc_html__( 'Originele bestand downloaden', 'borduur-patch-studio' ) . '</a></p>';
		}
		echo '</div></div>';
	}

	/**
	 * Human rows for a design (admin + e-mails).
	 *
	 * @param array       $d      DesignRecord.
	 * @param string|null $locale Locale for labels.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function design_rows( array $d, ?string $locale = null ): array {
		$p    = (array) $d['payload'];
		$lang = Config::lang( $locale );
		$rows = Cart::summary_rows(
			array(
				'ref'       => $d['ref'],
				'technique' => (string) ( $p['technique'] ?? '' ),
				'size_cm'   => (float) ( $p['sizeCm'] ?? 0 ),
				'width_mm'  => (float) ( $p['widthMm'] ?? 0 ),
				'height_mm' => (float) ( $p['heightMm'] ?? 0 ),
				'shape'     => (string) ( $p['shape'] ?? '' ),
				'options'   => (array) ( $p['options'] ?? array() ),
			),
			$lang
		);
		$fabric = self::fabric_label( (array) ( $p['fabric'] ?? array() ), $lang );
		if ( '' !== $fabric ) {
			$rows[] = array( __( 'Stofkleur', 'borduur-patch-studio' ), $fabric );
		}
		if ( ! empty( $p['threads'] ) ) {
			$codes = array();
			foreach ( (array) $p['threads'] as $t ) {
				$codes[] = trim( ( $t['name'] ?? '' ) . ' ' . ( isset( $t['code'] ) ? '(' . $t['code'] . ')' : '' ) );
			}
			$rows[] = array( __( 'Garenkleuren', 'borduur-patch-studio' ), implode( ', ', array_slice( $codes, 0, 16 ) ) );
		}
		if ( ! empty( $p['texts'] ) ) {
			$texts = array();
			foreach ( (array) $p['texts'] as $t ) {
				$texts[] = '“' . $t['text'] . '” (' . $t['font'] . ', ' . Config::num( (float) $t['heightMm'] ) . ' mm)';
			}
			$rows[] = array( __( 'Tekst', 'borduur-patch-studio' ), implode( ' · ', $texts ) );
		}
		if ( ! empty( $p['analysis'] ) && is_array( $p['analysis'] ) ) {
			$a     = $p['analysis'];
			$parts = array();
			if ( ! empty( $a['colors'] ) ) {
				/* translators: %d: number of colours */
				$parts[] = sprintf( _n( '%d kleur', '%d kleuren', (int) $a['colors'], 'borduur-patch-studio' ), (int) $a['colors'] );
			}
			if ( isset( $a['fineLineMm'] ) && null !== $a['fineLineMm'] ) {
				/* translators: %s: mm */
				$parts[] = sprintf( __( 'fijnste lijn ±%s mm', 'borduur-patch-studio' ), Config::num( (float) $a['fineLineMm'] ) );
			}
			if ( isset( $a['minTextMm'] ) && null !== $a['minTextMm'] ) {
				/* translators: %s: mm */
				$parts[] = sprintf( __( 'kleinste tekst ±%s mm', 'borduur-patch-studio' ), Config::num( (float) $a['minTextMm'] ) );
			}
			if ( $parts ) {
				$rows[] = array( __( 'Analyse', 'borduur-patch-studio' ), implode( ' · ', $parts ) );
			}
		}
		if ( ! empty( $p['advice']['headline'] ) ) {
			$rows[] = array( __( 'Advies', 'borduur-patch-studio' ), (string) $p['advice']['headline'] );
		}
		if ( ! empty( $p['notes'] ) ) {
			$rows[] = array( __( 'Opmerking bij het ontwerp', 'borduur-patch-studio' ), (string) $p['notes'] );
		}
		return $rows;
	}

	/**
	 * Fabric label in the recipient's language (config label by id; the browser label as fallback).
	 *
	 * @param array  $fabric Payload fabric {id, hex, label}.
	 * @param string $lang   Language.
	 */
	private static function fabric_label( array $fabric, string $lang ): string {
		$id = (string) ( $fabric['id'] ?? '' );
		foreach ( array_merge( (array) Config::path( 'fabrics', array() ), (array) Config::path( 'leathers', array() ) ) as $f ) {
			if ( isset( $f['id'] ) && $f['id'] === $id ) {
				return Config::label( $f['label'] ?? $id, $lang );
			}
		}
		return (string) ( $fabric['label'] ?? '' );
	}

	/**
	 * List columns.
	 *
	 * @param array $cols Columns.
	 */
	public static function columns( $cols ): array {
		return array(
			'cb'            => $cols['cb'] ?? '<input type="checkbox" />',
			'title'         => __( 'Offerte', 'borduur-patch-studio' ),
			'bps_preview'   => __( 'Voorbeeld', 'borduur-patch-studio' ),
			'bps_customer'  => __( 'Klant', 'borduur-patch-studio' ),
			'bps_technique' => __( 'Techniek', 'borduur-patch-studio' ),
			'bps_qty'       => __( 'Aantal', 'borduur-patch-studio' ),
			'date'          => __( 'Datum', 'borduur-patch-studio' ),
		);
	}

	/**
	 * Column content.
	 *
	 * @param string $col     Column.
	 * @param int    $post_id Post id.
	 */
	public static function column( $col, $post_id ): void {
		$post_id = (int) $post_id;
		switch ( $col ) {
			case 'bps_preview':
				$d = Designs::get( (int) get_post_meta( $post_id, '_bps_design_id', true ) );
				if ( $d && '' !== $d['preview_url'] ) {
					echo '<img src="' . esc_url( $d['preview_url'] ) . '" alt="" width="48" height="48" style="object-fit:contain;background:#f5f5f5;border-radius:4px">';
				}
				break;
			case 'bps_customer':
				echo esc_html( (string) get_post_meta( $post_id, '_bps_name', true ) ) . '<br><a href="mailto:' . esc_attr( (string) get_post_meta( $post_id, '_bps_email', true ) ) . '">' . esc_html( (string) get_post_meta( $post_id, '_bps_email', true ) ) . '</a>';
				break;
			case 'bps_technique':
				$t = (string) get_post_meta( $post_id, '_bps_technique', true );
				echo esc_html( '' !== $t ? Config::technique_label( $t ) : '—' );
				break;
			case 'bps_qty':
				echo esc_html( (string) (int) get_post_meta( $post_id, '_bps_qty', true ) );
				break;
		}
	}

	/**
	 * No "quick edit" for quotes.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 */
	public static function row_actions( $actions, $post ) {
		if ( $post instanceof \WP_Post && self::CPT === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'] );
			if ( isset( $actions['edit'] ) ) {
				$actions['edit'] = '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html__( 'Bekijken', 'borduur-patch-studio' ) . '</a>';
			}
		}
		return $actions;
	}

	/**
	 * When a quote is deleted, its design files go too (unless the design was ordered).
	 *
	 * @param int $post_id Post id.
	 */
	public static function on_delete( $post_id ): void {
		if ( self::CPT !== get_post_type( (int) $post_id ) ) {
			return;
		}
		$design_id = (int) get_post_meta( (int) $post_id, '_bps_design_id', true );
		if ( $design_id > 0 && 'quoted' === get_post_meta( $design_id, '_bps_status', true ) ) {
			wp_delete_post( $design_id, true );
		}
	}
}
