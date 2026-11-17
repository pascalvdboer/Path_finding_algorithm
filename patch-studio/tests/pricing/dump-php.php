<?php
/**
 * Parity helper: reads [{input, configOverrides, all?}] as JSON on stdin and writes the PHP quotes as JSON.
 * Used by tests/pricing/parity.test.js to compare the FULL quote of JS and PHP.
 *
 * @package BorduurPatchStudio
 */

require __DIR__ . '/bootstrap.php';

use BorduurPatchStudio\Pricing;

$cases = json_decode( (string) stream_get_contents( STDIN ), true, 512, JSON_THROW_ON_ERROR );
$out   = array();
foreach ( $cases as $c ) {
	$cfg   = bps_test_config( $c['configOverrides'] ?? array() );
	$out[] = empty( $c['all'] ) ? Pricing::quote( $c['input'], $cfg ) : Pricing::quote_all( $c['input'], $cfg );
}
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR );
