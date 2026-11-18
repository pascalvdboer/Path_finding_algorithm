<?php
/**
 * Plugin Name: Patch Studio e2e hooks (P01 security / api-errors)
 * Description: Only for the local e2e site. Inactive unless the option `bps_p01_test` is set by scenario 09/10
 *              (the scenario removes it again). Never ship this file.
 *
 * Option `bps_p01_test` (array):
 *   ip        true  → REMOTE_ADDR is taken from header X-BPS-Test-IP (valid IPs only), so the rate-limit
 *                     scenarios get their own buckets and do not disturb the other scenarios (all 127.0.0.1).
 *   rate      array → bucket => max requests (filter bps_rate_limit).
 *   maxBytes  int   → filter bps_upload_max_bytes (small files can test 413).
 *   lowDisk   true  → filter bps_min_free_disk_bytes = PHP_INT_MAX (simulated full disk → 507).
 *   record    true  → last bps_design_emailed call is stored in option bps_p01_emailed.
 *
 * @package BorduurPatchStudio
 */

defined( 'ABSPATH' ) || exit;

// Loaded either by the mu-plugins loader of the test site or copied there by scenario 09/10: load once.
if ( defined( 'BPS_P01_TEST_HOOKS' ) ) {
	return;
}
define( 'BPS_P01_TEST_HOOKS', 1 );

/**
 * Test settings (empty array when inactive).
 *
 * @return array
 */
function bps_p01_test_settings(): array {
	static $cfg = null;
	if ( null === $cfg ) {
		$cfg = get_option( 'bps_p01_test', array() );
		$cfg = is_array( $cfg ) ? $cfg : array();
	}
	return $cfg;
}

$bps_p01 = bps_p01_test_settings();
if ( ! $bps_p01 ) {
	return;
}

if ( ! empty( $bps_p01['ip'] ) && isset( $_SERVER['HTTP_X_BPS_TEST_IP'] ) ) {
	$bps_p01_ip = trim( (string) wp_unslash( $_SERVER['HTTP_X_BPS_TEST_IP'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( filter_var( $bps_p01_ip, FILTER_VALIDATE_IP ) ) {
		$_SERVER['REMOTE_ADDR'] = $bps_p01_ip;
	}
}

if ( ! empty( $bps_p01['rate'] ) && is_array( $bps_p01['rate'] ) ) {
	add_filter(
		'bps_rate_limit',
		static function ( $max, $bucket ) {
			$rate = bps_p01_test_settings()['rate'];
			return isset( $rate[ $bucket ] ) ? (int) $rate[ $bucket ] : $max;
		},
		99,
		2
	);
}

if ( ! empty( $bps_p01['maxBytes'] ) ) {
	add_filter(
		'bps_upload_max_bytes',
		static function () {
			return (int) bps_p01_test_settings()['maxBytes'];
		},
		99
	);
}

if ( ! empty( $bps_p01['lowDisk'] ) ) {
	add_filter(
		'bps_min_free_disk_bytes',
		static function () {
			return PHP_INT_MAX;
		},
		99
	);
}

if ( ! empty( $bps_p01['record'] ) ) {
	add_action(
		'bps_design_emailed',
		static function ( $id, $email ) {
			update_option(
				'bps_p01_emailed',
				array(
					'id'    => (int) $id,
					'email' => (string) $email,
				),
				false
			);
		},
		10,
		2
	);
}
