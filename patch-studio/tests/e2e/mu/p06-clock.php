<?php
/**
 * Plugin Name: Patch Studio e2e hooks (P06 designs-account)
 * Description: Only for the local e2e site. A fixed clock for the design domain: when the option `bps_p06_now`
 *              holds a unix timestamp, the filter bps_now returns it (reminder due times, consent, "opened").
 *              Scenario 11 sets and removes the option. Never ship this file.
 *
 * @package BorduurPatchStudio
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BPS_P06_TEST_HOOKS' ) ) {
	return;
}
define( 'BPS_P06_TEST_HOOKS', 1 );

add_filter(
	'bps_now',
	static function ( $now ) {
		$fixed = (int) get_option( 'bps_p06_now', 0 );
		return $fixed > 0 ? $fixed : $now;
	}
);
