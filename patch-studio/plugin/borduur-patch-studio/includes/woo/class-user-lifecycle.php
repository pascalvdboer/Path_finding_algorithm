<?php
/**
 * Deleting a WordPress user: what happens to the designs and quotes of that account (premium P03, klant-account-12).
 *
 * @package BorduurPatchStudio
 */

namespace BorduurPatchStudio\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * The design CPT has delete_with_user = false (orders point to designs), so WordPress would leave designs pointing to
 * a user id that no longer exists. On delete_user / wpmu_delete_user (which run before WordPress reassigns or deletes
 * posts):
 * - designs that were never ordered or quoted are deleted with their files;
 * - ordered/quoted designs stay linked to the order (bookkeeping) but lose the account link and the personal fields
 *   (post_author = 0, no _bps_email, _bps_title, _bps_reminder);
 * - quote requests of the account lose the account link (their contact data follows the quote retention).
 */
final class User_Lifecycle {

	/** @var bool */
	private static $registered = false;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'delete_user', array( __CLASS__, 'on_delete_user' ), 5, 1 );
		add_action( 'wpmu_delete_user', array( __CLASS__, 'on_delete_user' ), 5, 1 );
	}

	/**
	 * Post ids of a post type authored by a user.
	 *
	 * @param string $type    Post type.
	 * @param int    $user_id User id.
	 * @return int[]
	 */
	public static function authored( string $type, int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$q = new \WP_Query(
			array(
				'post_type'              => $type,
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'author__in'             => array( $user_id ),
				'orderby'                => 'ID',
				'order'                  => 'ASC',
			)
		);
		return array_map( 'intval', (array) $q->posts );
	}

	/**
	 * Does a design have to stay (linked to an order or a quote)?
	 *
	 * @param int $id Design id.
	 */
	public static function must_keep( int $id ): bool {
		$status = (string) get_post_meta( $id, '_bps_status', true );
		return in_array( $status, array( 'ordered', 'quoted' ), true )
			|| (int) get_post_meta( $id, '_bps_order_id', true ) > 0
			|| (int) get_post_meta( $id, '_bps_quote_id', true ) > 0;
	}

	/**
	 * Detach a kept design from its (deleted) account.
	 *
	 * @param int $id Design id.
	 */
	public static function detach( int $id ): void {
		wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => 0,
			)
		);
		delete_post_meta( $id, '_bps_email' );
		delete_post_meta( $id, '_bps_email_sent_to' );
		delete_post_meta( $id, '_bps_title' );
		delete_post_meta( $id, '_bps_reminder' );
	}

	/**
	 * delete_user / wpmu_delete_user.
	 *
	 * @param mixed $user_id User id.
	 */
	public static function on_delete_user( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		try {
			foreach ( self::authored( Designs::CPT, $user_id ) as $id ) {
				if ( self::must_keep( $id ) ) {
					self::detach( $id );
				} else {
					wp_delete_post( $id, true ); // Files go via Designs::on_delete_post.
				}
			}
			foreach ( self::authored( Quotes::CPT, $user_id ) as $qid ) {
				wp_update_post(
					array(
						'ID'          => $qid,
						'post_author' => 0,
					)
				);
			}
		} catch ( \Throwable $e ) {
			if ( class_exists( '\BorduurPatchStudio\Logger' ) ) {
				\BorduurPatchStudio\Logger::error( 'user_delete_cleanup_failed', array( 'exception' => $e ) );
			}
		}
	}
}
