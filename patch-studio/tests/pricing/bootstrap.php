<?php
/**
 * Standalone bootstrap for the pricing tests (no WordPress). Mirrors tests/pricing/lib.js.
 *
 * @package BorduurPatchStudio
 */

define( 'BPS_TESTING', true );
require_once dirname( __DIR__, 2 ) . '/plugin/borduur-patch-studio/includes/class-pricing.php';

/**
 * Deep merge like shared/config.js deepMerge(): objects recursively, lists and scalars replaced.
 *
 * @param mixed $base Base.
 * @param mixed $over Override.
 * @return mixed
 */
function bps_test_merge( $base, $over ) {
	$is_obj = static fn( $v ) => is_array( $v ) && ( array() === $v || ! array_is_list( $v ) );
	if ( ! $is_obj( $base ) || ! $is_obj( $over ) ) {
		return $over;
	}
	foreach ( $over as $k => $v ) {
		$base[ $k ] = ( isset( $base[ $k ] ) && $is_obj( $base[ $k ] ) && $is_obj( $v ) ) ? bps_test_merge( $base[ $k ], $v ) : $v;
	}
	return $base;
}

/** Defaults from config/defaults.json. */
function bps_test_defaults(): array {
	static $d = null;
	if ( null === $d ) {
		$d = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/plugin/borduur-patch-studio/config/defaults.json' ), true, 512, JSON_THROW_ON_ERROR );
	}
	return $d;
}

/**
 * Config for a vector.
 *
 * @param mixed $overrides Overrides.
 */
function bps_test_config( $overrides ): array {
	return is_array( $overrides ) && array() !== $overrides ? bps_test_merge( bps_test_defaults(), $overrides ) : bps_test_defaults();
}
