<?php
/**
 * Test double for Woo\Designs (declared before the autoloader runs): in-memory records, the C3 clone() and
 * preview_url() contract of P06, and a call log.
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

namespace BorduurPatchStudio\Woo;

final class Designs {
	public static $records = array();
	public static $clones  = array();
	public static $sizes   = array();
	public static $status  = array();
	private static $next   = 1000;

	public static function get( int $id ): ?array {
		return self::$records[ $id ] ?? null;
	}
	public static function set_status( int $id, string $status ): void {
		self::$status[ $id ] = $status;
	}
	public static function restore_url( int $id ): string {
		return 'https://shop.test/studio/?bps_design=' . $id;
	}
	public static function verify_token( int $id, string $token ): bool {
		return 'ok' === $token;
	}
	public static function token_for( int $id ): string {
		return 'ok';
	}
	public static function preview_url( int $id, string $size = 'full' ): string {
		if ( isset( self::$sizes[ $id ][ $size ] ) ) {
			return self::$sizes[ $id ][ $size ];
		}
		return (string) ( self::$records[ $id ]['preview_url'] ?? '' );
	}
	/** Contract C3. */
	public static function clone( int $id, array $args = array() ) {
		self::$clones[] = array(
			'id'   => $id,
			'args' => $args,
		);
		$new                   = ++self::$next;
		$payload               = ! empty( $args['from_payload'] ) ? $args['from_payload'] : ( self::$records[ $id ]['payload'] ?? array() );
		self::$records[ $new ] = array(
			'id'          => $new,
			'ref'         => 'PS-2026-' . $new,
			'payload'     => $payload,
			'preview_url' => 'https://shop.test/clone-' . $new . '.png',
		);
		return $new;
	}
}
