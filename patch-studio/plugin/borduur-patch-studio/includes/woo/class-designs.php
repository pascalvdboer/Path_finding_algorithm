<?php
/**
 * Design storage (CPT bps_design) and its public PHP API (SPEC §6.1).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

use BorduurPatchStudio\Emb_File;
use BorduurPatchStudio\Logger;
use BorduurPatchStudio\Rest\Account_Routes;
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

	/** Statuses whose originals count against woo.maxDraftStorageMb (security-3). */
	const STORAGE_STATUSES = array( 'draft', 'carted' );

	/** Statuses a clone may start in. */
	const CLONE_STATUSES = array( 'draft', 'carted' );

	/** Longest customer title of a design ("Naam wijzigen" in My account). */
	const TITLE_MAX = 80;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 5 );
		add_action( 'admin_post_bps_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ), 10, 1 );
		add_action( 'bps_design_released', array( __CLASS__, 'count_storage' ), 10, 1 );

		// Parts of the design domain (P06) that live in their own classes.
		Emb_File::register();
		Account::register();
		Reminder::register();
		Account_Routes::register();
	}

	/* ---------------------------------------------------------------------
	 * Clock (filter bps_now: a fixed clock for tests)
	 * ------------------------------------------------------------------- */

	/**
	 * Current unix time, filterable with bps_now (int timestamp) so integration tests can move the clock.
	 */
	public static function now_ts(): int {
		$now = apply_filters( 'bps_now', null );
		return is_numeric( $now ) && (int) $now > 0 ? (int) $now : time();
	}

	/**
	 * Current GMT time as Y-m-d H:i:s (bps_now aware).
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s', self::now_ts() );
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
			'title'         => (string) get_post_meta( $id, '_bps_title', true ),
			'author'        => (int) $post->post_author,
			'parent_id'     => (int) get_post_meta( $id, '_bps_parent_id', true ),
			'revision'      => max( 1, (int) get_post_meta( $id, '_bps_revision', true ) ),
			'hidden'        => '' !== (string) get_post_meta( $id, '_bps_hidden_gmt', true ),
			'preview_sizes' => self::preview_sizes( is_array( $preview ) ? $preview : array() ),
		);
	}

	/**
	 * Public URLs of the preview variants: {cart, cart2x, mail} (only those that exist).
	 *
	 * @param array $preview Preview meta.
	 * @return array<string,string>
	 */
	private static function preview_sizes( array $preview ): array {
		$out = array();
		foreach ( (array) ( $preview['sizes'] ?? array() ) as $key => $v ) {
			if ( is_array( $v ) && ! empty( $v['url'] ) ) {
				$out[ (string) $key ] = (string) $v['url'];
			}
		}
		return $out;
	}

	/**
	 * Preview URL in a given size (contract C3): full (PNG master), cart (300 px webp), cart2x (600 px webp) or
	 * mail (600 px jpg on white). Falls back to the PNG when the variant does not exist (older designs); '' when the
	 * design has no preview at all.
	 *
	 * @param int    $id   Design id.
	 * @param string $size full|cart|cart2x|mail.
	 */
	public static function preview_url( int $id, string $size = 'full' ): string {
		$preview = $id > 0 ? get_post_meta( $id, '_bps_preview', true ) : '';
		if ( ! is_array( $preview ) ) {
			return '';
		}
		$png = (string) ( $preview['url'] ?? '' );
		if ( 'full' !== $size && ! empty( $preview['sizes'][ $size ]['url'] ) ) {
			return (string) $preview['sizes'][ $size ]['url'];
		}
		return $png;
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
		if ( in_array( $status, array( 'carted', 'quoted', 'ordered' ), true ) ) {
			// Also when the rank does not change (a saved design that is carted stays "saved"): the customer acted,
			// so the promised reminder is no longer needed.
			if ( 'carted' === $status ) {
				update_post_meta( $id, '_bps_carted_gmt', self::now() );
			}
			Reminder::cancel( $id, 'status' );
		}
		$current = (string) get_post_meta( $id, '_bps_status', true );
		if ( '' !== $current && ( self::RANK[ $current ] ?? 0 ) > self::RANK[ $status ] ) {
			return;
		}
		update_post_meta( $id, '_bps_status', $status );
		update_post_meta( $id, '_bps_status_changed_gmt', self::now() );
		if ( ! in_array( $status, self::STORAGE_STATUSES, true ) ) {
			// Saved, quoted and ordered designs leave the draft storage budget (security-3).
			self::uncount_storage( $id );
		}
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
	 * Create a design from a validated payload and stored files. The design belongs to the current user (0 for
	 * guests; REST restores the logged-in user first, klant-account-2).
	 *
	 * @param array      $payload  Clean payload.
	 * @param array|null $original {path,name,ext,bytes} or null.
	 * @param array      $preview  {path,url,sizes?}.
	 * @param string     $page_url Studio page URL (for restore links).
	 * @return int|WP_Error
	 */
	public static function create( array $payload, ?array $original, array $preview, string $page_url = '' ) {
		$id = self::insert( $payload, $original, $preview, 'draft', get_current_user_id() );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( '' !== $page_url ) {
			update_post_meta( $id, '_bps_page_url', esc_url_raw( $page_url ) );
		}
		do_action( 'bps_design_created', $id, $payload );
		return $id;
	}

	/**
	 * Insert the post and its meta (shared by create() and clone()).
	 *
	 * @param array      $payload  Payload.
	 * @param array|null $original Original or null.
	 * @param array      $preview  Preview meta (may be empty).
	 * @param string     $status   draft|carted.
	 * @param int        $author   Owner (0 = guest).
	 * @return int|WP_Error
	 */
	private static function insert( array $payload, ?array $original, array $preview, string $status, int $author ) {
		$ref = self::next_ref( 'bps_ref_seq', 'PS' );
		$id  = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'private',
				'post_title'  => $ref,
				'post_author' => max( 0, $author ),
			),
			true
		);
		if ( is_wp_error( $id ) || (int) $id <= 0 ) {
			Logger::error( 'design_insert_failed', array( 'error' => is_wp_error( $id ) ? $id->get_error_message() : 'no id' ) );
			return new WP_Error( 'bps_store', __( 'Opslaan is niet gelukt. Probeer het nog eens.', 'borduur-patch-studio' ), array( 'status' => 500 ) );
		}
		$id  = (int) $id;
		$now = self::now();
		Emb_File::attach( $id, $original, $payload );
		update_post_meta( $id, '_bps_created_gmt', $now );
		update_post_meta( $id, '_bps_last_opened_gmt', $now );
		update_post_meta( $id, '_bps_payload', wp_slash( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		update_post_meta( $id, '_bps_status', in_array( $status, self::CLONE_STATUSES, true ) ? $status : 'draft' );
		update_post_meta( $id, '_bps_technique', (string) ( $payload['technique'] ?? '' ) );
		update_post_meta( $id, '_bps_original', $original ? $original : '' );
		update_post_meta( $id, '_bps_preview', $preview ? $preview : '' );
		update_post_meta( $id, '_bps_proof', array( 'status' => 'none', 'history' => array() ) );
		self::count_storage( $id );
		return $id;
	}

	/**
	 * Copy a design into a new record (contract C3). Always a new ref and created time (so a new token), copied
	 * files with new random names (never shared: cleanup of one design must not break the other), proof "none".
	 *
	 * Args:
	 * - parent_id    int    stored as _bps_parent_id (default: the source id)
	 * - reorder_of   array  {design_id, order_id} stored as _bps_reorder_of
	 * - from_payload array  payload to use when the source (or its files) is gone, e.g. the order line snapshot
	 * - status       string draft (default) | carted
	 * - author       int    owner of the copy (default: the current user, else 0)
	 * - payload      array  replace the payload (revise of an ordered design)
	 * - preview      array  an already stored new preview instead of a copy (revise)
	 * - original     array  an already stored new original instead of a copy (revise)
	 *
	 * @param int   $id   Source design id (may no longer exist when from_payload is given).
	 * @param array $args Args.
	 * @return int|WP_Error New design id.
	 */
	public static function clone( int $id, array $args = array() ) {
		$src     = self::get( $id );
		$payload = isset( $args['payload'] ) && is_array( $args['payload'] ) ? $args['payload'] : array();
		if ( ! $payload ) {
			$payload = $src && ! empty( $src['payload'] ) ? $src['payload'] : ( isset( $args['from_payload'] ) && is_array( $args['from_payload'] ) ? $args['from_payload'] : array() );
		}
		if ( ! $payload || empty( $payload['technique'] ) ) {
			return new WP_Error( 'bps_not_found', __( 'We vonden dit ontwerp niet terug.', 'borduur-patch-studio' ), array( 'status' => 404 ) );
		}
		unset( $payload['embFile'] ); // Recomputed from the copied original.

		// Files: new ones from the caller, else copies of the source files.
		$original = null;
		if ( isset( $args['original'] ) && is_array( $args['original'] ) ) {
			$original = $args['original'];
		} elseif ( $src && $src['original'] ) {
			$original = Uploads::copy_original( $src['original'] );
		}
		$preview = array();
		if ( isset( $args['preview'] ) && is_array( $args['preview'] ) ) {
			$preview = $args['preview'];
		} elseif ( $src ) {
			$meta    = get_post_meta( $id, '_bps_preview', true );
			$preview = is_array( $meta ) ? (array) Uploads::copy_preview( $meta ) : array();
		}

		$status = isset( $args['status'] ) && in_array( $args['status'], self::CLONE_STATUSES, true ) ? (string) $args['status'] : 'draft';
		$author = isset( $args['author'] ) ? (int) $args['author'] : get_current_user_id();
		$new    = self::insert( $payload, $original, $preview, $status, $author );
		if ( is_wp_error( $new ) ) {
			if ( $original && empty( $args['original'] ) ) {
				Uploads::delete( (string) $original['path'] );
			}
			if ( $preview && empty( $args['preview'] ) ) {
				Uploads::delete_preview( $preview );
			}
			return $new;
		}

		$parent = isset( $args['parent_id'] ) ? (int) $args['parent_id'] : $id;
		if ( $parent > 0 ) {
			update_post_meta( $new, '_bps_parent_id', $parent );
		}
		if ( isset( $args['reorder_of'] ) && is_array( $args['reorder_of'] ) ) {
			update_post_meta(
				$new,
				'_bps_reorder_of',
				array(
					'design_id' => (int) ( $args['reorder_of']['design_id'] ?? 0 ),
					'order_id'  => (int) ( $args['reorder_of']['order_id'] ?? 0 ),
				)
			);
		}
		if ( $src ) {
			foreach ( array( '_bps_title', '_bps_page_url' ) as $key ) {
				$val = (string) get_post_meta( $id, $key, true );
				if ( '' !== $val ) {
					update_post_meta( $new, $key, $val );
				}
			}
		}
		/**
		 * A design was copied (security-4 cart clone, revise of an ordered design, reorder).
		 *
		 * @param int   $new  New design id.
		 * @param int   $id   Source design id.
		 * @param array $args Clone args.
		 */
		do_action( 'bps_design_cloned', $new, $id, $args );
		return $new;
	}

	/**
	 * Revise a design after the customer changed it (klant-account-3). A draft or saved design is updated in place:
	 * same id, ref and token (the token depends on the created time, which stays). A carted, quoted or ordered design
	 * is never changed (cart line, quote and order point to it): the revision becomes a new design with
	 * _bps_parent_id, a copied original (unless a new one is given) and proof "none".
	 *
	 * @param int        $id       Design id.
	 * @param array      $payload  Validated payload.
	 * @param array      $preview  Newly stored preview.
	 * @param array|null $original Newly stored original, or null to keep (or copy) the current one.
	 * @return array|WP_Error {id, cloned, revision}
	 */
	public static function revise( int $id, array $payload, array $preview, ?array $original = null ) {
		$d = self::get( $id );
		if ( ! $d ) {
			return new WP_Error( 'bps_not_found', __( 'Dit ontwerp bestaat niet meer.', 'borduur-patch-studio' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $d['status'], array( 'draft', 'saved' ), true ) ) {
			$args = array(
				'parent_id' => $id,
				'status'    => 'draft',
				'payload'   => $payload,
				'preview'   => $preview,
			);
			if ( $original ) {
				$args['original'] = $original;
			}
			$new = self::clone( $id, $args );
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			return array(
				'id'       => (int) $new,
				'cloned'   => true,
				'revision' => 1,
			);
		}

		$old_preview = get_post_meta( $id, '_bps_preview', true );
		if ( $original ) {
			self::uncount_storage( $id );
			if ( $d['original'] ) {
				Uploads::delete( $d['original']['path'] );
			}
			update_post_meta( $id, '_bps_original', $original );
			$current = $original;
		} else {
			$current = $d['original'];
		}
		Emb_File::attach( $id, $current, $payload );
		update_post_meta( $id, '_bps_payload', wp_slash( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		update_post_meta( $id, '_bps_technique', (string) ( $payload['technique'] ?? '' ) );
		update_post_meta( $id, '_bps_preview', $preview );
		Uploads::delete_preview( $old_preview );
		$rev = max( 1, (int) get_post_meta( $id, '_bps_revision', true ) ) + 1;
		update_post_meta( $id, '_bps_revision', $rev );
		update_post_meta( $id, '_bps_updated_gmt', self::now() );
		if ( $original ) {
			self::count_storage( $id );
		}
		$uid = get_current_user_id();
		if ( $uid > 0 && 0 === (int) $d['author'] ) {
			self::claim( $id, $uid );
		}
		self::touch( $id );
		do_action( 'bps_design_revised', $id, $payload );
		return array(
			'id'       => $id,
			'cloned'   => false,
			'revision' => $rev,
		);
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
		update_post_meta( $id, '_bps_last_opened_gmt', self::now() );
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
		self::uncount_storage( $id );
		$original = get_post_meta( $id, '_bps_original', true );
		if ( is_array( $original ) && ! empty( $original['path'] ) ) {
			Uploads::delete( (string) $original['path'] );
		}
		update_post_meta( $id, '_bps_original', '' );
		if ( ! $keep_preview ) {
			Uploads::delete_preview( get_post_meta( $id, '_bps_preview', true ) );
			update_post_meta( $id, '_bps_preview', '' );
		}
		update_post_meta( $id, '_bps_files_removed_gmt', self::now() );
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

	/* ---------------------------------------------------------------------
	 * Draft storage counter (security-3)
	 * ------------------------------------------------------------------- */

	/**
	 * Count the original of a draft/carted design once (idempotent: the counted bytes are kept on the design).
	 *
	 * @param mixed $id Design id.
	 */
	public static function count_storage( $id ): void {
		$id = (int) $id;
		if ( $id <= 0 || '' !== (string) get_post_meta( $id, '_bps_storage_bytes', true ) ) {
			return;
		}
		$status   = (string) ( get_post_meta( $id, '_bps_status', true ) ?: 'draft' );
		$original = get_post_meta( $id, '_bps_original', true );
		if ( ! in_array( $status, self::STORAGE_STATUSES, true ) || ! is_array( $original ) || empty( $original['path'] ) ) {
			return;
		}
		$bytes = max( 0, (int) ( $original['bytes'] ?? 0 ) );
		if ( $bytes <= 0 && is_readable( (string) $original['path'] ) ) {
			$bytes = (int) filesize( (string) $original['path'] );
		}
		if ( $bytes > 0 ) {
			update_post_meta( $id, '_bps_storage_bytes', $bytes );
			Uploads::storage_add( $bytes );
		}
	}

	/**
	 * Remove a design's original from the counter (status upgrade, delete).
	 *
	 * @param int $id Design id.
	 */
	public static function uncount_storage( int $id ): void {
		$bytes = (string) get_post_meta( $id, '_bps_storage_bytes', true );
		if ( '' === $bytes ) {
			return;
		}
		delete_post_meta( $id, '_bps_storage_bytes' );
		Uploads::storage_add( -1 * max( 0, (int) $bytes ) );
	}

	/* ---------------------------------------------------------------------
	 * Customer account (klant-account-2)
	 * ------------------------------------------------------------------- */

	/**
	 * Owner of a design (0 = guest / unknown).
	 *
	 * @param int $id Design id.
	 */
	public static function owner( int $id ): int {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post && self::CPT === $post->post_type ? (int) $post->post_author : 0;
	}

	/**
	 * Link a guest design to a customer. Never re-assigns a design that already belongs to someone else.
	 *
	 * @param int $id      Design id.
	 * @param int $user_id Customer.
	 * @return bool True when the design now belongs to $user_id.
	 */
	public static function claim( int $id, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$post = get_post( $id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return false;
		}
		$owner = (int) $post->post_author;
		if ( $owner === $user_id ) {
			return true;
		}
		if ( $owner > 0 ) {
			return false;
		}
		$res = wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => $user_id,
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			Logger::warning(
				'design_claim_failed',
				array(
					'design' => $id,
					'error'  => $res->get_error_message(),
				)
			);
			return false;
		}
		clean_post_cache( $id );
		return true;
	}

	/**
	 * Customer title ("Naam wijzigen"); '' removes it.
	 *
	 * @param int    $id    Design id.
	 * @param string $title Title.
	 */
	public static function set_title( int $id, string $title ): string {
		$title = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $title ) ) );
		$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, self::TITLE_MAX ) : substr( $title, 0, self::TITLE_MAX );
		if ( '' === $title ) {
			delete_post_meta( $id, '_bps_title' );
		} else {
			update_post_meta( $id, '_bps_title', $title );
		}
		return $title;
	}

	/**
	 * Remove a design from the customer's list. Draft, saved and carted designs are deleted with their files;
	 * quoted and ordered designs only get hidden (the quote, order line and production sheet keep working).
	 *
	 * @param int $id Design id.
	 * @return string deleted|hidden
	 */
	public static function remove_for_customer( int $id ): string {
		$status = (string) ( get_post_meta( $id, '_bps_status', true ) ?: 'draft' );
		$linked = (int) get_post_meta( $id, '_bps_order_id', true ) > 0;
		if ( ! $linked && in_array( $status, array( 'draft', 'saved', 'carted' ), true ) ) {
			wp_delete_post( $id, true ); // on_delete_post() removes the files.
			return 'deleted';
		}
		update_post_meta( $id, '_bps_hidden_gmt', self::now() );
		Reminder::cancel( $id, 'hidden' );
		return 'hidden';
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
