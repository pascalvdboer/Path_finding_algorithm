<?php
/**
 * Dumpt de PHP-uitkomst van Services::catalog() en Services::evaluate() voor de gedeelde vectoren als JSON, zodat
 * tests/services/services-js.test.js kan nagaan dat assets/src/services/index.js exact hetzelfde doet.
 * Geen WordPress, geen netwerk (zelfde stubs als run-services-php.php).
 *
 * Run: php tests/services/js-parity.php
 *
 * @package BorduurPatchStudio
 */

// phpcs:disable

require __DIR__ . '/php/wp-stubs.php';
require __DIR__ . '/php/wc-fakes.php';

$base = dirname( __DIR__, 2 ) . '/plugin/borduur-patch-studio/includes/services/';
foreach ( array( 'config', 'text', 'owner', 'services' ) as $f ) {
	require_once $base . 'class-' . $f . '.php';
}

use BorduurPatchStudio\Services\Config;
use BorduurPatchStudio\Services\Services;

$vec = json_decode( (string) file_get_contents( __DIR__ . '/offer-vectors.json' ), true );
$out = array();
foreach ( array( 'nl', 'fr' ) as $lang ) {
	Config::set_override( array( 'mode' => 'live' ) );
	$catalog                    = Services::catalog( $lang );
	$out[ $lang ]['catalog']    = $catalog;
	$out[ $lang ]['vectors']    = array();
	foreach ( $vec['vectors'] as $v ) {
		$out[ $lang ]['vectors'][] = Services::evaluate( array_merge( $v['signals'], array( 'lang' => $lang ) ), $catalog, $v['dismissed'] ?? array() );
	}
	Config::set_override( array( 'mode' => 'live', 'services' => array( 'd4_rush' => array( 'enabled' => true ) ) ) );
	$catalog                     = Services::catalog( $lang );
	$out[ $lang ]['rushCatalog'] = $catalog;
	$out[ $lang ]['rush']        = array();
	foreach ( $vec['rushEnabled'] as $v ) {
		$out[ $lang ]['rush'][] = Services::evaluate( array_merge( $v['signals'], array( 'lang' => $lang ) ), $catalog, array() );
	}
}
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
