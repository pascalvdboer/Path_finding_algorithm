<?php
/**
 * Design storage (CPT bps_design) and its public PHP API (SPEC §6.1).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

use BorduurPatchStudio\Uploads;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Designs are not orders, so a private CPT with post meta is fine (HPOS only concerns orders).
 */
final class Designs {

	const CPT      = 'bps_design';
	const STATUSES = array( 'draft', 'carted', 'ordered', 'quoted', 'saved' );

	/** Status rank: a design never moves "back" (ordered stays ordered when it is later saved again). */
	const RANK = array(
		'draft'   => 0,
		'carted'  => 1,
		'saved'   => 2, // A saved design keeps the longer "saved" retention even when it is carted later.
		'quoted'  => 3,
		'ordered' => 4,
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 5 );
		add_action( 'admin_post_bps_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ), 10, 1 );
	}

	/**
	 * CPT (not public, no UI; production shows designs on the order screen).
	 */
	public static function register_post_type(): void {
		if ( post_type_exists( self::CPT ) ) {
			return;
		}
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'Patch-ontwerpen', 'borduur-patch-studio' ),
					'singular_name' => __( 'Patch-ontwerp', 'borduur-patch-studio' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'show_in_nav_menus'   => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'query_var'           => false,
				'rewrite'             => false,
				'has_archive'         => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'capabilities'        => self::caps(),
				'map_meta_cap'        => false,
			)
		);
	}

	/**
	 * All CPT capabilities map to manage_woocommerce.
	 */
	public static function caps(): array {
		$cap = 'manage_woocommerce';
		return array(
			'edit_post'              => $cap,
			'read_post'              => $cap,
			'delete_post'            => $cap,
			'edit_posts'             => $cap,
			'edit_others_posts'      => $cap,
			'publish_posts'          => $cap,
			'read_private_posts'     => $cap,
			'delete_posts'           => $cap,
			'delete_private_posts'   => $cap,
			'delete_published_posts' => $cap,
			'delete_others_posts'    => $cap,
			'edit_private_posts'     => $cap,
			'edit_published_posts'   => $cap,
			'create_posts'           => $cap,
		);
	}

	/* ---------------------------------------------------------------------
	 * Public API (SPEC §6.1) — used by production and other code
	 * ------------------------------------------------------------------- */

	/**
	 * DesignRecord or null.
	 *
	 * @param int $id Design id.
	 */
	public static function get( int $id ): ?array {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || self::CPT !== $post->post_type ) {
			return null;
		}
		$payload  = json_decode( (string) get_post_meta( $id, '_bps_payload', true ), true );
		$original = get_post_meta( $id, '_bps_original', true );
		$preview  = get_post_meta( $id, '_bps_preview', true );
		$proof    = get_post_meta( $id, '_bps_proof', true );
		$order_id = (int) get_post_meta( $id, '_bps_order_id', true );
		$item_id  = (int) get_post_meta( $id, '_bps_order_item_id', true );
		$email    = (string) get_post_meta( $id, '_bps_email', true );
		return array(
			'id'            => $id,
			'ref'           => (string) $post->post_title,
			'status'        => (string) ( get_post_meta( $id, '_bps_status', true ) ?: 'draft' ),
			'created_gmt'   => (string) get_post_meta( $id, '_bps_created_gmt', true ),
			'payload'       => is_array( $payload ) ? $payload : array(),
			'original'      => is_array( $original ) && ! empty( $original['path'] ) ? array(
				'path'  => (string) $original['path'],
				'name'  => (string) ( $original['name'] ?? '' ),
				'ext'   => (string) ( $original['ext'] ?? '' ),
				'bytes' => (int) ( $original['bytes'] ?? 0 ),
			) : null,
			'preview_url'   => is_array( $preview ) ? (string) ( $preview['url'] ?? '' ) : '',
			'order_id'      => $order_id > 0 ? $order_id : null,
			'order_item_id' => $item_id > 0 ? $item_id : null,
			'email'         => '' !== $email ? $email : null,
			'proof'         => is_array( $proof ) ? $proof : array( 'status' => 'none', 'history' => array() ),
		);
	}

	/**
	 * HMAC token for a design (never stored; recomputed from id + created time).
	 *
	 * @param int $id Design id.
	 */
	public static function token_for( int $id ): string {
		$created = (string) get_post_meta( $id, '_bps_created_gmt', true );
		if ( '' === $created ) {
			return '';
		}
		return hash_hmac( 'sha256', $id . '|' . $created, wp_salt( 'auth' ) );
	}

	/**
	 * Constant-time token check.
	 *
	 * @param int    $id    Design id.
	 * @param string $token Token.
	 */
	public static function verify_token( int $id, string $token ): bool {
		if ( $id <= 0 || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return false;
		}
		$post = get_post( $id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return false;
		}
		$expected = self::token_for( $id );
		return '' !== $expected && hash_equals( $expected, $token );
	}

	/**
	 * Set the status (draft → carted → ordered · quoted · saved). Never downgrades.
	 *
	 * @param int    $id     Design id.
	 * @param string $status Status.
	 */
	public static function set_status( int $id, string $status ): void {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}
		$current = (string) get_post_meta( $id, '_bps_status', true );
		if ( '' !== $current && ( self::RANK[ $current ] ?? 0 ) > self::RANK[ $status ] ) {
			return;
		}
		update_post_meta( $id, '_bps_status', $status );
		update_post_meta( $id, '_bps_status_changed_gmt', gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Merge proof data (production writes this).
	 *
	 * @param int   $id    Design id.
	 * @param array $proof Partial proof.
	 */
	public static function update_proof( int $id, array $proof ): void {
		$cur = get_post_meta( $id, '_bps_proof', true );
		$cur = is_array( $cur ) ? $cur : array(
			'status'  => 'none',
			'history' => array(),
		);
		$history = isset( $cur['history'] ) && is_array( $cur['history'] ) ? $cur['history'] : array();
		if ( isset( $proof['history'] ) && is_array( $proof['history'] ) ) {
			$history = $proof['history'];
			unset( $proof['history'] );
		}
		if ( isset( $proof['history_add'] ) && is_array( $proof['history_add'] ) ) {
			$history[] = $proof['history_add'];
			unset( $proof['history_add'] );
		}
		$new            = array_merge( $cur, $proof );
		$new['history'] = array_values( $history );
		update_post_meta( $id, '_bps_proof', $new );
	}

	/**
	 * Admin download link for the original file (admin-post + nonce).
	 *
	 * @param int $id Design id.
	 */
	public static function original_download_url( int $id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=bps_download&design=' . $id ), 'bps_download_' . $id );
	}

	/* ---------------------------------------------------------------------
	 * Creation
	 * ------------------------------------------------------------------- */

	/**
	 * Create a design from a validated payload and stored files.
	 *
	 * @param array      $payload  Clean payload.
	 * @param array|null $original {path,name,ext,bytes} or null.
	 * @param array      $preview  {path,url}.
	 * @param string     $page_url Studio page URL (for restore links).
	 * @return int|WP_Error
	 */
	public static function create( array $payload, ?array $original, array $preview, string $page_url = '' ) {
		$ref = self::next_ref( 'bps_ref_seq', 'PS' );
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
			return new WP_Error( 'bps_store', __( 'Opslaan is niet gelukt. Probeer het nog eens.', 'borduur-patch-studio' ), array( 'status' => 500 ) );
		}
		$id  = (int) $id;
		$now = gmdate( 'Y-m-d H:i:s' );
		update_post_meta( $id, '_bps_created_gmt', $now );
		update_post_meta( $id, '_bps_last_opened_gmt', $now );
		update_post_meta( $id, '_bps_payload', wp_slash( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		update_post_meta( $id, '_bps_status', 'draft' );
		update_post_meta( $id, '_bps_technique', (string) $payload['technique'] );
		update_post_meta( $id, '_bps_original', $original ? $original : '' );
		update_post_meta( $id, '_bps_preview', $preview );
		update_post_meta( $id, '_bps_proof', array( 'status' => 'none', 'history' => array() ) );
		if ( '' !== $page_url ) {
			update_post_meta( $id, '_bps_page_url', esc_url_raw( $page_url ) );
		}
		do_action( 'bps_design_created', $id, $payload );
		return $id;
	}

	/**
	 * Next human reference "PS-2026-0042" with a per-year counter. Compare-and-swap on the options row
	 * so two simultaneous uploads never get the same number.
	 *
	 * @param string $option Option name.
	 * @param string $prefix Prefix.
	 */
	public static function next_ref( string $option, string $prefix ): string {
		global $wpdb;
		$year = (int) gmdate( 'Y' );
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( null === $raw ) {
				if ( add_option( $option, $year . ':1', '', false ) ) {
					return sprintf( '%s-%d-%04d', $prefix, $year, 1 );
				}
				continue;
			}
			$parts = explode( ':', (string) $raw );
			$n     = ( 2 === count( $parts ) && (int) $parts[0] === $year ) ? (int) $parts[1] + 1 : 1;
			$new   = $year . ':' . $n;
			$rows  = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new, $option, $raw ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( 1 === (int) $rows ) {
				wp_cache_delete( $option, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				return sprintf( '%s-%d-%04d', $prefix, $year, $n );
			}
		}
		// Extremely unlikely: fall back to a unique, still readable reference.
		return sprintf( '%s-%d-%s', $prefix, $year, strtoupper( wp_generate_password( 6, false, false ) ) );
	}

	/**
	 * Mark as opened (retention for saved designs counts from the last opening).
	 *
	 * @param int $id Design id.
	 */
	public static function touch( int $id ): void {
		update_post_meta( $id, '_bps_last_opened_gmt', gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Studio URL for "continue with my design" links.
	 *
	 * @param int $id Design id.
	 */
	public static function restore_url( int $id ): string {
		$page = (string) get_post_meta( $id, '_bps_page_url', true );
		if ( '' === $page ) {
			$page = Product::studio_url();
		}
		return add_query_arg(
			array(
				'bps_design' => $id,
				'bps_token'  => self::token_for( $id ),
			),
			$page
		);
	}

	/**
	 * Temporary signed URL of the original (1 h), only for browser-displayable types.
	 *
	 * @param int $id Design id.
	 */
	public static function signed_original_url( int $id ): ?string {
		$d = self::get( $id );
		if ( ! $d || ! $d['original'] || ! in_array( $d['original']['ext'], Uploads::BROWSER_TYPES, true ) || ! is_readable( $d['original']['path'] ) ) {
			return null;
		}
		$exp = time() + HOUR_IN_SECONDS;
		return add_query_arg(
			array(
				'exp' => $exp,
				'sig' => self::original_sig( $id, $exp ),
			),
			rest_url( 'patch-studio/v1/designs/' . $id . '/original' )
		);
	}

	/**
	 * Signature for the signed original URL.
	 *
	 * @param int $id  Design id.
	 * @param int $exp Expiry (unix).
	 */
	public static function original_sig( int $id, int $exp ): string {
		return hash_hmac( 'sha256', 'orig|' . $id . '|' . $exp, wp_salt( 'auth' ) );
	}

	/* ---------------------------------------------------------------------
	 * Files
	 * ------------------------------------------------------------------- */

	/**
	 * Delete the files of a design (original + preview) and clear the meta. Post stays.
	 *
	 * @param int  $id           Design id.
	 * @param bool $keep_preview Keep the public preview (still referenced by order e-mails).
	 */
	public static function delete_files( int $id, bool $keep_preview = false ): void {
		$original = get_post_meta( $id, '_bps_original', true );
		if ( is_array( $original ) && ! empty( $original['path'] ) ) {
			Uploads::delete( (string) $original['path'] );
		}
		update_post_meta( $id, '_bps_original', '' );
		if ( ! $keep_preview ) {
			$preview = get_post_meta( $id, '_bps_preview', true );
			if ( is_array( $preview ) && ! empty( $preview['path'] ) ) {
				Uploads::delete( (string) $preview['path'] );
			}
			update_post_meta( $id, '_bps_preview', '' );
		}
		update_post_meta( $id, '_bps_files_removed_gmt', gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Remove files when the design post is deleted.
	 *
	 * @param int $post_id Post id.
	 */
	public static function on_delete_post( $post_id ): void {
		$post_id = (int) $post_id;
		if ( self::CPT === get_post_type( $post_id ) ) {
			self::delete_files( $post_id );
		}
	}

	/**
	 * Stream the original to an authorised admin (admin-post.php?action=bps_download).
	 */
	public static function handle_download(): void {
		$id = isset( $_GET['design'] ) ? absint( $_GET['design'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'bps_download_' . $id );
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot dit bestand.', 'borduur-patch-studio' ), '', array( 'response' => 403 ) );
		}
		$d = self::get( $id );
		if ( ! $d || ! $d['original'] || ! Uploads::is_ours( $d['original']['path'], 'private' ) || ! is_readable( $d['original']['path'] ) ) {
			wp_die( esc_html__( 'Dit ontwerpbestand bestaat niet (meer). Mogelijk werd het verwijderd na de bewaartermijn.', 'borduur-patch-studio' ), '', array( 'response' => 404 ) );
		}
		$name = sanitize_file_name( $d['ref'] . '-' . ( '' !== $d['original']['name'] ? $d['original']['name'] : 'ontwerp.' . $d['original']['ext'] ) );
		self::stream_file( $d['original']['path'], $name, 'application/octet-stream' );
	}

	/**
	 * Send a file as attachment and exit.
	 *
	 * @param string $path Path.
	 * @param string $name Download name.
	 * @param string $mime Content type.
	 */
	public static function stream_file( string $path, string $name, string $mime ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox" );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Find designs by meta (used by cleanup/privacy).
	 *
	 * @param array $meta_query Meta query.
	 * @param int   $limit      Limit.
	 * @return int[]
	 */
	public static function query( array $meta_query, int $limit = 100 ): array {
		$q = new \WP_Query(
			array(
				'post_type'              => self::CPT,
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => $limit,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'                => 'ID',
				'order'                  => 'ASC',
			)
		);
		return array_map( 'intval', $q->posts );
	}
}
